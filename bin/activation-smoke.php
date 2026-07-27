<?php
/**
 * Activation / deactivation smoke test. NON-PRODUCTION (excluded from the zip).
 *
 * Activates and deactivates the plugin against the live local WordPress +
 * WooCommerce install with every diagnostic turned on, and fails when any
 * notice, warning or deprecation is attributable to THIS plugin.
 *
 * Usage:  php bin/activation-smoke.php [activate|deactivate|cycle]
 *
 * @package Extonify\WCEP
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( "CLI only.\n" );
}

error_reporting( E_ALL );
ini_set( 'display_errors', '1' ); // phpcs:ignore

$wcep_plugin_dir  = dirname( __DIR__ );
$wcep_plugin_file = basename( $wcep_plugin_dir ) . '/extonify-custom-emails-per-product.php';
$wcep_mode        = $argv[1] ?? 'cycle';

$GLOBALS['wcep_smoke_diags'] = array();
set_error_handler(
	function ( $errno, $errstr, $errfile, $errline ) use ( $wcep_plugin_dir ) {
		if ( false !== strpos( (string) $errfile, $wcep_plugin_dir )
			&& false === strpos( (string) $errfile, $wcep_plugin_dir . '/vendor' ) ) {
			$GLOBALS['wcep_smoke_diags'][] = sprintf( '[%d] %s in %s:%d', $errno, $errstr, $errfile, $errline );
		}
		return false;
	}
);

$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SERVER_NAME']    = $_SERVER['SERVER_NAME'] ?? 'localhost';
$_SERVER['REQUEST_URI']    = $_SERVER['REQUEST_URI'] ?? '/wp-admin/';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}

require dirname( $wcep_plugin_dir, 3 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

echo "plugin: {$wcep_plugin_file}\n";
echo 'mode:   ' . $wcep_mode . "\n\n";

/**
 * Report the current schema state.
 *
 * @param string $label Stage label.
 * @return void
 */
function wcep_smoke_report( $label ) {
	global $wpdb;
	$version = get_option( 'extonify_wcep_db_version', '(unset)' );
	echo "  [$label] db_version=" . var_export( $version, true ) . "\n";
	foreach ( array( 'rules', 'deliveries', 'delivery_details' ) as $name ) {
		$table = $wpdb->prefix . 'extonify_wcep_' . $name;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		echo '           ' . str_pad( $table, 40 ) . ( $found === $table ? 'EXISTS' : 'MISSING' ) . "\n";
	}
	$error = get_option( 'extonify_wcep_db_error', '' );
	if ( '' !== $error ) {
		echo "           db_error=$error\n";
	}
}

$wcep_exit = 0;

if ( in_array( $wcep_mode, array( 'activate', 'cycle' ), true ) ) {
	// activate_plugin() returns early for a plugin already in the active list,
	// so the activation hook would never fire and this would silently report
	// "OK" while creating nothing. Deactivate first to force a REAL activation.
	deactivate_plugins( $wcep_plugin_file, true );
	$result = activate_plugin( $wcep_plugin_file );
	if ( is_wp_error( $result ) ) {
		echo 'ACTIVATION FAILED: ' . $result->get_error_message() . "\n";
		$wcep_exit = 1;
	} else {
		echo "activate_plugin(): OK\n";
		wcep_smoke_report( 'after activate' );
	}
}

if ( 'cycle' === $wcep_mode ) {
	// Prove reactivation is idempotent: run it a second time.
	deactivate_plugins( $wcep_plugin_file, true );
	$result = activate_plugin( $wcep_plugin_file );
	echo 'reactivation: ' . ( is_wp_error( $result ) ? 'FAILED' : 'OK (idempotent)' ) . "\n";
	wcep_smoke_report( 'after reactivate' );
}

if ( 'deactivate' === $wcep_mode ) {
	deactivate_plugins( $wcep_plugin_file );
	echo "deactivate_plugins(): OK\n";
	wcep_smoke_report( 'after deactivate' );
}

restore_error_handler();

echo "\n";
if ( empty( $GLOBALS['wcep_smoke_diags'] ) ) {
	echo "DIAGNOSTICS: none attributable to this plugin\n";
} else {
	echo 'DIAGNOSTICS: ' . count( $GLOBALS['wcep_smoke_diags'] ) . " from this plugin\n";
	foreach ( $GLOBALS['wcep_smoke_diags'] as $d ) {
		echo "  - $d\n";
	}
	$wcep_exit = 1;
}

echo 0 === $wcep_exit ? "SMOKE OK\n" : "SMOKE FAILED\n";
exit( $wcep_exit );
