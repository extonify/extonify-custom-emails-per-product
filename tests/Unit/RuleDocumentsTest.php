<?php
/**
 * GATE 29 — the editor–validator contract, proved over the whole emitted space.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Extonify\WCEP\Admin\RuleDocuments;
use Extonify\WCEP\Domain\RecipientsDocument;
use Extonify\WCEP\Domain\Targeting;

/**
 * The editor cannot emit a document its own validator rejects (ADR-0017 §2).
 *
 * ⚠ THIS IS A UNIT TEST, AND THAT IS PART OF THE PROOF. `Admin\RuleDocuments` is
 * free of WordPress and free of `$_POST` — it is a pure function of its argument —
 * so the space of documents it can emit is a property of its code rather than of a
 * request. If it ever gained a WordPress dependency this suite would stop loading,
 * which `SuiteIsolationTest` turns into a failure.
 *
 * ⚠ AND IT IS EXHAUSTIVE OVER THE SHAPE-SPACE, NOT A SAMPLE. Gate 29 asks for a
 * proof "across the space of inputs the form can produce — not a sample", and the
 * shape-space is finite and small:
 *
 *   - targeting: each of 2 sides is any of the 2^5 subsets of `Targeting::KINDS`,
 *     and `match_all` is either boolean — 32 x 32 x 2 = **2048 documents**;
 *   - recipients: each of 3 channels present or absent — **8 documents**.
 *
 * Every one is encoded through the production writer and read back through the RAW
 * STRING path, which is the strict one: only that path can tell `{}` from `[]`, and
 * it is the path the storage column actually takes.
 *
 * The VALUE space is infinite and is handled by TYPE instead: `Targeting::int_list()`
 * and `Targeting::type_list()` — which the editor uses rather than imitates — are
 * driven over a hostile corpus and asserted to emit only constrained values, so a
 * structural enumeration over representative values completes the proof.
 */
final class RuleDocumentsTest extends UnitTestCase {

	/**
	 * Representative entries per kind. Each is a value the picker can genuinely
	 * produce: an id from a search result, a slug from a checkbox.
	 *
	 * @return array<string,array>
	 */
	private static function sample_entries(): array {
		return array(
			'variations' => array( 101, '102' ),
			'products'   => array( 7 ),
			'categories' => array( 3, 4, 5 ),
			'tags'       => array( '19' ),
			'types'      => array( 'simple', 'virtual' ),
		);
	}

	/**
	 * GATE 29a. EVERY TARGETING DOCUMENT THE FORM CAN EMIT IS ACCEPTED BY
	 *           `Targeting`'s RAW VALIDATOR — all 2048 of them.
	 *
	 * @return void
	 */
	public function test_every_emittable_targeting_document_is_valid() {
		$kinds   = Targeting::KINDS;
		$samples = self::sample_entries();
		$subsets = 1 << count( $kinds );

		$documents = 0;
		$shapes    = array();

		for ( $include = 0; $include < $subsets; $include++ ) {
			for ( $exclude = 0; $exclude < $subsets; $exclude++ ) {
				foreach ( array( false, true ) as $match_all ) {
					$input = array(
						'include'   => self::subset( $kinds, $samples, $include ),
						'exclude'   => self::subset( $kinds, $samples, $exclude ),
						'match_all' => $match_all,
					);

					$document = RuleDocuments::targeting( $input );
					$encoded  = Targeting::encode( $document );

					// ⚠ THE RAW STRING PATH, which is the strict one: it is the only
					// path that can tell `{}` from `[]`, and it is the path the stored
					// column actually takes.
					$parsed = Targeting::from_value( $encoded );

					$this->assertTrue(
						$parsed->is_valid(),
						'⚠ the editor emitted a document its own validator rejects: ' . $encoded
					);

					// The document must also MEAN what the form said, or "valid" would be
					// satisfied by emitting `{}` for everything.
					$this->assertSame(
						$match_all,
						$parsed->matches_all(),
						'match_all did not survive the round trip: ' . $encoded
					);

					$this->assertSame(
						self::expected_side( $kinds, $samples, $include ),
						$parsed->includes(),
						'the include set did not survive the round trip: ' . $encoded
					);

					$this->assertSame(
						self::expected_side( $kinds, $samples, $exclude ),
						$parsed->excludes(),
						'the exclude set did not survive the round trip: ' . $encoded
					);

					$shapes[ self::shape_of( $encoded ) ] = true;
					++$documents;
				}
			}
		}

		$this->assertSame( 2048, $documents, 'The enumeration did not cover the whole shape-space.' );

		fwrite(
			STDERR,
			"\n[P9 gate 29] targeting: " . $documents . ' documents enumerated exhaustively ('
			. count( $kinds ) . " kinds x 2 sides x match_all), all accepted by the RAW validator;\n"
			. '             ' . count( $shapes ) . " distinct encoded shapes\n"
		);
	}

