<?php
/**
 * The five injection positions and their hook-class discipline (ADR-0003, ADR-0013 §4).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Render;

use Extonify\WCEP\Delivery\InsertPhase;

defined( 'ABSPATH' ) || exit;

/**
 * Puts rule content inside WooCommerce's own emails, and nowhere else.
 *
 * TWO HOOK CLASSES, DIFFERENT GATING, AND THE DIFFERENCE IS NOT COSMETIC
 * (ADR-0003):
 *
 * **SHARED** — `woocommerce_order_item_meta_end` fires inside email templates
 * AND inside front-end order templates: My Account view-order, the thank-you
 * page, order-pay. It carries no `$email` argument, so it cannot tell them
 * apart. It is therefore a HARD NO-OP unless a render frame is active — and a
 * frame can only exist inside an email render. Its fourth parameter is OPTIONAL
 * WITH A DEFAULT, because stale theme and plugin template overrides in the wild
 * still call it with three arguments and a required fourth would throw
 * `ArgumentCountError` on those installs.
 *
 * **EMAIL-ONLY** — before/after order table, order meta, customer details. These
 * never fire on the front end and self-identify from their own arguments, but
 * self-identification is not enough: they MUST validate the email id, the order
 * and the audience before emitting anything. Unconditional emission is
 * prohibited, because one shared `WC_Email` object and one set of permanently
 * registered callbacks serve every email a store sends — a rule targeting
 * `customer_processing_order` must never appear in `admin_new_order`.
 *
 * CALLBACKS ARE REGISTERED ONCE, AT BOOT, AND NEVER PER SEND. Sends nest, batch
 * and interleave; a callback added and removed around one send would still be
 * live during another that overlapped it.
 */
class Injector {

	/**
	 * Injection positions: stored `insert_position` => hook that emits it.
	 *
	 * FIVE POSITIONS, ONE OF WHICH IS SHARED. `item_meta` is the per-item
	 * position and the only one on a hook that also fires on the storefront.
	 */
	const POSITIONS = array(
		'before_order_table' => 'woocommerce_email_before_order_table',
		'after_order_table'  => 'woocommerce_email_after_order_table',
		'order_meta'         => 'woocommerce_email_order_meta',
		'customer_details'   => 'woocommerce_email_customer_details',
		'item_meta'          => 'woocommerce_order_item_meta_end',
	);

	/**
	 * The position used when a rule does not name one.
	 */
	const DEFAULT_POSITION = 'after_order_table';

	/**
	 * The one SHARED position (ADR-0003).
	 */
	const SHARED_POSITION = 'item_meta';

	/**
	 * Positions that fire INSIDE the ADR-0003 frame window (ADR-0013 §4a, §4c).
	 *
	 * These are bracketed by `woocommerce_email_order_details` priorities 5 and 15,
	 * so the LIVE FRAME is what says which render is emitting — see
	 * `RenderContext::frame_render()` for why the render-record stack must not be
	 * asked instead. The two that are NOT here (`order_meta`, `customer_details`)
	 * fire after the frame is popped and are the whole reason render records exist.
	 */
	const IN_FRAME_POSITIONS = array( 'before_order_table', 'after_order_table', self::SHARED_POSITION );

	/**
	 * The render context.
	 *
	 * @var RenderContext
	 */
	private $context;

	/**
	 * The finalization ledger.
	 *
	 * @var RenderLedger
	 */
	private $ledger;

	/**
	 * Constructor.
	 *
	 * @param RenderContext $context Frame stack.
	 * @param RenderLedger  $ledger  Slot ledger.
	 */
	public function __construct( RenderContext $context, RenderLedger $ledger ) {
		$this->context = $context;
		$this->ledger  = $ledger;
	}

	/**
	 * Whether a stored position string is one this plugin emits.
	 *
	 * @param string $position Stored `insert_position`.
	 * @return string Normalised position; the default when unrecognised.
	 */
	public static function normalize_position( string $position ): string {
		$position = sanitize_key( $position );

		return array_key_exists( $position, self::POSITIONS ) ? $position : self::DEFAULT_POSITION;
	}

