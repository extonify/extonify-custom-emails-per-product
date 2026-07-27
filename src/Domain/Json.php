<?php
/**
 * JSON column encode/decode with validation on read (ADR-0009).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The single gateway for the plugin's LONGTEXT JSON columns.
 *
 * Reads VALIDATE: a column that is empty, invalid JSON, or valid JSON that is
 * not an array (a bare string, number, boolean or null) yields an empty array
 * rather than propagating null into callers that expect an array. Corrupt
 * stored data must degrade a single rule, never fatal a request.
 */
final class Json {

	/**
	 * Maximum decode depth. Rule targeting and recipient structures are
	 * shallow; a deeply nested document is corrupt or hostile input.
	 */
	const MAX_DEPTH = 32;

	/**
	 * Suffix under which a hydrated row carries a JSON column's RAW string
	 * alongside the decoded array.
	 *
	 * DEFINED HERE, IN THE DOMAIN, so the one rule that matters can be stated in
	 * one place and applied by everything that reads a rule row: THE RAW STRING
	 * IS AUTHORITATIVE WHEN IT IS PRESENT. `Domain\PreparedRule` applies it, and
	 * `Repository\RuleRepository::RAW_SUFFIX` aliases this constant so the
	 * writer and the reader cannot name the passenger differently.
	 *
	 * Ordering used to read the DECODED array while evaluation read the RAW
	 * string, so a document valid as an array and invalid as text sorted at a
	 * specificity its own decision did not have — see `Domain\PreparedRule`.
	 */
	const RAW_SUFFIX = '_raw';

	/**
	 * Encode an array for storage.
	 *
	 * Slashes are not escaped (they are legal in JSON strings and escaping
	 * them only inflates the column) and unicode is preserved so stored data
	 * stays human-readable in the database.
	 *
	 * @param array $value Value to store.
	 * @return string JSON text, or '[]' when encoding fails.
	 */
	public static function encode( array $value ): string {
		return self::encode_value( $value, '[]' );
	}

	/**
	 * Encode any node for storage, including a `stdClass`.
	 *
	 * Exists so a caller that must control JSON OBJECT versus JSON ARRAY output
	 * — `Domain\Targeting`, whose schema says the document and its two sides are
	 * objects — can hand over an object without this class having to know the
	 * schema. PHP renders `array()` as `[]`, and for a document read back under
	 * strict validation that is a different thing from `{}`.
	 *
	 * @param mixed  $value    Value to store.
	 * @param string $fallback Returned when encoding fails.
	 * @return string JSON text.
	 */
	public static function encode_value( $value, string $fallback ): string {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, self::MAX_DEPTH );
		return is_string( $json ) ? $json : $fallback;
	}

	/**
	 * Decode a stored JSON column, validating the result.
	 *
	 * @param mixed $value Raw column value.
	 * @return array Decoded array; empty array when absent or malformed.
	 */
	public static function decode( $value ): array {
		if ( is_array( $value ) ) {
			return $value; // Already decoded by a caller; accept it unchanged.
		}
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}
		$decoded = json_decode( $value, true, self::MAX_DEPTH );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return array();
		}
		return $decoded;
	}
}
