<?php
/**
 * THE FAN-OUT PLANNER (ADR-0016 §1, §1a, §4, §7).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Extonify\WCEP\Delivery\Consolidation;
use Extonify\WCEP\Domain\Targeting;

/**
 * How many messages one decision becomes, and what each of them is about.
 *
 * A UNIT TEST, because `Consolidation::plan()` is pure by design — array in, array
 * out, no WordPress — and this is the code that decides how many emails a customer
 * receives. The cap READ is the one WordPress-facing part and lives in the
 * integration suite, where the real filter runs.
 */
final class ConsolidationTest extends UnitTestCase {

	/**
	 * A matched item record, in the shape `Matching\ItemResolver` produces.
	 *
	 * @param int    $item_id      Line-item id.
	 * @param int    $product_id   Parent product id.
	 * @param int    $variation_id Variation id, or 0.
	 * @param string $resolution   Resolution state, or '' to omit the key entirely —
	 *                             which is what the SCHEDULED path's records look like.
	 * @return array
	 */
	private function item( int $item_id, int $product_id, int $variation_id = 0, string $resolution = Targeting::RESOLVED ): array {
		$record = array(
			'item_id'      => $item_id,
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
		);

		if ( '' !== $resolution ) {
			$record['resolution'] = $resolution;
		}

		return $record;
	}

	/**
	 * A rule row asking for one consolidation.
	 *
	 * @param string|null $consolidation Value, or null to omit the column.
	 * @return array
	 */
	private function rule( ?string $consolidation ): array {
		return null === $consolidation ? array( 'id' => 1 ) : array( 'id' => 1, 'consolidation' => $consolidation );
	}

	/**
	 * The unit keys a plan produced, in message order.
	 *
	 * @param array $plan Plan.
	 * @return string[]
	 */
	private function units( array $plan ): array {
		return array_column( $plan['messages'], 'unit' );
	}

	/**
	 * ⚠ THE VOCABULARY IS EXHAUSTIVE (ADR-0016 §1).
	 *
	 * @return void
	 */
	public function test_the_vocabulary_is_exactly_two_members() {
		$this->assertSame( array( 'none', 'per_product' ), Consolidation::MODES );

		foreach ( Consolidation::MODES as $valid ) {
			$this->assertTrue( Consolidation::is_valid( $valid ) );
		}

		/*
		 * ⚠ `per_order` IS NOT A MEMBER, AND ITS ABSENCE IS ASSERTED RATHER THAN
		 * IMPLIED. Cross-rule merging merges across DIFFERENT delivery identities with
		 * different subjects, recipients and priorities; adding it as a third value here
		 * would design that feature by accident. It needs a superseding ADR.
		 */
		foreach ( array( 'daily', 'weekly', 'per_order', 'PER_PRODUCT', 'per_product!', ' per_product ', 'per product', '', 'none ', 'anything' ) as $invalid ) {
			$this->assertFalse( Consolidation::is_valid( $invalid ), "\"{$invalid}\" is inside the vocabulary" );
		}
	}

	/**
	 * TEST 1 — `none` PLANS EXACTLY ONE MESSAGE CARRYING EVERY MATCHED ITEM, and its
	 *          binding is ADR-0014 §5's unchanged one.
	 *
	 * @return void
	 */
	public function test_none_plans_one_combined_message() {
		$items = array( $this->item( 1, 101 ), $this->item( 2, 102 ), $this->item( 3, 103 ) );

		foreach ( array( 'none', null ) as $stored ) {
			$plan = Consolidation::plan( $this->rule( $stored ), $items, 10 );

			$this->assertCount( 1, $plan['messages'] );
			$this->assertSame( 'none', $plan['mode'] );
			$this->assertFalse( $plan['capped'] );
			$this->assertSame( array(), $plan['notes'], 'a plain `none` rule recorded a note' );
			$this->assertFalse( Consolidation::is_fan_out( $plan ) );

			$message = $plan['messages'][0];

			$this->assertSame( '', $message['unit'], 'a combined message named one product' );
			$this->assertSame( 0, $message['item_id'], '⚠ the ADR-0014 §5 first-matched-item binding changed' );
			$this->assertSame( 1, $message['index'] );
			$this->assertSame( 1, $message['count'] );
			$this->assertSame( $items, $message['items'], 'the combined message lost matched items' );

			// ⚠ AND IT WRITES NO SNAPSHOT PAYLOAD (ADR-0016 §5). A rule that never asked
			// for consolidation must produce byte-identical rows to the ones it produced
			// before this ADR existed.
			$this->assertSame( array(), Consolidation::snapshot_for( $plan, $message ) );
		}
	}

