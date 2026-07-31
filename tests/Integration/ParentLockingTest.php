<?php
/**
 * Parent-row locking against the insert/cleanup race (Prompt 2c Item 4).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Repository\DeliveryDetailRepository;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * Order cleanup resolves tombstone ids, deletes the details, then deletes the
 * tombstones. Without a lock, an insert landing between those two deletes
 * writes a detail row whose parent is about to vanish — and there is no
 * database foreign key to stop it.
 *
 * Both paths now take the parent row `FOR UPDATE` inside a transaction, so they
 * contend on the same row instead of interleaving.
 */
final class ParentLockingTest extends IntegrationTestCase {

	/**
	 * Tombstone repository.
	 *
	 * @var DeliveryRepository
	 */
	private $deliveries;

	/**
	 * Detail repository.
	 *
	 * @var DeliveryDetailRepository
	 */
	private $details;

	/**
	 * Build repositories.
	 *
	 * @before
	 * @return void
	 */
	protected function set_up_repos() {
		$this->deliveries = new DeliveryRepository();
		$this->details    = new DeliveryDetailRepository();
	}

	/**
	 * A second, independent database connection, so a real lock conflict can be
	 * observed rather than simulated.
	 *
	 * @return \wpdb|null Null when a second connection cannot be opened.
	 */
	private function second_connection(): ?\wpdb {
		if ( ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_HOST' ) ) {
			return null;
		}

		$second = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		if ( ! empty( $second->error ) ) {
			return null;
		}

		// Follow the suite onto the test database, and inherit the prefix so
		// Migrator::table() names resolve identically.
		global $wpdb;
		$second->set_prefix( $wpdb->prefix );
		$second->select( (string) $wpdb->get_var( 'SELECT DATABASE()' ) );
		$second->suppress_errors( true );

		$probe = $second->get_var( 'SELECT 1' );
		return ( '1' === (string) $probe ) ? $second : null;
	}

