<?php
/**
 * Three-way privacy ownership scoping (Prompt 2c Item 1).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Privacy\Eraser;
use Extonify\WCEP\Privacy\Exporter;
use Extonify\WCEP\Privacy\SubjectData;
use Extonify\WCEP\Repository\DeliveryDetailRepository;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * CC and BCC recipients are locked v1.0 scope, so one delivery on one order
 * produces rows owned by DIFFERENT people.
 *
 * Before this pass the resolver returned every row on the subject's order with
 * no ownership filter, so exporting for the customer disclosed the manager's
 * and supplier's addresses — a breach in its own right — and erasing for the
 * customer destroyed those third parties' records.
 *
 * The fixture below is exactly that shape: one order owned by customer A, one
 * delivery, three rows — TO: A, CC: manager, BCC: supplier — plus an
 * unattributed row with a NULL recipient.
 */
final class PrivacyOwnershipTest extends IntegrationTestCase {

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
	 * Fixture addresses and row ids.
	 *
	 * @var array
	 */
	private $fixture = array();

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
	 * One order for customer A, one delivery, three addressed rows plus one
	 * unattributed row.
	 *
	 * @return array
	 */
	private function seed(): array {
		if ( ! empty( $this->fixture ) ) {
			return $this->fixture;
		}

		$suffix   = strtolower( wp_generate_password( 10, false, false ) );
		$customer = "wcep-a-{$suffix}@example.test";
		$manager  = "wcep-manager-{$suffix}@example.test";
		$supplier = "wcep-supplier-{$suffix}@example.test";

		$product = $this->make_product( 'WCEP Ownership Fixture' );
		$order   = wc_create_order();
		$order->add_product( wc_get_product( $product ), 1 );
		$order->set_billing_email( $customer );
		$order->calculate_totals();
		$order->save();
		$this->order_ids[] = (int) $order->get_id();

		$claim = $this->deliveries->claim( (int) $order->get_id(), 200, 'separate', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );

		$rows = array();
		foreach ( array(
			'to'  => $customer,
			'cc'  => $manager,
			'bcc' => $supplier,
		) as $type => $address ) {
			$id = $this->details->insert(
				$claim['delivery_id'],
				array(
					'state'           => 'failed',
					'attempt'         => 1,
					'recipient'       => "Someone <{$address}>",
					'recipient_type'  => $type,
					'subject'         => "Order update for {$customer}",
					'snapshot'        => array( 'customer' => $customer ),
					'reason'          => "delivery attempted for {$customer}",
					'failure_message' => "550 5.1.1 <{$address}>: rejected",
				)
			);
			$this->track_detail( $id );
			$this->assertGreaterThan( 0, $id );
			$rows[ $type ] = $id;
		}

		// An unattributed row: no recipient was ever resolved, but the content
		// fields carry the customer's address.
		$unattributed = $this->details->insert(
			$claim['delivery_id'],
			array(
				'state'           => 'cancelled',
				'failure_message' => "no recipient resolved for {$customer}",
			)
		);
		$this->track_detail( $unattributed );
		$rows['unattributed'] = $unattributed;

		$this->fixture = array(
			'customer'    => $customer,
			'manager'     => $manager,
			'supplier'    => $supplier,
			'delivery_id' => $claim['delivery_id'],
			'rows'        => $rows,
		);

		return $this->fixture;
	}

	/**
	 * Read one row by id.
	 *
	 * @param int $delivery_id Owning tombstone.
	 * @param int $row_id      Detail row id.
	 * @return array
	 */
	private function row( int $delivery_id, int $row_id ): array {
		foreach ( $this->details->find_for_delivery( $delivery_id ) as $row ) {
			if ( (int) $row['id'] === $row_id ) {
				return $row;
			}
		}
		$this->fail( "Row {$row_id} not found." );
	}

