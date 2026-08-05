<?php
/**
 * Delayed delivery: scheduling, the snapshot, and execution
 * (ADR-0007, ADR-0015; gates 19 and 20).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\DeliveryLogger;
use Extonify\WCEP\Delivery\ScheduledDelivery;
use Extonify\WCEP\Domain\DeliverySnapshot;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * A DELAYED RULE SENDS ONCE, LATER, WITH THE CONTENT IT HAD WHEN IT WAS QUEUED
 * AND THE CUSTOMER AS THEY ARE WHEN IT GOES.
 *
 * ADR-0007 has been frozen since Prompt 1A and never built. These are its
 * decisions as executable assertions.
 */
final class ScheduledDeliveryTest extends ScheduledDeliveryTestCase {

	/**
	 * 1. END TO END: match -> claim `scheduled` -> job -> execute -> send.
	 *
	 * ⚠ THE ZERO-MAIL ASSERTION IS THE WHOLE FIRST HALF. A delayed rule that sent
	 * immediately would pass every other assertion in this file.
	 *
	 * @return void
	 */
	public function test_a_delayed_rule_schedules_and_later_sends() {
		$product_id = $this->make_simple_product( 'WCEP Delayed End To End' );
		$order      = $this->delayed_order( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_delayed_rule( $product_id );

		$before = time();
		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		// --- NOTHING WAS SENT. ------------------------------------------------
		$this->assertMailCount( 0, 'a delayed rule sent immediately' );

		// --- ONE TOMBSTONE, `scheduled`, CARRYING ITS SNAPSHOT. ---------------
		$tombstone = $this->scheduled_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone, 'the delayed rule was never claimed' );
		$this->assertSame( DeliveryRepository::SCHEDULED, (string) $tombstone['final_status'] );

		$delivery_id = (int) $tombstone['id'];
		$snapshot    = $this->snapshot_of( $delivery_id );

		$this->assertNotNull( $snapshot, 'the tombstone carries no snapshot' );
		$this->assertSame( 'Care guide for {customer_first_name}', $snapshot['subject'], 'the snapshot stored a RENDERED subject' );
		$this->assertGreaterThanOrEqual( $before + HOUR_IN_SECONDS, (int) $snapshot['scheduled_for'] );

		// --- AND A REAL ACTION SCHEDULER JOB EXISTS. --------------------------
		$this->assertTrue( $this->has_job( $delivery_id, $order_id ), 'no job was queued for the scheduled delivery' );

		// The scheduling itself is recorded as its own detail row, so a merchant
		// can see when the message is due and which action carries it.
		$queued_rows = $this->detail_rows( $delivery_id );
		$this->assertCount( 1, $queued_rows );
		$this->assertSame( 'scheduled', (string) $queued_rows[0]['state'] );

		// --- RUN IT. ----------------------------------------------------------
		$this->run_job( $delivery_id, $order_id );

		$this->assertMailCount( 1, 'the scheduled job sent no message' );
		$mail = $this->last_mail();

		$this->assertStringContainsString( 'DELAYED BLOCK for Ada.', (string) $mail['message'] );
		$this->assertSame( 'Care guide for Ada', (string) $mail['subject'] );
		$this->assertSame( 'ada@example.test', (string) $mail['to'] );

		$final = $this->deliveries->find_by_id( $delivery_id );
		$this->assertSame( 'sent', (string) $final['final_status'] );
		$this->assertSame( 1, (int) $final['rule_revision_sent'], 'the revision that produced the message was not recorded' );

		// ⚠ AND THE SNAPSHOT IS RELEASED (ADR-0015 §2): tombstones are never
		// purged, so a snapshot left behind grows without bound.
		$this->assertNull( $this->raw_snapshot_of( $delivery_id ), 'the snapshot outlived its delivery' );

		fwrite(
			STDERR,
			"\n[P7 item 1 / gate 19] end-to-end delayed delivery:\n"
			. sprintf( "  match      -> claim #%d final_status=scheduled\n", $delivery_id )
			. sprintf( "  snapshot   -> revision %d, due %s UTC\n", (int) $snapshot['revision'], gmdate( 'Y-m-d H:i:s', (int) $snapshot['scheduled_for'] ) )
			. "  queue      -> 1 action, 0 mail\n"
			. sprintf( "  execute    -> 1 mail, subject \"%s\", final_status=sent, snapshot released\n", (string) $mail['subject'] )
		);
	}

