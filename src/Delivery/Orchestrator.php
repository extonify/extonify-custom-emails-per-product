<?php
/**
 * The delivery lifecycle: evaluate, claim, send, record (ADR-0012 §1).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

use Extonify\WCEP\Domain\EvaluationResult;
use Extonify\WCEP\Domain\MatchDecision;
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
	 * Constructor.
	 *
	 * @param RuleRepository|null $rules   Rule storage.
	 * @param RuleMatcher|null    $matcher Matching engine.
	 * @param DeliveryLogger|null $logger  Delivery logger.
	 */
	public function __construct( ?RuleRepository $rules = null, ?RuleMatcher $matcher = null, ?DeliveryLogger $logger = null ) {
		$this->rules   = null !== $rules ? $rules : new RuleRepository();
		$this->matcher = null !== $matcher ? $matcher : new RuleMatcher( $this->rules );
		$this->logger  = null !== $logger ? $logger : new DeliveryLogger();
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
	 * Resolve recipients, send, and record the outcome.
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

		$recipients = RecipientResolver::resolve(
			$this->recipients_value( $rule ),
			array(
				RecipientResolver::TOKEN_CUSTOMER => (string) $order->get_billing_email(),
				RecipientResolver::TOKEN_ADMIN    => (string) get_option( 'admin_email', '' ),
			)
		);

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
			return;
		}

		$subject = HeaderGuard::strip( (string) ( $rule['subject'] ?? '' ) );
		$notes   = $recipients->reason();

		if ( HeaderGuard::has_break( (string) ( $rule['subject'] ?? '' ) ) ) {
			$notes = '' === $notes ? 'stripped a line break from the subject' : $notes . '; stripped a line break from the subject';
		}

		try {
			$result = $email->trigger(
				array(
					'recipient'     => implode( ', ', $recipients->addresses( 'to' ) ),
					'cc'            => implode( ', ', $recipients->addresses( 'cc' ) ),
					'bcc'           => implode( ', ', $recipients->addresses( 'bcc' ) ),
					'subject'       => $subject,
					'heading'       => (string) ( $rule['heading'] ?? '' ),
					'content'       => (string) ( $rule['content'] ?? '' ),
					'matched_items' => $decision->matched_items(),
					'delivery_id'   => $delivery_id,
					'object'        => $order,
				)
			);
		} catch ( \Throwable $error ) {
			/*
			 * AN SMTP PLUGIN MUST NOT BREAK THE ORDER UPDATE. This runs inside
			 * `woocommerce_order_status_changed`, so an uncaught throw would
			 * propagate out through the merchant's status change — and would
			 * leave the tombstone `claimed` forever with no detail row, while
			 * the consumed identity blocked every retry.
			 *
			 * `\Throwable`, not `\Exception`: a TypeError from a badly-typed
			 * third-party filter is exactly as fatal to the order update.
			 */
			$outcome->record(
				RunOutcome::FAILED,
				$decision->rule_id(),
				$delivery_id,
				$this->logger->record_send_failure( $delivery_id, $recipients, $subject, $error, $notes, $snapshot )
			);
			return;
		}

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
			return;
		}

		$sent = Custom_Email::SENT === $result;

		$outcome->record(
			$sent ? RunOutcome::SENT : RunOutcome::FAILED,
			$decision->rule_id(),
			$delivery_id,
			$this->logger->record_send( $delivery_id, $recipients, $subject, $sent, $notes, $snapshot )
		);
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
	 * @param array[] $rules Candidate rule rows.
	 * @return array[] Rows this phase may deliver.
	 */
	public static function deliverable_in_this_phase( array $rules ): array {
		$deliverable = array();

		foreach ( $rules as $rule ) {
			if ( DeliveryLogger::MODE !== (string) ( $rule['delivery_mode'] ?? '' ) ) {
				continue;
			}

			// ADR-0007 scheduling is Prompt 6. Sending a seven-day delay
			// immediately is the same class of error as sending an insert rule
			// separately.
			if ( (int) ( $rule['delay_seconds'] ?? 0 ) > 0 ) {
				continue;
			}

			$deliverable[] = $rule;
		}

		return $deliverable;
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
