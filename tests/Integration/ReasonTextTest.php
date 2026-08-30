<?php
/**
 * GATE 47 — the delivery reason a merchant reads is translatable at READ time,
 *           and the boundary of what is not is pinned so it cannot grow silently.
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\DeliveryPresenter;
use Extonify\WCEP\Admin\ReasonText;
use Extonify\WCEP\Delivery\ScheduledDelivery;

/**
 * Gate 47's evidence for `src/Delivery/`, which `AdminOutputTest` row 33d does not reach.
 *
 * ⚠ WHY THIS TEST EXISTS AT ALL. Gate 47 claims *"every user-facing string is
 * translatable"*. Until Prompt 13C Part E its only evidence was row 33d, which globs
 * `src/Admin/*.php`. The delivery REASON a merchant reads on the history screen is
 * produced in `src/Delivery/`, so the gate's claim ran ahead of its evidence for the
 * whole of that directory. This file is the evidence for the half that is now closed,
 * and the pin for the half that is not.
 */
class ReasonTextTest extends IntegrationTestCase {

	/**
	 * The MEASURED count of untranslated prose literals per file in `src/Delivery/`.
	 *
	 * ⚠ A COUNT, NOT A FILE LIST, AND PART F CHANGED IT FOR A REASON. Part E pinned the
	 * *set of files*, which meant ten new untranslated sentences added to an
	 * already-listed file passed silently — the pin only fired when a NEW file appeared.
	 * Counting per file closes that: a rise means new untranslated surface, and a fall
	 * means somebody closed part of the gap without updating the record. Both are
	 * failures, because both mean this record no longer describes the code.
	 *
	 * ⚠ THESE ARE FRAGMENTS, NOT SENTENCES, AND THE DIFFERENCE IS DELIBERATE. A
	 * concatenated sentence such as `'dropped a non-string ' . $channel . ' entry'`
	 * counts as its separate literals, because that is the unit a translator would have
	 * to be handed and the unit that changes when somebody edits one. Part E's heuristic
	 * could not see these at all — it required a single literal of 28+ characters, so
	 * every concatenated fragment was invisible and `Consolidation.php` had to be listed
	 * by hand with a comment admitting the scan could not find it. It is found now.
	 *
	 * @var array<string,int>
	 */
	const UNTRANSLATED_PROSE_PER_FILE = array(
		'Consolidation.php'         => 4,
		'DeferredEvaluation.php'    => 5,
		'DeliveryLogger.php'        => 56,
		'FanOutResult.php'          => 1,
		'Orchestrator.php'          => 38,
		'PlaceholderValues.php'     => 9,
		'RecipientResolver.php'     => 9,
		'RulePreview.php'           => 1,
		'ScheduledCancellation.php' => 4,
		'ScheduledDelivery.php'     => 48,
	);