	/**
	 * Rows are classified OWN / UNATTRIBUTED / THIRD_PARTY correctly, and the
	 * comparison is case-insensitive in both directions.
	 *
	 * @return void
	 */
	public function test_rows_are_classified_by_ownership() {
		$f       = $this->seed();
		$subject = new SubjectData();

		$own          = $this->row( $f['delivery_id'], $f['rows']['to'] );
		$third        = $this->row( $f['delivery_id'], $f['rows']['cc'] );
		$unattributed = $this->row( $f['delivery_id'], $f['rows']['unattributed'] );

		$this->assertSame( SubjectData::OWN, $subject->classify( $own, $f['customer'] ) );
		$this->assertSame( SubjectData::THIRD_PARTY, $subject->classify( $third, $f['customer'] ) );
		$this->assertSame( SubjectData::UNATTRIBUTED, $subject->classify( $unattributed, $f['customer'] ) );

		// A mixed-case request still matches a lowercased stored address, and a
		// mixed-case stored value would still match a lowercase request.
		$this->assertSame( SubjectData::OWN, $subject->classify( $own, strtoupper( $f['customer'] ) ) );

		// And from the manager's point of view the roles invert.
		$this->assertSame( SubjectData::OWN, $subject->classify( $third, $f['manager'] ) );
		$this->assertSame( SubjectData::THIRD_PARTY, $subject->classify( $own, $f['manager'] ) );
	}

	/**
	 * EXPORT — the subject's own row appears in full.
	 *
	 * @return void
	 */
	public function test_export_includes_the_subjects_own_row_in_full() {
		$f      = $this->seed();
		$item   = $this->export_item_for( $f['customer'], $f['rows']['to'] );
		$values = wp_list_pluck( $item['data'], 'value', 'name' );

		$this->assertSame( $f['customer'], $values['Recipient'] );
		$this->assertSame( 'to', $values['Recipient type'] );
		$this->assertArrayHasKey( 'Recipient (as addressed)', $values );
		$this->assertArrayHasKey( 'Subject', $values );
		$this->assertArrayHasKey( 'Reason', $values );
		$this->assertArrayHasKey( 'Failure message', $values );
		$this->assertArrayHasKey( 'Delivery snapshot', $values );
	}

	/**
	 * EXPORT — THE CRITICAL ASSERTION. Nothing identifying the manager or the
	 * supplier appears ANYWHERE in the customer's export.
	 *
	 * @return void
	 */
	public function test_export_never_reveals_a_third_party() {
		$f = $this->seed();

		$blob = '';
		$page = 1;
		do {
			$export = ( new Exporter() )->export( $f['customer'], $page );
			$blob  .= (string) wp_json_encode( $export['data'] );
			++$page;
		} while ( empty( $export['done'] ) && $page < 20 );

		$this->assertStringNotContainsString( $f['manager'], $blob, 'The export disclosed a CC recipient address.' );
		$this->assertStringNotContainsString( $f['supplier'], $blob, 'The export disclosed a BCC recipient address.' );
		$this->assertStringNotContainsString( '"cc"', $blob, 'The export disclosed a third party recipient type.' );
		$this->assertStringNotContainsString( '"bcc"', $blob, 'The export disclosed a third party recipient type.' );

		// The subject's own data IS there — this is a scoping test, not a
		// test that the export returns nothing.
		$this->assertStringContainsString( $f['customer'], $blob );
	}

	/**
	 * EXPORT — a third-party row still contributes its CONTENT fields, because
	 * those may describe the subject.
	 *
	 * @return void
	 */
	public function test_export_includes_third_party_content_fields_only() {
		$f      = $this->seed();
		$item   = $this->export_item_for( $f['customer'], $f['rows']['cc'] );
		$values = wp_list_pluck( $item['data'], 'value', 'name' );

		// Contact fields omitted ENTIRELY, not blanked.
		$this->assertArrayNotHasKey( 'Recipient', $values );
		$this->assertArrayNotHasKey( 'Recipient type', $values );
		$this->assertArrayNotHasKey( 'Recipient (as addressed)', $values );

		// Content fields present.
		$this->assertStringContainsString( $f['customer'], $values['Subject'] );
		$this->assertStringContainsString( $f['customer'], $values['Reason'] );
		$this->assertArrayHasKey( 'Note', $values );
	}

	/**
	 * EXPORT — an unattributed row is exported in full.
	 *
	 * @return void
	 */
	public function test_export_includes_the_unattributed_row_in_full() {
		$f      = $this->seed();
		$item   = $this->export_item_for( $f['customer'], $f['rows']['unattributed'] );
		$values = wp_list_pluck( $item['data'], 'value', 'name' );

		$this->assertStringContainsString( $f['customer'], $values['Failure message'] );
	}

