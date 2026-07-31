<?php
/**
 * Phase filtering, rule snapshotting and send-exception containment
 * (ADR-0012 §3, §9, §10).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\DeliveryLogger;
use Extonify\WCEP\Delivery\Orchestrator;
use Extonify\WCEP\Domain\MatchDecision;
use Extonify\WCEP\Domain\TriggerEvent;

/**
 * "Out of scope" has to mean LEFT ALONE, not "processed by whatever path
 * happens to exist".
 *
 * An insert rule reaching this phase was matched, claimed under a mode it never
 * had, and delivered to the customer as a standalone email — content meant to
 * appear inside their normal order email arriving as a surprise message. These
 * tests are what stop that returning.
 */
final class DeliveryPhaseTest extends DeliveryTestCase {

	/**
	 * 1. A rule belonging to ANOTHER PHASE produces nothing at all: no email,
	 *    and no tombstone under EITHER mode.
	 *
	 * @dataProvider out_of_phase_rule_provider
	 *
	 * @param array  $overrides Rule fields putting it in another phase.
	 * @param string $label     Which phase owns it.
	 * @return void
	 */
	public function test_a_rule_from_another_phase_is_left_entirely_untouched( array $overrides, string $label ) {
		$product_id = $this->make_simple_product( 'WCEP Phase ' . md5( $label ) );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule( $product_id, $overrides );

		$result = $this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 0, $label . ' was delivered by this phase.' );

		// The rule was never even EVALUATED, so it has no decision.
		$this->assertNull(
			$result->evaluation()->decision_for( $rule_id ),
			$label . ' reached the matcher; the filter must run BEFORE evaluation.'
		);

		// NO TOMBSTONE UNDER ANY MODE. Claiming under `separate` would consume
		// an identity the rule never had, so the phase that owns it could never
		// deliver it.
		foreach ( array( 'separate', 'insert' ) as $mode ) {
			$this->assertNull(
				$this->deliveries->find( $order_id, $rule_id, $mode, 'status:completed' ),
				$label . ' consumed an identity under mode=' . $mode . '.'
			);
		}

