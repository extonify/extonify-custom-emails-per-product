<?php
/**
 * One delivery's placeholder values (ADR-0014 §4, §5, §6, §8).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

use Extonify\WCEP\Domain\PlaceholderSyntax;
use Extonify\WCEP\Domain\Targeting;
use Extonify\WCEP\Domain\Text;
use Extonify\WCEP\Matching\ItemResolver;

defined( 'ABSPATH' ) || exit;

/**
 * The values ONE delivery resolves against, memoised, with the notes that
 * delivery accumulated.
 *
 * ⚠ PER DELIVERY, NOT PER REQUEST, AND THAT IS ADR-0012 §11 RATHER THAN A STYLE
 * CHOICE. `PlaceholderResolver` is a request-shared collaborator; this object is
 * created for one delivery, carries that delivery's order, matched items, memo
 * and notes, and is discarded with it. A nested delivery — which a third party
 * produces routinely by changing another order's status from inside a send —
 * gets its OWN object and cannot disturb the outer one. There is no per-run
 * state on anything shared, so there is nothing to capture and restore.
 *
 * THE COST CONTRACT (ADR-0014 §8, gate 6) — and it is NOT "costs no queries",
 * which the gate itself disproved:
 *
 *   **No per-placeholder and no per-occurrence query growth. One bounded, named
 *   cost per distinct DATA CLASS a body reads.**
 *
 * The classes and what each costs:
 *
 *   - **order fields and order meta** — 0. They come from the `WC_Order` this
 *     delivery already holds.
 *   - **matched line items and item meta** — 0. `WC_Order::get_items()` is cached
 *     on the order object and is indexed ONCE per delivery here; ⚠ NOT
 *     `WC_Order::get_item()`, which defaults to a database read.
 *   - **products** — 0. They come from the SHARED `ItemResolver` product cache,
 *     which the matcher has already filled for every matched item in this same
 *     request (ADR-0012 §11b).
 *   - **store and request-level lookups** — cold only, 0 warm. The my-account page
 *     permalink, the from-address option and `wc_price()`'s tax classes are cached
 *     by WordPress for the rest of the request.
 *   - ⚠ **shipping line items** — **+2, once per delivery**, and only for a body
 *     that writes `{shipping_method}`. `WC_Order::get_shipping_method()` loads a
 *     line-item TYPE nothing else in the delivery path reads.
 *   - ⚠ **variation attribute TERMS** — 0 for a custom attribute, and for a global
 *     (`pa_`) one the `get_term_by()` lookups WordPress caches for the rest of the
 *     request. Read by `{variation_attributes}` and, since ADR-0016 §7a, by
 *     `unit_label()` — so a capped fallback reads it once per VARIATION unit rather
 *     than once per delivery. It does not scale with placeholder count, occurrence
 *     count or template size, and siblings of one parent share their terms.
 *
 * Every one of those is measured by `PlaceholderTest`, not asserted in a comment.
 */
final class PlaceholderValues {

	/**
	 * Placeholders that resolve from the FIRST matched line item (ADR-0014 §5).
	 */
	const SINGULAR_ITEM_PLACEHOLDERS = array(
		'product_name',
		'product_sku',
		'product_quantity',
		'product_url',
		'variation_name',
		'variation_attributes',
	);

	/**
	 * The two parameterised namespaces (ADR-0014 §6).
	 */
	const META_ORDER = 'order_custom_field';
	const META_ITEM  = 'item_custom_field';

	/**
	 * The filter a site owner uses to permit one protected meta key.
	 */
	const META_FILTER = 'extonify_wcep_meta_placeholder_allowed';

	/**
	 * What joins a unit label's name to the attributes that identify it
	 * (ADR-0016 §7a).
	 *
	 * ⚠ AN EN DASH RATHER THAN WooCommerce's OWN ` - `, and the difference is
	 * legibility rather than taste: a variation title frequently ALREADY contains
	 * ` - ` — WooCommerce builds `Parent - Red` with it — so reusing it would produce
	 * `T-Shirt - Red - Colour: Red, Size: Large` with no visible boundary between what
	 * WooCommerce named and what this plugin appended. It is punctuation, not prose:
	 * nothing here needs translating.
	 */
	const LABEL_SEPARATOR = ' – ';

	/**
	 * How many distinct notes one delivery will record.
	 *
	 * The same reasoning as every other cap in the plugin: a template can carry
	 * an unbounded number of distinct unknown tokens, and a diagnostic that grows
	 * without limit is a memory leak — and, here, a `reason` column that no longer
	 * fits its own storage.
	 */
	const MAX_NOTES = 20;

	/**
	 * The order this delivery is for.
	 *
	 * @var \WC_Order
	 */
	private $order;

	/**
	 * Matched item records, in line-item order (ADR-0011 §5).
	 *
	 * @var array[]
	 */
	private $matched_items;

	/**
	 * The shared product cache.
	 *
	 * @var ItemResolver
	 */
	private $items;

	/**
	 * Resolved values, keyed by `name` or `name:param`.
	 *
	 * MEMOISED SO RESOLUTION DOES NOT SCALE WITH PLACEHOLDER COUNT. Cleared with
	 * the object at the end of the delivery; nothing else clears it, because
	 * nothing else outlives it.
	 *
	 * @var array<string,string>
	 */
	private $values = array();

