<?php
/**
 * Targeting against real products and orders (ADR-0011 §3, §4, §5).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Domain\MatchDecision;
use Extonify\WCEP\Domain\Specificity;
use Extonify\WCEP\Domain\TriggerEvent;

/**
 * The unit suite proves targeting against synthetic descriptors. This one
 * proves the descriptors themselves are right — that WooCommerce's CRUD really
 * does report what `ItemResolver` claims it does.
 */
final class MatchingTargetingTest extends MatchingTestCase {

	/**
	 * The `status:completed` event every test here evaluates.
	 *
	 * @return TriggerEvent
	 */
	private function completed(): TriggerEvent {
		return TriggerEvent::status( 'completed' );
	}

	/**
	 * 1. A simple product, targeted by product id, matches at PRODUCT
	 *    specificity.
	 *
	 * @return void
	 */
	public function test_simple_product_matches_at_product_specificity() {
		$product_id = $this->make_simple_product( 'WCEP Simple' );
		$order      = $this->make_order_with( array( $product_id ) );
		$rule_id    = $this->make_rule( array( 'targeting' => array( 'include' => array( 'products' => array( $product_id ) ) ) ) );

		$decision = $this->matcher()->evaluate( $order, $this->completed() )->decision_for( $rule_id );

		$this->assertNotNull( $decision );
		$this->assertTrue( $decision->matched() );
		$this->assertSame( MatchDecision::MATCHED, $decision->reason() );
		$this->assertTrue( $decision->loggable() );
		$this->assertSame( Specificity::PRODUCT, $decision->specificity() );
		$this->assertSame( 'product', $decision->specificity_label() );
		$this->assertSame( array( $product_id ), $decision->matched_product_ids() );
		$this->assertCount( 1, $decision->matched_item_ids() );
		$this->assertSame( 'status:completed', $decision->trigger_identity() );
	}

	/**
	 * 2. A variation-targeted rule matches only that variation.
	 *
	 * @return void
	 */
	public function test_variation_targeting_matches_only_that_variation() {
		$variable = $this->make_variable_product( 'WCEP Variable', array( 'Small', 'Large' ) );
		$order    = $this->make_order_with( array( $variable['variations'][0] ) );

		$matching = $this->make_rule( array( 'targeting' => array( 'include' => array( 'variations' => array( $variable['variations'][0] ) ) ) ) );
		$other    = $this->make_rule( array( 'targeting' => array( 'include' => array( 'variations' => array( $variable['variations'][1] ) ) ) ) );

		$result = $this->matcher()->evaluate( $order, $this->completed() );

		$hit = $result->decision_for( $matching );
		$this->assertTrue( $hit->matched() );
		$this->assertSame( Specificity::VARIATION, $hit->specificity() );
		$this->assertSame( array( $variable['variations'][0] ), $hit->matched_variation_ids() );
		$this->assertSame( array( $variable['parent'] ), $hit->matched_product_ids(), 'A variation reports its PARENT product id.' );

		$this->assertSame( MatchDecision::NO_TARGETING_MATCH, $result->decision_for( $other )->reason() );
	}

	/**
	 * 2. A parent-targeted rule matches the variation, at PRODUCT specificity.
	 *
	 * @return void
	 */
	public function test_parent_targeting_matches_a_variation_at_product_specificity() {
		$variable = $this->make_variable_product( 'WCEP Variable Parent', array( 'Small' ) );
		$order    = $this->make_order_with( array( $variable['variations'][0] ) );
		$rule_id  = $this->make_rule( array( 'targeting' => array( 'include' => array( 'products' => array( $variable['parent'] ) ) ) ) );

		$decision = $this->matcher()->evaluate( $order, $this->completed() )->decision_for( $rule_id );

		$this->assertTrue( $decision->matched() );
		$this->assertSame( Specificity::PRODUCT, $decision->specificity() );
		$this->assertSame( 'product', $decision->matched_items()[0]['kind'] );
	}

