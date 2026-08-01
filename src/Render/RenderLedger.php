<?php
/**
 * The render-slot state machine and send correlation (ADR-0005, ADR-0013 §5).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Render;

defined( 'ABSPATH' ) || exit;

/**
 * One slot per non-preview render, carrying the rules that were inserted and the
 * order they were inserted for.
 *
 * PORTED FROM `poc/_render-context.php`. The state machine, the bind stack and
 * the exact-token resolution are the output of four correction passes; this
 * class implements them and re-derives nothing.
 *
 *     rendering -> awaiting_send -> reserved -> in_flight -> resolved (removed)
 *
 * WHERE EACH TRANSITION HAPPENS, AND WHY THERE:
 *
 *   - `rendering` at `woocommerce_email_order_details` priority 5, alongside the
 *     context frame;
 *   - `awaiting_send` at the SAME action, priority 15 — **not** at
 *     `woocommerce_email_footer`, because ⚠ plain-text templates never fire the
 *     footer and every plain-text render would leak a slot. This ARMS the slot
 *     (self::complete_render()); it does NOT make the render a send candidate —
 *     see self::promote_render() and ADR-0013 §5e;
 *   - `reserved` at `woocommerce_mail_content`, the FIRST filter inside
 *     `WC_Email::send()`, at the earliest priority this plugin can register.
 *     See self::reserve() for why the reservation exists and why it must be
 *     taken there and nowhere later;
 *   - `in_flight` at `woocommerce_mail_callback_params`, still inside
 *     `WC_Email::send()` and before the mail callback. The RESERVED token is
 *     bound; nothing is selected here. The params are returned UNMODIFIED — no
 *     render token is ever embedded in outgoing mail;
 *   - resolved at `woocommerce_email_sent`, which resolves EXACTLY THE BOUND
 *     TOKEN.
 *
 * NOTHING IS EVER SELECTED FROM A SET (ADR-0013 §5a, §5d, §5e). The token is
 * CARRIED from the render that produced the message to the send that puts it on the
 * wire, and every stage uses THAT TOKEN:
 *
 *     order_details:15                    -> ARM the slot
 *     render reaches its TERMINAL position (customer_details and the footers,
 *     all at PHP_INT_MAX)                 -> PROMOTE the exact token
 *     send observed (headers/attachments, PHP_INT_MAX)
 *                                         -> OPEN this send's frame and TAKE
 *                                            the exact token for it
 *     woocommerce_mail_content            -> RESERVE that token
 *     woocommerce_mail_callback_params    -> BIND that token
 *     woocommerce_email_sent              -> FINALIZE that token
 *
 * ⚠ WHY CARRYING REPLACED SCANNING, AND IT IS A CORRECTION RATHER THAN A
 * REFACTOR. `reserve()` used to scan the `awaiting_send` slots and reserve only
 * when exactly one was eligible. That is fail-closed but wrong: two renders of the
 * SAME email for the SAME order — which a third party produces by calling
 * `get_content()` from inside a render hook — are two eligible slots, so nothing
 * was reserved, nothing was bound, nothing was finalized, and the outer send
 * recorded NO DELIVERY AT ALL even though its content demonstrably went out.
 * Uniqueness cannot resolve what identity cannot distinguish; only structure can,
 * and the structure is the promotion queue — whose ORDER is produced by each render
 * promoting itself at its own terminal position, never assumed from nesting.
 *
 * "The most recent `awaiting_send` slot for this object" remains PROHIBITED here,
 * in `bind()` and in `finalize()` alike. Prompt 1D removed that heuristic from the
 * binding POINT; Prompt 5A removed it from the binding SELECTION; Prompt 5B
 * removes the last set to choose from.
 *
 * NO TOKEN MEANS RESERVE NOTHING AND FINALIZE NOTHING. Guessing is prohibited; a
 * render that never reached the send path stays `awaiting_send` and is reported at
 * shutdown as an ABANDONED RENDER — which is not a delivery attempt and must never
 * downgrade a tombstone whose latest real send succeeded (ADR-0013 §6a).
 *
 * A PREVIEW HAS NO SLOT AT ALL, so preview safety is structural: nothing to
 * register into, nothing to reserve, nothing to bind, nothing to finalize.
 */
class RenderLedger {

	/**
	 * Slot states.
	 */
	const RENDERING     = 'rendering';
	const AWAITING_SEND = 'awaiting_send';
	const RESERVED      = 'reserved';
	const IN_FLIGHT     = 'in_flight';

	/**
	 * States from which a slot has NOT yet reached the send path.
	 *
	 * THE DIVIDING LINE OF ADR-0013 §6a, and it is structural rather than inferred.
	 * A slot still `rendering` or `awaiting_send` at the shutdown sweep belongs to a
	 * render that never handed anything to `WC_Email::send()` — an ABANDONED RENDER.
	 * A slot `reserved` or `in_flight` belongs to a send that DID begin and whose
	 * outcome was never reported — genuinely UNRESOLVED. The state machine already
	 * knows the difference, so nothing has to guess it.
	 */
	const PRE_SEND_STATES = array( self::RENDERING, self::AWAITING_SEND );

