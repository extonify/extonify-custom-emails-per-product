<?php
/**
 * POC-D — BATCH FINALIZATION + SINGLETON SAFETY (render slots). NON-PRODUCTION.
 *
 * Proves ADR-0005 (amended, Prompt 1c) under batching and nesting on the LIVE
 * singleton WC_Email_Customer_Completed_Order using the render-SLOT ledger:
 *  - three orders complete via ONE live singleton → three records, each against
 *    its own recorded order (never $email->object);
 *  - firing the same rule twice in one render → exactly ONE record (set dedup);
 *  - a non-WC_Order email bails cleanly (type-guard);
 *  - a nested same-singleton case finalizes both, zero stale slots.
 *
 * @package Extonify\WCEP\POC
 */

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_render-context.php';

wcep_poc_section( 'POC-D — BATCH FINALIZATION + SINGLETON SAFETY (ADR-0005 amended, Prompt 1c)' );

$matched         = wcep_poc_make_product( 'POC-D Matched Product', 40.0 );
$RULE_ID         = 404;
$NATIVE_EMAIL_ID = 'customer_completed_order';

$GLOBALS['wcep_d_records']        = array(); // oid => record
$GLOBALS['wcep_d_register_calls'] = 0;

function wcep_d_order_matches( $order ) {
	global $matched;
	if ( ! is_object( $order ) ) {
		return false;
	}
	foreach ( $order->get_items() as $item ) {
		if ( (int) $item->get_product_id() === (int) $matched ) {
			return true;
		}
	}
	return false;
}

// Registration point #1 — during render.
add_action( 'woocommerce_email_order_details', function ( $order, $sent_to_admin, $plain_text, $email = null ) use ( $RULE_ID, $NATIVE_EMAIL_ID ) {
	if ( ! is_object( $email ) || $email->id !== $NATIVE_EMAIL_ID || $sent_to_admin || wcep_rc_is_preview_current() || ! wcep_d_order_matches( $order ) ) {
		return;
	}
	++$GLOBALS['wcep_d_register_calls'];
	wcep_rc_register( $RULE_ID );
}, 7, 4 );

// Registration point #2 in the SAME render — same rule → SET dedups it.
add_action( 'woocommerce_email_before_order_table', function ( $order, $sent_to_admin, $plain_text, $email = null ) use ( $RULE_ID, $NATIVE_EMAIL_ID ) {
	if ( ! is_object( $email ) || $email->id !== $NATIVE_EMAIL_ID || $sent_to_admin || wcep_rc_is_preview_current() || ! wcep_d_order_matches( $order ) ) {
		return;
	}
	++$GLOBALS['wcep_d_register_calls'];
	wcep_rc_register( $RULE_ID ); // same rule, same render → no-op in the set.
}, 20, 4 );

// Finalization — render-slot ledger; type-guard inside wcep_rc_finalize.
add_action( 'woocommerce_email_sent', function ( $return, $id, $email ) {
	$slot = wcep_rc_finalize( $email );
	if ( null === $slot || empty( $slot['rules'] ) ) {
		return; // no eligible slot, or slot consumed with no rules.
	}
	$GLOBALS['wcep_d_records'][ $slot['order_id'] ] = array(
		'order_id' => $slot['order_id'],
		'rules'    => array_keys( $slot['rules'] ),
		'token'    => $slot['token'],
		'status'   => $return ? 'sent' : 'failed',
	);
}, 10, 3 );

// -------------------------------------------------------------------------
// BATCH: three orders complete via the LIVE singleton.
// -------------------------------------------------------------------------
wcep_poc_section( 'BATCH — 3 orders via ONE live singleton' );
$orders = array();
for ( $i = 0; $i < 3; $i++ ) {
	$orders[] = wcep_poc_make_order( array( $matched => 1 ), 'pending' );
}
$order_ids = array_map( function ( $o ) { return $o->get_id(); }, $orders );

wcep_poc_mail_reset();
foreach ( $orders as $ord ) {
	$ord->update_status( 'completed' );
}

