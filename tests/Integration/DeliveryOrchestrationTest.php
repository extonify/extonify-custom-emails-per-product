<?php
/**
 * The delivery spine end to end (ADR-0012).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\DeliveryLogger;
use Extonify\WCEP\Delivery\RunOutcome;
use Extonify\WCEP\Domain\MatchDecision;
use Extonify\WCEP\Domain\TriggerEvent;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * Event to identity to claim to send to log.
 *
 * Every "an email was sent" assertion reads the `pre_wp_mail` capture, never a
 * return value — a method reporting success is not evidence a message existed.
 */
final class DeliveryOrchestrationTest extends DeliveryTestCase {

	/**
	 * 1. END TO END: a matching order transitions to processing, exactly one
	 *    email is captured, one tombstone is claimed, detail rows are written,
	 *    and the final status is `sent`.
	 *
	 * @return void
	 */
	public function test_end_to_end_delivery() {
		$product_id = $this->make_simple_product( 'WCEP E2E' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule(
			$product_id,
			array( 'trigger_value' => 'processing' )
		);

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		// ONE email, and it is the one this rule describes.
		$this->assertMailCount( 1 );
		$mail = $this->last_mail();
		$this->assertSame( 'Care guide for your order', $mail['subject'] );
		$this->assertSame( 'wcep-matching@example.test', $mail['to'] );
		$this->assertStringContainsString( 'Hand wash only.', (string) $mail['message'] );

		// ONE tombstone, under the status identity.
		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:processing' );
		$this->assertNotNull( $tombstone, 'The delivery identity was not claimed.' );
		$this->track_delivery( (int) $tombstone['id'] );

		$this->assertSame( 'separate', $tombstone['mode'] );
		$this->assertSame( 'status:processing', $tombstone['trigger_identity'] );
		$this->assertSame( 'sent', $tombstone['final_status'] );
		$this->assertSame( 0, (int) $tombstone['suppressed_count'] );

		// One detail row per resolved recipient, carrying the address the
		// privacy eraser will later search on.
		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'sent', $rows[0]['state'] );
		$this->assertSame( 'auto', $rows[0]['type'] );
		$this->assertSame( 'wcep-matching@example.test', $rows[0]['recipient'] );
		$this->assertSame( 'to', $rows[0]['recipient_type'] );
		$this->assertSame( 'Care guide for your order', $rows[0]['subject'] );
	}

	/**
	 * 2. IDEMPOTENCY: firing the same transition twice sends once. The second
	 *    claim is suppressed, the counter increments, and no second email
	 *    exists.
	 *
	 * @return void
	 */
	public function test_the_same_trigger_twice_sends_once() {
		$product_id = $this->make_simple_product( 'WCEP Idempotent' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule( $product_id, array( 'trigger_value' => 'processing' ) );

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );
		$this->assertMailCount( 1, 'The first firing should send exactly one email.' );

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$this->assertMailCount( 1, 'The second firing sent a duplicate email.' );

		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:processing' );
		$this->track_delivery( (int) $tombstone['id'] );

		$this->assertSame( 1, (int) $tombstone['suppressed_count'], 'The duplicate was not counted.' );
		$this->assertSame( 'sent', $tombstone['final_status'], 'A suppressed retry rewrote the outcome.' );

		// A suppressed claim writes NO new row: ADR-0005's bounded log survives
		// a retry storm.
		$this->assertCount( 1, $this->detail_rows( (int) $tombstone['id'] ) );
	}

