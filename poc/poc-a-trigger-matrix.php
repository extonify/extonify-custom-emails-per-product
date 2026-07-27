<?php
/**
 * POC-A — TRIGGER MATRIX + DELIVERY IDENTITY / IDEMPOTENCY. NON-PRODUCTION.
 *
 * Proves ADR-0004 (amended) + ADR-0008:
 *  - durable tombstone vs purgeable detail; UNIQUE(order|rule|mode|identity);
 *  - FAIL-CLOSED atomic claim (affected-rows, no error-text parsing), and a
 *    claim_and_maybe_send() orchestration that sends NOTHING on claim failure;
 *  - status identities, and TRANSITION identities (first claimed, exact repeat
 *    suppressed, status-rule + transition-rule on one event → two distinct claims);
 *  - ADR-0008 status-before-items deferral (labelled an IN-MEMORY simulation);
 *  - tombstone survives retention purge + privacy erasure → re-fire suppressed.
 *
 * @package Extonify\WCEP\POC
 */

require __DIR__ . '/_bootstrap.php';

wcep_poc_section( 'POC-A — TRIGGER MATRIX + DELIVERY IDENTITY (ADR-0004 amended, ADR-0008)' );

global $wpdb;
$tombstone = $wpdb->prefix . 'extonify_wcep_poc_tombstone';
$detail    = $wpdb->prefix . 'extonify_wcep_poc_detail';
wcep_poc_track_table( $tombstone );
wcep_poc_track_table( $detail );

$charset = $wpdb->get_charset_collate();
$wpdb->query( "DROP TABLE IF EXISTS {$detail}" );
$wpdb->query( "DROP TABLE IF EXISTS {$tombstone}" );
$wpdb->query(
	"CREATE TABLE {$tombstone} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		order_id BIGINT UNSIGNED NOT NULL,
		rule_id BIGINT UNSIGNED NOT NULL,
		mode VARCHAR(20) NOT NULL,
		trigger_identity VARCHAR(191) NOT NULL,
		first_claimed_at DATETIME NOT NULL,
		final_status VARCHAR(20) NOT NULL DEFAULT 'claimed',
		suppressed_count INT UNSIGNED NOT NULL DEFAULT 0,
		last_seen_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY uniq_claim (order_id, rule_id, mode, trigger_identity)
	) {$charset}"
);
$wpdb->query(
	"CREATE TABLE {$detail} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		tombstone_id BIGINT UNSIGNED NOT NULL,
		recipient VARCHAR(191) NULL,
		subject VARCHAR(255) NULL,
		snapshot TEXT NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		KEY tombstone_id (tombstone_id)
	) {$charset}"
);
wcep_poc_assert( 'tombstone UNIQUE(order,rule,mode,identity) created', (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tombstone ) ) );
wcep_poc_assert( 'separate purgeable detail table created', (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $detail ) ) );

$matched_product = wcep_poc_make_product( 'POC-A Targeted Product', 25.0 );
$MODE            = 'separate';
$STATUS_RULE     = 101; // status trigger {processing, completed}
$TXN_RULE        = 111; // transition trigger {pending>processing}
$trigger_statuses = array( 'processing', 'completed' );

