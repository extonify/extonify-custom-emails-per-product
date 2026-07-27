<?php
/**
 * POC-C — INSERT POSITIONS + RENDER-SLOT LEDGER + PREVIEW/CORRELATION.
 * NON-PRODUCTION.
 *
 * Proves ADR-0003 + ADR-0005 (amended, Prompt 1d) on LIVE mailer singletons:
 *  - state-gated render SLOTS (rendering → awaiting_send → in_flight → resolved):
 *    an inner send can never consume the outer render's slot;
 *  - unmatched nested send makes no record and consumes only its own slot;
 *  - inner send FAILURE never marks the outer as failed;
 *  - a render without a send orphans its own slot (unresolved at shutdown) and is
 *    never consumed by a later send;
 *  - BLOCKER 1 (1d): a preview render creates a context frame but NO delivery
 *    slot; a successful AND an interrupted preview both leave zero residue —
 *    frames, open order-details/footer tokens, slots, markers and unresolved
 *    records all return to zero with no manual clearing;
 *  - BLOCKER 2 (1d): the send is bound at woocommerce_mail_callback_params and
 *    finalization resolves EXACTLY that token — a second render of the same live
 *    singleton mid-send cannot steal the attribution;
 *  - tuple-validated pop/cleanup: a foreign token removes no frame + records a
 *    mismatch;
 *  - order identity is NEVER read from $email->object.
 *
 * @package Extonify\WCEP\POC
 */

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_render-context.php';

wcep_poc_section( 'POC-C — RENDER-SLOT LEDGER + PREVIEW/CORRELATION (ADR-0003/0005 amended, Prompt 1d)' );

// Evidence of what the REAL shutdown handler recorded (the module's own shutdown
// callback is registered first, so it has already run when this fires).
register_shutdown_function( function () {
	$tokens  = array();
	foreach ( $GLOBALS['wcep_rc_unresolved'] as $u ) {
		$tokens[] = $u['token'] . '(' . $u['state'] . ',order=' . $u['order_id'] . ')';
	}
	$leaked = wcep_rc_unresolved_preview_tokens();
	echo "\n  [shutdown] unresolved records: " . ( empty( $tokens ) ? 'NONE' : implode( ' ', $tokens ) ) . "\n";
	echo '  [shutdown] preview tokens issued: ' . wcep_rc_preview_token_count()
		. ' · preview tokens present in unresolved: ' . ( empty( $leaked ) ? 'NONE' : implode( ',', $leaked ) ) . "\n";
	echo '  [shutdown] stale preview frames reconciled during the run: ' . wcep_rc_reconciled_count()
		. ' · frames left: ' . wcep_rc_frame_count() . "\n";
} );

$matched   = wcep_poc_make_product( 'POC-C Matched Product', 30.0 );
$unmatched = wcep_poc_make_product( 'POC-C Unmatched Product', 15.0 );
$order     = wcep_poc_make_order( array( $matched => 1, $unmatched => 2 ), 'pending' );

$RULE     = array( 'native_email' => 'customer_processing_order', 'audience' => 'customer', 'matched' => $matched );
$RULE_ID  = 303;
$RULE_C   = array( 'native_email' => 'customer_completed_order', 'audience' => 'customer', 'matched' => $matched );
$RULE_C_ID = 313;
$GLOBALS['wcep_c_completed_orders'] = array(); // whitelist for the completed rule.

$MARK_BEFORE   = '[[WCEP-BEFORE-TABLE]]';
$MARK_AFTER    = '[[WCEP-AFTER-TABLE]]';
$MARK_META     = '[[WCEP-ORDER-META]]';
$MARK_CUSTOMER = '[[WCEP-CUSTOMER-DETAILS]]';
$mark_item     = function ( $product_id ) { return "[[WCEP-ITEM:$product_id]]"; };

// Controllable send-failure (for inner-fails-outer-succeeds).
$GLOBALS['wcep_c_fail_recipient'] = null;
add_filter( 'pre_wp_mail', function ( $sc, $atts ) {
	if ( ! empty( $GLOBALS['wcep_c_fail_recipient'] ) ) {
		$to = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'];
		if ( false !== strpos( $to, $GLOBALS['wcep_c_fail_recipient'] ) ) {
			return false; // force this send to report failure.
		}
	}
	return $sc; // else continue to the bootstrap capture.
}, 1, 2 );

function wcep_c_order_has_matched( $order, $rule ) {
	if ( ! is_object( $order ) ) {
		return false;
	}
	foreach ( $order->get_items() as $item ) {
		if ( (int) $item->get_product_id() === (int) $rule['matched'] ) {
			return true;
		}
	}
	return false;
}
function wcep_c_should_inject_email_only( $email, $order, $sent_to_admin, $rule ) {
	if ( ! is_object( $email ) || $email->id !== $rule['native_email'] ) {
		return false;
	}
	if ( ! wcep_c_order_has_matched( $order, $rule ) ) {
		return false;
	}
	if ( 'customer' === $rule['audience'] && $sent_to_admin ) {
		return false;
	}
	if ( 'admin' === $rule['audience'] && ! $sent_to_admin ) {
		return false;
	}
	return true;
}

