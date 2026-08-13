<?php
/**
 * Rendering a rule against a real order without writing or sending anything
 * (ADR-0020 §1–§3).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

use Extonify\WCEP\Email\Custom_Email;
use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Plugin;
use Extonify\WCEP\Render\RenderEvents;

defined( 'ABSPATH' ) || exit;

/**
 * What this rule would produce, shown rather than sent.
 *
 * ⚠ THIS CLASS CANNOT WRITE A DELIVERY RECORD, AND THAT IS STRUCTURAL RATHER THAN
 * CAREFUL (ADR-0020 §1). It holds no `DeliveryRepository`, no
 * `DeliveryDetailRepository` and no `DeliveryLogger`; it never calls `claim()`; it
 * never calls `Custom_Email::trigger()` or `WC_Email::send()`. There is no line in it
 * that could write a tombstone or an attempt row, so preview inertness is a property of
 * the file and not of a flag somebody could get wrong.
 *
 * ⚠ AND IT CANNOT PRODUCE A LEDGER SLOT EITHER. Every render it drives is entered as a
 * preview by BOTH mechanisms ADR-0013 §5 recognises — this plugin's own render-scoped
 * marker AND WooCommerce's `woocommerce_is_email_preview` signal — so
 * `RenderEvents::on_render_start()` takes its no-slot branch. See self::as_preview().
 *
 * ⚠ IT DOES NOT APPLY `woocommerce_mail_content` (ADR-0020 §1c). Core's own preview
 * does; this one must not, because `RenderEvents::on_mail_content()` sits on that filter
 * at `PHP_INT_MIN` and `RenderLedger::reserve()` appends a reservation
 * UNCONDITIONALLY — a blank one when there is no candidate. Firing it from a preview
 * would leave residue on the request-shared ledger, which is the exact shape of the
 * defect ADR-0005 already had to fix once.
 */
final class RulePreview {

	/**
	 * How many recent orders self::default_order_id() will look at.
	 *
	 * ⚠ A BOUND, AND THE BOUND IS THE DECISION (ADR-0020 §2). "Find me an order this
	 * rule matches" without one is a full order-table scan plus a targeting evaluation
	 * per row, on an admin request, on a store with a million orders — a resource that
	 * grows without bound in normal operation, which is Tier 1. Twenty finds a match on
	 * any store where the rule is actually firing; when it does not, the screen says so
	 * and offers an explicit order id rather than searching harder.
	 */
	const MATCH_SCAN_LIMIT = 20;

	/**
	 * Outcome codes.
	 */
	const OK      = 'ok';
	const REFUSED = 'refused';

	/**
	 * Refusal codes. A closed vocabulary, each with its own sentence in `Admin\Notices`.
	 */
	const REFUSED_SCHEMA          = 'schema_unavailable';
	const REFUSED_RULE_DELETED    = 'rule_deleted';
	const REFUSED_RULE_VOCABULARY = 'rule_vocabulary';
	const REFUSED_ORDER_MISSING   = 'order_missing';
	const REFUSED_NO_ORDERS       = 'no_orders';
	const REFUSED_NATIVE_MISSING  = 'native_email_missing';
	const REFUSED_EMAIL_MISSING   = 'email_unavailable';
	const REFUSED_RENDER_FAILED   = 'render_failed';