	/**
	 * 2. `types: ["variable"]` matches a variation line item.
	 *
	 * WC_Product_Variation::get_type() returns `variation`, never `variable`,
	 * so without the parent-slug fallback this would match nothing anybody
	 * ever actually buys.
	 *
	 * @return void
	 */
	public function test_variable_type_targeting_matches_a_variation_line_item() {
		$variable = $this->make_variable_product( 'WCEP Variable Type', array( 'Small' ) );
		$order    = $this->make_order_with( array( $variable['variations'][0] ) );
		$rule_id  = $this->make_rule( array( 'targeting' => array( 'include' => array( 'types' => array( 'variable' ) ) ) ) );

		$this->assertTrue( $this->matcher()->evaluate( $order, $this->completed() )->decision_for( $rule_id )->matched() );
	}

	/**
	 * 3. Category targeting, including a product in several categories.
	 *
	 * @return void
	 */
	public function test_category_targeting() {
		$cat_a = $this->make_term( 'product_cat', 'WCEP Cat A' );
		$cat_b = $this->make_term( 'product_cat', 'WCEP Cat B' );
		$cat_c = $this->make_term( 'product_cat', 'WCEP Cat C' );

		$product_id = $this->make_simple_product( 'WCEP Multi-category', array( 'category_ids' => array( $cat_a, $cat_b ) ) );
		$order      = $this->make_order_with( array( $product_id ) );

		$first  = $this->make_rule( array( 'targeting' => array( 'include' => array( 'categories' => array( $cat_a ) ) ) ) );
		$second = $this->make_rule( array( 'targeting' => array( 'include' => array( 'categories' => array( $cat_b ) ) ) ) );
		$absent = $this->make_rule( array( 'targeting' => array( 'include' => array( 'categories' => array( $cat_c ) ) ) ) );

		$result = $this->matcher()->evaluate( $order, $this->completed() );

		$this->assertTrue( $result->decision_for( $first )->matched(), 'The first of two categories did not match.' );
		$this->assertTrue( $result->decision_for( $second )->matched(), 'The second of two categories did not match.' );
		$this->assertSame( Specificity::CATEGORY, $result->decision_for( $first )->specificity() );
		$this->assertSame( MatchDecision::NO_TARGETING_MATCH, $result->decision_for( $absent )->reason() );
	}

	/**
	 * 3. Tag targeting.
	 *
	 * @return void
	 */
	public function test_tag_targeting() {
		$tag_id     = $this->make_term( 'product_tag', 'WCEP Tag' );
		$other_tag  = $this->make_term( 'product_tag', 'WCEP Other Tag' );
		$product_id = $this->make_simple_product( 'WCEP Tagged', array( 'tag_ids' => array( $tag_id ) ) );
		$order      = $this->make_order_with( array( $product_id ) );

		$hit  = $this->make_rule( array( 'targeting' => array( 'include' => array( 'tags' => array( $tag_id ) ) ) ) );
		$miss = $this->make_rule( array( 'targeting' => array( 'include' => array( 'tags' => array( $other_tag ) ) ) ) );

		$result = $this->matcher()->evaluate( $order, $this->completed() );

		$this->assertTrue( $result->decision_for( $hit )->matched() );
		$this->assertSame( Specificity::TAG, $result->decision_for( $hit )->specificity() );
		$this->assertSame( MatchDecision::NO_TARGETING_MATCH, $result->decision_for( $miss )->reason() );
	}

	/**
	 * 3. A VARIATION line item inherits its parent's categories and tags.
	 *
	 * Verified WooCommerce behaviour, and the reason ItemResolver loads the
	 * parent: WC_Product_Variation never populates its own term ids, so
	 * reading them off the variation would make every category and tag rule
	 * silently miss every variation purchase.
	 *
	 * @return void
	 */
	public function test_variation_inherits_parent_categories_and_tags() {
		$cat_id = $this->make_term( 'product_cat', 'WCEP Variable Cat' );
		$tag_id = $this->make_term( 'product_tag', 'WCEP Variable Tag' );

		$variable = $this->make_variable_product(
			'WCEP Categorised Variable',
			array( 'Small' ),
			array(
				'category_ids' => array( $cat_id ),
				'tag_ids'      => array( $tag_id ),
			)
		);

		// The variation itself really does report no terms — this is the
		// WooCommerce behaviour the resolver exists to work around.
		$variation = wc_get_product( $variable['variations'][0] );
		$this->assertSame( array(), $variation->get_category_ids(), 'WooCommerce changed: a variation now carries category ids.' );
		$this->assertSame( array(), $variation->get_tag_ids(), 'WooCommerce changed: a variation now carries tag ids.' );

		$order  = $this->make_order_with( array( $variable['variations'][0] ) );
		$by_cat = $this->make_rule( array( 'targeting' => array( 'include' => array( 'categories' => array( $cat_id ) ) ) ) );
		$by_tag = $this->make_rule( array( 'targeting' => array( 'include' => array( 'tags' => array( $tag_id ) ) ) ) );
		$result = $this->matcher()->evaluate( $order, $this->completed() );

		$this->assertTrue( $result->decision_for( $by_cat )->matched(), 'Category targeting missed a variation purchase.' );
		$this->assertTrue( $result->decision_for( $by_tag )->matched(), 'Tag targeting missed a variation purchase.' );
	}

