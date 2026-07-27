<?php
/**
 * The order-level outcome of evaluating one trigger (ADR-0011).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the delivery phase needs to know about one trigger on one order.
 *
 * Immutable. One result per TRIGGER IDENTITY: a single status change produces
 * TWO results, one for the `status:` family and one for the `transition:`
 * family, which are independent of each other in every respect including the
 * `stop_processing` halt (ADR-0011 §1, §6).
 */
final class EvaluationResult {

	/**
	 * Order id the trigger fired on.
	 *
	 * @var int
	 */
	private $order_id;

	/**
	 * The trigger event.
	 *
	 * @var TriggerEvent
	 */
	private $event;

	/**
	 * Decisions in evaluation order.
	 *
	 * @var MatchDecision[]
	 */
	private $decisions;

	/**
	 * Item-level notes: line-item id => reason code, one of
	 * `MatchDecision::PRODUCT_UNAVAILABLE` (item skipped) or
	 * `MatchDecision::VARIATION_UNAVAILABLE` (item kept, variation facts lost).
	 *
	 * @var array<int,string>
	 */
	private $item_notes;

	/**
	 * Whether ADR-0008 deferral applies.
	 *
	 * @var bool
	 */
	private $deferred;

	/**
	 * Result-level reason code, or '' when the decisions speak for themselves.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * Use the named constructors.
	 *
	 * @param int             $order_id   Order id.
	 * @param TriggerEvent    $event      Trigger event.
	 * @param MatchDecision[] $decisions  Decisions in evaluation order.
	 * @param array           $item_notes Item id => reason code.
	 * @param bool            $deferred   ADR-0008 deferral flag.
	 * @param string          $reason     Result-level reason code.
	 */
	private function __construct( int $order_id, TriggerEvent $event, array $decisions, array $item_notes, bool $deferred, string $reason ) {
		$this->order_id   = $order_id;
		$this->event      = $event;
		$this->decisions  = array_values( $decisions );
		$this->item_notes = $item_notes;
		$this->deferred   = $deferred;
		$this->reason     = $reason;
	}

	/**
	 * An ordinary evaluation.
	 *
	 * @param int             $order_id   Order id.
	 * @param TriggerEvent    $event      Trigger event.
	 * @param MatchDecision[] $decisions  Decisions in evaluation order.
	 * @param array           $item_notes Item id => reason code.
	 * @return EvaluationResult
	 */
	public static function create( int $order_id, TriggerEvent $event, array $decisions, array $item_notes = array() ): EvaluationResult {
		return new self( $order_id, $event, $decisions, $item_notes, false, '' );
	}

	/**
	 * ADR-0008: the trigger fired on an order with zero line items.
	 *
	 * Nothing is evaluated and nothing is claimed. The trigger identity is
	 * PRESERVED so the deferred re-evaluation reuses the identity the immediate
	 * path would have used, and the DB unique constraint collapses the two
	 * paths into one claim (ADR-0004, ADR-0008).
	 *
	 * @param int          $order_id Order id.
	 * @param TriggerEvent $event    Trigger event.
	 * @return EvaluationResult
	 */
	public static function for_deferral( int $order_id, TriggerEvent $event ): EvaluationResult {
		return new self( $order_id, $event, array(), array(), true, MatchDecision::NO_ITEMS_DEFERRED );
	}

	/**
	 * A non-triggering event: `checkout-draft`, an unknown status, or an
	 * identity that does not satisfy ADR-0004's grammar. No decisions, no
	 * deferral, nothing claimed.
	 *
	 * @param int          $order_id Order id.
	 * @param TriggerEvent $event    Trigger event.
	 * @return EvaluationResult
	 */
	public static function not_triggered( int $order_id, TriggerEvent $event ): EvaluationResult {
		return new self( $order_id, $event, array(), array(), false, '' );
	}

	/**
	 * Order id.
	 *
	 * @return int
	 */
	public function order_id(): int {
		return $this->order_id;
	}

	/**
	 * The trigger event.
	 *
	 * @return TriggerEvent
	 */
	public function event(): TriggerEvent {
		return $this->event;
	}

