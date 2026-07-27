<?php
/**
 * SHARED RENDER-CONTEXT MODULE (Prompt 1d). NON-PRODUCTION.
 *
 * Implements the amended ADR-0003 render-token context frames and the amended
 * ADR-0005 render-SLOT state machine with PREVIEW SLOT SUPPRESSION and EXACT
 * SEND CORRELATION. Used by POC-C and POC-D so both exercise the SAME
 * production-shaped mechanism.
 *
 * Two structures, created at context push with the same render token:
 *
 *  - FRAMES ($wcep_rc_frames): the item-hook context window. A frame is pushed on
 *    woocommerce_email_order_details@5 and removed on @15 (pop_matching) with a
 *    footer backstop (cleanup_matching). Removal is RENDER-scoped by token AND
 *    tuple-validated against the email object + order id. A frame is created for
 *    EVERY render, preview included — injection callbacks need a context to read.
 *
 *  - SLOTS ($wcep_rc_slots): the finalization ledger. A flat list scanned
 *    newest-first, each slot storing its OWN recorded order id. A slot is created
 *    for every applicable NON-PREVIEW native-email render (even one that matches
 *    no rule) and moves through:
 *        rendering  →  awaiting_send  →  in_flight  →  resolved(removed)
 *
 * PROMPT 1d — BLOCKER 1: PREVIEW CREATES NO DELIVERY SLOT.
 *   A render identified as a preview gets a FRAME ONLY. Preview tokens therefore
 *   can never enter the ledger, never reach awaiting_send, and never appear in the
 *   unresolved report. Detection prefers WooCommerce's own render-time signal
 *   (`woocommerce_is_email_preview`) and falls back to the render-scoped
 *   `woocommerce_prepare_email_for_preview` next-render marker.
 *
 *   LEAK IMMUNITY (verified WC 10.9.4): EmailPreview::render_preview_email() calls
 *   clean_up_filters() WITHOUT a try/finally, so an exception thrown mid-render
 *   leaves `woocommerce_is_email_preview` attached and returning true for the rest
 *   of the request — a later REAL send then reports itself as a preview. Whenever
 *   reconciliation removes a stale preview frame while the core signal still reads
 *   true, the signal is marked LEAKED and the render-scoped marker becomes
 *   authoritative until the signal is observed healthy again.
 *
 * PROMPT 1d — BLOCKER 2: EXACT SEND CORRELATION.
 *   `woocommerce_mail_callback_params` fires inside WC_Email::send(), receives the
 *   email object, and runs BEFORE the mail callback. That is the correlation point:
 *   the most recent awaiting_send slot for the object is marked `in_flight` and its
 *   token pushed onto a per-object bind stack (params returned UNMODIFIED).
 *   woocommerce_email_sent finalizes EXACTLY the bound token — never "most recent
 *   awaiting_send". No binding → finalize nothing. A render that never reaches the
 *   mail callback stays awaiting_send, is reported unresolved at shutdown, and can
 *   never be consumed by another send.
 *
 * HARD RULE (verified WC 10.9.4): the order is NEVER read from $email->object at
 * finalization — nested same-object rendering leaves $email->object on the inner
 * order because WooCommerce does not restore it. The order always comes from the
 * slot recorded at push.
 *
 * @package Extonify\WCEP\POC
 */

$GLOBALS['wcep_rc_seq']             = 0;
$GLOBALS['wcep_rc_frames']          = array(); // item-hook context frames
$GLOBALS['wcep_rc_slots']           = array(); // finalization slots (state machine)
$GLOBALS['wcep_rc_open_od']         = array(); // spl => [tokens] popped at od:15
$GLOBALS['wcep_rc_open_footer']     = array(); // spl => [tokens] popped at footer
$GLOBALS['wcep_rc_preview_pending'] = array(); // spl => true (marks the NEXT render)
$GLOBALS['wcep_rc_preview_tokens']  = array(); // every token ever issued to a preview
$GLOBALS['wcep_rc_reconciled']      = array(); // stale preview frames torn down
$GLOBALS['wcep_rc_bindings']        = array(); // spl => [tokens] bind stack (LIFO)
$GLOBALS['wcep_rc_bind_log']        = array(); // every mail_callback_params binding
$GLOBALS['wcep_rc_finalize_log']    = array(); // bound token vs. what LIFO WOULD pick
$GLOBALS['wcep_rc_detect_log']      = array(); // per-render preview-detection evidence
$GLOBALS['wcep_rc_unresolved']      = array();
$GLOBALS['wcep_rc_mismatches']      = array();