	/**
	 * Emit every rule assigned to one NON-shared position.
	 *
	 * VALIDATES BEFORE EMITTING, ALWAYS (ADR-0003, ADR-0013 §4): the email id,
	 * the order and the audience must all match the frame this render pushed.
	 *
	 * @param string $position      Position being rendered.
	 * @param mixed  $email         Email object supplied by the hook.
	 * @param mixed  $order         Order supplied by the hook.
	 * @param bool   $sent_to_admin Audience supplied by the hook.
	 * @param bool   $plain_text    Format supplied by the hook.
	 * @return void
	 */
	public function emit_email_only( string $position, $email, $order, $sent_to_admin, $plain_text ): void {
		/*
		 * WHICH SOURCE IS AUTHORITATIVE DEPENDS ON WHEN THE POSITION FIRES, and that
		 * split is ADR-0013 §4a's, not a new idea (§4c).
		 *
		 * IN-FRAME (`before_order_table`, `after_order_table`) — bracketed by
		 * `woocommerce_email_order_details` priorities 5 and 15, so the LIVE FRAME
		 * says which render is emitting and no render record can outrank it. Asking
		 * the record stack here let a leaked inner record shadow the render that was
		 * actually emitting; see `RenderContext::frame_render()`.
		 *
		 * POST-FRAME (`order_meta`, `customer_details`) — ⚠ fired by WooCommerce's
		 * template AFTER that action returns, so the frame is already popped and the
		 * render record is the only thing left that knows the render is still able to
		 * emit. `RenderContext::current_render()` performs the same three-way
		 * validation against it.
		 */
		$render = in_array( $position, self::IN_FRAME_POSITIONS, true )
			? $this->context->frame_render( $email, $order, (bool) $sent_to_admin )
			: $this->context->current_render( $email, $order, (bool) $sent_to_admin );

		if ( null === $render ) {
			return;
		}

		foreach ( $render['rules'] as $entry ) {
			$rule = $entry['rule'];

			if ( self::rule_position( $rule ) !== $position ) {
				continue;
			}

			if ( ! self::targets_email( $rule, $render['email_id'] ) ) {
				continue;
			}

			$this->emit( $render, $entry, $position, (bool) $plain_text );
		}
	}

	/**
	 * Emit per-item content beside ONE line item — the SHARED hook.
	 *
	 * A HARD NO-OP OUTSIDE AN EMAIL RENDER. This is the storefront guarantee:
	 * the callback is permanently registered and fires on My Account view-order,
	 * the thank-you page and order-pay, where no frame can exist.
	 *
	 * @param mixed $item_id    Line-item id.
	 * @param mixed $item       Line item.
	 * @param mixed $order      Order.
	 * @param mixed $plain_text Format; OPTIONAL, because stale template
	 *                          overrides still call this hook with three
	 *                          arguments.
	 * @return void
	 */
	public function emit_item_meta( $item_id, $item = null, $order = null, $plain_text = false ): void {
		if ( ! $this->context->in_email() ) {
			return;
		}

		$frame = $this->context->current();

		if ( null === $frame ) {
			return;
		}

		// The shared hook carries no `$email`, so the frame is the only thing
		// that can say which order is rendering — and it must agree with the
		// order this item belongs to.
		$order_id = RenderContext::order_id( $order );

		if ( null !== $order_id && $frame['order_id'] !== $order_id ) {
			return;
		}

		$render = $this->context->render_for_token( $frame['token'] );

		if ( null === $render ) {
			return;
		}

		$item_id = (int) $item_id;

		foreach ( $render['rules'] as $entry ) {
			$rule = $entry['rule'];

			if ( self::rule_position( $rule ) !== self::SHARED_POSITION ) {
				continue;
			}

			if ( ! self::targets_email( $rule, $frame['email_id'] ) ) {
				continue;
			}

			// BESIDE THE MATCHED ITEM ONLY. A rule that matched one of three
			// line items renders once, next to that one.
			if ( ! self::matched_item( $entry, $item_id ) ) {
				continue;
			}

			$this->emit( $render, $entry, self::SHARED_POSITION, (bool) $plain_text );
		}
	}

