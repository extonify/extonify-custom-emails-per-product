<?php
/**
 * Privacy completeness through the order linkage (Prompt 2b Item 3).
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
 * A detail row may legitimately have a NULL `recipient` — a scheduled, skipped
 * or cancelled attempt never resolved one — while still holding the customer's
 * address in `failure_message`, `reason`, `subject` or `snapshot`.
 *
 * Such a row is INVISIBLE to `WHERE recipient = %s`, so a personal-data request
 * would silently under-report and under-erase. These tests prove the order-path
 * union reaches it.
 */
final class PrivacyOrderLinkageTest extends IntegrationTestCase {

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
	 * Create a real order for an address, plus a tombstone against it.
	 *
	 * @param string $email Billing email.
	 * @return array{order_id:int,delivery_id:int,hash:string}
	 */
	private function seed_order_with_delivery( string $email ): array {
		$product = $this->make_product( 'WCEP Privacy Linkage' );

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product ), 1 );
		$order->set_billing_email( $email );
		$order->calculate_totals();
		$order->save();
		$this->order_ids[] = (int) $order->get_id();

		$claim = $this->deliveries->claim( (int) $order->get_id(), 90, 'separate', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );

		return array(
			'order_id'    => (int) $order->get_id(),
			'delivery_id' => $claim['delivery_id'],
			'hash'        => $claim['identity_hash'],
		);
	}

	/**
	 * THE HEADLINE CASE. A row whose ONLY personal data is in
	 * `failure_message`, with `recipient` NULL, is found by the exporter and
	 * cleared by the eraser — through the order, not the recipient string.
	 *
	 * @return void
	 */
	public function test_row_with_null_recipient_is_found_and_erased_via_the_order() {
		$email = 'wcep-linkage-' . strtolower( wp_generate_password( 10, false, false ) ) . '@example.test';
		$seed  = $this->seed_order_with_delivery( $email );

		// A cancelled attempt: no recipient was ever resolved, but the SMTP
		// diagnostic captured the customer's address.
		$detail_id = $this->details->insert(
			$seed['delivery_id'],
			array(
				'state'           => 'cancelled',
				'failure_message' => "550 5.1.1 <{$email}>: Recipient address rejected",
			)
		);
		$this->track_detail( $detail_id );
		$this->assertGreaterThan( 0, $detail_id );

		// Precondition: the row really is invisible to the recipient lookup.
		$stored = $this->details->find_for_delivery( $seed['delivery_id'] )[0];
		$this->assertNull( $stored['recipient'], 'Fixture is wrong: the row should have no recipient.' );
		$this->assertSame( array(), $this->details->find_by_recipient( $email ), 'Fixture is wrong: a recipient lookup should not find this row.' );
		$this->assertStringContainsString( $email, (string) $stored['failure_message'] );

		// The order path finds it.
		$subject = new SubjectData();
		$this->assertContains( $seed['order_id'], $subject->order_ids( $email ) );
		$this->assertArrayHasKey( $detail_id, $subject->scoped_detail_ids( $email ), 'The order path did not reach the row.' );

		// EXPORT surfaces it.
		$export = ( new Exporter() )->export( $email );
		$blob   = wp_json_encode( $export['data'] );
		$this->assertStringContainsString( $email, (string) $blob, 'The export missed a row reachable only through the order.' );

		// ERASE clears it.
		$result = ( new Eraser() )->erase( $email );
		$this->assertTrue( $result['items_removed'] );

		$erased = $this->details->find_for_delivery( $seed['delivery_id'] )[0];
		$this->assertNull( $erased['failure_message'], 'The eraser missed a row reachable only through the order.' );
		$this->assertSame( 'cancelled', $erased['state'], 'The outcome field should survive erasure.' );

		// The tombstone survives, identity intact.
		$tombstone = $this->deliveries->find_by_hash( $seed['hash'] );
		$this->assertNotNull( $tombstone );
		$this->assertSame( $seed['hash'], $tombstone['identity_hash'] );
	}

	/**
	 * `items_retained` is TRUE when a tombstone is kept, and the message says
	 * what is kept and why.
	 *
	 * @return void
	 */
	public function test_items_retained_is_true_when_a_tombstone_remains() {
		$email = 'wcep-retained-' . strtolower( wp_generate_password( 10, false, false ) ) . '@example.test';
		$seed  = $this->seed_order_with_delivery( $email );

		$this->track_detail(
			$this->details->insert(
				$seed['delivery_id'],
				array(
					'state'     => 'sent',
					'recipient' => $email,
				)
			)
		);

		$result = ( new Eraser() )->erase( $email );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'], 'A tombstone was retained but items_retained was reported false.' );

		$messages = implode( ' ', $result['messages'] );
		$this->assertStringContainsString( 'no direct contact or message-content fields', $messages );
		$this->assertStringContainsString( 'duplicate', $messages );

		$this->assertNotNull( $this->deliveries->find_by_hash( $seed['hash'] ) );
	}

	/**
	 * An address with nothing stored reports neither removed nor retained.
	 *
	 * @return void
	 */
	public function test_unknown_address_reports_nothing_removed_or_retained() {
		$result = ( new Eraser() )->erase( 'wcep-nobody-' . strtolower( wp_generate_password( 10, false, false ) ) . '@example.test' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * The tombstone is exported as a delivery record — with its order, mode,
	 * trigger, outcome and date, and WITHOUT its internal identity hash.
	 *
	 * @return void
	 */
	public function test_tombstone_is_exported_without_its_hash() {
		$email = 'wcep-identity-' . strtolower( wp_generate_password( 10, false, false ) ) . '@example.test';
		$seed  = $this->seed_order_with_delivery( $email );

		$export = ( new Exporter() )->export( $email );
		$this->assertTrue( $export['done'] );

		$identity = null;
		foreach ( $export['data'] as $item ) {
			if ( 'extonify-wcep-delivery-identities' === $item['group_id'] ) {
				$identity = $item;
			}
		}
		$this->assertNotNull( $identity, 'The delivery identity was not exported.' );

		$values = wp_list_pluck( $identity['data'], 'value', 'name' );
		$this->assertSame( (string) $seed['order_id'], $values['Order'] );
		$this->assertSame( 'separate', $values['Delivery mode'] );
		$this->assertArrayHasKey( 'Triggered by', $values );
		$this->assertArrayHasKey( 'Outcome', $values );
		$this->assertArrayHasKey( 'First recorded (UTC)', $values );
		$this->assertStringContainsString( 'no direct contact or message-content fields', $values['Why this is kept'] );

		// The internal key must not leak into a subject-facing export.
		$blob = wp_json_encode( $export['data'] );
		$this->assertStringNotContainsString( $seed['hash'], (string) $blob, 'identity_hash leaked into the export.' );
		$this->assertStringNotContainsString( 'identity_hash', (string) $blob );
	}

	/**
	 * Erasure through the order path does not reach another customer's rows.
	 *
	 * @return void
	 */
	public function test_order_path_erasure_is_scoped_to_the_subject() {
		$suffix = strtolower( wp_generate_password( 10, false, false ) );
		$target = "wcep-scoped-a-{$suffix}@example.test";
		$other  = "wcep-scoped-b-{$suffix}@example.test";

		$a = $this->seed_order_with_delivery( $target );
		$b = $this->seed_order_with_delivery( $other );

		foreach ( array( $a['delivery_id'], $b['delivery_id'] ) as $delivery_id ) {
			$this->track_detail(
				$this->details->insert(
					$delivery_id,
					array(
						'state'   => 'failed',
						'reason'  => 'diagnostic marker',
						'subject' => 'shared subject',
					)
				)
			);
		}

		( new Eraser() )->erase( $target );

		$this->assertNull( $this->details->find_for_delivery( $a['delivery_id'] )[0]['reason'] );
		$this->assertSame(
			'diagnostic marker',
			$this->details->find_for_delivery( $b['delivery_id'] )[0]['reason'],
			'Erasure crossed into another customer\'s order.'
		);
	}

	/**
	 * The recipient path still works on its own, so the union has not
	 * regressed the fast, indexed common case.
	 *
	 * @return void
	 */
	public function test_recipient_path_still_finds_rows_without_an_order() {
		$email = 'wcep-direct-' . strtolower( wp_generate_password( 10, false, false ) ) . '@example.test';

		// A tombstone against an order that does not exist in WooCommerce, so
		// the order path contributes nothing and only the recipient match can.
		$claim = $this->deliveries->claim( $this->fake_order_id(), 91, 'insert', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );
		$this->track_detail(
			$this->details->insert(
				$claim['delivery_id'],
				array(
					'state'     => 'sent',
					'recipient' => $email,
					'subject'   => 'direct-path-marker',
				)
			)
		);

		$this->assertSame( array(), ( new SubjectData() )->order_ids( $email ), 'Fixture is wrong: there should be no matching order.' );

		$export = ( new Exporter() )->export( $email );
		$this->assertStringContainsString( 'direct-path-marker', (string) wp_json_encode( $export['data'] ) );

		$this->assertTrue( ( new Eraser() )->erase( $email )['items_removed'] );
	}
}