	/**
	 * Render one rule against one order, in both formats.
	 *
	 * @param int $rule_id  Rule to preview.
	 * @param int $order_id Order to preview against, or 0 to take the default.
	 * @return array {
	 *     @type string    $outcome  self::OK or self::REFUSED.
	 *     @type string    $code     Refusal code when refused, else ''.
	 *     @type int       $order_id The order actually used.
	 *     @type bool      $matched  Whether the rule's targeting matched it.
	 *     @type string    $mode     `separate` or `insert`.
	 *     @type string    $subject  Resolved subject (separate mode only).
	 *     @type string    $heading  Resolved heading (separate mode only).
	 *     @type string    $html     The HTML document a customer would receive.
	 *     @type string    $plain    The plain-text body a customer would receive.
	 *     @type string    $notes    Placeholder and consolidation notes, if any.
	 *     @type int       $messages How many messages this delivery would send.
	 *     @type string    $native   Native email id, insert mode only.
	 * }
	 */
	public static function render( int $rule_id, int $order_id = 0 ): array {
		if ( ! Migrator::is_operational() ) {
			return self::refuse( self::REFUSED_SCHEMA );
		}

		$rule = $rule_id > 0 ? Plugin::instance()->rules()->find( $rule_id ) : null;

		if ( null === $rule ) {
			return self::refuse( self::REFUSED_RULE_DELETED );
		}

		/*
		 * ⚠ THE SAME ANSWER THE SEND PATH GIVES, FROM THE SAME METHOD (ADR-0020 §3a,
		 * gate 39). A rule whose mode or consolidation is outside the vocabulary is not
		 * deliverable, so previewing it would show the merchant something that could
		 * never be sent — and the read boundary's reason is the honest one to give.
		 *
		 * ⚠ INSERT MODE IS THE ONE REFUSAL DELIBERATELY NOT INHERITED. ADR-0019 §4 R2
		 * refuses it for every SENDING action because insert content has no envelope;
		 * it very much has something to SHOW, and showing it in position is the whole
		 * point of previewing an insert rule.
		 */
		$refusal = ManualDelivery::rule_refusal( $rule );

		if ( null !== $refusal && ManualDelivery::REFUSED_RULE_INSERT_MODE !== $refusal ) {
			return self::refuse( ManualDelivery::REFUSED_RULE_DELETED === $refusal ? self::REFUSED_RULE_DELETED : self::REFUSED_RULE_VOCABULARY );
		}

		$order_id = $order_id > 0 ? $order_id : self::default_order_id( $rule );

		if ( $order_id <= 0 ) {
			return self::refuse( self::REFUSED_NO_ORDERS );
		}

		$order = self::load_order( $order_id );

		if ( null === $order ) {
			return self::refuse( self::REFUSED_ORDER_MISSING, $order_id );
		}

		/*
		 * ⚠ THROUGH THE SAME MATCHER THE DELIVERY PHASES USE (ADR-0020 §2b, gate 39). A
		 * preview that resolved items its own way could show content bound to products
		 * the rule does not target — a confident wrong answer, which is worse than no
		 * preview at all.
		 */
		$items = ManualDelivery::matched_items( $order, $rule );

		return self::compose( $rule, $order, $items );
	}

	/**
	 * Render the message, contained.
	 *
	 * ⚠ THE CONTAINMENT BOUNDARY IS HERE FOR THE SAME REASON `Orchestrator::send()` HAS
	 * ONE (ADR-0014 §10). Everything below runs third-party code — this plugin's own
	 * `extonify_wcep_meta_placeholder_allowed` filter, WooCommerce's formatters,
	 * `wc_price()`, the email templates, the theme's template overrides, and
	 * `woocommerce_mail_style_inline_callback`. A throw from any of them must produce a
	 * refusal on a screen, not a fatal in the merchant's admin.
	 *
	 * @param array     $rule  Rule row.
	 * @param \WC_Order $order Order to resolve against.
	 * @param array[]   $items Matched items; empty when targeting did not match.
	 * @return array
	 */
	private static function compose( array $rule, \WC_Order $order, array $items ): array {
		$mode     = (string) ( $rule['delivery_mode'] ?? '' );
		$order_id = (int) $order->get_id();

		try {
			$composed = ( new Orchestrator() )->compose_preview( $order, $rule, $items );

			$rendered = 'insert' === $mode
				? self::render_insert( $rule, $order )
				: self::render_separate( $order, $composed );

			if ( isset( $rendered['code'] ) ) {
				return self::refuse( (string) $rendered['code'], $order_id );
			}

			return array(
				'outcome'  => self::OK,
				'code'     => '',
				'order_id' => $order_id,
				'matched'  => array() !== $items,
				'mode'     => $mode,
				'subject'  => (string) ( $rendered['subject'] ?? '' ),
				'heading'  => (string) ( $rendered['heading'] ?? '' ),
				'html'     => (string) ( $rendered['html'] ?? '' ),
				'plain'    => (string) ( $rendered['plain'] ?? '' ),
				'notes'    => (string) $composed['notes'],

				/*
				 * ⚠ INSERT MODE IS ALWAYS ONE MESSAGE, WHATEVER THE PLAN SAYS (ADR-0016
				 * §2). `per_product` chooses HOW MANY messages to send, and insert mode
				 * does not choose that — WooCommerce already did, once, before any rule
				 * was consulted. `RuleRepository` refuses to store the combination, so
				 * this is only reachable through a row somebody wrote directly; reporting
				 * the plan's count there would have the screen state that an insert rule
				 * "would send 2 separate emails", which it never would.
				 */
				'messages' => 'insert' === $mode ? 1 : (int) $composed['messages'],
				'native'   => (string) ( $rule['native_email_id'] ?? '' ),
			);
		} catch ( \Throwable $error ) {
			return self::refuse( self::REFUSED_RENDER_FAILED, $order_id, get_class( $error ) . ': ' . $error->getMessage() );
		}
	}

