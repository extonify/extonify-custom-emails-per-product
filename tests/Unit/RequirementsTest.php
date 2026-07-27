<?php
/**
 * Requirement-guard version comparisons.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Extonify\WCEP\Requirements;

/**
 * A wrong comparison here either blocks a supported site or lets an
 * unsupported one load the plugin and fatal. Requirements::check() takes an
 * environment override precisely so this can be tested without WordPress.
 */
final class RequirementsTest extends UnitTestCase {

	/**
	 * An environment at or above every floor produces no errors.
	 *
	 * @return void
	 */
	public function test_supported_environment_passes() {
		$errors = Requirements::check(
			array(
				'wp_version' => EXTONIFY_WCEP_MIN_WP,
				'wc_active'  => true,
				'wc_version' => EXTONIFY_WCEP_MIN_WC,
			)
		);

		$this->assertSame( array(), $errors );
	}

	/**
	 * The floors themselves are INCLUSIVE — a site running exactly the minimum
	 * is supported, and a patch release above it is too.
	 *
	 * @return void
	 */
	public function test_floors_are_inclusive_and_newer_passes() {
		$this->assertSame(
			array(),
			Requirements::check(
				array(
					'wp_version' => '99.0',
					'wc_active'  => true,
					'wc_version' => '99.0.0',
				)
			)
		);

		$this->assertSame(
			array(),
			Requirements::check(
				array(
					'wp_version' => EXTONIFY_WCEP_MIN_WP . '.1',
					'wc_active'  => true,
					'wc_version' => EXTONIFY_WCEP_MIN_WC . '.1',
				)
			)
		);
	}

	/**
	 * An older WordPress is reported, and the message names both versions.
	 *
	 * @return void
	 */
	public function test_old_wordpress_is_reported() {
		$errors = Requirements::check(
			array(
				'wp_version' => '5.9',
				'wc_active'  => true,
				'wc_version' => EXTONIFY_WCEP_MIN_WC,
			)
		);

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( EXTONIFY_WCEP_MIN_WP, $errors[0] );
		$this->assertStringContainsString( '5.9', $errors[0] );
	}

	/**
	 * An inactive WooCommerce is reported, and the version check is NOT also
	 * reported — an absent WooCommerce has no version to be wrong about.
	 *
	 * @return void
	 */
	public function test_missing_woocommerce_is_reported_once() {
		$errors = Requirements::check(
			array(
				'wp_version' => EXTONIFY_WCEP_MIN_WP,
				'wc_active'  => false,
				'wc_version' => null,
			)
		);

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'WooCommerce', $errors[0] );
	}

	/**
	 * An older WooCommerce is reported against the provisional ADR-0006 floor.
	 *
	 * @return void
	 */
	public function test_old_woocommerce_is_reported() {
		$errors = Requirements::check(
			array(
				'wp_version' => EXTONIFY_WCEP_MIN_WP,
				'wc_active'  => true,
				'wc_version' => '7.9.0',
			)
		);

		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( EXTONIFY_WCEP_MIN_WC, $errors[0] );
		$this->assertStringContainsString( '7.9.0', $errors[0] );
	}

	/**
	 * Both failures are reported together, so a site owner fixes one round of
	 * problems rather than discovering the second after fixing the first.
	 *
	 * @return void
	 */
	public function test_both_failures_reported_together() {
		$errors = Requirements::check(
			array(
				'wp_version' => '5.0',
				'wc_active'  => true,
				'wc_version' => '6.0',
			)
		);

		$this->assertCount( 2, $errors );
	}

	/**
	 * An active WooCommerce whose version cannot be determined is NOT blocked:
	 * WC_VERSION is undefined in a few early-load edge cases, and refusing to
	 * run there would break sites that are actually supported.
	 *
	 * @return void
	 */
	public function test_unknown_woocommerce_version_is_not_blocked() {
		$errors = Requirements::check(
			array(
				'wp_version' => EXTONIFY_WCEP_MIN_WP,
				'wc_active'  => true,
				'wc_version' => null,
			)
		);

		$this->assertSame( array(), $errors );
	}

	/**
	 * WordPress ships versions like '6.5-RC1' and '7.0.2'; version_compare must
	 * treat a release candidate of the minimum as BELOW the minimum.
	 *
	 * @return void
	 */
	public function test_release_candidate_of_the_floor_is_below_the_floor() {
		$errors = Requirements::check(
			array(
				'wp_version' => EXTONIFY_WCEP_MIN_WP . '-RC1',
				'wc_active'  => true,
				'wc_version' => EXTONIFY_WCEP_MIN_WC,
			)
		);

		$this->assertCount( 1, $errors );
	}
}
