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
	 * A delayed rule's identity was claimed, its snapshot stored and its job
	 * queued (ADR-0015 §1). Nothing has been sent yet, and that is the point:
	 * `SENT` on this run would claim a delivery that is still hours away.
	 */
	const SCHEDULED = 'scheduled';

	/**
	 * The matcher's account of the run, or null for a scheduled execution.
	 *
	 * @var EvaluationResult|null
	 */
	private $evaluation;

	/**
	 * One entry per rule the run acted on.
	 *
	 * @var array[]
	 */
	private $records = array();

	/**
	 * Whether this RUN deferred, as opposed to whether one evaluation asked to.
	 *
	 * ⚠ THE TWO STOPPED BEING THE SAME THING IN PROMPT 7 (ADR-0015 §7). A trigger
	 * now produces TWO evaluations — immediate and scheduled — and either may want
	 * to defer. `deferred()` read the immediate evaluation alone, so a zero-item
	 * order whose only rules are DELAYED queued a deferral and then reported
	 * `deferred() === false`: a return value contradicting what the run had just
	 * done. Recorded by the orchestrator at the moment it defers, so the answer
	 * describes the RUN rather than one of its parts.
	 *
	 * @var bool
	 */
	private $deferred = false;

	/**
	 * Constructor.
	 *
	 * ⚠ NULL FOR A SCHEDULED EXECUTION, AND THAT IS A REAL DISTINCTION RATHER THAN
	 * A CONVENIENCE (ADR-0015 §3). A delayed delivery's evaluation happened hours
	 * earlier, in a different request; this run EXECUTES a decision rather than
	 * making one, so there is no `EvaluationResult` to hand over. Manufacturing an
	 * empty one would let a caller read "nothing matched" from a run that is
	 * sending a message *because* something did.
	 *
	 * @param EvaluationResult|null $evaluation Matcher result this run delivered,
	 *                                          or null when the run is executing a
	 *                                          decision taken earlier.
	 */
	public function __construct( ?EvaluationResult $evaluation = null ) {
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
	 * @return EvaluationResult|null Null when this run executed a decision taken
	 *                               in an earlier request (ADR-0015 §3).
	 */
	public function evaluation(): ?EvaluationResult {
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
	 * Record that this run deferred under ADR-0008 (ADR-0015 §7).
	 *
	 * @return void
	 */
	public function mark_deferred(): void {
		$this->deferred = true;
	}

	/**
	 * Whether the run was deferred under ADR-0008 rather than delivered.
	 *
	 * EITHER PHASE'S evaluation can produce a deferral, so this reports what the
	 * RUN did — see self::$deferred. The evaluation is still consulted, so an
	 * outcome built by a caller that never reached the orchestrator's deferral
	 * branch still answers correctly.
	 *
	 * @return bool
	 */
	public function deferred(): bool {
		if ( $this->deferred ) {
			return true;
		}

		return null !== $this->evaluation && $this->evaluation->deferred();
	}

	/**
	 * Flat array form, for logging and assertions.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'evaluation'   => null === $this->evaluation ? null : $this->evaluation->to_array(),
			'records'      => $this->records,
			'sent'         => $this->count_of( self::SENT ),
			'failed'       => $this->count_of( self::FAILED ),
			'skipped'      => $this->count_of( self::SKIPPED ),

			/*
			 * ⚠ REPORTED, NOT MERELY DEFINED. `SCHEDULED` was declared in Prompt 7
			 * and then omitted here, so the flat form of a run that queued three
			 * delayed deliveries read `sent 0, failed 0, skipped 0` — indistinguishable
			 * from a run that did nothing at all. `claim_failed` joins it for the same
			 * reason: a run whose claims all failed sent nothing, and that is a
			 * different fact from having nothing to send.
			 */
			'scheduled'    => $this->count_of( self::SCHEDULED ),
			'claim_failed' => $this->count_of( self::CLAIM_FAILED ),
			'deferred'     => $this->deferred(),
			'shortfalls'   => count( $this->shortfalls() ),
		);
	}
}
