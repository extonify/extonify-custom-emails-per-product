<?php
/**
 * THE SWEEP IS FAIR UNDER SUSTAINED INFLOW (ADR-0015 §8.3b; gate 23).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\DeliveryLogger;
use Extonify\WCEP\Delivery\ScheduledDelivery;
use Extonify\WCEP\Domain\DeliverySnapshot;
use Extonify\WCEP\Install\Maintenance;
use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * The invariant, and the reason a cursor alone could not satisfy it.
 *
 *     Every delivery eligible when a sweep cycle begins is examined within
 *     ceil(E / T) maintenance runs — E = that cycle's candidate count,
 *     T = SWEEP_THROUGHPUT — NO MATTER HOW MANY ROWS ARRIVE MEANWHILE.
 *
 * ⚠ THE DEFECT THIS FILE EXISTS FOR WAS CREATED BY THE FIX FOR THE PREVIOUS ONE.
 * Prompt 7B gave the orphan half a persisted cursor, which reset only when a page
 * came back SHORT. Under sustained inflow a page is never short, so the cursor
 * climbed for ever and a row behind it was never revisited:
 *
 *     cursor 5000, orphan at id 100, >1,000 new eligible rows per day
 *     day 1: 5001–6000   day 2: 6001–7000   day 3: 7001–8000  …
 *
 * ⚠ AND 7B's OWN REGRESSION COULD NOT SEE IT. `ScheduledIntegrityTest`'s paging
 * test starts at cursor 0 with 101 rows: it proves the sweep can pass page one
 * WITHIN a run, which is a different claim entirely. Every test here therefore
 * starts from a cursor that is already PAST the row whose recovery is at stake,
 * and every run is preceded by more than one run's throughput of newer candidates.
 *
 * The fixtures are deliberately large — the smallest adversary that keeps a page
 * from coming back short is `SWEEP_THROUGHPUT` rows — so this class is slower than
 * the rest of the suite. That is the cost of testing the actual failure rather than
 * a scaled-down analogue of it, and the numbers are printed so the bound can be read
 * off the run.
 */
final class SweepFairnessTest extends ScheduledDeliveryTestCase {

	/**
	 * The order every fixture row hangs off.
	 *
	 * @var int
	 */
	private $order_id = 0;

	/**
	 * The rule every fixture row points at.
	 *
	 * @var int
	 */
	private $rule_id = 0;

	/**
	 * Distinguishes each fixture row's trigger identity.
	 *
	 * @var int
	 */
	private $sequence = 0;

	/**
	 * One order and one rule, so a thousand tombstones need neither.
	 *
	 * @before
	 * @return void
	 */
	protected function set_up_fairness_fixture() {
		$product        = $this->make_product( 'WCEP sweep fairness' );
		$this->rule_id  = $this->make_delayed_rule( $product );
		$this->order_id = (int) $this->delayed_order( array( $product ) )->get_id();
		$this->sequence = 0;
	}

	/**
	 * Remove the fixture's jobs in ONE call rather than one per delivery.
	 *
	 * The inherited teardown unschedules per delivery, which is `MAX_RESCHEDULES + 1`
	 * scheduler lookups each — fine for a handful of rows, ruinous for thousands. This
	 * class queues nothing outside its own hook and group, so a single hook-wide
	 * unschedule is both cheaper and stricter.
	 *
	 * @after
	 * @return void
	 */
	protected function tear_down_fairness_fixture() {
		$this->queued = array();

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( ScheduledDelivery::HOOK, null, ScheduledDelivery::GROUP );
		}