	/**
	 * How many entries each diagnostic array will hold.
	 */
	const MAX_DIAGNOSTIC_ENTRIES = 100;

	/**
	 * How many DISTINCT notes one rule will carry out of one render
	 * (ADR-0014 §1d).
	 *
	 * ⚠ THE CAP `PlaceholderValues::MAX_NOTES` DOES NOT ALREADY PROVIDE. That one
	 * bounds a single value set, and at the per-item position a rule gets a FRESH
	 * value set per line item — so an order with fifty failing items could
	 * contribute fifty times the per-set limit to one rule-level string that had no
	 * limit of its own. Deliberately the same number, because it bounds the same
	 * `reason` column at the other end: 20 notes plus the outcome sentence stay
	 * inside `Domain\Text::MAX_LOG_LENGTH`, so the cap here and the truncation at
	 * storage agree rather than the second one quietly doing the first one's job.
	 */
	const MAX_RULE_NOTES = 20;

	/**
	 * The two observation stages of one `WC_Email::send()`, in the order
	 * `WC_Email` evaluates them as arguments to that call.
	 */
	const STAGE_HEADERS     = 'headers';
	const STAGE_ATTACHMENTS = 'attachments';

	/**
	 * Open slots, oldest first.
	 *
	 * @var array[]
	 */
	private $slots = array();

	/**
	 * Reservations, LIFO — one per `WC_Email::send()` invocation in progress.
	 *
	 * Each entry is `{token, spl}`. A reservation whose `token` is null is a
	 * DELIBERATE BLANK: the send is in progress but no slot could be identified
	 * for it, so the stack stays depth-aligned with the nested sends and the
	 * bind finalizes nothing rather than falling back to a guess.
	 *
	 * @var array[]
	 */
	private $reservations = array();

	/**
	 * SEND CANDIDATES, per `spl_object_id`, in PROMOTION order — the tail is the
	 * render whose message the next `send()` will carry (ADR-0013 §5e).
	 *
	 * Each entry is `{token, order_id, email_id}`. Appended by
	 * self::promote_render() when a render reaches ITS OWN TERMINAL POSITION;
	 * taken from the tail by self::observe_send().
	 *
	 * ⚠ THE ORDERING IS PRODUCED, NOT ASSUMED, AND THAT IS THE PROMPT 5C FIX.
	 * Until Prompt 5C a render was published here at `order_details` priority 15 and
	 * the tail was taken on the reasoning that "an enclosing render cannot finish
	 * before the render nested inside it". **That reasoning was false for the two
	 * positions this plugin actually ships.** `order_meta` and `customer_details`
	 * fire AFTER priority 15, so the outer render had already published when a third
	 * party rendered from one of them:
	 *
	 *   1. the outer publishes token 1 at `order_details:15`;
	 *   2. the outer reaches `woocommerce_email_order_meta`;
	 *   3. a third party calls `get_content()` on the same object — token 2
	 *      publishes and lands ON TOP — and never sends;
	 *   4. the outer finishes and its send takes the tail: **token 2**.
	 *
	 * The outer message then finalised the INNER slot and the genuine outer slot was
	 * swept as never sent. Wrong in both directions — the ADR-0013 §5a defect, in
	 * its fourth location.
	 *
	 * THE INVARIANT THAT NOW GUARANTEES THE ORDER. A render is appended here at the
	 * last position it can reach, and our callbacks sit at **`PHP_INT_MAX`** on those
	 * positions — the highest priority WordPress can run. PHP unwinds a nested render
	 * completely before the enclosing render's next statement runs, so the enclosing
	 * render necessarily promotes AFTER every render nested within it. The queue
	 * order is therefore the true completion order, rather than an assumption about
	 * it.
	 *
	 * ⚠ THE PRIORITY WAS 999 UNTIL PROMPT 5D, AND ITS DOCBLOCK CLAIMED THAT RAN
	 * "after any third party". **WordPress runs LOWER priority numbers first**, so a
	 * plugin registered at 1000 on `woocommerce_email_customer_details` ran after us,
	 * nested a render there, and promoted LAST — and the enclosing send then took the
	 * nested render's token. `PHP_INT_MAX` is the real ceiling. The residual is
	 * stated rather than papered over: a callback registered LATER at `PHP_INT_MAX`
	 * still runs after ours, because same-priority callbacks execute in registration
	 * order (ADR-0013 §5e).
	 *
	 * @var array<int,array[]>
	 */
	private $candidates = array();