	/**
	 * Separate mode: the message inside the store's own WooCommerce wrapper.
	 *
	 * @param \WC_Order $order    Order.
	 * @param array     $composed Output of `Orchestrator::compose_preview()`.
	 * @return array The rendered formats, or `['code' => …]`.
	 */
	private static function render_separate( \WC_Order $order, array $composed ): array {
		$email = self::custom_email();

		if ( null === $email ) {
			return array( 'code' => self::REFUSED_EMAIL_MISSING );
		}

		return self::as_preview(
			$email,
			static function () use ( $email, $order, $composed ): array {
				return $email->render_preview(
					array(
						// ⚠ NO RECIPIENT, NO CC, NO BCC. A preview addresses nobody: it
						// never reaches `get_recipient()`, never builds headers and never
						// enters `send()`, so leaving these empty is what the render is.
						'subject'       => $composed['subject'],
						'heading'       => $composed['heading'],
						'content'       => $composed['body']['html'],
						'content_plain' => $composed['body']['plain'],
						'matched_items' => array(),
						'delivery_id'   => 0,
						'object'        => $order,
					)
				);
			}
		);
	}

	/**
	 * Insert mode: the NATIVE email the rule targets, with this plugin's content in
	 * position (ADR-0020 §3).
	 *
	 * ⚠ THE LIVE REGISTERED EMAIL OBJECT IS MUTATED AND PUT BACK IN A `finally`. Core's
	 * own preview mutates the same shared object and restores nothing (WC 11.0.1,
	 * `EmailPreview::set_email_type()`), so an interrupted core preview leaves the
	 * store's `WC_Email_Customer_Processing_Order` addressed to a dummy order for the
	 * rest of the request. Every property this touches is captured first and restored
	 * whatever happens.
	 *
	 * ⚠ `trigger()` IS NOT CALLED, BECAUSE `trigger()` SENDS. The three things it sets
	 * before sending — the object, the recipient and the two order placeholders — are
	 * set here instead, and then the two content builders are called directly.
	 *
	 * ⚠ THE COMPOSED BODY IS NOT PASSED IN, AND MUST NOT BE. Insert content reaches the
	 * message through `Render\Injector`, fired by the native template at the rule's own
	 * position — that IS the thing being previewed. Handing the composed body to this
	 * method and printing it somewhere would be a second insertion path that renders
	 * content the real email would place somewhere else.
	 *
	 * @param array     $rule  Rule row.
	 * @param \WC_Order $order Order.
	 * @return array The rendered formats, or `['code' => …]`.
	 */
	private static function render_insert( array $rule, \WC_Order $order ): array {
		$email = self::native_email( (string) ( $rule['native_email_id'] ?? '' ) );

		if ( null === $email ) {
			return array( 'code' => self::REFUSED_NATIVE_MISSING );
		}

		$saved = array(
			'object'       => $email->object,
			'recipient'    => $email->recipient,
			'placeholders' => $email->placeholders,
			'email_type'   => $email->email_type,
			// ⚠ `get_content()` SETS THIS TRUE and `handle_multipart()` sets it false
			// again on `phpmailer_init` — which a preview never reaches. Left true it
			// would tell WooCommerce a send is in progress for the rest of the request
			// (ADR-0012 §11c names it the one parent property WooCommerce mutates
			// mid-delivery).
			'sending'      => $email->sending,
		);

		try {
			$email->set_object( $order );

			// The recipient is set because native templates read it, NOT because
			// anything is addressed: no send happens on this path.
			$email->recipient = (string) $order->get_billing_email();

			$email->placeholders = array_merge(
				(array) $email->placeholders,
				array(
					'{order_date}'   => function_exists( 'wc_format_datetime' ) ? wc_format_datetime( $order->get_date_created() ) : '',
					'{order_number}' => (string) $order->get_order_number(),
				)
			);

			return self::as_preview(
				$email,
				static function () use ( $email ): array {
					/*
					 * ⚠ `get_content()`, WHICH IS WHAT `send()` CALLS. For a plain body it
					 * additionally runs `wp_strip_all_tags()`, the `plain_search` /
					 * `plain_replace` entity pass and `wordwrap( …, 70 )` — so calling
					 * `get_content_plain()` directly showed the merchant a body full of
					 * raw `&mdash;` and `&#036;` entities the customer never receives, and
					 * unwrapped lines the customer never sees.
					 */
					$email->email_type = 'html';

					$html = $email->style_inline( $email->get_content() );

					$email->email_type = 'plain';

					$plain = $email->get_content();

					return array(
						'subject' => (string) $email->get_subject(),
						'heading' => (string) $email->get_heading(),
						'html'    => (string) $html,
						'plain'   => (string) $plain,
					);
				}
			);
		} finally {
			$email->set_object( $saved['object'] );

			$email->recipient    = $saved['recipient'];
			$email->placeholders = $saved['placeholders'];
			$email->email_type   = $saved['email_type'];
			$email->sending      = $saved['sending'];
		}
	}

