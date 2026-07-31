<?php
/**
 * The email-render context frame stack (ADR-0003, ADR-0013 §5).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Render;

defined( 'ABSPATH' ) || exit;

/**
 * "Are we inside an email render, and which one?" — as a STACK, never a boolean.
 *
 * PORTED FROM `poc/_render-context.php`, WHICH IS THE SPECIFICATION. That module
 * took four correction passes against real WooCommerce behaviour; every rule
 * below is proven against WC 10.9.4 and none of it is re-derived here.
 *
 * WHY A STACK. Emails nest — a third-party callback on `woocommerce_email_sent`
 * or inside a template can render another email — and one shared `WC_Email`
 * object serves every delivery of its type (ADR-0002). So two frames can carry
 * the SAME `spl_object_id`, and a boolean, or a per-object scalar, would be
 * cleared by the inner render while the outer one was still going.
 *
 * WHY EVERY OPERATION IS RENDER-SCOPED BY TOKEN. Matching on the email object
 * alone cannot tell those two frames apart. `pop()` and `cleanup()` therefore
 * remove EXACTLY ONE frame, identified by token and tuple-validated against the
 * email object and the order; a valid token from a different render removes
 * NOTHING and records a mismatch.
 *
 * WHY THE ORDER IS RECORDED HERE. ⚠ Verified on WC 10.9.4: WooCommerce does not
 * restore `$email->object` after a nested render, so it points at the INNER
 * order for the remainder of the outer one. The frame's recorded order id is the
 * only trustworthy source, and nothing in this phase reads `$email->object` for
 * identity.
 *
 * PREVIEW STATE IS RENDER-SCOPED. It is consumed at push and belongs to that one
 * frame. WooCommerce's own `woocommerce_is_email_preview` signal is preferred,
 * but ⚠ `EmailPreview::render_preview_email()` calls `clean_up_filters()` with no
 * `try`/`finally`, so an interrupted preview leaves the signal reading `true` for
 * the rest of the request. Reconciliation detects that and DEMOTES the signal to
 * the render-scoped marker until it reads healthy again — without which a later
 * REAL send would classify itself as a preview and silently record nothing.
 */
class RenderContext {

	/**
	 * How many render frames this plugin will do work for, at once.
	 *
	 * WHY A BOUND EXISTS AT ALL, AND IT IS A DENIAL-OF-SERVICE FIX RATHER THAN
	 * TIDINESS (ADR-0013 §5c). This plugin never originates a recursion, but every
	 * frame it accepts costs a frame record, a render record, a ledger slot, two
	 * open-token entries and — the expensive one — **ONE RULE EVALUATION, WHICH IS
	 * A DATABASE QUERY**. A third-party callback that re-enters a render hook
	 * therefore does not merely recurse: it makes THIS PLUGIN AMPLIFY its recursion
	 * by a query and three structures per level. Measured before the bound existed:
	 * 41 nested renders were accepted, producing 41 render records, 41 ledger slots
	 * and 41 evaluations. On a live store that is a query amplifier driven by
	 * somebody else's bug.
	 *
	 * WHY 16. WooCommerce's own order emails nest ZERO deep — one order-details
	 * block per render. Every legitimate nesting comes from a third party rendering
	 * another email from inside a render hook (an admin notification composed inside
	 * a customer email, a preview rendered from a template hook), and one or two
	 * levels covers every such pattern anyone has produced. 16 is an order of
	 * magnitude of headroom over that, while capping this plugin's contribution at
	 * 16 evaluations per request-path; it is also far below the point at which PHP's
	 * own stack or `xdebug.max_nesting_level` (256 by default) would intervene, so
	 * the refusal is ours, taken deliberately, rather than a crash.
	 *
	 * ⚠ WHAT THIS CANNOT DO. It cannot stop the recursion. A directly
	 * self-recursive third-party hook loops through that party's own closure and
	 * WordPress's dispatcher and never re-enters this class, so neither refusing nor
	 * throwing ends it — see self::push() for why throwing would be actively worse.
	 * The bound STARVES the recursion of this plugin's amplification, which is the
	 * honest limit of what this plugin controls.
	 */
	const MAX_RENDER_DEPTH = 16;

	/**
	 * How many entries each DIAGNOSTIC array will hold.
	 *
	 * Same reasoning as the depth bound, one layer down: `unresolved_emissions`,
	 * `mismatches` and `reconciled` exist to explain a request afterwards, and
	 * external recursion can drive any of them once per level. A diagnostic that
	 * grows without limit is a memory leak wearing a useful name. Beyond the cap
	 * entries are DROPPED AND COUNTED — self::diagnostics_dropped() reports the
	 * shortfall, so a truncated array can never be mistaken for a complete one.
	 */
	const MAX_DIAGNOSTIC_ENTRIES = 100;

	/**
	 * Frames, oldest first. The last entry is the current render.
	 *
	 * THE ITEM-HOOK CONTEXT WINDOW, and nothing else — exactly what ADR-0003
	 * and the POC call it. It opens at `woocommerce_email_order_details`
	 * priority 5 and closes at priority 15, which is the window the SHARED
	 * `woocommerce_order_item_meta_end` hook must be gated on.
	 *
	 * @var array[]
	 */
	private $frames = array();

