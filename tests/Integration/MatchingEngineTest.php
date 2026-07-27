<?php
/**
 * Evaluation order, halting, deferral and trigger families (ADR-0011 §1, §6-8).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Domain\MatchDecision;
use Extonify\WCEP\Domain\Specificity;
use Extonify\WCEP\Domain\TriggerEvent;
use Extonify\WCEP\Matching\OrderStatuses;

/**
 * The orchestration half: which rules run, in what order, and when the run
 * stops.
 */
final class MatchingEngineTest extends MatchingTestCase {

	/**
	 * The `status:completed` event most tests here evaluate.
	 *
	 * @return TriggerEvent
	 */
	private function completed(): TriggerEvent {
		return TriggerEvent::status( 'completed' );
	}

	/**
	 * 7. A mixed cart: A matches rule 1, B matches rule 2, C matches nothing,
	 *    and the rule that matched nothing is NOT loggable.
	 *
	 * @return void
	 */
	public function test_mixed_cart() {
		$a = $this->make_simple_product( 'WCEP Mixed A' );
		$b = $this->make_simple_product( 'WCEP Mixed B' );
		$c = $this->make_simple_product( 'WCEP Mixed C' );

		$order    = $this->make_order_with( array( $a, $b, $c ) );
		$item_ids = array_keys( $order->get_items() );

		$rule_a = $this->make_rule(
			array(
				'name'      => 'rule 1',
				'priority'  => 10,
				'targeting' => array( 'include' => array( 'products' => array( $a ) ) ),
			)
		);
		$rule_b = $this->make_rule(
			array(
				'name'      => 'rule 2',
				'priority'  => 11,
				'targeting' => array( 'include' => array( 'products' => array( $b ) ) ),
			)
		);
		$rule_c = $this->make_rule(
			array(
				'name'      => 'rule 3',
				'priority'  => 12,
				'targeting' => array( 'include' => array( 'products' => array( 987654321 ) ) ),
			)
		);

		$result = $this->matcher()->evaluate( $order, $this->completed() );

		$this->assertSame( array( $item_ids[0] ), $result->decision_for( $rule_a )->matched_item_ids() );
		$this->assertSame( array( $a ), $result->decision_for( $rule_a )->matched_product_ids() );

		$this->assertSame( array( $item_ids[1] ), $result->decision_for( $rule_b )->matched_item_ids() );
		$this->assertSame( array( $b ), $result->decision_for( $rule_b )->matched_product_ids() );

		$third = $result->decision_for( $rule_c );
		$this->assertSame( MatchDecision::NO_TARGETING_MATCH, $third->reason() );
		$this->assertFalse( $third->loggable(), 'The noise floor must stay silent (ADR-0005).' );
		$this->assertSame( array(), $third->matched_item_ids() );

		// Product C matched nothing, so its item appears in no decision.
		foreach ( $this->own_decisions( $result ) as $decision ) {
			$this->assertNotContains( $item_ids[2], $decision->matched_item_ids(), 'Product C should have matched nothing.' );
		}

		// The actionable subset is exactly the two rules that matched, in
		// evaluation order.
		$actionable = array_map(
			static function ( MatchDecision $decision ): int {
				return $decision->rule_id();
			},
			$result->actionable()
		);
		$this->assertSame( array( $rule_a, $rule_b ), $actionable );

		// And the loggable subset omits the noise floor.
		$this->assertNotContains(
			$rule_c,
			array_map(
				static function ( MatchDecision $decision ): int {
					return $decision->rule_id();
				},
				$result->loggable_decisions()
			)
		);

		$this->assertSame( array(), $result->item_notes(), 'No item should have been unresolvable.' );
	}

	/**
	 * 9. A matching rule carrying `stop_processing` halts evaluation; later
	 *    rules report `blocked_by_stop_flag`; earlier decisions are unchanged.
	 *
	 * @return void
	 */
	public function test_stop_processing_halts_evaluation() {
		$product_id = $this->make_simple_product( 'WCEP Stop' );
		$order      = $this->make_order_with( array( $product_id ) );
		$targeting  = array( 'include' => array( 'products' => array( $product_id ) ) );

		$first = $this->make_rule(
			array(
				'name'      => 'runs first',
				'priority'  => 1,
				'targeting' => $targeting,
			)
		);
		$stop  = $this->make_rule(
			array(
				'name'            => 'halts',
				'priority'        => 5,
				'targeting'       => $targeting,
				'stop_processing' => 1,
			)
		);
		$after = $this->make_rule(
			array(
				'name'      => 'never runs',
				'priority'  => 10,
				'targeting' => $targeting,
			)
		);
		$later = $this->make_rule(
			array(
				'name'      => 'also never runs',
				'priority'  => 20,
				'targeting' => $targeting,
			)
		);

		$result = $this->matcher()->evaluate( $order, $this->completed() );

		$this->assertSame(
			array(
				$first => MatchDecision::MATCHED,
				$stop  => MatchDecision::MATCHED,
				$after => MatchDecision::BLOCKED_BY_STOP_FLAG,
				$later => MatchDecision::BLOCKED_BY_STOP_FLAG,
			),
			$this->own_reasons( $result )
		);

		// The earlier decision keeps its matched items: a halt cannot rewrite
		// what already happened.
		$this->assertCount( 1, $result->decision_for( $first )->matched_item_ids() );

		// The halt is visible in the log rather than being an unexplained
		// absence.
		$this->assertTrue( $result->decision_for( $after )->loggable() );
		$this->assertSame( array(), $result->decision_for( $after )->matched_item_ids() );

		// The actionable subset stops at the halting rule.
		$this->assertSame(
			array( $first, $stop ),
			array_map(
				static function ( MatchDecision $decision ): int {
					return $decision->rule_id();
				},
				$result->actionable()
			)
		);
	}

