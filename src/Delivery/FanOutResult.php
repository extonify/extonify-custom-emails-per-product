<?php
/**
 * One fan-out's per-message outcomes, and the aggregate they add up to
 * (ADR-0016 §5).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

use Extonify\WCEP\Repository\DeliveryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The account of ONE delivery's messages, built as they complete.
 *
 * ⚠ PER DELIVERY, NEVER ON A SHARED OBJECT (ADR-0012 §11). Created inside the
 * delivery that owns it and handed down as an argument, exactly like `RunOutcome`
 * and for the same reason: a send re-enters this lifecycle whenever a third-party
 * callback changes another order's status, and per-run state parked on a shared
 * collaborator is state the inner run overwrites before the outer loop has finished
 * reading it.
 *
 * ⚠ IT EXISTS BECAUSE FINALISATION HAPPENS ONCE, AFTER THE LAST MESSAGE. A
 * per-message finalise would leave the tombstone holding whichever outcome was
 * written LAST, so `{failed, sent}` would report `sent` and lose the failure
 * entirely. The attempt ROWS are still written as each message completes — a row is
 * evidence of a side effect that has already happened, and suppressing it would
 * delete the only record of a real email (ADR-0015 §8.9) — so this object is what
 * carries the per-message facts across to the single aggregate write.
 */
final class FanOutResult {

	/**
	 * A message reached the mailer and the mailer reported success.
	 */
	const SENT = 'sent';

	/**
	 * A message was attempted and did not go out: the mailer reported failure, or
	 * resolving it threw.
	 */
	const FAILED = 'failed';

	/**
	 * A message was deliberately not sent — the per-delivery
	 * `woocommerce_email_enabled_{id}` filter declining it (ADR-0012 §5a), or no
	 * recipient surviving header sanitisation.
	 *
	 * NEITHER SENT NOR FAILED, and that distinction is what stops the aggregate
	 * blaming the mailer for a third party's decision.
	 */
	const SKIPPED = 'skipped';

	/**
	 * One entry per message, in fan-out order.
	 *
	 * @var array[]
	 */
	private $messages = array();

	/**
	 * Detail rows this fan-out should have written.
	 *
	 * @var int
	 */
	private $rows_expected = 0;

	/**
	 * Detail rows it actually wrote.
	 *
	 * @var int
	 */
	private $rows_written = 0;

	/**
	 * Record what happened to one message.
	 *
	 * @param array  $message  The plan's message descriptor.
	 * @param string $outcome  One of self::SENT, self::FAILED, self::SKIPPED.
	 * @param int    $expected Detail rows this message should have written.
	 * @param int    $written  Detail rows it wrote.
	 * @param string $reason   Diagnostic recorded with it.
	 * @return void
	 */
	public function record( array $message, string $outcome, int $expected, int $written, string $reason = '' ): void {
		$this->messages[] = array(
			'index'   => (int) ( $message['index'] ?? 0 ),
			'unit'    => (string) ( $message['unit'] ?? '' ),
			'outcome' => $outcome,
			'reason'  => $reason,
		);

		$this->rows_expected += max( 0, $expected );
		$this->rows_written  += max( 0, $written );
	}

	/**
	 * Every message's outcome, in fan-out order.
	 *
	 * @return array[]
	 */
	public function messages(): array {
		return $this->messages;
	}

	/**
	 * How many messages this fan-out planned and recorded.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->messages );
	}

	/**
	 * How many messages ended in one outcome.
	 *
	 * @param string $outcome One of the outcome constants.
	 * @return int
	 */
	public function count_of( string $outcome ): int {
		$count = 0;

		foreach ( $this->messages as $message ) {
			if ( $outcome === $message['outcome'] ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Detail rows this fan-out should have written.
	 *
	 * @return int
	 */
	public function rows_expected(): int {
		return $this->rows_expected;
	}

	/**
	 * Detail rows it actually wrote.
	 *
	 * @return int
	 */
	public function rows_written(): int {
		return $this->rows_written;
	}

	/**
	 * THE AGGREGATE the tombstone records (ADR-0016 §5).
	 *
	 * | Message outcomes | `final_status` |
	 * |---|---|
	 * | all `sent` | `sent` |
	 * | **any** `failed` | `failed` |
	 * | at least one `sent`, rest `skipped` | `sent` |
	 * | all `skipped` | `skipped` |
	 *
	 * ⚠ THE LAST ROW IS AN EXTENSION OF THE STATED RULE, AND IT IS DELIBERATE. The
	 * rule as written — "all sent → sent; any failure → failed; none sent → failed"
	 * — considers two outcomes. There is a third: a message can be SKIPPED, by the
	 * per-delivery `woocommerce_email_enabled_{id}` filter declining it or by no
	 * recipient surviving header sanitisation. Folding a skip into `failed` would
	 * report a DELIBERATE THIRD-PARTY REFUSAL as a transport failure, which is
	 * exactly the defect ADR-0012 §5a was written to remove, reached through a
	 * different column. `skipped` is already a member of
	 * `DeliveryRepository::FINAL_STATUSES`, so the honest answer is available and is
	 * used. "None sent → failed" holds unchanged wherever a message was actually
	 * ATTEMPTED.
	 *
	 * AN EMPTY RESULT REPORTS `failed`. Every path through a message records
	 * something — including the throw path — so an empty result means the loop
	 * recorded nothing at all, and a consumed identity with no evidence must not be
	 * reported as a success.
	 *
	 * @return string A member of `DeliveryRepository::FINAL_STATUSES`.
	 */
	public function aggregate_status(): string {
		if ( array() === $this->messages ) {
			return self::FAILED;
		}

		if ( $this->count_of( self::FAILED ) > 0 ) {
			return self::FAILED;
		}

		if ( $this->count_of( self::SENT ) > 0 ) {
			return self::SENT;
		}

		return self::SKIPPED;
	}

	/**
	 * The aggregate as a `RunOutcome` action.
	 *
	 * @return string One of the `RunOutcome` action constants.
	 */
	public function run_action(): string {
		$status = $this->aggregate_status();

		if ( self::SENT === $status ) {
			return RunOutcome::SENT;
		}

		return self::SKIPPED === $status ? RunOutcome::SKIPPED : RunOutcome::FAILED;
	}

	/**
	 * The fan-out as one sentence, for the aggregate row's `reason` column.
	 *
	 * NAMES THE COUNTS, NOT THE PRODUCTS. The per-message rows carry the unit each
	 * message was about; repeating them here would put the order's contents in a row
	 * that already has its own, and the aggregate's job is to answer *did this
	 * delivery work*.
	 *
	 * @return string
	 */
	public function describe(): string {
		$parts = array();

		foreach ( array( self::SENT, self::FAILED, self::SKIPPED ) as $outcome ) {
			$count = $this->count_of( $outcome );

			if ( $count > 0 ) {
				$parts[] = $count . ' ' . $outcome;
			}
		}

		return count( $this->messages ) . ' consolidated messages ('
			. ( array() === $parts ? 'none recorded' : implode( ', ', $parts ) ) . ')';
	}

	/**
	 * Assert at build time that the aggregate can only produce a storable status.
	 *
	 * A seam for the tests rather than a runtime check: `final_status` is a
	 * PHP-policed allowlist, and an aggregate outside it would be silently refused by
	 * `DeliveryRepository`.
	 *
	 * @return string[] The three statuses this class can return.
	 */
	public static function possible_statuses(): array {
		return array_values(
			array_intersect(
				array( self::SENT, self::FAILED, self::SKIPPED ),
				DeliveryRepository::FINAL_STATUSES
			)
		);
	}
}