	/**
	 * 3. BOTH FAMILIES: one status change produces two identities; with a rule
	 *    in each family, two independent claims and two emails.
	 *
	 * @return void
	 */
	public function test_both_trigger_families_claim_independently() {
		$product_id = $this->make_simple_product( 'WCEP Families' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$status_rule     = $this->make_sending_rule(
			$product_id,
			array(
				'name'          => 'status family',
				'trigger_type'  => 'status',
				'trigger_value' => 'completed',
			)
		);
		$transition_rule = $this->make_sending_rule(
			$product_id,
			array(
				'name'          => 'transition family',
				'trigger_type'  => 'transition',
				'trigger_value' => 'processing>completed',
			)
		);

		$this->orchestrator()->handle_status_change( $order_id, 'processing', 'completed' );

		$this->assertMailCount( 2, 'Each family should have sent its own email.' );

		$by_status     = $this->tombstone( $order_id, $status_rule, 'status:completed' );
		$by_transition = $this->tombstone( $order_id, $transition_rule, 'transition:processing>completed' );

		$this->assertNotNull( $by_status );
		$this->assertNotNull( $by_transition );
		$this->track_delivery( (int) $by_status['id'] );
		$this->track_delivery( (int) $by_transition['id'] );

		$this->assertNotSame(
			$by_status['identity_hash'],
			$by_transition['identity_hash'],
			'The two families must produce two DISTINCT identities (ADR-0011 §1).'
		);
		$this->assertSame( 'sent', $by_status['final_status'] );
		$this->assertSame( 'sent', $by_transition['final_status'] );
	}

	/**
	 * 4. CLAIM FAILURE FAILS CLOSED: zero emails, and a failure is recorded.
	 *
	 * The failure is forced at the database layer — the claim statement itself
	 * fails — because that is the real shape of the fault, and asserting on a
	 * mocked return value would prove only that the mock works.
	 *
	 * @return void
	 */
	public function test_a_failed_claim_never_sends() {
		global $wpdb;

		$product_id = $this->make_simple_product( 'WCEP Claim Fail' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$this->make_sending_rule( $product_id, array( 'trigger_value' => 'processing' ) );

		// Break the INSERT into the tombstone table, and only that statement.
		$breaker = static function ( $query ) {
			if ( 1 === preg_match( '/^\s*INSERT INTO \S*extonify_wcep_deliveries\b/i', (string) $query ) ) {
				return 'INSERT INTO extonify_wcep_no_such_table_for_this_test (id) VALUES (1)';
			}
			return $query;
		};

		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $breaker );

		try {
			$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );
		} finally {
			remove_filter( 'query', $breaker );
			$wpdb->suppress_errors( $suppressed );
			$wpdb->last_error = '';
		}

		// THE ASSERTION THAT MATTERS: nothing was sent.
		$this->assertMailCount( 0, 'A failed claim sent an email — the fail-closed rule was broken.' );

		// And nothing was claimed either, so the identity stays available.
		$this->assertSame( array(), $this->tombstones_for( $order_id ) );
	}

	/**
	 * 4b. A claim that FAILS after producing a delivery id records a `failed`
	 *     detail row and still sends nothing.
	 *
	 * Driven through the logger directly, because a real claim cannot both
	 * return an id and fail — this proves the branch the orchestrator relies on
	 * when the repository reports a partial failure.
	 *
	 * @return void
	 */
	public function test_a_claim_failure_with_a_delivery_id_records_a_failed_row() {
		$product_id = $this->make_simple_product( 'WCEP Claim Fail Row' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();
		$rule_id    = $this->make_sending_rule( $product_id );

		$claim = $this->deliveries->claim( $order_id, $rule_id, DeliveryLogger::MODE, 'status:completed' );
		$this->track_delivery( (int) $claim['delivery_id'] );

		$logger = new DeliveryLogger( $this->deliveries, $this->details );
		$result = $logger->record_claim_failure(
			array(
				'result'      => DeliveryRepository::FAILED,
				'delivery_id' => (int) $claim['delivery_id'],
			),
			$order_id,
			$rule_id,
			'forced failure'
		);

		$rows = $this->detail_rows( (int) $claim['delivery_id'] );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'failed', $rows[0]['state'] );
		$this->assertStringContainsString( 'forced failure', (string) $rows[0]['reason'] );

		$this->assertSame( 'failed', $this->deliveries->find_by_id( (int) $claim['delivery_id'] )['final_status'] );
		$this->assertMailCount( 0 );

		// HELD TO THE VERIFIED-WRITE CONTRACT (ADR-0012 §3). This branch used to
		// insert and finalise while checking neither result.
		$this->assertSame(
			array(
				'success'       => true,
				'rows_expected' => 1,
				'rows_written'  => 1,
				'finalized'     => true,
			),
			$result
		);
	}

	/**
	 * 4c. A claim failure with NO delivery id — the shape the repository really
	 *     produces — reports itself as fully recorded, because the error log IS
	 *     the record.
	 *
	 * @return void
	 */
	public function test_a_claim_failure_without_a_delivery_id_reports_its_own_result() {
		$logger = new DeliveryLogger( $this->deliveries, $this->details );

		$before = $this->deliveries->count();

		$result = $logger->record_claim_failure(
			array(
				'result'      => DeliveryRepository::FAILED,
				'delivery_id' => 0,
			),
			0,
			0,
			'no identity to attach to'
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['rows_expected'] );
		$this->assertSame( $before, $this->deliveries->count(), 'An orphan row was written.' );
		$this->assertMailCount( 0 );
	}

