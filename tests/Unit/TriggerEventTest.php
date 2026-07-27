<?php
/**
 * Trigger identity construction and status normalisation (ADR-0004, ADR-0011).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Extonify\WCEP\Domain\DeliveryIdentity;
use Extonify\WCEP\Domain\TriggerEvent;

/**
 * The identity string is the ADR-0004 uniqueness key's only variable part. An
 * identity a later call cannot reproduce silently defeats duplicate
 * prevention, so construction and normalisation are pinned here.
 */
final class TriggerEventTest extends UnitTestCase {

	/**
	 * `status:{S}`.
	 *
	 * @return void
	 */
	public function test_status_identity() {
		$event = TriggerEvent::status( 'completed' );

		$this->assertSame( 'status', $event->type() );
		$this->assertSame( 'completed', $event->value() );
		$this->assertSame( 'status:completed', $event->identity() );
		$this->assertSame( 'completed', $event->destination_status() );
		$this->assertSame( '', $event->from_status() );
		$this->assertSame( 0, $event->refund_id() );
		$this->assertTrue( $event->is_valid() );
		$this->assertTrue( $event->is_status_event() );
	}

	/**
	 * `transition:{A}>{B}`, and `trigger_value` is `A>B`.
	 *
	 * @return void
	 */
	public function test_transition_identity() {
		$event = TriggerEvent::transition( 'pending', 'processing' );

		$this->assertSame( 'transition', $event->type() );
		$this->assertSame( 'pending>processing', $event->value() );
		$this->assertSame( 'transition:pending>processing', $event->identity() );
		$this->assertSame( 'pending', $event->from_status() );
		$this->assertSame( 'processing', $event->destination_status() );
		$this->assertTrue( $event->is_valid() );
	}

	/**
	 * `refund:{refund_id}`, with an empty trigger value: ADR-0004 keys refunds
	 * by refund id and one family covers both full and partial refunds.
	 *
	 * @return void
	 */
	public function test_refund_identity() {
		$event = TriggerEvent::refund( 2191 );

		$this->assertSame( 'refund', $event->type() );
		$this->assertSame( '', $event->value() );
		$this->assertSame( 'refund:2191', $event->identity() );
		$this->assertSame( 2191, $event->refund_id() );
		$this->assertSame( '', $event->destination_status() );
		$this->assertFalse( $event->is_status_event() );
		$this->assertTrue( $event->is_valid() );
	}

	/**
	 * Two partial refunds produce two distinct identities; a re-fired event for
	 * the same refund reproduces the same one (ADR-0004).
	 *
	 * @return void
	 */
	public function test_refunds_are_distinguished_by_id() {
		$this->assertNotSame( TriggerEvent::refund( 11 )->identity(), TriggerEvent::refund( 12 )->identity() );
		$this->assertSame( TriggerEvent::refund( 11 )->identity(), TriggerEvent::refund( 11 )->identity() );
	}

	/**
	 * Status slugs normalise identically with and without the `wc-` prefix,
	 * and across case and stray whitespace.
	 *
	 * @dataProvider normalisation_provider
	 *
	 * @param string $raw      Raw status.
	 * @param string $expected Normalised slug.
	 * @return void
	 */
	public function test_status_normalisation( string $raw, string $expected ) {
		$this->assertSame( $expected, TriggerEvent::normalize_status( $raw ) );
		$this->assertSame( $expected, TriggerEvent::status( $raw )->destination_status() );
	}

	/**
	 * Every spelling that must land on the same slug.
	 *
	 * @return array
	 */
	public function normalisation_provider(): array {
		return array(
			'bare'                => array( 'completed', 'completed' ),
			'wc- prefixed'        => array( 'wc-completed', 'completed' ),
			'upper case'          => array( 'COMPLETED', 'completed' ),
			'prefixed upper case' => array( 'WC-Completed', 'completed' ),
			'padded'              => array( "  wc-completed \n", 'completed' ),
			'hyphenated slug'     => array( 'wc-on-hold', 'on-hold' ),
			'inner whitespace'    => array( "wc-on\thold", 'on hold' ),
			'empty'               => array( '', '' ),
			'prefix only'         => array( 'wc-', '' ),
		);
	}

