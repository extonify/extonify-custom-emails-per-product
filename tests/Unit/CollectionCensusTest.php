<?php
/**
 * THE COLLECTION CENSUS (gate 10).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Extonify\WCEP\Delivery\Consolidation;
use Extonify\WCEP\Delivery\FanOutResult;
use Extonify\WCEP\Delivery\RunOutcome;
use Extonify\WCEP\Render\RenderContext;
use Extonify\WCEP\Render\RenderLedger;

/**
 * EVERY PER-REQUEST COLLECTION IS CLASSIFIED, AND A NEW ONE FAILS THIS TEST UNTIL
 * SOMEBODY CLASSIFIES IT.
 *
 * ⚠ WHY A CENSUS RATHER THAN A LIST OF CAP ASSERTIONS. Prompt 5B capped every
 * collection in the render path, and Prompt 6 then added a new one —
 * `RenderLedger`'s per-rule `notes` — with no cap at all. Auditing by inspection
 * catches what was there when the audit ran; it cannot catch what is added
 * afterwards. This test enumerates the collections by REFLECTION and requires each
 * to appear in the classification below, so a property added later is a failing
 * test rather than a defect somebody has to notice.
 *
 * The same shape as `Custom_Email`'s `RUNTIME_FIELDS` completeness guard from
 * Prompt 4d, applied to a different failure.
 *
 * Three ways a collection may be bounded, and every entry names one:
 *
 *   - **`cap`** — an explicit named constant, with the overflow COUNTED rather
 *     than silently dropped;
 *   - **`depth`** — bounded by `RenderContext::MAX_RENDER_DEPTH` or by the
 *     nesting depth of `WC_Email::send()`, which is bounded by the same;
 *   - **`lifecycle`** — entries are removed as fast as they are added by a
 *     paired operation, and the sweep empties whatever is left.
 */
final class CollectionCensusTest extends UnitTestCase {

	/**
	 * Every array property of the audited collaborators, classified.
	 *
	 * ⚠ ADD A PROPERTY, ADD A LINE. A missing entry fails
	 * self::test_every_collection_is_classified().
	 *
	 * ⚠ THE SCOPE GREW IN PROMPT 8. It was the two RENDER collaborators, because that
	 * is where the unbounded collections had been found; `Delivery\FanOutResult` and
	 * `Delivery\RunOutcome` join them because consolidation introduced the first
	 * per-delivery collection whose SIZE A MERCHANT CONTROLS — one entry per message,
	 * with the message count coming from the order's contents and a filterable cap.
	 * Auditing by inspection catches what was there when the audit ran; this catches
	 * what is added afterwards, and only if its scope covers the classes that grow.
	 *
	 * @var array<string,array<string,string>>
	 */
	const CENSUS = array(
		RenderLedger::class   => array(
			'slots'           => 'depth: one per live render, and a render past MAX_RENDER_DEPTH opens none; emptied by take_open()',
			'reservations'    => 'depth: one per WC_Email::send() in progress, popped by bind()',
			'candidates'      => 'lifecycle: appended at promotion, popped by take_candidate(); re-promotion moves rather than duplicates',
			'send_frames'     => 'depth: one per WC_Email::send() in progress, popped by reserve()',
			'bindings'        => 'depth: one per send between bind() and finalize()',
			'reservation_log' => 'cap: MAX_DIAGNOSTIC_ENTRIES, overflow counted in $dropped',
			'finalizations'   => 'cap: MAX_DIAGNOSTIC_ENTRIES, overflow counted in $dropped',
			'dropped'         => 'lifecycle: fixed-size counter map, one key per capped array',
		),
		FanOutResult::class   => array(
			'messages' => 'cap: one entry per PLANNED message, and the plan is bounded by Consolidation::max_messages() — a larger matched-unit count falls back to a single message carrying one section per unit (ADR-0016 §7) rather than truncating, so the collection can never exceed the cap in force',
		),
		RunOutcome::class     => array(
			'records' => 'lifecycle: one entry per rule this run ACTED on, so it is bounded by the number of active rules matching one trigger; a fan-out adds ONE record however many messages it sends (ADR-0016 §3), and the object is per-run and discarded with it',
		),
		RenderContext::class  => array(
			'frames'               => 'depth: MAX_RENDER_DEPTH, enforced at push',
			'renders'              => 'depth: one per accepted frame',
			'open_details'         => 'depth: one per frame inside the order-details window',
			'open_footer'          => 'depth: one per frame inside the footer window',
			'preview_pending'      => 'depth: one per frame awaiting its preview verdict',
			'reconciled'           => 'cap: MAX_DIAGNOSTIC_ENTRIES, overflow counted in $dropped',
			'mismatches'           => 'cap: MAX_DIAGNOSTIC_ENTRIES, overflow counted in $dropped',
			'unresolved_emissions' => 'cap: MAX_DIAGNOSTIC_ENTRIES, overflow counted in $dropped',
			'dropped'              => 'lifecycle: fixed-size counter map, one key per capped array',
		),
	);

