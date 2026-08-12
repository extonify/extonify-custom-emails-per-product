<?php
/**
 * Identity consumption and delivery logging (ADR-0005, ADR-0012 §2, §3).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

use Extonify\WCEP\Domain\DeliveryIdentity;
use Extonify\WCEP\Domain\Json;
use Extonify\WCEP\Domain\MatchDecision;
use Extonify\WCEP\Domain\Text;
use Extonify\WCEP\Domain\WriteResult;
use Extonify\WCEP\Repository\DeliveryDetailRepository;
use Extonify\WCEP\Repository\DeliveryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that decides which decisions consume a delivery identity, and
 * the one place that writes what happened.
 *
 * WHY IT IS ONE CLASS. A claim permanently consumes
 * `order|rule|mode|trigger_identity` (ADR-0004). If two callers could each
 * decide when to claim, one of them would eventually claim for a
 * `blocked_by_stop_flag` and silently prevent a legitimate future send — the
 * failure mode ADR-0012 §2 exists to rule out. Routing every claim and every
 * log row through here makes the policy a property of the code rather than a
 * convention each caller re-derives.
 *
 * FAIL CLOSED (ADR-0012 §3). Only a `claimed` result proceeds to send. A
 * `suppressed` result records nothing beyond the atomic counter increment the
 * claim itself performed, and a `failed` result NEVER sends. There is no branch
 * in which an unrecognised claim outcome produces an email.
 */
class DeliveryLogger {

	/**
	 * Separate mode: this plugin composes and sends its own message (ADR-0012).
	 */
	const MODE_SEPARATE = 'separate';

	/**
	 * The delivery mode Prompt 5 implements: content injected into WooCommerce's
	 * own email (ADR-0013).
	 */
	const MODE_INSERT = 'insert';

	/**
	 * Separate mode, under its original name.
	 *
	 * Kept as an alias so every separate-mode call site keeps its meaning
	 * unchanged now that a second mode exists (ADR-0013 §8).
	 */
	const MODE = self::MODE_SEPARATE;

	/**
	 * MESSAGE outcomes (ADR-0013 §1a, §6a, ADR-0014 §10c).
	 *
	 * FOUR, AND NO TWO OF THEM ARE SYNONYMS. `sent` and `failed` are both things
	 * WooCommerce REPORTED; `unresolved` is the absence of a report about a send
	 * that DID begin; `abandoned` is a render that never reached a send at all.
	 * Collapsing any pair asserts something nobody observed — and collapsing
	 * `abandoned` into `unresolved` is the specific error that reported a
	 * demonstrably successful delivery as unresolved, because a third party
	 * rendered the same email again afterwards and threw the render away.
	 *
	 * ⚠ THESE DESCRIBE THE MESSAGE. WHETHER ONE RULE'S CONTENT GOT INTO IT IS A
	 * SECOND, INDEPENDENT FACT — see self::RENDER_OUTCOMES.
	 */
	const OUTCOME_SENT       = 'sent';
	const OUTCOME_FAILED     = 'failed';
	const OUTCOME_UNRESOLVED = DeliveryDetailRepository::UNRESOLVED;
	const OUTCOME_ABANDONED  = DeliveryDetailRepository::ABANDONED;

	/**
	 * Every outcome an insert record may carry FOR THE MESSAGE.
	 *
	 * ⚠ `not_rendered` IS DELIBERATELY NOT A MEMBER, AND REMOVING IT IS THE PROMPT 6B
	 * CORRECTION. It used to be the fifth member and it OVERWROTE the message
	 * outcome, so a rule whose resolution threw during an ABANDONED render was
	 * recorded as `not_rendered` — stored `failed`, which
	 * `DeliveryDetailRepository::GENUINE_ATTEMPT_STATES` counts as a real send
	 * attempt. The merchant's next, FIRST, genuine delivery was then typed `resend`:
	 * exactly the ADR-0013 §6b defect Prompt 5C fixed, reintroduced through a
	 * different column. The reason text was untrue as well, always ending "and the
	 * native email was sent without it" for messages that were never sent.
	 */
	const INSERT_OUTCOMES = array(
		self::OUTCOME_SENT,
		self::OUTCOME_FAILED,
		self::OUTCOME_UNRESOLVED,
		self::OUTCOME_ABANDONED,
	);

	/**
	 * RULE RENDER outcomes (ADR-0014 §10c).
	 *
	 * PER RULE, NOT PER MESSAGE, AND ORTHOGONAL TO self::INSERT_OUTCOMES. This says
	 * whether ONE rule's content reached the message the other axis describes; the
	 * two are recorded side by side and neither is ever derived from the other.
	 * `not_rendered` means resolving that rule threw and the injector emitted
	 * nothing — whatever then became of the message it would have gone into.
	 */
	const RENDER_RENDERED     = 'rendered';
	const RENDER_NOT_RENDERED = 'not_rendered';

	/**
	 * Both render outcomes.
	 */
	const RENDER_OUTCOMES = array(
		self::RENDER_RENDERED,
		self::RENDER_NOT_RENDERED,
	);

	/**
	 * Non-matching decisions that DO consume an identity (ADR-0012 §2).
	 *
	 * Each is per-rule, rare, and deterministic for that identity: an excluded
	 * rule stays excluded, an invalid targeting document stays invalid, a
	 * disabled rule was disabled. ADR-0004 already fires once per order per rule
	 * per identity, so consuming it loses nothing that would otherwise have
	 * happened — and recording it is what lets the log answer "why did rule X
	 * not send".
	 *
	 * `blocked_by_stop_flag` is POINTEDLY ABSENT — see self::consumes_identity().
	 */
	const CLAIMING_SKIPS = array(
		MatchDecision::EXCLUDED_BY_RULE,
		MatchDecision::TARGETING_INVALID,
		MatchDecision::RULE_DISABLED,
	);

	/**
	 * Tombstone repository.
	 *
	 * @var DeliveryRepository
	 */
	private $deliveries;

	/**
	 * Detail repository.
	 *
	 * @var DeliveryDetailRepository
	 */
	private $details;

	/**
	 * Constructor.
	 *
	 * @param DeliveryRepository|null       $deliveries Tombstone storage.
	 * @param DeliveryDetailRepository|null $details    Detail storage.
	 */
	public function __construct( ?DeliveryRepository $deliveries = null, ?DeliveryDetailRepository $details = null ) {
		$this->deliveries = null !== $deliveries ? $deliveries : new DeliveryRepository();
		$this->details    = null !== $details ? $details : new DeliveryDetailRepository();
	}

	/**
	 * Whether a decision consumes a delivery identity (ADR-0012 §2).
	 *
	 * `blocked_by_stop_flag` returns FALSE, and that is the load-bearing case. A
	 * blocked rule never got the chance to match, so:
	 *
	 *   - claiming it would mean that removing the stop flag could never make
	 *     that rule fire for that order again — a silent permanent loss, with
	 *     the merchant's fix appearing to do nothing; and
	 *   - in a fifty-rule store one halt would write forty-odd permanent
	 *     tombstones per status change, for rules that did not run.
	 *
	 * The halt is recorded on the STOP RULE's own detail row instead — one row,
	 * and "why didn't rule X send" is still answerable.
	 *
	 * `no_targeting_match` returns FALSE too: ADR-0005's noise floor, which is
	 * what keeps the log bounded.
	 *
	 * @param string $reason MatchDecision reason code.
	 * @return bool
	 */
	public static function consumes_identity( string $reason ): bool {
		return MatchDecision::MATCHED === $reason || in_array( $reason, self::CLAIMING_SKIPS, true );
	}

	/**
	 * Claim a delivery identity.
	 *
	 * @param int    $order_id  Order id.
	 * @param int    $rule_id   Rule id.
	 * @param string $identity  ADR-0004 trigger identity.
	 * @param int    $revision  Rule revision, audit only.
	 * @return array{result:string,delivery_id:int,identity_hash:string}
	 */
	public function claim( int $order_id, int $rule_id, string $identity, int $revision = 0 ): array {
		return $this->deliveries->claim( $order_id, $rule_id, self::MODE, $identity, $revision );
	}

	/**
	 * The shape every recording method returns.
	 *
	 * WHY A STRUCTURED RESULT AND NOT `void`. These methods used to ignore both
	 * the insert result and `set_final_status()`, so a sent email whose detail
	 * rows all failed to insert was finalised `sent` with NO record of who
	 * received it — invisible to delivery history and, worse, to the privacy
	 * exporter and eraser, which find rows by recipient. A finalisation failure
	 * left the tombstone `claimed` forever while the consumed identity blocked
	 * every retry. Both failures were silent, and both are now reported.
	 *
	 * ⚠ `finalized` IS THE KEY A LIFECYCLE CALLER MUST GATE ON, NOT `success`
	 * (ADR-0015 §8.1a). It answers "does the tombstone hold a terminal status now",
	 * which is the question "may I remove this delivery's job" reduces to. `success`
	 * is stricter and different — it also requires every DETAIL row to have been
	 * written — and gating an unschedule on it would leave a live job behind for a
	 * delivery that really is terminal.
	 *
	 * `transition` carries the underlying `WriteResult` when there was one, so a
	 * caller can tell a lost race from a failed write without a second read.
	 *
	 * @param bool             $success       Whether everything intended was written.
	 * @param int              $rows_expected Rows that should have been written.
	 * @param int              $rows_written  Rows actually written.
	 * @param bool             $finalized     Whether the tombstone holds a terminal status.
	 * @param WriteResult|null $transition    The guarded write's own outcome, when there was one.
	 * @return array{success:bool,rows_expected:int,rows_written:int,finalized:bool,transition:WriteResult|null}
	 */
	private static function result( bool $success, int $rows_expected, int $rows_written, bool $finalized, ?WriteResult $transition = null ): array {
		return array(
			'success'       => $success,
			'rows_expected' => $rows_expected,
			'rows_written'  => $rows_written,
			'finalized'     => $finalized,
			'transition'    => $transition,
		);
	}

