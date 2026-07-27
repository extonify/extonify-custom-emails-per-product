<?php
/**
 * Deactivation routine.
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Unschedules this plugin's recurring actions.
 *
 * Deactivation NEVER drops a table and NEVER deletes data. Removing data on
 * deactivate would destroy the ADR-0004 tombstones, and re-activating would
 * then re-arm every historical order — the exact failure that ADR forbids.
 * Data removal belongs to uninstall.php, and only when the site owner opted in.
 */
final class Deactivator {

	/**
	 * Action Scheduler group used by every action this plugin schedules.
	 */
	const ACTION_GROUP = 'extonify-wcep';

	/**
	 * Recurring actions registered by this plugin.
	 *
	 * The retention purge is the only one the foundation owns; scheduled
	 * delivery actions (ADR-0007) are one-off and belong to a later prompt.
	 */
	const RECURRING_HOOKS = array( 'extonify_wcep_retention_purge' );

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
	}
}