	/**
	 * Notes recorded this delivery, keyed by token label so each is recorded
	 * ONCE (ADR-0014 §1a).
	 *
	 * @var array<string,string>
	 */
	private $notes = array();

	/**
	 * Notes dropped at self::MAX_NOTES.
	 *
	 * @var int
	 */
	private $dropped_notes = 0;

	/**
	 * This order's product line items, indexed by item id — built at most once
	 * per delivery.
	 *
	 * ⚠ NOT THE CACHE ADR-0012 §11b PROHIBITS, and the difference is lifetime.
	 * That rule removed an order-contents cache from the request-shared
	 * `ItemResolver`, where an order that gained its items later in the same
	 * request was still answered as empty — defeating ADR-0008. This index lives
	 * on a PER-DELIVERY object, over the order that delivery is already holding,
	 * and dies with it: there is no later moment at which it could give a stale
	 * answer.
	 *
	 * @var array<int,\WC_Order_Item_Product>|null
	 */
	private $line_items = null;

	/**
	 * The attributes this set's bound item needs in order to be identifiable, or
	 * `''` when it needs none — computed at most once (ADR-0016 §7a).
	 *
	 * NOT A MEMBER OF self::$values, DELIBERATELY. That map is keyed by
	 * `name:parameter` and every key in it is reachable from a merchant's template;
	 * this is not a placeholder and must not become one by accident.
	 *
	 * @var string|null
	 */
	private $variation_details = null;

	/**
	 * The line item this value set is BOUND to, or 0 (ADR-0014 §5a).
	 *
	 * ⚠ SET ONLY AT THE PER-ITEM INJECTION POSITION, and that position exists to
	 * put content beside each matched line item. With it at 0 — every separate-mode
	 * delivery and every other insert position — item-scoped placeholders resolve
	 * against the FIRST matched item, which is ADR-0014 §5 and is unchanged.
	 *
	 * @var int
	 */
	private $current_item_id;

	/**
	 * Constructor.
	 *
	 * @param \WC_Order    $order           Live order.
	 * @param array[]      $matched_items   Matched item records.
	 * @param ItemResolver $items           Shared product cache.
	 * @param int          $current_item_id Line item being rendered at the per-item
	 *                                      position; 0 everywhere else.
	 */
	public function __construct( \WC_Order $order, array $matched_items, ItemResolver $items, int $current_item_id = 0 ) {
		$this->order           = $order;
		$this->matched_items   = array_values( $matched_items );
		$this->items           = $items;
		$this->current_item_id = $current_item_id;
	}

	/**
	 * The matched record this set's item-scoped placeholders resolve against
	 * (ADR-0014 §5, §5a).
	 *
	 * THE PER-ITEM POSITION TAKES THE ITEM BEING RENDERED; everything else takes
	 * the first matched item in line-item order. ⚠ The bound item must still be one
	 * of THIS rule's matched items: a rule matching one of three line items renders
	 * beside that one, and a binding that fell back to an unmatched item would put
	 * a different product's meta in the block.
	 *
	 * @return array|null Matched item record, or null.
	 */
	private function scoped_item(): ?array {
		if ( $this->current_item_id > 0 ) {
			foreach ( $this->matched_items as $matched ) {
				if ( (int) ( $matched['item_id'] ?? 0 ) === $this->current_item_id ) {
					return $matched;
				}
			}
		}

		return isset( $this->matched_items[0] ) ? $this->matched_items[0] : null;
	}

	/**
	 * Render one template for one destination (ADR-0014 §2, §3).
	 *
	 * SINGLE-PASS: `PlaceholderSyntax::render()` substitutes once and never looks
	 * at what it substituted. This method escapes each value for `$context`
	 * BEFORE handing it back, so the escaped text is what lands in the output and
	 * nothing downstream has to remember to escape it.
	 *
	 * @param string $template Merchant-authored template.
	 * @param string $context  One of the `PlaceholderSyntax::CONTEXT_*` values.
	 * @return string
	 */
	public function render( string $template, string $context ): string {
		return PlaceholderSyntax::render(
			$template,
			function ( string $name, ?string $param ) use ( $context ): string {
				$raw     = $this->value( $name, $param );
				$escaped = PlaceholderSyntax::escape( $raw, $context );

				/*
				 * A SANITISED INJECTION IS STILL AN EVENT (ADR-0012 §4). A billing
				 * name carrying `\r\nBcc:` cannot reach header composition — it is
				 * stripped as it is substituted — but the merchant is entitled to
				 * know it was attempted. Compared against the TRIMMED raw value, so
				 * ordinary trailing whitespace does not masquerade as an attack.
				 */
				if ( PlaceholderSyntax::CONTEXT_HEADER === $context && trim( $raw ) !== $escaped ) {
					$this->note(
						PlaceholderSyntax::label( $name, $param ),
						'stripped a line break or control character from'
					);
				}

				return $escaped;
			}
		);
	}

