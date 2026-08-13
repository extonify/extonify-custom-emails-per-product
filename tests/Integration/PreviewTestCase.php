<?php
/**
 * Fixtures shared by the preview and test-send integration tests (ADR-0020).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\ConfirmationToken;
use Extonify\WCEP\Admin\DeliveryActions;
use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Delivery\DeliveryLogger;
use Extonify\WCEP\Delivery\InsertPhase;
use Extonify\WCEP\Matching\ItemResolver;
use Extonify\WCEP\Render\RenderContext;
use Extonify\WCEP\Render\RenderEvents;
use Extonify\WCEP\Render\RenderLedger;

defined( 'ABSPATH' ) || exit;

/**
 * A preview is a render, a test send is an admin click that mails somebody, and this
 * class has to serve both.
 *
 * ⚠ IT NEEDS THREE HARNESSES AT ONCE, WHICH IS WHY IT SITS WHERE IT DOES. The delivery
 * half (mail capture, the live email object) comes from `DeliveryTestCase`; the admin
 * half (users, nonces, `wp_die()` that throws, the request method) comes from
 * `AdminHarness`; and the render half (a fresh `RenderContext`, `RenderLedger` and
 * `InsertPhase` per test) is set up here, exactly as `InsertModeTestCase` does — because
 * insert-mode preview drives the real render singletons and a leaked frame would make
 * the NEXT test's assertions meaningless.
 *
 * ⚠ THE RENDER SINGLETONS ARE REPLACED PER TEST, NOT SHARED. `RenderEvents` holds one
 * context, one ledger and one phase for a whole request, which is correct in production
 * and would carry preview residue across tests here, since the suite is one PHP process.
 */
abstract class PreviewTestCase extends DeliveryTestCase {

	use AdminHarness;

	/**
	 * Filters registered by a test, removed on teardown.
	 *
	 * @var array[]
	 */
	protected $hooks = array();

	/**
	 * Give every test its own render context, ledger and phase.
	 *
	 * @before
	 * @return void
	 */
	protected function set_up_preview_singletons() {
		RenderEvents::set_collaborators(
			new RenderContext(),
			new RenderLedger(),
			new InsertPhase( $this->rules, new ItemResolver(), new DeliveryLogger( $this->deliveries, $this->details ) )
		);
	}

	/**
	 * Drop this test's render state and hooks.
	 *
	 * @after
	 * @return void
	 */
	protected function tear_down_preview_singletons() {
		foreach ( $this->hooks as $hook ) {
			list( $tag, $callback, $priority ) = $hook;
			remove_filter( $tag, $callback, $priority );
		}

		$this->hooks = array();

		RenderEvents::set_collaborators( null, null, null );
	}

