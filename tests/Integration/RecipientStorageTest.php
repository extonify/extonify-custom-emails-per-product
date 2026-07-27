<?php
/**
 * One detail row per resolved recipient (Prompt 2a Item 2).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Repository\DeliveryDetailRepository;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * The privacy exporter and eraser find rows with an equality lookup on
 * `recipient`. These tests prove the column can only ever hold something that
 * lookup can find.
 */
final class RecipientStorageTest extends IntegrationTestCase {

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
	 * Owning tombstone for this test.
	 *
	 * @var int
	 */
	private $delivery_id = 0;

	/**
	 * Build repositories and an owning tombstone.
	 *
	 * @before
	 * @return void
	 */
	protected function set_up_fixture() {
		$this->deliveries = new DeliveryRepository();
		$this->details    = new DeliveryDetailRepository();

		$claim             = $this->deliveries->claim( $this->fake_order_id(), 40, 'separate', $this->unique_identity() );
		$this->delivery_id = $this->track_delivery( $claim['delivery_id'] );
	}

	/**
	 * A customer plus one CC plus one BCC writes THREE findable rows sharing
	 * delivery_id and attempt.
	 *
	 * @return void
	 */
	public function test_three_recipients_produce_three_findable_rows() {
		$suffix = strtolower( wp_generate_password( 8, false, false ) );
		$to     = "wcep-to-{$suffix}@example.test";
		$cc     = "wcep-cc-{$suffix}@example.test";
		$bcc    = "wcep-bcc-{$suffix}@example.test";

		foreach ( array(
			'to'  => $to,
			'cc'  => $cc,
			'bcc' => $bcc,
		) as $type => $address ) {
			$this->track_detail(
				$this->details->insert(
					$this->delivery_id,
					array(
						'state'          => 'sent',
						'attempt'        => 1,
						'recipient'      => $address,
						'recipient_type' => $type,
						'subject'        => 'Care guide',
					)
				)
			);
		}

		$rows = $this->details->find_for_delivery( $this->delivery_id );
		$this->assertCount( 3, $rows );

		// All three share the same attempt.
		foreach ( $rows as $row ) {
			$this->assertSame( '1', (string) $row['attempt'] );
		}

		// And each is independently findable by the privacy lookup.
		$this->assertCount( 1, $this->details->find_by_recipient( $to ) );
		$this->assertCount( 1, $this->details->find_by_recipient( $cc ) );
		$this->assertCount( 1, $this->details->find_by_recipient( $bcc ) );

		$types = wp_list_pluck( $rows, 'recipient_type' );
		sort( $types );
		$this->assertSame( array( 'bcc', 'cc', 'to' ), $types );
	}

	/**
	 * A mixed-case address is found by a lowercase lookup, and vice versa.
	 * Without consistent normalisation a legally-required erasure would miss
	 * the row.
	 *
	 * @return void
	 */
	public function test_case_insensitive_round_trip() {
		$suffix = wp_generate_password( 8, false, false );
		$mixed  = "WCEP-Mixed-{$suffix}@Example.TEST";
		$lower  = strtolower( $mixed );

		$this->track_detail(
			$this->details->insert(
				$this->delivery_id,
				array(
					'state'     => 'sent',
					'recipient' => $mixed,
				)
			)
		);

		// Stored lowercased.
		$rows = $this->details->find_for_delivery( $this->delivery_id );
		$this->assertSame( $lower, $rows[0]['recipient'] );

		// Findable both ways.
		$this->assertCount( 1, $this->details->find_by_recipient( $lower ), 'A lowercase lookup missed a mixed-case write.' );
		$this->assertCount( 1, $this->details->find_by_recipient( $mixed ), 'A mixed-case lookup missed a lowercase row.' );
	}