	/**
	 * SEND-OBSERVATION FRAMES, one per `WC_Email::send()` invocation in progress,
	 * innermost last. Each carries the EXACT token taken for that send.
	 *
	 * Each entry is `{token, spl, email_id, attachments}`, where `attachments`
	 * records whether that send's second observation has already been seen.
	 *
	 * ⚠ A STACK, NOT TWO SCALARS, AND THAT IS THE PROMPT 5D FIX. The handoff used
	 * to be one token plus one `{spl, email_id}` descriptor, and the descriptor
	 * decided whether an observation belonged to the send already in progress:
	 *
	 *     if ( null !== $this->send_scope && $this->send_scope === $scope ) {
	 *         return $this->send_token;   // "the SECOND filter of one send"
	 *     }
	 *
	 * **For a send nested inside another send through the same singleton with the
	 * same email id, that equality is TRUE**, so the inner send was treated as the
	 * outer send's second observation and inherited the OUTER token:
	 *
	 *     outer headers      -> takes outer token
	 *     inner headers      -> scope matches -> returns OUTER token
	 *     inner mail_content -> reserves OUTER token
	 *     inner send         -> FINALIZES OUTER token with the INNER result
	 *     outer attachments  -> scope now clear -> takes the remaining candidate,
	 *                           which is the INNER token
	 *     outer send         -> FINALIZES INNER token
	 *
	 * Reversed in both directions, and the same correlation defect this file has
	 * now removed from `current_render()`, `bind()`, `reserve()`, `take_completed()`
	 * and here. **No equality test on `{spl, email_id}` can separate nested calls
	 * through one singleton** — that is the whole lesson of the defect class, so
	 * depth is STRUCTURAL: one frame per `send()`, pushed at that send's own first
	 * observation and popped by that send's own self::reserve().
	 *
	 * WHAT PAIRS THE TWO OBSERVATIONS OF ONE SEND, since identity cannot. The
	 * FILTER is the discriminator: `WC_Email` evaluates `get_headers()` and then
	 * `get_attachments()` as the last two arguments of one `send()` call (WC 10.9.4,
	 * `class-wc-email.php:1173-1178` and `1209-1214`), so one send fires
	 * `woocommerce_email_headers` exactly once and then
	 * `woocommerce_email_attachments` exactly once. A HEADERS observation therefore
	 * always opens a frame; an ATTACHMENTS observation completes the frame on top
	 * when that frame is still waiting for its own — and PHP unwinds any send
	 * nested between the two completely, so the frame on top at that moment is this
	 * send's. The identity is then re-checked, but as a VERIFICATION of a frame
	 * chosen by depth, never as the thing that chose it.
	 *
	 * @var array[]
	 */
	private $send_frames = array();

	/**
	 * Bound tokens per email object, LIFO.
	 *
	 * A STACK, NOT A SCALAR: a send nested inside the mail callback must not
	 * displace the outer binding.
	 *
	 * @var array<int,string[]>
	 */
	private $bindings = array();

	/**
	 * Every reservation attempt, for diagnostics and tests.
	 *
	 * CAPPED at self::MAX_DIAGNOSTIC_ENTRIES, for the reason RenderContext caps its
	 * own diagnostics: a third party that sends in a loop drives one entry per
	 * iteration, and a diagnostic that grows without limit is a memory leak wearing
	 * a useful name.
	 *
	 * @var array[]
	 */
	private $reservation_log = array();

	/**
	 * Every finalization, for diagnostics and tests. Capped, as above.
	 *
	 * @var array[]
	 */
	private $finalizations = array();

	/**
	 * Diagnostic entries dropped at the cap, per array.
	 *
	 * @var array<string,int>
	 */
	private $dropped = array(
		'reservation_log' => 0,
		'finalizations'   => 0,
	);

	/**
	 * Send scopes whose token was never reserved, for diagnostics.
	 *
	 * @var int
	 */
	private $released_tokens = 0;

	/**
	 * Sends that could not be matched to any completed render, for diagnostics.
	 *
	 * @var int
	 */
	private $unidentified_sends = 0;

	/**
	 * Open a slot for a render.
	 *
	 * @param string $token    Render token.
	 * @param mixed  $email    Email object.
	 * @param int    $order_id Order id recorded at push — the ONLY source of
	 *                         truth for this render's order.
	 * @param string $email_id WooCommerce email id.
	 * @return void
	 */
	public function open( string $token, $email, int $order_id, string $email_id ): void {
		$this->slots[] = array(
			'token'    => $token,
			'spl'      => is_object( $email ) ? spl_object_id( $email ) : 0,
			'order_id' => $order_id,
			'email_id' => $email_id,
			'rules'    => array(),
			'state'    => self::RENDERING,
			// Whether this render ever reached a terminal position and became a send
			// candidate. Decides `abandoned` versus `unresolved` at the sweep — see
			// self::is_abandoned().
			'promoted' => false,
		);
	}

