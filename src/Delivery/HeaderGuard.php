<?php
/**
 * Mail-header injection defence (ADR-0012 §4).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

defined( 'ABSPATH' ) || exit;

/**
 * Strips line breaks out of anything that will become part of a mail header.
 *
 * A mail header ends at a CRLF. A value carrying one — a recipient address, a
 * display name taken from a billing field, a rule's subject line — can append
 * headers of its own:
 *
 *     "Alice\r\nBcc: attacker@example.test"
 *
 * DEFENCE IN DEPTH, DELIBERATELY. PHPMailer rejects such values too, but this
 * plugin composes its own `Cc:` and `Bcc:` header lines, and a value that
 * reaches header composition unstripped is one library version away from being
 * exploitable. Stripping happens at RESOLUTION, not at send, so nothing
 * downstream can bypass it by taking a different route to the mailer.
 *
 * ENCODED FORMS COUNT. A percent-encoded or HTML-entity-encoded break is a
 * break the moment some other layer decodes it, so `%0a`, `&#10;` and their
 * variants are removed as well — and removal REPEATS until the value stops
 * changing, so a nested encoding (`%%0a0a`, `&am&#38;#10;p;`) cannot survive by
 * reassembling itself after one pass.
 */
final class HeaderGuard {

	/**
	 * Maximum strip/re-scan rounds.
	 *
	 * Each round strictly shortens the value or the loop ends, so this is a
	 * belt-and-braces bound rather than a real limit.
	 */
	const MAX_ROUNDS = 8;

	/**
	 * Encoded spellings of CR and LF.
	 *
	 * Percent form (`%0a`, and the double-encoded `%250a`), decimal entities
	 * (`&#10;`, `&#013;`) and hex entities (`&#x0a;`), tolerating leading zeros
	 * and a missing semicolon.
	 *
	 * THE `i` MODIFIER IS LOAD-BEARING. `%0D%0A` is the spelling a browser
	 * produces, and without case-insensitivity it passed straight through —
	 * caught by `HeaderGuardTest`'s uppercase case, which is why that case
	 * exists separately from the lowercase one.
	 */
	const ENCODED_BREAKS = '/%(?:25)?0[ad]|&#(?:0*(?:10|13)|x0*[ad])+;?/i';

	/**
	 * Whether a value carries a line break in any form this class removes.
	 *
	 * Callers use this to RECORD the occurrence — a stripped injection attempt
	 * that leaves no trace in the delivery log is a silent failure of exactly
	 * the kind ADR-0005's reason codes exist to prevent.
	 *
	 * @param string $value Raw value.
	 * @return bool
	 */
	public static function has_break( string $value ): bool {
		return self::strip( $value ) !== $value;
	}

	/**
	 * Remove every raw and encoded line break, and every other control
	 * character, from a header-bound value.
	 *
	 * Control characters beyond CR and LF go too: they have no legitimate place
	 * in an address, a display name or a subject, and several mail transports
	 * treat NUL as a terminator.
	 *
	 * @param string $value Raw value.
	 * @return string Header-safe value, trimmed.
	 */
	public static function strip( string $value ): string {
		$previous = null;
		$rounds   = 0;

		while ( $previous !== $value && $rounds < self::MAX_ROUNDS ) {
			$previous = $value;
			$value    = (string) preg_replace( self::ENCODED_BREAKS, '', $value );
			$value    = self::strip_control_characters( $value );
			++$rounds;
		}

		return trim( $value );
	}

	/**
	 * Drop raw control characters, including CR, LF, NUL and DEL.
	 *
	 * Unlike `Domain\Text::log_value()` this removes tab and newline as well:
	 * that class sanitises a MULTI-LINE diagnostic for storage, whereas a header
	 * value is single-line by definition.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function strip_control_characters( string $value ): string {
		$stripped = preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );

		if ( null === $stripped ) {
			// Invalid UTF-8 defeated the /u pattern; fall back to bytes so a
			// malformed value is still stripped rather than passed through.
			$stripped = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
		}

		return (string) $stripped;
	}
}
