<?php
/**
 * The single ADR-0008 deferred re-evaluation job (ADR-0012 §7).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

use Extonify\WCEP\Domain\TriggerEvent;
use Extonify\WCEP\Install\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Re-asks a question that could not be answered when it was first put.
 *
 * ADR-0008: a targeted status event on an order with ZERO line items is not
 * evidence that nothing matches — it is evidence that the question cannot be
 * answered yet. `wc_create_order( array( 'status' => 'processing' ) )`, the REST
 * API and CSV importers all fire the transition before the items are attached.
 * Rather than guess, the engine defers once.
 *
 * WHAT MAKES THE DEFERRED PATH SAFE:
 *
 *   - it carries the ORIGINAL trigger identity, so its claim and the immediate
 *     path's claim collapse onto the same UNIQUE row (ADR-0004) and only one of
 *     them can send;
 *   - it RE-FETCHES current active rules at execution time rather than replaying
 *     a captured set — the ADR-0011 §7a freshness obligation being discharged;
 *   - it NEVER schedules another deferral. One deferral, never a chain: an order
 *     that still has no items when the job runs will not grow any by asking a
 *     third time.
 *
 * The two contracts that are easiest to state wrongly, in the words used
 * everywhere else (ADR-0008, ADR-0012 §7):
 *
 *     Deferred job : re-fetches current active rules. A rule disabled during the
 *                    delay is absent and sends nothing. No rule_disabled
 *                    tombstone is manufactured.
 *     Scheduler    : the per-identity pending check is best-effort; concurrent
 *                    duplicate jobs remain possible; the atomic claim prevents
 *                    duplicate delivery.
 *
 * NOTHING IS SCHEDULED WHEN THERE IS NOTHING TO DEFER FOR. A zero-item order
 * only reaches this class when at least one in-phase candidate rule exists; a
 * store with no active rules, or only insert-mode or delayed ones, queues no
 * action at all (ADR-0012 §7).
 */
class DeferredEvaluation {

	/**
	 * Action Scheduler hook.
	 */
	const HOOK = 'extonify_wcep_deferred_evaluation';

	/**
	 * Action Scheduler group, so a store owner can see and purge this plugin's
	 * scheduled work in isolation.
	 */
	const GROUP = 'extonify-wcep';

	/**
	 * Scheduling outcomes.
	 *
	 * FOUR VALUES, NOT A BOOLEAN. `false` used to mean "already scheduled",
	 * "Action Scheduler is unavailable" and "scheduling failed" all at once, and
	 * the caller reported every one of them as "already scheduled". The last two
	 * mean a matching order will silently never get its email, which has to be
	 * visible as an error rather than filed as routine.
	 */
	const SCHEDULED             = 'scheduled';
	const ALREADY_PENDING       = 'already_pending';
	const SCHEDULER_UNAVAILABLE = 'scheduler_unavailable';
	const SCHEDULE_FAILED       = 'schedule_failed';

	/**
	 * Register the job handler.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ), 10, 4 );
	}

	/**
	 * The argument set for one deferral.
	 *
	 * Positional and stable: `as_has_scheduled_action()` compares argument
	 * arrays when deciding whether an identical action is already pending, so
	 * the shape of this array is what makes that BEST-EFFORT pre-check
	 * per-identity. It is not a guarantee — see self::schedule() — and it is
	 * emphatically not what `$unique` matches on.
	 *
	 * @param int          $order_id Order id.
	 * @param TriggerEvent $event    Trigger event.
	 * @return array
	 */
	public static function args( int $order_id, TriggerEvent $event ): array {
		return array(
			'order_id'         => $order_id,
			'trigger_type'     => $event->type(),
			'trigger_value'    => $event->value(),
			'trigger_identity' => $event->identity(),
		);
	}

