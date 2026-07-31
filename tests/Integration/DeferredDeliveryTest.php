<?php
/**
 * ADR-0008 deferral and the rule-freshness contract (ADR-0012 §7, ADR-0011 §7a).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\DeferredEvaluation;
use Extonify\WCEP\Delivery\DeliveryLogger;
use Extonify\WCEP\Delivery\Orchestrator;
use Extonify\WCEP\Domain\MatchDecision;
use Extonify\WCEP\Domain\TriggerEvent;
use Extonify\WCEP\Install\Deactivator;
use Extonify\WCEP\Matching\ItemResolver;
use Extonify\WCEP\Matching\RuleMatcher;

/**
 * A status event on a zero-item order asks a question that cannot be answered
 * yet, so the engine defers once and asks again.
 *
 * The job is driven through `do_action( DeferredEvaluation::HOOK, ... )` rather
 * than by calling the method, so the registration itself is exercised — a job
 * that is never wired up would pass a direct call and fail in production.
 */
final class DeferredDeliveryTest extends DeliveryTestCase {

	/**
	 * Scheduled actions created by this test, cancelled on teardown.
	 *
	 * @var array[]
	 */
	private $scheduled = array();

	/**
	 * Cancel anything this test scheduled.
	 *
	 * @after
	 * @return void
	 */
	protected function tear_down_scheduled_actions() {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		foreach ( $this->scheduled as $args ) {
			as_unschedule_all_actions( DeferredEvaluation::HOOK, $args, DeferredEvaluation::GROUP );
		}

		$this->scheduled = array();
	}

	/**
	 * An order with NO line items, created the way the REST API and importers
	 * do it — status first, items later.
	 *
	 * @return \WC_Order
	 */
	private function make_empty_order(): \WC_Order {
		$order = wc_create_order();
		$order->set_billing_email( 'deferred@example.test' );
		$order->save();

		$this->order_ids[] = (int) $order->get_id();

		$this->assertCount( 0, $order->get_items(), 'The fixture order should have no items yet.' );

		return $order;
	}

	/**
	 * Track a scheduled action for teardown.
	 *
	 * @param int          $order_id Order id.
	 * @param TriggerEvent $event    Trigger event.
	 * @return void
	 */
	private function track_scheduled( int $order_id, TriggerEvent $event ): void {
		$this->scheduled[] = DeferredEvaluation::args( $order_id, $event );
	}

	/**
	 * 11. A zero-item order schedules EXACTLY ONE job and claims NOTHING.
	 *     After items are attached, the job claims once under the ORIGINAL
	 *     identity and sends. A second run is suppressed.
	 *
	 * @return void
	 */
	public function test_the_full_deferral_cycle() {
		$product_id = $this->make_simple_product( 'WCEP Deferred' );
		$order      = $this->make_empty_order();
		$order_id   = (int) $order->get_id();
		$event      = TriggerEvent::status( 'processing' );

		$this->track_scheduled( $order_id, $event );

		$rule_id = $this->make_sending_rule( $product_id, array( 'trigger_value' => 'processing' ) );

		// --- The immediate path: defers, claims nothing, sends nothing. ------
		$result = $this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$this->assertTrue( $result['status']->deferred(), 'A zero-item order must defer (ADR-0008).' );
		$this->assertMailCount( 0 );
		$this->assertSame( array(), $this->tombstones_for( $order_id ), 'The deferring path must claim nothing.' );

		// EXACTLY ONE job, and firing the same trigger again does not add a
		// second — the scheduler refuses a duplicate argument set.
		$this->assertTrue( DeferredEvaluation::is_scheduled( $order_id, $event ), 'No job was scheduled.' );
		$this->assertSame( 1, $this->pending_job_count( $order_id, $event ) );

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );
		$this->assertSame( 1, $this->pending_job_count( $order_id, $event ), 'A duplicate job was scheduled.' );

		// --- The items arrive. -----------------------------------------------
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->calculate_totals();
		$order->save();

		// --- The job runs: claims under the ORIGINAL identity and sends. -----
		$this->run_job( $order_id, $event );

		$this->assertMailCount( 1, 'The deferred run should have sent exactly one email.' );

		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:processing' );
		$this->assertNotNull( $tombstone, 'The deferred run did not claim.' );
		$this->track_delivery( (int) $tombstone['id'] );

