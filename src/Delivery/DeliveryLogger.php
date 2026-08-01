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
	 * @param bool $success       Whether everything intended was written.
	 * @param int  $rows_expected Rows that should have been written.
	 * @param int  $rows_written  Rows actually written.
	 * @param bool $finalized     Whether the tombstone reached its terminal status.
	 * @return array{success:bool,rows_expected:int,rows_written:int,finalized:bool}
	 */
	private static function result( bool $success, int $rows_expected, int $rows_written, bool $finalized ): array {
		return array(
			'success'       => $success,
			'rows_expected' => $rows_expected,
			'rows_written'  => $rows_written,
			'finalized'     => $finalized,
		);
	}

	/**
	 * Verify a write outcome and report any shortfall.
	 *
	 * @param int    $delivery_id   Tombstone id.
	 * @param string $what          What was being recorded.
	 * @param int    $rows_expected Rows that should have been written.
	 * @param int    $rows_written  Rows actually written.
	 * @param bool   $finalized     Whether the terminal status was written.
	 * @return array Structured result.
	 */
	private function verify( int $delivery_id, string $what, int $rows_expected, int $rows_written, bool $finalized ): array {
		$success = ( $rows_written === $rows_expected ) && $finalized;

		if ( ! $success ) {
			$this->log_error(
				sprintf(
					'delivery #%d: %s was not fully recorded (%d of %d detail rows written, tombstone %s). '
					. 'The delivery identity is consumed, so this cannot be retried automatically.',
					$delivery_id,
					$what,
					$rows_written,
					$rows_expected,
					$finalized ? 'finalized' : 'NOT finalized'
				)
			);
		}

		return self::result( $success, $rows_expected, $rows_written, $finalized );
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

		$finalized = $this->deliveries->set_final_status( $delivery_id, 'skipped' );

		return $this->verify( $delivery_id, 'a skip', 1, $written, $finalized );
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
	 * @return array Structured result.
	 */
	public function record_send( int $delivery_id, ResolvedRecipients $recipients, string $subject, bool $sent, string $reason = '', array $snapshot = array() ): array {
		return $this->write_attempt_rows(
			$delivery_id,
			$recipients,
			$subject,
			$sent ? 'sent' : 'failed',
			$reason,
			$sent ? null : 'the mailer reported the message as not sent',
			$snapshot,
			$sent ? 'a send' : 'a failed send'
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
	 * @return array Structured result.
	 */
	public function record_send_failure( int $delivery_id, ?ResolvedRecipients $recipients, string $subject, \Throwable $error, string $reason = '', array $snapshot = array() ): array {
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
			'a send that threw'
		);
	}

	/**
	 * Write one attempt row per resolved recipient, then finalise.
	 *
	 * @param int                     $delivery_id Tombstone id.
	 * @param ResolvedRecipients|null $recipients  Resolved recipients, or null.
	 * @param string                  $subject     Subject as sent.
	 * @param string                  $state       Attempt state.
	 * @param string                  $reason      Resolution notes.
	 * @param string|null             $failure     Failure message, or null.
	 * @param array                   $snapshot    Structured diagnostic payload.
	 * @param string                  $what        Description for the shortfall log.
	 * @return array Structured result.
	 */
	private function write_attempt_rows( int $delivery_id, ?ResolvedRecipients $recipients, string $subject, string $state, string $reason, ?string $failure, array $snapshot, string $what ): array {
		$entries = null === $recipients ? array() : $recipients->entries();
		$written = 0;

		$row = array(
			'type'            => 'auto',
			'state'           => $state,
			'subject'         => $subject,
			'reason'          => Text::log_value( $reason ),
			'failure_message' => null === $failure ? null : Text::log_value( $failure ),
		);

		if ( array() !== $snapshot ) {
			$row['snapshot'] = $snapshot;
		}

		if ( array() === $entries ) {
			/*
			 * ⚠ ONE RECIPIENT-LESS ROW RATHER THAN NONE (ADR-0014 §10). Reachable
			 * only on a failure path — a send is refused above unless there is a
			 * deliverable recipient — and it is the path where a third party threw
			 * before resolution produced an address. Writing nothing here would
			 * finalise the tombstone with no detail row: an identity consumed, a
			 * merchant told nothing, and the exception recorded nowhere.
			 */
			$written = $this->details->insert( $delivery_id, $row ) > 0 ? 1 : 0;

			return $this->verify( $delivery_id, $what, 1, $written, $this->deliveries->set_final_status( $delivery_id, 'sent' === $state ? 'sent' : 'failed' ) );
		}

		$expected = count( $entries );

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

		$finalized = $this->deliveries->set_final_status( $delivery_id, 'sent' === $state ? 'sent' : 'failed' );

		return $this->verify( $delivery_id, $what, $expected, $written, $finalized );
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

			$finalized = $this->deliveries->set_final_status( $delivery_id, 'failed' );

			return $this->verify( $delivery_id, 'a failed claim', 1, $written, $finalized );
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

		return $this->verify( $delivery_id, self::insert_label( $outcome, $rendered, 'resend' === (string) $recorded['type'] ), 1, $written, $finalized );
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