/** FAIL-CLOSED atomic claim (ADR-0004). Returns 'claimed'|'suppressed'|'failed'. */
function wcep_a_claim( $order_id, $identity, $rule_id, $recipient = 'poc-buyer@example.test', $subject = 'POC subject' ) {
	global $wpdb, $tombstone, $detail, $MODE;
	$now = current_time( 'mysql' );
	$sql = $wpdb->prepare(
		"INSERT INTO {$tombstone}
			(order_id, rule_id, mode, trigger_identity, first_claimed_at, final_status, suppressed_count, last_seen_at)
		 VALUES (%d, %d, %s, %s, %s, 'claimed', 0, %s)
		 ON DUPLICATE KEY UPDATE
			suppressed_count = suppressed_count + 1,
			last_seen_at     = VALUES(last_seen_at),
			id               = LAST_INSERT_ID(id)",
		$order_id,
		$rule_id,
		$MODE,
		$identity,
		$now,
		$now
	);
	$prev   = $wpdb->suppress_errors( true );
	$result = $wpdb->query( $sql ); // phpcs:ignore
	$wpdb->suppress_errors( $prev );

	if ( false === $result ) {
		return 'failed';
	}
	$affected = (int) $wpdb->rows_affected;
	if ( 1 === $affected ) {
		$tid = (int) $wpdb->insert_id;
		$wpdb->insert( $detail, array( 'tombstone_id' => $tid, 'recipient' => $recipient, 'subject' => $subject, 'snapshot' => 'rendered (personal)', 'created_at' => current_time( 'mysql' ) ) );
		return 'claimed';
	}
	if ( 2 === $affected ) {
		return 'suppressed';
	}
	return 'failed';
}

/**
 * Claim then send only on a fresh claim. Proves fail-closed: a claim FAILURE (or
 * duplicate) sends nothing. This is the single orchestration a trigger uses.
 */
function claim_and_maybe_send( $order_id, $identity, $rule_id, $recipient, $subject ) {
	$result = wcep_a_claim( $order_id, $identity, $rule_id, $recipient, $subject );
	if ( 'claimed' === $result ) {
		wp_mail( $recipient, $subject, 'POC body' ); // captured by pre_wp_mail.
	}
	return $result;
}

function wcep_a_order_targets_rule( $order ) {
	global $matched_product;
	foreach ( $order->get_items() as $item ) {
		if ( (int) $item->get_product_id() === (int) $matched_product ) {
			return true;
		}
	}
	return false;
}
function wcep_a_count( $order_id, $identity = null ) {
	global $wpdb, $tombstone;
	if ( null === $identity ) {
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tombstone} WHERE order_id = %d", $order_id ) );
	}
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tombstone} WHERE order_id = %d AND trigger_identity = %s", $order_id, $identity ) );
}
function wcep_a_suppressed( $order_id, $identity ) {
	global $wpdb, $tombstone;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT suppressed_count FROM {$tombstone} WHERE order_id = %d AND trigger_identity = %s", $order_id, $identity ) );
}
function wcep_a_rule_of( $order_id, $identity ) {
	global $wpdb, $tombstone;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT rule_id FROM {$tombstone} WHERE order_id = %d AND trigger_identity = %s", $order_id, $identity ) );
}
function wcep_a_detail_count( $order_id ) {
	global $wpdb, $tombstone, $detail;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$detail} d INNER JOIN {$tombstone} t ON d.tombstone_id = t.id WHERE t.order_id = %d", $order_id ) );
}

// --- ADR-0008 deferral machinery (IN-MEMORY SIMULATION) --------------------
$GLOBALS['wcep_a_deferrals'] = array();
function wcep_a_schedule_deferral( $order_id, $identity ) {
	$key = $order_id . '|' . $identity;
	if ( isset( $GLOBALS['wcep_a_deferrals'][ $key ] ) ) {
		return 'suppressed'; // single-deferral cap.
	}
	$GLOBALS['wcep_a_deferrals'][ $key ] = true;
	return 'scheduled';
}
function wcep_a_run_deferral( $order_id, $identity, $rule_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || ! wcep_a_order_targets_rule( $order ) ) {
		return 'skipped:no-matching-items';
	}
	return wcep_a_claim( $order_id, $identity, $rule_id, $order->get_billing_email(), 'deferred subject' );
}

$GLOBALS['wcep_poc_a_matrix'] = array();
function wcep_poc_a_row( $scenario, $event, $identity, $result ) {
	$GLOBALS['wcep_poc_a_matrix'][] = compact( 'scenario', 'event', 'identity', 'result' );
}