	/**
	 * GATE 29b. EVERY RECIPIENTS DOCUMENT THE FORM CAN EMIT IS ACCEPTED BY
	 *           `RecipientsDocument` — all 8 of them.
	 *
	 * @return void
	 */
	public function test_every_emittable_recipients_document_is_valid() {
		$channels = RecipientsDocument::CHANNELS;
		$samples  = array(
			'to'  => array( 'customer', '{customer_email}' ),
			'cc'  => array( 'admin' ),
			'bcc' => array( 'archive@example.test', 'archive@example.test' ),
		);

		$documents = 0;

		for ( $mask = 0; $mask < ( 1 << count( $channels ) ); $mask++ ) {
			$input = array();

			foreach ( $channels as $index => $channel ) {
				$input[ $channel ] = ( $mask & ( 1 << $index ) ) ? $samples[ $channel ] : array();
			}

			$document = RuleDocuments::recipients( $input );
			$encoded  = RecipientsDocument::encode( $document );
			$parsed   = RecipientsDocument::from_value( $encoded );

			$this->assertNotNull(
				$parsed,
				'⚠ the editor emitted a recipients document its own validator rejects: ' . $encoded
			);

			foreach ( $channels as $index => $channel ) {
				if ( $mask & ( 1 << $index ) ) {
					$this->assertSame(
						array_values( array_unique( $samples[ $channel ] ) ),
						array_values( (array) $parsed[ $channel ] ),
						'a channel did not survive the round trip: ' . $encoded
					);
					continue;
				}

				$this->assertArrayNotHasKey( $channel, $parsed, 'an empty channel was emitted: ' . $encoded );
			}

			++$documents;
		}

		$this->assertSame( 8, $documents );

		fwrite(
			STDERR,
			'[P9 gate 29] recipients: ' . $documents . " documents enumerated exhaustively, all accepted\n"
		);
	}

	/**
	 * GATE 29c. THE EMPTY FORM — every control untouched — emits `{}` for BOTH
	 *           columns, which is the one shape a brand-new rule always has.
	 *
	 * ⚠ AND `{}` IS NOT `[]`. `Json::encode()` renders an empty PHP array as `[]`,
	 * which `Targeting` correctly calls `targeting_invalid`, so a brand-new rule
	 * would have reported itself permanently broken. The writer has to be as precise
	 * about the distinction as the reader is (ADR-0011 §3).
	 *
	 * @return void
	 */
	public function test_the_empty_form_emits_object_shaped_documents() {
		$targeting = Targeting::encode( RuleDocuments::targeting( array() ) );

		$this->assertSame( '{"match_all":false}', $targeting );
		$this->assertTrue( Targeting::from_value( $targeting )->is_valid() );

		$recipients = RecipientsDocument::encode( RuleDocuments::recipients( array() ) );

		$this->assertSame( '{}', $recipients );
		$this->assertNotNull( RecipientsDocument::from_value( $recipients ) );
	}

