<?php
/**
 * Lifecycle events FINALISE pending deliveries (ADR-0015 §8.5, §8.6; gate 19).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\ScheduledDelivery;
use Extonify\WCEP\Install\Deactivator;
use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Repository\DeliveryDetailRepository;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * Deactivation, uninstall and order deletion, plus the scheduling containment
 * boundary.
 *
 * ⚠ WHAT EACH OF THESE USED TO DO. Deactivation unscheduled the jobs and left
 * every tombstone `scheduled` — the delivery lost, its ADR-0004 identity still
 * consumed, and its snapshot retained for ever on a table that is never purged.
 * `uninstall.php` returned BEFORE its Action Scheduler cleanup whenever data was
 * retained, which is the DEFAULT, so the ordinary uninstall left queued actions
 * whose hook no longer exists. Order deletion removed the tombstones and left
 * their jobs pointing at nothing.
 *
 * The invariant asserted throughout is gate 19's, strengthened: **every
 * `scheduled` tombstone has a live job, and every job has a `scheduled` or
 * `executing` tombstone** — after each of these events, not only in the happy
 * path.
 */
final class ScheduledLifecycleTest extends ScheduledDeliveryTestCase {

	/**
	 * Arm N delayed deliveries on N separate orders.
	 *
	 * @param int $count How many.
	 * @return array[] `{delivery_id, order_id}` pairs.
	 */
	private function arm_deliveries( int $count ): array {
		$product = $this->make_product( 'WCEP lifecycle' );
		$rule_id = $this->make_delayed_rule( $product );
		$armed   = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$order    = $this->delayed_order( array( $product ) );
			$order_id = (int) $order->get_id();

			$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

			$tombstone = $this->scheduled_tombstone( $order_id, $rule_id );
			$this->assertNotNull( $tombstone, 'the fixture never armed delivery ' . $i );
			$this->assertSame( DeliveryRepository::SCHEDULED, (string) $tombstone['final_status'] );

			$armed[] = array(
				'delivery_id' => (int) $tombstone['id'],
				'order_id'    => $order_id,
			);
		}

