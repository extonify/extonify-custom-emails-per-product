<?php
/**
 * Base class for the live-runtime integration suite.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Install\Migrator;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Integration tests run against the REAL local database.
 *
 * Every test registers what it creates and tears it down afterwards, then
 * VERIFIES the teardown actually removed it — the same verify-after-delete
 * discipline the POC harness uses, so a leaking test fails loudly instead of
 * polluting the next one.
 */
abstract class IntegrationTestCase extends TestCase {

	/**
	 * Tombstone ids created by this test.
	 *
	 * @var int[]
	 */
	protected $delivery_ids = array();

	/**
	 * Detail-row ids created by this test.
	 *
	 * @var int[]
	 */
	protected $detail_ids = array();

	/**
	 * Rule ids created by this test.
	 *
	 * @var int[]
	 */
	protected $rule_ids = array();

	/**
	 * Order ids created by this test.
	 *
	 * @var int[]
	 */
	protected $order_ids = array();

	/**
	 * Product ids created by this test.
	 *
	 * @var int[]
	 */
	protected $product_ids = array();

	/**
	 * Assert the suite-level destructive guard passed.
	 *
	 * Every test that issues DDL calls this immediately before its first
	 * destructive statement. The guard already ran in tests/bootstrap.php; this
	 * is the belt-and-braces check that a future refactor of the bootstrap or
	 * of this base class cannot silently re-expose a live database to
	 * DROP TABLE.
	 *
	 * @return void
	 */
	protected function assert_destructive_tests_allowed() {
		$this->assertTrue(
			defined( 'EXTONIFY_WCEP_DESTRUCTIVE_TESTS_ALLOWED' ) && EXTONIFY_WCEP_DESTRUCTIVE_TESTS_ALLOWED,
			'Refusing to run destructive DDL: the suite-level guard in tests/bootstrap.php did not pass.'
		);
	}

	/**
	 * Attribute any intercepted mail to this test class (gate 5).
	 *
	 * @before
	 * @return void
	 */
	protected function attribute_intercepted_mail() {
		MailGuard::attribute_to( static::class );
	}

	/**
	 * Confirm the schema is present before any test runs.
	 *
	 * @before
	 * @return void
	 */
	protected function require_schema() {
		// Self-healing, and the repair has to be as strong as the breaks.
		//
		// SchemaVerificationTest deliberately corrupts the live schema, and
		// migrate() alone CANNOT undo most of those: dbDelta() adds missing
		// objects but never drops a wrong-column index, never converts a
		// non-UNIQUE index to UNIQUE, and never changes a storage engine. A
		// migrate()-only repair therefore left one unrepairable break in place
		// and every subsequent test failed with a misleading "schema is
		// missing" instead of its own reason.
		//
		// Dropping and recreating is safe here: every test creates its own
		// fixtures and tears them down, so there is nothing in these tables
		// worth preserving between tests.
		if ( ! Migrator::is_operational( true ) ) {
			self::force_rebuild_schema();
		}

		$this->assertTrue(
			Migrator::is_operational( true ),
			'The plugin schema could not be established — activate the plugin before running the integration suite.'
		);
	}

