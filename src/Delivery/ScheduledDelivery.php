<?php
/**
 * The delayed-delivery Action Scheduler job (ADR-0007, ADR-0015 §4, §6).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

use Extonify\WCEP\Domain\DeliverySnapshot;
use Extonify\WCEP\Domain\WriteResult;
use Extonify\WCEP\Install\Maintenance;
use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Repository\DeliveryRepository;
use Extonify\WCEP\Repository\RuleRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules one delayed delivery, and later executes it.
 *
 * THE DIVISION THAT MAKES THIS SAFE (ADR-0015 §3):
 *
 *     content      -> the SNAPSHOT taken when the delivery was scheduled
 *     recipients   -> the SNAPSHOT's definitions, RESOLVED fresh
 *     placeholders -> the LIVE ORDER (ADR-0014 §8)
 *     permission   -> the LIVE RULE, re-fetched (ADR-0011 §7a)
 *
 * **The snapshot supplies CONTENT. It never supplies PERMISSION.** A rule that
 * has been disabled since scheduling is disabled, whatever this row remembers;
 * that is the whole reason §4's re-validation re-fetches rather than trusting
 * what it is carrying.
 *
 * ⚠ THE IDENTITY WAS CONSUMED AT SCHEDULING TIME, NOT HERE (ADR-0015 §1). This
 * job runs against a tombstone that already exists and is already `scheduled`;
 * it never claims. So a cancellation here is terminal for that identity — see
 * ADR-0015 §1a, which is the consequence most likely to be misread as a bug.
 *
 * ⚠ THE RUN BEGINS BY TAKING A LEASE (ADR-0015 §8.2). `scheduled -> executing`
 * is a guarded transition, and a run that does not win it returns WITHOUT
 * SENDING. That is what makes a re-claimed action harmless: a worker that died
 * after `wp_mail()` returned but before its status write leaves the row
 * `executing`, and the next worker stops here instead of re-validating
 * successfully and sending the customer a second email. It needs only a PHP
 * timeout to happen.
 *
 * ⚠ EVERY EXIT SATISFIES ONE OF ADR-0015 §8.4's THREE RULES — reach a terminal
 * state, transition to one with a distinct reason, or leave the tombstone
 * `scheduled` having PROVED a replacement job exists. There is no bare `return;`
 * that consumes the job and leaves the delivery owed to nobody; there used to be
 * five, and the `! Migrator::is_operational()` one fired during any plugin update.
 *
 * ⚠ AND NOTHING THROWN IN HERE MAY ESCAPE. Action Scheduler catches exceptions
 * and marks the action failed, but this plugin's own containment obligations
 * (ADR-0012 §3, ADR-0014 §10) do not stop applying because a different runner
 * invoked the code: a stranded tombstone with no reason recorded is the same
 * defect whichever caller produced it.
 */
class ScheduledDelivery {

	/**
	 * Action Scheduler hook.
	 */
	const HOOK = 'extonify_wcep_scheduled_delivery';

	/**
	 * Action Scheduler group, shared with the ADR-0008 deferral so a store owner
	 * sees and purges this plugin's scheduled work in one place.
	 */
	const GROUP = DeferredEvaluation::GROUP;

	/**
	 * Scheduling outcomes — the same four `DeferredEvaluation` established, and
	 * for the same reason: `false` meaning three different things is how a
	 * delivery that never scheduled gets filed as one that was correctly
	 * suppressed (ADR-0015 §6).
	 */
	const SCHEDULED             = DeferredEvaluation::SCHEDULED;
	const ALREADY_PENDING       = DeferredEvaluation::ALREADY_PENDING;
	const SCHEDULER_UNAVAILABLE = DeferredEvaluation::SCHEDULER_UNAVAILABLE;
	const SCHEDULE_FAILED       = DeferredEvaluation::SCHEDULE_FAILED;

	/**
	 * Cancellation reasons (ADR-0015 §4).
	 *
	 * ⚠ SIX CODES, NOT ONE GENERIC "cancelled". The six causes have six different
	 * remedies, and the delivery log exists to answer exactly one question — *why
	 * didn't this send?* A single reason makes it unable to.
	 */
	const REASON_RULE_DELETED    = 'rule_deleted';
	const REASON_RULE_DISABLED   = 'rule_disabled';
	const REASON_RULE_LEFT_PHASE = 'rule_left_phase';
	const REASON_ORDER_DELETED   = 'order_deleted';
	const REASON_ORDER_STATE     = 'order_state';
	const REASON_ITEMS_REFUNDED  = 'items_refunded';
	const REASON_NO_SNAPSHOT     = 'snapshot_unreadable';

	/**
	 * The live rule's `consolidation` is outside the vocabulary (ADR-0016 §1a,
	 * ADR-0015 §4 check 3a).
	 *
	 * ⚠ ITS OWN REASON, NOT `rule_left_phase`, BECAUSE THE REMEDIES DIFFER. Check 3
	 * compares the live value against the snapshot and catches a merchant CHANGING the
	 * rule; this catches CORRUPT DATA — a row from the vocabulary that was open before
	 * Prompt 8, most likely `daily`. Telling that merchant they "changed the rule"
	 * would send them looking for an edit they never made, when what they need to do is
	 * choose `none` or `per_product`.
	 */
	const REASON_CONSOLIDATION_INVALID = 'consolidation_invalid';

	/**
	 * Reasons a run ended without reaching §4 at all (ADR-0015 §8.4).
	 *
	 * ⚠ EACH ONE REPLACES A BARE `return;` THAT LEFT THE JOB CONSUMED AND THE
	 * TOMBSTONE `scheduled`. The delivery log's whole job is to answer *why didn't
	 * this send?*, and a silent exit is the one answer it cannot give. Only
	 * `schema_unavailable` is transient — it fires during a plugin update — and it
	 * is the sole branch that re-schedules instead of finalising.
	 */
	const REASON_SCHEMA_UNAVAILABLE = 'schema_unavailable';
	const REASON_ARGS_MISMATCH      = 'action_arguments_mismatch';
	const REASON_EMAIL_UNAVAILABLE  = 'email_class_unavailable';
	const REASON_INOPERATIVE        = 'execution_inoperative';

	/**
	 * THE WRITE ITSELF FAILED (ADR-0015 §8.1a, added Prompt 7B).
	 *
	 * ⚠ NOT A LOST RACE, AND THE DIFFERENCE IS THE WHOLE POINT OF BOTH CODES.
	 * `arm_failed` means the `claimed -> scheduled` UPDATE failed, so the identity
	 * is consumed with no job behind it; `lease_write_failed` means the
	 * `scheduled -> executing` UPDATE failed while the row was still `scheduled`, so
	 * NOBODY owns the delivery — the case a boolean return made indistinguishable
	 * from "another worker has it", which is the one case where exiting is correct.
	 */
	const REASON_ARM_FAILED       = 'arm_failed';
	const REASON_LEASE_UNWRITABLE = 'lease_write_failed';

	/**
	 * Reasons produced OUTSIDE a run (ADR-0015 §8.3, §8.6, §8.8).
	 */
	const REASON_LEASE_EXPIRED      = 'lease_expired';
	const REASON_ORPHANED           = 'orphaned_no_job';
	const REASON_PLUGIN_DEACTIVATED = 'plugin_deactivated';
	const REASON_SHUTDOWN_INTERRUPT = 'lease_interrupted_by_shutdown';

	/**
	 * How long an execution lease may be held before the sweep gives up on it
	 * (ADR-0015 §8.3). One hour, in seconds.
	 *
	 * ⚠ THE NUMBER IS ARGUED, NOT PICKED. Against Action Scheduler's own timings in
	 * the bundled WooCommerce 10.9.4 copy:
	 *
	 *   - `ActionScheduler_Abstract_QueueRunner::get_time_limit()`      —  30 s per batch;
	 *   - `ActionScheduler_QueueCleaner::mark_failures()`               — 300 s, after
	 *     which AS ITSELF declares a running action dead (10x the batch limit);
	 *   - `ActionScheduler_QueueCleaner::reset_timeouts()`              — 300 s;
	 *   - PHPMailer's default SMTP `Timeout`                            — 300 s, the
	 *     longest single blocking call a `wp_mail()` can make.
	 *
	 * An hour is 12x the largest of those, and Action Scheduler has long since given
	 * up on the action by then. **The errors are not symmetric, which is why it errs
	 * long**: sweeping too EARLY records a live send `unresolved`, which is untrue
	 * and is exactly what a future resend feature would read as "retry this" —
	 * reaching a customer twice. Sweeping too LATE only delays the log admitting it
	 * does not know.
	 */
	const LEASE_WINDOW_SECONDS = 3600;

	/**
	 * How many times one delivery may be re-scheduled past a transient condition
	 * (ADR-0015 §8.4).
	 *
	 * The cap is what stops "leave it `scheduled` and prove a job exists" becoming
	 * a job that re-queues itself for ever against a schema that never returns.
	 */
	const MAX_RESCHEDULES = 3;

	/**
	 * How far ahead a transient re-schedule is queued, in seconds.
	 *
	 * Five minutes: long enough that a plugin update has finished, short enough
	 * that a delayed email a merchant is waiting for is not delayed noticeably
	 * further.
	 */
	const RESCHEDULE_DELAY_SECONDS = 300;

	/**
	 * How many rows one maintenance sweep reads per page.
	 *
	 * Bounded so a store with a large backlog does a predictable amount of work
	 * per day rather than one unbounded pass that times out and recovers nothing.
	 */
	const SWEEP_BATCH = 100;