	/**
	 * GATE 29d. THE VALUE SPACE, BY TYPE: hostile entries are DROPPED, never
	 *           coerced into a different id.
	 *
	 * ⚠ THE STRUCTURAL ENUMERATION ABOVE COVERS SHAPES; THIS COVERS VALUES. The
	 * parsers are `Targeting`'s own — the editor uses them rather than imitating
	 * them (ADR-0017 §2) — so what is asserted here is that a form field carrying
	 * junk cannot put a value into a document that the validator would then have to
	 * judge.
	 *
	 * @dataProvider hostile_entry_provider
	 *
	 * @param mixed  $entry A value a forged POST could carry.
	 * @param string $why   What it would have become under a cast.
	 * @return void
	 */
	public function test_hostile_entries_are_dropped_not_coerced( $entry, string $why ) {
		$document = RuleDocuments::targeting(
			array(
				'include'   => array( 'products' => array( $entry ) ),
				'match_all' => false,
			)
		);

		$encoded = Targeting::encode( $document );
		$parsed  = Targeting::from_value( $encoded );

		$this->assertTrue( $parsed->is_valid(), 'a hostile entry made the document invalid: ' . $encoded );
		$this->assertSame( array(), $parsed->includes(), '⚠ ' . $why . ' — ' . $encoded );
	}

	/**
	 * Entries a cast would silently turn into a DIFFERENT, valid product id.
	 *
	 * @return array<string,array{0:mixed,1:string}>
	 */
	public static function hostile_entry_provider(): array {
		return array(
			'mixed alphanumeric' => array( '1abc', '"1abc" became product 1' ),
			'trailing letters'   => array( '12x', '"12x" became product 12' ),
			'boolean true'       => array( true, 'true became product 1' ),
			'float'              => array( 1.9, '1.9 became product 1' ),
			'negative'           => array( -3, 'a negative id was stored' ),
			'zero'               => array( 0, 'id 0 was stored' ),
			'zero string'        => array( '0', 'id 0 was stored' ),
			'signed string'      => array( '+7', '"+7" became product 7' ),
			'spaced'             => array( ' 7 ', '" 7 " became product 7' ),
			'decimal string'     => array( '7.0', '"7.0" became product 7' ),
			'oversized'          => array( '999999999999999999999999999999', 'an oversized id clamped to PHP_INT_MAX' ),
			'null'               => array( null, 'null became product 0' ),
			'array'              => array( array( 7 ), 'an array became a product id' ),
			'empty string'       => array( '', 'an empty entry became product 0' ),
			'hex'                => array( '0x7', '"0x7" became a product id' ),
		);
	}

	/**
	 * A forged NON-LIST value for a kind cannot make the document invalid either.
	 *
	 * ⚠ THE SHAPE THAT MATTERS MOST. `Targeting::from_json()` refuses a kind that is
	 * not a JSON list — `{"products":{"0":1}}` would otherwise turn an object's
	 * values into ids — so a form field arriving as a string, a scalar or an
	 * associative array must never reach the document as one.
	 *
	 * @dataProvider forged_kind_provider
	 *
	 * @param mixed $value A forged `targeting[include][products]` value.
	 * @return void
	 */
	public function test_a_forged_kind_value_cannot_produce_an_invalid_document( $value ) {
		$encoded = Targeting::encode(
			RuleDocuments::targeting(
				array(
					'include'   => array( 'products' => $value ),
					'match_all' => false,
				)
			)
		);

		$this->assertTrue(
			Targeting::from_value( $encoded )->is_valid(),
			'⚠ a forged kind value produced a document the validator rejects: ' . $encoded
		);
	}

	/**
	 * Values a forged POST could put where a list belongs.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function forged_kind_provider(): array {
		return array(
			'a string'            => array( '1,2,3' ),
			'an integer'          => array( 7 ),
			'a boolean'           => array( true ),
			'null'                => array( null ),
			'an associative map'  => array( array( 'a' => 1 ) ),
			'a nested list'       => array( array( array( 1 ) ) ),
			'an object-like list' => array(
				array(
					'0' => 1,
					'1' => 2,
				),
			),
		);
	}

	/**
	 * A forged `match_all` is always written as a REAL boolean.
	 *
	 * ⚠ `Targeting::from_array()` REFUSES `"yes"` AND `1` rather than guessing at
	 * them, because guessing means guessing whether a rule targets the entire
	 * catalogue. The editor must therefore never pass a truthy non-boolean through.
	 *
	 * @dataProvider match_all_provider
	 *
	 * @param mixed $value    Submitted value.
	 * @param bool  $expected What it must become.
	 * @return void
	 */
	public function test_match_all_is_always_a_real_boolean( $value, bool $expected ) {
		$document = RuleDocuments::targeting( array( 'match_all' => $value ) );

		$this->assertIsBool( $document['match_all'] );
		$this->assertSame( $expected, $document['match_all'] );

		$parsed = Targeting::from_value( Targeting::encode( $document ) );

		$this->assertTrue( $parsed->is_valid() );
		$this->assertSame( $expected, $parsed->matches_all() );
	}