// --- STATUS-rule handler (rule 101), applies to all POC-A orders -----------
add_action( 'woocommerce_order_status_changed', function ( $order_id, $from, $to, $order ) use ( $trigger_statuses, $STATUS_RULE ) {
	$scenario = $GLOBALS['wcep_poc_a_current_scenario'] ?? '?';
	if ( ! in_array( $to, $trigger_statuses, true ) ) {
		wcep_poc_a_row( $scenario, "status {$from}>{$to}", '—', 'no-claim (status not targeted)' );
		return;
	}
	$identity = 'status:' . $to;
	if ( 0 === count( $order->get_items() ) ) {
		$res = wcep_a_schedule_deferral( $order_id, $identity );
		wcep_poc_a_row( $scenario, "status {$from}>{$to} (0 items)", $identity, 'deferred:' . $res );
		return;
	}
	if ( ! wcep_a_order_targets_rule( $order ) ) {
		wcep_poc_a_row( $scenario, "status {$from}>{$to}", '—', 'no-claim (no product match)' );
		return;
	}
	$res = wcep_a_claim( $order_id, $identity, $STATUS_RULE, $order->get_billing_email(), 'subj' );
	wcep_poc_a_row( $scenario, "status {$from}>{$to}", $identity, $res );
}, 10, 4 );

// --- TRANSITION-rule handler (rule 111), scoped to transition-test orders ---
$GLOBALS['wcep_a_txn_orders'] = array();
add_action( 'woocommerce_order_status_changed', function ( $order_id, $from, $to, $order ) use ( $TXN_RULE ) {
	if ( ! in_array( $order_id, $GLOBALS['wcep_a_txn_orders'], true ) ) {
		return; // transition rule only applies to its own test orders.
	}
	if ( 'pending>processing' !== "{$from}>{$to}" || ! wcep_a_order_targets_rule( $order ) ) {
		return;
	}
	$identity = 'transition:' . $from . '>' . $to;
	$res      = wcep_a_claim( $order_id, $identity, $TXN_RULE, $order->get_billing_email(), 'txn subj' );
	wcep_poc_a_row( $GLOBALS['wcep_poc_a_current_scenario'] ?? '?', "status {$from}>{$to}", $identity, $res );
}, 12, 4 );

$refund_handler = function ( $order_id, $refund_id ) use ( $STATUS_RULE ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || ! wcep_a_order_targets_rule( $order ) ) {
		return;
	}
	$identity = 'refund:' . $refund_id;
	$res      = wcep_a_claim( $order_id, $identity, $STATUS_RULE, $order->get_billing_email(), 'refund subj' );
	wcep_poc_a_row( $GLOBALS['wcep_poc_a_current_scenario'] ?? '?', "refund #{$refund_id}", $identity, $res );
};
add_action( 'woocommerce_order_fully_refunded', $refund_handler, 10, 2 );
add_action( 'woocommerce_order_partially_refunded', $refund_handler, 10, 2 );

// =========================================================================
// S1 — checkout-draft → no claim.
// =========================================================================
$GLOBALS['wcep_poc_a_current_scenario'] = 'S1 checkout-draft';
$o1 = wcep_poc_make_order( array( $matched_product => 1 ), 'checkout-draft' );
wcep_poc_assert( 'S1 checkout-draft → 0 claims', 0 === wcep_a_count( $o1->get_id() ) );

// =========================================================================
// S2a — TRUE direct terminal status (zero items) → no immediate claim + defer.
// =========================================================================
$GLOBALS['wcep_poc_a_current_scenario'] = 'S2a true-direct-terminal';
$o2   = wc_create_order( array( 'status' => 'completed' ) );
$oid2 = $o2->get_id();
$GLOBALS['wcep_poc_fixture_orders'][] = $oid2;
wcep_poc_assert( 'S2a transition fired on a zero-item order', 0 === count( $o2->get_items() ) );
wcep_poc_assert( 'S2a NO immediate claim on the itemless targeted status', 0 === wcep_a_count( $oid2 ) );
wcep_poc_assert( 'S2a exactly ONE deferred re-evaluation scheduled', isset( $GLOBALS['wcep_a_deferrals'][ $oid2 . '|status:completed' ] ) );

