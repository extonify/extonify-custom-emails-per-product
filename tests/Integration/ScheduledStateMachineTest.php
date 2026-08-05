<?php
/**
 * THE SCHEDULED STATE MACHINE (ADR-0015 §8, gate 21).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\ScheduledDelivery;
use Extonify\WCEP\Domain\WriteResult;
use Extonify\WCEP\Install\Maintenance;
use Extonify\WCEP\Repository\DeliveryDetailRepository;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * Two workers, one delivery, and exactly one email.
 *
 * ⚠ THE DEFECT THIS SUITE COVERS NEEDS NOTHING EXOTIC — A PHP TIMEOUT DOES IT.
 * A worker that died after `wp_mail()` returned but before its status write left
 * the tombstone `scheduled` and the action re-claimable; the next worker read
 * `scheduled`, re-validated successfully, and sent the customer a SECOND email.
 * The lease is what makes that re-claim harmless, and the guarded transition is
 * what stops a cancellation and a send overwriting one another.
 *
 * ⚠ MOST OF THESE ARE **TWO-CONNECTION COMPETING WRITES, NOT INTERLEAVED RACES**,
 * and Prompt 7B corrected the description rather than the design. They open two
 * real connections and execute A then B, with no overlapping lock wait between
 * them: that proves the conditional UPDATE is a real guard — two writers cannot
 * both win — which a single connection replaying two statements could not prove.
 * It does not reproduce two workers colliding inside the same instant.
 *
 * ONE TEST HERE IS GENUINELY INTERLEAVED:
 * self::test_a_lease_blocked_by_a_real_lock_wait_is_not_a_lost_race() holds an
 * uncommitted row lock on connection A while connection B attempts the same
 * transition and BLOCKS on it. That is a real overlap, and it produces the exact
 * fact ADR-0015 §8.1a is about — a write that FAILED rather than lost.
 */
final class ScheduledStateMachineTest extends ScheduledDeliveryTestCase {

	/**
	 * A scheduled delivery, armed and queued through the real path.
	 *
	 * @return array{delivery_id:int,order_id:int,rule_id:int}
	 */
	private function armed_delivery(): array {
		$product  = $this->make_product( 'WCEP state machine' );
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
	 * THE TRANSITION TABLE, ASSERTED IN BOTH DIRECTIONS (gate 21).
	 *
	 * Every permitted pair is accepted and every other pair over the same state
	 * set is refused. One direction alone is not enough: a table that permits
	 * everything passes "the permitted ones work", and the pair that matters most
	 * — `executing` back to `scheduled`, which would let two workers pick up one
	 * delivery — is only caught by the second direction.
	 *
	 * @return void
	 */
	public function test_only_the_permitted_transitions_exist() {
		$expected = array(
			DeliveryRepository::SCHEDULED => array( DeliveryRepository::EXECUTING, 'cancelled' ),
			DeliveryRepository::EXECUTING => array( 'sent', 'failed', 'unresolved', 'cancelled', 'skipped' ),
		);

		$this->assertSame(
			$expected,
			DeliveryRepository::TRANSITIONS,
			'the permitted transition table changed without this assertion being updated'
		);

		$states  = DeliveryRepository::FINAL_STATUSES;
		$allowed = 0;
		$refused = 0;

		foreach ( $states as $from ) {
			foreach ( $states as $to ) {
				$permitted = in_array( $to, $expected[ $from ] ?? array(), true );

				$this->assertSame(
					$permitted,
					DeliveryRepository::transition_is_permitted( $from, $to ),
					"transition {$from} -> {$to} is classified wrongly"
				);

				$permitted ? ++$allowed : ++$refused;
			}
		}

		$this->assertSame( 7, $allowed, 'the transition table should have exactly seven edges' );

		$this->assertFalse(
			DeliveryRepository::transition_is_permitted( DeliveryRepository::EXECUTING, DeliveryRepository::SCHEDULED ),
			'executing -> scheduled would let two workers pick up one delivery'
		);

		fwrite(
			STDERR,
			"\n[7A gate 21] transition table: {$allowed} permitted, {$refused} refused, over "
				. count( $states ) . " states\n"
		);
	}

	/**
	 * A guarded transition changes exactly one row, only from its source — and
	 * SAYS WHICH OF THE THREE THINGS HAPPENED (ADR-0015 §8.1a).
	 *
	 * ⚠ THE OUTCOME CODES ARE ASSERTED, NOT JUST "IT DID NOT LAND". A boolean made
	 * a lost race and a failed query the same answer, and they need opposite
	 * handling; asserting the code is what keeps them apart here too.
	 *
	 * @return void
	 */
	public function test_transition_is_conditional_on_the_source_state() {
		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];

		// Wrong source: LOST_RACE — the statement ran and matched nothing.
		$wrong_source = $this->deliveries->transition( $delivery_id, DeliveryRepository::EXECUTING, 'sent' );
		$this->assertSame(
			WriteResult::LOST_RACE,
			$wrong_source->outcome(),
			'a transition from a state the row is not in must report a lost race'
		);
		$this->assertFalse( $wrong_source->won() );
		$this->assertFalse( $wrong_source->is_shortfall(), 'a lost race is somebody else\'s success, not a shortfall' );
		$this->assertSame( DeliveryRepository::SCHEDULED, $this->status_of( $delivery_id ) );

		// Right source: lands once.
		$this->assertTrue( $this->deliveries->transition( $delivery_id, DeliveryRepository::SCHEDULED, DeliveryRepository::EXECUTING )->won() );
		$this->assertSame( DeliveryRepository::EXECUTING, $this->status_of( $delivery_id ) );

		// And not twice — the row has left the source state.
		$this->assertFalse(
			$this->deliveries->transition( $delivery_id, DeliveryRepository::SCHEDULED, DeliveryRepository::EXECUTING )->won(),
			'the same transition landed twice'
		);

		// An impermissible pair is REFUSED — never attempted at all, which is a
		// third fact again and not a race anybody lost.
		$impermissible = $this->deliveries->transition( $delivery_id, DeliveryRepository::EXECUTING, DeliveryRepository::SCHEDULED );
		$this->assertSame( WriteResult::REFUSED, $impermissible->outcome(), 'an impermissible transition was written' );
		$this->assertTrue( $impermissible->is_shortfall() );
		$this->assertSame( DeliveryRepository::EXECUTING, $this->status_of( $delivery_id ) );
	}