// Is WooCommerce's own render-time preview signal present on this runtime? Core
// drives `woocommerce_is_email_preview` from EmailPreview::enable_preview_mode().
$GLOBALS['wcep_rc_core_signal_available'] = (
	class_exists( '\Automattic\WooCommerce\Internal\Admin\EmailPreview\EmailPreview' )
	&& method_exists( '\Automattic\WooCommerce\Internal\Admin\EmailPreview\EmailPreview', 'enable_preview_mode' )
);
$GLOBALS['wcep_rc_core_signal_leaked'] = false;

function wcep_rc_token() {
	return 'rt_' . ( ++$GLOBALS['wcep_rc_seq'] );
}

/** Which detection path this runtime uses: 'core' or 'marker-fallback'. */
function wcep_rc_preview_detection_path() {
	return $GLOBALS['wcep_rc_core_signal_available'] ? 'core' : 'marker-fallback';
}
/** Read WooCommerce's render-time preview signal (false when unavailable). */
function wcep_rc_core_preview_signal() {
	if ( ! $GLOBALS['wcep_rc_core_signal_available'] ) {
		return false;
	}
	return (bool) apply_filters( 'woocommerce_is_email_preview', false );
}
function wcep_rc_core_signal_leaked() {
	return (bool) $GLOBALS['wcep_rc_core_signal_leaked'];
}

// --- frames (item-hook context) --------------------------------------------
function wcep_rc_top() {
	$f = $GLOBALS['wcep_rc_frames'];
	return empty( $f ) ? null : end( $f );
}
function wcep_rc_in_email() {
	return ! empty( $GLOBALS['wcep_rc_frames'] );
}
function wcep_rc_frame_count() {
	return count( $GLOBALS['wcep_rc_frames'] );
}
function wcep_rc_current_token() {
	$t = wcep_rc_top();
	return $t ? $t['token'] : null;
}
/** Registration reads is_preview from the CURRENT render frame (never object state). */
function wcep_rc_is_preview_current() {
	$t = wcep_rc_top();
	return $t ? (bool) $t['is_preview'] : false;
}

// --- open-token ledgers ------------------------------------------------------
function wcep_rc_open_od_token_count() {
	$n = 0;
	foreach ( $GLOBALS['wcep_rc_open_od'] as $tokens ) {
		$n += count( $tokens );
	}
	return $n;
}
function wcep_rc_open_footer_token_count() {
	$n = 0;
	foreach ( $GLOBALS['wcep_rc_open_footer'] as $tokens ) {
		$n += count( $tokens );
	}
	return $n;
}
/** Remove one token from both open-token ledgers for an object. */
function wcep_rc_purge_token( $spl, $token ) {
	foreach ( array( 'wcep_rc_open_od', 'wcep_rc_open_footer' ) as $key ) {
		if ( empty( $GLOBALS[ $key ][ $spl ] ) ) {
			continue;
		}
		$GLOBALS[ $key ][ $spl ] = array_values( array_filter(
			$GLOBALS[ $key ][ $spl ],
			function ( $t ) use ( $token ) {
				return $t !== $token;
			}
		) );
	}
}

/**
 * PROMPT 1d — guaranteed preview teardown.
 *
 * Remove every surviving preview frame together with its open order-details and
 * open footer tokens. A preview frame that is still present when the next render
 * begins is proof that a preview render was interrupted between push and pop: a
 * completed preview always removes its own frame at od:15. Called at the start of
 * EVERY push and again from the shutdown fallback, so an exception between push
 * and pop can never leave an active context frame behind (ADR-0003).
 *
 * When a stale preview frame is found AND WooCommerce's own preview signal still
 * reads true, core leaked it (clean_up_filters() is not in a finally) — mark the
 * signal untrusted so the render-scoped marker governs detection until it heals.
 *
 * @return int Number of stale preview frames torn down.
 */
