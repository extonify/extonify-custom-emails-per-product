<?php
/**
 * A FAILED WRITE IS NOT A LOST RACE (ADR-0015 §8.1a, §8.3a; gates 19, 21).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\DeliveryLogger;
use Extonify\WCEP\Delivery\ScheduledCancellation;
use Extonify\WCEP\Delivery\ScheduledDelivery;
use Extonify\WCEP\Domain\DeliverySnapshot;
use Extonify\WCEP\Install\Maintenance;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * The three call sites that read `false` as "somebody else won", and the sweep
 * that could not reach past its first page.
 *
 * ⚠ EVERY FAILURE HERE IS A PLAIN DATABASE FAILURE THAT THROWS NOTHING, which is
 * what made the defects invisible: the containment boundaries added in Prompts 4a,
 * 7 and 7A all catch a `Throwable`, and none of these produce one. `$wpdb->query()`
 * simply returns `false`, the caller reads it as a boolean, and a delivery is lost
 * with no exception anywhere.
 *
 * The fixture rewrites ONE statement into a query against a table that does not
 * exist, through WordPress's own `query` filter — the same real extension point
 * `ScheduledLifecycleTest` throws from, used here to make a statement FAIL rather
 * than blow up. Nothing is mocked and no production code is stubbed.
 */
final class ScheduledIntegrityTest extends ScheduledDeliveryTestCase {

	/**
	 * Arm one delayed delivery through the real path.
	 *
	 * @return array{delivery_id:int,order_id:int,rule_id:int}
	 */
	private function armed_delivery(): array {
		$product  = $this->make_product( 'WCEP integrity' );
		$rule_id  = $this->make_delayed_rule( $product );
		$order    = $this->delayed_order( array( $product ) );
		$order_id = (int) $order->get_id();

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$tombstone = $this->scheduled_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone, 'the fixture never armed a delayed delivery' );
		$this->assertSame( DeliveryRepository::SCHEDULED, (string) $tombstone['final_status'] );

