<?php
/**
 * Targeting evaluation against plain item descriptors (ADR-0011 §3, §4).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Extonify\WCEP\Domain\Specificity;
use Extonify\WCEP\Domain\Targeting;

/**
 * Every targeting decision, with no WordPress, no database and no product.
 *
 * The engine's WooCommerce-facing half resolves line items into plain
 * descriptors precisely so this half can be proven here.
 */
final class TargetingTest extends UnitTestCase {

	/**
	 * A simple-product line item, overridable field by field.
	 *
	 * @param array $overrides Descriptor fields to replace.
	 * @return array
	 */
	private function item( array $overrides = array() ): array {
		return array_merge(
			array(
				'item_id'      => 1,
				'product_id'   => 100,
				'variation_id' => 0,
				'type'         => 'simple',
				'parent_type'  => '',
				'virtual'      => false,
				'downloadable' => false,
				'category_ids' => array(),
				'tag_ids'      => array(),
			),
			$overrides
		);
	}

	/**
	 * A variation line item of parent 100.
	 *
	 * @param array $overrides Descriptor fields to replace.
	 * @return array
	 */
	private function variation_item( array $overrides = array() ): array {
		return $this->item(
			array_merge(
				array(
					'item_id'      => 2,
					'variation_id' => 250,
					'type'         => 'variation',
					'parent_type'  => 'variable',
				),
				$overrides
			)
		);
	}

	// ---------------------------------------------------------------------
	// Include kinds.
	// ---------------------------------------------------------------------

	/**
	 * Each include kind matches, at its own rung of the ladder.
	 *
	 * @dataProvider include_kind_provider
	 *
	 * @param array  $include  Include document.
	 * @param array  $item     Item descriptor.
	 * @param string $kind     Expected matched kind.
	 * @param int    $level    Expected specificity level.
	 * @return void
	 */
	public function test_each_include_kind_matches( array $include, array $item, string $kind, int $level ) {
		$outcome = Targeting::from_array( array( 'include' => $include ) )->evaluate( $item );

		$this->assertTrue( $outcome['matched'] );
		$this->assertSame( $kind, $outcome['kind'] );
		$this->assertSame( $level, $outcome['level'] );
	}

	/**
	 * One case per include kind.
	 *
	 * @return array
	 */
	public function include_kind_provider(): array {
		$plain     = $this->item();
		$variation = $this->variation_item();

		return array(
			'products'   => array( array( 'products' => array( 100 ) ), $plain, 'product', Specificity::PRODUCT ),
			'variations' => array( array( 'variations' => array( 250 ) ), $variation, 'variation', Specificity::VARIATION ),
			'categories' => array( array( 'categories' => array( 7 ) ), $this->item( array( 'category_ids' => array( 7, 9 ) ) ), 'category', Specificity::CATEGORY ),
			'tags'       => array( array( 'tags' => array( 4 ) ), $this->item( array( 'tag_ids' => array( 4 ) ) ), 'tag', Specificity::TAG ),
			'types'      => array( array( 'types' => array( 'simple' ) ), $plain, 'type', Specificity::TYPE ),
		);
	}

	/**
	 * Each exclude kind removes an item the include set had accepted.
	 *
	 * @dataProvider exclude_kind_provider
	 *
	 * @param array $exclude Exclude document.
	 * @param array $item    Item descriptor.
	 * @return void
	 */
	public function test_each_exclude_kind_excludes( array $exclude, array $item ) {
		$outcome = Targeting::from_array(
			array(
				'match_all' => true,
				'exclude'   => $exclude,
			)
		)->evaluate( $item );

		$this->assertTrue( $outcome['included'], 'match_all should have included the item.' );
		$this->assertTrue( $outcome['excluded'] );
		$this->assertFalse( $outcome['matched'] );
	}

	/**
	 * One case per exclude kind.
	 *
	 * @return array
	 */
	public function exclude_kind_provider(): array {
		return array(
			'products'   => array( array( 'products' => array( 100 ) ), $this->item() ),
			'variations' => array( array( 'variations' => array( 250 ) ), $this->variation_item() ),
			'categories' => array( array( 'categories' => array( 7 ) ), $this->item( array( 'category_ids' => array( 7 ) ) ) ),
			'tags'       => array( array( 'tags' => array( 4 ) ), $this->item( array( 'tag_ids' => array( 4 ) ) ) ),
			'types'      => array( array( 'types' => array( 'simple' ) ), $this->item() ),
		);
	}

	// ---------------------------------------------------------------------
	// Exclusion always wins.
	// ---------------------------------------------------------------------

	/**
	 * A variation-level include — the most specific rung there is — cannot
	 * rescue an item excluded at the broadest rung (ADR-0011 §4).
	 *
	 * @return void
	 */
	public function test_exclusion_beats_a_more_specific_include() {
		$outcome = Targeting::from_array(
			array(
				'include' => array( 'variations' => array( 250 ) ),
				'exclude' => array( 'types' => array( 'variable' ) ),
			)
		)->evaluate( $this->variation_item() );

		$this->assertTrue( $outcome['included'] );
		$this->assertTrue( $outcome['excluded'] );
		$this->assertFalse( $outcome['matched'] );
	}

	/**
	 * Exclusion wins at every rung, in both directions.
	 *
	 * @dataProvider exclusion_precedence_provider
	 *
	 * @param array $document Complete targeting document.
	 * @return void
	 */
	public function test_exclusion_wins_at_every_level( array $document ) {
		$item = $this->variation_item(
			array(
				'category_ids' => array( 7 ),
				'tag_ids'      => array( 4 ),
			)
		);

		$outcome = Targeting::from_array( $document )->evaluate( $item );

		$this->assertFalse( $outcome['matched'] );
	}