	/**
	 * Collections that live INSIDE another collection's entries, which reflection
	 * over properties cannot see.
	 *
	 * ⚠ THE PER-RULE NOTE SET IS HERE, AND IT IS THE ONE THE CENSUS EXISTS FOR
	 * (ADR-0014 §1d). It is not a property of anything: it hangs off each rule
	 * entry inside a slot, which is why the property-level audit in Prompt 5B could
	 * not have found it and why nothing did until Prompt 6B.
	 *
	 * @var array<string,string>
	 */
	const NESTED_COLLECTIONS = array(
		'RenderLedger::$slots[*][rules]'         => 'lifecycle: a SET keyed by rule id, so it is bounded by the number of active rules that matched this render',
		'RenderLedger::$slots[*][rules][*][notes]' => 'cap: MAX_RULE_NOTES entries, each capped at Text::MAX_NOTE_LENGTH bytes; overflow counted in the entry\'s dropped_notes',
		'PlaceholderValues::$notes'               => 'cap: MAX_NOTES entries, each capped at Text::MAX_NOTE_LENGTH bytes; overflow counted in $dropped_notes',
		'PlaceholderResolver::render_sectioned_body() merged notes' => 'cap: PlaceholderValues::MAX_NOTES entries via fold_notes(), de-duplicated across the cap fallback\'s sections so one authoring mistake is one note however many units met it; overflow counted and reported by folded_notes() (ADR-0016 §7a)',
	);

	/**
	 * Array properties actually declared on one class.
	 *
	 * @param string $class Class name.
	 * @return string[]
	 */
	private function array_properties( string $class ): array {
		$found     = array();
		$reflected = new \ReflectionClass( $class );
		$instance  = $reflected->newInstance();

		foreach ( $reflected->getProperties() as $property ) {
			$property->setAccessible( true );

			if ( is_array( $property->getValue( $instance ) ) ) {
				$found[] = $property->getName();
			}
		}

		sort( $found );

		return $found;
	}

	/**
	 * ⚠ EVERY ARRAY PROPERTY IS CLASSIFIED, AND ONLY CLASSIFIED ONES EXIST.
	 *
	 * Both directions: an unclassified property is an unaudited collection, and a
	 * classified property that no longer exists is a stale claim.
	 *
	 * @return void
	 */
	public function test_every_collection_is_classified() {
		foreach ( self::CENSUS as $class => $classified ) {
			$declared = $this->array_properties( $class );
			$named    = array_keys( $classified );
			sort( $named );

			$this->assertSame(
				$named,
				$declared,
				$class . ': the census and the class disagree. A NEW collection must be classified here — '
					. 'cap, depth or lifecycle — before it ships, and a removed one must be struck out.'
			);
		}
	}

	/**
	 * Every classification names one of the three bounding mechanisms.
	 *
	 * @return void
	 */
	public function test_every_classification_names_a_bounding_mechanism() {
		$entries = array();

		foreach ( self::CENSUS as $class => $classified ) {
			foreach ( $classified as $name => $reason ) {
				$entries[ $class . '::$' . $name ] = $reason;
			}
		}

		$entries = array_merge( $entries, self::NESTED_COLLECTIONS );

		foreach ( $entries as $where => $reason ) {
			$this->assertSame(
				1,
				preg_match( '/^(cap|depth|lifecycle): /', $reason ),
				$where . ': a classification must open with cap:, depth: or lifecycle:'
			);
		}

		$this->assertGreaterThan( 15, count( $entries ), 'The census lost entries.' );
	}

