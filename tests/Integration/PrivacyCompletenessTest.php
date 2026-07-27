<?php
/**
 * Privacy export and erasure completeness (Prompt 2a Item 3).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Privacy\Eraser;
use Extonify\WCEP\Privacy\Exporter;
use Extonify\WCEP\Repository\DeliveryDetailRepository;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * `reason` and `failure_message` routinely carry the recipient address,
 * customer names, order values and raw SMTP responses. The first version of
 * the eraser left both behind, so an "erased" row still held the customer's
 * address twice over, and the exporter under-reported what the site holds.
 *
 * The test below plants the SAME address in every personal field and requires
 * it to be exported from all of them and erased from all of them.
 */
final class PrivacyCompletenessTest extends IntegrationTestCase {

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
	 * The customer's address planted in EVERY personal field is exported from
	 * every one of them, then erased from every one of them — while the
	 * tombstone survives with its identity intact.
	 *
	 * @return void
	 */
	public function test_address_in_every_personal_field_is_exported_then_erased() {
		$suffix  = strtolower( wp_generate_password( 10, false, false ) );
		$address = "wcep-everywhere-{$suffix}@example.test";

		$claim = $this->deliveries->claim( $this->fake_order_id(), 50, 'separate', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );
		$hash = $claim['identity_hash'];

		$detail_id = $this->details->insert(
			$claim['delivery_id'],
			array(
				'state'           => 'failed',
				// The address appears in all six personal columns.
				'recipient'       => "Alice Smith <{$address}>",
				'subject'         => "Your guide, {$address}",
				'snapshot'        => array( 'resolved_recipient' => $address ),
				'reason'          => "recipient rejected: {$address}",
				'failure_message' => "550 5.1.1 <{$address}>: Recipient address rejected",
			)
		);
		$this->track_detail( $detail_id );
		$this->assertGreaterThan( 0, $detail_id );

		// --- every personal column really holds the address on disk ----------
		$stored = $this->details->find_for_delivery( $claim['delivery_id'] )[0];
		$this->assertSame( $address, $stored['recipient'] );
		$this->assertStringContainsString( $address, (string) $stored['recipient_header'] );
		$this->assertStringContainsString( $address, (string) $stored['subject'] );
		$this->assertStringContainsString( $address, (string) $stored['reason'] );
		$this->assertStringContainsString( $address, (string) $stored['failure_message'] );
		$this->assertStringContainsString( $address, wp_json_encode( $stored['snapshot'] ) );

		// --- EXPORT surfaces it from every field -----------------------------
		$export = ( new Exporter() )->export( $address );

		// Two items now: the attempt row, plus the retained delivery identity
		// that Prompt 2c Item 2 discovers through the direct recipient match.
		$attempt = $this->attempt_item( $export );
		$this->assertNotNull( $attempt, 'The attempt row was not exported.' );

		$exported = wp_list_pluck( $attempt['data'], 'value', 'name' );
		$blob     = implode( "\n", $exported );

		foreach ( array( 'Recipient', 'Recipient (as addressed)', 'Subject', 'Reason', 'Failure message', 'Delivery snapshot' ) as $label ) {
			$this->assertArrayHasKey( $label, $exported, "The export omitted the {$label} field entirely." );
			$this->assertStringContainsString(
				$address,
				(string) $exported[ $label ],
				"The export did not surface the address held in {$label}."
			);
		}
		$this->assertSame( 6, substr_count( $blob, $address ), 'The export did not report every field holding the address.' );

		// --- ERASE removes it from every field -------------------------------
		$result = ( new Eraser() )->erase( $address );
		$this->assertTrue( $result['items_removed'] );

		$erased = $this->details->find_for_delivery( $claim['delivery_id'] )[0];

		foreach ( DeliveryDetailRepository::PERSONAL_FIELDS as $field ) {
			$value = 'snapshot' === $field ? wp_json_encode( $erased[ $field ] ) : (string) $erased[ $field ];
			$this->assertStringNotContainsString(
				$address,
				(string) $value,
				"The eraser left the address behind in {$field}."
			);
		}
		$this->assertNull( $erased['recipient'] );
		$this->assertNull( $erased['recipient_header'] );
		$this->assertNull( $erased['subject'] );
		$this->assertNull( $erased['reason'] );
		$this->assertNull( $erased['failure_message'] );
		$this->assertSame( array(), $erased['snapshot'] );

		// --- outcome fields, which hold no personal data, survive --------------
		$this->assertSame( 'failed', $erased['state'] );
		$this->assertSame( 'auto', $erased['type'] );

		// --- THE TOMBSTONE SURVIVES, identity intact --------------------------
		$tombstone = $this->deliveries->find_by_hash( $hash );
		$this->assertNotNull( $tombstone, 'Erasure destroyed the delivery identity — this would re-arm the order.' );
		$this->assertSame( $hash, $tombstone['identity_hash'] );
		$this->assertSame( 'claimed', $tombstone['final_status'] );
	}

