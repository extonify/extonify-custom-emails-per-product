<?php
/**
 * Live-schema verification (Prompt 2a Item 1).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Install\Migrator;

/**
 * The defect these tests exist for: dbDelta() fails SILENTLY. If the UNIQUE
 * index on identity_hash is not created on some host, the tables still exist,
 * the version option still gets stamped, and the plugin still reports itself
 * operational — while the one race protection the whole design rests on is
 * simply absent. Two concurrent requests could then claim the same delivery
 * and send the customer two emails.
 *
 * Each test here BREAKS the live schema deliberately, asserts the guard
 * notices, and puts it back.
 */
final class SchemaVerificationTest extends IntegrationTestCase {

	/**
	 * Whether the schema was deliberately broken and needs restoring.
	 *
	 * @var bool
	 */
	private $needs_restore = false;

	/**
	 * Always leave the runtime with a correct, verified schema.
	 *
	 * @after
	 * @return void
	 */
	protected function restore_schema() {
		if ( ! $this->needs_restore ) {
			return;
		}
		$this->needs_restore = false;

		self::hard_reset_schema();

		$this->assertTrue(
			true === Migrator::verify_schema(),
			'Failed to restore the schema after a deliberate break.'
		);
	}

	/**
	 * Drop and recreate the schema from scratch.
	 *
	 * A plain migrate() is NOT enough to undo every break these tests make:
	 * dbDelta() will add a missing column or index, but it will not convert an
	 * existing non-UNIQUE index into a UNIQUE one. A repair that quietly failed
	 * would leave the schema broken for every following test, so the reset is
	 * unconditional.
	 *
	 * Dropping the tables also discards any rows this test tracked, which is
	 * harmless: the base-class teardown then finds nothing to delete and
	 * reports no leaks, whichever order the @after hooks run in.
	 *
	 * @return void
	 */
	public static function hard_reset_schema(): void {
		// One repair routine, shared with the base class, so the suite cannot
		// drift into having two subtly different notions of "put it back".
		self::force_rebuild_schema();
	}

	/**
	 * Drop an index from a plugin table.
	 *
	 * @param string $table_key  Unprefixed table name.
	 * @param string $index_name Index to drop.
	 * @return void
	 */
	private function drop_index( string $table_key, string $index_name ) {
		global $wpdb;
		$this->assert_destructive_tests_allowed();
		$this->needs_restore = true;
		$table               = Migrator::table( $table_key );
		$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `{$index_name}`" );
		Migrator::flush_schema_cache();
	}

	/**
	 * Drop a column from a plugin table.
	 *
	 * @param string $table_key Unprefixed table name.
	 * @param string $column    Column to drop.
	 * @return void
	 */
	private function drop_column( string $table_key, string $column ) {
		global $wpdb;
		$this->assert_destructive_tests_allowed();
		$this->needs_restore = true;
		$table               = Migrator::table( $table_key );
		$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `{$column}`" );
		Migrator::flush_schema_cache();
	}

	/**
	 * A correct install verifies and is operational.
	 *
	 * @return void
	 */
	public function test_healthy_schema_verifies() {
		$this->assertTrue( true === Migrator::verify_schema() );
		$this->assertTrue( Migrator::is_operational( true ) );
		$this->assertSame( Migrator::TARGET_DB_VERSION, (int) get_option( Migrator::OPTION_DB_VERSION, 0 ) );
	}

