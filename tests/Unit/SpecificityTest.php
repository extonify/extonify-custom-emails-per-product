<?php
/**
 * The specificity ladder and the rule ordering comparator (ADR-0011 §5, §6).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Extonify\WCEP\Domain\PreparedRule;
use Extonify\WCEP\Domain\Specificity;
use Extonify\WCEP\Domain\Targeting;

/**
 * Ordering has to be TOTAL and deterministic, or the same rules on the same
 * order could fire in a different sequence between two requests and the
 * `stop_processing` halt would land somewhere else each time.
 */
final class SpecificityTest extends UnitTestCase {

	/**
	 * The ladder is exactly the five rungs ADR-0011 fixes, in that order.
	 *
	 * @return void
	 */
	public function test_the_ladder() {
		$this->assertSame( 4, Specificity::VARIATION );
		$this->assertSame( 3, Specificity::PRODUCT );
		$this->assertSame( 2, Specificity::CATEGORY );
		$this->assertSame( 1, Specificity::TAG );
		$this->assertSame( 0, Specificity::MATCH_ALL );
		$this->assertSame( -1, Specificity::NONE );

		$this->assertTrue( Specificity::VARIATION > Specificity::PRODUCT );
		$this->assertTrue( Specificity::PRODUCT > Specificity::CATEGORY );
		$this->assertTrue( Specificity::CATEGORY > Specificity::TAG );
		$this->assertTrue( Specificity::TAG > Specificity::MATCH_ALL );
	}

	/**
	 * `type` shares rung 1 with `tag`: it must rank above `match_all`, because
	 * selecting "every downloadable product" is a deliberate targeting act and
	 * not the absence of targeting, and it cannot rank above `tag`, because
	 * every product in the catalogue carries a type.
	 *
	 * @return void
	 */
	public function test_type_shares_the_tag_rung() {
		$this->assertSame( Specificity::TAG, Specificity::TYPE );
		$this->assertTrue( Specificity::TYPE > Specificity::MATCH_ALL );
		$this->assertTrue( Specificity::CATEGORY > Specificity::TYPE );
	}

	/**
	 * Every kind places on the ladder, and an unknown kind does not.
	 *
	 * @dataProvider kind_level_provider
	 *
	 * @param string $kind  Kind name.
	 * @param int    $level Expected level.
	 * @return void
	 */
	public function test_kind_level( string $kind, int $level ) {
		$this->assertSame( $level, Specificity::kind_level( $kind ) );
	}

	/**
	 * Kind => level, including the unknown case.
	 *
	 * @return array
	 */
	public function kind_level_provider(): array {
		return array(
			'variation' => array( 'variation', 4 ),
			'product'   => array( 'product', 3 ),
			'category'  => array( 'category', 2 ),
			'tag'       => array( 'tag', 1 ),
			'type'      => array( 'type', 1 ),
			'match_all' => array( 'match_all', 0 ),
			'unknown'   => array( 'brand', -1 ),
		);
	}

	/**
	 * Rung names. `type` is a KIND, not a rung, so level 1 is named `tag`.
	 *
	 * @return void
	 */
	public function test_labels() {
		$this->assertSame( 'variation', Specificity::label( Specificity::VARIATION ) );
		$this->assertSame( 'product', Specificity::label( Specificity::PRODUCT ) );
		$this->assertSame( 'category', Specificity::label( Specificity::CATEGORY ) );
		$this->assertSame( 'tag', Specificity::label( Specificity::TAG ) );
		$this->assertSame( 'match_all', Specificity::label( Specificity::MATCH_ALL ) );
		$this->assertSame( 'none', Specificity::label( Specificity::NONE ) );

		$this->assertTrue( Specificity::is_level( Specificity::MATCH_ALL ) );
		$this->assertFalse( Specificity::is_level( Specificity::NONE ) );
	}

	/**
	 * The strongest of two levels.
	 *
	 * @return void
	 */
	public function test_highest() {
		$this->assertSame( Specificity::PRODUCT, Specificity::highest( Specificity::TAG, Specificity::PRODUCT ) );
		$this->assertSame( Specificity::PRODUCT, Specificity::highest( Specificity::PRODUCT, Specificity::TAG ) );
		$this->assertSame( Specificity::MATCH_ALL, Specificity::highest( Specificity::NONE, Specificity::MATCH_ALL ) );
	}

