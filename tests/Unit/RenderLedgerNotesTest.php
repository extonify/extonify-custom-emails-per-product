<?php
/**
 * The per-rule note collection: exactly keyed, capped, flattened only at
 * storage (ADR-0014 §1d, gates 10 and 14).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Extonify\WCEP\Render\RenderLedger;

/**
 * A DIAGNOSTIC THAT GROWS WITHOUT LIMIT IS A MEMORY LEAK WEARING A USEFUL NAME.
 *
 * `PlaceholderValues` caps its notes at 20 PER VALUE SET, and at the per-item
 * injection position a rule gets a FRESH value set per line item — so the
 * rule-level collection here saw up to 20 × the number of matched items, and had
 * no cap of its own. Prompt 5B capped every other collection in the render path;
 * this one was added afterwards and was missed.
 *
 * The de-duplication was wrong in a second, quieter way: `strpos()` asked "does
 * the accumulated string CONTAIN this note", so a distinct note that happened to
 * be a substring of an earlier one was silently discarded.
 *
 * Unit-testable because `RenderLedger` is framework-free apart from a
 * `function_exists`-guarded logger call — so this exercises the REAL collection
 * rather than an approximation of it.
 */
final class RenderLedgerNotesTest extends UnitTestCase {

	/**
	 * A ledger with one open, registrable slot.
	 *
	 * @param string $token Render token.
	 * @return RenderLedger
	 */
	private function ledger( string $token = 'tok-1' ): RenderLedger {
		$ledger = new RenderLedger();
		$ledger->open( $token, new \stdClass(), 55, 'customer_processing_order' );

		return $ledger;
	}

	/**
	 * The single rule entry a ledger is holding.
	 *
	 * @param RenderLedger $ledger  Ledger.
	 * @param int          $rule_id Rule id.
	 * @return array
	 */
	private function entry( RenderLedger $ledger, int $rule_id = 7 ): array {
		$slots = $ledger->slots();

		return $slots[0]['rules'][ $rule_id ];
	}

	/**
	 * ⚠ A DISTINCT NOTE THAT IS A SUBSTRING OF AN EARLIER ONE SURVIVES.
	 *
	 * The exact failure the `strpos()` test produced: `unknown placeholder {a}` is
	 * a substring of `unknown placeholder {ab}`, so registering the longer one
	 * first made the shorter one vanish — a real, distinct authoring mistake that
	 * the merchant was then never told about.
	 *
	 * @return void
	 */
	public function test_a_note_that_is_a_substring_of_another_is_not_dropped() {
		$ledger = $this->ledger();

		$ledger->register( 'tok-1', 7, 1, 'item_meta', 'unknown placeholder {ab}' );
		$ledger->register( 'tok-1', 7, 1, 'item_meta', 'unknown placeholder {a}' );

		$this->assertSame(
			'unknown placeholder {ab}; unknown placeholder {a}',
			RenderLedger::notes_line( $this->entry( $ledger ) ),
			'a distinct note was swallowed because it was a substring of another'
		);
	}

	/**
	 * An EXACT repeat is still recorded once.
	 *
	 * @return void
	 */
	public function test_an_exact_repeat_is_recorded_once() {
		$ledger = $this->ledger();

		$ledger->register( 'tok-1', 7, 1, 'item_meta', 'unknown placeholder {x}' );
		$ledger->register( 'tok-1', 7, 1, 'item_meta', 'unknown placeholder {x}' );
		$ledger->register( 'tok-1', 7, 1, 'item_meta', 'unknown placeholder {x}' );

		$this->assertSame( 'unknown placeholder {x}', RenderLedger::notes_line( $this->entry( $ledger ) ) );
	}