	/**
	 * THE HEADLINE CASE. The tables exist and every column is present, but the
	 * UNIQUE index is gone: verification must fail, the plugin must report
	 * itself NOT operational, and a migration attempt must NOT stamp the
	 * version.
	 *
	 * @return void
	 */
	public function test_non_unique_index_where_unique_is_required_is_detected() {
		global $wpdb;

		// The truest form of the silent failure: an index of the RIGHT NAME
		// exists, but it is not UNIQUE. A check that only looked for the key
		// name would pass while the race protection was absent.
		$this->assert_destructive_tests_allowed();
		$this->needs_restore = true;
		$table               = Migrator::table( 'deliveries' );
		$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `identity_hash`" );
		$wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `identity_hash` (`identity_hash`)" );
		Migrator::flush_schema_cache();

		// Every table and every index NAME is present.
		$this->assertTrue( $this->tables_all_exist() );
		$this->assertTrue( $this->index_exists( 'deliveries', 'identity_hash' ) );

		$error = Migrator::verify_schema();
		$this->assertWPError( $error );
		$this->assertStringContainsString( 'UNIQUE', (string) $error->get_error_data()['detail'] );
		$this->assertStringContainsString( 'identity_hash', (string) $error->get_error_data()['detail'] );

		$this->assertFalse( Migrator::is_operational( true ), 'A schema whose identity_hash index is not UNIQUE reported itself operational.' );

		// And a duplicate really is possible in that state — which is exactly
		// what the guard exists to prevent shipping unnoticed.
		$suppressed = $wpdb->suppress_errors( true );
		$first      = $wpdb->insert(
			$table,
			array(
				'identity_hash' => str_repeat( 'a', 64 ),
				'order_id'      => 999111,
				'rule_id'       => 1,
				'mode'          => 'insert',
			),
			array( '%s', '%d', '%d', '%s' )
		);
		$second     = $wpdb->insert(
			$table,
			array(
				'identity_hash' => str_repeat( 'a', 64 ),
				'order_id'      => 999111,
				'rule_id'       => 1,
				'mode'          => 'insert',
			),
			array( '%s', '%d', '%d', '%s' )
		);
		$wpdb->suppress_errors( $suppressed );
		$wpdb->delete( $table, array( 'order_id' => 999111 ), array( '%d' ) );

		$this->assertTrue( (bool) $first );
		$this->assertTrue( (bool) $second, 'Sanity check failed: the index really should be non-unique here.' );
	}

	/**
	 * A missing index (name and all) is detected, and the version is NOT
	 * stamped while the schema is invalid.
	 *
	 * @return void
	 */
	public function test_missing_index_is_detected_and_version_not_stamped() {
		$this->drop_index( 'deliveries', 'identity_hash' );

		// Table still exists, so a naive SHOW TABLES probe would say "fine".
		$this->assertTrue( $this->tables_all_exist() );

		$error = Migrator::verify_schema();
		$this->assertWPError( $error );
		$this->assertStringContainsString( 'identity_hash', (string) $error->get_error_data()['detail'] );

		$this->assertFalse( Migrator::is_operational( true ), 'A schema without the index reported itself operational.' );

		// Simulate a fresh install whose dbDelta silently skipped the index:
		// with the version cleared, the STRUCTURAL check must fail and the
		// version must remain unstamped.
		delete_option( Migrator::OPTION_DB_VERSION );
		Migrator::flush_schema_cache();

		$this->assertWPError( Migrator::verify_schema( false ) );
		$this->assertSame( 0, (int) get_option( Migrator::OPTION_DB_VERSION, 0 ), 'The version was stamped while the schema was invalid.' );
	}

	/**
	 * Replace an index with a deliberately wrong definition.
	 *
	 * @param string   $table_key  Unprefixed table name.
	 * @param string   $index_name Index to rebuild.
	 * @param string[] $columns    Columns for the replacement, in order.
	 * @param bool     $unique     Whether the replacement is UNIQUE.
	 * @return void
	 */
	private function replace_index( string $table_key, string $index_name, array $columns, bool $unique ) {
		global $wpdb;
		$this->assert_destructive_tests_allowed();
		$this->needs_restore = true;

		$table = Migrator::table( $table_key );
		$cols  = '`' . implode( '`, `', $columns ) . '`';

		$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `{$index_name}`" );
		$wpdb->query( 'ALTER TABLE `' . $table . '` ADD ' . ( $unique ? 'UNIQUE ' : '' ) . "INDEX `{$index_name}` ({$cols})" );
		Migrator::flush_schema_cache();
	}

