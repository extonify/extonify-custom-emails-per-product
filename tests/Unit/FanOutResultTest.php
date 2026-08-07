<?php
/**
 * THE AGGREGATE OUTCOME RULE (ADR-0016 §5).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Extonify\WCEP\Delivery\FanOutResult;
use Extonify\WCEP\Delivery\RunOutcome;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * What ONE tombstone reports for N messages.
 *
 * ⚠ THE FAILURE THIS EXISTS TO PREVENT. Finalising per message would leave the
 * tombstone holding whichever outcome was written LAST, so `{failed, sent}` would
 * report `sent` and lose the failure entirely — a merchant told a delivery worked
 * while one of its messages never reached a customer. The aggregate is computed once,
 * from every message, and that computation is pure so it can be asserted directly
 * rather than inferred from a database row.
 */
final class FanOutResultTest extends UnitTestCase {

	/**
	 * A message descriptor, as the planner produces.
	 *
	 * @param int $index Message index.
	 * @return array
	 */
	private function message( int $index ): array {
		return array(
			'index' => $index,
			'count' => 3,
			'unit'  => 'product:' . ( 100 + $index ),
		);
	}

	/**
	 * A result carrying one outcome per message, in order.
	 *
	 * @param string[] $outcomes Outcome per message.
	 * @return FanOutResult
	 */
	private function result( array $outcomes ): FanOutResult {
		$result = new FanOutResult();

		foreach ( array_values( $outcomes ) as $offset => $outcome ) {
			$result->record( $this->message( $offset + 1 ), $outcome, 1, 1 );
		}

		return $result;
	}

	/**
	 * ⚠ THE AGGREGATE TABLE, EVERY ROW (ADR-0016 §5).
	 *
	 * @dataProvider aggregate_provider
	 *
	 * @param string[] $outcomes Per-message outcomes.
	 * @param string   $expected Expected tombstone status.
	 * @param string   $why      What the row proves.
	 * @return void
	 */
	public function test_the_aggregate( array $outcomes, string $expected, string $why ) {
		$this->assertSame( $expected, $this->result( $outcomes )->aggregate_status(), $why );
	}

	/**
	 * Every combination the rule has to answer.
	 *
	 * @return array<string,array{0:string[],1:string,2:string}>
	 */
	public static function aggregate_provider(): array {
		$sent    = FanOutResult::SENT;
		$failed  = FanOutResult::FAILED;
		$skipped = FanOutResult::SKIPPED;

		return array(
			'all sent'                 => array( array( $sent, $sent, $sent ), 'sent', 'three sent messages did not aggregate to sent' ),
			'one sent'                 => array( array( $sent ), 'sent', 'a single sent message did not aggregate to sent' ),
			'any failure, first'       => array( array( $failed, $sent, $sent ), 'failed', 'a failure in message 1 was lost' ),
			/*
			 * ⚠ THE CASE A PER-MESSAGE FINALISE WOULD GET WRONG. The failure is not the
			 * LAST thing written, so last-write-wins would report `sent` and the merchant
			 * would never learn that message 2 did not go out.
			 */
			'any failure, middle'      => array( array( $sent, $failed, $sent ), 'failed', '⚠ a failure followed by a success was overwritten' ),
			'any failure, last'        => array( array( $sent, $sent, $failed ), 'failed', 'a failure in the last message was lost' ),
			'all failed'              => array( array( $failed, $failed ), 'failed', 'nothing sent did not aggregate to failed' ),
			'failure beats a skip'     => array( array( $skipped, $failed ), 'failed', 'a failure was hidden behind a skip' ),
			/*
			 * ⚠ THE EXTENSION, AND WHY IT IS ONE (ADR-0016 §5). The stated rule — "any
			 * failure → failed; none sent → failed" — considers two outcomes. A message
			 * can also be SKIPPED, by the per-delivery `woocommerce_email_enabled_{id}`
			 * filter declining it. Folding that into `failed` would report a DELIBERATE
			 * third-party refusal as a transport failure, which is the defect ADR-0012 §5a
			 * was written to remove, reached through a different column.
			 */
			'all skipped'              => array( array( $skipped, $skipped ), 'skipped', '⚠ a deliberate refusal was reported as a transport failure' ),
			'one sent, rest skipped'   => array( array( $sent, $skipped ), 'sent', 'a partly-refused fan-out that DID deliver reported otherwise' ),
			'skipped then sent'        => array( array( $skipped, $sent ), 'sent', 'ordering changed the aggregate' ),
		);
	}

