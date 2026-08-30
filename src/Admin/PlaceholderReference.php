<?php
/**
 * The editor's placeholder reference (ADR-0014 §4, ADR-0017).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

use Extonify\WCEP\Delivery\PlaceholderValues;
use Extonify\WCEP\Domain\PlaceholderSyntax;

defined( 'ABSPATH' ) || exit;

/**
 * Every v1.0 placeholder, by category, for the click-to-insert panel.
 *
 * ⚠ THE EDITOR IS THE ONLY PLACE THE SINGULAR/PLURAL DISTINCTION CAN BE MADE
 * VISIBLE, and ADR-0014 §5 says so in as many words: "the rule editor's placeholder
 * picker should surface the plural forms alongside the singular ones and say which
 * is which, because the editor is the only place this distinction can be made
 * visible before the email is sent." So the product category lists them adjacently
 * and each singular form carries a description saying it takes the FIRST matched
 * product. `Admin\Warnings` then fires W1 when a rule actually uses one on targeting
 * that can match several — the panel teaches, the warning catches.
 *
 * ⚠ THE ITEM-SCOPED NAMES ARE READ FROM `PlaceholderValues`, NOT RETYPED. Which
 * placeholders bind to a single line item is a property of resolution (ADR-0014
 * §5a), and a second copy here would describe the wrong set the next time one moves.
 * The remaining names have no constant to read — resolution is a switch — so they
 * are listed here and `AdminPlaceholderReferenceTest` asserts EVERY name in this
 * reference actually resolves against a real order, which is the drift guard the
 * missing constant would otherwise have been.
 */
final class PlaceholderReference {

	/**
	 * Customer placeholders (ADR-0014 §4).
	 */
	const CUSTOMER = array(
		'customer_first_name',
		'customer_last_name',
		'customer_full_name',
		'customer_email',
		'customer_phone',
	);

	/**
	 * Order placeholders (ADR-0014 §4).
	 */
	const ORDER = array(
		'order_number',
		'order_date',
		'order_status',
		'order_total',
		'payment_method',
		'shipping_method',
		'billing_address',
		'shipping_address',
		'view_order_url',
	);

	/**
	 * Store placeholders (ADR-0014 §4).
	 */
	const STORE = array(
		'store_name',
		'store_email',
		'store_url',
		'my_account_url',
	);

	/**
	 * The PLURAL matched-product placeholders (ADR-0014 §5).
	 */
	const PRODUCT_PLURAL = array( 'product_names', 'matched_product_list' );

	/**
	 * Every category, in the order the panel renders them.
	 *
	 * @return array[] Each `{id, title, note, items}`, where an item is
	 *                 `{token, label, description, singular}`.
	 */
	public static function categories(): array {
		return array(
			array(
				'id'    => 'customer',
				'title' => __( 'Customer', 'extonify-custom-emails-per-product' ),
				'note'  => '',
				'items' => self::items( self::CUSTOMER, self::descriptions() ),
			),
			array(
				'id'    => 'order',
				'title' => __( 'Order', 'extonify-custom-emails-per-product' ),
				'note'  => '',
				'items' => self::items( self::ORDER, self::descriptions() ),
			),
			array(
				'id'    => 'store',
				'title' => __( 'Store', 'extonify-custom-emails-per-product' ),
				'note'  => '',
				'items' => self::items( self::STORE, self::descriptions() ),
			),
			array(
				'id'    => 'products',
				'title' => __( 'Matched products', 'extonify-custom-emails-per-product' ),
				'note'  => __( 'A rule can match several products. The single-product placeholders below use the first one; the all-products placeholders use every one.', 'extonify-custom-emails-per-product' ),
				'items' => array_merge(
					self::items( PlaceholderValues::SINGULAR_ITEM_PLACEHOLDERS, self::descriptions(), true ),
					self::items( self::PRODUCT_PLURAL, self::descriptions() )
				),
			),
			array(
				'id'    => 'meta',
				'title' => __( 'Custom fields', 'extonify-custom-emails-per-product' ),
				'note'  => __( 'Replace "key" with the custom field name. Fields whose name starts with an underscore are private and are refused unless the site owner allows that field in code.', 'extonify-custom-emails-per-product' ),
				'items' => self::meta_items(),
			),
		);
	}

	/**
	 * Every token this reference offers, flattened — the drift guard's input.
	 *
	 * @return string[] Tokens as written, e.g. `{order_total}`.
	 */
	public static function tokens(): array {
		$tokens = array();

		foreach ( self::categories() as $category ) {
			foreach ( $category['items'] as $item ) {
				$tokens[] = $item['token'];
			}
		}

		return $tokens;
	}