		return array(
			'delivery_id' => (int) $tombstone['id'],
			'order_id'    => $order_id,
			'rule_id'     => $rule_id,
		);
	}

	/**
	 * Run a callback with the FIRST statement matching a needle rewritten into one
	 * that fails — without throwing.
	 *
	 * ⚠ THE DISTINCTION THIS WHOLE FILE IS ABOUT. A throw is caught by a
	 * containment boundary and recorded; a `false` return is a value somebody has
	 * to interpret, and interpreting it as "another actor won" is the defect.
	 *
	 * @param string   $needle   Substring identifying the statement to break.
	 * @param callable $callback What to run.
	 * @return void
	 */
	private function with_a_failing_statement( string $needle, callable $callback ) {
		global $wpdb;

		$fired = false;

		$breaker = static function ( $query ) use ( $needle, &$fired ) {
			if ( ! $fired && false !== strpos( (string) $query, $needle ) ) {
				$fired = true;

				// A real statement against a table that is not there: MySQL refuses
				// it, `$wpdb->query()` returns false, and nothing is thrown.
				return 'UPDATE `extonify_wcep_no_such_table_7b` SET final_status = 1 WHERE 1 = 0';
			}

			return $query;
		};

		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $breaker, 1 );

		try {
			$callback();
		} finally {
			remove_filter( 'query', $breaker, 1 );
			$wpdb->suppress_errors( $suppressed );
			$wpdb->last_error = '';
		}

		$this->assertTrue( $fired, "the fixture never broke anything: no statement contained `{$needle}`" );
	}

	/**
	 * Every `state` recorded on a delivery's detail rows.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return string[]
	 */
	private function detail_states( int $delivery_id ): array {
		$states = array();

		foreach ( $this->detail_rows( $delivery_id ) as $row ) {
			$states[] = (string) $row['state'];
		}

		return $states;
	}

	/**
	 * TEST 1 — THE ARM FAILS AND NOTHING THROWS.
	 *
	 * The identity is consumed the moment the claim lands, so a `claimed` row with
	 * no job is a delivery that will never happen AND a permanent suppressor of
	 * every later trigger for that identity — and the maintenance sweep cannot help,
	 * because it looks for `scheduled` rows.
	 *
	 * @return void
	 */
	public function test_a_failed_arm_terminalises_the_claim_instead_of_stranding_it() {
		$product  = $this->make_product( 'WCEP failed arm' );
		$rule_id  = $this->make_delayed_rule( $product );
		$order    = $this->delayed_order( array( $product ) );
		$order_id = (int) $order->get_id();

		$this->with_a_failing_statement(
			'SET snapshot =',
			function () use ( $order_id ) {
				$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );
			}
		);

		$tombstone = $this->scheduled_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone, 'nothing was claimed, so the arm was never reached' );

		$delivery_id = (int) $tombstone['id'];

		$this->assertNotContains(
			$this->status_of( $delivery_id ),
			DeliveryRepository::IN_FLIGHT_STATUSES,
			'a failed arm left the tombstone in flight with no job and nothing able to reach it'
		);
		$this->assertSame( 'cancelled', $this->status_of( $delivery_id ) );
		$this->assertSame(
			ScheduledDelivery::REASON_ARM_FAILED,
			$this->cancellation_code( $delivery_id ),
			'a failed arm must record its own reason, not a generic cancellation'
		);
		$this->assertFalse( $this->has_job( $delivery_id, $order_id ), 'nothing was armed, so nothing may be queued' );
		$this->assertNull( $this->raw_snapshot_of( $delivery_id ) );
		$this->assertMailCount( 0 );

		fwrite(
			STDERR,
			"\n[7B A1] arm returned false without throwing: tombstone `cancelled`/arm_failed, 0 jobs, snapshot released\n"
		);
	}

	/**
	 * TEST 2 — THE LEASE UPDATE FAILS AND THE ROW IS STILL `scheduled`.
	 *
	 * ⚠ THE OLD BRANCH RETURNED NORMALLY HERE, so Action Scheduler consumed the
	 * action and the delivery was left `scheduled` with no job — while branch 3b's
	 * comment asserted that every existing row is "already terminal or owned".
	 *
	 * @return void
	 */
	public function test_a_failed_lease_write_requeues_instead_of_reading_as_a_lost_race() {
		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];
		$order_id    = $armed['order_id'];

		$this->captured_mail = array();

		$this->with_a_failing_statement(
			"lease_taken_at = '",
			function () use ( $delivery_id, $order_id ) {
				ScheduledDelivery::run( $delivery_id, $order_id, 0 );
			}
		);

		$this->assertSame(
			DeliveryRepository::SCHEDULED,
			$this->status_of( $delivery_id ),
			'the row moved despite the lease write failing'
		);

		// RULE 3: left `scheduled` only because a replacement job is PROVED to exist.
		$this->assertTrue(
			$this->has_job_at( $delivery_id, $order_id, 1 ),
			'a failed lease was treated as a lost race: the action was consumed and no replacement was queued'
		);
		$this->assertTrue( $this->has_job( $delivery_id, $order_id ), 'gate 19: a `scheduled` tombstone with no job' );

		// Not treated as somebody else's success: nothing terminal was recorded.
		$this->assertNotContains( 'cancelled', $this->detail_states( $delivery_id ) );
		$this->assertMailCount( 0 );

		fwrite(
			STDERR,
			"\n[7B A2] lease UPDATE failed without throwing: row still `scheduled`, replacement job verified at attempt 1\n"
		);
	}

	/**
	 * TEST 2b — A FAILED LEASE ON A ROW THAT IS GONE RE-QUEUES NOTHING.
	 *
	 * ⚠ THE OTHER HALF OF THE SAME DECISION, AND IT IS A GATE 19 QUESTION. "The write
	 * failed" is only a reason to re-queue while the delivery might still be owed;
	 * once a successful read shows the tombstone is GONE — order deletion took it —
	 * a re-queued job would be a job with no `scheduled` tombstone behind it.
	 *
	 * A failed READ is the case that must not land here; that one is contained and
	 * re-queued, because "cannot reach the table" is not "the row was deleted".
	 *
	 * @return void
	 */
	public function test_a_failed_lease_on_a_deleted_row_queues_nothing() {
		global $wpdb;

		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];
		$order_id    = $armed['order_id'];

		// The order was permanently deleted while the job was in flight.
		$wpdb->query(
			$wpdb->prepare( 'DELETE FROM ' . \Extonify\WCEP\Install\Migrator::table( 'deliveries' ) . ' WHERE id = %d', $delivery_id )
		);

		$this->captured_mail = array();

		$this->with_a_failing_statement(
			"lease_taken_at = '",
			function () use ( $delivery_id, $order_id ) {
				ScheduledDelivery::run( $delivery_id, $order_id, 0 );
			}
		);

		$this->assertFalse(
			$this->has_job_at( $delivery_id, $order_id, 1 ),
			'a delivery whose tombstone is gone was re-queued anyway — a job with no row behind it'
		);
		$this->assertSame( '', $this->status_of( $delivery_id ), 'the fixture did not actually delete the row' );
		$this->assertMailCount( 0 );

		fwrite(
			STDERR,
			"\n[7B A2b] lease UPDATE failed on a DELETED row: nothing re-queued, nothing sent\n"
		);
	}

	/**
	 * TEST 3 — THE CANCEL TRANSITION FAILS, SO THE JOB STAYS.
	 *
	 * ⚠ THE OLD ORDER UNSCHEDULED FIRST AND INSPECTED THE RESULT AFTERWARDS, with a
	 * comment claiming the job was removed only once the tombstone was terminal. A
	 * failed transition therefore removed the job from a delivery that was still
	 * `scheduled`, leaving it owed to nobody.
	 *
	 * @return void
	 */
	public function test_a_failed_cancel_transition_leaves_the_job_in_place() {
		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];
		$order_id    = $armed['order_id'];

		$this->assertTrue( $this->has_job( $delivery_id, $order_id ) );

		$this->with_a_failing_statement(
			"final_status = 'cancelled'",
			function () use ( $armed ) {
				ScheduledCancellation::cancel_pending( $armed['rule_id'], ScheduledDelivery::REASON_RULE_DISABLED );
			}
		);

		$this->assertSame(
			DeliveryRepository::SCHEDULED,
			$this->status_of( $delivery_id ),
			'the cancellation write failed, so the row must still be scheduled'
		);
		$this->assertTrue(
			$this->has_job( $delivery_id, $order_id ),
			'a cancellation that did not take ownership removed the job anyway — the delivery now has no owner'
		);
		$this->assertNotContains(
			'cancelled',
			$this->detail_states( $delivery_id ),
			'a cancellation that never landed still wrote a cancellation row'
		);

		fwrite(
			STDERR,
			"\n[7B A3] cancel transition failed without throwing: job still queued, row still `scheduled`, no cancellation row\n"
		);
	}

	/**
	 * TEST 8 — A CANCELLATION THAT LOSES TO A WORKER WRITES NO OUTCOME ROW.
	 *
	 * ⚠ THE DETAIL ROW USED TO BE WRITTEN FIRST, so a `sent` tombstone could carry a
	 * `cancelled` attempt row: a delivery log contradicting itself about an email
	 * that is already in the customer's inbox.
	 *
	 * @return void
	 */
	public function test_a_cancellation_that_loses_to_a_worker_writes_no_cancelled_row() {
		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];
		$order_id    = $armed['order_id'];

		$this->run_job( $delivery_id, $order_id );
		$this->assertSame( 'sent', $this->status_of( $delivery_id ), 'the fixture never sent the delivery' );
		$this->assertMailCount( 1 );

		// The eager cancellation arrives late, exactly as a merchant disabling the
		// rule a moment after the worker finished would.
		$result = ScheduledDelivery::cancel( $delivery_id, ScheduledDelivery::REASON_RULE_DISABLED, DeliveryRepository::SCHEDULED );

		$this->assertFalse( (bool) $result['success'], 'a lost cancellation reported success' );
		$this->assertSame( 'sent', $this->status_of( $delivery_id ), 'a cancellation overwrote a sent delivery' );
		$this->assertNotContains(
			'cancelled',
			$this->detail_states( $delivery_id ),
			'a cancellation that lost the transition still stamped a `cancelled` row on a `sent` tombstone'
		);

		fwrite(
			STDERR,
			"\n[7B Tier 2] cancellation lost to the worker: tombstone still `sent`, no `cancelled` detail row written\n"
		);
	}

	/**
	 * TEST 9 — A SEND THAT LOSES ITS TRANSITION STILL RECORDS THE EMAIL, AND SAYS SO
	 * (ADR-0015 §8.9, Prompt 7C Tier 2).
	 *
	 * ⚠ THE MIRROR OF TEST 8, AND THE OPPOSITE ANSWER — WHICH IS THE POINT. A
	 * cancellation that loses the race must write NO outcome row, because its detail
	 * row *is* the outcome and the outcome belongs to whoever won. A send that loses
	 * the race must write its rows ANYWAY, because by then `wp_mail()` has been called
	 * and a message either is or is not in a customer's inbox — a fact that is not
	 * conditional on winning anything, and one the privacy exporter and eraser find by
	 * recipient.
	 *
	 * What was missing was not the ordering but the EXPLANATION: a lost race leaves
	 * `finalize()` reporting terminal, so `verify()` computed success and logged
	 * nothing, and the delivery log held a `sent` row under someone else's tombstone
	 * with no account of how both could be true.
	 *
	 * @return void
	 */
	public function test_a_send_that_loses_its_transition_records_the_email_and_explains_itself() {
		$second = $this->second_connection();
		if ( null === $second ) {
			$this->markTestSkipped( 'A second database connection could not be opened in this environment.' );
		}

		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];
		$order_id    = $armed['order_id'];
		$interrupted = false;

		/*
		 * ⚠ THE FINALISER ARRIVES MID-SEND, ON A SECOND REAL CONNECTION. The worker
		 * has its lease and the mailer has been called; a deactivation landing here is
		 * the window §8.9 is about, and replaying the two statements in sequence on one
		 * connection would prove nothing about it.
		 */
		$interloper = function ( $short_circuit, $atts ) use ( $second, $delivery_id, &$interrupted ) {
			$interrupted = $this->transition_on( $second, $delivery_id, DeliveryRepository::EXECUTING, 'unresolved' );

			return $short_circuit;
		};

		add_filter( 'pre_wp_mail', $interloper, 0, 2 );

		$logged = $this->capture_plugin_log(
			function () use ( $delivery_id, $order_id ) {
				$this->run_job( $delivery_id, $order_id );
			}
		);

		remove_filter( 'pre_wp_mail', $interloper, 0 );

		$this->assertTrue( $interrupted, 'the concurrent finaliser never won the transition, so nothing was raced' );
		$this->assertMailCount( 1 );

		// The tombstone still belongs to whoever won it.
		$this->assertSame( 'unresolved', $this->status_of( $delivery_id ), 'the send overwrote a finalised tombstone' );

		// ...and the evidence of the message survived anyway.
		$this->assertContains(
			'sent',
			$this->detail_states( $delivery_id ),
			'a send that lost the transition discarded the only record of a real email'
		);

		$this->assertStringContainsString(
			'did not take ownership',
			$logged,
			'the contradictory pair was left unexplained — the exact silence §8.9 exists to end'
		);

		fwrite(
			STDERR,
			"\n[7C Tier 2] send vs concurrent finaliser: tombstone stays `unresolved`, the `sent` evidence row is kept, "
				. "and the lost transition is logged\n"
		);
	}

	/**
	 * Collect what this plugin writes to WooCommerce's log during a callback.
	 *
	 * WooCommerce's own `woocommerce_logger_log_message` filter, so the assertion runs
	 * against the real logging path rather than a stub of it.
	 *
	 * @param callable $callback What to run.
	 * @return string The log lines, joined.
	 */
	private function capture_plugin_log( callable $callback ): string {
		$lines = array();

		$collector = static function ( $message, $level, $context ) use ( &$lines ) {
			if ( 'extonify-wcep' === (string) ( $context['source'] ?? '' ) ) {
				$lines[] = (string) $message;
			}

			return $message;
		};

		add_filter( 'woocommerce_logger_log_message', $collector, 10, 3 );

		try {
			$callback();
		} finally {
			remove_filter( 'woocommerce_logger_log_message', $collector, 10 );
		}

		return implode( "\n", $lines );
	}

	/**
	 * TEST 4 — THE SWEEP REACHES PAST ITS FIRST PAGE.
	 *
	 * ⚠ NO FAILURE IS INVOLVED IN THIS ONE. `SWEEP_BATCH + 1` healthy aged rows and
	 * an orphan behind them is an ordinary store running 30-day follow-ups: the old
	 * sweep read the first hundred, `continue`d over every healthy row, advanced no
	 * cursor and never reached #102 on any day, for ever.
	 *
	 * @return void
	 */
	public function test_the_sweep_reaches_an_orphan_behind_a_full_page_of_healthy_rows() {
		$product  = $this->make_product( 'WCEP sweep paging' );
		$rule_id  = $this->make_delayed_rule( $product );
		$order    = $this->delayed_order( array( $product ) );
		$order_id = (int) $order->get_id();
		$healthy  = array();

		// A full page plus one, all aged past the cutoff and all with a live job.
		for ( $i = 0; $i <= ScheduledDelivery::SWEEP_BATCH; $i++ ) {
			$healthy[] = $this->aged_scheduled_row( $order_id, $rule_id, 'status:sweep-healthy-' . $i, true );
		}

		// The orphan comes LAST, so it sits behind a full page of candidates that
		// need no action.
		$orphan = $this->aged_scheduled_row( $order_id, $rule_id, 'status:sweep-orphan', false );

		$this->assertFalse( $this->has_job( $orphan, $order_id ), 'the orphan fixture has a job' );

		$swept = Maintenance::run();

		$this->assertSame( 1, (int) $swept['orphans_requeued'], 'the orphan behind the first page was never reached' );
		$this->assertTrue( $this->has_job( $orphan, $order_id ), 'the orphan was not re-queued' );
		$this->assertSame( DeliveryRepository::SCHEDULED, $this->status_of( $orphan ) );

		// The healthy rows are untouched: still scheduled, still holding their own
		// jobs, and none of them cancelled or requeued.
		$this->assertSame( 0, (int) $swept['orphans_cancelled'], 'a healthy row was cancelled by the sweep' );

		foreach ( $healthy as $delivery_id ) {
			$this->assertSame( DeliveryRepository::SCHEDULED, $this->status_of( $delivery_id ), 'the sweep moved a healthy row' );
			$this->assertTrue( $this->has_job( $delivery_id, $order_id ), 'the sweep removed a healthy row\'s job' );
		}

		$this->assertGreaterThan(
			ScheduledDelivery::SWEEP_BATCH,
			(int) $swept['examined'],
			'the sweep stopped at one page'
		);
		$this->assertLessThanOrEqual(
			ScheduledDelivery::SWEEP_BATCH * ScheduledDelivery::SWEEP_MAX_PAGES * 2,
			(int) $swept['examined'],
			'the sweep exceeded its stated per-run bound'
		);

		fwrite(
			STDERR,
			"\n[7B B] sweep paging: " . count( $healthy ) . ' healthy aged rows examined, orphan #'
				. ( count( $healthy ) + 1 ) . " reached and re-queued, examined=" . (int) $swept['examined']
				. ", bound=" . ( ScheduledDelivery::SWEEP_BATCH * ScheduledDelivery::SWEEP_MAX_PAGES ) . " per half\n"
		);
	}

	/**
	 * A `scheduled` tombstone old enough for the sweep to be entitled to look at it.
	 *
	 * Claimed and armed through the REAL repository — a raw INSERT would let this
	 * test pass against a state machine that no longer permits the move — under a
	 * distinct trigger identity per row, which is what lets one order stand in for a
	 * hundred without inventing a hundred orders.
	 *
	 * @param int    $order_id  Order id.
	 * @param int    $rule_id   Rule id.
	 * @param string $identity  Distinct trigger identity.
	 * @param bool   $with_job  Whether to queue a real job for it.
	 * @return int Tombstone id.
	 */
	private function aged_scheduled_row( int $order_id, int $rule_id, string $identity, bool $with_job ): int {
		$claim = $this->deliveries->claim( $order_id, $rule_id, DeliveryLogger::MODE, $identity );

		$this->assertSame( DeliveryRepository::CLAIMED, (string) $claim['result'], 'the fixture could not claim' );

		$delivery_id = (int) $claim['delivery_id'];
		$this->track_delivery( $delivery_id );
		$this->queued[] = array(
			'delivery_id' => $delivery_id,
			'order_id'    => $order_id,
		);

		$snapshot = DeliverySnapshot::create(
			array(
				'id'            => $rule_id,
				'revision'      => 1,
				'delivery_mode' => DeliveryLogger::MODE,
				'delay_seconds' => HOUR_IN_SECONDS,
				'subject'       => 'Sweep fixture',
				'content'       => '<p>Sweep fixture.</p>',
			),
			array( array( 'item_id' => 1, 'product_id' => 1, 'variation_id' => 0 ) ),
			$identity,
			time() + HOUR_IN_SECONDS
		);

		$this->assertTrue(
			$this->deliveries->arm_scheduled( $delivery_id, $snapshot )->won(),
			'the fixture could not arm a scheduled row'
		);

		if ( $with_job ) {
			ScheduledDelivery::schedule( $delivery_id, $order_id, time() + HOUR_IN_SECONDS );
			$this->assertTrue( $this->has_job( $delivery_id, $order_id ), 'the fixture failed to queue a job' );
		}

		$this->backdate_scheduled( $delivery_id, 2 * ScheduledDelivery::LEASE_WINDOW_SECONDS );

		return $delivery_id;
	}
}