	/**
	 * 9. REGRESSION: a malformed rule must not sort ahead of a valid
	 *    `stop_processing` rule and move the halt point.
	 *
	 * `{"include":{"products":{"0":1}}}` is the exact document that exposed the
	 * defect: VALID as a decoded PHP array (a kind that happens to be an array
	 * of one id, declaring PRODUCT specificity 3) and INVALID as raw text (a
	 * known kind written as a JSON object). Ordering read the decoded array
	 * while the decision read the raw string, so:
	 *
	 *     before : malformed -> targeting_invalid ; then valid rule -> matched, halt
	 *     after  : valid rule -> matched, halt     ; then malformed -> blocked_by_stop_flag
	 *
	 * The halt landed in a different place and the rules after it received
	 * different reason codes, so the delivery log recorded a different sequence
	 * of events — a direct violation of ADR-0011 §5 and §6, not a cosmetic one.
	 *
	 * @return void
	 */
	public function test_a_malformed_rule_cannot_sort_ahead_of_a_stop_processing_rule() {
		$product_id = $this->make_simple_product( 'WCEP Halt Order' );
		$order      = $this->make_order_with( array( $product_id ) );

		// Same priority throughout: declared specificity is what orders these.
		$malformed = $this->make_rule(
			array(
				'name'     => 'malformed',
				'priority' => 10,
			)
		);
		$halting   = $this->make_rule(
			array(
				'name'            => 'valid, halts',
				'priority'        => 10,
				'targeting'       => array( 'match_all' => true ),
				'stop_processing' => 1,
			)
		);

		$this->force_raw_targeting( $malformed, '{"include":{"products":{"0":1}}}' );

		// Pin the divergence the fixture depends on: the two representations of
		// this one column really do disagree.
		$row = $this->rules->find( $malformed );
		$this->assertSame( array( 'include' => array( 'products' => array( 1 ) ) ), $row['targeting'] );
		$this->assertSame(
			Specificity::PRODUCT,
			\Extonify\WCEP\Domain\Targeting::from_array( $row['targeting'] )->declared_specificity(),
			'The DECODED array declares product specificity — the value ordering used to use.'
		);
		$this->assertFalse(
			\Extonify\WCEP\Domain\Targeting::from_value( $row['targeting_raw'] )->is_valid(),
			'The RAW string is invalid — the value the decision used.'
		);

		$result = $this->matcher()->evaluate( $order, $this->completed() );

		// The valid rule runs FIRST and halts; the malformed one is never
		// evaluated at all, so it reports the halt rather than its own reason.
		$this->assertSame(
			array(
				$halting   => MatchDecision::MATCHED,
				$malformed => MatchDecision::BLOCKED_BY_STOP_FLAG,
			),
			$this->own_reasons( $result )
		);
		$this->assertSame( array( $halting, $malformed ), $this->decision_order( $result ) );
	}

	/**
	 * 10. A rule whose RAW targeting is invalid sorts LAST among equal
	 *     priorities — ADR-0011 §5's `-1` rule, asserted on the decision
	 *     sequence rather than on an annotation.
	 *
	 * @return void
	 */
	public function test_a_rule_with_invalid_raw_targeting_sorts_last() {
		$product_id = $this->make_simple_product( 'WCEP Invalid Sorts Last' );
		$order      = $this->make_order_with( array( $product_id ) );

		$malformed = $this->make_rule(
			array(
				'name'     => 'malformed',
				'priority' => 10,
			)
		);
		$by_tag    = $this->make_rule(
			array(
				'name'      => 'tag rung 1',
				'priority'  => 10,
				'targeting' => array( 'include' => array( 'tags' => array( 987654321 ) ) ),
			)
		);
		$all       = $this->make_rule(
			array(
				'name'      => 'match_all rung 0',
				'priority'  => 10,
				'targeting' => array( 'match_all' => true ),
			)
		);

		// Declares PRODUCT as an array, invalid as text.
		$this->force_raw_targeting( $malformed, '{"include":{"products":{"0":' . $product_id . '}}}' );

		$result = $this->matcher()->evaluate( $order, $this->completed() );

		$this->assertSame(
			array( $by_tag, $all, $malformed ),
			$this->decision_order( $result ),
			'An invalid document declares nothing and must sort last, below match_all.'
		);
		$this->assertSame( MatchDecision::TARGETING_INVALID, $result->decision_for( $malformed )->reason() );
	}

	/**
	 * 10. A row carrying a hand-set `declared_specificity` is ordered by the
	 *     RECOMPUTED value, through the real matcher.
	 *
	 * Ordering must never be externally supplied: `sort_rules()` writes that key
	 * itself, so a caller handing a previously sorted array back could otherwise
	 * dictate evaluation order.
	 *
	 * @return void
	 */
	public function test_a_supplied_declared_specificity_cannot_dictate_evaluation_order() {
		$product_id = $this->make_simple_product( 'WCEP Supplied Specificity' );
		$order      = $this->make_order_with( array( $product_id ) );

		$weak   = $this->make_rule(
			array(
				'name'      => 'declares match_all',
				'priority'  => 10,
				'targeting' => array( 'match_all' => true ),
			)
		);
		$strong = $this->make_rule(
			array(
				'name'      => 'declares product',
				'priority'  => 10,
				'targeting' => array( 'include' => array( 'products' => array( $product_id ) ) ),
			)
		);

		$rules = $this->rules->find_active_for_trigger( 'status', 'completed' );

		// Lie about both, in the direction that would invert the true order.
		foreach ( $rules as $index => $rule ) {
			if ( (int) $rule['id'] === $weak ) {
				$rules[ $index ]['declared_specificity'] = Specificity::VARIATION;
			}
			if ( (int) $rule['id'] === $strong ) {
				$rules[ $index ]['declared_specificity'] = Specificity::NONE;
			}
		}

		$result = $this->matcher()->evaluate( $order, $this->completed(), $rules );

		$this->assertSame(
			array( $strong, $weak ),
			$this->decision_order( $result ),
			'A supplied declared_specificity dictated evaluation order.'
		);
	}