	/**
	 * ⚠ THE PER-RULE NOTE SET IS IN THE CENSUS AND ITS CAP IS REAL (ADR-0014 §1d).
	 *
	 * A nested collection cannot be found by reflecting over properties, so the
	 * census names it explicitly and this asserts the named cap exists rather than
	 * being a sentence somebody wrote. `RenderLedgerNotesTest` proves it is
	 * enforced.
	 *
	 * @return void
	 */
	public function test_the_per_rule_note_collection_is_in_the_census() {
		$this->assertArrayHasKey(
			'RenderLedger::$slots[*][rules][*][notes]',
			self::NESTED_COLLECTIONS,
			'the collection Prompt 6B added a cap to is not in the census'
		);

		$this->assertGreaterThan( 0, RenderLedger::MAX_RULE_NOTES );

		/*
		 * ⚠ AND THE COUNT CAP IS ONLY HALF A BOUND (Prompt 7 C2). Twenty entries of
		 * unbounded length is unbounded, and the entries are not all plugin-authored
		 * — a note embeds the merchant's token text or a third party's exception
		 * message. Both dimensions are asserted, because the collection was capped
		 * in one of them for two prompts.
		 */
		$this->assertGreaterThan( 0, \Extonify\WCEP\Domain\Text::MAX_NOTE_LENGTH );
		$this->assertLessThan(
			\Extonify\WCEP\Domain\Text::MAX_LOG_LENGTH,
			\Extonify\WCEP\Domain\Text::MAX_NOTE_LENGTH,
			'a single note may not be allowed to fill the whole log column'
		);
		$this->assertSame( RenderLedger::MAX_DIAGNOSTIC_ENTRIES, RenderContext::MAX_DIAGNOSTIC_ENTRIES );
	}

	/**
	 * ⚠ THE FAN-OUT COLLECTION'S CAP IS REAL, AND IT IS A CAP ON WHAT A CUSTOMER
	 * RECEIVES (ADR-0016 §7).
	 *
	 * Every other bounded collection here holds diagnostics: exceeding its cap costs a
	 * log entry. This one holds MESSAGES, so exceeding it would cost a customer sixty
	 * emails from one order — which is why the overflow behaviour is a FALLBACK to one
	 * message carrying one section per unit rather than a dropped-and-counted tail. A
	 * truncating cap would silently lose products the merchant asked to have mentioned.
	 *
	 * @return void
	 */
	public function test_the_fan_out_collection_is_capped_by_a_real_message_limit() {
		$this->assertGreaterThan( 0, Consolidation::MAX_MESSAGES );

		// The plan is what bounds the collection, so the bound is asserted THERE rather
		// than on the accumulator: `FanOutResult` records one entry per planned message.
		$rule  = array( 'consolidation' => Consolidation::PER_PRODUCT );
		$items = array();

		for ( $i = 1; $i <= Consolidation::MAX_MESSAGES + 1; $i++ ) {
			$items[] = array( 'item_id' => $i, 'product_id' => 100 + $i, 'variation_id' => 0 );
		}

		$over = Consolidation::plan( $rule, $items, Consolidation::MAX_MESSAGES );

		$this->assertCount( 1, $over['messages'], 'a plan over the cap did not fall back to one message' );
		$this->assertTrue( $over['capped'] );
		$this->assertSame( Consolidation::MAX_MESSAGES + 1, $over['units'], 'the fallback lost the unit count' );

		// AT the cap it fans out fully, so the assertion above is about the boundary and
		// not about the planner refusing everything.
		$at = Consolidation::plan( $rule, array_slice( $items, 0, Consolidation::MAX_MESSAGES ), Consolidation::MAX_MESSAGES );

		$this->assertCount( Consolidation::MAX_MESSAGES, $at['messages'] );
		$this->assertFalse( $at['capped'] );
	}
}