	/**
	 * Trigger type.
	 *
	 * @return string
	 */
	public function trigger_type(): string {
		return $this->event->type();
	}

	/**
	 * Trigger value, as stored in `rules.trigger_value`.
	 *
	 * @return string
	 */
	public function trigger_value(): string {
		return $this->event->value();
	}

	/**
	 * ADR-0004 trigger identity.
	 *
	 * @return string
	 */
	public function trigger_identity(): string {
		return $this->event->identity();
	}

	/**
	 * Whether ADR-0008 deferral applies.
	 *
	 * @return bool
	 */
	public function deferred(): bool {
		return $this->deferred;
	}

	/**
	 * Result-level reason code, or '' when there is none.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Every decision, in evaluation order.
	 *
	 * @return MatchDecision[]
	 */
	public function decisions(): array {
		return $this->decisions;
	}

	/**
	 * The actionable subset: decisions that matched, in evaluation order.
	 *
	 * @return MatchDecision[]
	 */
	public function actionable(): array {
		return array_values(
			array_filter(
				$this->decisions,
				static function ( MatchDecision $decision ): bool {
					return $decision->matched();
				}
			)
		);
	}

	/**
	 * Decisions ADR-0005 records in normal operation.
	 *
	 * @return MatchDecision[]
	 */
	public function loggable_decisions(): array {
		return array_values(
			array_filter(
				$this->decisions,
				static function ( MatchDecision $decision ): bool {
					return $decision->loggable();
				}
			)
		);
	}

	/**
	 * One rule's decision.
	 *
	 * @param int $rule_id Rule id.
	 * @return MatchDecision|null
	 */
	public function decision_for( int $rule_id ): ?MatchDecision {
		foreach ( $this->decisions as $decision ) {
			if ( $rule_id === $decision->rule_id() ) {
				return $decision;
			}
		}
		return null;
	}

	/**
	 * Reason codes in evaluation order, keyed by rule id. Handy for asserting
	 * an exact decision sequence.
	 *
	 * @return array<int,string>
	 */
	public function reason_sequence(): array {
		$sequence = array();
		foreach ( $this->decisions as $decision ) {
			$sequence[ $decision->rule_id() ] = $decision->reason();
		}
		return $sequence;
	}

	/**
	 * Item-level notes: line-item id => reason code.
	 *
	 * @return array<int,string>
	 */
	public function item_notes(): array {
		return $this->item_notes;
	}

	/**
	 * Line items whose product no longer resolves.
	 *
	 * @return int[]
	 */
	public function unavailable_item_ids(): array {
		return $this->item_ids_noted( MatchDecision::PRODUCT_UNAVAILABLE );
	}

	/**
	 * Line items kept but degraded: the variation is gone, its parent survives,
	 * and the item's type slug and flags are therefore unknown (ADR-0011 §4).
	 *
	 * These items DID evaluate — they simply cannot satisfy a `types` rule — so
	 * they are deliberately absent from self::unavailable_item_ids().
	 *
	 * @return int[]
	 */
	public function partially_resolved_item_ids(): array {
		return $this->item_ids_noted( MatchDecision::VARIATION_UNAVAILABLE );
	}

	/**
	 * Line-item ids carrying one note code.
	 *
	 * @param string $note Note code.
	 * @return int[]
	 */
	private function item_ids_noted( string $note ): array {
		$ids = array();
		foreach ( $this->item_notes as $item_id => $recorded ) {
			if ( $note === $recorded ) {
				$ids[] = (int) $item_id;
			}
		}
		return $ids;
	}

	/**
	 * Flat array form, for logging and assertions.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'order_id'         => $this->order_id,
			'trigger_type'     => $this->trigger_type(),
			'trigger_value'    => $this->trigger_value(),
			'trigger_identity' => $this->trigger_identity(),
			'deferred'         => $this->deferred,
			'reason'           => $this->reason,
			'decisions'        => array_map(
				static function ( MatchDecision $decision ): array {
					return $decision->to_array();
				},
				$this->decisions
			),
			'item_notes'       => $this->item_notes,
		);
	}
}