// EMAIL-ONLY injection (validate id/order/audience).
add_action( 'woocommerce_email_before_order_table', function ( $order, $sent_to_admin, $plain_text, $email = null ) use ( $MARK_BEFORE, $RULE ) {
	if ( wcep_c_should_inject_email_only( $email, $order, $sent_to_admin, $RULE ) ) { echo $MARK_BEFORE; } // phpcs:ignore
}, 20, 4 );
add_action( 'woocommerce_email_after_order_table', function ( $order, $sent_to_admin, $plain_text, $email = null ) use ( $MARK_AFTER, $RULE ) {
	if ( wcep_c_should_inject_email_only( $email, $order, $sent_to_admin, $RULE ) ) { echo $MARK_AFTER; } // phpcs:ignore
}, 20, 4 );
add_action( 'woocommerce_email_order_meta', function ( $order, $sent_to_admin, $plain_text, $email = null ) use ( $MARK_META, $RULE ) {
	if ( wcep_c_should_inject_email_only( $email, $order, $sent_to_admin, $RULE ) ) { echo $MARK_META; } // phpcs:ignore
}, 20, 4 );
add_action( 'woocommerce_email_customer_details', function ( $order, $sent_to_admin, $plain_text, $email = null ) use ( $MARK_CUSTOMER, $RULE ) {
	if ( wcep_c_should_inject_email_only( $email, $order, $sent_to_admin, $RULE ) ) { echo $MARK_CUSTOMER; } // phpcs:ignore
}, 20, 4 );

// SHARED item hook (stack-gated via top frame; optional 4th param; matched item).
add_action( 'woocommerce_order_item_meta_end', function ( $item_id, $item, $order, $plain_text = false ) use ( $matched, $mark_item, $RULE ) {
	$top = wcep_rc_top();
	if ( ! $top || $top['email_id'] !== $RULE['native_email'] || ( 'customer' === $RULE['audience'] && $top['admin'] ) ) {
		return;
	}
	if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) || (int) $item->get_product_id() !== (int) $matched ) {
		return;
	}
	echo $mark_item( $matched ); // phpcs:ignore
}, 20, 4 );

// --- registration (reads is_preview from the RENDER FRAME) ------------------
$GLOBALS['wcep_c_reg_token']    = array();
$GLOBALS['wcep_c_final_count']  = array();
$GLOBALS['wcep_c_final_tokens'] = array();
$GLOBALS['wcep_c_final_status'] = array();
$GLOBALS['wcep_c_total_final']  = 0;
$GLOBALS['wcep_c_saw_preview']  = false;

add_action( 'woocommerce_email_order_details', function ( $order, $sent_to_admin, $plain_text, $email = null ) use ( $RULE, $RULE_ID ) {
	if ( ! is_object( $email ) || $email->id !== $RULE['native_email'] ) {
		return;
	}
	if ( wcep_rc_is_preview_current() ) {          // render-scoped preview flag.
		$GLOBALS['wcep_c_saw_preview'] = true;
		return;
	}
	if ( ! wcep_c_order_has_matched( $order, $RULE ) || $sent_to_admin ) {
		return;
	}
	wcep_rc_register( $RULE_ID );
	$GLOBALS['wcep_c_reg_token'][ $order->get_id() ] = wcep_rc_current_token();
}, 7, 4 );

// completed rule — whitelisted to the interrupted-preview test order only.
add_action( 'woocommerce_email_order_details', function ( $order, $sent_to_admin, $plain_text, $email = null ) use ( $RULE_C, $RULE_C_ID ) {
	if ( ! is_object( $email ) || $email->id !== $RULE_C['native_email'] || $sent_to_admin ) {
		return;
	}
	if ( ! in_array( $order->get_id(), $GLOBALS['wcep_c_completed_orders'], true ) ) {
		return;
	}
	if ( wcep_rc_is_preview_current() ) {
		return;
	}
	if ( ! wcep_c_order_has_matched( $order, $RULE_C ) ) {
		return;
	}
	wcep_rc_register( $RULE_C_ID );
	$GLOBALS['wcep_c_reg_token'][ $order->get_id() ] = wcep_rc_current_token();
}, 7, 4 );

// finalize — records only when the resolved slot carries rules.
add_action( 'woocommerce_email_sent', function ( $return, $id, $email ) {
	$slot = wcep_rc_finalize( $email );
	if ( null === $slot ) {
		return; // no eligible slot → bail.
	}
	if ( empty( $slot['rules'] ) ) {
		return; // slot consumed, but no delivery record.
	}
	$oid = $slot['order_id'];
	$GLOBALS['wcep_c_final_count'][ $oid ]    = ( $GLOBALS['wcep_c_final_count'][ $oid ] ?? 0 ) + 1;
	$GLOBALS['wcep_c_final_tokens'][ $oid ][] = $slot['token'];
	$GLOBALS['wcep_c_final_status'][ $oid ]   = $return ? 'sent' : 'failed';
	++$GLOBALS['wcep_c_total_final'];
}, 10, 3 );

