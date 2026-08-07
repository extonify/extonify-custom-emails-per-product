<?php
/**
 * Cancellation, from both directions (ADR-0007, ADR-0015 §4, §5).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\ScheduledCancellation;
use Extonify\WCEP\Delivery\ScheduledDelivery;
use Extonify\WCEP\Install\Deactivator;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * TWO PATHS STOP A QUEUED DELIVERY, AND NEITHER REPLACES THE OTHER
 * (ADR-0015 §5).
 *
 *   - EAGER cancellation, when the merchant disables or deletes the rule. It
 *     cannot be COMPLETE — a row deleted in SQL, a restored database, a sweep
 *     that failed part way — but it is the only one that is timely.
 *   - EXECUTION-TIME re-validation, when the job finally runs. It cannot be
 *     TIMELY — it runs after the delay the merchant wanted to stop — but it is
 *     the only one that is complete.
 *
 * Every cancellation carries its OWN reason. A single generic "cancelled" would
 * leave the delivery log unable to answer the one question anyone asks of it.
 */
final class ScheduledRevalidationTest extends ScheduledDeliveryTestCase {

	/**
	 * Schedule one delayed delivery and hand back everything a test needs.
	 *
	 * @param array $rule_overrides Rule fields to replace.
	 * @param int   $lines          How many distinct products the order carries.
	 * @return array{order_id:int,rule_id:int,delivery_id:int,products:int[]}
	 */
	private function schedule_one( array $rule_overrides = array(), int $lines = 1 ): array {
		$products = array();

		for ( $i = 0; $i < $lines; $i++ ) {
			$products[] = $this->make_simple_product( 'WCEP Revalidate ' . wp_generate_password( 6, false ) );
		}

		$order    = $this->delayed_order( $products );
		$order_id = (int) $order->get_id();

		$rule_id = $this->make_delayed_rule(
			$products[0],
			array_merge(
				array( 'targeting' => array( 'include' => array( 'products' => $products ) ) ),
				$rule_overrides
			)
		);

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$tombstone = $this->scheduled_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone, 'the fixture did not schedule' );
		$this->assertSame( DeliveryRepository::SCHEDULED, (string) $tombstone['final_status'] );