function wcep_rc_reconcile_previews() {
	$stale = array();
	$keep  = array();
	foreach ( $GLOBALS['wcep_rc_frames'] as $f ) {
		if ( empty( $f['is_preview'] ) ) {
			$keep[] = $f;
			continue;
		}
		$stale[] = $f;
	}
	if ( empty( $stale ) ) {
		return 0;
	}
	$GLOBALS['wcep_rc_frames'] = $keep;
	foreach ( $stale as $f ) {
		wcep_rc_purge_token( $f['spl'], $f['token'] );
		$GLOBALS['wcep_rc_reconciled'][] = array( 'token' => $f['token'], 'spl' => $f['spl'], 'oid' => $f['oid'] );
	}
	if ( wcep_rc_core_preview_signal() ) {
		$GLOBALS['wcep_rc_core_signal_leaked'] = true; // core left preview mode on.
	}
	return count( $stale );
}
function wcep_rc_reconciled_count() {
	return count( $GLOBALS['wcep_rc_reconciled'] );
}

// --- push: always a frame; a slot ONLY for non-preview renders ---------------
function wcep_rc_push( $email, $order, $plain, $admin ) {
	wcep_rc_reconcile_previews(); // FIRST — never inherit a previous render's residue.

	$spl   = is_object( $email ) ? spl_object_id( $email ) : 0;
	$oid   = is_object( $order ) ? $order->get_id() : 0;
	$token = wcep_rc_token();

	// Render-scoped marker (consumed at push, exactly as in 1c).
	$marker = ! empty( $GLOBALS['wcep_rc_preview_pending'][ $spl ] );
	if ( $marker ) {
		unset( $GLOBALS['wcep_rc_preview_pending'][ $spl ] );
	}
	// Core's render-time signal. A false reading proves the signal is healthy again.
	$core = wcep_rc_core_preview_signal();
	if ( ! $core ) {
		$GLOBALS['wcep_rc_core_signal_leaked'] = false;
	}
	$trust_core = $GLOBALS['wcep_rc_core_signal_available'] && ! $GLOBALS['wcep_rc_core_signal_leaked'];
	$is_preview = $trust_core ? ( $core || $marker ) : $marker;

	$GLOBALS['wcep_rc_detect_log'][] = array(
		'token'      => $token,
		'spl'        => $spl,
		'core'       => $core,
		'marker'     => $marker,
		'leaked'     => $GLOBALS['wcep_rc_core_signal_leaked'],
		'source'     => $is_preview ? ( $trust_core && $core ? 'core' : 'marker' ) : 'none',
		'is_preview' => $is_preview,
	);

	$GLOBALS['wcep_rc_frames'][] = array(
		'token'      => $token,
		'spl'        => $spl,
		'oid'        => $oid,
		'email_id'   => is_object( $email ) ? $email->id : '',
		'plain'      => (bool) $plain,
		'admin'      => (bool) $admin,
		'is_preview' => $is_preview,
	);

	if ( $is_preview ) {
		// ADR-0005 (1d): a preview writes NO delivery state whatsoever.
		$GLOBALS['wcep_rc_preview_tokens'][] = $token;
	} else {
		$GLOBALS['wcep_rc_slots'][] = array(
			'token'    => $token,
			'spl'      => $spl,
			'order_id' => $oid,       // recorded at push — the ONLY source of truth.
			'rules'    => array(),
			'state'    => 'rendering',
		);
	}

	$GLOBALS['wcep_rc_open_od'][ $spl ][]     = $token;
	$GLOBALS['wcep_rc_open_footer'][ $spl ][] = $token;
	return $token;
}

/** Normalize an order argument to an id (WC_Order | int | null). */
function wcep_rc_oid( $order ) {
	if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
		return (int) $order->get_id();
	}
	return is_numeric( $order ) ? (int) $order : null;
}

/**
 * Remove exactly ONE frame by token, tuple-validated: the frame bearing $token
 * must also belong to $email (spl) and, when an order id is supplied, to $order.
 * On mismatch: remove nothing and record it. Returns true iff a frame was removed.
 */