	/**
	 * The RAW resolved value for one token, memoised.
	 *
	 * Always TEXT, never markup (ADR-0014 §3): the WooCommerce helpers that
	 * return HTML — `wc_price()`, `get_formatted_billing_address()` — are
	 * flattened here, so every value leaves this method in the same class and
	 * the escaping rules have no exceptions to carry.
	 *
	 * @param string      $name  Normalised placeholder name.
	 * @param string|null $param Parameter, or null.
	 * @return string
	 */
	public function value( string $name, ?string $param = null ): string {
		$key = null === $param ? $name : $name . ':' . $param;

		if ( array_key_exists( $key, $this->values ) ) {
			return $this->values[ $key ];
		}

		$this->values[ $key ] = $this->resolve( $name, $param );

		return $this->values[ $key ];
	}

	/**
	 * Resolve one token for the FIRST time.
	 *
	 * @param string      $name  Normalised placeholder name.
	 * @param string|null $param Parameter, or null.
	 * @return string
	 */
	private function resolve( string $name, ?string $param ): string {
		if ( self::META_ORDER === $name || self::META_ITEM === $name ) {
			return $this->meta_value( $name, $param );
		}

		if ( null !== $param ) {
			// A parameter on a placeholder that takes none is not the
			// placeholder: `{customer_email:x}` is unrecognised, and saying so
			// beats silently ignoring the part the merchant wrote.
			return $this->unknown( $name, $param );
		}

		if ( in_array( $name, self::SINGULAR_ITEM_PLACEHOLDERS, true ) ) {
			return $this->item_value( $name );
		}

		$order = $this->order_value( $name );

		if ( null !== $order ) {
			return $order;
		}

		$list = $this->list_value( $name );

		if ( null !== $list ) {
			return $list;
		}

		return $this->unknown( $name, null );
	}

	/**
	 * Customer, order and store placeholders (ADR-0014 §4).
	 *
	 * @param string $name Normalised name.
	 * @return string|null Null when this is not one of them.
	 */
	private function order_value( string $name ) {
		switch ( $name ) {
			case 'customer_first_name':
				return (string) $this->order->get_billing_first_name();
			case 'customer_last_name':
				return (string) $this->order->get_billing_last_name();
			case 'customer_full_name':
				return trim( (string) $this->order->get_formatted_billing_full_name() );
			case 'customer_email':
				return (string) $this->order->get_billing_email();
			case 'customer_phone':
				return (string) $this->order->get_billing_phone();

			case 'order_number':
				return (string) $this->order->get_order_number();
			case 'order_date':
				$created = $this->order->get_date_created();
				return $created instanceof \WC_DateTime ? (string) wc_format_datetime( $created ) : '';
			case 'order_status':
				// The human-readable name, not the slug: a customer reading
				// "wc-processing" learns nothing.
				return (string) wc_get_order_status_name( $this->order->get_status() );
			case 'order_total':
				return self::flatten(
					wc_price( $this->order->get_total(), array( 'currency' => $this->order->get_currency() ) )
				);
			case 'payment_method':
				return (string) $this->order->get_payment_method_title();
			case 'shipping_method':
				return (string) $this->order->get_shipping_method();
			case 'billing_address':
				return self::flatten( $this->order->get_formatted_billing_address() );
			case 'shipping_address':
				return self::flatten( $this->order->get_formatted_shipping_address() );
			case 'view_order_url':
				return (string) $this->order->get_view_order_url();

			case 'store_name':
				// Decoded exactly as WooCommerce's own `get_blogname()` does, so
				// a store called "Ben &amp; Jerry" is not what the customer reads.
				return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
			case 'store_email':
				return self::store_email();
			case 'store_url':
				return (string) home_url();
			case 'my_account_url':
				return (string) wc_get_page_permalink( 'myaccount' );
		}

		return null;
	}

	/**
	 * The store's email identity (ADR-0014 §4).
	 *
	 * The `woocommerce_email_from_address` setting, falling back to the site
	 * admin address — the same source `WC_Email::get_from_address()` uses, so
	 * `{store_email}` is the address the store already sends from rather than a
	 * second, different answer to the same question.
	 *
	 * @return string
	 */
	public static function store_email(): string {
		$from = sanitize_email( (string) get_option( 'woocommerce_email_from_address', '' ) );

		if ( '' !== $from ) {
			return $from;
		}

		return sanitize_email( (string) get_option( 'admin_email', '' ) );
	}

	/**
	 * The plural product placeholders (ADR-0014 §5).
	 *
	 * @param string $name Normalised name.
	 * @return string|null Null when this is not one of them.
	 */
	private function list_value( string $name ) {
		if ( 'product_names' !== $name && 'matched_product_list' !== $name ) {
			return null;
		}

		$lines = array();

		foreach ( $this->matched_items as $matched ) {
			$item = $this->line_item( $matched );

			if ( null === $item ) {
				continue;
			}

			$label = (string) $item->get_name();

			if ( '' === $label ) {
				continue;
			}

			$lines[] = 'product_names' === $name
				? $label
				: (string) $item->get_quantity() . ' × ' . $label;
		}

		// `\n` for the list and `, ` for the inline form. The HTML context turns
		// each `\n` into a `<br />` AFTER escaping, so the list reads correctly
		// in both formats without either one carrying markup.
		return implode( 'product_names' === $name ? ', ' : "\n", $lines );
	}