	/**
	 * A COMPOSITE unique index over (identity_hash, order_id) is rejected.
	 *
	 * This is the subtle one. The index is UNIQUE and it does contain
	 * identity_hash, so a check that flattened "unique columns" into one list
	 * would accept it — while the constraint it actually enforces is on the
	 * PAIR. Two rows with the same identity but different orders would both be
	 * insertable, and the customer would get the email twice.
	 *
	 * @return void
	 */
	public function test_composite_unique_index_is_rejected() {
		$this->replace_index( 'deliveries', 'identity_hash', array( 'identity_hash', 'order_id' ), true );

		$error = Migrator::verify_schema();
		$this->assertWPError( $error );
		$this->assertStringContainsString( 'wrong columns', (string) $error->get_error_data()['detail'] );
		$this->assertStringContainsString( 'identity_hash', (string) $error->get_error_data()['detail'] );
		$this->assertFalse( Migrator::is_operational( true ) );
	}

	/**
	 * The right index NAME on the wrong column is rejected.
	 *
	 * @return void
	 */
	public function test_correct_index_name_on_wrong_column_is_rejected() {
		$this->replace_index( 'deliveries', 'order_id', array( 'rule_id' ), false );

		$error = Migrator::verify_schema();
		$this->assertWPError( $error );
		$this->assertStringContainsString( 'wrong columns', (string) $error->get_error_data()['detail'] );
		$this->assertStringContainsString( 'order_id', (string) $error->get_error_data()['detail'] );
	}

	/**
	 * The retention index with its columns in the wrong ORDER is rejected.
	 *
	 * Order is not cosmetic: the purge filters is_debug and state by equality
	 * and created_at by range, so created_at has to come last. Leading with it
	 * makes the index useless for the purge while still "existing".
	 *
	 * @return void
	 */
	public function test_retention_index_with_reversed_columns_is_rejected() {
		$this->replace_index( 'delivery_details', 'retention', array( 'created_at', 'state', 'is_debug' ), false );

		$error = Migrator::verify_schema();
		$this->assertWPError( $error );
		$this->assertStringContainsString( 'wrong columns', (string) $error->get_error_data()['detail'] );
		$this->assertStringContainsString( 'retention', (string) $error->get_error_data()['detail'] );
	}

	/**
	 * The recipient index pointing at recipient_type is rejected — the privacy
	 * lookup would then be an unindexed full table scan.
	 *
	 * @return void
	 */
	public function test_recipient_index_on_wrong_column_is_rejected() {
		$this->replace_index( 'delivery_details', 'recipient', array( 'recipient_type' ), false );

		$error = Migrator::verify_schema();
		$this->assertWPError( $error );
		$this->assertStringContainsString( 'wrong columns', (string) $error->get_error_data()['detail'] );
		$this->assertStringContainsString( 'recipient', (string) $error->get_error_data()['detail'] );
	}

	/**
	 * An index that is UNIQUE when it should not be is also rejected — the
	 * check is exact in both directions.
	 *
	 * @return void
	 */
	public function test_unexpected_unique_index_is_rejected() {
		$this->replace_index( 'delivery_details', 'delivery_id', array( 'delivery_id' ), true );

		$error = Migrator::verify_schema();
		$this->assertWPError( $error );
		$this->assertStringContainsString( 'unexpected UNIQUE', (string) $error->get_error_data()['detail'] );
	}