	/**
	 * Record a guarded write that did NOT take ownership, as its own diagnostic
	 * (ADR-0015 §8.1a, Prompt 7B Tier 2).
	 *
	 * ⚠ NOT AS THE DELIVERY'S OUTCOME. A recorder that loses the transition has
	 * learned something about ITS ATTEMPT, not about the delivery — the delivery's
	 * outcome belongs to whoever won — so this writes to the log and never to the
	 * attempt rows.
	 *
	 * Two levels, because the two facts differ in kind: a lost race is the
	 * concurrency design working, and a failed or refused write is a delivery that
	 * may now be owed to nobody.
	 *
	 * @param int              $delivery_id Tombstone id.
	 * @param string           $what        What was being recorded.
	 * @param WriteResult|null $write       The write that did not win.
	 * @return void
	 */
	private function record_lost_transition( int $delivery_id, string $what, ?WriteResult $write ): void {
		$described = $write instanceof WriteResult ? $write->describe() : 'no write attempted';

		if ( null === $write || $write->is_shortfall() ) {
			$this->log_error(
				'delivery #' . $delivery_id . ': ' . $what . ' could not be written (' . $described
					. '), so nothing was recorded and no other actor took ownership of this delivery'
			);
			return;
		}

		$this->log_notice(
			'delivery #' . $delivery_id . ': ' . $what . ' did not take ownership (' . $described
				. '), so its outcome was left to whoever did'
		);
	}

	/**
	 * The shape self::finalize() reports, for callers that did not attempt one.
	 *
	 * @param bool             $terminal Whether the tombstone holds a terminal status.
	 * @param WriteResult|null $write    The guarded write's outcome, when there was one.
	 * @return array{write:WriteResult|null,terminal:bool}
	 */
	private static function finalization( bool $terminal, ?WriteResult $write = null ): array {
		return array(
			'write'    => $write,
			'terminal' => $terminal,
		);
	}

	/**
	 * Write a tombstone's terminal status through the right mechanism for its
	 * path (ADR-0015 §8.1).
	 *
	 * ⚠ TWO MECHANISMS, AND WHICH ONE IS CORRECT IS A PROPERTY OF THE CALLER, NOT
	 * OF THE STATUS. The immediate path owns its tombstone from `claimed` to
	 * terminal inside one request with no second actor, so an unconditional write
	 * is right. A delayed one is contended by definition — a queue worker, a
	 * merchant disabling the rule, a stale-lease sweep — so every write must be
	 * conditional on the state the caller believes it is leaving, or a cancellation
	 * can overwrite `sent` and a send can overwrite `cancelled`.
	 *
	 * A caller on the scheduled path passes `$from`. **Passing null there is the
	 * defect this method exists to make visible**, which is why the scheduled
	 * entry points take it as a REQUIRED argument and carry it in the claim array
	 * rather than defaulting it.
	 *
	 * A LOST TRANSITION IS NOT AUTOMATICALLY A SHORTFALL. A guarded write can fail
	 * to change a row because somebody else finalised it first, and that delivery
	 * HAS an outcome — so a terminal row counts as finalised even when this caller
	 * was not the one who finalised it. **A failed QUERY is a different fact**, and
	 * the returned `write` keeps it distinguishable: `terminal` then stays false,
	 * because the row really is still pending and nobody owns it.
	 *
	 * @param int         $delivery_id Tombstone id.
	 * @param string      $status      Terminal status to record.
	 * @param string|null $from        State being left, or null for the immediate path.
	 * @param int         $revision    Rule revision, audit only.
	 * @return array{write:WriteResult|null,terminal:bool} `terminal` says whether the
	 *         tombstone holds a terminal status afterwards.
	 */
	private function finalize( int $delivery_id, string $status, ?string $from, int $revision = 0 ): array {
		if ( null === $from ) {
			$written = $this->deliveries->set_final_status( $delivery_id, $status, $revision );

			return self::finalization(
				$written,
				$written ? WriteResult::changed( 'set ' . $status ) : WriteResult::failed( 'set ' . $status )
			);
		}

		$write = $this->deliveries->transition( $delivery_id, $from, $status, $revision );

		if ( $write->won() ) {
			return self::finalization( true, $write );
		}

		// ⚠ THE READ IS WHAT SEPARATES "SOMEBODY ELSE FINISHED IT" FROM "NOTHING
		// HAPPENED". Both arrive here as a non-winning write, and only the row can
		// say which — including after a query failure, where the honest answer is
		// almost always "still pending", which is what `is_terminal()` reports.
		return self::finalization( $this->deliveries->is_terminal( $delivery_id ), $write );
	}

	/**
	 * The state a claim array says its delivery is leaving (ADR-0015 §8.1).
	 *
	 * The scheduled path puts `transition_from` in the claim it hands to
	 * `Orchestrator::send()`, so every recording call reached through that shared
	 * code knows which mechanism to use without the shared code having to know
	 * which phase invoked it.
	 *
	 * @param array $claim Claim result.
	 * @return string|null Null for the immediate path.
	 */
	public static function transition_from( array $claim ): ?string {
		$from = (string) ( $claim['transition_from'] ?? '' );

		return '' === $from ? null : $from;
	}

	/**
	 * The attempt type a claim array declares (ADR-0019 §8).
	 *
	 * ⚠ CARRIED ON THE CLAIM FOR THE SAME REASON `transition_from` IS. `send()` and
	 * `attempt()` serve the immediate, delayed and manual paths, and each writes a
	 * different attempt type — putting it on the claim means the shared code does the
	 * right thing without knowing which caller invoked it, and its absence is what
	 * marks an automatic delivery.
	 *
	 * @param array $claim Claim result.
	 * @return string One of `DeliveryDetailRepository::TYPES`.
	 */
	public static function attempt_type( array $claim ): string {
		$type = (string) ( $claim['attempt_type'] ?? '' );

		return '' === $type ? 'auto' : $type;
	}

	/**
	 * The rule revision a claim array says this attempt is sending.
	 *
	 * ⚠ A MANUAL SEND RENDERS FROM THE CURRENT RULE (ADR-0019 §3), so the tombstone's
	 * `rule_revision_sent` has to move with it. An automatic delivery records its
	 * revision at claim time and passes 0 here, which `set_final_status()` and
	 * `transition()` both read as "leave it alone".
	 *
	 * @param array $claim Claim result.
	 * @return int
	 */
	public static function attempt_revision( array $claim ): int {
		return max( 0, (int) ( $claim['rule_revision_sent'] ?? 0 ) );
	}

	/**
	 * Verify a write outcome and report any shortfall.
	 *
	 * @param int    $delivery_id   Tombstone id.
	 * @param string $what          What was being recorded.
	 * @param int    $rows_expected Rows that should have been written.
	 * @param int    $rows_written  Rows actually written.
	 * @param array  $finalization  self::finalize()'s report.
	 * @return array Structured result.
	 */
	private function verify( int $delivery_id, string $what, int $rows_expected, int $rows_written, array $finalization ): array {
		$finalized = (bool) ( $finalization['terminal'] ?? false );
		$write     = $finalization['write'] ?? null;
		$success   = ( $rows_written === $rows_expected ) && $finalized;

		if ( ! $success ) {
			$this->log_error(
				sprintf(
					'delivery #%d: %s was not fully recorded (%d of %d detail rows written, tombstone %s%s). '
					. 'The delivery identity is consumed, so this cannot be retried automatically.',
					$delivery_id,
					$what,
					$rows_written,
					$rows_expected,
					$finalized ? 'finalized' : 'NOT finalized',
					$write instanceof WriteResult ? ', status write ' . $write->describe() : ''
				)
			);
		}

		return self::result( $success, $rows_expected, $rows_written, $finalized, $write instanceof WriteResult ? $write : null );
	}

	/**
	 * Record a decision that did not send but does consume its identity.
	 *
	 * @param array  $claim  Claim result from self::claim().
	 * @param string $reason Reason code plus any resolution detail.
	 * @param array  $extra  Optional extra detail-row fields (`snapshot`).
	 * @return array Structured result.
	 */
	public function record_skip( array $claim, string $reason, array $extra = array() ): array {
		if ( DeliveryRepository::CLAIMED !== ( $claim['result'] ?? '' ) ) {
			// SUPPRESSED writes nothing: the identity was already recorded, and
			// the atomic counter increment already happened inside claim().
			// FAILED is reported by self::record_claim_failure().
			return self::result( true, 0, 0, true );
		}

		$delivery_id = (int) $claim['delivery_id'];

		$written = $this->details->insert(
			$delivery_id,
			array_merge(
				array(
					'type'   => 'auto',
					'state'  => 'skipped',
					'reason' => Text::log_value( $reason ),
				),
				$extra
			)
		) > 0 ? 1 : 0;

		// ADR-0015 §8.1: `executing -> skipped` when the claim carries a
		// `transition_from`, an unconditional write when it does not. A delayed
		// delivery reaches here from inside its lease — ADR-0012 §2's claiming
		// skips are discovered during the send, not before it.
		$finalized = $this->finalize( $delivery_id, 'skipped', self::transition_from( $claim ) );

		return $this->verify( $delivery_id, 'a skip', 1, $written, $finalized );
	}

