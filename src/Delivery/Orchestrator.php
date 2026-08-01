<?php
/**
 * The delivery lifecycle: evaluate, claim, send, record (ADR-0012 §1).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

use Extonify\WCEP\Domain\EvaluationResult;
use Extonify\WCEP\Domain\MatchDecision;
use Extonify\WCEP\Domain\PlaceholderSyntax;
use Extonify\WCEP\Domain\TriggerEvent;
use Extonify\WCEP\Email\Custom_Email;
use Extonify\WCEP\Email\EmailIdentity;
use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Matching\OrderStatuses;
use Extonify\WCEP\Matching\RuleMatcher;
use Extonify\WCEP\Repository\RuleRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an evaluated trigger into claims, emails and log rows.
 *
 * THE ONLY CLASS IN THE MATCHING-AND-DELIVERY STACK THAT WRITES.
 * `RuleMatcher`, `ItemResolver`, `Targeting`, `Specificity` and `PreparedRule`
 * are pure by ADR-0011 §9 and stay that way; every side effect lives here or in
 * `DeliveryLogger`.
 *
 * FRESHNESS IS DISCHARGED HERE (ADR-0011 §7a). The matcher makes no freshness
 * guarantee, so this class fetches active rules and evaluates them in the same
 * call stack. The deferred job re-fetches at execution time. A rule set captured
 * before a delay is never handed to the matcher.
 *
 * NOTHING ABOUT ONE RUN IS STORED ON THIS OBJECT (ADR-0012 §11). Every property
 * below is a per-REQUEST collaborator; the rule snapshot, the evaluation result
 * and the run's outcome are locals that travel down the call chain as arguments.
 * That is not tidiness — `Events` shares one instance for the whole request, and
 * a send re-enters this lifecycle whenever a third-party callback changes
 * another order's status mid-send. Per-run state on the shared object is state
 * the inner run overwrites before the outer loop has finished reading it.
 */
class Orchestrator {

	/**
	 * Rule storage. PER REQUEST: a stateless gateway onto the rules table.
	 *
	 * @var RuleRepository
	 */
	private $rules;

	/**
	 * The matching engine. PER REQUEST: pure (ADR-0011 §9) apart from the shared
	 * `ItemResolver` it holds, whose remaining caches are keyed by PRODUCT id —
	 * so a run resolving a different order neither reads nor writes another
	 * order's entry. It caches no ORDER contents at all: it used to, and an
	 * order that gained its items later in the same request was still seen as
	 * empty, which defeated ADR-0008 with a cache. See ADR-0012 §11b.
	 *
	 * @var RuleMatcher
	 */
	private $matcher;

	/**
	 * Identity consumption and logging policy. PER REQUEST: two repository
	 * handles and no run state.
	 *
	 * @var DeliveryLogger
	 */
	private $logger;

	/**
	 * Placeholder resolution (ADR-0014). PER REQUEST, and it holds NO per-delivery
	 * state: it hands out one value set per delivery, over the matcher's product
	 * cache, so nothing an inner delivery does can reach an outer one and the
	 * ADR-0014 §8 cost contract holds — no per-placeholder and no per-occurrence
	 * query growth, one bounded named cost per distinct data class a body reads.
	 *
	 * @var PlaceholderResolver
	 */
	private $placeholders;

	/**
	 * Constructor.
	 *
	 * @param RuleRepository|null      $rules        Rule storage.
	 * @param RuleMatcher|null         $matcher      Matching engine.
	 * @param DeliveryLogger|null      $logger       Delivery logger.
	 * @param PlaceholderResolver|null $placeholders Placeholder resolution;
	 *                                               defaults to one sharing THIS
	 *                                               matcher's product cache, which
	 *                                               is what keeps product
	 *                                               placeholders at zero queries
	 *                                               (ADR-0014 §8).
	 */
	public function __construct( ?RuleRepository $rules = null, ?RuleMatcher $matcher = null, ?DeliveryLogger $logger = null, ?PlaceholderResolver $placeholders = null ) {
		$this->rules        = null !== $rules ? $rules : new RuleRepository();
		$this->matcher      = null !== $matcher ? $matcher : new RuleMatcher( $this->rules );
		$this->logger       = null !== $logger ? $logger : new DeliveryLogger();
		$this->placeholders = null !== $placeholders ? $placeholders : new PlaceholderResolver( $this->matcher->item_resolver() );
	}

	/**
	 * Whether orchestration may run at all (ADR-0012 §8).
	 *
	 * @return bool
	 */
	public static function is_operational(): bool {
		if ( ! Migrator::is_operational() ) {
			// Degraded mode: the rest of the store keeps working and this
			// plugin no-ops, exactly as the activation guards promise.
			return false;
		}

		return ! self::is_rendering_preview();
	}

