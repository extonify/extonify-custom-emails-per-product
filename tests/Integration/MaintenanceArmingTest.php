<?php
/**
 * RECOVERY ARMS ITSELF FROM THE PATH THAT CREATES THE WORK (ADR-0015 §8.3c; gate 24).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\ScheduledDelivery;
use Extonify\WCEP\Install\Maintenance;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * The invariant:
 *
 *     Any mechanism whose absence makes another guarantee false must arm itself
 *     from the same runtime path that creates the work it protects, not from an
 *     event that may never fire.
 *
 * ⚠ WHAT WAS ACTUALLY WRONG. `Maintenance::ensure_scheduled()` was reachable from
 * exactly two places — `Activator::activate()` and `admin_init`. **An automatic
 * plugin update runs neither**, and a store driven by REST or WP-CLI can go a long
 * time without an admin request. So the plugin could schedule delayed deliveries into
 * a queue whose only recovery mechanism did not exist, and every guarantee in §8.3,
 * §8.3b and §8.4 rests on that action running.
 *
 * ⚠ AND LIKE THE FAIRNESS DEFECT, THIS ONE ONLY BECAME ASKABLE BECAUSE THE PREVIOUS
 * ROUND EXISTS. There was no maintenance action before Prompt 7A.
 *
 * Every test here runs with **no activation and no `admin_init`** — the two paths
 * that used to be the whole answer — and the fixture removes the action first, so a
 * pass cannot come from an action some earlier test happened to leave behind.
 */
final class MaintenanceArmingTest extends ScheduledDeliveryTestCase {

	/**
	 * Start every test with no maintenance action and no verification.
	 *
	 * @before
	 * @return void
	 */
	protected function set_up_arming() {
		$this->disarm();
	}

	/**
	 * Leave the store as this class found it.
	 *
	 * @after
	 * @return void
	 */
	protected function tear_down_arming() {
		$this->disarm();
	}