	/**
	 * EMISSION SCOPES, oldest first — the last entry is the render currently
	 * emitting. What each render is FOR, and which rules it matched.
	 *
	 * ⚠ A STACK, NOT A LOOKUP TABLE, AND THAT DISTINCTION IS THE PROMPT 5B FIX.
	 * This used to be scanned newest-first for the first record matching
	 * (object, email id, order, audience), on the reasoning that two records with
	 * the same identity were interchangeable. **They are not: identical identity
	 * is not identical render.** Two renders of the same email for the same order
	 * have different tokens and different delivery SLOTS, so choosing between
	 * them decides which slot receives the insertion:
	 *
	 *   1. the outer render for order 123 opens scope `wcep_rt_1`;
	 *   2. inside it a third party calls `get_content()` on the same singleton for
	 *      the SAME order and audience — scope `wcep_rt_2` — and never sends;
	 *   3. the outer render reaches `woocommerce_email_order_meta`, one of the two
	 *      positions that fire AFTER the priority-15 pop and therefore the whole
	 *      reason this structure exists;
	 *   4. newest-first returns `wcep_rt_2`;
	 *   5. content is physically emitted into the OUTER message and registered
	 *      against the INNER slot.
	 *
	 * The outer slot then finalises with no rules while the inner is reported
	 * `unresolved` despite its registered content demonstrably having gone out —
	 * the delivery history wrong in both directions at once. It is the same defect
	 * ADR-0013 §5a removed from slot binding, one file away.
	 *
	 * @see self::current_render() for the resolution rule that replaced it.
	 *
	 * ⚠ WHY THIS IS SEPARATE FROM THE FRAME STACK, AND IT IS A WOOCOMMERCE FACT
	 * RATHER THAN A DESIGN PREFERENCE. Verified in the WC 10.9.4 templates: only
	 * three of the five injection positions fire INSIDE the frame window.
	 *
	 * | Position | Where it fires | Inside the frame? |
	 * |---|---|---|
	 * | `before_order_table` | inside `email-order-details.php`, itself run at `woocommerce_email_order_details` priority 10 | yes |
	 * | `item_meta` | per item, same template | yes |
	 * | `after_order_table` | same template | yes |
	 * | `order_meta` | `customer-processing-order.php` line 64 — AFTER `woocommerce_email_order_details` returns | **no** |
	 * | `customer_details` | same template, line 70 | **no** |
	 *
	 * `woocommerce_email_order_details` is a single action that RENDERS the
	 * order table; `order_meta` and `customer_details` are separate actions the
	 * template fires afterwards. The frame is already popped by then, and
	 * keeping it open until the footer instead is not an option — ⚠ plain-text
	 * templates never fire `woocommerce_email_footer`, and they fire both of
	 * those positions too.
	 *
	 * So the frame keeps its ADR-0003 lifetime and a render record carries the
	 * identity and the matched rules for as long as the render can still emit.
	 * The email-only positions read this and validate against it; the shared
	 * position still requires a live FRAME, so the storefront guarantee is
	 * untouched.
	 *
	 * @var array[]
	 */
	private $renders = array();

	/**
	 * Tokens awaiting retirement at `woocommerce_email_order_details` priority
	 * 15, keyed by `spl_object_id`, LIFO.
	 *
	 * @var array<int,string[]>
	 */
	private $open_details = array();

	/**
	 * Tokens awaiting the HTML footer backstop, keyed by `spl_object_id`, LIFO.
	 *
	 * @var array<int,string[]>
	 */
	private $open_footer = array();

	/**
	 * Objects whose NEXT render is a preview, keyed by `spl_object_id`.
	 *
	 * Set from `woocommerce_prepare_email_for_preview` and consumed at push.
	 *
	 * @var array<int,bool>
	 */
	private $preview_pending = array();

	/**
	 * Whether WooCommerce's own preview signal is currently untrusted.
	 *
	 * @var bool
	 */
	private $signal_leaked = false;

	/**
	 * Frames torn down by reconciliation, for diagnostics and tests.
	 *
	 * @var array[]
	 */
	private $reconciled = array();

	/**
	 * Rejected removals: a valid token presented for the wrong render.
	 *
	 * @var array[]
	 */
	private $mismatches = array();

	/**
	 * Emission attempts that resolved to no exact scope, for diagnostics and
	 * tests. Each records why nothing was emitted.
	 *
	 * @var array[]
	 */
	private $unresolved_emissions = array();

	/**
	 * Diagnostic entries dropped at the cap, per array.
	 *
	 * @var array<string,int>
	 */
	private $dropped = array(
		'unresolved_emissions' => 0,
		'mismatches'           => 0,
		'reconciled'           => 0,
	);

	/**
	 * Renders refused for exceeding self::MAX_RENDER_DEPTH this request.
	 *
	 * @var int
	 */
	private $refused_renders = 0;

	/**
	 * Whether the depth refusal has already been logged this request.
	 *
	 * ONE ENTRY PER REQUEST, NOT ONE PER LEVEL. A per-level log would turn a
	 * third party's runaway recursion into a runaway log file — the same
	 * amplification the depth bound exists to remove, arriving by a different
	 * route.
	 *
	 * @var bool
	 */
	private $depth_logged = false;

	/**
	 * Monotonic token counter.
	 *
	 * @var int
	 */
	private $sequence = 0;

	/**
	 * Mint the next render token.
	 *
	 * @return string
	 */
	private function next_token(): string {
		++$this->sequence;

		return 'wcep_rt_' . $this->sequence;
	}

	/**
	 * Whether WooCommerce exposes its own render-time preview signal.
	 *
	 * @return bool
	 */
	public static function core_signal_available(): bool {
		return class_exists( '\Automattic\WooCommerce\Internal\Admin\EmailPreview\EmailPreview' );
	}