function wcep_rc_remove_frame( $email, $order, $token, $where ) {
	$spl = is_object( $email ) ? spl_object_id( $email ) : 0;
	$oid = wcep_rc_oid( $order );
	foreach ( $GLOBALS['wcep_rc_frames'] as $i => $f ) {
		if ( $f['token'] !== $token ) {
			continue;
		}
		if ( $f['spl'] !== $spl || ( null !== $oid && $f['oid'] !== $oid ) ) {
			$GLOBALS['wcep_rc_mismatches'][] = array( 'where' => $where, 'token' => $token, 'frame_spl' => $f['spl'], 'frame_oid' => $f['oid'], 'given_spl' => $spl, 'given_oid' => $oid );
			return false; // valid token, wrong email/order → remove NOTHING.
		}
		array_splice( $GLOBALS['wcep_rc_frames'], $i, 1 );
		return true;
	}
	return false; // token not present.
}
function wcep_rc_pop_matching( $email, $order, $token ) {
	return wcep_rc_remove_frame( $email, $order, $token, 'pop' );
}
function wcep_rc_cleanup_matching( $email, $order, $token ) {
	return wcep_rc_remove_frame( $email, $order, $token, 'cleanup' );
}
function wcep_rc_mismatch_count() {
	return count( $GLOBALS['wcep_rc_mismatches'] );
}
/** Recorded order for a token — slot first, then frame (previews have no slot). */
function wcep_rc_recorded_oid_for_token( $token ) {
	$i = wcep_rc_find_slot_index_by_token( $token );
	if ( $i >= 0 ) {
		return $GLOBALS['wcep_rc_slots'][ $i ]['order_id'];
	}
	foreach ( $GLOBALS['wcep_rc_frames'] as $f ) {
		if ( $f['token'] === $token ) {
			return $f['oid'];
		}
	}
	return null;
}

// --- slots (finalization state machine) ------------------------------------
function wcep_rc_find_slot_index_by_token( $token ) {
	foreach ( $GLOBALS['wcep_rc_slots'] as $i => $s ) {
		if ( $s['token'] === $token ) {
			return $i;
		}
	}
	return -1;
}
/** Matched rule → add to the slot bearing the CURRENT render token. */
function wcep_rc_register( $rule_id ) {
	$token = wcep_rc_current_token();
	if ( null === $token ) {
		return false;
	}
	$i = wcep_rc_find_slot_index_by_token( $token );
	if ( $i < 0 ) {
		return false; // preview render — no slot exists, nothing to register.
	}
	$GLOBALS['wcep_rc_slots'][ $i ]['rules'][ $rule_id ] = true;
	return true;
}
/** Footer / render completes → mark the render's slot 'awaiting_send'. */
function wcep_rc_mark_awaiting( $token ) {
	$i = wcep_rc_find_slot_index_by_token( $token );
	if ( $i >= 0 && 'rendering' === $GLOBALS['wcep_rc_slots'][ $i ]['state'] ) {
		$GLOBALS['wcep_rc_slots'][ $i ]['state'] = 'awaiting_send';
	}
}

/**
 * PROMPT 1d — BLOCKER 2: bind the send at woocommerce_mail_callback_params.
 *
 * Select the most recent awaiting_send slot for this email object, mark it
 * 'in_flight' and push its token onto the object's bind stack. Called from the
 * filter callback, which returns the params UNMODIFIED — outgoing mail is never
 * touched. A stack (not a scalar) so a send nested inside the mail callback
 * cannot displace the outer binding.
 *
 * @return string|null Bound token, or null when there was nothing to bind.
 */
function wcep_rc_bind_send( $email ) {
	if ( ! is_object( $email ) ) {
		return null;
	}
	$spl = spl_object_id( $email );
	for ( $i = count( $GLOBALS['wcep_rc_slots'] ) - 1; $i >= 0; $i-- ) {
		if ( $GLOBALS['wcep_rc_slots'][ $i ]['spl'] !== $spl || 'awaiting_send' !== $GLOBALS['wcep_rc_slots'][ $i ]['state'] ) {
			continue;
		}
		$GLOBALS['wcep_rc_slots'][ $i ]['state'] = 'in_flight';
		$token = $GLOBALS['wcep_rc_slots'][ $i ]['token'];
		$GLOBALS['wcep_rc_bindings'][ $spl ][] = $token;
		$GLOBALS['wcep_rc_bind_log'][]         = array( 'token' => $token, 'spl' => $spl, 'order_id' => $GLOBALS['wcep_rc_slots'][ $i ]['order_id'] );
		return $token;
	}
	$GLOBALS['wcep_rc_bind_log'][] = array( 'token' => null, 'spl' => $spl, 'order_id' => null ); // nothing eligible.
	return null;
}
function wcep_rc_bound_token( $email ) {
	if ( ! is_object( $email ) ) {
		return null;
	}
	$spl = spl_object_id( $email );
	if ( empty( $GLOBALS['wcep_rc_bindings'][ $spl ] ) ) {
		return null;
	}
	return end( $GLOBALS['wcep_rc_bindings'][ $spl ] );
}