	/**
	 * Only ONE `wc-` prefix is stripped. A status genuinely named `wc-thing`
	 * would otherwise be unreachable, and stripping repeatedly would make
	 * normalisation depend on how many times it was applied.
	 *
	 * @return void
	 */
	public function test_only_one_prefix_is_stripped() {
		$this->assertSame( 'wc-completed', TriggerEvent::normalize_status( 'wc-wc-completed' ) );
	}

	/**
	 * Normalisation is idempotent: normalising an already-normalised slug
	 * changes nothing, so it does not matter how often it is applied.
	 *
	 * @return void
	 */
	public function test_normalisation_is_idempotent() {
		foreach ( array( 'wc-completed', 'COMPLETED', 'on-hold', ' wc-Failed ' ) as $raw ) {
			$once = TriggerEvent::normalize_status( $raw );
			$this->assertSame( $once, TriggerEvent::normalize_status( $once ) );
		}
	}

	/**
	 * The prefix is stripped consistently on BOTH halves of a transition.
	 *
	 * @return void
	 */
	public function test_transition_normalises_both_halves() {
		$this->assertSame(
			'transition:pending>processing',
			TriggerEvent::transition( 'wc-pending', 'WC-Processing' )->identity()
		);
	}

	/**
	 * Every identity this class builds satisfies ADR-0004's grammar, as the
	 * tombstone's own validator reads it — the two cannot drift apart.
	 *
	 * @return void
	 */
	public function test_identities_satisfy_the_adr_0004_grammar() {
		$events = array(
			TriggerEvent::status( 'wc-completed' ),
			TriggerEvent::status( 'awaiting-shipment' ),
			TriggerEvent::transition( 'wc-pending', 'wc-on-hold' ),
			TriggerEvent::refund( 7 ),
		);

		foreach ( $events as $event ) {
			$this->assertTrue( $event->is_valid(), $event->identity() . ' failed TriggerEvent::is_valid()' );
			$this->assertTrue(
				DeliveryIdentity::is_valid_trigger_identity( $event->identity() ),
				$event->identity() . ' failed DeliveryIdentity::is_valid_trigger_identity()'
			);
		}
	}

	/**
	 * An identity that cannot be reproduced must never reach the tombstone.
	 *
	 * @dataProvider invalid_event_provider
	 *
	 * @param TriggerEvent $event Event under test.
	 * @return void
	 */
	public function test_unreproducible_identities_are_invalid( TriggerEvent $event ) {
		$this->assertFalse( $event->is_valid() );
		$this->assertFalse( $event->is_triggerable() );
	}

	/**
	 * Identities that must never reach the tombstone.
	 *
	 * @return array
	 */
	public function invalid_event_provider(): array {
		return array(
			'empty status'          => array( TriggerEvent::status( '' ) ),
			'prefix-only status'    => array( TriggerEvent::status( 'wc-' ) ),
			'empty transition from' => array( TriggerEvent::transition( '', 'processing' ) ),
			'empty transition to'   => array( TriggerEvent::transition( 'pending', '' ) ),
			'zero refund'           => array( TriggerEvent::refund( 0 ) ),
			'negative refund'       => array( TriggerEvent::refund( -3 ) ),
			'illegal characters'    => array( TriggerEvent::status( 'on/hold!' ) ),
			'over-long status'      => array( TriggerEvent::status( str_repeat( 'a', 200 ) ) ),
		);
	}

	/**
	 * This class is STRICTER than the raw ADR-0004 grammar, on purpose.
	 *
	 * The grammar exists to reject invented FORMATS, so it is permissive about
	 * the value part: `refund:0` and `transition:pending>` both satisfy it.
	 * They are still meaningless identities, and a tombstone written under one
	 * could never be matched again. This class knows what each type means, so
	 * it refuses them before they can be claimed.
	 *
	 * @return void
	 */
	public function test_trigger_event_is_stricter_than_the_raw_grammar() {
		$permitted_by_grammar = array(
			'refund:0'            => TriggerEvent::refund( 0 ),
			'transition:pending>' => TriggerEvent::transition( 'pending', '' ),
		);

		foreach ( $permitted_by_grammar as $identity => $event ) {
			$this->assertSame( $identity, $event->identity() );
			$this->assertTrue(
				DeliveryIdentity::is_valid_trigger_identity( $identity ),
				'The raw grammar was expected to permit ' . $identity
			);
			$this->assertFalse( $event->is_valid(), 'TriggerEvent should have refused ' . $identity );
		}
	}

