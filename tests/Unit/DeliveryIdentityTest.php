<?php
/**
 * Identity-hash determinism (ADR-0004, ADR-0009).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Extonify\WCEP\Domain\DeliveryIdentity;

/**
 * The tombstone's UNIQUE key is a stored hash, so an unstable hash silently
 * splits one delivery identity into two and re-sends an email the customer
 * already received. These tests exist to make that impossible to regress.
 */
final class DeliveryIdentityTest extends UnitTestCase {

	/**
	 * The hash is a 64-character lowercase sha256 hex digest.
	 *
	 * @return void
	 */
	public function test_hash_shape() {
		$hash = DeliveryIdentity::hash( 123, 4, 'insert', 'status:completed' );

		$this->assertSame( 64, strlen( $hash ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
	}

	/**
	 * The same tuple always produces the same digest.
	 *
	 * @return void
	 */
	public function test_hash_is_stable_for_the_same_tuple() {
		$a = DeliveryIdentity::hash( 123, 4, 'insert', 'status:completed' );
		$b = DeliveryIdentity::hash( 123, 4, 'insert', 'status:completed' );

		$this->assertSame( $a, $b );
	}

	/**
	 * Every component participates: changing any one changes the digest.
	 *
	 * @return void
	 */
	public function test_every_component_participates() {
		$base = DeliveryIdentity::hash( 123, 4, 'insert', 'status:completed' );

		$this->assertNotSame( $base, DeliveryIdentity::hash( 124, 4, 'insert', 'status:completed' ) );
		$this->assertNotSame( $base, DeliveryIdentity::hash( 123, 5, 'insert', 'status:completed' ) );
		$this->assertNotSame( $base, DeliveryIdentity::hash( 123, 4, 'separate', 'status:completed' ) );
		$this->assertNotSame( $base, DeliveryIdentity::hash( 123, 4, 'insert', 'status:processing' ) );
	}

	/**
	 * Components cannot be smuggled across the delimiter: an order id of 1 with
	 * rule 23 must not collide with order 12 and rule 3.
	 *
	 * @return void
	 */
	public function test_components_do_not_bleed_across_the_delimiter() {
		$this->assertNotSame(
			DeliveryIdentity::hash( 1, 23, 'insert', 'status:completed' ),
			DeliveryIdentity::hash( 12, 3, 'insert', 'status:completed' )
		);
	}

	/**
	 * Case, padding and internal whitespace are normalised away, so the same
	 * logical identity written differently still hashes identically.
	 *
	 * @return void
	 */
	public function test_normalisation_is_applied() {
		$canonical = DeliveryIdentity::hash( 7, 2, 'insert', 'status:completed' );

		$this->assertSame( $canonical, DeliveryIdentity::hash( 7, 2, 'INSERT', 'STATUS:COMPLETED' ) );
		$this->assertSame( $canonical, DeliveryIdentity::hash( 7, 2, '  insert  ', "\tstatus:completed\n" ) );
		$this->assertSame( $canonical, DeliveryIdentity::hash( 7, 2, 'Insert', 'Status:Completed' ) );
	}

	/**
	 * Internal whitespace runs collapse to a single space.
	 *
	 * @return void
	 */
	public function test_internal_whitespace_collapses() {
		$this->assertSame(
			DeliveryIdentity::hash( 7, 2, 'insert', 'transition:pending>on hold' ),
			DeliveryIdentity::hash( 7, 2, 'insert', "transition:pending>on \t  hold" )
		);
	}

	/**
	 * THE REGRESSION THIS CLASS EXISTS FOR.
	 *
	 * The sibling Extonify Address Book plugin case-folded with mb_strtolower(),
	 * so the digest depended on whether the mbstring extension was installed and
	 * on the active locale — the same address hashed differently across hosts.
	 *
	 * Turkish is the classic trap: in tr_TR, uppercase 'I' lowercases to the
	 * dotless 'ı' (U+0131), not to 'i'. A locale-sensitive fold would therefore
	 * produce a different digest under that locale. ASCII-range strtr() cannot.
	 *
	 * @return void
	 */
	public function test_hash_is_stable_across_locale_sensitive_inputs() {
		$expected = DeliveryIdentity::hash( 42, 9, 'insert', 'status:INVOICED' );

		$original = setlocale( LC_ALL, '0' );
		// Try the locales most likely to break a naive fold. If none are
		// installed on this box the assertion below still runs under the
		// default locale, and the non-ASCII case further down is unaffected
		// by locale availability.
		setlocale( LC_ALL, 'tr_TR.UTF-8', 'tr_TR', 'tr', 'C' );

		$under_locale = DeliveryIdentity::hash( 42, 9, 'insert', 'status:INVOICED' );

		setlocale( LC_ALL, (string) $original );

		$this->assertSame(
			$expected,
			$under_locale,
			'The identity hash changed under a different locale — case folding must be ASCII-range only.'
		);
	}

	/**
	 * Non-ASCII bytes pass through the fold unchanged, so the digest does not
	 * depend on the mbstring extension being present.
	 *
	 * @return void
	 */
	public function test_non_ascii_passes_through_unchanged() {
		// 'Ä' must NOT be folded to 'ä'; only A-Z are touched. If a
		// multibyte-aware fold ever crept in, these two would collide.
		$upper = DeliveryIdentity::hash( 5, 1, 'insert', 'status:PRÜFUNG' );
		$lower = DeliveryIdentity::hash( 5, 1, 'insert', 'status:prüfung' );

		$this->assertNotSame(
			$upper,
			$lower,
			'Non-ASCII characters were case-folded — that reintroduces the mbstring/locale dependency.'
		);
	}

	/**
	 * Invalid UTF-8 must not blow up the whitespace collapse (preg with /u
	 * returns null on invalid input, hence the byte-pattern fallback).
	 *
	 * @return void
	 */
	public function test_invalid_utf8_still_hashes() {
		$hash = DeliveryIdentity::hash( 5, 1, 'insert', "status:\xC3\x28broken" );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
	}

	/**
	 * The recorded algorithm version is what a future recompute migration keys
	 * off; changing the rules without bumping it would silently split
	 * identities.
	 *
	 * @return void
	 */
	public function test_hash_version_is_recorded() {
		$this->assertSame( 1, DeliveryIdentity::HASH_VERSION );
	}

	/**
	 * Mode validation accepts the two ADR-0005 modes and nothing else.
	 *
	 * @return void
	 */
	public function test_mode_validation() {
		$this->assertTrue( DeliveryIdentity::is_valid_mode( 'insert' ) );
		$this->assertTrue( DeliveryIdentity::is_valid_mode( 'SEPARATE' ) );
		$this->assertFalse( DeliveryIdentity::is_valid_mode( 'broadcast' ) );
		$this->assertFalse( DeliveryIdentity::is_valid_mode( '' ) );
	}

	/**
	 * The three ADR-0004 trigger-identity forms are accepted.
	 *
	 * @dataProvider valid_trigger_identity_provider
	 *
	 * @param string $identity Candidate identity.
	 * @return void
	 */
	public function test_valid_trigger_identities_are_accepted( string $identity ) {
		$this->assertTrue( DeliveryIdentity::is_valid_trigger_identity( $identity ) );
	}

	/**
	 * Identities the ADR defines.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function valid_trigger_identity_provider() {
		return array(
			'status'            => array( 'status:completed' ),
			'hyphenated status' => array( 'status:on-hold' ),
			'transition'        => array( 'transition:pending>processing' ),
			'hyphenated both'   => array( 'transition:on-hold>completed' ),
			'refund'            => array( 'refund:2191' ),
			'uppercase input'   => array( 'STATUS:COMPLETED' ),
			'padded input'      => array( '  status:completed  ' ),
		);
	}

	/**
	 * Anything else is rejected, so a tombstone can never be written under an
	 * identity no later call can reproduce.
	 *
	 * @dataProvider invalid_trigger_identity_provider
	 *
	 * @param string $identity Candidate identity.
	 * @return void
	 */
	public function test_invalid_trigger_identities_are_rejected( string $identity ) {
		$this->assertFalse( DeliveryIdentity::is_valid_trigger_identity( $identity ) );
	}

	/**
	 * Identities that must not be accepted.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function invalid_trigger_identity_provider() {
		return array(
			'empty'            => array( '' ),
			'whitespace only'  => array( "  \t " ),
			'no prefix'        => array( 'completed' ),
			'unknown prefix'   => array( 'whenever:completed' ),
			'empty value'      => array( 'status:' ),
			'value starts odd' => array( 'status:-completed' ),
			'over long'        => array( 'status:' . str_repeat( 'a', DeliveryIdentity::MAX_TRIGGER_IDENTITY_LENGTH ) ),
		);
	}
}