	/**
	 * 2. IDEMPOTENCY: the same trigger twice schedules ONCE.
	 *
	 * ⚠ AND THE GUARANTEE IS THE CLAIM, NOT THE SCHEDULER (ADR-0015 §6). Action
	 * Scheduler's `$unique` is not passed — it ignores the argument set — so the
	 * atomic ADR-0004 claim is the only thing preventing a second job, and this
	 * asserts it does.
	 *
	 * @return void
	 */
	public function test_the_same_trigger_schedules_only_once() {
		$product_id = $this->make_simple_product( 'WCEP Delayed Idempotent' );
		$order      = $this->delayed_order( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_delayed_rule( $product_id );

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$tombstone   = $this->scheduled_tombstone( $order_id, $rule_id );
		$delivery_id = (int) $tombstone['id'];

		$this->assertSame( 0, (int) $tombstone['suppressed_count'] );

		// The SAME trigger again — a merchant re-saving the order, a plugin
		// re-firing the transition.
		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$repeat = $this->deliveries->find_by_id( $delivery_id );

		$this->assertSame( 1, (int) $repeat['suppressed_count'], 'the duplicate trigger did not increment the counter' );
		$this->assertSame( DeliveryRepository::SCHEDULED, (string) $repeat['final_status'] );
		$this->assertCount(
			1,
			$this->deliveries->find_for_order( $order_id ),
			'the duplicate trigger created a SECOND tombstone for the same identity'
		);

		// One job, still. Running it sends exactly one message.
		$this->run_job( $delivery_id, $order_id );
		$this->assertMailCount( 1, 'the duplicate trigger produced a duplicate email' );

		fwrite(
			STDERR,
			"\n[P7 item 2 / gate 19] idempotency: 2 triggers -> 1 tombstone, suppressed_count=1, 1 job, 1 mail\n"
		);
	}

	/**
	 * 3. SNAPSHOT FIDELITY: editing the rule after scheduling changes NOTHING
	 *    about the queued message (ADR-0007, gate 20).
	 *
	 * @return void
	 */
	public function test_a_rule_edit_after_scheduling_does_not_change_the_queued_message() {
		$product_id = $this->make_simple_product( 'WCEP Delayed Snapshot' );
		$order      = $this->delayed_order( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_delayed_rule(
			$product_id,
			array(
				'subject' => 'ORIGINAL SUBJECT',
				'content' => '<p>ORIGINAL BODY.</p>',
			)
		);

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$tombstone   = $this->scheduled_tombstone( $order_id, $rule_id );
		$delivery_id = (int) $tombstone['id'];
		$snapshotted = (int) $this->snapshot_of( $delivery_id )['revision'];

		// --- NOW EDIT EVERYTHING THAT WOULD SHOW. -----------------------------
		$this->rules->update(
			$rule_id,
			array(
				'subject'    => 'EDITED SUBJECT',
				'content'    => '<p>EDITED BODY.</p>',
				'recipients' => array( 'to' => array( 'admin' ) ),
			)
		);

		$live = $this->rules->find( $rule_id );
		$this->assertGreaterThan( $snapshotted, (int) $live['revision'], 'the edit did not bump the revision' );

		// --- RUN THE QUEUED DELIVERY. -----------------------------------------
		$this->run_job( $delivery_id, $order_id );

		$this->assertMailCount( 1 );
		$mail = $this->last_mail();

		$this->assertSame( 'ORIGINAL SUBJECT', (string) $mail['subject'], '⚠ the edit mutated already-queued mail' );
		$this->assertStringContainsString( 'ORIGINAL BODY.', (string) $mail['message'] );
		$this->assertStringNotContainsString( 'EDITED BODY.', (string) $mail['message'] );
		$this->assertSame( 'ada@example.test', (string) $mail['to'], 'the edited recipients were used' );

		$final = $this->deliveries->find_by_id( $delivery_id );
		$this->assertSame(
			$snapshotted,
			(int) $final['rule_revision_sent'],
			'the recorded revision is the edited one, not the one that produced the message'
		);

		fwrite(
			STDERR,
			"\n[P7 item 3 / gate 20] snapshot fidelity:\n"
			. sprintf( "  snapshotted rev %d, live rev %d after the edit\n", $snapshotted, (int) $live['revision'] )
			. sprintf( "  delivered subject \"%s\" (rule now says \"EDITED SUBJECT\")\n", (string) $mail['subject'] )
			. sprintf( "  recorded rule_revision_sent = %d\n", (int) $final['rule_revision_sent'] )
		);
	}

	/**
	 * 4. PLACEHOLDERS ARE FRESH: the order changing during the delay DOES show
	 *    (ADR-0014 §8, gate 20).
	 *
	 * The mirror image of item 3, and the pair is the whole point: content is
	 * frozen, personal data is not.
	 *
	 * @return void
	 */
	public function test_placeholders_resolve_against_the_live_order_at_send_time() {
		$product_id = $this->make_simple_product( 'WCEP Delayed Fresh' );
		$order      = $this->delayed_order( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_delayed_rule( $product_id );

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$tombstone   = $this->scheduled_tombstone( $order_id, $rule_id );
		$delivery_id = (int) $tombstone['id'];

		// The customer corrects their name — and their address — during the delay.
		$live = wc_get_order( $order_id );
		$live->set_billing_first_name( 'Grace' );
		$live->set_billing_email( 'grace@example.test' );
		$live->save();

		$this->run_job( $delivery_id, $order_id );

		$this->assertMailCount( 1 );
		$mail = $this->last_mail();

		$this->assertStringContainsString( 'DELAYED BLOCK for Grace.', (string) $mail['message'], 'the body carried a stale name' );
		$this->assertSame( 'Care guide for Grace', (string) $mail['subject'], 'the subject carried a stale name' );
		$this->assertSame( 'grace@example.test', (string) $mail['to'], 'the message went to the stale address' );

		fwrite(
			STDERR,
			"\n[P7 item 4 / gate 20] fresh placeholders: snapshotted template + live order\n"
			. sprintf( "  scheduled as Ada <ada@example.test>, delivered as Grace <%s>\n", (string) $mail['to'] )
			. sprintf( "  subject \"%s\" from the SNAPSHOTTED template\n", (string) $mail['subject'] )
		);
	}

	/**
	 * ⚠ THE SNAPSHOT CARRIES NO RENDERED PERSONAL DATA (ADR-0015 §2a).
	 *
	 * The invariant that lets it live on the DURABLE, never-erased tombstone. A
	 * field added to `DeliverySnapshot::FIELDS` that carried resolved order data
	 * would quietly turn that row into a place personal data hides from a
	 * subject-access request, so it is asserted rather than promised.
	 *
	 * @return void
	 */
	public function test_the_snapshot_contains_no_rendered_personal_data() {
		$product_id = $this->make_simple_product( 'WCEP Delayed Privacy' );
		$order      = $this->delayed_order( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$this->make_delayed_rule( $product_id, array( 'content' => '<p>Hello {customer_first_name} at {billing_address}.</p>' ) );

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$tombstone = $this->deliveries->find_for_order( $order_id )[0];
		$this->track_delivery( (int) $tombstone['id'] );
		$this->queued[] = array(
			'delivery_id' => (int) $tombstone['id'],
			'order_id'    => $order_id,
		);

		$raw = (string) $tombstone['snapshot'];

		foreach ( array( 'Ada', 'Lovelace', 'ada@example.test' ) as $personal ) {
			$this->assertStringNotContainsString(
				$personal,
				$raw,
				'⚠ the snapshot on the durable tombstone carries rendered personal data: ' . $personal
			);
		}

		// The TEMPLATE is there, unrendered — which is exactly the distinction.
		$this->assertStringContainsString( '{customer_first_name}', $raw );

		$snapshot = DeliverySnapshot::read( $raw );
		$this->assertSame( array(), DeliverySnapshot::unexpected_fields( $snapshot ), 'the snapshot grew a field outside its allowlist' );

		fwrite(
			STDERR,
			"\n[P7 / ADR-0015 §2a] snapshot privacy: templates and ids only; no name, address or email in "
			. strlen( $raw ) . " bytes\n"
		);
	}

	/**
	 * 13. IMMEDIATE RULES ARE UNAFFECTED: `delay_seconds = 0` still sends inline
	 *     with no job (ADR-0015 §7).
	 *
	 * ⚠ AND A DELAYED RULE MUST NOT ALSO SEND INLINE. The two phases are disjoint
	 * by construction; the failure this guards is a delayed rule delivered twice,
	 * once now and once when the job runs.
	 *
	 * @return void
	 */
	public function test_immediate_and_delayed_rules_are_disjoint() {
		$product_id = $this->make_simple_product( 'WCEP Phase Split' );
		$order      = $this->delayed_order( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$immediate = $this->make_sending_rule(
			$product_id,
			array(
				'name'          => 'immediate',
				'trigger_value' => 'processing',
				'delay_seconds' => 0,
				'content'       => '<p>IMMEDIATE BLOCK.</p>',
			)
		);

		$delayed = $this->make_delayed_rule( $product_id, array( 'content' => '<p>DELAYED BLOCK.</p>' ) );

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		// EXACTLY ONE message, and it is the immediate rule's.
		$this->assertMailCount( 1, 'the delayed rule also sent inline' );
		$this->assertStringContainsString( 'IMMEDIATE BLOCK.', (string) $this->last_mail()['message'] );
		$this->assertStringNotContainsString( 'DELAYED BLOCK.', (string) $this->last_mail()['message'] );

		$immediate_row = $this->tombstone( $order_id, $immediate, 'status:processing' );
		$this->track_delivery( (int) $immediate_row['id'] );
		$this->assertSame( 'sent', (string) $immediate_row['final_status'] );
		$this->assertFalse(
			$this->has_job( (int) $immediate_row['id'], $order_id ),
			'the immediate rule queued a job'
		);

		$delayed_row = $this->scheduled_tombstone( $order_id, $delayed );
		$this->assertSame( DeliveryRepository::SCHEDULED, (string) $delayed_row['final_status'] );
		$this->assertTrue( $this->has_job( (int) $delayed_row['id'], $order_id ) );

		fwrite(
			STDERR,
			"\n[P7 item 13 / gate 15] phase split on one trigger:\n"
			. "  immediate rule -> sent inline, 0 jobs\n"
			. "  delayed rule   -> scheduled, 1 job, 0 mail\n"
		);
	}

	/**
	 * 12. AN INSERT RULE STILL REFUSES A DELAY, at the repository boundary
	 *     (ADR-0013 §2, unchanged and re-asserted by ADR-0015).
	 *
	 * @return void
	 */
	public function test_an_insert_rule_still_refuses_a_delay() {
		$product_id = $this->make_simple_product( 'WCEP Delayed Insert Refusal' );

		$refused = $this->rules->insert(
			array(
				'name'            => 'delayed insert',
				'status'          => 'active',
				'delivery_mode'   => 'insert',
				'native_email_id' => 'customer_processing_order',
				'insert_position' => 'after_order_table',
				'targeting'       => array( 'include' => array( 'products' => array( $product_id ) ) ),
				'content'         => '<p>NEVER.</p>',
				'delay_seconds'   => HOUR_IN_SECONDS,
			)
		);

		$this->assertSame( 0, $refused, '⚠ an insert rule with a delay was STORED' );

		// And the same refusal on the update path: a stored insert rule cannot
		// acquire a delay later.
		$valid = $this->rules->insert(
			array(
				'name'            => 'valid insert',
				'status'          => 'active',
				'delivery_mode'   => 'insert',
				'native_email_id' => 'customer_processing_order',
				'insert_position' => 'after_order_table',
				'targeting'       => array( 'include' => array( 'products' => array( $product_id ) ) ),
				'content'         => '<p>FINE.</p>',
			)
		);

		$this->assertGreaterThan( 0, $valid );
		$this->assertFalse( $this->rules->update( $valid, array( 'delay_seconds' => HOUR_IN_SECONDS ) ), '⚠ an insert rule acquired a delay by update' );
		$this->assertSame( 0, (int) $this->rules->find( $valid )['delay_seconds'] );

		fwrite( STDERR, "\n[P7 item 12] insert + delay: refused on insert AND on update (ADR-0013 §2 holds)\n" );
	}

	/**
	 * 9. SCHEDULER OUTCOMES: a second delivery for a DIFFERENT order still
	 *    schedules while the first is pending — the `$unique` trap
	 *    (ADR-0015 §6).
	 *
	 * ⚠ THIS IS THE ONE THAT WOULD HAVE BEEN SILENT. Passing `$unique = true`
	 * matches HOOK + GROUP only, so the second order's job would return 0 and its
	 * customer would never receive the email — with nothing anywhere saying so.
	 *
	 * @return void
	 */
	public function test_a_second_order_still_schedules_while_the_first_is_pending() {
		$product_id = $this->make_simple_product( 'WCEP Delayed Unique Trap' );

		$first  = $this->delayed_order( array( $product_id ) );
		$second = $this->delayed_order( array( $product_id ) );

		$rule_id = $this->make_delayed_rule( $product_id );

		$this->orchestrator()->handle_status_change( (int) $first->get_id(), 'pending', 'processing' );
		$this->orchestrator()->handle_status_change( (int) $second->get_id(), 'pending', 'processing' );

		$first_row  = $this->scheduled_tombstone( (int) $first->get_id(), $rule_id );
		$second_row = $this->scheduled_tombstone( (int) $second->get_id(), $rule_id );

		$this->assertNotNull( $first_row );
		$this->assertNotNull( $second_row, 'the second order was never claimed' );

		$this->assertTrue( $this->has_job( (int) $first_row['id'], (int) $first->get_id() ) );
		$this->assertTrue(
			$this->has_job( (int) $second_row['id'], (int) $second->get_id() ),
			'⚠ the second order got NO job while the first was pending — the $unique trap'
		);

		$this->assertSame( DeliveryRepository::SCHEDULED, (string) $second_row['final_status'] );

		fwrite(
			STDERR,
			"\n[P7 item 9 / gate 19] the \$unique trap: 2 orders pending simultaneously -> 2 jobs, both scheduled\n"
		);
	}

	/**
	 * 9b. A SCHEDULING FAILURE is recorded as `schedule_failed` and CANCELS the
	 *     delivery — never filed as "already pending" (ADR-0015 §6).
	 *
	 * ⚠ THE IDENTITY IS ALREADY CONSUMED BY THEN, so a tombstone left `scheduled`
	 * with no job behind it would sit for ever in a state that reads as "in
	 * progress" with nothing in the queue to find. `cancelled` plus a loud reason
	 * is the truthful terminal state.
	 *
	 * @return void
	 */
	public function test_a_scheduling_failure_is_recorded_and_cancels_the_delivery() {
		$product_id = $this->make_simple_product( 'WCEP Delayed Schedule Fail' );
		$order      = $this->delayed_order( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_delayed_rule( $product_id );

		/*
		 * Make Action Scheduler refuse the insert, through its OWN documented
		 * short-circuit. The simulated failure has to look exactly like a real one
		 * — `as_schedule_single_action()` returning 0 — rather than an exception,
		 * because the code path under test is the one that reads that return value
		 * and has to tell "0 because it failed" from "0 because it was already
		 * there".
		 */
		$block = static function () {
			return 0;
		};

		add_filter( 'pre_as_schedule_single_action', $block, PHP_INT_MAX );

		try {
			$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );
		} finally {
			remove_filter( 'pre_as_schedule_single_action', $block, PHP_INT_MAX );
		}

		$this->assertMailCount( 0 );

		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:processing' );
		$this->assertNotNull( $tombstone, 'nothing was recorded at all' );
		$this->track_delivery( (int) $tombstone['id'] );

		$this->assertSame( 'cancelled', (string) $tombstone['final_status'], 'a delivery that never queued was left `scheduled`' );

		$code = $this->cancellation_code( (int) $tombstone['id'] );
		$this->assertSame( 'schedule_failed', $code, 'a scheduling failure was filed under the wrong reason' );

		$reason = $this->reasons_of( (int) $tombstone['id'] );
		$this->assertStringContainsString( 'SCHEDULING FAILED', $reason );
		$this->assertStringNotContainsString( 'already queued', $reason, '⚠ a failure was described as a suppressed duplicate' );

		fwrite(
			STDERR,
			"\n[P7 item 9b / gate 19] scheduling failure:\n"
			. "  tombstone -> cancelled (not left `scheduled`)\n"
			. '  reason    -> ' . $reason . "\n"
		);
	}

	/**
	 * A SCHEDULED TOMBSTONE AND ITS JOB EXIST TOGETHER OR NOT AT ALL (gate 19).
	 *
	 * The invariant stated directly: every `scheduled` tombstone this store holds
	 * has a pending job, and every pending job has a `scheduled` tombstone.
	 *
	 * @return void
	 */
	public function test_every_scheduled_tombstone_has_a_job_and_the_reverse() {
		$product_id = $this->make_simple_product( 'WCEP Delayed Pairing' );
		$rule_id    = $this->make_delayed_rule( $product_id );

		$ids = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$order      = $this->delayed_order( array( $product_id ) );
			$order_id   = (int) $order->get_id();
			$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

			$row = $this->scheduled_tombstone( $order_id, $rule_id );
			$this->assertNotNull( $row );

			$ids[] = array( (int) $row['id'], $order_id );
		}

		foreach ( $ids as $pair ) {
			list( $delivery_id, $order_id ) = $pair;

			$row = $this->deliveries->find_by_id( $delivery_id );

			$this->assertSame( DeliveryRepository::SCHEDULED, (string) $row['final_status'] );
			$this->assertTrue( $this->has_job( $delivery_id, $order_id ), 'a scheduled tombstone has no job' );
		}

		// Now cancel one and assert BOTH halves go.
		list( $cancelled_id, $cancelled_order ) = $ids[1];

		ScheduledDelivery::cancel( $cancelled_id, ScheduledDelivery::REASON_RULE_DISABLED, DeliveryRepository::SCHEDULED );
		ScheduledDelivery::unschedule( $cancelled_id, $cancelled_order );

		$this->assertSame( 'cancelled', (string) $this->deliveries->find_by_id( $cancelled_id )['final_status'] );
		$this->assertFalse( $this->has_job( $cancelled_id, $cancelled_order ), 'cancellation left the job in the queue' );

		// The other two are untouched — cancellation is per delivery.
		foreach ( array( $ids[0], $ids[2] ) as $pair ) {
			list( $delivery_id, $order_id ) = $pair;

			$this->assertSame( DeliveryRepository::SCHEDULED, (string) $this->deliveries->find_by_id( $delivery_id )['final_status'] );
			$this->assertTrue( $this->has_job( $delivery_id, $order_id ), 'cancelling one delivery removed another\'s job' );
		}

		fwrite(
			STDERR,
			"\n[P7 / gate 19] tombstone/job pairing: 3 scheduled -> 3 jobs; cancel 1 -> both halves gone, other 2 intact\n"
		);
	}

	/**
	 * GATE 6. THE SECOND EVALUATION THE DELAYED PHASE ADDS COSTS NO QUERIES.
	 *
	 * ⚠ THE COST THIS MEASURES IS A REAL ONE TO BE SUSPICIOUS OF. `stop_processing`
	 * is phase-local, so the delayed rules must be evaluated SEPARATELY from the
	 * immediate ones — a second `RuleMatcher::evaluate()` over the same order.
	 * Prompt 4C measured re-resolution as free (`WC_Order::get_items()` is memoised
	 * on the order object and the item meta is already cached), and this is that
	 * claim held to the delayed path rather than inherited from a different prompt.
	 *
	 * Two orders, same rules: the first pays the cold cost, the second is measured.
	 *
	 * @return void
	 */
	public function test_the_delayed_phase_adds_no_query_cost_to_evaluation() {
		global $wpdb;

		$product_id = $this->make_simple_product( 'WCEP Delayed Query Cost' );
		$unrelated  = $this->make_simple_product( 'WCEP Delayed Query Other' );

		$this->make_sending_rule( $product_id, array( 'trigger_value' => 'processing', 'delay_seconds' => 0 ) );

		/*
		 * ⚠ THE DELAYED RULE TARGETS A PRODUCT THE ORDER DOES NOT CARRY, AND THAT
		 * IS WHAT MAKES THIS MEASURE THE RIGHT THING. A matching delayed rule would
		 * also CLAIM, ARM and QUEUE, and those writes are the cost of scheduling a
		 * delivery rather than the cost of the extra evaluation. Isolating the
		 * evaluation is the only way to answer the question gate 6 actually asks;
		 * the scheduling cost is measured separately below and named as its own
		 * data class.
		 */
		$delayed_id = $this->make_delayed_rule(
			$unrelated,
			array( 'targeting' => array( 'include' => array( 'products' => array( $unrelated ) ) ) )
		);

		$measure = function () use ( $product_id, $wpdb ) {
			$order    = $this->delayed_order( array( $product_id ) );
			$order_id = (int) $order->get_id();

			$before = $wpdb->num_queries;
			$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );
			$cost = $wpdb->num_queries - $before;

			foreach ( $this->deliveries->find_for_order( $order_id ) as $row ) {
				$this->track_delivery( (int) $row['id'] );
				$this->queued[] = array(
					'delivery_id' => (int) $row['id'],
					'order_id'    => $order_id,
				);
			}

			return $cost;
		};

		$measure();                 // Warm the caches.
		$with_second_evaluation = $measure();

		// Baseline: the delayed rule disabled, so no second evaluation runs.
		$this->rules->update( $delayed_id, array( 'status' => 'inactive' ) );

		$measure();
		$without = $measure();

		$this->assertSame(
			$without,
			$with_second_evaluation,
			'evaluating the delayed phase separately cost extra queries; re-resolution is no longer free'
		);

		// --- AND THE SCHEDULING COST, NAMED. ----------------------------------
		$this->rules->update(
			$delayed_id,
			array(
				'status'    => 'active',
				'targeting' => array( 'include' => array( 'products' => array( $product_id ) ) ),
			)
		);

		$measure();
		$with_scheduling = $measure();

		fwrite(
			STDERR,
			"\n[P7 / gate 6] delayed phase, query cost by data class (warm):\n"
			. sprintf( "  immediate delivery only                 %2d\n", $without )
			. sprintf( "  + second evaluation, nothing matched    %2d   delta +%d\n", $with_second_evaluation, $with_second_evaluation - $without )
			. sprintf( "  + one delivery claimed, armed, queued   %2d   delta +%d\n", $with_scheduling, $with_scheduling - $without )
			. "  => the extra EVALUATION is free; SCHEDULING is one bounded cost per queued delivery\n"
		);
	}

	/**
	 * 11. CONTAINMENT: a throwing filter during scheduled execution does not
	 *     escape the Action Scheduler run (ADR-0012 §3, ADR-0014 §10, gate 17).
	 *
	 * @return void
	 */
	public function test_a_throw_during_scheduled_execution_is_contained() {
		$product_id = $this->make_simple_product( 'WCEP Delayed Containment' );
		$order      = $this->delayed_order( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_delayed_rule(
			$product_id,
			array( 'content' => '<p>note=[{order_custom_field:wcep_detonator}]</p>' )
		);

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$tombstone   = $this->scheduled_tombstone( $order_id, $rule_id );
		$delivery_id = (int) $tombstone['id'];

		$boom = static function () {
			throw new \RuntimeException( 'the placeholder filter exploded' );
		};

		add_filter( 'extonify_wcep_meta_placeholder_allowed', $boom, 10, 4 );

		try {
			// NO try/catch AROUND THE RUN, deliberately: if a Throwable escapes,
			// PHPUnit reports this test as errored — which IS the assertion.
			$this->run_job( $delivery_id, $order_id );
		} finally {
			remove_filter( 'extonify_wcep_meta_placeholder_allowed', $boom, 10 );
		}

		$this->assertMailCount( 0, 'a delivery whose resolution threw still sent' );

		$final = $this->deliveries->find_by_id( $delivery_id );

		$this->assertNotSame( DeliveryRepository::SCHEDULED, (string) $final['final_status'], '⚠ the delivery was stranded `scheduled`' );
		$this->assertSame( 'failed', (string) $final['final_status'] );
		$this->assertNull( $this->raw_snapshot_of( $delivery_id ), 'a terminal delivery kept its snapshot' );

		$rows = $this->detail_rows( $delivery_id );
		$this->assertNotSame( array(), $rows, 'the failure left no detail row' );

		$evidence = '';
		foreach ( $rows as $row ) {
			$evidence .= (string) ( $row['reason'] ?? '' ) . ' ' . (string) ( $row['failure_message'] ?? '' );
		}

		$this->assertStringContainsString( 'RuntimeException', $evidence, 'the exception class was not recorded' );
		$this->assertStringContainsString( 'the placeholder filter exploded', $evidence );

		fwrite(
			STDERR,
			"\n[P7 item 11 / gate 17] throw during scheduled execution:\n"
			. "  run did not throw; 0 mail; tombstone failed (not stranded `scheduled`)\n"
			. '  recorded  : ' . trim( preg_replace( '/\s+/', ' ', $evidence ) ) . "\n"
		);
	}

	/**
	 * A RE-RUN OF AN ALREADY-TERMINAL DELIVERY DOES NOTHING (gate 19).
	 *
	 * Duplicate jobs are possible by design — the scheduler pre-check is
	 * best-effort — so they have to be cheap rather than dangerous.
	 *
	 * @return void
	 */
	public function test_running_a_terminal_delivery_twice_sends_once() {
		$product_id = $this->make_simple_product( 'WCEP Delayed Rerun' );
		$order      = $this->delayed_order( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_delayed_rule( $product_id );

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$tombstone   = $this->scheduled_tombstone( $order_id, $rule_id );
		$delivery_id = (int) $tombstone['id'];

		$this->run_job( $delivery_id, $order_id );
		$this->assertMailCount( 1, 'the first run sent nothing' );

		// The scheduler ran the same action again. `run_job()` scopes the capture
		// to each run, so each of these asserts what THAT run did.
		$this->run_job( $delivery_id, $order_id );
		$this->assertMailCount( 0, '⚠ a duplicate job produced a duplicate email' );

		$this->run_job( $delivery_id, $order_id );
		$this->assertMailCount( 0, '⚠ a third run produced yet another email' );

		// Exactly two rows, and each is a DIFFERENT event: the queueing, and the
		// one send. A re-run must add neither.
		$states = array();
		foreach ( $this->detail_rows( $delivery_id ) as $row ) {
			$states[] = (string) $row['state'];
		}

		$this->assertSame( array( 'scheduled', 'sent' ), $states, 'a duplicate job wrote a second attempt row' );
		$this->assertSame( 'sent', (string) $this->deliveries->find_by_id( $delivery_id )['final_status'] );

		fwrite( STDERR, "\n[P7 / gate 19] duplicate job execution: run 1 -> 1 mail; runs 2 and 3 -> 0 mail each; rows [scheduled, sent]\n" );
	}
}