	/**
	 * 4. Virtual and downloadable flag targeting.
	 *
	 * @return void
	 */
	public function test_virtual_and_downloadable_flag_targeting() {
		$virtual_id      = $this->make_simple_product( 'WCEP Virtual', array( 'virtual' => true ) );
		$downloadable_id = $this->make_simple_product( 'WCEP Downloadable', array( 'downloadable' => true ) );
		$plain_id        = $this->make_simple_product( 'WCEP Physical' );

		$order = $this->make_order_with( array( $virtual_id, $downloadable_id, $plain_id ) );

		$virtual_rule      = $this->make_rule( array( 'targeting' => array( 'include' => array( 'types' => array( 'virtual' ) ) ) ) );
		$downloadable_rule = $this->make_rule( array( 'targeting' => array( 'include' => array( 'types' => array( 'downloadable' ) ) ) ) );
		$simple_rule       = $this->make_rule( array( 'targeting' => array( 'include' => array( 'types' => array( 'simple' ) ) ) ) );

		$result = $this->matcher()->evaluate( $order, $this->completed() );

		$this->assertSame( array( $virtual_id ), $result->decision_for( $virtual_rule )->matched_product_ids() );
		$this->assertSame( array( $downloadable_id ), $result->decision_for( $downloadable_rule )->matched_product_ids() );

		// The type slug matches all three, because they are all simple
		// products — a flag and a type slug are different kinds of thing.
		$this->assertSame(
			array( $virtual_id, $downloadable_id, $plain_id ),
			$result->decision_for( $simple_rule )->matched_product_ids()
		);
		$this->assertSame( Specificity::TYPE, $result->decision_for( $virtual_rule )->specificity() );
	}

	/**
	 * 4. A TYPE-only match SERIALISES as `type`, not as `tag`.
	 *
	 * `types` shares rung 1 with `tag` (ADR-0011 §5) — a sound ordering
	 * decision, and a wrong label: rung 1 is *named* `tag`. Internally the kind
	 * was always right; the serialised decision, which is what the delivery log
	 * and the admin panel actually consume, carried the bare rung number and so
	 * reported a type match as a tag match the moment it left PHP.
	 *
	 * @return void
	 */
	public function test_a_type_only_match_serialises_with_kind_type() {
		$tag_id    = $this->make_term( 'product_tag', 'WCEP Kind Tag' );
		$typed_id  = $this->make_simple_product( 'WCEP Kind Typed', array( 'downloadable' => true ) );
		$tagged_id = $this->make_simple_product( 'WCEP Kind Tagged', array( 'tag_ids' => array( $tag_id ) ) );

		$order = $this->make_order_with( array( $typed_id, $tagged_id ) );

		$by_type = $this->make_rule( array( 'targeting' => array( 'include' => array( 'types' => array( 'downloadable' ) ) ) ) );
		$by_tag  = $this->make_rule( array( 'targeting' => array( 'include' => array( 'tags' => array( $tag_id ) ) ) ) );

		$result = $this->matcher()->evaluate( $order, $this->completed() );

		$type_flat = $result->decision_for( $by_type )->to_array();
		$tag_flat  = $result->decision_for( $by_tag )->to_array();

		$this->assertSame( 'type', $type_flat['specificity_kind'] );
		$this->assertSame( 'tag', $tag_flat['specificity_kind'] );

		// Both sit on the SAME rung, which is exactly why the level alone could
		// never have told them apart.
		$this->assertSame( Specificity::TYPE, $type_flat['specificity_level'] );
		$this->assertSame( $tag_flat['specificity_level'], $type_flat['specificity_level'] );

		// The matched-item records travel with the serialised decision too, each
		// carrying its own level and kind (ADR-0011 §5).
		$this->assertSame( 'type', $type_flat['matched_items'][0]['kind'] );
		$this->assertSame( $typed_id, $type_flat['matched_items'][0]['product_id'] );

		// The rung NAME is still `tag` for both — the trap this separation
		// exists to remove.
		$this->assertSame( 'tag', $result->decision_for( $by_type )->specificity_label() );
	}