	/**
	 * Include/exclude pairs across the whole ladder. Whole documents, because
	 * `match_all` lives at the ROOT and nowhere else (ADR-0011 §3).
	 *
	 * @return array
	 */
	public function exclusion_precedence_provider(): array {
		return array(
			'variation excluded by tag'      => array(
				array(
					'include' => array( 'variations' => array( 250 ) ),
					'exclude' => array( 'tags' => array( 4 ) ),
				),
			),
			'product excluded by category'   => array(
				array(
					'include' => array( 'products' => array( 100 ) ),
					'exclude' => array( 'categories' => array( 7 ) ),
				),
			),
			'category excluded by variation' => array(
				array(
					'include' => array( 'categories' => array( 7 ) ),
					'exclude' => array( 'variations' => array( 250 ) ),
				),
			),
			'tag excluded by product'        => array(
				array(
					'include' => array( 'tags' => array( 4 ) ),
					'exclude' => array( 'products' => array( 100 ) ),
				),
			),
			'match_all excluded by type'     => array(
				array(
					'match_all' => true,
					'exclude'   => array( 'types' => array( 'variation' ) ),
				),
			),
		);
	}

	/**
	 * A product that is both a matching TYPE and an excluded CATEGORY is
	 * excluded — the two kinds share no precedence, exclusion simply wins.
	 *
	 * @return void
	 */
	public function test_matching_type_with_excluded_category_is_excluded() {
		$targeting = Targeting::from_array(
			array(
				'include' => array( 'types' => array( 'downloadable' ) ),
				'exclude' => array( 'categories' => array( 12 ) ),
			)
		);

		$excluded = $targeting->evaluate(
			$this->item(
				array(
					'downloadable' => true,
					'category_ids' => array( 12 ),
				)
			)
		);
		$this->assertFalse( $excluded['matched'] );
		$this->assertTrue( $excluded['included'], 'The type include should still have matched before exclusion.' );

		// The same rule on the same product type in a different category does
		// match, so the exclusion is what made the difference.
		$kept = $targeting->evaluate(
			$this->item(
				array(
					'downloadable' => true,
					'category_ids' => array( 13 ),
				)
			)
		);
		$this->assertTrue( $kept['matched'] );
	}

	// ---------------------------------------------------------------------
	// match_all and the empty document.
	// ---------------------------------------------------------------------

	/**
	 * `match_all: true` matches every item at global specificity.
	 *
	 * @return void
	 */
	public function test_match_all_matches_everything_at_global_specificity() {
		$targeting = Targeting::from_array( array( 'match_all' => true ) );

		foreach ( array( $this->item(), $this->variation_item(), $this->item( array( 'product_id' => 999 ) ) ) as $item ) {
			$outcome = $targeting->evaluate( $item );
			$this->assertTrue( $outcome['matched'] );
			$this->assertSame( 'match_all', $outcome['kind'] );
			$this->assertSame( Specificity::MATCH_ALL, $outcome['level'] );
		}
	}

	/**
	 * `match_all` is a floor, not a ceiling: an item that also satisfies a
	 * declared include kind keeps the higher level it earned.
	 *
	 * @return void
	 */
	public function test_match_all_does_not_flatten_a_declared_include() {
		$targeting = Targeting::from_array(
			array(
				'match_all' => true,
				'include'   => array( 'products' => array( 100 ) ),
			)
		);

		$declared = $targeting->evaluate( $this->item() );
		$this->assertSame( Specificity::PRODUCT, $declared['level'] );
		$this->assertSame( 'product', $declared['kind'] );

		$other = $targeting->evaluate( $this->item( array( 'product_id' => 555 ) ) );
		$this->assertTrue( $other['matched'] );
		$this->assertSame( Specificity::MATCH_ALL, $other['level'] );
	}

	/**
	 * `match_all` is CANONICALLY at the root, and `include.match_all` is an
	 * ordinary unknown key (ADR-0011 §3).
	 *
	 * Reading it in two places put two spellings of one flag into a schema that
	 * import, export and editor validation all have to agree on. It is ignored
	 * — not invalid: an unknown key is not a shape error.
	 *
	 * @return void
	 */
	public function test_match_all_inside_include_is_ignored() {
		$targeting = Targeting::from_array( array( 'include' => array( 'match_all' => true ) ) );

		$this->assertTrue( $targeting->is_valid(), 'An unknown key is ignored, never fatal.' );
		$this->assertFalse( $targeting->matches_all() );
		$this->assertTrue( $targeting->matches_nothing() );
		$this->assertFalse( $targeting->evaluate( $this->item() )['matched'] );
	}

	/**
	 * `exclude.match_all` was never in the schema and had no coherent meaning —
	 * a rule that excludes everything is an inactive rule. It is ignored.
	 *
	 * @return void
	 */
	public function test_exclude_match_all_is_ignored() {
		$outcome = Targeting::from_array(
			array(
				'include' => array( 'products' => array( 100 ) ),
				'exclude' => array( 'match_all' => true ),
			)
		)->evaluate( $this->item() );

		$this->assertTrue( $outcome['included'] );
		$this->assertFalse( $outcome['excluded'] );
		$this->assertTrue( $outcome['matched'] );
	}

	/**
	 * The one canonical position works, and works for the exclude side too when
	 * an ordinary exclude kind is declared next to it.
	 *
	 * @return void
	 */
	public function test_root_match_all_is_the_only_position() {
		$targeting = Targeting::from_value( '{"match_all":true,"exclude":{"products":[100]}}' );

		$this->assertTrue( $targeting->matches_all() );
		$this->assertFalse( $targeting->evaluate( $this->item() )['matched'], 'The excluded product should have been removed.' );
		$this->assertTrue( $targeting->evaluate( $this->item( array( 'product_id' => 555 ) ) )['matched'] );
	}

	/**
	 * An empty include with match_all false matches nothing — and is VALID,
	 * not `targeting_invalid`. A brand-new rule with no targeting yet is
	 * simply inert.
	 *
	 * @dataProvider empty_document_provider
	 *
	 * @param mixed $value Stored targeting value.
	 * @return void
	 */
	public function test_empty_include_matches_nothing( $value ) {
		$targeting = Targeting::from_value( $value );

		$this->assertTrue( $targeting->is_valid(), 'An empty document is valid, just inert.' );
		$this->assertTrue( $targeting->matches_nothing() );
		$this->assertFalse( $targeting->evaluate( $this->item() )['matched'] );
		$this->assertSame( Specificity::NONE, $targeting->declared_specificity() );
	}