	/**
	 * The singular product placeholders, resolved against the FIRST matched item
	 * in line-item order (ADR-0014 §5).
	 *
	 * @param string $name Normalised name.
	 * @return string
	 */
	private function item_value( string $name ): string {
		// ADR-0014 §5a: the item being rendered at the per-item position, the first
		// matched item everywhere else.
		$matched = $this->scoped_item();

		if ( null === $matched ) {
			// A rule can be delivered with no matched items only in insert mode's
			// non-targeted case; there is nothing to name, and nothing went wrong.
			return '';
		}

		$item = $this->line_item( $matched );

		if ( null === $item ) {
			return '';
		}

		if ( 'product_quantity' === $name ) {
			return (string) $item->get_quantity();
		}

		if ( 'product_name' === $name ) {
			// THE LINE ITEM'S name — what the customer bought, exactly as the
			// order table shows it — rather than the product's current title.
			return (string) $item->get_name();
		}

		$variation_id = (int) ( $matched['variation_id'] ?? 0 );
		$product_id   = (int) ( $matched['product_id'] ?? 0 );
		$variation    = $variation_id > 0 ? $this->items->product_for( $variation_id ) : null;
		$parent       = $product_id > 0 ? $this->items->product_for( $product_id ) : null;

		if ( 'variation_name' === $name || 'variation_attributes' === $name ) {
			/*
			 * ⚠ EMPTY FOR A NON-VARIATION AND FOR A PARTIALLY RESOLVED ITEM
			 * (ADR-0011 §4, ADR-0014 §5). A deleted variation whose parent
			 * survives is `PARTIALLY_RESOLVED`: the ids are still knowable, the
			 * variation's own facts are not. Falling back to the parent's name or
			 * attributes would tell the customer they bought a variation nobody
			 * can identify — the same rule `types` targeting already holds, where
			 * unknown refuses rather than answering with the parent's value.
			 */
			if ( $variation_id <= 0 || ! $variation instanceof \WC_Product ) {
				return '';
			}

			return 'variation_name' === $name
				? (string) $variation->get_name()
				: self::flatten( wc_get_formatted_variation( $variation, true, true, false ) );
		}

		$product = $variation instanceof \WC_Product ? $variation : $parent;

		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		if ( 'product_sku' === $name ) {
			return (string) $product->get_sku();
		}

		// The one remaining singular placeholder is the product URL.
		return (string) $product->get_permalink();
	}

	/**
	 * THE LABEL FOR ONE FAN-OUT UNIT — a name that identifies it (ADR-0016 §7a).
	 *
	 * ⚠ THIS IS NOT `{product_name}`, AND THE DIFFERENCE IS A TIER 1 DEFECT. In a
	 * capped fallback the units past the bound carry their label and NOTHING ELSE —
	 * no merchant body, no placeholders — so the label is the whole of what the
	 * customer receives about that unit. An order item's name does not distinguish
	 * sibling variations, because WooCommerce generates a variation's title WITHOUT
	 * its attributes in two ordinary cases (verified in WC 10.9.4,
	 * `WC_Product_Variation_Data_Store_CPT::generate_product_title()`):
	 *
	 *   1. the variation has **3 or more** attributes — `count( $attributes ) < 3`;
	 *   2. it has **2 or more** and any attribute KEY contains a hyphen, which is what
	 *      a multi-word attribute name (`Shirt Size` → `shirt-size`) produces;
	 *
	 *   (and a third, degenerate one: every attribute is left "Any", so there is no
	 *   value to put in the title at all.)
	 *
	 * In each case both siblings are titled with the bare parent name, so two units
	 * ADR-0016 §4 defines as DISTINCT — `variation:101` and `variation:102` — arrive as
	 * two identical lines and either could be either. That the plugin has a separate
	 * `{variation_attributes}` placeholder is itself the evidence that the name was
	 * never expected to carry them.
	 *
	 * ⚠ ONLY WHAT THE NAME DOES NOT ALREADY SAY. The fourth argument to
	 * `wc_get_formatted_variation()` is WooCommerce's own "do not list attributes
	 * already part of the variation name", so a one-attribute variation whose title IS
	 * `T-Shirt - Red` keeps exactly that label and gains nothing. Appending
	 * unconditionally would render `T-Shirt - Red – Colour: Red`.
	 *
	 * ⚠ A SIMPLE PRODUCT'S LABEL IS BYTE-IDENTICAL to `{product_name}`: there is no
	 * variation, so there are no details, so nothing is appended. No churn where there
	 * is no problem.
	 *
	 * ⚠ AND A PARTIALLY RESOLVED VARIATION KEEPS THE PARENT LABEL, which is the same
	 * rule `{variation_attributes}` already follows for the same reason (ADR-0011 §4):
	 * the variation's own facts are gone, ADR-0016 §4 collapses it onto the parent
	 * unit, and there is nothing left to identify. Inventing detail there would be
	 * worse than admitting none.
	 *
	 * @return string
	 */
	public function unit_label(): string {
		$name    = $this->value( 'product_name' );
		$details = $this->variation_details();

		if ( '' === $details ) {
			return $name;
		}

		return '' === $name ? $details : $name . self::LABEL_SEPARATOR . $details;
	}