	/**
	 * Arm a freshly-claimed delayed delivery: store the snapshot, set the
	 * tombstone `scheduled` (ADR-0015 §1, §2).
	 *
	 * NO DETAIL ROW YET. Nothing has been attempted — the attempt row belongs to
	 * the send, hours from now — and writing one here would put a `scheduled` row
	 * in the PURGEABLE store, where retention would delete it before the delivery
	 * ran. The tombstone's own `scheduled` status is the record that a delivery is
	 * owed; self::record_schedule_outcome() adds the diagnostic row.
	 *
	 * ⚠ IT REPORTS *WHY* IT DID NOT ARM (ADR-0015 §8.1a). A `false` return meant
	 * both "another actor armed this first" and "the UPDATE failed", and the caller
	 * treated the pair as an inert skip — so a plain database failure left the
	 * identity consumed, the tombstone `claimed`, no job, no detail row, nothing for
	 * the maintenance sweep to find, and every later trigger for that delivery
	 * silently suppressed. The caller now terminalises the first case and lets the
	 * second alone.
	 *
	 * @param array $claim         Claim result; must be CLAIMED.
	 * @param array $snapshot      Snapshot from `Domain\DeliverySnapshot::create()`.
	 * @param int   $scheduled_for UTC timestamp the job is queued for.
	 * @return WriteResult CHANGED when this call armed the row.
	 */
	public function record_scheduled( array $claim, array $snapshot, int $scheduled_for ): WriteResult {
		if ( DeliveryRepository::CLAIMED !== ( $claim['result'] ?? '' ) ) {
			return WriteResult::refused( 'arm a delivery that was not claimed' );
		}

		$armed = $this->deliveries->arm_scheduled( (int) $claim['delivery_id'], $snapshot );

		if ( ! $armed->won() ) {
			$this->log_error(
				'failed to arm delivery #' . (int) $claim['delivery_id'] . ' as scheduled for '
					. gmdate( 'Y-m-d H:i:s', $scheduled_for ) . ' UTC (' . $armed->describe() . '); nothing was queued'
			);
		}

		return $armed;
	}

	/**
	 * Terminalise a delivery whose ARM did not land (ADR-0015 §8.1a).
	 *
	 * ⚠ THE ROW IS STILL `claimed`, WHICH IS NOT A STATE THE SCHEDULED MACHINE OWNS.
	 * `claimed -> cancelled` is not in `DeliveryRepository::TRANSITIONS` and must not
	 * be: `claimed` is the immediate path's transient state, held by one actor inside
	 * one request. A delivery whose arm failed never entered the scheduled machine —
	 * that is precisely what failed — so the unconditional writer is the correct
	 * mechanism here, exactly as it is in self::record_schedule_throw(), and using it
	 * is not a breach of §8.1.
	 *
	 * ⚠ AND THE DETAIL ROW COMES AFTER THE STATUS WRITE (Prompt 7B Tier 2). Writing
	 * the outcome row first would leave a `cancelled` detail row on a tombstone that
	 * a concurrent actor had meanwhile finalised some other way.
	 *
	 * @param int    $delivery_id Tombstone id.
	 * @param string $why         What the arm reported.
	 * @return array Structured result.
	 */
	public function record_arm_failure( int $delivery_id, string $why ): array {
		if ( $delivery_id <= 0 ) {
			return self::result( false, 1, 0, false );
		}

		$this->log_error(
			'delivery #' . $delivery_id . ' was claimed but could NOT be armed as scheduled (' . $why . '); '
				. 'it is being closed so the consumed identity does not sit in a state nothing can reach'
		);

		$final = $this->finalize( $delivery_id, 'cancelled', null );

		if ( ! $final['terminal'] ) {
			return $this->verify( $delivery_id, 'a delayed delivery that could not be armed', 1, 0, $final );
		}

		$written = $this->details->insert(
			$delivery_id,
			array(
				'type'     => 'auto',
				'state'    => 'cancelled',
				'reason'   => Text::log_value(
					'this delayed delivery could not be armed for sending, so nothing was queued and it will not be sent'
				),
				'snapshot' => array(
					'cancelled' => array( 'reason_code' => ScheduledDelivery::REASON_ARM_FAILED ),
				),
			)
		) > 0 ? 1 : 0;

		return $this->verify( $delivery_id, 'a delayed delivery that could not be armed', 1, $written, $final );
	}

	/**
	 * Record that a delayed delivery reached the queue (ADR-0015 §6).
	 *
	 * A `scheduled` DETAIL row, so a merchant can see when the message is due and
	 * which action carries it. The tombstone keeps `scheduled` until the job runs.
	 *
	 * @param int   $delivery_id   Tombstone id.
	 * @param array $queued        Outcome from `ScheduledDelivery::schedule()`.
	 * @param int   $scheduled_for UTC timestamp the job runs at.
	 * @param int   $delay         Delay in seconds, for the audit.
	 * @return array Structured result.
	 */
	public function record_schedule_outcome( int $delivery_id, array $queued, int $scheduled_for, int $delay ): array {
		$written = $this->details->insert(
			$delivery_id,
			array(
				'type'     => 'auto',
				'state'    => 'scheduled',
				'reason'   => Text::log_value(
					ScheduledDelivery::describe( $queued ) . ' for ' . gmdate( 'Y-m-d H:i:s', $scheduled_for ) . ' UTC'
				),
				'snapshot' => array(
					'scheduled' => array(
						'delay_seconds' => $delay,
						'scheduled_for' => $scheduled_for,
						'action_id'     => (int) ( $queued['action_id'] ?? 0 ),
						'result'        => (string) ( $queued['result'] ?? '' ),
					),
				),
			)
		) > 0 ? 1 : 0;

		// ⚠ THE TOMBSTONE IS NOT FINALISED. `scheduled` is not a terminal state:
		// the delivery is owed, not done, and `arm_scheduled()` already set it.
		return $this->verify( $delivery_id, 'a scheduled delivery', 1, $written, self::finalization( true ) );
	}

	/**
	 * Record that a delayed delivery COULD NOT be queued (ADR-0015 §6).
	 *
	 * ⚠ TERMINAL, AND LOUD. The identity was consumed when the delivery was
	 * claimed, so a tombstone left `scheduled` with no job behind it would sit for
	 * ever in a state that reads as "in progress", with nothing in the queue for
	 * anyone to find. `cancelled` is the truthful end state, and the reason says
	 * plainly that the email will not happen — a delivery that silently never
	 * scheduled must never be mistaken for a duplicate correctly suppressed.
	 *
	 * @param int   $delivery_id Tombstone id.
	 * @param array $queued      Outcome from `ScheduledDelivery::schedule()`.
	 * @return array Structured result.
	 */
	public function record_schedule_failure( int $delivery_id, array $queued ): array {
		$this->log_error(
			'delivery #' . $delivery_id . ' was claimed but NOT queued: ' . ScheduledDelivery::describe( $queued )
				. '; this delayed email will not be sent'
		);

		return $this->record_scheduled_cancellation(
			$delivery_id,
			'schedule_failed',
			ScheduledDelivery::describe( $queued ),
			DeliveryRepository::SCHEDULED
		);
	}

	/**
	 * Cancel a scheduled delivery with its own truthful reason (ADR-0015 §4).
	 *
	 * ⚠ ONE REASON CODE PER CAUSE, NEVER A GENERIC "cancelled". The delivery log
	 * exists to answer *why didn't this send?*, and six causes with one reason
	 * makes it unable to. The code goes in the snapshot so a query can group by
	 * it; the sentence goes in `reason` so a merchant can read it.
	 *
	 * ⚠ `$from` IS REQUIRED, AND DELIBERATELY HAS NO DEFAULT (ADR-0015 §8.1). Every
	 * caller of this method is on the scheduled path, where an unconditional write
	 * would let a cancellation overwrite a `sent` tombstone. A default would be a
	 * value somebody could forget to think about, and the two possible answers —
	 * `scheduled` for the eager and lifecycle paths, `executing` for everything
	 * discovered under the lease — are not interchangeable.
	 *
	 * ⚠ OWNERSHIP FIRST, DETAIL ROW SECOND (Prompt 7B Tier 2). The two used to be the
	 * other way round, so a cancellation that LOST to a worker still wrote its
	 * `cancelled` detail row — leaving a `sent` tombstone carrying a row that says
	 * the delivery was cancelled, which is a log that contradicts itself about an
	 * email the customer has in their inbox. A lost transition is recorded as its own
	 * diagnostic instead: it is a fact about this attempt, not about the delivery.
	 *
	 * ⚠ `$type` EXISTS SO A MERCHANT'S CANCELLATION IS DISTINGUISHABLE FROM THE
	 * SCHEDULER'S (ADR-0019 §8). It defaults to `auto`, so every existing caller is
	 * unchanged; the admin cancel handler passes `manual`. Without it the delivery
	 * history would show a merchant's deliberate cancellation and an automatic
	 * re-validation cancellation as the same thing, which is exactly where the
	 * difference matters — and the alternative, a second cancellation path for the
	 * admin, is how two paths diverge.
	 *
	 * @param int    $delivery_id Tombstone id.
	 * @param string $code        Machine-readable cause.
	 * @param string $sentence    What to tell the merchant.
	 * @param string $from        State the tombstone is being moved OUT of.
	 * @param string $type        Attempt type for the row: `auto` or `manual`.
	 * @return array Structured result.
	 */
	public function record_scheduled_cancellation( int $delivery_id, string $code, string $sentence, string $from, string $type = 'auto' ): array {
		if ( $delivery_id <= 0 ) {
			return self::result( false, 1, 0, false );
		}

		// Releases the snapshot too — `cancelled` is terminal (ADR-0015 §2), and
		// the transition is conditional on `$from` (ADR-0015 §8.1).
		$final = $this->finalize( $delivery_id, 'cancelled', $from );
		$write = $final['write'] ?? null;

		if ( ! ( $write instanceof WriteResult ) || ! $write->won() ) {
			/*
			 * THIS CALL DID NOT CANCEL ANYTHING. Whatever the row holds now was
			 * decided by somebody else — or by nobody, if the write failed — and
			 * stamping a `cancelled` outcome row onto it would describe an outcome
			 * this call did not produce.
			 *
			 * The two are logged at DIFFERENT LEVELS on purpose: losing to a worker
			 * that sent the email is the design working, while a failed write is a
			 * delivery nobody owns and belongs in the same log as every other
			 * shortfall.
			 */
			$this->record_lost_transition( $delivery_id, 'a cancellation (' . $code . ')', $write );

			return self::result( false, 1, 0, (bool) $final['terminal'], $write instanceof WriteResult ? $write : null );
		}

		$written = $this->details->insert(
			$delivery_id,
			array(
				// Validated against `DeliveryDetailRepository::TYPES` at the storage
				// boundary, so an unrecognised value is refused rather than stored.
				'type'     => $type,
				'state'    => 'cancelled',
				'reason'   => Text::log_value( $sentence ),
				'snapshot' => array(
					'cancelled' => array( 'reason_code' => $code ),
				),
			)
		) > 0 ? 1 : 0;

		return $this->verify( $delivery_id, 'a cancelled delayed delivery (' . $code . ')', 1, $written, $final );
	}