		return $armed;
	}

	/**
	 * GATE 19, BOTH DIRECTIONS, over a set of deliveries.
	 *
	 * @param array[] $armed  `{delivery_id, order_id}` pairs.
	 * @param string  $moment What just happened, for the failure message.
	 * @return void
	 */
	private function assertPairingHolds( array $armed, string $moment ) {
		foreach ( $armed as $entry ) {
			$status = $this->status_of( (int) $entry['delivery_id'] );
			$job    = $this->has_job( (int) $entry['delivery_id'], (int) $entry['order_id'] );

			if ( DeliveryRepository::SCHEDULED === $status ) {
				$this->assertTrue( $job, "after {$moment}: a `scheduled` tombstone has no job" );
				continue;
			}

			if ( '' === $status ) {
				$this->assertFalse( $job, "after {$moment}: a job outlived the row it points at" );
				continue;
			}

			if ( DeliveryRepository::EXECUTING === $status ) {
				continue;
			}

			$this->assertFalse( $job, "after {$moment}: a terminal tombstone still has a job" );
		}
	}

	/**
	 * TEST 6 — DEACTIVATION.
	 *
	 * 3 scheduled deliveries in, and afterwards: 0 pending jobs, 0 `scheduled`
	 * tombstones, all `cancelled` with `plugin_deactivated`, all snapshots NULL.
	 * Reactivating sends nothing.
	 *
	 * @return void
	 */
	public function test_deactivation_finalises_every_pending_delivery() {
		$armed = $this->arm_deliveries( 3 );

		$this->assertPairingHolds( $armed, 'arming' );

		Deactivator::deactivate();

		foreach ( $armed as $entry ) {
			$delivery_id = (int) $entry['delivery_id'];

			$this->assertSame( 'cancelled', $this->status_of( $delivery_id ), 'deactivation left a delivery unfinalised' );
			$this->assertSame( ScheduledDelivery::REASON_PLUGIN_DEACTIVATED, $this->cancellation_code( $delivery_id ) );
			$this->assertNull( $this->raw_snapshot_of( $delivery_id ), 'deactivation retained a snapshot for ever' );
			$this->assertFalse( $this->has_job( $delivery_id, (int) $entry['order_id'] ), 'deactivation left a job pending' );
		}

		$this->assertSame(
			0,
			$this->deliveries->count_with_status( DeliveryRepository::SCHEDULED ),
			'a `scheduled` tombstone survived deactivation'
		);

		$this->assertPairingHolds( $armed, 'deactivation' );

		// ⚠ REACTIVATION SENDS NOTHING (ADR-0015 §1a, §8.7). The identities stay
		// consumed, which is what stops a plugin toggled off and on re-delivering
		// to every order still inside its delay window.
		$this->captured_mail = array();

		foreach ( $armed as $entry ) {
			$this->run_job( (int) $entry['delivery_id'], (int) $entry['order_id'] );
		}

		$this->assertCount( 0, $this->captured_mail, 'a deactivated-and-reactivated delivery still sent' );

		fwrite(
			STDERR,
			"\n[7A test 6] deactivation: 3 scheduled -> 0 jobs, 0 `scheduled` rows, 3 `cancelled`/plugin_deactivated, "
				. "3 snapshots NULL, reactivation sent 0\n"
		);
	}

	/**
	 * TEST 7 — UNINSTALL WITH DATA RETAINED, WHICH IS THE DEFAULT PATH.
	 *
	 * ⚠ THIS IS THE ONE THAT WAS BROKEN. The Action Scheduler cleanup sat below an
	 * early return, so the path almost every site takes left queued actions behind.
	 *
	 * @return void
	 */
	public function test_uninstall_with_retention_finalises_and_keeps_the_tables() {
		$armed = $this->arm_deliveries( 2 );

		// The default: the site owner keeps their data.
		update_option( 'extonify_wcep_remove_data_on_uninstall', 'no' );

		$this->run_uninstall();

		foreach ( $armed as $entry ) {
			$delivery_id = (int) $entry['delivery_id'];

			$this->assertSame( 'cancelled', $this->status_of( $delivery_id ), 'uninstall left a delivery `scheduled`' );
			$this->assertNull( $this->raw_snapshot_of( $delivery_id ), 'uninstall retained a snapshot' );
			$this->assertFalse( $this->has_job( $delivery_id, (int) $entry['order_id'] ), 'uninstall left a job pending' );
		}

		$this->assertSame( 0, $this->deliveries->count_with_status( DeliveryRepository::SCHEDULED ) );

		// The TABLES are still there — only the drops are conditional on the option.
		$this->assertTrue( $this->table_exists( Migrator::table( 'deliveries' ) ), 'retention was requested and the table was dropped' );
		$this->assertTrue( $this->table_exists( Migrator::table( 'delivery_details' ) ) );
		$this->assertTrue( $this->table_exists( Migrator::table( 'rules' ) ) );

		// And the history is intact: the detail rows the site owner asked to keep.
		$this->assertNotEmpty( $this->detail_rows( (int) $armed[0]['delivery_id'] ) );

		$this->assertPairingHolds( $armed, 'uninstall with retention' );

		fwrite(
			STDERR,
			"\n[7A test 7] uninstall with data RETAINED: 0 pending jobs, 2 tombstones finalised, 2 snapshots released, "
				. "3 tables still present\n"
		);
	}

	/**
	 * TEST 7B-5 — DEACTIVATION WITH A DELIVERY MID-FLIGHT (ADR-0015 §8.8).
	 *
	 * ⚠ `executing` USED TO BE INVISIBLE TO EVERY LIFECYCLE PATH, and the reasoning
	 * for that — "a running delivery is not cancelled out from under its worker" — is
	 * correct during normal running and wrong during shutdown. Deactivation also
	 * removes the maintenance action, so the stale-lease sweep that would have
	 * recovered the row an hour later was gone with it: nothing could ever move it
	 * again.
	 *
	 * `unresolved`, not `cancelled` and not `failed`: nobody knows whether the worker
	 * sent before the shutdown, and both of the others assert something about a
	 * customer's inbox that this code cannot know.
	 *
	 * @return void
	 */
	public function test_deactivation_finalises_an_executing_delivery_as_unresolved() {
		$armed = $this->arm_deliveries( 2 );

		$scheduled = $armed[0];
		$leased    = $armed[1];

		// The second one is mid-flight, through the real transition.
		$this->backdate_lease( (int) $leased['delivery_id'], 0 );
		$this->assertSame( DeliveryRepository::EXECUTING, $this->status_of( (int) $leased['delivery_id'] ) );

		Deactivator::deactivate();

		$this->assertSame(
			'cancelled',
			$this->status_of( (int) $scheduled['delivery_id'] ),
			'the `scheduled` delivery was not cancelled'
		);
		$this->assertSame(
			ScheduledDelivery::REASON_PLUGIN_DEACTIVATED,
			$this->cancellation_code( (int) $scheduled['delivery_id'] )
		);

		$this->assertSame(
			DeliveryDetailRepository::UNRESOLVED,
			$this->status_of( (int) $leased['delivery_id'] ),
			'deactivation left a delivery stuck `executing` with nothing able to move it'
		);
		$this->assertSame(
			ScheduledDelivery::REASON_SHUTDOWN_INTERRUPT,
			$this->cancellation_code( (int) $leased['delivery_id'] ),
			'an interrupted lease must say WHY it is unresolved'
		);

		foreach ( $armed as $entry ) {
			$this->assertNull(
				$this->raw_snapshot_of( (int) $entry['delivery_id'] ),
				'a finalised delivery kept its snapshot for ever'
			);
			$this->assertFalse(
				$this->has_job( (int) $entry['delivery_id'], (int) $entry['order_id'] ),
				'deactivation left a job pending'
			);
		}

		$this->assertSame( 0, $this->deliveries->count_pending(), 'a pending tombstone survived deactivation' );
		$this->assertPairingHolds( $armed, 'deactivation with a delivery mid-flight' );

		fwrite(
			STDERR,
			"\n[7B C] deactivation with 1 `scheduled` + 1 `executing`: 0 pending jobs, `cancelled`/plugin_deactivated + "
				. "`unresolved`/lease_interrupted_by_shutdown, both snapshots NULL\n"
		);
	}

	/**
	 * TEST 7B-6 — UNINSTALL WITH RETENTION, WITH A DELIVERY MID-FLIGHT.
	 *
	 * Same two outcomes, reached through `uninstall.php`'s dependency-free SQL, and
	 * the tables are still there because the site owner kept their data.
	 *
	 * @return void
	 */
	public function test_uninstall_with_retention_finalises_an_executing_delivery() {
		$armed = $this->arm_deliveries( 2 );

		$scheduled = $armed[0];
		$leased    = $armed[1];

		$this->backdate_lease( (int) $leased['delivery_id'], 0 );
		$this->assertSame( DeliveryRepository::EXECUTING, $this->status_of( (int) $leased['delivery_id'] ) );

		update_option( 'extonify_wcep_remove_data_on_uninstall', 'no' );

		$this->run_uninstall();

		$this->assertSame( 'cancelled', $this->status_of( (int) $scheduled['delivery_id'] ) );
		$this->assertSame(
			DeliveryDetailRepository::UNRESOLVED,
			$this->status_of( (int) $leased['delivery_id'] ),
			'uninstall left a delivery stuck `executing`'
		);

		foreach ( $armed as $entry ) {
			$this->assertNull( $this->raw_snapshot_of( (int) $entry['delivery_id'] ), 'uninstall retained a snapshot' );
			$this->assertFalse(
				$this->has_job( (int) $entry['delivery_id'], (int) $entry['order_id'] ),
				'uninstall left a job pending'
			);
		}

		$this->assertSame( 0, $this->deliveries->count_pending() );

		$this->assertTrue( $this->table_exists( Migrator::table( 'deliveries' ) ), 'retention was requested and the table was dropped' );
		$this->assertTrue( $this->table_exists( Migrator::table( 'delivery_details' ) ) );
		$this->assertTrue( $this->table_exists( Migrator::table( 'rules' ) ) );

		fwrite(
			STDERR,
			"\n[7B C] uninstall with retention, 1 `scheduled` + 1 `executing`: both finalised, snapshots released, "
				. "3 tables intact\n"
		);
	}

	/**
	 * TEST 7B-7 — DEACTIVATION WHILE THE SCHEMA CHECK FAILS.
	 *
	 * ⚠ THIS IS THE ORDERING DEFECT. `cancel_all_pending()` returns early when the
	 * schema is unreadable, and the hook-wide unschedule below it ran anyway — a
	 * sweep with no tombstone in its hand, removing every job while every tombstone
	 * stayed pending. That is precisely the stranded state this ADR exists to
	 * eliminate, produced by the code written to prevent it.
	 *
	 * The jobs are left in place instead, and the reason is logged. A queued job for
	 * a deactivated plugin does nothing — its hook has no handler — so leaving it is
	 * strictly the safer failure.
	 *
	 * @return void
	 */
	public function test_deactivation_with_an_unreadable_schema_leaves_every_job_alone() {
		$armed       = $this->arm_deliveries( 1 );
		$delivery_id = (int) $armed[0]['delivery_id'];
		$order_id    = (int) $armed[0]['order_id'];

		$this->assertTrue( $this->has_job( $delivery_id, $order_id ) );

		$logged = $this->capture_wc_log(
			function () {
				$this->with_broken_schema(
					static function () {
						Deactivator::deactivate();
					}
				);
			}
		);

		$this->assertSame(
			DeliveryRepository::SCHEDULED,
			$this->status_of( $delivery_id ),
			'the schema was unreadable, so nothing could have been finalised'
		);
		$this->assertTrue(
			$this->has_job( $delivery_id, $order_id ),
			'deactivation unscheduled a job while its tombstone was still pending — the exact stranding gate 19 forbids'
		);
		$this->assertPairingHolds( $armed, 'deactivation with an unreadable schema' );

		$this->assertNotSame(
			'',
			$logged,
			'the condition was not logged: a merchant has no way to find out why work was left behind'
		);
		$this->assertStringContainsString( 'tables', $logged );

		fwrite(
			STDERR,
			"\n[7B C] deactivation with an unreadable schema: 0 jobs removed, tombstone still `scheduled`, condition logged\n"
		);
	}

	/**
	 * GATE 22 — LIFECYCLE COMPLETENESS, ENUMERATED.
	 *
	 * For each of `scheduled` and `executing`, and for each lifecycle event, there
	 * must be no reachable state in which the tombstone is non-terminal, no job can
	 * complete it, and no sweep can reach it. The five events and what makes each
	 * safe:
	 *
	 *   1. **deactivation** — both states finalised, then and only then the jobs
	 *      removed (§8.8). Asserted by
	 *      self::test_deactivation_finalises_an_executing_delivery_as_unresolved().
	 *   2. **uninstall with retention** — same two transitions in raw SQL. Asserted
	 *      by self::test_uninstall_with_retention_finalises_an_executing_delivery().
	 *   3. **uninstall with removal** — the tombstone table is DROPPED, so no row can
	 *      be pending. Asserted below, because "there are no rows" is a claim about
	 *      behaviour rather than a tautology: the finalisation runs first and the
	 *      drop is what makes it moot.
	 *   4. **order deletion** — the exact job is unscheduled BEFORE the rows go, and
	 *      a job that outlives its row finds nothing and stops. Asserted by
	 *      self::test_order_deletion_unschedules_before_deleting_the_tombstone().
	 *   5. **schema unavailable** — NOTHING is removed, so every pending row keeps
	 *      its job and the maintenance sweep keeps its action. Asserted by
	 *      self::test_deactivation_with_an_unreadable_schema_leaves_every_job_alone().
	 *
	 * @return void
	 */
	public function test_uninstall_with_removal_leaves_no_pending_delivery_behind() {
		$armed = $this->arm_deliveries( 2 );

		$this->backdate_lease( (int) $armed[1]['delivery_id'], 0 );
		$this->assertSame( DeliveryRepository::EXECUTING, $this->status_of( (int) $armed[1]['delivery_id'] ) );

		update_option( 'extonify_wcep_remove_data_on_uninstall', 'yes' );

		try {
			$this->run_uninstall();

			// No table, therefore no pending tombstone — and no job either, because
			// the finalisation ran BEFORE the drop and the sweep was authorised.
			$this->assertFalse( $this->table_exists( Migrator::table( 'deliveries' ) ), 'removal was requested and the table survived' );
			$this->assertFalse( $this->table_exists( Migrator::table( 'delivery_details' ) ) );
			$this->assertFalse( $this->table_exists( Migrator::table( 'rules' ) ) );

			foreach ( $armed as $entry ) {
				$this->assertFalse(
					$this->has_job( (int) $entry['delivery_id'], (int) $entry['order_id'] ),
					'uninstall-with-removal left a job pointing at a table that no longer exists'
				);
			}
		} finally {
			// Put the schema back for every test that follows, with the suite's own
			// repair routine rather than a second notion of "put it back".
			self::force_rebuild_schema();
			update_option( 'extonify_wcep_remove_data_on_uninstall', 'no' );
		}

		$this->assertTrue( Migrator::is_operational( true ), 'the schema was not restored after the removal test' );

		fwrite(
			STDERR,
			"\n[7B gate 22] uninstall with REMOVAL: pending rows finalised, tables dropped, 0 jobs left pointing at them\n"
		);
	}

	/**
	 * Run a callback with this plugin's tables renamed away.
	 *
	 * ⚠ THE REAL CHECK IS BROKEN, NOT MOCKED — the same fixture
	 * `ScheduledExitBranchesTest` uses. `Migrator::is_operational()` memoises
	 * `verify_schema()`, so renaming the table and flushing the cache makes the
	 * production code take its production branch.
	 *
	 * @param callable $callback What to run.
	 * @return void
	 */
	private function with_broken_schema( callable $callback ) {
		global $wpdb;

		$table  = Migrator::table( 'deliveries' );
		$parked = $table . '_parked_7b';

		$wpdb->query( "RENAME TABLE {$table} TO {$parked}" );
		Migrator::flush_schema_cache();

		try {
			$this->assertFalse( Migrator::is_operational(), 'the fixture did not actually break the schema' );
			$callback();
		} finally {
			$wpdb->query( "RENAME TABLE {$parked} TO {$table}" );
			Migrator::flush_schema_cache();
		}
	}

	/**
	 * Collect everything this plugin writes to WooCommerce's log during a callback.
	 *
	 * WooCommerce's own `woocommerce_logger_log_message` filter, so the assertion is
	 * against the real logging path rather than a stub of it.
	 *
	 * @param callable $callback What to run.
	 * @return string The log lines, joined.
	 */
	private function capture_wc_log( callable $callback ): string {
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
	 * TEST 8 — ORDER DELETION unschedules the exact action BEFORE the rows go.
	 *
	 * @return void
	 */
	public function test_order_deletion_unschedules_before_deleting_the_tombstone() {
		$armed       = $this->arm_deliveries( 1 );
		$delivery_id = (int) $armed[0]['delivery_id'];
		$order_id    = (int) $armed[0]['order_id'];

		$this->assertTrue( $this->has_job( $delivery_id, $order_id ) );

		// The real hook the plugin listens on.
		do_action( 'woocommerce_delete_order', $order_id );

		$this->assertNull( $this->deliveries->find_by_id( $delivery_id ), 'the tombstone survived order deletion' );
		$this->assertFalse(
			$this->has_job( $delivery_id, $order_id ),
			'an action outlived the row it points at — gate 19'
		);

		// A job that somehow did survive would be harmless, and that is asserted
		// rather than assumed: `run()` finds no tombstone and stops.
		$this->captured_mail = array();
		ScheduledDelivery::run( $delivery_id, $order_id );
		$this->assertCount( 0, $this->captured_mail );

		fwrite( STDERR, "\n[7A test 8] order deletion: job unscheduled BEFORE the tombstone was deleted; no action outlived its row\n" );
	}

	/**
	 * TEST 5 — SCHEDULING THROWS AND NO JOB EXISTS (ADR-0015 §8.5).
	 *
	 * The order event does not throw, no job exists, the tombstone is terminal,
	 * and the snapshot is released.
	 *
	 * ⚠ THE THROW COMES FROM WORDPRESS'S OWN `query` FILTER, not from a stub of
	 * this plugin's code — a real extension point that query monitors, replication
	 * plugins and read/write splitters all hook. That is what makes this a boundary
	 * test rather than a mock.
	 *
	 * ⚠⚠ AND `action_scheduler_pre_schedule_single_action` DOES NOT EXIST in the
	 * bundled Action Scheduler (WooCommerce 10.9.4). This test was written against
	 * it first and passed vacuously — the filter never fired, the job scheduled
	 * normally, and the assertion caught it. Recorded rather than quietly swapped:
	 * a hook that does not exist is a test that proves nothing.
	 *
	 * @return void
	 */
	public function test_a_throw_during_scheduling_never_escapes_the_order_event() {
		$outcome = $this->schedule_with_a_throw_matching( 'actionscheduler_actions', 'the queue backend is having a bad day' );

		$this->assertNull( $outcome['threw'], 'a scheduling throw escaped into woocommerce_order_status_changed' );

		$delivery_id = $outcome['delivery_id'];

		$this->assertFalse( $this->has_job( $delivery_id, $outcome['order_id'] ), 'a throw left a job behind' );
		$this->assertNotContains(
			$this->status_of( $delivery_id ),
			DeliveryRepository::IN_FLIGHT_STATUSES,
			'a scheduling throw stranded the tombstone with no job'
		);
		$this->assertNull( $this->raw_snapshot_of( $delivery_id ), 'a terminal delivery kept its snapshot' );
		$this->assertCount( 0, $this->captured_mail );

		fwrite(
			STDERR,
			"\n[7A test 5] scheduling threw (no job): order event did NOT throw, 0 jobs, tombstone terminal ("
				. $this->status_of( $delivery_id ) . "), snapshot released\n"
		);
	}

	/**
	 * TEST 5, THE OTHER BRANCH — the throw beats the ARM, leaving the row
	 * `claimed` rather than `scheduled`.
	 *
	 * ⚠ THIS IS THE CASE THE FIRST VERSION OF THE BOUNDARY MISSED. It finalised
	 * with `scheduled -> cancelled`, which lands on nothing when the row never got
	 * armed — stranding a consumed identity with no job and no reason. Found by
	 * writing this test.
	 *
	 * @return void
	 */
	public function test_a_throw_before_the_arm_still_finalises_the_claim() {
		$outcome = $this->schedule_with_a_throw_matching( 'SET snapshot =', 'the database went away mid-arm' );

		$this->assertNull( $outcome['threw'], 'the throw escaped into the order event' );

		$delivery_id = $outcome['delivery_id'];

		$this->assertNotContains(
			$this->status_of( $delivery_id ),
			DeliveryRepository::IN_FLIGHT_STATUSES,
			'a throw during arming left the identity consumed and the tombstone in flight'
		);
		$this->assertFalse( $this->has_job( $delivery_id, $outcome['order_id'] ) );
		$this->assertCount( 0, $this->captured_mail );

		fwrite(
			STDERR,
			"\n[7A test 5b] throw BEFORE the arm: tombstone finalised (" . $this->status_of( $delivery_id )
				. ") rather than stranded `claimed`\n"
		);
	}

	/**
	 * TEST 5, THE THIRD BRANCH — the throw lands AFTER the job exists.
	 *
	 * The delivery genuinely stands, so the tombstone KEEPS `scheduled` and only
	 * the diagnostic record is short. Cancelling here would throw away a delivery
	 * that is still going to happen.
	 *
	 * @return void
	 */
	public function test_a_throw_after_the_job_exists_keeps_the_delivery() {
		$product  = $this->make_product( 'WCEP post-queue throw' );
		$rule_id  = $this->make_delayed_rule( $product );
		$order    = $this->delayed_order( array( $product ) );
		$order_id = (int) $order->get_id();

		// Action Scheduler's own hook, fired AFTER the action row is stored.
		$bomb = static function () {
			throw new \RuntimeException( 'a logger hooked to action_scheduler_stored_action exploded' );
		};
		add_action( 'action_scheduler_stored_action', $bomb, 1 );

		$threw = null;

		try {
			$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );
		} catch ( \Throwable $error ) {
			$threw = $error;
		} finally {
			remove_action( 'action_scheduler_stored_action', $bomb, 1 );
		}

		$this->assertNull( $threw, 'the throw escaped into woocommerce_order_status_changed' );

		$tombstone = $this->scheduled_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone );

		$delivery_id = (int) $tombstone['id'];

		$this->assertTrue( $this->has_job( $delivery_id, $order_id ), 'the job was stored before the throw' );
		$this->assertSame(
			DeliveryRepository::SCHEDULED,
			$this->status_of( $delivery_id ),
			'a delivery that is genuinely queued was cancelled because the RECORD of it threw'
		);
		$this->assertNotNull( $this->snapshot_of( $delivery_id ), 'the snapshot was released while the delivery still stands' );

		fwrite( STDERR, "\n[7A test 5c] throw AFTER the job existed: delivery kept `scheduled`, job intact, shortfall recorded\n" );
	}

	/**
	 * Arm a delayed delivery while a real `query`-filter callback throws on the
	 * first statement whose SQL contains a needle.
	 *
	 * @param string $needle  Substring identifying the statement to blow up on.
	 * @param string $message Exception message.
	 * @return array{threw:\Throwable|null,delivery_id:int,order_id:int}
	 */
	private function schedule_with_a_throw_matching( string $needle, string $message ): array {
		$product  = $this->make_product( 'WCEP schedule throw' );
		$rule_id  = $this->make_delayed_rule( $product );
		$order    = $this->delayed_order( array( $product ) );
		$order_id = (int) $order->get_id();

		$fired = false;

		$bomb = static function ( $query ) use ( $needle, $message, &$fired ) {
			if ( ! $fired && false !== strpos( (string) $query, $needle ) ) {
				$fired = true;
				throw new \RuntimeException( $message );
			}

			return $query;
		};

		add_filter( 'query', $bomb, 1 );

		$threw = null;

		try {
			// If the boundary leaks, THIS throws — which in production is the
			// merchant's order status change breaking.
			$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );
		} catch ( \Throwable $error ) {
			$threw = $error;
		} finally {
			remove_filter( 'query', $bomb, 1 );
		}

		$this->assertTrue( $fired, "the fixture never threw: no statement contained `{$needle}`" );

		$tombstone = $this->scheduled_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone, 'nothing was claimed, so the boundary was never reached' );

		return array(
			'threw'       => $threw,
			'delivery_id' => (int) $tombstone['id'],
			'order_id'    => $order_id,
		);
	}

	/**
	 * The uninstall script, run against the live test database.
	 *
	 * `WP_UNINSTALL_PLUGIN` is what the file guards on; it is defined by
	 * WordPress for the real uninstall and by `bin/uninstall-smoke.php` for the
	 * standalone smoke test.
	 *
	 * @return void
	 */
	private function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'extonify-custom-emails-per-product/extonify-custom-emails-per-product.php' );
		}

		require dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	/**
	 * Whether a table exists in the test database.
	 *
	 * @param string $table Fully-prefixed table name.
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		return (string) $table === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
