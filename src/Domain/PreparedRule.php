<?php
/**
 * A rule row with its targeting parsed exactly once (ADR-0011 §5, §6).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One rule, ready to be ordered and then evaluated, carrying the SINGLE
 * `Targeting` instance both steps use.
 *
 * WHY THIS EXISTS. Ordering and evaluation used to parse the same column
 * separately and from DIFFERENT REPRESENTATIONS of it: `Specificity` read the
 * decoded array `$rule['targeting']`, while the matcher read the raw passenger
 * `targeting_raw`. For a document that is valid as an array but invalid as text
 * — `{"include":{"products":{"0":1}}}` is the plain case — the rule sorted at
 * PRODUCT specificity while its decision was `targeting_invalid` at NONE.
 *
 * THAT WAS NOT COSMETIC. At equal priority it put a malformed rule AHEAD of a
 * valid `stop_processing` rule, so the halt landed in a different place, the
 * rules after it received `blocked_by_stop_flag` instead of their real reasons,
 * and the delivery log recorded a different sequence of events — a direct
 * violation of ADR-0011 §5 and §6.
 *
 * Two rules follow from that, and both are enforced here rather than trusted:
 *
 *   1. **The raw string wins when it is present.** It is the authoritative
 *      stored form; the decoded array is a lossy convenience (`Json::decode()`
 *      cannot distinguish broken JSON from an empty column, and associative
 *      decoding cannot distinguish `{}` from `[]`). The decoded array is used
 *      only when no passenger exists at all.
 *   2. **Declared specificity is always RECOMPUTED, never read from the row.**
 *      `sort_rules()` annotates rows with `declared_specificity`, so any caller
 *      handing a previously sorted array back — or a hand-built row carrying
 *      that key — could otherwise dictate evaluation order directly, bypassing
 *      the declaration it is supposed to be derived from. Ordering is never
 *      externally supplied.
 */
final class PreparedRule {

	/**
	 * The rule row as stored, unmodified.
	 *
	 * @var array
	 */
	private $row;

	/**
	 * The rule's targeting document, parsed once.
	 *
	 * @var Targeting
	 */
	private $targeting;

	/**
	 * Declared specificity, computed from self::$targeting.
	 *
	 * @var int
	 */
	private $declared_specificity;

	/**
	 * Use self::from_row().
	 *
	 * @param array     $row       Rule row.
	 * @param Targeting $targeting Parsed targeting document.
	 */
	private function __construct( array $row, Targeting $targeting ) {
		$this->row                  = $row;
		$this->targeting            = $targeting;
		$this->declared_specificity = $targeting->declared_specificity();
	}

	/**
	 * Prepare one rule row. THE ONLY PLACE A RULE'S TARGETING IS PARSED.
	 *
	 * @param array $row Rule row, hydrated or hand-built.
	 * @return PreparedRule
	 */
	public static function from_row( array $row ): PreparedRule {
		return new self( $row, Targeting::from_value( self::targeting_value( $row ) ) );
	}

	/**
	 * Prepare a set of rule rows, in the order given.
	 *
	 * @param array[] $rows Rule rows.
	 * @return PreparedRule[]
	 */
	public static function from_rows( array $rows ): array {
		return array_values( array_map( array( self::class, 'from_row' ), $rows ) );
	}

	/**
	 * The authoritative targeting value on a row: the raw column string when the
	 * hydrated passenger is present, the decoded array otherwise.
	 *
	 * @param array $row Rule row.
	 * @return mixed Raw JSON string, decoded array, or null.
	 */
	private static function targeting_value( array $row ) {
		$passenger = 'targeting' . Json::RAW_SUFFIX;

		if ( array_key_exists( $passenger, $row ) ) {
			return $row[ $passenger ];
		}

		return $row['targeting'] ?? array();
	}

	/**
	 * The rule row, unmodified.
	 *
	 * @return array
	 */
	public function row(): array {
		return $this->row;
	}

	/**
	 * The row with its `declared_specificity` annotated from the parsed
	 * document, so a caller can read the value it was actually ordered by.
	 *
	 * @return array
	 */
	public function annotated_row(): array {
		$row                         = $this->row;
		$row['declared_specificity'] = $this->declared_specificity;

		return $row;
	}

	/**
	 * Rule id.
	 *
	 * @return int
	 */
	public function id(): int {
		return (int) ( $this->row['id'] ?? 0 );
	}

	/**
	 * Rule priority. Lower runs first (ADR-0011 §6).
	 *
	 * @return int
	 */
	public function priority(): int {
		return (int) ( $this->row['priority'] ?? 0 );
	}

	/**
	 * Whether the row says the rule is enabled.
	 *
	 * READ FROM THE ROW, NEVER RE-QUERIED (ADR-0011 §7a). Supplying current
	 * rules is the caller's responsibility.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return 'active' === (string) ( $this->row['status'] ?? 'active' );
	}

	/**
	 * Whether a match on this rule halts evaluation (ADR-0011 §6).
	 *
	 * @return bool
	 */
	public function stops_processing(): bool {
		return ! empty( $this->row['stop_processing'] );
	}

	/**
	 * The parsed targeting document — the same instance ordering used.
	 *
	 * @return Targeting
	 */
	public function targeting(): Targeting {
		return $this->targeting;
	}

	/**
	 * Declared specificity, computed from the targeting document.
	 *
	 * @return int Ladder level, or Specificity::NONE.
	 */
	public function declared_specificity(): int {
		return $this->declared_specificity;
	}
}