	/**
	 * How many pages one sweep may read PER HALF (ADR-0015 §8.3a).
	 *
	 * ⚠ THE PAGE COUNT IS WHAT MAKES THE SWEEP REACH ANYTHING PAST ROW 100, AND ITS
	 * ABSENCE WAS A STARVATION DEFECT NEEDING NO FAILURE AT ALL. The orphan half
	 * selected `LIMIT 100` with no cursor and simply `continue`d over a healthy row —
	 * advancing nothing. A store with more than a hundred aged pending deliveries
	 * therefore re-examined the same first hundred every day for ever, and orphan
	 * #101 was never reached. Moderate volume plus a long delay is all that took.
	 *
	 * Ten pages of a hundred bounds one run at 1,000 candidate rows per half. The
	 * cost of a run is stated in full at self::sweep().
	 */
	const SWEEP_MAX_PAGES = 10;

	/**
	 * THE ORPHAN HALF'S PER-RUN THROUGHPUT, `T` (ADR-0015 §8.3b).
	 *
	 * The fairness bound is stated against this number and nothing else: a delivery
	 * eligible when a cycle opens is examined within `ceil(E / T)` runs, where `E` is
	 * that cycle's candidate count. Naming it makes the bound checkable in a test
	 * rather than recomputed by hand in three places.
	 */
	const SWEEP_THROUGHPUT = self::SWEEP_BATCH * self::SWEEP_MAX_PAGES;

	/**
	 * The orphan half's open sweep CYCLE (ADR-0015 §8.3b).
	 *
	 * `array{cursor:int,high_water:int,cutoff:string}` — where the traversal has
	 * reached, and the two frozen predicates that define what it is traversing.
	 *
	 * ⚠ A CURSOR ALONE WAS NOT ENOUGH, AND PROMPT 7B's OWN FIX IS WHAT MADE THIS
	 * REACHABLE. A cursor guarantees progress THROUGH a candidate set; it guarantees
	 * nothing about reaching the BACK of one, because the reset that would revisit a
	 * low id only happened when a page came back short. Under sustained inflow no page
	 * is ever short, so the cursor climbed for ever:
	 *
	 *     cursor 5000, orphan at id 100, >1,000 new eligible rows per day
	 *     day 1: 5001–6000   day 2: 6001–7000   day 3: 7001–8000  …
	 *
	 * — and id 100 was never examined again. The high-water mark is what closes it:
	 * the cycle's candidate set is frozen when the cycle opens, so rows arriving
	 * afterwards join the NEXT cycle instead of extending the current one, and a cycle
	 * therefore always ends.
	 *
	 * ⚠ THE FROZEN CUTOFF IS PART OF THE MARK, NOT DECORATION. The id bound alone
	 * excludes rows CREATED during the cycle; it does not exclude a row that already
	 * existed below the mark and merely became old enough mid-cycle. Freezing the age
	 * predicate too is what makes the set monotonically SHRINKING, which is what turns
	 * `ceil(E / T)` from an estimate into a proof.
	 *
	 * The record is still only ever a hint: nothing is lost if it is stale, missing or
	 * malformed, because every candidate it skips is still `scheduled` and still older
	 * than the next cycle's cutoff. That is why it lives in an option and not in the
	 * schema, and why a value written by an older version is simply discarded.
	 */
	const OPTION_SWEEP_CYCLE = 'extonify_wcep_sweep_cycle';

	/**
	 * The human sentence recorded for each cancellation reason.
	 *
	 * Written as full sentences rather than codes, because a merchant reads this
	 * column and "items_refunded" is not an explanation.
	 */
	const REASON_TEXT = array(
		self::REASON_RULE_DELETED          => 'the rule was deleted during the delay, so there is nothing left to send',
		self::REASON_RULE_DISABLED         => 'the rule was disabled during the delay',
		self::REASON_RULE_LEFT_PHASE       => 'the rule no longer belongs to the delayed separate-mode phase (its mode, delay or consolidation changed during the delay)',
		self::REASON_ORDER_DELETED         => 'the order no longer exists, or was moved to the trash, during the delay',
		self::REASON_ORDER_STATE           => 'the order was cancelled, failed or fully refunded during the delay',
		self::REASON_ITEMS_REFUNDED        => 'every matched line item was removed or fully refunded during the delay',
		self::REASON_NO_SNAPSHOT           => 'the stored snapshot could not be read, so the message this delivery would send is unknown',
		self::REASON_CONSOLIDATION_INVALID => 'the rule\'s consolidation setting is not one this plugin recognises, so it was not delivered; set it to "none" or "per_product"',

		self::REASON_SCHEMA_UNAVAILABLE    => 'this plugin\'s database tables were unavailable when the delayed delivery came due, and it could not be re-queued',
		self::REASON_ARGS_MISMATCH         => 'the queued job named a different order from the delivery record it points at, so neither was trusted',
		self::REASON_EMAIL_UNAVAILABLE     => 'the custom email class was not registered with WooCommerce when the delayed delivery came due',
		self::REASON_INOPERATIVE           => 'delivery was not operational when the delayed delivery came due',

		self::REASON_ARM_FAILED            => 'this delayed delivery could not be armed for sending, so nothing was queued',
		self::REASON_LEASE_UNWRITABLE      => 'the execution lease for this delayed delivery could not be written and it could not be re-queued, so no worker was able to take it',

		self::REASON_LEASE_EXPIRED         => 'the worker running this delayed delivery never reported back, so whether the message was sent is not known',
		self::REASON_ORPHANED              => 'the queued job for this delayed delivery no longer exists and it could not be re-queued',
		self::REASON_PLUGIN_DEACTIVATED    => 'the plugin was deactivated while this delayed delivery was still queued',
		self::REASON_SHUTDOWN_INTERRUPT    => 'the plugin was shut down while a worker was running this delayed delivery, so whether the message was sent is not known',
	);

	/*
	 * ⚠ THERE IS DELIBERATELY NO `plugin_uninstalled` REASON. `uninstall.php` is
	 * dependency-free by design — no autoloader, no WooCommerce — so it cannot
	 * reference this class, and a constant nothing can produce is exactly the kind
	 * of drift gate 9 exists to catch. It finalises pending tombstones in raw SQL
	 * with no per-delivery detail row; the site is being torn down, and the log it
	 * would be written to is about to be dropped or orphaned either way.
	 */

	/**
	 * Order statuses that cancel a pending delayed delivery (ADR-0015 §4).
	 *
	 * `refunded` is WooCommerce's FULL-refund status; a partial refund leaves the
	 * order `processing` or `completed` and is handled per item by check 6, so a
	 * customer who returned one of three products still gets the email about the
	 * two they kept.
	 */
	const CANCELLING_ORDER_STATUSES = array( 'cancelled', 'failed', 'refunded' );

	/**
	 * Register the job handler.
	 *
	 * @return void
	 */
	public static function register(): void {
		// THREE ARGUMENTS, because self::args() carries the re-schedule attempt
		// (ADR-0015 §8.4). Action Scheduler spreads the stored argument array over
		// the callback positionally, and a callback registered for two would drop
		// the third silently — resetting the cap on every pass.
		add_action( self::HOOK, array( self::class, 'run' ), 10, 3 );
	}

	/**
	 * The argument set for one scheduled delivery.
	 *
	 * THE DELIVERY ID IS THE WHOLE KEY, and that is what makes this different
	 * from the ADR-0008 deferral. The tombstone already exists and already holds
	 * the snapshot, so the job needs nothing else to find its work — and an
	 * argument set that cannot drift from the row it describes cannot schedule a
	 * job for a delivery that is not the one it means.
	 *
	 * `order_id` rides along for `as_has_scheduled_action()` legibility and for a
	 * store owner reading the Action Scheduler admin screen; it is VERIFIED
	 * against the tombstone at execution rather than trusted.
	 *
	 * `attempt` counts TRANSIENT RE-SCHEDULES (ADR-0015 §8.4), and it rides in the
	 * arguments rather than on the tombstone for one reason: the only branch that
	 * needs the count is the one where this plugin's tables are unavailable, so a
	 * counter stored in them would be unreadable exactly when it is needed.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @param int $order_id    Order id.
	 * @param int $attempt     Transient re-schedule count; 0 for the first queueing.
	 * @return array
	 */
	public static function args( int $delivery_id, int $order_id, int $attempt = 0 ): array {
		return array(
			'delivery_id' => $delivery_id,
			'order_id'    => $order_id,
			'attempt'     => $attempt,
		);
	}

	/**
	 * Queue one delayed delivery.
	 *
	 * ⚠ THE PRE-CHECK LOOKS AT THIS ATTEMPT ALONE, AND THAT IS EXACT RATHER THAN
	 * APPROXIMATE (ADR-0015 §8.4). A re-scheduled job only ever exists after a run,
	 * and a run only happens after the tombstone left `claimed` — so a second
	 * trigger reaching here was already suppressed by the ADR-0004 claim, and a
	 * fresh delivery cannot have a job at any attempt but 0.
	 *
	 * @param int $delivery_id   Tombstone id, already armed `scheduled`.
	 * @param int $order_id      Order id.
	 * @param int $scheduled_for UTC timestamp to run at.
	 * @param int $attempt       Transient re-schedule count.
	 * @return array{result:string,action_id:int}
	 */
	public static function schedule( int $delivery_id, int $order_id, int $scheduled_for, int $attempt = 0 ): array {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! self::scheduler_ready() ) {
			return self::outcome( self::SCHEDULER_UNAVAILABLE, 0 );
		}

