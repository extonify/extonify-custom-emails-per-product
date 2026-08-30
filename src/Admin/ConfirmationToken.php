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
	 * `consume()` outcomes.
	 *
	 * ⚠ THREE ANSWERS, NOT A BOOLEAN, AND THE THIRD IS WHY (Prompt 13A item 4).
	 * `false` used to mean "already used, expired, or never issued" — three different
	 * things a merchant would respond to differently, and now four, because a token
	 * presented against a DIFFERENT CONTEXT is refused as well. Telling a merchant
	 * "this confirmation had already been used" when what actually happened is that
	 * the rule's recipients changed while they were reading the screen is a sentence
	 * that sends them looking in the wrong place (gate 38).
	 */
	const OK              = 'ok';
	const REPLAYED        = 'replayed';
	const CONTEXT_CHANGED = 'confirmation_changed';

	/**
	 * How the expiry and the context fingerprint share one option value.
	 *
	 * ⚠ THE EXPIRY STAYS FIRST AND STAYS NUMERIC, because `purge_expired()` compares
	 * it in SQL. `SUBSTRING_INDEX(option_value, '|', 1)` is what keeps that comparison
	 * reading the expiry rather than a hash, and a value written before this field
	 * existed — a bare timestamp with no separator — still yields itself.
	 */
	const SEPARATOR = '|';

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
	 * @param string $fingerprint What this confirmation is FOR — see self::fingerprint().
	 * @return string The token, or '' when it could not be stored.
	 */
	public static function issue( string $fingerprint = '' ): string {
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
		$stored = add_option(
			self::PREFIX . $token,
			(string) ( time() + self::TTL_SECONDS ) . self::SEPARATOR . $fingerprint,
			'',
			'no'
		);

		return $stored ? $token : '';
	}

	/**
	 * Consume a token, and say whether THIS caller consumed it FOR THIS ACTION.
	 *
	 * ⚠ THE RETURN VALUE IS THE OWNERSHIP DECISION AND MAY NOT BE DISCARDED. `OK` means
	 * this request holds the single execution of this confirmation; anything else means
	 * the caller must send nothing.
	 *
	 * ⚠ THE TOKEN NOW BINDS ITS CONTEXT, AND THE REASON IS SEND NOW (Prompt 13A item
	 * 4). A bare token authorised "one execution of something"; it did not say of WHAT.
	 * The replay-across-subjects angle is weak on its own — the nonce is already
	 * action- and subject-specific, and an attacker would need the merchant's session —
	 * but the confirmation screen and the send could disagree WITHOUT any attacker at
	 * all:
	 *
	 *   the Send Now screen resolves recipients, the merchant reads them, and the send
	 *   runs from the SCHEDULED SNAPSHOT (ADR-0015 §2). Edit the rule's recipients in
	 *   between and the merchant confirms one address while another receives the email
	 *   — the exact outcome ADR-0019 §5's confirmation exists to prevent.
	 *
	 * Both halves of the fix are needed and neither replaces the other: the screen now
	 * resolves from the snapshot too (`DeliveryActions::confirmation_context()`), so
	 * the two agree BY CONSTRUCTION; and the fingerprint refuses the case where the
	 * world moved anyway, so they agree BY CHECK as well.
	 *
	 * ⚠ THE READ IS BEFORE THE DELETE AND THE DELETE STILL DECIDES. Comparing the
	 * fingerprint does not weaken the single-execution guarantee: `delete_option()` is
	 * still the one statement two concurrent callers race for, and the comparison
	 * happens on the value the winner read.
	 *
	 * ⚠ SO A MISMATCHED PRESENTATION SPENDS THE TOKEN, AND THAT IS THE INTENDED
	 * DIRECTION. Every sentence this refusal renders tells the merchant to open the
	 * confirmation again and check who it reaches; a token that survived the refusal
	 * would let them press the same stale button instead of re-reading the screen, and
	 * would still be lying about the recipients the second time.
	 *
	 * @param string $token       Token from the confirmation form.
	 * @param string $fingerprint The context this request is executing — see
	 *                            self::fingerprint(). Must equal the one `issue()`
	 *                            stored.
	 * @return string One of self::OK, self::REPLAYED, self::CONTEXT_CHANGED.
	 */
	public static function consume( string $token, string $fingerprint = '' ): string {
		if ( ! self::is_well_formed( $token ) ) {
			return self::REPLAYED;
		}

		$key = self::PREFIX . $token;

		// The row may have outlived its TTL without being purged yet. It is deleted
		// either way — an expired confirmation is consumed and refused, never left for
		// a later replay.
		$stored = (string) get_option( $key, '' );

		$won = delete_option( $key );

		if ( ! $won ) {
			return self::REPLAYED;
		}

		$expires = self::expiry_of( $stored );

		if ( $expires <= 0 || $expires < time() ) {
			return self::REPLAYED;
		}

		/*
		 * ⚠ `hash_equals()`, NOT `===`. These are secrets in the sense that matters —
		 * a value an attacker would like to guess a byte at a time — and a constant-time
		 * comparison costs nothing here.
		 *
		 * ⚠ A ROW WITH NO FINGERPRINT IS REFUSED WHENEVER ONE IS EXPECTED. That is the
		 * fail-closed direction: it can only happen to a confirmation issued by an
		 * older build and still inside its 30-minute TTL when the plugin was updated,
		 * and the cost of being wrong is one re-confirmation.
		 */
		return hash_equals( $fingerprint, self::fingerprint_of( $stored ) ) ? self::OK : self::CONTEXT_CHANGED;
	}

	/**
	 * THE CONTEXT A CONFIRMATION IS BOUND TO, as one comparable string.
	 *
	 * ⚠ IT COVERS THE ACTION, THE SUBJECTS AND WHAT THE MERCHANT WAS SHOWN. The action
	 * and the ids stop one confirmation authorising a different one; the RECIPIENTS are
	 * the half that matters most, because they are the thing the merchant actually read
	 * before clicking and the thing that can change underneath them.
	 *
	 * ⚠ CANONICALISED BEFORE HASHING, so a difference in ORDER or CASE is not mistaken
	 * for a difference in PEOPLE. `Bob@Example.test` and `bob@example.test` are one
	 * recipient, and two channels listed in a different order are the same audience;
	 * refusing those would train merchants to re-confirm past a warning that is usually
	 * noise, which is how a real warning gets clicked through.
	 *
	 * @param string                 $action     One of `DeliveryActions::write_actions()`.
	 * @param int                    $delivery   Delivery id, or 0.
	 * @param int                    $order      Order id, or 0.
	 * @param int                    $rule       Rule id, or 0.
	 * @param array<string,string[]> $recipients Channel => addresses, exactly as the
	 *                                           confirmation screen displays them.
	 * @return string
	 */
	public static function fingerprint( string $action, int $delivery, int $order, int $rule, array $recipients ): string {
		$canonical = array();

		// A FIXED CHANNEL ORDER, and every channel present, so "no cc" and "cc absent"
		// hash identically rather than depending on how the caller built the array.
		foreach ( array( 'to', 'cc', 'bcc' ) as $channel ) {
			$addresses = array();

			foreach ( (array) ( $recipients[ $channel ] ?? array() ) as $address ) {
				if ( is_scalar( $address ) ) {
					$addresses[] = strtolower( trim( (string) $address ) );
				}
			}

			$addresses = array_values( array_unique( array_filter( $addresses, 'strlen' ) ) );

			sort( $addresses, SORT_STRING );

			$canonical[ $channel ] = $addresses;
		}

		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'action'     => $action,
					'delivery'   => max( 0, $delivery ),
					'order'      => max( 0, $order ),
					'rule'       => max( 0, $rule ),
					'recipients' => $canonical,
				)
			)
		);
	}

	/**
	 * The expiry half of a stored value.
	 *
	 * @param string $stored Raw option value.
	 * @return int Unix time, or 0 when there is none.
	 */
	private static function expiry_of( string $stored ): int {
		if ( '' === $stored ) {
			return 0;
		}

		$separator = strpos( $stored, self::SEPARATOR );

		return max( 0, (int) ( false === $separator ? $stored : substr( $stored, 0, $separator ) ) );
	}

	/**
	 * The fingerprint half of a stored value, or '' when it predates the field.
	 *
	 * @param string $stored Raw option value.
	 * @return string
	 */
	private static function fingerprint_of( string $stored ): string {
		$separator = strpos( $stored, self::SEPARATOR );

		return false === $separator ? '' : substr( $stored, $separator + 1 );
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
				// ⚠ AND `SUBSTRING_INDEX` FIRST, because the value now carries the expiry
				// AND the context fingerprint (`<expiry>|<sha256>`). Casting the whole
				// string would rely on MySQL's leading-numeric-prefix behaviour, which is
				// a coincidence rather than a contract; taking the first field says what
				// is meant here too. A value with no separator — one written before the
				// fingerprint existed — is returned unchanged, so the purge still finds it.
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d LIMIT %d",
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