		$this->assertSame( array(), $this->tombstones_for( $order_id ), $label . ' wrote a tombstone.' );
	}

	/**
	 * Rules owned by Prompt 5 (insert) and Prompt 6 (delayed).
	 *
	 * @return array<string,array{0:array,1:string}>
	 */
	public static function out_of_phase_rule_provider(): array {
		return array(
			'insert mode'         => array(
				array(
					'delivery_mode'   => 'insert',
					'native_email_id' => 'customer_completed_order',
					'insert_position' => 'after_order_table',
				),
				'an insert-mode rule',
			),
			'delayed one day'     => array( array( 'delay_seconds' => 86400 ), 'a rule delayed by one day' ),
			'delayed one second'  => array( array( 'delay_seconds' => 1 ), 'a rule delayed by one second' ),
			/*
			 * ⚠ ADDED PROMPT 5C, AND IT WAS A LIVE DEFECT RATHER THAN A GAP.
			 * Prompt 5B gave `consolidation` validated storage, so `daily` became
			 * storable — and nothing filtered it, so the rule was delivered with
			 * ordinary IMMEDIATE, once-per-trigger behaviour. That is `none`'s
			 * behaviour under another name: the merchant asked for a daily digest and
			 * got an email per order. The Prompt 4 shape exactly — out-of-scope
			 * behaviour reachable through DATA rather than through code.
			 */
			'consolidation daily' => array( array( 'consolidation' => 'daily' ), 'a rule consolidated daily' ),
			'consolidation weekly' => array( array( 'consolidation' => 'weekly' ), 'a rule consolidated weekly' ),
			'consolidation per_order' => array( array( 'consolidation' => 'per_order' ), 'a rule consolidated per order' ),
		);
	}

	/**
	 * 1a. A rule belonging to BOTH other phases — insert AND delayed — cannot be
	 *     stored at all since Prompt 5.
	 *
	 * This data set used to live in the provider above, where it asserted that
	 * the phase filter left such a rule untouched. ADR-0013 §2 now refuses the
	 * combination at the REPOSITORY, which is strictly stronger: the rule never
	 * reaches the filter because it never reaches the database. Asserted here so
	 * the case is still covered and the reason it moved is on the record.
	 *
	 * @return void
	 */
	public function test_an_insert_rule_with_a_delay_cannot_be_stored_at_all() {
		$refused = $this->rules->insert(
			array(
				'name'            => 'insert and delayed',
				'status'          => 'active',
				'delivery_mode'   => 'insert',
				'native_email_id' => 'customer_completed_order',
				'delay_seconds'   => 604800,
			)
		);

		$this->assertSame( 0, $refused, 'A rule belonging to two other phases was stored.' );
	}

	/**
	 * 1b. THE ORDERING MATTERS: an insert rule carrying `stop_processing` must
	 *     not halt the separate rules, because this is not its phase.
	 *
	 * A filter applied AFTER evaluation could not undo a halt that had already
	 * changed every later decision.
	 *
	 * @return void
	 */
	public function test_an_insert_rule_with_stop_processing_does_not_halt_this_phase() {
		$product_id = $this->make_simple_product( 'WCEP Phase Halt' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		// Lower priority, so it would run FIRST and halt everything after it.
		$insert_halter = $this->make_sending_rule(
			$product_id,
			array(
				'name'            => 'insert rule that halts',
				'priority'        => 1,
				'delivery_mode'   => 'insert',
				// Required since Prompt 5 for the rule to be storable at all
				// (ADR-0013 §2); irrelevant to what this test asserts.
				'native_email_id' => 'customer_completed_order',
				'stop_processing' => 1,
			)
		);
		$separate      = $this->make_sending_rule(
			$product_id,
			array(
				'name'     => 'separate rule that must still send',
				'priority' => 10,
			)
		);

		$result = $this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertNull( $result->evaluation()->decision_for( $insert_halter ), 'The insert rule was evaluated.' );
		$this->assertSame(
			MatchDecision::MATCHED,
			$result->evaluation()->decision_for( $separate )->reason(),
			'An out-of-phase rule halted this phase.'
		);

		$this->assertMailCount( 1 );

		$tombstone = $this->tombstone( $order_id, $separate, 'status:completed' );
		$this->assertNotNull( $tombstone );
		$this->track_delivery( (int) $tombstone['id'] );
		$this->assertSame( 'sent', $tombstone['final_status'] );

		$this->assertNull( $this->deliveries->find( $order_id, $insert_halter, 'separate', 'status:completed' ) );
		$this->assertNull( $this->deliveries->find( $order_id, $insert_halter, 'insert', 'status:completed' ) );
	}

	/**
	 * 1c. The in-phase case is unchanged: separate + no delay delivers normally.
	 *
	 * @return void
	 */
	public function test_a_separate_undelayed_rule_still_delivers() {
		$product_id = $this->make_simple_product( 'WCEP In Phase' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule( $product_id, array( 'delay_seconds' => 0 ) );

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 1 );
		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:completed' );
		$this->track_delivery( (int) $tombstone['id'] );
		$this->assertSame( 'sent', $tombstone['final_status'] );
	}

	/**
	 * 1d. The filter is a pure function, so its boundary is provable directly.
	 *
	 * @return void
	 */
	public function test_the_phase_filter_boundary() {
		$rules = array(
			array( 'id' => 1, 'delivery_mode' => 'separate', 'delay_seconds' => 0, 'consolidation' => 'none' ),
			array( 'id' => 2, 'delivery_mode' => 'insert', 'delay_seconds' => 0, 'consolidation' => 'none' ),
			array( 'id' => 3, 'delivery_mode' => 'separate', 'delay_seconds' => 1, 'consolidation' => 'none' ),
			array( 'id' => 4, 'delivery_mode' => 'insert', 'delay_seconds' => 86400, 'consolidation' => 'none' ),
			array( 'id' => 5, 'delivery_mode' => '', 'delay_seconds' => 0, 'consolidation' => 'none' ),
			array( 'id' => 6 ),
			array( 'id' => 7, 'delivery_mode' => 'separate', 'delay_seconds' => 0, 'consolidation' => 'daily' ),
			array( 'id' => 8, 'delivery_mode' => 'separate', 'delay_seconds' => 0, 'consolidation' => 'weekly' ),
			array( 'id' => 9, 'delivery_mode' => 'separate', 'delay_seconds' => 0, 'consolidation' => '' ),
			// A row with the column absent keeps the default, so it is deliverable —
			// the filter must not reject a rule for a column it never carried.
			array( 'id' => 10, 'delivery_mode' => 'separate', 'delay_seconds' => 0 ),
		);

		$this->assertSame(
			array( 1, 10 ),
			array_column( Orchestrator::deliverable_in_this_phase( $rules ), 'id' ),
			'Only separate + delay_seconds = 0 + consolidation = none belongs to this phase.'
		);
	}

	/**
	 * 1e / gate 15. EVERY UNIMPLEMENTED-BEHAVIOUR COLUMN IS ENUMERATED, and each
	 *               one's non-default value is filtered out.
	 *
	 * The enumeration is the contract: a future column carrying behaviour nobody
	 * has built yet is added HERE, and both phases inherit the filtering. Growing
	 * the list one incident at a time is what let `consolidation` ship deliverable.
	 *
	 * @return void
	 */
	public function test_every_unimplemented_behaviour_column_is_filtered() {
		$columns = Orchestrator::UNIMPLEMENTED_BEHAVIOUR_DEFAULTS;

		$this->assertSame(
			array( 'delay_seconds', 'consolidation' ),
			array_keys( $columns ),
			'The unimplemented-behaviour enumeration changed; both phases and gate 15 depend on it.'
		);

		foreach ( $columns as $column => $default ) {
			$base = array(
				'id'            => 1,
				'delivery_mode' => 'separate',
				'delay_seconds' => 0,
				'consolidation' => 'none',
			);

			$this->assertTrue(
				Orchestrator::behaviour_is_implemented( $base ),
				'The all-defaults rule is not deliverable.'
			);

			foreach ( array( 'daily', '1', '86400', 'per_order', 'anything' ) as $value ) {
				if ( (string) $value === (string) $default ) {
					continue;
				}

				$candidate            = $base;
				$candidate[ $column ] = $value;

				$this->assertFalse(
					Orchestrator::behaviour_is_implemented( $candidate ),
					sprintf( '%s = "%s" was treated as implemented behaviour.', $column, $value )
				);
				$this->assertSame(
					array(),
					Orchestrator::deliverable_in_this_phase( array( $candidate ) ),
					sprintf( '%s = "%s" reached the separate phase.', $column, $value )
				);
			}
		}

		fwrite(
			STDERR,
			"\n[5C item 2 / gate 15] unimplemented-behaviour columns filtered by BOTH phases: "
			. implode( ', ', array_map( static function ( $c, $d ) {
				return $c . ' (only "' . $d . '")';
			}, array_keys( $columns ), $columns ) ) . "\n"
		);
	}

	/**
	 * 2. ONE SNAPSHOT: an admin save between matching and sending does not make
	 *    the rule match on the old targeting and send the new content.
	 *
	 * The edit is performed from inside the matcher's own call, which is the
	 * only place it can land between the two — a `query` filter fires while the
	 * rule fetch is in flight.
	 *
	 * @return void
	 */
	public function test_a_concurrent_edit_cannot_split_matching_from_sending() {
		$product_id = $this->make_simple_product( 'WCEP Snapshot' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule(
			$product_id,
			array(
				'subject'    => 'ORIGINAL subject',
				'content'    => '<p>ORIGINAL content.</p>',
				'recipients' => array( 'to' => array( 'customer' ) ),
			)
		);

		$before = $this->rules->find( $rule_id );

		/*
		 * The admin saves NEW content and NEW recipients AFTER the rules were
		 * fetched but BEFORE anything is sent.
		 *
		 * The `query` filter fires BEFORE a statement executes, so editing when
		 * the rules SELECT is seen would land before the fetch and prove
		 * nothing. Instead the filter ARMS on that SELECT and performs the edit
		 * on the next statement — by which time the fetch has returned and the
		 * snapshot is built.
		 */
		$armed  = false;
		$busy   = false;
		$edited = false;

		$editor = function ( $query ) use ( &$armed, &$busy, &$edited, $rule_id ) {
			if ( $busy || $edited ) {
				return $query;
			}

			if ( $armed ) {
				$armed  = false;
				$edited = true;
				$busy   = true;
				$this->rules->update(
					$rule_id,
					array(
						'subject'    => 'EDITED subject',
						'content'    => '<p>EDITED content.</p>',
						'recipients' => array( 'to' => array( 'someone-else@example.test' ) ),
					)
				);
				$busy = false;
				return $query;
			}

			if ( 1 === preg_match( '/SELECT \* FROM \S*extonify_wcep_rules WHERE status/i', (string) $query ) ) {
				$armed = true;
			}

			return $query;
		};

		add_filter( 'query', $editor );
		try {
			$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );
		} finally {
			remove_filter( 'query', $editor );
		}

		$this->assertTrue( $edited, 'The fixture never performed the concurrent edit.' );

		$after = $this->rules->find( $rule_id );
		$this->assertSame( 'EDITED subject', $after['subject'], 'The concurrent edit did not land.' );
		$this->assertGreaterThan( (int) $before['revision'], (int) $after['revision'] );

		// THE DELIVERY USED THE MATCHED SNAPSHOT THROUGHOUT.
		$this->assertMailCount( 1 );
		$mail = $this->last_mail();

		$this->assertSame( 'ORIGINAL subject', $mail['subject'], 'The send used content the matcher never saw.' );
		$this->assertStringContainsString( 'ORIGINAL content.', (string) $mail['message'] );
		$this->assertSame( 'wcep-matching@example.test', $mail['to'], 'The send used recipients the matcher never saw.' );

		// And the audit records the revision that ACTUALLY matched.
		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:completed' );
		$this->track_delivery( (int) $tombstone['id'] );

		$this->assertSame(
			(int) $before['revision'],
			(int) $tombstone['rule_revision_sent'],
			'The audit recorded a revision that never produced this match.'
		);
	}

	/**
	 * 3. A SEND THAT THROWS is contained: the WooCommerce status hook does not
	 *    throw, nothing is sent, and the failure is recorded.
	 *
	 * Driven through the real `woocommerce_order_status_changed` hook, because
	 * the point of the fix is what happens to the MERCHANT'S ORDER UPDATE.
	 *
	 * @return void
	 */
	public function test_a_throwing_send_never_escapes_the_order_event() {
		$product_id = $this->make_simple_product( 'WCEP Throwing Send' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule( $product_id, array( 'trigger_value' => 'processing' ) );

		$thrower = static function () {
			throw new \RuntimeException( 'SMTP plugin exploded mid-send' );
		};

		// Priority 0: AHEAD of the capture filter at priority 1, so the throw
		// happens instead of the capture short-circuiting the send.
		add_filter( 'pre_wp_mail', $thrower, 0 );

		try {
			// THE ASSERTION THAT MATTERS: this does not throw.
			do_action( 'woocommerce_order_status_changed', $order_id, 'pending', 'processing', $order );
		} finally {
			remove_filter( 'pre_wp_mail', $thrower, 0 );
		}

		$this->assertMailCount( 0, 'A throwing send still delivered something.' );

		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:processing' );
		$this->assertNotNull( $tombstone, 'The claim never happened, so this proves nothing about the throw.' );
		$this->track_delivery( (int) $tombstone['id'] );

		$this->assertSame(
			'failed',
			$tombstone['final_status'],
			'The tombstone was left `claimed` forever while the identity blocked every retry.'
		);

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 1, $rows, 'A throwing send wrote no detail row.' );
		$this->assertSame( 'failed', $rows[0]['state'] );
		$this->assertStringContainsString( 'SMTP plugin exploded mid-send', (string) $rows[0]['failure_message'] );
		$this->assertStringContainsString( 'RuntimeException', (string) $rows[0]['failure_message'] );

		// The shared email object is clean, so the NEXT delivery is unaffected.
		$email = $this->live_email();
		$this->assertSame( '', $email->recipient );
		$this->assertSame( '', $email->delivery_subject );
		$this->assertNull( $email->object );
	}

	/**
	 * 3b. A `\TypeError` — not an `\Exception` — is contained too. A badly-typed
	 *     third-party filter is exactly as fatal to the order update.
	 *
	 * @return void
	 */
	public function test_a_typeerror_during_send_is_also_contained() {
		$product_id = $this->make_simple_product( 'WCEP TypeError' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$this->make_sending_rule( $product_id, array( 'trigger_value' => 'processing' ) );

		$thrower = static function () {
			throw new \TypeError( 'a third-party filter returned the wrong type' );
		};

		add_filter( 'pre_wp_mail', $thrower, 0 );

		try {
			do_action( 'woocommerce_order_status_changed', $order_id, 'pending', 'processing', $order );
		} finally {
			remove_filter( 'pre_wp_mail', $thrower, 0 );
		}

		$this->assertMailCount( 0 );

		$tombstones = $this->tombstones_for( $order_id );
		$this->assertCount( 1, $tombstones );
		$this->assertSame( 'failed', $tombstones[0]['final_status'] );
	}

	/**
	 * 4. A DETAIL-ROW SHORTFALL is detected and reported rather than reported as
	 *    a successful send.
	 *
	 * @return void
	 */
	public function test_a_failed_detail_insert_is_reported() {
		$product_id = $this->make_simple_product( 'WCEP Shortfall' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();
		$rule_id    = $this->make_sending_rule( $product_id );

		$claim = $this->deliveries->claim( $order_id, $rule_id, DeliveryLogger::MODE, 'status:completed' );
		$this->track_delivery( (int) $claim['delivery_id'] );

		$recipients = \Extonify\WCEP\Delivery\RecipientResolver::resolve(
			array( 'to' => array( 'customer' ), 'cc' => array( 'cc@example.test' ) ),
			array( 'customer' => 'shortfall@example.test', 'admin' => 'admin@example.test' )
		);
		$this->assertCount( 2, $recipients->entries() );

		$logger = $this->logger_with_failing_details();
		$result = $logger->record_send( (int) $claim['delivery_id'], $recipients, 'Shortfall subject', true );

		$this->assertFalse( $result['success'], 'A total detail-row failure was reported as success.' );
		$this->assertSame( 2, $result['rows_expected'] );
		$this->assertSame( 0, $result['rows_written'] );
		$this->assertNotSame( array(), $logger->recorded_errors, 'The shortfall was not logged.' );
		$this->assertStringContainsString( 'was not fully recorded', $logger->recorded_errors[0] );
		$this->assertStringContainsString( (string) $claim['delivery_id'], $logger->recorded_errors[0] );
	}

	/**
	 * 4b. A FINALISATION failure is detected and reported.
	 *
	 * @return void
	 */
	public function test_a_failed_finalisation_is_reported() {
		$product_id = $this->make_simple_product( 'WCEP Finalise Fail' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();
		$rule_id    = $this->make_sending_rule( $product_id );

		$claim = $this->deliveries->claim( $order_id, $rule_id, DeliveryLogger::MODE, 'status:completed' );
		$this->track_delivery( (int) $claim['delivery_id'] );

		$logger = $this->logger_with_failing_finalisation();
		$result = $logger->record_skip( $claim, 'a reason' );

		$this->assertFalse( $result['success'], 'A finalisation failure was reported as success.' );
		$this->assertFalse( $result['finalized'] );
		$this->assertSame( 1, $result['rows_written'], 'The detail row itself should still have been written.' );
		$this->assertNotSame( array(), $logger->recorded_errors );
		$this->assertStringContainsString( 'NOT finalized', $logger->recorded_errors[0] );
	}

	/**
	 * 4c. The happy path reports success with matching counts, so the failure
	 *     assertions above are about failure and not about a broken shape.
	 *
	 * @return void
	 */
	public function test_a_clean_send_reports_success() {
		$product_id = $this->make_simple_product( 'WCEP Clean Result' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();
		$rule_id    = $this->make_sending_rule( $product_id );

		$claim = $this->deliveries->claim( $order_id, $rule_id, DeliveryLogger::MODE, 'status:completed' );
		$this->track_delivery( (int) $claim['delivery_id'] );

		$recipients = \Extonify\WCEP\Delivery\RecipientResolver::resolve(
			array( 'to' => array( 'customer' ) ),
			array( 'customer' => 'clean@example.test', 'admin' => 'admin@example.test' )
		);

		$result = ( new DeliveryLogger( $this->deliveries, $this->details ) )
			->record_send( (int) $claim['delivery_id'], $recipients, 'Clean subject', true );

		$this->assertSame(
			array(
				'success'       => true,
				'rows_expected' => 1,
				'rows_written'  => 1,
				'finalized'     => true,
			),
			$result
		);
	}

	/**
	 * A logger whose detail inserts always fail, capturing its own error log.
	 *
	 * Captured by SUBCLASSING rather than by hooking WooCommerce: `WC_Logger`
	 * exposes no "a line was logged" action, and registering a log handler would
	 * make this test depend on WooCommerce's handler pipeline instead of on the
	 * behaviour under test.
	 *
	 * @return DeliveryLogger
	 */
	private function logger_with_failing_details(): DeliveryLogger {
		$details = new class() extends \Extonify\WCEP\Repository\DeliveryDetailRepository {
			/**
			 * Always fail.
			 *
			 * @param int   $delivery_id Tombstone id.
			 * @param array $data        Row.
			 * @return int
			 */
			public function insert( int $delivery_id, array $data ): int {
				return 0;
			}
		};

		return $this->recording_logger( $this->deliveries, $details );
	}

	/**
	 * A logger whose finalisation always fails, capturing its own error log.
	 *
	 * @return DeliveryLogger
	 */
	private function logger_with_failing_finalisation(): DeliveryLogger {
		$deliveries = new class() extends \Extonify\WCEP\Repository\DeliveryRepository {
			/**
			 * Always fail.
			 *
			 * @param int    $delivery_id        Tombstone id.
			 * @param string $final_status       Status.
			 * @param int    $rule_revision_sent Revision.
			 * @return bool
			 */
			public function set_final_status( int $delivery_id, string $final_status, int $rule_revision_sent = 0 ): bool {
				return false;
			}
		};

		return $this->recording_logger( $deliveries, $this->details );
	}

	/**
	 * A `DeliveryLogger` that records what it would have sent to the
	 * WooCommerce error log.
	 *
	 * @param \Extonify\WCEP\Repository\DeliveryRepository       $deliveries Tombstone storage.
	 * @param \Extonify\WCEP\Repository\DeliveryDetailRepository $details    Detail storage.
	 * @return DeliveryLogger
	 */
	private function recording_logger( $deliveries, $details ): DeliveryLogger {
		return new class( $deliveries, $details ) extends DeliveryLogger {
			/**
			 * Errors this logger recorded.
			 *
			 * @var string[]
			 */
			public $recorded_errors = array();

			/**
			 * Capture instead of writing to the WooCommerce log.
			 *
			 * @param string $message Detail.
			 * @return void
			 */
			protected function log_error( string $message ): void {
				$this->recorded_errors[] = $message;
			}
		};
	}
}
