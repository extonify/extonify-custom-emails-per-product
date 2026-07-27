<?php
/**
 * JSON column encode/decode round-trips and malformed input (ADR-0009).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Extonify\WCEP\Domain\Json;

/**
 * Corrupt stored JSON must degrade one rule, never fatal a request, and never
 * hand a caller something other than an array.
 */
final class JsonTest extends UnitTestCase {

	/**
	 * Round-trip of the shapes the rule columns actually hold.
	 *
	 * @dataProvider round_trip_provider
	 *
	 * @param array $value Structure to round-trip.
	 * @return void
	 */
	public function test_round_trip( array $value ) {
		$this->assertSame( $value, Json::decode( Json::encode( $value ) ) );
	}

	/**
	 * Structures that must survive a storage round-trip unchanged.
	 *
	 * @return array<string,array{0:array}>
	 */
	public static function round_trip_provider() {
		return array(
			'empty'            => array( array() ),
			'list'             => array( array( 1, 2, 3 ) ),
			'assoc'            => array(
				array(
					'products'   => array( 12, 34 ),
					'categories' => array( 'mugs' ),
				),
			),
			'nested'           => array(
				array(
					'match' => array(
						'all' => array(
							array(
								'type'  => 'product',
								'value' => 7,
							),
						),
					),
				),
			),
			'unicode'          => array( array( 'label' => 'Prüfung — ünïcodé ✓' ) ),
			'slashes and html' => array( array( 'url' => 'https://example.test/a/b?c=1&d=2' ) ),
			'booleans + null'  => array(
				array(
					'on'   => true,
					'off'  => false,
					'none' => null,
				),
			),
		);
	}

	/**
	 * Every malformed or non-array input decodes to an empty array rather than
	 * null, so callers can always treat the result as an array.
	 *
	 * @dataProvider malformed_provider
	 *
	 * @param mixed $stored Raw column value.
	 * @return void
	 */
	public function test_malformed_input_decodes_to_empty_array( $stored ) {
		$this->assertSame( array(), Json::decode( $stored ) );
	}

	/**
	 * Values a corrupt or legacy column could realistically hold.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function malformed_provider() {
		return array(
			'null'              => array( null ),
			'empty string'      => array( '' ),
			'whitespace'        => array( "  \n\t " ),
			'truncated json'    => array( '{"products":[1,2' ),
			'not json at all'   => array( 'O:8:"stdClass":0:{}' ),
			'json scalar int'   => array( '42' ),
			'json scalar bool'  => array( 'true' ),
			'json null literal' => array( 'null' ),
			'json string'       => array( '"just a string"' ),
			'integer column'    => array( 42 ),
			'boolean column'    => array( false ),
			'object'            => array( new \stdClass() ),
		);
	}

	/**
	 * An already-decoded array passes through untouched, so a caller that
	 * hydrates twice is harmless.
	 *
	 * @return void
	 */
	public function test_already_decoded_array_passes_through() {
		$value = array( 'products' => array( 1, 2 ) );

		$this->assertSame( $value, Json::decode( $value ) );
	}

	/**
	 * Encoding never returns null: a value json_encode genuinely cannot
	 * represent yields '[]', which decodes back to an empty array rather than
	 * writing NULL to the column.
	 *
	 * INF is used rather than invalid UTF-8 because the two behave differently
	 * across implementations — see the round-trip test below — whereas
	 * JSON_ERROR_INF_OR_NAN makes wp_json_encode() return false everywhere.
	 *
	 * @return void
	 */
	public function test_unencodable_value_yields_empty_array_json() {
		$encoded = Json::encode( array( 'bad' => INF ) );

		$this->assertSame( '[]', $encoded );
		$this->assertSame( array(), Json::decode( $encoded ) );
	}

	/**
	 * Invalid UTF-8 must always survive the round trip AS AN ARRAY, whatever
	 * the encoder does with the bytes.
	 *
	 * The two encoders differ here and the contract deliberately does not:
	 * WordPress's wp_json_encode() sanity-checks and strips invalid UTF-8, so
	 * the key survives with a scrubbed value; a bare json_encode() fails and
	 * Json::encode() falls back to '[]'. Either way the caller gets an array
	 * and never a null, which is the only thing storage depends on.
	 *
	 * @return void
	 */
	public function test_invalid_utf8_always_round_trips_to_an_array() {
		$decoded = Json::decode( Json::encode( array( 'bad' => "\xB1\x31" ) ) );

		$this->assertIsArray( $decoded );
	}

	/**
	 * Depth is bounded: a document nested past the limit is rejected as
	 * malformed rather than consuming the parser.
	 *
	 * @return void
	 */
	public function test_excessive_depth_is_rejected_on_read() {
		$deep = str_repeat( '[', Json::MAX_DEPTH + 5 ) . str_repeat( ']', Json::MAX_DEPTH + 5 );

		$this->assertSame( array(), Json::decode( $deep ) );
	}
}
