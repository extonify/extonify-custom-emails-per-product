<?php
/**
 * Fixtures and instrumentation for the delivery-history surfaces (ADR-0018).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\DeliveryHistory;
use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Repository\DeliveryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Tombstones and attempt rows written STRAIGHT INTO THE TABLES, plus a statement
 * counter.
 *
 * ⚠ EVERY FIXTURE HERE BYPASSES THE REPOSITORY, AND THAT IS THE WHOLE POINT
 * (gate 31, ADR-0018 Context). `DeliveryDetailRepository::insert()` runs `recipient`
 * through `Recipient::normalize()`, `subject` through `sanitize_text_field()` and
 * `reason`/`failure_message` through `Text::log_value()` — so a hostile fixture
 * written THROUGH it arrives at the screen already defanged, leaving nothing for
 * `esc_html()` to escape and letting a column that forgot to escape entirely pass
 * the test. That is precisely how `TargetLabels::describe()` shipped unescaped in
 * Prompt 9A. Storage sanitisation and output escaping are two barriers and each has
 * to be proven on its own.
 *
 * It is also the state a database import, a direct SQL edit or a future write path
 * that skips the repository would leave behind — so this is not a contrived shape.
 */
abstract class DeliveryHistoryTestCase extends AdminTestCase {

	/**
	 * The hostile payload, identical in construction to `AdminOutputTest::HOSTILE`.
	 *
	 * Five attacks in one string: a script element, a double-quoted attribute
	 * breakout, a single-quoted attribute breakout, a bare ampersand, and a
	 * percent-encoded `<script>` that must never be decoded into one.
	 *
	 * ⚠ THE MARKER IS `wcepXSS`, NOT "A SCRIPT TAG". WordPress's own admin markup
	 * legitimately contains `<script>`, so asserting on that would fail on core's
	 * output and prove nothing about ours.
	 */
	const HOSTILE = '<script>wcepXSS()</script>" onmouseover="wcepXSS()\' onfocus=\'wcepXSS() & %3Cscript%3EwcepXSS()%3C/script%3E';

	/**
	 * Statements observed while the counter is armed.
	 *
	 * @var string[]
	 */
	private $observed = array();

	/**
	 * Whether the counter is currently recording.
	 *
	 * @var bool
	 */
	private $recording = false;

	/**
	 * Drop any table a previous test in this process prepared.
	 *
	 * @before
	 * @return void
	 */
	protected function reset_history_screen() {
		DeliveryHistory::reset();
	}

	/**
	 * Take the statement filter down, whatever the test did.
	 *
	 * @after
	 * @return void
	 */
	protected function tear_down_statement_counter() {
		// ⚠ THE FILTER IS REMOVED BY `record_statements()`' OWN `finally`, NOT BY
		// `remove_all_filters( 'query' )` HERE. `query` is a core filter WordPress and
		// WooCommerce both hook for their own reasons, and tearing every callback off it
		// would break the rest of the process to clean up after one test.
		$this->recording = false;
		$this->observed  = array();
	}

	// -----------------------------------------------------------------------
	// Fixtures
	// -----------------------------------------------------------------------