	/**
	 * Every spelling of "nothing declared".
	 *
	 * @return array
	 */
	public function empty_document_provider(): array {
		return array(
			'null'              => array( null ),
			'empty string'      => array( '' ),
			'whitespace string' => array( "  \n " ),
			// A PHP array, not a raw string: the `{}`-versus-`[]` distinction
			// does not exist here, and a constructed empty document is valid.
			'empty array'       => array( array() ),
			'empty json object' => array( '{}' ),
			'empty include'     => array( '{"include":{},"match_all":false}' ),
			'empty kind lists'  => array( '{"include":{"products":[],"tags":[]}}' ),
		);
	}

	// ---------------------------------------------------------------------
	// Validity.
	// ---------------------------------------------------------------------

	/**
	 * Malformed targeting reports `targeting_invalid` and matches nothing.
	 *
	 * @dataProvider malformed_provider
	 *
	 * @param mixed $value Stored targeting value.
	 * @return void
	 */
	public function test_malformed_targeting_is_invalid( $value ) {
		$targeting = Targeting::from_value( $value );

		$this->assertFalse( $targeting->is_valid() );
		$this->assertTrue( $targeting->matches_nothing() );
		$this->assertFalse( $targeting->evaluate( $this->item() )['matched'] );
		$this->assertSame( Specificity::NONE, $targeting->declared_specificity() );
	}

	/**
	 * Syntactic and schema-level malformations.
	 *
	 * THE LIST-SHAPED CASES ARE THE POINT. Before strict validation `[1,2]` and
	 * `{"include":[1,2]}` found no `include` key, became perfectly VALID empty
	 * documents, and reported `no_targeting_match` — which ADR-0005 does not
	 * log. A corrupted or badly imported rule therefore stopped working with no
	 * entry anywhere in the delivery log, which is the exact silent failure the
	 * decision log exists to eliminate.
	 *
	 * @return array
	 */
	public function malformed_provider(): array {
		return array(
			'truncated json'         => array( '{"include":{"products":[1,2' ),
			'not json at all'        => array( 'products: 1, 2' ),
			'json scalar string'     => array( '"everything"' ),
			'json number'            => array( '42' ),
			'stored integer'         => array( 42 ),
			'stored boolean'         => array( true ),
			'include is scalar'      => array( '{"include":"everything"}' ),
			'exclude is scalar'      => array( '{"exclude":7}' ),
			'kind is scalar'         => array( '{"include":{"products":"12"}}' ),
			'exclude kind wrong'     => array( array( 'exclude' => array( 'tags' => 'four' ) ) ),
			// Root is a LIST, not an object — including an EMPTY one, which the
			// raw boundary can still tell apart from `{}`.
			'empty root list'        => array( '[]' ),
			'empty include list'     => array( '{"include":[]}' ),
			'empty exclude list'     => array( '{"exclude":[]}' ),
			'root list of ids'       => array( '[1,2]' ),
			'root list of objects'   => array( '[{"include":{"products":[1]}}]' ),
			'root list decoded'      => array( array( 1, 2 ) ),
			// include/exclude are LISTS, not objects.
			'include is a list'      => array( '{"include":[1,2]}' ),
			'exclude is a list'      => array( '{"exclude":[1,2]}' ),
			'include list decoded'   => array( array( 'include' => array( 1, 2 ) ) ),
			'include list of arrays' => array( '{"include":[{"products":[1]}]}' ),
			// match_all must be a REAL boolean.
			'match_all "yes"'        => array( '{"match_all":"yes"}' ),
			'match_all "true"'       => array( '{"match_all":"true"}' ),
			'match_all 1'            => array( '{"match_all":1}' ),
			'match_all 0'            => array( '{"match_all":0}' ),
			'match_all null'         => array( '{"match_all":null}' ),
			'match_all is a list'    => array( '{"match_all":[]}' ),
			'match_all is an object' => array( '{"match_all":{"value":true}}' ),
		);
	}

	/**
	 * Every known kind, on either polarity, must be an ARRAY when present.
	 *
	 * @dataProvider non_array_kind_provider
	 *
	 * @param string $json Stored targeting document.
	 * @return void
	 */
	public function test_a_known_kind_must_be_an_array( string $json ) {
		$this->assertFalse( Targeting::from_value( $json )->is_valid(), $json . ' should be structurally invalid.' );
	}

	/**
	 * One case per kind per polarity, plus the shapes a broken export writes.
	 *
	 * @return array
	 */
	public function non_array_kind_provider(): array {
		$cases = array();

		foreach ( array( 'variations', 'products', 'categories', 'tags', 'types' ) as $kind ) {
			$cases[ 'include.' . $kind . ' scalar' ] = array( '{"include":{"' . $kind . '":"7"}}' );
			$cases[ 'exclude.' . $kind . ' scalar' ] = array( '{"exclude":{"' . $kind . '":7}}' );
			$cases[ 'include.' . $kind . ' bool' ]   = array( '{"include":{"' . $kind . '":true}}' );
			$cases[ 'include.' . $kind . ' null' ]   = array( '{"include":{"' . $kind . '":null}}' );
		}

		return $cases;
	}

	/**
	 * An UNKNOWN key of any shape is still ignored, including one that would be
	 * fatal if it were a known kind. Shape errors and unknown keys are different
	 * things (ADR-0011 §3) and must stay different.
	 *
	 * @return void
	 */
	public function test_a_malformed_unknown_key_is_still_only_ignored() {
		$targeting = Targeting::from_value( '{"include":{"products":[100],"brands":"nonsense","authors":[1,2]},"version":"9"}' );

		$this->assertTrue( $targeting->is_valid() );
		$this->assertSame( array( 'products' ), array_keys( $targeting->includes() ) );
		$this->assertTrue( $targeting->evaluate( $this->item() )['matched'] );
	}

