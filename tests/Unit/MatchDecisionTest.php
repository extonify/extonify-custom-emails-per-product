<?php
/**
 * Decision records and the ADR-0005 loggability table (ADR-0011 §7).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Extonify\WCEP\Domain\EvaluationResult;
use Extonify\WCEP\Domain\MatchDecision;
use Extonify\WCEP\Domain\Specificity;
use Extonify\WCEP\Domain\TriggerEvent;

/**
 * `loggable` is the reason the engine returns reason codes instead of a
 * boolean, so the table itself is asserted here rather than left implicit.
 */
final class MatchDecisionTest extends UnitTestCase {

	/**
	 * Exactly one reason is silent, and it is the noise floor ADR-0005 names.
	 *
	 * @dataProvider loggable_provider
	 *
	 * @param string $reason   Reason code.
	 * @param bool   $loggable Expected loggability.
	 * @return void
	 */
	public function test_loggability( string $reason, bool $loggable ) {
		$this->assertSame( $loggable, MatchDecision::create( 1, $reason, 'status:completed' )->loggable() );
	}

	/**
	 * The full ADR-0011 §7 reason-code table.
	 *
	 * @return array
	 */
	public function loggable_provider(): array {
		return array(
			'matched'               => array( MatchDecision::MATCHED, true ),
			'no_targeting_match'    => array( MatchDecision::NO_TARGETING_MATCH, false ),
			'excluded_by_rule'      => array( MatchDecision::EXCLUDED_BY_RULE, true ),
			'blocked_by_stop_flag'  => array( MatchDecision::BLOCKED_BY_STOP_FLAG, true ),
			'rule_disabled'         => array( MatchDecision::RULE_DISABLED, true ),
			'targeting_invalid'     => array( MatchDecision::TARGETING_INVALID, true ),
			'no_items_deferred'     => array( MatchDecision::NO_ITEMS_DEFERRED, true ),
			'product_unavailable'   => array( MatchDecision::PRODUCT_UNAVAILABLE, true ),
			// The degradation note must be LOGGABLE, or the delivery log cannot
			// explain why a `types` rule stopped firing for an item whose
			// variation was deleted (ADR-0011 §4).
			'variation_unavailable' => array( MatchDecision::VARIATION_UNAVAILABLE, true ),
		);
	}

	/**
	 * The table covers every reason code the class defines — a new code cannot
	 * be added without deciding whether it is logged.
	 *
	 * @return void
	 */
	public function test_every_reason_code_has_a_loggability() {
		$codes = array(
			MatchDecision::MATCHED,
			MatchDecision::NO_TARGETING_MATCH,
			MatchDecision::EXCLUDED_BY_RULE,
			MatchDecision::BLOCKED_BY_STOP_FLAG,
			MatchDecision::RULE_DISABLED,
			MatchDecision::TARGETING_INVALID,
			MatchDecision::NO_ITEMS_DEFERRED,
			MatchDecision::PRODUCT_UNAVAILABLE,
			MatchDecision::VARIATION_UNAVAILABLE,
		);

		$this->assertSame( $codes, array_keys( MatchDecision::LOGGABLE ) );
		$this->assertCount( 9, MatchDecision::LOGGABLE );

		// The two item-level notes are DISTINCT codes. Collapsing them would
		// leave the log unable to say whether the item was skipped or merely
		// degraded.
		$this->assertNotSame( MatchDecision::PRODUCT_UNAVAILABLE, MatchDecision::VARIATION_UNAVAILABLE );
	}

	/**
	 * Only `matched` reports matched(), and only it carries items.
	 *
	 * @return void
	 */
	public function test_only_matched_carries_items() {
		$items = array(
			array(
				'item_id'      => 5,
				'product_id'   => 100,
				'variation_id' => 0,
				'level'        => Specificity::PRODUCT,
				'kind'         => 'product',
			),
		);

		$matched = MatchDecision::create( 1, MatchDecision::MATCHED, 'status:completed', $items );
		$this->assertTrue( $matched->matched() );
		$this->assertSame( array( 5 ), $matched->matched_item_ids() );

		// Items supplied with a non-matching reason are discarded, so a decision
		// can never claim to have matched items while reporting it did not.
		$excluded = MatchDecision::create( 1, MatchDecision::EXCLUDED_BY_RULE, 'status:completed', $items );
		$this->assertFalse( $excluded->matched() );
		$this->assertSame( array(), $excluded->matched_item_ids() );
		$this->assertSame( Specificity::NONE, $excluded->specificity() );
	}