	/**
	 * 4d. THE RUN CARRIES ITS LOGGING RESULTS (ADR-0012 §3, §11).
	 *
	 * The structured write results used to be discarded, so the orchestrator
	 * could not state a truthful aggregate outcome for a run — which is exactly
	 * what the delivery-history phase will ask it for.
	 *
	 * @return void
	 */
	public function test_a_run_reports_a_truthful_aggregate_outcome() {
		$sending_product = $this->make_simple_product( 'WCEP Outcome Sending' );
		$other_product   = $this->make_simple_product( 'WCEP Outcome Other' );
		$order           = $this->make_order_with( array( $sending_product, $other_product ) );

		$sends = $this->make_sending_rule( $sending_product, array( 'name' => 'sends' ) );

		// Claims and skips: no `to` recipient at all.
		$skips = $this->make_sending_rule(
			$other_product,
			array(
				'name'       => 'skips',
				'recipients' => array( 'cc' => array( 'nobody@example.test' ) ),
			)
		);

		// Never claims: ADR-0005's noise floor.
		$silent_product = $this->make_simple_product( 'WCEP Outcome Silent' );
		$silent         = $this->make_sending_rule( $silent_product, array( 'name' => 'no match' ) );

		$outcome = $this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertNotNull( $outcome );
		$this->assertSame( 1, $outcome->count_of( RunOutcome::SENT ) );
		$this->assertSame( 1, $outcome->count_of( RunOutcome::SKIPPED ) );
		$this->assertSame( 0, $outcome->count_of( RunOutcome::FAILED ) );
		$this->assertSame( 0, $outcome->count_of( RunOutcome::CLAIM_FAILED ) );
		$this->assertTrue( $outcome->is_fully_recorded() );
		$this->assertSame( array(), $outcome->shortfalls() );

		$acted_on = array();
		foreach ( $outcome->records() as $record ) {
			$acted_on[ $record['rule_id'] ] = $record['action'];
			$this->assertGreaterThan( 0, $record['delivery_id'], 'A recorded action carries no tombstone.' );
			$this->assertTrue( $record['finalized'] );
		}

		$this->assertSame( RunOutcome::SENT, $acted_on[ $sends ] ?? null );
		$this->assertSame( RunOutcome::SKIPPED, $acted_on[ $skips ] ?? null );
		$this->assertArrayNotHasKey( $silent, $acted_on, 'The noise floor produced a record.' );

		// The evaluation is still reachable, unchanged.
		$this->assertSame( 'status:completed', $outcome->evaluation()->trigger_identity() );

		foreach ( $this->tombstones_for( (int) $order->get_id() ) as $row ) {
			$this->assertContains( $row['final_status'], array( 'sent', 'skipped' ) );
		}
	}

	/**
	 * 4e. A SHORTFALL REACHES THE RUN OUTCOME. The logger already logs it; the
	 *     run must be able to say the delivery is not fully recorded.
	 *
	 * @return void
	 */
	public function test_a_write_shortfall_is_visible_in_the_run_outcome() {
		$product_id = $this->make_simple_product( 'WCEP Outcome Shortfall' );
		$order      = $this->make_order_with( array( $product_id ) );

		$this->make_sending_rule( $product_id );

		$details = new class() extends \Extonify\WCEP\Repository\DeliveryDetailRepository {
			/**
			 * Always fail.
			 *
			 * @param int   $delivery_id Tombstone id.
			 * @param array $data        Row.
			 * @return int
			 */
			public function insert( int $delivery_id, array $data ): int {
				return 0;
			}
		};

		$orchestrator = new \Extonify\WCEP\Delivery\Orchestrator(
			$this->rules,
			new \Extonify\WCEP\Matching\RuleMatcher( $this->rules, new \Extonify\WCEP\Matching\ItemResolver() ),
			new DeliveryLogger( $this->deliveries, $details )
		);

		$outcome = $orchestrator->run( $order, TriggerEvent::status( 'completed' ) );

		$this->tombstones_for( (int) $order->get_id() );

		$this->assertNotNull( $outcome );
		$this->assertSame( 1, $outcome->count_of( RunOutcome::SENT ), 'The message itself still went out.' );
		$this->assertFalse( $outcome->is_fully_recorded(), 'A total detail-row failure was reported as fully recorded.' );
		$this->assertCount( 1, $outcome->shortfalls() );
		$this->assertSame( 1, $outcome->shortfalls()[0]['rows_expected'] );
		$this->assertSame( 0, $outcome->shortfalls()[0]['rows_written'] );
	}

