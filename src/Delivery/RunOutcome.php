<?php
/**
 * The per-run account of one trigger's deliveries (ADR-0012 §11).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

use Extonify\WCEP\Domain\EvaluationResult;

defined( 'ABSPATH' ) || exit;

/**
 * Everything one evaluation-and-delivery run did, including whether the log
 * actually recorded it.
 *
 * WHY THIS EXISTS. `DeliveryLogger` already returns a structured
 * `{success, rows_expected, rows_written, finalized}` for every write, and the
 * orchestrator used to throw all of them away. The logger writes its own error
 * on a shortfall, so no email was ever mis-sent because of it — but the
 * orchestrator could not answer "did this run record what it did", which is
 * exactly the question the delivery-history phase will ask.
 *
 * WHY IT IS BUILT PER RUN AND PASSED DOWN THE STACK, NEVER HELD ON THE
 * ORCHESTRATOR (ADR-0012 §11). One `Orchestrator` serves a whole request, and a
 * send can re-enter the delivery lifecycle — a third-party callback on
 * `woocommerce_email_sent` that changes another order's status runs an inner
 * event to completion INSIDE the outer send. Any per-run value parked on the
 * shared object is therefore overwritten by the inner run before the outer loop
 * has finished reading it. This object is created in the run that owns it and
 * reaches every step as an argument, so nesting cannot reach it.
 *
 * Mutable during its own run and read afterwards; never shared between runs.
 */
final class RunOutcome {

	/**
	 * A rule's email reached the mailer and the mailer reported success.
	 */
	const SENT = 'sent';

	/**
	 * A rule's email was attempted and did not go out — the mailer reported
	 * failure, or the send threw.
	 */
	const FAILED = 'failed';

	/**
	 * The identity was claimed and deliberately not sent: no deliverable
	 * recipient, an ADR-0012 §2 claiming skip, or the per-delivery
	 * `woocommerce_email_enabled_{id}` filter rejecting this delivery.
	 */
	const SKIPPED = 'skipped';

	/**
	 * The identity could not be claimed, so nothing was sent (ADR-0012 §3).
	 */
	const CLAIM_FAILED = 'claim_failed';

	/**
	 * The matcher's account of the run.
	 *
	 * @var EvaluationResult
	 */
	private $evaluation;

	/**
	 * One entry per rule the run acted on.
	 *
	 * @var array[]
	 */
	private $records = array();

	/**
	 * Constructor.
	 *
	 * @param EvaluationResult $evaluation Matcher result this run delivered.
	 */
	public function __construct( EvaluationResult $evaluation ) {
		$this->evaluation = $evaluation;
	}

	/**
	 * Record what happened to one rule, and whether the log captured it.
	 *
	 * @param string $action      One of the action constants.
	 * @param int    $rule_id     Rule id.
	 * @param int    $delivery_id Tombstone id, or 0 when nothing was claimed.
	 * @param array  $result      `DeliveryLogger`'s structured write result.
	 * @return void
	 */
	public function record( string $action, int $rule_id, int $delivery_id, array $result ): void {
		$this->records[] = array(
			'action'        => $action,
			'rule_id'       => $rule_id,
			'delivery_id'   => $delivery_id,
			'recorded'      => (bool) ( $result['success'] ?? false ),
			'rows_expected' => (int) ( $result['rows_expected'] ?? 0 ),
			'rows_written'  => (int) ( $result['rows_written'] ?? 0 ),
			'finalized'     => (bool) ( $result['finalized'] ?? false ),
		);
	}

	/**
	 * The matcher's account of this run.
	 *
	 * @return EvaluationResult
	 */
	public function evaluation(): EvaluationResult {
		return $this->evaluation;
	}

	/**
	 * Every recorded action, in delivery order.
	 *
	 * @return array[]
	 */
	public function records(): array {
		return $this->records;
	}

	/**
	 * How many rules ended in one action.
	 *
	 * @param string $action One of the action constants.
	 * @return int
	 */
	public function count_of( string $action ): int {
		$count = 0;

		foreach ( $this->records as $record ) {
			if ( $action === $record['action'] ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Records whose write did not fully land.
	 *
	 * Each one is already an error in the WooCommerce log; collecting them is
	 * what lets a caller say "this run is not fully recorded" without re-reading
	 * the database.
	 *
	 * @return array[]
	 */
	public function shortfalls(): array {
		$shortfalls = array();

		foreach ( $this->records as $record ) {
			if ( ! $record['recorded'] ) {
				$shortfalls[] = $record;
			}
		}

		return $shortfalls;
	}

	/**
	 * Whether every action this run took was fully written to the log.
	 *
	 * @return bool
	 */
	public function is_fully_recorded(): bool {
		return array() === $this->shortfalls();
	}

	/**
	 * Whether the run was deferred under ADR-0008 rather than delivered.
	 *
	 * @return bool
	 */
	public function deferred(): bool {
		return $this->evaluation->deferred();
	}

	/**
	 * Flat array form, for logging and assertions.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'evaluation' => $this->evaluation->to_array(),
			'records'    => $this->records,
			'sent'       => $this->count_of( self::SENT ),
			'failed'     => $this->count_of( self::FAILED ),
			'skipped'    => $this->count_of( self::SKIPPED ),
			'shortfalls' => count( $this->shortfalls() ),
		);
	}
}
