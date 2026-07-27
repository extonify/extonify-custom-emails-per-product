<?php
/**
 * Fail-closed atomic claim semantics (ADR-0004(b)).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Domain\DeliveryIdentity;
use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * A repository pointed at a table that does not exist, so claim() takes the
 * query-failure branch for real rather than being mocked into it.
 */
final class BrokenDeliveryRepository extends DeliveryRepository {

	/**
	 * A deliberately non-existent table.
	 *
	 * @return string
	 */
	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'extonify_wcep_does_not_exist';
	}

	/**
	 * Swallow the log write so the failure path stays silent in tests.
	 *
	 * @param string $message Error detail.
	 * @return void
	 */
	protected function log_error( string $message ): void {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- deliberate no-op override; the message is intentionally discarded in tests.
}

/**
 * The claim is the single point where a duplicate email is prevented. Every
 * branch of ADR-0004(b) is asserted against the live database.
 */
final class ClaimTest extends IntegrationTestCase {

	/**
	 * Repository under test.
	 *
	 * @var DeliveryRepository
	 */
	private $repo;

	/**
	 * Build the repository.
	 *
	 * @before
	 * @return void
	 */
	protected function set_up_repo() {
		$this->repo = new DeliveryRepository();
	}

	/**
	 * A first claim is CLAIMED, writes one row, and starts at
	 * suppressed_count 0.
	 *
	 * @return void
	 */
	public function test_first_claim_is_claimed() {
		$order_id = $this->fake_order_id();
		$identity = $this->unique_identity();

		$result = $this->repo->claim( $order_id, 11, 'insert', $identity );
		$this->track_delivery( $result['delivery_id'] );

		$this->assertSame( DeliveryRepository::CLAIMED, $result['result'] );
		$this->assertGreaterThan( 0, $result['delivery_id'] );
		$this->assertSame(
			DeliveryIdentity::hash( $order_id, 11, 'insert', $identity ),
			$result['identity_hash']
		);
		$this->assertSame( '0', (string) $this->delivery_column( $result['delivery_id'], 'suppressed_count' ) );
		$this->assertSame( 'claimed', (string) $this->delivery_column( $result['delivery_id'], 'final_status' ) );
	}

	/**
	 * THE CORE IDEMPOTENCY ASSERTION: claimed, then suppressed, then suppressed
	 * again — with suppressed_count incrementing on the database side each
	 * time and NO second row ever appearing.
	 *
	 * @return void
	 */
	public function test_claim_then_suppressed_then_suppressed_with_atomic_counter() {
		$order_id = $this->fake_order_id();
		$identity = $this->unique_identity();

		$first = $this->repo->claim( $order_id, 12, 'separate', $identity );
		$this->track_delivery( $first['delivery_id'] );
		$rows_after_first = $this->repo->count();

		$second = $this->repo->claim( $order_id, 12, 'separate', $identity );
		$third  = $this->repo->claim( $order_id, 12, 'separate', $identity );

		$this->assertSame( DeliveryRepository::CLAIMED, $first['result'] );
		$this->assertSame( DeliveryRepository::SUPPRESSED, $second['result'] );
		$this->assertSame( DeliveryRepository::SUPPRESSED, $third['result'] );

		// LAST_INSERT_ID(id) hands the existing row's id back on the duplicate
		// branch, so no second lookup is needed and the id is stable.
		$this->assertSame( $first['delivery_id'], $second['delivery_id'] );
		$this->assertSame( $first['delivery_id'], $third['delivery_id'] );

		$this->assertSame( '2', (string) $this->delivery_column( $first['delivery_id'], 'suppressed_count' ) );
		$this->assertSame( $rows_after_first, $this->repo->count(), 'A duplicate claim inserted a second row.' );
	}

