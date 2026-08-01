<?php
/**
 * The five injection positions and their hook-class discipline (ADR-0003, ADR-0013 §4).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Render;

use Extonify\WCEP\Delivery\InsertPhase;
use Extonify\WCEP\Delivery\PlaceholderResolver;
use Extonify\WCEP\Delivery\PlaceholderValues;
use Extonify\WCEP\Domain\PlaceholderSyntax;
use Extonify\WCEP\Domain\Text;

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
	 * Placeholder resolution (ADR-0014). PER REQUEST and stateless: it hands out
	 * one value set per render — and, at the per-item position, one per LINE ITEM
	 * (§5a) — over the insert phase's own product cache. That cache is the one this
	 * render's evaluation at frame push already filled, which is what keeps product
	 * placeholders at zero queries under the ADR-0014 §8 cost contract.
	 *
	 * @var PlaceholderResolver
	 */
	private $placeholders;

	/**
	 * Constructor.
	 *
	 * @param RenderContext            $context      Frame stack.
	 * @param RenderLedger             $ledger       Slot ledger.
	 * @param PlaceholderResolver|null $placeholders Placeholder resolution.
	 */
	public function __construct( RenderContext $context, RenderLedger $ledger, ?PlaceholderResolver $placeholders = null ) {
		$this->context      = $context;
		$this->ledger       = $ledger;
		$this->placeholders = null !== $placeholders ? $placeholders : new PlaceholderResolver();
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

			// ⚠ AND THE CONTENT RESOLVES AGAINST **THIS** ITEM (ADR-0014 §5a). This
			// position exists to put content beside each matched item; item-scoped
			// placeholders resolving against the first matched item printed item A's
			// gift note beside item B.
			$this->emit( $render, $entry, self::SHARED_POSITION, (bool) $plain_text, $item_id );
		}
	}

	/**
	 * THE PER-RULE CONTAINMENT BOUNDARY (ADR-0014 §10).
	 *
	 * ⚠ THIS FILE USED TO CONTAIN NO `try` AT ALL, AND IT RUNS INSIDE WOOCOMMERCE'S
	 * OWN RENDERING HOOKS. A `Throwable` from placeholder resolution — from
	 * `extonify_wcep_meta_placeholder_allowed`, which ADR-0014 §6.4 introduced, or
	 * from any WooCommerce formatter's filters — propagated straight out of
	 * `woocommerce_email_after_order_table` and ABORTED THE MERCHANT'S OWN
	 * processing, completed or admin email. This plugin breaking WooCommerce's mail
	 * is a strictly worse outcome than this plugin's own content going missing.
	 *
	 * SO CONTAINMENT IS PER RULE, AND EVERY PART OF THAT MATTERS:
	 *
	 *   - the throwing rule emits NOTHING — the render happens into a variable and
	 *     is echoed only once it has succeeded, so a half-substituted body can
	 *     never reach the customer;
	 *   - the NATIVE EMAIL CONTINUES, because the throw stops here;
	 *   - every OTHER matched rule still emits, because the loop that calls this is
	 *     outside the boundary;
	 *   - and the failure is RECORDED against that rule as `not_rendered` — which is
	 *     the rule's RENDER outcome and a SEPARATE FACT from what happened to the
	 *     message (ADR-0014 §10c). It never replaces the message outcome: a message
	 *     that was `abandoned` stays `abandoned` even though this rule's content
	 *     never got into it, because collapsing the two made an abandoned render
	 *     count as a genuine delivery attempt.
	 *
	 * Registration is a SET keyed by rule id (ADR-0013 §1), so a rule reaching this
	 * twice within one render still records once.
	 *
	 * @param array  $render     Current render record.
	 * @param array  $entry      Matched `{rule, rule_id, matched_items}` entry.
	 * @param string $position   Position emitting.
	 * @param bool   $plain_text Whether the plain-text template is rendering.
	 * @param int    $item_id    Line item being rendered at the per-item position,
	 *                           or 0 everywhere else (ADR-0014 §5a).
	 * @return void
	 */
	private function emit( array $render, array $entry, string $position, bool $plain_text, int $item_id = 0 ): void {
		$rule    = $entry['rule'];
		$content = (string) ( $rule['content'] ?? '' );

		if ( '' === trim( $content ) ) {
			return;
		}

		/*
		 * DECLARED BEFORE ANYTHING CAN THROW, so the catch reports what THIS emission
		 * had actually established when it failed rather than assuming (ADR-0014 §10a):
		 *
		 *   - `$values` is the LIVE value set. It used to be a local inside
		 *     `render_and_emit()` and the catch passed `null`, so an unknown token or a
		 *     refused meta key noticed BEFORE the throw vanished from the record and
		 *     the merchant got the exception with no sight of the earlier problem
		 *     (ADR-0014 §1c). It is a by-reference local rather than a property
		 *     because this object is request-shared and renders nest (ADR-0012 §11);
		 *   - `$emitted` is set true only once the echo has RETURNED, so it states
		 *     whether output reached the message rather than whether a render was
		 *     attempted (ADR-0014 §10b).
		 */
		$values  = null;
		$emitted = false;

		try {
			$this->render_and_emit( $render, $entry, $position, $plain_text, $item_id, $content, $values, $emitted );
		} catch ( \Throwable $error ) {
			/*
			 * `\Throwable`, not `\Exception`: a TypeError from a badly-typed
			 * third-party filter aborts WooCommerce's email exactly as thoroughly.
			 */
			$this->register(
				$render,
				$entry,
				$position,
				$values,
				// PER EMISSION, NOT PER RULE, and the wording says so. At the
				// per-item position one rule emits several times, so "nothing was
				// inserted" would be false for a rule that threw on one line item
				// and rendered on the next. Whether the RULE inserted anything is
				// what `$emitted` decides, one layer down.
				'rendering threw and that block was withheld: ' . get_class( $error ) . ': ' . $error->getMessage(),
				// ⚠ THIS EMISSION'S OWN FACT, NEVER A BLANKET `false` (ADR-0014 §10b).
				// A throw AFTER the echo returned — an output-buffer callback, say —
				// really did put this block in front of the customer, and recording it
				// as withheld would be as untrue as the inverse. The ledger's OR then
				// accumulates across emissions and cannot erase a genuine one.
				$emitted
			);
		}
	}

	/**
	 * Render one rule's content, register it, and echo it.
	 *
	 * RUNS INSIDE self::emit()'s CONTAINMENT BOUNDARY and must only be called from
	 * there.
	 *
	 * ⚠ THE ORDER IS: BUILD THE FINAL STRING, THEN REGISTER, THEN ECHO — AND THE
	 * FIRST TWO USED TO BE THE OTHER WAY ROUND (ADR-0014 §10b). This docblock
	 * claimed "the echo is last, deliberately: everything that can throw has
	 * finished by then", and that claim was FALSE, because the escaping call was
	 * still to come:
	 *
	 *     $this->register( … );                       // ledger: emitted = true
	 *     echo wp_kses_post( wpautop( $html ) );       // ⚠ CAN THROW
	 *
	 * `wp_kses_post()` fires `wp_kses_allowed_html`, and `wpautop()` runs inside
	 * whatever a third party has wrapped around it. A throw there left the ledger
	 * holding `emitted = true`; the catch registered `false`; `register()` ORed the
	 * two and the result stayed `true`. Nothing was printed and the history said
	 * content was inserted — which then travels all the way to a merchant reading
	 * `sent` for a block their customer never saw.
	 *
	 * So EVERY transformation that can throw now completes into a variable BEFORE
	 * the registration, and the echo is genuinely the last statement that runs. The
	 * same ordering applies to the plain-text branch, which has no escaping step
	 * today but must not acquire one silently.
	 *
	 * @param array                  $render     Current render record.
	 * @param array                  $entry      Matched entry.
	 * @param string                 $position   Position emitting.
	 * @param bool                   $plain_text Whether the plain-text template is
	 *                                           rendering.
	 * @param int                    $item_id    Current line item at the per-item
	 *                                           position, else 0.
	 * @param string                 $content    Stored rule content.
	 * @param PlaceholderValues|null $values     THIS emission's value set, by
	 *                                           reference, so a throw still reports
	 *                                           the notes taken before it
	 *                                           (ADR-0014 §1c).
	 * @param bool                   $emitted    Set true only once the echo has
	 *                                           returned (ADR-0014 §10b).
	 * @return void
	 */
	private function render_and_emit( array $render, array $entry, string $position, bool $plain_text, int $item_id, string $content, ?PlaceholderValues &$values = null, bool &$emitted = false ): void {
		/*
		 * PLACEHOLDERS RESOLVE HERE, PER RENDER, AGAINST THE ORDER RECORDED AT
		 * PUSH (ADR-0014 §8, §9). ⚠ NOT against `$email->object`: WooCommerce does
		 * not restore that property after a nested render, so it can name an
		 * entirely different order by now (ADR-0013 §5, flagged). The frame's order
		 * is the only trustworthy source, and it is the same one this render's slot
		 * will be recorded against.
		 *
		 * ⚠ AND AT THE PER-ITEM POSITION IT IS BOUND TO THE ITEM BEING RENDERED
		 * (ADR-0014 §5a). `$item_id` is 0 everywhere else, which is the
		 * first-matched-item rule §5 has always stated. A fresh value set per item
		 * is required rather than tidy: the set memoises by `name:parameter`, so
		 * reusing one across two line items would answer item B with item A's
		 * memoised value — the very defect this fixes.
		 */
		$order  = isset( $render['order'] ) && $render['order'] instanceof \WC_Order ? $render['order'] : null;
		$values = null === $order
			? null
			: $this->placeholders->for_delivery( $order, (array) ( $entry['matched_items'] ?? array() ), $item_id );

		if ( $plain_text ) {
			/*
			 * FLATTEN FIRST, THEN SUBSTITUTE (ADR-0014 §9). The other order runs
			 * `wp_strip_all_tags()` over the VALUES, so a customer named
			 * `<script>x</script>` loses their name entirely and every `&` in a
			 * value is mangled by the entity handling.
			 *
			 * NOT `esc_html()` EITHER, and that is ADR-0013 §4b rather than an
			 * oversight: every tag is already gone, and this string is going into a
			 * `text/plain` body that is never parsed as HTML, so escaping could only
			 * corrupt it.
			 */
			$text = Text::to_plain_text( $content );
			$text = null === $values ? $text : $values->render( $text, PlaceholderSyntax::CONTEXT_PLAIN );

			// ASSEMBLED FIRST, for the ordering reason above. There is no escaping
			// step in a plain body, so nothing here throws today — the shape is what
			// stops a future one being added ahead of the registration by accident.
			$output = "\n" . $text . "\n";

			$this->register( $render, $entry, $position, $values );

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text email body: to_plain_text() strips all markup and decodes entities, placeholder values are substituted as text, and HTML-context escaping would corrupt a message that is never parsed as HTML. See the comment above.
			echo $output;
			$emitted = true;
			return;
		}

		/*
		 * Values are HTML-escaped AS THEY ARE SUBSTITUTED (ADR-0014 §3), so
		 * `wp_kses_post()` below sees `&lt;script&gt;` and has nothing to strip —
		 * the template is the merchant's and was kses-filtered at storage, and the
		 * values can never contribute markup.
		 */
		$html = null === $values ? $content : $values->render( $content, PlaceholderSyntax::CONTEXT_HTML );

		// ⚠ THE SANITISATION RUNS **BEFORE** THE REGISTRATION, AND THAT IS THE WHOLE
		// FIX. `wp_kses_post()` fires `wp_kses_allowed_html`; a callback there that
		// throws must leave a ledger saying nothing was emitted, which it cannot do
		// once `register()` has already said otherwise (ADR-0014 §10b).
		$output = wp_kses_post( wpautop( $html ) );

		$this->register( $render, $entry, $position, $values );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $output is the return of wp_kses_post() two statements above; escaping is deliberately hoisted ahead of the registration so a throwing wp_kses_allowed_html callback cannot leave the ledger claiming content was emitted.
		echo $output;
		$emitted = true;
	}

	/**
	 * Record this rule against the render's slot, with anything placeholder
	 * resolution had to say (ADR-0014 §1a).
	 *
	 * A SET KEYED BY RULE ID (ADR-0013 §1), so a rule emitting beside two matched
	 * line items records once — and records its notes once with it.
	 *
	 * ⚠ A FAILURE NOTE AND THE PARTIAL NOTES ARE BOTH KEPT (ADR-0014 §1c). This
	 * used to be `'' !== $failure ? $failure : $values->notes_line()` — an
	 * either/or — so a throw DISCARDED everything resolution had already recorded.
	 * A merchant whose template carried an unknown token AND a filter that then
	 * threw saw only the exception, and the earlier problem — the one they could
	 * actually fix — was never written down.
	 *
	 * @param array                  $render   Current render record.
	 * @param array                  $entry    Matched entry.
	 * @param string                 $position Position emitting.
	 * @param PlaceholderValues|null $values   This render's value set, if any.
	 * @param string                 $failure  Failure note when nothing was emitted.
	 * @param bool                   $emitted  Whether content actually reached the
	 *                                         message (ADR-0014 §10).
	 * @return void
	 */
	private function register( array $render, array $entry, string $position, ?PlaceholderValues $values, string $failure = '', bool $emitted = true ): void {
		$notes = null === $values ? '' : $values->notes_line();

		if ( '' !== $failure ) {
			$notes = '' === $notes ? $failure : $failure . '; ' . $notes;
		}

		$this->ledger->register(
			$render['token'],
			(int) $entry['rule_id'],
			(int) ( $entry['rule']['revision'] ?? 0 ),
			$position,
			$notes,
			$emitted
		);
	}

	/**
	 * Flatten stored HTML to plain text, ready to be emitted verbatim.
	 *
	 * MOVED TO `Domain\Text` IN PROMPT 6 AND KEPT HERE AS THE NAME CALLERS KNOW.
	 * Separate mode needed the identical flattening — it had been missing the
	 * entity-decode step entirely (ADR-0014 §9a) — and two copies of a rule about
	 * what WooCommerce deletes from plain bodies is one copy too many.
	 *
	 * @param string $content Stored content.
	 * @return string
	 */
	public static function to_plain_text( string $content ): string {
		return Text::to_plain_text( $content );
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
