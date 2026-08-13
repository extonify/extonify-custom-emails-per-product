<?php
/**
 * The delivery lifecycle: evaluate, claim, send, record (ADR-0012 §1).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

use Extonify\WCEP\Domain\DeliverySnapshot;
use Extonify\WCEP\Domain\EvaluationResult;
use Extonify\WCEP\Domain\MatchDecision;
use Extonify\WCEP\Domain\PlaceholderSyntax;
use Extonify\WCEP\Domain\TriggerEvent;
use Extonify\WCEP\Domain\WriteResult;
use Extonify\WCEP\Email\Custom_Email;
use Extonify\WCEP\Email\EmailIdentity;
use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Matching\OrderStatuses;
use Extonify\WCEP\Matching\RuleMatcher;
use Extonify\WCEP\Repository\DeliveryRepository;
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
	 * A COARSE GUARD, ON PURPOSE (ADR-0012 §8). The full render-context machinery —
	 * render slots, the bind stack — belongs to insert mode. Separate mode is never
	 * triggered BY a render in the first place: it is triggered by an order event, so
	 * erring toward inertness is cheap and the cost of being wrong in the other
	 * direction would be a customer email sent from a preview screen.
	 *
	 * ⚠ BUT IT NOW SHARES ADR-0013 §5's DEMOTION, AND IT HAS TO (ADR-0020 §1b, gate 41).
	 * The clause above used to read the raw signal alone, with a docblock claiming that
	 * "erring toward inertness here cannot drop a real delivery". ⚠ **THAT WAS FALSE, AND
	 * A TEST WRITTEN FOR GATE 41 PROVED IT.** After a third party's interrupted preview
	 * leaks `woocommerce_is_email_preview` — which core's own
	 * `EmailPreview::render_preview_email()` does, having no `try`/`finally`
	 * (WC 11.0.1) — `is_operational()` answered false for the REST OF THE REQUEST. Every
	 * separate-mode delivery whose trigger fired afterwards returned `null` from `run()`:
	 * no claim, no tombstone, no attempt row, no log line and no email. A status change
	 * happens once, so that delivery was **silently lost**, which is precisely the
	 * outcome ADR-0013 §5 built demotion to prevent for insert mode.
	 *
	 * The two guards therefore now agree on ONE question — "is this signal trustworthy?"
	 * — answered in one place. Once `RenderContext::reconcile_previews()` has PROVEN the
	 * signal leaked (a stale preview frame with the signal still true), this guard stops
	 * believing it too.
	 *
	 * ⚠ IT READS THE ESTABLISHED VERDICT AND DOES NOT RECONCILE. Calling
	 * `reconcile_previews()` from here would tear down a preview frame that is still
	 * LIVE whenever a third party triggers an order event from inside a preview render —
	 * turning a coarse read into a destructive one. Until something proves the leak, an
	 * unproven signal is still believed, which is ADR-0012 §8's original posture and
	 * remains the safe direction to be wrong in.
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
			return ! \Extonify\WCEP\Render\RenderEvents::context()->signal_leaked();
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
		$candidates = null !== $rules ? $rules : $this->fetch_rules( $event );

		/*
		 * ⚠ THE DELAYED PHASE IS EVALUATED SEPARATELY, FROM THE SAME FETCH
		 * (ADR-0015 §7). One query, two phases — and they must be two, because
		 * `stop_processing` is phase-local: a delayed rule halting the immediate
		 * rules (or the reverse) would make one merchant's scheduling choice
		 * silently suppress deliveries in the other phase.
		 *
		 * The second evaluation costs NO extra queries. `RuleMatcher` re-resolves
		 * the order, and re-resolution is free: `WC_Order::get_items()` is memoised
		 * on the order object and the item meta is already in the cache by then
		 * (measured in Prompt 4C when the order-contents cache was removed, and
		 * measured again by this prompt's query gate).
		 */
		$scheduled_rules = ScheduledPhase::deliverable_in_this_phase( $candidates );

		$rules = self::deliverable_in_this_phase( $candidates );

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

		// ⚠ EITHER PHASE MAY WANT TO DEFER (ADR-0008, ADR-0015 §7). A zero-item
		// order with only DELAYED rules used to be evaluated as "nothing matched",
		// so its delayed email was silently never scheduled — the same defect
		// Prompt 4B fixed for the immediate phase, in the phase that did not exist
		// yet. The deferral is still ONE job, and the deferred run redoes both.
		$scheduled_result = array() === $scheduled_rules
			? null
			: $this->matcher->evaluate( $order, $event, $scheduled_rules );

		if ( $result->deferred() || ( null !== $scheduled_result && $scheduled_result->deferred() ) ) {
			// RECORDED ON THE RUN, not inferred from one phase's evaluation: either
			// can produce the deferral, and the caller asked what the RUN did.
			$outcome->mark_deferred();

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

		if ( null !== $scheduled_result ) {
			$this->schedule_delayed( $order, $scheduled_result, self::snapshot_by_id( $scheduled_rules ), $outcome );
		}

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
	 * Claim, snapshot and QUEUE every delayed rule that matched (ADR-0015 §1).
	 *
	 * THE CLAIM HAPPENS HERE, NOT WHEN THE JOB RUNS, and that is the decision
	 * ADR-0015 §1 exists to record. ADR-0004's atomic claim is the only mechanism
	 * preventing a duplicate send, and the duplicate it has to prevent is the
	 * TRIGGER firing twice before either job runs. Claiming at execution would
	 * leave nothing between those two triggers to say a delivery was already owed.
	 *
	 * ⚠ AND A SCHEDULING FAILURE CANCELS THE DELIVERY IT COULD NOT QUEUE. The
	 * identity is consumed by then — so leaving the tombstone `scheduled` with no
	 * job behind it would strand it forever in a state that reads as "in progress",
	 * with no queue entry anyone could find. `cancelled` with the failure recorded
	 * is the truthful terminal state, and it is loud (ADR-0015 §6): a delivery that
	 * silently never scheduled is a lost email, and the one thing it must never be
	 * mistaken for is a duplicate correctly suppressed.
	 *
	 * @param \WC_Order        $order    Order.
	 * @param EvaluationResult $result   Evaluation of the DELAYED rule set.
	 * @param array<int,array> $snapshot Those rule rows, keyed by id.
	 * @param RunOutcome       $outcome  THIS run's outcome, collected into.
	 * @return void
	 */
	private function schedule_delayed( \WC_Order $order, EvaluationResult $result, array $snapshot, RunOutcome $outcome ): void {
		if ( array() === $result->decisions() ) {
			return;
		}

		$order_id = (int) $order->get_id();
		$identity = $result->trigger_identity();
		$email    = $this->email();

		// The same two pre-claim gates the immediate phase applies, for the same
		// reasons (ADR-0012 §5): nothing is consumed while the feature is off.
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
				continue;
			}

			$rule = $snapshot[ $decision->rule_id() ] ?? null;

			if ( null === $rule ) {
				continue;
			}

			$claim = $this->logger->claim( $order_id, $decision->rule_id(), $identity, (int) ( $rule['revision'] ?? 0 ) );

			if ( \Extonify\WCEP\Repository\DeliveryRepository::FAILED === $claim['result'] ) {
				// FAIL CLOSED (ADR-0012 §3): never queue on a failed claim.
				$outcome->record(
					RunOutcome::CLAIM_FAILED,
					$decision->rule_id(),
					(int) ( $claim['delivery_id'] ?? 0 ),
					$this->logger->record_claim_failure( $claim, $order_id, $decision->rule_id(), $reason )
				);
				continue;
			}

			if ( \Extonify\WCEP\Repository\DeliveryRepository::SUPPRESSED === $claim['result'] ) {
				// ⚠ THE IDEMPOTENCY GUARANTEE, AND IT IS THE CLAIM THAT PROVIDES IT
				// (ADR-0015 §6). The same trigger firing twice reaches here the
				// second time, queues NOTHING, and the counter has already
				// incremented atomically inside claim().
				continue;
			}

			if ( MatchDecision::MATCHED !== $reason ) {
				$outcome->record(
					RunOutcome::SKIPPED,
					$decision->rule_id(),
					(int) $claim['delivery_id'],
					$this->logger->record_skip( $claim, $reason )
				);
				continue;
			}

			$this->queue( $order_id, $rule, $decision, $claim, $identity, $outcome );
		}
	}

	/**
	 * Snapshot one matched delayed rule and put its job on the queue, contained.
	 *
	 * @param int           $order_id Order id.
	 * @param array         $rule     The rule row the matcher decided on.
	 * @param MatchDecision $decision The matched decision.
	 * @param array         $claim    Claim result.
	 * @param string        $identity Trigger identity.
	 * @param RunOutcome    $outcome  THIS run's outcome, collected into.
	 * @return void
	 */
	private function queue( int $order_id, array $rule, MatchDecision $decision, array $claim, string $identity, RunOutcome $outcome ): void {
		/*
		 * ⚠ THE CONTAINMENT BOUNDARY FOR THE SCHEDULING PATH (ADR-0015 §8.5).
		 *
		 * Everything below runs AFTER the identity is claimed and inside
		 * `woocommerce_order_status_changed`. Action Scheduler is a database-backed
		 * queue with its own tables, its own filters and — through
		 * `action_scheduler_pre_*` and the store's own hooks — third-party code; a
		 * throw from any of it used to escape into the merchant's status change,
		 * breaking the order update AND leaving the tombstone `scheduled` with no
		 * job, no reason and a consumed identity.
		 *
		 * That is the same failure `attempt()` has been contained against since
		 * Prompt 4a, reached through the phase that did not exist then.
		 */
		try {
			$this->queue_contained( $order_id, $rule, $decision, $claim, $identity, $outcome );
		} catch ( \Throwable $error ) {
			$this->record_queue_failure( $order_id, $decision, $claim, $error, $outcome );
		}
	}

	/**
	 * Recover from a throw during scheduling, without letting it escape
	 * (ADR-0015 §8.5).
	 *
	 * TWO OUTCOMES, DECIDED BY WHETHER A JOB ACTUALLY EXISTS — never by where the
	 * throw appeared to come from. `as_schedule_single_action()` can create the
	 * action and then throw on the way back, so "it threw" and "nothing was queued"
	 * are independent facts and only the queue can be asked which happened:
	 *
	 *   - a job EXISTS: the delivery is genuinely owed. The tombstone keeps
	 *     `scheduled`, the snapshot stays, and the diagnostic shortfall is
	 *     recorded — nothing is lost, only the record of it is incomplete.
	 *   - NO job exists: the identity is consumed with nothing behind it, so the
	 *     tombstone transitions to a terminal state and the snapshot is released.
	 *
	 * @param int           $order_id Order id.
	 * @param MatchDecision $decision The matched decision.
	 * @param array         $claim    Claim result.
	 * @param \Throwable    $error    What was thrown.
	 * @param RunOutcome    $outcome  THIS run's outcome, collected into.
	 * @return void
	 */
	private function record_queue_failure( int $order_id, MatchDecision $decision, array $claim, \Throwable $error, RunOutcome $outcome ): void {
		$delivery_id = (int) ( $claim['delivery_id'] ?? 0 );
		$message     = get_class( $error ) . ': ' . $error->getMessage();

		try {
			if ( ScheduledDelivery::has_any_job( $delivery_id, $order_id ) ) {
				$this->logger->record_inert(
					$order_id,
					'delivery #' . $delivery_id,
					'scheduling threw AFTER the job was queued, so the delivery still stands but its record is '
						. 'incomplete — ' . $message
				);
				$outcome->record( RunOutcome::SCHEDULED, $decision->rule_id(), $delivery_id, self::inert_result() );
				return;
			}

			$outcome->record(
				RunOutcome::FAILED,
				$decision->rule_id(),
				$delivery_id,
				// ⚠ NOT `record_scheduled_cancellation()`: the throw may have beaten
				// the arm, leaving the row `claimed` rather than `scheduled`. That
				// method takes ONE source state, and picking either would strand the
				// other (ADR-0015 §8.5).
				$this->logger->record_schedule_throw( $delivery_id, $message )
			);
		} catch ( \Throwable $while_recording ) {
			/*
			 * ⚠ THE CATCH ITSELF MUST NOT THROW. Escaping here would break the
			 * merchant's status change for the sake of a log row — the same inversion
			 * `send()`'s boundary guards against.
			 */
			$this->logger->record_inert(
				$order_id,
				'delivery #' . $delivery_id,
				'recording a contained scheduling failure ALSO threw: ' . get_class( $while_recording )
					. ': ' . $while_recording->getMessage() . ' (original: ' . $message . ')'
			);
		}
	}

	/**
	 * Snapshot one matched delayed rule and put its job on the queue, inside
	 * self::queue()'s containment boundary.
	 *
	 * @param int           $order_id Order id.
	 * @param array         $rule     The rule row the matcher decided on.
	 * @param MatchDecision $decision The matched decision.
	 * @param array         $claim    Claim result.
	 * @param string        $identity Trigger identity.
	 * @param RunOutcome    $outcome  THIS run's outcome, collected into.
	 * @return void
	 */
	private function queue_contained( int $order_id, array $rule, MatchDecision $decision, array $claim, string $identity, RunOutcome $outcome ): void {
		$delivery_id   = (int) $claim['delivery_id'];
		$delay         = (int) ( $rule['delay_seconds'] ?? 0 );
		$scheduled_for = time() + $delay;

		// ADR-0015 §2a: templates, definitions and ids. Built from the row the
		// MATCHER used, never a re-read (ADR-0012 §10).
		$snapshot = DeliverySnapshot::create( $rule, $decision->matched_items(), $identity, $scheduled_for );

		$armed = $this->logger->record_scheduled( $claim, $snapshot, $scheduled_for );

		if ( ! $armed->won() ) {
			// ⚠ NOT AN INERT SKIP (ADR-0015 §8.1a, Prompt 7B A1). This branch used to
			// treat every non-arm as harmless on the reasoning that a job whose
			// tombstone is not `scheduled` would stop anyway — which is true, and
			// says nothing about the case where THERE IS NO JOB. A database failure
			// that throws nothing lands here with the identity claimed, the tombstone
			// `claimed`, no job, no detail row, and nothing for the maintenance sweep
			// to find, because the sweep looks for `scheduled` rows. Every later
			// trigger for that identity is then suppressed by a delivery that will
			// never happen. The recovery added for a THROW before arming does not
			// cover a returned failure.
			$this->record_arm_shortfall( $order_id, $decision, $delivery_id, $armed, $outcome );
			return;
		}

		$queued = ScheduledDelivery::schedule( $delivery_id, $order_id, $scheduled_for );

		if ( ScheduledDelivery::SCHEDULED === $queued['result'] || ScheduledDelivery::ALREADY_PENDING === $queued['result'] ) {
			$outcome->record(
				RunOutcome::SCHEDULED,
				$decision->rule_id(),
				$delivery_id,
				$this->logger->record_schedule_outcome( $delivery_id, $queued, $scheduled_for, $delay )
			);
			return;
		}

		// ⚠ NOTHING IS QUEUED AND THE IDENTITY IS ALREADY CONSUMED. Cancel, loudly.
		$outcome->record(
			RunOutcome::FAILED,
			$decision->rule_id(),
			$delivery_id,
			$this->logger->record_schedule_failure( $delivery_id, $queued )
		);
	}

	/**
	 * Act on an ARM that did not take the row (ADR-0015 §8.1a).
	 *
	 * ⚠ THE ROW DECIDES, NOT THE WRITE. Whether this delivery is stranded depends on
	 * what the tombstone holds NOW, and the four answers need four different
	 * actions — which is the whole reason `arm_scheduled()` stopped returning a
	 * boolean:
	 *
	 *   - **still `claimed`** — nobody armed it and nothing is queued. The identity
	 *     is consumed with nothing behind it, so it is terminalised with
	 *     `arm_failed`: a delivery that will not happen must not sit in a state that
	 *     claims it is about to.
	 *   - **missing** — order cleanup deleted the tombstone between the claim and
	 *     here (ADR-0004). Inert: there is nothing to record onto and nothing owed.
	 *   - **already terminal** — somebody else recorded an outcome. NOT overwritten:
	 *     the later write would replace a truthful state with a guess.
	 *   - **`scheduled` or `executing`** — another actor armed it first and owns
	 *     queueing it. Queueing a second job here would be this request acting on a
	 *     delivery it does not own.
	 *
	 * A read that FAILS is its own fifth case and is treated as "do not touch": the
	 * state is unknown, and the §8.3 sweep reaches a `scheduled` row on its own.
	 *
	 * @param int           $order_id    Order id.
	 * @param MatchDecision $decision    The matched decision.
	 * @param int           $delivery_id Tombstone id.
	 * @param WriteResult   $armed       What the arm reported.
	 * @param RunOutcome    $outcome     THIS run's outcome, collected into.
	 * @return void
	 */
	private function record_arm_shortfall( int $order_id, MatchDecision $decision, int $delivery_id, WriteResult $armed, RunOutcome $outcome ): void {
		$probe  = $this->logger->deliveries()->inspect( $delivery_id );
		$status = (string) $probe['status'];

		if ( ! (bool) $probe['known'] ) {
			$this->logger->record_inert(
				$order_id,
				'delivery #' . $delivery_id,
				'arming this delayed delivery failed (' . $armed->describe() . ') and its state could not be read '
					. 'afterwards, so nothing was written; the daily maintenance sweep is the backstop'
			);
			$outcome->record( RunOutcome::FAILED, $decision->rule_id(), $delivery_id, self::inert_result() );
			return;
		}

		if ( ! (bool) $probe['exists'] ) {
			$this->logger->record_inert(
				$order_id,
				'delivery #' . $delivery_id,
				'arming this delayed delivery found no tombstone, so the order was deleted while it was being '
					. 'scheduled and nothing is owed'
			);
			$outcome->record( RunOutcome::SKIPPED, $decision->rule_id(), $delivery_id, self::inert_result() );
			return;
		}

		if ( DeliveryRepository::CLAIMED === $status ) {
			$outcome->record(
				RunOutcome::FAILED,
				$decision->rule_id(),
				$delivery_id,
				$this->logger->record_arm_failure( $delivery_id, $armed->describe() )
			);
			return;
		}

		if ( in_array( $status, DeliveryRepository::PENDING_SCHEDULED_STATUSES, true ) ) {
			$this->logger->record_inert(
				$order_id,
				'delivery #' . $delivery_id,
				'another request armed this delayed delivery first (it is "' . $status . '"), so this one queued nothing'
			);
			$outcome->record( RunOutcome::SCHEDULED, $decision->rule_id(), $delivery_id, self::inert_result() );
			return;
		}

		$this->logger->record_inert(
			$order_id,
			'delivery #' . $delivery_id,
			'this delayed delivery was already "' . $status . '" when arming ran, so its recorded outcome was left alone'
		);
		$outcome->record( RunOutcome::SKIPPED, $decision->rule_id(), $delivery_id, self::inert_result() );
	}

	/**
	 * The structured result used when a write was deliberately not attempted.
	 *
	 * @return array
	 */
	private static function inert_result(): array {
		return array(
			'success'       => false,
			'rows_expected' => 1,
			'rows_written'  => 0,
			'finalized'     => false,
			'transition'    => null,
		);
	}

	/**
	 * Send a delivery the SCHEDULED phase queued earlier (ADR-0015 §3).
	 *
	 * ⚠ IT REUSES `attempt()` AND ITS CONTAINMENT BOUNDARY RATHER THAN REPEATING
	 * THEM. A scheduled send runs the same third-party code an immediate one does
	 * — recipient resolution, WooCommerce's formatters, this plugin's own meta
	 * filter — so it needs the same boundary; and a second implementation of
	 * "resolve, send, record" is a second place for the two to disagree about what
	 * a delivery is. The differences are entirely in the ARGUMENTS: the rule row
	 * carries snapshotted content, and the identity is already claimed.
	 *
	 * @param \WC_Order $order         Live order.
	 * @param array     $rule          Rule row built from the snapshot.
	 * @param array[]   $matched_items The snapshotted items that survived §4.
	 * @param int       $delivery_id   Tombstone id, already `scheduled`.
	 * @param int       $revision      Snapshotted revision, recorded on send.
	 * @param string    $identity      Trigger identity this delivery was claimed
	 *                                 under.
	 * @return RunOutcome|null Null when orchestration was inert.
	 */
	public function send_scheduled( \WC_Order $order, array $rule, array $matched_items, int $delivery_id, int $revision, string $identity = '' ): ?RunOutcome {
		if ( ! self::is_operational() ) {
			return null;
		}

		$email = $this->email();

		if ( null === $email ) {
			// ⚠ TERMINAL, NOT AN INERT LOG (ADR-0015 §8.4). The identity was consumed
			// at scheduling time and this run holds the lease, so a bare return left
			// the tombstone `executing` with its job spent and nothing able to reach
			// it again except the §8.3 sweep an hour later.
			$this->logger->record_inert( (int) $order->get_id(), 'delivery #' . $delivery_id, 'the custom email class is not registered with WooCommerce' );
			$this->logger->record_scheduled_cancellation(
				$delivery_id,
				ScheduledDelivery::REASON_EMAIL_UNAVAILABLE,
				ScheduledDelivery::reason_text( ScheduledDelivery::REASON_EMAIL_UNAVAILABLE ),
				\Extonify\WCEP\Repository\DeliveryRepository::EXECUTING
			);
			return null;
		}

		if ( ! $email->is_globally_enabled() ) {
			// ⚠ A TERMINAL SKIP, NOT A SILENT RETURN. The identity was consumed at
			// scheduling time, so returning without recording would leave the
			// tombstone in flight for ever. The immediate phase can return
			// silently here because it has not claimed yet; this one cannot.
			$this->logger->record_scheduled_cancellation(
				$delivery_id,
				'globally_disabled',
				'custom product emails were switched off in WooCommerce settings before this delayed delivery ran',
				\Extonify\WCEP\Repository\DeliveryRepository::EXECUTING
			);
			return null;
		}

		return $this->execute_claimed(
			$order,
			$email,
			$rule,
			$matched_items,
			$delivery_id,
			$identity,
			array( 'scheduled' => array( 'revision' => $revision ) ),
			// ⚠ THE ONE ARGUMENT THAT MAKES THE SHARED SEND PATH SAFE FOR A DELAYED
			// DELIVERY (ADR-0015 §8.1). `send()` and `attempt()` serve every phase, and
			// every terminal write they reach must be CONDITIONAL for this one —
			// otherwise a `sent` tombstone could be overwritten by a cancellation, or
			// the reverse. Passing it here means the shared code does the right thing
			// without knowing which phase called it, and passing null is what marks the
			// immediate and manual paths.
			\Extonify\WCEP\Repository\DeliveryRepository::EXECUTING
		);
	}

	/**
	 * The addresses a delivery for this rule and order would really reach.
	 *
	 * ⚠ FOR THE CONFIRMATION SCREEN, AND IT DELEGATES TO THE SAME RESOLVER THE SEND
	 * USES (ADR-0019 §6, gate 39). A confirmation that showed a merchant a DIFFERENT
	 * answer from the one the send will produce would be worse than no confirmation:
	 * they would approve a send to one set of people believing it went to another.
	 * Read-only — resolving recipients writes nothing.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $rule  Rule row.
	 * @return ResolvedRecipients
	 */
	public function preview_recipients( \WC_Order $order, array $rule ): ResolvedRecipients {
		return $this->resolve_recipients( $order, $rule );
	}

	/**
	 * Whether a manual send may proceed AT ALL (ADR-0019 §4, R6/R9).
	 *
	 * ⚠ ASKED BEFORE ANYTHING IS CLAIMED, which is the same posture `deliver()` takes
	 * and for the same reason (ADR-0012 §5): claiming first and discovering the switch
	 * afterwards would consume the identity permanently, so re-enabling the feature and
	 * asking again would be suppressed by a delivery that never happened.
	 *
	 * ⚠ IT READS THE SAVED SETTING ONLY (ADR-0012 §5a). `is_globally_enabled()` does not
	 * apply `woocommerce_email_enabled_{id}`; that per-delivery filter runs later, in
	 * `Custom_Email::trigger()`, with the order attached — so a third party answering
	 * "false for order #123" cannot be misread here as "false, globally".
	 *
	 * @return bool
	 */
	public function manual_send_is_available(): bool {
		if ( ! self::is_operational() ) {
			return false;
		}

		$email = $this->email();

		return null !== $email && $email->is_globally_enabled();
	}

	/**
	 * Send a delivery a MERCHANT asked for by hand (ADR-0019 §7).
	 *
	 * ⚠ IT IS `send_scheduled()`'s BODY WITH ONE ARGUMENT CHANGED, AND THAT IS THE
	 * WHOLE POINT OF GATE 39. A manual send runs the same third-party code an automatic
	 * one does — recipient resolution, WooCommerce's formatters, this plugin's own
	 * placeholder filters, an SMTP plugin — so it needs the same containment boundary;
	 * and a second implementation of "resolve, render, fan out, send, record" is a
	 * second place for the two to disagree about what a delivery is. Both callers reach
	 * `execute_claimed()`, which is the only body that builds a decision and enters
	 * `send()`.
	 *
	 * ⚠ NO `transition_from`, WHICH IS THE IMMEDIATE PATH'S SHAPE. There is no lease
	 * here: a manual send's tombstone was claimed microseconds ago by this same request
	 * (ADR-0019 §2), and a resend's is already terminal. Nothing else can be holding
	 * either, so the terminal write is unconditional exactly as `deliver()`'s is.
	 *
	 * ⚠ THE CALLER MUST HAVE CHECKED `is_globally_enabled()` AND THE SCHEMA BEFORE
	 * CLAIMING (ADR-0019 §4, R6/R9). The scheduled path discovers those under its lease
	 * and has to terminalise a consumed identity; the manual path refuses BEFORE it
	 * claims, so a refused action leaves no tombstone at all — the same posture
	 * `deliver()` takes.
	 *
	 * @param \WC_Order $order         Live order.
	 * @param array     $rule          Rule row, read fresh (ADR-0019 §3).
	 * @param array[]   $matched_items Items this send is bound to.
	 * @param int       $delivery_id   Tombstone id, already claimed or already terminal.
	 * @param string    $identity      Trigger identity the tombstone carries.
	 * @param string    $type          Attempt type: `manual` or `resend` (ADR-0019 §8).
	 * @param array     $snapshot      Extra diagnostic payload for the attempt rows.
	 * @return RunOutcome|null Null when orchestration was inert.
	 */
	public function send_manual( \WC_Order $order, array $rule, array $matched_items, int $delivery_id, string $identity, string $type = 'manual', array $snapshot = array() ): ?RunOutcome {
		if ( ! self::is_operational() ) {
			return null;
		}

		$email = $this->email();

		if ( null === $email ) {
			// Nothing is terminalised here. Unlike the scheduled path, this one holds no
			// lease and the caller checked before claiming, so there is no consumed
			// identity to strand — the merchant simply gets a refusal.
			return null;
		}

		return $this->execute_claimed(
			$order,
			$email,
			$rule,
			$matched_items,
			$delivery_id,
			$identity,
			$snapshot,
			null,
			$type,
			// ⚠ THE CURRENT REVISION, RECORDED ON THE TOMBSTONE (ADR-0019 §3). A resend
			// renders from the rule as it is now, so a `rule_revision_sent` still naming
			// the revision that ran months ago would make the history lie about what the
			// customer just received.
			max( 0, (int) ( $rule['revision'] ?? 0 ) )
		);
	}

	/**
	 * Send a TEST of this rule to one address the merchant supplied (ADR-0020 §4).
	 *
	 * ⚠ THE ONLY TWO THINGS THAT DIFFER FROM A REAL SEND ARE THE RECIPIENT AND THE
	 * SUBJECT MARKER, and both ride on the claim array exactly as `attempt_type` and
	 * `transition_from` do (ADR-0019 §8's pattern). Everything else — the consolidation
	 * plan, the placeholder resolution, the `Custom_Email` wrapper, the containment
	 * boundary, the attempt rows — is the shared body, because a test that rendered
	 * differently from a real send would be testing something the customer will never
	 * receive.
	 *
	 * ⚠ `$recipients` IS BUILT BY THE CALLER FROM THE MERCHANT'S OWN ADDRESS AND IS THE
	 * ONLY WAY THIS CLASS WILL EVER ADDRESS A MESSAGE TO SOMEBODY THE RULE DOES NOT NAME
	 * (gate 42). `self::resolve_recipients()` — and therefore the rule's recipients
	 * document, `{customer_email}`, the admin address and every `cc`/`bcc` channel — is
	 * NOT consulted anywhere on this path. A test reaching a real customer is Tier 1.
	 *
	 * ⚠ NO `transition_from`: a test's tombstone was claimed microseconds ago by this
	 * same request under an identity nothing else can produce (ADR-0020 §4d), so there
	 * is no lease and the terminal write is unconditional, exactly as a manual send's.
	 *
	 * @param \WC_Order          $order         Live order.
	 * @param array              $rule          Rule row, read fresh.
	 * @param array[]            $matched_items Items this send is bound to.
	 * @param int                $delivery_id   Tombstone id, already claimed.
	 * @param string             $identity      The `test:<token>` identity it carries.
	 * @param ResolvedRecipients $recipients    The merchant's address, and nothing else.
	 * @param string             $prefix        Subject marker (ADR-0020 §4e).
	 * @return RunOutcome|null Null when orchestration was inert.
	 */
	public function send_test( \WC_Order $order, array $rule, array $matched_items, int $delivery_id, string $identity, ResolvedRecipients $recipients, string $prefix ): ?RunOutcome {
		if ( ! self::is_operational() ) {
			return null;
		}

		$email = $this->email();

		if ( null === $email ) {
			// Nothing is terminalised: the caller checked before claiming, exactly as
			// `send_manual()` documents, so there is no consumed identity to strand.
			return null;
		}

		return $this->execute_claimed(
			$order,
			$email,
			$rule,
			$matched_items,
			$delivery_id,
			$identity,
			array(),
			null,
			'test',
			max( 0, (int) ( $rule['revision'] ?? 0 ) ),
			$recipients,
			$prefix
		);
	}

	/**
	 * Resolve and compose ONE message for a preview, writing and sending nothing
	 * (ADR-0020 §1, §6).
	 *
	 * ⚠ IT IS self::compose()'s OWN BODY, REACHED THROUGH THE SAME PLAN. The plan comes
	 * from `Consolidation::plan()` with the same cap and the same capped fallback the
	 * send uses, and the composition is the same private method — so a preview cannot
	 * bind `{product_name}` differently, cap differently, or resolve a placeholder
	 * differently from the delivery it is previewing.
	 *
	 * ⚠ IT CLAIMS NOTHING AND RECORDS NOTHING. No `DeliveryRepository`, no
	 * `DeliveryLogger`, no `Custom_Email::trigger()`. The containment boundary is the
	 * CALLER's (`Delivery\RulePreview`), because the notes and the failure reporting a
	 * preview wants are a screen's, not a delivery record's.
	 *
	 * @param \WC_Order $order         Order to resolve against.
	 * @param array     $rule          Rule row.
	 * @param array[]   $matched_items Items the rule matches on this order; may be empty.
	 * @return array{subject:string, heading:string, body:array{html:string,plain:string}, notes:string, messages:int}
	 */
	public function compose_preview( \WC_Order $order, array $rule, array $matched_items ): array {
		$decision = MatchDecision::create( (int) ( $rule['id'] ?? 0 ), MatchDecision::MATCHED, 'preview', $matched_items );

		$plan = Consolidation::plan( $rule, $matched_items, Consolidation::max_messages( $rule, $order ) );

		$state = array(
			'recipients' => null,
			'values'     => null,
			'subject'    => '',
			'notes'      => Consolidation::note_line( $plan ),
			'snapshot'   => array(),
			'settled'    => false,
			'prefix'     => '',
		);

		/*
		 * ⚠ THE FIRST MESSAGE OF THE PLAN, AND THE COUNT REPORTED BESIDE IT. A
		 * `per_product` rule sends N messages; previewing all N would put N full email
		 * documents on one admin page, so the screen shows the first and STATES that
		 * there would be N. Silently showing one and calling it "the email" would
		 * misrepresent a fan-out.
		 */
		$composed = $this->compose( $order, $rule, $decision, $plan['messages'][0], $state );

		return array(
			'subject'  => $composed['subject'],
			'heading'  => $composed['heading'],
			'body'     => $composed['body'],
			'notes'    => self::notes_for( $state ),
			'messages' => count( (array) $plan['messages'] ),
		);
	}

	/**
	 * THE ONE BODY THAT EXECUTES AN ALREADY-CLAIMED DECISION (ADR-0015 §3, ADR-0019 §7,
	 * ADR-0020 §4).
	 *
	 * Shared by the delayed phase, by every manual action and by the test send. It makes
	 * no decision of its own: the decision was taken hours ago by the matcher, or
	 * seconds ago by a merchant, and this executes it.
	 *
	 * @param \WC_Order               $order           Live order.
	 * @param Custom_Email            $email           The live email object.
	 * @param array                   $rule            Rule row.
	 * @param array[]                 $matched_items   Items this send is bound to.
	 * @param int                     $delivery_id     Tombstone id.
	 * @param string                  $identity        Trigger identity.
	 * @param array                   $snapshot        Extra diagnostic payload.
	 * @param string|null             $transition_from State the terminal write must move
	 *                                                 OUT of, or null for an
	 *                                                 unconditional write.
	 * @param string                  $type            Attempt type for the rows this writes.
	 * @param int                     $revision        Revision to record, or 0 to leave it.
	 * @param ResolvedRecipients|null $recipients      Addresses to use INSTEAD of the
	 *                                                 rule's, or null to resolve the
	 *                                                 rule's as every other path does.
	 * @param string                  $prefix          Subject marker, or '' for none.
	 * @return RunOutcome
	 */
	private function execute_claimed( \WC_Order $order, Custom_Email $email, array $rule, array $matched_items, int $delivery_id, string $identity, array $snapshot, ?string $transition_from, string $type = 'auto', int $revision = 0, ?ResolvedRecipients $recipients = null, string $prefix = '' ): RunOutcome {
		$decision = MatchDecision::create( (int) $rule['id'], MatchDecision::MATCHED, $identity, $matched_items );

		// No `EvaluationResult`: this run EXECUTES a decision taken elsewhere rather
		// than making one (ADR-0015 §3).
		$outcome = new RunOutcome();

		$claim = array(
			'result'             => \Extonify\WCEP\Repository\DeliveryRepository::CLAIMED,
			'delivery_id'        => $delivery_id,
			'attempt_type'       => $type,
			'rule_revision_sent' => $revision,
		);

		if ( null !== $transition_from ) {
			$claim['transition_from'] = $transition_from;
		}

		if ( null !== $recipients ) {
			// ⚠ THE OVERRIDE IS ONLY EVER SET BY self::send_test() (ADR-0020 §4b). Its
			// presence is what makes `self::recipients_for()` skip the rule's document
			// entirely, and its absence is what every other path relies on.
			$claim['recipients_override'] = $recipients;
		}

		if ( '' !== $prefix ) {
			$claim['subject_prefix'] = $prefix;
		}

		$this->send( $order, $email, $rule, $decision, $claim, $snapshot, $outcome );

		return $outcome;
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
		 *
		 * `snapshot` IS HERE RATHER THAN CLOSED OVER (ADR-0016 §5). The diagnostic
		 * payload a row carries is decided once the fan-out plan is known — a capped
		 * fallback records its own count — and the plan is built inside this boundary
		 * because it reads a filter. Keeping it in `$state` means the catch reports
		 * the payload that was actually in force, from the one place the rest of this
		 * method already reads.
		 */
		$state = array(
			'recipients' => null,
			'values'     => null,
			'subject'    => '',
			'notes'      => '',
			'snapshot'   => $snapshot,
			'settled'    => false,

			/*
			 * ⚠ THE SUBJECT MARKER, READ OFF THE CLAIM AND SEEDED HERE (ADR-0020 §4e).
			 * It travels in `$state` rather than as a parameter because `compose()` and
			 * `compose_sectioned()` already take `$state` by reference and are the two
			 * places a subject is built — so one seeding covers both, and the RECORDED
			 * subject carries the marker as well as the sent one.
			 */
			'prefix'     => (string) ( $claim['subject_prefix'] ?? '' ),
		);

		try {
			/*
			 * ⚠ THE PLAN IS BUILT INSIDE THE BOUNDARY, AND IT HAS TO BE (ADR-0016 §7).
			 * `Consolidation::max_messages()` applies
			 * `extonify_wcep_consolidation_max_messages` — a PLUGIN-OWNED extension
			 * point invoked between the claim and the send, which is the precise
			 * situation ADR-0014 §10 widened this boundary to cover. A callback there
			 * that throws must not break the merchant's status change.
			 */
			$plan = Consolidation::plan( $rule, $decision->matched_items(), Consolidation::max_messages( $rule, $order ) );

			// The plan's own diagnostics — an unrecognised stored value (§1a) or a cap
			// fallback with its count (§7) — seed the delivery's notes, so every row
			// this delivery writes carries the reason its shape is what it is.
			$state['notes'] = Consolidation::note_line( $plan );

			if ( Consolidation::is_fan_out( $plan ) ) {
				$this->fan_out( $order, $email, $rule, $decision, $claim, $outcome, $plan, $state );
			} else {
				// ONE MESSAGE: `none`, the cap fallback, and §1a all arrive here, and
				// for a plain `none` rule the snapshot is byte-identical to what it
				// was before this ADR — `Consolidation::snapshot_for()` returns nothing
				// for a rule that never asked for consolidation.
				$state['snapshot'] = self::message_snapshot( $snapshot, $plan, $plan['messages'][0] );

				$this->attempt( $order, $email, $rule, $decision, $claim, $outcome, $state, $plan['messages'][0] );
			}
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
						(array) $state['snapshot'],
						// ADR-0015 §8.1: conditional on the lease for a delayed
						// delivery, unconditional for an immediate one.
						DeliveryLogger::transition_from( $claim )
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
		$merged = self::value_notes( $state['values'] ?? null );

		if ( '' === $merged ) {
			return $notes;
		}

		return '' === $notes ? $merged : $notes . '; ' . $merged;
	}

	/**
	 * The notes from one value set, or merged across several (ADR-0016 §7).
	 *
	 * ⚠ THE SINGLE-SET PATH IS DELEGATED UNCHANGED, DELIBERATELY. Every delivery that
	 * predates the cap fallback holds exactly one value set, and re-implementing
	 * `notes_line()`'s assembly here would risk changing that string — including its
	 * own overflow sentinel, which `notes()` already appends. `none` output stays
	 * byte-identical because this branch does not touch it.
	 *
	 * THE MULTI-ENTRY PATH IS THE CAPPED FALLBACK, where the body is rendered once per
	 * unit and each section has its own set (ADR-0014 §5a). Merging is
	 * DE-DUPLICATED and RE-CAPPED by `PlaceholderValues::fold_notes()`, and both are
	 * required rather than tidy: an unknown token is unknown in every section, so sixty
	 * sections would otherwise repeat one authoring mistake sixty times, and sixty sets
	 * × `MAX_NOTES` would put twelve hundred note strings in a `reason` column that
	 * holds one sentence. The bound is therefore the SAME as a single delivery's.
	 *
	 * ⚠ AN ENTRY MAY BE A SET **OR** AN ALREADY-MERGED NOTE LIST, and the second shape
	 * is what keeps retained memory linear (ADR-0016 §7a). A section's set memoises a
	 * full-set plural string that is itself O(units), so the renderer folds each
	 * section's notes out and releases the set; what reaches here for the completed
	 * sections is therefore `string[]`, with the LIVE section still arriving as a set so
	 * a throw inside it reports what it had reached.
	 *
	 * @param mixed $values A `PlaceholderValues`, an array of sets and/or note lists,
	 *                      or null.
	 * @return string
	 */
	private static function value_notes( $values ): string {
		if ( $values instanceof PlaceholderValues ) {
			return $values->has_notes() ? $values->notes_line() : '';
		}

		$accumulator = array();

		foreach ( (array) $values as $entry ) {
			if ( $entry instanceof PlaceholderValues ) {
				$accumulator = PlaceholderValues::fold_notes( $accumulator, $entry->notes() );
				continue;
			}

			if ( is_array( $entry ) ) {
				$accumulator = PlaceholderValues::fold_notes( $accumulator, $entry );
			}
		}

		return implode( '; ', PlaceholderValues::folded_notes( $accumulator ) );
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
	 * @param RunOutcome    $outcome  THIS run's outcome, collected into.
	 * @param array         $state    Containment state, by reference.
	 * @param array         $message  The plan's single message (ADR-0016 §5). For a
	 *                                plain `none` rule its `item_id` is 0, which is
	 *                                ADR-0014 §5's unchanged first-matched-item
	 *                                binding.
	 * @return void
	 */
	private function attempt( \WC_Order $order, Custom_Email $email, array $rule, MatchDecision $decision, array $claim, RunOutcome $outcome, array &$state, array $message ): void {
		$delivery_id = (int) $claim['delivery_id'];
		$snapshot    = (array) $state['snapshot'];

		$recipients          = $this->recipients_for( $order, $rule, $claim );
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

		$state['notes'] = self::delivery_notes( $rule, $recipients, (string) $state['notes'] );

		/*
		 * PLACEHOLDERS RESOLVE HERE, AT SEND TIME, AGAINST THE LIVE ORDER
		 * (ADR-0014 §8) — never at rule-save time, which ADR-0007 would have made
		 * Prompt 7 undo.
		 */
		$composed = $this->compose( $order, $rule, $decision, $message, $state );

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
		$subject = $composed['subject'];
		$result  = $email->trigger( $this->send_args( $order, $decision, $recipients, $composed, $delivery_id ) );

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
			// ADR-0015 §8.1: `executing -> sent|failed` for a delayed delivery, an
			// unconditional write for an immediate one.
			// ADR-0019 §8: the attempt type and the revision ride on the claim, exactly
			// as `transition_from` does, so this shared line serves all three paths.
			$this->logger->record_send(
				$delivery_id,
				$recipients,
				$subject,
				$sent,
				$notes,
				$snapshot,
				DeliveryLogger::transition_from( $claim ),
				DeliveryLogger::attempt_type( $claim ),
				DeliveryLogger::attempt_revision( $claim )
			)
		);

		// ⚠ AFTER THE RECORD, NOT AFTER THE SEND. A throw from the recording itself
		// must not make the boundary write a SECOND, contradictory row saying a
		// delivered message failed — but a throw before the record still has to
		// produce one, or the tombstone is left `claimed` with nothing to explain
		// it. Between those two is where this flag goes.
		$state['settled'] = true;
	}

	/**
	 * Send N messages for ONE claimed decision (ADR-0016 §3, §5).
	 *
	 * ⚠ ONE CLAIM, N MESSAGES, ONE AGGREGATE FINALISATION. The tombstone is the unit
	 * of DECISION and this method expands that decision; it never claims anything, so
	 * a re-fired trigger is suppressed before it ever gets here.
	 *
	 * RECIPIENTS ARE RESOLVED ONCE, NOT PER MESSAGE (ADR-0016 §6), and that is a
	 * correctness statement as well as a cost one: ADR-0014 §7 limits recipient
	 * placeholders to `{customer_email}` and `{store_email}`, neither of which is
	 * product-scoped, so every message in a fan-out is addressed identically. An
	 * unusable recipients document therefore produces ONE skip for the whole delivery
	 * rather than N identical ones.
	 *
	 * ⚠ FINALISATION IS AFTER THE LOOP, AND MUST BE. Finalising per message would
	 * leave the tombstone holding whichever outcome was written LAST, so
	 * `{failed, sent}` would report `sent` and lose the failure entirely. The attempt
	 * ROWS are still written as each message completes, because a row is evidence of a
	 * side effect that has already happened (ADR-0015 §8.9).
	 *
	 * @param \WC_Order     $order    Order.
	 * @param Custom_Email  $email    The live email object.
	 * @param array         $rule     Rule row — the SAME one the matcher used.
	 * @param MatchDecision $decision The matched decision.
	 * @param array         $claim    Claim result.
	 * @param RunOutcome    $outcome  THIS run's outcome, collected into.
	 * @param array         $plan     Plan from `Consolidation::plan()`.
	 * @param array         $state    Containment state, by reference.
	 * @return void
	 */
	private function fan_out( \WC_Order $order, Custom_Email $email, array $rule, MatchDecision $decision, array $claim, RunOutcome $outcome, array $plan, array &$state ): void {
		$delivery_id = (int) $claim['delivery_id'];
		$snapshot    = (array) $state['snapshot'];

		$recipients          = $this->recipients_for( $order, $rule, $claim );
		$state['recipients'] = $recipients;

		if ( ! $recipients->is_valid() || ! $recipients->is_deliverable() ) {
			$outcome->record(
				RunOutcome::SKIPPED,
				$decision->rule_id(),
				$delivery_id,
				$this->logger->record_skip(
					$claim,
					( $recipients->is_valid()
						? 'no deliverable recipient resolved: ' . ( '' !== $recipients->reason() ? $recipients->reason() : 'the rule declares no "to" recipient' )
						: 'recipients document unusable: ' . $recipients->reason() )
						. '; no consolidated message was attempted',
					array() !== $snapshot ? array( 'snapshot' => $snapshot ) : array()
				)
			);
			$state['settled'] = true;
			return;
		}

		// PER DELIVERY, COMPUTED ONCE: both halves are facts about the RULE and the
		// recipients document, not about any one message, so appending them inside the
		// loop would repeat them in every row.
		$state['notes'] = self::delivery_notes( $rule, $recipients, (string) $state['notes'] );

		$result = new FanOutResult();

		foreach ( $plan['messages'] as $message ) {
			$this->fan_out_message( $order, $email, $rule, $decision, $delivery_id, $plan, $message, $recipients, $result, $state );
		}

		$outcome->record(
			$result->run_action(),
			$decision->rule_id(),
			$delivery_id,
			// ADR-0015 §8.1: `executing -> …` for a delayed delivery, an unconditional
			// write for an immediate one — unchanged by the fan-out, because there is
			// still exactly one tombstone and exactly one terminal write.
			$this->logger->record_fanout_outcome( $delivery_id, $result, DeliveryLogger::transition_from( $claim ) )
		);

		$state['settled'] = true;
	}

	/**
	 * Compose and send ONE message of a fan-out, contained (ADR-0016 §5).
	 *
	 * ⚠ THE CONTAINMENT IS PER MESSAGE, AND THAT IS THE POINT. One message's failure
	 * must not abort the remaining messages — the Prompt 6A boundary applied per
	 * message rather than per delivery. Message 2 throwing while resolving a
	 * placeholder leaves messages 1 and 3 to send, is recorded against message 2, and
	 * never escapes into `woocommerce_order_status_changed`.
	 *
	 * ⚠ `values` AND `subject` ARE RESET FIRST. They are per-MESSAGE facts living on
	 * per-DELIVERY state, so a message that throws before setting them would otherwise
	 * have the PREVIOUS message's value-set notes and subject written onto its own
	 * failure row — one product's diagnostics attributed to another, which is the
	 * ADR-0012 §11 shape at message scale.
	 *
	 * @param \WC_Order          $order       Order.
	 * @param Custom_Email       $email       The live email object.
	 * @param array              $rule        Rule row.
	 * @param MatchDecision      $decision    The matched decision.
	 * @param int                $delivery_id Tombstone id.
	 * @param array              $plan        Plan from `Consolidation::plan()`.
	 * @param array              $message     This message's descriptor.
	 * @param ResolvedRecipients $recipients  Recipients, resolved once per delivery.
	 * @param FanOutResult       $result      The fan-out's accumulator.
	 * @param array              $state       Containment state, by reference.
	 * @return void
	 */
	private function fan_out_message( \WC_Order $order, Custom_Email $email, array $rule, MatchDecision $decision, int $delivery_id, array $plan, array $message, ResolvedRecipients $recipients, FanOutResult $result, array &$state ): void {
		$state['values']  = null;
		$state['subject'] = '';

		$snapshot = self::message_snapshot( (array) $state['snapshot'], $plan, $message );

		try {
			$composed = $this->compose( $order, $rule, $decision, $message, $state );
			$sent     = $email->trigger( $this->send_args( $order, $decision, $recipients, $composed, $delivery_id ) );

			$this->logger->record_fanout_message(
				$delivery_id,
				$result,
				$message,
				$recipients,
				$composed['subject'],
				self::message_outcome( $sent ),
				self::message_reason( $sent, self::notes_for( $state ) ),
				Custom_Email::NOT_SENT === $sent ? 'the mailer reported the message as not sent' : null,
				$snapshot
			);
		} catch ( \Throwable $error ) {
			$this->record_message_failure( (int) $order->get_id(), $delivery_id, $result, $message, $recipients, $error, $snapshot, $state );
		}
	}

	/**
	 * Record one fan-out message that threw, without letting the throw escape.
	 *
	 * ⚠ THE TALLY IS UPDATED EVEN WHEN THE RECORDING ITSELF THROWS. `$result->record()`
	 * is the LAST thing `record_fanout_message()` does, so a throw from writing the
	 * rows means the message is not in the tally at all — and a missing FAILED entry
	 * would let the aggregate report `sent` for a fan-out one of whose messages did
	 * not go out. That is the outcome-truthfulness failure this whole ADR turns on, so
	 * the inner catch records the message directly.
	 *
	 * @param int                $order_id    Order id, for the last-resort log line.
	 * @param int                $delivery_id Tombstone id.
	 * @param FanOutResult       $result      The fan-out's accumulator.
	 * @param array              $message     This message's descriptor.
	 * @param ResolvedRecipients $recipients  Recipients for this delivery.
	 * @param \Throwable         $error       What was thrown.
	 * @param array              $snapshot    This message's snapshot payload.
	 * @param array              $state       Containment state, by reference.
	 * @return void
	 */
	private function record_message_failure( int $order_id, int $delivery_id, FanOutResult $result, array $message, ResolvedRecipients $recipients, \Throwable $error, array $snapshot, array &$state ): void {
		$described = get_class( $error ) . ': ' . $error->getMessage();

		try {
			$this->logger->record_fanout_message(
				$delivery_id,
				$result,
				$message,
				$recipients,
				(string) $state['subject'],
				FanOutResult::FAILED,
				// PARTIAL NOTES INCLUDED (ADR-0014 §1c) — whatever THIS message's value
				// set had recorded by the moment it threw.
				self::notes_for( $state ),
				$described,
				$snapshot
			);
		} catch ( \Throwable $while_recording ) {
			/*
			 * ⚠ THE TALLY FIRST, THE LOG LINE SECOND. `record_inert()` reaches
			 * `wc_get_logger()` and can itself throw, and if it did before the tally was
			 * updated the aggregate would be computed from a set that is missing this
			 * message — reporting `sent` for a fan-out one of whose messages did not go
			 * out. The tally is the load-bearing fact; the log line is the diagnostic.
			 */
			$result->record( $message, FanOutResult::FAILED, 1, 0, $described );

			$this->logger->record_inert(
				$order_id,
				'delivery #' . $delivery_id . ' message ' . (int) $message['index'],
				'recording a contained consolidated-message failure ALSO threw: '
					. get_class( $while_recording ) . ': ' . $while_recording->getMessage()
					. ' (original: ' . $described . ')'
			);
		}
	}

	/**
	 * One message's `trigger()` outcome, as a `FanOutResult` code.
	 *
	 * ⚠ A FILTER REFUSAL AND AN EMPTY RECIPIENT ARE SKIPS, NOT FAILURES
	 * (ADR-0012 §5a). `woocommerce_email_enabled_{id}` runs with the order, the
	 * content and the recipients attached, so a third party returning false there made
	 * a DECISION; recording it as `failed` would blame the mailer for something the
	 * mailer never saw, and would drag the whole fan-out's aggregate to `failed` with
	 * it.
	 *
	 * @param string $sent A `Custom_Email::trigger()` outcome.
	 * @return string
	 */
	private static function message_outcome( string $sent ): string {
		if ( Custom_Email::SENT === $sent ) {
			return FanOutResult::SENT;
		}

		if ( Custom_Email::DISABLED_BY_FILTER === $sent || Custom_Email::NO_RECIPIENT === $sent ) {
			return FanOutResult::SKIPPED;
		}

		return FanOutResult::FAILED;
	}

	/**
	 * One message's stored `reason`, naming WHY when it is not a plain send.
	 *
	 * @param string $sent  A `Custom_Email::trigger()` outcome.
	 * @param string $notes This message's accumulated notes.
	 * @return string
	 */
	private static function message_reason( string $sent, string $notes ): string {
		if ( Custom_Email::DISABLED_BY_FILTER === $sent ) {
			$notes = self::join_notes(
				'disabled_by_filter: the ' . Custom_Email::enabled_filter() . ' filter returned false for this message',
				$notes
			);
		}

		if ( Custom_Email::NO_RECIPIENT === $sent ) {
			$notes = self::join_notes( 'no recipient survived header sanitisation, so nothing was sent', $notes );
		}

		return $notes;
	}

	/**
	 * Resolve ONE message's content against ITS OWN value set (ADR-0016 §6).
	 *
	 * ⚠ ONE VALUE SET PER MESSAGE, NEVER ONE REUSED ACROSS MESSAGES. The set memoises
	 * by `name:parameter`, so a shared set would answer message 2 with message 1's
	 * memoised `{product_name}` — the ADR-0014 §5a defect reintroduced through the
	 * cache. This is the single most likely way to get consolidation subtly wrong, and
	 * `for_delivery()` returning a NEW object per call is what prevents it.
	 *
	 * The binding itself is ADR-0014 §5a's, on a different axis: `item_id` is the
	 * unit's representative line item for a `per_product` message and 0 for a combined
	 * one, and at 0 the singular item placeholders resolve against the first matched
	 * item exactly as they always have.
	 *
	 * The subject and the heading resolve in the HEADER context, so every value is
	 * `HeaderGuard`-stripped as it is substituted; the outer strip still catches a
	 * break the MERCHANT put in the template itself. The body resolves twice — see
	 * `PlaceholderResolver::render_body()`.
	 *
	 * @param \WC_Order     $order    Order.
	 * @param array         $rule     Rule row.
	 * @param MatchDecision $decision The matched decision.
	 * @param array         $message  This message's descriptor.
	 * @param array         $state    Containment state, by reference.
	 * @return array{subject:string,heading:string,body:array{html:string,plain:string}}
	 */
	private function compose( \WC_Order $order, array $rule, MatchDecision $decision, array $message, array &$state ): array {
		$sections = (array) ( $message['sections'] ?? array() );

		if ( array() !== $sections ) {
			// THE CAP FALLBACK ONLY (ADR-0016 §7). Empty for `none` and for every real
			// fan-out message, so both take the unchanged path below.
			return $this->compose_sectioned( $order, $rule, $decision, $message, $sections, $state );
		}

		/*
		 * ⚠ THE FULL MATCHED SET, PLUS THIS MESSAGE'S BINDING. The plural forms —
		 * `{product_names}`, `{matched_product_list}` — deliberately list ALL matched
		 * products in every message (ADR-0016 §6): a merchant writing them asked for
		 * the whole set, and narrowing them would make `{product_names}` a duplicate of
		 * `{product_name}` under a misleading name. Only the singular forms move, and
		 * the binding is what moves them.
		 */
		$values = $this->placeholders->for_delivery( $order, $decision->matched_items(), (int) $message['item_id'] );

		// HANDED TO THE BOUNDARY AS SOON AS IT EXISTS, so every throw from here on
		// reports the notes taken up to it (ADR-0014 §1c).
		$state['values'] = $values;

		$subject          = self::subject_line( $state, $values->render( (string) ( $rule['subject'] ?? '' ), PlaceholderSyntax::CONTEXT_HEADER ) );
		$state['subject'] = $subject;

		return array(
			'subject' => $subject,
			'heading' => HeaderGuard::strip( $values->render( (string) ( $rule['heading'] ?? '' ), PlaceholderSyntax::CONTEXT_HEADER ) ),
			'body'    => PlaceholderResolver::render_body( $values, (string) ( $rule['content'] ?? '' ) ),
		);
	}

	/**
	 * One resolved subject line, with the claim's marker in front of it (ADR-0020 §4e).
	 *
	 * ⚠ THE MARKER GOES ON BEFORE `HeaderGuard::strip()`, NOT AFTER, so the whole line —
	 * marker and resolved values together — passes the one header sanitisation. Stripping
	 * first and concatenating afterwards would put an unsanitised join between two
	 * sanitised halves, which is the shape a header-injection fix usually regresses into.
	 *
	 * ⚠ AND IT IS APPLIED WHERE THE SUBJECT IS BUILT, so the RECORDED subject carries it
	 * too. A history row saying a plain subject went out while the mail said `[Test] …`
	 * would be the history lying about a message that exists.
	 *
	 * @param array  $state    Containment state, read for its `prefix`.
	 * @param string $resolved The subject with placeholders already substituted.
	 * @return string
	 */
	private static function subject_line( array $state, string $resolved ): string {
		$prefix = (string) ( $state['prefix'] ?? '' );

		return HeaderGuard::strip( '' === $prefix ? $resolved : $prefix . $resolved );
	}

	/**
	 * Compose the CAP FALLBACK: one message whose body covers every unit
	 * (ADR-0016 §7).
	 *
	 * ⚠ WHY THIS EXISTS. The fallback used to reuse self::compose() with
	 * `item_id = 0`, which is ADR-0014 §5's first-matched-item binding — so a rule
	 * whose template reads `Care guide for {product_name}` produced ONE message naming
	 * PRODUCT ONE, and products 2–60 appeared nowhere the customer could see. The
	 * fallback's own ADR clause claimed it "contained all matched products"; it
	 * contained them only if the merchant had pre-emptively added a plural placeholder
	 * to a template written for a single product.
	 *
	 * THE SUBJECT AND THE HEADING BIND THROUGH `item_id`, EXACTLY AS `none` DOES, and
	 * that is the whole reason this method does not touch them: they can carry one
	 * value, so they carry the first unit's — which is what `none` has always done, so
	 * the fallback introduces no new header semantics. The per-unit completeness lives
	 * entirely in the body.
	 *
	 * ⚠ THE BOUNDARY IS UPDATED AS EACH SECTION STARTS, NOT ONCE THE LOOP RETURNS, and
	 * that is a correction to what this method used to do. It handed over the HEADER's
	 * set, then the section sets only after EVERY section had completed — so a throw in
	 * section 5 discarded sections 1–4's notes entirely while the comment claimed they
	 * were reported. The renderer now calls back before each section with the live set
	 * and the completed sections' merged notes, so what the failure row reports is what
	 * resolution had actually reached (ADR-0014 §1a, §1c).
	 *
	 * ⚠ AND THE COMPLETED SECTIONS ARRIVE AS NOTES RATHER THAN AS SETS (ADR-0016 §7a).
	 * Each section's set memoises a full-set plural that is itself O(units); retaining
	 * one per unit would be quadratic memory. `self::value_notes()` therefore accepts
	 * both shapes.
	 *
	 * @param \WC_Order     $order    Order.
	 * @param array         $rule     Rule row.
	 * @param MatchDecision $decision The matched decision.
	 * @param array         $message  This message's descriptor.
	 * @param array[]       $sections Section descriptors from the plan.
	 * @param array         $state    Containment state, by reference.
	 * @return array{subject:string,heading:string,body:array{html:string,plain:string}}
	 */
	private function compose_sectioned( \WC_Order $order, array $rule, MatchDecision $decision, array $message, array $sections, array &$state ): array {
		$header = $this->placeholders->for_delivery( $order, $decision->matched_items(), (int) $message['item_id'] );

		// HANDED OVER BEFORE THE BODY RENDERS, so a throw inside the sections still
		// reports the header's notes (ADR-0014 §1c).
		$state['values'] = array( $header );

		$body = $this->placeholders->render_sectioned_body(
			$order,
			$decision->matched_items(),
			$sections,
			(string) ( $rule['content'] ?? '' ),
			static function ( PlaceholderValues $live, array $so_far ) use ( &$state, $header ): void {
				$state['values'] = array( $header, $so_far, $live );
			}
		);

		$state['values'] = array( $header, $body['notes'] );

		$subject          = self::subject_line( $state, $header->render( (string) ( $rule['subject'] ?? '' ), PlaceholderSyntax::CONTEXT_HEADER ) );
		$state['subject'] = $subject;

		return array(
			'subject' => $subject,
			'heading' => HeaderGuard::strip( $header->render( (string) ( $rule['heading'] ?? '' ), PlaceholderSyntax::CONTEXT_HEADER ) ),
			'body'    => array(
				'html'  => $body['html'],
				'plain' => $body['plain'],
			),
		);
	}

	/**
	 * The argument set one message hands `Custom_Email::trigger()`.
	 *
	 * ⚠ `matched_items` IS THE WHOLE DECISION'S, NOT THE MESSAGE'S UNIT (ADR-0016 §6).
	 * That property describes the DECISION — one per rule per trigger — and a
	 * per-message variant would give one field two meanings for the sake of something
	 * nothing currently renders. A message's own scope is carried by the placeholder
	 * binding in self::compose(), which is the one place it belongs.
	 *
	 * @param \WC_Order          $order       Order.
	 * @param MatchDecision      $decision    The matched decision.
	 * @param ResolvedRecipients $recipients  Resolved recipients.
	 * @param array              $composed    Output of self::compose().
	 * @param int                $delivery_id Tombstone id.
	 * @return array
	 */
	private function send_args( \WC_Order $order, MatchDecision $decision, ResolvedRecipients $recipients, array $composed, int $delivery_id ): array {
		return array(
			'recipient'     => implode( ', ', $recipients->addresses( 'to' ) ),
			'cc'            => implode( ', ', $recipients->addresses( 'cc' ) ),
			'bcc'           => implode( ', ', $recipients->addresses( 'bcc' ) ),
			'subject'       => $composed['subject'],
			'heading'       => $composed['heading'],
			'content'       => $composed['body']['html'],
			'content_plain' => $composed['body']['plain'],
			'matched_items' => $decision->matched_items(),
			'delivery_id'   => $delivery_id,
			'object'        => $order,
		);
	}

	/**
	 * The addresses THIS delivery uses: the claim's override when it has one, the
	 * rule's own document otherwise (ADR-0020 §4b).
	 *
	 * ⚠ THE OVERRIDE IS THE TEST SEND'S, AND IT IS A REPLACEMENT RATHER THAN AN
	 * ADDITION. When it is present the rule's recipients document is not read, not
	 * resolved and not merged — so a rule whose `to` is `customer` cannot contribute the
	 * customer's address to a test, and no `cc` or `bcc` channel can contribute one
	 * either. That exclusion is gate 42, and it is structural: there is exactly one
	 * `return` between the override and the send.
	 *
	 * ⚠ EVERY OTHER PATH REACHES THE SECOND BRANCH. The immediate, delayed, manual and
	 * resend paths set no override, so their behaviour is byte-identical to what it was
	 * before this method existed.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $rule  Rule row.
	 * @param array     $claim Claim result, which may carry `recipients_override`.
	 * @return ResolvedRecipients
	 */
	private function recipients_for( \WC_Order $order, array $rule, array $claim ): ResolvedRecipients {
		$override = $claim['recipients_override'] ?? null;

		if ( $override instanceof ResolvedRecipients ) {
			return $override;
		}

		return $this->resolve_recipients( $order, $rule );
	}

	/**
	 * Resolve this delivery's recipients.
	 *
	 * ONCE PER DELIVERY, WHATEVER THE MESSAGE COUNT (ADR-0016 §6). ADR-0014 §7 permits
	 * no product-scoped recipient placeholder, so a fan-out's messages are addressed
	 * identically and resolving per message would be N answers to one question.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $rule  Rule row.
	 * @return ResolvedRecipients
	 */
	private function resolve_recipients( \WC_Order $order, array $rule ): ResolvedRecipients {
		return RecipientResolver::resolve(
			$this->recipients_value( $rule ),
			array(
				RecipientResolver::TOKEN_CUSTOMER => (string) $order->get_billing_email(),
				RecipientResolver::TOKEN_ADMIN    => (string) get_option( 'admin_email', '' ),
				// ADR-0014 §7: the second — and last — address a recipient
				// placeholder may resolve to.
				RecipientResolver::TOKEN_STORE    => PlaceholderValues::store_email(),
			)
		);
	}

	/**
	 * The notes that belong to the DELIVERY rather than to any one message.
	 *
	 * Both are facts about the rule and the recipients document, so a fan-out computes
	 * them once: appending them per message would repeat one sentence in N rows and
	 * push the message's own diagnostics out of the `reason` column.
	 *
	 * The line-break check reads the stored SUBJECT TEMPLATE, so its answer does not
	 * depend on rendering and it is computed before it rather than after — which also
	 * means a delivery that throws mid-render now reports it, where before the note
	 * was lost.
	 *
	 * @param array              $rule       Rule row.
	 * @param ResolvedRecipients $recipients Resolved recipients.
	 * @param string             $existing   Notes already accumulated (the plan's).
	 * @return string
	 */
	private static function delivery_notes( array $rule, ResolvedRecipients $recipients, string $existing ): string {
		$notes = self::join_notes( $existing, $recipients->reason() );

		if ( HeaderGuard::has_break( (string) ( $rule['subject'] ?? '' ) ) ) {
			$notes = self::join_notes( $notes, 'stripped a line break from the subject' );
		}

		return $notes;
	}

	/**
	 * Join two note fragments with the separator the rest of the plugin uses.
	 *
	 * @param string $existing Notes so far.
	 * @param string $addition Note to append.
	 * @return string
	 */
	private static function join_notes( string $existing, string $addition ): string {
		if ( '' === $addition ) {
			return $existing;
		}

		return '' === $existing ? $addition : $existing . '; ' . $addition;
	}

	/**
	 * One message's snapshot payload: the delivery's, plus its place in the fan-out.
	 *
	 * ⚠ FOR A PLAIN `none` RULE THIS RETURNS THE PAYLOAD UNCHANGED.
	 * `Consolidation::snapshot_for()` yields nothing for a rule that never asked for
	 * consolidation, so a delivery that predates this ADR writes byte-identical rows.
	 *
	 * @param array $snapshot The delivery's payload (halt record, scheduled audit).
	 * @param array $plan     Plan from `Consolidation::plan()`.
	 * @param array $message  One of its messages.
	 * @return array
	 */
	private static function message_snapshot( array $snapshot, array $plan, array $message ): array {
		return array_merge( $snapshot, Consolidation::snapshot_for( $plan, $message ) );
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
	 * A DELIBERATE BOUNDARY, not an incidental one. A rule belonging to another
	 * phase must be left ENTIRELY untouched here, because the alternative is what
	 * this filter exists to stop: a rule the merchant configured as INSERT being
	 * claimed under `mode = separate` and delivered to the customer as a standalone
	 * email — content meant to appear inside their normal order email arriving as a
	 * surprise message, under an identity the rule never had.
	 *
	 * An unfiltered rule is therefore a DEFECT, never a default.
	 *
	 * ⚠ THIS PHASE IS NOW `separate` + **`delay_seconds = 0`** (ADR-0015 §7).
	 * `delay_seconds` used to be an UNIMPLEMENTED-behaviour column, excluded from
	 * every phase; Prompt 7 implements it, so a non-zero delay is no longer
	 * "nobody's" — it is `ScheduledPhase`'s. The zero check moves here, into the
	 * phase definition, and stays explicit: dropping it would make this phase send
	 * a delayed rule immediately, which is the behaviour of `delay = 0` under
	 * another name.
	 *
	 * THE UNIMPLEMENTED-BEHAVIOUR LIST COVERS WHAT IS STILL UNOWNED, and is
	 * maintained as a list rather than grown one incident at a time
	 * (self::UNIMPLEMENTED_BEHAVIOUR_DEFAULTS). ⚠ IT IS NOW **EMPTY**
	 * (ADR-0016 §9): `consolidation` was its last entry and Prompt 8 implements it, so
	 * this phase delivers `per_product` rules as well as `none` ones and the fan-out
	 * happens BELOW the claim (ADR-0016 §3). The list and its test stay, so a future
	 * column re-arms the gate the moment somebody adds it.
	 *
	 * ⚠ `consolidation` IS NO LONGER FILTERED HERE, AND IT DOES NOT NEED TO BE. Its
	 * vocabulary is now an ENUMERATION at the write boundary (ADR-0016 §1), so a value
	 * outside `Consolidation::MODES` cannot be stored at all — which is strictly
	 * stronger than a phase filter that had to remember it. That filter existed
	 * because Prompt 5B gave the column validated STORAGE with no vocabulary, so a
	 * merchant could store `daily` and have the rule delivered immediately, once per
	 * trigger: `none`'s behaviour under another name.
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

			// THE IMMEDIATE PHASE IS THE ZERO-DELAY ONE (ADR-0015 §7). A delayed
			// rule belongs to `ScheduledPhase` and must not also be sent here, or
			// the customer gets it twice — once now and once when the job runs.
			if ( 0 !== (int) ( $rule['delay_seconds'] ?? 0 ) ) {
				continue;
			}

			/*
			 * ⚠ THE READ BOUNDARY, AND IT IS A SEPARATE MECHANISM FROM THE ONE BELOW
			 * (ADR-0016 §1a). A rule whose `consolidation` is outside the vocabulary is
			 * CORRUPT DATA and is not deliverable: no claim, no send, no record.
			 *
			 * IT IS CHECKED HERE, BEFORE EVALUATION, BECAUSE OF `stop_processing`. This
			 * used to be missing entirely, and `behaviour_is_implemented()` returns true
			 * for everything now that §9 emptied its enumeration — so an invalid rule
			 * ENTERED `RuleMatcher`, and a matching invalid rule carrying the stop flag
			 * HALTED every lower-priority rule behind it. The customer received an email
			 * the merchant never configured AND lost the one they did.
			 *
			 * ⚠ AND IT IS NOT AN EXOTIC CASE. `daily`, `weekly` and `per_order` were
			 * LEGITIMATELY STORABLE from Prompt 5B to Prompt 8, so this is the upgrade
			 * path for any store that used one — no direct SQL involved.
			 */
			if ( ! Consolidation::has_valid_value( $rule ) ) {
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
	 * may hold to be deliverable (ADR-0012 §9, ADR-0013 §8a, ADR-0015 §7,
	 * ADR-0016 §9).
	 *
	 * ⚠ **IT IS EMPTY, AND IT IS DELIBERATELY NOT DELETED.**
	 *
	 * Every column that ever lived here has been implemented: `delay_seconds` left in
	 * Prompt 7 to become a phase discriminator, and `consolidation` — its last entry
	 * — leaves in Prompt 8 because ADR-0016 gives it behaviour. There is nothing left
	 * to hold.
	 *
	 * The MECHANISM stays anyway, and `DeliveryPhaseTest` asserts the emptiness rather
	 * than dropping the assertion, because this is the discipline that caught
	 * `consolidation` in the first place: Prompt 5B gave the column validated storage
	 * and no filter, so a stored `daily` rule was delivered immediately and once per
	 * trigger — `none`'s behaviour under another name, out-of-scope behaviour reachable
	 * through DATA rather than through code. A future column carrying behaviour nobody
	 * has built yet gets added HERE, both phases inherit the filtering with no new
	 * plumbing, and **adding it without a filter is a failing test rather than a defect
	 * somebody has to notice**.
	 *
	 * A rule holding a non-default value for any listed column is left ENTIRELY
	 * untouched by EVERY phase: no claim, no send, no schedule, no record, and —
	 * because the filter runs BEFORE evaluation — no halt of a supported rule through
	 * its `stop_processing` flag.
	 *
	 * @var array<string,string>
	 */
	const UNIMPLEMENTED_BEHAVIOUR_DEFAULTS = array();

	/**
	 * Whether every unimplemented-behaviour column on a rule holds its default.
	 *
	 * SHARED BY BOTH PHASES so they cannot drift, and KEPT ALIVE WITH AN EMPTY LIST
	 * (ADR-0016 §9). It returns `true` for every rule today, which is correct — there
	 * is no unimplemented behaviour left — and it keeps both call sites, so a future
	 * entry in `self::UNIMPLEMENTED_BEHAVIOUR_DEFAULTS` takes effect in both phases
	 * with no new plumbing. Removing the call sites because the list is empty is how
	 * the next column would ship deliverable.
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