// =========================================================================
// S2b — DEFERRED RECOVERY (IN-MEMORY SIMULATION of Action Scheduler).
// =========================================================================
$GLOBALS['wcep_poc_a_current_scenario'] = 'S2b deferred-recovery (sim)';
$o2->add_product( wc_get_product( $matched_product ), 1 );
$o2->calculate_totals();
$o2->save();
$deferred = wcep_a_run_deferral( $oid2, 'status:completed', $STATUS_RULE );
wcep_poc_a_row( 'S2b deferred-recovery (sim)', 'deferred re-eval', 'status:completed', $deferred );
wcep_poc_assert( 'S2b deferred re-eval claimed', 'claimed' === $deferred );
wcep_poc_assert( 'S2b exactly ONE claim under the ORIGINAL identity', 1 === wcep_a_count( $oid2 ) && 1 === wcep_a_count( $oid2, 'status:completed' ) );
wcep_poc_assert( 'S2b second deferral suppressed (single-deferral cap)', 'suppressed' === wcep_a_schedule_deferral( $oid2, 'status:completed' ) );
echo "  NOTE: the deferral queue here is an IN-MEMORY SIMULATION. Action Scheduler\n";
echo "        uniqueness, identity serialisation, cross-request execution, order\n";
echo "        reloading, and cancellation are NOT proven here — deferred to the\n";
echo "        production foundation phase.\n";

// =========================================================================
// S3 — attach-then-transition → one claim.
// =========================================================================
$GLOBALS['wcep_poc_a_current_scenario'] = 'S3 attach-then-transition';
$o3 = wcep_poc_make_order( array( $matched_product => 1 ), 'completed' );
wcep_poc_assert( 'S3 → 1 claim, identity status:completed', 1 === wcep_a_count( $o3->get_id() ) && 1 === wcep_a_count( $o3->get_id(), 'status:completed' ) );

// =========================================================================
// S4 — pending → processing → completed → distinct identities.
// =========================================================================
$GLOBALS['wcep_poc_a_current_scenario'] = 'S4 pending>processing>completed';
$o4 = wcep_poc_make_order( array( $matched_product => 1 ), 'pending' );
$o4->update_status( 'processing' );
$o4->update_status( 'completed' );
wcep_poc_assert( 'S4 → 2 distinct: status:processing + status:completed', 2 === wcep_a_count( $o4->get_id() ) && 1 === wcep_a_count( $o4->get_id(), 'status:processing' ) && 1 === wcep_a_count( $o4->get_id(), 'status:completed' ) );

// =========================================================================
// S5 — re-entry processing → on-hold → processing → one claim, second suppressed.
// =========================================================================
$GLOBALS['wcep_poc_a_current_scenario'] = 'S5 processing>on-hold>processing';
$o5 = wcep_poc_make_order( array( $matched_product => 1 ), 'pending' );
$o5->update_status( 'processing' );
$o5->update_status( 'on-hold' );
$o5->update_status( 'processing' );
wcep_poc_assert( 'S5 re-entry → 1 row for status:processing', 1 === wcep_a_count( $o5->get_id(), 'status:processing' ) );
wcep_poc_assert( 'S5 second processing suppressed (suppressed_count=1)', 1 === wcep_a_suppressed( $o5->get_id(), 'status:processing' ) );

