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
}