	/**
	 * Record that a rule's content was inserted into this render.
	 *
	 * A SET KEYED BY RULE ID, NOT A LIST (ADR-0004, ADR-0013 §1): a rule matching
	 * two line items still counts once, so a non-per-item position cannot
	 * double-record.
	 *
	 * ⚠ `$emitted` IS NOT A SECOND SPELLING OF "REGISTERED" (ADR-0014 §10b). A rule
	 * whose resolution THREW is registered too — the merchant is entitled to know
	 * its content was withheld from an email that went out — but nothing of it
	 * reached the message, so it must not be recorded as inserted. It is the RULE'S
	 * render outcome and is recorded ALONGSIDE the message's, never instead of it
	 * (ADR-0014 §10c).
	 *
	 * @param string $token    Render token.
	 * @param int    $rule_id  Rule id.
	 * @param int    $revision Rule revision, for the audit.
	 * @param string $position Injection position that emitted it.
	 * @param string $notes    Anything placeholder resolution had to say about
	 *                         this rule's content (ADR-0014 §1a) — carried to the
	 *                         attempt row's `reason` at finalization, because the
	 *                         render is where it is known and the record is
	 *                         written a whole send later. Stored in a keyed,
	 *                         capped SET; flatten it with self::notes_line().
	 * @param bool   $emitted  Whether content actually reached the message.
	 * @return bool True when the rule was recorded against a slot.
	 */
	public function register( string $token, int $rule_id, int $revision, string $position, string $notes = '', bool $emitted = true ): bool {
		$index = $this->index_of( $token );

		if ( $index < 0 ) {
			// A preview render — no slot exists, and that is the point.
			return false;
		}

		if ( ! isset( $this->slots[ $index ]['rules'][ $rule_id ] ) ) {
			$this->slots[ $index ]['rules'][ $rule_id ] = array(
				'rule_id'       => $rule_id,
				'revision'      => $revision,
				'position'      => $position,
				// A SET KEYED BY THE NOTE, NOT A STRING — see self::add_note().
				'notes'         => array(),
				'dropped_notes' => 0,
				'emitted'       => $emitted,
			);

			$this->add_note( $index, $rule_id, $notes );

			return true;
		}

		$existing = $this->slots[ $index ]['rules'][ $rule_id ];

		/*
		 * ACCUMULATED WITH OR, ACROSS EMISSIONS (ADR-0014 §10b). A rule emitting
		 * beside two line items and throwing on only one of them DID reach the
		 * customer, so a later `false` must not erase an earlier genuine `true`.
		 *
		 * ⚠ THIS IS ONLY SOUND BECAUSE `Injector` NO LONGER REGISTERS BEFORE THE
		 * OUTPUT IS WRITTEN. It used to register `true`, then run `wp_kses_post()`,
		 * which can throw — and the catch's `false` was then OR'd back into a `true`
		 * nothing had earned, so the ledger claimed content that was never printed.
		 * OR is the right rule ACROSS emissions and was the wrong rule WITHIN one;
		 * the ordering fix is what makes that distinction hold.
		 */
		$this->slots[ $index ]['rules'][ $rule_id ]['emitted'] = (bool) ( $existing['emitted'] ?? true ) || $emitted;

		$this->add_note( $index, $rule_id, $notes );

		return true;
	}

	/**
	 * Add one note to a rule's set, EXACTLY KEYED AND CAPPED (ADR-0014 §1d).
	 *
	 * A DISTINCT LATER NOTE IS KEPT, NOT DROPPED. The first registration wins on
	 * revision and position — one rule, one row — but "the second line item threw"
	 * is a different fact from "the first one resolved cleanly", and only one of the
	 * two would otherwise survive.
	 *
	 * ⚠ TWO THINGS WERE WRONG WITH THE CONCATENATED STRING THIS REPLACES, AND BOTH
	 * ARE CLASSES OF DEFECT ALREADY FIXED ELSEWHERE IN THIS PLUGIN:
	 *
	 *   1. **UNBOUNDED.** `PlaceholderValues` caps its own notes at 20 PER ITEM, and
	 *      this concatenated the per-item strings into one rule-level string with no
	 *      cap at all — so a fifty-line order with a per-item failure on each grew it
	 *      linearly. That is the memory-leak-wearing-a-useful-name shape Prompt 5B
	 *      capped in `RenderContext` and in this class's own diagnostics; it was
	 *      missed here only because the collection was new.
	 *   2. **INEXACT DE-DUPLICATION.** The test was `false === strpos( $existing,
	 *      $note )`, so a DISTINCT note that happened to be a SUBSTRING of one already
	 *      stored was silently discarded — `unknown placeholder {a}` swallowed by
	 *      `unknown placeholder {ab}`. A set keyed by the exact note answers the
	 *      question actually being asked.
	 *
	 * The set is flattened only at storage, by self::notes_line().
	 *
	 * @param int    $index   Slot index.
	 * @param int    $rule_id Rule id.
	 * @param string $notes   Note text; may itself be a `; `-joined run from one
	 *                        value set, which is SPLIT so each part is keyed and
	 *                        capped on its own rather than as one long line.
	 * @return void
	 */
	private function add_note( int $index, int $rule_id, string $notes ): void {
		if ( '' === trim( $notes ) ) {
			return;
		}

		foreach ( explode( '; ', $notes ) as $note ) {
			$note = trim( $note );

			if ( '' === $note || isset( $this->slots[ $index ]['rules'][ $rule_id ]['notes'][ $note ] ) ) {
				continue;
			}

			if ( count( $this->slots[ $index ]['rules'][ $rule_id ]['notes'] ) >= self::MAX_RULE_NOTES ) {
				++$this->slots[ $index ]['rules'][ $rule_id ]['dropped_notes'];
				continue;
			}

			$this->slots[ $index ]['rules'][ $rule_id ]['notes'][ $note ] = true;
		}
	}