	/**
	 * Every field the repository declares personal is one the exporter
	 * actually reports, so adding a personal column later cannot silently slip
	 * out of the export.
	 *
	 * @return void
	 */
	public function test_declared_personal_fields_are_all_covered_by_the_exporter() {
		$suffix  = strtolower( wp_generate_password( 10, false, false ) );
		$address = "wcep-cover-{$suffix}@example.test";

		$claim = $this->deliveries->claim( $this->fake_order_id(), 51, 'separate', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );

		$this->track_detail(
			$this->details->insert(
				$claim['delivery_id'],
				array(
					'state'           => 'failed',
					'recipient'       => "Someone <{$address}>",
					'subject'         => 'subject-marker',
					'snapshot'        => array( 'marker' => 'snapshot-marker' ),
					'reason'          => 'reason-marker',
					'failure_message' => 'failure-marker',
				)
			)
		);

		$export   = ( new Exporter() )->export( $address );
		$exported = implode( "\n", wp_list_pluck( $this->attempt_item( $export )['data'], 'value' ) );

		foreach ( array( 'subject-marker', 'snapshot-marker', 'reason-marker', 'failure-marker' ) as $marker ) {
			$this->assertStringContainsString( $marker, $exported, "The export omitted {$marker}." );
		}
		// recipient_type joined the personal set in Prompt 2c: it identifies
		// WHO a row was addressed to, so it is a contact field.
		$this->assertCount( 7, DeliveryDetailRepository::PERSONAL_FIELDS );
	}

	/**
	 * The attempt-row item from an export payload, or null.
	 *
	 * @param array $export Export result.
	 * @return array|null
	 */
	private function attempt_item( array $export ): ?array {
		foreach ( $export['data'] as $item ) {
			if ( 'extonify-wcep-deliveries' === $item['group_id'] ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Erasure is scoped to the address requested: another customer's rows are
	 * untouched.
	 *
	 * @return void
	 */
	public function test_erasure_does_not_touch_another_recipient() {
		$suffix = strtolower( wp_generate_password( 10, false, false ) );
		$target = "wcep-target-{$suffix}@example.test";
		$other  = "wcep-other-{$suffix}@example.test";

		$claim = $this->deliveries->claim( $this->fake_order_id(), 52, 'separate', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );

		foreach ( array( $target, $other ) as $address ) {
			$this->track_detail(
				$this->details->insert(
					$claim['delivery_id'],
					array(
						'state'     => 'sent',
						'recipient' => $address,
						'subject'   => 'shared subject',
					)
				)
			);
		}

		( new Eraser() )->erase( $target );

		$this->assertSame( array(), $this->details->find_by_recipient( $target ) );
		$this->assertCount( 1, $this->details->find_by_recipient( $other ), 'Erasure removed an unrelated recipient.' );
	}
}
