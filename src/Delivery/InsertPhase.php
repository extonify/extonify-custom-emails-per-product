<?php
/**
 * Insert-mode evaluation and recording (ADR-0013).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

use Extonify\WCEP\Domain\MatchDecision;
use Extonify\WCEP\Domain\PreparedRule;
use Extonify\WCEP\Domain\Specificity;
use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Matching\ItemResolver;
use Extonify\WCEP\Repository\RuleRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which rules' content goes into the WooCommerce email that is rendering,
 * and records what went.
 *
 * TWO THINGS MAKE THIS DIFFERENT FROM `Orchestrator`, AND BOTH ARE ADR-0013 §1:
 *
 *   1. **Evaluation happens once, at frame push**, and the answer is stored on
 *      the frame. Five injection positions read it; none of them evaluates.
 *      Evaluating per position would run the matcher five times for one answer
 *      and let the positions disagree if a rule were edited mid-render.
 *   2. **The claim is INVERTED.** Separate mode claims before sending because
 *      the claim decides whether to send. Nothing here decides anything —
 *      WooCommerce is sending its own email regardless, and our content is
 *      already inside it by the time the outcome is known. So the record is
 *      written at FINALIZATION and is a LOG, never a gate.
 *
 * THE PHASE FILTER'S GUARANTEE IS UNCHANGED (ADR-0012 §9). This phase admits
 * `insert` + `delay_seconds = 0` and nothing else; a delayed rule of either mode
 * still belongs to Prompt 6 and is still left entirely untouched by both phases.
 * `delay_seconds = 0` is part of the fetch's WHERE clause rather than a filter
 * applied afterwards, so a rule from another phase is never even loaded.
 */
class InsertPhase {

	/**
	 * Rule storage.
	 *
	 * @var RuleRepository
	 */
	private $rules;

	/**
	 * Line-item resolver, holding the request's PRODUCT cache.
	 *
	 * @var ItemResolver
	 */
	private $items;

	/**
	 * Identity consumption and logging policy.
	 *
	 * @var DeliveryLogger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param RuleRepository|null $rules  Rule storage.
	 * @param ItemResolver|null   $items  Item resolver.
	 * @param DeliveryLogger|null $logger Delivery logger.
	 */
	public function __construct( ?RuleRepository $rules = null, ?ItemResolver $items = null, ?DeliveryLogger $logger = null ) {
		$this->rules  = null !== $rules ? $rules : new RuleRepository();
		$this->items  = null !== $items ? $items : new ItemResolver();
		$this->logger = null !== $logger ? $logger : new DeliveryLogger();
	}

	/**
	 * Whether insert mode may run at all.
	 *
	 * @return bool
	 */
	public static function is_operational(): bool {
		return Migrator::is_operational();
	}

	/**
	 * Which rules' content belongs in this render, in injection order.
	 *
	 * Called ONCE per render, at frame push (ADR-0013 §3).
	 *
	 * @param string    $native_email_id WooCommerce email id being rendered.
	 * @param \WC_Order $order           Order being rendered.
	 * @return array[] Each `{rule, decision}`, ordered by ADR-0011 specificity.
	 */
	public function evaluate( string $native_email_id, \WC_Order $order ): array {
		if ( ! self::is_operational() || '' === $native_email_id ) {
			return array();
		}

		$rows = $this->rules->find_active_for_native_email( $native_email_id );

		if ( array() === $rows ) {
			return array();
		}

		$resolved = $this->items->resolve_order( $order );

		if ( 0 === (int) $resolved['item_count'] ) {
			/*
			 * NO ADR-0008 DEFERRAL HERE, AND THAT IS NOT AN OVERSIGHT. Deferral
			 * exists because a separate-mode trigger can be re-asked later. This
			 * render is happening NOW: WooCommerce is composing the message in
			 * this call stack, and there is no later moment at which content
			 * could still be inserted into it.
			 */
			return array();
		}

		return $this->decide( $rows, $resolved['items'] );
	}

