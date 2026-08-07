<?php
/**
 * Eager cancellation of pending delayed deliveries (ADR-0007, ADR-0015 §5).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Repository\DeliveryRepository;
use Extonify\WCEP\Repository\RuleRepository;

defined( 'ABSPATH' ) || exit;

/**
 * When a merchant disables or deletes a rule, its queued mail stops NOW.
 *
 * ⚠ WHY THIS EXISTS ALONGSIDE THE EXECUTION-TIME RE-VALIDATION, AND WHY NEITHER
 * REPLACES THE OTHER (ADR-0015 §5):
 *
 *   - eager cancellation cannot be COMPLETE. A rule row deleted directly in SQL,
 *     a migration, a staging-database restore, or a failure part-way through this
 *     sweep all leave jobs behind;
 *   - execution-time re-validation cannot be TIMELY. It runs when the job runs,
 *     which is exactly the delay the merchant wanted to stop.
 *
 * A design with only the first sends nothing it should not, but leaves queue
 * entries nobody can account for. A design with only the second leaves a merchant
 * who switched a rule off watching the queue for six hours wondering whether it
 * worked. ADR-0007 says "immediately cancels", and a job still sitting in the
 * queue is not immediate however correctly it later declines to send.
 *
 * DECOUPLED THROUGH PLUGIN-OWNED ACTIONS, DELIBERATELY. `RuleRepository` is the
 * storage boundary and knows nothing about delivery, scheduling or Action
 * Scheduler; it announces that a rule changed and this class decides what that
 * means for pending mail. That is the ordinary WordPress division, and it means
 * the rule editor — when it lands — gets eager cancellation without asking for it.
 */
class ScheduledCancellation {

	/**
	 * Fired by `RuleRepository::update()` after a rule row is written.
	 */
	const ACTION_UPDATED = 'extonify_wcep_rule_updated';

	/**
	 * Fired by `RuleRepository::delete()` after a rule row is removed.
	 */
	const ACTION_DELETED = 'extonify_wcep_rule_deleted';

	/**
	 * Register the subscribers.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::ACTION_UPDATED, array( self::class, 'on_rule_updated' ), 10, 1 );
		add_action( self::ACTION_DELETED, array( self::class, 'on_rule_deleted' ), 10, 1 );
	}

	/**
	 * A rule was written. Cancel its pending deliveries if it has left the
	 * delayed phase.
	 *
	 * ⚠ THE TEST IS "IS IT STILL SCHEDULABLE", NOT "WAS IT DISABLED". A merchant
	 * who switches a rule to insert mode, or changes its delay, has changed what
	 * the rule IS just as decisively as disabling it — and the queued job would
	 * otherwise deliver a rule that no longer exists in that form. One predicate
	 * covers every way out of the phase.
	 *
	 * ⚠ IT IS DELIBERATELY THE WEAKER OF THE TWO TESTS.
	 * `ScheduledDelivery::still_in_phase()` additionally compares the rule's delay
	 * against the SNAPSHOT'S, which this cannot do: eager cancellation acts on a
	 * rule and may be looking at many queued deliveries with different snapshots.
	 * So this asks only "could this rule still schedule at all", and anything it
	 * lets through the execution-time check catches per delivery. That is the §5
	 * two-path design working as intended rather than a gap — but the asymmetry is
	 * stated, because a reader who assumed the two predicates were identical would
	 * conclude one of them was redundant.
	 *
	 * @param mixed $rule_id Rule id.
	 * @return int Deliveries cancelled.
	 */
	public static function on_rule_updated( $rule_id ): int {
		$rule_id = (int) $rule_id;

		if ( $rule_id <= 0 || ! Migrator::is_operational() ) {
			return 0;
		}

		$rule = self::rules()->find( $rule_id );

		if ( null !== $rule && 'active' === (string) ( $rule['status'] ?? '' ) && ScheduledPhase::owns( $rule ) ) {
			// Still a live delayed rule: its queued deliveries stand.
			return 0;
		}

		if ( null === $rule ) {
			return self::cancel_pending( $rule_id, ScheduledDelivery::REASON_RULE_DELETED );
		}

		// STILL ACTIVE BUT NO LONGER SCHEDULABLE means the merchant changed the
		// rule's mode or delay — a different fact from disabling it, and it gets its
		// own reason (ADR-0015 §4). ⚠ Consolidation left this list in Prompt 8: a
		// delayed `per_product` rule is schedulable (ADR-0016 §8), so a CHANGE to it is
		// caught per delivery by `ScheduledDelivery::still_in_phase()`, which can
		// compare against that delivery's snapshot as this predicate deliberately
		// cannot.
		$active = 'active' === (string) ( $rule['status'] ?? '' );

		return self::cancel_pending(
			$rule_id,
			$active ? ScheduledDelivery::REASON_RULE_LEFT_PHASE : ScheduledDelivery::REASON_RULE_DISABLED
		);
	}

