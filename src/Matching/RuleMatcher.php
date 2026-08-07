<?php
/**
 * The rule matching engine (ADR-0011).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Matching;

use Extonify\WCEP\Domain\EvaluationResult;
use Extonify\WCEP\Domain\MatchDecision;
use Extonify\WCEP\Domain\PreparedRule;
use Extonify\WCEP\Domain\Specificity;
use Extonify\WCEP\Domain\Targeting;
use Extonify\WCEP\Domain\TriggerEvent;
use Extonify\WCEP\Repository\RuleRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Answers: which rules apply to this order for this trigger, which line items
 * they matched, and why.
 *
 * A PURE FUNCTION of (order, trigger event, active rules). It writes NOTHING —
 * no claims, no log rows, no order or product mutation — and registers no
 * hooks. Event wiring belongs to the delivery phase; this class is invoked
 * directly.
 *
 * Every rule produces a structured decision, never a boolean, because ADR-0005
 * forbids logging a rule that merely failed targeting while requiring that a
 * rule which matched and was then excluded IS logged — see
 * `Domain\MatchDecision`.
 */
class RuleMatcher {

	/**
	 * Rule storage.
	 *
	 * @var RuleRepository
	 */
	private $rules;

	/**
	 * Line-item resolver, holding the request cache.
	 *
	 * @var ItemResolver
	 */
	private $items;

	/**
	 * Constructor.
	 *
	 * @param RuleRepository|null $rules Rule storage; built on demand.
	 * @param ItemResolver|null   $items Item resolver; built on demand.
	 */
	public function __construct( ?RuleRepository $rules = null, ?ItemResolver $items = null ) {
		$this->rules = null !== $rules ? $rules : new RuleRepository();
		$this->items = null !== $items ? $items : new ItemResolver();
	}

	/**
	 * The resolver holding the request's PRODUCT cache, so a caller evaluating
	 * several triggers on one order loads each product once. It caches no order
	 * contents (ADR-0012 §11b).
	 *
	 * @return ItemResolver
	 */
	public function item_resolver(): ItemResolver {
		return $this->items;
	}

	/**
	 * Evaluate one trigger against one order.
	 *
	 * RULE FRESHNESS IS THE CALLER'S RESPONSIBILITY (ADR-0011 §7a). This method
	 * evaluates the rows it is handed and never re-reads rule state. When
	 * `$rules` is null it fetches active rules and evaluates them immediately,
	 * which is what makes the immediate path current. A DEFERRED caller must
	 * reload and revalidate rules at execution time per ADR-0007 and pass the
	 * CURRENT rows — it must not pass a set captured before the delay.
	 *
	 * A ZERO-ITEM ORDER DEFERS ONLY WHEN A CANDIDATE RULE EXISTS (ADR-0008,
	 * ADR-0012 §7). With an empty rule set the answer is already known and final,
	 * so the result is an ordinary empty one and the caller schedules nothing.
	 *
	 * @param \WC_Order    $order Order the trigger fired on.
	 * @param TriggerEvent $event Trigger event.
	 * @param array[]|null $rules Rules to evaluate. Fetched from storage when
	 *                            null. Supplied directly by tests, and by any
	 *                            caller that has just reloaded and revalidated
	 *                            them itself.
	 * @return EvaluationResult
	 */
	public function evaluate( \WC_Order $order, TriggerEvent $event, ?array $rules = null ): EvaluationResult {
		$order_id = (int) $order->get_id();

		if ( ! OrderStatuses::is_triggering( $event ) ) {
			return EvaluationResult::not_triggered( $order_id, $event );
		}

		$resolved = $this->items->resolve_order( $order );

		// THE RULES ARE CONSULTED BEFORE THE DEFERRAL DECISION, NOT AFTER.
		// Deferring is only meaningful if there is something to defer FOR.
		if ( null === $rules ) {
			$rules = $this->rules->find_active_for_trigger( $event->type(), $event->value() );
		}

		if ( 0 === (int) $resolved['item_count'] ) {
			/*
			 * ADR-0008: a targeted event on an order with zero line items is not
			 * evidence that nothing matches, it is evidence that the question
			 * cannot be answered yet — SO LONG AS SOMETHING COULD HAVE ANSWERED
			 * IT. Evaluate nothing, claim nothing, and preserve the identity so
			 * the deferred re-evaluation reuses it.
			 */
			if ( array() !== $rules ) {
				return EvaluationResult::for_deferral( $order_id, $event );
			}

			/*
			 * NO CANDIDATE, SO NOTHING TO ASK AGAIN (ADR-0008, ADR-0012 §7).
			 * The rule set handed here is already phase-filtered, so "empty"
			 * covers a store with no active rules, a store whose only rules are
			 * insert-mode or delayed, and a trigger family nothing targets. The
			 * deferral used to be decided before the rules were looked at, so
			 * every one of those cases queued an Action Scheduler job that could
			 * only ever re-discover the same nothing — and a status change
			 * evaluates two families, so a bulk import queued two useless
			 * actions per order and filled the queue.
			 */
			return EvaluationResult::create( $order_id, $event, array(), $resolved['notes'] );
		}

		/*
		 * Parse each rule's targeting ONCE, before ordering, and carry that one
		 * instance through both steps. Ordering and evaluation reading the same
		 * column separately — and from different representations of it — is what
		 * let a malformed rule sort ahead of a valid `stop_processing` rule and
		 * move the halt point (ADR-0011 §5, §6). See `Domain\PreparedRule`.
		 */
		$prepared = Specificity::sort( PreparedRule::from_rows( $rules ) );

		return EvaluationResult::create(
			$order_id,
			$event,
			$this->decide( $prepared, $resolved['items'], $event->identity() ),
			$resolved['notes']
		);
	}