	/**
	 * A non-InnoDB table is rejected.
	 *
	 * DeliveryRepository::delete_for_order() wraps its two deletes in a
	 * transaction so a failed child delete rolls back instead of orphaning
	 * detail rows. START TRANSACTION silently NO-OPS on MyISAM, so on a MyISAM
	 * table that rollback guarantee would be a comment rather than a
	 * behaviour — and the fail-closed cleanup would half-complete.
	 *
	 * @return void
	 */
	public function test_non_innodb_table_is_rejected() {
		global $wpdb;

		$this->assert_destructive_tests_allowed();
		$this->needs_restore = true;

		$table      = Migrator::table( 'delivery_details' );
		$suppressed = $wpdb->suppress_errors( true );
		$converted  = $wpdb->query( "ALTER TABLE `{$table}` ENGINE=MyISAM" );
		$wpdb->suppress_errors( $suppressed );
		Migrator::flush_schema_cache();

		$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
		if ( false === $converted || 0 === strcasecmp( (string) $engine, 'InnoDB' ) ) {
			$this->markTestSkipped( 'This MySQL build will not convert the table to MyISAM, so the engine check cannot be exercised here.' );
		}

		$error = Migrator::verify_schema();
		$this->assertWPError( $error );
		$this->assertStringContainsString( 'storage engine', (string) $error->get_error_data()['detail'] );
		$this->assertStringContainsString( 'InnoDB', (string) $error->get_error_data()['detail'] );
		$this->assertFalse( Migrator::is_operational( true ), 'A MyISAM table reported itself operational.' );
	}

	/**
	 * All three tables are InnoDB on a healthy install.
	 *
	 * @return void
	 */
	public function test_all_tables_are_innodb() {
		global $wpdb;

		foreach ( Migrator::tables() as $table ) {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			$this->assertSame( 'innodb', strtolower( (string) $engine ), "{$table} is not InnoDB." );
		}
	}

