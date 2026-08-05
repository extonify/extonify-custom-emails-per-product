<?php
/**
 * Activation routine.
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Runs only from the activation hook, only after every requirement guard has
 * passed. Creates or upgrades the schema and registers option defaults.
 * Idempotent and safe on reactivation. Never emits output.
 */
final class Activator {

	const OPTION_SETTINGS            = 'extonify_wcep_settings';
	const OPTION_REMOVE_ON_UNINSTALL = 'extonify_wcep_remove_data_on_uninstall';
	const OPTION_PLUGIN_VERSION      = 'extonify_wcep_version';

	/**
	 * Default settings.
	 *
	 * Retention windows come from ADR-0005: 90 days normal, 180 days failed,
	 * 14 days debug, with a keep-indefinitely opt-in. No UI exists in the
	 * foundation; these are registered with defaults only.
	 *
	 * @return array
	 */
	public static function default_settings(): array {
		return array(
			'retention_days'        => 90,
			'retention_days_failed' => 180,
			'retention_days_debug'  => 14,
			'retention_keep_all'    => 'no',
			'debug_logging'         => 'no',
		);
	}

	/**
	 * Activate: migrate the schema, then seed defaults.
	 *
	 * Network activation on multisite is refused: this plugin is per-site only
	 * (the schema keys on per-site order ids), so nothing is written at all and
	 * the load-time notice explains the policy.
	 *
	 * @param bool $network_wide True when activated network-wide on multisite.
	 * @return void
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			return;
		}

		// A migration failure is already recorded and surfaced as a notice by
		// the Migrator; activation still completes so the site owner can read
		// it, and the plugin runs in no-op mode until the retry succeeds.
		Migrator::migrate();

		// add_option() never overwrites an existing row, so reactivation
		// preserves whatever the site owner configured.
		add_option( self::OPTION_SETTINGS, self::default_settings(), '', 'no' );
		add_option( self::OPTION_REMOVE_ON_UNINSTALL, 'no', '', 'no' );

		// ADR-0015 §8.3: arm the daily sweep. Idempotent, and mirrored on
		// `admin_init` because a plugin UPDATE never runs the activation hook —
		// an existing site would otherwise have no way to recover a stranded
		// delivery.
		Maintenance::ensure_scheduled();

		update_option( self::OPTION_PLUGIN_VERSION, EXTONIFY_WCEP_VERSION, false );
	}

	/**
	 * Settings accessor merged over the shipped defaults, so an install
	 * predating a new key still reads a sane value.
	 *
	 * @return array
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::default_settings(), $stored );
	}
}