	/**
	 * One rule entry's notes, flattened for storage (ADR-0014 §1d).
	 *
	 * THE ONLY PLACE THE SET BECOMES A STRING. Everything upstream keeps it keyed,
	 * so de-duplication stays exact and the cap stays enforceable — the same
	 * discipline `PlaceholderValues::notes_line()` follows one layer down, including
	 * saying out loud how many were dropped rather than silently truncating.
	 *
	 * A plain string is accepted and returned unchanged, so a hand-built slot in a
	 * test or a fixture flattens rather than fatalling.
	 *
	 * @param array $entry Rule entry from a slot's `rules` set.
	 * @return string
	 */
	public static function notes_line( array $entry ): string {
		$notes = $entry['notes'] ?? array();

		if ( is_string( $notes ) ) {
			return $notes;
		}

		$lines   = array_keys( (array) $notes );
		$dropped = (int) ( $entry['dropped_notes'] ?? 0 );

		if ( $dropped > 0 ) {
			$lines[] = 'and ' . $dropped . ' further render notes not recorded';
		}

		return implode( '; ', $lines );
	}

	/**
	 * The order-details block is finished: ARM the slot (ADR-0013 §5e).
	 *
	 * ARMING IS NOT CANDIDACY, AND SEPARATING THEM IS THE PROMPT 5C FIX. This is
	 * the render-complete signal — the only point every WooCommerce order-email
	 * template guarantees, HTML and plain — so it is where the slot becomes
	 * reservable. It is **not** where the render becomes the send candidate: the two
	 * post-frame positions still fire after it, and a render that publishes here has
	 * published before it has finished emitting. See self::promote_render().
	 *
	 * The slot stays open and registrable afterwards, so the post-frame positions
	 * (ADR-0013 §4a) record onto exactly this token.
	 *
	 * A PREVIEW HAS NO SLOT, so nothing is armed and preview safety stays
	 * structural: there is no token for any send to take.
	 *
	 * @param string $token Render token.
	 * @return bool True when the slot was armed.
	 */
	public function complete_render( string $token ): bool {
		$index = $this->index_of( $token );

		if ( $index < 0 || self::RENDERING !== $this->slots[ $index ]['state'] ) {
			return false;
		}

		$this->slots[ $index ]['state'] = self::AWAITING_SEND;

		return true;
	}

	/**
	 * The render has reached ITS OWN TERMINAL POSITION: it becomes the send
	 * candidate (ADR-0013 §5e).
	 *
	 * WHY THIS EXISTS SEPARATELY FROM ARMING. Candidate ORDER decides which slot a
	 * send finalises, so the order has to be produced by the render lifecycle rather
	 * than assumed from it. A render promotes itself at the last position it can
	 * reach, and our callbacks sit at `PHP_INT_MAX` on those positions — so an
	 * enclosing render always promotes after every render nested within it, because
	 * PHP unwinds the nested render before the enclosing one's next statement runs.
	 *
	 * RE-PROMOTION MOVES THE TOKEN TO THE TAIL, and that is required rather than
	 * tidy. ⚠ For HTML templates the footer fires AFTER
	 * `woocommerce_email_customer_details`, so a third party rendering from the
	 * footer would otherwise promote after the enclosing render had already done so.
	 * Re-promoting at the footer restores the enclosing render to the tail, because
	 * our footer callback also runs at `PHP_INT_MAX` — after that third party's
	 * nested render has completed and promoted itself.
	 *
	 * @param string $token Render token.
	 * @return bool True when the token is now the tail candidate.
	 */
	public function promote_render( string $token ): bool {
		$index = $this->index_of( $token );

		if ( $index < 0 || self::AWAITING_SEND !== $this->slots[ $index ]['state'] ) {
			// No slot (a preview), or the send path already took it.
			return false;
		}

		$slot = $this->slots[ $index ];
		$spl  = (int) $slot['spl'];

		$this->slots[ $index ]['promoted'] = true;

		// Remove any earlier promotion of THIS token, so re-promotion moves it to
		// the tail instead of queueing it twice.
		if ( ! empty( $this->candidates[ $spl ] ) ) {
			$this->candidates[ $spl ] = array_values(
				array_filter(
					$this->candidates[ $spl ],
					static function ( array $candidate ) use ( $token ): bool {
						return $candidate['token'] !== $token;
					}
				)
			);
		}

		$this->candidates[ $spl ][] = array(
			'token'    => $slot['token'],
			'order_id' => $slot['order_id'],
			'email_id' => $slot['email_id'],
		);

		return true;
	}