	/**
	 * The attributes the bound item's NAME does not already carry (ADR-0016 §7a).
	 *
	 * COST: zero queries for a custom attribute, and for a global (`pa_`) one the term
	 * lookups WordPress caches for the rest of the request — the same data class
	 * `{variation_attributes}` already reads, and siblings of one parent share their
	 * terms. Memoised here because a set may be asked for its label more than once.
	 *
	 * @return string
	 */
	private function variation_details(): string {
		if ( null !== $this->variation_details ) {
			return $this->variation_details;
		}

		$this->variation_details = '';

		$matched      = $this->scoped_item();
		$variation_id = null === $matched ? 0 : (int) ( $matched['variation_id'] ?? 0 );

		if ( $variation_id <= 0 ) {
			// A simple product. Nothing to add, and adding nothing is the point.
			return $this->variation_details;
		}

		$variation = $this->items->product_for( $variation_id );

		if ( ! $variation instanceof \WC_Product ) {
			// PARTIALLY RESOLVED (ADR-0011 §4): the id survives, the facts do not.
			return $this->variation_details;
		}

		// ⚠ THE FOURTH ARGUMENT IS THE ONE THAT MATTERS: skip attributes already part
		// of the variation name. WC 10.9.4,
		// `wc_get_formatted_variation( $variation, $flat, $include_names, $skip_attributes_in_name )`.
		// It is asked ONLY when the name can answer — see self::title_collapsed().
		$parent_id = (int) ( $matched['product_id'] ?? 0 );

		$this->variation_details = self::flatten(
			wc_get_formatted_variation( $variation, true, true, ! $this->title_collapsed( $variation, $parent_id ) )
		);

		return $this->variation_details;
	}

	/**
	 * Whether this variation's generated title COLLAPSED to the bare parent name
	 * (ADR-0016 §7a, ADR-0017; carried from Prompt 8C).
	 *
	 * ⚠ WHY THE SKIP ARGUMENT CANNOT BE ASKED WHEN IT HAS. `skip_attributes_in_name`
	 * means "do not list attributes already part of the variation name", and
	 * WooCommerce answers it with `wc_is_attribute_in_product_name()`, which is a
	 * SUBSTRING TEST over the title:
	 *
	 *     stristr( $name, ' ' . $value . ',' ) || 0 === stripos( strrev( $name ), strrev( ' ' . $value ) )
	 *
	 * When the title collapsed, it contains NO attributes at all — it is exactly the
	 * parent's name — so every match that test reports is a FALSE POSITIVE BY
	 * CONSTRUCTION: it found the value in text the parent's author wrote, not in a
	 * suffix WooCommerce appended. A parent named `T-Shirt Red, Blue` makes
	 * ` Red,` and a trailing ` Blue` both hit, so two three-attribute siblings
	 * differing ONLY in colour have their colour skipped and render the SAME LABEL —
	 * and a label-only fallback section is the whole of what the customer receives
	 * about that unit (ADR-0016 §7a). Two distinct units, one indistinguishable line.
	 *
	 * ⚠ AND WHY IT IS STILL ASKED OTHERWISE. Where the title did NOT collapse it
	 * genuinely carries the values WooCommerce put there, the test is answering the
	 * question it was written for, and honouring it is what keeps `T-Shirt - Red`
	 * from rendering as `T-Shirt - Red – Colour: Red`. The fix is to stop asking a
	 * heuristic a question it cannot answer, not to stop trusting it.
	 *
	 * COST: zero queries. The parent is already in `ItemResolver`'s cache — the
	 * matching engine loaded it to resolve this very item — and a miss returns null,
	 * which reads as "not collapsed" and keeps the previous behaviour.
	 *
	 * @param \WC_Product $variation The variation.
	 * @param int         $parent_id The line item's parent product id.
	 * @return bool
	 */
	private function title_collapsed( \WC_Product $variation, int $parent_id ): bool {
		$parent = $this->items->product_for( $parent_id );

		if ( ! $parent instanceof \WC_Product ) {
			return false;
		}

		return (string) $variation->get_name() === (string) $parent->get_name();
	}

