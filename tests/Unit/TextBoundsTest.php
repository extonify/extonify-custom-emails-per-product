<?php
/**
 * Diagnostic-value truncation and per-entry bounds (Prompt 7 C2).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

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