	/**
	 * Truthy and falsy shapes a checkbox — or a forged post — can produce.
	 *
	 * @return array<string,array{0:mixed,1:bool}>
	 */
	public static function match_all_provider(): array {
		return array(
			'checkbox on'  => array( '1', true ),
			'string yes'   => array( 'yes', true ),
			'integer one'  => array( 1, true ),
			'real true'    => array( true, true ),
			'absent'       => array( null, false ),
			'empty string' => array( '', false ),
			'string zero'  => array( '0', false ),
			'real false'   => array( false, false ),
			'array'        => array( array( 'x' ), true ),
		);
	}

	/**
	 * Recipient entries are shape-filtered, de-duplicated and single-line.
	 *
	 * ⚠ THE LINE-BREAK STRIP IS NOT THE HEADER DEFENCE, and this test does not claim
	 * it is: `Delivery\RecipientResolver` still applies `HeaderGuard::strip()`,
	 * `is_email()` validation and ADR-0014 §7a's whole-entry refusal at send time.
	 * What it prevents is STORING an entry the sender will later refuse — a rule
	 * that looks saved and cannot deliver.
	 *
	 * @return void
	 */
	public function test_recipient_entries_are_shape_filtered() {
		$entries = RuleDocuments::entry_list(
			array(
				'  customer  ',
				'customer',
				"bcc@example.test\r\nBcc: attacker@example.test",
				'',
				'   ',
				123,
				null,
				array( 'x' ),
				'admin',
			)
		);

		$this->assertSame(
			array( 'customer', 'bcc@example.testBcc: attacker@example.test', 'admin' ),
			$entries
		);

		foreach ( $entries as $entry ) {
			$this->assertIsString( $entry );
			$this->assertNotSame( '', $entry );
			$this->assertSame( 0, preg_match( '/[\r\n]/', $entry ), 'a stored recipient carried a line break' );
		}
	}

	/**
	 * Build one side from a subset mask.
	 *
	 * @param string[] $kinds   Kind names.
	 * @param array    $samples Kind => sample entries.
	 * @param int      $mask    Subset mask.
	 * @return array
	 */
	private static function subset( array $kinds, array $samples, int $mask ): array {
		$side = array();

		foreach ( $kinds as $index => $kind ) {
			$side[ $kind ] = ( $mask & ( 1 << $index ) ) ? $samples[ $kind ] : array();
		}

		return $side;
	}

	/**
	 * What the validator must read back for one subset mask.
	 *
	 * @param string[] $kinds   Kind names.
	 * @param array    $samples Kind => sample entries.
	 * @param int      $mask    Subset mask.
	 * @return array
	 */
	private static function expected_side( array $kinds, array $samples, int $mask ): array {
		$side = array();

		foreach ( $kinds as $index => $kind ) {
			if ( ! ( $mask & ( 1 << $index ) ) ) {
				continue;
			}

			$side[ $kind ] = in_array( $kind, Targeting::ID_KINDS, true )
				? Targeting::int_list( $samples[ $kind ] )
				: Targeting::type_list( $samples[ $kind ] );
		}

		return $side;
	}

	/**
	 * The STRUCTURE of an encoded document, with values replaced — so the count of
	 * distinct shapes is a count of shapes rather than of values.
	 *
	 * @param string $encoded Encoded document.
	 * @return string
	 */
	private static function shape_of( string $encoded ): string {
		return (string) preg_replace( '/\[[^\]]*\]/', '[]', $encoded );
	}
}