	/**
	 * Record a throw that reached the SCHEDULING containment boundary
	 * (ADR-0015 §8.5).
	 *
	 * ⚠ THE TOMBSTONE CAN BE IN EITHER OF TWO STATES HERE, AND THE FIRST VERSION OF
	 * THIS CODE ONLY HANDLED ONE. `queue()` claims, then arms `claimed -> scheduled`,
	 * then queues. A throw AFTER the arm leaves a `scheduled` row, which
	 * `scheduled -> cancelled` finalises. A throw BEFORE or DURING the arm leaves the
	 * row `claimed` — and that transition then lands on nothing, stranding a consumed
	 * identity in a state with no job, no reason and nothing able to reach it. Found
	 * by this prompt's own boundary test, which threw from WordPress's `query` filter
	 * during the arming UPDATE.
	 *
	 * A `claimed` row has NOT entered the scheduled state machine: it is in exactly
	 * the state an immediate delivery occupies, with one actor and no contention, so
	 * the unconditional writer is the correct mechanism for it and using it here is
	 * not a breach of §8.1. The order below is what makes that safe — the guarded
	 * transition is tried FIRST, so a row that did reach `scheduled` is never written
	 * unconditionally.
	 *
	 * @param int    $delivery_id Tombstone id.
	 * @param string $message     What threw.
	 * @return array Structured result.
	 */
	public function record_schedule_throw( int $delivery_id, string $message ): array {
		if ( $delivery_id <= 0 ) {
			return self::result( false, 1, 0, false );
		}

		$this->log_error(
			'delivery #' . $delivery_id . ' was claimed but queueing THREW and nothing was queued; '
				. 'this delayed email will not be sent — ' . $message
		);

		$write = $this->deliveries->transition( $delivery_id, DeliveryRepository::SCHEDULED, 'cancelled' );
		$owned = $write->won();

		if ( ! $owned && ! $this->deliveries->is_terminal( $delivery_id ) ) {
			// Still `claimed`: the throw beat the arm. See the docblock.
			$owned = $this->deliveries->set_final_status( $delivery_id, 'cancelled' );
		}

		$final = self::finalization( $owned || $this->deliveries->is_terminal( $delivery_id ), $write );

		if ( ! $owned ) {
			// Somebody else finalised it, or nothing landed at all. Either way this
			// call did not produce the outcome, so it does not write one.
			$this->record_lost_transition( $delivery_id, 'a scheduling attempt that threw', $write );

			return self::result( false, 1, 0, (bool) $final['terminal'], $write );
		}

		$written = $this->details->insert(
			$delivery_id,
			array(
				'type'            => 'auto',
				'state'           => 'cancelled',
				'reason'          => Text::log_value(
					'queueing this delayed delivery threw and nothing was queued, so it will not be sent'
				),
				'failure_message' => Text::log_value( $message ),
				'snapshot'        => array(
					'cancelled' => array( 'reason_code' => 'schedule_threw' ),
				),
			)
		) > 0 ? 1 : 0;

		return $this->verify( $delivery_id, 'a scheduling attempt that threw', 1, $written, $final );
	}

	/**
	 * Record a throw that reached `ScheduledDelivery::run()`'s boundary.
	 *
	 * ⚠ THE THROW CAN LAND ON EITHER SIDE OF THE LEASE, AND THE TWO NEED DIFFERENT
	 * TRANSITIONS (ADR-0015 §8.1). A run that threw while holding the lease goes
	 * `executing -> failed`. One that threw before it could take the lease — the
	 * window is small but not empty — is still `scheduled`, and `scheduled ->
	 * failed` is not a permitted edge, so it goes `scheduled -> cancelled`: nothing
	 * was attempted, and the detail row carries the exception either way.
	 *
	 * Both are tried, in that order, rather than trusted from a flag alone: the
	 * caller's belief about whether it holds the lease can be wrong precisely when
	 * something threw.
	 *
	 * ⚠ AND THE DETAIL ROW IS WRITTEN AFTER ONE OF THEM LANDS (Prompt 7B Tier 2).
	 * The `state` it carries — `failed` for a throw under the lease, `cancelled` for
	 * one before it — is decided by WHICH transition won, so writing it first meant
	 * writing a state the tombstone might never reach.
	 *
	 * @param int        $delivery_id Tombstone id.
	 * @param \Throwable $error       The throw.
	 * @param bool       $leased      Whether the run had taken the lease.
	 * @return array Structured result.
	 */
	public function record_scheduled_failure( int $delivery_id, \Throwable $error, bool $leased = true ): array {
		$write = $leased
			? $this->deliveries->transition( $delivery_id, DeliveryRepository::EXECUTING, 'failed' )
			: WriteResult::refused( 'the run never took its lease' );
		$state = 'failed';

		if ( ! $write->won() ) {
			$write = $this->deliveries->transition( $delivery_id, DeliveryRepository::SCHEDULED, 'cancelled' );
			$state = 'cancelled';
		}

		$final = self::finalization( $write->won() ? true : $this->deliveries->is_terminal( $delivery_id ), $write );

		if ( ! $write->won() ) {
			// ⚠ THE EXCEPTION GOES IN THE LOG LINE. No detail row is written here,
			// and this is the only other place it would be recorded at all.
			$this->record_lost_transition(
				$delivery_id,
				'a scheduled delivery that threw (' . Text::log_value( get_class( $error ) . ': ' . $error->getMessage() ) . ')',
				$write
			);

			return self::result( false, 1, 0, (bool) $final['terminal'], $write );
		}

		$written = $this->details->insert(
			$delivery_id,
			array(
				'type'            => 'auto',
				'state'           => $state,
				'reason'          => Text::log_value(
					$leased
						? 'the scheduled delivery threw before it could be sent'
						: 'the scheduled delivery threw before it could take its execution lease'
				),
				'failure_message' => Text::log_value( get_class( $error ) . ': ' . $error->getMessage() ),
			)
		) > 0 ? 1 : 0;

		return $this->verify( $delivery_id, 'a scheduled delivery that threw', 1, $written, $final );
	}

	/**
	 * THE LAST GUARANTEE: close a lease nothing recorded an outcome for
	 * (ADR-0015 §8.4).
	 *
	 * ⚠ IT IS A NO-OP UNLESS THE ROW IS STILL `executing`, AND THAT IS HOW IT
	 * DETECTS ITS OWN CASE. Every path through the send records an outcome and
	 * moves the tombstone off the lease, so this transition normally finds nothing
	 * to do and costs one refused write. When it DOES land, something returned
	 * from the send path having recorded nothing — the recording itself threw
	 * after the message went out is the realistic way — and the delivery would
	 * otherwise sit `executing` until the §8.3 sweep found it an hour later.
	 *
	 * `unresolved` rather than `failed`, for §8.3's reason: the mail may well have
	 * gone out, and nobody knows.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return bool True when this call closed an open lease.
	 */
	public function close_unrecorded_lease( int $delivery_id ): bool {
		// Only a WIN closes a lease. A lost race means the send recorded its own
		// outcome after all, and a failed write means the row is still `executing`
		// — which is the §8.3 sweep's case, not this one.
		if ( ! $this->deliveries->transition( $delivery_id, DeliveryRepository::EXECUTING, DeliveryDetailRepository::UNRESOLVED )->won() ) {
			return false;
		}

		$this->log_error(
			'delivery #' . $delivery_id . ' returned from its send path with the execution lease still held and '
				. 'no outcome recorded; it has been closed as unresolved. Whether the message went out is NOT known.'
		);

		$this->details->insert(
			$delivery_id,
			array(
				'type'   => 'auto',
				'state'  => DeliveryDetailRepository::UNRESOLVED,
				'reason' => Text::log_value(
					'the delayed send returned without recording an outcome, so whether the message was sent is unknown'
				),
			)
		);

		return true;
	}

