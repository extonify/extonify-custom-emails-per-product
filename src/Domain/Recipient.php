<?php
/**
 * Recipient normalisation for the delivery-detail store (ADR-0009).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One stored detail row holds exactly ONE recipient address.
 *
 * The privacy exporter and eraser find rows with `WHERE recipient = %s`. That
 * lookup only works if the column holds a single, consistently normalised
 * address — a display name (`Alice <a@b.test>`) or a comma-joined list would
 * make a legally-required personal-data request silently miss rows. So the
 * storage layer refuses to hold anything else:
 *
 *   - header-form input is PARSED, the bare address stored in `recipient` and
 *     the original kept in `recipient_header` (never searched, always erased);
 *   - a comma-joined list is REJECTED outright — resolving a delivery into one
 *     row per recipient is the delivery engine's job (out of scope here), and
 *     accepting a list would let the engine skip it.
 *
 * Case folding is ASCII-range only, exactly as DeliveryIdentity does it: no
 * strtolower() (locale-sensitive before PHP 8.2) and no mb_strtolower()
 * (absent without the mbstring extension). An address that folds differently
 * across hosts would be unfindable by the eraser on some of them.
 */
final class Recipient {

	/**
	 * Recipient channels.
	 */
	const TYPES = array( 'to', 'cc', 'bcc' );

	/**
	 * Storage width of the recipient column (ADR-0009).
	 */
	const MAX_LENGTH = 191;

	/**
	 * Normalise a single recipient address for storage and lookup.
	 *
	 * @param string $raw Raw value: a bare address or a header-form string.
	 * @return string|null Lowercased bare address, or null when the input is
	 *                     not exactly one storable address.
	 */
	public static function normalize( string $raw ): ?string {
		$address = self::extract_address( $raw );
		if ( null === $address ) {
			return null;
		}

		// ASCII-range fold only — see the class docblock.
		$address = strtr( $address, DeliveryIdentity::ASCII_UPPER, DeliveryIdentity::ASCII_LOWER );

		if ( '' === $address || strlen( $address ) > self::MAX_LENGTH ) {
			return null;
		}
		if ( ! is_email( $address ) ) {
			return null;
		}

		return $address;
	}

	/**
	 * Pull the bare address out of a possibly header-form value.
	 *
	 * @param string $raw Raw value.
	 * @return string|null Bare address, or null when the input holds zero or
	 *                     more than one address.
	 */
	public static function extract_address( string $raw ): ?string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}

		// Header form FIRST: `Display Name <address@example.test>`. This has to
		// precede the list check, because a quoted display name may legitimately
		// contain a comma ("Smith, Alice" <a@b.test>) and that is still exactly
		// ONE recipient. Requiring the part before `<` to hold no angle bracket
		// is what keeps a genuine multi-recipient header
		// (`Alice <a@x>, Bob <b@y>`) from matching here — it falls through to
		// the list check below and is rejected.
		if ( preg_match( '/^[^<>]*<([^<>]+)>$/', $raw, $matches ) ) {
			$inner = trim( $matches[1] );
			return self::is_list( $inner ) ? null : $inner;
		}

		if ( self::is_list( $raw ) ) {
			return null; // The engine must split these into one row each.
		}

		// A stray angle bracket that is not a well-formed header is not an
		// address; refusing beats storing something unsearchable.
		if ( false !== strpos( $raw, '<' ) || false !== strpos( $raw, '>' ) ) {
			return null;
		}

		return $raw;
	}

	/**
	 * Whether a raw value holds more than one address.
	 *
	 * @param string $raw Raw value.
	 * @return bool
	 */
	public static function is_list( string $raw ): bool {
		return false !== strpos( $raw, ',' ) || false !== strpos( $raw, ';' );
	}

	/**
	 * Whether a raw value carries a display name, so the original is worth
	 * keeping in the non-searchable header column.
	 *
	 * @param string $raw Raw value.
	 * @return bool
	 */
	public static function has_display_name( string $raw ): bool {
		return 1 === preg_match( '/^[^<>]*\S[^<>]*<[^<>]+>$/', trim( $raw ) );
	}

	/**
	 * The display-name part of a header-form value, without the address.
	 *
	 * @param string $raw Raw value.
	 * @return string|null Display name, or null when there is not one.
	 */
	public static function display_name( string $raw ): ?string {
		$raw = trim( $raw );
		if ( ! preg_match( '/^([^<>]*)<[^<>]+>$/', $raw, $matches ) ) {
			return null;
		}
		$name = trim( $matches[1] );
		return '' === $name ? null : $name;
	}

	/**
	 * Rebuild a clean header-form string from a display name and an already
	 * normalised address.
	 *
	 * Recomposed rather than sanitising the original, because
	 * sanitize_text_field() reads `<alice@example.test>` as an HTML tag and
	 * strips it — which would silently drop the address from the very column
	 * that exists to preserve how the message was addressed.
	 *
	 * @param string $display_name Display name (sanitised by the caller).
	 * @param string $address      Normalised bare address.
	 * @return string
	 */
	public static function compose_header( string $display_name, string $address ): string {
		return trim( $display_name ) . ' <' . $address . '>';
	}

	/**
	 * Normalise a recipient channel.
	 *
	 * STRICT, like the `type` and `state` allowlists in the detail repository.
	 * An unknown value used to be coerced to 'to', which silently relabels a
	 * BCC as a direct recipient — corrupting the delivery audit and misreporting
	 * the recipient type in a privacy export. An empty input still means 'to',
	 * because "unspecified" genuinely is the direct recipient.
	 *
	 * @param string $type Candidate channel.
	 * @return string|null One of self::TYPES, or null when unrecognised.
	 */
	public static function normalize_type( string $type ): ?string {
		$type = strtr( trim( $type ), DeliveryIdentity::ASCII_UPPER, DeliveryIdentity::ASCII_LOWER );
		if ( '' === $type ) {
			return 'to';
		}
		return in_array( $type, self::TYPES, true ) ? $type : null;
	}
}
