<?php
/**
 * STRICT VERSION-1 SNAPSHOT VALIDATION (Prompt 7A Tier 2).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Extonify\WCEP\Domain\DeliverySnapshot;

/**
 * A half-written snapshot is not half a message — it is a DIFFERENT message.
 *
 * ⚠ `read()` USED TO DEFAULT WHAT WAS MISSING. A `null` subject became `''`, an
 * absent `recipients` became `array()`, a garbled `matched_items` became no items
 * at all — and the delivery then rendered and sent an email the merchant never
 * authored, addressed to nobody, about nothing. Every field is now required,
 * typed and checked against its own invariant, and anything else returns `null`
 * so `ScheduledDelivery` cancels with `snapshot_unreadable`.
 *
 * A UNIT TEST, because `Domain\DeliverySnapshot` is framework-free by design and
 * this is the boundary between a rule as it was and a message a customer receives
 * hours later.
 */
final class DeliverySnapshotStrictTest extends UnitTestCase {

	/**
	 * A complete, valid v1 snapshot.
	 *
	 * @return array
	 */
	private function valid(): array {
		return array(
			'version'          => 1,
			'revision'         => 4,
			'subject'          => 'Care guide for {customer_first_name}',
			'heading'          => 'Your order',
			'content'          => '<p>Delayed block.</p>',
			'recipients'       => array( 'to' => array( 'customer' ) ),
			'matched_items'    => array( 11, 12 ),
			'mode'             => 'separate',
			'trigger_identity' => 'status:processing',
			'delay_seconds'    => 3600,
			'scheduled_for'    => 1893456000,
		);
	}

	/**
	 * The baseline: a complete document reads back unchanged.
	 *
	 * Without this, every assertion below could pass because `read()` rejects
	 * everything.
	 *
	 * @return void
	 */
	public function test_a_complete_v1_snapshot_reads() {
		$read = DeliverySnapshot::read( $this->valid() );

		$this->assertIsArray( $read );
		$this->assertSame( 'Care guide for {customer_first_name}', $read['subject'] );
		$this->assertSame( array( 11, 12 ), $read['matched_items'] );
		$this->assertSame( 3600, $read['delay_seconds'] );
	}

	/**
	 * It survives the real JSON round trip the column performs.
	 *
	 * ⚠ THE STRICTNESS MUST NOT REJECT THE PLUGIN'S OWN WRITES. Requiring `is_int`
	 * on a value that JSON hands back as a float or a string would refuse every
	 * genuine snapshot, which would be a worse defect than the one being fixed.
	 *
	 * @return void
	 */
	public function test_it_survives_the_json_round_trip() {
		$this->assertIsArray( DeliverySnapshot::read( DeliverySnapshot::encode( $this->valid() ) ) );
	}

	/**
	 * TEST 9 — EACH MISSING FIELD, INDIVIDUALLY, YIELDS null.
	 *
	 * @return void
	 */
	public function test_every_missing_field_is_refused() {
		foreach ( DeliverySnapshot::FIELDS as $field ) {
			$broken = $this->valid();
			unset( $broken[ $field ] );

			$this->assertNull(
				DeliverySnapshot::read( $broken ),
				"a snapshot missing `{$field}` was accepted and would have sent an email built from defaults"
			);
		}
	}

	/**
	 * A field present but explicitly null is refused too.
	 *
	 * A field written as null is a field written WRONG, not one that is absent,
	 * and defaulting either is the same defect.
	 *
	 * @return void
	 */
	public function test_every_null_field_is_refused() {
		foreach ( DeliverySnapshot::FIELDS as $field ) {
			$broken           = $this->valid();
			$broken[ $field ] = null;

			$this->assertNull( DeliverySnapshot::read( $broken ), "a snapshot with a null `{$field}` was accepted" );
		}
	}

	/**
	 * TEST 9 — EACH WRONGLY-TYPED OR INVARIANT-BREAKING FIELD, INDIVIDUALLY.
	 *
	 * @return void
	 */
	public function test_every_wrongly_typed_field_is_refused() {
		$cases = array(
			'version not an int'          => array( 'version', '1' ),
			'version from the future'     => array( 'version', 2 ),
			'revision as a string'        => array( 'revision', '4' ),
			'revision negative'           => array( 'revision', -1 ),
			'subject as an array'         => array( 'subject', array( 'x' ) ),
			'subject as an int'           => array( 'subject', 7 ),
			'heading as an int'           => array( 'heading', 7 ),
			'content as an array'         => array( 'content', array() ),
			'recipients as a string'      => array( 'recipients', 'customer' ),
			'matched_items as a string'   => array( 'matched_items', '11,12' ),
			'matched_items empty'         => array( 'matched_items', array() ),
			'matched_items with a zero'   => array( 'matched_items', array( 11, 0 ) ),
			'matched_items negative'      => array( 'matched_items', array( -11 ) ),
			'matched_items duplicated'    => array( 'matched_items', array( 11, 11 ) ),
			'matched_items as strings'    => array( 'matched_items', array( '11' ) ),
			'mode insert'                 => array( 'mode', 'insert' ),
			'mode empty'                  => array( 'mode', '' ),
			'mode unrecognised'           => array( 'mode', 'digest' ),
			'trigger identity empty'      => array( 'trigger_identity', '' ),
			'trigger identity bad prefix' => array( 'trigger_identity', 'invented:1' ),
			'delay as a string'           => array( 'delay_seconds', '3600' ),
			'delay zero'                  => array( 'delay_seconds', 0 ),
			'delay negative'              => array( 'delay_seconds', -1 ),
			'scheduled_for as a string'   => array( 'scheduled_for', '1893456000' ),
			'scheduled_for zero'          => array( 'scheduled_for', 0 ),
			'scheduled_for negative'      => array( 'scheduled_for', -5 ),
		);

		foreach ( $cases as $label => $case ) {
			list( $field, $value ) = $case;

			$broken           = $this->valid();
			$broken[ $field ] = $value;

			$this->assertNull( DeliverySnapshot::read( $broken ), "{$label}: the snapshot was accepted" );
		}

		fwrite( STDERR, "\n[7A test 9] strict v1 snapshot: " . count( $cases ) . " invalid shapes refused, all `snapshot_unreadable`\n" );
	}

	/**
	 * A snapshot cannot describe an insert-mode delivery.
	 *
	 * ADR-0013 §2 refuses `insert + delay > 0` at the repository boundary, so a
	 * snapshot claiming it describes a delivery this plugin cannot perform.
	 *
	 * @return void
	 */
	public function test_the_only_snapshot_mode_matches_the_separate_phase() {
		$this->assertSame(
			\Extonify\WCEP\Delivery\DeliveryLogger::MODE_SEPARATE,
			DeliverySnapshot::MODE,
			'the snapshot mode literal drifted from the delivery phase constant'
		);
	}

	/**
	 * Garbage that is not a v1 document at all.
	 *
	 * @return void
	 */
	public function test_non_documents_are_refused() {
		foreach ( array( '', '{', 'null', '[]', '{}', '"a string"', '{"version":1}' ) as $stored ) {
			$this->assertNull( DeliverySnapshot::read( $stored ), "`{$stored}` was accepted as a snapshot" );
		}
	}
}
