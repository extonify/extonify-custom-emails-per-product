<?php
/**
 * Minimal WordPress shims for the pure-logic unit suite.
 *
 * Loaded ONLY when the unit suite runs on its own (no WordPress bootstrap).
 * Every definition is guarded, so running the full suite — where the real
 * WordPress functions exist — uses the real implementations instead.
 *
 * The plugin's version constants are parsed out of the shipped plugin header
 * rather than duplicated here, so a unit test can never assert against a floor
 * that differs from the one actually released.
 *
 * @package Extonify\WCEP\Tests
 */

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Shim for wp_json_encode().
	 *
	 * SIMPLIFICATION: the real wp_json_encode() runs _wp_json_sanity_check()
	 * first, which STRIPS invalid UTF-8 instead of letting json_encode() fail.
	 * This shim does not, so the two disagree on invalid-UTF-8 input. Unit
	 * tests must therefore assert the contract Json guarantees (always an
	 * array on read, never null) rather than an exact encoded string for that
	 * case — see JsonTest::test_invalid_utf8_always_round_trips_to_an_array().
	 *
	 * @param mixed $data    Value to encode.
	 * @param int   $options json_encode options.
	 * @param int   $depth   Maximum depth.
	 * @return string|false
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- this IS the shim standing in for wp_json_encode().
	}
}

/*
 * Every production file opens with `defined( 'ABSPATH' ) || exit;` as a
 * direct-access guard. ABSPATH must therefore exist BEFORE the autoloader
 * first touches one of those files — which happens at SUITE BUILD time, when
 * PHPUnit evaluates static data providers that reference a class constant, and
 * so is far earlier than any @beforeClass hook. Defining it there instead made
 * the whole unit run exit(0) with no output at all.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 5 ) . '/' );
}

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * Shim for is_email().
	 *
	 * SIMPLIFICATION: WordPress's is_email() applies a longer set of structural
	 * rules (label lengths, TLD shape, no leading/trailing dots). This shim is
	 * close enough for the cases Recipient unit tests exercise; anything
	 * depending on the exact boundary belongs in the integration suite, where
	 * the real function runs.
	 *
	 * @param string $email Candidate address.
	 * @return string|false
	 */
	function is_email( $email ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- deliberately shimming the WordPress function of this exact name.
		$email = (string) $email;
		if ( strlen( $email ) < 6 || ! preg_match( '/^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/', $email ) ) {
			return false;
		}
		return $email;
	}
}

if ( ! function_exists( 'wp_check_invalid_utf8' ) ) {
	/**
	 * Shim for wp_check_invalid_utf8().
	 *
	 * SIMPLIFICATION: WordPress consults `$blog_charset`, prefers `mbstring` and
	 * falls back to `iconv`, and returns `''` when `$strip` is false and the
	 * string is invalid. This shim assumes UTF-8 (which the plugin's own tables
	 * are) and implements only the `$strip = true` behaviour `Domain\Text` uses:
	 * valid input is returned unchanged, invalid byte sequences are removed.
	 *
	 * ⚠ WHAT THIS DOES AND DOES NOT PROVE. The unit tests using it assert that
	 * truncation never yields an invalid string — a property of the ORDER of
	 * operations in `Text::log_value()`, which this shim exercises faithfully
	 * because it really does reject a split multibyte character. The exact
	 * stripping WordPress performs on pathological input is the integration
	 * suite's business, where the real function runs.
	 *
	 * @param string $text  Text to check.
	 * @param bool   $strip Whether to strip invalid sequences.
	 * @return string
	 */
	function wp_check_invalid_utf8( $text, $strip = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- deliberately shimming the WordPress function of this exact name.
		$text = (string) $text;

		if ( '' === $text || 1 === preg_match( '//u', $text ) ) {
			return $text;
		}

		if ( ! $strip ) {
			return '';
		}

		/*
		 * Keep every COMPLETE UTF-8 sequence and drop everything else — which is
		 * what a truncation that split a multibyte character leaves behind. The
		 * alternation is the standard UTF-8 grammar; the trailing `.` matches one
		 * stray byte at a time and the callback discards it.
		 */
		return (string) preg_replace_callback(
			'/[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}|./s',
			static function ( $match ) {
				return 1 === strlen( $match[0] ) && "\x7F" < $match[0] ? '' : $match[0];
			},
			$text
		);
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Shim for __().
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- deliberately shimming the WordPress function of this exact name.
		return $text;
	}
}

/**
 * Read one define()'d string constant out of the main plugin file.
 *
 * @param string $name Constant name.
 * @return string|null
 */
function extonify_wcep_tests_read_plugin_constant( $name ) {
	static $source = null;
	if ( null === $source ) {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/extonify-custom-emails-per-product.php' );
	}
	$pattern = "/define\(\s*'" . preg_quote( $name, '/' ) . "'\s*,\s*'([^']+)'\s*\)/";
	if ( preg_match( $pattern, $source, $m ) ) {
		return $m[1];
	}
	return null;
}

foreach ( array( 'EXTONIFY_WCEP_MIN_PHP', 'EXTONIFY_WCEP_MIN_WP', 'EXTONIFY_WCEP_MIN_WC', 'EXTONIFY_WCEP_VERSION' ) as $extonify_wcep_const ) {
	if ( ! defined( $extonify_wcep_const ) ) {
		$extonify_wcep_value = extonify_wcep_tests_read_plugin_constant( $extonify_wcep_const );
		if ( null !== $extonify_wcep_value ) {
			define( $extonify_wcep_const, $extonify_wcep_value );
		}
	}
}