	/**
	 * TEST 2 — `per_product` PLANS ONE MESSAGE PER DISTINCT PRODUCT, in line-item
	 *          order.
	 *
	 * @return void
	 */
	public function test_per_product_plans_one_message_per_distinct_product() {
		$plan = Consolidation::plan(
			$this->rule( 'per_product' ),
			array( $this->item( 1, 101 ), $this->item( 2, 102 ), $this->item( 3, 103 ) ),
			10
		);

		$this->assertTrue( Consolidation::is_fan_out( $plan ) );
		$this->assertSame( 'per_product', $plan['mode'] );
		$this->assertSame( 3, $plan['units'] );
		$this->assertSame( array( 'product:101', 'product:102', 'product:103' ), $this->units( $plan ) );

		foreach ( $plan['messages'] as $offset => $message ) {
			$this->assertSame( $offset + 1, $message['index'] );
			$this->assertSame( 3, $message['count'] );
			$this->assertSame( $offset + 1, $message['item_id'], 'a message bound to another message\'s line item' );
			$this->assertCount( 1, $message['items'] );
		}
	}

	/**
	 * TEST 3 — QUANTITY AND DUPLICATE LINE ITEMS ARE IRRELEVANT (ADR-0011 §3,
	 *          ADR-0016 §4).
	 *
	 * ⚠ THE LOAD-BEARING CASE FOR "one email per product". A rule that matched once
	 * must not become five emails because a customer bought five, and two line items of
	 * one product are one product. Quantity never reaches the planner at all, which is
	 * the strongest form of "irrelevant" available — so the duplicate-line-item half is
	 * what this actually proves.
	 *
	 * @return void
	 */
	public function test_duplicate_line_items_of_one_product_plan_one_message() {
		$plan = Consolidation::plan(
			$this->rule( 'per_product' ),
			// Two line items of product 101 — the shape a re-added cart line produces —
			// plus one of 102.
			array( $this->item( 1, 101 ), $this->item( 2, 101 ), $this->item( 3, 102 ) ),
			10
		);

		$this->assertSame( array( 'product:101', 'product:102' ), $this->units( $plan ) );
		$this->assertSame( 2, $plan['units'] );

		// BOTH LINE ITEMS BELONG TO THE UNIT, and the REPRESENTATIVE is the first in
		// line-item order — so all seven singular placeholders describe the same line
		// rather than a mixture (ADR-0016 §6).
		$this->assertCount( 2, $plan['messages'][0]['items'] );
		$this->assertSame( 1, $plan['messages'][0]['item_id'] );
	}

	/**
	 * TEST 4 — VARIATIONS. Two variations of one parent are TWO units; a partially
	 *          resolved one falls back to the parent (ADR-0016 §4).
	 *
	 * @return void
	 */
	public function test_variations_are_distinct_units_and_a_dead_one_falls_back_to_its_parent() {
		// Two live variations of parent 200.
		$live = Consolidation::plan(
			$this->rule( 'per_product' ),
			array( $this->item( 1, 200, 201 ), $this->item( 2, 200, 202 ) ),
			10
		);

		$this->assertSame( array( 'variation:201', 'variation:202' ), $this->units( $live ) );
		$this->assertSame( 201, $live['messages'][0]['variation_id'] );
		$this->assertSame( 200, $live['messages'][0]['product_id'], 'the unit lost its parent product id' );

		// A DEAD VARIATION: `partially_resolved`, id still recorded on the item.
		$dead = Consolidation::plan(
			$this->rule( 'per_product' ),
			array( $this->item( 1, 200, 201, Targeting::PARTIALLY_RESOLVED ) ),
			10
		);

		$this->assertSame( array( 'product:200' ), $this->units( $dead ) );
		$this->assertSame(
			0,
			$dead['messages'][0]['variation_id'],
			'⚠ the unit is the PARENT, so reporting a variation id would name a variation nobody can identify'
		);

		// TWO DEAD VARIATIONS OF ONE PARENT COLLAPSE — neither can be told from the
		// other, so there is nothing for two messages to say differently.
		$both_dead = Consolidation::plan(
			$this->rule( 'per_product' ),
			array(
				$this->item( 1, 200, 201, Targeting::PARTIALLY_RESOLVED ),
				$this->item( 2, 200, 202, Targeting::PARTIALLY_RESOLVED ),
			),
			10
		);

		$this->assertSame( array( 'product:200' ), $this->units( $both_dead ) );

		// A DEAD ONE BESIDE A LIVE SIBLING IS TWO UNITS. It looks asymmetric until you
		// notice that only one of them is identifiable.
		$mixed = Consolidation::plan(
			$this->rule( 'per_product' ),
			array(
				$this->item( 1, 200, 201, Targeting::PARTIALLY_RESOLVED ),
				$this->item( 2, 200, 202 ),
			),
			10
		);

		$this->assertSame( array( 'product:200', 'variation:202' ), $this->units( $mixed ) );
	}

