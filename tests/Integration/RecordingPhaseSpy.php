<?php
/**
 * Test double for the shutdown sweep's reporting calls (gate 50b).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\InsertPhase;

defined( 'ABSPATH' ) || exit;

/**
 * An `InsertPhase` that records what the sweep asked it to record and writes nothing.
 *
 * ⚠ A DOUBLE RATHER THAN THE REAL PHASE, because gate 50b is about the SWEEP reaching
 * the reporting call — not about what the reporting writes, which
 * `InsertModeTest` covers against real rows. The fixture's slot names no real order, so
 * a real write would be asserting on a record nobody would ever have.
 */
final class RecordingPhaseSpy extends InsertPhase {

	/**
	 * Abandoned renders reported.
	 *
	 * @var int
	 */
	public $abandoned = 0;

	/**
	 * Unresolved sends reported.
	 *
	 * @var int
	 */
	public $unresolved = 0;

	/**
	 * Count an abandoned render.
	 *
	 * @param array $slot Slot.
	 * @return array
	 */
	public function record_abandoned( array $slot ): array {
		++$this->abandoned;

		return array();
	}

	/**
	 * Count an unresolved send.
	 *
	 * @param array $slot Slot.
	 * @return array
	 */
	public function record_unresolved( array $slot ): array {
		++$this->unresolved;

		return array();
	}
}