	/**
	 * Recover a delivery whose worker never came back (ADR-0015 §8.3).
	 *
	 * ⚠ OWNERSHIP FIRST, DETAIL ROW SECOND (Prompt 7B Tier 2), for the reason
	 * self::record_scheduled_cancellation() gives: a sweep that loses to the worker
	 * finishing its send must not leave an `unresolved` attempt row on a `sent`
	 * tombstone.
	 *
	 * ⚠ THE REASON CODE IS A PARAMETER BECAUSE TWO DIFFERENT EVENTS END A LEASE
	 * UNRESOLVED, and both are honest: a worker that never came back (§8.3) and a
	 * plugin shut down while a worker was running (§8.8). The TRANSITION is the same
	 * one — `executing -> unresolved` — but "nobody knows because the worker
	 * vanished" and "nobody knows because the site owner deactivated the plugin" are
	 * different answers to *why didn't this send?*, which is the only question the
	 * delivery log exists to answer.
	 *
	 * @param int    $delivery_id Tombstone id.
	 * @param string $sentence    What to tell the merchant.
	 * @param string $code        Machine-readable cause.
	 * @return array Structured result.
	 */
	public function record_expired_lease( int $delivery_id, string $sentence, string $code = ScheduledDelivery::REASON_LEASE_EXPIRED ): array {
		$final = $this->finalize( $delivery_id, DeliveryDetailRepository::UNRESOLVED, DeliveryRepository::EXECUTING );
		$write = $final['write'] ?? null;

		if ( ! ( $write instanceof WriteResult ) || ! $write->won() ) {
			$this->record_lost_transition( $delivery_id, 'an expired execution lease', $write );

			return self::result( false, 1, 0, (bool) $final['terminal'], $write instanceof WriteResult ? $write : null );
		}

		$written = $this->details->insert(
			$delivery_id,
			array(
				'type'     => 'auto',
				'state'    => DeliveryDetailRepository::UNRESOLVED,
				'reason'   => Text::log_value( $sentence ),
				'snapshot' => array(
					'cancelled' => array( 'reason_code' => $code ),
				),
			)
		) > 0 ? 1 : 0;

		return $this->verify( $delivery_id, 'an expired execution lease', 1, $written, $final );
	}

	/**
	 * Record the outcome of an attempted send, one row per recipient.
	 *
	 * @param int                $delivery_id Tombstone id.
	 * @param ResolvedRecipients $recipients  Resolved recipients.
	 * @param string             $subject     Subject as sent.
	 * @param bool               $sent        Whether WooCommerce reported success.
	 * @param string             $reason      Notes recorded during resolution.
	 * @param array              $snapshot    Structured diagnostic payload.
	 * @param string|null        $from        State being left on the scheduled path
	 *                                        (ADR-0015 §8.1); null for the immediate one.
	 * @param string             $type        Attempt type (ADR-0019 §8): `auto`,
	 *                                        `manual` or `resend`.
	 * @param int                $revision    Rule revision to record, or 0 to leave it.
	 * @return array Structured result.
	 */
	public function record_send( int $delivery_id, ResolvedRecipients $recipients, string $subject, bool $sent, string $reason = '', array $snapshot = array(), ?string $from = null, string $type = 'auto', int $revision = 0 ): array {
		return $this->write_attempt_rows(
			$delivery_id,
			$recipients,
			$subject,
			$sent ? 'sent' : 'failed',
			$reason,
			$sent ? null : 'the mailer reported the message as not sent',
			$snapshot,
			$sent ? 'a send' : 'a failed send',
			$from,
			$type,
			$revision
		);
	}

	/**
	 * Record a delivery that THREW — during recipient resolution, during
	 * placeholder resolution, during content assembly, or during the send itself.
	 *
	 * The exception is caught by the orchestrator so it cannot escape into the
	 * WooCommerce order event; this is where it becomes visible instead. The
	 * message is stored shape-sanitised — never tag-stripped, because an SMTP
	 * response routinely carries the address in angle brackets and
	 * `sanitize_text_field()` would delete the most useful part of it.
	 *
	 * ⚠ THE RECIPIENTS MAY NOT EXIST YET, AND THAT IS THE ADR-0014 §10 CASE.
	 * Prompt 6 introduced a third-party filter INSIDE resolution, so the throw can
	 * now happen before there is any address to write a row against — and
	 * `write_attempt_rows()` writes one row per recipient, which for an empty set
	 * is NO ROW AT ALL. That is precisely the claimed-tombstone-with-no-evidence
	 * state ADR-0012 §3 exists to prevent, so a recipient-less failure row is
	 * written instead. `null` is accepted rather than an empty object because the
	 * caller genuinely has nothing: the resolver never returned.
	 *
	 * @param int                     $delivery_id Tombstone id.
	 * @param ResolvedRecipients|null $recipients  Resolved recipients, or null when
	 *                                             resolution itself threw.
	 * @param string                  $subject     Subject as attempted.
	 * @param \Throwable              $error       What was thrown.
	 * @param string                  $reason      Notes recorded during resolution.
	 * @param array                   $snapshot    Structured diagnostic payload.
	 * @param string|null             $from        State being left on the scheduled
	 *                                             path (ADR-0015 §8.1).
	 * @return array Structured result.
	 */
	public function record_send_failure( int $delivery_id, ?ResolvedRecipients $recipients, string $subject, \Throwable $error, string $reason = '', array $snapshot = array(), ?string $from = null ): array {
		$message = get_class( $error ) . ': ' . $error->getMessage();

		$this->log_error( 'delivery #' . $delivery_id . ' threw during send — ' . $message );

		return $this->write_attempt_rows(
			$delivery_id,
			$recipients,
			$subject,
			'failed',
			$reason,
			$message,
			$snapshot,
			'a send that threw',
			$from
		);
	}

	/**
	 * Write one attempt row per resolved recipient, then finalise.
	 *
	 * ⚠ THIS ONE RECORDER WRITES ITS DETAIL ROWS *BEFORE* IT TAKES OWNERSHIP, AND
	 * UNLIKE THE OTHER FOUR THAT IS CORRECT (ADR-0015 §8.9, Prompt 7C Tier 2). The
	 * cancellation, arm-failure, expired-lease and scheduled-failure recorders all
	 * finalise first, because for them the detail row IS the outcome — writing one
	 * before winning the transition would be claiming an outcome that belongs to
	 * whoever did win. Here the detail row is EVIDENCE OF A SIDE EFFECT THAT HAS
	 * ALREADY HAPPENED: by the time this method runs, the mailer has been called and
	 * a message is or is not in a customer's inbox. That fact is not conditional on
	 * winning anything, and suppressing it would delete the only record of a real
	 * email.
	 *
	 * **AND REORDERING WOULD NOT FIX WHAT IT APPEARS TO FIX.** The state that looks
	 * wrong — an `unresolved` tombstone carrying a `sent` detail row — is produced by
	 * a CONCURRENT finaliser (deactivation, the sweep), not by the order of these two
	 * writes: finalise-first would lose the same race, still have to write the same
	 * evidence, and additionally risk a terminal tombstone with no detail row at all
	 * if the process died between the two. What the pair actually needs is to be
	 * SELF-EXPLAINING, which is what the lost-transition note below provides — and
	 * what was missing, because a lost race leaves `finalized` true and therefore
	 * passed `verify()` silently.
	 *
	 * @param int                     $delivery_id Tombstone id.
	 * @param ResolvedRecipients|null $recipients  Resolved recipients, or null.
	 * @param string                  $subject     Subject as sent.
	 * @param string                  $state       Attempt state.
	 * @param string                  $reason      Resolution notes.
	 * @param string|null             $failure     Failure message, or null.
	 * @param array                   $snapshot    Structured diagnostic payload.
	 * @param string                  $what        Description for the shortfall log.
	 * @param string|null             $from        State being left on the scheduled
	 *                                             path (ADR-0015 §8.1).
	 * @param string                  $type        Attempt type (ADR-0019 §8).
	 * @param int                     $revision    Rule revision to record, or 0.
	 * @return array Structured result.
	 */
	private function write_attempt_rows( int $delivery_id, ?ResolvedRecipients $recipients, string $subject, string $state, string $reason, ?string $failure, array $snapshot, string $what, ?string $from = null, string $type = 'auto', int $revision = 0 ): array {
		$rows = $this->insert_attempt_rows(
			$delivery_id,
			$recipients,
			self::attempt_row( $state, $subject, $reason, $failure, $snapshot, $type )
		);

		$finalized = $this->finalize( $delivery_id, 'sent' === $state ? 'sent' : 'failed', $from, $revision );

		$this->note_lost_attempt_ownership( $delivery_id, $what, $finalized, $from );

		return $this->verify( $delivery_id, $what, $rows['expected'], $rows['written'], $finalized );
	}

	/**
	 * Build one attempt row's shared fields.
	 *
	 * EXTRACTED SO THE ROW SHAPE HAS ONE DEFINITION (ADR-0016 §5). Consolidation
	 * writes attempt rows per MESSAGE and finalises once at the end, so the row
	 * building and the finalisation had to come apart — and a second copy of the row
	 * shape would be a second place for the two to disagree about what an attempt
	 * looks like.
	 *
	 * @param string      $state    Attempt state.
	 * @param string      $subject  Subject as sent.
	 * @param string      $reason   Resolution notes.
	 * @param string|null $failure  Failure message, or null.
	 * @param array       $snapshot Structured diagnostic payload.
	 * @param string      $type     Attempt type (ADR-0019 §8).
	 * @return array
	 */
	private static function attempt_row( string $state, string $subject, string $reason, ?string $failure, array $snapshot, string $type = 'auto' ): array {
		$row = array(
			// ⚠ DECLARED BY THE CALLER, NOT ASSUMED (ADR-0019 §8). `manual` and `resend`
			// are what a merchant's own action writes, and `DeliveryDetailRepository`
			// validates the value against `TYPES` — so an unrecognised type is refused
			// at the storage boundary rather than stored.
			'type'            => $type,
			'state'           => $state,
			'subject'         => $subject,
			'reason'          => Text::log_value( $reason ),
			'failure_message' => null === $failure ? null : Text::log_value( $failure ),
		);

		if ( array() !== $snapshot ) {
			$row['snapshot'] = $snapshot;
		}

		return $row;
	}