	/**
	 * ⚠ THE SCHEDULED PATH'S RECORDS CARRY NO `resolution`, AND REACH THE SAME UNIT BY
	 * THE OPPOSITE ROUTE (ADR-0016 §4).
	 *
	 * `ScheduledDelivery::surviving_items()` builds records from
	 * `WC_Order_Item_Product::get_variation_id()`, which returns **0** for a deleted
	 * variation on WC 10.9.4 — the behaviour ADR-0011 §4 flags. So a dead variation
	 * arrives with no variation id and no resolution state, and lands on the parent
	 * unit; the immediate path arrives with the id RECOVERED and marked
	 * `partially_resolved`, and lands on the same one. Both are asserted, because the
	 * feature depends on them agreeing.
	 *
	 * @return void
	 */
	public function test_a_record_without_a_resolution_key_is_treated_as_resolved() {
		// Live variation, scheduled-path shape: no `resolution` key.
		$live = Consolidation::plan(
			$this->rule( 'per_product' ),
			array( $this->item( 1, 200, 202, '' ) ),
			10
		);

		$this->assertSame( array( 'variation:202' ), $this->units( $live ) );

		// Dead variation, scheduled-path shape: `get_variation_id()` gave 0.
		$dead = Consolidation::plan(
			$this->rule( 'per_product' ),
			array( $this->item( 1, 200, 0, '' ) ),
			10
		);

		$this->assertSame(
			array( 'product:200' ),
			$this->units( $dead ),
			'the scheduled path reached a different unit than the immediate path for the same order'
		);
	}

	/**
	 * A record with neither id groups PER LINE ITEM, never merged.
	 *
	 * Defensive: with nothing to group by, merging unrelated items would attribute one
	 * product's content to another — which is the one thing a fan-out must never do.
	 *
	 * @return void
	 */
	public function test_a_record_with_no_ids_is_its_own_unit() {
		$plan = Consolidation::plan(
			$this->rule( 'per_product' ),
			array( $this->item( 7, 0, 0 ), $this->item( 8, 0, 0 ) ),
			10
		);

		$this->assertSame( array( 'item:7', 'item:8' ), $this->units( $plan ) );
	}

	/**
	 * TEST 9 — THE CAP FALLS BACK TO ONE MESSAGE AND RECORDS THE COUNT (ADR-0016 §7).
	 *
	 * ⚠ FALLBACK, NOT TRUNCATION. Truncating to the first N silently loses products the
	 * merchant asked to have mentioned; sending N is the failure the cap exists to
	 * prevent. ONE message carrying every unit's content keeps the intent — a change of
	 * SHAPE, not a loss of CONTENT. See
	 * self::test_the_capped_message_carries_one_section_per_unit() for the mechanism that
	 * makes "every unit" true whatever the template says.
	 *
	 * @return void
	 */
	public function test_over_the_cap_it_falls_back_to_one_message_and_records_the_count() {
		$items = array();

		for ( $i = 1; $i <= 12; $i++ ) {
			$items[] = $this->item( $i, 100 + $i );
		}

		$plan = Consolidation::plan( $this->rule( 'per_product' ), $items, 10 );

		$this->assertCount( 1, $plan['messages'] );
		$this->assertFalse( Consolidation::is_fan_out( $plan ) );
		$this->assertSame( 'none', $plan['mode'], 'the fallback is `none`\'s behaviour' );
		$this->assertSame( 'per_product', $plan['requested'], 'the fallback forgot what was asked for' );
		$this->assertTrue( $plan['capped'] );
		$this->assertSame( 12, $plan['units'] );

		// EVERY MATCHED ITEM IS STILL IN THE MESSAGE — nothing was truncated away.
		$this->assertCount( 12, $plan['messages'][0]['items'] );

		// THE FALLBACK IS RECORDED, WITH THE COUNT, IN BOTH PLACES A MERCHANT LOOKS.
		$note = Consolidation::note_line( $plan );
		$this->assertStringContainsString( '12 matched products exceeds the cap of 10', $note );

		$snapshot = Consolidation::snapshot_for( $plan, $plan['messages'][0] )['consolidation'];
		$this->assertSame( 'cap_exceeded', $snapshot['fallback'] );
		$this->assertSame( 12, $snapshot['units'] );
		$this->assertSame( 10, $snapshot['cap'] );
		$this->assertSame( 'per_product', $snapshot['requested'] );
		$this->assertSame( 'none', $snapshot['mode'] );
	}