	/**
	 * A parameterised meta placeholder (ADR-0014 §6).
	 *
	 * FOUR CONSTRAINTS, EACH CLOSING A SPECIFIC HOLE, and every refusal is
	 * RECORDED so a merchant sees "the plugin declined" rather than "the field
	 * was empty":
	 *
	 *   1. the key's SHAPE is tested on the RAW value and a bad key is refused,
	 *      never repaired — `sanitize_key()` would turn `total paid note` into
	 *      the different, valid key `totalpaidnote` and read whatever that holds;
	 *   2. an underscore-prefixed key is refused by default, because that is
	 *      where WooCommerce keeps payment tokens, IP addresses and internal
	 *      state;
	 *   3. a site owner can permit one such key deliberately, per key, in code —
	 *      and can refuse a PUBLIC key just as deliberately, which is a different
	 *      fact from (2) and is recorded as one. ⚠ **ONLY A LITERAL `true` OPENS A
	 *      KEY**; anything else the filter returns is itself a refusal (§6.5);
	 *   4. only STRINGS, INTEGERS AND FLOATS are printable (ADR-0014 §6.3). Not
	 *      "scalars": PHP counts `true` as a scalar and a customer reading "1"
	 *      where a merchant meant a date learns nothing.
	 *
	 * @param string      $scope self::META_ORDER or self::META_ITEM.
	 * @param string|null $param Raw key from the template.
	 * @return string
	 */
	private function meta_value( string $scope, ?string $param ): string {
		$key = null === $param ? '' : $param;

		if ( '' === $key ) {
			/*
			 * ⚠ AN EXPLICITLY EMPTY PARAMETER REACHES HERE AS `''` AND IS NAMED AS
			 * SUCH (ADR-0014 §1e). `{order_custom_field:}` used to be COERCED into
			 * `{order_custom_field}` by the grammar layer, so the two spellings were
			 * indistinguishable by the time this ran; they are now separate facts and
			 * the merchant is told which one they wrote. `null` — no parameter at all
			 * — lands here too, and the wording covers both.
			 */
			$this->note( PlaceholderSyntax::label( $scope, $param ), 'refused a meta placeholder with an empty key' );
			return '';
		}

		if ( ! PlaceholderSyntax::is_valid_meta_key( $key ) ) {
			$this->note( PlaceholderSyntax::label( $scope, $param ), 'refused a malformed meta key' );
			return '';
		}

		$protected = PlaceholderSyntax::is_protected_meta_key( $key );
		$allowed   = ! $protected;

		/**
		 * Permit — or refuse — one meta key in a customer-facing placeholder.
		 *
		 * Arrives `false` for a WordPress-protected (underscore-prefixed) key and
		 * `true` for a public one, so the default is refusal for exactly the class
		 * of key WooCommerce stores payment tokens and IP addresses under
		 * (ADR-0014 §6).
		 *
		 * @since 1.0.0
		 *
		 * ⚠ RETURN A LITERAL BOOLEAN. Anything else — including a `WP_Error` — is
		 * refused and recorded (§6.5); it is never read as consent.
		 *
		 * @param bool      $allowed Whether the key may be read.
		 * @param string    $key     The raw meta key.
		 * @param string    $scope   `order_custom_field` or `item_custom_field`.
		 * @param \WC_Order $order   The order being rendered.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant IS the prefixed name (`extonify_wcep_meta_placeholder_allowed`); it is referenced rather than repeated so the hook a site owner writes and the hook this plugin fires cannot drift apart.
		$decision = apply_filters( self::META_FILTER, $allowed, $key, $scope, $this->order );

		/*
		 * ⚠ ONLY A LITERAL BOOLEAN DECIDES, AND A CAST WOULD BE A SECURITY FAIL-OPEN
		 * (ADR-0014 §6.5). This used to be `(bool) apply_filters( … )`, so for a
		 * PROTECTED key — where the default is refusal — ANY truthy return opened it:
		 * `"no"`, `"false"`, `1`, a non-empty array, an `stdClass`, and worst of all
		 * **`WP_Error`**, which is what a callback conventionally returns when it
		 * FAILED TO DECIDE. Under that cast, "I could not work out whether this key is
		 * safe" was read as "yes, mail this key to the customer" — and the key in
		 * question is the class `_stripe_source_id` and `_customer_ip_address` live in.
		 *
		 * An extension point that cannot decide must never be read as consent, so a
		 * non-boolean return is a REFUSAL IN ITS OWN RIGHT, with its own recorded
		 * reason — the same fail-closed shape already required of claim results and
		 * database errors. Refusing here rather than falling back to `$allowed` is
		 * deliberate too: a filter that answered incoherently for a PUBLIC key has
		 * still answered incoherently, and the merchant is told so instead of being
		 * silently served the value.
		 */
		if ( ! is_bool( $decision ) ) {
			$this->note(
				PlaceholderSyntax::label( $scope, $param ),
				'refused an invalid non-boolean meta permission result ('
					. self::type_name( $decision ) . ') from ' . self::META_FILTER . ' for'
			);
			return '';
		}

		$allowed = $decision;

		if ( ! $allowed ) {
			/*
			 * ⚠ TWO REFUSALS, TWO REASONS, BECAUSE THEY ARE DIFFERENT FACTS. A
			 * `_`-prefixed key is refused by THIS PLUGIN'S default-deny policy and
			 * the merchant's remedy is the filter. A PUBLIC key is only ever refused
			 * because a site owner's own filter said so, and telling them the plugin
			 * "refused a protected meta key" would send them looking for an
			 * underscore that is not there — a diagnostic that is simply false.
			 */
			$this->note(
				PlaceholderSyntax::label( $scope, $param ),
				$protected
					? 'refused a protected meta key'
					: 'a site filter (' . self::META_FILTER . ') refused the public meta key'
			);
			return '';
		}

		$value = $this->raw_meta( $scope, $key );

		if ( ! PlaceholderSyntax::is_printable_meta_value( $value ) ) {
			if ( null !== $value && '' !== $value ) {
				// Recorded, because "my key holds data and prints nothing" is
				// otherwise unanswerable — and the two cases are named apart, since
				// a merchant's fix for a boolean flag is not their fix for an array.
				$this->note(
					PlaceholderSyntax::label( $scope, $param ),
					is_bool( $value )
						? 'refused a boolean meta value, which has no customer-facing spelling'
						: 'refused a non-printable meta value'
				);
			}

			return '';
		}

		return (string) $value;
	}

