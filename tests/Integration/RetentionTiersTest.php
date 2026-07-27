<?php
/**
 * Three-tier retention and UTC timestamps (Prompt 2a Item 4).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Install\Activator;
use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Repository\DeliveryDetailRepository;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * ADR-0005 documents 90 / 180 / 14 day retention, but the purge originally had
 * only a normal and a debug tier — so FAILED deliveries were destroyed at 90
 * days instead of 180. Failure history is exactly what support needs longest.
 */
final class RetentionTiersTest extends IntegrationTestCase {

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
	 * Owning tombstone.
	 *
	 * @var int
	 */
	private $delivery_id = 0;

	/**
	 * Build repositories and an owning tombstone.
	 *
	 * @before
	 * @return void
	 */
	protected function set_up_fixture() {
		$this->deliveries = new DeliveryRepository();
		$this->details    = new DeliveryDetailRepository();

		$claim             = $this->deliveries->claim( $this->fake_order_id(), 60, 'separate', $this->unique_identity() );
		$this->delivery_id = $this->track_delivery( $claim['delivery_id'] );
	}

	/**
	 * Seed one detail row of a given state, debug flag and age.
	 *
	 * @param string $state    Attempt state.
	 * @param bool   $is_debug Debug flag.
	 * @param int    $age_days Age in days.
	 * @return int Detail row id.
	 */
	private function seed_row( string $state, bool $is_debug, int $age_days ): int {
		global $wpdb;

		$id = $this->details->insert(
			$this->delivery_id,
			array(
				'state'     => $state,
				'is_debug'  => $is_debug,
				'type'      => $is_debug ? 'debug' : 'auto',
				'recipient' => 'wcep-retention-' . wp_generate_password( 8, false, false ) . '@example.test',
			)
		);
		$this->track_detail( $id );

		$wpdb->update(
			Migrator::table( 'delivery_details' ),
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( $age_days * DAY_IN_SECONDS ) ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		return $id;
	}

	/**
	 * Whether a detail row still exists.
	 *
	 * @param int $id Detail row id.
	 * @return bool
	 */
	private function row_exists( int $id ): bool {
		global $wpdb;
		$table = Migrator::table( 'delivery_details' );
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * The five cases named in the correction pass, run against ONE purge with
	 * the documented 90 / 180 / 14 windows.
	 *
	 * @return void
	 */
	public function test_three_tiers_purge_independently() {
		$normal_100 = $this->seed_row( 'sent', false, 100 );
		$failed_100 = $this->seed_row( 'failed', false, 100 );
		$failed_181 = $this->seed_row( 'failed', false, 181 );
		$debug_15   = $this->seed_row( 'sent', true, 15 );
		$debug_13   = $this->seed_row( 'sent', true, 13 );

		$this->details->purge_older_than( 90, 180, 14 );

		$this->assertFalse( $this->row_exists( $normal_100 ), 'A normal row at 100 days survived the 90-day window.' );
		$this->assertTrue( $this->row_exists( $failed_100 ), 'A FAILED row at 100 days was destroyed — failed retention is 180 days.' );
		$this->assertFalse( $this->row_exists( $failed_181 ), 'A failed row at 181 days survived the 180-day window.' );
		$this->assertFalse( $this->row_exists( $debug_15 ), 'A debug row at 15 days survived the 14-day window.' );
		$this->assertTrue( $this->row_exists( $debug_13 ), 'A debug row at 13 days was purged too early.' );
	}

	/**
	 * A tier set to 0 keeps its rows indefinitely, which is what backs the
	 * ADR-0005 keep-everything option.
	 *
	 * @return void
	 */
	public function test_zero_days_keeps_a_tier_indefinitely() {
		$normal = $this->seed_row( 'sent', false, 500 );
		$failed = $this->seed_row( 'failed', false, 500 );
		$debug  = $this->seed_row( 'sent', true, 500 );

		$this->details->purge_older_than( 0, 0, 0 );

		$this->assertTrue( $this->row_exists( $normal ) );
		$this->assertTrue( $this->row_exists( $failed ) );
		$this->assertTrue( $this->row_exists( $debug ) );
	}

	/**
	 * A debug row that is ALSO failed follows the debug window, not the failed
	 * one — the predicates are mutually exclusive, so no row is considered
	 * twice or missed.
	 *
	 * @return void
	 */
	public function test_debug_and_failed_predicates_do_not_overlap() {
		$debug_failed = $this->seed_row( 'failed', true, 20 );

		$this->details->purge_older_than( 90, 180, 14 );

		$this->assertFalse( $this->row_exists( $debug_failed ), 'A debug+failed row escaped both windows.' );
	}

	/**
	 * The purge never touches tombstones.
	 *
	 * @return void
	 */
	public function test_purge_leaves_tombstones_alone() {
		$this->seed_row( 'sent', false, 500 );
		$before = $this->deliveries->count();

		$this->details->purge_older_than( 1, 1, 1 );

		$this->assertSame( $before, $this->deliveries->count() );
	}

	/**
	 * The shipped retention defaults are the ADR-0005 windows.
	 *
	 * @return void
	 */
	public function test_shipped_defaults_match_the_adr() {
		$defaults = Activator::default_settings();

		$this->assertSame( 90, $defaults['retention_days'] );
		$this->assertSame( 180, $defaults['retention_days_failed'] );
		$this->assertSame( 14, $defaults['retention_days_debug'] );
	}

	/**
	 * created_at is written in UTC.
	 *
	 * This box's MySQL reports a system timezone of UTC+5, so a row written
	 * with the database's own NOW() would sit five hours in the future
	 * relative to the gmdate() cutoff the purge compares against — enough to
	 * mis-fire retention near a boundary. Writing through PHP keeps both sides
	 * on UTC.
	 *
	 * @return void
	 */
	public function test_created_at_is_written_in_utc() {
		$id = $this->details->insert(
			$this->delivery_id,
			array(
				'state'     => 'sent',
				'recipient' => 'wcep-utc-' . wp_generate_password( 8, false, false ) . '@example.test',
			)
		);
		$this->track_detail( $id );

		global $wpdb;
		$table      = Migrator::table( 'delivery_details' );
		$created_at = (string) $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$table} WHERE id = %d", $id ) );

		$drift = abs( strtotime( $created_at . ' UTC' ) - time() );
		$this->assertLessThan( 120, $drift, "created_at is not UTC — it reads {$created_at} against a UTC now of " . gmdate( 'Y-m-d H:i:s' ) );
	}

	/**
	 * No datetime column carries a server-side CURRENT_TIMESTAMP default.
	 *
	 * A future schema edit that added one would silently reintroduce the
	 * timezone skew above, because MySQL would then stamp rows in the database
	 * server's local time rather than UTC.
	 *
	 * @return void
	 */
	public function test_no_datetime_column_uses_a_server_side_default() {
		global $wpdb;

		foreach ( Migrator::TABLES as $name ) {
			$table   = Migrator::table( $name );
			$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );

			foreach ( (array) $columns as $column ) {
				if ( false === stripos( (string) $column['Type'], 'datetime' ) && false === stripos( (string) $column['Type'], 'timestamp' ) ) {
					continue;
				}
				$this->assertStringNotContainsStringIgnoringCase(
					'CURRENT_TIMESTAMP',
					(string) $column['Default'],
					"{$table}.{$column['Field']} has a server-side timestamp default; retention would use the DB server's timezone, not UTC."
				);
				$this->assertStringNotContainsStringIgnoringCase(
					'CURRENT_TIMESTAMP',
					(string) $column['Extra'],
					"{$table}.{$column['Field']} auto-updates from the DB clock; retention would use the DB server's timezone, not UTC."
				);
			}
		}
	}
}