/** Trigger a LIVE native email (no clone); temporarily set output format. */
function wcep_c_trigger( $class, $order, $type = 'html' ) {
	wcep_poc_mail_reset();
	$e             = WC()->mailer()->get_emails()[ $class ];
	$saved_type    = $e->email_type;
	$e->email_type = $type;
	$e->trigger( $order->get_id() );
	$e->email_type = $saved_type;
	$m = wcep_poc_mail_last();
	return $m ? $m['message'] : '';
}

$has_any_marker = function ( $body ) use ( $MARK_BEFORE, $MARK_AFTER, $MARK_META, $MARK_CUSTOMER, $mark_item, $matched ) {
	return false !== strpos( $body, $MARK_BEFORE ) || false !== strpos( $body, $MARK_AFTER )
		|| false !== strpos( $body, $MARK_META ) || false !== strpos( $body, $MARK_CUSTOMER )
		|| false !== strpos( $body, $mark_item( $matched ) );
};

// =========================================================================
// POSITIVE — HTML + PLAIN.
// =========================================================================
wcep_poc_section( 'POSITIVE — customer processing email (HTML + PLAIN), live object' );
foreach ( array( 'html', 'plain' ) as $type ) {
	$body = wcep_c_trigger( 'WC_Email_Customer_Processing_Order', $order, $type );
	$u    = strtoupper( $type );
	wcep_poc_assert( "$u: all 5 markers present, matched-item only", false !== strpos( $body, $MARK_BEFORE ) && false !== strpos( $body, $MARK_AFTER ) && false !== strpos( $body, $MARK_META ) && false !== strpos( $body, $MARK_CUSTOMER ) && false !== strpos( $body, $mark_item( $matched ) ) && false === strpos( $body, $mark_item( $unmatched ) ) );
}
wcep_poc_assert( 'two real sends finalized 2 records', 2 === ( $GLOBALS['wcep_c_final_count'][ $order->get_id() ] ?? 0 ) );
wcep_poc_assert( 'zero slots remain after the positive sends', 0 === wcep_rc_slot_count() );

// =========================================================================
// NEGATIVE ISOLATION.
// =========================================================================
wcep_poc_section( 'NEGATIVE ISOLATION' );
wcep_poc_assert( 'rule emits NOTHING in Admin New Order', ! $has_any_marker( wcep_c_trigger( 'WC_Email_New_Order', $order, 'html' ) ) );
wcep_poc_assert( 'rule emits NOTHING in Customer Completed', ! $has_any_marker( wcep_c_trigger( 'WC_Email_Customer_Completed_Order', $order, 'html' ) ) );
$other_order = wcep_poc_make_order( array( $unmatched => 1 ), 'pending' );
wcep_poc_assert( 'rule emits NOTHING in processing email for a different order lacking the product', ! $has_any_marker( wcep_c_trigger( 'WC_Email_Customer_Processing_Order', $other_order, 'html' ) ) );
wcep_poc_assert( 'no slots remain after negative isolation', 0 === wcep_rc_slot_count() );

// =========================================================================
// NESTED — same live singleton, two matched orders (1b regression).
// =========================================================================
wcep_poc_section( 'NESTED — same live singleton, two matched orders' );
$n_outer = wcep_poc_make_order( array( $matched => 1 ), 'pending' );
$n_inner = wcep_poc_make_order( array( $matched => 1 ), 'pending' );
$proc    = WC()->mailer()->get_emails()['WC_Email_Customer_Processing_Order'];
$GLOBALS['wcep_c_nested'] = array( 'armed' => true, 'frames_after_inner' => null, 'top_oid_after_inner' => null, 'outer_token' => null );
add_action( 'woocommerce_email_before_order_table', function ( $order, $sent_to_admin, $plain_text, $email = null ) use ( $proc, $n_inner, $n_outer ) {
	if ( empty( $GLOBALS['wcep_c_nested']['armed'] ) || ! is_object( $email ) || 'customer_processing_order' !== $email->id || $order->get_id() !== $n_outer->get_id() ) {
		return;
	}
	$GLOBALS['wcep_c_nested']['armed']       = false;
	$GLOBALS['wcep_c_nested']['outer_token'] = wcep_rc_current_token();
	$proc->trigger( $n_inner->get_id() );
	$GLOBALS['wcep_c_nested']['frames_after_inner']  = wcep_rc_frame_count();
	$top                                             = wcep_rc_top();
	$GLOBALS['wcep_c_nested']['top_oid_after_inner'] = $top ? $top['oid'] : null;
}, 30, 4 );
wcep_poc_mail_reset();
$proc->trigger( $n_outer->get_id() );
$n = $GLOBALS['wcep_c_nested'];
wcep_poc_assert( 'nested: OUTER frame survived inner (one frame, the outer)', 1 === (int) $n['frames_after_inner'] && (int) $n['top_oid_after_inner'] === $n_outer->get_id() );
wcep_poc_assert( 'nested: OUTER + INNER each finalized exactly once', 1 === ( $GLOBALS['wcep_c_final_count'][ $n_outer->get_id() ] ?? 0 ) && 1 === ( $GLOBALS['wcep_c_final_count'][ $n_inner->get_id() ] ?? 0 ) );
wcep_poc_assert( 'nested: no cross-finalization + outer token unchanged', array( $n['outer_token'] ) === ( $GLOBALS['wcep_c_final_tokens'][ $n_outer->get_id() ] ?? array() ) && $n['outer_token'] === $GLOBALS['wcep_c_reg_token'][ $n_outer->get_id() ] );
wcep_poc_assert( 'nested: zero slots remain', 0 === wcep_rc_slot_count() );