	/**
	 * A send is beginning: OPEN ITS FRAME and TAKE the exact token of the render
	 * that produced it (ADR-0013 §5d, §5g).
	 *
	 * Called from `woocommerce_email_headers` and `woocommerce_email_attachments` at
	 * `PHP_INT_MAX` — the last things evaluated before `WC_Email::send()` is entered,
	 * after every third party on those filters has run.
	 *
	 * ONE SEND CONSUMES EXACTLY ONE TOKEN, AND NESTED SENDS EACH GET THEIR OWN.
	 * ⚠ Both filters fire for a single send, because `get_headers()` and
	 * `get_attachments()` are two arguments to one `send()` call. The stage says
	 * which of the two this is, so the second observation completes the frame the
	 * first one opened instead of opening another — see self::$send_frames for why
	 * the descriptor equality this replaced could not tell a nested send from its
	 * enclosing send's second filter.
	 *
	 * WHY THE ATTACHMENTS PAIRING IS SAFE. Between one send's headers evaluation and
	 * its attachments evaluation, PHP can only run code that RETURNS before
	 * `get_attachments()` is called — a nested send there opens and closes its own
	 * frame in full. So the frame on top at an attachments observation is this
	 * send's own, unless a nested send opened a frame and then never reached
	 * `woocommerce_mail_content` (it threw, and a third party swallowed the throw).
	 * The identity check below rejects such a frame when it belongs to a different
	 * object or a different email id; when it does not, the outer send pairs with it
	 * and the accepted residual of `docs/p2-backlog.md` applies. ⚠ WHAT THE CHECK IS
	 * NOT: it VERIFIES a frame that depth already chose, and never chooses one — an
	 * identity that selected among frames is the defect this stack removed.
	 *
	 * THE TOP OF THE CANDIDATE QUEUE, VALIDATED — NEVER A SEARCH. Only the render
	 * that promoted last is a candidate, and it must belong to this email object and
	 * carry this email's id. A mismatch takes NOTHING rather than looking deeper:
	 * the same 0-or-1 law `RenderContext::current_render()` obeys, for the same
	 * reason.
	 *
	 * ⚠ THE ORDER IS DELIBERATELY NOT CHECKED, AND THAT IS A WOOCOMMERCE FACT.
	 * `woocommerce_email_headers` hands over `$this->object`, and WooCommerce does
	 * not restore that property after a nested render — so at the send it may point
	 * at some OTHER order entirely (ADR-0013 §5, flagged). Validating against it
	 * would reject the correct token and accept a wrong one. The email id comes from
	 * `$this->id`, which no render mutates.
	 *
	 * @param mixed  $email    Email object about to send.
	 * @param string $email_id Email id, from the filter's own argument.
	 * @param string $stage    Which observation this is — self::STAGE_HEADERS or
	 *                         self::STAGE_ATTACHMENTS.
	 * @return string|null The token held for this send, or null.
	 */
	public function observe_send( $email, string $email_id = '', string $stage = self::STAGE_HEADERS ): ?string {
		if ( ! is_object( $email ) ) {
			return null;
		}

		$spl  = spl_object_id( $email );
		$top  = count( $this->send_frames ) - 1;
		$pair = self::STAGE_ATTACHMENTS === $stage
			&& $top >= 0
			&& ! $this->send_frames[ $top ]['attachments']
			&& $this->send_frames[ $top ]['spl'] === $spl
			&& $this->send_frames[ $top ]['email_id'] === $email_id;

		if ( $pair ) {
			// THE SECOND OBSERVATION OF THE SEND THIS FRAME BELONGS TO: not a new
			// send, and not a second token.
			$this->send_frames[ $top ]['attachments'] = true;

			return $this->send_frames[ $top ]['token'];
		}

		$this->send_frames[] = array(
			'token'       => $this->take_candidate( $spl, $email_id ),
			'spl'         => $spl,
			'email_id'    => $email_id,
			'attachments' => self::STAGE_ATTACHMENTS === $stage,
		);

		return $this->send_frames[ count( $this->send_frames ) - 1 ]['token'];
	}

	/**
	 * Take the LAST-PROMOTED candidate for one email object, when it is this send's.
	 *
	 * THE TAIL IS THE ANSWER BECAUSE PROMOTION PUT IT THERE (ADR-0013 §5e), not
	 * because "later" is assumed to mean "outer". Each render appends itself at its
	 * own terminal position, so the tail is the render that most recently finished
	 * emitting — which is the render whose message this `send()` carries.
	 *
	 * ONLY THE TAIL IS CONSIDERED, and it must carry this send's email id. A
	 * mismatch takes NOTHING and is logged: nothing searches deeper, because a
	 * search would be selection by position under another name.
	 *
	 * @param int    $spl      Email object id.
	 * @param string $email_id Email id the send declares.
	 * @return string|null
	 */
	private function take_candidate( int $spl, string $email_id ): ?string {
		if ( empty( $this->candidates[ $spl ] ) ) {
			/*
			 * NO CANDIDATE MEANS IDENTIFY NOTHING. A render that reached neither
			 * `woocommerce_email_customer_details` nor `woocommerce_email_footer`
			 * never promoted itself — no WooCommerce 10.9.4 order-email template
			 * does that, so it takes a custom template override. The send reserves
			 * nothing and the slot is reported honestly at shutdown.
			 */
			++$this->unidentified_sends;
			return null;
		}

		$tail = $this->candidates[ $spl ][ count( $this->candidates[ $spl ] ) - 1 ];

		if ( '' !== $email_id && (string) $tail['email_id'] !== $email_id ) {
			// NOT THIS SEND'S RENDER. Nothing deeper is considered.
			++$this->unidentified_sends;
			$this->log_error(
				'refused to correlate a send of ' . $email_id . ' with the last completed render ('
				. (string) $tail['email_id'] . '): nothing was reserved for it'
			);

			return null;
		}

		array_pop( $this->candidates[ $spl ] );

		if ( array() === $this->candidates[ $spl ] ) {
			unset( $this->candidates[ $spl ] );
		}

		return (string) $tail['token'];
	}