// =========================================================================
// S6 — partial refund → refund:{id}.
// =========================================================================
$GLOBALS['wcep_poc_a_current_scenario'] = 'S6 partial-refund';
$o6     = wcep_poc_make_order( array( $matched_product => 1 ), 'completed' );
$refund = wcep_poc_make_refund( $o6, 5.0, 'POC-A partial refund' );
wcep_poc_assert( 'S6 refund object created', $refund instanceof WC_Order_Refund );
wcep_poc_assert( 'S6 partial refund → 1 claim refund:{id}', 1 === wcep_a_count( $o6->get_id(), 'refund:' . $refund->get_id() ) );

// =========================================================================
// S7 — double-fire → DB constraint blocks second; suppressed++.
// =========================================================================
$GLOBALS['wcep_poc_a_current_scenario'] = 'S7 double-fire';
$o7   = wcep_poc_make_order( array( $matched_product => 1 ), 'pending' );
$oid7 = $o7->get_id();
do_action( 'woocommerce_order_status_changed', $oid7, 'pending', 'processing', $o7 );
do_action( 'woocommerce_order_status_changed', $oid7, 'pending', 'processing', $o7 );
wcep_poc_assert( 'S7 double-fire → 1 row survives UNIQUE', 1 === wcep_a_count( $oid7, 'status:processing' ) );
wcep_poc_assert( 'S7 second fire suppressed', 1 === wcep_a_suppressed( $oid7, 'status:processing' ) );

// =========================================================================
// S8 — FAIL-CLOSED via claim_and_maybe_send(): failure sends NOTHING.
// =========================================================================
$GLOBALS['wcep_poc_a_current_scenario'] = 'S8 fail-closed';
// Positive control: a fresh claim sends exactly one mail.
$o8 = wcep_poc_make_order( array( $matched_product => 1 ), 'pending' );
wcep_poc_mail_reset();
$r_ok = claim_and_maybe_send( $o8->get_id(), 'status:processing', $STATUS_RULE, 'a@example.test', 'ok' );
wcep_poc_assert( 'S8 control: fresh claim → claimed AND exactly 1 mail sent', 'claimed' === $r_ok && 1 === count( wcep_poc_mail_all() ) );
// Fail-closed: provoke a real query failure (missing table) → failed AND 0 mail.
$saved_tombstone = $tombstone;
$tombstone       = $wpdb->prefix . 'extonify_wcep_poc_MISSING_TABLE';
wcep_poc_mail_reset();
$r_fail = claim_and_maybe_send( $o3->get_id(), 'status:completed', $STATUS_RULE, 'x@example.test', 'x' );
$tombstone = $saved_tombstone;
wcep_poc_a_row( 'S8 fail-closed', 'claim vs missing table', 'status:completed', $r_fail );
wcep_poc_assert( 'S8 provoked failure → result FAILED', 'failed' === $r_fail );
wcep_poc_assert( 'S8 FAIL-CLOSED: ZERO mail sent on claim failure', 0 === count( wcep_poc_mail_all() ) );
wcep_poc_assert( 'S8 no phantom row in real tombstone from the failed claim', 1 === wcep_a_count( $o3->get_id(), 'status:completed' ) );