		delete_option( ScheduledDelivery::OPTION_SWEEP_CYCLE );
	}

	/**
	 * Create one aged `scheduled` tombstone, with or without a job.
	 *
	 * Claimed and armed through the REAL repository, so the fixture cannot pass
	 * against a state machine that no longer permits the move. Ageing is applied in
	 * bulk afterwards by self::age_everything().
	 *
	 * @param bool $with_job Whether to queue a real Action Scheduler job for it.
	 * @return int Tombstone id.
	 */
	private function aged_row( bool $with_job ): int {
		++$this->sequence;

		$identity = 'status:fairness-' . $this->sequence;
		$claim    = $this->deliveries->claim( $this->order_id, $this->rule_id, DeliveryLogger::MODE, $identity );

		$this->assertSame( DeliveryRepository::CLAIMED, (string) $claim['result'], 'the fixture could not claim' );

		$delivery_id = (int) $claim['delivery_id'];
		$this->track_delivery( $delivery_id );

		$snapshot = DeliverySnapshot::create(
			array(
				'id'            => $this->rule_id,
				'revision'      => 1,
				'delivery_mode' => DeliveryLogger::MODE,
				'delay_seconds' => HOUR_IN_SECONDS,
				'subject'       => 'Fairness fixture',
				'content'       => '<p>Fairness fixture.</p>',
			),
			array( array( 'item_id' => 1, 'product_id' => 1, 'variation_id' => 0 ) ),
			$identity,
			time() + HOUR_IN_SECONDS
		);

		$this->assertTrue(
			$this->deliveries->arm_scheduled( $delivery_id, $snapshot )->won(),
			'the fixture could not arm a scheduled row'
		);

		if ( $with_job ) {
			ScheduledDelivery::schedule( $delivery_id, $this->order_id, time() + HOUR_IN_SECONDS );
		}

		return $delivery_id;
	}

	/**
	 * A batch of HEALTHY candidates — aged, `scheduled`, and holding a live job.
	 *
	 * Healthy is what makes them adversarial. A healthy row needs no action and is
	 * never removed from the candidate set, so it is exactly what a cursor has to
	 * walk past; an orphan would be re-queued and would leave.
	 *
	 * @param int $count How many.
	 * @return int[] Tombstone ids, ascending.
	 */
	private function inflow( int $count ): array {
		$ids = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$ids[] = $this->aged_row( true );
		}

		$this->age_everything();

		return $ids;
	}

	/**
	 * Backdate every `scheduled` fixture row past the sweep's cutoff, in one UPDATE.
	 *
	 * @param int $age_seconds How far back, default twice the lease window.
	 * @return void
	 */
	private function age_everything( int $age_seconds = 0 ) {
		global $wpdb;

		$table = Migrator::table( 'deliveries' );
		$when  = gmdate( 'Y-m-d H:i:s', time() - ( $age_seconds > 0 ? $age_seconds : 2 * ScheduledDelivery::LEASE_WINDOW_SECONDS ) );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET last_seen_at = %s WHERE final_status = %s",
				$when,
				DeliveryRepository::SCHEDULED
			)
		);
	}

	/**
	 * Persist a sweep cycle that is already in progress.
	 *
	 * ⚠ THIS IS THE STATE THE 7B REGRESSION COULD NOT REACH. A store that has been
	 * sweeping for days does not start at cursor 0; it starts wherever it stopped,
	 * which under inflow is somewhere above the rows that most need looking at.
	 *
	 * @param int $cursor     Where the traversal has reached.
	 * @param int $high_water The cycle's frozen id bound.
	 * @return void
	 */
	private function persist_cycle( int $cursor, int $high_water ) {
		update_option(
			ScheduledDelivery::OPTION_SWEEP_CYCLE,
			array(
				'cursor'     => $cursor,
				'high_water' => $high_water,
				'cutoff'     => gmdate( 'Y-m-d H:i:s', time() - ScheduledDelivery::LEASE_WINDOW_SECONDS ),
			),
			false
		);
	}

	/**
	 * GATE 23 — AN ORPHAN BEHIND A PERSISTED CURSOR IS REACHED, UNDER INFLOW THAT
	 * EXCEEDS ONE RUN'S THROUGHPUT BEFORE EVERY RUN.
	 *
	 * The mechanism under test is the cycle HIGH-WATER MARK. Rows arriving while a
	 * cycle is in progress are above the mark, so they join the NEXT cycle instead of
	 * extending the current one — which is what makes a cycle end, and therefore what
	 * makes the traversal come back to the front of the table.
	 *
	 * **THE DECLARED BOUND FOR THIS FIXTURE: two runs.** Run 1 finishes the cycle that
	 * was already open (`E = T`, so `ceil(E / T) = 1`); run 2 opens a fresh cycle whose
	 * lowest-id candidate IS the orphan. The assertion is on that number, not on
	 * "eventually".
	 *
	 * @return void
	 */
	public function test_an_orphan_behind_the_cursor_is_reached_under_sustained_inflow() {
		$throughput = ScheduledDelivery::SWEEP_THROUGHPUT;

		// The row whose email is at stake: the LOWEST id in the table, aged, and with
		// no job behind it. Nothing but the sweep can ever reach it.
		$orphan = $this->aged_row( false );

		// The cycle already in progress, with exactly one run's throughput of healthy
		// candidates left in it — all of them ABOVE the orphan.
		$first = $this->inflow( $throughput );

		$this->assertFalse( $this->has_job( $orphan, $this->order_id ), 'the orphan fixture has a job' );
		$this->persist_cycle( $orphan, (int) max( $first ) );

		$run_one = Maintenance::run();

		// Run 1 spent its whole budget on the open cycle and never looked below its
		// own cursor — the orphan is still stranded, which is correct and is the
		// state the bound is measured from.
		$this->assertSame( $throughput, (int) $run_one['examined'], 'run 1 did not spend exactly one throughput on the open cycle' );
		$this->assertFalse( $this->has_job( $orphan, $this->order_id ), 'run 1 reached below the cursor — the fixture is not modelling a cycle in progress' );
		$this->assertTrue( (bool) $run_one['cycle_complete'], 'the open cycle did not close in ceil(E / T) = 1 run' );

		// SUSTAINED INFLOW: more than one run's throughput of NEW healthy candidates,
		// all with higher ids, arriving before the next run. This is precisely what
		// used to keep every page full and the cursor climbing for ever.
		$second = $this->inflow( $throughput + 1 );

		$this->assertGreaterThan(
			$throughput,
			count( $second ),
			'the inflow does not exceed one run\'s throughput, so it is not adversarial'
		);

		$run_two = Maintenance::run();

		$this->assertTrue(
			$this->has_job( $orphan, $this->order_id ),
			'THE ORPHAN WAS NOT EXAMINED WITHIN THE DECLARED BOUND — the cursor climbed past it and did not come back'
		);
		$this->assertSame( 1, (int) $run_two['orphans_requeued'], 'the sweep recovered something other than the orphan' );
		$this->assertSame( DeliveryRepository::SCHEDULED, $this->status_of( $orphan ) );

		// The new cycle's mark was taken when it opened, so it cannot have swallowed
		// the whole of a table that keeps growing.
		$this->assertLessThanOrEqual(
			$throughput,
			(int) $run_two['examined'],
			'a run examined more than its stated throughput'
		);

		fwrite(
			STDERR,
			"\n[7C gate 23] sweep fairness under sustained inflow:"
				. "\n  T = SWEEP_BATCH x SWEEP_MAX_PAGES = " . $throughput
				. "\n  cursor persisted ABOVE the orphan; inflow " . count( $second ) . ' healthy rows (> T) before the run'
				. "\n  run 1 examined " . (int) $run_one['examined'] . ' (cycle closed), run 2 examined ' . (int) $run_two['examined']
				. "\n  orphan reached on run 2 — declared bound 2 (1 to close the open cycle + ceil(E/T) = 1)\n"
		);
	}

	/**
	 * THE HIGH-WATER MARK IS WHAT BOUNDS A CYCLE: rows created after it join the NEXT
	 * one.
	 *
	 * The cheap, exact statement of the same property the test above demonstrates at
	 * full scale. Without it, "the candidate set can only shrink" is an assertion
	 * about code rather than about behaviour — and it is the step that turns
	 * `ceil(E / T)` from an estimate into a bound.
	 *
	 * @return void
	 */
	public function test_rows_arriving_during_a_cycle_join_the_next_one() {
		$before = $this->inflow( 3 );

		// Open the cycle by running once: the mark is taken now, over these three.
		$this->persist_cycle( 0, (int) max( $before ) );

		$during = $this->inflow( 4 );

		$run = Maintenance::run();

		$this->assertSame(
			count( $before ),
			(int) $run['examined'],
			'the cycle examined rows created after its mark — under inflow it would never end'
		);
		$this->assertTrue( (bool) $run['cycle_complete'] );
		$this->assertSame( (int) max( $before ), (int) $run['high_water'], 'the mark moved mid-cycle' );

		// And the next run picks them up, so nothing is lost by being excluded.
		$next = Maintenance::run();

		$this->assertSame( count( $before ) + count( $during ), (int) $next['examined'], 'the next cycle did not include the rows the last one deferred' );
		$this->assertSame( (int) max( $during ), (int) $next['high_water'] );

		fwrite(
			STDERR,
			"\n[7C gate 23] cycle freeze: " . count( $before ) . ' in the open cycle, ' . count( $during )
				. ' arrived during it -> examined ' . (int) $run['examined'] . ' then ' . (int) $next['examined'] . "\n"
		);
	}

	/**
	 * THE FROZEN CUTOFF IS THE OTHER HALF OF THE MARK, AND IT IS NOT DECORATION.
	 *
	 * The id bound excludes rows CREATED during a cycle. It does not exclude a row
	 * that already existed BELOW the mark and merely became old enough while the cycle
	 * ran — and under inflow those are unbounded too. Freezing the age predicate at
	 * cycle start is what makes the candidate set monotonically shrinking.
	 *
	 * @return void
	 */
	public function test_a_row_that_ages_into_eligibility_mid_cycle_waits_for_the_next_one() {
		// Two aged candidates and, between them in id order, one that is still too
		// young for the sweep to be entitled to look at.
		$first = $this->aged_row( true );
		$young = $this->aged_row( true );
		$last  = $this->aged_row( true );

		$this->age_everything();
		$this->backdate_scheduled( $young, 0 );

		$this->persist_cycle( 0, $last );

		$run = Maintenance::run();

		$this->assertSame( 2, (int) $run['examined'], 'the young row was examined despite being inside the safety window' );

		// It ages in while the cycle is still notionally open. The frozen cutoff is
		// what keeps it out until the next cycle, which is what bounds the set.
		$this->backdate_scheduled( $young, 2 * ScheduledDelivery::LEASE_WINDOW_SECONDS );

		$reopened = Maintenance::run();

		$this->assertSame( 3, (int) $reopened['examined'], 'the row that aged in was never picked up by a later cycle' );

		fwrite(
			STDERR,
			"\n[7C gate 23] frozen cutoff: row #" . $young . ' aged in mid-cycle -> examined 2 then 3, not 3 then 3'
				. "\n  (ids " . $first . ', ' . $young . ', ' . $last . ")\n"
		);
	}

	/**
	 * A LEGACY OR CORRUPT CYCLE RECORD OPENS A NEW CYCLE RATHER THAN STALLING.
	 *
	 * Prompt 7B stored a bare integer under a different option key, and a restore or a
	 * manual edit can leave anything at all. The record is a hint; the one thing it
	 * must never do is stop the sweep.
	 *
	 * @return void
	 */
	public function test_an_unreadable_cycle_record_is_discarded() {
		$orphan = $this->aged_row( false );
		$this->age_everything();

		update_option( ScheduledDelivery::OPTION_SWEEP_CYCLE, 'not a cycle record', false );

		$run = Maintenance::run();

		$this->assertSame( 1, (int) $run['orphans_requeued'], 'a malformed cycle record stopped the sweep' );
		$this->assertTrue( $this->has_job( $orphan, $this->order_id ) );
	}
}
