<?php
/**
 * Uninstall handler.
 *
 * Honours the cleanup setting: data is removed ONLY when the site owner
 * explicitly opted in via the extonify_wcep_remove_data_on_uninstall option
 * (default 'no' — deleting the plugin keeps every rule and delivery record).
 *
 * ⚠ TWO THINGS HAPPEN ON *BOTH* PATHS (ADR-0015 §8.6), and they are not data
 * deletion: pending delayed deliveries are finalised, and this plugin's queued
 * Action Scheduler jobs are removed. A site owner who keeps their delivery
 * history is not asking to keep a queue of jobs whose hook has just been deleted,
 * nor a set of records claiming a delivery is about to happen when nothing will
 * ever run it. Only the TABLE DROPS are conditional on the option.
 *
 * Deliberately dependency-free: no autoloader, no WooCommerce, plain $wpdb.
 * This is the documented exception to the "SQL only in a repository" rule, as
 * the repositories cannot be assumed loadable during uninstall.
 *
 * @package Extonify\WCEP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$extonify_wcep_remove_data = ( 'yes' === get_option( 'extonify_wcep_remove_data_on_uninstall', 'no' ) );

/*
 * ⚠ EVERYTHING DOWN TO THE RETAIN-DATA CHECK RUNS ON BOTH PATHS (ADR-0015 §8.6).
 *
 * This block used to sit BELOW an early return, so the DEFAULT uninstall — the
 * one that keeps the site owner's data, and therefore the one almost every site
 * takes — left queued actions behind whose hook no longer exists. A site owner
 * asking to keep their delivery history is not asking to keep a queue of jobs
 * pointing at code that has been deleted.
 *
 * Only the TABLE DROPS are conditional on the option. Finalising pending
 * tombstones is not a data deletion: it moves a delivery that can no longer
 * happen out of a state that claims it is about to, and releases the snapshot —
 * unrendered rule content — that was only ever stored as pending work.
 */

/*
 * FINALISE, THEN UNSCHEDULE (ADR-0015 §8.6, §8.8). Uninstall runs with this
 * plugin's classes usually absent, so the tombstones are finalised in plain SQL
 * rather than through the repository — the documented exception this file already
 * relies on. Only the two PENDING states are moved, so a delivery that is already
 * terminal is left exactly as it is.
 *
 * ⚠ BOTH PENDING STATES, AND THEY DO NOT GET THE SAME OUTCOME (ADR-0015 §8.8):
 *
 *   `scheduled` -> `cancelled`  — nothing was attempted, so it will not happen;
 *   `executing` -> `unresolved` — a worker WAS running it, and nothing here can
 *                                 know whether the message went out first.
 *                                 `cancelled` would be a claim about a customer's
 *                                 inbox that this code is not in a position to make.
 *
 * `executing` used to be untouched, which left a row that nothing could ever move
 * again: the uninstall also removes the maintenance action, so the stale-lease
 * sweep was gone with it.
 *
 * ⚠ NO PER-DELIVERY REASON ROW IS WRITTEN, AND THAT IS THE DEPENDENCY-FREE
 * CONSTRAINT SHOWING. A detail row is allocated inside a transaction holding the
 * parent lock (`DeliveryDetailRepository::record_attempt()`), which is not
 * reimplementable here without duplicating the repository this file exists to do
 * without. The status and the released snapshot are recorded; the sentence is not.
 */
$extonify_wcep_deliveries = esc_sql( $wpdb->prefix . 'extonify_wcep_deliveries' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall read of a plugin-owned table; the identifier is esc_sql'd and is not user input, and the repositories cannot be assumed loadable here.
$extonify_wcep_table_exists = (string) $wpdb->get_var(
	$wpdb->prepare( 'SHOW TABLES LIKE %s', $extonify_wcep_deliveries )
) === $extonify_wcep_deliveries;

/*
 * Pending tombstones surviving this block, which is the precondition for the
 * hook-wide unschedule below. A table that is not there cannot hold a stranded
 * row, so its absence is 0 rather than "unknown".
 */
$extonify_wcep_pending = 0;

if ( $extonify_wcep_table_exists ) {
	$extonify_wcep_now = gmdate( 'Y-m-d H:i:s' );

	foreach (
		array(
			array( 'scheduled', 'cancelled' ),
			array( 'executing', 'unresolved' ),
		) as $extonify_wcep_move
	) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup of a plugin-owned table; the identifier is esc_sql'd and is not user input, and the repositories cannot be assumed loadable here.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i
					SET final_status = %s, snapshot = NULL, lease_taken_at = NULL, last_seen_at = %s
				WHERE final_status = %s',
				$extonify_wcep_deliveries,
				$extonify_wcep_move[1],
				$extonify_wcep_now,
				$extonify_wcep_move[0]
			)
		);
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall read of a plugin-owned table; a cached count would authorise removing jobs against a stale answer.
	$extonify_wcep_count = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE final_status IN ( %s, %s )',
			$extonify_wcep_deliveries,
			'scheduled',
			'executing'
		)
	);

	// ⚠ NULL IS NOT ZERO. An unreadable count must never authorise the sweep.
	$extonify_wcep_pending = null === $extonify_wcep_count ? -1 : (int) $extonify_wcep_count;
}