	/**
	 * 9. A `stop_processing` rule that does NOT match halts nothing.
	 *
	 * @return void
	 */
	public function test_stop_processing_only_halts_when_the_rule_matches() {
		$product_id = $this->make_simple_product( 'WCEP No Stop' );
		$order      = $this->make_order_with( array( $product_id ) );

		$stop  = $this->make_rule(
			array(
				'priority'        => 5,
				'targeting'       => array( 'include' => array( 'products' => array( 987654321 ) ) ),
				'stop_processing' => 1,
			)
		);
		$after = $this->make_rule(
			array(
				'priority'  => 10,
				'targeting' => array( 'include' => array( 'products' => array( $product_id ) ) ),
			)
		);

		$this->assertSame(
			array(
				$stop  => MatchDecision::NO_TARGETING_MATCH,
				$after => MatchDecision::MATCHED,
			),
			$this->own_reasons( $this->matcher()->evaluate( $order, $this->completed() ) )
		);
	}

	/**
	 * 10. Priority ascending, then declared specificity descending, then id
	 *     ascending — asserted as an exact decision sequence.
	 *
	 * @return void
	 */
	public function test_priority_and_specificity_ordering() {
		$variable  = $this->make_variable_product( 'WCEP Ordering', array( 'Small' ) );
		$tag_id    = $this->make_term( 'product_tag', 'WCEP Ordering Tag' );
		$order     = $this->make_order_with( array( $variable['variations'][0] ) );
		$variation = $variable['variations'][0];

		// Inserted in this order, so ids ascend r1 < r2 < r3 < r4 < r5 and the
		// id tie-breaker is observable.
		$r1 = $this->make_rule(
			array(
				'name'      => 'p20 variation',
				'priority'  => 20,
				'targeting' => array( 'include' => array( 'variations' => array( $variation ) ) ),
			)
		);
		$r2 = $this->make_rule(
			array(
				'name'      => 'p5 match_all',
				'priority'  => 5,
				'targeting' => array( 'match_all' => true ),
			)
		);
		$r3 = $this->make_rule(
			array(
				'name'      => 'p10 tag',
				'priority'  => 10,
				'targeting' => array( 'include' => array( 'tags' => array( $tag_id ) ) ),
			)
		);
		$r4 = $this->make_rule(
			array(
				'name'      => 'p10 variation, lower id',
				'priority'  => 10,
				'targeting' => array( 'include' => array( 'variations' => array( $variation ) ) ),
			)
		);
		$r5 = $this->make_rule(
			array(
				'name'      => 'p10 variation, higher id',
				'priority'  => 10,
				'targeting' => array( 'include' => array( 'variations' => array( $variation ) ) ),
			)
		);

		$result = $this->matcher()->evaluate( $order, $this->completed() );

		$this->assertSame(
			array( $r2, $r4, $r5, $r3, $r1 ),
			$this->decision_order( $result ),
			'Ordering is priority ASC, declared specificity DESC, id ASC.'
		);

		// Specificity ordered them, and yet suppressed nothing: every rule
		// still produced a decision.
		$this->assertCount( 5, $this->own_decisions( $result ) );
		$this->assertSame( Specificity::VARIATION, $result->decision_for( $r4 )->specificity() );
		$this->assertSame( Specificity::MATCH_ALL, $result->decision_for( $r2 )->specificity() );
	}

	/**
	 * 10. The sequence is the same whichever order the rules were created in,
	 *     because the comparator is total.
	 *
	 * @return void
	 */
	public function test_ordering_is_independent_of_insertion_order() {
		$product_id = $this->make_simple_product( 'WCEP Stable Order' );
		$order      = $this->make_order_with( array( $product_id ) );

		$broad = $this->make_rule(
			array(
				'priority'  => 10,
				'targeting' => array( 'match_all' => true ),
			)
		);
		$exact = $this->make_rule(
			array(
				'priority'  => 10,
				'targeting' => array( 'include' => array( 'products' => array( $product_id ) ) ),
			)
		);

		// $broad has the LOWER id but the LOWER specificity, so specificity —
		// not id, and not insertion order — must decide.
		$this->assertLessThan( $exact, $broad );
		$this->assertSame( array( $exact, $broad ), $this->decision_order( $this->matcher()->evaluate( $order, $this->completed() ) ) );
	}

	/**
	 * 11. A zero-item order defers, evaluates nothing, and preserves the
	 *     trigger identity for the deferred re-evaluation (ADR-0008).
	 *
	 * @return void
	 */
	public function test_zero_item_order_defers() {
		$order = wc_create_order();
		$order->set_billing_email( 'wcep-matching@example.test' );
		$order->save();
		$this->order_ids[] = (int) $order->get_id();

		$this->assertCount( 0, $order->get_items(), 'The fixture order should have no items.' );

		$rule_id = $this->make_rule( array( 'targeting' => array( 'match_all' => true ) ) );

		$result = $this->matcher()->evaluate( $order, $this->completed() );

		$this->assertTrue( $result->deferred() );
		$this->assertSame( MatchDecision::NO_ITEMS_DEFERRED, $result->reason() );
		$this->assertSame( array(), $result->decisions(), 'Nothing may be evaluated against an empty item set.' );
		$this->assertNull( $result->decision_for( $rule_id ) );

		// The identity the deferred path must reuse, so the ADR-0004 unique
		// constraint collapses the two paths into one claim.
		$this->assertSame( 'status:completed', $result->trigger_identity() );
		$this->assertSame( 'status', $result->trigger_type() );
		$this->assertSame( 'completed', $result->trigger_value() );
		$this->assertSame( (int) $order->get_id(), $result->order_id() );
	}