	/**
	 * 4. A virtual VARIATION is matched by its own flag, not the parent's.
	 *
	 * @return void
	 */
	public function test_variation_flags_come_from_the_variation() {
		$variable = $this->make_variable_product( 'WCEP Virtual Variable', array( 'Small' ), array( 'virtual' => true ) );
		$order    = $this->make_order_with( array( $variable['variations'][0] ) );
		$rule_id  = $this->make_rule( array( 'targeting' => array( 'include' => array( 'types' => array( 'virtual' ) ) ) ) );

		$this->assertTrue( $this->matcher()->evaluate( $order, $this->completed() )->decision_for( $rule_id )->matched() );
	}

	/**
	 * 5. Exclusion overrides an include at EVERY specificity level.
	 *
	 * @return void
	 */
	public function test_exclusion_overrides_an_include_at_every_level() {
		$cat_id = $this->make_term( 'product_cat', 'WCEP Excl Cat' );
		$tag_id = $this->make_term( 'product_tag', 'WCEP Excl Tag' );

		$variable = $this->make_variable_product(
			'WCEP Excludable',
			array( 'Small' ),
			array(
				'category_ids' => array( $cat_id ),
				'tag_ids'      => array( $tag_id ),
			)
		);

		$variation_id = $variable['variations'][0];
		$parent_id    = $variable['parent'];
		$order        = $this->make_order_with( array( $variation_id ) );

		// Whole documents: `match_all` is canonically at the ROOT and nowhere
		// else (ADR-0011 §3), so it cannot ride inside an include fragment.
		$documents = array(
			'variation excluded by variation' => array(
				'include' => array( 'variations' => array( $variation_id ) ),
				'exclude' => array( 'variations' => array( $variation_id ) ),
			),
			'variation excluded by product'   => array(
				'include' => array( 'variations' => array( $variation_id ) ),
				'exclude' => array( 'products' => array( $parent_id ) ),
			),
			'variation excluded by category'  => array(
				'include' => array( 'variations' => array( $variation_id ) ),
				'exclude' => array( 'categories' => array( $cat_id ) ),
			),
			'variation excluded by tag'       => array(
				'include' => array( 'variations' => array( $variation_id ) ),
				'exclude' => array( 'tags' => array( $tag_id ) ),
			),
			'variation excluded by type'      => array(
				'include' => array( 'variations' => array( $variation_id ) ),
				'exclude' => array( 'types' => array( 'variable' ) ),
			),
			'product excluded by variation'   => array(
				'include' => array( 'products' => array( $parent_id ) ),
				'exclude' => array( 'variations' => array( $variation_id ) ),
			),
			'category excluded by tag'        => array(
				'include' => array( 'categories' => array( $cat_id ) ),
				'exclude' => array( 'tags' => array( $tag_id ) ),
			),
			'tag excluded by category'        => array(
				'include' => array( 'tags' => array( $tag_id ) ),
				'exclude' => array( 'categories' => array( $cat_id ) ),
			),
			'match_all excluded by product'   => array(
				'match_all' => true,
				'exclude'   => array( 'products' => array( $parent_id ) ),
			),
		);

		$rule_ids = array();
		foreach ( $documents as $label => $document ) {
			$rule_ids[ $label ] = $this->make_rule(
				array(
					'name'      => $label,
					'targeting' => $document,
				)
			);
		}

		$result = $this->matcher()->evaluate( $order, $this->completed() );

		foreach ( $rule_ids as $label => $rule_id ) {
			$decision = $result->decision_for( $rule_id );
			$this->assertFalse( $decision->matched(), $label . ' was not excluded.' );
			$this->assertSame( MatchDecision::EXCLUDED_BY_RULE, $decision->reason(), $label . ' reported the wrong reason.' );

			// ADR-0005 REQUIRES that a rule which matched and was then excluded
			// is logged. This is the distinction a boolean engine could not
			// make.
			$this->assertTrue( $decision->loggable(), $label . ' would not be logged.' );
		}
	}