	/**
	 * A `; `-JOINED RUN IS SPLIT AND KEYED PART BY PART, so one value set's whole
	 * line does not occupy a single cap slot — and so two runs sharing a part
	 * record that part once.
	 *
	 * @return void
	 */
	public function test_a_joined_run_is_keyed_part_by_part() {
		$ledger = $this->ledger();

		$ledger->register( 'tok-1', 7, 1, 'item_meta', 'note one; note two' );
		$ledger->register( 'tok-1', 7, 1, 'item_meta', 'note two; note three' );

		$this->assertSame(
			'note one; note two; note three',
			RenderLedger::notes_line( $this->entry( $ledger ) )
		);
	}

	/**
	 * ⚠ THE COLLECTION IS BOUNDED, AND SAYS SO RATHER THAN TRUNCATING SILENTLY.
	 *
	 * Drives the per-item shape directly: many registrations of one rule, each
	 * with its own distinct note, as a fifty-line order with a per-item failure on
	 * each line would produce.
	 *
	 * @return void
	 */
	public function test_the_per_rule_note_set_is_capped() {
		$ledger = $this->ledger();

		for ( $i = 1; $i <= 60; $i++ ) {
			$ledger->register( 'tok-1', 7, 1, 'item_meta', 'unknown placeholder {n' . $i . '}' );
		}

		$entry = $this->entry( $ledger );

		$this->assertCount(
			RenderLedger::MAX_RULE_NOTES,
			$entry['notes'],
			'the per-rule note collection grew past its cap'
		);
		$this->assertSame( 40, (int) $entry['dropped_notes'] );

		$line = RenderLedger::notes_line( $entry );

		$this->assertStringContainsString( 'unknown placeholder {n1}', $line, 'the earliest notes were evicted' );
		$this->assertStringContainsString( 'unknown placeholder {n20}', $line );
		$this->assertStringNotContainsString( 'unknown placeholder {n21}', $line );
		$this->assertStringContainsString( 'and 40 further render notes not recorded', $line, 'the cap truncated silently' );
	}

	/**
	 * An entry with no notes flattens to the empty string, and a blank
	 * registration adds nothing.
	 *
	 * @return void
	 */
	public function test_no_notes_flattens_to_nothing() {
		$ledger = $this->ledger();

		$ledger->register( 'tok-1', 7, 1, 'after_order_table' );
		$ledger->register( 'tok-1', 7, 1, 'after_order_table', '   ' );

		$entry = $this->entry( $ledger );

		$this->assertSame( array(), $entry['notes'] );
		$this->assertSame( '', RenderLedger::notes_line( $entry ) );
	}

	/**
	 * A HAND-BUILT ENTRY CARRYING A PLAIN STRING STILL FLATTENS. Storage is the
	 * only consumer, and it must not fatal on a fixture.
	 *
	 * @return void
	 */
	public function test_a_string_entry_flattens_unchanged() {
		$this->assertSame( 'legacy note', RenderLedger::notes_line( array( 'notes' => 'legacy note' ) ) );
		$this->assertSame( '', RenderLedger::notes_line( array() ) );
	}

	/**
	 * `emitted` ACCUMULATES WITH OR ACROSS EMISSIONS (ADR-0014 §10b): a rule that
	 * emitted beside one line item and threw on the next DID reach the customer.
	 *
	 * @return void
	 */
	public function test_emitted_accumulates_across_emissions() {
		$ledger = $this->ledger();

		$ledger->register( 'tok-1', 7, 1, 'item_meta', 'first item fine', true );
		$ledger->register( 'tok-1', 7, 1, 'item_meta', 'second item threw', false );

		$this->assertTrue( $this->entry( $ledger )['emitted'], 'a later failure erased a genuine earlier emission' );

		$other = $this->ledger( 'tok-2' );

		$other->register( 'tok-2', 9, 1, 'item_meta', 'first item threw', false );
		$other->register( 'tok-2', 9, 1, 'item_meta', 'second item threw too', false );

		$this->assertFalse( $this->entry( $other, 9 )['emitted'], 'a rule that never emitted was recorded as emitted' );
	}
}