	/**
	 * Remove the recurring action AND every trace that it was ever verified.
	 *
	 * @return void
	 */
	private function disarm() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Maintenance::HOOK, array(), Maintenance::GROUP );
		}

		delete_option( Maintenance::OPTION_VERIFIED );
		Maintenance::forget_verification();

		$this->assertFalse( $this->maintenance_armed(), 'the fixture could not remove the maintenance action' );
	}

	/**
	 * Whether the recurring maintenance action exists in the queue.
	 *
	 * Asked of Action Scheduler itself, not of the plugin's own bookkeeping — the
	 * bookkeeping is exactly what is under test.
	 *
	 * @return bool
	 */
	private function maintenance_armed(): bool {
		return function_exists( 'as_next_scheduled_action' )
			&& false !== as_next_scheduled_action( Maintenance::HOOK, array(), Maintenance::GROUP );
	}

	/**
	 * Arm one delayed delivery through the real order path.
	 *
	 * @param string $label Distinguishes the product, and so the rule and the order.
	 * @return array{delivery_id:int,order_id:int}
	 */
	private function schedule_a_delayed_delivery( string $label ): array {
		$product  = $this->make_product( 'WCEP arming ' . $label );
		$rule_id  = $this->make_delayed_rule( $product );
		$order    = $this->delayed_order( array( $product ) );
		$order_id = (int) $order->get_id();

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$tombstone = $this->scheduled_tombstone( $order_id, $rule_id );

		$this->assertNotNull( $tombstone, 'the fixture never armed a delayed delivery' );
		$this->assertSame( DeliveryRepository::SCHEDULED, (string) $tombstone['final_status'] );

		return array(
			'delivery_id' => (int) $tombstone['id'],
			'order_id'    => $order_id,
		);
	}

	/**
	 * GATE 24 — QUEUEING DELAYED WORK ARMS THE THING THAT RECOVERS IT.
	 *
	 * No activation, no `admin_init`: just an order reaching `processing`, which is
	 * the ordinary front-end path on which a customer's delayed email is created.
	 *
	 * @return void
	 */
	public function test_scheduling_a_delayed_delivery_arms_the_maintenance_action() {
		$armed = $this->schedule_a_delayed_delivery( 'first' );

		$this->assertTrue( $this->has_job( $armed['delivery_id'], $armed['order_id'] ), 'the fixture queued no job' );
		$this->assertTrue(
			$this->maintenance_armed(),
			'a delayed delivery was queued into a store with NO recovery mechanism — §8.3c'
		);

		fwrite( STDERR, "\n[7C gate 24] no activation, no admin_init: scheduling a delayed delivery armed the maintenance action\n" );
	}

	/**
	 * GATE 24, SECOND HALF — AND IT HEALS. Deleting the action and scheduling again
	 * restores it.
	 *
	 * A merchant can delete a recurring action from Action Scheduler's admin screen,
	 * and a queue purge takes them all. Arming once is not the guarantee; arming
	 * whenever protected work is created is.
	 *
	 * @return void
	 */
	public function test_a_deleted_maintenance_action_is_restored_by_the_next_delayed_delivery() {
		$this->schedule_a_delayed_delivery( 'before' );
		$this->assertTrue( $this->maintenance_armed() );

		// Gone — along with the verification that would otherwise vouch for it.
		$this->disarm();

		$restored = $this->schedule_a_delayed_delivery( 'after' );

		$this->assertTrue( $this->has_job( $restored['delivery_id'], $restored['order_id'] ) );
		$this->assertTrue( $this->maintenance_armed(), 'a deleted maintenance action was never restored' );

		fwrite( STDERR, "\n[7C gate 24] deleted maintenance action restored by the next delayed delivery\n" );
	}

	/**
	 * `action_scheduler_init` ARMS IT ON A REQUEST THAT SCHEDULES NOTHING AT ALL.
	 *
	 * The other half of §8.3c, and the half that covers the site whose deliveries were
	 * queued by a PREVIOUS version: a plugin update leaves pending rows behind and runs
	 * neither activation nor, on a REST- or CLI-driven store, `admin_init`.
	 *
	 * @return void
	 */
	public function test_action_scheduler_init_arms_the_maintenance_action() {
		$this->assertNotFalse(
			has_action( 'action_scheduler_init', array( Maintenance::class, 'ensure_armed' ) ),
			'the plugin does not arm from `action_scheduler_init`, so an updated store may never arm at all'
		);

		do_action( 'action_scheduler_init' );

		$this->assertTrue( $this->maintenance_armed(), '`action_scheduler_init` did not arm the maintenance action' );
	}

	/**
	 * THE COST: A CACHED EXISTENCE CHECK, NOT A SCHEDULER QUERY PER DELIVERY.
	 *
	 * ⚠ ASSERTED IN QUERIES, NOT BY READING THE SOURCE. `ensure_armed()` is called
	 * from `ScheduledDelivery::schedule()`, so a bulk status change queueing fifty
	 * deliveries would otherwise add fifty scheduler reads to a request that is
	 * already doing real work.
	 *
	 * @return void
	 */
	public function test_the_arming_check_costs_nothing_after_the_first_call() {
		global $wpdb;

		$this->assertTrue( Maintenance::ensure_armed(), 'the first call did not arm the action' );

		$before = (int) $wpdb->num_queries;

		for ( $i = 0; $i < 50; $i++ ) {
			$this->assertTrue( Maintenance::ensure_armed() );
		}

		$spent = (int) $wpdb->num_queries - $before;

		$this->assertSame( 0, $spent, '50 arming checks in one request cost ' . $spent . ' queries' );

		// And a fresh request — the static forgotten — still costs no scheduler read
		// while the stamp is inside its interval.
		Maintenance::forget_verification();
		$before = (int) $wpdb->num_queries;

		$this->assertTrue( Maintenance::ensure_armed() );

		$this->assertSame(
			0,
			(int) $wpdb->num_queries - $before,
			'a verified check hit the database — the option is meant to be autoloaded'
		);

		fwrite( STDERR, "\n[7C gate 24] arming cost: 1 scheduler read per " . Maintenance::VERIFY_INTERVAL_SECONDS . "s; 50 further checks cost 0 queries\n" );
	}

	/**
	 * A FAILED ARM MUST NOT BUY AN HOUR OF SILENCE.
	 *
	 * The stamp says "verified", and writing one when nothing was verified would
	 * suppress every later attempt for a full interval — on precisely the store that
	 * has a queue and no recovery behind it.
	 *
	 * @return void
	 */
	public function test_a_failed_arm_writes_no_verification_stamp() {
		/*
		 * Action Scheduler's OWN pre-empt filter, verified in the bundled 3.9.3 copy:
		 * a non-null return makes `as_schedule_recurring_action()` return that value
		 * and queue nothing (`functions.php`, `pre_as_schedule_recurring_action`).
		 * Nothing is mocked; the refusal comes from the real extension point a
		 * conflicting plugin would use.
		 */
		add_filter( 'pre_as_schedule_recurring_action', '__return_zero' );

		$armed = Maintenance::ensure_armed();

		remove_filter( 'pre_as_schedule_recurring_action', '__return_zero' );

		$this->assertFalse( $armed, 'a refused schedule reported the action as armed' );
		$this->assertFalse( $this->maintenance_armed() );
		$this->assertSame(
			0,
			(int) get_option( Maintenance::OPTION_VERIFIED, 0 ),
			'a failed arm wrote a verification stamp, silencing every retry for a full interval'
		);
	}
}