	/**
	 * Write one tombstone DIRECTLY, bypassing `DeliveryRepository::claim()`.
	 *
	 * @param array $overrides Column overrides.
	 * @return int Tombstone id, tracked for teardown.
	 */
	protected function raw_delivery( array $overrides = array() ): int {
		global $wpdb;

		$row = array_merge(
			array(
				'identity_hash'      => str_pad( wp_generate_password( 24, false, false ), 64, '0' ),
				'order_id'           => $this->fake_order_id(),
				'rule_id'            => 0,
				'mode'               => 'separate',
				'trigger_identity'   => 'status:completed',
				'first_claimed_at'   => gmdate( 'Y-m-d H:i:s' ),
				'last_seen_at'       => gmdate( 'Y-m-d H:i:s' ),
				'final_status'       => 'sent',
				'suppressed_count'   => 0,
				'rule_revision_sent' => 1,
			),
			$overrides
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only raw write to the plugin-owned table, deliberately past the repository; see the class docblock.
		$ok = $wpdb->insert( Migrator::table( 'deliveries' ), $row );

		$this->assertNotFalse( $ok, 'Could not write the raw tombstone fixture: ' . $wpdb->last_error );

		return $this->track_delivery( (int) $wpdb->insert_id );
	}

	/**
	 * Write one attempt row DIRECTLY, bypassing `DeliveryDetailRepository::insert()`.
	 *
	 * ⚠ THE COLUMN LIST IS EXPLICIT AND INCLUDES THE NULLABLE ONES, so a test can
	 * write `null` into `recipient_type` or `reason` — the two markers the privacy
	 * eraser leaves and the two the write path never leaves. `$wpdb->insert()` drops a
	 * null only when the caller omits the key, not when it passes one.
	 *
	 * @param int   $delivery_id Owning tombstone id.
	 * @param array $overrides   Column overrides.
	 * @return int Detail row id, tracked for teardown.
	 */
	protected function raw_detail( int $delivery_id, array $overrides = array() ): int {
		global $wpdb;

		$row = array_merge(
			array(
				'delivery_id'       => $delivery_id,
				'parent_attempt_id' => null,
				'attempt'           => 1,
				'type'              => 'auto',
				'state'             => 'sent',
				'reason'            => '',
				'recipient'         => 'customer@example.test',
				'recipient_type'    => 'to',
				'recipient_header'  => null,
				'subject'           => 'Your order',
				'snapshot'          => null,
				'failure_message'   => null,
				'is_debug'          => 0,
				'created_at'        => gmdate( 'Y-m-d H:i:s' ),
			),
			$overrides
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only raw write to the plugin-owned table, deliberately past the repository; see the class docblock.
		$ok = $wpdb->insert( Migrator::table( 'delivery_details' ), $row );

		$this->assertNotFalse( $ok, 'Could not write the raw detail fixture: ' . $wpdb->last_error );

		return $this->track_detail( (int) $wpdb->insert_id );
	}

	/**
	 * A tombstone whose every free-text and personal column is hostile.
	 *
	 * @param array $delivery_overrides Tombstone column overrides.
	 * @return int Tombstone id.
	 */
	protected function hostile_delivery( array $delivery_overrides = array() ): int {
		$delivery_id = $this->raw_delivery( $delivery_overrides );

		$this->raw_detail(
			$delivery_id,
			array(
				// A header-form recipient carrying the payload in its display name,
				// which is the shape a customer-supplied billing name actually reaches
				// the mailer in.
				'recipient'        => self::HOSTILE,
				'recipient_header' => self::HOSTILE,
				'subject'          => self::HOSTILE,
				'reason'           => self::HOSTILE,
				'failure_message'  => self::HOSTILE,
				'state'            => 'failed',
			)
		);

		return $delivery_id;
	}

	/**
	 * Assert one hostile value really is in the column, so the escaping test cannot
	 * pass because the fixture never landed.
	 *
	 * @param int    $detail_id Detail row id.
	 * @param string $column    Column name.
	 * @return void
	 */
	protected function assertRawColumnIsHostile( int $detail_id, string $column ): void {
		global $wpdb;

		$allowed = array( 'recipient', 'recipient_header', 'subject', 'reason', 'failure_message' );

		$this->assertContains( $column, $allowed, 'Unknown detail column requested by a test.' );

		$table = Migrator::table( 'delivery_details' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test-only primary-key read of the plugin-owned table; {$column} is checked against the allowlist directly above.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT `{$column}` FROM {$table} WHERE id = %d", $detail_id ) );

		$this->assertSame(
			self::HOSTILE,
			(string) $value,
			'⚠ the raw hostile value did not land in ' . $column . ', so this test would prove nothing.'
		);
	}

	// -----------------------------------------------------------------------
	// Statement instrumentation
	// -----------------------------------------------------------------------

	/**
	 * Record every statement issued while a callable runs.
	 *
	 * ⚠ THROUGH THE `query` FILTER, NOT `$wpdb->num_queries`. The filter sees the SQL
	 * TEXT, which is what lets gate 34 assert that no `INSERT`, `UPDATE` or `DELETE`
	 * touched either delivery table and gate 35 count only the statements that reach
	 * this plugin's own tables — a raw counter can do neither, and would also drift
	 * with whatever WordPress happened to cache on the previous request.
	 *
	 * @param callable $body What to measure.
	 * @return string[] Every statement issued, in order.
	 */
	protected function record_statements( callable $body ): array {
		$this->observed  = array();
		$this->recording = true;

		$capture = function ( $query ) {
			if ( $this->recording ) {
				$this->observed[] = (string) $query;
			}

			return $query;
		};

		add_filter( 'query', $capture, 1 );

		try {
			$body();
		} finally {
			$this->recording = false;

			remove_filter( 'query', $capture, 1 );
		}

		return $this->observed;
	}

	/**
	 * The subset of recorded statements that touch this plugin's delivery tables.
	 *
	 * @param string[] $statements Recorded statements.
	 * @return string[]
	 */
	protected function delivery_statements( array $statements ): array {
		$tables = array( Migrator::table( 'deliveries' ), Migrator::table( 'delivery_details' ) );
		$out    = array();

		foreach ( $statements as $statement ) {
			foreach ( $tables as $table ) {
				if ( false !== strpos( $statement, $table ) ) {
					$out[] = $statement;
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * The subset of recorded statements that touch ANY table this plugin owns.
	 *
	 * Wider than self::delivery_statements() by exactly the rules table, which the
	 * history render reads twice — once to name the rules on the page, once to fill
	 * the rule filter — and which is the other half of the ADR-0018 §7 formula.
	 *
	 * @param string[] $statements Recorded statements.
	 * @return string[]
	 */
	protected function plugin_statements( array $statements ): array {
		$out = array();

		foreach ( $statements as $statement ) {
			foreach ( Migrator::tables() as $table ) {
				if ( false !== strpos( $statement, $table ) ) {
					$out[] = $statement;
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * The subset that WRITES to this plugin's delivery tables.
	 *
	 * @param string[] $statements Recorded statements.
	 * @return string[]
	 */
	protected function delivery_writes( array $statements ): array {
		$writes = array();

		foreach ( $this->delivery_statements( $statements ) as $statement ) {
			if ( 1 === preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP)\b/i', $statement ) ) {
				$writes[] = $statement;
			}
		}

		return $writes;
	}

	// -----------------------------------------------------------------------
	// Read-only checksums
	// -----------------------------------------------------------------------

	/**
	 * A content checksum of both delivery tables.
	 *
	 * ⚠ CONTENT, NOT JUST A ROW COUNT (gate 34). A render that updated a column in
	 * place — a "last viewed" stamp, a lazily repaired status — would leave the count
	 * untouched and the table changed, which is the write most likely to be added by
	 * accident and the least likely to be noticed.
	 *
	 * @return array{deliveries:string, details:string, delivery_rows:int, detail_rows:int}
	 */
	protected function delivery_checksums(): array {
		global $wpdb;

		$deliveries = Migrator::table( 'deliveries' );
		$details    = Migrator::table( 'delivery_details' );

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
	 * The history screen's markup for one request.
	 *
	 * @param array $get The request.
	 * @return string
	 */
	protected function render_history( array $get = array() ): string {
		$this->use_history_screen();
		$this->request( array_merge( array( 'page' => Menu::HISTORY_PAGE ), $get ) );

		DeliveryHistory::reset();

		return $this->capture(
			static function () {
				DeliveryHistory::prepare();
				DeliveryHistory::render();
			}
		);
	}

	/**
	 * Pretend WordPress is rendering the HISTORY screen.
	 *
	 * `WP_List_Table` reads the current screen in its constructor and registers column
	 * filters against its id, so a table built under the rules screen's id would
	 * register them in the wrong place.
	 *
	 * @return string The screen id.
	 */
	protected function use_history_screen(): string {
		$screen_id = 'woocommerce_page_' . Menu::HISTORY_PAGE;

		set_current_screen( $screen_id );

		return $screen_id;
	}

	/**
	 * How many deliveries the history screen shows per page.
	 *
	 * @return int
	 */
	protected function per_page(): int {
		return DeliveryRepository::HISTORY_PER_PAGE;
	}
}