	/**
	 * 8A-1 / gate 25. THE CAPPED MESSAGE CARRIES ONE **SECTION PER UNIT**, which is
	 *                 what makes the fallback contain every matched product
	 *                 (ADR-0016 §7).
	 *
	 * ⚠ THE ASSERTION PROMPT 8 WAS MISSING. Its capped test asserted that every product
	 * name appeared in the rendered body — against a template containing
	 * `ALL=[{product_names}]`. The products were there because the TEMPLATE asked for
	 * them, not because the mechanism put them there, so a fixture carrying the very
	 * placeholder whose absence is the failure mode could not detect the failure mode.
	 * This asserts the mechanism directly: the plan must hand the renderer one binding
	 * per unit, in line-item order, whatever any template says.
	 *
	 * @return void
	 */
	public function test_the_capped_message_carries_one_section_per_unit() {
		$items = array();

		for ( $i = 1; $i <= 12; $i++ ) {
			$items[] = $this->item( $i, 100 + $i );
		}

		$plan    = Consolidation::plan( $this->rule( 'per_product' ), $items, 10 );
		$message = $plan['messages'][0];

		$this->assertTrue( $plan['capped'] );
		$this->assertCount( 1, $plan['messages'], 'the fallback is still ONE message' );

		// ONE SECTION PER UNIT, in line-item order, each with its own binding.
		$this->assertCount( 12, $message['sections'], 'the capped message lost a unit' );
		$this->assertSame(
			array_map(
				static function ( int $i ): string {
					return 'product:' . ( 100 + $i );
				},
				range( 1, 12 )
			),
			array_column( $message['sections'], 'unit' )
		);
		$this->assertSame( range( 1, 12 ), array_column( $message['sections'], 'item_id' ) );

		/*
		 * ⚠ AND THE MESSAGE'S OWN BINDING STAYS ZERO. The SUBJECT and the HEADING bind
		 * through it and can carry one value, so they bind to the first unit — which is
		 * exactly what `none` does, so the fallback introduces no new header semantics.
		 */
		$this->assertSame( 0, $message['item_id'] );
	}

	/**
	 * 8B / gate 27. ONLY THE FIRST `cap` SECTIONS CARRY THE MERCHANT'S BODY; THE REST
	 *               ARE LABEL ONLY (ADR-0016 §7a).
	 *
	 * ⚠ THE FIX FOR A QUADRATIC, DECIDED IN THE PURE PLANNER. A section's body may
	 * contain a full-set plural — `{product_names}`, `{matched_product_list}` — which
	 * resolves to ALL N matched products (§6). Rendering the body in every section
	 * therefore put N × N list entries in ONE message: ~3,600 entries for a sixty-line
	 * wholesale order, past the size at which mail clients clip. The bound is the CAP,
	 * because the fallback moved the repetition out of N messages and into one body and
	 * 8A moved it without moving the limit with it.
	 *
	 * ⚠ AND EVERY UNIT IS STILL A SECTION. The bound decides what a section CARRIES,
	 * never whether a unit HAS one — a labelled section still names its product, which
	 * is the requirement the sections exist to satisfy.
	 *
	 * @return void
	 */
	public function test_only_the_first_cap_sections_carry_the_body() {
		$items = array();

		for ( $i = 1; $i <= 25; $i++ ) {
			$items[] = $this->item( $i, 100 + $i );
		}

		$plan     = Consolidation::plan( $this->rule( 'per_product' ), $items, 10 );
		$sections = $plan['messages'][0]['sections'];

		$this->assertTrue( $plan['capped'] );

		// EVERY UNIT HAS A SECTION — nothing is truncated away.
		$this->assertCount( 25, $sections, 'the bound dropped a unit instead of dropping its body' );

		// THE FIRST TEN CARRY THE BODY, IN LINE-ITEM ORDER.
		$this->assertSame(
			array_merge( array_fill( 0, 10, true ), array_fill( 0, 15, false ) ),
			array_column( $sections, 'content' ),
			'the body-carrying sections are not the first `cap` in line-item order'
		);

		$this->assertSame( 10, Consolidation::rendered_sections( 25, 10 ) );

		// AND THE COUNT IS RECORDED IN BOTH PLACES A MERCHANT LOOKS.
		$this->assertStringContainsString(
			'the body is rendered for the first 10 and the remaining 15 are named',
			Consolidation::note_line( $plan )
		);
		$this->assertSame(
			10,
			Consolidation::snapshot_for( $plan, $plan['messages'][0] )['consolidation']['rendered']
		);
	}