	/**
	 * 5. An exclusion that matches nothing leaves the include intact, so the
	 *    exclusion is what made the difference above.
	 *
	 * @return void
	 */
	public function test_a_non_matching_exclusion_changes_nothing() {
		$product_id = $this->make_simple_product( 'WCEP Not Excluded' );
		$other_id   = $this->make_simple_product( 'WCEP Elsewhere' );
		$order      = $this->make_order_with( array( $product_id ) );

		$rule_id = $this->make_rule(
			array(
				'targeting' => array(
					'include' => array( 'products' => array( $product_id ) ),
					'exclude' => array( 'products' => array( $other_id ) ),
				),
			)
		);

		$this->assertTrue( $this->matcher()->evaluate( $order, $this->completed() )->decision_for( $rule_id )->matched() );
	}

	/**
	 * 6. Quantity 5 matches ONCE.
	 *
	 * @return void
	 */
	public function test_quantity_five_matches_once() {
		$product_id = $this->make_simple_product( 'WCEP Bulk' );
		$order      = $this->make_order_with( array( array( $product_id, 5 ) ) );
		$rule_id    = $this->make_rule( array( 'targeting' => array( 'include' => array( 'products' => array( $product_id ) ) ) ) );

		$decision = $this->matcher()->evaluate( $order, $this->completed() )->decision_for( $rule_id );

		$this->assertCount( 1, $decision->matched_item_ids(), 'Quantity leaked into matching.' );
		$this->assertSame( array( $product_id ), $decision->matched_product_ids() );

		// The order really does carry quantity 5, so the assertion above is
		// about matching and not about a fixture that failed to set it.
		$quantities = array();
		foreach ( $order->get_items() as $item ) {
			$quantities[] = (int) $item->get_quantity();
		}
		$this->assertSame( array( 5 ), $quantities );
	}

	/**
	 * 6. Two separate line items of the same product yield TWO matched item
	 *    ids and ONE matched product id.
	 *
	 * @return void
	 */
	public function test_two_line_items_of_one_product() {
		$product_id = $this->make_simple_product( 'WCEP Twice' );

		// add_product() twice on one order creates two distinct line items.
		$order = wc_create_order();
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->add_product( wc_get_product( $product_id ), 2 );
		$order->set_billing_email( 'wcep-matching@example.test' );
		$order->calculate_totals();
		$order->save();
		$this->order_ids[] = (int) $order->get_id();

		$this->assertCount( 2, $order->get_items(), 'The fixture did not create two line items.' );

		$rule_id  = $this->make_rule( array( 'targeting' => array( 'include' => array( 'products' => array( $product_id ) ) ) ) );
		$decision = $this->matcher()->evaluate( $order, $this->completed() )->decision_for( $rule_id );

		$this->assertCount( 2, $decision->matched_item_ids(), 'Both line items should appear.' );
		$this->assertSame( array_keys( $order->get_items() ), $decision->matched_item_ids() );
		$this->assertSame( array( $product_id ), $decision->matched_product_ids(), 'Product ids should be de-duplicated.' );
	}