	/**
	 * Reserve the slot this send belongs to, BEFORE any third party can create a
	 * competing one (ADR-0013 §5a).
	 *
	 * WHY A RESERVATION EXISTS AT ALL. `woocommerce_mail_content` is the first
	 * thing `WC_Email::send()` does, and third-party callbacks on it routinely
	 * render — sometimes send — other emails through the SAME shared object
	 * (ADR-0002). Anything that chose a slot at the mail callback would therefore
	 * be choosing from a set the send itself had already let grow. Taking the
	 * reservation at the top of `send()` fixes the candidate set to what existed
	 * when the send began, and every later stage then uses THAT TOKEN rather than
	 * re-selecting.
	 *
	 * NOTHING IS SELECTED HERE, AND THERE IS NO SET TO SELECT FROM (ADR-0013 §5d).
	 * The token was taken when this send's observation frame was opened, before this
	 * filter's other callbacks could add a slot; this method consumes THAT TOKEN —
	 * from THIS send's own frame, popped by depth — and never looks at
	 * the slot list except to verify it. Two things are still checked, because a
	 * carried token is a claim about state and claims are verified:
	 *
	 *   1. the slot still EXISTS — a token whose slot was swept or already resolved
	 *      reserves nothing;
	 *   2. the slot is still `awaiting_send` — one already `reserved` belongs to an
	 *      enclosing send.
	 *
	 * A blank reservation is still PUSHED, so reserve/bind stay one-to-one with
	 * `WC_Email::send()` invocations however deeply they nest.
	 *
	 * THE INNERMOST SEND FRAME IS CONSUMED, NEVER A SCALAR (ADR-0013 §5g).
	 * `woocommerce_mail_content` is the first statement inside `WC_Email::send()`,
	 * and the frame on top is the one opened by that same call's own argument
	 * evaluation — any send nested between them has already opened, consumed and
	 * closed its own frame. Popping it here is what keeps observation and
	 * reservation depth-aligned instead of identity-aligned.
	 *
	 * @return string|null The reserved token, or null when there was none.
	 */
	public function reserve(): ?string {
		$frame = array() === $this->send_frames ? null : array_pop( $this->send_frames );
		$token = null === $frame ? null : $frame['token'];

		$index = null === $token ? -1 : $this->index_of( $token );

		if ( $index >= 0 && self::AWAITING_SEND === $this->slots[ $index ]['state'] ) {
			$this->slots[ $index ]['state'] = self::RESERVED;
		} else {
			$token = null;
			$index = -1;
		}

		$this->reservations[] = array(
			'token' => $token,
			'spl'   => $index >= 0 ? $this->slots[ $index ]['spl'] : (int) ( $frame['spl'] ?? 0 ),
		);

		$this->record_diagnostic(
			'reservation_log',
			array(
				'scope'    => $frame['spl'] ?? null,
				'email_id' => $frame['email_id'] ?? '',
				'handoff'  => null !== $token,
				'token'    => $token,
				'depth'    => count( $this->reservations ),
				'enclosed' => count( $this->send_frames ),
			)
		);

		return $token;
	}

	/**
	 * Bind the reserved token to the send that is about to hand off to the mailer.
	 *
	 * NOTHING IS SELECTED HERE (ADR-0013 §5a). The innermost reservation is
	 * taken and verified against the email object; a missing, blank or mismatched reservation binds
	 * nothing, and the fallback that used to scan for the most recent
	 * `awaiting_send` slot is gone rather than merely unused.
	 *
	 * @param mixed $email Email object.
	 * @return string|null Bound token, or null when nothing was reserved for it.
	 */
	public function bind( $email ): ?string {
		if ( ! is_object( $email ) || array() === $this->reservations ) {
			return null;
		}

		$spl = spl_object_id( $email );

		// Popped whether or not it binds: it belongs to the `send()` call that is
		// executing right now, and leaving it behind would offset every
		// enclosing send's reservation by one.
		$reservation = array_pop( $this->reservations );
		$token       = $reservation['token'];

		if ( null === $token || $reservation['spl'] !== $spl ) {
			return null;
		}

		$index = $this->index_of( $token );

		if ( $index < 0 || self::RESERVED !== $this->slots[ $index ]['state'] ) {
			return null;
		}

		$this->slots[ $index ]['state'] = self::IN_FLIGHT;
		$this->bindings[ $spl ][]       = $token;

		return $token;
	}

	/**
	 * Resolve EXACTLY the bound token and hand back its slot.
	 *
	 * @param mixed $email Email object.
	 * @return array|null The resolved slot, or null when nothing was bound.
	 */
	public function finalize( $email ): ?array {
		if ( ! is_object( $email ) ) {
			return null;
		}

		$spl = spl_object_id( $email );

		if ( empty( $this->bindings[ $spl ] ) ) {
			// NEVER GUESS. No binding means this send belongs to no render of
			// ours.
			return null;
		}

		$token = array_pop( $this->bindings[ $spl ] );

		if ( empty( $this->bindings[ $spl ] ) ) {
			unset( $this->bindings[ $spl ] );
		}

		$index = $this->index_of( $token );

		if ( $index < 0 || self::IN_FLIGHT !== $this->slots[ $index ]['state'] ) {
			$this->record_diagnostic(
				'finalizations',
				array(
					'bound'    => $token,
					'resolved' => null,
					'order_id' => null,
				)
			);

			return null;
		}

		$slot = $this->slots[ $index ];
		array_splice( $this->slots, $index, 1 );

		$this->record_diagnostic(
			'finalizations',
			array(
				'bound'    => $token,
				'resolved' => $slot['token'],
				'order_id' => $slot['order_id'],
			)
		);

		return $slot;
	}

