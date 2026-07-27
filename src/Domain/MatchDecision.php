<?php
/**
 * One rule's decision for one trigger (ADR-0005, ADR-0011).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The immutable answer to "did this rule apply, and why".
 *
 * WHY THIS IS NOT A BOOLEAN. ADR-0005 forbids logging rules that merely failed
 * product targeting — that silence is what keeps the decision log bounded —
 * while REQUIRING that rules which matched and were then excluded are logged
 * (`excluded-after-product-match`). Both outcomes are "did not send", so a
 * boolean engine cannot tell them apart: the logging policy would be
 * unimplementable and the delivery-history panel would have nothing to show.
 * The reason code carries the distinction from the start, and `loggable` is a
 * fixed property of the reason rather than a judgement each caller re-derives.
 */
final class MatchDecision {

	/**
	 * At least one item matched the include set and survived exclusion.
	 */
	const MATCHED = 'matched';

	/**
	 * No item matched the include set. The noise floor — NOT loggable.
	 */
	const NO_TARGETING_MATCH = 'no_targeting_match';

	/**
	 * Items matched the include set and were then excluded.
	 */
	const EXCLUDED_BY_RULE = 'excluded_by_rule';

	/**
	 * A higher-priority rule carrying `stop_processing` halted evaluation
	 * before this rule was reached.
	 */
	const BLOCKED_BY_STOP_FLAG = 'blocked_by_stop_flag';

	/**
	 * The rule was disabled between fetch and evaluation.
	 */
	const RULE_DISABLED = 'rule_disabled';

	/**
	 * The targeting document is structurally malformed.
	 */
	const TARGETING_INVALID = 'targeting_invalid';

	/**
	 * ADR-0008: the trigger fired on an order with zero line items. Carried by
	 * the order-level result, never by a decision — nothing is evaluated.
	 */
	const NO_ITEMS_DEFERRED = 'no_items_deferred';

	/**
	 * Item-level note: the item's product no longer resolves. Carried by the
	 * order-level result against the item id, never as a rule's reason — it
	 * describes the ORDER, not any rule's decision.
	 */
	const PRODUCT_UNAVAILABLE = 'product_unavailable';

	/**
	 * Item-level note: the item's VARIATION no longer resolves but its parent
	 * product does — ADR-0011 §4's `partially_resolved` state.
	 *
	 * DISTINCT from `product_unavailable` on purpose. The item is NOT skipped:
	 * the customer did buy that product, so id, category and tag rules still
	 * fire on it. What is lost is the variation's own type slug and its
	 * `virtual`/`downloadable` flags, so a `types` rule cannot match it — and
	 * without a note of its own, the delivery log could not explain why a type
	 * rule stopped firing after a discontinued variation was removed.
	 */
	const VARIATION_UNAVAILABLE = 'variation_unavailable';

	/**
	 * Reason code => whether ADR-0005 records it.
	 *
	 * Only `no_targeting_match` is silent, and only in normal operation: it is
	 * the dominant case (a rule simply does not apply to this order) and
	 * logging it would make the table grow without bound and leak order
	 * contents. Debug mode may still record it.
	 */
	const LOGGABLE = array(
		self::MATCHED               => true,
		self::NO_TARGETING_MATCH    => false,
		self::EXCLUDED_BY_RULE      => true,
		self::BLOCKED_BY_STOP_FLAG  => true,
		self::RULE_DISABLED         => true,
		self::TARGETING_INVALID     => true,
		self::NO_ITEMS_DEFERRED     => true,
		self::PRODUCT_UNAVAILABLE   => true,
		self::VARIATION_UNAVAILABLE => true,
	);

	/**
	 * Rule id.
	 *
	 * @var int
	 */
	private $rule_id;

	/**
	 * Reason code, one of the constants above.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * ADR-0004 trigger identity this decision was made under.
	 *
	 * @var string
	 */
	private $trigger_identity;

	/**
	 * Matched items, in line-item order. Each entry is
	 * `{item_id, product_id, variation_id, level, kind}`.
	 *
	 * @var array[]
	 */
	private $matched_items;

	/**
	 * Use self::create().
	 *
	 * @param int    $rule_id          Rule id.
	 * @param string $reason           Reason code.
	 * @param string $trigger_identity ADR-0004 trigger identity.
	 * @param array  $matched_items    Matched item records.
	 */
	private function __construct( int $rule_id, string $reason, string $trigger_identity, array $matched_items ) {
		$this->rule_id          = $rule_id;
		$this->reason           = $reason;
		$this->trigger_identity = $trigger_identity;
		$this->matched_items    = $matched_items;
	}

	/**
	 * Build a decision.
	 *
	 * @param int    $rule_id          Rule id.
	 * @param string $reason           Reason code.
	 * @param string $trigger_identity ADR-0004 trigger identity.
	 * @param array  $matched_items    Matched item records; only meaningful
	 *                                 with self::MATCHED.
	 * @return MatchDecision
	 */
	public static function create( int $rule_id, string $reason, string $trigger_identity, array $matched_items = array() ): MatchDecision {
		return new self( $rule_id, $reason, $trigger_identity, self::MATCHED === $reason ? array_values( $matched_items ) : array() );
	}

