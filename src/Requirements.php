<?php
/**
 * Environment requirement checks.
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP;

defined( 'ABSPATH' ) || exit;

/**
 * Pure environment checks: no I/O beyond reading globals and constants, zero
 * database queries, safe to run when WooCommerce is absent.
 */
final class Requirements {

	/**
	 * Check WordPress and WooCommerce requirements.
	 *
	 * PHP is checked earlier, in the bootstrap, before the autoloader loads —
	 * a class in this namespace cannot be the guard for the PHP version that
	 * would be needed to parse it.
	 *
	 * @param array|null $env Optional environment overrides for testing:
	 *                        { wp_version, wc_active, wc_version }.
	 * @return string[] Translated error messages; empty when all pass.
	 */
	public static function check( ?array $env = null ): array {
		global $wp_version;

		$wp     = $env['wp_version'] ?? $wp_version;
		$active = $env['wc_active'] ?? class_exists( 'WooCommerce' );
		$wc     = $env['wc_version'] ?? ( defined( 'WC_VERSION' ) ? WC_VERSION : null );

		$errors = array();

		if ( version_compare( (string) $wp, EXTONIFY_WCEP_MIN_WP, '<' ) ) {
			$errors[] = sprintf(
				/* translators: 1: required WordPress version, 2: current WordPress version. */
				__( 'requires WordPress %1$s or newer. This site runs WordPress %2$s. The plugin is inactive until WordPress is updated.', 'extonify-custom-emails-per-product' ),
				EXTONIFY_WCEP_MIN_WP,
				$wp
			);
		}

		if ( ! $active ) {
			$errors[] = __( 'requires WooCommerce to be installed and active. The plugin is inactive until WooCommerce is activated.', 'extonify-custom-emails-per-product' );
		} elseif ( null !== $wc && version_compare( (string) $wc, EXTONIFY_WCEP_MIN_WC, '<' ) ) {
			$errors[] = sprintf(
				/* translators: 1: required WooCommerce version, 2: current WooCommerce version. */
				__( 'requires WooCommerce %1$s or newer. This site runs WooCommerce %2$s. The plugin is inactive until WooCommerce is updated.', 'extonify-custom-emails-per-product' ),
				EXTONIFY_WCEP_MIN_WC,
				$wc
			);
		}

		return $errors;
	}
}
