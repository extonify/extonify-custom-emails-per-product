<?php
/**
 * Retention purge, privacy erasure, and order-deletion cleanup.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Privacy\Eraser;
use Extonify\WCEP\Privacy\Exporter;
use Extonify\WCEP\Repository\DeliveryDetailRepository;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * The invariant under test throughout: personal data goes, tombstones stay.
 *
 * Purging or erasing a tombstone would silently re-arm an already-delivered
 * order, so every one of these paths is asserted to leave it untouched.
 */
final class RetentionAndPrivacyTest extends IntegrationTestCase {

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
	 * Create a tombstone plus one detail row.
	 *
	 * @param string $recipient  Recipient address.
	 * @param int    $age_days   How many days ago the detail row was created.
	 * @param bool   $is_debug   Whether the detail row is a debug row.
	 * @return array{delivery_id:int,detail_id:int}
	 */
	private function seed( string $recipient = 'wcep-privacy@example.test', int $age_days = 0, bool $is_debug = false ): array {
		global $wpdb;

		$claim = $this->deliveries->claim( $this->fake_order_id(), 30, 'separate', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );

		$detail_id = $this->details->insert(
			$claim['delivery_id'],
			array(
				'type'      => $is_debug ? 'debug' : 'auto',
				'state'     => 'sent',
				'recipient' => $recipient,
				'subject'   => 'Your product guide',
				'snapshot'  => array( 'rule' => 30 ),
				'is_debug'  => $is_debug,
			)
		);
		$this->track_detail( $detail_id );

		if ( $age_days > 0 ) {
			// Backdate directly: created_at is set by the repository to "now",
			// and the purge selects on age.
			$wpdb->update(
				Migrator::table( 'delivery_details' ),
				array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( $age_days * DAY_IN_SECONDS ) ) ),
				array( 'id' => $detail_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		return array(
			'delivery_id' => $claim['delivery_id'],
			'detail_id'   => $detail_id,
		);
	}

	/**
	 * Retention purge removes aged detail rows and leaves tombstones intact.
	 *
	 * @return void
	 */
	public function test_retention_purge_removes_details_and_keeps_tombstones() {
		$old    = $this->seed( 'wcep-old@example.test', 200 );
		$recent = $this->seed( 'wcep-recent@example.test', 1 );

		$tombstones_before = $this->deliveries->count();

		$removed = $this->details->purge_older_than( 90, 180, 14 );

		$this->assertGreaterThanOrEqual( 1, $removed );
		$this->assertSame( array(), $this->details->find_for_delivery( $old['delivery_id'] ), 'The aged detail row survived the purge.' );
		$this->assertCount( 1, $this->details->find_for_delivery( $recent['delivery_id'] ), 'The recent detail row was purged too early.' );

		$this->assertSame( $tombstones_before, $this->deliveries->count(), 'The retention purge deleted a tombstone.' );
		$this->assertNotNull( $this->deliveries->find_by_hash( (string) $this->delivery_column( $old['delivery_id'], 'identity_hash' ) ) );
	}

	/**
	 * Debug rows purge on their own, much shorter window, so a long normal
	 * retention cannot keep them around.
	 *
	 * @return void
	 */
	public function test_debug_rows_purge_on_their_own_window() {
		$debug  = $this->seed( 'wcep-debug@example.test', 30, true );
		$normal = $this->seed( 'wcep-normal@example.test', 30, false );

		$this->details->purge_older_than( 90, 180, 14 );

		$this->assertSame( array(), $this->details->find_for_delivery( $debug['delivery_id'] ), 'The aged debug row survived its 14-day window.' );
		$this->assertCount( 1, $this->details->find_for_delivery( $normal['delivery_id'] ), 'A non-debug row was purged by the debug window.' );
	}

	/**
	 * The privacy eraser nulls personal fields and leaves the tombstone alone.
	 *
	 * @return void
	 */
	public function test_privacy_eraser_nulls_personal_fields_and_keeps_tombstones() {
		$email = 'wcep-erase-' . strtolower( wp_generate_password( 8, false, false ) ) . '@example.test';
		$seed  = $this->seed( $email );

		$tombstones_before = $this->deliveries->count();
		$hash              = (string) $this->delivery_column( $seed['delivery_id'], 'identity_hash' );

		$result = ( new Eraser() )->erase( $email );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['done'] );

		$rows = $this->details->find_for_delivery( $seed['delivery_id'] );
		$this->assertCount( 1, $rows, 'Erasure deleted the attempt row instead of anonymising it.' );
		$this->assertNull( $rows[0]['recipient'] );
		$this->assertNull( $rows[0]['subject'] );
		$this->assertSame( array(), $rows[0]['snapshot'] );

		// The outcome fields, which hold no personal data, survive so the
		// attempt history stays
		// coherent.
		$this->assertSame( 'sent', $rows[0]['state'] );

		$this->assertSame( $tombstones_before, $this->deliveries->count(), 'Erasure deleted a tombstone.' );
		$this->assertNotNull( $this->deliveries->find_by_hash( $hash ), 'Erasure removed the delivery identity — this would re-arm the order.' );
	}