/**
 * Finalize on woocommerce_email_sent: type-guard, then resolve EXACTLY the token
 * bound at woocommerce_mail_callback_params. Never "most recent awaiting_send".
 * Returns the slot (with its recorded order_id + rules) or null (bail). Order is
 * NEVER read from $email->object.
 */
function wcep_rc_finalize( $email ) {
	if ( ! is_object( $email ) || ! isset( $email->object ) || ! ( $email->object instanceof WC_Order ) ) {
		return null; // type-guard.
	}
	$spl = spl_object_id( $email );
	if ( empty( $GLOBALS['wcep_rc_bindings'][ $spl ] ) ) {
		return null; // no binding → finalize nothing, never guess.
	}
	$token = array_pop( $GLOBALS['wcep_rc_bindings'][ $spl ] );
	if ( empty( $GLOBALS['wcep_rc_bindings'][ $spl ] ) ) {
		unset( $GLOBALS['wcep_rc_bindings'][ $spl ] );
	}

	// What the REJECTED "most recent awaiting_send" rule would have selected —
	// recorded so a test can prove no LIFO selection happened at finalization.
	$lifo = null;
	for ( $i = count( $GLOBALS['wcep_rc_slots'] ) - 1; $i >= 0; $i-- ) {
		if ( $GLOBALS['wcep_rc_slots'][ $i ]['spl'] === $spl && 'awaiting_send' === $GLOBALS['wcep_rc_slots'][ $i ]['state'] ) {
			$lifo = $GLOBALS['wcep_rc_slots'][ $i ]['token'];
			break;
		}
	}

	$idx = wcep_rc_find_slot_index_by_token( $token );
	if ( $idx < 0 || 'in_flight' !== $GLOBALS['wcep_rc_slots'][ $idx ]['state'] ) {
		$GLOBALS['wcep_rc_finalize_log'][] = array( 'bound' => $token, 'lifo_would_pick' => $lifo, 'resolved' => null, 'order_id' => null );
		return null;
	}
	$slot = $GLOBALS['wcep_rc_slots'][ $idx ];
	array_splice( $GLOBALS['wcep_rc_slots'], $idx, 1 );
	$GLOBALS['wcep_rc_finalize_log'][] = array( 'bound' => $token, 'lifo_would_pick' => $lifo, 'resolved' => $slot['token'], 'order_id' => $slot['order_id'] );
	return $slot;
}
function wcep_rc_last_finalize() {
	$l = $GLOBALS['wcep_rc_finalize_log'];
	return empty( $l ) ? null : end( $l );
}

function wcep_rc_slot_count() {
	return count( $GLOBALS['wcep_rc_slots'] );
}
function wcep_rc_pending_count() {
	// Delivery-relevant backlog: slots still carrying unresolved rule sets.
	$n = 0;
	foreach ( $GLOBALS['wcep_rc_slots'] as $s ) {
		if ( ! empty( $s['rules'] ) ) {
			++$n;
		}
	}
	return $n;
}
/** Non-destructive: what shutdown WOULD record as unresolved (all remaining slots). */
function wcep_rc_compute_unresolved() {
	$out = array();
	foreach ( $GLOBALS['wcep_rc_slots'] as $s ) {
		$out[] = array( 'token' => $s['token'], 'spl' => $s['spl'], 'order_id' => $s['order_id'], 'state' => $s['state'], 'rules' => array_keys( $s['rules'] ) );
	}
	return $out;
}
/** Preview tokens that leaked into the unresolved report — must always be empty. */
function wcep_rc_unresolved_preview_tokens() {
	$preview = $GLOBALS['wcep_rc_preview_tokens'];
	$hits    = array();
	foreach ( wcep_rc_compute_unresolved() as $u ) {
		if ( in_array( $u['token'], $preview, true ) ) {
			$hits[] = $u['token'];
		}
	}
	foreach ( $GLOBALS['wcep_rc_unresolved'] as $u ) {
		if ( in_array( $u['token'], $preview, true ) ) {
			$hits[] = $u['token'];
		}
	}
	return $hits;
}