	/**
	 * 8B. THE BOUND FOLLOWS THE CAP IN FORCE, INCLUDING A FILTERED ONE.
	 *
	 * One knob, not two (ADR-0016 §7a). A merchant who raises the cap raises the number
	 * of messages a fan-out may send AND the number of sections the fallback may render,
	 * because they are the same judgement about the same customer.
	 *
	 * @return void
	 */
	public function test_the_section_bound_follows_the_cap_in_force() {
		$items = array();

		for ( $i = 1; $i <= 30; $i++ ) {
			$items[] = $this->item( $i, 100 + $i );
		}

		foreach ( array( 1, 2, 10, 25 ) as $cap ) {
			$plan     = Consolidation::plan( $this->rule( 'per_product' ), $items, $cap );
			$sections = $plan['messages'][0]['sections'];
			$carrying = array_filter( array_column( $sections, 'content' ) );

			$this->assertCount( 30, $sections, 'cap ' . $cap . ' lost a unit' );
			$this->assertCount( $cap, $carrying, 'cap ' . $cap . ' rendered the wrong number of bodies' );
		}

		/*
		 * ⚠ AT LEAST ONE, ALWAYS. `plan()` floors the cap at 1 and so does the bound: a
		 * fallback that rendered the merchant's body ZERO times would be a message
		 * containing nothing but a list of names, which is the appended-block mechanism
		 * ADR-0016 §7 rejected as the primary answer.
		 */
		$this->assertSame( 1, Consolidation::rendered_sections( 30, 0 ) );
		$this->assertSame( 1, Consolidation::rendered_sections( 30, -5 ) );

		// AND IT NEVER EXCEEDS THE UNIT COUNT.
		$this->assertSame( 3, Consolidation::rendered_sections( 3, 10 ) );
		$this->assertSame( 0, Consolidation::rendered_sections( 0, 10 ) );
	}

	/**
	 * 8A-1. `none` AND A REAL FAN-OUT CARRY **NO** SECTIONS.
	 *
	 * The sectioned renderer is the cap fallback's alone: `none` must render exactly as
	 * it did before ADR-0016 existed, and a real fan-out message IS one unit, so there
	 * is nothing to section.
	 *
	 * @return void
	 */
	public function test_only_the_capped_fallback_carries_sections() {
		$items = array( $this->item( 1, 101 ), $this->item( 2, 102 ) );

		foreach ( array( 'none', null ) as $stored ) {
			$plan = Consolidation::plan( $this->rule( $stored ), $items, 10 );

			$this->assertSame( array(), $plan['messages'][0]['sections'], 'a `none` rule gained sections' );
		}

		$fanned = Consolidation::plan( $this->rule( 'per_product' ), $items, 10 );

		$this->assertCount( 2, $fanned['messages'] );

		foreach ( $fanned['messages'] as $message ) {
			$this->assertSame( array(), $message['sections'], 'a real fan-out message gained sections' );
		}

		// The key is DECLARED on every message, so no reader has to guess.
		$this->assertArrayHasKey( 'sections', $fanned['messages'][0] );
	}