	/**
	 * A short, safe type name for a value a filter returned (ADR-0014 §6.5).
	 *
	 * NAMES THE TYPE, NEVER PRINTS THE VALUE. This string goes into a `reason`
	 * column a merchant reads; a filter that returned an object or an array could
	 * otherwise put whatever it liked — including order meta it had just been asked
	 * about — into the delivery log, which is the same exposure the refusal exists
	 * to prevent. `WP_Error` is named specifically because it is the return a
	 * failing callback conventionally produces, and telling the merchant "your
	 * filter errored" is a different diagnosis from "your filter returned a string".
	 *
	 * @param mixed $value Whatever the filter handed back.
	 * @return string
	 */
	private static function type_name( $value ): string {
		if ( is_object( $value ) ) {
			return get_class( $value );
		}

		return gettype( $value );
	}

	/**
	 * Read one meta value from the order or from the first matched line item.
	 *
	 * @param string $scope self::META_ORDER or self::META_ITEM.
	 * @param string $key   Validated key.
	 * @return mixed
	 */
	private function raw_meta( string $scope, string $key ) {
		if ( self::META_ORDER === $scope ) {
			return $this->order->get_meta( $key, true );
		}

		/*
		 * ⚠ THE ITEM BEING RENDERED, AT THE PER-ITEM POSITION (ADR-0014 §5a). This
		 * always read `matched_items[0]`, so a rule emitting beside item B printed
		 * item A's `{item_custom_field:…}` — a gift note or an engraving on the wrong
		 * product line, in the one position built specifically to sit beside each
		 * matched item.
		 */
		$matched = $this->scoped_item();
		$item    = null === $matched ? null : $this->line_item( $matched );

		return null === $item ? '' : $item->get_meta( $key, true );
	}

	/**
	 * The live line item behind one matched record.
	 *
	 * READ FROM THE ORDER, NEVER CACHED HERE. `WC_Order::get_items()` is cached
	 * on the order object by WooCommerce, so the line-items data class measures 0
	 * under the ADR-0014 §8 contract — and reading it fresh is what ADR-0012 §11b
	 * requires after an order-contents cache defeated ADR-0008.
	 *
	 * @param array $matched Matched item record.
	 * @return \WC_Order_Item_Product|null
	 */
	private function line_item( array $matched ) {
		$item_id = (int) ( $matched['item_id'] ?? 0 );

		if ( $item_id <= 0 ) {
			return null;
		}

		$items = $this->line_items();

		return isset( $items[ $item_id ] ) ? $items[ $item_id ] : null;
	}

	/**
	 * This order's product line items, indexed by id.
	 *
	 * ⚠ `WC_Order::get_item( $id )` DEFAULTS TO `$load_from_db = true` AND ISSUES
	 * A QUERY EVERY TIME (WC 10.9.4, `abstract-wc-order.php:1091` —
	 * `WC_Order_Factory::get_order_item( $item_id )`). Calling it per placeholder
	 * cost ONE QUERY PER PRODUCT PLACEHOLDER, which the ADR-0014 §8 cost gate
	 * caught: a body with 25 placeholders cost 24 queries more than the same body
	 * with one. `get_items()` reads the collection ONCE and WooCommerce caches it
	 * on the order object, so indexing it here is free for every later lookup.
	 *
	 * @return array<int,\WC_Order_Item_Product>
	 */
	private function line_items(): array {
		if ( null !== $this->line_items ) {
			return $this->line_items;
		}

		$this->line_items = array();

		foreach ( $this->order->get_items() as $item_id => $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$this->line_items[ (int) $item_id ] = $item;
			}
		}