	/**
	 * `{}` and `[]` ARE distinguished at the raw boundary.
	 *
	 * Associative decoding renders them as the same empty PHP array, which is a
	 * limitation of that decode MODE, not of JSON. Decoding without it keeps the
	 * distinction, and strict validation makes it — so a truncated or badly
	 * exported document reports the LOGGABLE `targeting_invalid` rather than the
	 * silent `no_targeting_match`.
	 *
	 * @dataProvider empty_shape_provider
	 *
	 * @param string $json  Raw column string.
	 * @param bool   $valid Expected validity.
	 * @return void
	 */
	public function test_empty_object_is_valid_and_empty_list_is_not( string $json, bool $valid ) {
		$targeting = Targeting::from_value( $json );

		$this->assertSame( $valid, $targeting->is_valid(), $json );
		$this->assertTrue( $targeting->matches_nothing(), $json . ' must match nothing either way.' );
		$this->assertFalse( $targeting->evaluate( $this->item() )['matched'] );
	}

	/**
	 * The empty shapes, object versus list, on the root and both sides.
	 *
	 * @return array
	 */
	public function empty_shape_provider(): array {
		return array(
			'root object'           => array( '{}', true ),
			'root list'             => array( '[]', false ),
			'include object'        => array( '{"include":{}}', true ),
			'include list'          => array( '{"include":[]}', false ),
			'exclude object'        => array( '{"exclude":{}}', true ),
			'exclude list'          => array( '{"exclude":[]}', false ),
			'both objects'          => array( '{"include":{},"exclude":{}}', true ),
			'both lists'            => array( '{"include":[],"exclude":[]}', false ),
			'one of each'           => array( '{"include":{},"exclude":[]}', false ),
			'object with match_all' => array( '{"include":{},"match_all":false}', true ),
		);
	}

	/**
	 * The distinction is a RAW-BOUNDARY one. A programmatically constructed
	 * empty document is legitimate, and by the time a document is a PHP array
	 * the information simply is not there any more — so `from_array()` accepts
	 * it and does not pretend otherwise.
	 *
	 * @return void
	 */
	public function test_an_empty_array_document_is_valid_when_built_programmatically() {
		foreach ( array( array(), array( 'include' => array() ), array( 'exclude' => array() ) ) as $document ) {
			$targeting = Targeting::from_array( $document );
			$this->assertTrue( $targeting->is_valid(), 'A constructed empty document is legitimate.' );
			$this->assertTrue( $targeting->matches_nothing() );
		}
	}

	/**
	 * `encode()` writes what `from_value()` reads: every document survives the
	 * round trip, and an EMPTY one comes back valid rather than invalid.
	 *
	 * The writer has to be as precise about object-versus-array as the reader,
	 * or a rule with no targeting yet reports itself permanently broken.
	 *
	 * @dataProvider round_trip_provider
	 *
	 * @param array  $document Document to store.
	 * @param string $expected Exact JSON expected in the column.
	 * @return void
	 */
	public function test_encode_round_trips_through_the_strict_reader( array $document, string $expected ) {
		$json = Targeting::encode( $document );

		$this->assertSame( $expected, $json );
		$this->assertTrue( Targeting::from_value( $json )->is_valid(), $json . ' did not survive its own encoder.' );
	}

	/**
	 * Documents a rule editor or importer realistically writes.
	 *
	 * @return array<string,array{0:array,1:string}>
	 */
	public function round_trip_provider(): array {
		return array(
			'nothing declared' => array( array(), '{}' ),
			'empty include'    => array( array( 'include' => array() ), '{"include":{}}' ),
			'empty exclude'    => array( array( 'exclude' => array() ), '{"exclude":{}}' ),
			'empty kind list'  => array( array( 'include' => array( 'products' => array() ) ), '{"include":{"products":[]}}' ),
			'products'         => array( array( 'include' => array( 'products' => array( 100 ) ) ), '{"include":{"products":[100]}}' ),
			'match_all'        => array( array( 'match_all' => true ), '{"match_all":true}' ),
			'both sides'       => array(
				array(
					'include' => array( 'products' => array( 1 ) ),
					'exclude' => array( 'tags' => array( 2 ) ),
				),
				'{"include":{"products":[1]},"exclude":{"tags":[2]}}',
			),
		);
	}

	/**
	 * The encoder fixes the empty-array AMBIGUITY and nothing else: a root that
	 * genuinely is a list is written as one, so garbage still reads back as
	 * `targeting_invalid` instead of being quietly repaired on the way in.
	 *
	 * @return void
	 */
	public function test_encode_does_not_repair_a_list_shaped_document() {
		$json = Targeting::encode( array( 1, 2 ) );

		$this->assertSame( '[1,2]', $json );
		$this->assertFalse( Targeting::from_value( $json )->is_valid() );
	}

	/**
	 * A known kind written as a JSON OBJECT where a list is required is a shape
	 * error, and is caught BEFORE conversion — afterwards `{"0":1}` is an
	 * ordinary PHP array and its values would have become ids.
	 *
	 * @dataProvider object_kind_provider
	 *
	 * @param string $json Raw column string.
	 * @return void
	 */
	public function test_a_kind_written_as_an_object_is_invalid( string $json ) {
		$this->assertFalse( Targeting::from_value( $json )->is_valid(), $json );
	}

	/**
	 * Object-shaped kinds on both polarities.
	 *
	 * @return array
	 */
	public function object_kind_provider(): array {
		return array(
			'numeric-keyed object' => array( '{"include":{"products":{"0":1}}}' ),
			'named-keyed object'   => array( '{"include":{"products":{"a":100}}}' ),
			'empty object kind'    => array( '{"include":{"products":{}}}' ),
			'exclude side'         => array( '{"exclude":{"tags":{"0":4}}}' ),
			'types as an object'   => array( '{"include":{"types":{"0":"simple"}}}' ),
		);
	}

	/**
	 * An UNKNOWN key may be any shape, including an object, and is still only
	 * ignored — the raw boundary tightened known shapes, not forward
	 * compatibility.
	 *
	 * @return void
	 */
	public function test_an_unknown_key_may_be_an_object() {
		$targeting = Targeting::from_value( '{"include":{"products":[100],"brands":{"a":1}},"meta":{"by":"importer"}}' );

		$this->assertTrue( $targeting->is_valid() );
		$this->assertSame( array( 'products' ), array_keys( $targeting->includes() ) );
		$this->assertTrue( $targeting->evaluate( $this->item() )['matched'] );
	}