	/**
	 * 11. Once items exist, the same trigger evaluates normally under the same
	 *     identity — which is what makes the deferred re-evaluation safe.
	 *
	 * @return void
	 */
	public function test_the_same_trigger_evaluates_once_items_arrive() {
		$product_id = $this->make_simple_product( 'WCEP Late Item' );

		$order = wc_create_order();
		$order->set_billing_email( 'wcep-matching@example.test' );
		$order->save();
		$this->order_ids[] = (int) $order->get_id();

		$rule_id = $this->make_rule( array( 'targeting' => array( 'include' => array( 'products' => array( $product_id ) ) ) ) );

		$deferred = $this->matcher()->evaluate( $order, $this->completed() );
		$this->assertTrue( $deferred->deferred() );

		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->calculate_totals();
		$order->save();

		$later = $this->matcher()->evaluate( wc_get_order( $order->get_id() ), $this->completed() );

		$this->assertFalse( $later->deferred() );
		$this->assertTrue( $later->decision_for( $rule_id )->matched() );
		$this->assertSame( $deferred->trigger_identity(), $later->trigger_identity(), 'The deferred path must reuse the original identity.' );
	}

	/**
	 * 12. One status change evaluates BOTH families, producing two distinct
	 *     trigger identities.
	 *
	 * @return void
	 */
	public function test_status_and_transition_families_are_both_evaluated() {
		$product_id = $this->make_simple_product( 'WCEP Families' );
		$order      = $this->make_order_with( array( $product_id ) );
		$targeting  = array( 'include' => array( 'products' => array( $product_id ) ) );

		$status_rule     = $this->make_rule(
			array(
				'trigger_type'  => 'status',
				'trigger_value' => 'completed',
				'targeting'     => $targeting,
			)
		);
		$transition_rule = $this->make_rule(
			array(
				'trigger_type'  => 'transition',
				'trigger_value' => 'pending>completed',
				'targeting'     => $targeting,
			)
		);

		$results = $this->matcher()->evaluate_status_change( $order, 'wc-pending', 'wc-completed' );

		$this->assertSame( 'status:completed', $results['status']->trigger_identity() );
		$this->assertSame( 'transition:pending>completed', $results['transition']->trigger_identity() );
		$this->assertNotSame( $results['status']->trigger_identity(), $results['transition']->trigger_identity() );

		// Each rule fires in its own family and is absent from the other.
		$this->assertTrue( $results['status']->decision_for( $status_rule )->matched() );
		$this->assertNull( $results['status']->decision_for( $transition_rule ) );

		$this->assertTrue( $results['transition']->decision_for( $transition_rule )->matched() );
		$this->assertNull( $results['transition']->decision_for( $status_rule ) );

		// Each decision carries its OWN identity, so the delivery phase cannot
		// claim one under the other.
		$this->assertSame( 'status:completed', $results['status']->decision_for( $status_rule )->trigger_identity() );
		$this->assertSame( 'transition:pending>completed', $results['transition']->decision_for( $transition_rule )->trigger_identity() );
	}

	/**
	 * 12. A `stop_processing` halt in one family does not touch the other: the
	 *     halt is scoped to a single trigger identity, or the outcome would
	 *     depend on which family ran first.
	 *
	 * @return void
	 */
	public function test_a_halt_in_one_family_does_not_halt_the_other() {
		$product_id = $this->make_simple_product( 'WCEP Family Halt' );
		$order      = $this->make_order_with( array( $product_id ) );
		$targeting  = array( 'include' => array( 'products' => array( $product_id ) ) );

		$this->make_rule(
			array(
				'trigger_type'    => 'status',
				'trigger_value'   => 'completed',
				'priority'        => 1,
				'targeting'       => $targeting,
				'stop_processing' => 1,
			)
		);
		$transition_rule = $this->make_rule(
			array(
				'trigger_type'  => 'transition',
				'trigger_value' => 'pending>completed',
				'priority'      => 99,
				'targeting'     => $targeting,
			)
		);

		$results = $this->matcher()->evaluate_status_change( $order, 'pending', 'completed' );

		$this->assertSame( MatchDecision::MATCHED, $results['transition']->decision_for( $transition_rule )->reason() );
	}

	/**
	 * 13. A custom order status registered by a fixture is discovered and
	 *     matchable.
	 *
	 * @return void
	 */
	public function test_custom_order_status_is_discovered_and_matchable() {
		$slug   = 'wcep-awaiting-stock';
		$filter = static function ( $statuses ) use ( $slug ) {
			$statuses[ 'wc-' . $slug ] = 'Awaiting stock';
			return $statuses;
		};

		$this->assertFalse( OrderStatuses::is_known( $slug ), 'The fixture status should not exist yet.' );

		add_filter( 'wc_order_statuses', $filter );

		try {
			$this->assertTrue( OrderStatuses::is_known( $slug ), 'A custom status was not discovered.' );
			$this->assertContains( $slug, OrderStatuses::all() );

			$product_id = $this->make_simple_product( 'WCEP Custom Status' );
			$order      = $this->make_order_with( array( $product_id ) );
			$rule_id    = $this->make_rule(
				array(
					'trigger_value' => $slug,
					'targeting'     => array( 'include' => array( 'products' => array( $product_id ) ) ),
				)
			);

			$result = $this->matcher()->evaluate( $order, TriggerEvent::status( $slug ) );

			$this->assertFalse( $result->deferred() );
			$this->assertSame( 'status:' . $slug, $result->trigger_identity() );
			$this->assertTrue( $result->decision_for( $rule_id )->matched() );
		} finally {
			remove_filter( 'wc_order_statuses', $filter );
		}

		$this->assertFalse( OrderStatuses::is_known( $slug ), 'The fixture status outlived the test.' );
	}