	/**
	 * Drop and recreate the plugin schema unconditionally.
	 *
	 * @return void
	 */
	public static function force_rebuild_schema(): void {
		global $wpdb;

		if ( ! defined( 'EXTONIFY_WCEP_DESTRUCTIVE_TESTS_ALLOWED' ) || ! EXTONIFY_WCEP_DESTRUCTIVE_TESTS_ALLOWED ) {
			throw new \RuntimeException( 'Refusing to rebuild the plugin schema: the destructive-test guard did not pass.' );
		}

		foreach ( Migrator::tables() as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin-derived identifier, not user input.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		delete_option( Migrator::OPTION_DB_VERSION );
		delete_option( Migrator::OPTION_DB_ERROR );
		delete_option( Migrator::OPTION_LOCK );
		Migrator::flush_schema_cache();
		Migrator::migrate();
	}

	/**
	 * Remove every fixture, then verify it is really gone.
	 *
	 * @after
	 * @return void
	 */
	protected function tear_down_fixtures() {
		global $wpdb;

		$deliveries = Migrator::table( 'deliveries' );
		$details    = Migrator::table( 'delivery_details' );
		$rules      = Migrator::table( 'rules' );

		foreach ( array_unique( $this->detail_ids ) as $id ) {
			$wpdb->delete( $details, array( 'id' => $id ), array( '%d' ) );
		}
		foreach ( array_unique( $this->delivery_ids ) as $id ) {
			$wpdb->delete( $details, array( 'delivery_id' => $id ), array( '%d' ) );
			$wpdb->delete( $deliveries, array( 'id' => $id ), array( '%d' ) );
		}
		foreach ( array_unique( $this->rule_ids ) as $id ) {
			$wpdb->delete( $rules, array( 'id' => $id ), array( '%d' ) );
		}
		foreach ( array_unique( $this->order_ids ) as $id ) {
			$order = wc_get_order( $id );
			if ( $order ) {
				$order->delete( true );
			}
		}
		foreach ( array_unique( $this->product_ids ) as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$product->delete( true );
			}
		}

		$leaks = array();
		foreach ( array_unique( $this->delivery_ids ) as $id ) {
			if ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$deliveries} WHERE id = %d", $id ) ) ) {
				$leaks[] = "delivery:$id";
			}
		}
		foreach ( array_unique( $this->rule_ids ) as $id ) {
			if ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$rules} WHERE id = %d", $id ) ) ) {
				$leaks[] = "rule:$id";
			}
		}
		foreach ( array_unique( $this->order_ids ) as $id ) {
			if ( wc_get_order( $id ) ) {
				$leaks[] = "order:$id";
			}
		}
		foreach ( array_unique( $this->product_ids ) as $id ) {
			if ( self::product_exists( $id ) ) {
				$leaks[] = "product:$id";
			}
		}

		$this->delivery_ids = array();
		$this->detail_ids   = array();
		$this->rule_ids     = array();
		$this->order_ids    = array();
		$this->product_ids  = array();

		$this->assertSame( array(), $leaks, 'Fixture teardown left rows behind: ' . implode( ', ', $leaks ) );
	}

	/**
	 * Whether a product id still resolves to a real, readable product.
	 *
	 * `wc_get_product()` returning an object is NOT evidence the product
	 * exists. Verified on WC 10.9.4: for a deleted VARIATION,
	 * `WC_Product_Variation_Data_Store_CPT::read()` returns silently instead of
	 * throwing the way the ordinary product store does, so the factory hands
	 * back a fully constructed but entirely hollow `WC_Product_Variation`.
	 * A plain truthiness check therefore reports every deleted variation as a
	 * teardown leak. The read flag is the CRUD-level signal that `read()`
	 * actually completed.
	 *
	 * @param int $product_id Product or variation id.
	 * @return bool
	 */
	protected static function product_exists( int $product_id ): bool {
		$product = wc_get_product( $product_id );

		return $product instanceof \WC_Product
			&& $product->get_id() > 0
			&& $product->get_object_read();
	}

	/**
	 * A unique trigger identity, so parallel or repeated runs never collide on
	 * the tombstone UNIQUE key.
	 *
	 * @param string $prefix Identity prefix.
	 * @return string
	 */
	protected function unique_identity( string $prefix = 'status' ): string {
		return $prefix . ':test-' . wp_generate_password( 12, false, false );
	}

	/**
	 * An order id that does not correspond to a real order. Sufficient for
	 * storage-layer tests, which never load the order.
	 *
	 * @return int
	 */
	protected function fake_order_id(): int {
		return wp_rand( 900000, 999999 );
	}

	/**
	 * Create a simple product fixture.
	 *
	 * @param string $name Product name.
	 * @return int
	 */
	protected function make_product( string $name = 'WCEP Test Product' ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '10.00' );
		$product->set_price( '10.00' );
		$product->set_status( 'publish' );
		$id = (int) $product->save();

		$this->product_ids[] = $id;
		return $id;
	}

	/**
	 * Create an order fixture via WooCommerce CRUD (never direct SQL).
	 *
	 * @param int $product_id Product to add.
	 * @return \WC_Order
	 */
	protected function make_order( int $product_id ): \WC_Order {
		$order = wc_create_order();
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->set_billing_email( 'wcep-test@example.test' );
		$order->calculate_totals();
		$order->save();

		$this->order_ids[] = (int) $order->get_id();
		return $order;
	}

	/**
	 * Track a tombstone id for teardown.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return int
	 */
	protected function track_delivery( int $delivery_id ): int {
		$this->delivery_ids[] = $delivery_id;
		return $delivery_id;
	}

	/**
	 * Track a detail-row id for teardown.
	 *
	 * @param int $detail_id Detail row id.
	 * @return int
	 */
	protected function track_detail( int $detail_id ): int {
		$this->detail_ids[] = $detail_id;
		return $detail_id;
	}

	/**
	 * Track a rule id for teardown.
	 *
	 * @param int $rule_id Rule id.
	 * @return int
	 */
	protected function track_rule( int $rule_id ): int {
		$this->rule_ids[] = $rule_id;
		return $rule_id;
	}

	/**
	 * Read one raw tombstone column.
	 *
	 * @param int    $delivery_id Tombstone id.
	 * @param string $column      Column name (must be a known column).
	 * @return string|null
	 */
	protected function delivery_column( int $delivery_id, string $column ) {
		global $wpdb;

		$allowed = array( 'identity_hash', 'order_id', 'rule_id', 'mode', 'trigger_identity', 'first_claimed_at', 'last_seen_at', 'final_status', 'suppressed_count', 'rule_revision_sent' );
		$this->assertContains( $column, $allowed, 'Unknown tombstone column requested by a test.' );

		$table = Migrator::table( 'deliveries' );
		return $wpdb->get_var( $wpdb->prepare( "SELECT `{$column}` FROM {$table} WHERE id = %d", $delivery_id ) );
	}
}
