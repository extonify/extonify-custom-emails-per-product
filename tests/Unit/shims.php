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
