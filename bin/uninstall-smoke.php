<?php
/**
 * Uninstall smoke test. NON-PRODUCTION (excluded from the zip).
 *
 * Exercises BOTH uninstall paths against the live install:
 *
 *   1. opt-out (the default) — uninstall must leave every table and option;
 *   2. opt-in                — uninstall must drop all three tables and remove
 *                              every plugin option.
 *
 * The plugin is reactivated at the end, so the SCHEMA is left as it was found —
 * but the rows are not: the opt-in path really does DROP all three tables, and
 * reactivation recreates them EMPTY. Any rules or delivery history on the
 * target database are destroyed permanently.
 *
 * It therefore refuses to run unless the operator has said so explicitly. See
 * the guard below.
 *
 * Usage:
 *   EXTONIFY_WCEP_ALLOW_DESTRUCTIVE_TESTS=1 php bin/uninstall-smoke.php --i-understand
 *
 * @package Extonify\WCEP
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( "CLI only.\n" );
}

$wcep_plugin_dir  = dirname( __DIR__ );
$wcep_plugin_file = basename( $wcep_plugin_dir ) . '/extonify-custom-emails-per-product.php';
$wcep_argv        = isset( $argv ) ? (array) $argv : array();
$wcep_acknowledged = in_array( '--i-understand', $wcep_argv, true );

$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI']    = $_SERVER['REQUEST_URI'] ?? '/wp-admin/';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}

require dirname( $wcep_plugin_dir, 3 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

global $wpdb;

// ---------------------------------------------------------------------------
// DESTRUCTIVE-TEST GUARD (Prompt 2a Item 9).
//
// This script drops production tables. Refusing loudly is the correct default,
// so it runs only when EVERY condition below holds. This box's database is not
// named with a test suffix, so the fallback branch applies: the operator must
// pass --i-understand AND the script prints the database name and a live row
// count for each table first, so an accidental run on real data is visible
// before anything is destroyed.
// ---------------------------------------------------------------------------
$wcep_opt_in = ( '1' === (string) getenv( 'EXTONIFY_WCEP_ALLOW_DESTRUCTIVE_TESTS' ) )
	|| ( defined( 'EXTONIFY_WCEP_ALLOW_DESTRUCTIVE_TESTS' ) && EXTONIFY_WCEP_ALLOW_DESTRUCTIVE_TESTS );

$wcep_env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

$wcep_db_name       = defined( 'DB_NAME' ) ? DB_NAME : '(unknown)';
$wcep_db_looks_test = (bool) preg_match( '/(_test|_tests|test_|^test$)/i', (string) $wcep_db_name );

// Full disclosure BEFORE any decision, so an accidental invocation shows the
// operator exactly what is at stake.
echo "TARGET DATABASE: {$wcep_db_name}\n";
echo 'ENVIRONMENT:     ' . $wcep_env . "\n";
echo "LIVE ROW COUNTS (all of these will be DESTROYED):\n";
$wcep_total_rows = 0;
foreach ( array( 'rules', 'deliveries', 'delivery_details' ) as $wcep_probe ) {
	$wcep_probe_table = $wpdb->prefix . 'extonify_wcep_' . $wcep_probe;
	$wcep_exists      = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wcep_probe_table ) ) ) === $wcep_probe_table;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-owned identifier built from $wpdb->prefix; not user input.
	$wcep_rows        = $wcep_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wcep_probe_table}`" ) : 0;
	$wcep_total_rows += $wcep_rows;
	printf( "  %-40s %s\n", $wcep_probe_table, $wcep_exists ? $wcep_rows . ' rows' : 'absent' );
}
echo "\n";

// The opt-in is mandatory on BOTH paths.
if ( ! $wcep_opt_in ) {
	echo "REFUSING TO RUN — this script destroys data.\n";
	echo "  - EXTONIFY_WCEP_ALLOW_DESTRUCTIVE_TESTS is not set to 1\n";
	echo "\nTo run it deliberately:\n";
	echo "  EXTONIFY_WCEP_ALLOW_DESTRUCTIVE_TESTS=1 php bin/uninstall-smoke.php --i-understand\n";
	exit( 1 );
}

// PATH A — strict: a development environment AND a database named as a test
// database. Runs with no further interaction.
$wcep_strict = in_array( $wcep_env, array( 'local', 'development' ), true ) && $wcep_db_looks_test;

if ( ! $wcep_strict ) {
	// PATH B — fallback for a box like this one, whose database is not named
	// with a test suffix and which reports the default 'production'
	// environment type. Requires the explicit flag AND a typed confirmation of
	// the database name, so muscle memory alone cannot destroy real data.
	if ( ! $wcep_acknowledged ) {
		echo "REFUSING TO RUN — the strict conditions are not met:\n";
		if ( ! in_array( $wcep_env, array( 'local', 'development' ), true ) ) {
			echo '  - WP_ENVIRONMENT_TYPE is "' . $wcep_env . '" (expected local or development)' . "\n";
		}
		if ( ! $wcep_db_looks_test ) {
			echo '  - database "' . $wcep_db_name . '" is not named as a test database' . "\n";
		}
		echo "\nPass --i-understand to proceed anyway; you will be asked to type the database name.\n";
		exit( 1 );
	}

	printf( 'Type the database name (%s) to confirm destruction, or anything else to abort: ', $wcep_db_name );
	$wcep_handle  = fopen( 'php://stdin', 'r' );
	$wcep_typed   = $wcep_handle ? trim( (string) fgets( $wcep_handle ) ) : '';
	if ( $wcep_handle ) {
		fclose( $wcep_handle );
	}
	if ( ! hash_equals( (string) $wcep_db_name, $wcep_typed ) ) {
		echo "\nAborted — the database name was not confirmed. Nothing was changed.\n";
		exit( 1 );
	}
	echo "\nConfirmed.\n\n";
}

$wcep_tables = array(
	$wpdb->prefix . 'extonify_wcep_rules',
	$wpdb->prefix . 'extonify_wcep_deliveries',
	$wpdb->prefix . 'extonify_wcep_delivery_details',
);

$wcep_options = array(
	'extonify_wcep_db_version',
	'extonify_wcep_db_error',
	'extonify_wcep_migration_lock',
	'extonify_wcep_settings',
	'extonify_wcep_remove_data_on_uninstall',
	'extonify_wcep_version',
);

/**
 * How many of the plugin tables currently exist.
 *
 * @param array $tables Table names.
 * @return int
 */
