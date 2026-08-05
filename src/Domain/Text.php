<?php
/**
 * Text sanitisation for free-text diagnostic columns.
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Shape-only sanitisation for values that are stored, never interpreted.
 *
 * `reason` and `failure_message` hold diagnostics — an SMTP response, a
 * skip reason — and those routinely contain an address in angle brackets:
 *
 *     550 5.1.1 <alice@example.test>: Recipient address rejected
 *
 * WordPress's sanitize_text_field() and sanitize_textarea_field() read
 * `<alice@example.test>` as an HTML tag and REMOVE it. Using them here would
 * silently delete the single most useful part of a delivery failure, leaving
 * support with "550 5.1.1 : Recipient address rejected".
 *
 * So these columns are sanitised for SHAPE only — valid UTF-8, no control
 * characters, bounded length — and escaped at the point of output, which is
 * the ordinary WordPress division of responsibility. Nothing in this plugin
 * renders them as HTML without escaping.
 */
final class Text {

	/**
	 * Default cap for a stored diagnostic value.
	 *
	 * Generous enough for a multi-line SMTP conversation, small enough that a
	 * runaway message cannot bloat the table.
	 */
	const MAX_LOG_LENGTH = 2000;

	/**
	 * Cap for ONE entry of a bounded note collection (Prompt 7 C2).
	 *
	 * ⚠ SIZED TO BOUND A HOSTILE NOTE WITHOUT GUTTING AN HONEST ONE, and the first
	 * attempt got that wrong. At 80 bytes the containment note
	 *
	 *     rendering threw and that block was withheld: RuntimeException: <message>
	 *
	 * lost the exception message — the single most useful diagnostic this plugin
	 * records, and the reason `PlaceholderContainmentTest` exists at all. A cap
	 * that destroys the content it is protecting is not a bound, it is data loss
	 * with a justification. Caught by that suite rather than by inspection.
	 *
	 * 250 bytes holds every note this plugin composes, including a class name and
	 * a realistic third-party exception message, while still cutting a 4 KB one
	 * down to something a column can hold.
	 *
	 * ⚠ AND THE COLLECTION BOUND IS `count × this`, NOT "fits in one column".
	 * 20 entries — the count cap `PlaceholderValues::MAX_NOTES` and
	 * `RenderLedger::MAX_RULE_NOTES` both use — comes to about 5 KB, so
	 * self::log_value() DOES truncate the tail at storage. That is the honest
	 * arrangement: the caps make the collection finite, and the column cap decides
	 * how much of a finite collection is worth keeping. Claiming the two multiply
	 * to under self::MAX_LOG_LENGTH would only be true for notes shorter than
	 * anything real.
	 */
	const MAX_NOTE_LENGTH = 250;

	/**
	 * Sanitise a free-text diagnostic value for storage.
	 *
	 * ⚠ THE VALIDITY CHECK RUNS **AFTER** THE TRUNCATION, AND THE OTHER ORDER LOST
	 * WHOLE ROWS (Prompt 7 C2). `substr()` cuts BYTES, so truncating at a byte
	 * boundary inside a multibyte character produces invalid UTF-8 — and this ran
	 * `wp_check_invalid_utf8()` FIRST, so nothing ever inspected the result. On a
	 * `utf8mb4` column MySQL rejects that string, `$wpdb->insert()` fails, and the
	 * diagnostic row is lost **entirely**: the failure a merchant most needs to see
	 * is the one most likely to carry a long non-ASCII SMTP response.
	 *
	 * So the order is: strip control characters → truncate → **then** validate, so
	 * the check sees the string that will actually be written. `wp_check_invalid_utf8()`
	 * with `$strip = true` removes the partial character rather than emptying the
	 * value, which is why it is the right tool for the second pass as well as the
	 * first.
	 *
	 * @param string $value Raw value.
	 * @param int    $max   Maximum stored length in BYTES.
	 * @return string
	 */
	public static function log_value( string $value, int $max = self::MAX_LOG_LENGTH ): string {
		// Drop control characters, keeping tab and newline so a multi-line
		// server response stays readable. Run against the raw bytes first,
		// because the `/u` pattern needs valid UTF-8 to apply at all.
		$stripped = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value );
		if ( null === $stripped ) {
			// Invalid UTF-8 defeated the /u pattern; fall back to bytes.
			$stripped = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
		}

