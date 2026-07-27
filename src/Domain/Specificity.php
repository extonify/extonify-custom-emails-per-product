<?php
/**
 * Targeting specificity ladder and rule ordering (ADR-0011).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The specificity ladder, and the total order rules are evaluated in.
 *
 * ADR-0011 fixes the ladder at five rungs:
 *
 *     variation 4 > product 3 > category 2 > tag 1 > match_all 0
 *
 * The targeting schema has SIX include kinds, so `types` has to be placed on an
 * existing rung. It shares rung 1 with `tag`: selecting "every downloadable
 * product" is a deliberate targeting act and must rank above `match_all`, and a
 * product type is the broadest classification there is — every product in the
 * catalogue carries one — so it cannot rank above `tag`.
 *
 * CONSEQUENCE: THE LEVEL CANNOT NAME WHAT MATCHED. `label( 1 )` is `tag`,
 * because rung 1 is called `tag`. The matched KIND is therefore carried
 * ALONGSIDE the level everywhere, including in the serialised decision — see
 * `MatchDecision::specificity_kind()`. Never use the numeric rung, or its rung
 * name, as a human-readable match type.
 *
 * Specificity never suppresses a rule. It is recorded per matched item for the
 * decision log, and used as a tie-breaker when ordering rules that share a
 * priority — nothing else.
 */
final class Specificity {

	/**
	 * A variation id matched the line item's variation id.
	 */
	const VARIATION = 4;

	/**
	 * A product id matched the line item's product id.
	 */
	const PRODUCT = 3;

	/**
	 * A category term id matched the product's categories.
	 */
	const CATEGORY = 2;

	/**
	 * A tag term id matched the product's tags.
	 */
	const TAG = 1;

	/**
	 * A product type slug or flag matched. Shares rung 1 with TAG — see the
	 * class docblock.
	 */
	const TYPE = 1;

	/**
	 * `match_all: true` matched, with nothing more specific declared.
	 */
	const MATCH_ALL = 0;

	/**
	 * Nothing matched, or nothing was declared. Sorts last within a priority.
	 */
	const NONE = -1;

	/**
	 * Targeting kind => ladder level.
	 *
	 * Ordered most specific first; iteration order is what makes
	 * `highest_kind()` return the strongest kind for a level shared by two.
	 */
	const KIND_LEVELS = array(
		'variation' => self::VARIATION,
		'product'   => self::PRODUCT,
		'category'  => self::CATEGORY,
		'tag'       => self::TAG,
		'type'      => self::TYPE,
		'match_all' => self::MATCH_ALL,
	);

	/**
	 * Ladder level => canonical rung name.
	 *
	 * `type` is absent deliberately: it is a KIND, not a rung. Use
	 * `kind_level()` to place a kind and `label()` to name a rung.
	 */
	const LEVEL_LABELS = array(
		self::VARIATION => 'variation',
		self::PRODUCT   => 'product',
		self::CATEGORY  => 'category',
		self::TAG       => 'tag',
		self::MATCH_ALL => 'match_all',
	);

	/**
	 * The ladder level a targeting kind occupies.
	 *
	 * @param string $kind One of the keys of self::KIND_LEVELS.
	 * @return int Ladder level, or self::NONE for an unknown kind.
	 */
	public static function kind_level( string $kind ): int {
		return self::KIND_LEVELS[ $kind ] ?? self::NONE;
	}

	/**
	 * The canonical rung name for a ladder level.
	 *
	 * @param int $level Ladder level.
	 * @return string Rung name, or 'none' when the level is not on the ladder.
	 */
	public static function label( int $level ): string {
		return self::LEVEL_LABELS[ $level ] ?? 'none';
	}

	/**
	 * Whether a value is a level on the ladder (self::NONE is not).
	 *
	 * @param int $level Candidate level.
	 * @return bool
	 */
	public static function is_level( int $level ): bool {
		return isset( self::LEVEL_LABELS[ $level ] );
	}

	/**
	 * The strongest of two levels.
	 *
	 * @param int $a First level.
	 * @param int $b Second level.
	 * @return int
	 */
	public static function highest( int $a, int $b ): int {
		return $a > $b ? $a : $b;
	}

	/**
	 * Compare two PREPARED rules for evaluation order (ADR-0011 §6).
	 *
	 * Priority ascending (LOWER RUNS FIRST, matching WordPress hook ordering),
	 * then declared specificity descending, then rule id ascending. The order
	 * is TOTAL, so the result never depends on the sort implementation's
	 * stability or on the order rows came back from the database.
	 *
	 * TAKES PREPARED RULES, NOT ROWS, DELIBERATELY. A row could carry a
	 * `declared_specificity` key and dictate its own position; a `PreparedRule`
	 * computes that value from the targeting document it also hands to
	 * evaluation, so ordering and the decision can never disagree about the same
	 * rule.
	 *
	 * @param PreparedRule $a First rule.
	 * @param PreparedRule $b Second rule.
	 * @return int Negative when $a runs first, positive when $b does, 0 only
	 *             when both are the same rule.
	 */
	public static function compare( PreparedRule $a, PreparedRule $b ): int {
		$priority = $a->priority() <=> $b->priority();
		if ( 0 !== $priority ) {
			return $priority;
		}

		// Descending: the more specific rule runs first.
		$specificity = $b->declared_specificity() <=> $a->declared_specificity();
		if ( 0 !== $specificity ) {
			return $specificity;
		}

		return $a->id() <=> $b->id();
	}

	/**
	 * Sort prepared rules into evaluation order.
	 *
	 * @param PreparedRule[] $rules Prepared rules.
	 * @return PreparedRule[] Re-indexed, in evaluation order.
	 */
	public static function sort( array $rules ): array {
		$ordered = array_values( $rules );
		usort( $ordered, array( self::class, 'compare' ) );

		return $ordered;
	}

	/**
	 * Sort rule ROWS into evaluation order, for callers holding plain rows.
	 *
	 * A convenience wrapper over self::sort(), and it goes through
	 * `PreparedRule` exactly as the matcher does — so the two can never order
	 * the same rules differently. Each returned row is annotated with the
	 * `declared_specificity` it was ordered by; that annotation is OUTPUT ONLY
	 * and is recomputed from the targeting document on every call, so a row
	 * carrying one cannot dictate its own position.
	 *
	 * @param array[] $rules Rule rows.
	 * @return array[] Re-indexed, in evaluation order, each annotated.
	 */
	public static function sort_rules( array $rules ): array {
		return array_map(
			static function ( PreparedRule $rule ): array {
				return $rule->annotated_row();
			},
			self::sort( PreparedRule::from_rows( $rules ) )
		);
	}
}