function wcep_tables_present( array $tables ) {
	global $wpdb;
	$n = 0;
	foreach ( $tables as $table ) {
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
			++$n;
		}
	}
	return $n;
}

/**
 * How many plugin options currently exist.
 *
 * @param array $options Option names.
 * @return int
 */
function wcep_options_present( array $options ) {
	$n = 0;
	foreach ( $options as $option ) {
		if ( false !== get_option( $option, false ) ) {
			++$n;
		}
	}
	return $n;
}

/**
 * Run uninstall.php exactly as WordPress would.
 *
 * @param string $plugin_dir Plugin directory.
 * @return void
 */
function wcep_run_uninstall( $plugin_dir ) {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', basename( $plugin_dir ) . '/extonify-custom-emails-per-product.php' );
	}
	require $plugin_dir . '/uninstall.php';
}

$wcep_failures = array();

// Ensure a known starting state. Deactivate first: activate_plugin() returns
// early for an already-active plugin, so without this the activation hook
// never runs and a prior run's dropped schema would be measured as "start".
deactivate_plugins( $wcep_plugin_file, true );
activate_plugin( $wcep_plugin_file );

$wcep_start_tables = wcep_tables_present( $wcep_tables );
printf( "start: %d/3 tables, %d/6 options\n\n", $wcep_start_tables, wcep_options_present( $wcep_options ) );
if ( 3 !== $wcep_start_tables ) {
	echo "Cannot establish a clean starting state — aborting.\n";
	exit( 1 );
}

// --- 1. OPT-OUT (default): uninstall must preserve everything -------------
update_option( 'extonify_wcep_remove_data_on_uninstall', 'no' );

$wcep_pid = pcntl_fork();
if ( 0 === $wcep_pid ) {
	// Child: uninstall.php returns early; nothing should change.
	wcep_run_uninstall( $wcep_plugin_dir );
	exit( 0 );
}
pcntl_waitpid( $wcep_pid, $wcep_status );

$wcep_after_optout_tables  = wcep_tables_present( $wcep_tables );
$wcep_after_optout_options = wcep_options_present( $wcep_options );
printf( "opt-out uninstall: %d/3 tables, %d/6 options\n", $wcep_after_optout_tables, $wcep_after_optout_options );
if ( 3 !== $wcep_after_optout_tables ) {
	$wcep_failures[] = 'opt-out uninstall destroyed data (tables=' . $wcep_after_optout_tables . ')';
}
echo 3 === $wcep_after_optout_tables ? "  OK — data preserved when the option is 'no'\n\n" : "  FAIL\n\n";

// --- 2. OPT-IN: uninstall must remove everything --------------------------
update_option( 'extonify_wcep_remove_data_on_uninstall', 'yes' );

$wcep_pid = pcntl_fork();
if ( 0 === $wcep_pid ) {
	wcep_run_uninstall( $wcep_plugin_dir );
	exit( 0 );
}
pcntl_waitpid( $wcep_pid, $wcep_status );

wp_cache_flush();
$wcep_after_optin_tables  = wcep_tables_present( $wcep_tables );
$wcep_after_optin_options = wcep_options_present( $wcep_options );
printf( "opt-in uninstall:  %d/3 tables, %d/6 options\n", $wcep_after_optin_tables, $wcep_after_optin_options );
if ( 0 !== $wcep_after_optin_tables ) {
	$wcep_failures[] = 'opt-in uninstall left ' . $wcep_after_optin_tables . ' table(s)';
}
if ( 0 !== $wcep_after_optin_options ) {
	$wcep_failures[] = 'opt-in uninstall left ' . $wcep_after_optin_options . ' option(s)';
}
echo ( 0 === $wcep_after_optin_tables && 0 === $wcep_after_optin_options ) ? "  OK — all tables and options removed\n\n" : "  FAIL\n\n";

// --- restore the runtime ---------------------------------------------------
// activate_plugin() returns early when the plugin is already in the active
// list, so the activation hook would never fire and the schema would stay
// dropped. Deactivate first to force a real activation.
deactivate_plugins( $wcep_plugin_file, true );
activate_plugin( $wcep_plugin_file );

$wcep_restored_tables = wcep_tables_present( $wcep_tables );
printf( "restored: %d/3 tables, db_version=%s\n\n", $wcep_restored_tables, var_export( get_option( 'extonify_wcep_db_version', '(unset)' ), true ) );
if ( 3 !== $wcep_restored_tables ) {
	$wcep_failures[] = 'restore left the runtime without its schema (tables=' . $wcep_restored_tables . ')';
}

if ( empty( $wcep_failures ) ) {
	echo "UNINSTALL SMOKE OK\n";
	exit( 0 );
}
foreach ( $wcep_failures as $wcep_failure ) {
	echo "  - $wcep_failure\n";
}
echo "UNINSTALL SMOKE FAILED\n";
exit( 1 );