// =========================================================================
// BLOCKER 1 · TEST 1 — UNMATCHED nested send must not consume the outer slot.
// =========================================================================
wcep_poc_section( 'BLOCKER 1 · unmatched nested send' );
$u_outer = wcep_poc_make_order( array( $matched => 1 ), 'pending' );   // matches
$u_inner = wcep_poc_make_order( array( $unmatched => 1 ), 'pending' ); // matches NOTHING
$GLOBALS['wcep_c_um'] = array( 'armed' => true, 'slots_after_inner' => null, 'outer_final_after_inner' => null );
add_action( 'woocommerce_email_before_order_table', function ( $order, $sent_to_admin, $plain_text, $email = null ) use ( $proc, $u_inner, $u_outer ) {
	if ( empty( $GLOBALS['wcep_c_um']['armed'] ) || ! is_object( $email ) || 'customer_processing_order' !== $email->id || $order->get_id() !== $u_outer->get_id() ) {
		return;
	}
	$GLOBALS['wcep_c_um']['armed'] = false;
	$proc->trigger( $u_inner->get_id() ); // unmatched inner on the SAME singleton.
	$GLOBALS['wcep_c_um']['slots_after_inner']       = wcep_rc_slot_count();
	$GLOBALS['wcep_c_um']['outer_final_after_inner'] = $GLOBALS['wcep_c_final_count'][ $u_outer->get_id() ] ?? 0;
}, 30, 4 );
wcep_poc_mail_reset();
$proc->trigger( $u_outer->get_id() );
$um = $GLOBALS['wcep_c_um'];
wcep_poc_assert( 'unmatched inner created NO delivery record', 0 === ( $GLOBALS['wcep_c_final_count'][ $u_inner->get_id() ] ?? 0 ) );
wcep_poc_assert( 'unmatched inner did NOT consume the outer slot (outer slot still present after inner)', 1 === (int) $um['slots_after_inner'] && 0 === (int) $um['outer_final_after_inner'] );
wcep_poc_assert( 'outer rule finalized ONCE, only on the outer send', 1 === ( $GLOBALS['wcep_c_final_count'][ $u_outer->get_id() ] ?? 0 ) );
wcep_poc_assert( 'outer finalized against the OUTER order id', array( $GLOBALS['wcep_c_reg_token'][ $u_outer->get_id() ] ) === ( $GLOBALS['wcep_c_final_tokens'][ $u_outer->get_id() ] ?? array() ) );
wcep_poc_assert( 'ledger empty afterwards', 0 === wcep_rc_slot_count() );

// =========================================================================
// BLOCKER 1 · TEST 2 — inner send FAILS, outer SUCCEEDS.
// =========================================================================
wcep_poc_section( 'BLOCKER 1 · inner fails, outer succeeds' );
$f_outer = wcep_poc_make_order( array( $matched => 1 ), 'pending', 'outer-ok@example.test' );
$f_inner = wcep_poc_make_order( array( $matched => 1 ), 'pending', 'inner-fail@example.test' );
$GLOBALS['wcep_c_ff_armed'] = true;
add_action( 'woocommerce_email_before_order_table', function ( $order, $sent_to_admin, $plain_text, $email = null ) use ( $proc, $f_inner, $f_outer ) {
	if ( empty( $GLOBALS['wcep_c_ff_armed'] ) || ! is_object( $email ) || 'customer_processing_order' !== $email->id || $order->get_id() !== $f_outer->get_id() ) {
		return;
	}
	$GLOBALS['wcep_c_ff_armed'] = false;
	$proc->trigger( $f_inner->get_id() ); // inner send will fail (recipient targeted below).
}, 30, 4 );
$GLOBALS['wcep_c_fail_recipient'] = 'inner-fail@example.test';
$proc->trigger( $f_outer->get_id() );
$GLOBALS['wcep_c_fail_recipient'] = null;
wcep_poc_assert( 'inner finalized as FAILED', 'failed' === ( $GLOBALS['wcep_c_final_status'][ $f_inner->get_id() ] ?? '' ) );
wcep_poc_assert( 'outer finalized as SENT (NOT poisoned by inner failure)', 'sent' === ( $GLOBALS['wcep_c_final_status'][ $f_outer->get_id() ] ?? '' ) );
wcep_poc_assert( 'ledger empty afterwards', 0 === wcep_rc_slot_count() );

