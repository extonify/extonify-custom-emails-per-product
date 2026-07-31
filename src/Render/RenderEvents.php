<?php
/**
 * WooCommerce hook wiring for insert mode (ADR-0003, ADR-0013).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Render;

use Extonify\WCEP\Delivery\InsertPhase;

defined( 'ABSPATH' ) || exit;

/**
 * The only place WooCommerce's render hooks reach the insert phase.
 *
 * REGISTRATION IS HOOKS ONLY — zero queries, zero output — matching the
 * discipline `Plugin::init()` and `Delivery\Events` already follow.
 *
 * WHY EVERY CALLBACK IS REGISTERED ONCE AND NEVER PER SEND (ADR-0003): sends
 * nest, batch and interleave within one request, so a callback added and removed
 * around one send would still be live during another that overlapped it. The
 * gating is the render frame, not the registration.
 *
 * THE HOOK MAP, AND WHY EACH PRIORITY IS WHAT IT IS:
 *
 * | Hook | Priority | Role |
 * |---|---|---|
 * | `woocommerce_email_order_details` | 5 | push the frame, evaluate ONCE, open the slot — or REFUSE past the depth bound |
 * | `woocommerce_email_order_details` | 15 | close the frame; ARM the slot `rendering` -> `awaiting_send` |
 * | `woocommerce_email_customer_details` | PHP_INT_MAX | the TERMINAL position: close the emission scope and PROMOTE this render to send candidacy |
 * | `woocommerce_email_footer` | PHP_INT_MAX | HTML backstop for a frame priority 15 missed, and RE-PROMOTION past anything nested from the footer |
 * | `woocommerce_pos_email_footer` | PHP_INT_MAX | the same, for the two POS receipt templates, which fire no `woocommerce_email_footer` at all |
 * | `woocommerce_email_headers` | PHP_INT_MAX | OPEN this send's observation frame and TAKE the exact completed token for it |
 * | `woocommerce_email_attachments` | PHP_INT_MAX | the SECOND observation of that same send; ONE send takes ONE token |
 * | `woocommerce_mail_content` | PHP_INT_MIN | RESERVE that token; message returned UNMODIFIED |
 * | `woocommerce_mail_callback_params` | 9999 | bind the RESERVED token; params returned UNMODIFIED |
 * | `woocommerce_email_sent` | 10 | resolve EXACTLY the bound token, record the REPORTED outcome, and purge that token from every open-token ledger |
 * | `woocommerce_prepare_email_for_preview` | 10 | mark the NEXT render of that object a preview |
 * | `shutdown` | 10 | reconcile, then record abandoned renders and unresolved sends |
 *
 * ⚠ `PHP_INT_MAX` RATHER THAN 999, AND THE OLD COMMENT WAS WRONG (Prompt 5D).
 * Promotion used to sit at 999 with a docblock claiming that ran "after any third
 * party". **WordPress runs lower priority numbers first**, so an ordinary plugin at
 * priority 1000 ran after us — and a render nested from there promoted last and was
 * taken by the enclosing send. What `PHP_INT_MAX` guarantees is stated exactly in
 * ADR-0013 §5e: it is the highest priority available, and a callback registered
 * later at the SAME priority still runs after ours, because same-priority callbacks
 * execute in registration order. That residual is accepted and recorded.
 *
 * ⚠ Priority 15 rather than the footer is load-bearing: plain-text templates
 * never fire `woocommerce_email_footer`, so a footer-based render-complete
 * signal leaks one slot per plain-text email (ADR-0013 §5).
 *
 * ⚠ THE HANDOFF NEEDS TWO HOOKS BECAUSE ONE CANNOT SUPPLY BOTH HALVES.
 * `woocommerce_mail_content` is the first thing `WC_Email::send()` runs, and is
 * therefore the last moment before a third party can render a competing slot —
 * but WooCommerce passes it ONLY the message (WC 10.9.4,
 * `class-wc-email.php:1233`), so it cannot say which email is sending. The
 * object comes instead from `woocommerce_email_headers` and
 * `woocommerce_email_attachments`, which `send_notification()` and
 * `send_if_recipient()` evaluate as the last two arguments to `send()` — i.e.
 * immediately before it is entered, with nothing in between. ⚠ BOTH fire for one
 * send, so `RenderLedger::observe_send()` takes exactly one token per send and the
 * second observation recognises its own.
 */
