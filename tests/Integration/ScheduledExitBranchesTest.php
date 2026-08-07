<?php
/**
 * EVERY EXIT BRANCH OF ScheduledDelivery::run() (ADR-0015 §8.4, gate 21).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\ScheduledDelivery;
use Extonify\WCEP\Domain\DeliverySnapshot;
use Extonify\WCEP\Install\Maintenance;
use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * One test per way out, and each asserts the SAME property:
 *
 *   the tombstone is terminal with a distinct reason,
 *   OR it is still `scheduled` and a replacement job provably exists.
 *
 * ⚠ THE PROPERTY IS WHAT MATTERS, NOT THE REASON STRING. Before Prompt 7A five
 * of these branches were a bare `return;`: the action was consumed, the tombstone
 * stayed `scheduled`, the identity stayed claimed, and nothing in the queue or the
 * log could ever reach that delivery again. Every one of them was a silently lost
 * email, and the `! Migrator::is_operational()` branch fires during any plugin
 * update.
 *
 * The §4 re-validation branches (rule deleted, disabled, left phase, order
 * deleted, order state, items refunded) already had truthful reasons and keep
 * them; `ScheduledRevalidationTest` owns those. What is asserted HERE is that
 * they now leave the lease correctly rather than the row's raw status.
 */
final class ScheduledExitBranchesTest extends ScheduledDeliveryTestCase {

	/**
	 * A scheduled delivery, armed and queued through the real path.
	 *
	 * @return array{delivery_id:int,order_id:int,rule_id:int}
	 */
	private function armed_delivery(): array {
		$product  = $this->make_product( 'WCEP exit branch' );
		$rule_id  = $this->make_delayed_rule( $product );
		$order    = $this->delayed_order( array( $product ) );
		$order_id = (int) $order->get_id();

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$tombstone = $this->scheduled_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone, 'the fixture never armed a delayed delivery' );