	/**
	 * 13. A status nothing has registered is not matchable, so discovery is
	 *     doing real work.
	 *
	 * @return void
	 */
	public function test_an_unregistered_status_produces_nothing() {
		$product_id = $this->make_simple_product( 'WCEP Unknown Status' );
		$order      = $this->make_order_with( array( $product_id ) );
		$rule_id    = $this->make_rule(
			array(
				'trigger_value' => 'never-registered',
				'targeting'     => array( 'match_all' => true ),
			)
		);

		$result = $this->matcher()->evaluate( $order, TriggerEvent::status( 'never-registered' ) );

		$this->assertSame( array(), $result->decisions() );
		$this->assertFalse( $result->deferred() );
		$this->assertNull( $result->decision_for( $rule_id ) );
	}

	/**
	 * 14. `checkout-draft` produces nothing — and the exclusion is real work,
	 *     because WooCommerce Blocks DOES register it as an order status.
	 *
	 * @return void
	 */
	public function test_checkout_draft_produces_nothing() {
		// Pin the behaviour the explicit exclusion exists for.
		$this->assertArrayHasKey(
			'wc-checkout-draft',
			wc_get_order_statuses(),
			'WooCommerce Blocks no longer registers checkout-draft; the exclusion is still correct but this note is stale.'
		);
		$this->assertNotContains( 'checkout-draft', OrderStatuses::all(), 'checkout-draft leaked into the matchable statuses.' );

		$product_id = $this->make_simple_product( 'WCEP Draft' );
		$order      = $this->make_order_with( array( $product_id ) );

		$this->make_rule(
			array(
				'trigger_value' => 'checkout-draft',
				'targeting'     => array( 'match_all' => true ),
			)
		);
		$this->make_rule(
			array(
				'trigger_type'  => 'transition',
				'trigger_value' => 'pending>checkout-draft',
				'targeting'     => array( 'match_all' => true ),
			)
		);

		$entering = $this->matcher()->evaluate( $order, TriggerEvent::status( 'checkout-draft' ) );
		$this->assertSame( array(), $entering->decisions() );
		$this->assertFalse( $entering->deferred() );

		$into_draft = $this->matcher()->evaluate( $order, TriggerEvent::transition( 'pending', 'checkout-draft' ) );
		$this->assertSame( array(), $into_draft->decisions() );
		$this->assertFalse( $into_draft->deferred() );
	}

	/**
	 * 14. LEAVING the draft state does fire: the order has become real, which
	 *     is the only way to target a block-checkout order's first moment.
	 *
	 * @return void
	 */
	public function test_leaving_checkout_draft_does_fire() {
		$product_id = $this->make_simple_product( 'WCEP Leaving Draft' );
		$order      = $this->make_order_with( array( $product_id ) );

		$rule_id = $this->make_rule(
			array(
				'trigger_type'  => 'transition',
				'trigger_value' => 'checkout-draft>pending',
				'targeting'     => array( 'include' => array( 'products' => array( $product_id ) ) ),
			)
		);

		$result = $this->matcher()->evaluate( $order, TriggerEvent::transition( 'checkout-draft', 'pending' ) );

		$this->assertSame( 'transition:checkout-draft>pending', $result->trigger_identity() );
		$this->assertTrue( $result->decision_for( $rule_id )->matched() );
	}

	/**
	 * 15. A refund trigger carries the `refund:{id}` identity, and two refunds
	 *     on one order carry two distinct identities (ADR-0004).
	 *
	 * @return void
	 */
	public function test_refund_trigger_identity() {
		$product_id = $this->make_simple_product( 'WCEP Refunded' );
		$order      = $this->make_order_with( array( array( $product_id, 3 ) ) );

		$rule_id = $this->make_rule(
			array(
				'trigger_type'  => 'refund',
				'trigger_value' => '',
				'targeting'     => array( 'include' => array( 'products' => array( $product_id ) ) ),
			)
		);

		$first = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '5.00',
				'reason'   => 'WCEP fixture',
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $first, 'Could not create the refund fixture.' );
		$this->track_refund( (int) $first->get_id() );

		$result = $this->matcher()->evaluate_refund( wc_get_order( $order->get_id() ), (int) $first->get_id() );

		$this->assertSame( 'refund', $result->trigger_type() );
		$this->assertSame( '', $result->trigger_value(), 'Refund rules are keyed by refund id, not by a trigger value.' );
		$this->assertSame( 'refund:' . $first->get_id(), $result->trigger_identity() );
		$this->assertTrue( $result->decision_for( $rule_id )->matched() );
		$this->assertSame( 'refund:' . $first->get_id(), $result->decision_for( $rule_id )->trigger_identity() );

