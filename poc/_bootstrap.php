<?php
/**
 * POC BOOTSTRAP — NON-PRODUCTION.
 *
 * Shared harness for the Extonify Custom Emails Per Product architecture
 * proof-of-concept scripts (Prompt 1, Phase 0/2). This file is a throwaway
 * test rig: it is deliberately procedural and is NOT part of the shipped
 * plugin. Nothing here defines production classes.
 *
 * Runtime approach mirrors the sibling Address Book project: there is no
 * wp-env here, so we boot the live local WordPress + WooCommerce install by
 * requiring wp-load.php and create/clean our own fixtures.
 *
 * @package Extonify\WCEP\POC
 */

// CLI only — refuse to run inside a web request.
if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( "POC harness is CLI-only.\n" );
}

// ---------------------------------------------------------------------------
// Boot the live WordPress + WooCommerce install.
// poc/ is one level under the plugin dir, so the WP root is 4 dirnames up
// (…/extonify/wp-content/plugins/<plugin>/poc  ->  …/extonify).
// ---------------------------------------------------------------------------
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}

$wcep_wp_root = dirname( __DIR__, 4 );
require $wcep_wp_root . '/wp-load.php';

if ( ! class_exists( 'WooCommerce' ) ) {
	fwrite( STDERR, "WooCommerce must be active to run the POC harness.\n" );
	exit( 1 );
}
if ( ! function_exists( 'wc_create_order' ) ) {
	fwrite( STDERR, "wc_create_order() unavailable — WooCommerce not fully loaded.\n" );
	exit( 1 );
}

// Load the WC_Email base class WITHOUT initialising the mailer's email
// collection, so a POC can register via woocommerce_email_classes BEFORE the
// first WC()->mailer() call and prove the class lands in get_emails()
// (ADR-0002 amended: registration timing). POCs that need the core emails call
// WC()->mailer() themselves — it lazy-initialises on first use.
if ( ! class_exists( 'WC_Email', false ) ) {
	require_once WC_ABSPATH . 'includes/emails/class-wc-email.php';
}

// ---------------------------------------------------------------------------
// Assertion + reporting primitives.
// ---------------------------------------------------------------------------
$GLOBALS['wcep_poc_pass']    = 0;
$GLOBALS['wcep_poc_fail']    = 0;
$GLOBALS['wcep_poc_started'] = microtime( true );

/**
 * Print a section banner.
 *
 * @param string $name Section title.
 */
function wcep_poc_section( $name ) {
	echo "\n" . str_repeat( '=', 72 ) . "\n";
	echo $name . "\n";
	echo str_repeat( '=', 72 ) . "\n";
}

/**
 * Real assertion. On failure: print detail and STOP the whole run (exit 1),
 * honouring "do not proceed past a failed assertion".
 *
 * @param string $label  What is being asserted.
 * @param bool   $cond   Result of the check.
 * @param string $detail Extra context printed on failure (and short pass note).
 */
function wcep_poc_assert( $label, $cond, $detail = '' ) {
	if ( $cond ) {
		++$GLOBALS['wcep_poc_pass'];
		echo '  [PASS] ' . $label . ( '' !== $detail ? '  (' . $detail . ')' : '' ) . "\n";
		return;
	}
	++$GLOBALS['wcep_poc_fail'];
	echo '  [FAIL] ' . $label . "\n";
	if ( '' !== $detail ) {
		echo '         detail: ' . $detail . "\n";
	}
	echo "\n>>> ASSERTION FAILED — STOPPING. Do not proceed past a failed assertion.\n";
	wcep_poc_cleanup();
	exit( 1 );
}

/**
 * Print the tally. Callers decide whether to print the POC PASSED banner.
 */
function wcep_poc_summary() {
	printf(
		"\n-- assertions: %d passed, %d failed --\n",
		(int) $GLOBALS['wcep_poc_pass'],
		(int) $GLOBALS['wcep_poc_fail']
	);
}

// ---------------------------------------------------------------------------
// Mail capture — intercept wp_mail so POCs can inspect outgoing email without
// real delivery. pre_wp_mail returning non-null short-circuits wp_mail; we
// return true so WC_Email::send() sees success and woocommerce_email_sent
// fires with a boolean true result.
// ---------------------------------------------------------------------------
$GLOBALS['wcep_poc_mail_log'] = array();