	/**
	 * A rule row carrying a REAL targeting document that declares the wanted
	 * specificity.
	 *
	 * The helper used to set `declared_specificity` directly. That is exactly
	 * the hole Prompt 3c closed: ordering must be derived from the declaration
	 * and never supplied by the row, or a caller handing back a previously
	 * sorted array controls evaluation order. Building the document instead
	 * makes these tests prove the comparator AND the derivation.
	 *
	 * @param int $id          Rule id.
	 * @param int $priority    Rule priority.
	 * @param int $specificity Specificity the targeting document must declare.
	 * @return array
	 */
	private function rule( int $id, int $priority, int $specificity ): array {
		return array(
			'id'        => $id,
			'priority'  => $priority,
			'targeting' => $this->targeting_declaring( $specificity ),
		);
	}

	/**
	 * A raw targeting document whose declared specificity is the given rung.
	 *
	 * @param int $specificity Ladder level.
	 * @return string Raw JSON, as the column stores it.
	 */
	private function targeting_declaring( int $specificity ): string {
		switch ( $specificity ) {
			case Specificity::VARIATION:
				return '{"include":{"variations":[9]}}';
			case Specificity::PRODUCT:
				return '{"include":{"products":[9]}}';
			case Specificity::CATEGORY:
				return '{"include":{"categories":[9]}}';
			case Specificity::TAG:
				return '{"include":{"tags":[9]}}';
			case Specificity::MATCH_ALL:
				return '{"match_all":true}';
			default:
				return '{}';
		}
	}

	/**
	 * The helper really does declare what it claims, so every ordering
	 * assertion below is about the comparator and not about a broken fixture.
	 *
	 * @return void
	 */
	public function test_the_fixture_declares_the_specificity_it_claims() {
		foreach (
			array(
				Specificity::VARIATION,
				Specificity::PRODUCT,
				Specificity::CATEGORY,
				Specificity::TAG,
				Specificity::MATCH_ALL,
				Specificity::NONE,
			) as $level
		) {
			$this->assertSame(
				$level,
				Targeting::from_value( $this->targeting_declaring( $level ) )->declared_specificity()
			);
		}
	}

	/**
	 * Priority runs first, and LOWER runs first — the WordPress hook
	 * convention.
	 *
	 * @return void
	 */
	public function test_priority_ascending_beats_specificity() {
		$ordered = Specificity::sort_rules(
			array(
				$this->rule( 1, 20, Specificity::VARIATION ),
				$this->rule( 2, 5, Specificity::MATCH_ALL ),
			)
		);

		$this->assertSame( array( 2, 1 ), array_column( $ordered, 'id' ) );
	}

	/**
	 * Equal priorities are resolved by declared specificity, descending.
	 *
	 * @return void
	 */
	public function test_equal_priority_resolved_by_specificity_descending() {
		$ordered = Specificity::sort_rules(
			array(
				$this->rule( 1, 10, Specificity::TAG ),
				$this->rule( 2, 10, Specificity::VARIATION ),
				$this->rule( 3, 10, Specificity::CATEGORY ),
				$this->rule( 4, 10, Specificity::MATCH_ALL ),
				$this->rule( 5, 10, Specificity::PRODUCT ),
			)
		);

		$this->assertSame( array( 2, 5, 3, 1, 4 ), array_column( $ordered, 'id' ) );
	}

	/**
	 * Equal priority AND equal specificity are resolved by id, ascending.
	 *
	 * @return void
	 */
	public function test_equal_priority_and_specificity_resolved_by_id() {
		$ordered = Specificity::sort_rules(
			array(
				$this->rule( 9, 10, Specificity::PRODUCT ),
				$this->rule( 3, 10, Specificity::PRODUCT ),
				$this->rule( 7, 10, Specificity::PRODUCT ),
			)
		);

		$this->assertSame( array( 3, 7, 9 ), array_column( $ordered, 'id' ) );
	}

	/**
	 * Both tie-breakers at once, across several priorities.
	 *
	 * @return void
	 */
	public function test_full_comparator() {
		$ordered = Specificity::sort_rules(
			array(
				$this->rule( 4, 10, Specificity::PRODUCT ),
				$this->rule( 1, 20, Specificity::VARIATION ),
				$this->rule( 2, 10, Specificity::VARIATION ),
				$this->rule( 5, 5, Specificity::NONE ),
				$this->rule( 3, 10, Specificity::PRODUCT ),
				$this->rule( 6, 20, Specificity::TAG ),
			)
		);

		$this->assertSame( array( 5, 2, 3, 4, 1, 6 ), array_column( $ordered, 'id' ) );
	}

	/**
	 * A rule that declares nothing sorts last within its priority, but is
	 * still evaluated.
	 *
	 * @return void
	 */
	public function test_undeclared_rules_sort_last_within_their_priority() {
		$ordered = Specificity::sort_rules(
			array(
				$this->rule( 1, 10, Specificity::NONE ),
				$this->rule( 2, 10, Specificity::MATCH_ALL ),
			)
		);

		$this->assertSame( array( 2, 1 ), array_column( $ordered, 'id' ) );
	}

