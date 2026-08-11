<?php
/**
 * The targeting picker's search endpoint (ADR-0017 §6).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

use Extonify\WCEP\Domain\Targeting;

defined( 'ABSPATH' ) || exit;

/**
 * The one AJAX endpoint this plugin registers.
 *
 * ⚠ IT EXISTS SO THE PICKER DOES NOT DEPEND ON WooCommerce's ADMIN BUNDLE
 * (ADR-0017 §6). Reusing `wc-enhanced-select` would have meant this plugin's
 * targeting controls depending on WooCommerce's class names, its AJAX action names
 * and its nonce names, on a screen WooCommerce has never heard of, with none of it
 * reachable by PHPUnit. Owning the endpoint costs more JavaScript and buys an entry
 * point inside this plugin's own capability, nonce and sanitisation contracts — and
 * one gate 28 enumerates like every other.
 *
 * ⚠ READ-ONLY, CAPABILITY-CHECKED, NONCE-CHECKED, AND BOUNDED. It returns nothing a
 * `manage_woocommerce` user cannot already read from the products screen, it refuses
 * a request without the admin nonce, and it never returns more than
 * self::MAX_RESULTS rows however the query is shaped — an unbounded search endpoint
 * is a denial-of-service primitive with a login in front of it.
 */
final class TargetSearch {

	/**
	 * The `wp_ajax_` action name.
	 */
	const ACTION = 'extonify_wcep_search_targets';

	/**
	 * The nonce action every admin request carries.
	 */
	const NONCE_ACTION = 'extonify_wcep_admin';

	/**
	 * Longest result set, whatever was asked for.
	 */
	const MAX_RESULTS = 30;

	/**
	 * Register the endpoint. Hooks only.
	 *
	 * ⚠ `wp_ajax_` ONLY, NEVER `wp_ajax_nopriv_`. The privileged variant is the whole
	 * registration: a `nopriv` twin would expose the same handler to logged-out
	 * visitors, and the capability check inside it would then be the only thing
	 * standing between the storefront and a product enumeration endpoint.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Answer one search.
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to search products here.', 'extonify-custom-emails-per-product' ) ),
				403
			);
		}

		// ⚠ FALSE, NOT THE DEFAULT: check_ajax_referer() dies on failure by default,
		// which produces a bare `-1` the picker cannot explain. Checking the return
		// value lets the refusal be a JSON error the JavaScript can show.
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'This page has expired. Reload it and try again.', 'extonify-custom-emails-per-product' ) ),
				403
			);
		}

		/*
		 * ⚠ THE RAW VALUE, TESTED — NOT `sanitize_key()`, WHICH REPAIRS. `kind` decides
		 * which store is queried, so it is an ENUMERATION, and ADR-0009's boundary
		 * applies to it exactly as it applies to `status` and `delivery_mode`:
		 * `sanitize_key( 'PRODUCTS!' )` is `products`, so sanitising first would turn a
		 * value nobody sent into a valid one and answer it. Refused, never repaired.
		 *
		 * Nothing downstream relies on sanitising it either: it is used only as an array
		 * key into two frozen maps, after this test has passed.
		 */
		$kind = isset( $_GET['kind'] ) && is_scalar( $_GET['kind'] ) ? (string) wp_unslash( $_GET['kind'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- deliberately unsanitised: the value is TESTED against Targeting::ID_KINDS below and refused if it is not an exact member, because sanitising would repair a value nobody sent into a valid one.

		if ( ! in_array( $kind, Targeting::ID_KINDS, true ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Unknown search type.', 'extonify-custom-emails-per-product' ) ),
				400
			);
		}

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';

		wp_send_json_success( array( 'results' => self::results( $kind, $term ) ) );
	}

	/**
	 * Bounded results for one kind.
	 *
	 * @param string $kind One of `Targeting::ID_KINDS`.
	 * @param string $term Search text.
	 * @return array[] Each `{id:int, label:string}`.
	 */
	private static function results( string $kind, string $term ): array {
		if ( isset( TargetLabels::TERM_KINDS[ $kind ] ) ) {
			return self::terms( TargetLabels::TERM_KINDS[ $kind ], $term );
		}

		$post_type = TargetLabels::POST_KINDS[ $kind ] ?? 'product';

		return self::posts( $post_type, $term, 'product_variation' === $post_type );
	}

	/**
	 * Search products or variations.
	 *
	 * @param string $post_type Post type.
	 * @param string $term      Search text.
	 * @param bool   $show_id   Append `(#id)` — variations frequently share a title.
	 * @return array[]
	 */
	private static function posts( string $post_type, string $term, bool $show_id ): array {
		$posts = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => array( 'publish', 'private', 'draft', 'pending' ),
				's'                      => $term,
				'numberposts'            => self::MAX_RESULTS,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'suppress_filters'       => false,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$out = array();

		foreach ( (array) $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$title = (string) $post->post_title;

			if ( '' === $title ) {
				/* translators: %d: post id of a product with no title. */
				$title = sprintf( __( 'Untitled (#%d)', 'extonify-custom-emails-per-product' ), (int) $post->ID );
			}

			$out[] = array(
				'id'    => (int) $post->ID,
				'label' => $show_id ? $title . ' (#' . (int) $post->ID . ')' : $title,
			);
		}

		return $out;
	}

	/**
	 * Search a product taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param string $term     Search text.
	 * @return array[]
	 */
	private static function terms( string $taxonomy, string $term ): array {
		$found = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'search'     => $term,
				'number'     => self::MAX_RESULTS,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $found ) ) {
			return array();
		}

		$out = array();

		foreach ( (array) $found as $item ) {
			if ( $item instanceof \WP_Term ) {
				$out[] = array(
					'id'    => (int) $item->term_id,
					'label' => (string) $item->name,
				);
			}
		}

		return $out;
	}
}
