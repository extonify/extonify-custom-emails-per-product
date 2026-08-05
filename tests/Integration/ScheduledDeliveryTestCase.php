<?php
/**
 * Shared fixtures for the delayed-delivery suite (ADR-0007, ADR-0015).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\DeliveryLogger;
use Extonify\WCEP\Delivery\ScheduledDelivery;
use Extonify\WCEP\Domain\DeliverySnapshot;
use Extonify\WCEP\Domain\WriteResult;
use Extonify\WCEP\Install\Maintenance;
use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * A REAL Action Scheduler queue, and execution driven directly.
 *
 * ⚠ WHY BOTH, RATHER THAN A FAKE QUEUE OR A REAL WAIT. The POC simulated the
 * scheduler and this is the first real integration, so the tests have to touch
 * the real thing: `as_has_scheduled_action()` against the live store is what
 * proves a job exists, that it is args-exact, and that cancelling removed it —
 * gate 19's whole subject. But WAITING an hour for the action to fire proves
 * nothing extra, so execution is invoked directly with the same argument set
 * Action Scheduler would spread over the callback.
 *
 * Every action this suite queues is removed on teardown, so a test that fails
 * part way cannot leave work in the store's queue for a later run to trip over.
 */
abstract class ScheduledDeliveryTestCase extends DeliveryTestCase {

	/**
	 * Deliveries queued by this test, as `{delivery_id, order_id}`.
	 *
	 * @var array[]
	 */
	protected $queued = array();

	/**
	 * Model a FRESH REQUEST for the maintenance-arming check (ADR-0015 §8.3c).
	 *
	 * ⚠ WITHOUT THIS, `ensure_armed()` IS A NO-OP FOR THE WHOLE SUITE, AND THE REASON
	 * IS A PROPERTY OF THE HARNESS RATHER THAN OF THE PLUGIN. WordPress boots against
	 * `DB_NAME`, and `tests/bootstrap.php` switches `$wpdb` onto the test database
	 * afterwards — so the `action_scheduler_init` arming that runs during boot verifies
	 * (and, if needed, arms) the DEVELOPMENT database, then leaves the request-scoped
	 * static set. Every later check would short-circuit on a fact that is not true of
	 * the database the tests actually run against.
	 *
	 * Clearing it per test is also the more faithful model: one test is one request.
	 *
	 * @before
	 * @return void
	 */
	protected function set_up_scheduled_arming() {
		Maintenance::forget_verification();
	}

	/**
	 * Remove every action this test queued.
	 *
	 * @after
	 * @return void
	 */
	protected function tear_down_scheduled() {
		foreach ( $this->queued as $entry ) {
			ScheduledDelivery::unschedule( (int) $entry['delivery_id'], (int) $entry['order_id'] );
		}

		$this->queued = array();
	}

	/**
	 * A separate-mode rule with a delay.
	 *
	 * @param int   $product_id Product to target.
	 * @param array $overrides  Rule fields to replace.
	 * @return int Rule id.
	 */
	protected function make_delayed_rule( int $product_id, array $overrides = array() ): int {
		return $this->make_sending_rule(
			$product_id,
			array_merge(
				array(
					'name'          => 'WCEP delayed fixture',
					'trigger_type'  => 'status',
					'trigger_value' => 'processing',
					'delay_seconds' => HOUR_IN_SECONDS,
					'subject'       => 'Care guide for {customer_first_name}',
					'content'       => '<p>DELAYED BLOCK for {customer_first_name}.</p>',
				),
				$overrides
			)
		);
	}

	/**
	 * An order with a billing identity the placeholders can resolve.
	 *
	 * @param int[] $lines Product ids, as `make_order_with()` takes.
	 * @return \WC_Order
	 */
	protected function delayed_order( array $lines ): \WC_Order {
		$order = $this->make_order_with( $lines );

		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_billing_email( 'ada@example.test' );
		$order->save();

		return $order;
	}