		return array(
			'order_id'    => $order_id,
			'rule_id'     => $rule_id,
			'delivery_id' => (int) $tombstone['id'],
			'products'    => $products,
		);
	}

	/**
	 * Assert one delivery ended cancelled, with the expected reason and no mail.
	 *
	 * @param int    $delivery_id Tombstone id.
	 * @param string $code        Expected `reason_code`.
	 * @param string $where       Description for failure messages.
	 * @return string The recorded reason sentence, for the report.
	 */
	private function assert_cancelled( int $delivery_id, string $code, string $where ): string {
		// Scoped by `run_job()`, which clears the capture first — WooCommerce
		// sends its own cancelled/refunded notifications during these fixtures.
		$this->assertMailCount( 0, $where . ': a cancelled delivery still sent' );

		// Belt and braces on the identity of what did NOT go: no captured message
		// anywhere in this test carries this rule's marker.
		foreach ( $this->captured_mail as $mail ) {
			$this->assertStringNotContainsString( 'DELAYED BLOCK', (string) $mail['message'], $where );
		}

		$final = $this->deliveries->find_by_id( $delivery_id );

		$this->assertSame( 'cancelled', (string) $final['final_status'], $where );
		$this->assertSame( $code, $this->cancellation_code( $delivery_id ), $where . ': the wrong reason was recorded' );
		$this->assertNull( $this->raw_snapshot_of( $delivery_id ), $where . ': a terminal delivery kept its snapshot' );

		return $this->reasons_of( $delivery_id );
	}

	// -----------------------------------------------------------------------
	// §4 — EXECUTION-TIME RE-VALIDATION, one test per row of the table.
	// -----------------------------------------------------------------------

	/**
	 * §4 check 1 — the rule was DELETED during the delay.
	 *
	 * @return void
	 */
	public function test_check_1_rule_deleted() {
		$fixture = $this->schedule_one();

		// Deleted directly through the repository, with eager cancellation
		// unhooked: this is the BACKSTOP path, and testing it needs the eager
		// path out of the way or it would never be reached.
		remove_action( ScheduledCancellation::ACTION_DELETED, array( ScheduledCancellation::class, 'on_rule_deleted' ), 10 );

		try {
			$this->rules->delete( $fixture['rule_id'] );
			$this->run_job( $fixture['delivery_id'], $fixture['order_id'] );
		} finally {
			add_action( ScheduledCancellation::ACTION_DELETED, array( ScheduledCancellation::class, 'on_rule_deleted' ), 10, 1 );
		}

		$reason = $this->assert_cancelled( $fixture['delivery_id'], ScheduledDelivery::REASON_RULE_DELETED, 'check 1' );

		$this->report_row( '1  rule deleted', ScheduledDelivery::REASON_RULE_DELETED, $reason );
	}

	/**
	 * §4 check 2 — the rule was DISABLED during the delay.
	 *
	 * @return void
	 */
	public function test_check_2_rule_disabled() {
		$fixture = $this->schedule_one();

		remove_action( ScheduledCancellation::ACTION_UPDATED, array( ScheduledCancellation::class, 'on_rule_updated' ), 10 );

		try {
			$this->rules->update( $fixture['rule_id'], array( 'status' => 'inactive' ) );
			$this->run_job( $fixture['delivery_id'], $fixture['order_id'] );
		} finally {
			add_action( ScheduledCancellation::ACTION_UPDATED, array( ScheduledCancellation::class, 'on_rule_updated' ), 10, 1 );
		}

		$reason = $this->assert_cancelled( $fixture['delivery_id'], ScheduledDelivery::REASON_RULE_DISABLED, 'check 2' );

		$this->report_row( '2  rule disabled', ScheduledDelivery::REASON_RULE_DISABLED, $reason );
	}

	/**
	 * §4 check 3 — the rule LEFT THE PHASE: its delay changed.
	 *
	 * ⚠ NOT REDUNDANT WITH CHECKS 1 AND 2. A merchant who re-times a rule from one
	 * hour to one week has a queued job whose scheduled time no longer means
	 * anything they asked for, and the rule is neither deleted nor disabled.
	 *
	 * @return void
	 */
	public function test_check_3_rule_left_the_phase() {
		$fixture = $this->schedule_one();

		remove_action( ScheduledCancellation::ACTION_UPDATED, array( ScheduledCancellation::class, 'on_rule_updated' ), 10 );

		try {
			$this->rules->update( $fixture['rule_id'], array( 'delay_seconds' => WEEK_IN_SECONDS ) );
			$this->run_job( $fixture['delivery_id'], $fixture['order_id'] );
		} finally {
			add_action( ScheduledCancellation::ACTION_UPDATED, array( ScheduledCancellation::class, 'on_rule_updated' ), 10, 1 );
		}

		$reason = $this->assert_cancelled( $fixture['delivery_id'], ScheduledDelivery::REASON_RULE_LEFT_PHASE, 'check 3' );

		$this->report_row( '3  rule left phase', ScheduledDelivery::REASON_RULE_LEFT_PHASE, $reason );
	}

	/**
	 * §4 check 3 again — the rule's CONSOLIDATION CHANGED during the delay
	 * (ADR-0016 §8).
	 *
	 * ⚠ THE REASON THIS CANCELS HAS CHANGED, AND THE TEST CHANGED WITH IT RATHER THAN
	 * BEING WEAKENED. It used to store `daily` and assert that acquiring an
	 * UNIMPLEMENTED consolidation took the rule out of every phase. Prompt 8 implements
	 * consolidation, so `daily` cannot be stored at all and a `per_product` rule is one
	 * this phase OWNS — what cancels now is the MISMATCH against the snapshot, exactly
	 * as a re-timed delay does: a merchant who switched a queued delivery from one
	 * message to one-per-product changed HOW MANY EMAILS it would send, and delivering
	 * the old shape would deliver a rule that no longer exists in that form.
	 *
	 * @return void
	 */
	public function test_check_3_rule_changed_its_consolidation() {
		$fixture = $this->schedule_one();

		remove_action( ScheduledCancellation::ACTION_UPDATED, array( ScheduledCancellation::class, 'on_rule_updated' ), 10 );

		try {
			$this->rules->update( $fixture['rule_id'], array( 'consolidation' => 'per_product' ) );

			// The write must have LANDED, or this test would pass because the rule never
			// changed rather than because the phase check caught it.
			$this->assertSame( 'per_product', (string) $this->rules->find( $fixture['rule_id'] )['consolidation'] );

			$this->run_job( $fixture['delivery_id'], $fixture['order_id'] );
		} finally {
			add_action( ScheduledCancellation::ACTION_UPDATED, array( ScheduledCancellation::class, 'on_rule_updated' ), 10, 1 );
		}

		$this->assert_cancelled( $fixture['delivery_id'], ScheduledDelivery::REASON_RULE_LEFT_PHASE, 'check 3 / consolidation' );
	}

	/**
	 * §4 check 4 — the ORDER was deleted during the delay.
	 *
	 * @return void
	 */
	public function test_check_4_order_deleted() {
		$fixture = $this->schedule_one();

		// Trashed, not force-deleted: `woocommerce_delete_order` would take the
		// tombstone with it (ADR-0004), and this check exists for the case where
		// the tombstone survives an order `wc_get_order()` can no longer return.
		$order = wc_get_order( $fixture['order_id'] );
		$order->delete( false );

		$this->run_job( $fixture['delivery_id'], $fixture['order_id'] );

		$reason = $this->assert_cancelled( $fixture['delivery_id'], ScheduledDelivery::REASON_ORDER_DELETED, 'check 4' );

		$this->report_row( '4  order deleted', ScheduledDelivery::REASON_ORDER_DELETED, $reason );
	}

	/**
	 * §4 check 5 — the order was CANCELLED during the delay (item 7).
	 *
	 * @dataProvider cancelling_status_provider
	 *
	 * @param string $status Order status.
	 * @return void
	 */
	public function test_check_5_order_state( string $status ) {
		$fixture = $this->schedule_one();

		$order = wc_get_order( $fixture['order_id'] );
		$order->set_status( $status );
		$order->save();

		$this->run_job( $fixture['delivery_id'], $fixture['order_id'] );

		$reason = $this->assert_cancelled( $fixture['delivery_id'], ScheduledDelivery::REASON_ORDER_STATE, 'check 5 / ' . $status );

		$this->report_row( '5  order ' . str_pad( $status, 9 ), ScheduledDelivery::REASON_ORDER_STATE, $reason );
	}

	/**
	 * The three statuses that stop a pending delayed delivery.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function cancelling_status_provider(): array {
		return array(
			'cancelled' => array( 'cancelled' ),
			'failed'    => array( 'failed' ),
			'refunded'  => array( 'refunded' ),
		);
	}

	/**
	 * §4 check 6 — EVERY matched item was fully refunded (item 8, first half).
	 *
	 * @return void
	 */
	public function test_check_6_all_matched_items_refunded() {
		$fixture = $this->schedule_one( array(), 2 );

		$order = wc_get_order( $fixture['order_id'] );
		$this->refund_items( $order, array_keys( $order->get_items() ) );

		$this->run_job( $fixture['delivery_id'], $fixture['order_id'] );

		$reason = $this->assert_cancelled( $fixture['delivery_id'], ScheduledDelivery::REASON_ITEMS_REFUNDED, 'check 6' );

		$this->report_row( '6  all items refunded', ScheduledDelivery::REASON_ITEMS_REFUNDED, $reason );
	}

	/**
	 * §4 check 6 — ONE OF SEVERAL refunded still SENDS (item 8, second half).
	 *
	 * ⚠ THE HALF THAT PROVES THE CHECK IS PER ITEM. A customer who returned one of
	 * two products still bought the other, so the delivery still has something to
	 * be about. An order-level test alone would pass against a check that
	 * cancelled on any refund at all.
	 *
	 * @return void
	 */
	public function test_check_6_a_partial_refund_still_sends() {
		$fixture = $this->schedule_one( array(), 2 );

		$order = wc_get_order( $fixture['order_id'] );
		$items = array_keys( $order->get_items() );

		$this->refund_items( $order, array( $items[0] ) );

		$this->run_job( $fixture['delivery_id'], $fixture['order_id'] );

		$this->assertMailCount( 1, '⚠ a partial refund cancelled a delivery that still had matched items' );
		$this->assertStringContainsString( 'DELAYED BLOCK', (string) $this->last_mail()['message'] );
		$this->assertSame( 'sent', (string) $this->deliveries->find_by_id( $fixture['delivery_id'] )['final_status'] );

		fwrite(
			STDERR,
			"\n[P7 item 8] refunds, per item:\n"
			. "  2 of 2 matched items refunded -> cancelled (items_refunded)\n"
			. "  1 of 2 matched items refunded -> SENT\n"
		);
	}

	/**
	 * Fully refund the named line items.
	 *
	 * @param \WC_Order $order    Order.
	 * @param int[]     $item_ids Line-item ids.
	 * @return void
	 */
	private function refund_items( \WC_Order $order, array $item_ids ): void {
		$line_items = array();
		$total      = 0.0;

		foreach ( $item_ids as $item_id ) {
			$item = $order->get_item( $item_id );

			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$amount = (float) $item->get_total();
			$total += $amount;

			$line_items[ (int) $item_id ] = array(
				'qty'          => (int) $item->get_quantity(),
				'refund_total' => $amount,
			);
		}

		$refund = wc_create_refund(
			array(
				'order_id'   => (int) $order->get_id(),
				'amount'     => $total,
				'line_items' => $line_items,
			)
		);

		$this->assertInstanceOf( \WC_Order_Refund::class, $refund, 'the refund fixture failed' );
		$this->track_refund( (int) $refund->get_id() );
	}

	// -----------------------------------------------------------------------
	// §5 — EAGER CANCELLATION, and deactivation.
	// -----------------------------------------------------------------------

	/**
	 * 5. DISABLING A RULE CANCELS ITS PENDING DELIVERIES IMMEDIATELY, and the
	 *    job goes with them (ADR-0015 §5).
	 *
	 * ⚠ NOT LAZILY AT EXECUTION. A merchant who disables a rule expects the mail
	 * to stop; a job that sits in the queue for another six hours is a promise
	 * this plugin has not kept, however correctly it later declines to send.
	 *
	 * @return void
	 */
	public function test_disabling_a_rule_cancels_its_pending_deliveries_immediately() {
		$fixture = $this->schedule_one();

		$this->assertTrue( $this->has_job( $fixture['delivery_id'], $fixture['order_id'] ) );

		// The eager path is LIVE here — this is what it is for.
		$this->rules->update( $fixture['rule_id'], array( 'status' => 'inactive' ) );

		$this->assertSame(
			'cancelled',
			(string) $this->deliveries->find_by_id( $fixture['delivery_id'] )['final_status'],
			'disabling the rule did not cancel its queued delivery'
		);
		$this->assertSame( ScheduledDelivery::REASON_RULE_DISABLED, $this->cancellation_code( $fixture['delivery_id'] ) );
		$this->assertFalse(
			$this->has_job( $fixture['delivery_id'], $fixture['order_id'] ),
			'the queued job survived the rule being disabled'
		);

		// And nothing sends if the scheduler runs it anyway.
		$this->run_job( $fixture['delivery_id'], $fixture['order_id'] );
		$this->assertMailCount( 0, 'a cancelled delivery sent when the job ran' );

		fwrite(
			STDERR,
			"\n[P7 item 5 / gate 19] eager cancellation on DISABLE: tombstone cancelled + job removed, both immediately\n"
		);
	}

	/**
	 * 5b. DELETING a rule does the same.
	 *
	 * @return void
	 */
	public function test_deleting_a_rule_cancels_its_pending_deliveries_immediately() {
		$fixture = $this->schedule_one();

		$this->rules->delete( $fixture['rule_id'] );

		$this->assertSame( 'cancelled', (string) $this->deliveries->find_by_id( $fixture['delivery_id'] )['final_status'] );
		$this->assertSame( ScheduledDelivery::REASON_RULE_DELETED, $this->cancellation_code( $fixture['delivery_id'] ) );
		$this->assertFalse( $this->has_job( $fixture['delivery_id'], $fixture['order_id'] ) );

		$this->run_job( $fixture['delivery_id'], $fixture['order_id'] );
		$this->assertMailCount( 0 );

		fwrite( STDERR, "\n[P7 item 5 / gate 19] eager cancellation on DELETE: tombstone cancelled + job removed\n" );
	}

	/**
	 * 5c. AN EDIT THAT LEAVES THE RULE SCHEDULABLE CHANGES NOTHING.
	 *
	 * The other side of eager cancellation, and the one that would make it
	 * destructive if it were wrong: editing a rule's subject must not cancel the
	 * mail already queued from it (ADR-0007's "editing never re-arms" has a twin —
	 * editing never CANCELS either).
	 *
	 * @return void
	 */
	public function test_an_ordinary_edit_leaves_pending_deliveries_alone() {
		$fixture = $this->schedule_one();

		$this->rules->update( $fixture['rule_id'], array( 'subject' => 'A DIFFERENT SUBJECT' ) );

		$this->assertSame(
			DeliveryRepository::SCHEDULED,
			(string) $this->deliveries->find_by_id( $fixture['delivery_id'] )['final_status'],
			'⚠ an ordinary edit cancelled a queued delivery'
		);
		$this->assertTrue( $this->has_job( $fixture['delivery_id'], $fixture['order_id'] ), 'an ordinary edit removed the job' );

		// And it still sends the SNAPSHOTTED subject.
		$this->run_job( $fixture['delivery_id'], $fixture['order_id'] );
		$this->assertMailCount( 1 );
		$this->assertNotSame( 'A DIFFERENT SUBJECT', (string) $this->last_mail()['subject'] );

		fwrite( STDERR, "\n[P7 item 5] ordinary edit: delivery still scheduled, job intact, snapshotted subject delivered\n" );
	}

	/**
	 * 10. DEACTIVATION unschedules pending delivery jobs (ADR-0015 §5).
	 *
	 * @return void
	 */
	public function test_deactivation_unschedules_pending_delivery_jobs() {
		$fixture = $this->schedule_one();

		$this->assertTrue( $this->has_job( $fixture['delivery_id'], $fixture['order_id'] ) );

		Deactivator::deactivate();

		$this->assertFalse(
			$this->has_job( $fixture['delivery_id'], $fixture['order_id'] ),
			'deactivation left a pending delayed-delivery job in the queue'
		);

		$this->assertContains(
			ScheduledDelivery::HOOK,
			Deactivator::ONE_OFF_HOOKS,
			'the delayed-delivery hook is not in the deactivation list'
		);

		// Reactivation sends nothing from them: the jobs are gone, and the
		// tombstone is still `scheduled` with its snapshot — honest, because the
		// delivery is genuinely owed and nothing is now going to deliver it.
		$this->assertMailCount( 0 );

		fwrite(
			STDERR,
			"\n[P7 item 10] deactivation: pending delayed-delivery jobs unscheduled; nothing sent on reactivation\n"
		);
	}

	/**
	 * ⚠ UNINSTALL CANCELS THE SAME HOOKS, AND ITS LITERALS CANNOT DRIFT.
	 *
	 * `uninstall.php` runs WITHOUT the autoloader, so it cannot reference the
	 * constants and has to repeat the hook and group names as strings. That is a
	 * real duplication and the usual consequence is silent drift: the constant is
	 * renamed, uninstall keeps cancelling a hook nobody fires, and a pending
	 * delayed delivery outlives the tables it needs. Asserting the literals
	 * against the constants is the cheapest way to make the duplication safe.
	 *
	 * @return void
	 */
	public function test_uninstall_cancels_the_same_hooks_it_should() {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

		$this->assertStringContainsString(
			"'" . ScheduledDelivery::HOOK . "'",
			$source,
			'uninstall.php does not cancel the delayed-delivery hook'
		);
		$this->assertStringContainsString(
			"'" . ScheduledDelivery::GROUP . "'",
			$source,
			'uninstall.php cancels against a different Action Scheduler group'
		);

		// ⚠ AND WITH `null` FOR THE ARGS. `array()` matches only actions whose args
		// are exactly empty, so it would cancel nothing at all (Prompt 4A).
		$this->assertMatchesRegularExpression(
			'/as_unschedule_all_actions\(\s*\$?\w+\s*,\s*null\s*,/',
			$source,
			'uninstall.php passes an args value that matches only exactly-empty args'
		);

		fwrite(
			STDERR,
			"\n[P7 item 10] uninstall: cancels " . ScheduledDelivery::HOOK . " in group "
			. ScheduledDelivery::GROUP . " with args=null\n"
		);
	}

	/**
	 * A row of the §4 table, for the report.
	 *
	 * @param string $check  Check description.
	 * @param string $code   Reason code.
	 * @param string $reason Recorded sentence.
	 * @return void
	 */
	private function report_row( string $check, string $code, string $reason ): void {
		$parts = explode( '|', $reason );

		fwrite(
			STDERR,
			sprintf( "\n[P7 item 6 / §4] check %-24s -> %-16s %s\n", $check, $code, trim( (string) end( $parts ) ) )
		);
	}
}