	/**
	 * Two line items of the same product yield TWO item ids and ONE product id
	 * (ADR-0011 §4). The engine returns both; consolidation is a delivery
	 * decision.
	 *
	 * @return void
	 */
	public function test_item_ids_are_not_deduplicated_but_product_ids_are() {
		$decision = MatchDecision::create(
			1,
			MatchDecision::MATCHED,
			'status:completed',
			array(
				array(
					'item_id'      => 11,
					'product_id'   => 100,
					'variation_id' => 0,
					'level'        => Specificity::PRODUCT,
					'kind'         => 'product',
				),
				array(
					'item_id'      => 12,
					'product_id'   => 100,
					'variation_id' => 0,
					'level'        => Specificity::PRODUCT,
					'kind'         => 'product',
				),
			)
		);

		$this->assertSame( array( 11, 12 ), $decision->matched_item_ids() );
		$this->assertSame( array( 100 ), $decision->matched_product_ids() );
	}

	/**
	 * A decision's specificity is the strongest rung any of its items reached,
	 * while each item keeps its own.
	 *
	 * @return void
	 */
	public function test_specificity_is_the_strongest_item_level() {
		$decision = MatchDecision::create(
			1,
			MatchDecision::MATCHED,
			'status:completed',
			array(
				array(
					'item_id'      => 11,
					'product_id'   => 100,
					'variation_id' => 0,
					'level'        => Specificity::TAG,
					'kind'         => 'tag',
				),
				array(
					'item_id'      => 12,
					'product_id'   => 100,
					'variation_id' => 250,
					'level'        => Specificity::VARIATION,
					'kind'         => 'variation',
				),
			)
		);

		$this->assertSame( Specificity::VARIATION, $decision->specificity() );
		$this->assertSame( 'variation', $decision->specificity_label() );
		$this->assertSame( array( 250 ), $decision->matched_variation_ids() );
		$this->assertSame( Specificity::TAG, $decision->matched_items()[0]['level'] );
	}

	/**
	 * The flat form carries exactly the ADR-0011 §7 fields.
	 *
	 * @return void
	 */
	public function test_to_array_shape() {
		$decision = MatchDecision::create( 3, MatchDecision::NO_TARGETING_MATCH, 'transition:pending>processing' );

		$this->assertSame(
			array(
				'rule_id',
				'matched',
				'reason',
				'loggable',
				'matched_item_ids',
				'matched_product_ids',
				'matched_items',
				'specificity_level',
				'specificity_kind',
				'trigger_identity',
			),
			array_keys( $decision->to_array() )
		);
		$this->assertSame( 'transition:pending>processing', $decision->trigger_identity() );
		$this->assertSame( '', $decision->to_array()['specificity_kind'], 'Nothing matched, so nothing named a kind.' );
	}

	/**
	 * A TYPE-only match serialises with kind `type`, never `tag`.
	 *
	 * `types` shares rung 1 with `tag` (ADR-0011 §5), which is a fine ordering
	 * decision and a terrible label: `Specificity::label( 1 )` is `tag`, and the
	 * serialised form used to carry the bare rung number, so a type match came
	 * out of the engine indistinguishable from a tag match the instant it left
	 * PHP — in exactly the payload the delivery log and the admin panel consume.
	 *
	 * @return void
	 */
	public function test_a_type_only_match_serialises_as_type_not_tag() {
		$decision = MatchDecision::create(
			7,
			MatchDecision::MATCHED,
			'status:completed',
			array(
				array(
					'item_id'      => 11,
					'product_id'   => 100,
					'variation_id' => 0,
					'level'        => Specificity::TYPE,
					'kind'         => 'type',
				),
			)
		);

		$flat = $decision->to_array();

		$this->assertSame( 'type', $flat['specificity_kind'] );
		$this->assertSame( Specificity::TYPE, $flat['specificity_level'] );
		$this->assertSame( 'type', $flat['matched_items'][0]['kind'] );

		// The trap itself, pinned: the LEVEL and its rung name really are the
		// tag ones, which is why the kind has to be carried separately.
		$this->assertSame( Specificity::TAG, $flat['specificity_level'] );
		$this->assertSame( 'tag', $decision->specificity_label() );
		$this->assertSame( 'type', $decision->specificity_kind() );
	}

	/**
	 * A tag-only match on the same rung still serialises as `tag`, so the kind
	 * is reporting what matched rather than always saying `type`.
	 *
	 * @return void
	 */
	public function test_a_tag_only_match_still_serialises_as_tag() {
		$decision = MatchDecision::create(
			7,
			MatchDecision::MATCHED,
			'status:completed',
			array(
				array(
					'item_id'      => 11,
					'product_id'   => 100,
					'variation_id' => 0,
					'level'        => Specificity::TAG,
					'kind'         => 'tag',
				),
			)
		);

		$this->assertSame( 'tag', $decision->to_array()['specificity_kind'] );
	}