add_filter(
	'pre_wp_mail',
	function ( $short_circuit, $atts ) {
		// Respect an earlier filter's decision (e.g. a POC forcing a send to
		// fail). Only capture + simulate success when nothing overrode us.
		if ( null !== $short_circuit ) {
			return $short_circuit;
		}
		$GLOBALS['wcep_poc_mail_log'][] = array(
			'to'          => $atts['to'] ?? '',
			'subject'     => $atts['subject'] ?? '',
			'message'     => $atts['message'] ?? '',
			'headers'     => $atts['headers'] ?? '',
			'attachments' => $atts['attachments'] ?? array(),
			'captured_at' => microtime( true ),
		);
		return true; // Simulate a successful send; nothing leaves the box.
	},
	10,
	2
);

/**
 * Reset the captured mail log (call at the start of a POC step).
 */
function wcep_poc_mail_reset() {
	$GLOBALS['wcep_poc_mail_log'] = array();
}

/**
 * @return array<int,array<string,mixed>> Captured mail entries.
 */
function wcep_poc_mail_all() {
	return $GLOBALS['wcep_poc_mail_log'];
}

/**
 * @return array<string,mixed>|null Last captured mail entry, or null.
 */
function wcep_poc_mail_last() {
	$log = $GLOBALS['wcep_poc_mail_log'];
	return empty( $log ) ? null : end( $log );
}

// ---------------------------------------------------------------------------
// Fixture tracking + cleanup. Everything we create is registered here and torn
// down at the end (or on assertion failure) so the live DB is left clean.
// ---------------------------------------------------------------------------
$GLOBALS['wcep_poc_fixture_orders']   = array();
$GLOBALS['wcep_poc_fixture_products'] = array();
$GLOBALS['wcep_poc_fixture_refunds']  = array();
$GLOBALS['wcep_poc_poc_tables']       = array();
$GLOBALS['wcep_poc_cleanup_report']   = array( 'total' => 0, 'leaks' => array(), 'ok' => true );

/**
 * Create a simple product fixture.
 *
 * @param string $name  Product name.
 * @param float  $price Regular price.
 * @return int Product ID.
 */
function wcep_poc_make_product( $name, $price = 10.0 ) {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_regular_price( (string) $price );
	$product->set_price( (string) $price );
	$product->set_status( 'publish' );
	$id = $product->save();
	$GLOBALS['wcep_poc_fixture_products'][] = $id;
	return (int) $id;
}

/**
 * Create an order fixture containing the given products.
 *
 * @param array<int,int> $product_qty Map of product_id => qty.
 * @param string         $status      Initial status (default 'pending').
 * @param string         $email       Billing email.
 * @return WC_Order
 */
function wcep_poc_make_order( array $product_qty, $status = 'pending', $email = 'poc-buyer@example.test' ) {
	// Create as a plain draft first, attach items, THEN move to the requested
	// status so status-change triggers see the line items — exactly as real
	// checkout/admin flows do. (Passing a status straight to wc_create_order()
	// fires the transition *before* items are added; see Phase 3 notes.)
	$order = wc_create_order();
	foreach ( $product_qty as $pid => $qty ) {
		$order->add_product( wc_get_product( $pid ), $qty );
	}
	$order->set_billing_email( $email );
	$order->set_billing_first_name( 'Poc' );
	$order->set_billing_last_name( 'Buyer' );
	$order->calculate_totals();
	$order->save();
	$GLOBALS['wcep_poc_fixture_orders'][] = $order->get_id();

	if ( 'pending' !== $status && $order->get_status() !== $status ) {
		$order->update_status( $status ); // meaningful transition, items present.
	}
	return $order;
}

/**
 * Create a refund fixture against an order and track it for teardown.
 *
 * @param WC_Order $order  Parent order.
 * @param float    $amount Refund amount.
 * @param string   $reason Refund reason.
 * @return WC_Order_Refund|WP_Error
 */
function wcep_poc_make_refund( $order, $amount, $reason = 'POC refund' ) {
	$refund = wc_create_refund(
		array(
			'order_id' => $order->get_id(),
			'amount'   => $amount,
			'reason'   => $reason,
		)
	);
	if ( $refund instanceof WC_Order_Refund ) {
		$GLOBALS['wcep_poc_fixture_refunds'][] = $refund->get_id();
	}
	return $refund;
}

/**
 * Register a POC-only DB table name for teardown.
 *
 * @param string $table Fully-qualified table name.
 */
function wcep_poc_track_table( $table ) {
	$GLOBALS['wcep_poc_poc_tables'][] = $table;
}

/**
 * Tear down every fixture we created, then VERIFY each is gone (verify-after-
 * delete). Prints the verification. Idempotent and re-entrancy-safe; also wired
 * to register_shutdown_function() as a safety net for paths that skip the
 * explicit call (e.g. a fatal error).
 */
