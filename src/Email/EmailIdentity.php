<?php
/**
 * Identifiers for the single fixed email, safe to reference anywhere.
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Email;

defined( 'ABSPATH' ) || exit;

/**
 * The two strings that name this plugin's email, held OUTSIDE the `WC_Email`
 * subclass on purpose.
 *
 * `Custom_Email extends \WC_Email`, and PHP resolves a parent class the moment
 * the child's file is autoloaded. WooCommerce only includes
 * `includes/emails/class-wc-email.php` when `WC_Emails::init()` runs — that is,
 * on the first `WC()->mailer()` call — so ANY reference to a `Custom_Email`
 * class constant before that point autoloads the child, fails to find the
 * parent, and fatals.
 *
 * That is a real trap rather than a theoretical one: reading the email's saved
 * settings, or looking up its registry key, are both things a caller naturally
 * does before touching the mailer. Keeping the identifiers in a class with no
 * parent makes them referenceable from anywhere, at any point in the request,
 * with no ordering condition to remember.
 */
final class EmailIdentity {

	/**
	 * The WooCommerce email id.
	 *
	 * Drives the settings option name (`woocommerce_extonify_wcep_custom_settings`)
	 * and the `$id` argument of `woocommerce_email_sent`.
	 */
	const EMAIL_ID = 'extonify_wcep_custom';

	/**
	 * The key this email occupies in `WC()->mailer()->get_emails()`.
	 *
	 * A stable literal rather than `get_class()`, so a future namespace move
	 * cannot change the key a merchant's saved settings and this plugin's own
	 * lookups depend on.
	 */
	const REGISTRY_KEY = 'Extonify_WCEP_Custom_Email';

	/**
	 * The option WooCommerce stores this email's settings under.
	 *
	 * @return string
	 */
	public static function settings_key(): string {
		return 'woocommerce_' . self::EMAIL_ID . '_settings';
	}
}
