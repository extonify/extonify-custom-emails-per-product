<?php
/**
 * Deactivation routine.
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Install;

use Extonify\WCEP\Delivery\DeferredEvaluation;
use Extonify\WCEP\Delivery\ScheduledDelivery;

defined( 'ABSPATH' ) || exit;

/**
 * Finalises every pending delivery, then unschedules every action this plugin
 * owns.
 *
 * Deactivation NEVER drops a table and NEVER deletes data. Removing data on
 * deactivate would destroy the ADR-0004 tombstones, and re-activating would
 * then re-arm every historical order — the exact failure that ADR forbids.
 * Data removal belongs to uninstall.php, and only when the site owner opted in.
 * **Finalising a pending delivery is not data removal**: the tombstone stays,
 * with a terminal status and a reason recorded against it (ADR-0015 §8.6).
 *
 * IT DOES CANCEL PENDING WORK, INCLUDING ONE-OFF DEFERRALS. A pending ADR-0008
 * deferral that survives deactivation fires whenever the queue next runs — which
 * may be after the merchant reactivates the plugin days later — and sends a
 * customer an email for a status change they have long forgotten. "Deactivated"
 * has to mean nothing further is sent.
 *
 * ⚠ AND IT DOES BOTH HALVES. Unscheduling alone left every delayed delivery
 * `scheduled` for ever: lost, with its identity still consumed and its snapshot
 * retained on a table that is never purged. The tombstone is finalised FIRST and
 * the job removed second — so a process that dies between them leaves a surviving
 * job that finds a terminal tombstone and stops, rather than a `scheduled`
 * tombstone owed to nobody.
 *
 * ⚠ AND THE SECOND HALF HAS A PRECONDITION (ADR-0015 §8.8, Prompt 7B Group C).
 * `cancel_all_pending()` returns early when the schema is unreadable, and the
 * hook-wide unschedule below used to run regardless — removing every job while the
 * tombstones stayed pending, which is precisely the stranded state the ADR exists
 * to eliminate. The sweep now runs only when every pending tombstone was finalised;
 * otherwise the jobs are left where they are and the reason is logged.
 */
final class Deactivator {

	/**
	 * Action Scheduler group used by every action this plugin schedules.
	 */
	const ACTION_GROUP = 'extonify-wcep';

	/**
	 * Recurring actions registered by this plugin.
	 */
	const RECURRING_HOOKS = array( 'extonify_wcep_retention_purge', Maintenance::HOOK );

	/**
	 * One-off actions registered by this plugin.
	 *
	 * ENUMERATED, not cleared by group alone. Unscheduling the whole group would
	 * also remove anything a future integration deliberately parked there, and
	 * a named list makes each addition a reviewable edit — the same reasoning
	 * `RuleRepository::NON_REVISION_FIELDS` follows.
	 */
	const ONE_OFF_HOOKS = array( DeferredEvaluation::HOOK, ScheduledDelivery::HOOK );

	/**
	 * Finalise pending work, then unschedule everything this plugin registered.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		/*
		 * ⚠ FINALISE FIRST, UNSCHEDULE SECOND (ADR-0015 §8.6). Removing the jobs
		 * alone left every pending delayed delivery `scheduled` for ever: the
		 * delivery lost, its ADR-0004 identity still consumed, and its snapshot
		 * with no clearing path — one rule body per interrupted delivery, retained
		 * on a table that is never purged.
		 *
		 * The order is the one ADR-0015 §5 argues for. If the process dies between
		 * the halves a surviving job finds a terminal tombstone and stops, which is
		 * what the site owner asked for; the other order leaves a `scheduled`
		 * tombstone owed to nobody.
		 *
		 * ⚠ AND IT IS PERMANENT (ADR-0015 §1a, §8.7). Those identities stay
		 * consumed, so reactivating does NOT resume the interrupted deliveries —
		 * which is correct, and is the alternative to a plugin toggled off and on
		 * re-delivering to every order still inside its delay window.
		 *
		 * Guarded by the schema check inside `cancel_all_pending()`, so deactivating
		 * a plugin whose tables are already gone is a no-op rather than a fatal —
		 * and, since Prompt 7B, a no-op that also stops the hook-wide sweep below
		 * (ADR-0015 §8.8). "Nothing could be finalised" is exactly the condition
		 * under which removing every job strands every delivery.
		 */
		$finalised = ScheduledDelivery::cancel_all_pending( ScheduledDelivery::REASON_PLUGIN_DEACTIVATED );

