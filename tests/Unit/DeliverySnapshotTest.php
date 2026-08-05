<?php
/**
 * The delayed-delivery snapshot, framework-free (ADR-0015 §2a, gate 20).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Extonify\WCEP\Domain\DeliverySnapshot;

/**
 * THE SNAPSHOT IS A RULE STATE, AND ITS ALLOWLIST IS THE MECHANISM THAT KEEPS IT
 * ONE.
 *
 * It lives on the DURABLE tombstone — never purged, never erased — which is only
 * defensible because it holds no rendered personal data. That invariant is worth
 * exactly as much as the code enforcing it, so the enforcement is what these
 * tests exercise: an allowlist applied on write AND on read, and a version that
 * refuses a shape it does not understand rather than half-reading it.
 */
final class DeliverySnapshotTest extends UnitTestCase {

	/**
	 * A rule row as the matcher would hand it over.
	 *
	 * @param array $overrides Fields to replace.
	 * @return array
	 */
	private function rule( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'            => 7,
				'revision'      => 3,
				'subject'       => 'Hello {customer_first_name}',
				'heading'       => 'Your order',
				'content'       => '<p>Body for {customer_first_name}.</p>',
				'recipients'    => array( 'to' => array( 'customer' ) ),
				'delivery_mode' => 'separate',
				'delay_seconds' => 3600,
			),
			$overrides
		);
	}

	/**
	 * Matched item records as a decision carries them.
	 *
	 * @return array[]
	 */
	private function matched(): array {
		return array(
			array(
				'item_id'      => 11,
				'product_id'   => 21,
				'variation_id' => 0,
			),
			array(
				'item_id'      => 12,
				'product_id'   => 22,
				'variation_id' => 33,
			),
		);
	}

	/**
	 * A snapshot round-trips through the column unchanged.
	 *
	 * @return void
	 */
	public function test_a_snapshot_round_trips() {
		$snapshot = DeliverySnapshot::create( $this->rule(), $this->matched(), 'status:processing', 1700000000 );
		$read     = DeliverySnapshot::read( DeliverySnapshot::encode( $snapshot ) );

		$this->assertNotNull( $read );
		$this->assertSame( 3, $read['revision'] );
		$this->assertSame( 'Hello {customer_first_name}', $read['subject'] );
		$this->assertSame( '<p>Body for {customer_first_name}.</p>', $read['content'] );
		$this->assertSame( array( 'to' => array( 'customer' ) ), $read['recipients'] );
		$this->assertSame( array( 11, 12 ), $read['matched_items'] );
		$this->assertSame( 'separate', $read['mode'] );
		$this->assertSame( 'status:processing', $read['trigger_identity'] );
		$this->assertSame( 3600, $read['delay_seconds'] );
		$this->assertSame( 1700000000, $read['scheduled_for'] );
	}

	/**
	 * ⚠ THE TEMPLATES ARE STORED UNRENDERED. The whole reason the snapshot may
	 * live on a never-erased row.
	 *
	 * @return void
	 */
	public function test_templates_are_stored_unrendered() {
		$encoded = DeliverySnapshot::encode(
			DeliverySnapshot::create( $this->rule(), $this->matched(), 'status:processing', 1700000000 )
		);

		$this->assertStringContainsString( '{customer_first_name}', $encoded, 'the template was resolved before storage' );
	}

	/**
	 * ⚠ AN EXTRA FIELD CANNOT BE SMUGGLED IN. The allowlist is applied on write,
	 * so a caller adding a resolved address to the array it passes does not put
	 * one in the column.
	 *
	 * @return void
	 */
	public function test_the_allowlist_is_applied_on_write() {
		$snapshot                      = DeliverySnapshot::create( $this->rule(), $this->matched(), 'status:processing', 1700000000 );
		$snapshot['resolved_recipient'] = 'ada@example.test';
		$snapshot['customer_name']      = 'Ada Lovelace';

		$encoded = DeliverySnapshot::encode( $snapshot );

		$this->assertStringNotContainsString( 'ada@example.test', $encoded, '⚠ a resolved address reached the durable snapshot' );
		$this->assertStringNotContainsString( 'Ada Lovelace', $encoded );
		$this->assertStringNotContainsString( 'resolved_recipient', $encoded );
	}

	/**
	 * And on READ: a stored row carrying an extra key does not hand it back.
	 *
	 * @return void
	 */
	public function test_the_allowlist_is_applied_on_read() {
		$stored = DeliverySnapshot::create( $this->rule(), $this->matched(), 'status:processing', 1700000000 );

		$stored['smuggled'] = 'ada@example.test';

		$read = DeliverySnapshot::read( $stored );

		$this->assertNotNull( $read );
		$this->assertArrayNotHasKey( 'smuggled', $read );
		$this->assertSame( array(), DeliverySnapshot::unexpected_fields( $read ) );
	}

	/**
	 * `unexpected_fields()` NAMES an out-of-allowlist key, so the guard can fail
	 * loudly rather than silently dropping it.
	 *
	 * @return void
	 */
	public function test_unexpected_fields_reports_what_it_found() {
		$this->assertSame(
			array( 'customer_email' ),
			DeliverySnapshot::unexpected_fields( array( 'version' => 1, 'customer_email' => 'x@y.test' ) )
		);
	}

	/**
	 * ⚠ AN UNREADABLE SNAPSHOT IS NULL, NOT A PARTIAL ONE — fail closed. A
	 * delivery whose content cannot be read must not send a message assembled
	 * from defaults.
	 *
	 * @dataProvider unreadable_provider
	 *
	 * @param mixed  $stored Stored value.
	 * @param string $why    What the case proves.
	 * @return void
	 */
	public function test_an_unreadable_snapshot_reads_as_null( $stored, string $why ) {
		$this->assertNull( DeliverySnapshot::read( $stored ), $why );
	}

	/**
	 * Every shape a stored snapshot can be broken in.
	 *
	 * @return array<string,array{0:mixed,1:string}>
	 */
	public function unreadable_provider(): array {
		return array(
			'empty string'   => array( '', 'an empty column' ),
			'null'           => array( null, 'a NULL column — a released snapshot' ),
			'not json'       => array( 'not json at all', 'corrupt text' ),
			'json scalar'    => array( '"a string"', 'valid JSON that is not an object' ),
			'empty object'   => array( '{}', 'an object with no version' ),
			'no version key' => array( '{"subject":"x"}', 'a shape with content but no version' ),
			'future version' => array( '{"version":99,"subject":"x"}', '⚠ a shape written by another version of this plugin' ),
		);
	}

	/**
	 * The rule-row form carries the SNAPSHOT'S content and the LIVE rule's id —
	 * content from here, permission from the re-fetch (ADR-0015 §3).
	 *
	 * @return void
	 */
	public function test_the_rule_row_form_carries_snapshot_content_and_a_live_id() {
		$snapshot = DeliverySnapshot::read(
			DeliverySnapshot::encode( DeliverySnapshot::create( $this->rule(), $this->matched(), 'status:processing', 1700000000 ) )
		);

		$row = DeliverySnapshot::as_rule_row( $snapshot, 99 );

		$this->assertSame( 99, $row['id'], 'the rule id came from the snapshot rather than the live rule' );
		$this->assertSame( 'Hello {customer_first_name}', $row['subject'] );
		$this->assertSame( 3, $row['revision'] );
		$this->assertSame( array( 'to' => array( 'customer' ) ), $row['recipients'] );
	}

	/**
	 * Duplicate and junk item ids are normalised away, so the refund check walks
	 * a clean list.
	 *
	 * @return void
	 */
	public function test_item_ids_are_normalised() {
		$snapshot = DeliverySnapshot::create(
			$this->rule(),
			array(
				array( 'item_id' => 11 ),
				array( 'item_id' => 11 ),
				array( 'item_id' => 0 ),
				array( 'item_id' => -4 ),
				array( 'product_id' => 5 ),
				array( 'item_id' => '12' ),
			),
			'status:processing',
			1700000000
		);

		$this->assertSame( array( 11, 12 ), $snapshot['matched_items'] );
	}

	/**
	 * A rule with no recipients document still snapshots cleanly.
	 *
	 * @return void
	 */
	public function test_a_missing_recipients_document_is_an_empty_array() {
		$snapshot = DeliverySnapshot::create( $this->rule( array( 'recipients' => null ) ), array(), 'status:processing', 0 );

		$this->assertSame( array(), $snapshot['recipients'] );
	}
}