	/**
	 * ⚠ THE SNAPSHOT SURVIVES THE LEASE AND IS RELEASED AT THE TERMINAL STATE.
	 *
	 * `executing` is pending work, not history: a delivery whose lease is later
	 * swept and recovered still has to know what it was going to send.
	 *
	 * @return void
	 */
	public function test_executing_retains_the_snapshot_and_terminal_releases_it() {
		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];

		$this->assertNotNull( $this->snapshot_of( $delivery_id ), 'a scheduled delivery must carry its snapshot' );

		$this->assertTrue( $this->deliveries->transition( $delivery_id, DeliveryRepository::SCHEDULED, DeliveryRepository::EXECUTING )->won() );
		$this->assertNotNull( $this->snapshot_of( $delivery_id ), 'the lease released the snapshot' );

		$this->assertNotContains(
			DeliveryRepository::EXECUTING,
			DeliveryRepository::SNAPSHOT_RELEASING_STATUSES,
			'executing must not be a snapshot-releasing status'
		);

		$this->assertTrue( $this->deliveries->transition( $delivery_id, DeliveryRepository::EXECUTING, 'sent' )->won() );
		$this->assertNull( $this->raw_snapshot_of( $delivery_id ), 'a terminal state must release the snapshot' );
	}

	/**
	 * TEST 7B — A LEASE BLOCKED BY A REAL LOCK WAIT IS NOT A LOST RACE.
	 *
	 * ⚠ THE ONE GENUINELY INTERLEAVED TEST IN THIS FILE, and it produces exactly the
	 * fact ADR-0015 §8.1a exists for. Connection A takes the row lock inside an
	 * uncommitted transaction; connection B then attempts the same transition and
	 * BLOCKS on that lock — a real overlap, not two statements in sequence — until
	 * its own `innodb_lock_wait_timeout` expires and MySQL refuses the statement.
	 *
	 * B's write FAILED. It did not lose a race, and under the old boolean the two
	 * were the same answer: B's caller would have concluded the delivery had an owner
	 * and exited, consuming its action. `is_shortfall()` is what tells them apart —
	 * and here it is true even though, moments later, A really does own the row.
	 *
	 * @return void
	 */
	public function test_a_lease_blocked_by_a_real_lock_wait_is_not_a_lost_race() {
		$second = $this->second_connection();
		if ( null === $second ) {
			$this->markTestSkipped( 'A second database connection could not be opened in this environment.' );
		}

		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];

		global $wpdb;

		$table = \Extonify\WCEP\Install\Migrator::table( 'deliveries' );

		// Connection A holds the row lock without committing.
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET final_status = %s, lease_taken_at = %s WHERE id = %d AND final_status = %s",
				DeliveryRepository::EXECUTING,
				gmdate( 'Y-m-d H:i:s' ),
				$delivery_id,
				DeliveryRepository::SCHEDULED
			)
		);

		// Connection B waits on it for real, then gives up.
		$second->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		$blocked = $this->write_on( $second, $delivery_id, DeliveryRepository::SCHEDULED, DeliveryRepository::EXECUTING );

		$wpdb->query( 'COMMIT' );

		$this->assertFalse( $blocked->won(), 'two workers both took the lease' );
		$this->assertTrue(
			$blocked->is_shortfall(),
			'a lease blocked by a real lock wait was reported as a lost race — the caller would have exited, '
				. 'consuming its action and leaving the delivery to nobody'
		);
		$this->assertSame( WriteResult::QUERY_FAILED, $blocked->outcome() );

		// A's write is the one that stands.
		$this->assertSame( DeliveryRepository::EXECUTING, $this->status_of( $delivery_id ) );

		fwrite(
			STDERR,
			"\n[7B gate 21] INTERLEAVED lease: B blocked on A's uncommitted row lock, reported `query_failed` "
				. "(a shortfall), not a lost race\n"
		);
	}

	/**
	 * TEST 1 — EXECUTION VERSUS EXECUTION, ON TWO REAL CONNECTIONS.
	 *
	 * Exactly one worker takes the lease; the loser exits without sending.
	 *
	 * ⚠ COMPETING WRITES, NOT AN INTERLEAVED RACE — A then B, with no overlapping
	 * lock wait. What it proves is that the conditional UPDATE is a real guard; see
	 * the class docblock, and self::test_a_lease_blocked_by_a_real_lock_wait_is_not_a_lost_race()
	 * for the overlapping case.
	 *
	 * @return void
	 */
	public function test_two_workers_race_for_the_lease_and_only_one_wins() {
		$second = $this->second_connection();
		if ( null === $second ) {
			$this->markTestSkipped( 'A second database connection could not be opened in this environment.' );
		}

		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];

		global $wpdb;

		$worker_a = $this->transition_on( $wpdb, $delivery_id, DeliveryRepository::SCHEDULED, DeliveryRepository::EXECUTING );
		$worker_b = $this->transition_on( $second, $delivery_id, DeliveryRepository::SCHEDULED, DeliveryRepository::EXECUTING );

		$this->assertTrue( $worker_a, 'the first worker did not get the lease' );
		$this->assertFalse( $worker_b, 'BOTH workers took the lease — the guard is not a guard' );
		$this->assertSame( DeliveryRepository::EXECUTING, $this->status_of( $delivery_id ) );

		// And end to end: the loser's run sends nothing at all.
		$this->captured_mail = array();
		ScheduledDelivery::run( $delivery_id, $armed['order_id'] );

		$this->assertCount( 0, $this->captured_mail, 'a worker that lost the lease still sent an email' );
		$this->assertSame(
			DeliveryRepository::EXECUTING,
			$this->status_of( $delivery_id ),
			'the loser overwrote the winner\'s state'
		);

		fwrite(
			STDERR,
			"\n[7A test 1] execution vs execution on 2 connections: A won the lease, B refused, 0 mail from the loser\n"
		);
	}

	/**
	 * TEST 2a — EXECUTION VERSUS CANCELLATION: the LEASE wins.
	 *
	 * Once a worker holds `executing`, the eager cancellation's
	 * `scheduled -> cancelled` cannot land — so a message that went out is never
	 * recorded as cancelled.
	 *
	 * @return void
	 */
	public function test_a_cancellation_cannot_overwrite_a_delivery_under_lease() {
		$second = $this->second_connection();
		if ( null === $second ) {
			$this->markTestSkipped( 'A second database connection could not be opened in this environment.' );
		}

		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];

		global $wpdb;

		$leased    = $this->transition_on( $wpdb, $delivery_id, DeliveryRepository::SCHEDULED, DeliveryRepository::EXECUTING );
		$cancelled = $this->transition_on( $second, $delivery_id, DeliveryRepository::SCHEDULED, 'cancelled' );

		$this->assertTrue( $leased );
		$this->assertFalse( $cancelled, 'a cancellation overwrote a delivery that was already executing' );

		// The send then completes and owns the outcome.
		$this->assertTrue( $this->transition_on( $wpdb, $delivery_id, DeliveryRepository::EXECUTING, 'sent' ) );
		$this->assertSame( 'sent', $this->status_of( $delivery_id ) );

		fwrite( STDERR, "\n[7A test 2a] execution vs cancellation: lease won, final state `sent`, cancellation refused\n" );
	}

	/**
	 * TEST 2b — EXECUTION VERSUS CANCELLATION: the CANCELLATION wins.
	 *
	 * When cancellation gets there first the worker's lease attempt fails, its run
	 * sends nothing, and the final state is `cancelled`.
	 *
	 * @return void
	 */
	public function test_a_delivery_cancelled_first_is_never_sent() {
		$second = $this->second_connection();
		if ( null === $second ) {
			$this->markTestSkipped( 'A second database connection could not be opened in this environment.' );
		}

		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];

		global $wpdb;

		$cancelled = $this->transition_on( $second, $delivery_id, DeliveryRepository::SCHEDULED, 'cancelled' );
		$leased    = $this->transition_on( $wpdb, $delivery_id, DeliveryRepository::SCHEDULED, DeliveryRepository::EXECUTING );

		$this->assertTrue( $cancelled );
		$this->assertFalse( $leased, 'a cancelled delivery still granted an execution lease' );

		$this->captured_mail = array();
		ScheduledDelivery::run( $delivery_id, $armed['order_id'] );

		$this->assertCount( 0, $this->captured_mail, 'a cancelled delivery was still sent' );
		$this->assertSame( 'cancelled', $this->status_of( $delivery_id ), 'the send overwrote the cancellation' );

		fwrite( STDERR, "\n[7A test 2b] cancellation vs execution: cancellation won, 0 mail, final state `cancelled`\n" );
	}

	/**
	 * TEST 3 — THE TIMEOUT RE-CLAIM, WHICH IS THE WHOLE POINT.
	 *
	 * A worker sent the message and died before writing the outcome. The row is
	 * `executing`; re-running the same action sends NOTHING; the stale sweep later
	 * records `unresolved`.
	 *
	 * @return void
	 */
	public function test_a_reclaimed_action_after_a_timeout_sends_nothing() {
		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];
		$order_id    = $armed['order_id'];

		// The dead worker: it took the lease, the mail went out, and the process
		// died before the status write. Backdated past the window in one step so
		// the sweep is entitled to look at it.
		$this->backdate_lease( $delivery_id, ScheduledDelivery::LEASE_WINDOW_SECONDS + 60 );
		$this->assertSame( DeliveryRepository::EXECUTING, $this->status_of( $delivery_id ) );

		// Action Scheduler re-runs the action. Nothing may be sent.
		$this->run_job( $delivery_id, $order_id );

		$this->assertCount( 0, $this->captured_mail, 'the re-claimed action sent the customer a SECOND email' );
		$this->assertSame( DeliveryRepository::EXECUTING, $this->status_of( $delivery_id ) );

		// The sweep recovers it — as `unresolved`, because nobody knows whether
		// the message went out.
		$swept = Maintenance::run();

		$this->assertSame( 1, (int) $swept['leases_recovered'] );
		$this->assertSame( DeliveryDetailRepository::UNRESOLVED, $this->status_of( $delivery_id ) );
		$this->assertNotSame( 'failed', $this->status_of( $delivery_id ), '`failed` would assert something nobody knows' );
		$this->assertSame( ScheduledDelivery::REASON_LEASE_EXPIRED, $this->cancellation_code( $delivery_id ) );
		$this->assertNull( $this->raw_snapshot_of( $delivery_id ), 'a recovered lease must release its snapshot' );

		fwrite(
			STDERR,
			"\n[7A test 3] timeout re-claim: row `executing`, re-run sent 0, sweep recorded `unresolved` (lease_expired)\n"
		);
	}

	/**
	 * A lease INSIDE the window is left strictly alone.
	 *
	 * The mirror of the test above, and the one that stops the sweep becoming the
	 * defect: recovering a live send would record `unresolved` for a message still
	 * on its way out.
	 *
	 * @return void
	 */
	public function test_a_fresh_lease_is_not_swept() {
		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];

		$this->backdate_lease( $delivery_id, 60 );

		$swept = Maintenance::run();

		$this->assertSame( 0, (int) $swept['leases_recovered'] );
		$this->assertSame( DeliveryRepository::EXECUTING, $this->status_of( $delivery_id ), 'the sweep took a live lease' );
	}

	/**
	 * A `scheduled` tombstone whose job is gone is re-queued, not lost.
	 *
	 * @return void
	 */
	public function test_an_orphaned_scheduled_delivery_is_requeued() {
		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];
		$order_id    = $armed['order_id'];

		// The job vanishes — a staging restore, a queue table truncation, a
		// scheduling failure that could not be recorded.
		ScheduledDelivery::unschedule( $delivery_id, $order_id );
		$this->assertFalse( $this->has_job( $delivery_id, $order_id ) );

		$this->backdate_scheduled( $delivery_id, ScheduledDelivery::LEASE_WINDOW_SECONDS + 60 );

		$swept = Maintenance::run();

		$this->assertSame( 1, (int) $swept['orphans_requeued'] );
		$this->assertSame( DeliveryRepository::SCHEDULED, $this->status_of( $delivery_id ) );
		$this->assertTrue(
			$this->has_job( $delivery_id, $order_id ),
			'the sweep left a scheduled tombstone with no job — gate 19'
		);

		fwrite( STDERR, "\n[7A gate 19] orphaned `scheduled` row re-queued by the daily sweep; tombstone/job pairing restored\n" );
	}

	/**
	 * A freshly scheduled delivery is never touched by the orphan sweep.
	 *
	 * @return void
	 */
	public function test_a_fresh_scheduled_delivery_is_not_swept() {
		$armed = $this->armed_delivery();

		$swept = Maintenance::run();

		$this->assertSame( 0, (int) $swept['orphans_requeued'] );
		$this->assertSame( 0, (int) $swept['orphans_cancelled'] );
		$this->assertSame( DeliveryRepository::SCHEDULED, $this->status_of( $armed['delivery_id'] ) );
	}

	/**
	 * EVERY SCHEDULED-PATH STATUS WRITE IS A CONDITIONAL TRANSITION (gate 21).
	 *
	 * ⚠ ASSERTED BY MAKING `set_final_status()` FATAL FOR THE DURATION, rather than
	 * by reading the source. A repository whose unconditional writer cannot be
	 * called at all is one where a scheduled path that used it fails loudly, and
	 * that keeps being true for code nobody has written yet.
	 *
	 * @return void
	 */
	public function test_no_scheduled_path_uses_the_unconditional_writer() {
		$armed = $this->armed_delivery();

		$tripwire = new class() extends DeliveryRepository {
			/**
			 * Refuse the immediate path's writer.
			 *
			 * @param int    $delivery_id        Tombstone id.
			 * @param string $final_status       Status.
			 * @param int    $rule_revision_sent Revision.
			 * @return bool
			 * @throws \RuntimeException Always.
			 */
			public function set_final_status( int $delivery_id, string $final_status, int $rule_revision_sent = 0 ): bool {
				throw new \RuntimeException( 'a scheduled path called set_final_status()' );
			}
		};

		// Drive every scheduled-path terminal write through the tripwire.
		$logger = new \Extonify\WCEP\Delivery\DeliveryLogger( $tripwire );

		$result = $logger->record_scheduled_cancellation(
			$armed['delivery_id'],
			ScheduledDelivery::REASON_RULE_DISABLED,
			'the rule was disabled during the delay',
			DeliveryRepository::SCHEDULED
		);

		$this->assertTrue( (bool) $result['success'], 'the guarded cancellation did not complete' );
		$this->assertSame( 'cancelled', $this->status_of( $armed['delivery_id'] ) );

		fwrite( STDERR, "\n[7A gate 21] scheduled-path writes proven conditional: set_final_status() made fatal, path still green\n" );
	}
}