	/**
	 * 8. A deleted product yields `product_unavailable` for its item, does not
	 *    fatal, and the other items still evaluate.
	 *
	 * @return void
	 */
	public function test_deleted_product_yields_product_unavailable() {
		$doomed_id   = $this->make_simple_product( 'WCEP Doomed' );
		$survivor_id = $this->make_simple_product( 'WCEP Survivor' );

		$order    = $this->make_order_with( array( $doomed_id, $survivor_id ) );
		$item_ids = array_keys( $order->get_items() );

		$survivor_rule = $this->make_rule( array( 'targeting' => array( 'include' => array( 'products' => array( $survivor_id ) ) ) ) );
		$doomed_rule   = $this->make_rule( array( 'targeting' => array( 'include' => array( 'products' => array( $doomed_id ) ) ) ) );
		$all_rule      = $this->make_rule( array( 'targeting' => array( 'match_all' => true ) ) );

		wc_get_product( $doomed_id )->delete( true );
		$this->assertFalse( (bool) wc_get_product( $doomed_id ), 'The fixture product was not deleted.' );

		$result = $this->matcher()->evaluate( wc_get_order( $order->get_id() ), $this->completed() );

		$this->assertSame( array( $item_ids[0] ), $result->unavailable_item_ids() );
		$this->assertSame(
			array( $item_ids[0] => MatchDecision::PRODUCT_UNAVAILABLE ),
			$result->item_notes()
		);

		// The surviving item still evaluates, in both directions.
		$this->assertTrue( $result->decision_for( $survivor_rule )->matched() );
		$this->assertSame( array( $item_ids[1] ), $result->decision_for( $survivor_rule )->matched_item_ids() );
		$this->assertSame( MatchDecision::NO_TARGETING_MATCH, $result->decision_for( $doomed_rule )->reason() );

		// match_all matches the items that resolved, and only those: an item
		// that could not be resolved is skipped, never guessed at.
		$this->assertSame( array( $item_ids[1] ), $result->decision_for( $all_rule )->matched_item_ids() );

		// An order whose items are gone is NOT deferred: it has items, they are
		// simply unresolvable, and asking again later gets the same answer.
		$this->assertFalse( $result->deferred() );
	}

	/**
	 * 8. A deleted VARIATION also yields `product_unavailable` — the case that
	 *    a truthiness check on `wc_get_product()` silently gets wrong.
	 *
	 * WC_Product_Variation_Data_Store_CPT::read() returns SILENTLY for a
	 * missing post instead of throwing the way the ordinary product store
	 * does, so the factory hands back a hollow WC_Product_Variation. Believing
	 * it would report a deleted variation as one that simply matched nothing.
	 *
	 * @return void
	 */
	public function test_deleted_variation_yields_product_unavailable() {
		$variable     = $this->make_variable_product( 'WCEP Doomed Variable', array( 'Small' ) );
		$variation_id = $variable['variations'][0];

		$order    = $this->make_order_with( array( $variation_id ) );
		$item_ids = array_keys( $order->get_items() );
		$rule_id  = $this->make_rule( array( 'targeting' => array( 'match_all' => true ) ) );

		wc_get_product( $variable['parent'] )->delete( true );

		// Pin the WooCommerce behaviour this test exists for: the object still
		// constructs, and only the read flag reveals that it is hollow.
		$hollow = wc_get_product( $variation_id );
		$this->assertInstanceOf( \WC_Product_Variation::class, $hollow, 'WooCommerce changed: a deleted variation no longer constructs.' );
		$this->assertFalse( $hollow->get_object_read(), 'WooCommerce changed: a deleted variation now reports itself as read.' );

		$result = $this->matcher()->evaluate( wc_get_order( $order->get_id() ), $this->completed() );

		$this->assertSame( array( $item_ids[0] ), $result->unavailable_item_ids() );
		$this->assertSame( MatchDecision::NO_TARGETING_MATCH, $result->decision_for( $rule_id )->reason() );
	}

