<?php
/**
 * Deterministic delivery-identity hash (ADR-0004, ADR-0009).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Computes the sha256 identity hash that backs the tombstone's UNIQUE key.
 *
 * ADR-0004 defines the delivery identity as the tuple
 * `order_id | rule_id | mode | trigger_identity`. ADR-0009 stores it as a
 * fixed-length CHAR(64) hash so the UNIQUE index length is safe on every
 * MySQL/MariaDB configuration.
 *
 * HASH ALGORITHM v1 (deterministic across hosts) — per component, in order:
 *   1. integers are cast and rendered in base 10;
 *   2. strings are trim()ed and internal whitespace runs collapsed to one
 *      space (UTF-8 aware, with a plain byte fallback for invalid UTF-8);
 *   3. ASCII-RANGE case folding ONLY: A-Z to a-z via strtr(). Non-ASCII bytes
 *      pass through UNCHANGED.
 * then sha256 over implode('|', components).
 *
 * The hash needs STABILITY, not linguistic correctness. strtolower() is
 * locale-sensitive before PHP 8.2 and mb_strtolower() is unavailable when the
 * mbstring extension is not installed, so either would make the same input
 * hash differently across hosts — which would split one delivery identity into
 * two and re-send an email a customer already received. The sibling Extonify
 * Address Book plugin shipped exactly that bug; this implementation uses no
 * locale- or extension-dependent function.
 */
final class DeliveryIdentity {

	/**
	 * Hash algorithm version.
	 *
	 * Bump this on ANY change to the rules above, and ship a recompute
	 * migration alongside — stored hashes are an indexed column, not a
	 * computed one, so a silent change would split existing identities.
	 */
	const HASH_VERSION = 1;

	const ASCII_UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
	const ASCII_LOWER = 'abcdefghijklmnopqrstuvwxyz';

	/**
	 * Delivery modes recognised by the identity (ADR-0005).
	 */
	const MODES = array( 'insert', 'separate' );

	/**
	 * Identity prefixes defined by ADR-0004. The identity is `status:{slug}`,
	 * `transition:{from}>{to}`, `refund:{id}` or `native:{native_email_id}` —
	 * nothing else. Keeping this closed is deliberate: an unrecognised prefix
	 * means a caller invented an identity format, and a tombstone written under
	 * an identity no later call can reproduce silently defeats duplicate
	 * prevention.
	 *
	 * `native` IS INSERT MODE'S (ADR-0013 §1). ADR-0004 states the insert
	 * identity as `order_id | rule_id | native_email_id`; the tuple is unchanged
	 * and only its third component's ENCODING into this column gains a prefix,
	 * exactly as a status trigger's slug does. Without one, a bare
	 * `customer_processing_order` would be indistinguishable from an invented
	 * format — which is the one thing this list exists to make impossible.
	 */
	const TRIGGER_PREFIXES = array( 'status', 'transition', 'refund', self::NATIVE_PREFIX, self::MANUAL_PREFIX );

	/**
	 * Prefix marking an insert-mode identity.
	 */
	const NATIVE_PREFIX = 'native';

	/**
	 * Prefix marking a delivery a MERCHANT asked for by hand (ADR-0019 §2).
	 *
	 * ⚠ A MANUAL SEND HAS NO TRIGGER, SO IT HAS NO TRIGGER IDENTITY — and ADR-0004's
	 * uniqueness key needs one. This is an ADDITION to the vocabulary, not a weakening
	 * of it: the key is still `sha256(order_id|rule_id|mode|trigger_identity)`, the
	 * UNIQUE index is untouched, and the form still has to satisfy
	 * self::is_valid_trigger_identity(). A fourth prefix simply becomes recognisable.
	 *
	 * ⚠ IT CANNOT COLLIDE WITH AN AUTOMATIC IDENTITY, BY CONSTRUCTION. `TriggerEvent`
	 * emits only `status:`, `transition:` and `refund:`; insert mode emits only
	 * `native:`. No automatic path can produce this prefix, and the prefix is inside
	 * the hashed string — so a manual delivery never consumes an identity a trigger
	 * would later want, and a trigger never suppresses a manual send.
	 *
	 * ⚠ AND THE VALUE IS A PER-CONFIRMATION TOKEN, NOT THE ORDER OR THE RULE. Those are
	 * already in the hash. What the token adds is that ONE CONFIRMED CLICK maps to one
	 * identity: a replayed submission carries the same token, hashes the same, and is
	 * SUPPRESSED by the same single statement that has prevented duplicate automatic
	 * sends since ADR-0004 — while a merchant who deliberately confirms a second time
	 * gets a fresh token and a genuinely separate delivery (ADR-0019 §5).
	 */
	const MANUAL_PREFIX = 'manual';