function wcep_poc_cleanup() {
	global $wpdb;
	static $running = false;
	if ( $running ) {
		return;
	}
	$running = true;

	$orders   = array_values( array_unique( $GLOBALS['wcep_poc_fixture_orders'] ) );
	$refunds  = array_values( array_unique( $GLOBALS['wcep_poc_fixture_refunds'] ) );
	$products = array_values( array_unique( $GLOBALS['wcep_poc_fixture_products'] ) );
	$tables   = array_values( array_unique( $GLOBALS['wcep_poc_poc_tables'] ) );

	if ( ! $orders && ! $refunds && ! $products && ! $tables ) {
		$running = false;
		return; // nothing to clean (e.g. redundant shutdown call).
	}

	// Delete refunds (order children) first, then orders, products, tables.
	foreach ( $refunds as $rid ) {
		$r = wc_get_order( $rid );
		if ( $r ) {
			$r->delete( true );
		} else {
			wp_delete_post( $rid, true );
		}
	}
	foreach ( $orders as $oid ) {
		$o = wc_get_order( $oid );
		if ( $o ) {
			$o->delete( true );
		}
	}
	foreach ( $products as $pid ) {
		$p = wc_get_product( $pid );
		if ( $p ) {
			$p->delete( true );
		}
	}
	foreach ( $tables as $table ) {
		// POC tables only — names are built from $wpdb->prefix + a poc marker.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
	}

	// VERIFY-AFTER-DELETE — assert each fixture no longer exists.
	$leaks = array();
	foreach ( $refunds as $rid ) {
		if ( wc_get_order( $rid ) || get_post( $rid ) ) {
			$leaks[] = "refund:$rid";
		}
	}
	foreach ( $orders as $oid ) {
		if ( wc_get_order( $oid ) ) {
			$leaks[] = "order:$oid";
		}
	}
	foreach ( $products as $pid ) {
		if ( wc_get_product( $pid ) ) {
			$leaks[] = "product:$pid";
		}
	}
	foreach ( $tables as $table ) {
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			$leaks[] = "table:$table";
		}
	}

	$GLOBALS['wcep_poc_cleanup_report'] = array(
		'total' => count( $orders ) + count( $refunds ) + count( $products ) + count( $tables ),
		'leaks' => $leaks,
		'ok'    => empty( $leaks ),
	);

	if ( empty( $leaks ) ) {
		printf(
			"  [cleanup] verify-after-delete OK: removed & confirmed gone — %d orders, %d refunds, %d products, %d tables\n",
			count( $orders ),
			count( $refunds ),
			count( $products ),
			count( $tables )
		);
	} else {
		printf(
			"  [cleanup] LEAK — %d fixture(s) still exist after delete: %s\n",
			count( $leaks ),
			implode( ', ', $leaks )
		);
	}

	$GLOBALS['wcep_poc_fixture_orders']   = array();
	$GLOBALS['wcep_poc_fixture_refunds']  = array();
	$GLOBALS['wcep_poc_fixture_products'] = array();
	$GLOBALS['wcep_poc_poc_tables']       = array();
	$running = false;
}

// Safety net: guarantee teardown even if a POC exits via a fatal error before
// its explicit wcep_poc_cleanup() call.
register_shutdown_function( 'wcep_poc_cleanup' );
$GLOBALS['wcep_poc_shutdown_wired'] = true;

// ---------------------------------------------------------------------------
// debug.log tail helpers — used by POC-C negative gates. read-only.
// ---------------------------------------------------------------------------
/**
 * @return string Absolute path to wp-content/debug.log.
 */
function wcep_poc_debug_log_path() {
	return dirname( __DIR__, 3 ) . '/debug.log';
}

/**
 * @return int Current byte length of debug.log (0 if absent).
 */
function wcep_poc_debug_log_size() {
	$path = wcep_poc_debug_log_path();
	return file_exists( $path ) ? (int) filesize( $path ) : 0;
}

/**
 * Return debug.log content written since a byte offset.
 *
 * @param int $since_bytes Offset captured earlier via wcep_poc_debug_log_size().
 * @return string New log content (may be empty).
 */
function wcep_poc_debug_log_since( $since_bytes ) {
	$path = wcep_poc_debug_log_path();
	if ( ! file_exists( $path ) ) {
		return '';
	}
	$fh = fopen( $path, 'rb' );
	if ( ! $fh ) {
		return '';
	}
	fseek( $fh, max( 0, (int) $since_bytes ) );
	$data = stream_get_contents( $fh );
	fclose( $fh );
	return (string) $data;
}