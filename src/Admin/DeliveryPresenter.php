<?php
/**
 * Every delivery-history value, rendered once and escaped at its point of output
 * (ADR-0018 §1, §2, §4; gate 31).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

use Extonify\WCEP\Domain\DeliveryIdentity;
use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Plugin;
use Extonify\WCEP\Repository\DeliveryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The one place the history screen and the order panel agree about what a
 * delivery says.
 *
 * ⚠ EVERY VALUE HERE IS DATABASE-SOURCED AND NONE OF IT IS SAFE BY VIRTUE OF THE
 * WRITE PATH (ADR-0018 Context). This is the difference from the rules admin, and it
 * is not a difference of degree. A rule's name is merchant-authored — hostile content
 * there needs a hostile administrator. A delivery's `recipient` comes from a
 * customer-controlled billing field; `failure_message` carries an SMTP server's
 * response, which quotes that address back verbatim; `reason` routinely embeds both.
 * Storage sanitisation is a DIFFERENT barrier from output escaping and it hid an
 * unescaped output path once already (`TargetLabels::describe()`, Prompt 9A). So
 * every method here escapes at the point of output, for the context it is writing
 * into, and gate 31 proves each one with a row written STRAIGHT INTO THE TABLE.
 *
 * ⚠ AND IT IS ONE CLASS BECAUSE THE HONEST-ABSENCE WORDING MUST NOT BE DUPLICATED
 * (ADR-0018 §1). Two copies of "its details were removed by retention" is two
 * chances for one of them to drift into "unknown error", which asserts a failure
 * that did not happen.
 *
 * ⚠ NOTHING HERE QUERIES PER ROW. `rule_names()` and `order_edit_url()` are the two
 * that could, and both are built so a page render costs a fixed number of statements
 * (ADR-0018 §7).
 */
final class DeliveryPresenter {

	/**
	 * Retention windows, in days, exactly as ADR-0005 specifies them.
	 *
	 * Stated on the screen so an absent detail row is explicable without reading the
	 * documentation — see self::retention_note(), which also has to say that nothing
	 * currently applies them.
	 */
	const RETENTION_NORMAL_DAYS = 90;
	const RETENTION_FAILED_DAYS = 180;
	const RETENTION_DEBUG_DAYS  = 14;