	/**
	 * ERASE — the subject's own row is fully cleared.
	 *
	 * @return void
	 */
	public function test_erase_clears_the_subjects_own_row() {
		$f = $this->seed();
		$this->erase_all( $f['customer'] );

		$own = $this->row( $f['delivery_id'], $f['rows']['to'] );
		foreach ( DeliveryDetailRepository::PERSONAL_FIELDS as $field ) {
			$value = 'snapshot' === $field ? $own[ $field ] : (string) $own[ $field ];
			$this->assertEmpty( $value, "The subject's own {$field} survived erasure." );
		}
		$this->assertSame( 'failed', $own['state'], 'A non-personal outcome field was destroyed.' );
	}

	/**
	 * ERASE — THE CRITICAL ASSERTION. The manager's and supplier's CONTACT
	 * fields survive an erasure requested by the customer.
	 *
	 * @return void
	 */
	public function test_erase_preserves_third_party_contact_fields() {
		$f = $this->seed();
		$this->erase_all( $f['customer'] );

		foreach ( array(
			'cc'  => $f['manager'],
			'bcc' => $f['supplier'],
		) as $type => $address ) {
			$row = $this->row( $f['delivery_id'], $f['rows'][ $type ] );

			$this->assertSame( $address, $row['recipient'], "A third party's recipient was erased by another person's request." );
			$this->assertStringContainsString( $address, (string) $row['recipient_header'], "A third party's header was erased." );
			$this->assertSame( $type, $row['recipient_type'], "A third party's recipient type was erased." );
		}
	}

	/**
	 * ERASE — the CONTENT fields of a third-party row ARE cleared, because they
	 * may describe the subject.
	 *
	 * @return void
	 */
	public function test_erase_clears_third_party_content_fields() {
		$f = $this->seed();
		$this->erase_all( $f['customer'] );

		foreach ( array( 'cc', 'bcc' ) as $type ) {
			$row = $this->row( $f['delivery_id'], $f['rows'][ $type ] );

			$this->assertNull( $row['subject'], "A third party row's subject survived." );
			$this->assertNull( $row['reason'], "A third party row's reason survived." );
			$this->assertNull( $row['failure_message'], "A third party row's failure message survived." );
			$this->assertSame( array(), $row['snapshot'], "A third party row's snapshot survived." );
		}
	}

	/**
	 * ERASE — an unattributed row is fully cleared.
	 *
	 * @return void
	 */
	public function test_erase_clears_the_unattributed_row() {
		$f = $this->seed();
		$this->erase_all( $f['customer'] );

		$row = $this->row( $f['delivery_id'], $f['rows']['unattributed'] );
		$this->assertNull( $row['failure_message'], 'The unattributed row was not erased.' );
	}

	/**
	 * The eraser tells the subject that other people's rows were left alone.
	 *
	 * @return void
	 */
	public function test_erase_explains_the_third_party_carve_out() {
		$f        = $this->seed();
		$messages = implode( ' ', $this->erase_all( $f['customer'] ) );

		$this->assertStringContainsString( 'addressed to other people', $messages );
	}

	/**
	 * Run the eraser to completion and collect its messages.
	 *
	 * @param string $email Subject address.
	 * @return string[]
	 */
	private function erase_all( string $email ): array {
		$messages = array();
		$page     = 1;
		do {
			$result   = ( new Eraser() )->erase( $email, $page );
			$messages = array_merge( $messages, (array) $result['messages'] );
			++$page;
		} while ( empty( $result['done'] ) && $page < 20 );

		return $messages;
	}

	/**
	 * Find the export item for one detail row, paging until found.
	 *
	 * @param string $email  Subject address.
	 * @param int    $row_id Detail row id.
	 * @return array
	 */
	private function export_item_for( string $email, int $row_id ): array {
		$wanted = 'extonify-wcep-delivery-' . $row_id;
		$page   = 1;
		do {
			$export = ( new Exporter() )->export( $email, $page );
			foreach ( $export['data'] as $item ) {
				if ( $wanted === $item['item_id'] ) {
					return $item;
				}
			}
			++$page;
		} while ( empty( $export['done'] ) && $page < 20 );

		$this->fail( "Export item {$wanted} not found." );
	}
}