	/**
	 * Render one rule's content and register it against the slot.
	 *
	 * Registration is a SET keyed by rule id (ADR-0013 §1), so a rule reaching
	 * this twice within one render still records once.
	 *
	 * @param array  $render     Current render record.
	 * @param array  $entry      Matched `{rule, rule_id, matched_items}` entry.
	 * @param string $position   Position emitting.
	 * @param bool   $plain_text Whether the plain-text template is rendering.
	 * @return void
	 */
	private function emit( array $render, array $entry, string $position, bool $plain_text ): void {
		$rule    = $entry['rule'];
		$content = (string) ( $rule['content'] ?? '' );

		if ( '' === trim( $content ) ) {
			return;
		}

		$this->ledger->register(
			$render['token'],
			(int) $entry['rule_id'],
			(int) ( $rule['revision'] ?? 0 ),
			$position
		);

		// Content is stored through `wp_kses_post` at the repository boundary
		// (ADR-0012 §6) and is rendered LITERALLY — placeholders do not exist
		// yet, so `{customer_name}` is delivered as those characters.
		if ( $plain_text ) {
			/*
			 * NOT `esc_html()`, AND THAT IS THE FIX RATHER THAN AN OVERSIGHT
			 * (ADR-0013 §4b). self::to_plain_text() has already removed every tag,
			 * so there is no markup left for HTML escaping to protect; and this
			 * string is going into a `text/plain` message body that is never
			 * parsed as HTML, so escaping only corrupts it. A merchant writing
			 * `Care for A & B` was sending `Care for A &amp; B` to the customer.
			 * The HTML branch below keeps its escaping, unchanged.
			 */
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email body: to_plain_text() strips all markup and decodes entities, and HTML-context escaping would corrupt a message that is never parsed as HTML. See the comment above.
			echo "\n" . self::to_plain_text( $content ) . "\n";
			return;
		}

		echo wp_kses_post( wpautop( $content ) );
	}

	/**
	 * Flatten stored HTML to plain text, ready to be emitted verbatim.
	 *
	 * FOUR STEPS, IN THIS ORDER, AND THE ORDER MATTERS:
	 *
	 *   1. block-ish tags become newlines, so the shape of the block survives;
	 *   2. every remaining tag is removed;
	 *   3. entities are DECODED — `&amp;` back to `&`, `&#8212;` back to an
	 *      em dash. This is the step that used to be missing while the caller
	 *      additionally ran `esc_html()`, which encoded them a second time;
	 *   4. line endings are normalised to `\n`.
	 *
	 * Decoding AFTER stripping is deliberate: decoding first could reveal
	 * character sequences that step 2 would then eat, so `<3` written by a
	 * merchant as `&lt;3` would silently vanish.
	 *
	 * @param string $content Stored content.
	 * @return string
	 */
	public static function to_plain_text( string $content ): string {
		$text = preg_replace( '/<\s*br\s*\/?\s*>/i', "\n", $content );
		$text = preg_replace( '/<\s*\/\s*(p|div|li|h[1-6])\s*>/i', "\n", (string) $text );
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\r\n|\r/', "\n", $text );

		return trim( (string) $text );
	}

	/**
	 * The position a rule is configured for.
	 *
	 * @param array $rule Rule row.
	 * @return string
	 */
	public static function rule_position( array $rule ): string {
		return self::normalize_position( (string) ( $rule['insert_position'] ?? '' ) );
	}

	/**
	 * Whether a rule targets the email currently rendering.
	 *
	 * Belt and braces: the fetch already selected on `native_email_id`, and this
	 * is checked again at the point of emission because ADR-0003 requires the
	 * email-only callbacks to validate rather than assume.
	 *
	 * @param array  $rule     Rule row.
	 * @param string $email_id Email id currently rendering.
	 * @return bool
	 */
	private static function targets_email( array $rule, string $email_id ): bool {
		return '' !== $email_id && sanitize_key( (string) ( $rule['native_email_id'] ?? '' ) ) === $email_id;
	}

	/**
	 * Whether a matched entry includes one line item.
	 *
	 * @param array $entry   Matched entry.
	 * @param int   $item_id Line-item id.
	 * @return bool
	 */
	private static function matched_item( array $entry, int $item_id ): bool {
		foreach ( (array) ( $entry['matched_items'] ?? array() ) as $item ) {
			if ( (int) ( $item['item_id'] ?? 0 ) === $item_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Positions that are email-only, i.e. everything except the shared one.
	 *
	 * @return array<string,string>
	 */
	public static function email_only_positions(): array {
		$positions = self::POSITIONS;
		unset( $positions[ self::SHARED_POSITION ] );

		return $positions;
	}

	/**
	 * The insert phase's noise-floor reason, re-exported so callers do not reach
	 * across namespaces for it.
	 *
	 * @return string
	 */
	public static function noise_floor_reason(): string {
		return InsertPhase::noise_floor_reason();
	}
}