	/**
	 * Schedule the single deferred re-evaluation for a trigger.
	 *
	 * @param int          $order_id Order id.
	 * @param TriggerEvent $event    Trigger event.
	 * @return array{result:string,action_id:int}
	 */
	public static function schedule( int $order_id, TriggerEvent $event ): array {
		// Action Scheduler ships with WooCommerce, so an unavailable scheduler
		// is a belt-and-braces case rather than an expected path — but it is
		// checked, and reported distinctly, because the consequence is a
		// delivery that never happens. `is_initialized()` matters as well as
		// `function_exists()`: the functions load before the data store does,
		// and scheduling against an uninitialised store returns 0.
		if ( ! function_exists( 'as_schedule_single_action' ) || ! self::scheduler_ready() ) {
			return self::outcome( self::SCHEDULER_UNAVAILABLE, 0 );
		}

		if ( self::is_scheduled( $order_id, $event ) ) {
			return self::outcome( self::ALREADY_PENDING, 0 );
		}

		/*
		 * ⚠ `$unique = true` IS DELIBERATELY NOT PASSED, AND THE REASON IS A
		 * WOOCOMMERCE BEHAVIOUR WORTH KNOWING.
		 *
		 * Action Scheduler's uniqueness check matches on HOOK + GROUP ONLY — it
		 * ignores the argument set entirely. Verified against the bundled copy:
		 * `ActionScheduler_DBStore::build_where_clause_for_insert()` filters on
		 * `status IN (pending, running) AND hook = %s AND group_id = %d`, with no
		 * `args` term. Confirmed by experiment: scheduling this hook for order
		 * 111 and then for order 222 with `$unique = true` returns a real action
		 * id for the first and **0 for the second**.
		 *
		 * Passing it would therefore mean that while ANY deferral is pending,
		 * every OTHER order's deferral is silently refused — one busy order
		 * suppressing the rest of the store's email. That is a far worse failure
		 * than the duplicate it would prevent, and it is exactly the "silently
		 * never gets its email" outcome this method exists to make visible.
		 *
		 * WHAT REPLACES IT, STATED HONESTLY. `as_has_scheduled_action()` above
		 * DOES match arguments, so the pre-check is per-identity — but it is
		 * BEST-EFFORT: two requests can both pass it before either row lands, so
		 * concurrent duplicate JOBS remain possible. They cannot produce
		 * duplicate DELIVERY, because the atomic ADR-0004 claim collapses them
		 * onto one row. The claim is the guarantee; the scheduler check only
		 * keeps the queue tidy.
		 *
		 * The RETURN VALUE is still captured: 0 means the action was not
		 * scheduled, and discarding that was the other half of this defect.
		 */
		$action_id = (int) as_schedule_single_action( time(), self::HOOK, self::args( $order_id, $event ), self::GROUP );

		if ( $action_id <= 0 ) {
			// Distinguish the benign race (something else scheduled it between
			// the pre-check and here) from a genuine scheduling failure.
			return self::is_scheduled( $order_id, $event )
				? self::outcome( self::ALREADY_PENDING, 0 )
				: self::outcome( self::SCHEDULE_FAILED, 0 );
		}

		return self::outcome( self::SCHEDULED, $action_id );
	}

	/**
	 * Whether Action Scheduler is initialised enough to accept an action.
	 *
	 * @return bool
	 */
	protected static function scheduler_ready(): bool {
		if ( ! class_exists( '\ActionScheduler' ) ) {
			return false;
		}

		return (bool) \ActionScheduler::is_initialized();
	}

	/**
	 * Build a scheduling outcome.
	 *
	 * @param string $result    One of the outcome constants.
	 * @param int    $action_id Action Scheduler action id, or 0.
	 * @return array{result:string,action_id:int}
	 */
	private static function outcome( string $result, int $action_id ): array {
		return array(
			'result'    => $result,
			'action_id' => $action_id,
		);
	}

	/**
	 * A human-readable phrase for a scheduling outcome, for the delivery log.
	 *
	 * @param array $outcome Outcome from self::schedule().
	 * @return string
	 */
	public static function describe( array $outcome ): string {
		switch ( $outcome['result'] ?? '' ) {
			case self::SCHEDULED:
				return 'one re-evaluation scheduled (action #' . (int) $outcome['action_id'] . ')';
			case self::ALREADY_PENDING:
				return 'a re-evaluation was already pending';
			case self::SCHEDULER_UNAVAILABLE:
				return 'ACTION SCHEDULER UNAVAILABLE — no re-evaluation was scheduled and this delivery will not happen';
			case self::SCHEDULE_FAILED:
				return 'SCHEDULING FAILED — no re-evaluation was scheduled and this delivery will not happen';
			default:
				return 'unrecognised scheduling outcome';
		}
	}