		// Guarded by function_exists: Action Scheduler ships inside WooCommerce,
		// and deactivation can run while WooCommerce is already gone.
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		if ( ! self::may_sweep_the_queue( $finalised ) ) {
			return;
		}

		foreach ( self::RECURRING_HOOKS as $hook ) {
			as_unschedule_all_actions( $hook, array(), self::ACTION_GROUP );
		}

		/*
		 * ⚠ THE MAINTENANCE ACTION HAS JUST BEEN REMOVED, SO ITS VERIFICATION STAMP IS
		 * NOW A LIE (ADR-0015 §8.3c). Leaving it would make `ensure_armed()` report an
		 * armed sweep for up to VERIFY_INTERVAL_SECONDS after a deactivate/reactivate
		 * cycle — the one window in which a fresh delivery could be queued with no
		 * recovery behind it. Clearing costs one DELETE on a path that runs once.
		 */
		Maintenance::forget_verification();
		delete_option( Maintenance::OPTION_VERIFIED );

		/*
		 * A one-off deferral carries ARGUMENTS, and
		 * `as_unschedule_all_actions( $hook, array(), $group )` matches only
		 * actions whose args are exactly `array()` — it would leave every real
		 * deferral pending. Passing `null` for the args means "any arguments",
		 * which is what cancelling a hook actually requires.
		 */
		foreach ( self::ONE_OFF_HOOKS as $hook ) {
			as_unschedule_all_actions( $hook, null, self::ACTION_GROUP );
		}
	}

	/**
	 * Whether the HOOK-WIDE unschedule may run (ADR-0015 §8.8).
	 *
	 * ⚠ IT IS A SWEEP WITH NO TOMBSTONE IN ITS HAND, AND THAT IS WHY IT NEEDS A
	 * PRECONDITION. `as_unschedule_all_actions( $hook, null, $group )` removes every
	 * job this plugin owns whether or not the row behind it was finalised — so when
	 * `cancel_all_pending()` returned early because the tables were unreadable, this
	 * ran anyway and produced exactly the stranded state this ADR exists to
	 * eliminate: a pending tombstone, its identity consumed, and no job left to
	 * complete it. The per-delivery unschedule inside `cancel_all_pending()` is
	 * already gated on that delivery being terminal; this is the same gate for the
	 * sweep that has no per-delivery knowledge.
	 *
	 * **The jobs are left in place, deliberately.** A queued job whose plugin is
	 * deactivated does nothing — its hook has no handler — and if the plugin is
	 * reactivated, ADR-0015 §4's re-validation decides whether the delivery is still
	 * owed. That is recoverable. A tombstone with no job is not.
	 *
	 * @param array $finalised What `ScheduledDelivery::cancel_all_pending()` reported.
	 * @return bool
	 */
	private static function may_sweep_the_queue( array $finalised ): bool {
		$operational = (bool) ( $finalised['operational'] ?? false );
		$unfinalised = (int) ( $finalised['unfinalised'] ?? 0 );

		if ( $operational && 0 === $unfinalised ) {
			return true;
		}

		// LOUDLY, not silently. A merchant whose deactivation left work behind has to
		// be able to find out why from the log.
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				$operational
					? 'deactivation could not finalise ' . $unfinalised . ' pending delayed deliver'
						. ( 1 === $unfinalised ? 'y' : 'ies' ) . ', so their queued jobs were LEFT IN PLACE rather '
						. 'than stranding the deliveries. Nothing further will be sent while the plugin is inactive.'
					: 'deactivation could not read this plugin\'s tables, so no pending delayed delivery was finalised '
						. 'and NO queued job was removed. Nothing further will be sent while the plugin is inactive.',
				array( 'source' => 'extonify-wcep' )
			);
		}

		return false;
	}
}