	/**
	 * AN EMPTY RESULT REPORTS `failed`.
	 *
	 * Every path through a message records something — including the throw path — so an
	 * empty result means the loop recorded nothing at all. A consumed identity with no
	 * evidence must not be reported as a success.
	 *
	 * @return void
	 */
	public function test_an_empty_result_is_a_failure() {
		$empty = new FanOutResult();

		$this->assertSame( 'failed', $empty->aggregate_status() );
		$this->assertSame( 0, $empty->count() );
		$this->assertSame( RunOutcome::FAILED, $empty->run_action() );
	}

	/**
	 * ⚠ EVERY STATUS THIS CLASS CAN PRODUCE IS STORABLE.
	 *
	 * `final_status` is a PHP-policed allowlist, so an aggregate outside it would be
	 * refused by `DeliveryRepository::set_final_status()` — a delivery finalised
	 * nowhere, with an error in the log and a tombstone left `claimed`.
	 *
	 * @return void
	 */
	public function test_every_possible_aggregate_is_a_storable_final_status() {
		$possible = FanOutResult::possible_statuses();

		$this->assertCount( 3, $possible, 'an aggregate status is not a member of FINAL_STATUSES' );

		foreach ( array( FanOutResult::SENT, FanOutResult::FAILED, FanOutResult::SKIPPED ) as $status ) {
			$this->assertContains( $status, DeliveryRepository::FINAL_STATUSES, "\"{$status}\" cannot be stored" );
		}
	}

	/**
	 * The aggregate maps onto a `RunOutcome` action, so a caller reading the run gets
	 * the same answer the tombstone holds.
	 *
	 * @return void
	 */
	public function test_the_run_action_follows_the_aggregate() {
		$this->assertSame( RunOutcome::SENT, $this->result( array( FanOutResult::SENT ) )->run_action() );
		$this->assertSame( RunOutcome::FAILED, $this->result( array( FanOutResult::SENT, FanOutResult::FAILED ) )->run_action() );
		$this->assertSame( RunOutcome::SKIPPED, $this->result( array( FanOutResult::SKIPPED ) )->run_action() );
	}

	/**
	 * Row counts are summed across messages, so `verify()` can detect a shortfall in
	 * ANY message rather than only the last.
	 *
	 * @return void
	 */
	public function test_row_counts_sum_across_messages() {
		$result = new FanOutResult();

		// Message 1: two recipients, both written. Message 2: two expected, one written.
		$result->record( $this->message( 1 ), FanOutResult::SENT, 2, 2 );
		$result->record( $this->message( 2 ), FanOutResult::SENT, 2, 1 );

		$this->assertSame( 4, $result->rows_expected() );
		$this->assertSame( 3, $result->rows_written(), 'a shortfall in one message was not visible to the aggregate' );

		// Negative counts cannot subtract from the totals.
		$result->record( $this->message( 3 ), FanOutResult::SENT, -1, -1 );
		$this->assertSame( 4, $result->rows_expected() );
		$this->assertSame( 3, $result->rows_written() );
	}

	/**
	 * Each message keeps its OWN outcome and unit, so "which one failed" is answerable.
	 *
	 * @return void
	 */
	public function test_every_message_keeps_its_own_outcome_and_unit() {
		$result = $this->result( array( FanOutResult::SENT, FanOutResult::FAILED, FanOutResult::SENT ) );

		$this->assertSame(
			array(
				array( 1, 'product:101', 'sent' ),
				array( 2, 'product:102', 'failed' ),
				array( 3, 'product:103', 'sent' ),
			),
			array_map(
				static function ( array $message ): array {
					return array( $message['index'], $message['unit'], $message['outcome'] );
				},
				$result->messages()
			)
		);

		$this->assertSame( 2, $result->count_of( FanOutResult::SENT ) );
		$this->assertSame( 1, $result->count_of( FanOutResult::FAILED ) );
		$this->assertSame( 3, $result->count() );
	}

	/**
	 * The description names the counts and NOT the products.
	 *
	 * The per-message rows carry the unit each message was about; repeating them in the
	 * aggregate's log line would put the order's contents somewhere that already has
	 * its own copy.
	 *
	 * @return void
	 */
	public function test_the_description_names_counts_not_products() {
		$described = $this->result( array( FanOutResult::SENT, FanOutResult::FAILED, FanOutResult::SKIPPED ) )->describe();

		$this->assertStringContainsString( '3 consolidated messages', $described );
		$this->assertStringContainsString( '1 sent', $described );
		$this->assertStringContainsString( '1 failed', $described );
		$this->assertStringContainsString( '1 skipped', $described );
		$this->assertStringNotContainsString( 'product:', $described );

		$this->assertStringContainsString( 'none recorded', ( new FanOutResult() )->describe() );
	}
}