	/**
	 * 8A-2 / gate 26. THE READ BOUNDARY: a rule row's value is judged, not repaired.
	 *
	 * `has_valid_value()` is the mechanism both separate-mode phase filters call, and it
	 * is deliberately SEPARATE from `behaviour_is_implemented()` — see ADR-0016 §1a's
	 * distinction table. This asserts the predicate itself; the integration suite
	 * asserts that both phases actually apply it.
	 *
	 * @return void
	 */
	public function test_the_read_boundary_judges_a_rule_row() {
		foreach ( array( 'none', 'per_product' ) as $valid ) {
			$this->assertTrue( Consolidation::has_valid_value( array( 'consolidation' => $valid ) ) );
		}

		// An ABSENT column is the documented default and is deliverable — the boundary
		// must not reject a rule for a column it never carried.
		$this->assertTrue( Consolidation::has_valid_value( array( 'id' => 1 ) ) );

		/*
		 * ⚠ `daily`, `weekly` AND `per_order` WERE LEGITIMATELY STORABLE from Prompt 5B
		 * to Prompt 8, so these are ordinary upgrade-path rows, not direct-SQL exotica.
		 */
		foreach ( array( 'daily', 'weekly', 'per_order', 'PER_PRODUCT', '', 'anything' ) as $invalid ) {
			$this->assertFalse(
				Consolidation::has_valid_value( array( 'consolidation' => $invalid ) ),
				"\"{$invalid}\" passed the read boundary"
			);
		}

		// A non-scalar column cannot throw its way past the boundary either.
		$this->assertFalse( Consolidation::has_valid_value( array( 'consolidation' => array( 'none' ) ) ) );
	}

	/**
	 * A RAISED CAP FANS OUT FULLY, so the fallback above is about the CAP and not
	 * about the planner refusing to fan out at all.
	 *
	 * @return void
	 */
	public function test_a_raised_cap_fans_out_fully() {
		$items = array();

		for ( $i = 1; $i <= 12; $i++ ) {
			$items[] = $this->item( $i, 100 + $i );
		}

		$plan = Consolidation::plan( $this->rule( 'per_product' ), $items, 20 );

		$this->assertCount( 12, $plan['messages'] );
		$this->assertFalse( $plan['capped'] );
		$this->assertSame( array(), $plan['notes'] );
	}

	/**
	 * The cap is FLOORED AT 1 inside the planner too, so a nonsense cap cannot plan
	 * zero messages.
	 *
	 * A delivery that sends nothing because of an arithmetic accident is a lost email.
	 *
	 * @return void
	 */
	public function test_a_nonsense_cap_still_plans_a_message() {
		foreach ( array( 0, -5 ) as $cap ) {
			$plan = Consolidation::plan(
				$this->rule( 'per_product' ),
				array( $this->item( 1, 101 ), $this->item( 2, 102 ) ),
				$cap
			);

			$this->assertCount( 1, $plan['messages'], 'a cap of ' . $cap . ' planned the wrong number of messages' );
			$this->assertTrue( $plan['capped'] );
			$this->assertSame( 1, $plan['cap'] );
		}
	}

	/**
	 * A cap of exactly 1 on a single-unit order is NOT a fallback: one unit does not
	 * exceed one.
	 *
	 * @return void
	 */
	public function test_one_unit_under_a_cap_of_one_is_a_normal_single_message() {
		$plan = Consolidation::plan( $this->rule( 'per_product' ), array( $this->item( 1, 101 ) ), 1 );

		$this->assertCount( 1, $plan['messages'] );
		$this->assertFalse( $plan['capped'] );
		$this->assertSame( 'per_product', $plan['mode'] );
		$this->assertSame( 'product:101', $plan['messages'][0]['unit'] );
		$this->assertSame( 1, $plan['messages'][0]['item_id'], 'a single per_product message lost its binding' );
	}

	/**
	 * ADR-0016 §1a — AN UNRECOGNISED STORED VALUE PLANS ONE MESSAGE AND SAYS SO.
	 *
	 * Defence in depth, not a repair: the enumeration is enforced at the WRITE
	 * boundary, so a value reaching here came from direct SQL. Fanning out on it would
	 * guess at a count; leaving the rule inert would send NOTHING, and a rule that is
	 * active, matching and silently invisible is the failure ADR-0009 cites when
	 * explaining why `insert_position` repairs.
	 *
	 * @dataProvider unrecognised_provider
	 *
	 * @param string $stored Stored value.
	 * @return void
	 */
	public function test_an_unrecognised_stored_value_plans_one_explained_message( string $stored ) {
		$plan = Consolidation::plan(
			$this->rule( $stored ),
			array( $this->item( 1, 101 ), $this->item( 2, 102 ) ),
			10
		);

		$this->assertCount( 1, $plan['messages'] );
		$this->assertSame( 'none', $plan['mode'] );
		$this->assertSame( $stored, $plan['requested'] );
		$this->assertCount( 2, $plan['messages'][0]['items'], 'the combined message lost matched items' );

		$this->assertStringContainsString( 'unrecognised consolidation', Consolidation::note_line( $plan ) );
		$this->assertStringContainsString( $stored, Consolidation::note_line( $plan ) );

		// AND IT IS RECORDED IN THE SNAPSHOT, because `requested` is not `none`.
		$this->assertSame( $stored, Consolidation::snapshot_for( $plan, $plan['messages'][0] )['consolidation']['requested'] );
	}