	/**
	 * Whether a preview or customizer render is in progress.
	 *
	 * A COARSE GUARD, ON PURPOSE (ADR-0012 §8). The full render-context
	 * machinery — render slots, the bind stack, preview-signal leak detection —
	 * belongs to insert mode in Prompt 5. Separate mode is never triggered BY a
	 * render in the first place: it is triggered by an order event. So erring
	 * toward inertness here cannot drop a real delivery, and the cost of being
	 * wrong in the other direction would be a customer email sent from a preview
	 * screen.
	 *
	 * @return bool
	 */
	public static function is_rendering_preview(): bool {
		/*
		 * WooCommerce's OWN preview signal, present since WC 9.6, and this is
		 * exactly how core reads it — `apply_filters( 'woocommerce_is_email_preview',
		 * false )` appears in WC_Email, FeaturesController and EmailPreview
		 * itself. There is no helper function to call instead, so the naming
		 * sniff is suppressed for this one line.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce-owned hook; this is core's own documented way of reading the preview state.
		if ( true === apply_filters( 'woocommerce_is_email_preview', false ) ) {
			return true;
		}

		return function_exists( 'is_customize_preview' ) && is_customize_preview();
	}

	/**
	 * Handle one WooCommerce status change: BOTH trigger families (ADR-0011 §1).
	 *
	 * Two identities, two independent evaluations, two independent claims. A
	 * `stop_processing` halt in one family does not touch the other.
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Status moved from.
	 * @param string $to       Status moved to.
	 * @return RunOutcome[] Keyed `status` and `transition`; empty when
	 *                      orchestration was inert.
	 */
	public function handle_status_change( int $order_id, string $from, string $to ): array {
		$order = $this->load_order( $order_id );
		if ( null === $order ) {
			return array();
		}

		$results = array();

		foreach (
			array(
				'status'     => TriggerEvent::status( $to ),
				'transition' => TriggerEvent::transition( $from, $to ),
			) as $family => $event
		) {
			$result = $this->run( $order, $event );
			if ( null !== $result ) {
				$results[ $family ] = $result;
			}
		}

		return $results;
	}

	/**
	 * Handle a refund (ADR-0004: keyed by refund id, so two partial refunds are
	 * two identities).
	 *
	 * @param int $order_id  Parent order id.
	 * @param int $refund_id Refund id.
	 * @return RunOutcome|null
	 */
	public function handle_refund( int $order_id, int $refund_id ): ?RunOutcome {
		$order = $this->load_order( $order_id );
		if ( null === $order ) {
			return null;
		}

		return $this->run( $order, TriggerEvent::refund( $refund_id ) );
	}

	/**
	 * Evaluate one trigger and deliver its actionable decisions.
	 *
	 * @param \WC_Order    $order Order the trigger fired on.
	 * @param TriggerEvent $event Trigger event.
	 * @param array[]|null $rules Rules to evaluate. Fetched by the matcher when
	 *                            null. Supplied only by a caller that has JUST
	 *                            re-read them (ADR-0011 §7a).
	 * @return RunOutcome|null Null when orchestration was inert.
	 */
	public function run( \WC_Order $order, TriggerEvent $event, ?array $rules = null ): ?RunOutcome {
		return $this->evaluate_and_deliver( $order, $event, $rules, true );
	}

	/**
	 * Evaluate a trigger on behalf of the ADR-0008 deferred job.
	 *
	 * IDENTICAL to self::run() except that it will NOT schedule another
	 * deferral. One deferral, never a chain (ADR-0012 §7): an order that still
	 * has no items when the job runs will not grow any by asking a third time,
	 * and a self-rescheduling job is a queue that never empties.
	 *
	 * A separate entry point rather than a boolean argument, so the guarantee is
	 * legible at every call site.
	 *
	 * @param \WC_Order    $order Order the trigger fired on.
	 * @param TriggerEvent $event Trigger event, rebuilt with its ORIGINAL identity.
	 * @return RunOutcome|null Null when orchestration was inert.
	 */
	public function run_deferred( \WC_Order $order, TriggerEvent $event ): ?RunOutcome {
		return $this->evaluate_and_deliver( $order, $event, null, false );
	}

	/**
	 * The shared lifecycle body.
	 *
	 * EVERY PER-RUN VALUE IS A LOCAL (ADR-0012 §11). The snapshot and the
	 * outcome are built here and handed to `deliver()` and `send()` as
	 * arguments, so a nested run started from inside a send gets its own and
	 * cannot overwrite this one.
	 *
	 * @param \WC_Order    $order     Order.
	 * @param TriggerEvent $event     Trigger event.
	 * @param array[]|null $rules     Rules, or null to fetch.
	 * @param bool         $may_defer Whether a zero-item order may schedule a job.
	 * @return RunOutcome|null
	 */
	private function evaluate_and_deliver( \WC_Order $order, TriggerEvent $event, ?array $rules, bool $may_defer ): ?RunOutcome {
		if ( ! self::is_operational() ) {
			return null;
		}

		/*
		 * FETCH AND PHASE-FILTER BEFORE EVALUATION (ADR-0012 §9), rather than
		 * letting the matcher fetch. Two reasons, and the second is why "after"
		 * would not do:
		 *
		 *   1. a rule belonging to another phase must produce NOTHING here — no
		 *      claim under a mode it never had, no send, no row;
		 *   2. an INSERT rule carrying `stop_processing` must not halt the
		 *      separate rules, because this is not the phase that rule belongs
		 *      to. A filter applied after evaluation could not undo a halt that
		 *      had already changed every later decision.
		 *
		 * The fetch is skipped entirely for an event that cannot trigger, so a
		 * non-triggering status change still costs no query.
		 */
		$rules = self::deliverable_in_this_phase(
			null !== $rules ? $rules : $this->fetch_rules( $event )
		);

		/*
		 * THE RULE SNAPSHOT IS A LOCAL, NOT A PROPERTY (ADR-0012 §11).
		 *
		 * It used to live on `$this`, and `Events` shares one orchestrator for
		 * the whole request. So: the outer order's run snapshotted rules A and
		 * B; sending A ran a third-party callback that changed another order's
		 * status; the inner event reached this same object and REPLACED the
		 * snapshot; the outer loop then resumed at rule B and looked it up in
		 * the inner run's map. Rule B either vanished — silently unsent — or
		 * collided with an unrelated inner rule carrying the same id and sent
		 * THAT rule's subject, content and recipients, recording a
		 * `rule_revision_sent` belonging to a different rule entirely.
		 */
		$snapshot = self::snapshot_by_id( $rules );

		$result  = $this->matcher->evaluate( $order, $event, $rules );
		$outcome = new RunOutcome( $result );

		if ( $result->deferred() ) {
			// ADR-0008: zero line items is not evidence that nothing matches.
			// Claim NOTHING — the identity is preserved for the deferred run,
			// and claiming here would make that run's claim a duplicate.
			if ( $may_defer ) {
				$this->defer( $order, $event );
			} else {
				$this->logger->record_deferral(
					(int) $order->get_id(),
					$event->identity(),
					'still zero line items at deferred execution; stopping (one deferral only)'
				);
			}
			return $outcome;
		}

		$this->deliver( $order, $result, $snapshot, $outcome );

		return $outcome;
	}

	/**
	 * Index a phase-filtered rule set by rule id.
	 *
	 * @param array[] $rules Rule rows.
	 * @return array<int,array>
	 */
	private static function snapshot_by_id( array $rules ): array {
		$snapshot = array();

		foreach ( $rules as $rule ) {
			$snapshot[ (int) ( $rule['id'] ?? 0 ) ] = $rule;
		}

		return $snapshot;
	}

	/**
	 * Walk the decisions and apply the ADR-0012 §2 identity-consumption policy.
	 *
	 * @param \WC_Order        $order    Order.
	 * @param EvaluationResult $result   Evaluation result.
	 * @param array<int,array> $snapshot THIS run's rule rows, keyed by id.
	 * @param RunOutcome       $outcome  THIS run's outcome, collected into.
	 * @return void
	 */
	private function deliver( \WC_Order $order, EvaluationResult $result, array $snapshot, RunOutcome $outcome ): void {
		if ( array() === $result->decisions() ) {
			// Nothing matched and nothing was blocked, so there is no delivery
			// question to answer — and no reason to initialise the mailer or
			// write a note about a switch that governs nothing here.
			return;
		}

		$order_id = (int) $order->get_id();
		$identity = $result->trigger_identity();
		$blocked  = $this->blocked_rule_ids( $result );
		$halting  = $this->halting_rule_id( $result );

		$email = $this->email();

		/*
		 * THE GLOBAL KILL SWITCH IS CHECKED BEFORE ANYTHING IS CLAIMED
		 * (ADR-0012 §5). Claiming first and discovering the switch afterwards
		 * consumed the identity permanently, so re-enabling the feature and
		 * re-firing the same trigger was suppressed — the merchant's switch
		 * would have been a one-way door. Nothing is consumed while the feature
		 * is off.
		 *
		 * IT READS THE SAVED SETTING ONLY (ADR-0012 §5a). `is_enabled()` applies
		 * `woocommerce_email_enabled_{id}`, which receives the order — and there
		 * is no order here, because this runs before any delivery is chosen. A
		 * filter answering "true, globally" and "false, for order #123" would
		 * have had its per-order answer read as the global switch, silently
		 * suppressing every rule on the order. The per-delivery answer is asked
		 * later, in `Custom_Email::trigger()`, with the order attached.
		 */
		if ( null === $email ) {
			$this->logger->record_inert( $order_id, $identity, 'the custom email class is not registered with WooCommerce' );
			return;
		}

		if ( ! $email->is_globally_enabled() ) {
			$this->logger->record_inert( $order_id, $identity, 'custom product emails are switched off in WooCommerce settings' );
			return;
		}

		foreach ( $result->decisions() as $decision ) {
			$reason = $decision->reason();

			if ( ! DeliveryLogger::consumes_identity( $reason ) ) {
				// `no_targeting_match` — ADR-0005's noise floor, deliberately
				// silent. `blocked_by_stop_flag` — recorded on the stop rule's
				// own row below, never as a tombstone of its own.
				continue;
			}

			// THE SAME ROW THE MATCHER DECIDED ON — never a re-read, and never
			// another run's row.
			$rule = $snapshot[ $decision->rule_id() ] ?? null;
			if ( null === $rule ) {
				continue;
			}

			$claim = $this->logger->claim( $order_id, $decision->rule_id(), $identity, (int) ( $rule['revision'] ?? 0 ) );

			if ( \Extonify\WCEP\Repository\DeliveryRepository::FAILED === $claim['result'] ) {
				// FAIL CLOSED (ADR-0012 §3): never send on a failed claim.
				$outcome->record(
					RunOutcome::CLAIM_FAILED,
					$decision->rule_id(),
					(int) ( $claim['delivery_id'] ?? 0 ),
					$this->logger->record_claim_failure( $claim, $order_id, $decision->rule_id(), $reason )
				);
				continue;
			}

			if ( \Extonify\WCEP\Repository\DeliveryRepository::SUPPRESSED === $claim['result'] ) {
				// The identity already exists. The atomic counter increment
				// happened inside claim(); nothing else is recorded and nothing
				// is sent.
				continue;
			}

			if ( MatchDecision::MATCHED === $reason ) {
				$halt_snapshot = ( $decision->rule_id() === $halting && array() !== $blocked )
					? DeliveryLogger::halt_snapshot( (int) $claim['delivery_id'], $blocked )
					: array();

				$this->send( $order, $email, $rule, $decision, $claim, $halt_snapshot, $outcome );
				continue;
			}

			$outcome->record(
				RunOutcome::SKIPPED,
				$decision->rule_id(),
				(int) $claim['delivery_id'],
				$this->logger->record_skip( $claim, $reason )
			);
		}
	}

	/**
	 * THE CONTAINMENT BOUNDARY: everything that happens after the identity is
	 * claimed runs inside it (ADR-0014 §10).
	 *
	 * ⚠ THE BOUNDARY USED TO SIT AROUND `trigger()` ALONE, AND PROMPT 6 MADE THAT
	 * REACHABLE. Recipient resolution, placeholder resolution and content assembly
	 * all happen between the claim and the send, and ADR-0014 §6.4 introduced
	 * `extonify_wcep_meta_placeholder_allowed` — a PLUGIN-OWNED extension point
	 * invoked in the middle of that window. A callback on it that throws used to
	 * propagate out through `woocommerce_order_status_changed`, breaking the
	 * merchant's status change, while the tombstone stayed `claimed` with no detail
	 * row and the consumed identity blocked every retry. That is the exact failure
	 * Prompt 4a built containment to prevent; shipping a filter that reaches around
	 * it is not something a plugin gets to do.
	 *
	 * SO THE RULE IS: A PLUGIN-OWNED FILTER INVOKED DURING RESOLUTION IS HOSTILE.
	 * It can throw, and containing it is this plugin's job, not the site owner's.
	 * The same applies to every WooCommerce formatter this resolution calls —
	 * `wc_price()`, `get_formatted_billing_address()`, `wc_format_datetime()` — each
	 * of which ends in filters a third party owns.
	 *
	 * `$settled` exists so the catch cannot double-record: a recording call that
	 * throws half-way leaves it false and the failure is recorded, while one that
	 * returned normally has already written the row this delivery gets.
	 *
	 * @param \WC_Order     $order    Order.
	 * @param Custom_Email  $email    The live email object.
	 * @param array         $rule     Rule row — the SAME one the matcher used.
	 * @param MatchDecision $decision The matched decision.
	 * @param array         $claim    Claim result.
	 * @param array         $snapshot Extra snapshot payload (halt record).
	 * @param RunOutcome    $outcome  THIS run's outcome, collected into.
	 * @return void
	 */
	private function send( \WC_Order $order, Custom_Email $email, array $rule, MatchDecision $decision, array $claim, array $snapshot, RunOutcome $outcome ): void {
		$delivery_id = (int) $claim['delivery_id'];

		/*
		 * Everything the catch needs, declared before anything can throw. A throw
		 * during recipient resolution leaves `$recipients` null, which is the case
		 * `DeliveryLogger::record_send_failure()` writes a recipient-less row for.
		 *
		 * ⚠ `values` HOLDS THE LIVE VALUE SET, NOT A COPY OF ITS NOTES (ADR-0014 §1c).
		 * Notes used to be read out of it only AFTER subject, heading and both body
		 * formats had all resolved, so a throw part way through discarded every note
		 * taken before it: a merchant whose template carried an unknown token AND a
		 * filter that then threw saw the exception alone, and never learnt about the
		 * problem they could actually fix. The object accumulates as it resolves, so
		 * holding it here means the failure row reports whatever it had reached.
		 */
		$state = array(
			'recipients' => null,
			'values'     => null,
			'subject'    => '',
			'notes'      => '',
			'settled'    => false,
		);

		try {
			$this->attempt( $order, $email, $rule, $decision, $claim, $snapshot, $outcome, $state );
		} catch ( \Throwable $error ) {
			if ( $state['settled'] ) {
				// This delivery already has its row. The throw came from the
				// recording itself, which `DeliveryLogger::verify()` has already
				// reported as a shortfall — overwriting a recorded outcome with a
				// second, contradictory one would be worse than the gap.
				return;
			}

			try {
				$outcome->record(
					RunOutcome::FAILED,
					$decision->rule_id(),
					$delivery_id,
					$this->logger->record_send_failure(
						$delivery_id,
						$state['recipients'],
						(string) $state['subject'],
						$error,
						// PARTIAL NOTES INCLUDED (ADR-0014 §1c) — whatever resolution
						// had recorded by the moment it threw.
						self::notes_for( $state ),
						$snapshot
					)
				);
			} catch ( \Throwable $while_recording ) {
				/*
				 * ⚠ THE CATCH ITSELF MUST NOT THROW. It runs inside
				 * `woocommerce_order_status_changed`; a throw escaping from here
				 * would break the merchant's status change for the sake of a log
				 * row, which inverts the whole purpose of the boundary. The
				 * shortfall goes to the WooCommerce log, and the delivery stays
				 * visible as one that never reached a terminal status.
				 */
				$this->logger->record_inert(
					(int) $order->get_id(),
					$decision->rule_id() . ' (delivery #' . $delivery_id . ')',
					'recording a contained failure ALSO threw: ' . get_class( $while_recording ) . ': ' . $while_recording->getMessage()
				);
			}
		}
	}

	/**
	 * This delivery's notes: the non-placeholder ones plus whatever the LIVE value
	 * set has accumulated so far (ADR-0014 §1a, §1c).
	 *
	 * READ AT THE POINT OF USE, NEVER SNAPSHOTTED. Both the success path and the
	 * containment boundary call this, so a delivery that threw half way through
	 * resolution reports exactly the notes it had reached — and one that completed
	 * cannot report them twice.
	 *
	 * @param array $state Containment state.
	 * @return string
	 */
	private static function notes_for( array $state ): string {
		$notes  = (string) ( $state['notes'] ?? '' );
		$values = $state['values'] ?? null;

		if ( ! $values instanceof PlaceholderValues || ! $values->has_notes() ) {
			return $notes;
		}

		return '' === $notes ? $values->notes_line() : $notes . '; ' . $values->notes_line();
	}

	/**
	 * Resolve recipients, resolve placeholders, send, and record the outcome.
	 *
	 * RUNS INSIDE self::send()'s CONTAINMENT BOUNDARY and must only ever be called
	 * from there. `$state` is this delivery's, by reference, so whatever has been
	 * established when a throw happens is what the failure row reports.
	 *
	 * @param \WC_Order     $order    Order.
	 * @param Custom_Email  $email    The live email object.
	 * @param array         $rule     Rule row — the SAME one the matcher used.
	 * @param MatchDecision $decision The matched decision.
	 * @param array         $claim    Claim result.
	 * @param array         $snapshot Extra snapshot payload (halt record).
	 * @param RunOutcome    $outcome  THIS run's outcome, collected into.
	 * @param array         $state    Containment state, by reference.
	 * @return void
	 */
	private function attempt( \WC_Order $order, Custom_Email $email, array $rule, MatchDecision $decision, array $claim, array $snapshot, RunOutcome $outcome, array &$state ): void {
		$delivery_id = (int) $claim['delivery_id'];

		/*
		 * ONE VALUE SET FOR THE WHOLE DELIVERY (ADR-0014 §8). Subject, heading and
		 * both body formats resolve against it, so each placeholder is resolved
		 * ONCE however many times it appears and whatever format it appears in —
		 * and the notes it accumulates are this delivery's, recorded once.
		 */
		$values = $this->placeholders->for_delivery( $order, $decision->matched_items() );

		// HANDED TO THE BOUNDARY IMMEDIATELY, so every throw from here on reports
		// the notes taken up to it (ADR-0014 §1c).
		$state['values'] = $values;

		$recipients          = RecipientResolver::resolve(
			$this->recipients_value( $rule ),
			array(
				RecipientResolver::TOKEN_CUSTOMER => (string) $order->get_billing_email(),
				RecipientResolver::TOKEN_ADMIN    => (string) get_option( 'admin_email', '' ),
				// ADR-0014 §7: the second — and last — address a recipient
				// placeholder may resolve to.
				RecipientResolver::TOKEN_STORE    => PlaceholderValues::store_email(),
			)
		);
		$state['recipients'] = $recipients;

		if ( ! $recipients->is_valid() || ! $recipients->is_deliverable() ) {
			// No direct recipient means nothing was sent to anybody, so this is
			// a skip with a recorded reason — never a silent no-op.
			$outcome->record(
				RunOutcome::SKIPPED,
				$decision->rule_id(),
				$delivery_id,
				$this->logger->record_skip(
					$claim,
					$recipients->is_valid()
						? 'no deliverable recipient resolved: ' . ( '' !== $recipients->reason() ? $recipients->reason() : 'the rule declares no "to" recipient' )
						: 'recipients document unusable: ' . $recipients->reason(),
					array() !== $snapshot ? array( 'snapshot' => $snapshot ) : array()
				)
			);
			$state['settled'] = true;
			return;
		}

		/*
		 * PLACEHOLDERS RESOLVE HERE, AT SEND TIME, AGAINST THE LIVE ORDER
		 * (ADR-0014 §8) — never at rule-save time, which ADR-0007 would have made
		 * Prompt 7 undo.
		 *
		 * The subject and the heading resolve in the HEADER context, so every
		 * value is `HeaderGuard`-stripped as it is substituted; the outer strip
		 * below still catches a break the MERCHANT put in the template itself.
		 * The body resolves twice — see PlaceholderResolver::render_body().
		 */
		$state['notes'] = $recipients->reason();

		$subject          = HeaderGuard::strip( $values->render( (string) ( $rule['subject'] ?? '' ), PlaceholderSyntax::CONTEXT_HEADER ) );
		$state['subject'] = $subject;

		$heading = HeaderGuard::strip( $values->render( (string) ( $rule['heading'] ?? '' ), PlaceholderSyntax::CONTEXT_HEADER ) );
		$body    = PlaceholderResolver::render_body( $values, (string) ( $rule['content'] ?? '' ) );

		if ( HeaderGuard::has_break( (string) ( $rule['subject'] ?? '' ) ) ) {
			$state['notes'] = '' === $state['notes']
				? 'stripped a line break from the subject'
				: $state['notes'] . '; stripped a line break from the subject';
		}

		/*
		 * ADR-0014 §1a: an unrecognised placeholder, a refused meta key or a stripped
		 * line break is recorded ONCE per delivery, so "why is this blank" has an
		 * answer that does not require reading the source.
		 *
		 * ⚠ THE VALUE-SET NOTES ARE MERGED AT THE POINT OF USE, NOT COPIED INTO
		 * `$state` HERE (ADR-0014 §1c). Copying them meant the state held a SNAPSHOT
		 * taken at one instant, and every note recorded after that instant — or
		 * before it, on a path that threw before reaching this line — was lost. It
		 * also made the merge a thing that could happen twice. `self::notes_for()`
		 * reads the live object, so the success path and the failure path get the
		 * same answer by construction.
		 */
		$notes = self::notes_for( $state );

		/*
		 * AN SMTP PLUGIN MUST NOT BREAK THE ORDER UPDATE, AND NEITHER MAY A
		 * PLACEHOLDER FILTER. The throw is caught by self::send()'s boundary, which
		 * wraps this whole method rather than this one call: ADR-0014 §10 moved it
		 * out because resolution — not just transport — now runs third-party code.
		 */
		$result = $email->trigger(
			array(
				'recipient'     => implode( ', ', $recipients->addresses( 'to' ) ),
				'cc'            => implode( ', ', $recipients->addresses( 'cc' ) ),
				'bcc'           => implode( ', ', $recipients->addresses( 'bcc' ) ),
				'subject'       => $subject,
				'heading'       => $heading,
				'content'       => $body['html'],
				'content_plain' => $body['plain'],
				'matched_items' => $decision->matched_items(),
				'delivery_id'   => $delivery_id,
				'object'        => $order,
			)
		);

		/*
		 * THE PER-DELIVERY FILTER IS NOT A TRANSPORT FAILURE (ADR-0012 §5a).
		 * `woocommerce_email_enabled_{id}` runs with the order, the rule content
		 * and the recipients attached, so a third party returning false there
		 * has made a DECISION about this specific delivery with everything in
		 * front of it. Recording that as `failed` blamed the mailer for
		 * something the mailer never saw, and buried a deliberate refusal among
		 * genuine SMTP breakage.
		 */
		if ( Custom_Email::DISABLED_BY_FILTER === $result ) {
			$outcome->record(
				RunOutcome::SKIPPED,
				$decision->rule_id(),
				$delivery_id,
				$this->logger->record_skip(
					$claim,
					'disabled_by_filter: the ' . Custom_Email::enabled_filter() . ' filter returned false for this delivery'
						. ( '' !== $notes ? '; ' . $notes : '' ),
					array() !== $snapshot ? array( 'snapshot' => $snapshot ) : array()
				)
			);
			$state['settled'] = true;
			return;
		}

		/*
		 * NOR IS "THERE WAS NOBODY TO SEND TO". Recipients are resolved above, so
		 * this is only reachable if header-stripping emptied the address inside
		 * `trigger()` — but "the mailer reported the message as not sent" would
		 * be untrue, and an untrue reason in the delivery log is worse than a
		 * rare one.
		 */
		if ( Custom_Email::NO_RECIPIENT === $result ) {
			$outcome->record(
				RunOutcome::SKIPPED,
				$decision->rule_id(),
				$delivery_id,
				$this->logger->record_skip(
					$claim,
					'no recipient survived header sanitisation, so nothing was sent'
						. ( '' !== $notes ? '; ' . $notes : '' ),
					array() !== $snapshot ? array( 'snapshot' => $snapshot ) : array()
				)
			);
			$state['settled'] = true;
			return;
		}

		$sent = Custom_Email::SENT === $result;

		$outcome->record(
			$sent ? RunOutcome::SENT : RunOutcome::FAILED,
			$decision->rule_id(),
			$delivery_id,
			$this->logger->record_send( $delivery_id, $recipients, $subject, $sent, $notes, $snapshot )
		);

		// ⚠ AFTER THE RECORD, NOT AFTER THE SEND. A throw from the recording itself
		// must not make the boundary write a SECOND, contradictory row saying a
		// delivered message failed — but a throw before the record still has to
		// produce one, or the tombstone is left `claimed` with nothing to explain
		// it. Between those two is where this flag goes.
		$state['settled'] = true;
	}

	/**
	 * Schedule the single ADR-0008 deferred re-evaluation.
	 *
	 * @param \WC_Order    $order Order.
	 * @param TriggerEvent $event Trigger event.
	 * @return void
	 */
	private function defer( \WC_Order $order, TriggerEvent $event ): void {
		$outcome = DeferredEvaluation::schedule( (int) $order->get_id(), $event );

		$this->logger->record_deferral(
			(int) $order->get_id(),
			$event->identity(),
			'zero line items; ' . DeferredEvaluation::describe( $outcome ),
			DeferredEvaluation::SCHEDULE_FAILED === $outcome['result'] || DeferredEvaluation::SCHEDULER_UNAVAILABLE === $outcome['result']
		);
	}

	/**
	 * The live registered email object.
	 *
	 * Read from `WC()->mailer()->get_emails()` — the LIVE object, never a fresh
	 * instance. A fresh instance would carry none of the merchant's saved
	 * settings, so the global kill switch would not apply to it.
	 *
	 * @return Custom_Email|null
	 */
	protected function email(): ?Custom_Email {
		if ( ! function_exists( 'WC' ) || ! is_object( WC()->mailer() ) ) {
			return null;
		}

		$emails = WC()->mailer()->get_emails();
		$email  = $emails[ EmailIdentity::REGISTRY_KEY ] ?? null;

		return $email instanceof Custom_Email ? $email : null;
	}

	/**
	 * Load an order by id.
	 *
	 * BY ID, NOT FROM THE HOOK ARGUMENT (ADR-0012, flagged behaviour).
	 * `woocommerce_order_status_changed` passes the order object, but ADR-0008's
	 * motivating case is an order whose items are attached AFTER the transition
	 * fires — so the passed object can be mid-construction. Reloading costs one
	 * cached read and removes the question.
	 *
	 * @param int $order_id Order id.
	 * @return \WC_Order|null
	 */
	protected function load_order( int $order_id ): ?\WC_Order {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Active rules for a trigger, or none when the event cannot fire.
	 *
	 * The `is_triggering()` pre-check costs no query — `wc_get_order_statuses()`
	 * builds an array and applies one filter — and keeps a non-triggering status
	 * change free, which it was before the fetch moved here.
	 *
	 * @param TriggerEvent $event Trigger event.
	 * @return array[]
	 */
	private function fetch_rules( TriggerEvent $event ): array {
		if ( ! OrderStatuses::is_triggering( $event ) ) {
			return array();
		}

		return $this->rules->find_active_for_trigger( $event->type(), $event->value() );
	}

	/**
	 * Keep only the rules THIS PHASE delivers (ADR-0012 §9).
	 *
	 * A TEMPORARY, DELIBERATE BOUNDARY, not a permanent rule. Prompt 5 claims
	 * insert rules under `mode = 'insert'` and Prompt 6 owns delayed rules; until
	 * then a rule belonging to either must be left ENTIRELY untouched, because
	 * the alternative is what this filter exists to stop: a rule the merchant
	 * configured as INSERT being claimed under `mode = separate` and delivered to
	 * the customer as a standalone email — content meant to appear inside their
	 * normal order email arriving as a surprise message, under an identity the
	 * rule never had.
	 *
	 * An unfiltered rule is therefore a DEFECT, never a default.
	 *
	 * THE FILTER COVERS EVERY COLUMN WHOSE BEHAVIOUR IS UNIMPLEMENTED, and the list
	 * is maintained as such rather than grown one incident at a time
	 * (self::UNIMPLEMENTED_BEHAVIOUR_DEFAULTS). ⚠ `consolidation` was missing until
	 * Prompt 5C: Prompt 5B gave it validated storage, so a merchant could store
	 * `daily` and have the rule delivered **immediately, once per trigger**, which is
	 * the behaviour of `none` under another name. Out of scope silently meant
	 * "handled by whatever path exists" — the same defect as insert rules being sent
	 * separately, arriving through DATA rather than through code.
	 *
	 * @param array[] $rules Candidate rule rows.
	 * @return array[] Rows this phase may deliver.
	 */
	public static function deliverable_in_this_phase( array $rules ): array {
		$deliverable = array();

		foreach ( $rules as $rule ) {
			if ( DeliveryLogger::MODE !== (string) ( $rule['delivery_mode'] ?? '' ) ) {
				continue;
			}

			if ( ! self::behaviour_is_implemented( $rule ) ) {
				continue;
			}

			$deliverable[] = $rule;
		}

		return $deliverable;
	}

	/**
	 * Columns carrying behaviour no phase implements yet, with the ONLY value each
	 * may hold to be deliverable (ADR-0012 §9, ADR-0013 §8a).
	 *
	 * | Column | Deliverable value | Owner |
	 * |---|---|---|
	 * | `delay_seconds` | `0` | Prompt 6 — ADR-0007 scheduling |
	 * | `consolidation` | `none` | a later prompt — ADR-0005 consolidation |
	 *
	 * A rule holding anything else is left ENTIRELY untouched by both phases: no
	 * claim, no send, no record, and — because the filter runs BEFORE evaluation —
	 * no halt of a supported rule through its `stop_processing` flag.
	 */
	const UNIMPLEMENTED_BEHAVIOUR_DEFAULTS = array(
		'delay_seconds' => '0',
		'consolidation' => 'none',
	);

	/**
	 * Whether every unimplemented-behaviour column on a rule holds its default.
	 *
	 * SHARED BY BOTH PHASES so they cannot drift: insert mode applies the same list
	 * in its indexed fetch, and this is the assertion that the two agree.
	 *
	 * @param array $rule Rule row.
	 * @return bool
	 */
	public static function behaviour_is_implemented( array $rule ): bool {
		foreach ( self::UNIMPLEMENTED_BEHAVIOUR_DEFAULTS as $column => $default ) {
			if ( (string) ( $rule[ $column ] ?? $default ) !== (string) $default ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Ids of the rules a halt prevented from running.
	 *
	 * @param EvaluationResult $result Evaluation result.
	 * @return int[]
	 */
	private function blocked_rule_ids( EvaluationResult $result ): array {
		$ids = array();

		foreach ( $result->decisions() as $decision ) {
			if ( MatchDecision::BLOCKED_BY_STOP_FLAG === $decision->reason() ) {
				$ids[] = $decision->rule_id();
			}
		}

		return $ids;
	}

	/**
	 * The id of the rule whose `stop_processing` flag caused the halt.
	 *
	 * DERIVED FROM THE DECISION SEQUENCE, which ADR-0011 §6 makes unambiguous:
	 * evaluation halts IMMEDIATELY after a matching rule that carries the flag,
	 * so the halting rule is the last `matched` decision before the first
	 * `blocked_by_stop_flag` one. Deriving it keeps the matcher pure — it has no
	 * reason to carry a delivery concern.
	 *
	 * @param EvaluationResult $result Evaluation result.
	 * @return int Rule id, or 0 when nothing halted.
	 */
	private function halting_rule_id( EvaluationResult $result ): int {
		$last_matched = 0;

		foreach ( $result->decisions() as $decision ) {
			if ( MatchDecision::BLOCKED_BY_STOP_FLAG === $decision->reason() ) {
				return $last_matched;
			}
			if ( $decision->matched() ) {
				$last_matched = $decision->rule_id();
			}
		}

		return 0;
	}

	/**
	 * The authoritative recipients value on a rule row.
	 *
	 * Prefers the raw column string when the hydrated passenger is present, for
	 * the same reason `Domain\PreparedRule` does with targeting: the raw string
	 * is what was stored, and the decoded array cannot tell broken JSON from an
	 * empty document.
	 *
	 * @param array $rule Rule row.
	 * @return mixed
	 */
	private function recipients_value( array $rule ) {
		$passenger = 'recipients' . \Extonify\WCEP\Domain\Json::RAW_SUFFIX;

		if ( array_key_exists( $passenger, $rule ) ) {
			return $rule[ $passenger ];
		}

		return $rule['recipients'] ?? array();
	}
}