	/**
	 * Nested objects inside a well-formed document survive conversion, so
	 * decoding without associative mode did not change what a valid document
	 * means.
	 *
	 * @return void
	 */
	public function test_a_valid_document_is_unchanged_by_the_object_decode() {
		$targeting = Targeting::from_value(
			'{"include":{"products":[100,"250"],"types":["simple"]},"exclude":{"tags":[4]},"match_all":false}'
		);

		$this->assertTrue( $targeting->is_valid() );
		$this->assertSame( array( 100, 250 ), $targeting->includes()['products'] );
		$this->assertSame( array( 'simple' ), $targeting->includes()['types'] );
		$this->assertSame( array( 4 ), $targeting->excludes()['tags'] );
		$this->assertFalse( $targeting->matches_all() );
	}

	// ---------------------------------------------------------------------
	// Id parsing: only real positive integers become ids.
	// ---------------------------------------------------------------------

	/**
	 * Junk id entries are DROPPED, never cast into real ids.
	 *
	 * `(int)` is not a validator: it turns `"1abc"` into 1, `"12x"` into 12,
	 * `true` into 1 and `1.9` into 1 — so a rule could target a product nobody
	 * ever selected. The list must come out EMPTY, not merely different.
	 *
	 * @dataProvider rejected_id_provider
	 *
	 * @param string $json  Stored targeting document.
	 * @param string $label What the entry is.
	 * @return void
	 */
	public function test_rejected_id_forms_never_become_ids( string $json, string $label ) {
		$targeting = Targeting::from_value( $json );

		$this->assertTrue( $targeting->is_valid(), $label . ': a junk ENTRY must not invalidate the document.' );
		$this->assertSame(
			array(),
			$targeting->includes(),
			$label . ' produced an id. A rule would target a product nobody selected.'
		);
		$this->assertTrue( $targeting->matches_nothing(), $label . ' left the rule targeting something.' );

		// And specifically: it did not become id 1, 12 or 100 — the values the
		// old cast would have produced from these inputs.
		foreach ( array( 1, 12, 100 ) as $id ) {
			$this->assertFalse(
				Targeting::from_value( $json )->evaluate( $this->item( array( 'product_id' => $id ) ) )['matched'],
				$label . ' matched product ' . $id . '.'
			);
		}
	}

	/**
	 * Every rejected entry form, in the `products` kind.
	 *
	 * @return array
	 */
	public function rejected_id_provider(): array {
		return array(
			'mixed alphanumeric'  => array( '{"include":{"products":["1abc"]}}', '"1abc"' ),
			'trailing letters'    => array( '{"include":{"products":["12x"]}}', '"12x"' ),
			'leading letters'     => array( '{"include":{"products":["x12"]}}', '"x12"' ),
			'boolean true'        => array( '{"include":{"products":[true]}}', 'true' ),
			'boolean false'       => array( '{"include":{"products":[false]}}', 'false' ),
			'float'               => array( '{"include":{"products":[1.9]}}', '1.9' ),
			'float string'        => array( '{"include":{"products":["1.9"]}}', '"1.9"' ),
			'negative int'        => array( '{"include":{"products":[-3]}}', '-3' ),
			'negative string'     => array( '{"include":{"products":["-3"]}}', '"-3"' ),
			'signed positive'     => array( '{"include":{"products":["+12"]}}', '"+12"' ),
			'zero'                => array( '{"include":{"products":[0]}}', '0' ),
			'zero string'         => array( '{"include":{"products":["0"]}}', '"0"' ),
			'empty string'        => array( '{"include":{"products":[""]}}', '""' ),
			'whitespace padded'   => array( '{"include":{"products":[" 12 "]}}', '" 12 "' ),
			'null'                => array( '{"include":{"products":[null]}}', 'null' ),
			'nested array'        => array( '{"include":{"products":[[12]]}}', '[12]' ),
			'nested object'       => array( '{"include":{"products":[{"id":12}]}}', '{"id":12}' ),
			'scientific notation' => array( '{"include":{"products":["1e2"]}}', '"1e2"' ),
			'hex string'          => array( '{"include":{"products":["0x64"]}}', '"0x64"' ),
			'thousands separator' => array( '{"include":{"products":["1,00"]}}', '"1,00"' ),
		);
	}

	/**
	 * The strict parser still accepts what it should, on every id kind, and
	 * still de-duplicates.
	 *
	 * @return void
	 */
	public function test_accepted_id_forms() {
		$targeting = Targeting::from_value(
			'{"include":{"products":[100,"100","0100"],"variations":[250],"categories":["7"],"tags":[4]}}'
		);

		$this->assertSame( array( 100 ), $targeting->includes()['products'], 'Digit strings and duplicates.' );
		$this->assertSame( array( 250 ), $targeting->includes()['variations'] );
		$this->assertSame( array( 7 ), $targeting->includes()['categories'] );
		$this->assertSame( array( 4 ), $targeting->includes()['tags'] );
	}

	/**
	 * A digit string beyond `PHP_INT_MAX` is REJECTED, not clamped.
	 *
	 * `(int) "999999999999999999999999999999"` is `PHP_INT_MAX` — the cast does
	 * not fail, it silently produces a DIFFERENT id. That is the same coercion
	 * shape the strict parser exists to remove, so it is refused for the same
	 * reason.
	 *
	 * @return void
	 */
	public function test_digit_strings_beyond_php_int_max_are_rejected() {
		$max  = (string) PHP_INT_MAX;
		$over = substr( $max, 0, -1 ) . ( (int) substr( $max, -1 ) + 1 );

		// The fixture really is one past the maximum, on whatever platform this
		// is, and the cast really would clamp it.
		$this->assertSame( strlen( $max ), strlen( $over ) );
		$this->assertSame( PHP_INT_MAX, (int) $over, 'The cast is expected to clamp — that is the bug being refused.' );

		$accepted = Targeting::from_value( '{"include":{"products":[' . wp_json_encode( $max ) . ']}}' );
		$this->assertTrue( $accepted->is_valid() );
		$this->assertSame( array( PHP_INT_MAX ), $accepted->includes()['products'], 'PHP_INT_MAX itself is a usable id.' );

		foreach ( array( $over, '999999999999999999999999999999' ) as $too_big ) {
			$rejected = Targeting::from_value( '{"include":{"products":[' . wp_json_encode( $too_big ) . ']}}' );

			$this->assertTrue( $rejected->is_valid(), 'An out-of-range ENTRY is dropped, not fatal.' );
			$this->assertSame( array(), $rejected->includes(), $too_big . ' produced an id.' );
			$this->assertFalse(
				$rejected->evaluate( $this->item( array( 'product_id' => PHP_INT_MAX ) ) )['matched'],
				$too_big . ' clamped to PHP_INT_MAX and matched it.'
			);
		}
	}