/*
 * SCHEDULED WORK GOES NEXT (ADR-0015 §5, §8.6, §8.8). A pending delayed-delivery
 * action outliving its plugin fires against a hook nothing handles — and a one-off
 * action carries ARGUMENTS, so `array()` matches only actions whose args are
 * exactly empty and would cancel nothing. `null` means "any arguments", which is
 * what cancelling a hook actually requires (verified in Prompt 4A).
 *
 * The recurring maintenance action carries no arguments and is cancelled with
 * `array()`, which is exact for it.
 *
 * ⚠ ONLY WHEN NOTHING IS LEFT PENDING. This is a sweep with no tombstone in its
 * hand: it removes every job whether or not the row behind it was finalised, so
 * running it while a pending row survives is how a delivery loses its job and its
 * last chance of being completed. A job left queued for an uninstalled plugin does
 * nothing at all — its hook has no handler — so leaving it is the strictly safer
 * failure.
 *
 * Guarded: Action Scheduler ships inside WooCommerce, and uninstall can run with
 * WooCommerce already gone.
 */
if ( function_exists( 'as_unschedule_all_actions' ) && 0 === $extonify_wcep_pending ) {
	foreach ( array( 'extonify_wcep_deferred_evaluation', 'extonify_wcep_scheduled_delivery' ) as $extonify_wcep_hook ) {
		as_unschedule_all_actions( $extonify_wcep_hook, null, 'extonify-wcep' );
	}

	foreach ( array( 'extonify_wcep_retention_purge', 'extonify_wcep_maintenance' ) as $extonify_wcep_hook ) {
		as_unschedule_all_actions( $extonify_wcep_hook, array(), 'extonify-wcep' );
	}
} elseif ( 0 !== $extonify_wcep_pending && function_exists( 'wc_get_logger' ) ) {
	wc_get_logger()->error(
		'uninstall could not finalise every pending delayed delivery, so no queued job was removed and nothing is '
			. 'stranded. Whatever is left will simply never run: its hook no longer has a handler.',
		array( 'source' => 'extonify-wcep' )
	);
}

if ( ! $extonify_wcep_remove_data ) {
	// The site owner keeps their data. Nothing further is dropped, and the
	// options stay so a reinstall finds their settings.
	return;
}

/*
 * Table names are plugin-built (the CURRENT site's prefix plus a hardcoded
 * literal) and are never user input. No assumption is made about other sites'
 * prefixes: this plugin is per-site-activation only, so uninstall cleans this
 * site and nothing else. A table identifier cannot be bound by
 * $wpdb->prepare(); esc_sql() on the identifier is the correct tool here and
 * keeps static analysis honest.
 *
 * Children first (delivery_details references deliveries by convention), then
 * parents.
 */
$extonify_wcep_tables = array(
	esc_sql( $wpdb->prefix . 'extonify_wcep_delivery_details' ),
	esc_sql( $wpdb->prefix . 'extonify_wcep_deliveries' ),
	esc_sql( $wpdb->prefix . 'extonify_wcep_rules' ),
);

foreach ( $extonify_wcep_tables as $extonify_wcep_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall cleanup of plugin-owned tables.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $extonify_wcep_table ) );
}

/*
 * Invalidate the request-memoized schema check. uninstall.php runs without the
 * autoloader, so the class is usually absent — but WordPress can run uninstall
 * in a request where the plugin was loaded, and a stale `true` would then
 * outlive the tables that were just dropped.
 */
if ( class_exists( '\\Extonify\\WCEP\\Install\\Migrator', false ) ) {
	\Extonify\WCEP\Install\Migrator::flush_schema_cache();
}

// The sweep's own bookkeeping. `_sweep_cursor` is the Prompt 7B key, superseded by
// the cycle record in 7C and deleted here so an install that ran the earlier code
// leaves nothing behind.
delete_option( 'extonify_wcep_sweep_cursor' );
delete_option( 'extonify_wcep_sweep_cycle' );
delete_option( 'extonify_wcep_maintenance_verified' );
delete_option( 'extonify_wcep_db_version' );
delete_option( 'extonify_wcep_db_error' );
delete_option( 'extonify_wcep_migration_lock' );
delete_option( 'extonify_wcep_settings' );
delete_option( 'extonify_wcep_remove_data_on_uninstall' );
delete_option( 'extonify_wcep_version' );