// --- preview state (render-scoped) -----------------------------------------
function wcep_rc_preview_pending_count() {
	return count( $GLOBALS['wcep_rc_preview_pending'] );
}
function wcep_rc_preview_token_count() {
	return count( $GLOBALS['wcep_rc_preview_tokens'] );
}
/** Shutdown fallback — LAST resort only. Never called by normal render code. */
function wcep_rc_run_preview_shutdown() {
	wcep_rc_reconcile_previews();
	$GLOBALS['wcep_rc_preview_pending'] = array();
}

// --- hook wiring -----------------------------------------------------------
add_action( 'woocommerce_email_order_details', function ( $order, $sent_to_admin, $plain_text, $email = null ) {
	wcep_rc_push( $email, $order, $plain_text, $sent_to_admin );
}, 5, 4 );

// od:15 removes the item-hook frame AND marks the slot 'awaiting_send'. This is
// the render-complete signal because it fires for BOTH html and plain templates
// (plain templates do NOT fire woocommerce_email_footer), and always before the
// send. For nested renders the inner od:15 fires before the outer's, so at the
// inner send the outer slot is still 'rendering' (structurally ineligible).
// For a preview there is no slot, so mark_awaiting is a no-op by construction.
add_action( 'woocommerce_email_order_details', function ( $order, $sent_to_admin, $plain_text, $email = null ) {
	$spl = is_object( $email ) ? spl_object_id( $email ) : 0;
	if ( ! empty( $GLOBALS['wcep_rc_open_od'][ $spl ] ) ) {
		$token   = array_pop( $GLOBALS['wcep_rc_open_od'][ $spl ] ); // LIFO — this render.
		$removed = wcep_rc_pop_matching( $email, $order, $token );   // remove FRAME (validated).
		wcep_rc_mark_awaiting( $token );                            // slot rendering → awaiting_send.
		if ( $removed && $plain_text ) {
			// PLAIN templates never fire woocommerce_email_footer, so nothing
			// would ever retire this render's footer entry and the ledger would
			// leak one token per plain render. Retire it here now that the frame
			// is confirmed gone. HTML renders are left alone so the footer
			// backstop keeps its LIFO alignment under nesting, and a REJECTED pop
			// keeps its entry either way — that is when the backstop is needed.
			wcep_rc_purge_token( $spl, $token );
		}
	}
}, 15, 4 );

// Footer backstop (html only): remove any frame od:15 missed. Render-scoped by
// token; a no-op in the normal path since od:15 already removed the frame.
add_action( 'woocommerce_email_footer', function ( $email = null ) {
	$spl = is_object( $email ) ? spl_object_id( $email ) : 0;
	if ( ! empty( $GLOBALS['wcep_rc_open_footer'][ $spl ] ) ) {
		$token = array_pop( $GLOBALS['wcep_rc_open_footer'][ $spl ] );
		wcep_rc_cleanup_matching( $email, wcep_rc_recorded_oid_for_token( $token ), $token );
	}
}, 999, 1 );

// PROMPT 1d — BLOCKER 2. Runs inside WC_Email::send(), before the mail callback.
// Late priority so it binds after any third-party params filtering. Returns the
// params UNTOUCHED: no render token is ever embedded in outgoing mail.
add_filter( 'woocommerce_mail_callback_params', function ( $params, $email = null ) {
	wcep_rc_bind_send( $email );
	return $params;
}, 9999, 2 );

// Fallback preview marker for runtimes without core's render-time signal. Kept
// wired on this runtime too: it is what makes detection immune to core's leaked
// `woocommerce_is_email_preview` after an interrupted preview.
add_filter( 'woocommerce_prepare_email_for_preview', function ( $email ) {
	if ( is_object( $email ) ) {
		$GLOBALS['wcep_rc_preview_pending'][ spl_object_id( $email ) ] = true; // marks the NEXT render.
	}
	return $email;
} );

// Shutdown: reconcile stale preview frames, then record every remaining slot as
// unresolved (never sent). Previews own no slots, so nothing preview-related can
// appear here.
register_shutdown_function( function () {
	wcep_rc_run_preview_shutdown();
	foreach ( $GLOBALS['wcep_rc_slots'] as $s ) {
		$GLOBALS['wcep_rc_unresolved'][] = array( 'token' => $s['token'], 'spl' => $s['spl'], 'order_id' => $s['order_id'], 'state' => $s['state'] );
	}
} );