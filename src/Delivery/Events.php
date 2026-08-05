<?php
/**
 * WooCommerce event wiring for the delivery spine (ADR-0012 §8).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

use Extonify\WCEP\Email\Custom_Email;
use Extonify\WCEP\Email\EmailIdentity;

defined( 'ABSPATH' ) || exit;

/**
 * The only place WooCommerce hooks reach the delivery lifecycle.
 *
 * Registration is hooks ONLY — zero queries, zero output — matching the
 * discipline `Plugin::init()` already follows. Every handler re-checks that
 * orchestration may run, so a schema that broke after registration cannot
 * produce a half-delivery.
 *
 * THE EMAIL CLASS IS REGISTERED BEFORE THE MAILER INITIALISES. ADR-0002:
 * `woocommerce_email_classes` is applied once, when `WC_Emails` first builds its
 * list. A class added after that point exists but is absent from the live
 * `WC()->mailer()->get_emails()`, so the orchestrator could never find it and no
 * merchant could ever switch it off.
 *
 * NO INSTANCE STATE AT ALL. This class has no properties; the only thing it
 * holds across calls is the per-REQUEST orchestrator in `orchestrator()`, which
 * is shared on purpose so several events in one request reuse the resolver's
 * PRODUCT cache. Sharing it is safe on two counts, and both had to be earned:
 * `Orchestrator` keeps nothing about one run on itself (ADR-0012 §11) — a send
 * that re-enters the lifecycle gets its own snapshot and its own outcome, on the
 * stack — and the resolver caches no order contents, so an order that changes
 * between two events in one request is re-read rather than replayed
 * (ADR-0012 §11b).
 */
class Events {

	/**
	 * Register every hook this prompt owns.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'woocommerce_email_classes', array( self::class, 'register_email_class' ) );

		// A status change raises BOTH families (ADR-0011 §1), each with its own
		// identity and its own independent claim.
		add_action( 'woocommerce_order_status_changed', array( self::class, 'on_status_changed' ), 10, 3 );

		// Two hooks, ONE identity family. WooCommerce fires only the `fully`
		// hook for a full refund and only the `partially` hook for a partial
		// one, so registering both is required for coverage and cannot
		// double-fire for a single refund.
		add_action( 'woocommerce_order_fully_refunded', array( self::class, 'on_refunded' ), 10, 2 );
		add_action( 'woocommerce_order_partially_refunded', array( self::class, 'on_refunded' ), 10, 2 );

		DeferredEvaluation::register();

		// ADR-0015: delayed delivery. The job handler executes a delivery claimed
		// and snapshotted hours earlier; the cancellation subscriber stops a rule's
		// queued mail the moment the merchant disables or deletes it.
		ScheduledDelivery::register();
		ScheduledCancellation::register();
	}

	/**
	 * Add the single fixed email class to WooCommerce's list.
	 *
	 * @param array $emails Registered email objects, keyed by registry key.
	 * @return array
	 */
	public static function register_email_class( $emails ): array {
		$emails = (array) $emails;

		if ( ! isset( $emails[ EmailIdentity::REGISTRY_KEY ] ) ) {
			$emails[ EmailIdentity::REGISTRY_KEY ] = new Custom_Email();
		}

		return $emails;
	}

	/**
	 * An order changed status.
	 *
	 * The order OBJECT is deliberately not taken from the hook arguments — see
	 * `Orchestrator::load_order()`.
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Status moved from.
	 * @param string $to       Status moved to.
	 * @return void
	 */
	public static function on_status_changed( $order_id, $from = '', $to = '' ): void {
		if ( ! Orchestrator::is_operational() ) {
			return;
		}

		self::orchestrator()->handle_status_change( (int) $order_id, (string) $from, (string) $to );
	}

	/**
	 * An order was fully or partially refunded.
	 *
	 * ADR-0004 keys refunds by REFUND id, which is what distinguishes two
	 * partial refunds on one order from each other.
	 *
	 * @param int $order_id  Parent order id.
	 * @param int $refund_id Refund id.
	 * @return void
	 */
	public static function on_refunded( $order_id, $refund_id = 0 ): void {
		if ( ! Orchestrator::is_operational() ) {
			return;
		}

		self::orchestrator()->handle_refund( (int) $order_id, (int) $refund_id );
	}

	/**
	 * The orchestrator, built once per request.
	 *
	 * Shared so several events in one request reuse the matcher's PRODUCT cache
	 * (ADR-0011 §9) rather than re-loading the same products. It deliberately
	 * does NOT reuse a resolved order: the resolver re-walks the line items every
	 * time, because an order routinely gains, loses or replaces items between two
	 * events in one request (ADR-0012 §11b).
	 *
	 * @return Orchestrator
	 */
	protected static function orchestrator(): Orchestrator {
		static $orchestrator = null;

		if ( null === $orchestrator ) {
			$orchestrator = new Orchestrator();
		}

		return $orchestrator;
	}
}