	/**
	 * The kind names the STRONGEST item's kind, and ties are broken by
	 * line-item order so the answer is deterministic.
	 *
	 * @return void
	 */
	public function test_specificity_kind_follows_the_strongest_item() {
		$item = static function ( int $item_id, int $level, string $kind ): array {
			return array(
				'item_id'      => $item_id,
				'product_id'   => 100,
				'variation_id' => 0,
				'level'        => $level,
				'kind'         => $kind,
			);
		};

		$stronger = MatchDecision::create(
			1,
			MatchDecision::MATCHED,
			'status:completed',
			array( $item( 11, Specificity::TYPE, 'type' ), $item( 12, Specificity::PRODUCT, 'product' ) )
		);
		$this->assertSame( 'product', $stronger->specificity_kind() );

		// Rung 1 shared by a type and a tag: first item in line-item order wins.
		$tied = MatchDecision::create(
			1,
			MatchDecision::MATCHED,
			'status:completed',
			array( $item( 11, Specificity::TYPE, 'type' ), $item( 12, Specificity::TAG, 'tag' ) )
		);
		$this->assertSame( 'type', $tied->specificity_kind() );
	}

	/**
	 * A deferred result carries the identity and no decisions (ADR-0008).
	 *
	 * @return void
	 */
	public function test_deferred_result() {
		$result = EvaluationResult::for_deferral( 42, TriggerEvent::status( 'completed' ) );

		$this->assertTrue( $result->deferred() );
		$this->assertSame( MatchDecision::NO_ITEMS_DEFERRED, $result->reason() );
		$this->assertSame( array(), $result->decisions() );
		$this->assertSame( 'status:completed', $result->trigger_identity() );
		$this->assertSame( 42, $result->order_id() );
	}

	/**
	 * A non-triggering event defers nothing and decides nothing.
	 *
	 * @return void
	 */
	public function test_not_triggered_result() {
		$result = EvaluationResult::not_triggered( 42, TriggerEvent::status( 'checkout-draft' ) );

		$this->assertFalse( $result->deferred() );
		$this->assertSame( '', $result->reason() );
		$this->assertSame( array(), $result->decisions() );
	}

	/**
	 * The result splits its decisions into the actionable and the loggable
	 * subsets without reordering either.
	 *
	 * @return void
	 */
	public function test_actionable_and_loggable_subsets() {
		$result = EvaluationResult::create(
			42,
			TriggerEvent::status( 'completed' ),
			array(
				MatchDecision::create( 1, MatchDecision::NO_TARGETING_MATCH, 'status:completed' ),
				MatchDecision::create(
					2,
					MatchDecision::MATCHED,
					'status:completed',
					array(
						array(
							'item_id'      => 5,
							'product_id'   => 100,
							'variation_id' => 0,
							'level'        => 3,
							'kind'         => 'product',
						),
					)
				),
				MatchDecision::create( 3, MatchDecision::EXCLUDED_BY_RULE, 'status:completed' ),
			),
			array( 9 => MatchDecision::PRODUCT_UNAVAILABLE )
		);

		$rule_ids = static function ( array $decisions ): array {
			return array_map(
				static function ( MatchDecision $decision ): int {
					return $decision->rule_id();
				},
				$decisions
			);
		};

		$this->assertSame( array( 2 ), $rule_ids( $result->actionable() ) );
		$this->assertSame( array( 2, 3 ), $rule_ids( $result->loggable_decisions() ) );
		$this->assertSame( array( 9 ), $result->unavailable_item_ids() );
		$this->assertSame( array(), $result->partially_resolved_item_ids() );
		$this->assertSame(
			array(
				1 => MatchDecision::NO_TARGETING_MATCH,
				2 => MatchDecision::MATCHED,
				3 => MatchDecision::EXCLUDED_BY_RULE,
			),
			$result->reason_sequence()
		);
		$this->assertSame( 2, $result->decision_for( 2 )->rule_id() );
		$this->assertNull( $result->decision_for( 99 ) );
	}

	/**
	 * The two item-level notes are reported through two separate accessors: a
	 * degraded item still evaluated, so it must NOT appear as unavailable.
	 *
	 * @return void
	 */
	public function test_item_notes_separate_skipped_from_degraded() {
		$result = EvaluationResult::create(
			42,
			TriggerEvent::status( 'completed' ),
			array(),
			array(
				9  => MatchDecision::PRODUCT_UNAVAILABLE,
				10 => MatchDecision::VARIATION_UNAVAILABLE,
			)
		);

		$this->assertSame( array( 9 ), $result->unavailable_item_ids() );
		$this->assertSame( array( 10 ), $result->partially_resolved_item_ids() );
	}
}
