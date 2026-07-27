<?php
/**
 * Fail-closed order cleanup (Prompt 2a Item 5).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Repository\DeliveryDetailRepository;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * A detail repository whose bulk delete always fails, so the parent
 * repository's abort path is exercised for real rather than mocked.
 */
final class FailingDetailRepository extends DeliveryDetailRepository {

	/**
	 * Always report failure, exactly as a real query error would.
	 *
	 * @param int[] $delivery_ids Tombstone ids.
	 * @return int|false
	 */
	public function delete_for_deliveries( array $delivery_ids ) {
		return false;
	}
}

/**
 * There is no real foreign key — ADR-0009 makes referential integrity
 * repository discipline. The original code discarded the child delete's result
 * and deleted the parents anyway, so a failed child delete orphaned detail rows
 * permanently, unreachable even by the privacy eraser.
 */
final class OrderCleanupTest extends IntegrationTestCase {

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
	 * Seed one tombstone with one detail row for an order.
	 *
	 * @param int $order_id Order id.
	 * @param int $rule_id  Rule id.
	 * @return array{delivery_id:int,detail_id:int}
	 */
	private function seed( int $order_id, int $rule_id ): array {
		$claim = $this->deliveries->claim( $order_id, $rule_id, 'separate', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );

		$detail_id = $this->details->insert(
			$claim['delivery_id'],
			array(
				'state'     => 'sent',
				'recipient' => 'wcep-cleanup-' . wp_generate_password( 8, false, false ) . '@example.test',
			)
		);
		$this->track_detail( $detail_id );

		return array(
			'delivery_id' => $claim['delivery_id'],
			'detail_id'   => $detail_id,
		);
	}

	/**
	 * A successful cleanup reports exactly what it removed.
	 *
	 * @return void
	 */
	public function test_successful_cleanup_returns_a_structured_result() {
		$order_id = $this->fake_order_id();
		$this->seed( $order_id, 70 );
		$this->seed( $order_id, 71 );

		$result = $this->deliveries->delete_for_order( $order_id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['tombstones_deleted'] );
		$this->assertSame( 2, $result['details_deleted'] );
		$this->assertSame( array(), $this->deliveries->find_for_order( $order_id ) );
	}

	/**
	 * An order with nothing to clean is a success, not a failure.
	 *
	 * @return void
	 */
	public function test_cleanup_of_an_untouched_order_is_a_success() {
		$result = $this->deliveries->delete_for_order( $this->fake_order_id() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['tombstones_deleted'] );
		$this->assertSame( 0, $result['details_deleted'] );
	}

	/**
	 * THE FAIL-CLOSED CASE. When the child delete fails, the tombstones MUST
	 * remain, the result MUST report failure, and no partial cleanup may be
	 * reported as success.
	 *
	 * @return void
	 */
	public function test_child_delete_failure_aborts_and_keeps_tombstones() {
		$order_id = $this->fake_order_id();
		$seeded   = $this->seed( $order_id, 72 );

		$result = $this->run_cleanup_with_failing_child( $order_id );

		$this->assertFalse( $result['success'], 'A failed child delete was reported as success.' );
		$this->assertSame( 0, $result['details_deleted'], 'A failed cleanup reported deleted details.' );
		$this->assertSame( 0, $result['tombstones_deleted'], 'A failed cleanup reported deleted tombstones.' );

		// The rows are all still there and still reachable.
		$this->assertCount( 1, $this->deliveries->find_for_order( $order_id ), 'The tombstone was deleted despite the child delete failing.' );
		$this->assertCount( 1, $this->details->find_for_delivery( $seeded['delivery_id'] ), 'The detail row was orphaned.' );
	}

	/**
	 * Run delete_for_order() with the failing child repository injected.
	 *
	 * DeliveryRepository exposes a protected details() seam for exactly this:
	 * the fail-closed branch cannot be proven without being able to make the
	 * CHILD delete fail.
	 *
	 * @param int $order_id Order id.
	 * @return array{success:bool,details_deleted:int,tombstones_deleted:int}
	 */
	private function run_cleanup_with_failing_child( int $order_id ): array {
		$failing = new FailingDetailRepository();

		$subclass = new class( $failing ) extends DeliveryRepository {

			/**
			 * Failing child repository.
			 *
			 * @var DeliveryDetailRepository
			 */
			private $child;

			/**
			 * Constructor.
			 *
			 * @param DeliveryDetailRepository $child Child repository.
			 */
			public function __construct( DeliveryDetailRepository $child ) {
				$this->child = $child;
			}

			/**
			 * Use the injected failing child.
			 *
			 * @return DeliveryDetailRepository
			 */
			protected function details(): DeliveryDetailRepository {
				return $this->child;
			}

			/**
			 * Silence the log in tests.
			 *
			 * @param string $message Error detail.
			 * @return void
			 */
			protected function log_error( string $message ): void {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- deliberate no-op override.
		};

		return $subclass->delete_for_order( $order_id );
	}

	/**
	 * Cleanup is scoped to one order: another order's rows survive.
	 *
	 * @return void
	 */
	public function test_cleanup_is_scoped_to_one_order() {
		$target   = $this->fake_order_id();
		$survivor = $this->fake_order_id() + 1;

		$this->seed( $target, 73 );
		$this->seed( $survivor, 73 );

		$result = $this->deliveries->delete_for_order( $target );

		$this->assertTrue( $result['success'] );
		$this->assertSame( array(), $this->deliveries->find_for_order( $target ) );
		$this->assertCount( 1, $this->deliveries->find_for_order( $survivor ) );
	}

	/**
	 * More tombstones than one chunk still clean up completely, so the
	 * chunking cannot drop the tail of the list.
	 *
	 * @return void
	 */
	public function test_cleanup_handles_more_rows_than_one_chunk() {
		$order_id = $this->fake_order_id();
		$count    = DeliveryRepository::DELETE_CHUNK_SIZE + 5;

		for ( $i = 0; $i < $count; $i++ ) {
			$claim = $this->deliveries->claim( $order_id, 74, 'separate', 'status:chunk-' . $i );
			$this->track_delivery( $claim['delivery_id'] );
		}

		$this->assertCount( $count, $this->deliveries->find_for_order( $order_id ) );

		$result = $this->deliveries->delete_for_order( $order_id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( $count, $result['tombstones_deleted'] );
		$this->assertSame( array(), $this->deliveries->find_for_order( $order_id ) );
	}
}