// =========================================================================
// FRONT-END NEGATIVE GATE (TEMPLATE-LEVEL).
// =========================================================================
wcep_poc_section( 'NEGATIVE — front-end order views (TEMPLATE-LEVEL)' );
echo "  (scope: woocommerce_order_details_table + direct hook firing; full endpoint\n";
echo "   coverage — view-order, thank-you, order-pay — deferred to end-to-end tests)\n";
$captured = array();
$eh       = function ( $errno, $errstr, $errfile ) use ( &$captured ) { $captured[] = array( 'msg' => $errstr, 'file' => $errfile ); return false; };
set_error_handler( $eh );
@trigger_error( 'WCEP-CANARY', E_USER_WARNING );
restore_error_handler();
$canary = false;
foreach ( $captured as $c ) { if ( false !== strpos( $c['msg'], 'WCEP-CANARY' ) ) { $canary = true; } }
wcep_poc_assert( 'error capture LIVE (canary) — gate non-vacuous', $canary );
$captured = array();
set_error_handler( $eh );
ob_start();
woocommerce_order_details_table( $order->get_id() );
$frontend = ob_get_clean();
$threw    = null;
$items    = array_values( $order->get_items() );
$first    = $items[0];
try {
	ob_start();
	do_action( 'woocommerce_order_item_meta_end', $first->get_id(), $first, $order, false );
	do_action( 'woocommerce_order_item_meta_end', $first->get_id(), $first, $order );
	$frontend .= ob_get_clean();
} catch ( \Throwable $t ) {
	$frontend .= ob_get_clean();
	$threw     = $t;
}
restore_error_handler();
wcep_poc_assert( 'front-end: no markers, no throw, no our-code diagnostics', ! wcep_rc_in_email() && ! $has_any_marker( $frontend ) && null === $threw && empty( array_filter( $captured, function ( $c ) { return false !== stripos( $c['file'], 'extonify-custom-emails-per-product' ); } ) ) );