	/**
	 * 5. SKIP LOGGING (ADR-0012 §2): `excluded_by_rule` claims and writes a
	 *    reason; `no_targeting_match` writes NOTHING.
	 *
	 * @return void
	 */
	public function test_excluded_claims_and_no_match_writes_nothing() {
		$product_id = $this->make_simple_product( 'WCEP Skip' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$excluded = $this->make_sending_rule(
			$product_id,
			array(
				'name'      => 'excluded',
				'targeting' => array(
					'include' => array( 'products' => array( $product_id ) ),
					'exclude' => array( 'products' => array( $product_id ) ),
				),
			)
		);
		$no_match = $this->make_sending_rule(
			$product_id,
			array(
				'name'      => 'no match',
				'targeting' => array( 'include' => array( 'products' => array( 987654321 ) ) ),
			)
		);

		$result = $this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertSame( MatchDecision::EXCLUDED_BY_RULE, $result->evaluation()->decision_for( $excluded )->reason() );
		$this->assertSame( MatchDecision::NO_TARGETING_MATCH, $result->evaluation()->decision_for( $no_match )->reason() );
		$this->assertMailCount( 0 );

		// The excluded rule CONSUMED its identity and said why.
		$tombstone = $this->tombstone( $order_id, $excluded, 'status:completed' );
		$this->assertNotNull( $tombstone, 'excluded_by_rule must claim (ADR-0012 §2).' );
		$this->track_delivery( (int) $tombstone['id'] );
		$this->assertSame( 'skipped', $tombstone['final_status'] );

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'skipped', $rows[0]['state'] );
		$this->assertStringContainsString( MatchDecision::EXCLUDED_BY_RULE, (string) $rows[0]['reason'] );

		// The noise floor stayed silent — ADR-0005's bounded log.
		$this->assertNull(
			$this->tombstone( $order_id, $no_match, 'status:completed' ),
			'no_targeting_match must write nothing at all.'
		);
	}

