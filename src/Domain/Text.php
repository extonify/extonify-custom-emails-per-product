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
	 * Sanitise a free-text diagnostic value for storage.
	 *
	 * @param string $value Raw value.
	 * @param int    $max   Maximum stored length.
	 * @return string
	 */
	public static function log_value( string $value, int $max = self::MAX_LOG_LENGTH ): string {
		$value = wp_check_invalid_utf8( $value, true );

		// Drop control characters, keeping tab and newline so a multi-line
		// server response stays readable.
		$stripped = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value );
		if ( null === $stripped ) {
			// Invalid UTF-8 defeated the /u pattern; fall back to bytes.
			$stripped = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
		}

		$value = trim( (string) $stripped );

		if ( $max > 0 && strlen( $value ) > $max ) {
			$value = substr( $value, 0, $max );
		}

		return $value;
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