	/**
	 * The order is TOTAL: the same set shuffled 40 different ways sorts to
	 * exactly the same sequence every time.
	 *
	 * @return void
	 */
	public function test_determinism_across_shuffled_input() {
		$rules = array(
			$this->rule( 1, 10, Specificity::PRODUCT ),
			$this->rule( 2, 10, Specificity::PRODUCT ),
			$this->rule( 3, 10, Specificity::VARIATION ),
			$this->rule( 4, 5, Specificity::TAG ),
			$this->rule( 5, 20, Specificity::MATCH_ALL ),
			$this->rule( 6, 10, Specificity::NONE ),
			$this->rule( 7, 5, Specificity::TAG ),
		);

		$expected = array_column( Specificity::sort_rules( $rules ), 'id' );
		$this->assertSame( array( 4, 7, 3, 1, 2, 6, 5 ), $expected );

		for ( $run = 0; $run < 40; $run++ ) {
			$shuffled = $rules;
			shuffle( $shuffled );
			$this->assertSame( $expected, array_column( Specificity::sort_rules( $shuffled ), 'id' ), 'Ordering depended on input order.' );
		}
	}

	/**
	 * Declared specificity is derived from the rule's targeting document when
	 * it has not already been annotated, and the annotation is written back so
	 * the caller can read what it was ordered by.
	 *
	 * @return void
	 */
	public function test_declared_specificity_is_derived_from_targeting() {
		$ordered = Specificity::sort_rules(
			array(
				array(
					'id'        => 1,
					'priority'  => 10,
					'targeting' => array( 'include' => array( 'tags' => array( 3 ) ) ),
				),
				array(
					'id'        => 2,
					'priority'  => 10,
					'targeting' => '{"include":{"variations":[9]}}',
				),
				array(
					'id'        => 3,
					'priority'  => 10,
					'targeting' => '{"include":{"products":[', // Invalid: sorts last.
				),
			)
		);

		$this->assertSame( array( 2, 1, 3 ), array_column( $ordered, 'id' ) );
		$this->assertSame( Specificity::VARIATION, $ordered[0]['declared_specificity'] );
		$this->assertSame( Specificity::TAG, $ordered[1]['declared_specificity'] );
		$this->assertSame( Specificity::NONE, $ordered[2]['declared_specificity'] );
	}

	/**
	 * A missing priority or id is treated as 0 rather than throwing, so a
	 * hand-built rule set from a test or a future importer still orders.
	 *
	 * @return void
	 */
	public function test_missing_fields_default_to_zero() {
		$ordered = Specificity::sort_rules(
			array(
				array( 'id' => 2 ),
				array( 'id' => 1 ),
			)
		);

		$this->assertSame( array( 1, 2 ), array_column( $ordered, 'id' ) );
	}

	// ---------------------------------------------------------------------
	// Ordering is never externally supplied (ADR-0011 §5).
	// ---------------------------------------------------------------------

	/**
	 * A row carrying a hand-set `declared_specificity` that DISAGREES with its
	 * targeting is ordered by the RECOMPUTED value.
	 *
	 * `sort_rules()` writes that key itself, so a caller handing a previously
	 * sorted array back — or a hand-built row carrying it — could otherwise
	 * dictate evaluation order directly, bypassing the declaration it is meant
	 * to be derived from.
	 *
	 * @return void
	 */
	public function test_a_supplied_declared_specificity_cannot_dictate_order() {
		$ordered = Specificity::sort_rules(
			array(
				// Claims the top rung; actually declares a tag.
				array(
					'id'                   => 1,
					'priority'             => 10,
					'targeting'            => '{"include":{"tags":[9]}}',
					'declared_specificity' => Specificity::VARIATION,
				),
				// Claims nothing; actually declares a variation.
				array(
					'id'                   => 2,
					'priority'             => 10,
					'targeting'            => '{"include":{"variations":[9]}}',
					'declared_specificity' => Specificity::NONE,
				),
			)
		);

		$this->assertSame( array( 2, 1 ), array_column( $ordered, 'id' ), 'The supplied annotation controlled ordering.' );
		$this->assertSame( Specificity::VARIATION, $ordered[0]['declared_specificity'] );
		$this->assertSame( Specificity::TAG, $ordered[1]['declared_specificity'], 'The annotation was not overwritten with the truth.' );
	}

