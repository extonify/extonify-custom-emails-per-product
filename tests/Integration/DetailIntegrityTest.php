<?php
/**
 * Detail-row referential and value integrity (Prompt 2b Item 4).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Repository\DeliveryDetailRepository;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * A detail repository that keeps the log quiet while rejection paths run.
 */
final class QuietDetailRepository extends DeliveryDetailRepository {

	/**
	 * Swallow the log write.
	 *
	 * @param string $message Error detail.
	 * @return void
	 */
	protected function log_error( string $message ): void {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- deliberate no-op override; the message is intentionally discarded in tests.
}

/**
 * There is no database foreign key — ADR-0009 makes referential integrity
 * repository discipline — so an unvalidated `delivery_id` writes an orphan row
 * that no cleanup path and no privacy path can ever reach.
 *
 * `state` matters just as much: the retention purge branches on
 * `state = 'failed'`, so a mistyped state would quietly hand a failed delivery
 * the 90-day window instead of 180. A weak value silently changes how long
 * customer data is kept.
 */
final class DetailIntegrityTest extends IntegrationTestCase {

	/**
	 * Repository under test.
	 *
	 * @var DeliveryDetailRepository
	 */
	private $details;

	/**
	 * Tombstone repository.
	 *
	 * @var DeliveryRepository
	 */
	private $deliveries;

	/**
	 * A real tombstone to attach rows to.
	 *
	 * @var int
	 */
	private $delivery_id = 0;

	/**
	 * Build repositories and one valid tombstone.
	 *
	 * @before
	 * @return void
	 */
	protected function set_up_fixture() {
		$this->details    = new QuietDetailRepository();
		$this->deliveries = new DeliveryRepository();

		$claim             = $this->deliveries->claim( $this->fake_order_id(), 100, 'separate', $this->unique_identity() );
		$this->delivery_id = $this->track_delivery( $claim['delivery_id'] );
	}

	/**
	 * A delivery_id of 0 is rejected and writes nothing.
	 *
	 * @return void
	 */
	public function test_zero_delivery_id_is_rejected() {
		$before = $this->details->count();

		$this->assertSame( 0, $this->details->insert( 0, array( 'state' => 'sent' ) ) );
		$this->assertSame( $before, $this->details->count(), 'A rejected row was still written.' );
	}

	/**
	 * A negative delivery_id is rejected.
	 *
	 * @return void
	 */
	public function test_negative_delivery_id_is_rejected() {
		$before = $this->details->count();

		$this->assertSame( 0, $this->details->insert( -7, array( 'state' => 'sent' ) ) );
		$this->assertSame( $before, $this->details->count() );
	}

	/**
	 * A delivery_id that does not correspond to a tombstone is rejected — this
	 * is the orphan the missing foreign key would otherwise allow.
	 *
	 * @return void
	 */
	public function test_nonexistent_delivery_id_is_rejected() {
		$before = $this->details->count();

		$this->assertSame( 0, $this->details->insert( 987654321, array( 'state' => 'sent' ) ) );
		$this->assertSame( $before, $this->details->count(), 'An orphan row was written for a delivery that does not exist.' );
	}

	/**
	 * A parent_attempt_id belonging to a DIFFERENT delivery is rejected: an
	 * attempt chain that crossed deliveries would make a resend look like part
	 * of another order's history.
	 *
	 * @return void
	 */
	public function test_parent_attempt_from_another_delivery_is_rejected() {
		$other = $this->deliveries->claim( $this->fake_order_id() + 1, 101, 'separate', $this->unique_identity() );
		$this->track_delivery( $other['delivery_id'] );

		$foreign_parent = $this->details->insert(
			$other['delivery_id'],
			array( 'state' => 'sent' )
		);
		$this->track_detail( $foreign_parent );
		$this->assertGreaterThan( 0, $foreign_parent );

		$before = $this->details->count();
		$id     = $this->details->insert(
			$this->delivery_id,
			array(
				'state'             => 'sent',
				'parent_attempt_id' => $foreign_parent,
			)
		);

		$this->assertSame( 0, $id, 'A cross-delivery attempt chain was accepted.' );
		$this->assertSame( $before, $this->details->count() );
	}

