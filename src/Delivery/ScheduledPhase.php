<?php
/**
 * The delayed phase: which rules it owns, and how one gets queued
 * (ADR-0007, ADR-0015 §1, §7).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

defined( 'ABSPATH' ) || exit;

/**
 * Separate-mode rules with a non-zero delay.
 *
 * THREE PHASES, DISJOINT BY CONSTRUCTION (ADR-0015 §7):
 *
 *     immediate  ->  separate + delay_seconds = 0
 *     scheduled  ->  separate + delay_seconds > 0     <- this class
 *     insert     ->  insert   + delay_seconds = 0
 *
 * The disjointness is asserted rather than assumed, because it is what stops a
 * rule being delivered twice — once inline and once from the queue — which is
 * the failure mode a phase split exists to prevent. `Orchestrator` owns the
 * immediate phase, `InsertPhase` the third; ADR-0013 §2 refuses the fourth
 * combination at the repository boundary, so `insert + delay > 0` cannot be
 * stored at all.
 *
 * ⚠ WHY THIS IS A PHASE AND NOT A BRANCH INSIDE `Orchestrator::deliver()`. The
 * filter runs BEFORE evaluation (ADR-0012 §9), so a delayed rule's
 * `stop_processing` flag cannot halt the immediate rules and vice versa. Deciding
 * per rule after evaluation could not undo a halt that had already changed every
 * later decision — the same reasoning that put insert mode behind its own filter.
 */
class ScheduledPhase {

	/**
	 * Keep only the rules THIS phase delivers.
	 *
	 * @param array[] $rules Candidate rule rows.
	 * @return array[] Rows this phase may schedule.
	 */
	public static function deliverable_in_this_phase( array $rules ): array {
		$deliverable = array();

		foreach ( $rules as $rule ) {
			if ( self::owns( $rule ) ) {
				$deliverable[] = $rule;
			}
		}

		return $deliverable;
	}

	/**
	 * Whether one rule belongs to the delayed phase.
	 *
	 * ⚠ `consolidation` IS STILL CHECKED (ADR-0015 §7). It remains the only
	 * unimplemented-behaviour column, and a rule carrying `daily` must be left
	 * ENTIRELY untouched by every phase — including this one. Delay and
	 * consolidation are different features, and a delayed delivery is not an
	 * implementation of a consolidated one.
	 *
	 * @param array $rule Rule row.
	 * @return bool
	 */
	public static function owns( array $rule ): bool {
		if ( DeliveryLogger::MODE !== (string) ( $rule['delivery_mode'] ?? '' ) ) {
			return false;
		}

		if ( (int) ( $rule['delay_seconds'] ?? 0 ) <= 0 ) {
			return false;
		}

		return Orchestrator::behaviour_is_implemented( $rule );
	}
}