	/**
	 * Write one attempt's detail rows — one per resolved recipient — WITHOUT
	 * finalising.
	 *
	 * @param int                     $delivery_id Tombstone id.
	 * @param ResolvedRecipients|null $recipients  Resolved recipients, or null.
	 * @param array                   $row         Row from self::attempt_row().
	 * @return array{expected:int,written:int}
	 */
	private function insert_attempt_rows( int $delivery_id, ?ResolvedRecipients $recipients, array $row ): array {
		$entries = null === $recipients ? array() : $recipients->entries();

		if ( array() === $entries ) {
			/*
			 * ⚠ ONE RECIPIENT-LESS ROW RATHER THAN NONE (ADR-0014 §10). Reachable
			 * only on a failure or skip path — a send is refused above unless there is
			 * a deliverable recipient — and it is the path where a third party threw
			 * before resolution produced an address. Writing nothing here would
			 * finalise the tombstone with no detail row: an identity consumed, a
			 * merchant told nothing, and the exception recorded nowhere.
			 */
			return array(
				'expected' => 1,
				'written'  => $this->details->insert( $delivery_id, $row ) > 0 ? 1 : 0,
			);
		}

		$written = 0;

		// ONE ROW PER RESOLVED RECIPIENT (ADR-0009, ADR-0012 §4). Never a
		// comma-joined list: the privacy eraser finds rows with
		// `WHERE recipient = %s`, so a joined list would be invisible to a
		// legally-required erasure request.
		foreach ( $entries as $entry ) {
			$row['recipient']      = $entry['address'];
			$row['recipient_type'] = $entry['type'];

			if ( $this->details->insert( $delivery_id, $row ) > 0 ) {
				++$written;
			}
		}

		return array(
			'expected' => count( $entries ),
			'written'  => $written,
		);
	}

	/**
	 * Record ONE message of a consolidated fan-out (ADR-0016 §5).
	 *
	 * ⚠ IT WRITES ROWS AND DOES NOT FINALISE, AND THAT SPLIT IS THE WHOLE POINT.
	 * Finalising per message would leave the tombstone holding whichever outcome was
	 * written LAST, so `{failed, sent}` would report `sent` and lose the failure
	 * entirely. The rows are still written AS EACH MESSAGE COMPLETES, because a row
	 * is evidence of a side effect that has already happened — the mailer has been
	 * called and a message either is or is not in a customer's inbox — and
	 * suppressing it would delete the only record of a real email (ADR-0015 §8.9).
	 *
	 * ⚠ A SKIPPED MESSAGE WRITES ONE RECIPIENT-LESS ROW, not one per address. Nothing
	 * was sent to anybody, so a row per recipient would record N attempts against
	 * addresses no message ever reached — the same reasoning `record_skip()` already
	 * applies to a whole delivery.
	 *
	 * @param int                     $delivery_id Tombstone id.
	 * @param FanOutResult            $result      This fan-out's accumulator.
	 * @param array                   $message     The plan's message descriptor.
	 * @param ResolvedRecipients|null $recipients  Resolved recipients, or null when
	 *                                             resolution never returned.
	 * @param string                  $subject     Subject as attempted — THIS
	 *                                             message's, which for a
	 *                                             `{product_name}` subject differs
	 *                                             per message.
	 * @param string                  $outcome     One of the `FanOutResult` outcomes.
	 * @param string                  $reason      Diagnostics for this message.
	 * @param string|null             $failure     Failure message, or null.
	 * @param array                   $snapshot    Structured diagnostic payload,
	 *                                             including `consolidation`.
	 * @return void
	 */
	public function record_fanout_message( int $delivery_id, FanOutResult $result, array $message, ?ResolvedRecipients $recipients, string $subject, string $outcome, string $reason = '', ?string $failure = null, array $snapshot = array() ): void {
		if ( FanOutResult::SENT === $outcome ) {
			$state = self::OUTCOME_SENT;
		} elseif ( FanOutResult::SKIPPED === $outcome ) {
			$state = 'skipped';
		} else {
			$state = self::OUTCOME_FAILED;
		}

		if ( null !== $failure ) {
			$this->log_error(
				'delivery #' . $delivery_id . ' message ' . (int) ( $message['index'] ?? 0 ) . ' of '
					. (int) ( $message['count'] ?? 0 ) . ' (' . (string) ( $message['unit'] ?? '' ) . ') failed — ' . $failure
			);
		}

		$rows = $this->insert_attempt_rows(
			$delivery_id,
			FanOutResult::SKIPPED === $outcome ? null : $recipients,
			self::attempt_row( $state, $subject, $reason, $failure, $snapshot )
		);

		$result->record( $message, $outcome, $rows['expected'], $rows['written'], $reason );
	}

	/**
	 * Finalise a consolidated delivery ONCE, with the aggregate (ADR-0016 §5).
	 *
	 * NO DETAIL ROW OF ITS OWN. The per-message rows ARE the evidence, and each one
	 * carries `snapshot.consolidation.count`, so an aggregate row would duplicate
	 * facts already recorded N times. The aggregate's job is the tombstone's
	 * `final_status` — *did this delivery work* — and the per-message rows answer
	 * *which message*.
	 *
	 * @param int          $delivery_id Tombstone id.
	 * @param FanOutResult $result      The fan-out's accumulated outcomes.
	 * @param string|null  $from        State being left on the scheduled path
	 *                                  (ADR-0015 §8.1); null for the immediate one.
	 * @return array Structured result.
	 */
	public function record_fanout_outcome( int $delivery_id, FanOutResult $result, ?string $from = null ): array {
		$what  = 'a consolidated delivery — ' . $result->describe();
		$final = $this->finalize( $delivery_id, $result->aggregate_status(), $from );

		$this->note_lost_attempt_ownership( $delivery_id, $what, $final, $from );

		return $this->verify( $delivery_id, $what, $result->rows_expected(), $result->rows_written(), $final );
	}

	/**
	 * Say so when an ATTEMPT's evidence outlived its claim on the tombstone
	 * (ADR-0015 §8.9).
	 *
	 * ⚠ THE SILENT CASE THIS EXISTS FOR. When a concurrent finaliser — deactivation,
	 * the §8.3 sweep — terminalises the row first, `finalize()` reports `terminal`
	 * true, because it really is terminal and somebody really does own it. `verify()`
	 * then computes success, logs nothing, and the delivery log is left holding a
	 * `sent` attempt row under an `unresolved` tombstone with no explanation anywhere
	 * of how the two can both be true. They can both be true, and the explanation is
	 * worth one log line: the message went out, and its outcome column belongs to
	 * whoever won the transition.
	 *
	 * Immediate-path callers pass `$from = null` and are skipped: that path owns its
	 * tombstone from `claimed` to terminal inside one request, so there is no second
	 * actor and no race to report.
	 *
	 * @param int         $delivery_id  Tombstone id.
	 * @param string      $what         What was being recorded.
	 * @param array       $finalization self::finalize()'s report.
	 * @param string|null $from         State the caller believed it was leaving.
	 * @return void
	 */
	private function note_lost_attempt_ownership( int $delivery_id, string $what, array $finalization, ?string $from ): void {
		if ( null === $from ) {
			return;
		}

		$write = $finalization['write'] ?? null;

		if ( $write instanceof WriteResult && $write->won() ) {
			return;
		}

		$this->record_lost_transition( $delivery_id, $what . ' (whose evidence rows are written regardless)', $write instanceof WriteResult ? $write : null );
	}

	/**
	 * Record a halt: which rules a matching `stop_processing` rule blocked.
	 *
	 * Written onto the STOP RULE's own detail rows rather than as tombstones for
	 * the blocked rules (ADR-0012 §2). The structured list goes in `snapshot`
	 * so support tooling can read it without parsing prose.
	 *
	 * @param int   $delivery_id     The stop rule's tombstone id.
	 * @param int[] $blocked_rule_ids Ids of the rules that never ran.
	 * @return array Snapshot payload merged into the stop rule's detail rows.
	 */
	public static function halt_snapshot( int $delivery_id, array $blocked_rule_ids ): array {
		return array(
			'blocked_by_stop_flag' => array(
				'delivery_id' => $delivery_id,
				'count'       => count( $blocked_rule_ids ),
				'rule_ids'    => array_values( array_map( 'intval', $blocked_rule_ids ) ),
			),
		);
	}

	/**
	 * Record a claim that failed.
	 *
	 * A failed claim has no `delivery_id` to attach a detail row to — the row
	 * would be an orphan the detail repository rightly refuses. When an id IS
	 * present the failure is written as a detail row; otherwise it goes to the
	 * WooCommerce log, which is the only durable place left. Either way, NOTHING
	 * IS SENT.
	 *
	 * THE `delivery_id > 0` BRANCH IS DEFENSIVE, AND IT IS VERIFIED LIKE EVERY
	 * OTHER WRITE (ADR-0012 §3). `DeliveryRepository::claim()` returns
	 * `delivery_id = 0` on both of its failure paths, so today nothing in the
	 * plugin reaches it — but this method takes a claim array from its caller
	 * rather than producing one, and a future repository that DID carry an id
	 * would otherwise be silently writing unverified rows. It used to insert and
	 * finalise while checking neither result, which is exactly the shortfall
	 * Prompt 4a removed everywhere else.
	 *
	 * @param array  $claim    Claim result.
	 * @param int    $order_id Order id.
	 * @param int    $rule_id  Rule id.
	 * @param string $reason   Diagnostic detail.
	 * @return array Structured result.
	 */
	public function record_claim_failure( array $claim, int $order_id, int $rule_id, string $reason ): array {
		$delivery_id = (int) ( $claim['delivery_id'] ?? 0 );

		if ( $delivery_id > 0 ) {
			$written = $this->details->insert(
				$delivery_id,
				array(
					'type'            => 'auto',
					'state'           => 'failed',
					'reason'          => Text::log_value( $reason ),
					'failure_message' => Text::log_value( 'delivery identity could not be claimed' ),
				)
			) > 0 ? 1 : 0;

			// The IMMEDIATE path's unconditional writer: a failed claim never entered
			// the scheduled state machine (ADR-0015 §8.1).
			$finalized = $this->deliveries->set_final_status( $delivery_id, 'failed' );

			return $this->verify( $delivery_id, 'a failed claim', 1, $written, self::finalization( $finalized ) );
		}

		$this->log_error(
			'delivery identity could not be claimed for order #' . $order_id . ' rule #' . $rule_id . ': ' . $reason
		);

		// Recorded as fully as it can be: there is no tombstone to write onto,
		// and the error log entry above IS the record.
		return self::result( true, 0, 0, true );
	}

