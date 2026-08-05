<?php
/**
 * The outcome of one guarded write (ADR-0015 §8.1a).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * WHY A GUARDED WRITE MAY NOT REPORT ITSELF AS A BOOLEAN.
 *
 * `UPDATE … WHERE id = %d AND final_status = %s` has three outcomes, not two,
 * and the two that a boolean merges are the two that need OPPOSITE handling:
 *
 *   - **changed**      — this caller moved the row and OWNS the outcome;
 *   - **lost_race**    — the row was not in the expected state, so somebody else
 *                        owns it. Exiting is correct: the delivery HAS an owner;
 *   - **query_failed** — nothing happened at all. **Nobody owns it**, and exiting
 *                        strands the delivery in the state it was already in;
 *   - **refused**      — the call was never attempted (an unusable id, a pair
 *                        outside the transition table). Like `query_failed` in
 *                        every way that matters to a caller: no write happened
 *                        and no other actor took responsibility.
 *
 * ⚠ THE MERGED BOOLEAN IS A DEFECT CLASS THIS PROJECT HAS NOW MET TWICE. Prompt 2A
 * split `claim()`'s outcomes apart for the same reason — a suppressed identity and
 * a failed query both returned "did not claim", and only one of them may proceed.
 * Prompt 7B found the same shape on the scheduled path, where three call sites read
 * `false` as "another actor won": a failed arm left the tombstone `claimed` with no
 * job, a failed lease UPDATE let the worker return while the row was still
 * `scheduled`, and a failed cancel transition unscheduled the job anyway. Returning
 * the fact instead of a boolean is what makes those three call sites able to differ.
 *
 * Immutable, dependency-free and unit-testable: it is a fact about a write, not a
 * behaviour.
 */
final class WriteResult {

	/**
	 * Exactly one row moved, and THIS caller moved it.
	 */
	const CHANGED = 'changed';

	/**
	 * The statement ran and matched nothing: the row is not in `$from`.
	 *
	 * Another actor got there first, or the row is gone. Either way it is not
	 * this caller's to finish.
	 */
	const LOST_RACE = 'lost_race';

	/**
	 * The statement itself failed. The row is untouched and unowned.
	 */
	const QUERY_FAILED = 'query_failed';

	/**
	 * The write was refused before it was attempted — an unusable id, or a
	 * transition outside the permitted table.
	 */
	const REFUSED = 'refused';

	/**
	 * Every outcome, for validation and for the state-machine gate.
	 */
	const OUTCOMES = array( self::CHANGED, self::LOST_RACE, self::QUERY_FAILED, self::REFUSED );

	/**
	 * One of self::OUTCOMES.
	 *
	 * @var string
	 */
	private $outcome;

	/**
	 * What was being attempted, for the log line a caller writes.
	 *
	 * @var string
	 */
	private $detail;

	/**
	 * Private: instances come from the named constructors, so an outcome outside
	 * self::OUTCOMES cannot exist.
	 *
	 * @param string $outcome One of self::OUTCOMES.
	 * @param string $detail  Short description of the attempted write.
	 */
	private function __construct( string $outcome, string $detail ) {
		$this->outcome = $outcome;
		$this->detail  = $detail;
	}

	/**
	 * Exactly one row changed and this caller changed it.
	 *
	 * @param string $detail Short description of the attempted write.
	 * @return self
	 */
	public static function changed( string $detail = '' ): self {
		return new self( self::CHANGED, $detail );
	}

	/**
	 * The statement ran and matched no row in the expected state.
	 *
	 * @param string $detail Short description of the attempted write.
	 * @return self
	 */
	public static function lost( string $detail = '' ): self {
		return new self( self::LOST_RACE, $detail );
	}

	/**
	 * The statement failed. Nothing was written and nobody else acted.
	 *
	 * @param string $detail Short description of the attempted write.
	 * @return self
	 */
	public static function failed( string $detail = '' ): self {
		return new self( self::QUERY_FAILED, $detail );
	}

	/**
	 * The write was never attempted.
	 *
	 * @param string $detail Short description of the attempted write.
	 * @return self
	 */
	public static function refused( string $detail = '' ): self {
		return new self( self::REFUSED, $detail );
	}

	/**
	 * The outcome code.
	 *
	 * @return string One of self::OUTCOMES.
	 */
	public function outcome(): string {
		return $this->outcome;
	}

	/**
	 * What was being attempted.
	 *
	 * @return string
	 */
	public function detail(): string {
		return $this->detail;
	}

	/**
	 * THIS caller changed the row and owns what happens next.
	 *
	 * @return bool
	 */
	public function won(): bool {
		return self::CHANGED === $this->outcome;
	}

	/**
	 * Somebody else owns the row.
	 *
	 * @return bool
	 */
	public function is_lost_race(): bool {
		return self::LOST_RACE === $this->outcome;
	}

	/**
	 * The statement failed outright.
	 *
	 * @return bool
	 */
	public function is_query_failed(): bool {
		return self::QUERY_FAILED === $this->outcome;
	}

	/**
	 * The write was refused before it ran.
	 *
	 * @return bool
	 */
	public function is_refused(): bool {
		return self::REFUSED === $this->outcome;
	}

	/**
	 * NOTHING HAPPENED AND NOBODY ELSE ACTED — the case a boolean hides.
	 *
	 * The predicate every caller that used to read `false` should be asking: a
	 * lost race is somebody else's success, and a shortfall is nobody's.
	 *
	 * @return bool
	 */
	public function is_shortfall(): bool {
		return self::QUERY_FAILED === $this->outcome || self::REFUSED === $this->outcome;
	}

	/**
	 * One line for a log.
	 *
	 * @return string
	 */
	public function describe(): string {
		return '' === $this->detail ? $this->outcome : $this->outcome . ' (' . $this->detail . ')';
	}
}