		$value = trim( (string) $stripped );

		if ( $max > 0 && strlen( $value ) > $max ) {
			$value = substr( $value, 0, $max );
		}

		// LAST, over exactly the bytes that will be stored: a character split by
		// the truncation above is removed here, not carried into the INSERT.
		return (string) wp_check_invalid_utf8( $value, true );
	}

	/**
	 * Cap ONE entry of a bounded collection, leaving room for its own notice.
	 *
	 * ⚠ A COUNT CAP IS NOT A SIZE BOUND (Prompt 7 C2). Every note collection in
	 * this plugin caps the number of ENTRIES — 20 in `PlaceholderValues` and in
	 * `RenderLedger` — and none of them capped an entry's LENGTH. Twenty entries of
	 * unbounded length is unbounded, and the entries are not all plugin-authored:
	 * an unknown-placeholder note embeds the merchant's token text, and a
	 * containment note embeds a third party's exception message.
	 *
	 * The truncation announces itself. A silently shortened diagnostic reads as a
	 * complete one, which is how a merchant concludes the plugin lost the rest of
	 * the sentence rather than that it chose to.
	 *
	 * @param string $value Note text.
	 * @param int    $max   Maximum stored length in BYTES, notice included.
	 * @return string
	 */
	public static function note_value( string $value, int $max = self::MAX_NOTE_LENGTH ): string {
		$value = self::log_value( $value, 0 );

		if ( $max <= 0 || strlen( $value ) <= $max ) {
			return $value;
		}

		// ROOM IS RESERVED FOR THE NOTICE, so the returned string honours `$max`
		// rather than overshooting it by the length of its own explanation.
		$notice = ' […truncated]';
		$room   = $max - strlen( $notice );

		if ( $room <= 0 ) {
			// A cap too small to hold its own notice: truncate plainly rather
			// than return something longer than asked for.
			return self::log_value( $value, $max );
		}

		return self::log_value( $value, $room ) . $notice;
	}

	/**
	 * Flatten stored HTML to plain text, ready to be emitted verbatim
	 * (ADR-0013 §4b, ADR-0014 §9a).
	 *
	 * FOUR STEPS, IN THIS ORDER, AND THE ORDER MATTERS:
	 *
	 *   1. block-ish tags become newlines, so the shape of the block survives;
	 *   2. every remaining tag is removed;
	 *   3. entities are DECODED — `&amp;` back to `&`, `&#8212;` back to an em
	 *      dash;
	 *   4. line endings are normalised to `\n`.
	 *
	 * Decoding AFTER stripping is deliberate: decoding first could reveal
	 * character sequences that step 2 would then eat, so `<3` written by a
	 * merchant as `&lt;3` would silently vanish.
	 *
	 * ⚠ STEP 3 IS NOT COSMETIC. `WC_Email::get_content()` runs every plain body
	 * through `$plain_search`/`$plain_replace`, whose last-but-one pattern is
	 * `/&[^&\s;]+;/i` → `''`: **every entity WooCommerce does not explicitly
	 * handle is deleted outright**, so an un-decoded `caf&eacute;` reaches the
	 * customer as `caf`.
	 *
	 * ⚠ ONE IMPLEMENTATION, TWO MODES, AND THAT IS WHY IT LIVES HERE. Prompt 5A
	 * fixed this for INSERT mode only, inside `Render\Injector`. Separate mode's
	 * `Custom_Email::get_content_plain()` kept doing
	 * `wp_strip_all_tags( wp_kses_post( … ) )` with no decode step, so the
	 * identical defect was live there the whole time (ADR-0014 §9a). Both modes
	 * now call this, so the two halves cannot come apart again.
	 *
	 * @param string $content Stored content.
	 * @return string
	 */
	public static function to_plain_text( string $content ): string {
		$text = preg_replace( '/<\s*br\s*\/?\s*>/i', "\n", $content );
		$text = preg_replace( '/<\s*\/\s*(p|div|li|h[1-6])\s*>/i', "\n", (string) $text );
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\r\n|\r/', "\n", $text );

		return trim( (string) $text );
	}
}