	/**
	 * The UNIQUE constraint genuinely rejects a duplicate at the database
	 * level — proven by a raw INSERT that bypasses the repository entirely.
	 *
	 * @return void
	 */
	public function test_unique_constraint_rejects_a_raw_duplicate_insert() {
		global $wpdb;

		$order_id = $this->fake_order_id();
		$identity = $this->unique_identity();

		$first = $this->repo->claim( $order_id, 13, 'insert', $identity );
		$this->track_delivery( $first['delivery_id'] );

		$suppressed = $wpdb->suppress_errors( true );
		$inserted   = $wpdb->insert(
			Migrator::table( 'deliveries' ),
			array(
				'identity_hash'    => $first['identity_hash'],
				'order_id'         => $order_id,
				'rule_id'          => 13,
				'mode'             => 'insert',
				'trigger_identity' => $identity,
				'first_claimed_at' => current_time( 'mysql', true ),
				'last_seen_at'     => current_time( 'mysql', true ),
				'final_status'     => 'claimed',
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( $suppressed );

		$this->assertFalse( $inserted, 'The UNIQUE constraint did not reject a duplicate identity_hash.' );
	}

	/**
	 * Distinct identities do not collide: a different rule, mode, order or
	 * trigger each yields its own tombstone.
	 *
	 * @return void
	 */
	public function test_distinct_identities_each_claim_separately() {
		$order_id = $this->fake_order_id();
		$identity = $this->unique_identity();

		$a = $this->repo->claim( $order_id, 20, 'insert', $identity );
		$b = $this->repo->claim( $order_id, 21, 'insert', $identity );
		$c = $this->repo->claim( $order_id, 20, 'separate', $identity );
		$d = $this->repo->claim( $order_id, 20, 'insert', $this->unique_identity( 'transition' ) );

		foreach ( array( $a, $b, $c, $d ) as $claim ) {
			$this->track_delivery( $claim['delivery_id'] );
			$this->assertSame( DeliveryRepository::CLAIMED, $claim['result'] );
		}

		$ids = array( $a['delivery_id'], $b['delivery_id'], $c['delivery_id'], $d['delivery_id'] );
		$this->assertCount( 4, array_unique( $ids ) );
	}

	/**
	 * A FORCED QUERY FAILURE returns FAILED and writes nothing. This is the
	 * fail-closed branch: the caller must not send, and no phantom row may be
	 * left behind in the real tombstone table.
	 *
	 * @return void
	 */
	public function test_forced_query_failure_returns_failed_and_writes_nothing() {
		global $wpdb;

		$order_id = $this->fake_order_id();
		$identity = $this->unique_identity();

		$real_rows_before = $this->repo->count();

		$broken     = new BrokenDeliveryRepository();
		$suppressed = $wpdb->suppress_errors( true );
		$result     = $broken->claim( $order_id, 14, 'insert', $identity );
		$wpdb->suppress_errors( $suppressed );

		$this->assertSame( DeliveryRepository::FAILED, $result['result'] );
		$this->assertSame( 0, $result['delivery_id'] );

		// The identity hash is still returned, so the caller can log the
		// attempt it refused to send.
		$this->assertSame(
			DeliveryIdentity::hash( $order_id, 14, 'insert', $identity ),
			$result['identity_hash']
		);

		$this->assertSame( $real_rows_before, $this->repo->count(), 'A failed claim wrote a phantom row.' );
		$this->assertNull( $this->repo->find_by_hash( $result['identity_hash'] ) );
	}

	/**
	 * The failure branch is reached without inspecting error text — the only
	 * signal used is the affected-row count. Asserted structurally: a claim
	 * against a broken table fails even while $wpdb->last_error is empty
	 * because errors are suppressed.
	 *
	 * @return void
	 */
	public function test_failure_does_not_depend_on_error_text() {
		global $wpdb;

		$broken           = new BrokenDeliveryRepository();
		$suppressed       = $wpdb->suppress_errors( true );
		$wpdb->last_error = '';
		$result           = $broken->claim( $this->fake_order_id(), 15, 'insert', $this->unique_identity() );
		$wpdb->suppress_errors( $suppressed );

		$this->assertSame( DeliveryRepository::FAILED, $result['result'] );
	}

	/**
	 * find() locates a tombstone by its identity components, and the recorded
	 * columns are normalised exactly as the hash normalises them.
	 *
	 * @return void
	 */
	public function test_find_by_identity_components() {
		$order_id = $this->fake_order_id();
		$identity = $this->unique_identity();

		$claim = $this->repo->claim( $order_id, 16, 'INSERT', strtoupper( $identity ) );
		$this->track_delivery( $claim['delivery_id'] );

		// Normalisation means the lower-case form finds the same row.
		$found = $this->repo->find( $order_id, 16, 'insert', $identity );

		$this->assertNotNull( $found );
		$this->assertSame( $claim['delivery_id'], (int) $found['id'] );
		$this->assertSame( 'insert', $found['mode'] );
	}

	/**
	 * The terminal outcome is recorded, and rule_revision_sent is stored for
	 * audit — never as part of the key.
	 *
	 * @return void
	 */
	public function test_final_status_and_revision_are_recorded() {
		$claim = $this->repo->claim( $this->fake_order_id(), 17, 'separate', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );

		$this->assertTrue( $this->repo->set_final_status( $claim['delivery_id'], 'sent', 7 ) );
		$this->assertSame( 'sent', (string) $this->delivery_column( $claim['delivery_id'], 'final_status' ) );
		$this->assertSame( '7', (string) $this->delivery_column( $claim['delivery_id'], 'rule_revision_sent' ) );
	}

	/**
	 * A rule revision change does NOT create a new identity: re-claiming the
	 * same tuple at a newer revision is still suppressed. Editing a rule must
	 * never re-arm an order that already received the email.
	 *
	 * @return void
	 */
	public function test_rule_revision_is_not_part_of_the_identity() {
		$order_id = $this->fake_order_id();
		$identity = $this->unique_identity();

		$first = $this->repo->claim( $order_id, 18, 'insert', $identity, 1 );
		$this->track_delivery( $first['delivery_id'] );

		$after_edit = $this->repo->claim( $order_id, 18, 'insert', $identity, 99 );

		$this->assertSame( DeliveryRepository::SUPPRESSED, $after_edit['result'] );
		$this->assertSame( $first['delivery_id'], $after_edit['delivery_id'] );
	}
}