class RenderEvents {

	/**
	 * The shared render context.
	 *
	 * @var RenderContext|null
	 */
	private static $context = null;

	/**
	 * The shared finalization ledger.
	 *
	 * @var RenderLedger|null
	 */
	private static $ledger = null;

	/**
	 * The shared injector.
	 *
	 * @var Injector|null
	 */
	private static $injector = null;

	/**
	 * The shared insert phase.
	 *
	 * @var InsertPhase|null
	 */
	private static $phase = null;

	/**
	 * Register every hook this phase owns.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'woocommerce_email_order_details', array( self::class, 'on_render_start' ), 5, 4 );
		add_action( 'woocommerce_email_order_details', array( self::class, 'on_render_end' ), 15, 4 );

		/*
		 * BOTH FOOTER HOOKS, AND THE SECOND ONE IS NOT OPTIONAL. ⚠ Verified in
		 * WC 10.9.4: `customer-pos-completed-order.php` and
		 * `customer-pos-refunded-order.php` fire `woocommerce_pos_email_footer`
		 * (lines 122 and 133) and never fire `woocommerce_email_footer`. Without
		 * this registration a POS receipt reached no footer of ours at all — see
		 * self::on_footer() and ADR-0013 §5e.
		 */
		add_action( 'woocommerce_email_footer', array( self::class, 'on_footer' ), PHP_INT_MAX, 1 );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce-owned hook, fired by its own POS receipt templates.
		add_action( 'woocommerce_pos_email_footer', array( self::class, 'on_footer' ), PHP_INT_MAX, 1 );

		/*
		 * THE RENDER'S LAST EMISSION POINT (ADR-0013 §4a). `PHP_INT_MAX` so it runs
		 * after this plugin's own emission at 10 and after every third party on the
		 * hook — 999 does NOT do that, because WordPress runs lower priority numbers
		 * first and an ordinary plugin at 1000 runs later. A nested render gives its
		 * emission scope back here, before control returns to the render that
		 * started it.
		 */
		add_action( 'woocommerce_email_customer_details', array( self::class, 'on_emissions_end' ), PHP_INT_MAX, 4 );

		/*
		 * LAST, so a third party that swaps the email object on either filter has
		 * already done so when this send's frame is opened. TWO CALLBACKS, NOT ONE:
		 * the filter a send is on is the only thing that can pair its two
		 * observations, because identity cannot separate a nested send from its
		 * enclosing send's second filter (ADR-0013 §5g).
		 */
		add_filter( 'woocommerce_email_headers', array( self::class, 'on_send_headers' ), PHP_INT_MAX, 4 );
		add_filter( 'woocommerce_email_attachments', array( self::class, 'on_send_attachments' ), PHP_INT_MAX, 4 );

		// FIRST, so no third-party callback on this same filter can create a
		// competing slot before the reservation is taken.
		add_filter( 'woocommerce_mail_content', array( self::class, 'on_mail_content' ), PHP_INT_MIN, 1 );

		add_filter( 'woocommerce_mail_callback_params', array( self::class, 'on_mail_params' ), 9999, 2 );
		add_action( 'woocommerce_email_sent', array( self::class, 'on_email_sent' ), 10, 3 );

		add_filter( 'woocommerce_prepare_email_for_preview', array( self::class, 'on_prepare_preview' ), 10, 1 );

		self::register_positions();