		if ( self::is_scheduled( $delivery_id, $order_id, $attempt ) ) {
			return self::outcome( self::ALREADY_PENDING, 0 );
		}

		/*
		 * ⚠ `$unique = true` IS DELIBERATELY NOT PASSED (ADR-0015 §6). Action
		 * Scheduler's uniqueness check matches HOOK + GROUP only —
		 * `ActionScheduler_DBStore::build_where_clause_for_insert()` has no `args`
		 * term, verified against the bundled copy and confirmed by experiment in
		 * Prompt 4A. Passing it would mean that while ANY delayed delivery is
		 * pending, EVERY OTHER ORDER'S IS SILENTLY REFUSED — one busy order
		 * suppressing the rest of the store's delayed mail.
		 *
		 * WHAT REPLACES IT, STATED HONESTLY: the args-exact
		 * `as_has_scheduled_action()` above keeps the queue tidy but is
		 * best-effort, because it is a read followed by a write with no lock
		 * between. **The atomic ADR-0004 claim — already taken, before this job
		 * was queued — is what prevents a duplicate SEND**, and it is the only
		 * thing that does. Duplicate jobs are cheap; the second one finds a
		 * tombstone that is no longer `scheduled` and stops.
		 */
		$action_id = (int) as_schedule_single_action( $scheduled_for, self::HOOK, self::args( $delivery_id, $order_id, $attempt ), self::GROUP );

		if ( $action_id <= 0 ) {
			// The benign race (something else queued it between the pre-check and
			// here) is a DIFFERENT fact from a genuine scheduling failure, and
			// only one of them means a customer never gets their email.
			return self::is_scheduled( $delivery_id, $order_id, $attempt )
				? self::outcome( self::ALREADY_PENDING, 0 )
				: self::outcome( self::SCHEDULE_FAILED, 0 );
		}

		/*
		 * ⚠ QUEUE CREATION HEALS THE QUEUE'S OWN RECOVERY (ADR-0015 §8.3c). A job now
		 * exists that only two mechanisms can ever reach — this job itself, and the
		 * daily maintenance sweep — and until Prompt 7C the second could be absent on a
		 * store that had upgraded rather than activated and had served no admin request
		 * since. Verifying it HERE, at the moment the protected work is created, is what
		 * makes "the sweep exists" a property of scheduling rather than of a hook that
		 * may never fire.
		 *
		 * Cheap by construction: cached per request and per VERIFY_INTERVAL_SECONDS, so
		 * a bulk status change queueing fifty deliveries pays for one option read.
		 */
		Maintenance::ensure_armed();

