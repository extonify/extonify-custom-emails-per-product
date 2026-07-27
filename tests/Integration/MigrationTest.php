<?php
/**
 * Schema migration behaviour (ADR-0009).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Install\Migrator;

/**
 * dbDelta fails SILENTLY on mis-formatted input, so "the tables exist with the
 * right keys" has to be asserted against the live database rather than assumed
 * from the DDL.
 */
final class MigrationTest extends IntegrationTestCase {

	/**
	 * All three tables exist after activation.
	 *
	 * @return void
	 */
	public function test_all_three_tables_exist() {
		global $wpdb;

		foreach ( Migrator::tables() as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			$this->assertSame( $table, $found, "Missing table: {$table}" );
		}

		$this->assertCount( 3, Migrator::tables() );
	}

	/**
	 * The recorded schema version matches the target.
	 *
	 * @return void
	 */
	public function test_schema_version_is_recorded() {
		$this->assertSame(
			Migrator::TARGET_DB_VERSION,
			(int) get_option( Migrator::OPTION_DB_VERSION, 0 )
		);
	}

	/**
	 * No migration failure is recorded.
	 *
	 * @return void
	 */
	public function test_no_db_error_recorded() {
		$this->assertSame( '', (string) get_option( Migrator::OPTION_DB_ERROR, '' ) );
	}

	/**
	 * The tombstone's UNIQUE key exists on identity_hash. This is the race
	 * protection the whole ADR-0004 claim design rests on; without it the
	 * claim degrades to a plain insert and duplicates become possible.
	 *
	 * @return void
	 */
	public function test_tombstone_has_unique_index_on_identity_hash() {
		global $wpdb;
		$table = Migrator::table( 'deliveries' );

		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );

		$unique_on_hash = false;
		foreach ( (array) $indexes as $index ) {
			if ( 'identity_hash' === $index['Column_name'] && '0' === (string) $index['Non_unique'] ) {
				$unique_on_hash = true;
			}
		}

		$this->assertTrue( $unique_on_hash, 'identity_hash is not covered by a UNIQUE index.' );
	}

	/**
	 * identity_hash is a fixed-length CHAR(64) (ADR-0009): a variable-length
	 * raw tuple is what makes index-length limits bite on some configurations.
	 *
	 * @return void
	 */
	public function test_identity_hash_is_fixed_length() {
		global $wpdb;
		$table = Migrator::table( 'deliveries' );

		$column = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'identity_hash'", ARRAY_A );

		$this->assertNotNull( $column );
		$this->assertSame( 'char(64)', strtolower( (string) $column['Type'] ) );
	}

	/**
	 * Every index ADR-0009 specifies is present.
	 *
	 * @dataProvider expected_index_provider
	 *
	 * @param string $table_key  Unprefixed table name.
	 * @param string $index_name Expected key name.
	 * @return void
	 */
	public function test_expected_indexes_exist( string $table_key, string $index_name ) {
		global $wpdb;
		$table = Migrator::table( $table_key );

		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		$names   = array();
		foreach ( (array) $indexes as $index ) {
			$names[] = $index['Key_name'];
		}

		$this->assertContains( $index_name, $names, "Missing index {$index_name} on {$table}" );
	}

	/**
	 * Indexes the schema must carry.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function expected_index_provider() {
		return array(
			'rules status+priority'    => array( 'rules', 'status_priority' ),
			'rules trigger lookup'     => array( 'rules', 'trigger_lookup' ),
			'deliveries order_id'      => array( 'deliveries', 'order_id' ),
			'details delivery_id'      => array( 'delivery_details', 'delivery_id' ),
			// Prompt 2a: the separate created_at and is_debug indexes were
			// REPLACED by one composite, because the retention purge filters on
			// is_debug, state and created_at together.
			'details retention purge'  => array( 'delivery_details', 'retention' ),
			'details recipient lookup' => array( 'delivery_details', 'recipient' ),
		);
	}

	/**
	 * Re-running the migration is a no-op: same version, same tables, and — the
	 * part that actually matters — no data loss.
	 *
	 * @return void
	 */
	public function test_migration_is_idempotent_on_rerun() {
		$order_id = $this->fake_order_id();
		$identity = $this->unique_identity();

		$repo  = new \Extonify\WCEP\Repository\DeliveryRepository();
		$claim = $repo->claim( $order_id, 1, 'insert', $identity );
		$this->track_delivery( $claim['delivery_id'] );

		$before = $repo->count();

		$this->assertTrue( true === Migrator::migrate() );
		$this->assertTrue( true === Migrator::migrate() );

		$this->assertSame( Migrator::TARGET_DB_VERSION, (int) get_option( Migrator::OPTION_DB_VERSION, 0 ) );
		$this->assertTrue( Migrator::is_operational( true ) );
		$this->assertSame( $before, $repo->count(), 'Re-running the migration changed the row count.' );
		$this->assertNotNull( $repo->find_by_hash( $claim['identity_hash'] ), 'Re-running the migration destroyed existing data.' );
	}

	/**
	 * The migration lock is released, not left behind, after a successful run.
	 *
	 * @return void
	 */
	public function test_migration_lock_is_released() {
		Migrator::migrate();

		$this->assertFalse( get_option( Migrator::OPTION_LOCK, false ) );
	}
}