	/**
	 * Evaluate BOTH families raised by one status change (ADR-0011 §1).
	 *
	 * Two `find_active_for_trigger()` calls, two trigger identities, two
	 * independent results. The families are not merged, ordered against each
	 * other or deduplicated, and a `stop_processing` halt in one does not touch
	 * the other — the halt is scoped to a single trigger identity, or the
	 * outcome would depend on which family happened to be evaluated first.
	 *
	 * Both share one resolver, so each PRODUCT is loaded once. The order's line
	 * items are re-read per evaluation and deliberately not cached: an order
	 * routinely gains, loses or replaces items between two events in one request
	 * (ADR-0012 §11b). Re-reading them costs no query — the product facts are
	 * what the cache is for.
	 *
	 * @param \WC_Order $order Order that changed status.
	 * @param string    $from  Source status, with or without the `wc-` prefix.
	 * @param string    $to    Destination status, with or without the prefix.
	 * @return array {
	 *     @type EvaluationResult $status     The `status:{to}` family.
	 *     @type EvaluationResult $transition The `transition:{from}>{to}` family.
	 * }
	 */
	public function evaluate_status_change( \WC_Order $order, string $from, string $to ): array {
		return array(
			'status'     => $this->evaluate( $order, TriggerEvent::status( $to ) ),
			'transition' => $this->evaluate( $order, TriggerEvent::transition( $from, $to ) ),
		);
	}

	/**
	 * Evaluate a refund trigger.
	 *
	 * The PARENT order's line items are evaluated, not the refund's: ADR-0011
	 * §4 defines item matching over the order's line items, and ADR-0004 keys
	 * the identity to the refund id, which is what distinguishes two partial
	 * refunds from one another. Narrowing the match to the refunded items only
	 * is recorded in `docs/p2-backlog.md`.
	 *
	 * @param \WC_Order $order     The parent order.
	 * @param int       $refund_id WooCommerce refund object id.
	 * @return EvaluationResult
	 */
	public function evaluate_refund( \WC_Order $order, int $refund_id ): EvaluationResult {
		return $this->evaluate( $order, TriggerEvent::refund( $refund_id ) );
	}