	/**
	 * THE RACE, on two real connections.
	 *
	 * Connection A opens a transaction and takes the tombstone FOR UPDATE — the
	 * state cleanup is in between its child and parent deletes. Connection B
	 * then tries to lock the same row with a short timeout. If the lock is
	 * genuinely held, B blocks and times out; if it is not, B proceeds
	 * immediately and the race is open.
	 *
	 * @return void
	 */
	public function test_parent_row_lock_is_actually_held() {
		$second = $this->second_connection();
		if ( null === $second ) {
			$this->markTestSkipped( 'A second database connection could not be opened in this environment.' );
		}

		global $wpdb;

		$claim = $this->deliveries->claim( $this->fake_order_id(), 220, 'separate', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );

		$table = Migrator::table( 'deliveries' );

		// Connection A: hold the parent row.
		$wpdb->query( 'START TRANSACTION' );
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $claim['delivery_id'] ) );
		$this->assertSame( (string) $claim['delivery_id'], (string) $held );

		// Connection B: the same row, with a short lock timeout so the test
		// cannot hang.
		$second->query( 'SET SESSION innodb_lock_wait_timeout = 2' );
		$second->query( 'START TRANSACTION' );
		$start   = microtime( true );
		$blocked = $second->get_var( $second->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $claim['delivery_id'] ) );
		$elapsed = microtime( true ) - $start;
		$error   = (string) $second->last_error;
		$second->query( 'ROLLBACK' );

		// Connection A releases.
		$wpdb->query( 'COMMIT' );

		$this->assertNull( $blocked, 'The second connection acquired the lock — the parent row was NOT held.' );
		$this->assertStringContainsString( 'ock', $error, "Expected a lock-wait error, got: {$error}" );
		$this->assertGreaterThan( 1.0, $elapsed, 'The second connection did not actually wait for the lock.' );

		$second->close();
	}

	/**
	 * With the lock in place, a cleanup running while a competing connection
	 * holds the parent cannot half-complete: it either waits or fails closed,
	 * and never leaves an orphan.
	 *
	 * @return void
	 */
	public function test_cleanup_never_leaves_an_orphan_under_contention() {
		$second = $this->second_connection();
		if ( null === $second ) {
			$this->markTestSkipped( 'A second database connection could not be opened in this environment.' );
		}

		global $wpdb;

		$order_id = $this->fake_order_id();
		$claim    = $this->deliveries->claim( $order_id, 221, 'separate', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );
		$this->track_detail(
			$this->details->insert(
				$claim['delivery_id'],
				array(
					'state'     => 'sent',
					'recipient' => 'wcep-lock-' . strtolower( wp_generate_password( 8, false, false ) ) . '@example.test',
				)
			)
		);

		$table = Migrator::table( 'deliveries' );

		// The competing connection holds the parent, as a concurrent insert
		// would while it validates.
		$second->query( 'SET SESSION innodb_lock_wait_timeout = 2' );
		$second->query( 'START TRANSACTION' );
		$second->get_var( $second->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $claim['delivery_id'] ) );

		// Cleanup on this connection now contends for the same row. Keep its
		// wait short so the test cannot hang.
		$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 2' );
		$suppressed = $wpdb->suppress_errors( true );
		$result     = $this->deliveries->delete_for_order( $order_id );
		$wpdb->suppress_errors( $suppressed );
		$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = DEFAULT' );

		$second->query( 'ROLLBACK' );
		$second->close();

		// Whichever way it went, the invariant holds: never a tombstone deleted
		// while its details survive.
		$remaining_tombstones = count( $this->deliveries->find_for_order( $order_id ) );
		$remaining_details    = count( $this->details->find_for_delivery( $claim['delivery_id'] ) );

		if ( $result['success'] ) {
			$this->assertSame( 0, $remaining_tombstones );
			$this->assertSame( 0, $remaining_details );
		} else {
			$this->assertSame( 1, $remaining_tombstones, 'A failed cleanup deleted the tombstone anyway.' );
			$this->assertSame( 1, $remaining_details, 'A failed cleanup orphaned the detail row.' );
			$this->assertSame( 0, $result['tombstones_deleted'] );
		}
	}

	/**
	 * The ordinary paths still work with the transactions in place — locking
	 * must not have broken the common case.
	 *
	 * @return void
	 */
	public function test_insert_and_cleanup_still_work_normally() {
		$order_id = $this->fake_order_id();
		$claim    = $this->deliveries->claim( $order_id, 222, 'separate', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );

		$id = $this->details->insert(
			$claim['delivery_id'],
			array(
				'state'     => 'sent',
				'recipient' => 'wcep-normal-' . strtolower( wp_generate_password( 8, false, false ) ) . '@example.test',
			)
		);
		$this->track_detail( $id );
		$this->assertGreaterThan( 0, $id );

		$result = $this->deliveries->delete_for_order( $order_id );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['tombstones_deleted'] );
		$this->assertSame( 1, $result['details_deleted'] );
	}

	/**
	 * 5B-5. ATTEMPT ALLOCATION IS SERIALISED BY THE PARENT LOCK, on two real
	 * connections (ADR-0009 amendment).
	 *
	 * ⚠ THE TWO RACES THIS CLOSES. The attempt number used to be computed OUTSIDE
	 * the transaction that later locked the parent, and from the tombstone's
	 * `suppressed_count` rather than from the attempt rows:
	 *
	 *   1. two concurrent recorders read the same count and wrote the same attempt
	 *      number;
	 *   2. the recorder that FINISHED last wrote `final_status`, even if it had
	 *      allocated the EARLIER attempt — so a tombstone could report the outcome of
	 *      attempt 2 while holding attempt 3.
	 *
	 * Both are impossible once allocation, insertion and the status write happen
	 * inside one transaction holding the parent row: whoever allocates the higher
	 * number is necessarily the one who writes last.
	 *
	 * @return void
	 */
	public function test_attempt_allocation_and_status_are_serialised_by_the_parent_lock() {
		$second = $this->second_connection();
		if ( null === $second ) {
			$this->markTestSkipped( 'A second database connection could not be opened in this environment.' );
		}

		global $wpdb;

		$primary = $wpdb;
		$details = new QuietDetailRepository();

		$order_id = $this->fake_order_id();
		$claim    = $this->deliveries->claim( $order_id, 224, 'insert', 'native:customer_processing_order' );
		$this->track_delivery( $claim['delivery_id'] );

		$delivery_id = (int) $claim['delivery_id'];

		// --- Attempt 1, on connection A. --------------------------------------
		$first = $details->record_attempt( $delivery_id, array( 'state' => 'sent' ), 'sent', 7 );
		$this->track_detail( (int) $first['id'] );

		$this->assertSame( 1, $first['attempt'] );
		$this->assertTrue( $first['status_written'] );

		/*
		 * --- WHILE A HOLDS THE PARENT, B CANNOT ALLOCATE OR WRITE STATUS. -----
		 *
		 * This is the state connection A is in for the whole of its own
		 * `record_attempt()`: transaction open, parent row held. The old code did its
		 * allocation OUTSIDE this window, which is exactly why two recorders could
		 * agree on a number.
		 */
		$table = Migrator::table( 'deliveries' );

		$primary->query( 'START TRANSACTION' );
		$primary->get_var( $primary->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $delivery_id ) );

		$second->query( 'SET SESSION innodb_lock_wait_timeout = 2' );

		$GLOBALS['wpdb'] = $second;

		$start   = microtime( true );
		$blocked = $details->record_attempt( $delivery_id, array( 'state' => 'failed' ), 'failed', 9 );
		$elapsed = microtime( true ) - $start;

		$GLOBALS['wpdb'] = $primary;

		$this->assertSame( 0, $blocked['id'], 'The second connection wrote an attempt row while the parent was held.' );
		$this->assertSame( 0, $blocked['attempt'], 'The second connection allocated an attempt number under the lock.' );
		$this->assertFalse( $blocked['status_written'], 'The second connection wrote final_status under the lock.' );
		$this->assertGreaterThan( 1.0, $elapsed, 'The second connection did not actually wait for the lock.' );

		$primary->query( 'COMMIT' );

		$held = $this->deliveries->find_by_id( $delivery_id );
		$this->assertSame( 'sent', $held['final_status'], 'A blocked recorder changed the tombstone anyway.' );
		$this->assertSame( 7, (int) $held['rule_revision_sent'] );

		/*
		 * --- AND THE NUMBER COMES FROM THE ATTEMPT ROWS, NOT `suppressed_count`. -
		 *
		 * Three more claims of the same identity push `suppressed_count` to 3 without
		 * writing any attempt row. The old allocator would call the next attempt 4.
		 */
		for ( $i = 0; $i < 3; $i++ ) {
			// THE SAME IDENTITY, so each claim is suppressed and increments the
			// counter without writing anything.
			$this->deliveries->claim( $order_id, 224, 'insert', 'native:customer_processing_order' );
		}

		$bumped = $this->deliveries->find_by_id( $delivery_id );
		$this->assertGreaterThan( 0, (int) $bumped['suppressed_count'], 'The claim counter did not move, so the probe proves nothing.' );

		// --- Attempt 2, now on connection B, which sees A's committed row. -----
		$second->query( 'SET SESSION innodb_lock_wait_timeout = DEFAULT' );
		$GLOBALS['wpdb'] = $second;

		$on_b = $details->record_attempt( $delivery_id, array( 'state' => 'failed' ), 'failed', 9 );

		$GLOBALS['wpdb'] = $primary;
		$this->track_detail( (int) $on_b['id'] );

		$this->assertSame(
			2,
			$on_b['attempt'],
			'The attempt number came from suppressed_count instead of MAX(attempt).'
		);

		// --- Attempt 3, back on connection A. ---------------------------------
		$on_a = $details->record_attempt( $delivery_id, array( 'state' => 'sent' ), 'sent', 11 );
		$this->track_detail( (int) $on_a['id'] );

		$this->assertSame( 3, $on_a['attempt'] );

		// --- Consecutive, unique, and the status belongs to the HIGHEST. -------
		$rows     = $details->find_for_delivery( $delivery_id );
		$attempts = array_map( 'intval', wp_list_pluck( $rows, 'attempt' ) );

		$this->assertSame( array( 1, 2, 3 ), $attempts, 'Attempt numbers are not consecutive and unique.' );
		$this->assertSame( count( $attempts ), count( array_unique( $attempts ) ) );

		$highest   = $rows[ count( $rows ) - 1 ];
		$tombstone = $this->deliveries->find_by_id( $delivery_id );

		$this->assertSame( 3, (int) $highest['attempt'] );
		$this->assertSame(
			(string) $highest['state'],
			(string) $tombstone['final_status'],
			'The tombstone contradicts its own highest attempt.'
		);
		$this->assertSame(
			11,
			(int) $tombstone['rule_revision_sent'],
			'The revision belongs to an earlier attempt than the status does.'
		);

		$second->close();

		fwrite(
			STDERR,
			sprintf(
				"\n[5B item 5] two real connections, one tombstone:\n"
				. "  A attempt 1 (sent, rev 7); B blocked %.1fs under A's parent lock and wrote NOTHING\n"
				. "  suppressed_count pushed to %d, next allocation still %d — MAX(attempt)+1, not the claim counter\n"
				. "  B attempt 2 (failed, rev 9); A attempt 3 (sent, rev 11)\n"
				. "  attempts %s; tombstone final_status=%s rev=%d = the HIGHEST attempt's own outcome\n",
				$elapsed,
				(int) $bumped['suppressed_count'],
				$on_b['attempt'],
				implode( ',', $attempts ),
				$tombstone['final_status'],
				(int) $tombstone['rule_revision_sent']
			)
		);
	}

	/**
	 * A rejected insert leaves no open transaction behind, so the next
	 * statement on this connection is not silently swept into one.
	 *
	 * @return void
	 */
	public function test_rejected_insert_does_not_leak_a_transaction() {
		global $wpdb;

		// Rejected: the delivery does not exist.
		$this->assertSame( 0, ( new QuietDetailRepository() )->insert( 987654321, array( 'state' => 'sent' ) ) );

		// If a transaction were still open, this row would be invisible to the
		// second connection until commit. Prove it is immediately visible.
		$claim = $this->deliveries->claim( $this->fake_order_id(), 223, 'separate', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );

		$second = $this->second_connection();
		if ( null === $second ) {
			$this->markTestSkipped( 'A second database connection could not be opened in this environment.' );
		}

		$table   = Migrator::table( 'deliveries' );
		$visible = $second->get_var( $second->prepare( "SELECT id FROM {$table} WHERE id = %d", $claim['delivery_id'] ) );
		$second->close();

		$this->assertSame(
			(string) $claim['delivery_id'],
			(string) $visible,
			'A rejected insert left an open transaction, so later writes were not committed.'
		);
	}
}