		return array(
			'delivery_id' => (int) $tombstone['id'],
			'order_id'    => $order_id,
			'rule_id'     => $rule_id,
		);
	}

	/**
	 * Assert a branch reached a terminal state with its own reason.
	 *
	 * @param int    $delivery_id Tombstone id.
	 * @param string $code        Expected reason code.
	 * @param string $branch      Branch name, for the failure message.
	 * @return void
	 */
	private function assertTerminalWithReason( int $delivery_id, string $code, string $branch ) {
		$status = $this->status_of( $delivery_id );

		$this->assertNotContains(
			$status,
			DeliveryRepository::IN_FLIGHT_STATUSES,
			"branch {$branch} left the delivery in flight ({$status}) — rule 4 violated"
		);
		$this->assertSame( $code, $this->cancellation_code( $delivery_id ), "branch {$branch} recorded the wrong reason" );
		$this->assertNull( $this->raw_snapshot_of( $delivery_id ), "branch {$branch} left the snapshot behind" );
	}

	/**
	 * BRANCH 1 — no usable delivery id. Rule 1, vacuously terminal.
	 *
	 * @return void
	 */
	public function test_branch_1_no_delivery_id() {
		$before = $this->deliveries->count();

		$this->captured_mail = array();
		ScheduledDelivery::run( 0, 0 );

		$this->assertCount( 0, $this->captured_mail );
		$this->assertSame( $before, $this->deliveries->count(), 'a run with no delivery id wrote a tombstone' );
	}

	/**
	 * BRANCH 2 — the schema is unavailable. RULE 3, the only branch that uses it:
	 * the row stays `scheduled` and a REPLACEMENT JOB IS PROVED TO EXIST.
	 *
	 * ⚠ THIS IS THE BRANCH THAT FIRES DURING A PLUGIN UPDATE, so it is the one a
	 * real store hits. It cannot use rule 2 even in principle — recording a
	 * terminal state means writing to a table that by hypothesis is not there.
	 *
	 * @return void
	 */
	public function test_branch_2_schema_unavailable_reschedules_with_a_verified_job() {
		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];
		$order_id    = $armed['order_id'];

		// The original job runs and is consumed.
		ScheduledDelivery::unschedule( $delivery_id, $order_id );
		$this->assertFalse( $this->has_job( $delivery_id, $order_id ) );

		// Simulate the update window: the schema check fails.
		$this->with_broken_schema(
			function () use ( $delivery_id, $order_id ) {
				$this->captured_mail = array();
				ScheduledDelivery::run( $delivery_id, $order_id, 0 );
			}
		);

		$this->assertCount( 0, $this->captured_mail, 'a run with no schema still sent an email' );
		$this->assertSame(
			DeliveryRepository::SCHEDULED,
			$this->status_of( $delivery_id ),
			'a transient condition must NOT finalise the delivery'
		);
		$this->assertTrue(
			$this->has_job_at( $delivery_id, $order_id, 1 ),
			'rule 3 requires a PROVED replacement job, and there is none at attempt 1'
		);
		$this->assertTrue( $this->has_job( $delivery_id, $order_id ) );

		fwrite( STDERR, "\n[7A branch 2] schema unavailable: tombstone stays `scheduled`, replacement job verified at attempt 1\n" );
	}

	/**
	 * BRANCH 2, CAPPED — the re-schedule cannot loop for ever.
	 *
	 * @return void
	 */
	public function test_branch_2_reschedules_are_capped() {
		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];
		$order_id    = $armed['order_id'];

		ScheduledDelivery::unschedule( $delivery_id, $order_id );

		$this->with_broken_schema(
			function () use ( $delivery_id, $order_id ) {
				// The last permitted attempt runs and asks for one more.
				ScheduledDelivery::run( $delivery_id, $order_id, ScheduledDelivery::MAX_RESCHEDULES );
			}
		);

		$this->assertFalse(
			$this->has_job( $delivery_id, $order_id ),
			'the re-schedule cap did not hold — this loops for ever against a schema that never returns'
		);
		$this->assertSame( DeliveryRepository::SCHEDULED, $this->status_of( $delivery_id ) );

		// ...and the orphan half of the daily sweep is what recovers it.
		$this->backdate_scheduled( $delivery_id, ScheduledDelivery::LEASE_WINDOW_SECONDS + 60 );
		$swept = \Extonify\WCEP\Install\Maintenance::run();

		$this->assertSame( 1, (int) $swept['orphans_requeued'], 'nothing recovered the capped-out delivery' );
		$this->assertTrue( $this->has_job( $delivery_id, $order_id ) );

		fwrite( STDERR, "\n[7A branch 2] cap of " . ScheduledDelivery::MAX_RESCHEDULES . " held; the daily sweep then recovered the row\n" );
	}

	/**
	 * BRANCH 2, CAPPED, WITH THE SCHEMA BACK — the one path that produces
	 * `schema_unavailable`.
	 *
	 * ⚠ THE STALE MEMO IS THE REAL SCENARIO, NOT A CONTRIVANCE.
	 * `Migrator::is_operational()` memoises per REQUEST and an Action Scheduler
	 * worker runs many actions in one request, so a `false` recorded when the
	 * worker started survives the update that caused it finishing. The forced
	 * re-check at the cap is what turns that into a terminal state recorded now
	 * rather than one left for tomorrow's sweep.
	 *
	 * @return void
	 */
	public function test_branch_2_capped_with_the_schema_back_records_schema_unavailable() {
		global $wpdb;

		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];
		$order_id    = $armed['order_id'];

		ScheduledDelivery::unschedule( $delivery_id, $order_id );

		$table  = Migrator::table( 'deliveries' );
		$parked = $table . '_parked_7a_memo';

		// Break it, PRIME the memo (this is the worker starting mid-update), then
		// put it back WITHOUT flushing — exactly the stale-`false` state.
		$wpdb->query( "RENAME TABLE {$table} TO {$parked}" );
		Migrator::flush_schema_cache();
		$this->assertFalse( Migrator::is_operational() );
		$wpdb->query( "RENAME TABLE {$parked} TO {$table}" );

		try {
			$this->captured_mail = array();
			ScheduledDelivery::run( $delivery_id, $order_id, ScheduledDelivery::MAX_RESCHEDULES );
		} finally {
			Migrator::flush_schema_cache();
		}

		$this->assertCount( 0, $this->captured_mail );
		$this->assertTerminalWithReason( $delivery_id, ScheduledDelivery::REASON_SCHEMA_UNAVAILABLE, '2/capped' );
		$this->assertFalse( $this->has_job( $delivery_id, $order_id ) );

		fwrite( STDERR, "\n[7A branch 2] cap spent, schema back: terminal with `schema_unavailable` rather than left for the sweep\n" );
	}

	/**
	 * BRANCH 3a — the tombstone is gone (its order was permanently deleted).
	 * Rule 1, vacuously terminal.
	 *
	 * @return void
	 */
	public function test_branch_3a_missing_tombstone() {
		$this->captured_mail = array();

		// An id no row has ever had.
		ScheduledDelivery::run( 999000111, 0 );

		$this->assertCount( 0, $this->captured_mail );
	}

	/**
	 * BRANCH 3b — the lease is refused because the row is already terminal.
	 * Rule 1: it HAS an outcome, and re-recording would overwrite a truthful one.
	 *
	 * @return void
	 */
	public function test_branch_3b_lease_refused_on_a_terminal_row() {
		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];

		$this->assertTrue( $this->deliveries->transition( $delivery_id, DeliveryRepository::SCHEDULED, 'cancelled' )->won() );

		$this->run_job( $delivery_id, $armed['order_id'] );

		$this->assertCount( 0, $this->captured_mail );
		$this->assertSame( 'cancelled', $this->status_of( $delivery_id ), 'a late job overwrote a terminal state' );
	}

	/**
	 * BRANCH 5 — the queued arguments and the tombstone name different orders.
	 * Rule 2, reason `action_arguments_mismatch`.
	 *
	 * @return void
	 */
	public function test_branch_5_argument_mismatch_is_terminal() {
		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];

		$this->captured_mail = array();
		ScheduledDelivery::run( $delivery_id, $armed['order_id'] + 5000 );

		$this->assertCount( 0, $this->captured_mail, 'a mismatched job mailed one customer under another\'s identity' );
		$this->assertTerminalWithReason( $delivery_id, ScheduledDelivery::REASON_ARGS_MISMATCH, '5' );
	}

	/**
	 * BRANCH 6 — the snapshot cannot be read. Rule 2, reason
	 * `snapshot_unreadable`, and NOTHING assembled from defaults is sent.
	 *
	 * @return void
	 */
	public function test_branch_6_unreadable_snapshot_is_terminal() {
		global $wpdb;

		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];

		$table = Migrator::table( 'deliveries' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET snapshot = %s WHERE id = %d", '{"version":1', $delivery_id ) );

		$this->run_job( $delivery_id, $armed['order_id'] );

		$this->assertCount( 0, $this->captured_mail );
		$this->assertTerminalWithReason( $delivery_id, ScheduledDelivery::REASON_NO_SNAPSHOT, '6' );
	}

	/**
	 * BRANCH 14 — delivery is not operational at the moment of sending.
	 * Rule 2, reason `execution_inoperative`.
	 *
	 * Reached through the preview signal, which `Orchestrator::is_operational()`
	 * consults and which nothing else in this run touches.
	 *
	 * @return void
	 */
	public function test_branch_14_inoperative_at_send_time_is_terminal() {
		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];

		$preview = static function () {
			return true;
		};
		add_filter( 'woocommerce_is_email_preview', $preview, 99 );

		try {
			$this->run_job( $delivery_id, $armed['order_id'] );
		} finally {
			remove_filter( 'woocommerce_is_email_preview', $preview, 99 );
		}

		$this->assertCount( 0, $this->captured_mail, 'a delivery sent while orchestration was inert' );
		$this->assertTerminalWithReason( $delivery_id, ScheduledDelivery::REASON_INOPERATIVE, '14' );
	}

	/**
	 * BRANCH 15, GLOBALLY DISABLED — the merchant switched custom emails off
	 * during the delay. Rule 2, reason `globally_disabled`.
	 *
	 * @return void
	 */
	public function test_branch_15_globally_disabled_is_terminal() {
		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];

		$this->set_email_setting( 'enabled', 'no' );

		$this->run_job( $delivery_id, $armed['order_id'] );

		$this->assertCount( 0, $this->captured_mail );
		$this->assertTerminalWithReason( $delivery_id, 'globally_disabled', '15/globally_disabled' );
	}

	/**
	 * BRANCH 15, THE HAPPY PATH — one email, `sent`, off the lease.
	 *
	 * @return void
	 */
	public function test_branch_15_success_leaves_the_lease_for_sent() {
		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];

		$this->run_job( $delivery_id, $armed['order_id'] );

		$this->assertCount( 1, $this->captured_mail, 'the happy path did not send exactly one email' );
		$this->assertSame( 'sent', $this->status_of( $delivery_id ) );
		$this->assertNull( $this->raw_snapshot_of( $delivery_id ) );
	}

	/**
	 * A RUN THAT THROWS UNDER THE LEASE reaches `failed`, not a stranded
	 * `executing`.
	 *
	 * @return void
	 */
	public function test_a_throw_under_the_lease_is_terminal() {
		$armed       = $this->armed_delivery();
		$delivery_id = $armed['delivery_id'];

		$bomb = static function () {
			throw new \RuntimeException( 'a third-party placeholder filter exploded' );
		};
		add_filter( 'extonify_wcep_meta_placeholder_allowed', $bomb, 1 );
		add_filter( 'woocommerce_email_recipient_' . \Extonify\WCEP\Email\EmailIdentity::EMAIL_ID, $bomb, 1 );

		try {
			$this->run_job( $delivery_id, $armed['order_id'] );
		} finally {
			remove_filter( 'extonify_wcep_meta_placeholder_allowed', $bomb, 1 );
			remove_filter( 'woocommerce_email_recipient_' . \Extonify\WCEP\Email\EmailIdentity::EMAIL_ID, $bomb, 1 );
		}

		$this->assertNotContains(
			$this->status_of( $delivery_id ),
			DeliveryRepository::IN_FLIGHT_STATUSES,
			'a throw left the delivery stranded under its lease'
		);
		$this->assertNull( $this->raw_snapshot_of( $delivery_id ) );
	}

	/**
	 * EVERY REASON CODE IS DISTINCT, EXPLAINED, AND PRODUCIBLE (gate 9).
	 *
	 * The delivery log exists to answer *why didn't this send?*, and two causes
	 * sharing a code — or a code with no sentence — makes it unable to.
	 *
	 * ⚠ THE THIRD CLAUSE IS THE ONE THAT CAUGHT SOMETHING. A reason constant that
	 * nothing in `src/` ever passes to a cancellation is documentation of behaviour
	 * the code does not have, which is exactly what gate 9 forbids. Written first
	 * with a `plugin_uninstalled` code that `uninstall.php` — dependency-free by
	 * design, so unable to reference this class — could never produce, and a
	 * `schema_unavailable` code no branch reached. One was deleted; the other was
	 * given the branch it describes.
	 *
	 * @return void
	 */
	public function test_every_reason_code_is_distinct_explained_and_producible() {
		$src   = dirname( __DIR__, 2 ) . '/src';
		$codes = array();

		foreach ( ( new \ReflectionClass( ScheduledDelivery::class ) )->getConstants() as $name => $value ) {
			if ( 0 !== strpos( $name, 'REASON_' ) || 'REASON_TEXT' === $name ) {
				continue;
			}

			$this->assertNotContains( $value, $codes, "reason code {$value} is used by two constants" );
			$codes[] = $value;

			$this->assertArrayHasKey( $value, ScheduledDelivery::REASON_TEXT, "reason {$value} has no sentence" );
			$this->assertNotSame( '', trim( (string) ScheduledDelivery::REASON_TEXT[ $value ] ) );

			// Two references are the declaration and the sentence; a producible
			// code has at least one more.
			$uses = (int) shell_exec( 'grep -roF ' . escapeshellarg( $name ) . ' ' . escapeshellarg( $src ) . ' | wc -l' );

			$this->assertGreaterThan(
				2,
				$uses,
				"reason `{$value}` ({$name}) is declared and explained but nothing in src/ can produce it"
			);
		}

		/*
		 * 14 through Prompt 7A; Prompt 7B added `arm_failed`, `lease_write_failed`
		 * and `lease_interrupted_by_shutdown` — one per fact a boolean used to hide.
		 *
		 * ⚠ 18 SINCE PROMPT 8A: `consolidation_invalid` (ADR-0015 §4 check 3a,
		 * ADR-0016 §1a). A rule can become undeliverable DURING a delay — most likely a
		 * `daily` row, legitimately storable from Prompt 5B to Prompt 8 — and it needs
		 * its OWN reason rather than `rule_left_phase`, because the remedies differ:
		 * check 3 catches a merchant CHANGING the rule, this catches corrupt data, and
		 * telling that merchant they "changed the rule" would send them looking for an
		 * edit they never made.
		 *
		 * ⚠ AND THIS ASSERTION FAILING IS THIS TEST WORKING. It caught the addition on
		 * the first full 8A run and required it to be written down here — which is
		 * exactly the same discipline as gate 15's enumeration and the collection
		 * census. Widening it to `assertGreaterThan` would remove the only thing that
		 * makes a new reason code impossible to add silently.
		 */
		$this->assertCount( 18, $codes, 'the reason-code set changed without this assertion being updated' );

		fwrite(
			STDERR,
			"\n[7A gate 9] " . count( $codes ) . " scheduled-delivery reason codes: all distinct, all explained, all producible\n"
		);
	}

	/**
	 * GATE 21, CORRECTED — EVERY JOBLESS-BUT-`scheduled` EXIT IS COVERED BY A LIVE
	 * MAINTENANCE ACTION *AND* A BOUNDED SWEEP (ADR-0015 §8.4, §8.3b, §8.3c).
	 *
	 * ⚠ THE OLD GATE CLAIMED SOMETHING THE CODE DOES NOT DO. Prompt 7A reported "no
	 * `run()` branch leaves `scheduled` without a live job", and
	 * `reschedule_transient()` deliberately does exactly that when the re-schedule cap
	 * is spent or the queue refuses the replacement — its own log lines say so. **A
	 * gate that overstates is worse than one that admits a bound**, because the
	 * overstatement is what stops anyone checking the thing it depends on. So the
	 * property asserted here is the conjunction, not the absence:
	 *
	 *     the delivery is still reachable, BECAUSE a verified recurring maintenance
	 *     action exists AND the §8.3b sweep reaches it within a stated number of runs.
	 *
	 * Neither half is worth anything alone. A bounded sweep that is not scheduled
	 * never runs; a scheduled sweep that starves never arrives.
	 *
	 * @return void
	 */
	public function test_every_jobless_scheduled_exit_is_covered_by_an_armed_and_bounded_sweep() {
		$covered = array();

		// EXIT 1 — the re-schedule cap is spent while the schema is still down.
		$capped = $this->armed_delivery();
		ScheduledDelivery::unschedule( $capped['delivery_id'], $capped['order_id'] );

		$this->with_broken_schema(
			function () use ( $capped ) {
				ScheduledDelivery::run( $capped['delivery_id'], $capped['order_id'], ScheduledDelivery::MAX_RESCHEDULES );
			}
		);

		$covered['the re-schedule cap spent with the schema still down'] = $capped;

		// EXIT 2 — Action Scheduler refuses the replacement job. `pre_as_schedule_single_action`
		// is Action Scheduler's own pre-empt filter in the bundled 3.9.3 copy: a
		// non-null return queues nothing, which is exactly what a conflicting plugin
		// or a full queue table produces.
		$refused = $this->armed_delivery();
		ScheduledDelivery::unschedule( $refused['delivery_id'], $refused['order_id'] );

		add_filter( 'pre_as_schedule_single_action', '__return_zero' );

		try {
			$this->with_broken_schema(
				function () use ( $refused ) {
					ScheduledDelivery::run( $refused['delivery_id'], $refused['order_id'], 0 );
				}
			);
		} finally {
			remove_filter( 'pre_as_schedule_single_action', '__return_zero' );
		}

		$covered['the queue refused the replacement job'] = $refused;

		foreach ( $covered as $exit => $delivery ) {
			// The exit really is the shape the corrected contract is about.
			$this->assertSame(
				DeliveryRepository::SCHEDULED,
				$this->status_of( $delivery['delivery_id'] ),
				"exit '{$exit}' did not leave the delivery scheduled — the fixture is not testing what it claims"
			);
			$this->assertFalse(
				$this->has_job( $delivery['delivery_id'], $delivery['order_id'] ),
				"exit '{$exit}' left a job behind — the fixture is not testing what it claims"
			);

			// HALF ONE: a live recurring maintenance action. Asked of Action Scheduler,
			// not of the plugin's bookkeeping.
			$this->assertNotFalse(
				as_next_scheduled_action( Maintenance::HOOK, array(), Maintenance::GROUP ),
				"exit '{$exit}' left a delivery with no job AND no maintenance action to recover it — §8.3c"
			);

			// HALF TWO: the sweep reaches it, within the bound §8.3b states. One
			// candidate is one cycle of E = 1, so ceil(E / T) = 1 run.
			$this->backdate_scheduled( $delivery['delivery_id'], ScheduledDelivery::LEASE_WINDOW_SECONDS + 60 );

			$swept = \Extonify\WCEP\Install\Maintenance::run();

			$this->assertTrue(
				$this->has_job( $delivery['delivery_id'], $delivery['order_id'] ),
				"exit '{$exit}' was not recovered within ceil(E / T) = 1 maintenance run"
			);
			$this->assertGreaterThanOrEqual( 1, (int) $swept['orphans_requeued'] );
		}

		fwrite(
			STDERR,
			"\n[7C gate 21] corrected contract: " . count( $covered ) . ' jobless-but-scheduled exits, each asserted against '
				. "BOTH an armed maintenance action and recovery within 1 sweep run\n"
		);
	}

	/**
	 * Run a callback with the schema check reporting failure.
	 *
	 * ⚠ THE REAL CHECK IS BROKEN, NOT MOCKED. `Migrator::is_operational()`
	 * memoises `verify_schema()`, so renaming the table and flushing the cache
	 * makes the production code take its production branch — which is the branch
	 * under test.
	 *
	 * @param callable $callback What to run.
	 * @return void
	 */
	private function with_broken_schema( callable $callback ) {
		global $wpdb;

		$table  = Migrator::table( 'deliveries' );
		$parked = $table . '_parked_7a';

		$wpdb->query( "RENAME TABLE {$table} TO {$parked}" );
		Migrator::flush_schema_cache();

		try {
			$this->assertFalse( Migrator::is_operational(), 'the fixture did not actually break the schema' );
			$callback();
		} finally {
			$wpdb->query( "RENAME TABLE {$parked} TO {$table}" );
			Migrator::flush_schema_cache();
		}
	}
}
