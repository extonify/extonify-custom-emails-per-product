<?php
/**
 * The rules list table (ADR-0017 §7).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

use Extonify\WCEP\Delivery\Consolidation;
use Extonify\WCEP\Domain\Targeting;
use Extonify\WCEP\Domain\TriggerEvent;
use Extonify\WCEP\Email\NativeEmailTargets;
use Extonify\WCEP\Plugin;
use Extonify\WCEP\Repository\RuleRepository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The rules list.
 *
 * ⚠ `WP_List_Table` IS MARKED `@access private` BY CORE AND ADR-0017 §7 ACCEPTS THAT
 * EXPLICITLY, rather than by not noticing. It has been effectively public API for
 * fifteen years, core has never broken its subclass contract, and a break would be a
 * WordPress-wide event rather than a surprise landing on this plugin. Only the
 * documented-by-convention surface is used — `prepare_items()`, `get_columns()`,
 * `get_sortable_columns()`, `column_default()`, `column_{name}()`,
 * `set_pagination_args()`, `display()` — no private method is called and no property
 * is written. The alternative, a hand-rolled table, is MORE risk: pagination,
 * sortable-column URL state and nonce-carrying row actions all re-implemented, none
 * of it exercised by anybody else's install.
 *
 * ⚠ NO BULK ACTIONS IN v1.0 (ADR-0017 §7). `get_bulk_actions()` returns an empty
 * array, so no bulk UI renders and there is no fourth destructive entry point to
 * secure, to nonce or to enumerate in gate 28.
 *
 * ⚠ EVERY CELL IS ESCAPED AT ITS POINT OF OUTPUT, for its own context (gate 31). A
 * rule's name, subject and heading are merchant-authored free text that reached
 * storage through `sanitize_text_field()`, which is not an escaping function and
 * never was.
 */
final class RulesListTable extends \WP_List_Table {

	/**
	 * Rules shown per page.
	 */
	const PER_PAGE = 20;

	/**
	 * How many target names one cell names before saying "+N more" (ADR-0017 §9).
	 */
	const NAMES_PER_KIND = 3;

	/**
	 * Resolved id => label for the whole page, kind-keyed.
	 *
	 * @var array
	 */
	private $labels = array();

