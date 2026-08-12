<?php
/**
 * Single-use confirmation tokens (ADR-0019 §5, gate 37).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * One confirmed click, one execution.
 *
 * ⚠ THE CONSUMPTION IS A `DELETE` WHOSE AFFECTED-ROW COUNT DECIDES, NEVER A READ.
 * That is the same idiom `DeliveryRepository::claim()` and `transition()` use, and it
 * is used here for the same reason: two concurrent requests both pass any *read*
 * check, and only one can win a single-statement write. Read-then-delete would leave a
 * race exactly as wide as a double-click — which is the single most likely way a
 * merchant sends a customer two emails, and Tier 1 on this prompt.
 *
 * `delete_option()` is that statement. Core reads the row, issues one
 * `DELETE ... WHERE option_name = %s`, and returns `true` only when `$wpdb->delete()`
 * reported a row gone — so of two concurrent callers exactly one gets `true`. It
 * always reaches the database, with or without a persistent object cache, which is
 * why this does NOT use a transient: with an external object cache
 * `delete_transient()` resolves through `wp_cache_delete()`, which would answer `true`
 * to both callers and hand the guarantee away.
 *
 * ⚠ THE TOKEN IS ALSO THE MANUAL SEND'S IDENTITY (ADR-0019 §2), so that action has a
 * second, permanent barrier underneath this one: even if the token row were wiped, a
 * replay would still hash to an identity the UNIQUE index already holds. Resend has
 * only this guard, which is why it is a write and not a read.
 *
 * ⚠ GROWTH IS BOUNDED. Rows are NOT autoloaded, so they never load on a normal
 * request; each carries an expiry; and every issue purges a bounded page of expired
 * ones. The live set is bounded by the confirmations a merchant opens within the TTL —
 * a resource that grows without bound in normal operation would be Tier 1.
 */
final class ConfirmationToken {

	/**
	 * Option-name prefix. Also what the purge matches on.
	 */
	const PREFIX = 'extonify_wcep_confirm_';

	/**
	 * How long an unused confirmation stays valid, in seconds.
	 *
	 * Long enough for a merchant to read the dialog and think about it; short enough
	 * that an abandoned confirmation does not sit in `wp_options` for a day.
	 */
	const TTL_SECONDS = 1800;

	/**
	 * How many expired tokens one issue clears.
	 *
	 * A bound, not a target: the purge runs on a merchant's click, and an unbounded
	 * `DELETE ... LIKE` on `wp_options` is not something to put on any request path.
	 */
	const PURGE_LIMIT = 50;

	/**
	 * Issue a fresh single-use token.
	 *
	 * ⚠ 32 LOWERCASE HEX CHARACTERS, WHICH IS NOT AN AESTHETIC CHOICE. The token
	 * becomes the value half of a `manual:` trigger identity, and
	 * `DeliveryIdentity::is_valid_trigger_identity()` accepts `[a-z0-9][a-z0-9 _.>-]*`
	 * within 191 bytes. Hex satisfies that with no normalisation surprises, and
	 * `random_bytes()` is the CSPRNG — a guessable token would let a crafted request
	 * satisfy the confirmation gate without a confirmation.
	 *
	 * @return string The token, or '' when it could not be stored.
	 */
	public static function issue(): string {
		self::purge_expired();

		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			// No CSPRNG: refuse rather than fall back to a predictable source. A
			// guessable confirmation token is a confirmation that did not happen.
			return '';
		}

		// ⚠ NOT AUTOLOADED. These are read exactly once, by the request that consumes
		// them; autoloading would put every open confirmation into every page load.
		$stored = add_option( self::PREFIX . $token, (string) ( time() + self::TTL_SECONDS ), '', 'no' );

		return $stored ? $token : '';
	}

	/**
	 * Consume a token, and say whether THIS caller consumed it.
	 *
	 * ⚠ THE RETURN VALUE IS THE OWNERSHIP DECISION AND MAY NOT BE DISCARDED. `true`
	 * means this request holds the single execution of this confirmation; `false` means
	 * the action has already been performed, the confirmation expired, or the token was
	 * never issued — and in all three cases the caller must send nothing.
	 *
	 * @param string $token Token from the confirmation form.
	 * @return bool
	 */
	public static function consume( string $token ): bool {
		if ( ! self::is_well_formed( $token ) ) {
			return false;
		}

		$key = self::PREFIX . $token;

		// The row may have outlived its TTL without being purged yet. It is deleted
		// either way — an expired confirmation is consumed and refused, never left for
		// a later replay.
		$expires = get_option( $key, '' );

		$won = delete_option( $key );

		if ( ! $won ) {
			return false;
		}

		return '' !== (string) $expires && (int) $expires >= time();
	}

	/**
	 * Whether a token has the shape self::issue() produces.
	 *
	 * Checked before the delete so a hand-crafted `option_name` cannot be aimed at an
	 * unrelated option through this path.
	 *
	 * @param string $token Candidate token.
	 * @return bool
	 */
	public static function is_well_formed( string $token ): bool {
		return 1 === preg_match( '/^[a-f0-9]{32}$/', $token );
	}

	/**
	 * Delete a bounded page of expired tokens.
	 *
	 * @return int How many were removed.
	 */
	private static function purge_expired(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- there is no options API for "every option whose name starts with"; the write below goes through delete_option() so the cache stays coherent.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				// ⚠ `CAST(... AS UNSIGNED)`, NOT A STRING COMPARE. `option_value` is a
				// longtext, so `< '1786000000'` would compare lexicographically — which
				// happens to agree with numeric order only while every timestamp has the
				// same digit count. Casting says what is meant.
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT %d",
				// esc_like: the prefix contains underscores, which are LIKE wildcards
				// and must match literally.
				$wpdb->esc_like( self::PREFIX ) . '%',
				time(),
				self::PURGE_LIMIT
			)
		);

		$removed = 0;

		foreach ( (array) $names as $name ) {
			// Through the options API, so the object cache is invalidated with it.
			if ( delete_option( (string) $name ) ) {
				++$removed;
			}
		}

		return $removed;
	}
}