	/**
	 * Walk the ordered rules, producing one decision each and honouring the
	 * `stop_processing` halt.
	 *
	 * @param PreparedRule[] $rules    Prepared rules in evaluation order.
	 * @param array[]        $items    Resolved item descriptors.
	 * @param string         $identity ADR-0004 trigger identity.
	 * @return MatchDecision[] Decisions in evaluation order.
	 */
	private function decide( array $rules, array $items, string $identity ): array {
		$decisions = array();
		$halted    = false;

		foreach ( $rules as $rule ) {
			if ( $halted ) {
				// The rule was never evaluated, so nothing else about it can be
				// known — and the halt has to be visible in the log rather than
				// showing up as an unexplained absence (ADR-0011 §6).
				$decisions[] = MatchDecision::create( $rule->id(), MatchDecision::BLOCKED_BY_STOP_FLAG, $identity );
				continue;
			}

			$decision    = $this->decide_rule( $rule, $items, $identity );
			$decisions[] = $decision;

			if ( $decision->matched() && $rule->stops_processing() ) {
				$halted = true;
			}
		}

		return $decisions;
	}

	/**
	 * One rule's decision.
	 *
	 * Reason precedence (ADR-0011 §7): `rule_disabled`, `targeting_invalid`,
	 * `matched`, `excluded_by_rule`, `no_targeting_match`.
	 *
	 * @param PreparedRule $rule     Prepared rule.
	 * @param array[]      $items    Resolved item descriptors.
	 * @param string       $identity ADR-0004 trigger identity.
	 * @return MatchDecision
	 */
	private function decide_rule( PreparedRule $rule, array $items, string $identity ): MatchDecision {
		$rule_id = $rule->id();

		/*
		 * Read `status` off the row we were handed, without re-querying: the
		 * matcher makes no freshness guarantee (ADR-0011 §7a). Rules this class
		 * fetched are active by construction, so this path exists for a caller
		 * that has ALREADY re-read a rule, found it inactive, and wants the
		 * outcome recorded in the log rather than silently dropped — a deferred
		 * job revalidating under ADR-0007. It is not a staleness check, and it
		 * is not a second source of truth beside the ADR-0007 snapshot.
		 */
		if ( ! $rule->is_active() ) {
			return MatchDecision::create( $rule_id, MatchDecision::RULE_DISABLED, $identity );
		}

		// Parsed once, before ordering, from the authoritative representation.
		$targeting = $rule->targeting();

		if ( ! $targeting->is_valid() ) {
			return MatchDecision::create( $rule_id, MatchDecision::TARGETING_INVALID, $identity );
		}

		$matched      = array();
		$any_excluded = false;

		foreach ( $items as $item ) {
			$outcome = $targeting->evaluate( $item );

			if ( $outcome['matched'] ) {
				$matched[] = array(
					'item_id'      => (int) $item['item_id'],
					'product_id'   => (int) $item['product_id'],
					'variation_id' => (int) $item['variation_id'],

					/*
					 * ⚠ CARRIED SINCE PROMPT 8, AND ITS ABSENCE WAS A DEFECT (ADR-0011 §4,
					 * ADR-0016 §4). The item DESCRIPTOR has always known its resolution
					 * state; this record dropped it, so every consumer downstream had to
					 * treat a `partially_resolved` item as fully resolved.
					 *
					 * That was invisible until consolidation, which reads it to decide a
					 * fan-out UNIT: a deleted variation whose parent survives still carries
					 * its recovered `variation_id`, so without this key two dead siblings of
					 * one parent became TWO units and the customer received two
					 * near-identical emails about a variation nobody can identify — each
					 * rendering `{variation_name}` empty, because the variation's own facts
					 * are exactly what is gone.
					 *
					 * Absent means resolved (`Domain\Targeting`'s own contract), so a
					 * consumer handed a record from another source still reads correctly.
					 */
					'resolution'   => (string) ( $item['resolution'] ?? Targeting::RESOLVED ),
					'level'        => (int) $outcome['level'],
					'kind'         => (string) $outcome['kind'],
				);
				continue;
			}

			if ( $outcome['included'] && $outcome['excluded'] ) {
				$any_excluded = true;
			}
		}

		if ( array() !== $matched ) {
			return MatchDecision::create( $rule_id, MatchDecision::MATCHED, $identity, $matched );
		}

		// Matched the include set and was then excluded: ADR-0005 REQUIRES this
		// be logged, which is exactly why the engine returns reason codes
		// rather than a boolean.
		if ( $any_excluded ) {
			return MatchDecision::create( $rule_id, MatchDecision::EXCLUDED_BY_RULE, $identity );
		}

		return MatchDecision::create( $rule_id, MatchDecision::NO_TARGETING_MATCH, $identity );
	}
}