	/**
	 * Run one render inside the preview state, and leave the request exactly as it was
	 * found (ADR-0020 §1a).
	 *
	 * ⚠ BOTH SIGNALS ARE SET, AND NEITHER IS REDUNDANT.
	 *
	 * `mark_preview_pending()` is this plugin's OWN signal: render-scoped, consumed by
	 * the one `push()` it was set for, and therefore incapable of leaking into a later
	 * real send. It is what classifies the render when core's signal is distrusted —
	 * which is exactly the state ADR-0013 §5 puts the context into after somebody else's
	 * preview leaked.
	 *
	 * `woocommerce_is_email_preview` is the WORLD-FACING signal: WooCommerce's own code
	 * and every third party read it to decide whether a real message is going out. A
	 * preview that did not assert it would have them behaving as though one were.
	 *
	 * ⚠ AND THE REMOVAL IS IN A `finally`, WHICH IS THE ENTIRE DIFFERENCE FROM CORE.
	 * `EmailPreview::render_preview_email()` calls `set_up_filters()`, renders, then
	 * calls `clean_up_filters()` with nothing between them to survive a throw (WC 11.0.1,
	 * verified) — so an interrupted core preview leaves the signal reading `true` for the
	 * rest of the request and the next REAL send silently records nothing. An interrupted
	 * preview of OURS cannot do that: the filter comes off on the way out, whatever
	 * happened.
	 *
	 * @param object   $email  The object whose next render is the preview.
	 * @param callable $render The render.
	 * @return array Whatever the render returned.
	 */
	private static function as_preview( $email, callable $render ): array {
		$context = RenderEvents::context();

		$context->mark_preview_pending( $email );

		/*
		 * ⚠ FLAGGED WOOCOMMERCE BEHAVIOUR, verified in the bundled WC 11.0.1.
		 * `wc_get_template_html()` is `ob_start(); wc_get_template( … ); return
		 * ob_get_clean();` with **no `try`/`finally`** (`wc-core-functions.php:369-373`),
		 * and every email template this render touches goes through it. A throw from a
		 * template, a theme override or a third party's hook therefore leaves ONE OPEN
		 * OUTPUT BUFFER PER NESTED TEMPLATE, permanently.
		 *
		 * That is this plugin's problem in particular for the same reason ADR-0012 §11d's
		 * `send()` wrapper is: the preview CATCHES the throw and lets the request
		 * continue, so an unbalanced buffer does not die with a fatal — it silently
		 * swallows the rest of the admin page the merchant is looking at. The entry depth
		 * is recorded here and anything left above it is discarded on the way out.
		 */
		$depth = ob_get_level();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce-owned hook; this is the signal core itself sets for the duration of a preview, and ADR-0020 §1a is why this plugin sets it too.
		add_filter( 'woocommerce_is_email_preview', '__return_true', PHP_INT_MAX );

		try {
			return (array) $render();
		} finally {
			/*
			 * ⚠ DOWN TO THE ENTRY DEPTH AND NO FURTHER. Buffers BELOW it belong to
			 * WordPress, to the admin page, or to the test harness, and closing one of
			 * those would be this plugin destroying somebody else's output. The contents
			 * are discarded rather than flushed: this render failed, and emitting half a
			 * rendered email into the admin page is worse than emitting nothing.
			 */
			while ( ob_get_level() > $depth ) {
				ob_end_clean();
			}

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the removal half of the line above.
			remove_filter( 'woocommerce_is_email_preview', '__return_true', PHP_INT_MAX );

			/*
			 * ⚠ THE MARKER IS DROPPED TOO, AND THAT MATTERS ON THE SEPARATE-MODE PATH.
			 * `Custom_Email::render_preview()` fires no `woocommerce_email_order_details`
			 * of its own, so nothing consumes the marker — and an unconsumed marker on a
			 * request-shared object would classify a LATER, REAL send of the same object
			 * as a preview, which is the very failure this whole mechanism exists to
			 * prevent. `RenderContext::forget_preview_pending()` is the consuming half
			 * that a render would otherwise have performed.
			 */
			$context->forget_preview_pending( $email );
		}
	}