	/**
	 * Whether a deferral for this exact identity is already pending.
	 *
	 * BEST-EFFORT, BY CONSTRUCTION. This is a read followed by a write with no
	 * lock between them, so two concurrent requests can both see "nothing
	 * pending" and both schedule. That is accepted: duplicate JOBS are cheap and
	 * the atomic ADR-0004 claim makes duplicate DELIVERY impossible. Do not
	 * describe this method as a uniqueness guarantee.
	 *
	 * @param int          $order_id Order id.
	 * @param TriggerEvent $event    Trigger event.
	 * @return bool
	 */
	public static function is_scheduled( int $order_id, TriggerEvent $event ): bool {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}

		return (bool) as_has_scheduled_action( self::HOOK, self::args( $order_id, $event ), self::GROUP );
	}

	/**
	 * Run the deferred evaluation.
	 *
	 * Signature matches self::args() positionally: Action Scheduler spreads the
	 * stored argument array over the callback's parameters in order.
	 *
	 * @param mixed $order_id         Order id.
	 * @param mixed $trigger_type     Trigger type.
	 * @param mixed $trigger_value    Trigger value.
	 * @param mixed $trigger_identity Original trigger identity.
	 * @return void
	 */
	public static function run( $order_id = 0, $trigger_type = '', $trigger_value = '', $trigger_identity = '' ): void {
		$order_id = (int) $order_id;

		if ( $order_id <= 0 || ! Migrator::is_operational() ) {
			return;
		}

		$event = self::rebuild_event( (string) $trigger_type, (string) $trigger_value, (string) $trigger_identity );

		if ( null === $event ) {
			return;
		}

		// The identity the immediate path would have used must be the identity
		// this run claims, or the two could both send. Rebuilding it from the
		// type and value and comparing is stronger than trusting the stored
		// string: a mismatch means the scheduled arguments no longer describe a
		// reachable trigger, and sending under a DIFFERENT identity would
		// silently defeat ADR-0004's duplicate prevention.
		if ( $event->identity() !== (string) $trigger_identity ) {
			return;
		}

		$orchestrator = self::orchestrator();
		$order        = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		/*
		 * Rules are RE-FETCHED here, at execution time — never replayed from a
		 * set captured when the deferral was scheduled (ADR-0011 §7a). A rule
		 * DISABLED during the delay is therefore simply ABSENT from the re-fetch:
		 * it sends nothing, consumes no identity, and no `rule_disabled`
		 * tombstone is manufactured for it. Re-enabling it leaves it able to
		 * claim normally on a future trigger.
		 *
		 * `run_deferred()` will NOT schedule another job if the order still has
		 * no items.
		 */
		$orchestrator->run_deferred( $order, $event );
	}

	/**
	 * Rebuild the trigger event from its stored parts.
	 *
	 * The refund case reads its id from the IDENTITY rather than the value,
	 * because ADR-0011 §2 stores an empty `trigger_value` for refund rules — the
	 * refund id lives only in the `refund:{id}` identity, and without it the
	 * rebuilt event could not reproduce the identity it must claim under.
	 *
	 * @param string $type     Trigger type.
	 * @param string $value    Trigger value.
	 * @param string $identity Original trigger identity.
	 * @return TriggerEvent|null
	 */
	private static function rebuild_event( string $type, string $value, string $identity ): ?TriggerEvent {
		if ( TriggerEvent::TYPE_STATUS === $type ) {
			return TriggerEvent::status( $value );
		}

		if ( TriggerEvent::TYPE_TRANSITION === $type ) {
			$sides = explode( '>', $value );
			return 2 === count( $sides ) ? TriggerEvent::transition( $sides[0], $sides[1] ) : null;
		}

		if ( TriggerEvent::TYPE_REFUND === $type && 1 === preg_match( '/^refund:(\d+)$/', $identity, $matches ) ) {
			return TriggerEvent::refund( (int) $matches[1] );
		}

		return null;
	}

	/**
	 * The orchestrator this job drives.
	 *
	 * A seam so the deferred path can be exercised against a test double
	 * without scheduling a real action.
	 *
	 * @return Orchestrator
	 */
	protected static function orchestrator(): Orchestrator {
		return new Orchestrator();
	}
}
