<?php
/**
 * Plugin Name: Extonify Custom Emails Per Product for WooCommerce
 * Description: Send custom WooCommerce emails per product — either inserted into a native WooCommerce email or delivered as a separate message.
 * Version: 1.0.0
 * Author: Extonify
 * Text Domain: extonify-custom-emails-per-product
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.6
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 9.6
 * WC tested up to: 11.0.1
 *
 * @package Extonify\WCEP
 *
 * This file must stay parseable by very old PHP so that a failed PHP-version
 * requirement produces an admin notice instead of a parse fatal. Modern syntax
 * lives behind the autoloader, which is required only after that check passes.
 */

defined( 'ABSPATH' ) || exit;

define( 'EXTONIFY_WCEP_FILE', __FILE__ );
define( 'EXTONIFY_WCEP_DIR', __DIR__ );
define( 'EXTONIFY_WCEP_URL', plugin_dir_url( __FILE__ ) );
define( 'EXTONIFY_WCEP_VERSION', '1.0.0' );
define( 'EXTONIFY_WCEP_MIN_PHP', '8.0' );
define( 'EXTONIFY_WCEP_MIN_WP', '6.6' );

/**
 * Minimum supported WooCommerce version.
 *
 * ADR-0006: SETTLED at 9.6 by the Prompt 13 release matrix, replacing POC-E's
 * provisional 8.2.
 *
 * ⚠ 9.6 IS A HARD FLOOR, NOT A CAUTIOUS ONE. `WC_Email::$placeholders` is
 * `protected` up to WooCommerce 9.5 and `public` from 9.6, and
 * `Delivery\RulePreview::render_insert()` reads and writes it on the live
 * registered native email object. Below 9.6 that access throws, and previewing an
 * insert-mode rule refuses with `render_failed`. Verified by running the suite at
 * WooCommerce 8.9.0, not by reading source. Delivery is unaffected — the property
 * is touched in that one file only — but a refusing screen is not a feature this
 * plugin may claim.
 *
 * It is also the version at which core's own email preview (`EmailPreview`,
 * `woocommerce_is_email_preview`) appears, which is not a coincidence: making the
 * property public is what core needed for it.
 *
 * ⚠ Raising this constant is not sufficient on its own — WooCommerce 9.6 requires
 * WordPress 6.6, which is why EXTONIFY_WCEP_MIN_WP moved with it.
 */
define( 'EXTONIFY_WCEP_MIN_WC', '9.6' );

/**
 * Print an admin error notice. Dismissible, translated, escaped, never fatal.
 *
 * @param string $message Already-translated, unescaped message.
 * @return void
 */
function extonify_wcep_admin_notice( $message ) {
	$render = function () use ( $message ) {
		echo '<div class="notice notice-error is-dismissible"><p><strong>'
			. esc_html__( 'Extonify Custom Emails Per Product for WooCommerce', 'extonify-custom-emails-per-product' )
			. ':</strong> ' . esc_html( $message ) . '</p></div>';
	};
	add_action( 'admin_notices', $render );
	add_action( 'network_admin_notices', $render );
}

/**
 * Whether this plugin is NETWORK-activated on a multisite install.
 *
 * Version 1.0 supports PER-SITE activation only: the delivery tombstone and rule
 * tables are per-site (they key on per-site order ids), so a network-wide
 * install has no coherent single schema to create. When network-activated the
 * plugin no-ops behind an explanatory notice — nothing half-activates and no
 * table is created on any site.
 *
 * @return bool
 */
function extonify_wcep_is_network_active() {
	if ( ! is_multisite() ) {
		return false;
	}
	$network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
	return isset( $network_plugins[ plugin_basename( EXTONIFY_WCEP_FILE ) ] );
}

/**
 * Load-time guards plus wiring. Runs on every load: if any requirement fails,
 * show a notice naming exactly what is missing and run NO plugin code. Zero
 * queries, zero HTTP requests, zero output here.
 *
 * @return void
 */