	/**
	 * Record one rule's content having been inserted into a native email
	 * (ADR-0013 §1).
	 *
	 * THE CLAIM IS INVERTED HERE, AND THAT IS THE WHOLE POINT. Separate mode
	 * claims BEFORE sending because the claim decides whether to send. Insert
	 * mode has no such decision — WooCommerce sent its own email, and our content
	 * was already inside it — so this runs at FINALIZATION and the record is a
	 * LOG, never a gate.
	 *
	 * A `suppressed` claim is therefore NOT a duplicate to be discarded: it is a
	 * merchant deliberately re-sending a native email, which genuinely does
	 * re-insert the content (ADR-0004). A second attempt row is written and the
	 * tombstone's counter has already incremented atomically inside `claim()`.
	 *
	 * WHAT `final_status` MEANS ON AN AGGREGATE INSERT TOMBSTONE (ADR-0013 §1a):
	 * **the LATEST attempt**, never "any success". A merchant looking at an
	 * identity they have just re-sent is asking *did the message I just sent go
	 * out*, and "sent" left over from three days ago answers a different
	 * question. The per-attempt rows keep the whole history, so nothing is lost
	 * by the aggregate tracking the newest fact — and each attempt row ALWAYS
	 * records its own true outcome, which is not negotiable either way.
	 *
	 * ⚠ TWO OUTCOMES ARE RECORDED, NOT ONE, AND NEITHER MAY REPLACE THE OTHER
	 * (ADR-0014 §10c). `$outcome` is what happened to the MESSAGE; `$rendered` is
	 * whether THIS RULE'S content got into it. They were collapsed until Prompt 6B —
	 * `not_rendered` was written INSTEAD of the message outcome — which made an
	 * abandoned render indistinguishable from a failed delivery in the `state`
	 * column, and so made a merchant's first real send report as a `resend`.
	 *
	 * @param int    $order_id        Order id, from the slot recorded at push.
	 * @param int    $rule_id         Rule id.
	 * @param string $native_email_id WooCommerce email the content went into —
	 *                                the ADR-0004 insert identity.
	 * @param int    $revision        Rule revision, audit only.
	 * @param string $position        Injection position that emitted it.
	 * @param string $outcome         The MESSAGE outcome; one of
	 *                                self::INSERT_OUTCOMES.
	 * @param string $notes           Placeholder-resolution notes from the render
	 *                                that produced this content (ADR-0014 §1a).
	 * @param bool   $rendered        Whether this RULE'S content reached the
	 *                                message (ADR-0014 §10c).
	 * @return array Structured result.
	 */
	public function record_insert( int $order_id, int $rule_id, string $native_email_id, int $revision, string $position, string $outcome = self::OUTCOME_SENT, string $notes = '', bool $rendered = true ): array {
		if ( ! in_array( $outcome, self::INSERT_OUTCOMES, true ) ) {
			// An allowlist, not a default: silently recording an unrecognised
			// outcome as `sent` is the exact failure this parameter exists to
			// remove. `not_rendered` is refused here too, and deliberately — it is
			// a RENDER outcome and arrives as `$rendered`, never as a message one.
			$this->log_error( 'refused an unrecognised insert outcome "' . $outcome . '" for order #' . $order_id . ' rule #' . $rule_id );
			return self::result( false, 1, 0, false );
		}

		$claim = $this->deliveries->claim( $order_id, $rule_id, self::MODE_INSERT, DeliveryIdentity::native( $native_email_id ), $revision );

		if ( DeliveryRepository::FAILED === $claim['result'] ) {
			return $this->record_claim_failure( $claim, $order_id, $rule_id, self::insert_label( $outcome, $rendered ) . ' into ' . $native_email_id );
		}

		$delivery_id = (int) $claim['delivery_id'];
		$abandoned   = self::OUTCOME_ABANDONED === $outcome;

		/*
		 * THE STORED `state` IS THE TWO OUTCOMES MAPPED ONTO THE ATTEMPT-STATE
		 * ALLOWLIST — see self::insert_state() for the full matrix and for why an
		 * ABANDONED message keeps its own state whatever became of this rule's
		 * content. Nothing is lost by the mapping: `snapshot.insert.outcome` and
		 * `snapshot.insert.render` keep BOTH facts exactly, and the `reason` sentence
		 * spells them out.
		 */
		$state = self::insert_state( $outcome, $rendered );

		/*
		 * ONE TRANSACTION ALLOCATES THE ATTEMPT, WRITES THE ROW AND RECORDS THE
		 * STATUS (ADR-0009 amendment). The attempt number used to be read before the
		 * transaction, from `suppressed_count`, so two concurrent recorders could
		 * agree on the same one — and the status could be written by whoever finished
		 * last rather than by whoever allocated the highest attempt.
		 *
		 * TWO RULES ARE PUSHED DOWN INTO THAT LOCK, both of them ADR-0013's, and both
		 * because they depend on the tombstone's attempt history:
		 *
		 *   - `$defer_to_genuine_attempt` (§6a) — an ABANDONED render is not a
		 *     delivery attempt and must not overwrite the status of a send that
		 *     really happened;
		 *   - `$type_by_genuine_attempt` (§6b) — ⚠ `auto` versus `resend` is derived
		 *     from prior GENUINE attempts, never from claim suppression. An abandoned
		 *     render claims the identity and creates the tombstone, so deriving the
		 *     type from the claim recorded the merchant's FIRST real delivery as a
		 *     `resend` of a message that had never gone out. The §6a policy was
		 *     applied to `final_status` and not to attempt typing; this closes it.
		 */
		$recorded = $this->details->record_attempt(
			$delivery_id,
			array(
				// DERIVED INSIDE THE TRANSACTION (§6b) — this value is a placeholder
				// that `$type_by_genuine_attempt` below replaces. It is still written
				// as a real member of the allowlist so nothing downstream can see an
				// unvalidated type.
				'type'     => 'auto',
				'state'    => $state,
				'reason'   => Text::log_value(
					self::insert_reason( $outcome, $rendered, $native_email_id, $position )
					. ( '' !== $notes ? '; ' . $notes : '' )
				),
				// THE PER-ATTEMPT AUDIT (ADR-0013 §1a). The tombstone carries one
				// `rule_revision_sent` — necessarily the newest — so without this
				// a send at revision 1 followed by a resend at revision 2 exposed
				// only one revision for two different bodies. Every attempt now
				// states the revision, the email, the position and the outcome
				// that produced IT.
				//
				// ⚠ THE ATTEMPT NUMBER IS NOT COPIED IN HERE. It is allocated inside
				// the transaction below, and the `attempt` COLUMN is its single
				// source; a JSON copy could only ever disagree with the column it
				// duplicates. It used to be written here from a number computed
				// before the transaction — which is exactly the number that could be
				// wrong.
				'snapshot' => array(
					// ⚠ BOTH AXES, SIDE BY SIDE (ADR-0014 §10c). `outcome` is the
					// MESSAGE'S and `render` is THIS RULE'S; `not_rendered` used to be
					// written into `outcome`, which destroyed the message fact
					// entirely. Storing both is what lets the stored `state` be a
					// lossy allowlist without anything having to be inferred back.
					'insert' => array(
						'rule_revision'   => $revision,
						'native_email_id' => $native_email_id,
						'position'        => $position,
						'outcome'         => $outcome,
						'render'          => $rendered ? self::RENDER_RENDERED : self::RENDER_NOT_RENDERED,
					),
				),
			),
			$state,
			$revision,
			$abandoned,
			true
		);

		$written = $recorded['id'] > 0 ? 1 : 0;

		// A DEFERRED STATUS IS A SUCCESS, NOT A SHORTFALL. The tombstone deliberately
		// keeps the status of its latest real send attempt (ADR-0013 §6a); reporting
		// that as "not finalized" would log an error for correct behaviour.
		$finalized = $recorded['status_written'] || $recorded['status_deferred'];

		return $this->verify( $delivery_id, self::insert_label( $outcome, $rendered, 'resend' === (string) $recorded['type'] ), 1, $written, self::finalization( (bool) $finalized ) );
	}