	/**
	 * Re-sorting an already-sorted (and therefore annotated) set is a no-op, so
	 * the output annotation can never feed back into a later ordering.
	 *
	 * @return void
	 */
	public function test_re_sorting_an_annotated_set_is_idempotent() {
		$rules = array(
			$this->rule( 1, 10, Specificity::TAG ),
			$this->rule( 2, 10, Specificity::VARIATION ),
			$this->rule( 3, 10, Specificity::NONE ),
		);

		$once  = Specificity::sort_rules( $rules );
		$twice = Specificity::sort_rules( $once );

		$this->assertSame( array_column( $once, 'id' ), array_column( $twice, 'id' ) );
		$this->assertSame( $once, $twice );
	}

	// ---------------------------------------------------------------------
	// One parse, from the authoritative representation (ADR-0011 §5).
	// ---------------------------------------------------------------------

	/**
	 * When a hydrated row carries the RAW passenger, that is what is parsed —
	 * for ordering AND for evaluation, because they share one instance.
	 *
	 * `{"include":{"products":{"0":1}}}` is valid as a decoded PHP array (a
	 * kind that happens to be an array of one id) and INVALID as raw text (a
	 * known kind written as a JSON object). Reading different representations
	 * in the two steps is what let such a rule sort at PRODUCT specificity
	 * while its decision was `targeting_invalid`.
	 *
	 * @return void
	 */
	public function test_the_raw_passenger_is_authoritative() {
		$prepared = PreparedRule::from_row(
			array(
				'id'            => 1,
				'priority'      => 10,
				'targeting'     => array( 'include' => array( 'products' => array( 1 ) ) ),
				'targeting_raw' => '{"include":{"products":{"0":1}}}',
			)
		);

		$this->assertFalse( $prepared->targeting()->is_valid(), 'The raw string is authoritative and it is invalid.' );
		$this->assertSame(
			Specificity::NONE,
			$prepared->declared_specificity(),
			'An invalid document declares nothing and must sort last (ADR-0011 §5).'
		);
	}

	/**
	 * With no passenger present, the decoded array is used — the only case in
	 * which it is authoritative.
	 *
	 * @return void
	 */
	public function test_the_decoded_array_is_used_when_no_passenger_exists() {
		$prepared = PreparedRule::from_row(
			array(
				'id'        => 1,
				'targeting' => array( 'include' => array( 'products' => array( 1 ) ) ),
			)
		);

		$this->assertTrue( $prepared->targeting()->is_valid() );
		$this->assertSame( Specificity::PRODUCT, $prepared->declared_specificity() );
	}

	/**
	 * A NULL passenger — what `hydrate()` writes for an empty column — is still
	 * the authoritative answer, and means an empty document rather than "fall
	 * back to the array".
	 *
	 * @return void
	 */
	public function test_a_null_passenger_is_still_authoritative() {
		$prepared = PreparedRule::from_row(
			array(
				'id'            => 1,
				'targeting'     => array( 'include' => array( 'products' => array( 1 ) ) ),
				'targeting_raw' => null,
			)
		);

		$this->assertTrue( $prepared->targeting()->is_valid() );
		$this->assertSame( Specificity::NONE, $prepared->declared_specificity() );
	}

	/**
	 * Ordering and evaluation are handed the SAME `Targeting` instance, so they
	 * cannot disagree about a rule however the document is written.
	 *
	 * @return void
	 */
	public function test_ordering_and_evaluation_share_one_targeting_instance() {
		$prepared = PreparedRule::from_row(
			array(
				'id'            => 1,
				'targeting_raw' => '{"include":{"variations":[9]}}',
			)
		);

		$this->assertSame( $prepared->targeting(), $prepared->targeting() );
		$this->assertSame( $prepared->targeting()->declared_specificity(), $prepared->declared_specificity() );
	}

	/**
	 * The prepared rule exposes the row's evaluation-relevant flags without the
	 * matcher reaching back into the array.
	 *
	 * @return void
	 */
	public function test_prepared_rule_exposes_row_state() {
		$active = PreparedRule::from_row(
			array(
				'id'       => 5,
				'priority' => 7,
			)
		);
		$this->assertSame( 5, $active->id() );
		$this->assertSame( 7, $active->priority() );
		$this->assertTrue( $active->is_active(), 'A row with no status is treated as active.' );
		$this->assertFalse( $active->stops_processing() );

		$halting = PreparedRule::from_row(
			array(
				'id'              => 6,
				'status'          => 'inactive',
				'stop_processing' => 1,
			)
		);
		$this->assertFalse( $halting->is_active() );
		$this->assertTrue( $halting->stops_processing() );

		// The row itself is carried through unmodified.
		$this->assertSame(
			array(
				'id'       => 5,
				'priority' => 7,
			),
			$active->row()
		);
	}
}