	/**
	 * A bare JSON NUMBER too large for an int decodes to a float and is dropped
	 * by the `is_int` gate, so the overflow is closed from both directions.
	 *
	 * @return void
	 */
	public function test_oversized_json_numbers_are_dropped_as_floats() {
		$targeting = Targeting::from_value( '{"include":{"products":[999999999999999999999999999999]}}' );

		$this->assertTrue( $targeting->is_valid() );
		$this->assertSame( array(), $targeting->includes() );
	}

	/**
	 * Leading zeros still normalise — the existing contract, unchanged by the
	 * range check.
	 *
	 * @return void
	 */
	public function test_leading_zeros_still_normalise() {
		$targeting = Targeting::from_value( '{"include":{"products":["0100","000000000000000000000000000100"]}}' );

		$this->assertSame( array( 100 ), $targeting->includes()['products'], 'Padding is not overflow.' );
	}

	/**
	 * A kind holding nothing BUT junk declares nothing at all, so the rule
	 * sorts last rather than appearing to target something.
	 *
	 * @return void
	 */
	public function test_a_kind_of_pure_junk_declares_nothing() {
		$targeting = Targeting::from_value( '{"include":{"products":["1abc",true,-1,0]}}' );

		$this->assertTrue( $targeting->is_valid() );
		$this->assertSame( array(), $targeting->includes() );
		$this->assertSame( Specificity::NONE, $targeting->declared_specificity() );
	}

	/**
	 * Junk ENTRIES inside a well-formed kind are dropped, not fatal: a
	 * wrong-shaped container means the author's intent is unknowable, one bad
	 * id in a list plainly is not.
	 *
	 * @return void
	 */
	public function test_junk_entries_are_dropped_not_fatal() {
		$targeting = Targeting::from_value( '{"include":{"products":[100,0,-3,"12",null,{"a":1}],"types":["simple",7,null]}}' );

		$this->assertTrue( $targeting->is_valid() );
		$this->assertSame( array( 100, 12 ), $targeting->includes()['products'] );
		$this->assertSame( array( 'simple' ), $targeting->includes()['types'] );
		$this->assertTrue( $targeting->evaluate( $this->item() )['matched'] );
	}

	/**
	 * Unknown keys are ignored at the root and inside include/exclude, so a
	 * document written by a newer version degrades rather than failing shut.
	 *
	 * @return void
	 */
	public function test_unknown_keys_are_ignored() {
		$targeting = Targeting::from_value( '{"version":9,"include":{"products":[100],"brands":[5]},"exclude":{"authors":[2]}}' );

		$this->assertTrue( $targeting->is_valid() );
		$this->assertTrue( $targeting->evaluate( $this->item() )['matched'] );
		$this->assertSame( array( 'products' ), array_keys( $targeting->includes() ) );
	}

	// ---------------------------------------------------------------------
	// Types: slugs and flags.
	// ---------------------------------------------------------------------

	/**
	 * A `types` entry matches when the product's type slug equals it.
	 *
	 * @return void
	 */
	public function test_type_slug_matching() {
		$targeting = Targeting::from_array( array( 'include' => array( 'types' => array( 'grouped', 'external' ) ) ) );

		$this->assertTrue( $targeting->evaluate( $this->item( array( 'type' => 'external' ) ) )['matched'] );
		$this->assertFalse( $targeting->evaluate( $this->item( array( 'type' => 'simple' ) ) )['matched'] );
	}

	/**
	 * A custom product type registered by another plugin matches: the core
	 * list is documentation, not a closed allowlist.
	 *
	 * @return void
	 */
	public function test_custom_product_type_matching() {
		$targeting = Targeting::from_array( array( 'include' => array( 'types' => array( 'subscription' ) ) ) );

		$this->assertTrue( $targeting->evaluate( $this->item( array( 'type' => 'subscription' ) ) )['matched'] );
	}

	/**
	 * A `types` entry also matches when it names a boolean FLAG that is true
	 * on the product. `virtual` and `downloadable` are values
	 * WC_Product::get_type() can never return.
	 *
	 * @dataProvider flag_provider
	 *
	 * @param string $flag Flag name.
	 * @return void
	 */
	public function test_flag_matching( string $flag ) {
		$targeting = Targeting::from_array( array( 'include' => array( 'types' => array( $flag ) ) ) );

		$this->assertTrue( $targeting->evaluate( $this->item( array( $flag => true ) ) )['matched'] );
		$this->assertFalse( $targeting->evaluate( $this->item( array( $flag => false ) ) )['matched'] );

		// The flag is not the product's type slug, and must not be compared as
		// one: a product whose TYPE happened to be 'virtual' is not the case
		// being tested here, a product carrying the FLAG is.
		$this->assertSame( 'simple', $this->item( array( $flag => true ) )['type'] );
	}

	/**
	 * The two flag entries.
	 *
	 * @return array
	 */
	public function flag_provider(): array {
		return array(
			'virtual'      => array( 'virtual' ),
			'downloadable' => array( 'downloadable' ),
		);
	}

	/**
	 * Both flags on one product each match independently.
	 *
	 * @return void
	 */
	public function test_both_flags_on_one_product() {
		$item = $this->item(
			array(
				'virtual'      => true,
				'downloadable' => true,
			)
		);

		$this->assertTrue( Targeting::from_array( array( 'include' => array( 'types' => array( 'virtual' ) ) ) )->evaluate( $item )['matched'] );
		$this->assertTrue( Targeting::from_array( array( 'include' => array( 'types' => array( 'downloadable' ) ) ) )->evaluate( $item )['matched'] );
	}