	/**
	 * Turn a list of names into panel items.
	 *
	 * @param string[]             $names        Placeholder names.
	 * @param array<string,string> $descriptions Name => description.
	 * @param bool                 $singular     Whether these bind to the first matched item.
	 * @return array[]
	 */
	private static function items( array $names, array $descriptions, bool $singular = false ): array {
		$items = array();

		foreach ( $names as $name ) {
			$name = (string) $name;

			$items[] = array(
				'token'       => PlaceholderSyntax::label( $name ),
				'label'       => $name,
				'description' => $descriptions[ $name ] ?? '',
				'singular'    => $singular,
			);
		}

		return $items;
	}

	/**
	 * The two parameterised placeholders (ADR-0014 §6).
	 *
	 * @return array[]
	 */
	private static function meta_items(): array {
		return array(
			array(
				'token'       => PlaceholderSyntax::label( PlaceholderValues::META_ORDER, 'key' ),
				'label'       => PlaceholderValues::META_ORDER,
				'description' => __( 'A custom field stored on the order.', 'extonify-custom-emails-per-product' ),
				'singular'    => false,
			),
			array(
				'token'       => PlaceholderSyntax::label( PlaceholderValues::META_ITEM, 'key' ),
				'label'       => PlaceholderValues::META_ITEM,
				'description' => __( 'A custom field stored on the matched order line — the first matched product, or the product beside it when the rule is placed beside each product.', 'extonify-custom-emails-per-product' ),
				'singular'    => true,
			),
		);
	}

	/**
	 * Name => description.
	 *
	 * @return array<string,string>
	 */
	private static function descriptions(): array {
		return array(
			'customer_first_name'  => __( 'Billing first name.', 'extonify-custom-emails-per-product' ),
			'customer_last_name'   => __( 'Billing last name.', 'extonify-custom-emails-per-product' ),
			'customer_full_name'   => __( 'Billing full name.', 'extonify-custom-emails-per-product' ),
			'customer_email'       => __( 'Billing email address.', 'extonify-custom-emails-per-product' ),
			'customer_phone'       => __( 'Billing phone number.', 'extonify-custom-emails-per-product' ),
			'order_number'         => __( 'The order\'s display number, not its internal ID.', 'extonify-custom-emails-per-product' ),
			'order_date'           => __( 'Order date, in this store\'s date format.', 'extonify-custom-emails-per-product' ),
			'order_status'         => __( 'Order status, by its readable name.', 'extonify-custom-emails-per-product' ),
			'order_total'          => __( 'Order total, in this store\'s currency format.', 'extonify-custom-emails-per-product' ),
			'payment_method'       => __( 'Payment method title.', 'extonify-custom-emails-per-product' ),
			'shipping_method'      => __( 'Shipping method title.', 'extonify-custom-emails-per-product' ),
			'billing_address'      => __( 'Formatted billing address.', 'extonify-custom-emails-per-product' ),
			'shipping_address'     => __( 'Formatted shipping address.', 'extonify-custom-emails-per-product' ),
			'view_order_url'       => __( 'Link to the order in the customer\'s account.', 'extonify-custom-emails-per-product' ),
			'store_name'           => __( 'This store\'s name.', 'extonify-custom-emails-per-product' ),
			'store_email'          => __( 'The address this store sends email from.', 'extonify-custom-emails-per-product' ),
			'store_url'            => __( 'This store\'s home page.', 'extonify-custom-emails-per-product' ),
			'my_account_url'       => __( 'Link to the My Account page.', 'extonify-custom-emails-per-product' ),
			'product_name'         => __( 'Name of the first matched product, as it appears on the order.', 'extonify-custom-emails-per-product' ),
			'product_sku'          => __( 'SKU of the first matched product.', 'extonify-custom-emails-per-product' ),
			'product_quantity'     => __( 'Quantity ordered of the first matched product.', 'extonify-custom-emails-per-product' ),
			'product_url'          => __( 'Link to the first matched product.', 'extonify-custom-emails-per-product' ),
			'variation_name'       => __( 'Variation name of the first matched product. Empty when it is not a variation.', 'extonify-custom-emails-per-product' ),
			'variation_attributes' => __( 'Variation attributes of the first matched product, for example "Colour: Blue, Size: L".', 'extonify-custom-emails-per-product' ),
			'product_names'        => __( 'Every matched product, comma-separated.', 'extonify-custom-emails-per-product' ),
			'matched_product_list' => __( 'Every matched product, one per line, with quantities.', 'extonify-custom-emails-per-product' ),
		);
	}
}