	/**
	 * Values only a hand-edited database can produce.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function unrecognised_provider(): array {
		return array(
			'daily'     => array( 'daily' ),
			'weekly'    => array( 'weekly' ),
			'per_order' => array( 'per_order' ),
			'garbage'   => array( 'x' ),
		);
	}

	/**
	 * A non-scalar column reads as unrecognised rather than throwing.
	 *
	 * @return void
	 */
	public function test_a_non_scalar_stored_value_is_unrecognised() {
		$this->assertSame( '', Consolidation::requested( array( 'consolidation' => array( 'per_product' ) ) ) );

		$plan = Consolidation::plan(
			array( 'consolidation' => array( 'per_product' ) ),
			array( $this->item( 1, 101 ) ),
			10
		);

		$this->assertCount( 1, $plan['messages'] );
		$this->assertSame( 'none', $plan['mode'] );
	}

	/**
	 * `per_product` with NO matched items still plans a message.
	 *
	 * Unreachable in separate mode — a matched decision has matched items, and insert
	 * mode's non-targeted case cannot consolidate (ADR-0016 §2) — but nothing about "no
	 * items" makes sending nothing the right answer for a rule that matched.
	 *
	 * @return void
	 */
	public function test_per_product_with_no_matched_items_still_plans_one_message() {
		$plan = Consolidation::plan( $this->rule( 'per_product' ), array(), 10 );

		$this->assertCount( 1, $plan['messages'] );
		$this->assertSame( 'none', $plan['mode'] );
		$this->assertSame( 0, $plan['units'] );
	}

	/**
	 * Non-array junk inside `matched_items` is skipped rather than fatal.
	 *
	 * @return void
	 */
	public function test_junk_matched_records_are_skipped() {
		$plan = Consolidation::plan(
			$this->rule( 'per_product' ),
			array( $this->item( 1, 101 ), 'not an array', 42, $this->item( 2, 102 ) ),
			10
		);

		$this->assertSame( array( 'product:101', 'product:102' ), $this->units( $plan ) );
	}

	/**
	 * ⚠ THE MESSAGE INDEX IS ITS OWN AXIS AND NEVER THE `attempt` COLUMN
	 * (ADR-0016 §5).
	 *
	 * `attempt` means "attempt N at this delivery" — ADR-0004's manual resend chain —
	 * and the N messages of a fan-out are ONE attempt at one decision. Two orthogonal
	 * facts in one column is the failure shape this project has hit three times.
	 *
	 * @return void
	 */
	public function test_the_snapshot_payload_carries_the_fan_out_axis() {
		$plan = Consolidation::plan(
			$this->rule( 'per_product' ),
			array( $this->item( 1, 101 ), $this->item( 2, 200, 202 ) ),
			10
		);

		$first  = Consolidation::snapshot_for( $plan, $plan['messages'][0] )['consolidation'];
		$second = Consolidation::snapshot_for( $plan, $plan['messages'][1] )['consolidation'];

		$this->assertSame( 1, $first['index'] );
		$this->assertSame( 2, $first['count'] );
		$this->assertSame( 'product:101', $first['unit'] );
		$this->assertSame( 101, $first['product_id'] );
		$this->assertArrayNotHasKey( 'variation_id', $first, 'a non-variation unit reported a variation id' );
		$this->assertArrayNotHasKey( 'attempt', $first, '⚠ the fan-out axis leaked into the attempt axis' );
		$this->assertArrayNotHasKey( 'fallback', $first, 'an uncapped plan recorded a fallback' );

		$this->assertSame( 2, $second['index'] );
		$this->assertSame( 'variation:202', $second['unit'] );
		$this->assertSame( 202, $second['variation_id'] );
		$this->assertSame( 200, $second['product_id'] );
	}
}
