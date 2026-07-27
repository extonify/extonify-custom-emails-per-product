<?php
/**
 * Uninstall handler.
 *
 * Honours the cleanup setting: data is removed ONLY when the site owner
 * explicitly opted in via the extonify_wcep_remove_data_on_uninstall option
 * (default 'no' — deleting the plugin keeps every rule and delivery record).
 *
 * Deliberately dependency-free: no autoloader, no WooCommerce, plain $wpdb.
 * This is the documented exception to the "SQL only in a repository" rule, as
 * the repositories cannot be assumed loadable during uninstall.
 *
 * @package Extonify\WCEP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( 'yes' !== get_option( 'extonify_wcep_remove_data_on_uninstall', 'no' ) ) {
	return;
}

global $wpdb;

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
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- uninstall cleanup of plugin-owned tables; the identifier is esc_sql'd above and is not user input.
	$wpdb->query( "DROP TABLE IF EXISTS `{$extonify_wcep_table}`" );
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

delete_option( 'extonify_wcep_db_version' );
delete_option( 'extonify_wcep_db_error' );
delete_option( 'extonify_wcep_migration_lock' );
delete_option( 'extonify_wcep_settings' );
delete_option( 'extonify_wcep_remove_data_on_uninstall' );
delete_option( 'extonify_wcep_version' );
