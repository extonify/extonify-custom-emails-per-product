<?php
/**
 * Resolves order line items to the facts targeting needs (ADR-0011).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Matching;

use Extonify\WCEP\Domain\MatchDecision;
use Extonify\WCEP\Domain\Targeting;

defined( 'ABSPATH' ) || exit;

/**
 * The only WooCommerce-facing part of the matching engine.
 *
 * Turns an order's line items into plain ITEM DESCRIPTORS that
 * `Domain\Targeting` can evaluate without WordPress:
 *
 *     item_id, product_id, variation_id, resolution, type, parent_type,
 *     virtual, downloadable, category_ids, tag_ids
 *
 * READS ONLY THROUGH WOOCOMMERCE CRUD. Never `wp_posts`, never `wp_postmeta`
 * (ADR-0009). Writes nothing at all.
 *
 * Each product is loaded ONCE per resolver and its term ids read once, cached
 * for the request; a second line item of the same product costs no further
 * query, and both trigger families of one status change share one resolution
 * pass. The query cost is therefore a function of the number of DISTINCT
 * products on the order, and is independent of how many rules are evaluated
 * (ADR-0011 §9).
 *
 * IDENTITY COMES FROM THE ITEM, FACTS COME FROM THE PRODUCT. `product_id` and
 * `variation_id` are read from the line item, which stores them, so id-based
 * targeting keeps working even when a product object no longer loads.
 *
 * RESOLUTION HAS THREE STATES, NOT TWO (ADR-0011 §4):
 *
 * | State                | When                          | Descriptor | Note                    |
 * |----------------------|-------------------------------|------------|-------------------------|
 * | `resolved`           | everything needed loaded      | full       | none                    |
 * | `partially_resolved` | variation gone, parent alive  | ids+terms  | `variation_unavailable` |
 * | `unresolved`         | neither loaded                | none       | `product_unavailable`   |
 *
 * The middle state exists because the two-state version was WRONG, not merely
 * coarse: it fell back to the parent's type slug and flags, and a variation may
 * be virtual or downloadable when its parent is not, so a `types` rule could
 * match on facts that never applied to what the customer bought. Skipping the
 * item outright would be wrong too — the customer DID buy that product, and a
 * category or tag rule should still fire after a discontinued variation is
 * removed. So identity matching proceeds and the variation-specific facts are
 * reported UNKNOWN.
 */
class ItemResolver {

	/**
	 * Loaded products, keyed by id. `false` records a failed load so a missing
	 * product is not looked up twice.
	 *
	 * @var array<int,\WC_Product|false>
	 */
	private $products = array();

	/**
	 * Resolved facts, keyed by "product_id:variation_id".
	 *
	 * @var array<string,array|null>
	 */
	private $facts = array();

	/**
	 * Resolved orders, keyed by order id.
	 *
	 * @var array<int,array>
	 */
	private $orders = array();

	/**
	 * Resolve every line item on an order.
	 *
	 * @param \WC_Order $order Order to resolve.
	 * @return array {
	 *     @type array[] $items      Item descriptors, in line-item order.
	 *                               PARTIALLY resolved items ARE included:
	 *                               they still match by id, category and tag.
	 *     @type array   $notes      Line-item id => reason code, for items
	 *                               whose product no longer fully resolves —
	 *                               `product_unavailable` when the item was
	 *                               skipped, `variation_unavailable` when it was
	 *                               kept but its variation facts were lost.
	 *     @type int     $item_count Number of line items the order carries,
	 *                               resolvable or not. ADR-0008 deferral turns
	 *                               on THIS being zero, not on $items being
	 *                               empty: an order whose products were all
	 *                               deleted has items, they are simply gone,
	 *                               and asking again later would get the same
	 *                               answer.
	 * }
	 */
	public function resolve_order( \WC_Order $order ): array {
		$order_id = (int) $order->get_id();

		if ( $order_id > 0 && isset( $this->orders[ $order_id ] ) ) {
			return $this->orders[ $order_id ];
		}

		$items      = array();
		$notes      = array();
		$item_count = 0;

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			++$item_count;

			$product_id   = (int) $item->get_product_id();
			$variation_id = $this->variation_id( $item );
			$facts        = $this->facts( $product_id, $variation_id );

			if ( null === $facts ) {
				$notes[ (int) $item_id ] = MatchDecision::PRODUCT_UNAVAILABLE;
				continue;
			}

			if ( Targeting::PARTIALLY_RESOLVED === $facts['resolution'] ) {
				$notes[ (int) $item_id ] = MatchDecision::VARIATION_UNAVAILABLE;
			}

			$items[] = array_merge(
				array(
					'item_id'      => (int) $item_id,
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
				),
				$facts
			);
		}