	/**
	 * A rule was deleted. Cancel every pending delivery it owns.
	 *
	 * @param mixed $rule_id Rule id.
	 * @return int Deliveries cancelled.
	 */
	public static function on_rule_deleted( $rule_id ): int {
		$rule_id = (int) $rule_id;

		if ( $rule_id <= 0 || ! Migrator::is_operational() ) {
			return 0;
		}

		return self::cancel_pending( $rule_id, ScheduledDelivery::REASON_RULE_DELETED );
	}

	/**
	 * Cancel every pending scheduled delivery for one rule.
	 *
	 * BOTH HALVES, IN THIS ORDER, AND THE SECOND ONLY IF THE FIRST WON: the tombstone
	 * is finalised FIRST and the queued action removed second — and *removed at all*
	 * only when the tombstone actually reached a terminal state. If the process dies
	 * between them, the surviving job finds a tombstone that is no longer `scheduled`
	 * and stops, which is the outcome the merchant asked for. The other order, and a
	 * removal that does not check, both leave a `scheduled` tombstone with no job:
	 * a delivery owed to nobody.
	 *
	 * ⚠ EACH DELIVERY IS CONTAINED SEPARATELY. This runs from a rule save, so a
	 * throw would break the merchant's admin action for the sake of one queue
	 * entry — and would leave the remaining deliveries uncancelled, which is the
	 * failure it was called to prevent.
	 *
	 * @param int    $rule_id Rule id.
	 * @param string $reason  One of the `ScheduledDelivery::REASON_*` codes.
	 * @return int Deliveries cancelled.
	 */
	public static function cancel_pending( int $rule_id, string $reason ): int {
		$cancelled = 0;

		foreach ( self::deliveries()->find_scheduled_for_rule( $rule_id ) as $tombstone ) {
			$delivery_id = (int) $tombstone['id'];
			$order_id    = (int) $tombstone['order_id'];

			try {
				/*
				 * ⚠ CONDITIONAL ON `scheduled` (ADR-0015 §8.1), so a delivery that is
				 * ALREADY EXECUTING is not cancelled out from under its worker. The
				 * transition simply loses and this loop moves on: the running send
				 * owns the outcome, and overwriting a `sent` tombstone with
				 * `cancelled` would tell a merchant an email they received was never
				 * sent. `find_scheduled_for_rule()` filters on `scheduled` too, so the
				 * row is normally not even read — this is the guard for the window
				 * between that read and this write.
				 */
				$result = ScheduledDelivery::cancel( $delivery_id, $reason, DeliveryRepository::SCHEDULED );

				/*
				 * ⚠ UNSCHEDULED ONLY WHEN THE TOMBSTONE REALLY IS TERMINAL, AND THE
				 * COMMENT THAT USED TO SIT HERE CLAIMED THAT WITHOUT CHECKING IT
				 * (Prompt 7B A3). The unschedule ran first and the result was inspected
				 * afterwards, so a transition that failed on a query error — leaving the
				 * row `scheduled` — still had its job removed, and the delivery was left
				 * with no owner and nothing to run it.
				 *
				 * `finalized` is the right gate rather than `success`: it means the
				 * tombstone HOLDS a terminal status, which is exactly the precondition
				 * for the job being safe to remove. `success` is stricter — it also
				 * demands the detail row — and a delivery that is genuinely terminal
				 * with a missing log row must still not keep a live job.
				 *
				 * When it did NOT take ownership the job is deliberately LEFT IN PLACE:
				 * ADR-0015 §4's re-validation is the backstop, and it will decline to
				 * send a delivery whose rule is gone or disabled when the job fires.
				 * A job with a live tombstone is recoverable; a tombstone with no job
				 * is the stranding this whole ADR exists to prevent.
				 */
				if ( (bool) ( $result['finalized'] ?? false ) ) {
					ScheduledDelivery::unschedule( $delivery_id, $order_id );
				} else {
					self::logger()->record_inert(
						$order_id,
						'delivery #' . $delivery_id,
						'eager cancellation for rule #' . $rule_id . ' did not finalise this delivery, so its queued '
							. 'job was left in place for the execution-time re-validation to refuse'
					);
				}

				if ( (bool) ( $result['success'] ?? false ) ) {
					++$cancelled;
				}
			} catch ( \Throwable $error ) {
				self::logger()->record_inert(
					$order_id,
					'delivery #' . $delivery_id,
					'eager cancellation threw for rule #' . $rule_id . ': '
						. get_class( $error ) . ': ' . $error->getMessage()
				);
			}
		}

		return $cancelled;
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
	 * Tombstone storage.
	 *
	 * @return DeliveryRepository
	 */
	protected static function deliveries(): DeliveryRepository {
		return new DeliveryRepository();
	}

	/**
	 * Delivery logger.
	 *
	 * @return DeliveryLogger
	 */
	protected static function logger(): DeliveryLogger {
		return new DeliveryLogger();
	}
}
