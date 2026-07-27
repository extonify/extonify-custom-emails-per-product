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