	/**
	 * A parent_attempt_id that does not exist at all is rejected.
	 *
	 * @return void
	 */
	public function test_nonexistent_parent_attempt_is_rejected() {
		$before = $this->details->count();

		$id = $this->details->insert(
			$this->delivery_id,
			array(
				'state'             => 'sent',
				'parent_attempt_id' => 987654321,
			)
		);

		$this->assertSame( 0, $id );
		$this->assertSame( $before, $this->details->count() );
	}

	/**
	 * A parent_attempt_id from the SAME delivery is accepted — resend chains
	 * are the reason the column exists.
	 *
	 * @return void
	 */
	public function test_parent_attempt_from_the_same_delivery_is_accepted() {
		$parent = $this->track_detail( $this->details->insert( $this->delivery_id, array( 'state' => 'sent' ) ) );
		$this->assertGreaterThan( 0, $parent );

		$child = $this->details->insert(
			$this->delivery_id,
			array(
				'state'             => 'sent',
				'type'              => 'resend',
				'attempt'           => 2,
				'parent_attempt_id' => $parent,
			)
		);
		$this->track_detail( $child );

		$this->assertGreaterThan( 0, $child, 'A legitimate resend chain was rejected.' );
	}

	/**
	 * An unknown state is REJECTED, not silently defaulted.
	 *
	 * The mistyped `faild` is the motivating case: defaulted to 'scheduled' it
	 * would receive the 90-day normal retention window rather than the 180-day
	 * failed one, quietly shortening how long a failure is kept.
	 *
	 * @dataProvider unknown_state_provider
	 *
	 * @param string $state Candidate state.
	 * @return void
	 */
	public function test_unknown_state_is_rejected( string $state ) {
		$before = $this->details->count();

		$this->assertSame( 0, $this->details->insert( $this->delivery_id, array( 'state' => $state ) ) );
		$this->assertSame( $before, $this->details->count() );
	}

	/**
	 * States nothing downstream could interpret.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function unknown_state_provider() {
		return array(
			'typo for failed' => array( 'faild' ),
			'invented'        => array( 'exploded' ),
			'near miss'       => array( 'sending' ),
			'empty'           => array( '' ),
		);
	}

	/**
	 * An unknown type is rejected.
	 *
	 * @return void
	 */
	public function test_unknown_type_is_rejected() {
		$before = $this->details->count();

		$this->assertSame(
			0,
			$this->details->insert(
				$this->delivery_id,
				array(
					'type'  => 'broadcast',
					'state' => 'sent',
				)
			)
		);
		$this->assertSame( $before, $this->details->count() );
	}

	/**
	 * Every allowlisted state and type is accepted.
	 *
	 * @return void
	 */
	public function test_all_allowlisted_states_and_types_are_accepted() {
		foreach ( DeliveryDetailRepository::STATES as $state ) {
			$id = $this->details->insert( $this->delivery_id, array( 'state' => $state ) );
			$this->track_detail( $id );
			$this->assertGreaterThan( 0, $id, "The allowlisted state {$state} was rejected." );
		}
		foreach ( DeliveryDetailRepository::TYPES as $type ) {
			$id = $this->details->insert(
				$this->delivery_id,
				array(
					'type'  => $type,
					'state' => 'sent',
				)
			);
			$this->track_detail( $id );
			$this->assertGreaterThan( 0, $id, "The allowlisted type {$type} was rejected." );
		}
	}

	/**
	 * A valid row is written and retrievable, with its state stored verbatim.
	 *
	 * @return void
	 */
	public function test_valid_row_is_written_and_retrievable() {
		$id = $this->details->insert(
			$this->delivery_id,
			array(
				'state'     => 'failed',
				'type'      => 'auto',
				'recipient' => 'wcep-valid-' . strtolower( wp_generate_password( 8, false, false ) ) . '@example.test',
			)
		);
		$this->track_detail( $id );

		$this->assertGreaterThan( 0, $id );

		$rows = $this->details->find_for_delivery( $this->delivery_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( $id, (int) $rows[0]['id'] );
		$this->assertSame( 'failed', $rows[0]['state'], 'The state was not stored verbatim — retention depends on it.' );
	}
}