	/**
	 * The scheduled tombstone for one rule, tracked for cleanup.
	 *
	 * @param int    $order_id Order id.
	 * @param int    $rule_id  Rule id.
	 * @param string $identity Trigger identity.
	 * @return array|null
	 */
	protected function scheduled_tombstone( int $order_id, int $rule_id, string $identity = 'status:processing' ): ?array {
		$row = $this->deliveries->find( $order_id, $rule_id, DeliveryLogger::MODE, $identity );

		if ( null !== $row ) {
			$this->track_delivery( (int) $row['id'] );
			$this->queued[] = array(
				'delivery_id' => (int) $row['id'],
				'order_id'    => $order_id,
			);
		}

		return $row;
	}

	/**
	 * Whether this delivery has a pending Action Scheduler job.
	 *
	 * Reads the LIVE store through Action Scheduler's own args-exact API — the
	 * same call the scheduling path uses, so the test and the code agree on what
	 * "pending" means.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @param int $order_id    Order id.
	 * @return bool
	 */
	protected function has_job( int $delivery_id, int $order_id ): bool {
		// ⚠ ANY ATTEMPT, NOT JUST 0 (ADR-0015 §8.4). A delivery re-scheduled past a
		// transient condition owns a job whose argument set differs, and gate 19's
		// question is about the DELIVERY rather than one argument set.
		return ScheduledDelivery::has_any_job( $delivery_id, $order_id );
	}

	/**
	 * Whether one exact argument set has a pending action.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @param int $order_id    Order id.
	 * @param int $attempt     Transient re-schedule count.
	 * @return bool
	 */
	protected function has_job_at( int $delivery_id, int $order_id, int $attempt ): bool {
		return ScheduledDelivery::is_scheduled( $delivery_id, $order_id, $attempt );
	}

	/**
	 * The tombstone's current `final_status`.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return string Empty when the row is gone.
	 */
	protected function status_of( int $delivery_id ): string {
		$row = $this->deliveries->find_by_id( $delivery_id );

		return null === $row ? '' : (string) $row['final_status'];
	}