	/**
	 * `checkout-draft` never triggers anything when it is the DESTINATION.
	 *
	 * @return void
	 */
	public function test_checkout_draft_destination_never_triggers() {
		$entering = TriggerEvent::status( 'checkout-draft' );
		$this->assertTrue( $entering->is_valid(), 'The identity is well formed; it is simply not a trigger.' );
		$this->assertTrue( $entering->is_draft_destination() );
		$this->assertFalse( $entering->is_triggerable() );

		$into_draft = TriggerEvent::transition( 'pending', 'wc-checkout-draft' );
		$this->assertTrue( $into_draft->is_draft_destination() );
		$this->assertFalse( $into_draft->is_triggerable() );
	}

	/**
	 * Leaving the draft state DOES trigger: the order has become real, which is
	 * a genuine lifecycle event and the only way to target it.
	 *
	 * @return void
	 */
	public function test_leaving_checkout_draft_does_trigger() {
		$event = TriggerEvent::transition( 'checkout-draft', 'pending' );

		$this->assertFalse( $event->is_draft_destination() );
		$this->assertTrue( $event->is_triggerable() );
		$this->assertSame( 'transition:checkout-draft>pending', $event->identity() );
	}

	/**
	 * The identity feeds the ADR-0009 hash unchanged, and the hash is stable
	 * across the case and prefix variants normalisation collapses.
	 *
	 * @return void
	 */
	public function test_identity_feeds_a_stable_delivery_hash() {
		$plain    = DeliveryIdentity::hash( 42, 7, 'separate', TriggerEvent::status( 'completed' )->identity() );
		$prefixed = DeliveryIdentity::hash( 42, 7, 'separate', TriggerEvent::status( 'WC-Completed' )->identity() );

		$this->assertSame( $plain, $prefixed );
	}

	// ---------------------------------------------------------------------
	// Status-slug validation (ADR-0011 §2).
	// ---------------------------------------------------------------------

	/**
	 * A status value is usable only when it normalises to a real slug.
	 *
	 * Storage refuses everything this rejects, because a `trigger_value` that no
	 * real event can produce is a rule that never fires and never says why.
	 *
	 * @dataProvider status_slug_provider
	 *
	 * @param string $status Raw status value.
	 * @param bool   $valid  Expected result.
	 * @return void
	 */
	public function test_is_valid_status_slug( string $status, bool $valid ) {
		$this->assertSame( $valid, TriggerEvent::is_valid_status_slug( $status ), var_export( $status, true ) );
	}

	/**
	 * Real statuses, the `wc-` and case variants normalisation collapses, and
	 * everything that is not a slug at all.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function status_slug_provider(): array {
		return array(
			'plain slug'          => array( 'completed', true ),
			'hyphenated'          => array( 'checkout-draft', true ),
			'underscored'         => array( 'awaiting_stock', true ),
			'digits'              => array( 'stage2', true ),
			'prefixed'            => array( 'wc-completed', true ),
			'uppercase prefixed'  => array( 'WC-COMPLETED', true ),
			'padded'              => array( '  completed  ', true ),
			'empty'               => array( '', false ),
			'whitespace only'     => array( '   ', false ),
			'tab and newline'     => array( "\t\n", false ),
			'prefix only'         => array( 'wc-', false ),
			'internal space'      => array( 'on hold', false ),
			'dot'                 => array( 'on.hold', false ),
			'slash'               => array( 'a/b', false ),
			'transition operator' => array( 'pending>processing', false ),
			'percent'             => array( '100%', false ),
			'sql-ish'             => array( "completed'; DROP", false ),
			'non-ascii'           => array( 'terminé', false ),
		);
	}

	/**
	 * The validator is STRICTER than the identity grammar, deliberately.
	 *
	 * `DeliveryIdentity::is_valid_trigger_identity()` permits spaces and dots in
	 * the value part because it exists to reject invented identity FORMATS. This
	 * one exists to reject values that are not order statuses, so it refuses
	 * both — and the divergence is asserted rather than assumed.
	 *
	 * @return void
	 */
	public function test_slug_validation_is_stricter_than_the_identity_grammar() {
		foreach ( array( 'on hold', 'on.hold' ) as $status ) {
			$this->assertTrue(
				DeliveryIdentity::is_valid_trigger_identity( 'status:' . $status ),
				'The identity grammar is expected to permit ' . $status . '.'
			);
			$this->assertFalse( TriggerEvent::is_valid_status_slug( $status ) );
		}
	}
}