		$second = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '2.00',
				'reason'   => 'WCEP fixture 2',
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $second );
		$this->track_refund( (int) $second->get_id() );

		$second_result = $this->matcher()->evaluate_refund( wc_get_order( $order->get_id() ), (int) $second->get_id() );

		$this->assertNotSame(
			$result->trigger_identity(),
			$second_result->trigger_identity(),
			'Two partial refunds must produce two distinct identities.'
		);
		$this->assertTrue( $second_result->decision_for( $rule_id )->matched() );
	}

	/**
	 * 15. A refund with no id is not a trigger: an identity nothing can
	 *     reproduce must never reach the tombstone.
	 *
	 * @return void
	 */
	public function test_a_refund_without_an_id_produces_nothing() {
		$product_id = $this->make_simple_product( 'WCEP No Refund Id' );
		$order      = $this->make_order_with( array( $product_id ) );

		$this->make_rule(
			array(
				'trigger_type'  => 'refund',
				'trigger_value' => '',
				'targeting'     => array( 'match_all' => true ),
			)
		);

		$result = $this->matcher()->evaluate_refund( $order, 0 );

		$this->assertSame( array(), $result->decisions() );
		$this->assertFalse( $result->deferred() );
	}

	/**
	 * A rule ROW ALREADY MARKED INACTIVE is reported `rule_disabled` and logged.
	 *
	 * NAMED FOR WHAT IT ASSERTS. It was called
	 * `test_rule_disabled_between_fetch_and_evaluation`, which claimed more than
	 * it proved: it rewrites `status` on the already-fetched array, so it
	 * exercises the matcher's re-read of the row it was handed and NOT detection
	 * of a rule disabled in storage. The matcher cannot detect that, by design —
	 * ADR-0011 §7 makes `evaluate()` a pure function of the rows it is given and
	 * places the freshness obligation on the caller.
	 *
	 * `rule_disabled` therefore exists for a caller that has ALREADY determined a
	 * rule is inactive and wants the outcome recorded rather than silently
	 * dropped — a deferred job re-validating per ADR-0007. Because
	 * `find_active_for_trigger()` filters `status = 'active'` in SQL, this reason
	 * is UNREACHABLE through the immediate path; the second assertion below pins
	 * exactly that. Real end-to-end coverage belongs to the delivery phase and is
	 * recorded as required work in `docs/p2-backlog.md`.
	 *
	 * @return void
	 */
	public function test_a_rule_row_marked_inactive_is_reported_as_rule_disabled() {
		$product_id = $this->make_simple_product( 'WCEP Disabled Later' );
		$order      = $this->make_order_with( array( $product_id ) );
		$rule_id    = $this->make_rule( array( 'targeting' => array( 'include' => array( 'products' => array( $product_id ) ) ) ) );

		// Fetch first, exactly as the immediate path would.
		$fetched = $this->rules->find_active_for_trigger( 'status', 'completed' );
		$this->assertNotEmpty( $fetched );

		// The store owner disables it. NOTE: this write is what the SECOND
		// assertion below is about — the matcher never sees it, because the
		// array is what is handed to it.
		$this->assertTrue( $this->rules->update( $rule_id, array( 'status' => 'inactive' ) ) );

		$stale = array();
		foreach ( $fetched as $rule ) {
			if ( (int) $rule['id'] === $rule_id ) {
				// Stand in for a caller that re-read the rule and found it
				// disabled. The matcher performs no such re-read itself.
				$rule['status'] = 'inactive';
			}
			$stale[] = $rule;
		}

		$decision = $this->matcher()->evaluate( $order, $this->completed(), $stale )->decision_for( $rule_id );

		$this->assertSame( MatchDecision::RULE_DISABLED, $decision->reason() );
		$this->assertFalse( $decision->matched() );
		$this->assertTrue( $decision->loggable() );

		// The immediate path never reaches that code at all: the fetch filters
		// on status in SQL, so the rule is simply absent.
		$this->assertNull( $this->matcher()->evaluate( $order, $this->completed() )->decision_for( $rule_id ) );
	}

	/**
	 * The freshness contract itself (ADR-0011 §7): handed an UNMODIFIED stale
	 * array, the matcher evaluates the rule as it was given it and does NOT
	 * consult storage.
	 *
	 * This is the assertion the renamed test above could not make. It is not a
	 * defect — it is the documented division of labour, and the delivery phase's
	 * re-validation is what closes it (ADR-0007).
	 *
	 * @return void
	 */
	public function test_the_matcher_does_not_re_read_rule_state() {
		$product_id = $this->make_simple_product( 'WCEP Freshness' );
		$order      = $this->make_order_with( array( $product_id ) );
		$rule_id    = $this->make_rule( array( 'targeting' => array( 'include' => array( 'products' => array( $product_id ) ) ) ) );

		$fetched = $this->rules->find_active_for_trigger( 'status', 'completed' );
		$this->assertNotEmpty( $fetched );

		$this->assertTrue( $this->rules->update( $rule_id, array( 'status' => 'inactive' ) ) );
		$this->assertSame( 'inactive', $this->rules->find( $rule_id )['status'], 'The fixture did not actually disable the rule.' );

		// The array still says `active`, and that is what the matcher answers
		// from. Supplying current rules is the CALLER's responsibility.
		$decision = $this->matcher()->evaluate( $order, $this->completed(), $fetched )->decision_for( $rule_id );

		$this->assertSame(
			MatchDecision::MATCHED,
			$decision->reason(),
			'The matcher re-read rule state from storage. ADR-0011 §7 says it must not — that would be a second source of '
			. 'truth beside the ADR-0007 snapshot.'
		);
	}

	/**
	 * A targeting column corrupted by a bad import reports `targeting_invalid`
	 * and IS logged — a rule that can never fire is something support has to
	 * be able to see.
	 *
	 * @return void
	 */
	public function test_malformed_targeting_column_reports_targeting_invalid() {
		$product_id = $this->make_simple_product( 'WCEP Corrupt Targeting' );
		$order      = $this->make_order_with( array( $product_id ) );

		$broken = $this->make_rule( array( 'targeting' => array( 'include' => array( 'products' => array( $product_id ) ) ) ) );
		$this->force_raw_targeting( $broken, '{"include":{"products":[' );

		$empty = $this->make_rule( array( 'targeting' => array() ) );

		$result = $this->matcher()->evaluate( $order, $this->completed() );

		$invalid = $result->decision_for( $broken );
		$this->assertSame( MatchDecision::TARGETING_INVALID, $invalid->reason() );
		$this->assertTrue( $invalid->loggable() );
		$this->assertFalse( $invalid->matched() );

		// An EMPTY document is a different thing: a valid, inert rule, and
		// deliberately silent.
		$inert = $result->decision_for( $empty );
		$this->assertSame( MatchDecision::NO_TARGETING_MATCH, $inert->reason() );
		$this->assertFalse( $inert->loggable() );
	}

	/**
	 * A schema-level corruption — valid JSON, wrong shape — is invalid too.
	 *
	 * @return void
	 */
	public function test_schema_level_corruption_reports_targeting_invalid() {
		$product_id = $this->make_simple_product( 'WCEP Wrong Shape' );
		$order      = $this->make_order_with( array( $product_id ) );

		$rule_id = $this->make_rule();
		$this->force_raw_targeting( $rule_id, '{"include":{"products":"12"}}' );

		$this->assertSame(
			MatchDecision::TARGETING_INVALID,
			$this->matcher()->evaluate( $order, $this->completed() )->decision_for( $rule_id )->reason()
		);
	}

	/**
	 * Every structurally malformed shape reports `targeting_invalid` THROUGH THE
	 * ENGINE, written into the raw `targeting` COLUMN.
	 *
	 * The column is the real boundary and the one an importer writes to.
	 * `Targeting::from_array()` alone cannot prove this: the list-shaped cases
	 * arrive as JSON text, and it is the round trip through
	 * `RuleRepository::hydrate()` and the `{$column}_raw` passenger that decides
	 * whether the engine ever sees them.
	 *
	 * Every one of these previously reported `no_targeting_match`, which
	 * ADR-0005 does NOT log — so a corrupted rule stopped working with no entry
	 * anywhere in the delivery log.
	 *
	 * @dataProvider malformed_column_provider
	 *
	 * @param string $raw  Raw column value.
	 * @param string $note What is wrong with it.
	 * @return void
	 */
	public function test_malformed_targeting_column_shapes_report_targeting_invalid( string $raw, string $note ) {
		$product_id = $this->make_simple_product( 'WCEP Shape ' . md5( $raw ) );
		$order      = $this->make_order_with( array( $product_id ) );

		$rule_id = $this->make_rule( array( 'targeting' => array( 'include' => array( 'products' => array( $product_id ) ) ) ) );
		$this->force_raw_targeting( $rule_id, $raw );

		$decision = $this->matcher()->evaluate( $order, $this->completed() )->decision_for( $rule_id );

		$this->assertSame( MatchDecision::TARGETING_INVALID, $decision->reason(), $note );
		$this->assertTrue( $decision->loggable(), $note . ' would leave no trace in the delivery log.' );
		$this->assertFalse( $decision->matched() );
	}

	/**
	 * The malformed shapes, as an importer or a truncated export writes them.
	 *
	 * @return array
	 */
	public static function malformed_column_provider(): array {
		return array(
			// The EMPTY list shapes. Associative decoding renders `{}` and `[]`
			// identically, so these three used to pass as valid inert documents
			// and report the unlogged `no_targeting_match`. Decoding the raw
			// column without associative mode keeps the distinction.
			'root is an empty list'  => array( '[]', 'An empty root-level list is not an empty document.' ),
			'include is empty list'  => array( '{"include":[]}', 'An empty include list is not an empty include object.' ),
			'exclude is empty list'  => array( '{"exclude":[]}', 'An empty exclude list is not an empty exclude object.' ),
			'kind is an object'      => array( '{"include":{"products":{"0":1}}}', 'A known kind must be a JSON list.' ),
			'root is a list'         => array( '[1,2]', 'A root-level list is not a targeting document.' ),
			'root list of objects'   => array( '[{"products":[1]}]', 'A root-level list is not a targeting document.' ),
			'include is a list'      => array( '{"include":[1,2]}', 'include must be an object.' ),
			'exclude is a list'      => array( '{"exclude":[1,2]}', 'exclude must be an object.' ),
			'include is a scalar'    => array( '{"include":"everything"}', 'include must be an object.' ),
			'kind is a scalar'       => array( '{"include":{"products":"12"}}', 'A known kind must be an array.' ),
			'kind is a bool'         => array( '{"include":{"types":true}}', 'A known kind must be an array.' ),
			'exclude kind is scalar' => array( '{"exclude":{"tags":4}}', 'A known kind must be an array.' ),
			'match_all is "yes"'     => array( '{"match_all":"yes"}', 'match_all must be a real boolean.' ),
			'match_all is 1'         => array( '{"match_all":1}', 'match_all must be a real boolean.' ),
			'match_all is a string'  => array( '{"include":{"products":[1]},"match_all":"true"}', 'match_all must be a real boolean.' ),
			'truncated json'         => array( '{"include":{"products":[1,2', 'Syntactically invalid JSON.' ),
			'json scalar'            => array( '"everything"', 'A scalar is not a document.' ),
		);
	}

	/**
	 * The counterpart: a well-formed document with an UNKNOWN key is still only
	 * ignored, so strictness did not swallow ADR-0011's forward compatibility.
	 *
	 * @return void
	 */
	public function test_unknown_keys_in_the_column_stay_ignored() {
		$product_id = $this->make_simple_product( 'WCEP Forward Compatible' );
		$order      = $this->make_order_with( array( $product_id ) );

		$rule_id = $this->make_rule();
		$this->force_raw_targeting(
			$rule_id,
			'{"schema":9,"include":{"products":[' . $product_id . '],"brands":[5],"match_all":true},"exclude":{"authors":[2],"match_all":true}}'
		);

		$decision = $this->matcher()->evaluate( $order, $this->completed() )->decision_for( $rule_id );

		$this->assertSame( MatchDecision::MATCHED, $decision->reason(), 'An unknown key is not a shape error.' );
		$this->assertSame( array( $product_id ), $decision->matched_product_ids() );
	}

	/**
	 * A rule SAVED THROUGH THE REPOSITORY with no targeting is valid and inert,
	 * whatever shape of empty document the author supplied.
	 *
	 * THE WRITER HAS TO AGREE WITH THE STRICT READER. `Json::encode( array() )`
	 * emits `[]`, so before `Targeting::encode()` existed the repository stored
	 * its own empty document in the one shape the new validation rejects — and
	 * every brand-new rule with no targeting yet would have reported itself
	 * `targeting_invalid`, contradicting ADR-0011 §3.
	 *
	 * @dataProvider empty_saved_targeting_provider
	 *
	 * @param array $targeting Targeting document as saved.
	 * @return void
	 */
	public function test_an_empty_saved_targeting_document_is_inert_not_invalid( array $targeting ) {
		$product_id = $this->make_simple_product( 'WCEP Saved Empty' );
		$order      = $this->make_order_with( array( $product_id ) );

		$rule_id  = $this->make_rule( array( 'targeting' => $targeting ) );
		$decision = $this->matcher()->evaluate( $order, $this->completed() )->decision_for( $rule_id );

		$this->assertSame(
			MatchDecision::NO_TARGETING_MATCH,
			$decision->reason(),
			'A rule with no targeting yet must be VALID and inert (ADR-0011 §3), not permanently broken.'
		);
		$this->assertFalse( $decision->loggable() );

		// And the stored column really is object-shaped, which is what makes it
		// survive the strict read.
		$stored = $this->raw_targeting_column( $rule_id );
		$this->assertStringStartsWith( '{', $stored, "Stored as '{$stored}', which the strict reader rejects." );
	}

	/**
	 * Every spelling of "this rule has no targeting yet".
	 *
	 * @return array<string,array{0:array}>
	 */
	public static function empty_saved_targeting_provider(): array {
		return array(
			'no document'   => array( array() ),
			'empty include' => array( array( 'include' => array() ) ),
			'empty exclude' => array( array( 'exclude' => array() ) ),
			'both empty'    => array(
				array(
					'include' => array(),
					'exclude' => array(),
				),
			),
			'empty kinds'   => array( array( 'include' => array( 'products' => array() ) ) ),
			'match_all off' => array(
				array(
					'include'   => array(),
					'match_all' => false,
				),
			),
		);
	}

	/**
	 * A saved document that DOES declare something still stores its kinds as
	 * JSON lists, so the object cast fixed the ambiguity and nothing else.
	 *
	 * @return void
	 */
	public function test_a_saved_document_keeps_its_kinds_as_lists() {
		$rule_id = $this->make_rule(
			array(
				'targeting' => array(
					'include'   => array( 'products' => array( 12, 34 ) ),
					'exclude'   => array( 'tags' => array( 7 ) ),
					'match_all' => false,
				),
			)
		);

		$this->assertSame(
			'{"include":{"products":[12,34]},"exclude":{"tags":[7]},"match_all":false}',
			$this->raw_targeting_column( $rule_id )
		);
	}

	/**
	 * The raw `targeting` column as stored, bypassing hydration.
	 *
	 * @param int $rule_id Rule id.
	 * @return string
	 */
	private function raw_targeting_column( int $rule_id ): string {
		global $wpdb;

		$table = \Extonify\WCEP\Install\Migrator::table( 'rules' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT targeting FROM {$table} WHERE id = %d", $rule_id ) );
	}

	/**
	 * The EMPTY OBJECT shapes stay valid and inert through the engine, so the
	 * previous test is distinguishing `{}` from `[]` rather than simply
	 * rejecting everything empty.
	 *
	 * @dataProvider empty_object_column_provider
	 *
	 * @param string $raw Raw column value.
	 * @return void
	 */
	public function test_empty_object_columns_stay_valid_and_inert( string $raw ) {
		$product_id = $this->make_simple_product( 'WCEP Inert ' . md5( $raw ) );
		$order      = $this->make_order_with( array( $product_id ) );

		$rule_id = $this->make_rule();
		$this->force_raw_targeting( $rule_id, $raw );

		$decision = $this->matcher()->evaluate( $order, $this->completed() )->decision_for( $rule_id );

		$this->assertSame( MatchDecision::NO_TARGETING_MATCH, $decision->reason(), $raw . ' should be a valid, inert rule.' );
		$this->assertFalse( $decision->loggable(), 'An inert rule is the noise floor and must stay silent.' );
	}

	/**
	 * Empty documents written with OBJECT syntax.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function empty_object_column_provider(): array {
		return array(
			'empty root object' => array( '{}' ),
			'empty include'     => array( '{"include":{}}' ),
			'empty exclude'     => array( '{"exclude":{}}' ),
			'both empty'        => array( '{"include":{},"exclude":{}}' ),
			'empty kind lists'  => array( '{"include":{"products":[],"tags":[]}}' ),
		);
	}

	/**
	 * A junk ENTRY in the column is still dropped rather than fatal — and the
	 * junk never becomes an id, so the rule cannot target a product nobody
	 * selected.
	 *
	 * @return void
	 */
	public function test_junk_id_entries_in_the_column_are_dropped_not_cast() {
		$product_id = $this->make_simple_product( 'WCEP Junk Ids' );
		$order      = $this->make_order_with( array( $product_id ) );

		$kept    = $this->make_rule();
		$nothing = $this->make_rule();

		$this->force_raw_targeting( $kept, '{"include":{"products":["' . $product_id . '","1abc",true,0,-3,1.9]}}' );
		$this->force_raw_targeting( $nothing, '{"include":{"products":["1abc",true,1.9,"12x"]}}' );

		$result = $this->matcher()->evaluate( $order, $this->completed() );

		$this->assertSame( MatchDecision::MATCHED, $result->decision_for( $kept )->reason(), 'A digit string is a valid id.' );
		$this->assertSame( array( $product_id ), $result->decision_for( $kept )->matched_product_ids() );

		// Valid document, nothing declared — NOT targeting_invalid, and nothing
		// invented from the junk either.
		$this->assertSame( MatchDecision::NO_TARGETING_MATCH, $result->decision_for( $nothing )->reason() );
	}

	/**
	 * An inactive rule is never fetched in the first place, so the ordinary
	 * path costs nothing to protect.
	 *
	 * @return void
	 */
	public function test_inactive_rules_are_not_fetched() {
		$product_id = $this->make_simple_product( 'WCEP Inactive' );
		$order      = $this->make_order_with( array( $product_id ) );

		$rule_id = $this->make_rule(
			array(
				'status'    => 'inactive',
				'targeting' => array( 'match_all' => true ),
			)
		);

		$this->assertNull( $this->matcher()->evaluate( $order, $this->completed() )->decision_for( $rule_id ) );
	}
}