		return self::outcome( self::SCHEDULED, $action_id );
	}

	/**
	 * Cancel the queued action for one delivery (ADR-0015 §5).
	 *
	 * ⚠ IT SWEEPS THE WHOLE ATTEMPT RANGE, NOT JUST ATTEMPT 0 (ADR-0015 §8.4). A
	 * delivery that was re-scheduled past a transient condition owns a job whose
	 * arguments differ, and args-exact matching means an unschedule aimed at
	 * attempt 0 would leave it pending — a job outliving the tombstone it points
	 * at, which is precisely what gate 19 forbids. The range is bounded by
	 * self::MAX_RESCHEDULES, so this is a small fixed number of lookups on a path
	 * that only ever runs from an admin or lifecycle action.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @param int $order_id    Order id.
	 * @return int Actions unscheduled.
	 */
	public static function unschedule( int $delivery_id, int $order_id ): int {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return 0;
		}

		$removed = 0;

		foreach ( self::attempt_range() as $attempt ) {
			if ( ! self::is_scheduled( $delivery_id, $order_id, $attempt ) ) {
				continue;
			}

			/*
			 * ARGS-EXACT, so this cancels THIS delivery's action and no other. ⚠ Not
			 * `array()`, which matches only actions whose args are exactly empty and
			 * would silently cancel nothing (ADR-0015 §6, Prompt 4A item 6); and not
			 * `null`, which here would mean "every delayed delivery in the store".
			 */
			as_unschedule_all_actions( self::HOOK, self::args( $delivery_id, $order_id, $attempt ), self::GROUP );

			if ( ! self::is_scheduled( $delivery_id, $order_id, $attempt ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Whether this exact delivery already has a pending action at one attempt.
	 *
	 * BEST-EFFORT, BY CONSTRUCTION — see self::schedule(). Do not describe it as
	 * a uniqueness guarantee.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @param int $order_id    Order id.
	 * @param int $attempt     Transient re-schedule count.
	 * @return bool
	 */
	public static function is_scheduled( int $delivery_id, int $order_id, int $attempt = 0 ): bool {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}

		return (bool) as_has_scheduled_action( self::HOOK, self::args( $delivery_id, $order_id, $attempt ), self::GROUP );
	}

	/**
	 * Whether this delivery has a live job at ANY attempt.
	 *
	 * THE QUESTION GATE 19 ACTUALLY ASKS — "does every `scheduled` tombstone have
	 * a job" is about the delivery, not about one argument set.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @param int $order_id    Order id.
	 * @return bool
	 */
	public static function has_any_job( int $delivery_id, int $order_id ): bool {
		foreach ( self::attempt_range() as $attempt ) {
			if ( self::is_scheduled( $delivery_id, $order_id, $attempt ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every attempt number a live job may carry.
	 *
	 * @return int[]
	 */
	public static function attempt_range(): array {
		return range( 0, self::MAX_RESCHEDULES );
	}

	/**
	 * Execute one delayed delivery.
	 *
	 * Signature matches self::args() positionally: Action Scheduler spreads the
	 * stored argument array over the callback's parameters in order.
	 *
	 * @param mixed $delivery_id Tombstone id.
	 * @param mixed $order_id    Order id.
	 * @param mixed $attempt     Transient re-schedule count.
	 * @return void
	 */
	public static function run( $delivery_id = 0, $order_id = 0, $attempt = 0 ): void {
		$delivery_id = (int) $delivery_id;

		if ( $delivery_id <= 0 ) {
			/*
			 * BRANCH 1 — vacuously terminal (ADR-0015 §8.4 rule 1). There is no
			 * tombstone to strand: an action whose arguments name delivery 0 points
			 * at no row, so nothing was claimed and nothing is owed. It is logged
			 * rather than dropped, because it can only arrive from a hand-edited or
			 * corrupted action and somebody should be able to see that.
			 */
			self::logger()->record_inert(
				(int) $order_id,
				'scheduled delivery',
				'a queued delayed-delivery action carried no usable delivery id, so there was nothing to run'
			);
			return;
		}

		/*
		 * ⚠ THE WHOLE RUN IS CONTAINED (ADR-0012 §3, ADR-0014 §10). Action
		 * Scheduler would catch a throw and mark the action failed, but that is
		 * ITS bookkeeping, not ours: the tombstone would stay `scheduled` forever,
		 * with a consumed identity, no reason recorded, and no queued action left
		 * to retry it. A delivery must reach a terminal state whatever happens, so
		 * the boundary is here and not borrowed.
		 *
		 * `$state` carries ONE fact out of the try block: whether the lease was
		 * taken before the throw. The catch needs it because `executing -> failed`
		 * and `scheduled -> cancelled` are different edges and only one of them can
		 * land (ADR-0015 §8.1).
		 */
		$state = array( 'leased' => false );

		try {
			self::execute( $delivery_id, (int) $order_id, (int) $attempt, $state );
		} catch ( \Throwable $error ) {
			self::record_containment_failure( $delivery_id, $error, (bool) $state['leased'] );
		}
	}

	/**
	 * The lease-then-re-validate-then-send body, inside self::run()'s boundary.
	 *
	 * EVERY EXIT FROM HERE SATISFIES ONE OF ADR-0015 §8.4's THREE RULES — reach a
	 * terminal state, transition to one with a distinct reason, or leave the row
	 * `scheduled` having PROVED a replacement job exists. The branch comments name
	 * which.
	 *
	 * @param int   $delivery_id Tombstone id.
	 * @param int   $order_id    Order id from the action arguments.
	 * @param int   $attempt     Transient re-schedule count from the arguments.
	 * @param array $state       Run state, by reference; carries the lease flag out.
	 * @return void
	 */
	private static function execute( int $delivery_id, int $order_id, int $attempt, array &$state ): void {
		/*
		 * BRANCH 2 — RULE 3, AND THE ONLY BRANCH THAT USES IT. This is true while a
		 * plugin update is in flight, which is an ordinary event during a window
		 * measured in hours. It cannot use rule 2 even in principle: recording a
		 * terminal state means writing to a table that by hypothesis is not there.
		 */
		if ( ! Migrator::is_operational() ) {
			self::reschedule_transient( $delivery_id, $order_id, $attempt );
			return;
		}

		$deliveries = self::deliveries();

		/*
		 * THE LEASE (ADR-0015 §8.2). This is the first thing the run does, and if it
		 * fails the run sends NOTHING. Taking it is not itself an exit — the two
		 * exits below (3a, 3b) are, and both satisfy RULE 1: the row is already
		 * terminal, already owned, or already gone.
		 *
		 * ⚠ IT IS WHAT MAKES A RE-CLAIMED ACTION HARMLESS. A worker that died after
		 * `wp_mail()` returned but before its status write leaves this row
		 * `executing`; the next worker's lease attempt fails here and it exits,
		 * rather than re-validating successfully and sending the customer a second
		 * email. That needs only a PHP timeout to happen.
		 */
		$lease = $deliveries->transition( $delivery_id, DeliveryRepository::SCHEDULED, DeliveryRepository::EXECUTING );

		if ( ! $lease->won() ) {
			self::handle_ungranted_lease( $delivery_id, $order_id, $attempt, $lease );
			return;
		}

		$state['leased'] = true;

		$tombstone = $deliveries->find_by_id( $delivery_id );

		if ( null === $tombstone ) {
			// BRANCH 4 — rule 1, vacuously terminal. The row was deleted between
			// the lease and this read, which means the order was permanently
			// deleted concurrently. There is nothing left to record onto.
			return;
		}

		if ( $order_id > 0 && (int) $tombstone['order_id'] !== $order_id ) {
			/*
			 * BRANCH 5 — RULE 2. ⚠ THE ARGUMENTS AND THE ROW DISAGREE, SO NEITHER IS
			 * TRUSTED. The tombstone is authoritative for the order — it is what the
			 * identity was hashed from — and a queued argument set naming a DIFFERENT
			 * order means the queue and the table have diverged: a restored database,
			 * a hand-edited action, an id reused after a purge. Sending would mail one
			 * customer's order under another's delivery identity, so this refuses and
			 * says so rather than picking a side.
			 *
			 * It used to `return;` after an inert log, leaving the delivery
			 * `scheduled` with its job consumed and no way for anything to reach it
			 * again.
			 */
			self::logger()->record_inert(
				(int) $tombstone['order_id'],
				'delivery #' . $delivery_id,
				'refused a scheduled delivery whose queued arguments name order #' . $order_id
					. ' while its tombstone names order #' . (int) $tombstone['order_id']
			);
			self::cancel( $delivery_id, self::REASON_ARGS_MISMATCH, DeliveryRepository::EXECUTING );
			return;
		}

		$snapshot = DeliverySnapshot::read( (string) ( $tombstone['snapshot'] ?? '' ) );

		if ( null === $snapshot ) {
			// BRANCH 6 — rule 2. FAIL CLOSED: a delivery whose content cannot be
			// read must not send something assembled from defaults.
			self::cancel( $delivery_id, self::REASON_NO_SNAPSHOT, DeliveryRepository::EXECUTING );
			return;
		}

		$rule_id = (int) $tombstone['rule_id'];

		// --- §4 checks 1-3: the LIVE rule supplies permission. ----------------
		$rule = self::rules()->find( $rule_id );

		if ( null === $rule ) {
			// BRANCH 7 — rule 2.
			self::cancel( $delivery_id, self::REASON_RULE_DELETED, DeliveryRepository::EXECUTING );
			return;
		}

		if ( 'active' !== (string) ( $rule['status'] ?? '' ) ) {
			// BRANCH 8 — rule 2.
			self::cancel( $delivery_id, self::REASON_RULE_DISABLED, DeliveryRepository::EXECUTING );
			return;
		}

		if ( ! Consolidation::has_valid_value( $rule ) ) {
			/*
			 * BRANCH 8a — rule 2. ⚠ THE READ BOUNDARY, AT EXECUTION (ADR-0015 §4 check
			 * 3a, ADR-0016 §1a). A rule can become invalid DURING the delay — a database
			 * restore, a migration, or simply a `daily` row that was legitimately
			 * storable when this job was queued — and it must no more deliver here than
			 * at trigger time.
			 *
			 * CHECKED BEFORE check 3 so the DISTINCT reason wins. Check 3 would also
			 * refuse it, as a snapshot/live mismatch, but `rule_left_phase` would tell
			 * the merchant they had edited something when what they have is corrupt data.
			 */
			self::cancel( $delivery_id, self::REASON_CONSOLIDATION_INVALID, DeliveryRepository::EXECUTING );
			return;
		}

		if ( ! self::still_in_phase( $rule, $snapshot ) ) {
			// BRANCH 9 — rule 2.
			self::cancel( $delivery_id, self::REASON_RULE_LEFT_PHASE, DeliveryRepository::EXECUTING );
			return;
		}

		// --- §4 checks 4-6: the LIVE order. -----------------------------------
		$order = wc_get_order( (int) $tombstone['order_id'] );

		if ( ! $order instanceof \WC_Order ) {
			// BRANCH 10 — rule 2.
			self::cancel( $delivery_id, self::REASON_ORDER_DELETED, DeliveryRepository::EXECUTING );
			return;
		}

		/*
		 * ⚠ `wc_get_order()` RETURNS A TRASHED ORDER, so "the order still exists"
		 * is NOT the same question as "the merchant still has this order". Found by
		 * this prompt's own check-4 test, which trashed an order and watched the
		 * delayed email go out anyway: WooCommerce's trash is a soft delete and the
		 * object comes back intact, status `trash`, with every item and address
		 * still on it.
		 *
		 * A merchant who trashes an order has said as plainly as the UI allows that
		 * it should stop doing things. Sending is recorded as `order_deleted`
		 * rather than `order_state`, because deleting is what they actually did —
		 * and restoring the order from the trash does not un-send an email.
		 */
		if ( 'trash' === (string) $order->get_status() ) {
			// BRANCH 11 — rule 2.
			self::cancel( $delivery_id, self::REASON_ORDER_DELETED, DeliveryRepository::EXECUTING );
			return;
		}

		if ( in_array( (string) $order->get_status(), self::CANCELLING_ORDER_STATUSES, true ) ) {
			// BRANCH 12 — rule 2.
			self::cancel( $delivery_id, self::REASON_ORDER_STATE, DeliveryRepository::EXECUTING );
			return;
		}

		$live_items = self::surviving_items( $order, (array) $snapshot['matched_items'] );

		if ( array() === $live_items ) {
			// BRANCH 13 — rule 2.
			self::cancel( $delivery_id, self::REASON_ITEMS_REFUNDED, DeliveryRepository::EXECUTING );
			return;
		}

		if ( ! Orchestrator::is_operational() ) {
			/*
			 * BRANCH 14 — rule 2. Distinct from branch 2: the schema was operational
			 * when this run started and is not now, or WooCommerce itself went away
			 * mid-run. The write is best-effort by nature — if the tables really have
			 * gone it will fail and `verify()` reports the shortfall — but attempting
			 * it is what turns "silently stuck" into "recorded, or loudly not".
			 */
			self::cancel( $delivery_id, self::REASON_INOPERATIVE, DeliveryRepository::EXECUTING );
			return;
		}

		// --- Everything holds: send, through the SAME path an immediate
		// delivery takes, so the containment boundary and the recording are
		// one implementation rather than two (ADR-0015 §3).
		//
		// BRANCH 15 — rule 2, in every case. `send_scheduled()` records `sent`,
		// `failed` or `skipped` off the lease; its own two refusals
		// (`email_class_unavailable`, `globally_disabled`) are terminal
		// cancellations rather than the silent returns they were.
		self::orchestrator()->send_scheduled(
			$order,
			DeliverySnapshot::as_rule_row( $snapshot, $rule_id ),
			$live_items,
			$delivery_id,
			(int) $snapshot['revision'],
			(string) $tombstone['trigger_identity']
		);

		/*
		 * ⚠ THE LAST GUARANTEE (ADR-0015 §8.4). A no-op unless the row is somehow
		 * still `executing` — every path above records an outcome and moves off the
		 * lease, so this normally costs one refused write. When it DOES land,
		 * something returned having recorded nothing (the recording itself throwing
		 * after the message went out is the realistic way), and without this the
		 * delivery would sit leased until the §8.3 sweep found it an hour later.
		 */
		self::logger()->close_unrecorded_lease( $delivery_id );
	}

	/**
	 * The lease was not granted — decide whether that is somebody else's success or
	 * nobody's (ADR-0015 §8.1a, §8.4).
	 *
	 * ⚠ THE OLD CODE READ EVERY UNGRANTED LEASE AS A LOST RACE, AND ONE OF THEM IS
	 * NOT. Branch 3b's reasoning — "the row is already terminal, or another worker
	 * holds the lease, so it HAS an owner" — is sound for a statement that RAN and
	 * matched nothing. It is false for a statement that FAILED: the row is then still
	 * `scheduled`, nobody owns it, and returning normally lets Action Scheduler
	 * consume the action and leaves the delivery with no job at all.
	 *
	 * The four branches:
	 *
	 *   - **lost race, row missing** (3a) — the order was permanently deleted and
	 *     took its tombstones with it (ADR-0004). Vacuously terminal.
	 *   - **lost race, row present** (3b) — terminal, or another worker's. It has an
	 *     owner and an outcome; re-recording would overwrite a truthful state.
	 *   - **lost race, read failed** (3b) — still a legitimate loss. A statement that
	 *     ran and changed nothing PROVES the row is not `scheduled`, whatever a
	 *     failing read then says, so exiting is correct without it.
	 *   - **write failed** (3c) — nobody owns it. It is re-queued and the replacement
	 *     job VERIFIED (rule 3), and when that cannot be done it is terminalised
	 *     loudly with `lease_write_failed` rather than left silently stranded. A read
	 *     that also fails lands here too: a contained failure, never "the row was
	 *     deleted".
	 *
	 * @param int         $delivery_id Tombstone id.
	 * @param int         $order_id    Order id from the action arguments.
	 * @param int         $attempt     Transient re-schedule count from the arguments.
	 * @param WriteResult $lease       What the lease write reported.
	 * @return void
	 */
	private static function handle_ungranted_lease( int $delivery_id, int $order_id, int $attempt, WriteResult $lease ): void {
		$deliveries = self::deliveries();
		$probe      = $deliveries->inspect( $delivery_id );

		$unknown         = ! (bool) $probe['known'];
		$still_scheduled = ! $unknown && (bool) $probe['exists'] && DeliveryRepository::SCHEDULED === (string) $probe['status'];

		if ( $still_scheduled || ( $lease->is_shortfall() && $unknown ) ) {
			/*
			 * BRANCH 3c — RULE 3, then RULE 2. The write did not happen and the row
			 * is — or, when the read failed too, may still be — `scheduled`, which
			 * means NOBODY owns this delivery and returning would consume its job.
			 *
			 * ⚠ A FAILED READ LANDS HERE, NOT IN 3a. "This plugin cannot currently
			 * reach its own table" is not "the row was deleted", and only one of them
			 * is safe to walk away from.
			 *
			 * A row that a successful read shows MISSING or terminal does not: the
			 * write failing tells us nothing new about a delivery that is already
			 * gone or already finished, and re-queueing one would leave a job with no
			 * `scheduled` tombstone behind it (gate 19).
			 *
			 * `$still_scheduled` also catches the same shape arriving as a LOST RACE,
			 * which should be impossible — a matching row always changes, so a
			 * `scheduled` row cannot report zero changed rows — and is handled rather
			 * than assumed away.
			 */
			self::log_error(
				'delivery #' . $delivery_id . ' could not take its execution lease (' . $lease->describe()
					. ') and no other actor owns it; re-queueing rather than letting the job be consumed'
			);

			self::reschedule_transient( $delivery_id, $order_id, $attempt, self::REASON_LEASE_UNWRITABLE );
			return;
		}

		if ( (bool) $probe['known'] && ! (bool) $probe['exists'] ) {
			// BRANCH 3a — rule 1, vacuously terminal. The order was permanently
			// deleted and took its tombstones with it (ADR-0004).
			return;
		}

		// BRANCH 3b — rule 1. The row is already terminal, or another worker holds
		// the lease. Either way it HAS an owner and an outcome.
		self::logger()->record_inert(
			(int) $probe['order_id'],
			'delivery #' . $delivery_id,
			'the execution lease was not granted (' . $lease->describe() . '; the delivery is "'
				. ( (bool) $probe['known'] ? (string) $probe['status'] : 'in a state that could not be read' )
				. '"), so this run sent nothing'
		);
	}

	/**
	 * Re-queue a delivery that hit a TRANSIENT condition (ADR-0015 §8.4, rule 3).
	 *
	 * ⚠ THE TOMBSTONE IS LEFT `scheduled` ON PURPOSE, AND THAT IS ONLY LEGITIMATE
	 * BECAUSE A REPLACEMENT JOB IS PROVED TO EXIST. The action id is checked, and
	 * the attempt count — which rides in the job's own arguments, because the
	 * tombstone is exactly what is unreachable here — caps the loop.
	 *
	 * When the cap is spent, or the queue refuses the job, the delivery is
	 * terminalised with `$reason` — loudly. For the SCHEMA cause that write is
	 * attempted only after a forced re-check, because writing a terminal state means
	 * writing to a table that by hypothesis is not there; when it really is gone the
	 * row is left `scheduled` and the §8.3 orphan sweep recovers it once the schema
	 * comes back.
	 *
	 * ⚠ TWO CAUSES REACH HERE, NOT ONE (Prompt 7B A2). The original is
	 * `! Migrator::is_operational()` — a plugin update in flight. The second is a
	 * lease UPDATE that FAILED while the row was still `scheduled`: nobody owns the
	 * delivery, the job is about to be consumed, and "leave it `scheduled` and prove
	 * a replacement job exists" is exactly the guarantee rule 3 provides. The reason
	 * recorded when the cap is spent differs, because the two causes are different
	 * facts and a merchant reading `schema_unavailable` for a failed lease would be
	 * told something untrue.
	 *
	 * @param int    $delivery_id Tombstone id.
	 * @param int    $order_id    Order id from the action arguments.
	 * @param int    $attempt     The attempt that just ran.
	 * @param string $reason      Terminal reason if the re-queue cannot be done.
	 * @return void
	 */
	private static function reschedule_transient( int $delivery_id, int $order_id, int $attempt, string $reason = self::REASON_SCHEMA_UNAVAILABLE ): void {
		$next  = $attempt + 1;
		$cause = self::REASON_SCHEMA_UNAVAILABLE === $reason
			? 'came due while this plugin\'s tables were unavailable'
			: 'could not take its execution lease';

		if ( $next > self::MAX_RESCHEDULES ) {
			/*
			 * ⚠ ONE FORCED RE-CHECK BEFORE GIVING UP, AND IT IS NOT A FORMALITY.
			 * `Migrator::is_operational()` memoises per REQUEST, and an Action
			 * Scheduler worker runs many actions in one request — so a `false`
			 * recorded when the worker started stays `false` for the rest of it, long
			 * after the update that caused it has finished. Forcing the re-check is
			 * what turns "left for tomorrow's sweep" into a truthful terminal state
			 * recorded now, and it is the only thing that can produce
			 * `schema_unavailable`.
			 */
			if ( Migrator::is_operational( true ) ) {
				$cancelled = self::cancel( $delivery_id, $reason, DeliveryRepository::SCHEDULED );

				if ( (bool) ( $cancelled['finalized'] ?? false ) ) {
					return;
				}

				// ⚠ TERMINALISING CAN ITSELF FAIL — the same write failure that
				// brought a lease here can refuse a cancellation. Say so; the row is
				// still `scheduled` with no job, which is precisely the shape the
				// §8.3 orphan sweep recovers.
				self::log_error(
					'delivery #' . $delivery_id . ' ' . $cause . ', exhausted its ' . self::MAX_RESCHEDULES
						. ' re-queue attempts, and could NOT be finalised either; it is left scheduled with no job and '
						. 'the daily maintenance sweep will recover it'
				);
				return;
			}

			self::log_error(
				'delivery #' . $delivery_id . ' ' . $cause . ' and has now exhausted its ' . self::MAX_RESCHEDULES
					. ' re-queue attempts; it is left scheduled and the daily maintenance sweep will recover it once '
					. 'the schema returns'
			);
			return;
		}

		$queued = self::schedule( $delivery_id, $order_id, time() + self::RESCHEDULE_DELAY_SECONDS, $next );

		if ( self::SCHEDULED !== $queued['result'] && self::ALREADY_PENDING !== $queued['result'] ) {
			self::log_error(
				'delivery #' . $delivery_id . ' ' . $cause . ' and could NOT be re-queued ('
					. self::describe( $queued ) . '); the daily maintenance sweep will recover it'
			);
			return;
		}

		self::log_error(
			'delivery #' . $delivery_id . ' ' . $cause . '; re-queued as attempt ' . $next . ' of '
				. self::MAX_RESCHEDULES . ' — ' . self::describe( $queued )
		);
	}

	/**
	 * Whether a live rule still belongs to the delayed separate-mode phase
	 * (ADR-0015 §4, check 3).
	 *
	 * NOT REDUNDANT WITH THE EXISTENCE AND ENABLED CHECKS. A merchant who
	 * switches a rule from `separate` to `insert`, or changes its delay, has
	 * changed what the rule IS — delivering the queued message would deliver a
	 * rule that no longer exists in that form. This is ADR-0012 §9's
	 * phase-isolation guarantee holding across TIME as well as within one
	 * request.
	 *
	 * The DELAY is compared against the snapshot rather than merely required to
	 * be non-zero: a rule re-timed from one hour to one week has a queued job
	 * whose scheduled time no longer means anything the merchant asked for.
	 *
	 * ⚠ SO IS `consolidation`, SINCE ADR-0016 §8, AND THE CHANGE IS FROM ONE TEST TO A
	 * DIFFERENT ONE RATHER THAN A WEAKENING. It used to require the value to be
	 * unimplemented-behaviour default — correct only while no phase implemented
	 * consolidation, which is why a rule that acquired `daily` mid-delay left the
	 * phase. Prompt 8 implements it, so a delayed `per_product` rule is a rule this
	 * phase OWNS, and what matters now is whether the merchant changed it: switching
	 * between `none` and `per_product` during the delay changes HOW MANY MESSAGES the
	 * queued delivery would send, which is exactly the class of change the delay
	 * comparison above exists for. A mismatch is `rule_left_phase`.
	 *
	 * BOTH VALUES MUST ALSO BE IN THE VOCABULARY. Equality alone would let two
	 * identically-corrupted values agree with each other, and this predicate is what
	 * `as_rule_row()` relies on when it carries the snapshotted value into the send.
	 *
	 * @param array $rule     Live rule row.
	 * @param array $snapshot Stored snapshot.
	 * @return bool
	 */
	private static function still_in_phase( array $rule, array $snapshot ): bool {
		if ( DeliveryLogger::MODE !== (string) ( $rule['delivery_mode'] ?? '' ) ) {
			return false;
		}

		if ( (int) ( $rule['delay_seconds'] ?? 0 ) !== (int) ( $snapshot['delay_seconds'] ?? 0 ) ) {
			return false;
		}

		if ( (int) ( $rule['delay_seconds'] ?? 0 ) <= 0 ) {
			return false;
		}

		/*
		 * ⚠ THE LIVE VALUE'S VALIDITY IS NO LONGER DECIDED HERE (ADR-0016 §1a).
		 * `run()` checks it one branch earlier so the distinct `consolidation_invalid`
		 * reason wins; deciding it in two places would let whichever ran first name the
		 * cause, which is the two-sources-of-truth shape ADR-0011 §7a exists to prevent.
		 * What remains here is the CHANGE check, which is this predicate's own job.
		 *
		 * The SNAPSHOTTED value is still validated, as depth. It is unreachable —
		 * `DeliverySnapshot::read()` refuses a snapshot outside the vocabulary, so the
		 * caller has already cancelled `snapshot_unreadable` — and it stays because this
		 * predicate must not depend on that ordering to be correct.
		 */
		$snapshotted = (string) ( $snapshot['consolidation'] ?? Consolidation::NONE );

		if ( ! Consolidation::is_valid( $snapshotted ) ) {
			return false;
		}

		if ( (string) ( $rule['consolidation'] ?? Consolidation::NONE ) !== $snapshotted ) {
			return false;
		}

		// KEPT WITH AN EMPTY LIST (ADR-0016 §9). It answers `true` for every rule
		// today, and it is the call site a future unimplemented-behaviour column
		// inherits without new plumbing.
		return Orchestrator::behaviour_is_implemented( $rule );
	}

	/**
	 * The snapshotted matched items that still exist and are not fully refunded
	 * (ADR-0015 §4, check 6).
	 *
	 * ⚠ PER ITEM, NOT PER ORDER, AND THAT IS THE POINT. A customer who returned
	 * one of three products still bought the other two, so the delivery still has
	 * something to be about. Only when EVERY matched item is gone does the
	 * delivery lose its subject.
	 *
	 * Refund quantities are negative in WooCommerce's own accounting
	 * (`WC_Order::get_qty_refunded_for_item()` returns a negative number), so a
	 * fully refunded item is one whose refunded quantity offsets its own.
	 *
	 * ⚠ **THESE RECORDS CARRY NO `resolution` KEY, AND THE FAN-OUT PLANNER DEPENDS ON
	 * THAT STAYING TRUE TOGETHER WITH THE LINE BELOW** (ADR-0016 §4). `get_variation_id()`
	 * returns **0** for a deleted variation on WC 10.9.4 — the behaviour ADR-0011 §4
	 * flags, where `set_props()` swallows the setter's exception while `_variation_id`
	 * survives in item meta — so a dead variation lands on `product_id` here, which is
	 * exactly the parent unit ADR-0016 §4 requires for a `partially_resolved` item. The
	 * immediate path reaches the same unit by the opposite route: `ItemResolver`
	 * RECOVERS the id from meta and marks the record `partially_resolved`, and the
	 * planner maps that to the parent.
	 *
	 * **So if a future change adds the meta fallback here, it MUST also set
	 * `resolution`.** Recovering the id without it would present a dead variation as a
	 * live one, and the planner would fan out `variation:{id}` for something nobody can
	 * identify.
	 *
	 * @param \WC_Order $order    Live order.
	 * @param int[]     $item_ids Snapshotted line-item ids.
	 * @return array[] Matched item records for the survivors, in line-item order.
	 */
	private static function surviving_items( \WC_Order $order, array $item_ids ): array {
		$wanted    = array_flip( array_map( 'intval', $item_ids ) );
		$survivors = array();

		foreach ( $order->get_items() as $id => $item ) {
			$id = (int) $id;

			if ( ! isset( $wanted[ $id ] ) || ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$quantity = (int) $item->get_quantity();
			$refunded = abs( (int) $order->get_qty_refunded_for_item( $id ) );

			if ( $quantity > 0 && $refunded >= $quantity ) {
				continue;
			}

			$survivors[] = array(
				'item_id'      => $id,
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
			);
		}

		return $survivors;
	}

	/**
	 * Cancel a scheduled delivery with its own truthful reason (ADR-0015 §4).
	 *
	 * ⚠ `$from` IS REQUIRED (ADR-0015 §8.1). `scheduled` for the eager and lifecycle
	 * paths; `executing` for everything discovered under the lease, which is every
	 * §4 re-validation check. They are not interchangeable, and a default would be
	 * a decision somebody could skip making.
	 *
	 * @param int    $delivery_id Tombstone id.
	 * @param string $reason      One of the self::REASON_* codes.
	 * @param string $from        State the tombstone is being moved out of.
	 * @return array Structured write result.
	 */
	public static function cancel( int $delivery_id, string $reason, string $from ): array {
		return self::logger()->record_scheduled_cancellation( $delivery_id, $reason, self::reason_text( $reason ), $from );
	}

	/**
	 * The sentence recorded for one cancellation reason.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	public static function reason_text( string $reason ): string {
		return self::REASON_TEXT[ $reason ] ?? 'the delivery was cancelled before it was sent';
	}

	/**
	 * Record a throw that reached self::run()'s boundary.
	 *
	 * @param int        $delivery_id Tombstone id.
	 * @param \Throwable $error       The throw.
	 * @param bool       $leased      Whether the run held the execution lease.
	 * @return void
	 */
	private static function record_containment_failure( int $delivery_id, \Throwable $error, bool $leased = true ): void {
		try {
			self::logger()->record_scheduled_failure( $delivery_id, $error, $leased );
		} catch ( \Throwable $while_recording ) {
			/*
			 * ⚠ THE CATCH ITSELF MUST NOT THROW. Escaping here would hand Action
			 * Scheduler an exception for the sake of a log row, and the delivery
			 * would still be stranded — the same inversion `Orchestrator::send()`
			 * guards against.
			 */
			self::logger()->record_inert(
				0,
				'delivery #' . $delivery_id,
				'recording a contained scheduled-delivery failure ALSO threw: '
					. get_class( $while_recording ) . ': ' . $while_recording->getMessage()
			);
		}
	}

	/**
	 * THE MAINTENANCE SWEEP: recover deliveries nothing else can reach
	 * (ADR-0015 §8.3).
	 *
	 * Two kinds of stranding, both invisible to every other mechanism:
	 *
	 *   - an `executing` row whose worker never came back. Nothing re-runs it,
	 *     because the lease is exactly what stops a second worker touching it.
	 *   - a `scheduled` row with no live job. Nothing runs it, because the thing
	 *     that would have is gone.
	 *
	 * ⚠ BOTH HALVES ARE AGE-FILTERED, AND THAT IS THE SAFETY RATHER THAN AN
	 * OPTIMISATION. A delivery armed microseconds ago has not queued its job yet; a
	 * sweep without the filter would find no job and "recover" something that was
	 * never lost.
	 *
	 * ⚠ AND BOTH HALVES ARE CURSOR-PAGED (ADR-0015 §8.3a). The orphan half used to
	 * read one page and `continue` over every healthy row in it, advancing no cursor
	 * and updating no timestamp — so on a store with more than `SWEEP_BATCH` aged
	 * pending deliveries it re-examined the same first hundred every day and orphan
	 * #101 was never reached. That needed no database error, no third-party
	 * misbehaviour and no unusual configuration: only moderate volume and a long
	 * delay, which is the feature this ADR ships.
	 *
	 * ⚠ AND THE ORPHAN HALF'S CURSOR RUNS INSIDE A BOUNDED CYCLE (ADR-0015 §8.3b),
	 * BECAUSE THE CURSOR ALONE WAS FAIR ONLY WHEN THE QUEUE WAS QUIET. The cursor
	 * reset when a page came back short — and under sustained inflow no page is ever
	 * short, so it climbed for ever and a row behind it was never revisited. The
	 * candidate set is now frozen when a cycle opens, by BOTH its id (the high-water
	 * mark) and its age (the cutoff), so it can only shrink while the sweep works
	 * through it. **THE BOUND: a delivery eligible when a cycle opens is examined
	 * within `ceil(E / T)` runs, `E` = that cycle's candidate count, `T` =
	 * `SWEEP_THROUGHPUT` (1,000) — no matter how many rows arrive meanwhile.**
	 *
	 * **THE COST OF ONE RUN, IN FULL** (gate 6):
	 *
	 *     lease half   ≤ SWEEP_MAX_PAGES            indexed SELECTs   (10)
	 *     orphan half  ≤ SWEEP_MAX_PAGES            indexed SELECTs   (10)
	 *                  + 1 SELECT MAX(id)  per cycle OPENED, ≤ 1 per run
	 *                  + 1 option read + 1 option write               (the cycle record)
	 *     per orphan-half candidate      1 as_has_scheduled_action(), short-circuiting
	 *     per orphan actually re-queued  ≤ MAX_RESCHEDULES + 1 (4) lookups + 1 insert
	 *     per lease actually recovered   1 guarded UPDATE + 1 detail insert
	 *                                    + ≤ 4 unschedule lookups
	 *
	 * so at most 1,000 candidate rows per half and 21 statements of overhead. Nothing
	 * grows with the size of the tombstone table: `lease_sweep` covers the lease half
	 * outright and its leading `final_status` column covers the orphan half's status
	 * predicate, the age filter and the high-water mark bound the candidate set at
	 * both ends, and the page cap bounds the work regardless of either.
	 *
	 * @return array{leases_recovered:int,orphans_requeued:int,orphans_cancelled:int,examined:int,cursor:int,high_water:int,cycle_complete:bool}
	 */
	public static function sweep(): array {
		$result = self::empty_sweep();

		if ( ! Migrator::is_operational() ) {
			return $result;
		}

		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - self::LEASE_WINDOW_SECONDS );
		$deliveries = self::deliveries();
		$logger     = self::logger();

		/*
		 * THE LEASE HALF. Its cursor lives only for this run, and it needs no cycle
		 * mark — RE-EXAMINED UNDER THE SAME ADVERSARIAL INFLOW THAT BROKE THE ORPHAN
		 * HALF (§8.3b), because "it drains itself" was asserted in Prompt 7B and
		 * asserting is not proving:
		 *
		 *   1. every row this half reads is transitioned out of `executing`, so it
		 *      leaves the candidate set the moment it is recovered;
		 *   2. the cursor restarts at 0 every run, so each run reads the LOWEST
		 *      surviving stale lease first;
		 *   3. a new stale lease gets a higher id — ids are monotonic and a lease is
		 *      only taken by a job that already exists — and (2) means a higher id
		 *      can never be read before a lower one.
		 *
		 * So inflow cannot push an old row backwards, and `ceil(E / T)` holds here
		 * WITHOUT a high-water mark: after each run the E oldest have gone. What the
		 * within-run cursor buys is narrower and unchanged — a row the sweep FAILED to
		 * recover cannot sit at the head of every page and hide the rows behind it.
		 *
		 * ⚠ THE ONE WAY THIS HALF CAN STARVE, STATED RATHER THAN GLOSSED: a row whose
		 * recovery WRITE fails stays `executing` and stays a candidate, so P such rows
		 * cost P of the run's T. That needs a database that accepts a read and refuses
		 * a write, it is logged per row, and it is recorded as accepted residual risk —
		 * not repaired with a mark that would break property (2).
		 */
		$after = 0;

		for ( $page = 0; $page < self::SWEEP_MAX_PAGES; $page++ ) {
			$rows = $deliveries->find_stale_leases( $cutoff, $after, self::SWEEP_BATCH );

			if ( array() === $rows ) {
				break;
			}

			foreach ( $rows as $tombstone ) {
				$delivery_id = (int) $tombstone['id'];
				$after       = max( $after, $delivery_id );
				++$result['examined'];

				try {
					// `unresolved`, NEVER `failed`. The mail may well have gone out and
					// nobody knows; asserting otherwise is what a resend feature would
					// read as permission to send it again.
					$written = $logger->record_expired_lease( $delivery_id, self::reason_text( self::REASON_LEASE_EXPIRED ) );

					// Whatever job the dead worker was running is finished or gone;
					// any surviving one would now find a terminal tombstone and stop,
					// but leaving it queued is queue litter with no owner.
					if ( (bool) ( $written['finalized'] ?? false ) ) {
						self::unschedule( $delivery_id, (int) $tombstone['order_id'] );
					}

					if ( (bool) ( $written['success'] ?? false ) ) {
						++$result['leases_recovered'];
					}
				} catch ( \Throwable $error ) {
					// ⚠ ONE BAD ROW MUST NOT END THE SWEEP. This is the only mechanism
					// that can free a stranded delivery, so it finishes its batch.
					$logger->record_inert(
						(int) $tombstone['order_id'],
						'delivery #' . $delivery_id,
						'recovering an expired execution lease threw: ' . get_class( $error ) . ': ' . $error->getMessage()
					);
				}
			}

			if ( count( $rows ) < self::SWEEP_BATCH ) {
				break;
			}
		}

		/*
		 * THE ORPHAN HALF, resuming the open cycle or opening a new one. A healthy row
		 * stays a candidate for ever — it is still `scheduled` and only gets older — so
		 * the cursor has to outlive the run or the budget alone would re-read the same
		 * head of the queue every day. And the cycle has to bound the cursor, or under
		 * sustained inflow the cursor climbs for ever and never comes back (§8.3b).
		 */
		$cycle      = self::open_cycle( $deliveries, $cutoff );
		$after      = $cycle['cursor'];
		$high_water = $cycle['high_water'];
		$frozen     = $cycle['cutoff'];
		$complete   = $after >= $high_water;

		for ( $page = 0; $page < self::SWEEP_MAX_PAGES && ! $complete; $page++ ) {
			$rows = $deliveries->find_stale_scheduled( $frozen, $after, self::SWEEP_BATCH, $high_water );

			if ( array() === $rows ) {
				/*
				 * ⚠ NO WRAP INSIDE THE RUN ANY MORE, AND ITS REMOVAL IS THE POINT RATHER
				 * THAN A SIMPLIFICATION. Wrapping used to exist because a stale cursor
				 * past the end of the table would otherwise spend a whole run reading
				 * nothing; a cycle answers that at the top instead — `open_cycle()` sees
				 * `cursor >= high_water` and opens the next cycle in THIS run. Wrapping
				 * here would instead hand a fresh cycle a partly-spent page budget, and
				 * `ceil(E / T)` would stop being true of its first run.
				 */
				$complete = true;
				break;
			}

			foreach ( $rows as $tombstone ) {
				$delivery_id = (int) $tombstone['id'];
				$order_id    = (int) $tombstone['order_id'];
				$after       = max( $after, $delivery_id );
				++$result['examined'];

				try {
					if ( self::has_any_job( $delivery_id, $order_id ) ) {
						// Not orphaned. A delivery queued for next week is pending, and
						// Action Scheduler reports it as such — only "no job at all"
						// means nothing will ever run this. THE CURSOR HAS ALREADY
						// MOVED PAST IT, which is what the old `continue;` failed to do.
						continue;
					}

					/*
					 * RE-QUEUED FOR IMMEDIATE EXECUTION RATHER THAN CANCELLED. §4's
					 * re-validation runs when it fires and decides whether the delivery
					 * is still owed, so a late job cannot send something stale — and a
					 * late email is a better outcome than a lost one. Attempt 0, because
					 * the transient counter is about a condition this row is no longer in.
					 */
					$queued = self::schedule( $delivery_id, $order_id, time(), 0 );

					if ( self::SCHEDULED === $queued['result'] || self::ALREADY_PENDING === $queued['result'] ) {
						++$result['orphans_requeued'];
						self::log_error(
							'delivery #' . $delivery_id . ' was scheduled with no queued job behind it and has been '
								. 're-queued to run now — ' . self::describe( $queued )
						);
						continue;
					}

					$cancelled = self::cancel( $delivery_id, self::REASON_ORPHANED, DeliveryRepository::SCHEDULED );

					if ( (bool) ( $cancelled['success'] ?? false ) ) {
						++$result['orphans_cancelled'];
					}
				} catch ( \Throwable $error ) {
					$logger->record_inert(
						$order_id,
						'delivery #' . $delivery_id,
						'recovering an orphaned scheduled delivery threw: ' . get_class( $error ) . ': ' . $error->getMessage()
					);
				}
			}

			if ( count( $rows ) < self::SWEEP_BATCH ) {
				// The frozen candidate set is exhausted before the mark — rows below it
				// were finalised or re-armed while the cycle ran. The cycle is over.
				$complete = true;
			}
		}

		/*
		 * ⚠ TWO WAYS A CYCLE ENDS, AND BOTH HAVE TO COUNT. A page short of the mark
		 * says the frozen set is exhausted; a cursor that has REACHED the mark says the
		 * same thing without the extra read, and it is the ordinary case when the
		 * candidate count is an exact multiple of the page budget. Reporting only the
		 * first would understate `cycle_complete` and cost a run.
		 */
		$complete = $complete || $after >= $high_water;

		if ( $complete ) {
			// ⚠ THE CURSOR IS PARKED ON THE MARK, NOT ON 0, AND THE DIFFERENCE IS THE
			// WHOLE MECHANISM. `cursor >= high_water` is how the NEXT run recognises a
			// finished cycle and opens a fresh one — with a fresh mark and a fresh
			// cutoff, which is what lets a row that became orphaned behind this cursor
			// be reached. Writing 0 here would leave an open cycle that restarts against
			// a stale mark and re-examines what it has already examined.
			$after = $high_water;
		}

		$result['cursor']         = $after;
		$result['high_water']     = $high_water;
		$result['cycle_complete'] = $complete;

		self::store_sweep_cycle( $after, $high_water, $frozen );

		return $result;
	}

	/**
	 * Resume the open sweep cycle, or open the next one (ADR-0015 §8.3b).
	 *
	 * A cycle is a single ascending traversal of ONE frozen candidate set. It is
	 * resumed while `cursor < high_water` and replaced as soon as it is not, which is
	 * why a run never wastes itself on a finished cycle: the replacement happens here,
	 * at the top, before any page budget has been spent.
	 *
	 * ⚠ AN UNREADABLE OR LEGACY RECORD OPENS A NEW CYCLE RATHER THAN FAILING. Prompt
	 * 7B stored a bare integer under a different key; a partial write, a manual edit or
	 * a restore can leave anything at all. None of it is worth a code path: the cycle
	 * record is a hint, and the cost of discarding one is re-examining rows that need
	 * no action.
	 *
	 * @param DeliveryRepository $deliveries Tombstone repository.
	 * @param string             $cutoff     Cutoff for a NEW cycle, `Y-m-d H:i:s` UTC.
	 * @return array{cursor:int,high_water:int,cutoff:string}
	 */
	private static function open_cycle( DeliveryRepository $deliveries, string $cutoff ): array {
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION_SWEEP_CYCLE, array() ) : array();

		if ( is_array( $stored ) && isset( $stored['cursor'], $stored['high_water'], $stored['cutoff'] ) ) {
			$open = array(
				'cursor'     => max( 0, (int) $stored['cursor'] ),
				'high_water' => max( 0, (int) $stored['high_water'] ),
				'cutoff'     => (string) $stored['cutoff'],
			);

			if ( $open['cursor'] < $open['high_water'] && '' !== $open['cutoff'] ) {
				return $open;
			}
		}

		return array(
			'cursor'     => 0,
			'high_water' => $deliveries->max_stale_scheduled_id( $cutoff ),
			'cutoff'     => $cutoff,
		);
	}

	/**
	 * The counts a sweep that recovered nothing reports.
	 *
	 * @return array{leases_recovered:int,orphans_requeued:int,orphans_cancelled:int,examined:int,cursor:int,high_water:int,cycle_complete:bool}
	 */
	public static function empty_sweep(): array {
		return array(
			'leases_recovered'  => 0,
			'orphans_requeued'  => 0,
			'orphans_cancelled' => 0,
			'examined'          => 0,
			'cursor'            => 0,
			'high_water'        => 0,
			'cycle_complete'    => false,
		);
	}

	/**
	 * Remember where the orphan half stopped, and in which cycle (ADR-0015 §8.3b).
	 *
	 * ⚠ A RECORD THAT IS ONLY EVER A HINT. Nothing is lost if it is stale, missing or
	 * discarded: every candidate it skips is still `scheduled` and still older than
	 * the next cycle's cutoff, so the next cycle reaches it. That is why it can live
	 * in an option rather than in the schema — and why `open_cycle()` may throw away
	 * anything it does not recognise instead of migrating it.
	 *
	 * @param int    $cursor     Last id examined; equal to the mark when the cycle is done.
	 * @param int    $high_water The cycle's frozen id bound.
	 * @param string $cutoff     The cycle's frozen age bound.
	 * @return void
	 */
	private static function store_sweep_cycle( int $cursor, int $high_water, string $cutoff ): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		// Never autoloaded: it is read once a day by one action.
		update_option(
			self::OPTION_SWEEP_CYCLE,
			array(
				'cursor'     => max( 0, $cursor ),
				'high_water' => max( 0, $high_water ),
				'cutoff'     => $cutoff,
			),
			false
		);
	}

	/**
	 * Finalise EVERY pending delayed delivery in the store (ADR-0015 §8.6).
	 *
	 * For deactivation and uninstall, which must not merely unschedule: a job
	 * removed without finalising its tombstone leaves the delivery lost, its
	 * identity consumed, and its snapshot with no clearing path.
	 *
	 * ⚠ TOMBSTONE FIRST, JOB SECOND, AND THE ORDER IS THE SAME ONE §5 ARGUES FOR.
	 * If the process dies between the two halves, a surviving job finds a terminal
	 * tombstone and stops — the outcome the site owner asked for. The other order
	 * leaves a `scheduled` tombstone owed to nobody.
	 *
	 * Paged by last id seen rather than by offset: each pass finalises the rows it
	 * read, so they leave the result set and an offset would skip exactly as many
	 * unprocessed rows as it had already handled.
	 *
	 * ⚠ BOTH PENDING STATES, AND `executing` USED TO BE INVISIBLE HERE (ADR-0015
	 * §8.8, Prompt 7B Group C). The lease means no second actor may touch a running
	 * delivery, which is right while the plugin is running and wrong while it is
	 * being shut down: a merchant who deactivated mid-flight left a row `executing`
	 * with its snapshot retained, and deactivation ALSO removes the maintenance
	 * action, so the stale-lease sweep that would have recovered it an hour later was
	 * gone as well. Nothing could ever move that row again.
	 *
	 *   `scheduled` -> `cancelled`  — nothing was attempted, so the truth is that it
	 *                                 will not happen;
	 *   `executing` -> `unresolved` — a worker WAS running it, and this plugin cannot
	 *                                 know whether the message went out before the
	 *                                 shutdown. `failed` would assert it did not,
	 *                                 which is the input a resend feature reads as
	 *                                 "retry this" (ADR-0015 §8.3).
	 *
	 * `unfinalised` is what the caller must gate the hook-wide sweep on: a job
	 * removed while its tombstone is still pending is the exact stranding this
	 * method exists to prevent.
	 *
	 * @param string $reason One of the self::REASON_* codes, for the `scheduled` half.
	 * @return array{cancelled:int,unresolved:int,unscheduled:int,unfinalised:int,operational:bool}
	 */
	public static function cancel_all_pending( string $reason ): array {
		$result = array(
			'cancelled'   => 0,
			'unresolved'  => 0,
			'unscheduled' => 0,
			'unfinalised' => 0,
			'operational' => true,
		);

		if ( ! Migrator::is_operational() ) {
			// ⚠ NOT SILENTLY "NOTHING TO DO". The caller unschedules every job this
			// plugin owns next, and doing that after an unreadable table is how a
			// pending tombstone loses its job (ADR-0015 §8.8).
			$result['operational'] = false;
			self::log_error(
				'pending delayed deliveries could NOT be finalised during ' . $reason . ': this plugin\'s tables are '
					. 'not readable. No queued job has been removed, so nothing is stranded — the deliveries stay '
					. 'pending and their jobs stay queued.'
			);

			return $result;
		}

		$deliveries = self::deliveries();
		$after_id   = 0;

		while ( true ) {
			$rows = $deliveries->find_pending_after( $after_id, DeliveryRepository::SWEEP_CHUNK_SIZE );

			if ( array() === $rows ) {
				break;
			}

			foreach ( $rows as $tombstone ) {
				$delivery_id = (int) $tombstone['id'];
				$order_id    = (int) $tombstone['order_id'];
				$leased      = DeliveryRepository::EXECUTING === (string) $tombstone['final_status'];
				$after_id    = max( $after_id, $delivery_id );

				try {
					$finalised = $leased
						? self::logger()->record_expired_lease(
							$delivery_id,
							self::reason_text( self::REASON_SHUTDOWN_INTERRUPT ),
							self::REASON_SHUTDOWN_INTERRUPT
						)
						: self::cancel( $delivery_id, $reason, DeliveryRepository::SCHEDULED );

					if ( ! (bool) ( $finalised['finalized'] ?? false ) ) {
						// ⚠ THE JOB STAYS. An unfinalised tombstone whose job has been
						// removed is stranded; one that keeps its job is merely late,
						// and §4's re-validation refuses it when it fires.
						++$result['unfinalised'];
						continue;
					}

					// Only AFTER the tombstone is terminal — see above.
					$result['unscheduled'] += self::unschedule( $delivery_id, $order_id );

					if ( (bool) ( $finalised['success'] ?? false ) ) {
						++$result[ $leased ? 'unresolved' : 'cancelled' ];
					}
				} catch ( \Throwable $error ) {
					// ⚠ CONTAINED PER DELIVERY. This runs from a deactivation hook; a
					// throw would abort the site owner's action AND leave every
					// remaining delivery uncancelled, which is the failure it was
					// called to prevent.
					++$result['unfinalised'];
					self::log_error(
						'finalising delivery #' . $delivery_id . ' during ' . $reason . ' threw: '
							. get_class( $error ) . ': ' . $error->getMessage()
					);
				}
			}
		}

		return $result;
	}

	/**
	 * Log through WooCommerce's logger when available.
	 *
	 * A plain WooCommerce-log write, deliberately not a delivery-log one: the
	 * callers here are lifecycle and maintenance paths that may run with no
	 * tombstone to attach a row to, or with the schema already gone.
	 *
	 * @param string $message What happened.
	 * @return void
	 */
	protected static function log_error( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'extonify-wcep' ) );
		}
	}

	/**
	 * Whether Action Scheduler is initialised enough to accept an action.
	 *
	 * `is_initialized()` matters as well as `function_exists()`: the functions
	 * load before the data store does, and scheduling against an uninitialised
	 * store returns 0 (ADR-0015 §6).
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
	 * @param int    $action_id Action id, or 0.
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
				return 'delivery scheduled (action #' . (int) $outcome['action_id'] . ')';
			case self::ALREADY_PENDING:
				return 'a delivery was already queued for this identity';
			case self::SCHEDULER_UNAVAILABLE:
				return 'ACTION SCHEDULER UNAVAILABLE — nothing was queued and this delivery will not happen';
			case self::SCHEDULE_FAILED:
				return 'SCHEDULING FAILED — nothing was queued and this delivery will not happen';
			default:
				return 'unrecognised scheduling outcome';
		}
	}

	/**
	 * Tombstone storage. A seam, so tests drive the job without a live queue.
	 *
	 * @return DeliveryRepository
	 */
	protected static function deliveries(): DeliveryRepository {
		return new DeliveryRepository();
	}

	/**
	 * Rule storage.
	 *
	 * @return RuleRepository
	 */
	protected static function rules(): RuleRepository {
		return new RuleRepository();
	}

	/**
	 * Delivery logger.
	 *
	 * @return DeliveryLogger
	 */
	protected static function logger(): DeliveryLogger {
		return new DeliveryLogger();
	}

	/**
	 * The orchestrator this job sends through.
	 *
	 * @return Orchestrator
	 */
	protected static function orchestrator(): Orchestrator {
		return new Orchestrator();
	}
}