// =========================================================================
// BLOCKER 1 (1d) — PREVIEW RENDERS LEAVE NOTHING BEHIND.
// =========================================================================
wcep_poc_section( 'BLOCKER 1 (1d) — preview slot suppression + guaranteed frame teardown' );
$preview_class = '\Automattic\WooCommerce\Internal\Admin\EmailPreview\EmailPreview';
if ( class_exists( $preview_class ) && function_exists( 'wc_get_container' ) ) {
	echo '  PREVIEW-DETECTION-PATH: ' . ( 'core' === wcep_rc_preview_detection_path()
		? "core render-time signal — woocommerce_is_email_preview IS exposed by this WooCommerce"
		: "fallback next-render marker — woocommerce_prepare_email_for_preview (no core signal)" ) . "\n";
	echo "   (the fallback marker stays wired regardless: it is what makes detection immune to\n";
	echo "    core's leaked preview filter after an interrupted preview — see below)\n";

	wcep_poc_assert( 'ledger + frames empty before preview (no reset needed)', 0 === wcep_rc_slot_count() && 0 === wcep_rc_frame_count() && 0 === wcep_rc_open_od_token_count() && 0 === wcep_rc_open_footer_token_count() );

	// --- (a) SUCCESSFUL preview — zero residue -----------------------------
	$GLOBALS['wcep_c_saw_preview'] = false;
	$before_total = $GLOBALS['wcep_c_total_final'];
	$pv_tokens0   = wcep_rc_preview_token_count();
	wcep_poc_mail_reset();
	$preview = wc_get_container()->get( $preview_class );
	$preview->set_email_type( 'WC_Email_Customer_Processing_Order' );
	$preview_html = $preview->render();
	$det_a = end( $GLOBALS['wcep_rc_detect_log'] );

	wcep_poc_assert( 'preview produced non-empty HTML + positively detected in the render frame', is_string( $preview_html ) && strlen( $preview_html ) > 0 && true === $GLOBALS['wcep_c_saw_preview'] );
	wcep_poc_assert( 'preview detected via the CORE signal on this runtime', 'core' === $det_a['source'] && true === $det_a['core'], 'source=' . $det_a['source'] . ' core=' . var_export( $det_a['core'], true ) . ' marker=' . var_export( $det_a['marker'], true ) );
	wcep_poc_assert( 'successful preview: FRAME count = 0', 0 === wcep_rc_frame_count(), 'frames=' . wcep_rc_frame_count() );
	wcep_poc_assert( 'successful preview: DELIVERY SLOT count = 0 (no slot was ever created)', 0 === wcep_rc_slot_count() && wcep_rc_preview_token_count() === $pv_tokens0 + 1 );
	wcep_poc_assert( 'successful preview: open ORDER-DETAILS token count = 0', 0 === wcep_rc_open_od_token_count() );
	wcep_poc_assert( 'successful preview: open FOOTER token count = 0', 0 === wcep_rc_open_footer_token_count() );
	wcep_poc_assert( 'successful preview: preview PENDING MARKER count = 0', 0 === wcep_rc_preview_pending_count() );
	wcep_poc_assert( 'successful preview: unresolved contains NO preview token', array() === wcep_rc_unresolved_preview_tokens() );
	wcep_poc_assert( 'successful preview created ZERO delivery records', $GLOBALS['wcep_c_total_final'] === $before_total );
	wcep_poc_assert( 'successful preview sent ZERO mail', 0 === count( wcep_poc_mail_all() ) );

	// (b) Real send after a successful preview finalizes normally.
	$before = $GLOBALS['wcep_c_final_count'][ $order->get_id() ] ?? 0;
	wcep_c_trigger( 'WC_Email_Customer_Processing_Order', $order, 'html' );
	wcep_poc_assert( 'real send after a successful preview finalized normally', ( $GLOBALS['wcep_c_final_count'][ $order->get_id() ] ?? 0 ) === $before + 1 );

	// --- (c) INTERRUPTED preview — zero residue ----------------------------
	$comp_order = wcep_poc_make_order( array( $matched => 1 ), 'pending' );
	$GLOBALS['wcep_c_completed_orders'][] = $comp_order->get_id(); // enable the completed rule for it.
	$completed_obj = WC()->mailer()->get_emails()['WC_Email_Customer_Completed_Order'];

	// Interrupt a Completed preview AFTER push (frame created, marker consumed)
	// and BEFORE od:15 — the exact window that leaked frame + tokens in 1c.
	$GLOBALS['wcep_c_throw_completed'] = true;
	add_action( 'woocommerce_email_order_details', function ( $order, $a, $p, $email = null ) {
		if ( ! empty( $GLOBALS['wcep_c_throw_completed'] ) && is_object( $email ) && 'customer_completed_order' === $email->id ) {
			$GLOBALS['wcep_c_throw_completed'] = false;
			throw new RuntimeException( 'simulated preview interruption after push, before pop' );
		}
	}, 6, 4 );
	$pv_tokens1 = wcep_rc_preview_token_count();
	$threw2     = null;
	$preview2   = wc_get_container()->get( $preview_class );
	try {
		$preview2->set_email_type( 'WC_Email_Customer_Completed_Order' ); // marks completed for preview.
		$preview2->render();                                              // push, then @6 throws.
	} catch ( \Throwable $t ) {
		$threw2 = $t;
	}
	$det_c = end( $GLOBALS['wcep_rc_detect_log'] );

	// Ledger-level residue is ZERO the instant the exception unwinds: a preview
	// never creates a slot, so nothing delivery-related can survive at all.
	wcep_poc_assert( 'interrupted preview threw and was identified as a preview', null !== $threw2 && true === $det_c['is_preview'] && wcep_rc_preview_token_count() === $pv_tokens1 + 1 );
	wcep_poc_assert( 'interrupted preview: DELIVERY SLOT count = 0 (instant — no slot ever created)', 0 === wcep_rc_slot_count(), 'slots=' . wcep_rc_slot_count() );
	wcep_poc_assert( 'interrupted preview: preview PENDING MARKER count = 0 (instant — consumed at push)', 0 === wcep_rc_preview_pending_count() );
	wcep_poc_assert( 'interrupted preview: unresolved contains NO preview token (instant)', array() === wcep_rc_unresolved_preview_tokens() );

	// The frame + open-token residue is what 1c left behind for good. It is torn
	// down by reconciliation at the START of the next push — no manual clearing.
	$residue = array( 'frames' => wcep_rc_frame_count(), 'od' => wcep_rc_open_od_token_count(), 'footer' => wcep_rc_open_footer_token_count() );
	echo "  [window] between the interruption and the next push, the interrupted preview\n";
	echo "           still holds: frames={$residue['frames']} open_od={$residue['od']} open_footer={$residue['footer']}\n";
	echo "           (torn down automatically at the next push — asserted below)\n";
	wcep_poc_assert( 'interrupted preview: core preview signal LEAKED true (WC clean_up_filters is not in a finally)', true === wcep_rc_core_preview_signal(), 'this is why detection cannot trust the core signal blindly' );

	// Real Completed email, SAME live object, same request, NO manual clearing.
	$reconciled_before = wcep_rc_reconciled_count();
	$before_c          = $GLOBALS['wcep_c_final_count'][ $comp_order->get_id() ] ?? 0;
	wcep_poc_mail_reset();
	$completed_obj->trigger( $comp_order->get_id() );
	$det_r = $GLOBALS['wcep_rc_detect_log'][ count( $GLOBALS['wcep_rc_detect_log'] ) - 1 ];

	wcep_poc_assert( 'stale preview frame was RECONCILED at the next push (no manual clearing)', wcep_rc_reconciled_count() === $reconciled_before + 1 );
	wcep_poc_assert( 'the real send was NOT misread as a preview despite the leaked core signal', false === $det_r['is_preview'] && true === $det_r['leaked'] && false === $det_r['marker'] );
	wcep_poc_assert( 'interrupted preview: FRAME count = 0', 0 === wcep_rc_frame_count(), 'frames=' . wcep_rc_frame_count() );
	wcep_poc_assert( 'interrupted preview: open ORDER-DETAILS token count = 0', 0 === wcep_rc_open_od_token_count() );
	wcep_poc_assert( 'interrupted preview: open FOOTER token count = 0', 0 === wcep_rc_open_footer_token_count() );
	wcep_poc_assert( 'interrupted preview: unresolved STILL contains no preview token', array() === wcep_rc_unresolved_preview_tokens() );
	wcep_poc_assert( 'same-object recovery: real Completed email registered a slot + finalized a delivery record', ( $GLOBALS['wcep_c_final_count'][ $comp_order->get_id() ] ?? 0 ) === $before_c + 1 );
	wcep_poc_assert( 'same-object recovery: ledger empty again, no preview state remains', 0 === wcep_rc_slot_count() && 0 === wcep_rc_preview_pending_count() );
} else {
	echo "  [SKIP] EmailPreview surface not present (see POC-E).\n";
}