	/**
	 * 5b. `targeting_invalid` and `rule_disabled` claim too, each with its
	 *     reason recorded.
	 *
	 * @return void
	 */
	public function test_the_other_claiming_skips() {
		$product_id = $this->make_simple_product( 'WCEP Other Skips' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$broken = $this->make_sending_rule( $product_id, array( 'name' => 'broken targeting' ) );
		$this->force_raw_targeting( $broken, '{"include":{"products":[' );

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$tombstone = $this->tombstone( $order_id, $broken, 'status:completed' );
		$this->assertNotNull( $tombstone, 'targeting_invalid must claim (ADR-0012 §2).' );
		$this->track_delivery( (int) $tombstone['id'] );

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertSame( 'skipped', $rows[0]['state'] );
		$this->assertStringContainsString( MatchDecision::TARGETING_INVALID, (string) $rows[0]['reason'] );
		$this->assertMailCount( 0 );
	}

	/**
	 * 6. STOP FLAG: the halting rule records the blocked rule ids on its OWN
	 *    row, and NO tombstone exists for any blocked rule — so removing the
	 *    flag genuinely re-enables them.
	 *
	 * @return void
	 */
	public function test_a_halt_writes_no_tombstone_for_the_blocked_rules() {
		$product_id = $this->make_simple_product( 'WCEP Halt' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();
		$targeting  = array( 'include' => array( 'products' => array( $product_id ) ) );

		$halting = $this->make_sending_rule(
			$product_id,
			array(
				'name'            => 'halts',
				'priority'        => 5,
				'targeting'       => $targeting,
				'stop_processing' => 1,
			)
		);
		$blocked_a = $this->make_sending_rule( $product_id, array( 'name' => 'blocked a', 'priority' => 10 ) );
		$blocked_b = $this->make_sending_rule( $product_id, array( 'name' => 'blocked b', 'priority' => 20 ) );

		$result = $this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertSame( MatchDecision::MATCHED, $result->evaluation()->decision_for( $halting )->reason() );
		$this->assertSame( MatchDecision::BLOCKED_BY_STOP_FLAG, $result->evaluation()->decision_for( $blocked_a )->reason() );
		$this->assertSame( MatchDecision::BLOCKED_BY_STOP_FLAG, $result->evaluation()->decision_for( $blocked_b )->reason() );

		$this->assertMailCount( 1, 'Only the halting rule should have sent.' );

		// NO TOMBSTONE FOR A BLOCKED RULE. This is the load-bearing assertion:
		// claiming one would silently prevent that rule from ever firing for
		// this order, even after the merchant removed the stop flag.
		foreach ( array( $blocked_a, $blocked_b ) as $blocked ) {
			$this->assertNull(
				$this->tombstone( $order_id, $blocked, 'status:completed' ),
				'A blocked rule consumed its identity (ADR-0012 §2).'
			);
		}

		// The halt IS recorded — on the stop rule's own row, once.
		$stop_tombstone = $this->tombstone( $order_id, $halting, 'status:completed' );
		$this->track_delivery( (int) $stop_tombstone['id'] );

		$rows = $this->detail_rows( (int) $stop_tombstone['id'] );
		$this->assertCount( 1, $rows, 'One recipient, one row carrying the halt record.' );

		$snapshot = $rows[0]['snapshot'];
		$this->assertIsArray( $snapshot );
		$this->assertArrayHasKey( 'blocked_by_stop_flag', $snapshot );
		$this->assertSame( 2, (int) $snapshot['blocked_by_stop_flag']['count'] );
		$this->assertSame(
			array( $blocked_a, $blocked_b ),
			array_map( 'intval', $snapshot['blocked_by_stop_flag']['rule_ids'] ),
			'The blocked rule ids must be recoverable from the log.'
		);
	}

	/**
	 * 6b. Once the stop flag is removed, a previously blocked rule CAN claim —
	 *     under a different identity, because the first trigger is spent.
	 *
	 * @return void
	 */
	public function test_a_blocked_rule_can_still_claim_after_the_flag_is_removed() {
		$product_id = $this->make_simple_product( 'WCEP Unblocked' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$halting = $this->make_sending_rule(
			$product_id,
			array(
				'name'            => 'halts',
				'priority'        => 5,
				'trigger_value'   => 'processing',
				'stop_processing' => 1,
			)
		);
		$blocked = $this->make_sending_rule(
			$product_id,
			array(
				'name'          => 'blocked then freed',
				'priority'      => 10,
				'trigger_value' => 'processing',
			)
		);

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );
		$this->assertMailCount( 1 );
		$this->assertNull( $this->tombstone( $order_id, $blocked, 'status:processing' ) );

		// The merchant removes the flag and the order moves on.
		$this->assertTrue( $this->rules->update( $halting, array( 'stop_processing' => 0 ) ) );
		$this->assertTrue( $this->rules->update( $halting, array( 'trigger_value' => 'completed' ) ) );
		$this->assertTrue( $this->rules->update( $blocked, array( 'trigger_value' => 'completed' ) ) );

		$this->orchestrator()->handle_status_change( $order_id, 'processing', 'completed' );

		$freed = $this->tombstone( $order_id, $blocked, 'status:completed' );
		$this->assertNotNull( $freed, 'The previously blocked rule could not claim after the flag was removed.' );
		$this->track_delivery( (int) $freed['id'] );
		$this->assertSame( 'sent', $freed['final_status'] );
	}

	/**
	 * 7. RECIPIENTS: customer + cc + bcc writes THREE rows with the right
	 *    types, and the message carries the CC and BCC headers.
	 *
	 * @return void
	 */
	public function test_customer_cc_and_bcc_write_three_rows_and_set_headers() {
		$product_id = $this->make_simple_product( 'WCEP Recipients' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule(
			$product_id,
			array(
				'recipients' => array(
					'to'  => array( 'customer' ),
					'cc'  => array( 'shop@example.test' ),
					'bcc' => array( 'archive@example.test' ),
				),
			)
		);

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 1 );
		$headers = $this->headers_of( $this->last_mail() );
		$this->assertStringContainsString( 'Cc: shop@example.test', $headers );
		$this->assertStringContainsString( 'Bcc: archive@example.test', $headers );

		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:completed' );
		$this->track_delivery( (int) $tombstone['id'] );

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 3, $rows, 'One row per resolved recipient (ADR-0009, ADR-0012 §4).' );

		$by_type = array();
		foreach ( $rows as $row ) {
			$by_type[ $row['recipient_type'] ] = $row['recipient'];
		}

		$this->assertSame(
			array(
				'to'  => 'wcep-matching@example.test',
				'cc'  => 'shop@example.test',
				'bcc' => 'archive@example.test',
			),
			$by_type
		);
	}

	/**
	 * 8. ZERO RECIPIENTS: nothing sends, and the reason is recorded.
	 *
	 * @dataProvider undeliverable_recipients_provider
	 *
	 * @param array|string $recipients Recipients document.
	 * @param string       $label      What is wrong with it.
	 * @return void
	 */
	public function test_a_rule_resolving_no_recipient_sends_nothing( $recipients, string $label ) {
		$product_id = $this->make_simple_product( 'WCEP No Recipients ' . md5( $label ) );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule( $product_id, array( 'recipients' => (array) $recipients ) );

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 0, $label . ' sent an email with no deliverable recipient.' );

		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:completed' );
		$this->assertNotNull( $tombstone, $label );
		$this->track_delivery( (int) $tombstone['id'] );
		$this->assertSame( 'skipped', $tombstone['final_status'], $label );

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 1, $rows, $label );
		$this->assertSame( 'skipped', $rows[0]['state'], $label );
		$this->assertNotSame( '', (string) $rows[0]['reason'], $label . ' left no reason in the log.' );
	}

	/**
	 * Recipient documents that cannot produce a delivery.
	 *
	 * @return array<string,array{0:array,1:string}>
	 */
	public static function undeliverable_recipients_provider(): array {
		return array(
			'no document'   => array( array(), 'an empty recipients document' ),
			'cc only'       => array( array( 'cc' => array( 'shop@example.test' ) ), 'cc without to' ),
			'bcc only'      => array( array( 'bcc' => array( 'shop@example.test' ) ), 'bcc without to' ),
			'invalid to'    => array( array( 'to' => array( 'not-an-address' ) ), 'an invalid to address' ),
			'empty to list' => array( array( 'to' => array() ), 'an empty to list' ),
		);
	}

	/**
	 * 10. KILL SWITCH: with the email disabled in WooCommerce settings nothing
	 *     sends, NOTHING IS CLAIMED, and re-enabling then re-firing delivers.
	 *
	 * The switch is checked BEFORE the claim (ADR-0012 §5). Claiming first and
	 * discovering the switch afterwards consumed the identity permanently, so
	 * re-enabling and re-firing the same trigger was suppressed — the merchant's
	 * switch was a one-way door. The previous version of this test asserted only
	 * that `is_enabled()` came back true afterwards; it never re-fired, so it
	 * could not have caught that.
	 *
	 * @return void
	 */
	public function test_the_kill_switch_claims_nothing_and_re_enabling_delivers() {
		$product_id = $this->make_simple_product( 'WCEP Kill Switch' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule( $product_id );

		$this->set_email_setting( 'enabled', 'no' );
		$this->assertFalse( $this->live_email()->is_enabled(), 'The fixture did not actually disable the email.' );

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 0, 'The global kill switch did not stop the send.' );

		// NOTHING CONSUMED. This is the assertion the old test lacked.
		$this->assertNull(
			$this->tombstone( $order_id, $rule_id, 'status:completed' ),
			'The kill switch consumed a delivery identity while the feature was off.'
		);
		$this->assertSame( array(), $this->tombstones_for( $order_id ) );

		// RE-ENABLE AND RE-FIRE THE SAME TRIGGER. It must deliver.
		$this->set_email_setting( 'enabled', 'yes' );
		$this->assertTrue( $this->live_email()->is_enabled() );

		$this->orchestrator()->run( wc_get_order( $order_id ), TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 1, 'Re-enabling the switch did not restore delivery.' );

		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:completed' );
		$this->assertNotNull( $tombstone );
		$this->track_delivery( (int) $tombstone['id'] );
		$this->assertSame( 'sent', $tombstone['final_status'] );
		$this->assertSame( 0, (int) $tombstone['suppressed_count'], 'The disabled run had consumed the identity.' );
	}

	/**
	 * 10b. THE PER-DELIVERY FILTER IS NOT THE GLOBAL SWITCH (ADR-0012 §5a).
	 *
	 * `woocommerce_email_enabled_{id}` receives the order, so a third party may
	 * legitimately answer `true` for the store and `false` for one order. The
	 * pre-claim check used to call `is_enabled()` with NO order attached and read
	 * that per-order `false` as the global switch; the delivery was then claimed,
	 * `trigger()` returned `false`, and the outcome was recorded as a MAIL
	 * TRANSPORT FAILURE — wrong reason, consumed identity, suppressed retry.
	 *
	 * @return void
	 */
	public function test_a_filter_refusing_one_order_is_recorded_as_disabled_by_filter() {
		$product_id = $this->make_simple_product( 'WCEP Filter Refusal' );

		$refused  = $this->make_order_with( array( $product_id ) );
		$refused_id = (int) $refused->get_id();

		$allowed    = $this->make_order_with( array( $product_id ) );
		$allowed_id = (int) $allowed->get_id();

		$rule_id = $this->make_sending_rule( $product_id );

		// TRUE when asked with no object — the shape that used to be misread as
		// the global switch — and FALSE for one specific order.
		$filter = static function ( $enabled, $mail_object = null ) use ( $refused_id ) {
			if ( $mail_object instanceof \WC_Order && (int) $mail_object->get_id() === $refused_id ) {
				return false;
			}
			return $enabled;
		};

		add_filter( 'woocommerce_email_enabled_' . \Extonify\WCEP\Email\EmailIdentity::EMAIL_ID, $filter, 10, 3 );

		try {
			$this->assertTrue(
				$this->live_email()->is_enabled(),
				'The fixture filter must answer TRUE when there is no order, or it proves nothing.'
			);

			$refused_outcome = $this->orchestrator()->run( $refused, TriggerEvent::status( 'completed' ) );
			$allowed_outcome = $this->orchestrator()->run( $allowed, TriggerEvent::status( 'completed' ) );
		} finally {
			remove_filter( 'woocommerce_email_enabled_' . \Extonify\WCEP\Email\EmailIdentity::EMAIL_ID, $filter, 10 );
		}

		// The refused order sent nothing; the other order was unaffected.
		$this->assertMailCount( 1, 'The per-order refusal suppressed the wrong number of messages.' );
		$this->assertSame( 'wcep-matching@example.test', $this->last_mail()['to'] );

		// THE REFUSED DELIVERY: claimed, recorded `skipped`, reason names the
		// filter, and NO `failed` transport row anywhere.
		$tombstone = $this->tombstone( $refused_id, $rule_id, 'status:completed' );
		$this->assertNotNull( $tombstone, 'disabled_by_filter claims (ADR-0012 §5a).' );
		$this->track_delivery( (int) $tombstone['id'] );

		$this->assertSame( 'skipped', $tombstone['final_status'], 'A deliberate filter refusal was recorded as a failure.' );

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'skipped', $rows[0]['state'] );
		$this->assertNotSame( 'failed', $rows[0]['state'], 'The mailer was blamed for a decision it never saw.' );
		$this->assertNull( $rows[0]['failure_message'], 'A filter refusal wrote a transport failure message.' );

		$reason = (string) $rows[0]['reason'];
		$this->assertStringContainsString( 'disabled_by_filter', $reason );
		$this->assertStringContainsString(
			'woocommerce_email_enabled_' . \Extonify\WCEP\Email\EmailIdentity::EMAIL_ID,
			$reason,
			'The recorded reason does not name the filter that refused.'
		);

		fwrite( STDERR, "\n[4b item 3] recorded reason: {$reason}\n" );

		// And the run reports it as a skip, not a failure.
		$this->assertNotNull( $refused_outcome );
		$this->assertSame( 1, $refused_outcome->count_of( RunOutcome::SKIPPED ) );
		$this->assertSame( 0, $refused_outcome->count_of( RunOutcome::FAILED ) );
		$this->assertSame( 0, $refused_outcome->count_of( RunOutcome::SENT ) );

		$this->assertNotNull( $allowed_outcome );
		$this->assertSame( 1, $allowed_outcome->count_of( RunOutcome::SENT ) );

		foreach ( $this->tombstones_for( $allowed_id ) as $row ) {
			$this->assertSame( 'sent', $row['final_status'] );
		}
	}

	/**
	 * 10c. The GLOBAL switch reads the saved setting only, so a per-order filter
	 *      cannot make the feature look switched off.
	 *
	 * @return void
	 */
	public function test_the_global_switch_ignores_the_per_delivery_filter() {
		$email = $this->live_email();

		$always_false = '__return_false';
		add_filter( 'woocommerce_email_enabled_' . \Extonify\WCEP\Email\EmailIdentity::EMAIL_ID, $always_false );

		try {
			$this->assertFalse( $email->is_enabled(), 'The fixture filter did not take effect.' );
			$this->assertTrue(
				$email->is_globally_enabled(),
				'The global switch consulted the per-delivery filter.'
			);
		} finally {
			remove_filter( 'woocommerce_email_enabled_' . \Extonify\WCEP\Email\EmailIdentity::EMAIL_ID, $always_false );
		}

		// And it really does track the saved setting.
		$this->set_email_setting( 'enabled', 'no' );
		$this->assertFalse( $this->live_email()->is_globally_enabled() );

		$this->set_email_setting( 'enabled', 'yes' );
		$this->assertTrue( $this->live_email()->is_globally_enabled() );
	}

	/**
	 * 13. SCHEMA NOT OPERATIONAL: orchestration is inert — no claim, no send,
	 *     no log.
	 *
	 * @return void
	 */
	public function test_orchestration_is_inert_without_a_working_schema() {
		$product_id = $this->make_simple_product( 'WCEP Degraded' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$this->make_sending_rule( $product_id );

		$before = $this->deliveries->count();

		// `Migrator::is_operational()` caches its answer, so poison the cache
		// rather than dropping a table: the assertion is about the guard, not
		// about DDL.
		$this->force_schema_not_operational();

		try {
			$result = $this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );
		} finally {
			\Extonify\WCEP\Install\Migrator::flush_schema_cache();
		}

		$this->assertNull( $result, 'Orchestration ran while the schema was not operational.' );
		$this->assertMailCount( 0 );
		$this->assertSame( $before, $this->deliveries->count(), 'A tombstone was written in degraded mode.' );
	}

	/**
	 * 13b. A preview render makes orchestration inert.
	 *
	 * @return void
	 */
	public function test_orchestration_is_inert_during_a_preview() {
		$product_id = $this->make_simple_product( 'WCEP Preview' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$this->make_sending_rule( $product_id );

		$before  = $this->deliveries->count();
		$preview = '__return_true';

		add_filter( 'woocommerce_is_email_preview', $preview );

		try {
			$result = $this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );
		} finally {
			remove_filter( 'woocommerce_is_email_preview', $preview );
		}

		$this->assertNull( $result, 'Orchestration ran during a preview render.' );
		$this->assertMailCount( 0 );
		$this->assertSame( $before, $this->deliveries->count() );
	}

	/**
	 * Gate 6. One delivery on a 10-item order against 20 rules: the query cost
	 * does not scale with rules MULTIPLIED BY items.
	 *
	 * The matcher's own bound is ADR-0011 §9 and is already proven by
	 * `MatchingPurityTest`; what is measured here is the DELIVERY cost on top of
	 * it — the claim, the rule read and the detail rows — which must be a
	 * function of the rules that actually send, not of the candidate set.
	 *
	 * @return void
	 */
	public function test_query_cost_does_not_scale_with_rules_times_items() {
		global $wpdb;

		$products = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$products[] = $this->make_simple_product( 'WCEP Cost ' . $i );
		}

		$order    = $this->make_order_with( $products );
		$order_id = (int) $order->get_id();

		// One rule sends; the other nineteen are candidates that match nothing,
		// so they must add no delivery cost at all.
		$this->make_sending_rule( $products[0], array( 'name' => 'sends' ) );

		for ( $i = 0; $i < 19; $i++ ) {
			$this->make_sending_rule(
				$products[0],
				array(
					'name'      => 'noise ' . $i,
					'targeting' => array( 'include' => array( 'products' => array( 987654321 ) ) ),
				)
			);
		}

		$before = $wpdb->num_queries;
		$this->orchestrator()->run( wc_get_order( $order_id ), TriggerEvent::status( 'completed' ) );
		$cost = $wpdb->num_queries - $before;

		$this->assertMailCount( 1 );

		// rules × items would be 20 × 10 = 200.
		$this->assertLessThan(
			200,
			$cost,
			"One delivery on a 10-item order against 20 rules took {$cost} queries; a per-rule-per-item implementation would cost 200."
		);
		$this->assertLessThanOrEqual(
			120,
			$cost,
			"One delivery cost {$cost} queries; the bound is the matcher's per-DISTINCT-PRODUCT cost plus a constant per SENDING rule."
		);

		fwrite(
			STDERR,
			"\n[gate 6] one delivery, 10-item order, 20 rules (1 sending): {$cost} queries.\n"
		);
	}

	/**
	 * Make `Migrator::is_operational()` report false without touching the
	 * schema.
	 *
	 * @return void
	 */
	private function force_schema_not_operational(): void {
		$reflection = new \ReflectionClass( \Extonify\WCEP\Install\Migrator::class );
		$property   = $reflection->getProperty( 'schema_ok' );
		$property->setAccessible( true );
		$property->setValue( null, false );

		$this->assertFalse(
			\Extonify\WCEP\Install\Migrator::is_operational(),
			'The fixture did not put the plugin into degraded mode.'
		);
	}
}
