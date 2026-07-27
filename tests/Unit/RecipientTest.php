<?php
/**
 * Recipient normalisation (Prompt 2a Item 2).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Extonify\WCEP\Domain\Recipient;

/**
 * The privacy exporter and eraser match on `recipient` with an equality
 * lookup, so anything that reaches the column in a non-canonical form becomes
 * invisible to a personal-data request. These tests pin that boundary.
 */
final class RecipientTest extends UnitTestCase {

	/**
	 * A bare address normalises to itself, lowercased.
	 *
	 * @return void
	 */
	public function test_bare_address_is_lowercased() {
		$this->assertSame( 'alice@example.test', Recipient::normalize( 'alice@example.test' ) );
		$this->assertSame( 'alice@example.test', Recipient::normalize( 'Alice@Example.TEST' ) );
		$this->assertSame( 'alice@example.test', Recipient::normalize( '  alice@example.test  ' ) );
	}

	/**
	 * A mixed-case address and its lowercase form normalise identically, which
	 * is what makes the eraser find a row written either way.
	 *
	 * @return void
	 */
	public function test_case_variants_collapse_to_one_value() {
		$forms = array( 'Alice@Example.test', 'ALICE@EXAMPLE.TEST', 'aLiCe@eXaMpLe.TeSt' );

		foreach ( $forms as $form ) {
			$this->assertSame( 'alice@example.test', Recipient::normalize( $form ), "Failed for: {$form}" );
		}
	}

	/**
	 * Header form is parsed: the bare address is extracted for storage.
	 *
	 * @dataProvider header_form_provider
	 *
	 * @param string $raw      Header-form input.
	 * @param string $expected Expected bare address.
	 * @return void
	 */
	public function test_header_form_is_normalised_not_stored_raw( string $raw, string $expected ) {
		$this->assertSame( $expected, Recipient::normalize( $raw ) );
	}

	/**
	 * Header-form values the delivery layer will realistically produce.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function header_form_provider() {
		return array(
			'display name'        => array( 'Alice Smith <alice@example.test>', 'alice@example.test' ),
			'quoted display name' => array( '"Smith, Alice" <alice@example.test>', 'alice@example.test' ),
			'no space'            => array( 'Alice<alice@example.test>', 'alice@example.test' ),
			'mixed case in name'  => array( 'ALICE <Alice@Example.TEST>', 'alice@example.test' ),
			'angle brackets only' => array( '<alice@example.test>', 'alice@example.test' ),
		);
	}

	/**
	 * A display name is detected so the original can be kept in the
	 * non-searchable header column.
	 *
	 * @return void
	 */
	public function test_display_name_is_detected() {
		$this->assertTrue( Recipient::has_display_name( 'Alice Smith <alice@example.test>' ) );
		$this->assertFalse( Recipient::has_display_name( 'alice@example.test' ) );
		$this->assertFalse( Recipient::has_display_name( '<alice@example.test>' ) );
	}

	/**
	 * Anything that is not exactly one storable address is REJECTED. Storing
	 * it raw would make the row unfindable by the eraser.
	 *
	 * @dataProvider unstorable_provider
	 *
	 * @param string $raw Unstorable input.
	 * @return void
	 */
	public function test_unstorable_values_are_rejected( string $raw ) {
		$this->assertNull( Recipient::normalize( $raw ) );
	}

	/**
	 * Values that must never reach the recipient column.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function unstorable_provider() {
		return array(
			'comma list'        => array( 'a@example.test, b@example.test' ),
			'semicolon list'    => array( 'a@example.test; b@example.test' ),
			'header-form list'  => array( 'Alice <a@example.test>, Bob <b@example.test>' ),
			'empty'             => array( '' ),
			'whitespace only'   => array( "  \t " ),
			'not an address'    => array( 'not-an-address' ),
			'stray open angle'  => array( 'alice@example.test>' ),
			'stray close angle' => array( '<alice@example.test' ),
			'no domain'         => array( 'alice@' ),
			'no local part'     => array( '@example.test' ),
		);
	}

	/**
	 * An address longer than the column is rejected rather than silently
	 * truncated by MySQL into something no lookup can reproduce.
	 *
	 * @return void
	 */
	public function test_over_long_address_is_rejected() {
		$long = str_repeat( 'a', Recipient::MAX_LENGTH ) . '@example.test';

		$this->assertGreaterThan( Recipient::MAX_LENGTH, strlen( $long ) );
		$this->assertNull( Recipient::normalize( $long ) );
	}

	/**
	 * Case folding is ASCII-range only, so the stored value cannot depend on
	 * the locale or on mbstring being installed — the same reasoning as
	 * DeliveryIdentity.
	 *
	 * @return void
	 */
	public function test_folding_is_locale_independent() {
		$original = setlocale( LC_ALL, '0' );
		setlocale( LC_ALL, 'tr_TR.UTF-8', 'tr_TR', 'tr', 'C' );

		$under_locale = Recipient::normalize( 'ALICE@EXAMPLE.TEST' );

		setlocale( LC_ALL, (string) $original );

		$this->assertSame(
			'alice@example.test',
			$under_locale,
			'Recipient folding changed under a different locale — it must be ASCII-range only.'
		);
	}

	/**
	 * The display name is extracted, and a clean header is recomposed from it
	 * plus the normalised address.
	 *
	 * Recomposition matters because sanitize_text_field() reads
	 * `<alice@example.test>` as an HTML tag and strips it — sanitising the raw
	 * header would silently lose the address.
	 *
	 * @return void
	 */
	public function test_display_name_extraction_and_header_recomposition() {
		$this->assertSame( 'Alice Smith', Recipient::display_name( 'Alice Smith <alice@example.test>' ) );
		$this->assertSame( '"Smith, Alice"', Recipient::display_name( '"Smith, Alice" <alice@example.test>' ) );
		$this->assertNull( Recipient::display_name( '<alice@example.test>' ) );
		$this->assertNull( Recipient::display_name( 'alice@example.test' ) );

		$this->assertSame(
			'Alice Smith <alice@example.test>',
			Recipient::compose_header( 'Alice Smith', 'alice@example.test' )
		);
	}

	/**
	 * Channels are constrained to the three the schema allows, defaulting to
	 * 'to' rather than writing an unrecognised value.
	 *
	 * @return void
	 */
	public function test_recipient_type_normalisation() {
		$this->assertSame( 'to', Recipient::normalize_type( 'to' ) );
		$this->assertSame( 'cc', Recipient::normalize_type( 'CC' ) );
		$this->assertSame( 'bcc', Recipient::normalize_type( ' Bcc ' ) );

		// Unspecified genuinely IS the direct recipient.
		$this->assertSame( 'to', Recipient::normalize_type( '' ) );
	}

	/**
	 * An unrecognised channel is REJECTED, not coerced to 'to'.
	 *
	 * Coercion was the previous behaviour and it is wrong: silently relabelling
	 * a BCC as a direct recipient corrupts the delivery audit, and — now that
	 * privacy scoping keys off recipient ownership — would misreport who a row
	 * belongs to in a subject access response.
	 *
	 * @dataProvider unknown_type_provider
	 *
	 * @param string $type Candidate channel.
	 * @return void
	 */
	public function test_unknown_recipient_type_is_rejected( string $type ) {
		$this->assertNull( Recipient::normalize_type( $type ) );
	}

	/**
	 * Channels the schema does not define.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function unknown_type_provider() {
		return array(
			'invented'  => array( 'archive' ),
			'near miss' => array( 'bbc' ),
			'plural'    => array( 'ccs' ),
			'numeric'   => array( '1' ),
		);
	}
}
