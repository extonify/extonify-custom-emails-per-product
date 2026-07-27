<?php
/**
 * The unit suite must be framework-free (Prompt 2a Item 8).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

/**
 * Guards the boundary between the two suites.
 *
 * Before this, `composer test` ran a single `phpunit` with no `--testsuite`, so
 * `tests/bootstrap.php` booted WordPress and the "unit" suite silently ran
 * against the real framework. That is precisely what hid the
 * `wp_json_encode()` divergence reported in Prompt 2: a shim disagreed with
 * real WordPress, and the disagreement only surfaced when the combined run
 * loaded WordPress underneath the unit tests.
 *
 * `composer test` now runs the two suites as separate processes, and these
 * assertions fail loudly if WordPress ever creeps back into this one.
 *
 * ABSPATH is deliberately NOT the marker: every production file opens with
 * `defined( 'ABSPATH' ) || exit;` as a direct-access guard, so the unit
 * bootstrap defines it itself in order to load those files at all. Its
 * presence therefore proves nothing. These assertions look for WordPress core
 * itself instead.
 */
final class SuiteIsolationTest extends UnitTestCase {

	/**
	 * WordPress core is not loaded.
	 *
	 * @dataProvider wordpress_marker_provider
	 *
	 * @param string $kind Marker kind: constant, function, class or global.
	 * @param string $name Marker name.
	 * @return void
	 */
	public function test_wordpress_is_not_loaded( string $kind, string $name ) {
		switch ( $kind ) {
			case 'constant':
				$loaded = defined( $name );
				break;
			case 'function':
				$loaded = function_exists( $name );
				break;
			case 'class':
				$loaded = class_exists( $name, false );
				break;
			default:
				$loaded = isset( $GLOBALS[ $name ] );
				break;
		}

		$this->assertFalse(
			$loaded,
			"WordPress appears to be loaded in the unit suite ({$kind} {$name}). "
			. 'The unit suite must run without the framework — check that composer test '
			. 'still runs the two suites as separate phpunit processes.'
		);
	}

	/**
	 * Unambiguous markers that WordPress core has been loaded.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function wordpress_marker_provider() {
		return array(
			'WPINC constant' => array( 'constant', 'WPINC' ),
			'add_action()'   => array( 'function', 'add_action' ),
			'add_filter()'   => array( 'function', 'add_filter' ),
			'get_option()'   => array( 'function', 'get_option' ),
			'wpdb class'     => array( 'class', 'wpdb' ),
			'WP_Error class' => array( 'class', 'WP_Error' ),
			'$wpdb global'   => array( 'global', 'wpdb' ),
			'$wp_version'    => array( 'global', 'wp_version' ),
		);
	}

	/**
	 * The shims the suite relies on ARE present, so a passing unit run is
	 * exercising the shim path rather than silently skipping it.
	 *
	 * @return void
	 */
	public function test_unit_shims_are_loaded() {
		foreach ( array( 'wp_json_encode', '__', 'is_email' ) as $shim ) {
			$this->assertTrue( function_exists( $shim ), "Missing unit shim: {$shim}()" );
		}
	}

	/**
	 * ABSPATH is defined by the unit bootstrap so the production files' direct
	 * -access guards do not exit. Documented here so nobody later mistakes it
	 * for evidence that WordPress is loaded.
	 *
	 * @return void
	 */
	public function test_abspath_is_defined_by_the_harness_not_by_wordpress() {
		$this->assertTrue( defined( 'ABSPATH' ) );
		$this->assertFalse( defined( 'WPINC' ) );
	}
}