	/**
	 * Walk the prepared rules in ADR-0011 order, honouring `stop_processing`.
	 *
	 * The ordering code is `Domain\PreparedRule` and `Domain\Specificity`,
	 * unchanged — insert mode gets no ordering of its own (ADR-0013 §7).
	 *
	 * @param array[] $rows  Rule rows.
	 * @param array[] $items Resolved item descriptors.
	 * @return array[] Matched `{rule, decision}` entries, in injection order.
	 */
	private function decide( array $rows, array $items ): array {
		$prepared = Specificity::sort( PreparedRule::from_rows( $rows ) );
		$matched  = array();

		foreach ( $prepared as $rule ) {
			if ( ! $rule->is_active() ) {
				continue;
			}

			$targeting = $rule->targeting();

			if ( ! $targeting->is_valid() ) {
				continue;
			}

			$hits = array();

			foreach ( $items as $item ) {
				$outcome = $targeting->evaluate( $item );

				if ( $outcome['matched'] ) {
					$hits[] = array(
						'item_id'      => (int) $item['item_id'],
						'product_id'   => (int) $item['product_id'],
						'variation_id' => (int) $item['variation_id'],
					);
				}
			}

			if ( array() === $hits ) {
				continue;
			}

			$matched[] = array(
				'rule'          => $rule->row(),
				'rule_id'       => $rule->id(),
				'matched_items' => $hits,
			);

			if ( $rule->stops_processing() ) {
				// Halts WITHIN THIS PHASE ONLY (ADR-0013 §7).
				break;
			}
		}

		return $matched;
	}

	/**
	 * Record everything one finalized render inserted, under the outcome
	 * WooCommerce reported (ADR-0013 §1a).
	 *
	 * @param array $slot Resolved slot from `Render\RenderLedger::finalize()`.
	 * @param bool  $sent Whether the mail callback reported success.
	 * @return array[] One structured write result per rule.
	 */
	public function record_sent( array $slot, bool $sent = true ): array {
		return $this->record( $slot, $sent ? DeliveryLogger::OUTCOME_SENT : DeliveryLogger::OUTCOME_FAILED );
	}

	/**
	 * Record a render whose send BEGAN and never reported its outcome
	 * (ADR-0013 §6).
	 *
	 * @param array $slot Slot taken from the shutdown sweep.
	 * @return array[] One structured write result per rule.
	 */
	public function record_unresolved( array $slot ): array {
		return $this->record( $slot, DeliveryLogger::OUTCOME_UNRESOLVED );
	}

	/**
	 * Record a render that never reached the send path at all (ADR-0013 §6a).
	 *
	 * NOT A DELIVERY ATTEMPT, AND THAT IS THE WHOLE DISTINCTION. A third party that
	 * calls `get_content()` and never sends has produced no message; recording that
	 * as a later `unresolved` attempt would, under the ADR-0013 §1a latest-attempt
	 * rule, report a delivery that DEMONSTRABLY SUCCEEDED as unresolved. The row is
	 * written so the merchant can see the abandoned render, and the tombstone's
	 * status is left to the latest genuine send attempt.
	 *
	 * @param array $slot Slot taken from the shutdown sweep.
	 * @return array[] One structured write result per rule.
	 */
	public function record_abandoned( array $slot ): array {
		return $this->record( $slot, DeliveryLogger::OUTCOME_ABANDONED );
	}

	/**
	 * Write one record per rule the slot carried.
	 *
	 * @param array  $slot    Slot.
	 * @param string $outcome One of the DeliveryLogger::OUTCOME_* codes.
	 * @return array[]
	 */
	private function record( array $slot, string $outcome ): array {
		$order_id = (int) ( $slot['order_id'] ?? 0 );
		$email_id = (string) ( $slot['email_id'] ?? '' );
		$results  = array();

		if ( $order_id <= 0 || '' === $email_id ) {
			return $results;
		}

		foreach ( (array) ( $slot['rules'] ?? array() ) as $entry ) {
			$rule_id  = (int) ( $entry['rule_id'] ?? 0 );
			$revision = (int) ( $entry['revision'] ?? 0 );
			$position = (string) ( $entry['position'] ?? '' );

			if ( $rule_id <= 0 ) {
				continue;
			}

			$results[ $rule_id ] = $this->logger->record_insert( $order_id, $rule_id, $email_id, $revision, $position, $outcome );
		}

		return $results;
	}

	/**
	 * The reason code a rule that matched nothing would carry.
	 *
	 * Insert mode does NOT log these: ADR-0005's noise floor applies here for the
	 * same reason it applies to separate mode — a rule that simply did not target
	 * this order's products is not an event.
	 *
	 * @return string
	 */
	public static function noise_floor_reason(): string {
		return MatchDecision::NO_TARGETING_MATCH;
	}

	/**
	 * The item resolver, so a caller can share one request cache.
	 *
	 * @return ItemResolver
	 */
	public function item_resolver(): ItemResolver {
		return $this->items;
	}
}