	/**
	 * Read WooCommerce's render-time preview signal.
	 *
	 * @return bool
	 */
	public static function core_preview_signal(): bool {
		if ( ! self::core_signal_available() ) {
			return false;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce-owned hook; core reads its own preview state exactly this way, in WC_Email, FeaturesController and EmailPreview.
		return true === apply_filters( 'woocommerce_is_email_preview', false );
	}

	/**
	 * Mark an object's NEXT render as a preview.
	 *
	 * @param mixed $email Email object.
	 * @return void
	 */
	public function mark_preview_pending( $email ): void {
		if ( is_object( $email ) ) {
			$this->preview_pending[ spl_object_id( $email ) ] = true;
		}
	}

	/**
	 * Push a frame for a render that is beginning — or REFUSE it past the depth
	 * bound (ADR-0013 §5c).
	 *
	 * @param mixed $email         Email object.
	 * @param mixed $order         Order being rendered.
	 * @param bool  $plain_text    Whether this is the plain-text template.
	 * @param bool  $sent_to_admin Whether the audience is the store admin.
	 * @return array The frame, including its `token`. A refused frame additionally
	 *               carries `refused => true` and must be given no render record,
	 *               no ledger slot and no rule evaluation.
	 */
	public function push( $email, $order, bool $plain_text, bool $sent_to_admin ): array {
		if ( count( $this->frames ) >= self::MAX_RENDER_DEPTH ) {
			return $this->refuse( $email, $order, $plain_text );
		}

		// FIRST, ALWAYS: never inherit a previous render's residue (ADR-0003).
		$this->reconcile_previews();

		$spl   = is_object( $email ) ? spl_object_id( $email ) : 0;
		$token = $this->next_token();

		$marker = ! empty( $this->preview_pending[ $spl ] );
		if ( $marker ) {
			unset( $this->preview_pending[ $spl ] );
		}

		// A FALSE reading proves the signal is healthy again, so trust is
		// restored automatically rather than staying off for the request.
		$core = self::core_preview_signal();
		if ( ! $core ) {
			$this->signal_leaked = false;
		}

		$trust_core = self::core_signal_available() && ! $this->signal_leaked;
		$is_preview = $trust_core ? ( $core || $marker ) : $marker;

		$frame = array(
			'token'      => $token,
			'spl'        => $spl,
			'order_id'   => self::order_id( $order ),
			'order'      => $order instanceof \WC_Order ? $order : null,
			'email_id'   => is_object( $email ) && isset( $email->id ) ? (string) $email->id : '',
			'plain_text' => $plain_text,
			'admin'      => $sent_to_admin,
			'is_preview' => $is_preview,
			'rules'      => array(),

			/*
			 * HOW MANY FRAMES ENCLOSED THIS RENDER WHEN IT BEGAN. Zero for a
			 * top-level render, one for a render started from inside another
			 * render's order-details action, and so on. self::in_scope() checks it,
			 * which is what turns a leaked scope into "emit nothing" instead of
			 * "emit against the wrong token".
			 */
			'push_depth' => count( $this->frames ),
		);

		$this->frames[]               = $frame;
		$this->renders[]              = $frame;
		$this->open_details[ $spl ][] = $token;
		$this->open_footer[ $spl ][]  = $token;

		return $frame;
	}

	/**
	 * Refuse a render past the depth bound, leaving a marker that can be popped
	 * (ADR-0013 §5c).
	 *
	 * WHAT A REFUSED RENDER COSTS US: one six-key array and two token strings. No
	 * render record, no ledger slot, NO RULE EVALUATION and therefore no query. The
	 * render itself proceeds normally — WooCommerce composes and sends its own
	 * email, simply without any of this plugin's content in it. Losing an insertion
	 * at depth 17 is a vastly better outcome than a store that stops responding.
	 *
	 * WHY A MARKER AND NOT A PLAIN `return`. If nothing were pushed, the OUTER
	 * frame would stay on top of the stack: `in_email()` would still report an
	 * active render, `current()` would hand the shared item-meta hook the outer
	 * frame, and in the same-order/same-email case the outer render's content would
	 * be emitted INTO the refused inner render — content in the wrong message,
	 * recorded against the wrong slot. The marker is what makes every guard see
	 * "inside a render, and it is not one of ours": `render_for_token()` finds no
	 * record for the marker's token, and `in_scope()` sees a stack deeper than any
	 * open record's enclosing depth. Both fail closed, which is emit nothing.
	 *
	 * ⚠ WHY NOT AN EXCEPTION. A throw would propagate out through the third party's
	 * callback, through WooCommerce's rendering, and into whatever triggered it —
	 * potentially a checkout or an admin order save — converting a memory problem
	 * into a broken order flow on a merchant's live site. And it would stop nothing:
	 * the recursion loops through the third party's own closure and WordPress's
	 * dispatcher and never re-enters this class, so the throw would unwind OUR frame
	 * while their loop continued.
	 *
	 * THE MARKER IS DELIBERATELY MINIMAL — six keys, and each one is required by a
	 * specific reader: `token` (`close_details()`, `close_footer()`, `pop()`),
	 * `spl` and `order_id` (`remove_frame()`'s tuple validation), `plain_text`
	 * (`is_plain_render()`, which decides whether the footer entry is purged),
	 * `is_preview` (`reconcile_previews()`) and `refused` (the callers that must
	 * skip evaluation). Nothing else is carried, because nothing else is read.
	 *
	 * RECONCILIATION IS SKIPPED, DELIBERATELY. A refused push does the least work
	 * possible; the ADR-0003 invariant it appears to bypass — "no render inherits a
	 * previous render's residue" — is about renders that get a RECORD or a SLOT, and
	 * a refused render gets neither. The next accepted push reconciles as always.
	 *
	 * @param mixed $email      Email object.
	 * @param mixed $order      Order being rendered.
	 * @param bool  $plain_text Whether this is the plain-text template.
	 * @return array The refused-frame marker.
	 */
	private function refuse( $email, $order, bool $plain_text ): array {
		$spl   = is_object( $email ) ? spl_object_id( $email ) : 0;
		$token = $this->next_token();

		/*
		 * THE PENDING PREVIEW MARKER BELONGS TO THIS RENDER AND IS CONSUMED HERE
		 * TOO. `woocommerce_prepare_email_for_preview` marks the NEXT render of an
		 * object; leaving the marker set because that render happened to be refused
		 * would hand it to a LATER, REAL send, which would then classify itself as a
		 * preview and record nothing at all.
		 */
		unset( $this->preview_pending[ $spl ] );

		$marker = array(
			'token'      => $token,
			'spl'        => $spl,
			'order_id'   => self::order_id( $order ),
			'plain_text' => $plain_text,
			'is_preview' => false,
			'refused'    => true,
		);

		$this->frames[]               = $marker;
		$this->open_details[ $spl ][] = $token;
		$this->open_footer[ $spl ][]  = $token;

		++$this->refused_renders;

		if ( ! $this->depth_logged ) {
			$this->depth_logged = true;

			$this->log_error(
				'refused to process a render nested more than ' . self::MAX_RENDER_DEPTH
				. ' deep: another plugin is re-entering a WooCommerce email render hook. '
				. 'Emails still send; no custom content is inserted at this depth. '
				. 'Logged once per request.'
			);
		}

		return $marker;
	}

	/**
	 * The emission scope the email-only positions should emit into, or null.
	 *
	 * EXACT SCOPE OR NOTHING (ADR-0013 §4a). The resolution rule is absolute and
	 * is the same law slot selection obeys:
	 *
	 *     0 exact scopes  -> emit nothing
	 *     1 exact scope   -> use it
	 *     2+ exact scopes -> emit nothing, and log the ambiguity
	 *
	 * The ONLY candidate is the innermost open scope — the render currently
	 * emitting — which is then validated against everything the hook itself
	 * carries. That is a stack top, not a search: nothing is chosen by recency,
	 * by list position, or by "first match" over a set that can hold more than
	 * one member. A scope that fails validation disqualifies itself; it never
	 * defers to an older one.
	 *
	 * VALIDATES ALL THREE THINGS ADR-0003 REQUIRES — the email id, the order and
	 * the audience — plus the object identity and the enclosing-depth invariant
	 * of self::in_scope().
	 *
	 * @param mixed $email         Email object from the hook.
	 * @param mixed $order         Order from the hook.
	 * @param bool  $sent_to_admin Audience from the hook.
	 * @return array|null
	 */
	public function current_render( $email, $order, bool $sent_to_admin ): ?array {
		$exact = $this->exact_renders( $email, $order, $sent_to_admin );
		$count = count( $exact );

		if ( 1 === $count ) {
			return $exact[0];
		}

		$innermost = $this->innermost_render();

		if ( null !== $innermost ) {
			// Something WAS emitting and it is not this hook's render. Recorded
			// rather than resolved: emitting anyway is how content lands in one
			// message and is recorded against another slot.
			$this->record_diagnostic(
				'unresolved_emissions',
				array(
					'matches'   => $count,
					'innermost' => $innermost['token'],
					'email_id'  => is_object( $email ) && isset( $email->id ) ? (string) $email->id : '',
					'order_id'  => self::order_id( $order ),
					'admin'     => $sent_to_admin,
					'depth'     => count( $this->frames ),
				)
			);

			if ( $count > 1 ) {
				$this->log_error(
					'refused to emit into an ambiguous render: ' . $count . ' scopes matched '
					. $innermost['token'] . ' for order #' . (string) self::order_id( $order )
				);
			}
		}

		return null;
	}

	/**
	 * The render an IN-FRAME position is emitting into, or null (ADR-0013 §4c).
	 *
	 * ⚠ WHY THE IN-FRAME POSITIONS DO NOT USE self::current_render(), AND IT IS A
	 * CORRECTION RATHER THAN A SECOND OPINION. Three of the five positions —
	 * `before_order_table`, `after_order_table` and the shared `item_meta` — fire
	 * from INSIDE the template that the live frame belongs to. For those, THE LIVE
	 * FRAME IS THE ANSWER, and nothing in the render-record stack outranks it.
	 *
	 * Resolving them through the record stack instead let a LEAKED INNER RECORD
	 * shadow the render that was actually emitting. A third party that fires
	 * `woocommerce_email_order_details` on its own — which is how a plugin borrows
	 * WooCommerce's order table for its own email — never fires the two post-frame
	 * positions, so its render record is never retired at
	 * `woocommerce_email_customer_details`. Its record then sat on top of the stack
	 * while the ENCLOSING render reached its own `after_order_table`, and the
	 * enclosing render either emitted nothing (the depth check saving it) or
	 * emitted into the inner record's slot. Measured: 15 nested renders left 15
	 * leaked records, the outer send finalised with NO rules, and content that was
	 * physically inside the outer message was recorded against a slot reported
	 * `abandoned`. Both directions wrong at once, which is the ADR-0013 §5a defect
	 * shape in a third place.
	 *
	 * The frame cannot be shadowed: exactly one frame is topmost, it is pushed and
	 * popped by the same action that brackets these three positions, and a refused
	 * frame (ADR-0013 §5c) answers null rather than deferring to anything.
	 *
	 * VALIDATES EVERYTHING ADR-0003 REQUIRES ANYWAY. The frame being live does not
	 * excuse the callback from checking the email object, the email id, the order and
	 * the audience it was handed: a stale template override could pass any of them.
	 *
	 * @param mixed $email         Email object from the hook.
	 * @param mixed $order         Order from the hook.
	 * @param bool  $sent_to_admin Audience from the hook.
	 * @return array|null
	 */
	public function frame_render( $email, $order, bool $sent_to_admin ): ?array {
		$frame = $this->current();

		if ( null === $frame || ! empty( $frame['refused'] ) ) {
			return null;
		}

		if ( ! is_object( $email ) || ! isset( $email->id ) ) {
			return null;
		}

		$order_id = self::order_id( $order );

		if ( spl_object_id( $email ) !== $frame['spl'] || (string) $email->id !== $frame['email_id'] ) {
			return null;
		}

		if ( null === $order_id || $frame['order_id'] !== $order_id || $frame['admin'] !== $sent_to_admin ) {
			$this->record_diagnostic(
				'unresolved_emissions',
				array(
					'matches'   => 0,
					'innermost' => $frame['token'],
					'email_id'  => (string) $email->id,
					'order_id'  => $order_id,
					'admin'     => $sent_to_admin,
					'depth'     => count( $this->frames ),
				)
			);

			return null;
		}

		return $this->render_for_token( $frame['token'] );
	}

	/**
	 * Every emission scope that EXACTLY describes this hook's render.
	 *
	 * @param mixed $email         Email object from the hook.
	 * @param mixed $order         Order from the hook.
	 * @param bool  $sent_to_admin Audience from the hook.
	 * @return array[] Zero or one entry; two would be a token-uniqueness failure.
	 */
	private function exact_renders( $email, $order, bool $sent_to_admin ): array {
		if ( ! is_object( $email ) || ! isset( $email->id ) ) {
			return array();
		}

		$innermost = $this->innermost_render();

		if ( null === $innermost ) {
			return array();
		}

		$spl      = spl_object_id( $email );
		$email_id = (string) $email->id;
		$order_id = self::order_id( $order );

		if ( null === $order_id ) {
			return array();
		}

		$exact = array();

		foreach ( $this->renders as $render ) {
			// THE CANDIDATE SET IS THE INNERMOST SCOPE AND NOTHING ELSE. Tokens
			// are unique, so this admits at most one — the loop is written this
			// way so the 0/1/2+ rule above is literal rather than implied, and so
			// a token-uniqueness failure is reported instead of masked.
			if ( $render['token'] !== $innermost['token'] ) {
				continue;
			}

			if ( $render['spl'] !== $spl || $render['email_id'] !== $email_id ) {
				continue;
			}

			if ( $render['order_id'] !== $order_id || $render['admin'] !== $sent_to_admin ) {
				continue;
			}

			if ( ! $this->in_scope( $render ) ) {
				continue;
			}

			$exact[] = $render;
		}

		return $exact;
	}

	/**
	 * The innermost open emission scope, or null.
	 *
	 * @return array|null
	 */
	private function innermost_render(): ?array {
		if ( array() === $this->renders ) {
			return null;
		}

		return $this->renders[ count( $this->renders ) - 1 ];
	}

	/**
	 * Whether a scope's enclosing depth still matches the live frame stack.
	 *
	 * WHAT THIS CATCHES. A scope is closed at its render's last email-only
	 * position (`woocommerce_email_customer_details`), with the HTML footer as a
	 * token-exact backstop. A custom template that fires `order_meta` but neither
	 * of those leaks its scope — and a leaked INNER scope would otherwise sit on
	 * top of the stack while the OUTER render fires its own post-frame positions,
	 * reproducing the very defect this class was rewritten to remove. The
	 * enclosing depth separates them: the outer was pushed with zero frames open
	 * and the inner with one, and at the outer's `order_meta` the stack is empty.
	 * Mismatch means emit nothing.
	 *
	 * THE RENDER'S OWN FRAME IS DISCOUNTED. The three in-frame positions fire
	 * while that frame is still open and topmost, so it must not count towards the
	 * enclosing depth or every in-frame emission would be off by one.
	 *
	 * @param array $render Emission scope.
	 * @return bool
	 */
	private function in_scope( array $render ): bool {
		$depth = count( $this->frames );
		$top   = $this->current();

		if ( null !== $top && $top['token'] === $render['token'] ) {
			--$depth;
		}

		return (int) ( $render['push_depth'] ?? 0 ) === $depth;
	}

	/**
	 * Close the innermost emission scope when it is this render's.
	 *
	 * Called from the LAST email-only position a WooCommerce order email fires.
	 * ⚠ Verified across the WC 10.9.4 order-email templates, HTML and plain:
	 * every one of them fires `woocommerce_email_order_details`, then
	 * `woocommerce_email_order_meta`, then `woocommerce_email_customer_details`,
	 * and nothing of ours after that. Closing there is what makes a nested render
	 * give the stack back BEFORE control returns to the render that started it.
	 *
	 * TARGETED, NEVER A BLIND POP: the innermost scope must match this hook's own
	 * email object, email id, order and audience, or nothing is closed.
	 *
	 * @param mixed $email         Email object from the hook.
	 * @param mixed $order         Order from the hook.
	 * @param bool  $sent_to_admin Audience from the hook.
	 * @return string|null The closed token, or null.
	 */
	public function close_emissions( $email, $order, bool $sent_to_admin ): ?string {
		$exact = $this->exact_renders( $email, $order, $sent_to_admin );

		if ( 1 !== count( $exact ) ) {
			return null;
		}

		$token = $exact[0]['token'];
		$this->retire_render( $token );

		return $token;
	}

	/**
	 * The render record bearing a token, or null.
	 *
	 * Used by the SHARED position, which reaches it through the live frame's
	 * token rather than by matching — the shared hook has no `$email` to match
	 * on, which is exactly why it is gated on the frame instead.
	 *
	 * @param string $token Render token.
	 * @return array|null
	 */
	public function render_for_token( string $token ): ?array {
		foreach ( $this->renders as $render ) {
			if ( $render['token'] === $token ) {
				return $render;
			}
		}

		return null;
	}

	/**
	 * Retire every record for a render that was nested inside the one just closed
	 * (ADR-0013 §4a, amended Prompt 5C).
	 *
	 * ⚠ WHY A LEAKED RECORD HAD TO BE COLLECTED, AND WHY HERE. A render record
	 * outlives its frame so the two post-frame positions can still emit (§4a). A
	 * third party that fires `woocommerce_email_order_details` **on its own** — how a
	 * plugin borrows WooCommerce's order table — fires neither of those positions and
	 * neither the footer, so its record was never retired and sat on top of the
	 * record stack for the rest of the request. The enclosing render's own
	 * `order_meta` and `customer_details` then resolved to the leaked record instead
	 * of to themselves, and emitted **nothing** (fail-closed by the enclosing-depth
	 * invariant). Prompt 5B recorded that as a known cost; Prompt 5C makes it
	 * blocking, because promotion to send candidacy happens at a post-frame position
	 * and a render that cannot identify itself there can never become a candidate.
	 *
	 * THE RETIREMENT RULE IS PROVABLE, NOT HEURISTIC. WooCommerce's email templates
	 * fire `order_details`, then `order_meta`, then `customer_details`, in that order
	 * and with nothing of ours in between. So a render nested inside this one has
	 * ALREADY had its own post-frame positions — they fire immediately after its own
	 * `order_details` returns, which is still inside this render's details window.
	 * By the time this frame closes at priority 15, every record pushed after this
	 * render's is therefore either already retired or provably dead.
	 *
	 * @param string $token The token whose frame has just been removed.
	 * @return void
	 */
	private function retire_records_nested_in( string $token ): void {
		$position = -1;

		foreach ( $this->renders as $index => $render ) {
			if ( $render['token'] === $token ) {
				$position = $index;
				break;
			}
		}

		if ( $position < 0 ) {
			return;
		}

		// Everything after it in push order was pushed later, and is dead.
		$this->renders = array_slice( $this->renders, 0, $position + 1 );
	}

	/**
	 * Drop a render record once it can no longer emit.
	 *
	 * @param string $token Render token.
	 * @return void
	 */
	public function retire_render( string $token ): void {
		foreach ( $this->renders as $index => $render ) {
			if ( $render['token'] === $token ) {
				array_splice( $this->renders, $index, 1 );
				return;
			}
		}
	}

	/**
	 * Open render records, for diagnostics and tests.
	 *
	 * @return array[]
	 */
	public function renders(): array {
		return $this->renders;
	}

	/**
	 * How many tokens each open-token ledger is still holding, for diagnostics
	 * and tests.
	 *
	 * THE RESIDUE MEASUREMENT OF GATE 10. A request that sends N emails must leave
	 * these at zero however many of them fired a footer hook.
	 *
	 * @return array<string,int> Keyed by ledger name.
	 */
	public function open_token_counts(): array {
		$counts = array(
			'open_details' => 0,
			'open_footer'  => 0,
		);

		foreach ( array_keys( $counts ) as $ledger ) {
			foreach ( $this->{$ledger} as $tokens ) {
				$counts[ $ledger ] += count( $tokens );
			}
		}

		return $counts;
	}

	/**
	 * The current render's frame, or null outside an email render.
	 *
	 * @return array|null
	 */
	public function current(): ?array {
		if ( array() === $this->frames ) {
			return null;
		}

		return $this->frames[ count( $this->frames ) - 1 ];
	}

	/**
	 * Whether an email render is in progress.
	 *
	 * THE ONLY THING THE SHARED `woocommerce_order_item_meta_end` HOOK MAY ASK.
	 * That hook also fires on My Account view-order, the thank-you page and
	 * order-pay, where no frame can exist.
	 *
	 * @return bool
	 */
	public function in_email(): bool {
		return array() !== $this->frames;
	}

	/**
	 * Number of frames currently open.
	 *
	 * @return int
	 */
	public function depth(): int {
		return count( $this->frames );
	}

	/**
	 * Attach this render's matched rules to its frame (ADR-0013 §3).
	 *
	 * Evaluated ONCE, at push. The five injection callbacks read this; none of
	 * them evaluates.
	 *
	 * @param string $token Render token.
	 * @param array  $rules Matched rules, in ADR-0011 injection order.
	 * @return void
	 */
	public function set_rules( string $token, array $rules ): void {
		foreach ( $this->frames as $index => $frame ) {
			if ( $frame['token'] === $token ) {
				$this->frames[ $index ]['rules'] = $rules;
				break;
			}
		}

		foreach ( $this->renders as $index => $render ) {
			if ( $render['token'] === $token ) {
				$this->renders[ $index ]['rules'] = $rules;
				return;
			}
		}
	}

	/**
	 * Retire this render's order-details token and remove its frame.
	 *
	 * Called at `woocommerce_email_order_details` priority 15 — the
	 * render-complete signal, because ⚠ plain-text templates never fire
	 * `woocommerce_email_footer`.
	 *
	 * @param mixed $email Email object.
	 * @param mixed $order Order being rendered.
	 * @return string|null The retired token, or null when there was none.
	 */
	public function close_details( $email, $order ): ?string {
		$spl = is_object( $email ) ? spl_object_id( $email ) : 0;

		if ( empty( $this->open_details[ $spl ] ) ) {
			return null;
		}

		$token = array_pop( $this->open_details[ $spl ] );

		// READ BEFORE REMOVING: pop() deletes the frame this asks about.
		$plain_text = $this->is_plain_render( $token );

		$removed = $this->pop( $email, $order, $token );

		if ( $removed ) {
			$this->retire_records_nested_in( $token );
		}

		if ( $removed && $plain_text ) {
			/*
			 * PLAIN-TEXT RENDERS NEVER FIRE THE FOOTER, so nothing would ever
			 * retire this render's footer entry and the ledger would leak one
			 * token per plain-text email. HTML renders keep theirs so the footer
			 * backstop stays LIFO-aligned under nesting — and a REJECTED pop
			 * keeps its entry either way, because that is exactly when the
			 * backstop is needed.
			 */
			$this->purge_token( $spl, $token );
		}

		return $token;
	}

	/**
	 * The HTML footer backstop: remove any frame `close_details()` missed.
	 *
	 * A no-op on the normal path.
	 *
	 * @param mixed $email Email object.
	 * @return string|null The retired token, or null when there was none.
	 */
	public function close_footer( $email ): ?string {
		$spl = is_object( $email ) ? spl_object_id( $email ) : 0;

		if ( empty( $this->open_footer[ $spl ] ) ) {
			return null;
		}

		$token = array_pop( $this->open_footer[ $spl ] );

		$this->cleanup( $email, $this->recorded_order_id( $token ), $token );

		/*
		 * TOKEN-EXACT EMISSION BACKSTOP. `close_emissions()` normally closes the
		 * scope at `woocommerce_email_customer_details`, so this is a no-op on the
		 * ordinary path. It matters for an HTML template that omits that position:
		 * the footer still names this render's exact token, so the scope is
		 * returned before control leaves the render. ⚠ Plain-text templates never
		 * fire the footer, which is why this is a backstop and not the mechanism.
		 */
		$this->retire_render( $token );

		return $token;
	}

	/**
	 * Remove exactly one frame by token, tuple-validated (ADR-0003).
	 *
	 * @param mixed  $email Email object the caller believes owns the frame.
	 * @param mixed  $order Order the caller believes the frame is for.
	 * @param string $token Render token.
	 * @return bool True when a frame was removed.
	 */
	public function pop( $email, $order, string $token ): bool {
		return $this->remove_frame( $email, $order, $token, 'pop' );
	}

	/**
	 * Footer-backstop removal, with the same tuple validation as self::pop().
	 *
	 * @param mixed  $email Email object.
	 * @param mixed  $order Order.
	 * @param string $token Render token.
	 * @return bool True when a frame was removed.
	 */
	public function cleanup( $email, $order, string $token ): bool {
		return $this->remove_frame( $email, $order, $token, 'cleanup' );
	}

	/**
	 * Remove exactly one frame, or nothing.
	 *
	 * A VALID TOKEN FOR THE WRONG RENDER REMOVES NOTHING and is recorded. That
	 * is the whole reason removal is tuple-validated: one shared object serves
	 * every delivery of an email type, so a token alone cannot prove ownership.
	 *
	 * @param mixed  $email Email object.
	 * @param mixed  $order Order.
	 * @param string $token Render token.
	 * @param string $where Call site, for the mismatch record.
	 * @return bool
	 */
	private function remove_frame( $email, $order, string $token, string $where ): bool {
		$spl      = is_object( $email ) ? spl_object_id( $email ) : 0;
		$order_id = self::order_id( $order );

		foreach ( $this->frames as $index => $frame ) {
			if ( $frame['token'] !== $token ) {
				continue;
			}

			if ( $frame['spl'] !== $spl || ( null !== $order_id && $frame['order_id'] !== $order_id ) ) {
				$this->record_diagnostic(
					'mismatches',
					array(
						'where'     => $where,
						'token'     => $token,
						'frame_spl' => $frame['spl'],
						'frame_oid' => $frame['order_id'],
						'given_spl' => $spl,
						'given_oid' => $order_id,
					)
				);

				return false;
			}

			array_splice( $this->frames, $index, 1 );

			return true;
		}

		return false;
	}

	/**
	 * Tear down every surviving preview frame.
	 *
	 * A PREVIEW FRAME THAT IS STILL OPEN WHEN THE NEXT RENDER BEGINS IS PROOF
	 * that a preview was interrupted between push and pop — a completed preview
	 * always removes its own frame at the order-details close. PHP offers no
	 * unwind hook at a call site this plugin does not own, so teardown at the
	 * instant of the exception is impossible; reconciling at the start of every
	 * push (and at shutdown) bounds the residue instead, and no render ever
	 * inherits another's.
	 *
	 * When a stale preview frame is found AND WooCommerce's signal still reads
	 * true, core leaked it — mark it untrusted so the render-scoped marker
	 * governs detection until it heals.
	 *
	 * @return int Frames torn down.
	 */
	public function reconcile_previews(): int {
		$keep  = array();
		$stale = array();

		foreach ( $this->frames as $frame ) {
			if ( empty( $frame['is_preview'] ) ) {
				$keep[] = $frame;
				continue;
			}

			$stale[] = $frame;
		}

		if ( array() === $stale ) {
			return 0;
		}

		$this->frames = $keep;

		foreach ( $stale as $frame ) {
			$this->purge_token( (int) $frame['spl'], (string) $frame['token'] );
			$this->retire_render( $frame['token'] );
			$this->record_diagnostic( 'reconciled', $frame );
		}

		if ( self::core_preview_signal() ) {
			$this->signal_leaked = true;
		}

		return count( $stale );
	}

	/**
	 * Drop everything an interrupted request may have left behind.
	 *
	 * EVERY PER-REQUEST COLLECTION THIS CLASS RECONCILES IS CLEARED HERE, and that
	 * enumeration is the point (gate 10). Reconciliation alone removes only stale
	 * PREVIEW frames; an interrupted real render leaves a frame, two open tokens and
	 * a record, and clearing the records while leaving the frames and tokens behind
	 * left `in_email()` reporting an active render for the rest of the request.
	 *
	 * | Collection | Populated | Cleared |
	 * |---|---|---|
	 * | `frames` | self::push(), self::refuse() | self::pop() / self::cleanup() per render; self::reconcile_previews() for stale previews; wholesale here |
	 * | `renders` | self::push() | self::retire_render() / self::discard_render() per render; self::retire_records_nested_in() at the frame close; wholesale here |
	 * | `open_details` | self::push(), self::refuse() | self::close_details() per render; self::discard_render() at finalization; wholesale here |
	 * | `open_footer` | self::push(), self::refuse() | self::close_footer() per render; self::discard_render() at finalization; self::purge_token() for plain renders; wholesale here |
	 * | `preview_pending` | self::mark_preview_pending() | consumed at the next push or refuse; wholesale here |
	 *
	 * The three DIAGNOSTIC arrays — `reconciled`, `mismatches` and
	 * `unresolved_emissions` — are deliberately NOT cleared: they are hard-capped at
	 * self::MAX_DIAGNOSTIC_ENTRIES, they exist to explain the request afterwards, and
	 * `shutdown` is when something is reading them. They die with the object at the
	 * end of the request.
	 *
	 * @return void
	 */
	public function shutdown(): void {
		$this->reconcile_previews();

		$this->frames          = array();
		$this->renders         = array();
		$this->open_details    = array();
		$this->open_footer     = array();
		$this->preview_pending = array();
	}

	/**
	 * Remove one token from both open-token ledgers.
	 *
	 * AN EMPTIED BUCKET IS UNSET RATHER THAN LEFT AS AN EMPTY ARRAY, so neither
	 * ledger can accumulate one key per `spl_object_id` a long-running request has
	 * seen (gate 10).
	 *
	 * @param int|null $spl   Object id, or null to purge the token wherever it is.
	 * @param string   $token Render token.
	 * @return void
	 */
	private function purge_token( ?int $spl, string $token ): void {
		foreach ( array( 'open_details', 'open_footer' ) as $ledger ) {
			$buckets = null === $spl ? array_keys( $this->{$ledger} ) : array( $spl );

			foreach ( $buckets as $bucket ) {
				if ( empty( $this->{$ledger}[ $bucket ] ) ) {
					unset( $this->{$ledger}[ $bucket ] );
					continue;
				}

				$kept = array_values(
					array_filter(
						$this->{$ledger}[ $bucket ],
						static function ( string $candidate ) use ( $token ): bool {
							return $candidate !== $token;
						}
					)
				);

				if ( array() === $kept ) {
					unset( $this->{$ledger}[ $bucket ] );
					continue;
				}

				$this->{$ledger}[ $bucket ] = $kept;
			}
		}
	}

	/**
	 * A render is over: drop EVERY trace of its token (ADR-0013 §5h).
	 *
	 * ⚠ WHY THE PURGE EXISTS, AND WHY IT IS TOKEN-EXACT. Every accepted render
	 * appends its token to `open_footer`, and only a footer hook removed it again.
	 * **Two WC 10.9.4 HTML templates fire no footer this plugin was listening to** —
	 * `customer-pos-completed-order.php` and `customer-pos-refunded-order.php` fire
	 * `woocommerce_pos_email_footer` instead — so each POS receipt left one entry
	 * behind for the rest of the request, and a request sending 100 receipts left
	 * 100. That is unbounded per-request residue on a request-shared singleton, and
	 * a hook registration alone would only fix the templates we happen to know
	 * about. Purging the exact token when its send finalizes makes the ledgers
	 * correct for a template that fires ANY footer hook, or none.
	 *
	 * Token-exact, never "the last one for this object": the token identifies one
	 * render, and a positional pop here would take an enclosing render's entry.
	 *
	 * @param string $token Render token.
	 * @return void
	 */
	public function discard_render( string $token ): void {
		$this->purge_token( null, $token );
		$this->retire_render( $token );
	}

	/**
	 * Whether the frame bearing a token is a plain-text render.
	 *
	 * MUST BE READ BEFORE `pop()` REMOVES THE FRAME. A token with no frame
	 * answers false, which is the safe direction: the footer entry is kept, and
	 * the HTML backstop finds nothing to do.
	 *
	 * @param string $token Render token.
	 * @return bool
	 */
	private function is_plain_render( string $token ): bool {
		foreach ( $this->frames as $frame ) {
			if ( $frame['token'] === $token ) {
				return (bool) $frame['plain_text'];
			}
		}

		return false;
	}

	/**
	 * The order id recorded for a token at push.
	 *
	 * @param string $token Render token.
	 * @return int|null
	 */
	public function recorded_order_id( string $token ): ?int {
		foreach ( $this->frames as $frame ) {
			if ( $frame['token'] === $token ) {
				return $frame['order_id'];
			}
		}

		return null;
	}

	/**
	 * Frames torn down by reconciliation.
	 *
	 * @return array[]
	 */
	public function reconciled(): array {
		return $this->reconciled;
	}

	/**
	 * Rejected removals.
	 *
	 * @return array[]
	 */
	public function mismatches(): array {
		return $this->mismatches;
	}

	/**
	 * Emission attempts that resolved to no exact scope.
	 *
	 * @return array[]
	 */
	public function unresolved_emissions(): array {
		return $this->unresolved_emissions;
	}

	/**
	 * Append to a capped diagnostic array (ADR-0013 §5c).
	 *
	 * A DROPPED ENTRY IS COUNTED, NEVER SILENTLY DISCARDED. A diagnostic that
	 * truncates without saying so is worse than one that is missing: whoever reads
	 * it later would conclude that 100 was all that happened.
	 *
	 * @param string $bucket Property name — one of the keys of self::$dropped.
	 * @param array  $entry  Entry to record.
	 * @return void
	 */
	private function record_diagnostic( string $bucket, array $entry ): void {
		if ( count( $this->{$bucket} ) >= self::MAX_DIAGNOSTIC_ENTRIES ) {
			++$this->dropped[ $bucket ];
			return;
		}

		$this->{$bucket}[] = $entry;
	}

	/**
	 * Diagnostic entries dropped at the cap, per array.
	 *
	 * @return array<string,int>
	 */
	public function diagnostics_dropped(): array {
		return $this->dropped;
	}

	/**
	 * How many renders were refused for exceeding self::MAX_RENDER_DEPTH.
	 *
	 * @return int
	 */
	public function refused_renders(): int {
		return $this->refused_renders;
	}

	/**
	 * Log through WooCommerce's logger when available.
	 *
	 * @param string $message Detail.
	 * @return void
	 */
	protected function log_error( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'extonify-wcep' ) );
		}
	}

	/**
	 * Whether WooCommerce's preview signal is currently distrusted.
	 *
	 * @return bool
	 */
	public function signal_leaked(): bool {
		return $this->signal_leaked;
	}

	/**
	 * Normalise an order argument to an id.
	 *
	 * @param mixed $order `WC_Order`, an id, or null.
	 * @return int|null
	 */
	public static function order_id( $order ): ?int {
		if ( $order instanceof \WC_Order ) {
			return (int) $order->get_id();
		}

		if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
			return (int) $order->get_id();
		}

		return is_numeric( $order ) ? (int) $order : null;
	}
}