	/**
	 * Register a filter and remember it for teardown.
	 *
	 * @param string   $tag      Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted arguments.
	 * @return void
	 */
	protected function hook( string $tag, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $tag, $callback, $priority, $args );
		$this->hooks[] = array( $tag, $callback, $priority );
	}

	// -----------------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------------

	/**
	 * A separate-mode rule, its product and an order containing it.
	 *
	 * @param array $rule_overrides Rule fields to replace.
	 * @return array{rule:int, order:\WC_Order, order_id:int, product:int}
	 */
	protected function previewable( array $rule_overrides = array() ): array {
		$product_id = $this->make_product( 'WCEP Preview Product' );
		$rule_id    = $this->make_sending_rule( $product_id, $rule_overrides );
		$order      = $this->make_order( $product_id );

		$this->captured_mail = array();

		return array(
			'rule'     => $rule_id,
			'order'    => $order,
			'order_id' => (int) $order->get_id(),
			'product'  => $product_id,
		);
	}

	/**
	 * An INSERT-mode rule, its product and an order containing it.
	 *
	 * @param array $rule_overrides Rule fields to replace.
	 * @return array{rule:int, order:\WC_Order, order_id:int, product:int}
	 */
	protected function previewable_insert( array $rule_overrides = array() ): array {
		$product_id = $this->make_product( 'WCEP Preview Insert Product' );

		$rule_id = $this->make_rule(
			array_merge(
				array(
					'name'            => 'WCEP preview insert fixture',
					'delivery_mode'   => 'insert',
					'native_email_id' => 'customer_processing_order',
					'insert_position' => 'after_order_table',
					'targeting'       => array( 'include' => array( 'products' => array( $product_id ) ) ),
					'content'         => '<p>INSERTED PREVIEW BLOCK.</p>',
				),
				$rule_overrides
			)
		);

		$order = $this->make_order( $product_id );

		$this->captured_mail = array();

		return array(
			'rule'     => $rule_id,
			'order'    => $order,
			'order_id' => (int) $order->get_id(),
			'product'  => $product_id,
		);
	}

	// -----------------------------------------------------------------------
	// Inertness instrumentation (gate 40)
	// -----------------------------------------------------------------------

	/**
	 * A CONTENT checksum of both delivery tables, plus their row counts.
	 *
	 * ⚠ CONTENT, NOT JUST COUNTS, for the reason `DeliveryHistoryTestCase` gives: a
	 * write that updated a column in place would leave the count untouched and the table
	 * changed, and that is exactly the write most likely to be added by accident.
	 *
	 * @return array{deliveries:string, details:string, delivery_rows:int, detail_rows:int}
	 */
	protected function delivery_checksums(): array {
		global $wpdb;

		$deliveries = \Extonify\WCEP\Install\Migrator::table( 'deliveries' );
		$details    = \Extonify\WCEP\Install\Migrator::table( 'delivery_details' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test-only full read of the plugin-owned tables to checksum them.
		$delivery_rows = (array) $wpdb->get_results( "SELECT * FROM {$deliveries} ORDER BY id ASC", ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test-only full read of the plugin-owned tables to checksum them.
		$detail_rows = (array) $wpdb->get_results( "SELECT * FROM {$details} ORDER BY id ASC", ARRAY_A );

		return array(
			'deliveries'    => md5( (string) wp_json_encode( $delivery_rows ) ),
			'details'       => md5( (string) wp_json_encode( $detail_rows ) ),
			'delivery_rows' => count( $delivery_rows ),
			'detail_rows'   => count( $detail_rows ),
		);
	}

	/**
	 * Every statement issued while a callable runs.
	 *
	 * @param callable $body What to measure.
	 * @return string[]
	 */
	protected function record_statements( callable $body ): array {
		$observed = array();

		$capture = static function ( $query ) use ( &$observed ) {
			$observed[] = (string) $query;

			return $query;
		};

		add_filter( 'query', $capture, 1 );

		try {
			$body();
		} finally {
			remove_filter( 'query', $capture, 1 );
		}

		return $observed;
	}

	/**
	 * The subset of recorded statements that WRITE to this plugin's delivery tables.
	 *
	 * @param string[] $statements Recorded statements.
	 * @return string[]
	 */
	protected function delivery_writes( array $statements ): array {
		$tables = array(
			\Extonify\WCEP\Install\Migrator::table( 'deliveries' ),
			\Extonify\WCEP\Install\Migrator::table( 'delivery_details' ),
		);

		$writes = array();

		foreach ( $statements as $statement ) {
			if ( 1 !== preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP)\b/i', $statement ) ) {
				continue;
			}

			foreach ( $tables as $table ) {
				if ( false !== strpos( $statement, $table ) ) {
					$writes[] = $statement;
					break;
				}
			}
		}

		return $writes;
	}

	/**
	 * How many Action Scheduler jobs this plugin currently has queued.
	 *
	 * @return int
	 */
	protected function scheduled_job_count(): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		return count(
			(array) as_get_scheduled_actions(
				array(
					'hook'     => \Extonify\WCEP\Delivery\ScheduledDelivery::HOOK,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 100,
				),
				'ids'
			)
		);
	}

	/**
	 * Assert a preview left absolutely no trace (gate 40).
	 *
	 * @param array    $before Checksums taken before.
	 * @param string[] $writes Delivery-table writes recorded during.
	 * @param int      $jobs   Scheduled jobs before.
	 * @param string   $what   What was previewed, for the failure message.
	 * @return void
	 */
	protected function assertPreviewWroteNothing( array $before, array $writes, int $jobs, string $what ): void {
		$after = $this->delivery_checksums();

		$this->assertSame(
			$before,
			$after,
			'⚠ TIER 1: previewing ' . $what . ' changed the delivery tables. Rows before/after: '
			. $before['delivery_rows'] . '/' . $after['delivery_rows'] . ' deliveries, '
			. $before['detail_rows'] . '/' . $after['detail_rows'] . ' details.'
		);

		$this->assertSame(
			array(),
			$writes,
			'⚠ TIER 1: previewing ' . $what . ' issued a write to a delivery table: ' . implode( ' | ', $writes )
		);

		$this->assertSame(
			$jobs,
			$this->scheduled_job_count(),
			'⚠ TIER 1: previewing ' . $what . ' scheduled an action.'
		);

		$this->assertMailCount( 0, '⚠ TIER 1: previewing ' . $what . ' sent mail.' );
	}

	/**
	 * Assert the render ledger and context hold nothing at all (gate 40).
	 *
	 * @param string $what What was previewed, for the failure message.
	 * @return void
	 */
	protected function assertNoLedgerResidue( string $what ): void {
		$ledger  = RenderEvents::ledger();
		$context = RenderEvents::context();

		$this->assertSame( array(), $ledger->slots(), '⚠ previewing ' . $what . ' left a ledger slot.' );
		$this->assertSame( array(), $ledger->reservations(), '⚠ previewing ' . $what . ' left a reservation.' );
		$this->assertSame( array(), $ledger->candidates(), '⚠ previewing ' . $what . ' left a send candidate.' );
		$this->assertSame( array(), $context->renders(), '⚠ previewing ' . $what . ' left a render record.' );
		$this->assertSame( 0, $context->depth(), '⚠ previewing ' . $what . ' left a render frame open.' );

		$this->assertSame(
			array(
				'open_details' => 0,
				'open_footer'  => 0,
			),
			$context->open_token_counts(),
			'⚠ previewing ' . $what . ' left an open token behind.'
		);
	}

	// -----------------------------------------------------------------------
	// Test-send submission (the ADR-0019 §5 gate, unchanged)
	// -----------------------------------------------------------------------

	/**
	 * The `$_POST` the test confirmation form submits.
	 *
	 * @param int    $order_id Order id.
	 * @param int    $rule_id  Rule id.
	 * @param string $address  Address to carry.
	 * @param string $token    Token to carry; '' issues a fresh one.
	 * @param string $nonce    Nonce to carry; '' mints the correct one.
	 * @return array
	 */
	protected function test_post( int $order_id, int $rule_id, string $address, string $token = '', string $nonce = '' ): array {
		return array(
			'action'                        => DeliveryActions::ACTION_TEST,
			DeliveryActions::FIELD_DELIVERY => 0,
			DeliveryActions::FIELD_ORDER    => $order_id,
			DeliveryActions::FIELD_RULE     => $rule_id,
			DeliveryActions::FIELD_ADDRESS  => $address,
			DeliveryActions::FIELD_TOKEN    => '' !== $token ? $token : ConfirmationToken::issue(),
			DeliveryActions::FIELD_NONCE    => '' !== $nonce
				? $nonce
				: wp_create_nonce( DeliveryActions::nonce_action( DeliveryActions::ACTION_TEST, $order_id, $rule_id ) ),
		);
	}

	/**
	 * Submit one confirmed test send exactly as a browser would.
	 *
	 * @param array $post The `$_POST` to submit.
	 * @return array The handler's outcome.
	 */
	protected function submit_test( array $post ): array {
		$this->use_method( 'POST' );
		$this->request( array( 'page' => Menu::HISTORY_PAGE ), $post );

		return DeliveryActions::handle( DeliveryActions::ACTION_TEST, $post );
	}

	/**
	 * The refusal code an outcome reports, or '' on success.
	 *
	 * @param array $outcome Handler outcome.
	 * @return string
	 */
	protected function refusal_of( array $outcome ): string {
		return (string) ( $outcome['refusal'] ?? '' );
	}

	/**
	 * Assert an outcome refused with one reason and mailed nobody.
	 *
	 * @param array  $outcome  Handler outcome.
	 * @param string $expected Expected refusal code.
	 * @return void
	 */
	protected function assertRefusedWith( array $outcome, string $expected ): void {
		$this->assertSame(
			$expected,
			$this->refusal_of( $outcome ),
			'⚠ the action refused for the wrong reason, so the merchant would be told the wrong thing.'
		);

		$this->assertMailCount( 0, '⚠ TIER 1: a refused test send sent mail.' );
	}

	/**
	 * Every recipient a captured message reached, across `to`, `Cc` and `Bcc`.
	 *
	 * ⚠ ALL THREE CHANNELS, WHICH IS THE POINT (gate 42). Asserting only `to` would miss
	 * a customer address that arrived as a `Bcc` — the exact shape of a leak nobody sees
	 * until a customer replies to a test.
	 *
	 * @param array $mail Captured message.
	 * @return string[] Lower-cased addresses.
	 */
	protected function every_recipient_of( array $mail ): array {
		$found = array();

		foreach ( (array) ( is_array( $mail['to'] ?? '' ) ? $mail['to'] : array( (string) ( $mail['to'] ?? '' ) ) ) as $to ) {
			$found[] = (string) $to;
		}

		foreach ( preg_split( '/\r\n|\r|\n/', $this->headers_of( $mail ) ) as $line ) {
			if ( 1 === preg_match( '/^\s*(cc|bcc)\s*:\s*(.+)$/i', (string) $line, $matches ) ) {
				foreach ( explode( ',', $matches[2] ) as $address ) {
					$found[] = trim( $address );
				}
			}
		}

		$out = array();

		foreach ( $found as $address ) {
			$address = strtolower( trim( $address ) );

			if ( '' !== $address ) {
				$out[] = $address;
			}
		}

		return array_values( array_unique( $out ) );
	}
}