		$this->assertSame(
			'status:processing',
			$tombstone['trigger_identity'],
			'The deferred run must claim under the ORIGINAL identity, or it could double-send against the immediate path.'
		);
		$this->assertSame( 'sent', $tombstone['final_status'] );

		// --- A second run is suppressed. --------------------------------------
		$this->run_job( $order_id, $event );

		$this->assertMailCount( 1, 'A second deferred run sent a duplicate.' );
		$this->assertSame(
			1,
			(int) $this->deliveries->find_by_id( (int) $tombstone['id'] )['suppressed_count']
		);
	}

	/**
	 * 11b. The immediate path and the deferred path share ONE identity, so
	 *      whichever runs second sends nothing.
	 *
	 * @return void
	 */
	public function test_the_immediate_path_and_the_deferred_job_cannot_both_send() {
		$product_id = $this->make_simple_product( 'WCEP Race' );
		$order      = $this->make_empty_order();
		$order_id   = (int) $order->get_id();
		$event      = TriggerEvent::status( 'processing' );

		$this->track_scheduled( $order_id, $event );

		$rule_id = $this->make_sending_rule( $product_id, array( 'trigger_value' => 'processing' ) );

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->calculate_totals();
		$order->save();

		// The immediate path fires again now that items exist — and sends.
		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );
		$this->assertMailCount( 1 );

		// The deferred job then runs against the SAME identity.
		$this->run_job( $order_id, $event );

		$this->assertMailCount( 1, 'The deferred job double-sent against the immediate path.' );

		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:processing' );
		$this->track_delivery( (int) $tombstone['id'] );
		$this->assertSame( 1, (int) $tombstone['suppressed_count'] );
	}

	/**
	 * 11c. ONE DEFERRAL, NEVER A CHAIN. A job that still finds no items does
	 *      not schedule another.
	 *
	 * @return void
	 */
	public function test_a_deferred_run_never_schedules_another() {
		$product_id = $this->make_simple_product( 'WCEP No Chain' );
		$order      = $this->make_empty_order();
		$order_id   = (int) $order->get_id();
		$event      = TriggerEvent::status( 'processing' );

		// A CANDIDATE RULE IS REQUIRED FOR THE FIRST DEFERRAL TO HAPPEN AT ALL
		// (ADR-0012 §7a). Without one there is nothing to re-ask, so nothing is
		// scheduled — which would make this test pass for the wrong reason.
		$this->make_sending_rule( $product_id, array( 'trigger_value' => 'processing' ) );

		$this->track_scheduled( $order_id, $event );

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );
		$this->assertSame( 1, $this->pending_job_count( $order_id, $event ) );

		// Consume the pending job, then run it with the order still empty.
		$this->cancel_pending( $order_id, $event );
		$this->assertSame( 0, $this->pending_job_count( $order_id, $event ) );

		$this->run_job( $order_id, $event );

		$this->assertSame(
			0,
			$this->pending_job_count( $order_id, $event ),
			'The deferred job scheduled another deferral — that is a queue that never empties.'
		);
		$this->assertMailCount( 0 );
		$this->assertSame( array(), $this->tombstones_for( $order_id ) );
	}

	/**
	 * 11d. A ZERO-ITEM ORDER DEFERS ONLY WHEN THERE IS SOMETHING TO DEFER FOR
	 *      (ADR-0008 §4, ADR-0012 §7a).
	 *
	 * The deferral decision used to be taken BEFORE the rule list was consulted,
	 * so every zero-item order queued a job that could only ever re-discover the
	 * same nothing. A status change evaluates TWO families, so a bulk import
	 * queued two useless actions per order — which is why this test counts jobs
	 * across both identities rather than one.
	 *
	 * @return void
	 */
	public function test_a_zero_item_order_defers_only_when_a_candidate_exists() {
		/*
		 * EACH CASE MOVES THE ORDER TO ITS OWN STATUS. The rules a case creates
		 * live until teardown, so sharing one destination status would let the
		 * "separate zero-delay rule" case supply a candidate to every case after
		 * it — and the test would pass while measuring the wrong thing.
		 */
		$cases = array(
			'no active rules'                => array( 'to' => 'processing', 'rule' => null, 'actions' => 0 ),
			// An insert rule needs a target email to be storable at all since
			// Prompt 5 (ADR-0013 §2); it is still out of this phase.
			'only an insert rule'            => array(
				'to'      => 'on-hold',
				'rule'    => array(
					'delivery_mode'   => 'insert',
					'native_email_id' => 'customer_on_hold_order',
				),
				'actions' => 0,
			),
			'only a delayed rule'            => array( 'to' => 'cancelled', 'rule' => array( 'delay_seconds' => 604800 ), 'actions' => 0 ),
			'a rule on another trigger only' => array( 'to' => 'refunded', 'rule' => array( 'trigger_value' => 'completed' ), 'actions' => 0 ),
			// One, not two: the rule targets `status:{to}`, so the
			// `transition:pending>{to}` family still has no candidate.
			'a separate zero-delay rule'     => array( 'to' => 'completed', 'rule' => array(), 'actions' => 1 ),
		);

		$table = array();

		foreach ( $cases as $label => $case ) {
			$to         = $case['to'];
			$product_id = $this->make_simple_product( 'WCEP Defer ' . substr( md5( $label ), 0, 8 ) );
			$order      = $this->make_empty_order();
			$order_id   = (int) $order->get_id();

			$status     = TriggerEvent::status( $to );
			$transition = TriggerEvent::transition( 'pending', $to );

			$this->track_scheduled( $order_id, $status );
			$this->track_scheduled( $order_id, $transition );

			if ( null !== $case['rule'] ) {
				$this->make_sending_rule(
					$product_id,
					array_merge( array( 'trigger_value' => $to ), $case['rule'] )
				);
			}

			$results = $this->orchestrator()->handle_status_change( $order_id, 'pending', $to );

			$jobs = $this->pending_job_count( $order_id, $status )
				+ $this->pending_job_count( $order_id, $transition );

			$deferred = array();
			foreach ( $results as $family => $outcome ) {
				if ( $outcome->deferred() ) {
					$deferred[] = $family;
				}
			}

			$table[] = sprintf(
				'  %-32s -> %-11s deferred=%-12s actions=%d',
				$label,
				$to,
				array() === $deferred ? 'none' : implode( '+', $deferred ),
				$jobs
			);

			$this->assertSame(
				$case['actions'],
				$jobs,
				$label . ': wrong number of Action Scheduler jobs queued for a zero-item order.'
			);

			// Nothing is ever claimed on a zero-item order, deferred or not.
			$this->assertSame( array(), $this->tombstones_for( $order_id ), $label );
			$this->assertMailCount( 0, $label );

			$this->assertCount(
				$case['actions'],
				$deferred,
				$label . ': the deferral flag disagrees with what was scheduled.'
			);
		}

		fwrite( STDERR, "\n[4b item 2] zero-item deferral decision table:\n" . implode( "\n", $table ) . "\n" );
	}

	/**
	 * 12. THE OWED FRESHNESS TEST (ADR-0011 §7a, `docs/p2-backlog.md`).
	 *
	 * Fetch candidate rules, disable one in the DATABASE without touching any
	 * fetched array, run the REAL deferred job, and assert the disabled rule
	 * neither sends nor is silently dropped.
	 *
	 * This is the test the backlog has owed since Prompt 3b, and it is only
	 * writable now that the deferred job exists. It proves the freshness
	 * obligation is genuinely discharged at execution time rather than assumed:
	 * the job re-fetches, so a rule disabled during the delay is simply absent
	 * from the fetch and cannot send.
	 *
	 * @return void
	 */
	public function test_a_rule_disabled_during_the_delay_does_not_send() {
		$product_id = $this->make_simple_product( 'WCEP Freshness' );
		$order      = $this->make_empty_order();
		$order_id   = (int) $order->get_id();
		$event      = TriggerEvent::status( 'processing' );

		$this->track_scheduled( $order_id, $event );

		$doomed   = $this->make_sending_rule(
			$product_id,
			array(
				'name'          => 'disabled during the delay',
				'trigger_value' => 'processing',
			)
		);
		$survivor = $this->make_sending_rule(
			$product_id,
			array(
				'name'          => 'still enabled',
				'trigger_value' => 'processing',
			)
		);

		// Candidates are fetched here and NOT handed to anything — exactly the
		// stale set the contract forbids passing to the matcher.
		$fetched = $this->rules->find_active_for_trigger( 'status', 'processing' );
		$this->assertContains( $doomed, array_column( $fetched, 'id' ) );

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );
		$this->assertMailCount( 0 );

		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->calculate_totals();
		$order->save();

		// Disabled in the DATABASE, with the fetched array left untouched.
		$this->assertTrue( $this->rules->update( $doomed, array( 'status' => 'inactive' ) ) );
		$this->assertSame( 'inactive', $this->rules->find( $doomed )['status'] );

		$this->run_job( $order_id, $event );

		// The disabled rule sent nothing and claimed nothing.
		$this->assertNull(
			$this->tombstone( $order_id, $doomed, 'status:processing' ),
			'A rule disabled during the delay claimed an identity.'
		);

		// The still-enabled rule DID send — so the absence above is the
		// disabling, not a broken deferred path.
		$survivor_tombstone = $this->tombstone( $order_id, $survivor, 'status:processing' );
		$this->assertNotNull( $survivor_tombstone, 'The deferred job did not run at all.' );
		$this->track_delivery( (int) $survivor_tombstone['id'] );
		$this->assertSame( 'sent', $survivor_tombstone['final_status'] );

		$this->assertMailCount( 1, 'Exactly one rule should have sent.' );
		$this->assertSame( 'deferred@example.test', $this->last_mail()['to'] );

		// NOT SILENTLY DROPPED: re-enabling makes it deliverable again, so its
		// identity was genuinely preserved rather than consumed.
		$this->assertTrue( $this->rules->update( $doomed, array( 'status' => 'active' ) ) );
		$this->run_job( $order_id, $event );

		$revived = $this->tombstone( $order_id, $doomed, 'status:processing' );
		$this->assertNotNull( $revived, 'The re-enabled rule could not claim its preserved identity.' );
		$this->track_delivery( (int) $revived['id'] );
		$this->assertSame( 'sent', $revived['final_status'] );
		$this->assertMailCount( 2 );
	}

	/**
	 * 12b. A rule handed to the matcher already marked inactive is REPORTED
	 *      `rule_disabled` and claims a skip — the other half of the contract,
	 *      and the reason code's only legitimate caller.
	 *
	 * @return void
	 */
	public function test_a_row_marked_inactive_is_recorded_as_rule_disabled() {
		$product_id = $this->make_simple_product( 'WCEP Disabled Row' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule( $product_id );

		$fetched = $this->rules->find_active_for_trigger( 'status', 'completed' );

		$stale = array();
		foreach ( $fetched as $rule ) {
			if ( (int) $rule['id'] === $rule_id ) {
				// Stands in for a deferred job that re-read the rule, found it
				// disabled, and wants the outcome in the log rather than
				// silently dropped (ADR-0011 §7a).
				$rule['status'] = 'inactive';
			}
			$stale[] = $rule;
		}

		$result = $this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ), $stale );

		$this->assertSame( MatchDecision::RULE_DISABLED, $result->evaluation()->decision_for( $rule_id )->reason() );
		$this->assertMailCount( 0 );

		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:completed' );
		$this->assertNotNull( $tombstone, 'rule_disabled must claim (ADR-0012 §2).' );
		$this->track_delivery( (int) $tombstone['id'] );
		$this->assertSame( 'skipped', $tombstone['final_status'] );

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertSame( 'skipped', $rows[0]['state'] );
		$this->assertStringContainsString( MatchDecision::RULE_DISABLED, (string) $rows[0]['reason'] );
	}

	/**
	 * 5. SCHEDULING OUTCOMES are distinguished, not collapsed into a boolean.
	 *
	 * `false` used to mean "already scheduled", "scheduler unavailable" and
	 * "scheduling failed" all at once, and the logger reported every one of them
	 * as "already scheduled" — so a deferral that never scheduled, meaning a
	 * matching order silently never gets its email, was filed as routine.
	 *
	 * @return void
	 */
	public function test_scheduling_outcomes_are_distinguished() {
		$order = $this->make_empty_order();
		$event = TriggerEvent::status( 'processing' );
		$this->track_scheduled( (int) $order->get_id(), $event );

		$first = DeferredEvaluation::schedule( (int) $order->get_id(), $event );

		$this->assertSame( DeferredEvaluation::SCHEDULED, $first['result'] );
		$this->assertGreaterThan( 0, $first['action_id'], 'A scheduled action must report its id.' );
		$this->assertSame( 1, $this->pending_job_count( (int) $order->get_id(), $event ) );

		// A SECOND deferral for the same identity is `already_pending`, and the
		// pending count is still one.
		$second = DeferredEvaluation::schedule( (int) $order->get_id(), $event );

		$this->assertSame( DeferredEvaluation::ALREADY_PENDING, $second['result'] );
		$this->assertSame( 0, $second['action_id'] );
		$this->assertSame(
			1,
			$this->pending_job_count( (int) $order->get_id(), $event ),
			'A duplicate action was queued.'
		);

		// Each outcome describes itself distinctly for the log, and the two
		// failure modes say so in terms a merchant can act on.
		$this->assertStringContainsString( 'scheduled', DeferredEvaluation::describe( $first ) );

		// ⚠ AND A DIFFERENT ORDER IS NOT BLOCKED BY IT. Action Scheduler's own
		// `$unique` flag matches HOOK + GROUP ONLY, ignoring arguments — so
		// passing it would make one pending deferral suppress every other
		// order's. Uniqueness here is per IDENTITY, and this proves it.
		$other = $this->make_empty_order();
		$this->track_scheduled( (int) $other->get_id(), $event );

		$elsewhere = DeferredEvaluation::schedule( (int) $other->get_id(), $event );

		$this->assertSame(
			DeferredEvaluation::SCHEDULED,
			$elsewhere['result'],
			'A pending deferral for one order suppressed another order\'s — one busy order would silence the store.'
		);
		$this->assertSame( 1, $this->pending_job_count( (int) $other->get_id(), $event ) );
		$this->assertStringContainsString( 'already pending', DeferredEvaluation::describe( $second ) );
		$this->assertStringContainsString(
			'will not happen',
			DeferredEvaluation::describe( array( 'result' => DeferredEvaluation::SCHEDULE_FAILED ) )
		);
		$this->assertStringContainsString(
			'will not happen',
			DeferredEvaluation::describe( array( 'result' => DeferredEvaluation::SCHEDULER_UNAVAILABLE ) )
		);
	}

	/**
	 * 5b. A SCHEDULING FAILURE is recorded as `schedule_failed`, never as
	 *     "already scheduled".
	 *
	 * Forced by short-circuiting Action Scheduler's own enqueue filter to return
	 * 0 — the documented way to intercept it, and the exact value the function
	 * returns when the store rejects an action.
	 *
	 * @return void
	 */
	public function test_a_scheduling_failure_is_recorded_distinctly() {
		$order = $this->make_empty_order();
		$event = TriggerEvent::status( 'processing' );
		$this->track_scheduled( (int) $order->get_id(), $event );

		$fail = static function () {
			return 0;
		};

		add_filter( 'pre_as_schedule_single_action', $fail, 10 );

		try {
			$outcome = DeferredEvaluation::schedule( (int) $order->get_id(), $event );
		} finally {
			remove_filter( 'pre_as_schedule_single_action', $fail, 10 );
		}

		$this->assertSame(
			DeferredEvaluation::SCHEDULE_FAILED,
			$outcome['result'],
			'A scheduling failure was reported as something benign.'
		);
		$this->assertSame( 0, $outcome['action_id'] );
		$this->assertSame( 0, $this->pending_job_count( (int) $order->get_id(), $event ) );
	}

	/**
	 * 5c. A failed schedule is logged as an ERROR, not a notice: the delivery
	 *     will never happen and that has to be visible.
	 *
	 * @return void
	 */
	public function test_a_failed_schedule_is_logged_as_an_error() {
		$product_id = $this->make_simple_product( 'WCEP Schedule Fail Log' );
		$order      = $this->make_empty_order();
		$order_id   = (int) $order->get_id();
		$event      = TriggerEvent::status( 'processing' );
		$this->track_scheduled( $order_id, $event );

		$this->make_sending_rule( $product_id, array( 'trigger_value' => 'processing' ) );

		$logger = new class( $this->deliveries, $this->details ) extends DeliveryLogger {
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

		$orchestrator = new Orchestrator(
			$this->rules,
			new RuleMatcher( $this->rules, new ItemResolver() ),
			$logger
		);

		$fail = static function () {
			return 0;
		};

		add_filter( 'pre_as_schedule_single_action', $fail, 10 );

		try {
			$orchestrator->handle_status_change( $order_id, 'pending', 'processing' );
		} finally {
			remove_filter( 'pre_as_schedule_single_action', $fail, 10 );
		}

		$this->assertNotSame( array(), $logger->recorded_errors, 'A failed deferral was not logged as an error.' );
		$this->assertStringContainsString( 'SCHEDULING FAILED', $logger->recorded_errors[0] );
		$this->assertMailCount( 0 );
	}

	/**
	 * 6. DEACTIVATION cancels pending deferrals, and reactivating sends nothing
	 *    from the stale event.
	 *
	 * A pending deferral that survives deactivation fires whenever the queue
	 * next runs — possibly after the merchant reactivates days later — sending a
	 * customer an email for a status change they have long forgotten.
	 *
	 * @return void
	 */
	public function test_deactivation_cancels_pending_deferrals() {
		$product_id = $this->make_simple_product( 'WCEP Deactivate' );
		$order      = $this->make_empty_order();
		$order_id   = (int) $order->get_id();
		$event      = TriggerEvent::status( 'processing' );
		$this->track_scheduled( $order_id, $event );

		$rule_id = $this->make_sending_rule( $product_id, array( 'trigger_value' => 'processing' ) );

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );
		$this->assertSame( 1, $this->pending_job_count( $order_id, $event ), 'Nothing was scheduled to cancel.' );

		// The merchant deactivates the plugin.
		Deactivator::deactivate();

		$this->assertSame(
			0,
			$this->pending_job_count( $order_id, $event ),
			'A pending deferral survived deactivation and can still fire.'
		);

		// The items arrive and the plugin is reactivated. Nothing fires from the
		// stale event, because the job is gone.
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->calculate_totals();
		$order->save();

		$this->assertMailCount( 0, 'A stale deferral sent after reactivation.' );
		$this->assertSame( array(), $this->tombstones_for( $order_id ) );
		$this->assertNull( $this->tombstone( $order_id, $rule_id, 'status:processing' ) );
	}

	/**
	 * 6b. The recurring purge is still cancelled too — deactivation gained the
	 *     one-off hooks without losing what it already owned.
	 *
	 * @return void
	 */
	public function test_deactivation_still_covers_the_recurring_hooks() {
		$this->assertContains( 'extonify_wcep_retention_purge', Deactivator::RECURRING_HOOKS );
		$this->assertContains( DeferredEvaluation::HOOK, Deactivator::ONE_OFF_HOOKS );
	}

	/**
	 * Run the deferred job through its registered hook.
	 *
	 * @param int          $order_id Order id.
	 * @param TriggerEvent $event    Trigger event.
	 * @return void
	 */
	private function run_job( int $order_id, TriggerEvent $event ): void {
		$args = DeferredEvaluation::args( $order_id, $event );

		do_action(
			DeferredEvaluation::HOOK,
			$args['order_id'],
			$args['trigger_type'],
			$args['trigger_value'],
			$args['trigger_identity']
		);
	}

	/**
	 * How many jobs are pending for one identity.
	 *
	 * @param int          $order_id Order id.
	 * @param TriggerEvent $event    Trigger event.
	 * @return int
	 */
	private function pending_job_count( int $order_id, TriggerEvent $event ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler is unavailable.' );
		}

		return count(
			as_get_scheduled_actions(
				array(
					'hook'     => DeferredEvaluation::HOOK,
					'args'     => DeferredEvaluation::args( $order_id, $event ),
					'group'    => DeferredEvaluation::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				),
				'ids'
			)
		);
	}

	/**
	 * Cancel the pending job for one identity, as running it would.
	 *
	 * @param int          $order_id Order id.
	 * @param TriggerEvent $event    Trigger event.
	 * @return void
	 */
	private function cancel_pending( int $order_id, TriggerEvent $event ): void {
		as_unschedule_all_actions(
			DeferredEvaluation::HOOK,
			DeferredEvaluation::args( $order_id, $event ),
			DeferredEvaluation::GROUP
		);
	}
}