	/**
	 * A variation is matched against its PARENT's type slug as well as its
	 * own, or `types: ["variable"]` would match no line item anybody buys.
	 *
	 * @return void
	 */
	public function test_variation_matches_the_parent_type_slug() {
		$item = $this->variation_item();

		$this->assertTrue( Targeting::from_array( array( 'include' => array( 'types' => array( 'variable' ) ) ) )->evaluate( $item )['matched'] );
		$this->assertTrue( Targeting::from_array( array( 'include' => array( 'types' => array( 'variation' ) ) ) )->evaluate( $item )['matched'] );
		$this->assertFalse( Targeting::from_array( array( 'include' => array( 'types' => array( 'simple' ) ) ) )->evaluate( $item )['matched'] );
	}

	// ---------------------------------------------------------------------
	// Ids: the two lists are compared against the two ids the item carries.
	// ---------------------------------------------------------------------

	/**
	 * A parent-targeted rule matches a variation at PRODUCT specificity, and a
	 * variation-targeted rule matches only that variation.
	 *
	 * @return void
	 */
	public function test_variation_fallback_to_the_parent_product() {
		$item = $this->variation_item();

		$parent_rule = Targeting::from_array( array( 'include' => array( 'products' => array( 100 ) ) ) )->evaluate( $item );
		$this->assertTrue( $parent_rule['matched'] );
		$this->assertSame( Specificity::PRODUCT, $parent_rule['level'] );

		$variation_rule = Targeting::from_array( array( 'include' => array( 'variations' => array( 250 ) ) ) )->evaluate( $item );
		$this->assertSame( Specificity::VARIATION, $variation_rule['level'] );

		$other_variation = Targeting::from_array( array( 'include' => array( 'variations' => array( 251 ) ) ) )->evaluate( $item );
		$this->assertFalse( $other_variation['matched'] );
	}

	/**
	 * A variation id listed under `products` matches nothing, and a parent id
	 * listed under `variations` matches nothing.
	 *
	 * @return void
	 */
	public function test_the_two_id_lists_are_not_interchangeable() {
		$item = $this->variation_item();

		$this->assertFalse( Targeting::from_array( array( 'include' => array( 'products' => array( 250 ) ) ) )->evaluate( $item )['matched'] );
		$this->assertFalse( Targeting::from_array( array( 'include' => array( 'variations' => array( 100 ) ) ) )->evaluate( $item )['matched'] );
	}

	/**
	 * A simple product carries variation_id 0, which never matches a
	 * variations list however that list is written.
	 *
	 * @return void
	 */
	public function test_zero_variation_id_never_matches() {
		$targeting = Targeting::from_value( '{"include":{"variations":[0]}}' );

		$this->assertTrue( $targeting->is_valid() );
		$this->assertTrue( $targeting->matches_nothing(), 'A list of only invalid ids declares nothing.' );
		$this->assertFalse( $targeting->evaluate( $this->item() )['matched'] );
	}

	/**
	 * A product in several categories matches a rule naming any one of them.
	 *
	 * @return void
	 */
	public function test_a_product_in_several_categories() {
		$item = $this->item( array( 'category_ids' => array( 7, 9, 11 ) ) );

		$this->assertTrue( Targeting::from_array( array( 'include' => array( 'categories' => array( 9 ) ) ) )->evaluate( $item )['matched'] );
		$this->assertTrue( Targeting::from_array( array( 'include' => array( 'categories' => array( 11, 99 ) ) ) )->evaluate( $item )['matched'] );
		$this->assertFalse( Targeting::from_array( array( 'include' => array( 'categories' => array( 99 ) ) ) )->evaluate( $item )['matched'] );
	}

	// ---------------------------------------------------------------------
	// Declared specificity.
	// ---------------------------------------------------------------------

	/**
	 * Declared specificity is the highest rung present in the DECLARATION.
	 *
	 * @dataProvider declared_specificity_provider
	 *
	 * @param array $document Targeting document.
	 * @param int   $expected Expected level.
	 * @return void
	 */
	public function test_declared_specificity( array $document, int $expected ) {
		$this->assertSame( $expected, Targeting::from_array( $document )->declared_specificity() );
	}

	/**
	 * Every combination that changes the answer.
	 *
	 * @return array
	 */
	public function declared_specificity_provider(): array {
		return array(
			'nothing'                => array( array(), Specificity::NONE ),
			'match_all only'         => array( array( 'match_all' => true ), Specificity::MATCH_ALL ),
			'tags only'              => array( array( 'include' => array( 'tags' => array( 1 ) ) ), Specificity::TAG ),
			'types only'             => array( array( 'include' => array( 'types' => array( 'simple' ) ) ), Specificity::TYPE ),
			'categories only'        => array( array( 'include' => array( 'categories' => array( 1 ) ) ), Specificity::CATEGORY ),
			'products only'          => array( array( 'include' => array( 'products' => array( 1 ) ) ), Specificity::PRODUCT ),
			'variations only'        => array( array( 'include' => array( 'variations' => array( 1 ) ) ), Specificity::VARIATION ),
			'tags and categories'    => array(
				array(
					'include' => array(
						'tags'       => array( 1 ),
						'categories' => array( 2 ),
					),
				),
				Specificity::CATEGORY,
			),
			'products and tags'      => array(
				array(
					'include' => array(
						'products' => array( 1 ),
						'tags'     => array( 2 ),
					),
				),
				Specificity::PRODUCT,
			),
			'variations and the lot' => array(
				array(
					'include' => array(
						'variations' => array( 1 ),
						'products'   => array( 2 ),
						'categories' => array( 3 ),
						'tags'       => array( 4 ),
						'types'      => array( 'simple' ),
					),
				),
				Specificity::VARIATION,
			),
			'match_all and products' => array(
				array(
					'match_all' => true,
					'include'   => array( 'products' => array( 1 ) ),
				),
				Specificity::PRODUCT,
			),
			'excludes do not count'  => array( array( 'exclude' => array( 'variations' => array( 1 ) ) ), Specificity::NONE ),
			'empty lists'            => array( array( 'include' => array( 'products' => array() ) ), Specificity::NONE ),
		);
	}

