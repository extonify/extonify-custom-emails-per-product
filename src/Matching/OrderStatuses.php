<?php
/**
 * Order-status discovery for trigger matching (ADR-0011).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Matching;

use Extonify\WCEP\Domain\TriggerEvent;

defined( 'ABSPATH' ) || exit;

/**
 * Which order statuses exist, and which of them may fire a trigger.
 *
 * Statuses are discovered from `wc_get_order_statuses()`, so a status
 * registered by a theme or another plugin is matchable without any change
 * here.
 *
 * DISCOVERY DOES NOT EXCLUDE DRAFT ORDERS. It is tempting to assume
 * `checkout-draft` is absent from that list; it is not. Verified on WC 10.9.4:
 * `Automattic\WooCommerce\Blocks\Domain\Services\DraftOrders` registers
 * `wc-checkout-draft` through the `wc_order_statuses` filter, so any install
 * with WooCommerce Blocks active returns it and discovery alone would let a
 * half-finished basket send customer email. The exclusion is therefore an
 * explicit constant (`TriggerEvent::CHECKOUT_DRAFT`), not an emergent property
 * of the list.
 *
 * Deliberately NOT cached. `wc_get_order_statuses()` builds an array and
 * applies one filter — it issues no query — so a cache would buy nothing and
 * would go stale the moment a plugin registered a status mid-request.
 */
class OrderStatuses {

	/**
	 * Every known order status, normalised without the `wc-` prefix, with the
	 * block-checkout draft status removed.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		$slugs = array();

		foreach ( array_keys( (array) wc_get_order_statuses() ) as $raw ) {
			$slug = TriggerEvent::normalize_status( (string) $raw );
			if ( '' === $slug || TriggerEvent::CHECKOUT_DRAFT === $slug ) {
				continue;
			}
			$slugs[] = $slug;
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Whether a slug is a known, non-draft order status.
	 *
	 * @param string $status Status slug, with or without the `wc-` prefix.
	 * @return bool
	 */
	public static function is_known( string $status ): bool {
		return in_array( TriggerEvent::normalize_status( $status ), self::all(), true );
	}

	/**
	 * Whether an event may fire at all (ADR-0011 §1).
	 *
	 * The DESTINATION status governs. `status:checkout-draft` and
	 * `transition:{any}>checkout-draft` never fire;
	 * `transition:checkout-draft>pending` does, because the order has LEFT the
	 * draft state — a genuine lifecycle event, and the only way to target the
	 * moment a block-checkout order becomes real.
	 *
	 * Refund events carry no status and are gated on identity validity alone.
	 *
	 * @param TriggerEvent $event Trigger event.
	 * @return bool
	 */
	public static function is_triggering( TriggerEvent $event ): bool {
		if ( ! $event->is_triggerable() ) {
			return false;
		}

		if ( ! $event->is_status_event() ) {
			return true;
		}

		return self::is_known( $event->destination_status() );
	}
}
