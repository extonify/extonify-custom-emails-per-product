<?php
/**
 * Plugin wiring (runs only after every bootstrap guard passed).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP;

use Extonify\WCEP\Delivery\Events;
use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Privacy\Eraser;
use Extonify\WCEP\Privacy\Exporter;
use Extonify\WCEP\Repository\DeliveryDetailRepository;
use Extonify\WCEP\Repository\DeliveryRepository;
use Extonify\WCEP\Repository\RuleRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Central wiring and a small service container.
 *
 * The init() method registers hooks ONLY — zero queries, zero output, zero
 * HTTP. The constructor has no side effects: repositories are built lazily on
 * first access, so merely requiring this file (or instantiating the container
 * in a unit test) never touches the database.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Lazily-built rule repository.
	 *
	 * @var RuleRepository|null
	 */
	private $rules = null;

	/**
	 * Lazily-built delivery (tombstone) repository.
	 *
	 * @var DeliveryRepository|null
	 */
	private $deliveries = null;

	/**
	 * Lazily-built delivery-detail repository.
	 *
	 * @var DeliveryDetailRepository|null
	 */
	private $delivery_details = null;

	/**
	 * Accessor.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private: use instance(). Deliberately empty — no side effects on
	 * construction.
	 */
	private function __construct() {}

	/**
	 * Plugin version.
	 *
	 * @return string
	 */
	public function version(): string {
		return EXTONIFY_WCEP_VERSION;
	}

	/**
	 * Rule repository accessor.
	 *
	 * @return RuleRepository
	 */
	public function rules(): RuleRepository {
		if ( null === $this->rules ) {
			$this->rules = new RuleRepository();
		}
		return $this->rules;
	}

	/**
	 * Delivery (durable tombstone) repository accessor.
	 *
	 * @return DeliveryRepository
	 */
	public function deliveries(): DeliveryRepository {
		if ( null === $this->deliveries ) {
			$this->deliveries = new DeliveryRepository();
		}
		return $this->deliveries;
	}

	/**
	 * Delivery-detail (purgeable) repository accessor.
	 *
	 * @return DeliveryDetailRepository
	 */
	public function delivery_details(): DeliveryDetailRepository {
		if ( null === $this->delivery_details ) {
			$this->delivery_details = new DeliveryDetailRepository();
		}
		return $this->delivery_details;
	}

	/**
	 * Hook registrations only.
	 *
	 * @return void
	 */
	public function init(): void {
		// Translations. Kept explicit rather than relying solely on WordPress
		// 6.5+ just-in-time loading, so a bundled languages/ directory works.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Privacy framework (ADR-0005): the eraser nulls personal fields in the
		// purgeable detail store and NEVER touches tombstones.
		add_filter( 'wp_privacy_personal_data_exporters', array( new Exporter(), 'register' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( new Eraser(), 'register' ) );

		// ADR-0004: the tombstone's bound is the ORDER lifetime. When an order
		// is permanently deleted its tombstones and details go with it — the
		// identity can never be re-armed because the order no longer exists.
		// Fires for both HPOS and legacy post-based storage.
		add_action( 'woocommerce_delete_order', array( $this, 'on_order_deleted' ) );

		// ADR-0012: the delivery spine. Order events reach the matcher, matched
		// decisions claim an identity and send, and everything that happened is
		// written to the delivery log. Registration is hooks only; every handler
		// re-checks that the schema is operational before it touches anything.
		Events::register();

		// Admin-only concerns: schema upgrade on plugin update without
		// reactivation, privacy-policy suggestion, degraded-mode notice.
		if ( is_admin() ) {
			add_action( 'admin_init', array( Migrator::class, 'maybe_upgrade' ) );
			add_action( 'admin_init', array( $this, 'register_privacy_policy_content' ) );
			add_action( 'admin_notices', array( $this, 'maybe_degraded_notice' ) );
		}
	}

	/**
	 * Load the plugin text domain.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'extonify-custom-emails-per-product',
			false,
			dirname( plugin_basename( EXTONIFY_WCEP_FILE ) ) . '/languages'
		);
	}

	/**
	 * Remove this plugin's rows for a permanently deleted order (ADR-0004).
	 *
	 * @param int $order_id Deleted order id.
	 * @return void
	 */
	public function on_order_deleted( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 || ! Migrator::is_operational() ) {
			return;
		}
		$result = $this->deliveries()->delete_for_order( $order_id );

		// Cleanup is fail-closed: on failure the tombstones are deliberately
		// left in place rather than orphaning their detail rows.
		//
		// There is NO automatic retry. This hook fires on permanent deletion,
		// and a deleted order is not deleted again — so the rows remain, safely
		// linked to each other and still reachable by the privacy exporter and
		// eraser, but they may persist indefinitely. Removing them needs a
		// maintenance sweep over rows whose order no longer exists; that is
		// recorded as a production requirement in docs/p2-backlog.md.
		if ( empty( $result['success'] ) && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				'order cleanup did not complete for order #' . $order_id . '; rows were left intact',
				array( 'source' => 'extonify-wcep' )
			);
		}
	}

	/**
	 * Suggested privacy-policy text.
	 *
	 * @return void
	 */
	public function register_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'Extonify Custom Emails Per Product for WooCommerce', 'extonify-custom-emails-per-product' ),
			wp_kses_post(
				'<p>' . __( 'When this site sends you a product-specific order email, it records the delivery locally in this site&#8217;s database so that unintended duplicate automatic deliveries are prevented. The record of what was sent — the recipient address, subject line and any delivery failure details — is kept for a limited retention period and is included in personal-data export and erasure requests. A smaller record that the email was already delivered for your order, holding no address or message content, is kept for longer, so that a later change to your order does not trigger the same automatic email again. A store administrator can still deliberately resend an email to you.', 'extonify-custom-emails-per-product' ) . '</p>'
			)
		);
	}

	/**
	 * Degraded-mode admin notice: shown while a migration failure is recorded
	 * or a table is missing. The site keeps working — this plugin no-ops.
	 *
	 * The notice carries only a generic message, never SQL, table, engine or
	 * host detail (that goes to the WooCommerce log).
	 *
	 * @return void
	 */
	public function maybe_degraded_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$db_error = get_option( Migrator::OPTION_DB_ERROR, '' );
		if ( '' === $db_error && Migrator::is_operational() ) {
			return;
		}

		$message = '' !== $db_error
			? __( 'the custom-email database could not be prepared. Custom product emails are disabled; the rest of your store, including checkout and native WooCommerce emails, is unaffected. See the WooCommerce logs (source: extonify-wcep) for details, then deactivate and reactivate the plugin to retry.', 'extonify-custom-emails-per-product' )
			: __( 'the custom-email database tables are missing. Custom product emails are disabled; the rest of your store, including checkout and native WooCommerce emails, is unaffected. Deactivate and reactivate the plugin to recreate them.', 'extonify-custom-emails-per-product' );

		echo '<div class="notice notice-error is-dismissible"><p><strong>'
			. esc_html__( 'Extonify Custom Emails Per Product for WooCommerce', 'extonify-custom-emails-per-product' )
			. ':</strong> ' . esc_html( $message ) . '</p></div>';
	}
}