	/**
	 * Erasing an address with no rows reports nothing removed rather than
	 * failing.
	 *
	 * @return void
	 */
	public function test_eraser_is_a_no_op_for_an_unknown_address() {
		$result = ( new Eraser() )->erase( 'wcep-nobody-' . wp_generate_password( 8, false, false ) . '@example.test' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * The exporter returns this plugin's personal data for an address, and
	 * exports the tombstone as a delivery record without its internal hash.
	 *
	 * @return void
	 */
	public function test_privacy_exporter_returns_detail_rows() {
		$email = 'wcep-export-' . strtolower( wp_generate_password( 8, false, false ) ) . '@example.test';
		$this->seed( $email );

		$export = ( new Exporter() )->export( $email );

		$this->assertTrue( $export['done'] );

		// The attempt row, plus the retained delivery identity that Prompt 2c
		// Item 2 discovers through the direct recipient match.
		$groups = wp_list_pluck( $export['data'], 'group_id' );
		$this->assertContains( 'extonify-wcep-deliveries', $groups );
		$this->assertContains( 'extonify-wcep-delivery-identities', $groups );

		$attempt = null;
		foreach ( $export['data'] as $item ) {
			if ( 'extonify-wcep-deliveries' === $item['group_id'] ) {
				$attempt = $item;
			}
		}
		$this->assertNotNull( $attempt );

		$values = wp_list_pluck( $attempt['data'], 'value', 'name' );
		$this->assertContains( $email, $values );
		$this->assertContains( 'Your product guide', $values );
	}

	/**
	 * Exporting an unknown address returns an empty, completed page.
	 *
	 * @return void
	 */
	public function test_exporter_is_empty_for_an_unknown_address() {
		$export = ( new Exporter() )->export( 'wcep-nobody-' . wp_generate_password( 8, false, false ) . '@example.test' );

		$this->assertSame( array(), $export['data'] );
		$this->assertTrue( $export['done'] );
	}

	/**
	 * Both exporter and eraser register themselves with the WordPress privacy
	 * framework under this plugin's key.
	 *
	 * @return void
	 */
	public function test_privacy_callbacks_register() {
		$exporters = ( new Exporter() )->register( array() );
		$erasers   = ( new Eraser() )->register( array() );

		$this->assertArrayHasKey( 'extonify-wcep-deliveries', $exporters );
		$this->assertArrayHasKey( 'extonify-wcep-deliveries', $erasers );
		$this->assertTrue( is_callable( $exporters['extonify-wcep-deliveries']['callback'] ) );
		$this->assertTrue( is_callable( $erasers['extonify-wcep-deliveries']['callback'] ) );
	}

	/**
	 * Permanently deleting an order removes BOTH its tombstones and its
	 * details (ADR-0004: the tombstone's bound is the order lifetime), and
	 * leaves another order's rows alone.
	 *
	 * @return void
	 */
	public function test_order_deletion_removes_tombstone_and_details() {
		$product = $this->make_product( 'WCEP Deletion Fixture' );
		$order   = $this->make_order( $product );
		$other   = $this->make_order( $product );

		$claim = $this->deliveries->claim( (int) $order->get_id(), 31, 'insert', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );
		$this->track_detail(
			$this->details->insert(
				$claim['delivery_id'],
				array(
					'state'     => 'sent',
					'recipient' => 'wcep-deleted@example.test',
				)
			)
		);

		$survivor = $this->deliveries->claim( (int) $other->get_id(), 31, 'insert', $this->unique_identity() );
		$this->track_delivery( $survivor['delivery_id'] );

		$this->assertCount( 1, $this->deliveries->find_for_order( (int) $order->get_id() ) );
		$this->assertCount( 1, $this->details->find_for_delivery( $claim['delivery_id'] ) );

		$deleted = $this->deliveries->delete_for_order( (int) $order->get_id() );

		$this->assertTrue( $deleted['success'] );
		$this->assertSame( 1, $deleted['tombstones_deleted'] );
		$this->assertSame( 1, $deleted['details_deleted'] );
		$this->assertSame( array(), $this->deliveries->find_for_order( (int) $order->get_id() ) );
		$this->assertSame( array(), $this->details->find_for_delivery( $claim['delivery_id'] ), 'Detail rows were orphaned by the order deletion.' );

		// The other order is untouched.
		$this->assertCount( 1, $this->deliveries->find_for_order( (int) $other->get_id() ) );
	}

	/**
	 * The same cleanup runs from the real WooCommerce deletion hook, for both
	 * HPOS and legacy storage.
	 *
	 * @return void
	 */
	public function test_order_deletion_hook_triggers_cleanup() {
		$product  = $this->make_product( 'WCEP Hook Fixture' );
		$order    = $this->make_order( $product );
		$order_id = (int) $order->get_id();

		$claim = $this->deliveries->claim( $order_id, 32, 'separate', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );

		// Real deletion through WooCommerce CRUD — never direct SQL on posts.
		$order->delete( true );

		// The fixture is gone; stop the teardown from trying to delete it again.
		$this->order_ids = array_values( array_diff( $this->order_ids, array( $order_id ) ) );

		$this->assertSame( array(), $this->deliveries->find_for_order( $order_id ), 'woocommerce_delete_order did not clean up this plugin rows.' );
	}
}