	/**
	 * The stored attempt `state` for one MESSAGE outcome and one RENDER outcome
	 * (ADR-0014 §10c).
	 *
	 * `state` is a storage allowlist that retention tiering, the privacy exporter,
	 * `GENUINE_ATTEMPT_STATES` and every support query read, so it holds four
	 * values and the two axes have to project onto them. The projection is stated
	 * as a table rather than derived, because the previous one-line version was
	 * where the defect lived:
	 *
	 *   | message      | rendered | state        |
	 *   |--------------|----------|--------------|
	 *   | `sent`       | yes      | `sent`       |
	 *   | `sent`       | **no**   | **`failed`** |
	 *   | `failed`     | either   | `failed`     |
	 *   | `unresolved` | either   | `unresolved` |
	 *   | `abandoned`  | either   | `abandoned`  |
	 *
	 * ⚠ ONLY THE `sent` ROW IS AFFECTED BY THE RENDER OUTCOME, and that is the
	 * correction. A message that WENT OUT without this rule's content is a failed
	 * delivery OF THIS RULE and must be found by `state = 'failed'`. A message that
	 * was ABANDONED was no delivery at all, so it keeps `abandoned` whether or not
	 * this rule rendered — the previous code overwrote it with `failed`,
	 * `GENUINE_ATTEMPT_STATES` counted that as a real send attempt, and the
	 * merchant's next and FIRST genuine delivery was typed `resend` (ADR-0013 §6b).
	 * `unresolved` keeps its own state for the same reason, one step weaker: a send
	 * that began and never reported is not a send that failed.
	 *
	 * Nothing is inferred back out of the shared state: `snapshot.insert.outcome`
	 * and `snapshot.insert.render` keep both codes exactly.
	 *
	 * @param string $outcome  One of self::INSERT_OUTCOMES — the MESSAGE outcome.
	 * @param bool   $rendered Whether this rule's content reached the message.
	 * @return string A member of `DeliveryDetailRepository::STATES`.
	 */
	private static function insert_state( string $outcome, bool $rendered ): string {
		if ( ! $rendered && self::OUTCOME_SENT === $outcome ) {
			return self::OUTCOME_FAILED;
		}

		return $outcome;
	}

	/**
	 * The shortfall-log description for one insert record.
	 *
	 * @param string $outcome  MESSAGE outcome code.
	 * @param bool   $rendered Whether this rule's content reached the message.
	 * @param bool   $repeat   Whether this attempt was DERIVED as a resend — i.e.
	 *                         whether the tombstone already carried a real send
	 *                         attempt (ADR-0013 §6b). Never the claim result.
	 * @return string
	 */
	private static function insert_label( string $outcome, bool $rendered = true, bool $repeat = false ): string {
		if ( ! $rendered ) {
			// THE RULE'S OWN FAILURE IS WHAT A SHORTFALL AGAINST THIS ROW IS ABOUT,
			// and this wording is true under every message outcome — which the
			// outcome-derived labels below are not.
			return $repeat ? 'a rule that failed to re-render' : 'a rule that failed to render';
		}

		if ( self::OUTCOME_ABANDONED === $outcome ) {
			return $repeat ? 'an abandoned re-render' : 'an abandoned render';
		}

		if ( self::OUTCOME_UNRESOLVED === $outcome ) {
			return $repeat ? 'an unresolved re-insert' : 'an unresolved insert';
		}

		if ( self::OUTCOME_FAILED === $outcome ) {
			return $repeat ? 'a failed re-insert' : 'a failed insert';
		}

		return $repeat ? 'a re-insert' : 'an insert';
	}

	/**
	 * The stored `reason` for one insert outcome.
	 *
	 * ⚠ `unresolved` IS NOT `failed`, AND THE WORDING KEEPS THEM APART. Nothing
	 * is known to have failed: the content went into the message, and
	 * `woocommerce_email_sent` never fired to say what happened next — because
	 * the send threw and the throw escaped its caller (ADR-0012 §11e), or the
	 * render never sent at all. Recording that as `failed` would assert something
	 * untrue; dropping it would leave the merchant with an email this plugin
	 * contributed to and no log entry at all.
	 *
	 * ⚠ NO LONGER SAYS "re-inserted", AND THAT IS DELIBERATE (ADR-0013 §6b). The
	 * wording used to be chosen from the CLAIM result, which is the same wrong
	 * source the attempt `type` was derived from: an abandoned render claims the
	 * identity, so a merchant's first real delivery was described as a re-insert of
	 * a message that had never gone out. Whether an attempt is a repeat is now
	 * recorded in exactly one place — the `type` column, derived from real delivery
	 * history inside the allocating transaction — and this string no longer offers a
	 * second, weaker answer to the same question.
	 *
	 * ⚠ AND IT NO LONGER CLAIMS THE NATIVE EMAIL WAS SENT WHEN IT WAS NOT
	 * (ADR-0014 §10c). The `not_rendered` sentence used to end "…and the native
	 * email was sent without it" unconditionally, because `not_rendered` had
	 * REPLACED the message outcome and there was nothing left to consult. It was
	 * therefore false for every abandoned, unresolved and failed message — a
	 * merchant reading the log was told a customer had received an email nobody
	 * ever sent. The two facts are now separate parameters and the sentence is
	 * assembled from both.
	 *
	 * @param string $outcome         MESSAGE outcome code.
	 * @param bool   $rendered        Whether this rule's content reached the
	 *                                message.
	 * @param string $native_email_id Native email id.
	 * @param string $position        Injection position.
	 * @return string
	 */
	private static function insert_reason( string $outcome, bool $rendered, string $native_email_id, string $position ): string {
		if ( ! $rendered ) {
			/*
			 * ⚠ THE ONE OPENING THAT SAYS NOTHING WAS INSERTED (ADR-0014 §10c). Every
			 * other branch opens with "inserted into" or "content was rendered into",
			 * because in those cases the content really did reach the message. Here it
			 * did not: resolving this rule threw and the injector emitted nothing. What
			 * then became of the MESSAGE is a second fact and is stated as one. The
			 * exception's class and message arrive as `$notes` and are appended by the
			 * caller.
			 */
			return 'NOTHING was inserted into ' . $native_email_id . ' at ' . $position
				. ' — resolving this rule threw, so its content was withheld and '
				. self::message_clause( $outcome );
		}

		if ( self::OUTCOME_ABANDONED === $outcome ) {
			return 'content was rendered into ' . $native_email_id . ' at ' . $position
				. ' but that render was never sent — no delivery was attempted';
		}

		if ( self::OUTCOME_UNRESOLVED === $outcome ) {
			return 'content was rendered into ' . $native_email_id . ' at ' . $position
				. ' but the send outcome was never reported';
		}

		$prefix = 'inserted into ' . $native_email_id . ' at ' . $position;

		if ( self::OUTCOME_FAILED === $outcome ) {
			return $prefix . ' — WooCommerce reported the message as not sent';
		}

		return $prefix;
	}

	/**
	 * What became of the MESSAGE this rule failed to render into (ADR-0014 §10c).
	 *
	 * FOUR SENTENCES, ONE PER MESSAGE OUTCOME, and exactly one of them says the
	 * email was sent. That is the whole point: the sentence a merchant reads has to
	 * match what actually happened to the message, and until Prompt 6B every one of
	 * these cases produced the `sent` wording.
	 *
	 * @param string $outcome MESSAGE outcome code.
	 * @return string
	 */
	private static function message_clause( string $outcome ): string {
		if ( self::OUTCOME_ABANDONED === $outcome ) {
			return 'that render never reached a send at all — no delivery was attempted';
		}

		if ( self::OUTCOME_UNRESOLVED === $outcome ) {
			return "the native email's send outcome was never reported";
		}

		if ( self::OUTCOME_FAILED === $outcome ) {
			return 'WooCommerce reported the native email itself as not sent';
		}

		return 'the native email was sent without it';
	}

	/**
	 * Record that a trigger was deferred under ADR-0008.
	 *
	 * NO CLAIM. The identity is preserved for the deferred run, which claims it
	 * — claiming here would make the deferred path's claim a duplicate and stop
	 * it sending.
	 *
	 * @param int    $order_id  Order id.
	 * @param string $identity  Trigger identity.
	 * @param string $reason    Diagnostic detail.
	 * @param bool   $is_defect Whether the deferral failed to arrange itself.
	 * @return void
	 */
	public function record_deferral( int $order_id, string $identity, string $reason, bool $is_defect = false ): void {
		$message = 'deferred ' . $identity . ' for order #' . $order_id . ': ' . $reason;

		if ( $is_defect ) {
			// A deferral that never scheduled means a matching order silently
			// never gets its email. That is an error, not an informational note.
			$this->log_error( $message );
			return;
		}

		$this->log_notice( $message );
	}

	/**
	 * Record that orchestration ran but was deliberately inert.
	 *
	 * NOTHING IS CLAIMED. The global kill switch being off is a merchant
	 * decision, not a delivery outcome — consuming identities while the feature
	 * is switched off would make switching it back on a no-op for every trigger
	 * that fired in the meantime (ADR-0012 §5).
	 *
	 * @param int    $order_id Order id.
	 * @param string $identity Trigger identity.
	 * @param string $reason   Why nothing happened.
	 * @return void
	 */
	public function record_inert( int $order_id, string $identity, string $reason ): void {
		$this->log_notice( 'no delivery attempted for ' . $identity . ' on order #' . $order_id . ': ' . $reason );
	}

	/**
	 * The tombstone repository, for callers that need to read state back.
	 *
	 * @return DeliveryRepository
	 */
	public function deliveries(): DeliveryRepository {
		return $this->deliveries;
	}

	/**
	 * The detail repository.
	 *
	 * @return DeliveryDetailRepository
	 */
	public function details(): DeliveryDetailRepository {
		return $this->details;
	}

	/**
	 * Encode a snapshot payload for storage.
	 *
	 * @param array $snapshot Payload.
	 * @return string
	 */
	public static function encode_snapshot( array $snapshot ): string {
		return Json::encode( $snapshot );
	}

	/**
	 * Log an error through WooCommerce's logger when available.
	 *
	 * @param string $message Detail.
	 * @return void
	 */
	protected function log_error( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'extonify-wcep' ) );
		}
	}

	/**
	 * Log a notice through WooCommerce's logger when available.
	 *
	 * @param string $message Detail.
	 * @return void
	 */
	protected function log_notice( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info( $message, array( 'source' => 'extonify-wcep' ) );
		}
	}
}