$rec_order_ids = array_keys( $GLOBALS['wcep_d_records'] );
sort( $rec_order_ids );
$expected = $order_ids;
sort( $expected );
wcep_poc_assert( 'exactly THREE records finalized', 3 === count( $GLOBALS['wcep_d_records'] ), 'records=' . count( $GLOBALS['wcep_d_records'] ) );
wcep_poc_assert( 'records carry the THREE correct, distinct order IDs', $rec_order_ids === $expected );
$distinct_tokens = array_unique( array_map( function ( $r ) { return $r['token']; }, $GLOBALS['wcep_d_records'] ) );
wcep_poc_assert( 'each record has a DISTINCT render token', 3 === count( $distinct_tokens ) );
wcep_poc_assert( 'double-registration exercised (6 attempts for 3 orders)', 6 === $GLOBALS['wcep_d_register_calls'], 'attempts=' . $GLOBALS['wcep_d_register_calls'] );
$max_rules = 0;
foreach ( $GLOBALS['wcep_d_records'] as $r ) {
	$max_rules = max( $max_rules, count( $r['rules'] ) );
}
wcep_poc_assert( 'despite 2 registrations/render, each record holds exactly ONE rule (set dedup)', 1 === $max_rules );
wcep_poc_assert( 'zero slots remain after the batch', 0 === wcep_rc_slot_count() );

// -------------------------------------------------------------------------
// NESTED: same live singleton, inner order mid-render.
// -------------------------------------------------------------------------
wcep_poc_section( 'NESTED — same live singleton, inner order mid-render' );
$n_outer   = wcep_poc_make_order( array( $matched => 1 ), 'pending' );
$n_inner   = wcep_poc_make_order( array( $matched => 1 ), 'pending' );
$completed = WC()->mailer()->get_emails()['WC_Email_Customer_Completed_Order'];
$GLOBALS['wcep_d_nested_armed'] = true;
add_action( 'woocommerce_email_before_order_table', function ( $order, $sent_to_admin, $plain_text, $email = null ) use ( $completed, $n_inner, $n_outer ) {
	if ( empty( $GLOBALS['wcep_d_nested_armed'] ) || ! is_object( $email ) || 'customer_completed_order' !== $email->id || $order->get_id() !== $n_outer->get_id() ) {
		return;
	}
	$GLOBALS['wcep_d_nested_armed'] = false;
	$completed->trigger( $n_inner->get_id() );
	$GLOBALS['wcep_d_nested_frames'] = wcep_rc_frame_count();
}, 25, 4 );
$completed->trigger( $n_outer->get_id() );

wcep_poc_assert( 'nested: OUTER frame survived inner (one frame during outer)', 1 === (int) ( $GLOBALS['wcep_d_nested_frames'] ?? -1 ) );
wcep_poc_assert( 'nested: OUTER order finalized', isset( $GLOBALS['wcep_d_records'][ $n_outer->get_id() ] ) );
wcep_poc_assert( 'nested: INNER order finalized', isset( $GLOBALS['wcep_d_records'][ $n_inner->get_id() ] ) );
wcep_poc_assert( 'nested: finalized against DISTINCT recorded orders', $GLOBALS['wcep_d_records'][ $n_outer->get_id() ]['order_id'] !== $GLOBALS['wcep_d_records'][ $n_inner->get_id() ]['order_id'] );
wcep_poc_assert( 'nested: zero slots remain', 0 === wcep_rc_slot_count() );

// -------------------------------------------------------------------------
// TYPE-GUARD — non-WC_Order email object bails cleanly.
// -------------------------------------------------------------------------
wcep_poc_section( 'TYPE-GUARD — non-WC_Order email object bails' );
$records_before = count( $GLOBALS['wcep_d_records'] );
$fake           = new stdClass();
$fake->id       = 'customer_note';
$fake->object   = new stdClass(); // NOT a WC_Order.
$threw = null;
$r     = 'sentinel';
try {
	$r = wcep_rc_finalize( $fake );
} catch ( \Throwable $t ) {
	$threw = $t;
}
wcep_poc_assert( 'type-guard: did NOT throw', null === $threw );
wcep_poc_assert( 'type-guard: returned null (bailed)', null === $r );
wcep_poc_assert( 'type-guard: nothing finalized', count( $GLOBALS['wcep_d_records'] ) === $records_before );

// Report.
wcep_poc_section( 'POC-D RECORDS' );
printf( "  %-10s | %-8s | %-6s | %s\n", 'ORDER', 'RULES', 'STATUS', 'RENDER TOKEN' );
printf( "  %s\n", str_repeat( '-', 56 ) );
foreach ( $GLOBALS['wcep_d_records'] as $r ) {
	printf( "  %-10s | %-8s | %-6s | %s\n", $r['order_id'], implode( ',', $r['rules'] ), $r['status'], $r['token'] );
}

wcep_poc_cleanup();
wcep_poc_assert( 'cleanup verify-after-delete OK — no fixture leak', ! empty( $GLOBALS['wcep_poc_cleanup_report']['ok'] ), 'leaks=' . implode( ',', $GLOBALS['wcep_poc_cleanup_report']['leaks'] ) );
wcep_poc_summary();
echo "\nPOC-D OK\n";