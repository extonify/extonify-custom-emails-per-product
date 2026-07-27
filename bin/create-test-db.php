<?php
/**
 * Create (or refresh) the dedicated integration-test database.
 *
 * The integration suite performs real DDL — it drops the plugin tables in order
 * to prove that Migrator::verify_schema() notices a broken schema. Pointing it
 * at the working development database means authorising the destruction of real
 * rules and delivery history, so it gets a database of its own.
 *
 * This clones the WordPress install (structure AND data) into that database, so
 * the suite runs against a faithful copy: same options, same active plugins,
 * same WooCommerce tables, same orders.
 *
 * Usage:  php bin/create-test-db.php [--name=extonify_wcep_test] [--force]
 *
 * Re-running is safe: without --force an existing, already-populated database is
 * left alone. With --force every table in it is dropped and re-cloned.
 *
 * @package Extonify\WCEP
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( "CLI only.\n" );
}

$wcep_plugin_dir = dirname( __DIR__ );

$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI']    = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}

require dirname( $wcep_plugin_dir, 3 ) . '/wp-load.php';

global $wpdb;

$wcep_argv  = isset( $argv ) ? (array) $argv : array();
$wcep_force = in_array( '--force', $wcep_argv, true );
$wcep_name  = 'extonify_wcep_test';
foreach ( $wcep_argv as $wcep_arg ) {
	if ( 0 === strpos( (string) $wcep_arg, '--name=' ) ) {
		$wcep_name = substr( (string) $wcep_arg, 7 );
	}
}

// The target must be recognisably a test database. This script clones over
// whatever is there, so pointing it at the live database would be catastrophic.
if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $wcep_name ) || ! preg_match( '/(_test|_tests)$/i', $wcep_name ) ) {
	fwrite( STDERR, "Refusing: the target name must be alphanumeric and end in _test or _tests. Got \"{$wcep_name}\".\n" );
	exit( 1 );
}
if ( $wcep_name === DB_NAME ) {
	fwrite( STDERR, "Refusing: the target must not be the live database (" . DB_NAME . ").\n" );
	exit( 1 );
}

$wcep_source = DB_NAME;
echo "source: {$wcep_source}\n";
echo "target: {$wcep_name}\n\n";

$wpdb->query( "CREATE DATABASE IF NOT EXISTS `{$wcep_name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci" );

$wcep_existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s', $wcep_name ) );
if ( $wcep_existing > 0 && ! $wcep_force ) {
	echo "Already populated with {$wcep_existing} tables. Pass --force to re-clone.\n";
	exit( 0 );
}

$wcep_tables = $wpdb->get_col( $wpdb->prepare( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_TYPE = "BASE TABLE"', $wcep_source ) );
echo 'cloning ' . count( $wcep_tables ) . " tables...\n";

// Foreign-key checks off for the duration: tables are copied in an arbitrary
// order and WooCommerce declares constraints between some of them.
$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );

$wcep_copied = 0;
foreach ( $wcep_tables as $wcep_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$wcep_name}`.`{$wcep_table}`" );
	$wpdb->query( "CREATE TABLE `{$wcep_name}`.`{$wcep_table}` LIKE `{$wcep_source}`.`{$wcep_table}`" );
	$wpdb->query( "INSERT INTO `{$wcep_name}`.`{$wcep_table}` SELECT * FROM `{$wcep_source}`.`{$wcep_table}`" );

	if ( '' !== $wpdb->last_error ) {
		fwrite( STDERR, "  FAILED {$wcep_table}: {$wpdb->last_error}\n" );
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
		exit( 1 );
	}
	++$wcep_copied;
}

$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );

echo "cloned {$wcep_copied} tables\n\n";
echo "Run the integration suite against it with:\n";
echo "  EXTONIFY_WCEP_ALLOW_DESTRUCTIVE_TESTS=1 WP_ENVIRONMENT_TYPE=development \\\n";
echo "    EXTONIFY_WCEP_TEST_DB={$wcep_name} composer test:integration\n";