	/**
	 * Build the identity for one manually confirmed send (ADR-0019 §2).
	 *
	 * @param string $token Per-confirmation token from `Admin\ConfirmationToken`.
	 * @return string
	 */
	public static function manual( string $token ): string {
		return self::MANUAL_PREFIX . ':' . self::normalize( $token );
	}

	/**
	 * Whether an identity was produced by a merchant's manual send.
	 *
	 * @param string $trigger_identity Recorded identity.
	 * @return bool
	 */
	public static function is_manual( string $trigger_identity ): bool {
		return 0 === strpos( self::normalize( $trigger_identity ), self::MANUAL_PREFIX . ':' );
	}

	/**
	 * Build the insert-mode identity for one native email (ADR-0013 §1).
	 *
	 * @param string $native_email_id WooCommerce email id.
	 * @return string
	 */
	public static function native( string $native_email_id ): string {
		return self::NATIVE_PREFIX . ':' . self::normalize( $native_email_id );
	}

	/**
	 * Storage width of the trigger_identity column (ADR-0009). A longer value
	 * would be truncated by MySQL, producing a hash that no later call can
	 * reproduce.
	 */
	const MAX_TRIGGER_IDENTITY_LENGTH = 191;

	/**
	 * Compute the identity hash for a delivery.
	 *
	 * @param int    $order_id         WooCommerce order id.
	 * @param int    $rule_id          Rule id.
	 * @param string $mode             Delivery mode: 'insert' or 'separate'.
	 * @param string $trigger_identity Trigger identity, e.g. 'status:completed',
	 *                                 'transition:pending>processing', 'refund:123'.
	 * @return string 64-character sha256 hex digest.
	 */
	public static function hash( int $order_id, int $rule_id, string $mode, string $trigger_identity ): string {
		$parts = array(
			(string) $order_id,
			(string) $rule_id,
			self::normalize( $mode ),
			self::normalize( $trigger_identity ),
		);
		return hash( 'sha256', implode( '|', $parts ) );
	}

	/**
	 * Normalise a string component: trim, collapse whitespace, ASCII-only
	 * lowercase. Deliberately free of locale- and mbstring-dependent calls.
	 *
	 * @param string $value Raw component.
	 * @return string
	 */
	public static function normalize( string $value ): string {
		$value = self::collapse_whitespace( trim( $value ) );
		return strtr( $value, self::ASCII_UPPER, self::ASCII_LOWER );
	}

	/**
	 * Collapse whitespace runs to single spaces, deterministically: the UTF-8
	 * pattern when the input is valid UTF-8, a byte pattern otherwise
	 * (preg_replace with /u returns null on invalid UTF-8).
	 *
	 * @param string $value Trimmed input.
	 * @return string
	 */
	private static function collapse_whitespace( string $value ): string {
		$collapsed = preg_replace( '/\s+/u', ' ', $value );
		if ( null === $collapsed ) {
			$collapsed = preg_replace( '/\s+/', ' ', $value );
		}
		return (string) $collapsed;
	}

	/**
	 * Whether a delivery mode string is one this plugin recognises.
	 *
	 * @param string $mode Candidate mode.
	 * @return bool
	 */
	public static function is_valid_mode( string $mode ): bool {
		return in_array( self::normalize( $mode ), self::MODES, true );
	}

	/**
	 * Whether a trigger identity matches an ADR-0004 form and fits the column.
	 *
	 * Accepted, after normalisation:
	 *   status:{slug}                 e.g. status:completed
	 *   transition:{from}>{to}        e.g. transition:pending>processing
	 *   refund:{id}                   e.g. refund:2191
	 *
	 * @param string $trigger_identity Candidate identity.
	 * @return bool
	 */
	public static function is_valid_trigger_identity( string $trigger_identity ): bool {
		$normalized = self::normalize( $trigger_identity );

		if ( '' === $normalized || strlen( $normalized ) > self::MAX_TRIGGER_IDENTITY_LENGTH ) {
			return false;
		}

		$prefixes = implode( '|', self::TRIGGER_PREFIXES );

		// Value part: at least one character, drawn from the slug/id/transition
		// alphabet. '>' separates a transition's two states.
		return 1 === preg_match( '/^(?:' . $prefixes . '):[a-z0-9][a-z0-9 _.>-]*$/', $normalized );
	}
}