	/**
	 * Take every remaining slot that carried inserted content, emptying the
	 * ledger.
	 *
	 * Slots with NO rules are dropped silently: nothing of ours went into that
	 * message, so there is nothing to report — ADR-0005's noise floor
	 * (ADR-0013 §6).
	 *
	 * EACH SLOT KEEPS ITS STATE, which is what lets the caller tell an abandoned
	 * render from an unresolved delivery without inferring anything
	 * (self::PRE_SEND_STATES, ADR-0013 §6a).
	 *
	 * @return array[] Slots still open, with their states.
	 */
	public function take_open(): array {
		$open = array();

		foreach ( $this->slots as $slot ) {
			if ( array() !== $slot['rules'] ) {
				$open[] = $slot;
			}
		}

		/*
		 * A SEND FRAME STILL OPEN AT THE SWEEP TOOK A TOKEN AND NEVER REACHED
		 * `woocommerce_mail_content` — it threw between the two, or a third party
		 * evaluated `get_headers()` / `get_attachments()` outside a send. The token
		 * is RELEASED rather than handed to anything: its slot is still
		 * `awaiting_send`, so it is swept above and reported as an abandoned render,
		 * which is the honest answer. Passing it on would attribute one render's
		 * content to another's message.
		 */
		foreach ( $this->send_frames as $frame ) {
			if ( null !== $frame['token'] ) {
				++$this->released_tokens;
			}
		}

		$this->slots        = array();
		$this->bindings     = array();
		$this->reservations = array();
		$this->candidates   = array();
		$this->send_frames  = array();

		return $open;
	}

	/**
	 * Whether a slot state means the render never reached the send path
	 * (ADR-0013 §6a).
	 *
	 * @param array $slot Slot.
	 * @return bool
	 */
	public static function is_abandoned( array $slot ): bool {
		if ( ! in_array( (string) ( $slot['state'] ?? '' ), self::PRE_SEND_STATES, true ) ) {
			// `reserved` or `in_flight`: a send BEGAN and never reported.
			return false;
		}

		if ( self::AWAITING_SEND === (string) ( $slot['state'] ?? '' ) && empty( $slot['promoted'] ) ) {
			/*
			 * ARMED BUT NEVER PROMOTED, so this render reached neither
			 * `customer_details` nor the footer — a custom template override, since
			 * no WC 10.9.4 order-email template omits both. It was never offered to
			 * a send, so we cannot say a send did not take it: its message may well
			 * have gone out carrying our content. That is `unresolved` — outcome
			 * unknown — and NOT `abandoned`, which asserts that no send happened
			 * (ADR-0013 §5e, §6a).
			 */
			return false;
		}

		return true;
	}

	/**
	 * Promoted send candidates not yet taken by a send, per object, tail last.
	 *
	 * @return array<int,array[]>
	 */
	public function candidates(): array {
		return $this->candidates;
	}

	/**
	 * Sends that could not be matched to any completed render.
	 *
	 * @return int
	 */
	public function unidentified_sends(): int {
		return $this->unidentified_sends;
	}

	/**
	 * The token held by the INNERMOST send that has begun, or null.
	 *
	 * @return string|null
	 */
	public function send_token(): ?string {
		if ( array() === $this->send_frames ) {
			return null;
		}

		return $this->send_frames[ count( $this->send_frames ) - 1 ]['token'];
	}

	/**
	 * Send-observation frames still open, innermost last.
	 *
	 * @return array[]
	 */
	public function send_frames(): array {
		return $this->send_frames;
	}

	/**
	 * How many taken tokens were dropped because their send never reserved.
	 *
	 * @return int
	 */
	public function released_tokens(): int {
		return $this->released_tokens;
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
	 * Append to a capped diagnostic array.
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
	 * Open slots, for diagnostics and tests.
	 *
	 * @return array[]
	 */
	public function slots(): array {
		return $this->slots;
	}

	/**
	 * Reservations currently held, innermost last.
	 *
	 * @return array[]
	 */
	public function reservations(): array {
		return $this->reservations;
	}

	/**
	 * Every reservation attempt recorded so far.
	 *
	 * @return array[]
	 */
	public function reservation_log(): array {
		return $this->reservation_log;
	}

	/**
	 * Every finalization recorded so far.
	 *
	 * @return array[]
	 */
	public function finalizations(): array {
		return $this->finalizations;
	}

	/**
	 * The slot bearing a token, or -1.
	 *
	 * @param string $token Render token.
	 * @return int
	 */
	private function index_of( string $token ): int {
		foreach ( $this->slots as $index => $slot ) {
			if ( $slot['token'] === $token ) {
				return $index;
			}
		}

		return -1;
	}
}