	/**
	 * A header-form value is NORMALISED, not stored raw: the bare address goes
	 * to `recipient` and the original to the never-searched header column.
	 *
	 * @return void
	 */
	public function test_header_form_is_split_not_stored_raw() {
		$suffix  = strtolower( wp_generate_password( 8, false, false ) );
		$address = "wcep-header-{$suffix}@example.test";

		$this->track_detail(
			$this->details->insert(
				$this->delivery_id,
				array(
					'state'     => 'sent',
					'recipient' => "Alice Smith <{$address}>",
				)
			)
		);

		$rows = $this->details->find_for_delivery( $this->delivery_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( $address, $rows[0]['recipient'], 'The header form reached the searchable column.' );
		$this->assertSame( "Alice Smith <{$address}>", $rows[0]['recipient_header'] );

		// And the privacy lookup finds it by the bare address.
		$this->assertCount( 1, $this->details->find_by_recipient( $address ) );
	}

	/**
	 * A comma-joined list is REJECTED rather than stored raw — resolving one
	 * row per recipient is the delivery engine's job, and a list in this
	 * column would be invisible to the eraser.
	 *
	 * @dataProvider rejected_recipient_provider
	 *
	 * @param string $raw Unstorable recipient value.
	 * @return void
	 */
	public function test_unresolvable_recipients_are_rejected( string $raw ) {
		$before = $this->details->count();

		$id = $this->details->insert(
			$this->delivery_id,
			array(
				'state'     => 'sent',
				'recipient' => $raw,
			)
		);

		$this->assertSame( 0, $id, 'An unresolvable recipient was accepted.' );
		$this->assertSame( $before, $this->details->count(), 'A rejected recipient still wrote a row.' );
	}

	/**
	 * Values the storage layer must refuse.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function rejected_recipient_provider() {
		return array(
			'comma list'       => array( 'a@example.test, b@example.test' ),
			'semicolon list'   => array( 'a@example.test; b@example.test' ),
			'header-form list' => array( 'Alice <a@example.test>, Bob <b@example.test>' ),
			'not an address'   => array( 'not-an-address' ),
		);
	}

	/**
	 * A row with no recipient at all (a 'scheduled' or 'skipped' attempt) is
	 * still storable — rejection applies only to unresolvable values.
	 *
	 * @return void
	 */
	public function test_row_without_a_recipient_is_allowed() {
		$id = $this->details->insert(
			$this->delivery_id,
			array(
				'state'  => 'scheduled',
				'reason' => 'waiting for the scheduled window',
			)
		);
		$this->track_detail( $id );

		$this->assertGreaterThan( 0, $id );

		$rows = $this->details->find_for_delivery( $this->delivery_id );
		$this->assertNull( $rows[0]['recipient'] );
		$this->assertSame( 'to', $rows[0]['recipient_type'] );
	}

	/**
	 * An unrecognised channel is REJECTED, not silently relabelled 'to'.
	 *
	 * Prompt 2c Item 5: coercion contradicted the strict allowlists already
	 * applied to `type` and `state`, and mislabelling a BCC as a direct
	 * recipient corrupts both the delivery audit and the ownership decision the
	 * privacy layer now makes from this column.
	 *
	 * @return void
	 */
	public function test_unknown_recipient_type_is_rejected() {
		$before = $this->details->count();

		$id = $this->details->insert(
			$this->delivery_id,
			array(
				'state'          => 'sent',
				'recipient'      => 'wcep-type-' . strtolower( wp_generate_password( 8, false, false ) ) . '@example.test',
				'recipient_type' => 'archive',
			)
		);

		$this->assertSame( 0, $id, 'An unrecognised recipient_type was accepted.' );
		$this->assertSame( $before, $this->details->count(), 'A rejected row was still written.' );
	}

	/**
	 * A non-positive parent_attempt_id is stored as NULL, never as a fake
	 * parent id of 0 that validation would never inspect.
	 *
	 * @return void
	 */
	public function test_non_positive_parent_attempt_id_is_stored_as_null() {
		foreach ( array( 0, -5 ) as $value ) {
			$id = $this->details->insert(
				$this->delivery_id,
				array(
					'state'             => 'sent',
					'parent_attempt_id' => $value,
				)
			);
			$this->track_detail( $id );
			$this->assertGreaterThan( 0, $id, 'A row with no parent should still be storable.' );
		}

		foreach ( $this->details->find_for_delivery( $this->delivery_id ) as $row ) {
			$this->assertNull( $row['parent_attempt_id'], 'A non-positive parent id was stored instead of NULL.' );
		}
	}

	/**
	 * A lookup by an unstorable address returns nothing instead of running a
	 * query that could never match.
	 *
	 * @return void
	 */
	public function test_lookup_by_unstorable_address_returns_nothing() {
		$this->assertSame( array(), $this->details->find_by_recipient( 'a@example.test, b@example.test' ) );
		$this->assertSame( array(), $this->details->find_by_recipient( '' ) );
	}
}