	/**
	 * 8. A deleted VARIATION whose PARENT SURVIVES is `partially_resolved`, not
	 *    unresolved and not silently inherited (ADR-0011 §4).
	 *
	 * The three-state matrix, proved end to end on one fixture:
	 *
	 * | Targeting        | Matches? | Why                                       |
	 * |------------------|----------|-------------------------------------------|
	 * | parent product   | yes      | id comes from the LINE ITEM               |
	 * | the variation id | yes      | id comes from the LINE ITEM               |
	 * | category         | yes      | terms come from the parent, as they always do |
	 * | tag              | yes      | as above                                  |
	 * | `types`          | NO       | the variation's slug and flags are UNKNOWN |
	 *
	 * Falling back to the parent for `types` is not merely degraded, it is
	 * affirmatively wrong: this fixture's variation is virtual and its parent is
	 * not, and `WC_Product_Variation::get_type()` returns `variation`, never
	 * `variable`. Skipping the item outright would be wrong too — the customer
	 * did buy it.
	 *
	 * @return void
	 */
	public function test_deleted_variation_with_surviving_parent_is_partially_resolved() {
		$cat_id = $this->make_term( 'product_cat', 'WCEP Orphan Cat' );
		$tag_id = $this->make_term( 'product_tag', 'WCEP Orphan Tag' );

		$variable = $this->make_variable_product(
			'WCEP Orphaned Variation',
			array( 'Small' ),
			array(
				'category_ids' => array( $cat_id ),
				'tag_ids'      => array( $tag_id ),
				'virtual'      => true,
			)
		);

		$parent_id    = $variable['parent'];
		$variation_id = $variable['variations'][0];

		$order    = $this->make_order_with( array( $variation_id ) );
		$item_ids = array_keys( $order->get_items() );

		// The facts the fallback would have produced, pinned BEFORE the delete:
		// the parent is not virtual and its slug is `variable`, so inheriting
		// either would be a fact that never applied to what was bought.
		$this->assertTrue( wc_get_product( $variation_id )->is_virtual() );
		$this->assertFalse( wc_get_product( $parent_id )->is_virtual() );
		$this->assertSame( 'variable', wc_get_product( $parent_id )->get_type() );

		$by_parent         = $this->make_rule( array( 'targeting' => array( 'include' => array( 'products' => array( $parent_id ) ) ) ) );
		$by_variation      = $this->make_rule( array( 'targeting' => array( 'include' => array( 'variations' => array( $variation_id ) ) ) ) );
		$by_category       = $this->make_rule( array( 'targeting' => array( 'include' => array( 'categories' => array( $cat_id ) ) ) ) );
		$by_tag            = $this->make_rule( array( 'targeting' => array( 'include' => array( 'tags' => array( $tag_id ) ) ) ) );
		$by_variable       = $this->make_rule( array( 'targeting' => array( 'include' => array( 'types' => array( 'variable' ) ) ) ) );
		$by_variation_type = $this->make_rule( array( 'targeting' => array( 'include' => array( 'types' => array( 'variation' ) ) ) ) );
		$by_virtual        = $this->make_rule( array( 'targeting' => array( 'include' => array( 'types' => array( 'virtual' ) ) ) ) );
		$by_download       = $this->make_rule( array( 'targeting' => array( 'include' => array( 'types' => array( 'downloadable' ) ) ) ) );

		// 1. Delete ONLY the variation, and confirm the parent still exists.
		wc_get_product( $variation_id )->delete( true );

		$surviving_parent = wc_get_product( $parent_id );
		$this->assertInstanceOf( \WC_Product::class, $surviving_parent, 'The parent must survive for this test to mean anything.' );
		$this->assertTrue( $surviving_parent->get_object_read() );
		$this->assertFalse( wc_get_product( $variation_id )->get_object_read(), 'The variation should no longer load.' );

		/*
		 * FLAGGED WOOCOMMERCE BEHAVIOUR — it CONTRADICTS ADR-0011 §4's premise
		 * that identity comes from the line item and therefore survives a
		 * deleted product. `WC_Order_Item_Product::set_variation_id()` rejects
		 * any id whose post type is not `product_variation`, and `set_props()`
		 * swallows the exception, so the CRUD accessor reports 0 while the
		 * stored meta still holds the real id. Left alone, the line item
		 * disguises itself as an ordinary parent-product purchase and the
		 * resolver hands targeting the PARENT's type slug and flags.
		 */
		$degraded = wc_get_order( $order->get_id() )->get_items()[ $item_ids[0] ];
		$this->assertSame(
			0,
			(int) $degraded->get_variation_id(),
			'WooCommerce changed: the CRUD accessor now reports a deleted variation id. Re-check ItemResolver::variation_id().'
		);
		$this->assertSame(
			$variation_id,
			(int) wc_get_order_item_meta( $item_ids[0], '_variation_id', true ),
			'The recorded variation id must survive in order-item meta, or nothing can recover it.'
		);

		$result = $this->matcher()->evaluate( wc_get_order( $order->get_id() ), $this->completed() );

		// 2. A parent-product rule matches the item.
		$this->assertTrue( $result->decision_for( $by_parent )->matched(), 'A parent-product rule stopped matching.' );
		$this->assertSame( array( $item_ids[0] ), $result->decision_for( $by_parent )->matched_item_ids() );

		// 3. Category and tag rules match the item.
		$this->assertTrue( $result->decision_for( $by_category )->matched(), 'A category rule stopped matching.' );
		$this->assertTrue( $result->decision_for( $by_tag )->matched(), 'A tag rule stopped matching.' );

		// 4. `types` rules — slug AND flag — do NOT match it.
		foreach ( array( $by_variable, $by_variation_type, $by_virtual, $by_download ) as $type_rule ) {
			$this->assertSame(
				MatchDecision::NO_TARGETING_MATCH,
				$result->decision_for( $type_rule )->reason(),
				'A types rule matched on facts that are unknowable for this item.'
			);
		}

		// 5. A variation-targeted rule still matches by id.
		$this->assertTrue( $result->decision_for( $by_variation )->matched(), 'The variation id comes from the LINE ITEM and must still match.' );
		$this->assertSame( array( $variation_id ), $result->decision_for( $by_variation )->matched_variation_ids() );

		// 6. The degradation is recorded with its own loggable note, distinct
		// from product_unavailable, and the item is NOT reported unavailable.
		$this->assertSame(
			array( $item_ids[0] => MatchDecision::VARIATION_UNAVAILABLE ),
			$result->item_notes()
		);
		$this->assertSame( array( $item_ids[0] ), $result->partially_resolved_item_ids() );
		$this->assertSame( array(), $result->unavailable_item_ids(), 'A degraded item is not an unavailable one.' );
		$this->assertTrue( MatchDecision::LOGGABLE[ MatchDecision::VARIATION_UNAVAILABLE ] );

		$this->assertFalse( $result->deferred() );
	}