	/**
	 * How many rules match the current filters.
	 *
	 * @var int
	 */
	private $total = 0;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'extonify_wcep_rule',
				'plural'   => 'extonify_wcep_rules',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns, in order.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'name'          => __( 'Name', 'extonify-custom-emails-per-product' ),
			'status'        => __( 'Status', 'extonify-custom-emails-per-product' ),
			'trigger'       => __( 'Trigger', 'extonify-custom-emails-per-product' ),
			'targets'       => __( 'Targets', 'extonify-custom-emails-per-product' ),
			'delivery_mode' => __( 'Mode', 'extonify-custom-emails-per-product' ),
			'delay'         => __( 'Delay', 'extonify-custom-emails-per-product' ),
			'consolidation' => __( 'Emails', 'extonify-custom-emails-per-product' ),
			'priority'      => __( 'Priority', 'extonify-custom-emails-per-product' ),
		);
	}

	/**
	 * Sortable columns => `[orderby, is-descending-first]`.
	 *
	 * Every key is a member of `RuleRepository::ORDERABLE_COLUMNS`, so a sort link
	 * can never name a column the repository will not order by.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'name'          => array( 'name', false ),
			'status'        => array( 'status', false ),
			'trigger'       => array( 'trigger_type', false ),
			'delivery_mode' => array( 'delivery_mode', false ),
			'delay'         => array( 'delay_seconds', false ),
			'consolidation' => array( 'consolidation', false ),
			'priority'      => array( 'priority', false ),
		);
	}

	/**
	 * No bulk actions in v1.0 (ADR-0017 §7).
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array();
	}

	/**
	 * The first-run empty state.
	 *
	 * ⚠ NO UPSELL, HERE OR ANYWHERE (ADR-0001). An empty state is the one screen a
	 * merchant reaches before they have decided anything, which is exactly why a free
	 * plugin has no business putting a sales pitch on it.
	 *
	 * @return void
	 */
	public function no_items() {
		// A filtered-to-nothing list is not a first run, and telling a merchant what a
		// rule is when they already have forty would be noise.
		if ( $this->is_filtered() ) {
			esc_html_e( 'No rules match these filters.', 'extonify-custom-emails-per-product' );
			return;
		}

		echo '<div class="extonify-wcep-empty">';
		echo '<h2>' . esc_html__( 'No custom product emails yet', 'extonify-custom-emails-per-product' ) . '</h2>';
		echo '<p>' . esc_html__( 'A rule decides which products get an extra email, when it is sent, and what it says. It can send a separate message, or add its content to an email WooCommerce already sends.', 'extonify-custom-emails-per-product' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( Menu::url( array( 'action' => Menu::ACTION_NEW ) ) ) . '">'
			. esc_html__( 'Create your first rule', 'extonify-custom-emails-per-product' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Fetch the page (ADR-0017 §8).
	 *
	 * ⚠ FILTERING, ORDERING AND PAGING HAPPEN IN SQL. Nothing here loads a rule it is
	 * not going to show.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$args = self::query_args( $this->get_pagenum() );

		$rules = Plugin::instance()->rules();

		$this->total = $rules->count( $args );
		$this->items = $rules->query( $args );

		// ⚠ ONE BATCH FOR THE WHOLE PAGE (ADR-0017 §9): two queries, whatever the page
		// holds. Resolving names inside column_targets() would be one query per id per
		// rule per page.
		$documents = array();

		foreach ( $this->items as $rule ) {
			$targeting = Targeting::from_value( $rule[ 'targeting' . RuleRepository::RAW_SUFFIX ] ?? null );

			$documents[] = $targeting->includes();
			$documents[] = $targeting->excludes();
		}

		$this->labels = TargetLabels::resolve( TargetLabels::collect( $documents, self::NAMES_PER_KIND ) );

		$this->set_pagination_args(
			array(
				'total_items' => $this->total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $this->total / self::PER_PAGE ),
			)
		);
	}

	/**
	 * The repository arguments this request asks for.
	 *
	 * ⚠ EVERY ONE IS SANITISED AND NONE IS TRUSTED. `orderby` and `order` reach an
	 * `ORDER BY` clause, which `prepare()` cannot bind — the repository allowlists
	 * both (ADR-0017 §8), and this reads them as keys rather than as SQL.
	 *
	 * @param int $page 1-based page number.
	 * @return array
	 */
	public static function query_args( int $page ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only, idempotent listing filter carried in the URL; there is no state change here to protect and a nonce would break bookmarking and paging links.
		$args = array(
			'status'        => isset( $_GET['rule_status'] ) ? sanitize_text_field( wp_unslash( $_GET['rule_status'] ) ) : '',
			'trigger_type'  => isset( $_GET['rule_trigger'] ) ? sanitize_text_field( wp_unslash( $_GET['rule_trigger'] ) ) : '',
			'delivery_mode' => isset( $_GET['rule_mode'] ) ? sanitize_text_field( wp_unslash( $_GET['rule_mode'] ) ) : '',
			'search'        => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'orderby'       => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'priority',
			'order'         => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'asc',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$args['limit']  = self::PER_PAGE;
		$args['offset'] = ( max( 1, $page ) - 1 ) * self::PER_PAGE;

		return $args;
	}

	/**
	 * The filter controls above the table.
	 *
	 * @param string $which `top` or `bottom`.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only listing filters; see self::query_args().
		$selected = array(
			'rule_status'  => isset( $_GET['rule_status'] ) ? sanitize_text_field( wp_unslash( $_GET['rule_status'] ) ) : '',
			'rule_trigger' => isset( $_GET['rule_trigger'] ) ? sanitize_text_field( wp_unslash( $_GET['rule_trigger'] ) ) : '',
			'rule_mode'    => isset( $_GET['rule_mode'] ) ? sanitize_text_field( wp_unslash( $_GET['rule_mode'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="alignleft actions">';

		self::filter_select( 'rule_status', __( 'All statuses', 'extonify-custom-emails-per-product' ), __( 'Filter by status', 'extonify-custom-emails-per-product' ), FieldOptions::statuses(), $selected['rule_status'] );
		self::filter_select( 'rule_trigger', __( 'All triggers', 'extonify-custom-emails-per-product' ), __( 'Filter by trigger', 'extonify-custom-emails-per-product' ), FieldOptions::trigger_types(), $selected['rule_trigger'] );
		self::filter_select( 'rule_mode', __( 'All modes', 'extonify-custom-emails-per-product' ), __( 'Filter by delivery mode', 'extonify-custom-emails-per-product' ), FieldOptions::delivery_modes(), $selected['rule_mode'] );

		submit_button( __( 'Filter', 'extonify-custom-emails-per-product' ), '', 'filter_action', false );

		echo '</div>';
	}

	/**
	 * One labelled filter dropdown.
	 *
	 * ⚠ THE `<label>` IS REAL AND SCREEN-READER-ONLY, NOT A `title` ATTRIBUTE
	 * (gate 33). An unlabelled select in a row of three is "combo box" three times to
	 * anyone not looking at the screen.
	 *
	 * @param string               $name     Query argument.
	 * @param string               $all      The "no filter" option's label.
	 * @param string               $label    The control's accessible name.
	 * @param array<string,string> $options  Value => label.
	 * @param string               $selected Current value.
	 * @return void
	 */
	private static function filter_select( string $name, string $all, string $label, array $options, string $selected ): void {
		echo '<label class="screen-reader-text" for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '">';
		echo '<option value="">' . esc_html( $all ) . '</option>';

		foreach ( $options as $value => $text ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $selected, (string) $value, false ) . '>'
				. esc_html( (string) $text ) . '</option>';
		}

		echo '</select>';
	}

	/**
	 * Whether the merchant narrowed the list.
	 *
	 * @return bool
	 */
	private function is_filtered(): bool {
		$args = self::query_args( 1 );

		foreach ( array( 'status', 'trigger_type', 'delivery_mode', 'search' ) as $key ) {
			if ( '' !== (string) $args[ $key ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The name cell, with the row actions.
	 *
	 * @param array $item Rule row.
	 * @return string
	 */
	public function column_name( $item ) {
		$rule_id = (int) ( $item['id'] ?? 0 );
		$name    = (string) ( $item['name'] ?? '' );
		$edit    = Menu::url(
			array(
				'action' => Menu::ACTION_EDIT,
				'rule'   => $rule_id,
			)
		);

		$title = '' !== trim( $name )
			? esc_html( $name )
			/* translators: %d: rule id. */
			: '<em>' . esc_html( sprintf( __( 'Rule #%d (no name)', 'extonify-custom-emails-per-product' ), $rule_id ) ) . '</em>';

		$is_active = 'active' === (string) ( $item['status'] ?? '' );

		$actions = array(
			'edit'      => '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'extonify-custom-emails-per-product' ) . '</a>',
			// ⚠ A PLAIN GET LINK WITH NO NONCE, AND THAT IS CORRECT (ADR-0020 §5). Preview
			// writes nothing and sends nothing, so it is not a row action in the sense the
			// three below are — there is no state for a nonce to protect, and gate 36's
			// prohibition is on GET links that SEND.
			'preview'   => '<a href="' . esc_url( RulePreviewScreen::url( $rule_id ) ) . '">'
				. esc_html__( 'Preview', 'extonify-custom-emails-per-product' ) . '</a>',
			'duplicate' => '<a href="' . esc_url( Menu::row_action_url( RuleActions::ACTION_DUPLICATE, $rule_id ) ) . '">'
				. esc_html__( 'Duplicate', 'extonify-custom-emails-per-product' ) . '</a>',
			'toggle'    => '<a href="' . esc_url(
				Menu::row_action_url( $is_active ? RuleActions::ACTION_DEACTIVATE : RuleActions::ACTION_ACTIVATE, $rule_id )
			) . '">' . ( $is_active
				? esc_html__( 'Disable', 'extonify-custom-emails-per-product' )
				: esc_html__( 'Enable', 'extonify-custom-emails-per-product' ) ) . '</a>',
			// ⚠ THE CONFIRMATION IS `data-`-DRIVEN, NOT AN INLINE `onclick`. The
			// admin script binds it, so the page needs no inline handler and the
			// message stays translatable.
			'delete'    => '<a href="' . esc_url( Menu::row_action_url( RuleActions::ACTION_DELETE, $rule_id ) ) . '"'
				. ' class="submitdelete extonify-wcep-confirm"'
				. ' data-extonify-wcep-confirm="' . esc_attr(
					sprintf(
						/* translators: %s: rule name. */
						__( 'Delete the rule "%s"? Any emails it has queued but not yet sent are cancelled. This cannot be undone.', 'extonify-custom-emails-per-product' ),
						'' !== trim( $name ) ? $name : (string) $rule_id
					)
				) . '">'
				. esc_html__( 'Delete', 'extonify-custom-emails-per-product' ) . '</a>',
		);

		return '<strong><a class="row-title" href="' . esc_url( $edit ) . '">' . $title . '</a></strong>'
			. $this->row_actions( $actions );
	}

	/**
	 * The status cell.
	 *
	 * @param array $item Rule row.
	 * @return string
	 */
	public function column_status( $item ) {
		$status   = (string) ( $item['status'] ?? '' );
		$statuses = FieldOptions::statuses();

		return '<span class="extonify-wcep-status extonify-wcep-status--' . esc_attr( sanitize_html_class( $status ) ) . '">'
			. esc_html( $statuses[ $status ] ?? $status ) . '</span>';
	}

	/**
	 * The trigger cell.
	 *
	 * @param array $item Rule row.
	 * @return string
	 */
	public function column_trigger( $item ) {
		$mode = (string) ( $item['delivery_mode'] ?? '' );

		if ( 'insert' === $mode ) {
			// ADR-0013 §2: an insert rule has no trigger at all — `native_email_id` is
			// the single source of truth for which email it belongs to, and showing a
			// plausible-looking trigger would read as a promise nothing keeps.
			//
			// ⚠ THE UNFILTERED DISPLAY MAP (Prompt 13C Part M). `native_emails()` now
			// returns only the targets an insert rule can REACH, and the whole point of
			// the marker below is a rule whose target is not among them — looking the
			// name up there would print a raw id for exactly the row that most needs a
			// readable one.
			$emails = FieldOptions::native_email_titles();
			$id     = (string) ( $item['native_email_id'] ?? '' );

			$cell = esc_html(
				sprintf(
					/* translators: %s: WooCommerce email name. */
					__( 'Inside: %s', 'extonify-custom-emails-per-product' ),
					$emails[ $id ] ?? $id
				)
			);

			/*
			 * ⚠ SILENCE IS THE ONE UNACCEPTABLE OUTCOME (ADR-0013 §2a). A rule whose
			 * target never renders order details sends nothing and records nothing, so
			 * the list is the only screen a merchant sees it on until they open it. It
			 * reads as active, and until Prompt 13C Part M it looked identical to a rule
			 * that works. The row now says so where the merchant is already looking.
			 */
			if ( '' !== $id && NativeEmailTargets::cannot_render( $id ) ) {
				$cell .= '<br /><span class="extonify-wcep-rule-broken">'
					. esc_html__( 'This email has no order details section, so this rule can never add anything to it.', 'extonify-custom-emails-per-product' )
					. '</span>';
			}

			return $cell;
		}

		$type     = (string) ( $item['trigger_type'] ?? '' );
		$value    = (string) ( $item['trigger_value'] ?? '' );
		$statuses = FieldOptions::order_statuses();

		if ( TriggerEvent::TYPE_REFUND === $type ) {
			return esc_html__( 'Order is refunded', 'extonify-custom-emails-per-product' );
		}

		if ( TriggerEvent::TYPE_TRANSITION === $type ) {
			$sides = explode( '>', $value );
			$from  = (string) ( $sides[0] ?? '' );
			$to    = (string) ( $sides[1] ?? '' );

			return esc_html(
				sprintf(
					/* translators: 1: source order status, 2: destination order status. */
					__( '%1$s → %2$s', 'extonify-custom-emails-per-product' ),
					$statuses[ $from ] ?? $from,
					$statuses[ $to ] ?? $to
				)
			);
		}

		return esc_html(
			sprintf(
				/* translators: %s: order status name. */
				__( 'Reaches %s', 'extonify-custom-emails-per-product' ),
				$statuses[ $value ] ?? $value
			)
		);
	}

	/**
	 * The targets cell (ADR-0017 §9).
	 *
	 * @param array $item Rule row.
	 * @return string
	 */
	public function column_targets( $item ) {
		$targeting = Targeting::from_value( $item[ 'targeting' . RuleRepository::RAW_SUFFIX ] ?? null );

		if ( ! $targeting->is_valid() ) {
			return '<span class="extonify-wcep-invalid">'
				. esc_html__( 'Targeting is unreadable — open the rule and set it again.', 'extonify-custom-emails-per-product' )
				. '</span>';
		}

		$parts = array();

		if ( $targeting->matches_all() ) {
			$parts[] = '<strong>' . esc_html__( 'All products', 'extonify-custom-emails-per-product' ) . '</strong>';
		}

		$include = $this->summarise_side( $targeting->includes() );

		if ( '' !== $include ) {
			$parts[] = $include;
		}

		$exclude = $this->summarise_side( $targeting->excludes() );

		if ( '' !== $exclude ) {
			$parts[] = '<span class="extonify-wcep-exclude">' . esc_html__( 'Except:', 'extonify-custom-emails-per-product' ) . ' ' . $exclude . '</span>';
		}

		if ( array() === $parts ) {
			// A valid document that declares nothing is an INERT rule, not a broken one
			// (ADR-0011 §3) — and a merchant needs telling, because it looks configured.
			return '<span class="extonify-wcep-inert">' . esc_html__( 'Nothing yet — this rule matches no products.', 'extonify-custom-emails-per-product' ) . '</span>';
		}

		return implode( '<br />', $parts );
	}

	/**
	 * The delivery-mode cell.
	 *
	 * @param array $item Rule row.
	 * @return string
	 */
	public function column_delivery_mode( $item ) {
		$modes = FieldOptions::delivery_modes();
		$mode  = (string) ( $item['delivery_mode'] ?? '' );

		return esc_html( $modes[ $mode ] ?? $mode );
	}

	/**
	 * The delay cell.
	 *
	 * @param array $item Rule row.
	 * @return string
	 */
	public function column_delay( $item ) {
		$seconds = max( 0, (int) ( $item['delay_seconds'] ?? 0 ) );

		if ( 0 === $seconds ) {
			return esc_html__( 'Immediately', 'extonify-custom-emails-per-product' );
		}

		return esc_html(
			sprintf(
				/* translators: %s: a human-readable duration, e.g. "2 days". */
				__( 'After %s', 'extonify-custom-emails-per-product' ),
				human_time_diff( 0, $seconds )
			)
		);
	}

	/**
	 * The consolidation cell.
	 *
	 * @param array $item Rule row.
	 * @return string
	 */
	public function column_consolidation( $item ) {
		$value = (string) ( $item['consolidation'] ?? '' );

		if ( ! Consolidation::is_valid( $value ) ) {
			// ADR-0016 §1a: a value outside the vocabulary makes the rule NOT
			// DELIVERABLE, and it is legitimately storable history rather than an
			// exotic case — so it is shown, not hidden behind a fallback label.
			return '<span class="extonify-wcep-invalid">'
				. esc_html__( 'Unrecognised — this rule will not send.', 'extonify-custom-emails-per-product' )
				. '</span>';
		}

		$modes = FieldOptions::consolidation_modes();

		return esc_html( $modes[ $value ] ?? $value );
	}

	/**
	 * Any column with no handler of its own.
	 *
	 * @param array  $item        Rule row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	/**
	 * A readable, BOUNDED summary of one side of a targeting document.
	 *
	 * @param array $side Kind => ids or slugs.
	 * @return string Escaped HTML, or '' when the side declares nothing.
	 */
	private function summarise_side( array $side ): string {
		$kinds = FieldOptions::targeting_kinds();
		$types = FieldOptions::product_types();
		$lines = array();

		foreach ( Targeting::KINDS as $kind ) {
			$entries = array_values( (array) ( $side[ $kind ] ?? array() ) );

			if ( array() === $entries ) {
				continue;
			}

			$shown = array_slice( $entries, 0, self::NAMES_PER_KIND );
			$names = array();

			foreach ( $shown as $entry ) {
				if ( 'types' === $kind ) {
					$names[] = esc_html( (string) ( $types[ (string) $entry ] ?? $entry ) );
					continue;
				}

				$label = $this->labels[ $kind ][ (int) $entry ] ?? null;

				if ( null === $label ) {
					$names[] = esc_html( '#' . (int) $entry );
					continue;
				}

				$names[] = $label['missing']
					? '<span class="extonify-wcep-missing">' . esc_html( $label['label'] ) . '</span>'
					: esc_html( $label['label'] );
			}

			$remaining = count( $entries ) - count( $shown );

			if ( $remaining > 0 ) {
				$names[] = esc_html(
					sprintf(
						/* translators: %d: how many further targets are not listed. */
						_n( '+%d more', '+%d more', $remaining, 'extonify-custom-emails-per-product' ),
						$remaining
					)
				);
			}

			$lines[] = '<em>' . esc_html( $kinds[ $kind ] ?? $kind ) . ':</em> ' . implode( ', ', $names );
		}

		return implode( '<br />', $lines );
	}
}