		add_action( 'shutdown', array( self::class, 'on_shutdown' ), 10, 0 );
	}

	/**
	 * Register the five injection callbacks (ADR-0003's two hook classes).
	 *
	 * @return void
	 */
	private static function register_positions(): void {
		foreach ( Injector::email_only_positions() as $position => $hook ) {
			add_action(
				$hook,
				static function ( $order = null, $sent_to_admin = false, $plain_text = false, $email = null ) use ( $position ) {
					self::injector()->emit_email_only( $position, $email, $order, $sent_to_admin, $plain_text );
				},
				10,
				4
			);
		}

		/*
		 * THE SHARED HOOK. Four parameters requested, but the callback's own
		 * signature defaults every one of them: stale theme and plugin template
		 * overrides in the wild still call this with THREE arguments, and a
		 * required fourth would throw `ArgumentCountError` on those installs
		 * (ADR-0003).
		 */
		add_action(
			Injector::POSITIONS[ Injector::SHARED_POSITION ],
			static function ( $item_id = 0, $item = null, $order = null, $plain_text = false ) {
				self::injector()->emit_item_meta( $item_id, $item, $order, $plain_text );
			},
			10,
			4
		);
	}

	/**
	 * A native email began rendering its order details.
	 *
	 * Pushes the frame, evaluates ONCE (ADR-0013 §3), and opens a slot — unless
	 * this is a preview, which gets a frame and NO slot so that preview safety is
	 * structural rather than conditional.
	 *
	 * @param mixed $order         Order being rendered.
	 * @param mixed $sent_to_admin Audience.
	 * @param mixed $plain_text    Format.
	 * @param mixed $email         Email object.
	 * @return void
	 */
	public static function on_render_start( $order = null, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		$frame = self::context()->push( $email, $order, (bool) $plain_text, (bool) $sent_to_admin );

		if ( ! empty( $frame['refused'] ) ) {
			/*
			 * PAST THE DEPTH BOUND (ADR-0013 §5c). Returning HERE is what makes the
			 * bound worth having: no slot is opened and — the expensive one —
			 * `InsertPhase::evaluate()` is not called, so a third party recursing
			 * through a render hook cannot make this plugin run a query per level.
			 * The render proceeds uninjected.
			 */
			return;
		}

		if ( ! $order instanceof \WC_Order || '' === $frame['email_id'] ) {
			return;
		}

		if ( $frame['is_preview'] ) {
			// ADR-0013 §5: a preview writes no delivery state whatsoever. The
			// frame exists so injection callbacks have a context to read; the
			// absence of a slot is what makes a record impossible.
			self::context()->set_rules( $frame['token'], self::phase()->evaluate( $frame['email_id'], $order ) );
			return;
		}

		self::ledger()->open( $frame['token'], $email, (int) $frame['order_id'], $frame['email_id'] );
		self::context()->set_rules( $frame['token'], self::phase()->evaluate( $frame['email_id'], $order ) );
	}

	/**
	 * The order-details block finished: close the frame and arm the slot.
	 *
	 * @param mixed $order         Order.
	 * @param mixed $sent_to_admin Audience.
	 * @param mixed $plain_text    Format.
	 * @param mixed $email         Email object.
	 * @return void
	 */
	public static function on_render_end( $order = null, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		$token = self::context()->close_details( $email, $order );

		if ( null !== $token ) {
			// ARMS the slot only. Candidacy is taken later, at this render's own
			// terminal position (ADR-0013 §5e) — arming here and treating that as
			// candidacy is precisely what let a render nested from a post-frame
			// position displace the render enclosing it. A refused frame has no
			// slot, so this is a no-op for one.
			self::ledger()->complete_render( $token );
		}
	}

	/**
	 * The render's LAST EMAIL-ONLY POSITION: close its emission scope and PROMOTE
	 * its token to send candidacy (ADR-0013 §5e).
	 *
	 * ⚠ VERIFIED AGAINST EVERY WC 10.9.4 ORDER-EMAIL TEMPLATE, HTML AND PLAIN:
	 * `woocommerce_email_customer_details` is fired by all thirteen templates that
	 * fire `woocommerce_email_order_details`, and it is always the LAST of this
	 * plugin's five positions to fire. That makes it the terminal position for both
	 * formats — the footer is only a later backstop, and an HTML-only one.
	 *
	 * PROMOTING HERE RATHER THAN AT PRIORITY 15 IS THE ORDERING GUARANTEE. A render
	 * nested inside this one has already completed and promoted itself by the time
	 * control returns here, so this render lands after it. `PHP_INT_MAX` puts this
	 * after every third party emitting — or nesting — on the same hook, which
	 * priority 999 did not: a plugin at 1000 runs later, and one nesting a render
	 * there promoted after this render had (ADR-0013 §5e).
	 *
	 * @param mixed $order         Order.
	 * @param mixed $sent_to_admin Audience.
	 * @param mixed $plain_text    Format.
	 * @param mixed $email         Email object.
	 * @return void
	 */
	public static function on_emissions_end( $order = null, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		$token = self::context()->close_emissions( $email, $order, (bool) $sent_to_admin );

		if ( null !== $token ) {
			self::ledger()->promote_render( $token );
		}
	}

	/**
	 * HTML footer backstop for a frame priority 15 missed, and a token-exact
	 * backstop for an emission scope `on_emissions_end()` missed.
	 *
	 * REGISTERED ON BOTH FOOTER HOOKS. ⚠ WC 10.9.4's two POS receipt templates fire
	 * `woocommerce_pos_email_footer` and no `woocommerce_email_footer`; every other
	 * HTML order-email template fires the latter. Both are handled here because the
	 * job is identical, and neither is the mechanism — see ADR-0013 §5e and the
	 * token-exact purge in self::on_email_sent(), which is what makes correctness
	 * independent of which footer hook a template fires, or whether it fires one.
	 *
	 * @param mixed $email Email object.
	 * @return void
	 */
	public static function on_footer( $email = null ): void {
		$token = self::context()->close_footer( $email );

		if ( null !== $token ) {
			/*
			 * RE-PROMOTION, AND IT IS LOAD-BEARING FOR HTML (ADR-0013 §5e).
			 * ⚠ The footer fires AFTER `woocommerce_email_customer_details`, so a
			 * third party that renders another email from the footer would otherwise
			 * promote AFTER this render had already done so and would be taken by
			 * this render's send. Re-promoting here — at `PHP_INT_MAX`, after that
			 * third party's nested render has finished and promoted itself — puts
			 * this render back at the tail where it belongs.
			 *
			 * It is also the promotion backstop for an HTML template that omits
			 * `customer_details`. ⚠ Plain-text templates almost never fire a footer
			 * (only `plain/customer-cancelled-order.php` does in WC 10.9.4), which is
			 * exactly why the footer is a backstop and not the mechanism.
			 */
			self::ledger()->promote_render( $token );
		}
	}

	/**
	 * `get_headers()`, the FIRST of the two things `WC_Email` evaluates immediately
	 * before entering `send()`: OPEN this send's observation frame and take the
	 * exact token of the render it is about to put on the wire (ADR-0013 §5d, §5g).
	 *
	 * RETURNS THE HEADERS UNTOUCHED. This is an observation, not a filter — the
	 * headers are WooCommerce's business.
	 *
	 * ⚠ A HEADERS OBSERVATION ALWAYS OPENS A FRAME, and that is what makes nesting
	 * structural. The previous implementation asked whether `{spl, email_id}`
	 * matched the send already in progress and, if so, treated this as that send's
	 * second filter — which is exactly what a nested send through the same singleton
	 * looks like, so the inner send inherited the outer's token and each finalized
	 * the other's slot.
	 *
	 * ⚠ `$subject` IS `$email->object` AND IS DELIBERATELY IGNORED. WooCommerce does
	 * not restore that property after a nested render, so it can name an entirely
	 * different order by the time a send begins (ADR-0013 §5, flagged). `$id` is
	 * `$email->id`, which no render mutates, so that is what the handoff validates
	 * against.
	 *
	 * @param mixed $value   Headers, passed straight back.
	 * @param mixed $id      Email id.
	 * @param mixed $subject Order or other subject of the email — NOT used.
	 * @param mixed $email   Email object.
	 * @return mixed The first argument, unmodified.
	 */
	public static function on_send_headers( $value, $id = '', $subject = null, $email = null ) {
		self::ledger()->observe_send( $email, is_scalar( $id ) ? (string) $id : '', RenderLedger::STAGE_HEADERS );

		return $value;
	}

	/**
	 * `get_attachments()`, the SECOND observation of the same send: complete the
	 * frame `self::on_send_headers()` opened, without taking a second token.
	 *
	 * ONE SEND CONSUMES EXACTLY ONE TOKEN. ⚠ Both filters fire for a single send,
	 * because `get_headers()` and `get_attachments()` are two arguments to one
	 * `send()` call (WC 10.9.4, `class-wc-email.php:1173-1178`). Any send nested
	 * between them opens and closes its own frame in full, so the frame on top here
	 * is this send's own — and it is then verified against this email object and id
	 * rather than found by them.
	 *
	 * RETURNS THE ATTACHMENT LIST UNTOUCHED.
	 *
	 * @param mixed $value   Attachments, passed straight back.
	 * @param mixed $id      Email id.
	 * @param mixed $subject Order or other subject of the email — NOT used.
	 * @param mixed $email   Email object.
	 * @return mixed The first argument, unmodified.
	 */
	public static function on_send_attachments( $value, $id = '', $subject = null, $email = null ) {
		self::ledger()->observe_send( $email, is_scalar( $id ) ? (string) $id : '', RenderLedger::STAGE_ATTACHMENTS );

		return $value;
	}

	/**
	 * `WC_Email::send()` has begun: RESERVE this send's slot (ADR-0013 §5).
	 *
	 * REGISTERED AT `PHP_INT_MIN` AND THAT IS THE WHOLE POINT. Every other
	 * callback on this filter runs afterwards, so a third party that renders
	 * another email from here cannot produce a slot that competes with this
	 * send's — the candidate set is already fixed.
	 *
	 * RETURNS THE MESSAGE UNMODIFIED.
	 *
	 * @param mixed $message Message body.
	 * @return mixed The message, untouched.
	 */
	public static function on_mail_content( $message ) {
		self::ledger()->reserve();

		return $message;
	}

	/**
	 * Bind the RESERVED token to the send that is about to happen (ADR-0013 §5).
	 *
	 * RETURNS THE PARAMS UNMODIFIED. No render token is ever embedded in
	 * outgoing mail.
	 *
	 * @param mixed $params Mail callback parameters.
	 * @param mixed $email  Email object.
	 * @return mixed The parameters, untouched.
	 */
	public static function on_mail_params( $params, $email = null ) {
		self::ledger()->bind( $email );

		return $params;
	}

	/**
	 * A native email finished sending: resolve exactly the bound token and record
	 * WHAT WOOCOMMERCE ACTUALLY REPORTED.
	 *
	 * ⚠ `$sent` IS THE MAIL CALLBACK'S OWN RETURN VALUE AND IS NOT OPTIONAL
	 * INFORMATION (ADR-0013 §1a). This used to be ignored and every finalized
	 * render was recorded `sent`, so a `false` — a failure WooCommerce had
	 * explicitly reported — became a delivery history stating the content went
	 * out. That is worse than an unresolved slot: unresolved is honest.
	 *
	 * @param mixed $sent  Whether WooCommerce reported success.
	 * @param mixed $id    Email id.
	 * @param mixed $email Email object.
	 * @return void
	 */
	public static function on_email_sent( $sent = false, $id = '', $email = null ): void {
		// ⚠ TYPE-GUARD (ADR-0013 §5). `$email->object` is not used for identity —
		// the order comes from the slot — but a malformed object must not reach
		// the ledger at all.
		if ( ! is_object( $email ) ) {
			return;
		}

		$slot = self::ledger()->finalize( $email );

		if ( null === $slot ) {
			return;
		}

		/*
		 * THE RENDER IS OVER: DROP EVERY TRACE OF ITS TOKEN, TOKEN-EXACTLY
		 * (ADR-0013 §5h). It can emit nothing further, so its record goes — and so do
		 * its entries in the two open-token ledgers, which is what makes correctness
		 * independent of WHICH footer hook a template fires, or whether it fires one
		 * at all. ⚠ Before Prompt 5D only the record was retired, so the two WC 10.9.4
		 * POS receipt templates — which fire no `woocommerce_email_footer` — left one
		 * permanent `open_footer` entry per send: 100 POS receipts in one request left
		 * 100 stale tokens on the request-shared singleton.
		 */
		self::context()->discard_render( $slot['token'] );

		if ( array() === $slot['rules'] ) {
			return;
		}

		self::phase()->record_sent( $slot, (bool) $sent );
	}

	/**
	 * WooCommerce is preparing this object for a preview render.
	 *
	 * @param mixed $email Email object.
	 * @return mixed The email, untouched.
	 */
	public static function on_prepare_preview( $email ) {
		self::context()->mark_preview_pending( $email );

		return $email;
	}

	/**
	 * Reconcile any interrupted render, then record everything that was inserted
	 * but never finalized (ADR-0013 §6).
	 *
	 * TWO OUTCOMES, TOLD APART BY SLOT STATE AND NOT BY GUESSWORK (ADR-0013 §6a):
	 *
	 *   - `reserved` or `in_flight` — a send BEGAN and never reported its outcome.
	 *     The message may well have gone out, so this is `unresolved`;
	 *   - `rendering` or `awaiting_send` — the render never reached the send path at
	 *     all. That is an ABANDONED RENDER, which is not a delivery attempt and must
	 *     not report a delivery that succeeded as unresolved.
	 *
	 * ⚠ THE LEDGER IS EMPTIED BEFORE THE OPERATIONAL CHECK, NOT AFTER. `take_open()`
	 * is the ledger's only wholesale clearing path (gate 10), and returning early
	 * from an inoperative phase used to skip it — leaving slots, candidates,
	 * reservations, bindings and send frames on the request-shared singleton with
	 * nothing left in the request able to clear them.
	 *
	 * @return void
	 */
	public static function on_shutdown(): void {
		self::context()->shutdown();

		$open = self::ledger()->take_open();

		if ( ! InsertPhase::is_operational() ) {
			return;
		}

		foreach ( $open as $slot ) {
			if ( RenderLedger::is_abandoned( $slot ) ) {
				self::phase()->record_abandoned( $slot );
				continue;
			}

			self::phase()->record_unresolved( $slot );
		}
	}

	/**
	 * The render context, built once per request.
	 *
	 * @return RenderContext
	 */
	public static function context(): RenderContext {
		if ( null === self::$context ) {
			self::$context = new RenderContext();
		}

		return self::$context;
	}

	/**
	 * The finalization ledger, built once per request.
	 *
	 * @return RenderLedger
	 */
	public static function ledger(): RenderLedger {
		if ( null === self::$ledger ) {
			self::$ledger = new RenderLedger();
		}

		return self::$ledger;
	}

	/**
	 * The injector, built once per request.
	 *
	 * @return Injector
	 */
	public static function injector(): Injector {
		if ( null === self::$injector ) {
			self::$injector = new Injector( self::context(), self::ledger() );
		}

		return self::$injector;
	}

	/**
	 * The insert phase, built once per request so several renders share the
	 * resolver's PRODUCT cache (ADR-0012 §11b).
	 *
	 * @return InsertPhase
	 */
	public static function phase(): InsertPhase {
		if ( null === self::$phase ) {
			self::$phase = new InsertPhase();
		}

		return self::$phase;
	}

	/**
	 * Replace the per-request collaborators.
	 *
	 * A TEST SEAM, and the only way to drive this phase against test doubles —
	 * every hook callback is static because WooCommerce's hooks are global.
	 *
	 * @param RenderContext|null $context Frame stack.
	 * @param RenderLedger|null  $ledger  Slot ledger.
	 * @param InsertPhase|null   $phase   Insert phase.
	 * @return void
	 */
	public static function set_collaborators( ?RenderContext $context, ?RenderLedger $ledger, ?InsertPhase $phase ): void {
		self::$context  = $context;
		self::$ledger   = $ledger;
		self::$phase    = $phase;
		self::$injector = null;
	}
}
