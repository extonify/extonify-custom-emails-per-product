<?php
/**
 * Diagnostic-value truncation and per-entry bounds (Prompt 7 C2).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Extonify\WCEP\Delivery\PlaceholderValues;
use Extonify\WCEP\Domain\Text;

/**
 * A DIAGNOSTIC THAT CANNOT BE STORED IS WORSE THAN NO DIAGNOSTIC, because the
 * row it belonged to goes with it.
 *
 * `Text::log_value()` ran `wp_check_invalid_utf8()` and THEN `substr()`, so the
 * validity check never saw the string that would actually be written. `substr()`
 * cuts bytes; a cut inside a multibyte character yields invalid UTF-8; a
 * `utf8mb4` column rejects it; `$wpdb->insert()` fails; the whole detail row is
 * lost. The failures most likely to carry long non-ASCII text — a foreign SMTP
 * server's rejection notice — are exactly the ones worth keeping.
 *
 * The second half is bounds: a count cap of 20 over entries of unbounded length
 * is not a bounded collection, and the entry text is not all plugin-authored.
 */
final class TextBoundsTest extends UnitTestCase {

	/**
	 * A three-byte character, so a byte-wise cut can land inside it.
	 */
	const EURO = "\xE2\x82\xAC";

	/**
	 * ⚠ TRUNCATION NEVER PRODUCES INVALID UTF-8, AT ANY CUT POINT.
	 *
	 * Sweeps every limit across a run of multibyte characters, so the cut lands
	 * inside a character at two thirds of the offsets rather than by luck.
	 *
	 * @return void
	 */
	public function test_truncation_never_yields_invalid_utf8() {
		$value = str_repeat( self::EURO, 40 );

		for ( $max = 1; $max <= 120; $max++ ) {
			$out = Text::log_value( $value, $max );

			$this->assertSame(
				1,
				preg_match( '//u', $out ),
				'truncating at ' . $max . ' bytes split a multibyte character and produced invalid UTF-8'
			);
			$this->assertLessThanOrEqual( $max, strlen( $out ), 'truncation at ' . $max . ' overshot its own limit' );
			$this->assertSame( 0, strlen( $out ) % 3, 'a partial character survived truncation at ' . $max );
		}
	}

	/**
	 * Valid input under the limit is returned untouched — the fix must not
	 * mangle the ordinary case.
	 *
	 * @return void
	 */
	public function test_a_short_value_is_unchanged() {
		$this->assertSame( 'caf' . "\xC3\xA9" . ' — 40' . "\xC2\xB0" . 'C', Text::log_value( 'caf' . "\xC3\xA9" . ' — 40' . "\xC2\xB0" . 'C' ) );
	}

	/**
	 * Invalid bytes are still stripped, and control characters still go.
	 *
	 * @return void
	 */
	public function test_invalid_bytes_and_control_characters_are_removed() {
		$this->assertSame( 'ab', Text::log_value( "a\xFFb" ) );
		$this->assertSame( "a\tb\nc", Text::log_value( "a\tb\nc\x00\x07" ) );
	}

	/**
	 * ⚠ A PER-ENTRY CAP EXISTS, AND IT ANNOUNCES ITSELF.
	 *
	 * @return void
	 */
	public function test_a_note_is_capped_and_says_so() {
		$note = Text::note_value( str_repeat( 'x', 500 ) );

		$this->assertLessThanOrEqual( Text::MAX_NOTE_LENGTH, strlen( $note ), 'the per-note cap was exceeded' );
		$this->assertStringEndsWith( '[…truncated]', $note, 'the note was shortened silently' );
	}

	/**
	 * The cap is a BYTE cap that still respects character boundaries.
	 *
	 * @return void
	 */
	public function test_a_multibyte_note_is_capped_without_breaking_a_character() {
		$note = Text::note_value( str_repeat( self::EURO, 200 ) );

		$this->assertLessThanOrEqual( Text::MAX_NOTE_LENGTH, strlen( $note ) );
		$this->assertSame( 1, preg_match( '//u', $note ), 'the per-note cap split a multibyte character' );
	}

	/**
	 * A note already inside the cap keeps its exact text — no notice, no change.
	 *
	 * @return void
	 */
	public function test_a_short_note_is_untouched() {
		$this->assertSame( 'unknown placeholder {x}', Text::note_value( 'unknown placeholder {x}' ) );
	}