// =========================================================================
// BLOCKER 2 (1d) — EXACT SEND CORRELATION AT THE MAIL CALLBACK.
// =========================================================================
wcep_poc_section( 'BLOCKER 2 (1d) — exact send correlation (woocommerce_mail_callback_params)' );
$sc_order = wcep_poc_make_order( array( $matched => 1 ), 'pending', 'correlation@example.test' );
$GLOBALS['wcep_c_sc'] = array( 'armed' => true, 'capturing' => false, 'real_token' => null, 'second_token' => null, 'bound_at_render' => null, 'slots_after_second' => null );

// Capture the token issued to the SECOND render.
add_action( 'woocommerce_email_order_details', function ( $order, $a, $p, $email = null ) {
	if ( ! empty( $GLOBALS['wcep_c_sc']['capturing'] ) ) {
		$GLOBALS['wcep_c_sc']['second_token'] = wcep_rc_current_token();
	}
}, 8, 4 );

// Inside the mail callback (pre_wp_mail at priority 5 — ahead of the bootstrap
// capture at 10, never short-circuiting): the real content has already rendered
// and the send is already bound. Render the SAME live singleton a second time.
add_filter( 'pre_wp_mail', function ( $sc, $atts ) use ( $proc, $sc_order ) {
	if ( empty( $GLOBALS['wcep_c_sc']['armed'] ) ) {
		return $sc;
	}
	$to = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'];
	if ( false === strpos( $to, 'correlation@example.test' ) ) {
		return $sc;
	}
	$GLOBALS['wcep_c_sc']['armed']           = false;
	$GLOBALS['wcep_c_sc']['real_token']      = $GLOBALS['wcep_c_reg_token'][ $sc_order->get_id() ] ?? null;
	$GLOBALS['wcep_c_sc']['bound_at_render'] = wcep_rc_bound_token( $proc );
	$GLOBALS['wcep_c_sc']['capturing']       = true;
	$proc->get_content();  // SECOND render of the same live singleton, mid-send.
	$GLOBALS['wcep_c_sc']['capturing']          = false;
	$GLOBALS['wcep_c_sc']['slots_after_second'] = wcep_rc_slot_count();
	return $sc;
}, 5, 2 );

$before_sc = $GLOBALS['wcep_c_final_count'][ $sc_order->get_id() ] ?? 0;
wcep_poc_mail_reset();
$proc->trigger( $sc_order->get_id() );
$sc  = $GLOBALS['wcep_c_sc'];
$fin = wcep_rc_last_finalize();

wcep_poc_assert( 'the second render really happened mid-send and produced its own token', null !== $sc['second_token'] && $sc['second_token'] !== $sc['real_token'] && 2 === (int) $sc['slots_after_second'], 'real=' . $sc['real_token'] . ' second=' . $sc['second_token'] . ' slots=' . $sc['slots_after_second'] );
wcep_poc_assert( 'the send was BOUND at woocommerce_mail_callback_params, before the second render', $sc['bound_at_render'] === $sc['real_token'] );
wcep_poc_assert( 'the real email finalized against its OWN bound token', $fin && $fin['bound'] === $sc['real_token'] && $fin['resolved'] === $sc['real_token'] );
wcep_poc_assert( 'the real email finalized against its OWN recorded order', $fin && (int) $fin['order_id'] === $sc_order->get_id() && array( $sc['real_token'] ) === ( $GLOBALS['wcep_c_final_tokens'][ $sc_order->get_id() ] ?? array() ) );
wcep_poc_assert( 'NO LIFO selection occurred: the rejected "most recent awaiting_send" rule WOULD have picked the second render', $fin && $fin['lifo_would_pick'] === $sc['second_token'] && $fin['lifo_would_pick'] !== $fin['resolved'] );
wcep_poc_assert( 'the second render created NO delivery record (exactly one record for this order)', ( $GLOBALS['wcep_c_final_count'][ $sc_order->get_id() ] ?? 0 ) === $before_sc + 1 );
$sc_unresolved = false;
foreach ( wcep_rc_compute_unresolved() as $u ) {
	if ( $u['token'] === $sc['second_token'] && 'awaiting_send' === $u['state'] ) {
		$sc_unresolved = true;
	}
}
wcep_poc_assert( 'the second render is reported UNRESOLVED (still awaiting_send, never consumable)', $sc_unresolved );
wcep_poc_assert( 'outgoing mail was NOT mutated: exactly one message, carrying no render token', 1 === count( wcep_poc_mail_all() ) && false === strpos( (string) wcep_poc_mail_last()['message'], (string) $sc['real_token'] ) && false === strpos( (string) wcep_poc_mail_last()['message'], (string) $sc['second_token'] ) );

