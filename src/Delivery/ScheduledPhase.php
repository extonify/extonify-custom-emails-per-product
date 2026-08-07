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
	 * ⚠ `consolidation` IS NO LONGER A REASON TO DISOWN A RULE (ADR-0016 §8). It used
	 * to be the only unimplemented-behaviour column, so a rule carrying `daily` had to
	 * be left entirely untouched by every phase including this one. Prompt 8 implements
	 * consolidation, so **a delayed `per_product` rule is a rule this phase owns**: one
	 * job, fanning out at execution (ADR-0016 §8), never N jobs.
	 *
	 * Delay and consolidation are still different features — a delayed delivery is not
	 * an implementation of a consolidated one — and they now COMPOSE rather than
	 * exclude. What `daily` used to be excluded FOR is handled one layer earlier: it
	 * cannot be stored (ADR-0016 §1).
	 *
	 * THE MID-DELAY CHANGE IS `ScheduledDelivery::still_in_phase()`'S JOB, not this
	 * one's, and the asymmetry is deliberate — see the class docblock in
	 * `ScheduledCancellation`. This predicate asks only "could this rule still schedule
	 * at all"; whether the merchant changed its consolidation SINCE a particular
	 * delivery was queued is per-delivery and needs that delivery's snapshot.
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

		if ( ! Consolidation::has_valid_value( $rule ) ) {
			/*
			 * ⚠ THE READ BOUNDARY (ADR-0016 §1a). A rule whose `consolidation` is
			 * outside the vocabulary is CORRUPT DATA and is not deliverable by ANY
			 * phase — the immediate one now refuses it too, and this comment used to
			 * say the opposite.
			 *
			 * Queueing it would be worse than pointless: the snapshot would carry a
			 * value `DeliverySnapshot::read()` refuses, so the job would run hours later
			 * only to cancel itself `snapshot_unreadable`. Refusing here says the same
			 * thing immediately, and — the reason it must be BEFORE evaluation — an
			 * invalid rule that never enters the matcher can never halt a supported one
			 * through `stop_processing`.
			 */
			return false;
		}

		return Orchestrator::behaviour_is_implemented( $rule );
	}
}