		$resolved = array(
			'items'      => $items,
			'notes'      => $notes,
			'item_count' => $item_count,
		);

		if ( $order_id > 0 ) {
			$this->orders[ $order_id ] = $resolved;
		}

		return $resolved;
	}

	/**
	 * Drop every cached product, fact and order.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->products = array();
		$this->facts    = array();
		$this->orders   = array();
	}

	/**
	 * The variation id the line item RECORDED — not the one it will admit to.
	 *
	 * FLAGGED WOOCOMMERCE BEHAVIOUR, verified on WC 10.9.4, and it CONTRADICTS
	 * ADR-0011 §4's premise that "identity comes from the order item":
	 *
	 *   `WC_Order_Item_Product::set_variation_id()` rejects any value whose
	 *   `get_post_type()` is not `product_variation`. The order-item data store
	 *   calls it through `set_props()`, which SWALLOWS the resulting
	 *   `WC_Data_Exception` per property — so once the variation post is
	 *   deleted, `get_variation_id()` returns 0 while `_variation_id` still
	 *   holds the real id in `woocommerce_order_itemmeta`.
	 *
	 * The line item therefore disguises itself as an ordinary parent-product
	 * purchase: a `variations` rule silently stops matching, and — worse — the
	 * resolver would load the PARENT as the primary object and hand targeting
	 * the parent's type slug and flags, which is exactly the affirmatively wrong
	 * inheritance ADR-0011 §4's third resolution state exists to prevent. The
	 * degradation would also never be noted, because nothing would look
	 * degraded.
	 *
	 * `wc_get_order_item_meta()` is WooCommerce's own public order-item meta
	 * API and is the SAME source `WC_Order_Item_Product_Data_Store::read()`
	 * reads this property from, so this stays inside CRUD (ADR-0009): no
	 * `wp_posts`, no `wp_postmeta`, no direct SQL. It costs no query — the data
	 * store's own `get_metadata()` calls during `read()` have already primed the
	 * `order_item` meta cache for this item.
	 *
	 * @param \WC_Order_Item_Product $item Line item.
	 * @return int Recorded variation id, or 0 when the item is not a variation.
	 */
	private function variation_id( \WC_Order_Item_Product $item ): int {
		$variation_id = (int) $item->get_variation_id();

		if ( $variation_id > 0 ) {
			return $variation_id;
		}

		$stored = wc_get_order_item_meta( (int) $item->get_id(), '_variation_id', true );

		return is_scalar( $stored ) ? max( 0, (int) $stored ) : 0;
	}

	/**
	 * The targeting facts for one (product, variation) pair.
	 *
	 * @param int $product_id   Parent product id from the line item.
	 * @param int $variation_id Variation id from the line item, or 0.
	 * @return array|null Facts carrying their `resolution` state, or null when
	 *                    NEITHER object loads (`unresolved`).
	 */
	private function facts( int $product_id, int $variation_id ): ?array {
		$key = $product_id . ':' . $variation_id;

		if ( array_key_exists( $key, $this->facts ) ) {
			return $this->facts[ $key ];
		}

		$variation = $variation_id > 0 ? $this->product( $variation_id ) : null;
		$parent    = $product_id > 0 ? $this->product( $product_id ) : null;

		if ( null === $variation && null === $parent ) {
			$this->facts[ $key ] = null;
			return null;
		}

		/*
		 * DELETED VARIATION, SURVIVING PARENT (ADR-0011 §4). Identity and
		 * taxonomy are still knowable — the ids come from the line item and
		 * variations never carry their own terms anyway — so the item is kept
		 * and matches by product id, variation id, category and tag. The type
		 * slug and the two flags are NOT inherited from the parent: they are
		 * reported unknown, and `Targeting` refuses every `types` entry against
		 * this state rather than answering with the parent's values.
		 */
		if ( $variation_id > 0 && null === $variation ) {
			$facts = array(
				'resolution'   => Targeting::PARTIALLY_RESOLVED,
				'type'         => '',
				'parent_type'  => '',
				'virtual'      => null,
				'downloadable' => null,
				'category_ids' => array_map( 'intval', (array) $parent->get_category_ids() ),
				'tag_ids'      => array_map( 'intval', (array) $parent->get_tag_ids() ),
			);

			$this->facts[ $key ] = $facts;

			return $facts;
		}

		// Facts come from the most specific object that loaded: a variation
		// carries its own virtual/downloadable flags and its own type slug.
		$primary = null !== $variation ? $variation : $parent;

		/*
		 * Categories and tags ALWAYS come from the parent product. Verified on
		 * WC 10.9.4: WC_Product_Variation inherits get_category_ids() and
		 * get_tag_ids() unchanged and its data store never populates them —
		 * parent_data carries title, sku, stock, dimensions and image, and no
		 * taxonomy at all — so reading them off a variation returns an empty
		 * array and every category and tag rule would silently miss every
		 * variation purchase (ADR-0011 §4).
		 */
		$taxonomy_source = null !== $parent ? $parent : $primary;

		/*
		 * WC_Product_Variation::get_type() returns 'variation', never
		 * 'variable', so the parent's slug is carried alongside — otherwise
		 * types: ["variable"] would match no line item anybody ever buys, since
		 * a variable product is only ever purchased as one of its variations.
		 */
		$parent_type = ( null !== $variation && null !== $parent ) ? (string) $parent->get_type() : '';

		$facts = array(
			'resolution'   => Targeting::RESOLVED,
			'type'         => (string) $primary->get_type(),
			'parent_type'  => $parent_type,
			'virtual'      => (bool) $primary->is_virtual(),
			'downloadable' => (bool) $primary->is_downloadable(),
			'category_ids' => array_map( 'intval', (array) $taxonomy_source->get_category_ids() ),
			'tag_ids'      => array_map( 'intval', (array) $taxonomy_source->get_tag_ids() ),
		);

		$this->facts[ $key ] = $facts;

		return $facts;
	}

	/**
	 * Load a product through WooCommerce CRUD, once per id.
	 *
	 * `wc_get_product()` RETURNING AN OBJECT IS NOT EVIDENCE THE PRODUCT
	 * EXISTS. Verified on WC 10.9.4, the two data stores disagree about a
	 * missing post:
	 *
	 *   - `WC_Product_Data_Store_CPT::read()` THROWS, the factory catches it,
	 *     and `wc_get_product()` returns `false` — the expected behaviour;
	 *   - `WC_Product_Variation_Data_Store_CPT::read()` `return`s SILENTLY, so
	 *     a deleted VARIATION yields a fully constructed, entirely hollow
	 *     `WC_Product_Variation`: type `variation`, both flags false, no term
	 *     ids, and a real-looking id.
	 *
	 * Trusting the object would report a deleted variation as an ordinary
	 * variation that simply matched nothing, instead of `product_unavailable`
	 * — the exact silent-miss ADR-0011 §4 exists to prevent. The read flag is
	 * the CRUD-level signal that `read()` actually completed, and it is checked
	 * for every product type rather than only variations, so the plugin does
	 * not depend on which store threw today.
	 *
	 * @param int $product_id Product or variation id.
	 * @return \WC_Product|null Null when the product no longer exists.
	 */
	private function product( int $product_id ): ?\WC_Product {
		if ( ! array_key_exists( $product_id, $this->products ) ) {
			$product = wc_get_product( $product_id );

			$loaded = $product instanceof \WC_Product
				&& $product->get_id() > 0
				&& $product->get_object_read();

			$this->products[ $product_id ] = $loaded ? $product : false;
		}

		return false === $this->products[ $product_id ] ? null : $this->products[ $product_id ];
	}
}