	/**
	 * Force a tombstone into `executing` with a lease of a chosen age.
	 *
	 * ⚠ WRITTEN THROUGH THE REAL TRANSITION FIRST, THEN BACKDATED. Setting the
	 * status by raw SQL alone would let a test pass against a transition table that
	 * no longer permits the move — the assertion would be testing the fixture.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @param int $age_seconds How long ago the lease was taken.
	 * @return void
	 */
	protected function backdate_lease( int $delivery_id, int $age_seconds ) {
		global $wpdb;

		$this->assertTrue(
			$this->deliveries->transition( $delivery_id, DeliveryRepository::SCHEDULED, DeliveryRepository::EXECUTING )->won(),
			'the fixture could not take the execution lease'
		);

		$table = Migrator::table( 'deliveries' );
		$when  = gmdate( 'Y-m-d H:i:s', time() - $age_seconds );

		$wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET lease_taken_at = %s WHERE id = %d", $when, $delivery_id )
		);
	}

	/**
	 * Backdate a `scheduled` row so the orphan sweep is entitled to look at it.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @param int $age_seconds How long ago the row was last touched.
	 * @return void
	 */
	protected function backdate_scheduled( int $delivery_id, int $age_seconds ) {
		global $wpdb;

		$table = Migrator::table( 'deliveries' );
		$when  = gmdate( 'Y-m-d H:i:s', time() - $age_seconds );

		$wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET last_seen_at = %s WHERE id = %d", $when, $delivery_id )
		);
	}

	/**
	 * A second, independent database connection.
	 *
	 * ⚠ TWO REAL CONNECTIONS, NOT A SIMULATION. The whole claim of ADR-0015 §8.1 is
	 * that two writers cannot both act, and a single connection replaying two
	 * statements in sequence proves nothing about that — it proves the statements
	 * are correct in isolation, which was never in doubt. Copied in shape from
	 * `ParentLockingTest`, which established the harness.
	 *
	 * @return \wpdb|null Null when a second connection cannot be opened.
	 */
	protected function second_connection(): ?\wpdb {
		if ( ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_HOST' ) ) {
			return null;
		}

		$second = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		if ( ! empty( $second->error ) ) {
			return null;
		}

		global $wpdb;
		$second->set_prefix( $wpdb->prefix );
		$second->select( (string) $wpdb->get_var( 'SELECT DATABASE()' ) );
		$second->suppress_errors( true );

		return ( '1' === (string) $second->get_var( 'SELECT 1' ) ) ? $second : null;
	}

	/**
	 * Run one guarded transition over a SPECIFIC connection.
	 *
	 * The repository always uses the global `$wpdb`, so the connection is swapped
	 * around the call — which is exactly what a second worker in a second PHP
	 * process would be doing with its own connection.
	 *
	 * @param \wpdb  $connection  Connection to run on.
	 * @param int    $delivery_id Tombstone id.
	 * @param string $from        Source state.
	 * @param string $to          Target state.
	 * @return bool Whether this connection won the transition.
	 */
	protected function transition_on( \wpdb $connection, int $delivery_id, string $from, string $to ): bool {
		return $this->write_on( $connection, $delivery_id, $from, $to )->won();
	}

	/**
	 * The same, keeping the STRUCTURED result (ADR-0015 §8.1a).
	 *
	 * ⚠ NEEDED BECAUSE "DID NOT WIN" IS TWO FACTS. A competing write that ran and
	 * matched nothing, and one that failed outright, are the pair this whole
	 * correction round is about — a test that reduces them to a boolean before
	 * asserting cannot tell which one it saw.
	 *
	 * @param \wpdb  $connection  Connection to run on.
	 * @param int    $delivery_id Tombstone id.
	 * @param string $from        Source state.
	 * @param string $to          Target state.
	 * @return WriteResult
	 */
	protected function write_on( \wpdb $connection, int $delivery_id, string $from, string $to ): WriteResult {
		global $wpdb;

		$original = $wpdb;
		$wpdb     = $connection; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- deliberately running one repository call on a second real connection; restored immediately below.

		try {
			return ( new DeliveryRepository() )->transition( $delivery_id, $from, $to );
		} finally {
			$wpdb = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the suite's own connection.
		}
	}

	/**
	 * Run one queued delivery, exactly as Action Scheduler would.
	 *
	 * ⚠ THE CAPTURE IS CLEARED FIRST, AND THAT IS NOT TIDINESS. The capture sits
	 * on `pre_wp_mail` at priority 1 and therefore catches **WooCommerce's own**
	 * mail as well as this plugin's — and the re-validation fixtures cancel,
	 * fail and refund orders, every one of which makes WooCommerce send a
	 * customer or admin notification. Counting those as ours would make
	 * `assertMailCount( 0 )` fail for a delivery that correctly sent nothing, and
	 * — far worse — could make it PASS for one that sent something it should not
	 * have. Clearing here scopes the count to what the job itself did.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @param int $order_id    Order id.
	 * @return void
	 */
	protected function run_job( int $delivery_id, int $order_id ): void {
		$this->captured_mail = array();

		$args = ScheduledDelivery::args( $delivery_id, $order_id );

		ScheduledDelivery::run( $args['delivery_id'], $args['order_id'] );
	}

	/**
	 * The snapshot stored on one tombstone.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return array|null
	 */
	protected function snapshot_of( int $delivery_id ): ?array {
		$row = $this->deliveries->find_by_id( $delivery_id );

		return null === $row ? null : DeliverySnapshot::read( (string) ( $row['snapshot'] ?? '' ) );
	}

	/**
	 * The raw `snapshot` column of one tombstone.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return string|null
	 */
	protected function raw_snapshot_of( int $delivery_id ): ?string {
		$row = $this->deliveries->find_by_id( $delivery_id );

		return null === $row ? null : ( $row['snapshot'] ?? null );
	}

	/**
	 * The `reason_code` recorded on a cancelled delivery's detail row.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return string
	 */
	protected function cancellation_code( int $delivery_id ): string {
		foreach ( $this->detail_rows( $delivery_id ) as $row ) {
			$snapshot = (array) ( $row['snapshot'] ?? array() );
			$code     = (string) ( $snapshot['cancelled']['reason_code'] ?? '' );

			if ( '' !== $code ) {
				return $code;
			}
		}

		return '';
	}

	/**
	 * Every detail row's `reason`, joined.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return string
	 */
	protected function reasons_of( int $delivery_id ): string {
		$parts = array();

		foreach ( $this->detail_rows( $delivery_id ) as $row ) {
			$parts[] = (string) ( $row['reason'] ?? '' );
		}

		return implode( ' | ', $parts );
	}
}