	/**
	 * 8. Deleting the PARENT as well collapses the item to the third state:
	 *    `product_unavailable`, and the item is skipped.
	 *
	 * @return void
	 */
	public function test_deleting_the_parent_too_yields_product_unavailable() {
		$cat_id = $this->make_term( 'product_cat', 'WCEP Both Gone Cat' );

		$variable = $this->make_variable_product(
			'WCEP Both Gone',
			array( 'Small' ),
			array( 'category_ids' => array( $cat_id ) )
		);

		$order    = $this->make_order_with( array( $variable['variations'][0] ) );
		$item_ids = array_keys( $order->get_items() );

		$by_parent   = $this->make_rule( array( 'targeting' => array( 'include' => array( 'products' => array( $variable['parent'] ) ) ) ) );
		$by_category = $this->make_rule( array( 'targeting' => array( 'include' => array( 'categories' => array( $cat_id ) ) ) ) );
		$by_all      = $this->make_rule( array( 'targeting' => array( 'match_all' => true ) ) );

		wc_get_product( $variable['variations'][0] )->delete( true );
		wc_get_product( $variable['parent'] )->delete( true );

		$result = $this->matcher()->evaluate( wc_get_order( $order->get_id() ), $this->completed() );

		$this->assertSame( array( $item_ids[0] => MatchDecision::PRODUCT_UNAVAILABLE ), $result->item_notes() );
		$this->assertSame( array( $item_ids[0] ), $result->unavailable_item_ids() );
		$this->assertSame( array(), $result->partially_resolved_item_ids() );

		// The item is SKIPPED, so even an id rule finds nothing to match.
		foreach ( array( $by_parent, $by_category, $by_all ) as $rule_id ) {
			$this->assertSame( MatchDecision::NO_TARGETING_MATCH, $result->decision_for( $rule_id )->reason() );
		}

		$this->assertFalse( $result->deferred() );
	}

	/**
	 * 8. An order whose only product was deleted still evaluates, still is not
	 *    deferred, and every rule reports the noise-floor reason.
	 *
	 * @return void
	 */
	public function test_order_with_only_deleted_products() {
		$product_id = $this->make_simple_product( 'WCEP Sole Doomed' );
		$order      = $this->make_order_with( array( $product_id ) );
		$rule_id    = $this->make_rule( array( 'targeting' => array( 'match_all' => true ) ) );

		wc_get_product( $product_id )->delete( true );

		$result = $this->matcher()->evaluate( wc_get_order( $order->get_id() ), $this->completed() );

		$this->assertFalse( $result->deferred() );
		$this->assertCount( 1, $result->unavailable_item_ids() );
		$this->assertSame( MatchDecision::NO_TARGETING_MATCH, $result->decision_for( $rule_id )->reason() );
		$this->assertFalse( $result->decision_for( $rule_id )->loggable(), 'The noise floor must stay silent.' );
	}
}
