<?php
/**
 * The `rules.recipients` JSON document: shape, decode, encode (ADR-0012 §4).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The structural half of the recipients column, kept apart from the resolution
 * half so the repository can encode it without depending on the delivery layer.
 *
 * Schema — unknown keys are ignored:
 *
 *     { "to": [...], "cc": [...], "bcc": [...] }
 *
 * `{}` VERSUS `[]` IS ENFORCED AT THE RAW BOUNDARY, exactly as `Targeting` does
 * it and for the same reason. `json_decode( $raw, true )` renders `{}` and `[]`
 * as the same empty PHP array — a limitation of the ASSOCIATIVE decode mode, not
 * of JSON — so decoding WITHOUT it keeps objects as `stdClass` and the
 * distinction survives:
 *
 *     {}                      valid, inert   (no recipients configured yet)
 *     []                      INVALID        (a list where an object belongs)
 *     {"to":["customer"]}     valid
 *     {"to":{}}               INVALID        (a channel must be a JSON array)
 *     {"to":{"a":"customer"}} INVALID        (same)
 *
 * The last two are why this matters here and not only in `Targeting`: an
 * object-shaped channel used to resolve its VALUES as recipients, so a
 * malformed document quietly addressed an email to whatever those values held.
 *
 * THE WRITER IS AS PRECISE AS THE READER. PHP renders `array()` as `[]`, so
 * `Json::encode()` would store an empty recipients document in the one shape
 * the reader now rejects — every rule with no recipients yet would report its
 * document unusable instead of simply having none. self::encode() casts the
 * root to an object; a genuinely list-shaped root is left alone so garbage
 * still reads back as invalid rather than being repaired on the way in.
 */
final class RecipientsDocument {

	/**
	 * Recipient channels, in precedence order.
	 */
	const CHANNELS = array( 'to', 'cc', 'bcc' );

	/**
	 * Decode a stored recipients value into a validated document.
	 *
	 * @param mixed $value Raw JSON string, decoded array, or null.
	 * @return array|null Channel => entries, or null when the document is
	 *                    structurally unusable.
	 */
	public static function from_value( $value ): ?array {
		if ( null === $value ) {
			return array();
		}

		if ( is_array( $value ) ) {
			// A programmatically constructed document. The `{}`-versus-`[]`
			// distinction genuinely no longer exists here, so an empty array is
			// an empty document — the same split `Targeting::from_array()` makes.
			return self::from_array( $value );
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$trimmed = trim( $value );
		if ( '' === $trimmed ) {
			return array();
		}

		return self::from_json( $trimmed );
	}

	/**
	 * Decode the RAW column string, where `{}` and `[]` are distinguishable.
	 *
	 * @param string $json Trimmed raw column string.
	 * @return array|null
	 */
	private static function from_json( string $json ): ?array {
		$document = json_decode( $json, false, Json::MAX_DEPTH );

		if ( JSON_ERROR_NONE !== json_last_error() || ! $document instanceof \stdClass ) {
			return null;
		}

		foreach ( self::CHANNELS as $channel ) {
			// A channel must be a JSON ARRAY. Checked here, before conversion,
			// because `{"to":{"a":"x"}}` collapses into an ordinary PHP array
			// afterwards and its values would become recipients.
			if ( property_exists( $document, $channel ) && ! is_array( $document->{$channel} ) ) {
				return null;
			}
		}

		return self::from_array( (array) $document );
	}

	/**
	 * Validate an already-decoded document.
	 *
	 * @param array $document Decoded document.
	 * @return array|null
	 */
	private static function from_array( array $document ): ?array {
		if ( array() !== $document && array_keys( $document ) === range( 0, count( $document ) - 1 ) ) {
			return null; // A list where an object keyed by channel belongs.
		}

		foreach ( self::CHANNELS as $channel ) {
			if ( array_key_exists( $channel, $document ) && ! is_array( $document[ $channel ] ) ) {
				return null;
			}
		}

		return $document;
	}

	/**
	 * Encode a recipients document for the `rules.recipients` column.
	 *
	 * @param array $document Recipients document.
	 * @return string JSON text for storage.
	 */
	public static function encode( array $document ): string {
		$is_object_shaped = array() === $document
			|| array_keys( $document ) !== range( 0, count( $document ) - 1 );

		return Json::encode_value( $is_object_shaped ? (object) $document : $document, '{}' );
	}
}