	/**
	 * Resolve a page of rule ids to their CURRENT names, in ONE query
	 * (ADR-0018 §3, §7).
	 *
	 * ⚠ THE TOMBSTONE RECORDS NO RULE NAME, AND THIS ADR DECLINED TO ADD ONE. A
	 * denormalised `rule_name` column would have to be written by `claim()` — the one
	 * atomic statement the plugin's entire duplicate-prevention design rests on, which
	 * ADR-0015 §2 already refused to widen for a column that is not part of its
	 * decision — and it would go stale the moment a merchant renamed the rule. So a
	 * live rule shows its current name and a deleted one shows the recorded id.
	 *
	 * ⚠ ONE STATEMENT FOR THE WHOLE PAGE, never one per row: `find_by_ids()` binds a
	 * generated placeholder run, and the page size (20) is far below its chunk bound.
	 *
	 * @param int[] $rule_ids Rule ids from a page of tombstones.
	 * @return array<int,string> rule_id => name, missing when the rule is gone.
	 */
	public static function rule_names( array $rule_ids ): array {
		$ids = array();

		foreach ( $rule_ids as $id ) {
			$id = (int) $id;

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		if ( array() === $ids ) {
			return array();
		}

		return Plugin::instance()->rules()->names_for_ids( array_values( $ids ) );
	}

	/**
	 * The order cell: a link built from the recorded id, costing no query
	 * (ADR-0018 §7).
	 *
	 * ⚠ `OrderUtil::get_order_admin_edit_url()` IS DELIBERATELY NOT USED. Under HPOS
	 * it calls `wc_get_order()` to read the order TYPE, which would be one order load
	 * per row — the N+1 shape ADR-0018 §7 exists to forbid, and invisible on the
	 * twenty orders a developer tests with. The URL shape is decided once per render
	 * from the active storage and the recorded id is substituted into it.
	 *
	 * ⚠ AND THE LINK IS RENDERED EVEN FOR AN ORDER THAT NO LONGER EXISTS. It 404s,
	 * which is honest; hiding the row would hide a delivery that really happened.
	 *
	 * @param int $order_id Recorded order id.
	 * @return string Escaped HTML.
	 */
	public static function order_cell( int $order_id ): string {
		if ( $order_id <= 0 ) {
			return '<span class="extonify-wcep-muted">' . esc_html__( 'No order recorded', 'extonify-custom-emails-per-product' ) . '</span>';
		}

		/* translators: %d: WooCommerce order id. */
		$label = sprintf( __( 'Order #%d', 'extonify-custom-emails-per-product' ), $order_id );

		return '<a href="' . esc_url( self::order_edit_url( $order_id ) ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * The admin edit URL for one order, under whichever storage is active
	 * (ADR-0018 §8).
	 *
	 * Detected, never assumed: the plugin declares HPOS compatibility, so a link that
	 * worked under only one storage would be a visible contradiction of its own header.
	 *
	 * @param int $order_id Order id.
	 * @return string Unescaped URL; the caller escapes it for its context.
	 */
	public static function order_edit_url( int $order_id ): string {
		if ( OrderPanel::hpos_is_active() ) {
			return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
		}

		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	/**
	 * The rule cell: linked when the rule still exists, honest when it does not.
	 *
	 * @param int               $rule_id Recorded rule id.
	 * @param array<int,string> $names   Page-wide map from self::rule_names().
	 * @return string Escaped HTML.
	 */
	public static function rule_cell( int $rule_id, array $names ): string {
		if ( $rule_id <= 0 ) {
			return '<span class="extonify-wcep-muted">' . esc_html__( 'No rule recorded', 'extonify-custom-emails-per-product' ) . '</span>';
		}

		/* translators: %d: rule id. */
		$fallback = sprintf( __( 'Rule #%d', 'extonify-custom-emails-per-product' ), $rule_id );

		if ( ! isset( $names[ $rule_id ] ) ) {
			// ⚠ DELETED, NOT MISSING. ADR-0004 keeps the tombstone when the rule goes,
			// so this is the ordinary state of an old delivery rather than an error —
			// and saying so is the difference between a merchant reading it as history
			// and reading it as corruption.
			return '<span class="extonify-wcep-muted">' . esc_html( $fallback ) . '</span><br />'
				. '<span class="description">' . esc_html__( 'This rule has been deleted.', 'extonify-custom-emails-per-product' ) . '</span>';
		}

		$name = trim( $names[ $rule_id ] );
		$url  = Menu::url(
			array(
				'action' => Menu::ACTION_EDIT,
				'rule'   => $rule_id,
			)
		);

		return '<a href="' . esc_url( $url ) . '">' . esc_html( '' !== $name ? $name : $fallback ) . '</a>';
	}

	/**
	 * The trigger cell, from the recorded trigger identity (ADR-0004).
	 *
	 * ⚠ ESCAPED DESPITE BEING PLUGIN-GENERATED AND VALIDATED ON WRITE. It is read out
	 * of a database column, and gate 31 admits no output whose safety rests on the
	 * write path — that reasoning is exactly what hid the `TargetLabels` defect.
	 *
	 * @param string $trigger_identity Recorded identity, e.g. `status:completed`.
	 * @return string Escaped HTML.
	 */
	public static function trigger_cell( string $trigger_identity ): string {
		$identity = trim( $trigger_identity );

		if ( '' === $identity ) {
			return self::not_recorded();
		}

		$separator = strpos( $identity, ':' );
		$prefix    = false === $separator ? '' : substr( $identity, 0, $separator );
		$value     = false === $separator ? $identity : substr( $identity, $separator + 1 );

		switch ( $prefix ) {
			case 'status':
				/* translators: %s: order status, as recorded on the delivery. */
				$sentence = sprintf( __( 'Order reached %s', 'extonify-custom-emails-per-product' ), $value );
				break;

			case 'transition':
				$sides    = explode( '>', $value );
				$sentence = sprintf(
					/* translators: 1: source order status, 2: destination order status. */
					__( 'Order moved from %1$s to %2$s', 'extonify-custom-emails-per-product' ),
					(string) ( $sides[0] ?? '' ),
					(string) ( $sides[1] ?? '' )
				);
				break;

			case 'refund':
				$sentence = __( 'Order was refunded', 'extonify-custom-emails-per-product' );
				break;

			case DeliveryIdentity::NATIVE_PREFIX:
				/* translators: %s: WooCommerce email id the rule inserts into. */
				$sentence = sprintf( __( 'Inside the WooCommerce email %s', 'extonify-custom-emails-per-product' ), $value );
				break;

			default:
				// An identity shape this version does not recognise is shown AS STORED
				// rather than hidden or repaired: it is real history, and a merchant
				// reporting it verbatim is more use than a screen that swallowed it.
				$sentence = $identity;
		}

		return esc_html( $sentence );
	}

	/**
	 * The status cell.
	 *
	 * @param string $final_status Recorded status.
	 * @return string Escaped HTML.
	 */
	public static function status_cell( string $final_status ): string {
		$statuses = FieldOptions::delivery_statuses();

		return '<span class="extonify-wcep-delivery-status extonify-wcep-delivery-status--'
			. esc_attr( sanitize_html_class( $final_status ) ) . '">'
			. esc_html( $statuses[ $final_status ] ?? $final_status ) . '</span>';
	}

	/**
	 * The delivery-mode cell.
	 *
	 * @param string $mode Recorded mode.
	 * @return string Escaped HTML.
	 */
	public static function mode_cell( string $mode ): string {
		$modes = FieldOptions::delivery_modes();

		return esc_html( $modes[ $mode ] ?? $mode );
	}

	/**
	 * The "when" cell: first claimed, and last seen when the two differ.
	 *
	 * @param array $delivery Tombstone row.
	 * @return string Escaped HTML.
	 */
	public static function when_cell( array $delivery ): string {
		$first = self::format_time( (string) ( $delivery['first_claimed_at'] ?? '' ) );
		$last  = self::format_time( (string) ( $delivery['last_seen_at'] ?? '' ) );

		if ( '' === $first ) {
			return self::not_recorded();
		}

		$html = esc_html( $first );

		if ( '' !== $last && $last !== $first ) {
			$html .= '<br /><span class="description">' . esc_html(
				sprintf(
					/* translators: %s: a date and time. */
					__( 'Last activity %s', 'extonify-custom-emails-per-product' ),
					$last
				)
			) . '</span>';
		}

		return $html;
	}

	/**
	 * The attempts cell — the whole of a delivery's detail, or an honest account of
	 * why there is none (ADR-0018 §4).
	 *
	 * ⚠ THE THREE ABSENCES ARE THREE DIFFERENT FACTS AND THIS METHOD KEEPS THEM
	 * APART. "The details were removed" (retention), "the details were erased on
	 * request" (privacy) and "there is no delivery here" (the caller's business, not
	 * this method's) are separate sentences. Rendering an empty row for any of them
	 * makes a plugin that is working exactly as ADR-0004 designed it look broken.
	 *
	 * @param array $details Attempt rows for ONE tombstone, oldest first.
	 * @return string Escaped HTML.
	 */
	public static function attempts_cell( array $details ): string {
		if ( array() === $details ) {
			// ⚠ PURGED, NOT EMPTY. The tombstone survives retention by construction
			// (ADR-0004), so a delivery with no detail beneath it is the EXPECTED state
			// of an old row rather than a missing one.
			return '<span class="extonify-wcep-purged">'
				. esc_html__( 'This delivery was recorded, but its details have been removed. See the retention note below.', 'extonify-custom-emails-per-product' )
				. '</span>';
		}

		$blocks = array();

		foreach ( $details as $detail ) {
			$blocks[] = self::attempt_block( (array) $detail );
		}

		return '<ul class="extonify-wcep-attempts">' . implode( '', $blocks ) . '</ul>';
	}

	/**
	 * One attempt, rendered.
	 *
	 * @param array $detail Attempt row.
	 * @return string Escaped HTML.
	 */
	private static function attempt_block( array $detail ): string {
		$types  = FieldOptions::attempt_types();
		$states = FieldOptions::attempt_states();

		$type  = (string) ( $detail['type'] ?? '' );
		$state = (string) ( $detail['state'] ?? '' );

		$heading = esc_html(
			sprintf(
				/* translators: 1: attempt number, 2: how the attempt was made, e.g. "Automatic", 3: what happened, e.g. "Sent". */
				__( 'Attempt %1$d — %2$s — %3$s', 'extonify-custom-emails-per-product' ),
				max( 1, (int) ( $detail['attempt'] ?? 1 ) ),
				$types[ $type ] ?? $type,
				$states[ $state ] ?? $state
			)
		);

		$lines = array( '<strong>' . $heading . '</strong>' );

		if ( self::is_redacted( $detail ) ) {
			// ⚠ REDACTED IS NOT PURGED AND IS NOT AN ERROR. The attempt, its type, its
			// state and its timestamps all survived the erasure and still say what
			// happened — only who it went to and what it said are gone. Rendering this
			// blank, or as "unknown error", asserts a failure that did not occur.
			$lines[] = '<span class="extonify-wcep-redacted">'
				. esc_html__( 'The details of this attempt were removed in response to a personal-data erasure request. The delivery itself is unchanged.', 'extonify-custom-emails-per-product' )
				. '</span>';
		}

		$recipient = self::personal_value( $detail, 'recipient' );

		if ( '' !== $recipient ) {
			$types_map = FieldOptions::recipient_types();
			$kind      = (string) ( $detail['recipient_type'] ?? '' );

			$lines[] = esc_html(
				sprintf(
					/* translators: 1: recipient kind, e.g. "To", 2: an email address. */
					__( '%1$s: %2$s', 'extonify-custom-emails-per-product' ),
					$types_map[ $kind ] ?? $kind,
					$recipient
				)
			);
		}

		foreach ( self::optional_lines( $detail ) as $line ) {
			$lines[] = $line;
		}

		$when = self::format_time( (string) ( $detail['created_at'] ?? '' ) );

		if ( '' !== $when ) {
			$lines[] = '<span class="description">' . esc_html( $when ) . '</span>';
		}

		return '<li class="extonify-wcep-attempt">' . implode( '<br />', $lines ) . '</li>';
	}

	/**
	 * The subject, reason and failure-message lines, where each was recorded.
	 *
	 * ⚠ EVERY ONE OF THESE IS CUSTOMER-INFLUENCED. `failure_message` is an SMTP
	 * server's response and quotes the address back; `reason` embeds addresses, names
	 * and order values. `esc_html()` on each, at the point of output.
	 *
	 * @param array $detail Attempt row.
	 * @return string[] Escaped HTML lines.
	 */
	private static function optional_lines( array $detail ): array {
		$fields = array(
			/* translators: %s: an email subject line. */
			'subject'         => __( 'Subject: %s', 'extonify-custom-emails-per-product' ),
			/* translators: %s: why the delivery ended the way it did. */
			'reason'          => __( 'Reason: %s', 'extonify-custom-emails-per-product' ),
			/* translators: %s: the failure detail the mail server reported. */
			'failure_message' => __( 'Failure: %s', 'extonify-custom-emails-per-product' ),
		);

		$lines = array();

		foreach ( $fields as $field => $format ) {
			$value = self::personal_value( $detail, $field );

			if ( '' === $value ) {
				continue;
			}

			$class = 'failure_message' === $field ? ' class="extonify-wcep-failure"' : '';

			$lines[] = '<span' . $class . '>' . esc_html( sprintf( $format, $value ) ) . '</span>';
		}

		return $lines;
	}

	/**
	 * One personal field's displayable value, or '' when there is nothing to show.
	 *
	 * @param array  $detail Attempt row.
	 * @param string $field  Column name.
	 * @return string
	 */
	private static function personal_value( array $detail, string $field ): string {
		$value = $detail[ $field ] ?? null;

		if ( null === $value ) {
			return '';
		}

		return trim( (string) $value );
	}

	/**
	 * Whether an attempt row has been through the privacy eraser (ADR-0018 §4b).
	 *
	 * ⚠ THE MARKERS ARE THE TWO COLUMNS THE WRITE PATH ALWAYS POPULATES.
	 * `recipient_type` is validated against `Recipient::TYPES` and defaults to `to`,
	 * and `reason` defaults to the empty string — so NEITHER is ever null on a row
	 * this plugin wrote, and either being null means the eraser has been here.
	 * `recipient`, `subject` and `failure_message` are all legitimately null on an
	 * untouched row (no CC, no subject recorded, nothing failed), so testing those
	 * would report an ordinary delivery as redacted.
	 *
	 * ⚠ EITHER, NOT BOTH. `Privacy\SubjectData` scopes an erasure to CONTACT fields,
	 * CONTENT fields, or both, because a row addressed to a third party keeps the
	 * contact fields that belong to that other person. A partial erasure is still an
	 * erasure and must still say so.
	 *
	 * @param array $detail Attempt row.
	 * @return bool
	 */
	public static function is_redacted( array $detail ): bool {
		return ! isset( $detail['recipient_type'] ) || ! isset( $detail['reason'] );
	}

	/**
	 * The retention note shown beneath both surfaces (ADR-0018 §4d).
	 *
	 * ⚠ IT STATES THE WINDOWS **AND** THAT NOTHING CURRENTLY APPLIES THEM, WHICH IS
	 * THE ONLY HONEST FORM THIS NOTE CAN TAKE TODAY. ADR-0005 specifies 90/180/14 days
	 * purged by a daily recurring action; that action does not exist —
	 * `DeliveryDetailRepository::purge_older_than()` has no production caller,
	 * `Install\Maintenance` says so in terms, and `docs/p2-backlog.md` has recorded it
	 * since Prompt 1a. A screen announcing those windows as current behaviour would
	 * tell every merchant something untrue about their own data, which is the same
	 * class of defect as rendering a purged row blank.
	 *
	 * @return string Escaped HTML.
	 */
	public static function retention_note(): string {
		$windows = esc_html(
			sprintf(
				/* translators: 1: days a normal delivery's details are kept, 2: days a failed delivery's are kept, 3: days a debug entry's are kept. */
				__( 'Delivery details are kept for %1$d days, or %2$d days for a failed delivery, and %3$d days for debug entries.', 'extonify-custom-emails-per-product' ),
				self::RETENTION_NORMAL_DAYS,
				self::RETENTION_FAILED_DAYS,
				self::RETENTION_DEBUG_DAYS
			)
		);

		$today = esc_html__( 'Automatic clearing is not scheduled yet, so details are currently removed only by a personal-data erasure request.', 'extonify-custom-emails-per-product' );

		$durable = esc_html__( 'The record that a delivery happened is always kept, so the same automatic email is never sent twice for one order.', 'extonify-custom-emails-per-product' );

		return '<p class="description extonify-wcep-retention">' . $windows . ' ' . $today . ' ' . $durable . '</p>';
	}

	/**
	 * A stored UTC timestamp in the site's own timezone and format.
	 *
	 * ⚠ THE COLUMN IS UTC — `current_time( 'mysql', true )` wrote it — AND A MERCHANT
	 * READS LOCAL TIME. Rendering the stored string raw would silently misreport every
	 * delivery by the site's offset, which on a support screen is the difference
	 * between "before the customer complained" and "after".
	 *
	 * ⚠ COSTS NO QUERY. Both format options are autoloaded, so they ride along in a
	 * blob WordPress has already fetched.
	 *
	 * @param string $utc `Y-m-d H:i:s` UTC, or an empty/zero value.
	 * @return string Unescaped; the caller escapes it.
	 */
	public static function format_time( string $utc ): string {
		$utc = trim( $utc );

		if ( '' === $utc || 0 === strpos( $utc, '0000-00-00' ) ) {
			return '';
		}

		$timestamp = strtotime( $utc . ' UTC' );

		if ( false === $timestamp ) {
			return '';
		}

		return (string) wp_date(
			(string) get_option( 'date_format', 'Y-m-d' ) . ' ' . (string) get_option( 'time_format', 'H:i' ),
			$timestamp
		);
	}

	/**
	 * The cell for a value the delivery simply never recorded.
	 *
	 * Distinct from purged and from redacted, and it has to be: those two say
	 * something WAS here and is gone.
	 *
	 * @return string Escaped HTML.
	 */
	public static function not_recorded(): string {
		return '<span class="extonify-wcep-muted" aria-hidden="true">&#8212;</span>'
			. '<span class="screen-reader-text">' . esc_html__( 'Not recorded', 'extonify-custom-emails-per-product' ) . '</span>';
	}

	/**
	 * Convert one `Y-m-d` date, entered in the site's timezone, into the UTC bound
	 * the repository filters on.
	 *
	 * ⚠ THE CONVERSION HAPPENS HERE, ONCE, AND NOT IN SQL. The columns are UTC and
	 * the merchant typed local time; a filter that compared them directly would drop
	 * deliveries near midnight by the site's offset and look like an off-by-one nobody
	 * could reproduce in UTC+0.
	 *
	 * @param string $date  `Y-m-d`, or '' for no bound.
	 * @param bool   $is_end Whether this is the inclusive upper bound.
	 * @return string `Y-m-d H:i:s` UTC, or '' when the input is not a date.
	 */
	public static function date_bound_to_utc( string $date, bool $is_end ): string {
		$date = trim( $date );

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}

		return get_gmt_from_date( $date . ( $is_end ? ' 23:59:59' : ' 00:00:00' ) );
	}

	/**
	 * Both plugin delivery tables, for the read-only assertions and for tests.
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		return array( Migrator::table( 'deliveries' ), Migrator::table( 'delivery_details' ) );
	}

	/**
	 * The delivery repository.
	 *
	 * @return DeliveryRepository
	 */
	public static function deliveries(): DeliveryRepository {
		return Plugin::instance()->deliveries();
	}
}
