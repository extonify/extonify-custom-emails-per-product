<?php
/**
 * PHASE 0 — PREFLIGHT. NON-PRODUCTION.
 *
 * Reports the runtime, confirms WooCommerce is active and orders can be
 * created programmatically, and proves mail capture is in place. Prints
 * "PREFLIGHT PASSED" or lists blockers and stops.
 *
 * @package Extonify\WCEP\POC
 */

require __DIR__ . '/_bootstrap.php';

wcep_poc_section( 'PHASE 0 — PREFLIGHT' );

$blockers = array();

// 1. Runtime versions.
global $wp_version;
$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : ( function_exists( 'WC' ) ? WC()->version : 'unknown' );
echo "  PHP version ........... " . PHP_VERSION . "\n";
echo "  WordPress version ..... " . $wp_version . "\n";
echo "  WooCommerce version ... " . $wc_version . "\n";

// PHP 8.0–8.4 target matrix note.
$php_major_minor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
echo "  PHP target matrix ..... 8.0–8.4 (running $php_major_minor)\n";
if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	$blockers[] = 'PHP < 8.0 (target matrix is 8.0–8.4).';
}

// Disposable runtime approach.
echo "  Disposable WC runtime . live local WP+WC via wp-load.php "
	. "(same approach as the Address Book project; no wp-env needed)\n";

// 2. WooCommerce active + programmatic order creation.
wcep_poc_section( 'CHECK — WooCommerce active & wc_create_order()' );
wcep_poc_assert( 'WooCommerce class present', class_exists( 'WooCommerce' ), $wc_version );
wcep_poc_assert( 'wc_create_order() callable', function_exists( 'wc_create_order' ) );
wcep_poc_assert( 'Action Scheduler present (as_schedule_single_action)', function_exists( 'as_schedule_single_action' ) );

$probe_product = wcep_poc_make_product( 'POC Preflight Widget', 12.5 );
wcep_poc_assert( 'created a product fixture', $probe_product > 0, "product_id=$probe_product" );

$probe_order = wcep_poc_make_order( array( $probe_product => 1 ), 'pending' );
wcep_poc_assert(
	'created an order fixture with an item',
	$probe_order->get_id() > 0 && count( $probe_order->get_items() ) === 1,
	'order_id=' . $probe_order->get_id()
);

// 3. Mail capture proof.
wcep_poc_section( 'CHECK — mail capture (pre_wp_mail)' );
wcep_poc_mail_reset();
$sent = wp_mail( 'sink@example.test', 'POC preflight subject', 'POC preflight body' );
$captured = wcep_poc_mail_last();
wcep_poc_assert( 'wp_mail short-circuited to true (no real delivery)', true === $sent );
wcep_poc_assert( 'mail capture recorded the message', null !== $captured );
wcep_poc_assert(
	'captured subject matches',
	$captured && 'POC preflight subject' === $captured['subject'],
	$captured ? $captured['subject'] : 'none'
);
wcep_poc_assert(
	'captured recipient matches',
	$captured && 'sink@example.test' === $captured['to'],
	$captured ? ( is_array( $captured['to'] ) ? implode( ',', $captured['to'] ) : $captured['to'] ) : 'none'
);

// 4. Refund fixture tracking + verify-after-delete (Prompt 1a).
wcep_poc_section( 'CHECK — refund tracking + verify-after-delete cleanup' );
$refund_order = wcep_poc_make_order( array( $probe_product => 1 ), 'completed' );
$refund       = wcep_poc_make_refund( $refund_order, 3.0, 'POC preflight partial refund' );
wcep_poc_assert( 'refund fixture created and tracked', $refund instanceof WC_Order_Refund, is_wp_error( $refund ) ? $refund->get_error_message() : 'ok' );
$refund_id = $refund instanceof WC_Order_Refund ? $refund->get_id() : 0;
$order_id  = $refund_order->get_id();

// Teardown fixtures, then verify every fixture is actually gone.
wcep_poc_cleanup();
$report = $GLOBALS['wcep_poc_cleanup_report'];
wcep_poc_assert( 'cleanup verify-after-delete reported OK (no leaks)', ! empty( $report['ok'] ), 'leaks=' . implode( ',', $report['leaks'] ) );
wcep_poc_assert( 'refunded order truly gone', ! wc_get_order( $order_id ), 'order_id=' . $order_id );
wcep_poc_assert( 'refund truly gone', $refund_id && ! wc_get_order( $refund_id ) && ! get_post( $refund_id ), 'refund_id=' . $refund_id );
wcep_poc_assert( 'probe product truly gone', ! wc_get_product( $probe_product ), 'product_id=' . $probe_product );
wcep_poc_assert( 'register_shutdown_function(wcep_poc_cleanup) is wired', ! empty( $GLOBALS['wcep_poc_shutdown_wired'] ) );

wcep_poc_summary();

if ( ! empty( $blockers ) || $GLOBALS['wcep_poc_fail'] > 0 ) {
	echo "\nBLOCKERS:\n";
	foreach ( $blockers as $b ) {
		echo "  - $b\n";
	}
	echo "\nPREFLIGHT-1D FAILED — STOP.\n";
	exit( 1 );
}

echo "\nPREFLIGHT-1D PASSED\n";