// =========================================================================
// BLOCKER 1 · TEST 3 — render WITHOUT send orphans its own slot.
// =========================================================================
wcep_poc_section( 'BLOCKER 1 · render without send → orphan slot, unresolved at shutdown' );
$orphan_order = wcep_poc_make_order( array( $matched => 1 ), 'pending' );
$real_order   = wcep_poc_make_order( array( $matched => 1 ), 'pending' );
$proc->object    = $orphan_order;
$proc->recipient = $orphan_order->get_billing_email();
$proc->get_content(); // fires render (od → footer) with NO send.
$orphan_token = $GLOBALS['wcep_c_reg_token'][ $orphan_order->get_id() ] ?? null;
$before_real  = $GLOBALS['wcep_c_final_count'][ $real_order->get_id() ] ?? 0;
wcep_c_trigger( 'WC_Email_Customer_Processing_Order', $real_order, 'html' ); // unrelated real send, same object.
$unresolved = wcep_rc_compute_unresolved();
$orphan_present = false;
foreach ( $unresolved as $u ) { if ( $u['token'] === $orphan_token ) { $orphan_present = true; } }
wcep_poc_assert( 'render-without-send produced a token', null !== $orphan_token );
wcep_poc_assert( 'real send finalized ITS OWN slot', ( $GLOBALS['wcep_c_final_count'][ $real_order->get_id() ] ?? 0 ) === $before_real + 1 );
wcep_poc_assert( 'orphan slot was NOT consumed by the real send', 0 === ( $GLOBALS['wcep_c_final_count'][ $orphan_order->get_id() ] ?? 0 ) );
wcep_poc_assert( 'orphan slot reported UNRESOLVED at shutdown', $orphan_present );

// =========================================================================
// PHASE 3 · foreign-token rejection (tuple validation on a LIVE frame).
// =========================================================================
wcep_poc_section( 'HARDENING · foreign-token rejection (tuple-validated pop/cleanup)' );
$completed_singleton = WC()->mailer()->get_emails()['WC_Email_Customer_Completed_Order'];
$GLOBALS['wcep_c_ft_armed'] = true;
add_action( 'woocommerce_email_before_order_table', function ( $order, $a, $p, $email = null ) use ( $completed_singleton, $other_order ) {
	if ( empty( $GLOBALS['wcep_c_ft_armed'] ) || ! is_object( $email ) || 'customer_processing_order' !== $email->id ) {
		return;
	}
	$GLOBALS['wcep_c_ft_armed'] = false;
	$token   = wcep_rc_current_token(); // valid token of THIS live processing frame.
	$mm0     = wcep_rc_mismatch_count();
	$frames0 = wcep_rc_frame_count();
	$r1      = wcep_rc_pop_matching( $completed_singleton, $order, $token ); // wrong email object.
	$r2      = wcep_rc_pop_matching( $email, $other_order, $token );          // wrong order id.
	$GLOBALS['wcep_c_ft'] = array( 'r1' => $r1, 'r2' => $r2, 'mm' => wcep_rc_mismatch_count() - $mm0, 'frames_same' => wcep_rc_frame_count() === $frames0 );
}, 25, 4 );
wcep_c_trigger( 'WC_Email_Customer_Processing_Order', $order, 'html' );
$ft = $GLOBALS['wcep_c_ft'];
wcep_poc_assert( 'foreign token removed NO frame (wrong email object rejected)', false === $ft['r1'] );
wcep_poc_assert( 'valid token + wrong order removed NO frame', false === $ft['r2'] );
wcep_poc_assert( 'both mismatches were RECORDED', 2 === $ft['mm'] );
wcep_poc_assert( 'the correct frame was left intact during the render', true === $ft['frames_same'] );

wcep_poc_cleanup();
wcep_poc_assert( 'cleanup verify-after-delete OK — no fixture leak', ! empty( $GLOBALS['wcep_poc_cleanup_report']['ok'] ), 'leaks=' . implode( ',', $GLOBALS['wcep_poc_cleanup_report']['leaks'] ) );
wcep_poc_summary();
echo "\nPOC-C OK\n";