		return $this->line_items;
	}

	/**
	 * An unrecognised placeholder: empty, and recorded once (ADR-0014 §1a).
	 *
	 * @param string      $name  Normalised name.
	 * @param string|null $param Parameter, or null.
	 * @return string
	 */
	private function unknown( string $name, ?string $param ): string {
		$this->note( PlaceholderSyntax::label( $name, $param ), 'unknown placeholder' );

		return '';
	}

	/**
	 * Record one note, ONCE per distinct token (ADR-0014 §1a).
	 *
	 * A template with the same typo in twenty places is one authoring mistake,
	 * not twenty events — and twenty copies of one sentence would push the useful
	 * notes beside it out of the `reason` column.
	 *
	 * ⚠ CAPPED IN BOTH DIMENSIONS (Prompt 7 C2). The count cap alone left each
	 * entry unbounded, and the entries are not all plugin-authored: the LABEL is
	 * the merchant's token text, and the grammar deliberately places no length
	 * limit on a parameter (ADR-0014 §1b), so a 4 KB meta key produced a 4 KB note.
	 * Twenty of those is not a bounded collection.
	 *
	 * @param string $label  Token label, `{name}` or `{name:param}`.
	 * @param string $reason What happened.
	 * @return void
	 */
	private function note( string $label, string $reason ): void {
		if ( isset( $this->notes[ $label ] ) ) {
			return;
		}

		if ( count( $this->notes ) >= self::MAX_NOTES ) {
			++$this->dropped_notes;
			return;
		}

		$this->notes[ $label ] = Text::note_value( $reason . ' ' . $label );
	}

	/**
	 * Every note this delivery accumulated, in first-occurrence order.
	 *
	 * @return string[]
	 */
	public function notes(): array {
		$notes = array_values( $this->notes );

		if ( $this->dropped_notes > 0 ) {
			$notes[] = self::overflow_note( $this->dropped_notes );
		}

		return $notes;
	}

	/**
	 * The sentinel that replaces the notes a cap dropped.
	 *
	 * ⚠ ONE SPELLING, THREE CALLERS. This set's own overflow, the SECTIONED body's
	 * merged overflow (ADR-0016 §7) and the containment boundary's merge all produce
	 * it, and a merchant reading two different sentences for one fact would reasonably
	 * conclude they were two different facts.
	 *
	 * @param int $dropped How many notes were not recorded.
	 * @return string
	 */
	public static function overflow_note( int $dropped ): string {
		return 'and ' . $dropped . ' further placeholder notes not recorded';
	}

	/**
	 * Fold one note list into an accumulating, de-duplicated, CAPPED set.
	 *
	 * ⚠ THIS IS WHAT KEEPS A MULTI-SET DELIVERY'S DIAGNOSTICS THE SAME SIZE AS A
	 * SINGLE-SET ONE (ADR-0016 §7). The cap fallback renders the merchant's body once
	 * per unit, and an unknown token is unknown in EVERY unit — so sixty sets would
	 * otherwise repeat one authoring mistake sixty times, and sixty sets ×
	 * self::MAX_NOTES would put twelve hundred note strings in a `reason` column that
	 * holds one sentence. De-duplication and the cap are both required, and the bound
	 * is deliberately the SAME constant a single delivery is held to.
	 *
	 * The accumulator is an opaque `{seen, dropped}` pair rather than a plain array so
	 * the dropped COUNT survives the fold — a cap that silently discards is the failure
	 * this project records rather than hides.
	 *
	 * @param array    $accumulator Accumulator, or `array()` to start one.
	 * @param string[] $notes       Notes to fold in.
	 * @return array{seen:array<string,bool>,dropped:int}
	 */
	public static function fold_notes( array $accumulator, array $notes ): array {
		$seen    = (array) ( $accumulator['seen'] ?? array() );
		$dropped = (int) ( $accumulator['dropped'] ?? 0 );

		foreach ( $notes as $note ) {
			$note = (string) $note;

			if ( isset( $seen[ $note ] ) ) {
				continue;
			}

			if ( count( $seen ) >= self::MAX_NOTES ) {
				++$dropped;
				continue;
			}

			$seen[ $note ] = true;
		}

		return array(
			'seen'    => $seen,
			'dropped' => $dropped,
		);
	}

	/**
	 * An accumulator's notes, in first-occurrence order, with its overflow sentinel.
	 *
	 * @param array $accumulator Accumulator from self::fold_notes().
	 * @return string[]
	 */
	public static function folded_notes( array $accumulator ): array {
		$notes   = array_map( 'strval', array_keys( (array) ( $accumulator['seen'] ?? array() ) ) );
		$dropped = (int) ( $accumulator['dropped'] ?? 0 );

		if ( $dropped > 0 ) {
			$notes[] = self::overflow_note( $dropped );
		}

		return $notes;
	}

	/**
	 * The notes as one sentence, for a `reason` column.
	 *
	 * @return string
	 */
	public function notes_line(): string {
		return implode( '; ', $this->notes() );
	}

	/**
	 * Whether anything was recorded this delivery.
	 *
	 * @return bool
	 */
	public function has_notes(): bool {
		return array() !== $this->notes;
	}

	/**
	 * Flatten a WooCommerce helper's HTML to text (ADR-0014 §3).
	 *
	 * ⚠ WHY EVERY VALUE IS TEXT. `wc_price()` returns a `<span class="…">`
	 * wrapper and `get_formatted_address()` returns `<br/>`-joined parts that
	 * WooCommerce has ALREADY `esc_html`-escaped. Substituting either as-is would
	 * create a second class of value with a second escaping rule, and the first
	 * value that changed class would be the vulnerability. Flattening decodes
	 * what WooCommerce escaped and drops what WooCommerce added, so the value is
	 * text and is escaped exactly once — by us, for the destination it is
	 * actually going to.
	 *
	 * @param mixed $value Helper output.
	 * @return string
	 */
	private static function flatten( $value ): string {
		return Text::to_plain_text( (string) $value );
	}

	/**
	 * The resolution state of the first matched item, for diagnostics and tests.
	 *
	 * @return string One of the `Targeting` resolution constants, or `''`.
	 */
	public function first_item_resolution(): string {
		$matched = $this->scoped_item();

		if ( null === $matched ) {
			return '';
		}

		$variation_id = (int) ( $matched['variation_id'] ?? 0 );

		if ( $variation_id > 0 && ! $this->items->product_for( $variation_id ) instanceof \WC_Product ) {
			return Targeting::PARTIALLY_RESOLVED;
		}

		return Targeting::RESOLVED;
	}
}