	/**
	 * ⚠ THE COLLECTION IS FINITE, AND THE COLUMN CAP IS THE SECOND BOUND.
	 *
	 * Twenty capped notes come to about 5 KB, which `log_value()` then truncates
	 * to the column budget. Both bounds are asserted, because both do work: the
	 * per-note cap makes an unbounded third-party string finite, and the column
	 * cap decides how much of a finite collection is stored.
	 *
	 * @return void
	 */
	public function test_the_collection_is_finite_and_the_column_cap_is_the_second_bound() {
		$notes = array();

		for ( $i = 0; $i < 20; $i++ ) {
			$notes[] = Text::note_value( str_repeat( 'x', 50000 ) );
		}

		$joined = implode( '; ', $notes );

		// FINITE: 20 entries, each capped, whatever the input was.
		$this->assertLessThanOrEqual(
			20 * Text::MAX_NOTE_LENGTH + 19 * 2,
			strlen( $joined ),
			'a note escaped the per-entry cap'
		);

		// And storage bounds it again.
		$this->assertLessThanOrEqual( Text::MAX_LOG_LENGTH, strlen( Text::log_value( $joined ) ) );
	}

	/**
	 * 8B / gate 27. MERGING MANY SECTIONS' NOTES IS BOUNDED BY THE SAME CONSTANT AS
	 *               ONE DELIVERY'S (ADR-0016 §7a).
	 *
	 * ⚠ THE COLLECTION THE CAP FALLBACK CREATES. The fallback renders the merchant's
	 * body once per unit against its own value set, and an unknown token is unknown in
	 * EVERY unit — so sixty sets would repeat one authoring mistake sixty times, and
	 * sixty × `MAX_NOTES` would put twelve hundred note strings in a `reason` column
	 * that holds one sentence. `fold_notes()` de-duplicates AND caps, and the overflow
	 * is COUNTED rather than silently dropped.
	 *
	 * ⚠ ONE IMPLEMENTATION, TWO CALLERS. The renderer folds as it goes and the
	 * containment boundary folds what reaches it; a second copy of this logic would be
	 * a second sentinel wording for one fact.
	 *
	 * @return void
	 */
	public function test_folding_many_note_sets_stays_inside_one_deliverys_bound() {
		$accumulator = array();

		// Sixty sections, each recording the SAME authoring mistake plus one of its own.
		for ( $section = 1; $section <= 60; $section++ ) {
			$accumulator = PlaceholderValues::fold_notes(
				$accumulator,
				array( 'unknown placeholder {typo}', 'refused a protected meta key {_secret_' . $section . '}' )
			);
		}

		$notes = PlaceholderValues::folded_notes( $accumulator );

		// DE-DUPLICATED: one authoring mistake, recorded once.
		$this->assertSame( 1, count( array_keys( $notes, 'unknown placeholder {typo}', true ) ) );

		// CAPPED at the same constant a single delivery is held to, plus the sentinel.
		$this->assertCount( PlaceholderValues::MAX_NOTES + 1, $notes );

		/*
		 * ⚠ AND A REPEAT IS NOT A DROPPED NOTE. 61 DISTINCT notes arrived — one shared
		 * typo and sixty per-section keys — so the count reports 61 − MAX_NOTES, not
		 * 120 − MAX_NOTES. De-duplication happens BEFORE the cap, which is what makes
		 * the sentinel mean "diagnostics you are not seeing" rather than "sections that
		 * repeated themselves".
		 */
		$this->assertSame(
			PlaceholderValues::overflow_note( 61 - PlaceholderValues::MAX_NOTES ),
			end( $notes ),
			'the dropped notes were not counted'
		);

		// An empty fold is empty — no sentinel, nothing invented.
		$this->assertSame( array(), PlaceholderValues::folded_notes( array() ) );
		$this->assertSame( array(), PlaceholderValues::folded_notes( PlaceholderValues::fold_notes( array(), array() ) ) );
	}

	/**
	 * ⚠ A REAL CONTAINMENT NOTE SURVIVES INTACT. The cap must bound a hostile
	 * string without destroying the diagnostic it exists to carry — an 80-byte
	 * first attempt cut the exception message off the end of exactly this note.
	 *
	 * @return void
	 */
	public function test_a_real_containment_note_is_not_truncated() {
		$note = 'rendering threw and that block was withheld: RuntimeException: the placeholder filter exploded';

		$this->assertSame( $note, Text::note_value( $note ), 'the per-note cap destroyed a real diagnostic' );
	}
}
