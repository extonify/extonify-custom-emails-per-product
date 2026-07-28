<?php
/**
 * Deactivation routine.
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Install;

use Extonify\WCEP\Delivery\DeferredEvaluation;

defined( 'ABSPATH' ) || exit;

/**
 * Unschedules every action this plugin owns.
 *
 * Deactivation NEVER drops a table and NEVER deletes data. Removing data on
 * deactivate would destroy the ADR-0004 tombstones, and re-activating would
 * then re-arm every historical order — the exact failure that ADR forbids.
 * Data removal belongs to uninstall.php, and only when the site owner opted in.
 *
 * IT DOES CANCEL PENDING WORK, INCLUDING ONE-OFF DEFERRALS. A pending ADR-0008
 * deferral that survives deactivation fires whenever the queue next runs — which
 * may be after the merchant reactivates the plugin days later — and sends a
 * customer an email for a status change they have long forgotten. "Deactivated"
 * has to mean nothing further is sent.
 */
final class Deactivator {

	/**
	 * Action Scheduler group used by every action this plugin schedules.
	 */
	const ACTION_GROUP = 'extonify-wcep';

	/**
	 * Recurring actions registered by this plugin.
	 */
	const RECURRING_HOOKS = array( 'extonify_wcep_retention_purge' );

	/**
	 * One-off actions registered by this plugin.
	 *
	 * ENUMERATED, not cleared by group alone. Unscheduling the whole group would
	 * also remove anything a future integration deliberately parked there, and
	 * a named list makes each addition a reviewable edit — the same reasoning
	 * `RuleRepository::NON_REVISION_FIELDS` follows.
	 */
	const ONE_OFF_HOOKS = array( DeferredEvaluation::HOOK );

	/**
	 * Unschedule everything this plugin registered.
	 *
	 * Guarded by function_exists: Action Scheduler ships inside WooCommerce, and
	 * deactivation can run while WooCommerce is already gone.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		foreach ( self::RECURRING_HOOKS as $hook ) {
			as_unschedule_all_actions( $hook, array(), self::ACTION_GROUP );
		}

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
}
