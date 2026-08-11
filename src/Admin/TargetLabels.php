<?php
/**
 * Batched id-to-label resolution for the targeting controls (ADR-0017 §9).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

use Extonify\WCEP\Domain\Targeting;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the ids in a targeting document into names a merchant recognises.
 *
 * ⚠ TWO QUERIES FOR A WHOLE PAGE, WHATEVER IT HOLDS, AND THAT IS THE DESIGN
 * (ADR-0017 §9). The naive form — `wc_get_product( $id )` per entry while rendering
 * a row — is one query per targeted id per rule per page, which is unbounded in the
 * two dimensions that grow: rules, and ids per rule. Every id on the page is
 * collected FIRST, resolved in one `get_posts( post__in )` and one
 * `get_terms( include )`, and the render then reads an array.
 *
 * ⚠ A MISSING ID IS SHOWN, NOT DROPPED. A product that has been deleted is still in
 * the stored document and still affects nothing; hiding it would make the editor lie
 * about what it is about to save, and would make the entry unremovable because the
 * merchant cannot see it. It renders as `#123` marked unavailable, and the checkbox
 * that removes it is still there.
 */
final class TargetLabels {

	/**
	 * Kinds resolved from posts, and the post types each accepts.
	 */
	const POST_KINDS = array(
		'products'   => 'product',
		'variations' => 'product_variation',
	);

	/**
	 * Kinds resolved from terms, and the taxonomy each uses.
	 */
	const TERM_KINDS = array(
		'categories' => 'product_cat',
		'tags'       => 'product_tag',
	);

	/**
	 * Resolve every id in one batch.
	 *
	 * @param array $needed Kind => list of ids.
	 * @return array Kind => id => `{label:string, missing:bool}`.
	 */
	public static function resolve( array $needed ): array {
		$post_ids = array();
		$term_ids = array();

		foreach ( self::POST_KINDS as $kind => $unused_type ) {
			$post_ids = array_merge( $post_ids, Targeting::int_list( $needed[ $kind ] ?? array() ) );
		}

		foreach ( self::TERM_KINDS as $kind => $unused_taxonomy ) {
			$term_ids = array_merge( $term_ids, Targeting::int_list( $needed[ $kind ] ?? array() ) );
		}

		$posts = self::post_titles( array_values( array_unique( $post_ids ) ) );
		$terms = self::term_names( array_values( array_unique( $term_ids ) ) );

		$out = array();

		foreach ( array_keys( self::POST_KINDS ) as $kind ) {
			$out[ $kind ] = self::label_set( Targeting::int_list( $needed[ $kind ] ?? array() ), $posts, 'variations' === $kind );
		}

		foreach ( array_keys( self::TERM_KINDS ) as $kind ) {
			$out[ $kind ] = self::label_set( Targeting::int_list( $needed[ $kind ] ?? array() ), $terms, false );
		}

		return $out;
	}

	/**
	 * Merge the id lists of several rules into one `resolve()` argument.
	 *
	 * @param array[] $documents Each a kind => ids map.
	 * @param int     $per_kind  Keep at most this many ids per kind per document; 0 for all.
	 * @return array Kind => ids.
	 */
	public static function collect( array $documents, int $per_kind = 0 ): array {
		$needed = array();

		foreach ( Targeting::KINDS as $kind ) {
			$needed[ $kind ] = array();
		}

		foreach ( $documents as $document ) {
			foreach ( Targeting::KINDS as $kind ) {
				$ids = array_values( (array) ( $document[ $kind ] ?? array() ) );

				if ( $per_kind > 0 ) {
					$ids = array_slice( $ids, 0, $per_kind );
				}

				$needed[ $kind ] = array_merge( $needed[ $kind ], $ids );
			}
		}

		return $needed;
	}

	/**
	 * Attach labels to one kind's ids, preserving the document's order.
	 *
	 * @param int[]             $ids     Ids, in document order.
	 * @param array<int,string> $names   Resolved id => name.
	 * @param bool              $show_id Append `(#id)` — variations, whose generated
	 *                                   titles are frequently identical between siblings.
	 * @return array id => `{label:string, missing:bool}`
	 */
	private static function label_set( array $ids, array $names, bool $show_id ): array {
		$out = array();

		foreach ( $ids as $id ) {
			$id = (int) $id;

			if ( ! isset( $names[ $id ] ) ) {
				$out[ $id ] = array(
					'label'   => '#' . $id,
					'missing' => true,
				);
				continue;
			}

			$out[ $id ] = array(
				'label'   => $show_id ? $names[ $id ] . ' (#' . $id . ')' : $names[ $id ],
				'missing' => false,
			);
		}

		return $out;
	}

	/**
	 * One query for every product and variation on the page.
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int,string> Id => title.
	 */
	private static function post_titles( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'              => array_values( self::POST_KINDS ),
				'post__in'               => $ids,
				'post_status'            => 'any',
				'numberposts'            => count( $ids ),
				'orderby'                => 'post__in',
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

			$out[ (int) $post->ID ] = '' !== $title
				? $title
				/* translators: %d: post id of a product with no title. */
				: sprintf( __( 'Untitled (#%d)', 'extonify-custom-emails-per-product' ), (int) $post->ID );
		}

		return $out;
	}

	/**
	 * One query for every category and tag on the page.
	 *
	 * @param int[] $ids Term ids.
	 * @return array<int,string> Id => name.
	 */
	private static function term_names( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => array_values( self::TERM_KINDS ),
				'include'    => $ids,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$out = array();

		foreach ( (array) $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$out[ (int) $term->term_id ] = (string) $term->name;
			}
		}

		return $out;
	}
}