// =========================================================================
// S9 — tombstone survives purge + privacy erasure → re-fire suppressed.
// =========================================================================
$GLOBALS['wcep_poc_a_current_scenario'] = 'S9 tombstone-survival';
$o9   = wcep_poc_make_order( array( $matched_product => 1 ), 'completed' );
$oid9 = $o9->get_id();
wcep_poc_assert( 'S9 setup: 1 claim + 1 detail row', 1 === wcep_a_count( $oid9, 'status:completed' ) && 1 === wcep_a_detail_count( $oid9 ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$detail} d INNER JOIN {$tombstone} t ON d.tombstone_id = t.id SET d.recipient = NULL, d.subject = NULL, d.snapshot = NULL WHERE t.order_id = %d", $oid9 ) );
wcep_poc_assert( 'S9 privacy erasure nulled personal fields', 1 <= (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$detail} d INNER JOIN {$tombstone} t ON d.tombstone_id=t.id WHERE t.order_id=%d AND d.recipient IS NULL", $oid9 ) ) );
$wpdb->query( $wpdb->prepare( "DELETE d FROM {$detail} d INNER JOIN {$tombstone} t ON d.tombstone_id = t.id WHERE t.order_id = %d", $oid9 ) );
wcep_poc_assert( 'S9 retention purge removed detail rows', 0 === wcep_a_detail_count( $oid9 ) );
wcep_poc_assert( 'S9 tombstone SURVIVED purge + erasure', 1 === wcep_a_count( $oid9, 'status:completed' ) );
$refire = wcep_a_claim( $oid9, 'status:completed', $STATUS_RULE, $o9->get_billing_email(), 'refire' );
wcep_poc_a_row( 'S9 tombstone-survival', 're-fire after purge', 'status:completed', $refire );
wcep_poc_assert( 'S9 re-fire after purge is SUPPRESSED (not re-sent)', 'suppressed' === $refire );
wcep_poc_assert( 'S9 no new detail row from the suppressed re-fire', 0 === wcep_a_detail_count( $oid9 ) );

// =========================================================================
// S10 — TRANSITION IDENTITIES (ADR-0004): first claimed, exact repeat
// suppressed, and a status-rule + transition-rule on ONE event → two claims.
// =========================================================================
$GLOBALS['wcep_poc_a_current_scenario'] = 'S10 transitions';
$o10  = wcep_poc_make_order( array( $matched_product => 1 ), 'pending' );
$oid10 = $o10->get_id();
$GLOBALS['wcep_a_txn_orders'][] = $oid10; // enable the transition rule for this order.
$o10->update_status( 'processing' );        // pending>processing: status rule 101 + transition rule 111 both fire.
wcep_poc_assert( 'S10 first pending>processing → transition:pending>processing CLAIMED', 1 === wcep_a_count( $oid10, 'transition:pending>processing' ) );
wcep_poc_assert( 'S10 two DISTINCT claims on one event (status + transition)', 2 === wcep_a_count( $oid10 ) && 1 === wcep_a_count( $oid10, 'status:processing' ) && 1 === wcep_a_count( $oid10, 'transition:pending>processing' ) );
wcep_poc_assert( 'S10 the two claims belong to DIFFERENT rules (101 status, 111 transition)', $STATUS_RULE === wcep_a_rule_of( $oid10, 'status:processing' ) && $TXN_RULE === wcep_a_rule_of( $oid10, 'transition:pending>processing' ) );
// Exact transition repeated: processing → pending → processing.
$o10->update_status( 'pending' );
$o10->update_status( 'processing' );
wcep_poc_assert( 'S10 exact transition repeated → transition:pending>processing SUPPRESSED', 1 === wcep_a_suppressed( $oid10, 'transition:pending>processing' ) );
wcep_poc_assert( 'S10 still exactly ONE transition row after the repeat', 1 === wcep_a_count( $oid10, 'transition:pending>processing' ) );

// --- Matrix ---------------------------------------------------------------
wcep_poc_section( 'POC-A MATRIX  (event → identity → result)' );
printf( "  %-30s | %-30s | %-30s | %s\n", 'SCENARIO', 'EVENT', 'TRIGGER IDENTITY', 'RESULT' );
printf( "  %s\n", str_repeat( '-', 118 ) );
foreach ( $GLOBALS['wcep_poc_a_matrix'] as $r ) {
	printf( "  %-30s | %-30s | %-30s | %s\n", $r['scenario'], $r['event'], $r['identity'], $r['result'] );
}

wcep_poc_cleanup();
wcep_poc_assert( 'cleanup verify-after-delete OK — no fixture leak', ! empty( $GLOBALS['wcep_poc_cleanup_report']['ok'] ), 'leaks=' . implode( ',', $GLOBALS['wcep_poc_cleanup_report']['leaks'] ) );
wcep_poc_summary();
echo "\nPOC-A OK\n";