	/**
	 * Untranslated prose literals in one file, both quote styles.
	 *
	 * ⚠ WHAT THIS STILL CANNOT SEE, STATED RATHER THAN IMPLIED. It requires three or
	 * more words, so **two-word fragments** (about six of them in this directory, e.g.
	 * `'no snapshot'`) are below the floor and are not counted — raising the floor to two
	 * words sweeps in array keys, hook names and status values, which would make the pin
	 * noisy enough to be ignored. It also skips anything inside a block or line comment,
	 * anything that looks like a bare key (`^[a-z0-9_]+$`), SQL, a `%s`/`%d` format
	 * string, and a namespaced class name. Heredocs would be missed too; there are none
	 * in this directory, which was checked rather than assumed.
	 *
	 * @param string $file Absolute path.
	 * @return string[]
	 */
	private function prose_literals( string $file ): array {
		$body = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $file ) );
		$body = (string) preg_replace( '#^\s*//.*$#m', '', $body );

		$hits = array();

		foreach ( array( "/'((?:[^'\\\\]|\\\\.)*)'/", '/"((?:[^"\\\\]|\\\\.)*)"/' ) as $pattern ) {
			if ( ! preg_match_all( $pattern, $body, $found ) ) {
				continue;
			}

			foreach ( $found[1] as $raw ) {
				$text = trim( stripslashes( $raw ) );

				if ( '' === $text ) continue;
				if ( ! preg_match( '/^[A-Za-z]/', $text ) ) continue;
				if ( substr_count( $text, ' ' ) < 2 ) continue;
				if ( preg_match( '/^[a-z0-9_]+$/', $text ) ) continue;
				if ( preg_match( '/\bSELECT\b|\bFROM\b|\bWHERE\b|\bJOIN\b|%[sdi]/', $text ) ) continue;
				if ( preg_match( '/^[A-Z][A-Za-z]*\\\\/', $text ) ) continue;

				$hits[] = $text;
			}
		}

		return $hits;
	}

	/**
	 * GATE 47a. EVERY STORED CANCELLATION CODE HAS A TRANSLATED SENTENCE, BOTH WAYS.
	 *
	 * A code with no translation falls back to English silently; a translation for a
	 * code that no longer exists is dead weight that hides the first problem.
	 *
	 * @return void
	 */
	public function test_every_cancellation_code_has_a_translated_sentence() {
		$stored = ScheduledDelivery::REASON_TEXT;
		$shown  = ReasonText::map();

		$this->assertNotEmpty( $stored );

		$missing = array_diff( array_keys( $stored ), array_keys( $shown ) );
		$extra   = array_diff( array_keys( $shown ), array_keys( $stored ) );

		$this->assertSame( array(), $missing, '⚠ stored reason codes with no translated sentence: ' . implode( ', ', $missing ) );
		$this->assertSame( array(), $extra, '⚠ translated sentences for codes that are never stored: ' . implode( ', ', $extra ) );

		fwrite( STDERR, "\n[P13E gate 47] read-time reason translation: " . count( $shown ) . ' cancellation codes, each with a translated sentence' );
	}

	/**
	 * GATE 47b. THE TRANSLATED SENTENCE SAYS THE SAME THING AS THE STORED ONE.
	 *
	 * ⚠ THE WHOLE POINT IS THAT THESE ARE TWO COPIES, AND TWO COPIES DRIFT. The stored
	 * copy is the audit record; the shown copy is what the merchant reads. If they say
	 * different things the history is lying to somebody, and which of the two is wrong
	 * is not something a reader can tell. Under the default (English) locale `__()`
	 * returns its own argument, so equality here is exactly the no-drift property.
	 *
	 * @return void
	 */
	public function test_the_translated_sentence_matches_the_stored_one_exactly() {
		$stored = ScheduledDelivery::REASON_TEXT;
		$shown  = ReasonText::map();
		$drift  = array();

		foreach ( $stored as $code => $sentence ) {
			if ( ! isset( $shown[ $code ] ) || $shown[ $code ] !== $sentence ) {
				$drift[] = $code;
			}
		}

		$this->assertSame( array(), $drift, '⚠ the shown sentence differs from the recorded one for: ' . implode( ', ', $drift ) );
	}

	/**
	 * GATE 47c. THE CODE IS READ FROM THE SNAPSHOT THE WRITER ALREADY PERSISTS.
	 *
	 * ⚠ PART D SAID THIS NEEDED A SCHEMA ADDITION. It does not, and this asserts why:
	 * the code is inside the `snapshot` column, which the repository has always decoded
	 * on read. The shape asserted here is the shape `DeliveryLogger` writes.
	 *
	 * @return void
	 */
	public function test_the_reason_code_is_read_from_the_decoded_snapshot() {
		$row = array(
			'reason'   => 'the rule was disabled during the delay',
			'snapshot' => array(
				'cancelled' => array( 'reason_code' => ScheduledDelivery::REASON_RULE_DISABLED ),
			),
		);

		$this->assertSame( ScheduledDelivery::REASON_RULE_DISABLED, ReasonText::code_for( $row ) );
		$this->assertNotSame( '', ReasonText::for_code( ReasonText::code_for( $row ) ) );
	}

	/**
	 * GATE 47d. A ROW WITH NO CODE FALLS BACK RATHER THAN GUESSING.
	 *
	 * Three ways a row arrives without one, all real: written before the code was
	 * recorded, erased by the privacy eraser (`snapshot` is a PERSONAL field), or a
	 * state that never carried a code at all.
	 *
	 * @return void
	 */
	public function test_a_row_without_a_code_yields_no_translation() {
		$this->assertSame( '', ReasonText::code_for( array( 'reason' => 'x' ) ) );
		$this->assertSame( '', ReasonText::code_for( array( 'snapshot' => null ) ) );
		$this->assertSame( '', ReasonText::code_for( array( 'snapshot' => array( 'scheduled' => array( 'action_id' => 4 ) ) ) ) );
		$this->assertSame( '', ReasonText::for_code( '' ) );
		$this->assertSame( '', ReasonText::for_code( 'a_code_that_does_not_exist' ) );
	}

	/**
	 * GATE 47e. THE HISTORY SCREEN SHOWS THE TRANSLATED SENTENCE, NOT THE STORED ONE.
	 *
	 * The two previous tests prove the map; this proves the map is actually reached by
	 * the code that renders. A correct map nothing calls is not evidence for gate 47.
	 *
	 * @return void
	 */
	public function test_the_rendered_attempt_shows_the_translated_reason() {
		$detail = array(
			'attempt'        => 1,
			'type'           => 'auto',
			'state'          => 'cancelled',
			'recipient_type' => 'customer',
			'reason'         => 'STORED-ENGLISH-SENTINEL',
			'created_at'     => '2026-01-01 00:00:00',
			'snapshot'       => array(
				'cancelled' => array( 'reason_code' => ScheduledDelivery::REASON_RULE_DELETED ),
			),
		);

		$html = DeliveryPresenter::attempts_cell( array( $detail ) );

		$this->assertStringNotContainsString(
			'STORED-ENGLISH-SENTINEL',
			$html,
			'⚠ the screen rendered the stored audit sentence instead of the translated one.'
		);
		$this->assertStringContainsString(
			esc_html( ScheduledDelivery::REASON_TEXT[ ScheduledDelivery::REASON_RULE_DELETED ] ),
			$html
		);
	}

	/**
	 * GATE 47f. A ROW WITH NO CODE STILL SHOWS ITS STORED REASON.
	 *
	 * The fallback must actually fall back. A translation layer that blanks the reason
	 * for historical rows would destroy readable history to satisfy a gate.
	 *
	 * @return void
	 */
	public function test_a_row_without_a_code_still_shows_its_stored_reason() {
		$detail = array(
			'attempt'        => 1,
			'type'           => 'auto',
			'state'          => 'failed',
			'recipient_type' => 'customer',
			'reason'         => 'STORED-ENGLISH-SENTINEL',
			'created_at'     => '2026-01-01 00:00:00',
			'snapshot'       => null,
		);

		$html = DeliveryPresenter::attempts_cell( array( $detail ) );

		$this->assertStringContainsString( 'STORED-ENGLISH-SENTINEL', $html );
	}

	/**
	 * GATE 47g. THE UNTRANSLATED SURFACE IS PINNED PER FILE, IN BOTH DIRECTIONS.
	 *
	 * ⚠ THIS TEST DOES NOT CLAIM THE REMAINING PROSE IS ACCEPTABLE. It claims only that
	 * its extent is measured and cannot drift unnoticed. Gate 47 is reported as NOT
	 * satisfied on its full wording precisely because these counts are not zero.
	 *
	 * ⚠ A FALL FAILS TOO, AND THAT IS NOT PEDANTRY. If somebody translates twenty of
	 * these and does not update the record, the next reader inherits a document that
	 * overstates the remaining work — which is how "~30 sentences" survived into Part D
	 * when the real figure was more than twice that.
	 *
	 * @return void
	 */
	public function test_the_untranslated_prose_surface_is_pinned_per_file() {
		$files = glob( dirname( __DIR__, 2 ) . '/src/Delivery/*.php' );

		$this->assertNotEmpty( $files );

		$measured = array();

		foreach ( $files as $file ) {
			$count = count( $this->prose_literals( $file ) );

			if ( $count > 0 ) {
				$measured[ basename( $file ) ] = $count;
			}
		}

		ksort( $measured );
		$expected = self::UNTRANSLATED_PROSE_PER_FILE;
		ksort( $expected );

		$this->assertSame(
			$expected,
			$measured,
			"\n⚠ the untranslated prose surface in src/Delivery/ has MOVED.\n"
				. "   A rise means new untranslated merchant-facing text; a fall means part of the\n"
				. "   gap was closed without updating the record. Neither is allowed to pass silently.\n"
				. '   Update ReasonTextTest::UNTRANSLATED_PROSE_PER_FILE and the Part F section of '
				. "docs/p2-backlog.md together.\n"
		);

		fwrite(
			STDERR,
			"\n[P13F gate 47] untranslated prose PINNED: " . array_sum( $measured )
				. ' literals across ' . count( $measured ) . ' of ' . count( $files )
				. " files in src/Delivery/ — gate 47 is NOT satisfied on its full wording"
		);
	}

	/**
	 * GATE 47h. A REAL TRANSLATION REACHES THE SCREEN, NOT JUST A SECOND ENGLISH COPY.
	 *
	 * ⚠ PART E'S RENDERING TEST COULD NOT TELL TRANSLATION FROM A NO-OP. Under the
	 * default locale `__()` returns its own argument, so asserting that the catalogue
	 * sentence appears proved only that *some* English string did — a `for_code()` that
	 * returned the stored text verbatim would have passed it just as happily.
	 *
	 * This installs a `gettext` filter standing in for a loaded translation of one
	 * specific code, and asserts that **that** string reaches the rendered screen. If the
	 * read-time lookup were removed, the stored English would appear instead and this
	 * fails.
	 *
	 * @return void
	 */
	public function test_a_loaded_translation_reaches_the_screen() {
		$english  = ScheduledDelivery::REASON_TEXT[ ScheduledDelivery::REASON_RULE_DISABLED ];
		$sentinel = 'ZZ-TRANSLATED-die Regel wurde deaktiviert-ZZ';

		$translate = static function ( $translated, $text, $domain ) use ( $english, $sentinel ) {
			if ( 'extonify-custom-emails-per-product' === $domain && $text === $english ) {
				return $sentinel;
			}
			return $translated;
		};

		add_filter( 'gettext', $translate, 10, 3 );

		try {
			$this->assertSame( $sentinel, ReasonText::for_code( ScheduledDelivery::REASON_RULE_DISABLED ) );

			$html = DeliveryPresenter::attempts_cell(
				array(
					array(
						'attempt'        => 1,
						'type'           => 'auto',
						'state'          => 'cancelled',
						'recipient_type' => 'customer',
						'reason'         => 'STORED-ENGLISH-SENTINEL',
						'created_at'     => '2026-01-01 00:00:00',
						'snapshot'       => array(
							'cancelled' => array( 'reason_code' => ScheduledDelivery::REASON_RULE_DISABLED ),
						),
					),
				)
			);
		} finally {
			remove_filter( 'gettext', $translate, 10 );
		}

		$this->assertStringContainsString(
			esc_html( $sentinel ),
			$html,
			'⚠ the loaded translation did not reach the screen — the read-time lookup is not wired.'
		);
		$this->assertStringNotContainsString( 'STORED-ENGLISH-SENTINEL', $html );
		$this->assertStringNotContainsString( $english, $html, '⚠ the untranslated English appeared despite a loaded translation.' );

		// And the filter really is gone, so no later test inherits it.
		$this->assertSame( $english, ReasonText::for_code( ScheduledDelivery::REASON_RULE_DISABLED ) );
	}
}