	/**
	 * The most recent order this rule's targeting matches, or 0 (ADR-0020 §2).
	 *
	 * ⚠ BOUNDED BY self::MATCH_SCAN_LIMIT, AND THE FIRST MATCH WINS. See that constant
	 * for why an unbounded search is not on the table.
	 *
	 * ⚠ IT RETURNS THE MOST RECENT ORDER EVEN WHEN NOTHING MATCHES, rather than 0. A
	 * merchant whose rule matches no recent order still wants to see the template render;
	 * ADR-0020 §2a says so, and the screen states plainly that targeting did not match so
	 * the matched-item placeholders are empty. Zero is returned only when the store has
	 * no orders at all, which is a different sentence.
	 *
	 * @param array $rule Rule row.
	 * @return int Order id, or 0 when the store has no orders.
	 */
	public static function default_order_id( array $rule ): int {
		$ids = self::recent_order_ids();

		if ( array() === $ids ) {
			return 0;
		}

		foreach ( $ids as $candidate ) {
			$order = self::load_order( $candidate );

			if ( null === $order ) {
				continue;
			}

			if ( array() !== ManualDelivery::matched_items( $order, $rule ) ) {
				return $candidate;
			}
		}

		return (int) $ids[0];
	}

	/**
	 * The most recent order ids, newest first, bounded.
	 *
	 * @return int[]
	 */
	private static function recent_order_ids(): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$ids = wc_get_orders(
			array(
				'limit'   => self::MATCH_SCAN_LIMIT,
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'ids',
				// Every status, including the ones no email fires on: a merchant
				// previewing a rule is looking at content, not at eligibility.
				'status'  => function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : 'any',
			)
		);

		$out = array();

		foreach ( (array) $ids as $id ) {
			$id = (int) $id;

			if ( $id > 0 ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * Load an order, or null.
	 *
	 * @param int $order_id Order id.
	 * @return \WC_Order|null
	 */
	private static function load_order( int $order_id ): ?\WC_Order {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * The live registered `Custom_Email`, or null.
	 *
	 * @return Custom_Email|null
	 */
	private static function custom_email(): ?Custom_Email {
		if ( ! function_exists( 'WC' ) || ! is_object( WC()->mailer() ) ) {
			return null;
		}

		foreach ( (array) WC()->mailer()->get_emails() as $email ) {
			if ( $email instanceof Custom_Email ) {
				return $email;
			}
		}

		return null;
	}

	/**
	 * The live registered native WooCommerce email with one id, or null.
	 *
	 * @param string $native_email_id WooCommerce email id.
	 * @return \WC_Email|null
	 */
	private static function native_email( string $native_email_id ): ?\WC_Email {
		if ( '' === $native_email_id || ! function_exists( 'WC' ) || ! is_object( WC()->mailer() ) ) {
			return null;
		}

		foreach ( (array) WC()->mailer()->get_emails() as $email ) {
			if ( $email instanceof \WC_Email && $native_email_id === (string) $email->id ) {
				return $email;
			}
		}

		return null;
	}

	/**
	 * A refusal.
	 *
	 * @param string $code     Refusal code.
	 * @param int    $order_id Order the refusal is about, or 0.
	 * @param string $detail   Diagnostic detail for the log, never for the screen.
	 * @return array
	 */
	private static function refuse( string $code, int $order_id = 0, string $detail = '' ): array {
		if ( '' !== $detail && function_exists( 'wc_get_logger' ) ) {
			/*
			 * ⚠ THE DETAIL GOES TO THE LOG, NEVER TO THE SCREEN. A merchant gets the
			 * refusal's own sentence; an exception message can carry a file path, a
			 * query fragment or a third party's internal state, and echoing it into the
			 * admin would be this plugin choosing to leak somebody else's diagnostics.
			 */
			wc_get_logger()->error(
				'preview of a rule could not be rendered: ' . $detail,
				array( 'source' => 'extonify-wcep' )
			);
		}

		return array(
			'outcome'  => self::REFUSED,
			'code'     => $code,
			'order_id' => $order_id,
			'matched'  => false,
			'mode'     => '',
			'subject'  => '',
			'heading'  => '',
			'html'     => '',
			'plain'    => '',
			'notes'    => '',
			'messages' => 0,
			'native'   => '',
		);
	}
}