	/**
	 * Declared specificity is computed from the DECLARATION, so it is the same
	 * whatever order is under evaluation — including an order the rule matches
	 * at a lower rung than it declared.
	 *
	 * @return void
	 */
	public function test_declared_specificity_is_independent_of_the_match() {
		$targeting = Targeting::from_array(
			array(
				'include' => array(
					'variations' => array( 250 ),
					'tags'       => array( 4 ),
				),
			)
		);

		$this->assertSame( Specificity::VARIATION, $targeting->declared_specificity() );

		// This item matches only by tag, so the ITEM records rung 1 while the
		// RULE keeps its declared rung 4 for ordering.
		$by_tag = $targeting->evaluate( $this->item( array( 'tag_ids' => array( 4 ) ) ) );
		$this->assertSame( Specificity::TAG, $by_tag['level'] );
		$this->assertSame( Specificity::VARIATION, $targeting->declared_specificity() );
	}

	// ---------------------------------------------------------------------
	// The three resolution states (ADR-0011 §4).
	// ---------------------------------------------------------------------

	/**
	 * A PARTIALLY RESOLVED item — variation deleted, parent alive — still
	 * matches on identity: product id, variation id, category and tag.
	 *
	 * @dataProvider identity_kind_provider
	 *
	 * @param array $include Include document.
	 * @param array $item    Item descriptor.
	 * @return void
	 */
	public function test_a_partially_resolved_item_still_matches_on_identity( array $include, array $item ) {
		$this->assertTrue( Targeting::from_array( array( 'include' => $include ) )->evaluate( $item )['matched'] );
	}

	/**
	 * The identity kinds, evaluated against a partially resolved descriptor.
	 *
	 * @return array
	 */
	public function identity_kind_provider(): array {
		$degraded = $this->partially_resolved_item();

		return array(
			'products'   => array( array( 'products' => array( 100 ) ), $degraded ),
			'variations' => array( array( 'variations' => array( 250 ) ), $degraded ),
			'categories' => array( array( 'categories' => array( 7 ) ), $degraded ),
			'tags'       => array( array( 'tags' => array( 4 ) ), $degraded ),
		);
	}

	/**
	 * `match_all` reaches a partially resolved item too: it is still an item the
	 * customer bought.
	 *
	 * @return void
	 */
	public function test_match_all_still_reaches_a_partially_resolved_item() {
		$outcome = Targeting::from_array( array( 'match_all' => true ) )->evaluate( $this->partially_resolved_item() );

		$this->assertTrue( $outcome['matched'] );
		$this->assertSame( 'match_all', $outcome['kind'] );
	}

	/**
	 * A `types` entry can NEVER match a partially resolved item — not the
	 * parent's slug, not the flags, not `variation` itself.
	 *
	 * Unknown is not false, and it is certainly not the parent's value: a
	 * variation may be virtual or downloadable when its parent is not, and
	 * `WC_Product_Variation::get_type()` returns `variation`, never the parent's
	 * slug. Inheriting either would let a `types` rule match on facts that never
	 * applied to what the customer actually bought.
	 *
	 * @dataProvider unknown_type_provider
	 *
	 * @param string $entry `types` entry.
	 * @return void
	 */
	public function test_types_never_match_a_partially_resolved_item( string $entry ) {
		$targeting = Targeting::from_array( array( 'include' => array( 'types' => array( $entry ) ) ) );

		$this->assertFalse(
			$targeting->evaluate( $this->partially_resolved_item() )['matched'],
			'types: ["' . $entry . '"] matched an item whose type is unknowable.'
		);

		// The same entry DOES match the same item once it resolves, so the
		// refusal above is the resolution state and not a broken fixture.
		$resolved = $this->variation_item(
			array(
				'virtual'      => true,
				'downloadable' => true,
				'category_ids' => array( 7 ),
				'tag_ids'      => array( 4 ),
			)
		);
		$this->assertTrue( $targeting->evaluate( $resolved )['matched'] );
	}

	/**
	 * Every `types` spelling that the parent's facts would have satisfied.
	 *
	 * @return array
	 */
	public function unknown_type_provider(): array {
		return array(
			'parent type slug' => array( 'variable' ),
			'own type slug'    => array( 'variation' ),
			'virtual flag'     => array( 'virtual' ),
			'downloadable'     => array( 'downloadable' ),
		);
	}

	/**
	 * The refusal is scoped to the include side's `types` and does not leak into
	 * exclusion: an exclude `types` entry cannot silently remove the item
	 * either, because the same "unknown is not a match" rule applies.
	 *
	 * @return void
	 */
	public function test_a_partially_resolved_item_is_not_excluded_by_type() {
		$outcome = Targeting::from_array(
			array(
				'include' => array( 'products' => array( 100 ) ),
				'exclude' => array( 'types' => array( 'variable' ) ),
			)
		)->evaluate( $this->partially_resolved_item() );

		$this->assertTrue( $outcome['included'] );
		$this->assertFalse( $outcome['excluded'] );
		$this->assertTrue( $outcome['matched'] );
	}

	/**
	 * An absent `resolution` key means RESOLVED, so every descriptor written
	 * before the third state existed keeps its meaning.
	 *
	 * @return void
	 */
	public function test_an_absent_resolution_key_means_resolved() {
		$item = $this->item();
		$this->assertArrayNotHasKey( 'resolution', $item );

		$this->assertTrue( Targeting::from_array( array( 'include' => array( 'types' => array( 'simple' ) ) ) )->evaluate( $item )['matched'] );
	}

	/**
	 * A descriptor for a deleted variation whose parent survives: ids and terms
	 * are knowable, type slugs and flags are not.
	 *
	 * @return array
	 */
	private function partially_resolved_item(): array {
		return $this->item(
			array(
				'item_id'      => 2,
				'variation_id' => 250,
				'resolution'   => Targeting::PARTIALLY_RESOLVED,
				'type'         => '',
				'parent_type'  => '',
				'virtual'      => null,
				'downloadable' => null,
				'category_ids' => array( 7 ),
				'tag_ids'      => array( 4 ),
			)
		);
	}
}