	/**
	 * Every index carries exactly the ordered columns and uniqueness the
	 * specification declares — asserted against the live database rather than
	 * against the DDL that was submitted.
	 *
	 * @return void
	 */
	public function test_live_indexes_match_the_specification_exactly() {
		global $wpdb;

		foreach ( Migrator::SCHEMA as $name => $expected ) {
			$table = Migrator::table( $name );

			$live = array();
			foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ) as $row ) {
				$key                    = (string) $row['Key_name'];
				$live[ $key ]['unique'] = ( '0' === (string) $row['Non_unique'] );
				$live[ $key ]['columns'][ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
			}
			foreach ( $live as $key => $definition ) {
				ksort( $live[ $key ]['columns'] );
				$live[ $key ]['columns'] = array_values( $live[ $key ]['columns'] );
			}

			// Exactly the managed indexes, no more and no fewer: a leftover
			// index from a superseded schema shows up here.
			$this->assertSame(
				array_keys( $expected['indexes'] ),
				array_keys( $live ),
				"{$table} carries a different set of indexes than the specification."
			);

			foreach ( $expected['indexes'] as $index_name => $spec ) {
				$this->assertSame( $spec['columns'], $live[ $index_name ]['columns'], "{$table}.{$index_name} has the wrong ordered columns." );
				$this->assertSame( $spec['unique'], $live[ $index_name ]['unique'], "{$table}.{$index_name} has the wrong uniqueness." );
			}
		}
	}

	/**
	 * Whether a named index exists on a plugin table.
	 *
	 * @param string $table_key  Unprefixed table name.
	 * @param string $index_name Index name.
	 * @return bool
	 */
	private function index_exists( string $table_key, string $index_name ): bool {
		global $wpdb;
		$table = Migrator::table( $table_key );
		foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ) as $index ) {
			if ( $index_name === $index['Key_name'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A missing column is detected and named.
	 *
	 * @return void
	 */
	public function test_missing_column_is_detected() {
		$this->drop_column( 'delivery_details', 'recipient_type' );

		$error = Migrator::verify_schema();
		$this->assertWPError( $error );
		$this->assertStringContainsString( 'recipient_type', (string) $error->get_error_data()['detail'] );
		$this->assertFalse( Migrator::is_operational( true ) );
	}

	/**
	 * A missing supporting index is detected — including the composite
	 * retention index the purge depends on.
	 *
	 * @return void
	 */
	public function test_missing_retention_index_is_detected() {
		$this->drop_index( 'delivery_details', 'retention' );

		$error = Migrator::verify_schema();
		$this->assertWPError( $error );
		$this->assertStringContainsString( 'retention', (string) $error->get_error_data()['detail'] );
		$this->assertFalse( Migrator::is_operational( true ) );
	}

	/**
	 * A schema at an OLDER version is not operational, even though every table
	 * and index is present. Once a version 2 exists, runtime code must not
	 * touch v2 columns before an administrator triggers the upgrade.
	 *
	 * @return void
	 */
	public function test_older_version_is_not_operational() {
		$this->needs_restore = true;
		update_option( Migrator::OPTION_DB_VERSION, Migrator::TARGET_DB_VERSION - 1, false );
		Migrator::flush_schema_cache();

		$error = Migrator::verify_schema();
		$this->assertWPError( $error );
		$this->assertStringContainsString( 'version', (string) $error->get_error_data()['detail'] );
		$this->assertFalse( Migrator::is_operational( true ), 'An out-of-date schema version reported itself operational.' );

		// The structural check alone still passes — it is only the version arm
		// that fails, which is exactly what lets the migration verify structure
		// before stamping.
		$this->assertTrue( true === Migrator::verify_schema( false ) );
	}

	/**
	 * A successful migration verifies AND stamps the version.
	 *
	 * @return void
	 */
	public function test_successful_migration_verifies_and_stamps_version() {
		$this->needs_restore = true;
		delete_option( Migrator::OPTION_DB_VERSION );
		Migrator::flush_schema_cache();

		$this->assertTrue( true === Migrator::migrate() );
		$this->assertSame( Migrator::TARGET_DB_VERSION, (int) get_option( Migrator::OPTION_DB_VERSION, 0 ) );
		$this->assertTrue( Migrator::is_operational( true ) );
	}

	/**
	 * The memoized result is invalidated by a schema change: a stale `true`
	 * must not survive a table drop within the same request.
	 *
	 * @return void
	 */
	public function test_memo_is_invalidated_after_a_drop() {
		global $wpdb;

		// Warm the memo with a healthy schema.
		$this->assertTrue( Migrator::is_operational( true ) );

		$this->assert_destructive_tests_allowed();
		$this->needs_restore = true;
		$table               = Migrator::table( 'delivery_details' );
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

		// Without invalidation the static would still answer true here.
		Migrator::flush_schema_cache();

		$this->assertFalse( Migrator::is_operational(), 'The memoized schema result survived a table drop.' );

		$error = Migrator::verify_schema();
		$this->assertWPError( $error );
		$this->assertStringContainsString( 'missing table', (string) $error->get_error_data()['detail'] );
	}

	/**
	 * migrate() clears the memo itself, so a caller that never calls
	 * flush_schema_cache() still sees the truth afterwards.
	 *
	 * @return void
	 */
	public function test_migrate_refreshes_the_memo_itself() {
		global $wpdb;

		$this->assertTrue( Migrator::is_operational( true ) );

		$this->assert_destructive_tests_allowed();
		$this->needs_restore = true;
		$table               = Migrator::table( 'rules' );
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

		// No explicit flush: migrate() must not trust its own stale memo.
		$this->assertTrue( true === Migrator::migrate() );
		$this->assertTrue( Migrator::is_operational() );
	}

	/**
	 * Helper: do all three tables exist right now?
	 *
	 * @return bool
	 */
	private function tables_all_exist(): bool {
		global $wpdb;
		foreach ( Migrator::tables() as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Assert a value is a WP_Error.
	 *
	 * @param mixed $value Value under test.
	 * @return void
	 */
	private function assertWPError( $value ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the PHPUnit/WordPress assertion naming convention.
		$this->assertInstanceOf( \WP_Error::class, $value );
	}
}