function extonify_wcep_boot() {
	// 1. Per-site activation only — network-activated means notice and no-op.
	if ( extonify_wcep_is_network_active() ) {
		extonify_wcep_admin_notice(
			__( 'does not support network activation in this version. Please network-deactivate it and activate it individually on each site that needs custom product emails. No data was created or modified.', 'extonify-custom-emails-per-product' )
		);
		return;
	}

	// 2. PHP version — checked before the autoloader is even required.
	if ( version_compare( PHP_VERSION, EXTONIFY_WCEP_MIN_PHP, '<' ) ) {
		extonify_wcep_admin_notice(
			sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'requires PHP %1$s or newer. This site runs PHP %2$s. The plugin is inactive until PHP is updated.', 'extonify-custom-emails-per-product' ),
				EXTONIFY_WCEP_MIN_PHP,
				PHP_VERSION
			)
		);
		return;
	}

	// 3. Built package present.
	if ( ! file_exists( EXTONIFY_WCEP_DIR . '/vendor/autoload.php' ) ) {
		extonify_wcep_admin_notice(
			__( 'is missing its built files (vendor/autoload.php). Please install a release build, or run "composer install" in the plugin directory.', 'extonify-custom-emails-per-product' )
		);
		return;
	}
	require_once EXTONIFY_WCEP_DIR . '/vendor/autoload.php';

	// 4. WordPress and WooCommerce checks.
	$errors = \Extonify\WCEP\Requirements::check();
	if ( ! empty( $errors ) ) {
		foreach ( $errors as $error ) {
			extonify_wcep_admin_notice( $error );
		}
		return;
	}

	// 5. All guards passed — wire the plugin (hook registrations only).
	\Extonify\WCEP\Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'extonify_wcep_boot', 20 );

/**
 * Declare HPOS (custom order tables) compatibility.
 *
 * Registered outside extonify_wcep_boot() because WooCommerce fires
 * before_woocommerce_init earlier than plugins_loaded priority 20, and an
 * undeclared plugin is treated as incompatible. Deliberately guard-free beyond
 * the class_exists check: declaring compatibility touches no plugin code and
 * must happen even while a non-blocking requirement notice is showing.
 *
 * @return void
 */
function extonify_wcep_declare_hpos_compatibility() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'extonify_wcep_declare_hpos_compatibility' );

/**
 * Activation: re-check requirements, then create/upgrade the schema and seed
 * defaults. A failed requirement makes activation a silent no-op — the
 * load-time notice explains why. Never fatal, never emits output.
 *
 * Network activation on multisite is refused: the Activator writes nothing and
 * the load-time notice explains the per-site-only policy.
 *
 * @param bool $network_wide True when activated network-wide on multisite.
 * @return void
 */
function extonify_wcep_activate( $network_wide = false ) {
	if ( version_compare( PHP_VERSION, EXTONIFY_WCEP_MIN_PHP, '<' ) ) {
		return;
	}
	if ( ! file_exists( EXTONIFY_WCEP_DIR . '/vendor/autoload.php' ) ) {
		return;
	}
	require_once EXTONIFY_WCEP_DIR . '/vendor/autoload.php';

	if ( ! empty( \Extonify\WCEP\Requirements::check() ) ) {
		return;
	}
	\Extonify\WCEP\Install\Activator::activate( (bool) $network_wide );
}
register_activation_hook( __FILE__, 'extonify_wcep_activate' );

/**
 * Deactivation: unschedule this plugin's recurring actions. Never drops tables
 * and never deletes data — that belongs to uninstall.php, and only when the
 * site owner has opted in.
 *
 * @return void
 */
function extonify_wcep_deactivate() {
	if ( version_compare( PHP_VERSION, EXTONIFY_WCEP_MIN_PHP, '<' ) ) {
		return;
	}
	if ( ! file_exists( EXTONIFY_WCEP_DIR . '/vendor/autoload.php' ) ) {
		return;
	}
	require_once EXTONIFY_WCEP_DIR . '/vendor/autoload.php';

	\Extonify\WCEP\Install\Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'extonify_wcep_deactivate' );
