<?php
/**
 * Identity consumption and delivery logging (ADR-0005, ADR-0012 §2, §3).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

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
	 * The delivery mode this prompt implements (ADR-0005). Insert mode is
	 * Prompt 5 and is deliberately absent rather than stubbed.
	 */
	const MODE = 'separate';

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
	 * Record a send that THREW.
	 *
	 * The exception is caught by the orchestrator so it cannot escape into the
	 * WooCommerce order event; this is where it becomes visible instead. The
	 * message is stored shape-sanitised — never tag-stripped, because an SMTP
	 * response routinely carries the address in angle brackets and
	 * `sanitize_text_field()` would delete the most useful part of it.
	 *
	 * @param int                $delivery_id Tombstone id.
	 * @param ResolvedRecipients $recipients  Resolved recipients.
	 * @param string             $subject     Subject as attempted.
	 * @param \Throwable         $error       What was thrown.
	 * @param string             $reason      Notes recorded during resolution.
	 * @param array              $snapshot    Structured diagnostic payload.
	 * @return array Structured result.
	 */
	public function record_send_failure( int $delivery_id, ResolvedRecipients $recipients, string $subject, \Throwable $error, string $reason = '', array $snapshot = array() ): array {
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
	 * @param int                $delivery_id Tombstone id.
	 * @param ResolvedRecipients $recipients  Resolved recipients.
	 * @param string             $subject     Subject as sent.
	 * @param string             $state       Attempt state.
	 * @param string             $reason      Resolution notes.
	 * @param string|null        $failure     Failure message, or null.
	 * @param array              $snapshot    Structured diagnostic payload.
	 * @param string             $what        Description for the shortfall log.
	 * @return array Structured result.
	 */
	private function write_attempt_rows( int $delivery_id, ResolvedRecipients $recipients, string $subject, string $state, string $reason, ?string $failure, array $snapshot, string $what ): array {
		$entries  = $recipients->entries();
		$expected = count( $entries );
		$written  = 0;

		// ONE ROW PER RESOLVED RECIPIENT (ADR-0009, ADR-0012 §4). Never a
		// comma-joined list: the privacy eraser finds rows with
		// `WHERE recipient = %s`, so a joined list would be invisible to a
		// legally-required erasure request.
		foreach ( $entries as $entry ) {
			$row = array(
				'type'            => 'auto',
				'state'           => $state,
				'recipient'       => $entry['address'],
				'recipient_type'  => $entry['type'],
				'subject'         => $subject,
				'reason'          => Text::log_value( $reason ),
				'failure_message' => null === $failure ? null : Text::log_value( $failure ),
			);

			if ( array() !== $snapshot ) {
				$row['snapshot'] = $snapshot;
			}

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