	/**
	 * Rule id.
	 *
	 * @return int
	 */
	public function rule_id(): int {
		return $this->rule_id;
	}

	/**
	 * Whether the rule applies to this order for this trigger.
	 *
	 * @return bool
	 */
	public function matched(): bool {
		return self::MATCHED === $this->reason;
	}

	/**
	 * Reason code.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Whether ADR-0005 records this decision in normal operation.
	 *
	 * @return bool
	 */
	public function loggable(): bool {
		return self::LOGGABLE[ $this->reason ] ?? false;
	}

	/**
	 * Matched line-item ids, in line-item order.
	 *
	 * NOT de-duplicated across line items: two separate line items of the same
	 * product yield two ids here and ONE id in matched_product_ids(). The
	 * engine returns both; consolidating them is a delivery decision
	 * (ADR-0011 §4).
	 *
	 * @return int[]
	 */
	public function matched_item_ids(): array {
		return array_map(
			static function ( array $item ): int {
				return (int) $item['item_id'];
			},
			$this->matched_items
		);
	}

	/**
	 * Matched product ids, de-duplicated, in first-seen order.
	 *
	 * A variation contributes its PARENT product id; its variation id is
	 * available on the matched-item records.
	 *
	 * @return int[]
	 */
	public function matched_product_ids(): array {
		$ids = array();
		foreach ( $this->matched_items as $item ) {
			$product_id = (int) $item['product_id'];
			if ( $product_id > 0 && ! in_array( $product_id, $ids, true ) ) {
				$ids[] = $product_id;
			}
		}
		return $ids;
	}

	/**
	 * Matched variation ids, de-duplicated, in first-seen order.
	 *
	 * @return int[]
	 */
	public function matched_variation_ids(): array {
		$ids = array();
		foreach ( $this->matched_items as $item ) {
			$variation_id = (int) $item['variation_id'];
			if ( $variation_id > 0 && ! in_array( $variation_id, $ids, true ) ) {
				$ids[] = $variation_id;
			}
		}
		return $ids;
	}

	/**
	 * The full matched-item records, each carrying the specificity LEVEL and
	 * KIND that item matched at (ADR-0011 §5 records specificity per item).
	 *
	 * @return array[]
	 */
	public function matched_items(): array {
		return $this->matched_items;
	}

	/**
	 * The strongest specificity level any matched item achieved.
	 *
	 * @return int Ladder level, or Specificity::NONE when nothing matched.
	 */
	public function specificity(): int {
		$highest = Specificity::NONE;
		foreach ( $this->matched_items as $item ) {
			$highest = Specificity::highest( $highest, (int) $item['level'] );
		}
		return $highest;
	}

	/**
	 * The targeting KIND that produced self::specificity().
	 *
	 * SEPARATE FROM THE LEVEL BECAUSE THE LEVEL CANNOT NAME IT. `types` shares
	 * rung 1 with `tag` (ADR-0011 §5), so the rung NAME for a type-only match is
	 * `tag` — correct as a rung, wrong as a description of what matched. The
	 * kind is what the delivery log and the admin panel must show.
	 *
	 * Ties are resolved by line-item order: the first item to reach the highest
	 * level names the kind, so the answer is deterministic.
	 *
	 * @return string Kind name, or '' when nothing matched.
	 */
	public function specificity_kind(): string {
		$highest = Specificity::NONE;
		$kind    = '';

		foreach ( $this->matched_items as $item ) {
			$level = (int) $item['level'];
			if ( $level > $highest ) {
				$highest = $level;
				$kind    = (string) $item['kind'];
			}
		}

		return $kind;
	}

	/**
	 * The RUNG name for self::specificity().
	 *
	 * NOT A MATCH TYPE. A type-only match sits on rung 1 and this returns `tag`,
	 * because rung 1 is called `tag` — use self::specificity_kind() for what
	 * actually matched. Kept because the ordering ladder is a real thing worth
	 * naming; never serialise it as the match type.
	 *
	 * @return string
	 */
	public function specificity_label(): string {
		return Specificity::label( $this->specificity() );
	}

	/**
	 * ADR-0004 trigger identity.
	 *
	 * @return string
	 */
	public function trigger_identity(): string {
		return $this->trigger_identity;
	}

	/**
	 * Flat array form, for logging and assertions.
	 *
	 * CARRIES THE KIND, NOT ONLY THE RUNG. The serialised form previously held
	 * `specificity` alone — a bare number whose only name is a rung name — so a
	 * type-only match came out of the engine indistinguishable from a tag match
	 * the moment it left PHP, which is precisely what the delivery log and the
	 * admin panel consume. The matched-item records ride along too, each with
	 * the level AND kind that item matched at (ADR-0011 §5).
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'rule_id'             => $this->rule_id,
			'matched'             => $this->matched(),
			'reason'              => $this->reason,
			'loggable'            => $this->loggable(),
			'matched_item_ids'    => $this->matched_item_ids(),
			'matched_product_ids' => $this->matched_product_ids(),
			'matched_items'       => $this->matched_items,
			'specificity_level'   => $this->specificity(),
			'specificity_kind'    => $this->specificity_kind(),
			'trigger_identity'    => $this->trigger_identity,
		);
	}
}
