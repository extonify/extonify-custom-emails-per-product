<?php
/**
 * GATE 35 — the history listing filters, orders and pages IN SQL (ADR-0018 §5, §6).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\DeliveryPresenter;
use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Plugin;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * The repository half of the delivery history, asserted without a screen.
 *
 * ⚠ EACH FILTER IS ASSERTED TO RETURN **EXACTLY** THE EXPECTED SET, not merely to
 * include the row it was given. A filter that quietly matched everything would pass
 * an "is my row in there" test on every single case, and that is the failure mode
 * worth guarding: the screen would look like it worked and would be showing a
 * merchant another order's deliveries.
 */
final class DeliveryHistoryQueryTest extends DeliveryHistoryTestCase {

	/**
	 * Gate lines.
	 *
	 * @var string[]
	 */
	private $gate = array();

	/**
	 * The tombstones this test built, keyed by a readable name.
	 *
	 * @var array<string,int>
	 */
	private $fixtures = array();

	/**
	 * Print the gate lines.
	 *
	 * @after
	 * @return void
	 */
	protected function report_gate() {
		foreach ( $this->gate as $line ) {
			fwrite( STDERR, "\n[P10 gate 35] " . $line );
		}

		$this->gate     = array();
		$this->fixtures = array();
	}

	/**
	 * A set of deliveries that differs in every filterable dimension.
	 *
	 * ⚠ THE ORDER AND RULE IDS ARE UNIQUE TO THIS TEST RUN, so an exact-set assertion
	 * is meaningful in a shared database that other tests are also writing to. Every
	 * assertion below intersects its result with THESE ids and then compares exactly —
	 * which is stricter than filtering the expectation, because a filter that leaked a
	 * sibling fixture's row still fails.
	 *
	 * @return void
	 */
	private function build_fixtures(): void {
		$order_a = $this->fake_order_id();
		$order_b = $order_a + 1;
		$rule_a  = 810001;
		$rule_b  = 810002;

		$this->fixtures = array(
			// order A, rule A, sent, separate, old
			'a_sent_separate_old' => $this->raw_delivery(
				array(
					'order_id'         => $order_a,
					'rule_id'          => $rule_a,
					'final_status'     => 'sent',
					'mode'             => 'separate',
					'first_claimed_at' => '2026-01-10 12:00:00',
					'last_seen_at'     => '2026-01-10 12:00:00',
				)
			),
			// order A, rule B, failed, insert, middle
			'a_failed_insert_mid' => $this->raw_delivery(
				array(
					'order_id'         => $order_a,
					'rule_id'          => $rule_b,
					'final_status'     => 'failed',
					'mode'             => 'insert',
					'first_claimed_at' => '2026-03-15 12:00:00',
					'last_seen_at'     => '2026-03-15 12:00:00',
				)
			),
			// order B, rule A, sent, insert, new
			'b_sent_insert_new'   => $this->raw_delivery(
				array(
					'order_id'         => $order_b,
					'rule_id'          => $rule_a,
					'final_status'     => 'sent',
					'mode'             => 'insert',
					'first_claimed_at' => '2026-06-20 12:00:00',
					'last_seen_at'     => '2026-06-20 12:00:00',
				)
			),
		);

		$this->fixtures['order_a'] = $order_a;
		$this->fixtures['order_b'] = $order_b;
		$this->fixtures['rule_a']  = $rule_a;
		$this->fixtures['rule_b']  = $rule_b;
	}

	/**
	 * The three tombstone ids, sorted.
	 *
	 * @return int[]
	 */
	private function all_ids(): array {
		$ids = array(
			$this->fixtures['a_sent_separate_old'],
			$this->fixtures['a_failed_insert_mid'],
			$this->fixtures['b_sent_insert_new'],
		);

		sort( $ids );

		return $ids;
	}

	/**
	 * Run one filter and return which of THIS test's fixtures came back, sorted.
	 *
	 * @param array $args Filter arguments.
	 * @return int[]
	 */
	private function matched( array $args ): array {
		$rows = DeliveryPresenter::deliveries()->query( array_merge( $args, array( 'limit' => 100 ) ) );

		$mine = array_intersect( array_map( static fn( $row ) => (int) $row['id'], $rows ), $this->all_ids() );

		sort( $mine );

		return array_values( $mine );
	}

	/**
	 * EACH FILTER RETURNS EXACTLY THE EXPECTED SET.
	 *
	 * @return void
	 */
	public function test_every_filter_returns_exactly_the_expected_set() {
		$this->build_fixtures();

		$cases = array(
			'order id'           => array(
				array( 'order_id' => $this->fixtures['order_a'] ),
				array( $this->fixtures['a_sent_separate_old'], $this->fixtures['a_failed_insert_mid'] ),
			),
			'rule id'            => array(
				array( 'rule_id' => $this->fixtures['rule_a'] ),
				array( $this->fixtures['a_sent_separate_old'], $this->fixtures['b_sent_insert_new'] ),
			),
			'final status'       => array(
				array( 'final_status' => 'failed' ),
				array( $this->fixtures['a_failed_insert_mid'] ),
			),
			'mode'               => array(
				array( 'mode' => 'separate' ),
				array( $this->fixtures['a_sent_separate_old'] ),
			),
			'date from'          => array(
				array( 'date_from' => '2026-03-01 00:00:00' ),
				array( $this->fixtures['a_failed_insert_mid'], $this->fixtures['b_sent_insert_new'] ),
			),
			'date to'            => array(
				array( 'date_to' => '2026-03-31 23:59:59' ),
				array( $this->fixtures['a_sent_separate_old'], $this->fixtures['a_failed_insert_mid'] ),
			),
			'date range'         => array(
				array(
					'date_from' => '2026-02-01 00:00:00',
					'date_to'   => '2026-04-01 00:00:00',
				),
				array( $this->fixtures['a_failed_insert_mid'] ),
			),
			'order + status'     => array(
				array(
					'order_id'     => $this->fixtures['order_a'],
					'final_status' => 'sent',
				),
				array( $this->fixtures['a_sent_separate_old'] ),
			),
			'rule + mode'        => array(
				array(
					'rule_id' => $this->fixtures['rule_a'],
					'mode'    => 'insert',
				),
				array( $this->fixtures['b_sent_insert_new'] ),
			),
			// ⚠ AN UNRECOGNISED VALUE MATCHES NOTHING; IT IS NOT REPAIRED AND THE FILTER
			// IS NOT SILENTLY DROPPED. A dropped filter widens the result to everything,
			// which on this screen means showing a merchant deliveries they did not ask
			// for and believing they asked for them.
			'unknown status'     => array(
				array( 'final_status' => 'not_a_status' ),
				array(),
			),
			'unknown mode'       => array(
				array( 'mode' => 'carrier-pigeon' ),
				array(),
			),
			'contradictory pair' => array(
				array(
					'order_id' => $this->fixtures['order_a'],
					'rule_id'  => 999999,
				),
				array(),
			),
		);

		foreach ( $cases as $label => $case ) {
			list( $args, $expected ) = $case;

			sort( $expected );

			$this->assertSame(
				array_values( $expected ),
				$this->matched( $args ),
				'⚠ the "' . $label . '" filter returned the wrong set.'
			);

			// The counter must agree with the page, or the pager lies about the last page.
			$counted = DeliveryPresenter::deliveries()->count_matching( $args );

			$this->assertGreaterThanOrEqual(
				count( $expected ),
				$counted,
				'⚠ count_matching() reported fewer rows than query() returned for "' . $label . '".'
			);
		}

		$this->gate[] = 'filters: ' . count( $cases ) . ' cases — order id, rule id, status, mode, date from, date to, '
			. 'date range, and four combinations including two unrecognised values and one contradictory pair — '
			. 'each returns EXACTLY the expected set';
	}

	/**
	 * THE COUNTER AND THE PAGE AGREE, on the same filters.
	 *
	 * @return void
	 */
	public function test_the_counter_and_the_page_agree() {
		$this->build_fixtures();

		$filters = array( 'order_id' => $this->fixtures['order_a'] );

		$this->assertSame(
			2,
			DeliveryPresenter::deliveries()->count_matching( $filters ),
			'⚠ the pager count disagrees with the two deliveries order A has.'
		);

		$this->assertCount(
			2,
			DeliveryPresenter::deliveries()->query( array_merge( $filters, array( 'limit' => 100 ) ) ),
			'⚠ the page disagrees with the pager count.'
		);

		$this->gate[] = 'count/page agreement: count_matching() and query() report the same 2 rows for one order';
	}

	/**
	 * ORDERING IS NEWEST FIRST, TIE-BROKEN ON THE PRIMARY KEY.
	 *
	 * @return void
	 */
	public function test_ordering_is_newest_first_with_a_deterministic_tie_break() {
		$this->build_fixtures();

		$rows = DeliveryPresenter::deliveries()->query(
			array(
				'order_id' => $this->fixtures['order_a'],
				'limit'    => 100,
			)
		);

		$this->assertSame(
			array( $this->fixtures['a_failed_insert_mid'], $this->fixtures['a_sent_separate_old'] ),
			array_map( static fn( $row ) => (int) $row['id'], $rows ),
			'⚠ the history is not ordered newest first.'
		);

		// The tie-break, on rows that share a timestamp to the second.
		$order_id = $this->fake_order_id();
		$stamp    = '2026-05-05 05:05:05';
		$tied     = array();

		for ( $i = 0; $i < 5; $i++ ) {
			$tied[] = $this->raw_delivery(
				array(
					'order_id'         => $order_id,
					'first_claimed_at' => $stamp,
					'last_seen_at'     => $stamp,
				)
			);
		}

		rsort( $tied );

		$read = DeliveryPresenter::deliveries()->query(
			array(
				'order_id' => $order_id,
				'limit'    => 100,
			)
		);

		$this->assertSame(
			$tied,
			array_map( static fn( $row ) => (int) $row['id'], $read ),
			'⚠ rows sharing a timestamp came back in an order the primary key does not define.'
		);

		$this->gate[] = 'ordering: first_claimed_at DESC then id DESC — 5 rows sharing one timestamp come back in '
			. 'strict descending id order, which is what makes paging repeatable';
	}

	/**
	 * PAGING HAPPENS IN SQL, NOT IN PHP.
	 *
	 * ⚠ ASSERTED ON THE STATEMENT TEXT, because a method that read the whole table and
	 * sliced it would return exactly the same rows. `LIMIT` and `OFFSET` have to be IN
	 * the statement — that is the claim, and the rows cannot demonstrate it.
	 *
	 * @return void
	 */
	public function test_filtering_ordering_and_paging_are_in_the_statement() {
		$this->build_fixtures();

		$statements = $this->record_statements(
			function () {
				DeliveryPresenter::deliveries()->query(
					array(
						'order_id'     => $this->fixtures['order_a'],
						'final_status' => 'sent',
						'mode'         => 'separate',
						'date_from'    => '2026-01-01 00:00:00',
						'date_to'      => '2026-12-31 23:59:59',
						'limit'        => 20,
						'offset'       => 40,
					)
				);
			}
		);

		$reads = $this->delivery_statements( $statements );

		$this->assertCount( 1, $reads, '⚠ one filtered page cost more than one statement.' );

		$sql = $reads[0];

		foreach ( array( 'order_id =', 'final_status =', 'mode =', 'first_claimed_at >=', 'first_claimed_at <=' ) as $predicate ) {
			$this->assertStringContainsString( $predicate, $sql, '⚠ the "' . $predicate . '" filter is not in the SQL.' );
		}

		$this->assertStringContainsString( 'ORDER BY first_claimed_at DESC, id DESC', $sql, '⚠ the ordering is not in the SQL.' );
		$this->assertStringContainsString( 'LIMIT 20', $sql, '⚠ the page size is not in the SQL.' );
		$this->assertStringContainsString( 'OFFSET 40', $sql, '⚠ the page offset is not in the SQL.' );

		$this->gate[] = 'in-SQL: one statement carries all five predicates, the ORDER BY and the LIMIT/OFFSET — '
			. 'nothing is loaded and sliced in PHP';
	}

	/**
	 * A PAGE OF DETAIL ROWS COSTS ONE STATEMENT, whatever the page holds.
	 *
	 * @return void
	 */
	public function test_the_detail_batch_is_one_statement_for_the_whole_page() {
		$ids = array();

		for ( $i = 0; $i < 15; $i++ ) {
			$delivery_id = $this->raw_delivery();

			$this->raw_detail( $delivery_id );
			$this->raw_detail( $delivery_id, array( 'attempt' => 2 ) );

			$ids[] = $delivery_id;
		}

		$fetched = array();

		$statements = $this->record_statements(
			function () use ( $ids, &$fetched ) {
				$fetched = Plugin::instance()->delivery_details()->find_for_deliveries( $ids );
			}
		);

		$this->assertCount(
			1,
			$this->delivery_statements( $statements ),
			'⚠ reading 15 deliveries\' details cost more than one statement.'
		);

		$this->assertCount( 15, $fetched, 'the batch did not return a page per requested id.' );

		foreach ( $ids as $id ) {
			$this->assertArrayHasKey( $id, $fetched );
			$this->assertCount( 2, $fetched[ $id ], 'delivery #' . $id . ' lost an attempt row.' );
		}

		// ⚠ AN ID WITH NO ROWS STILL GETS A KEY. Without this the screen cannot tell
		// "the details were purged" from "I never asked", which is the whole of
		// ADR-0018 §4a.
		$purged = $this->raw_delivery();
		$mixed  = Plugin::instance()->delivery_details()->find_for_deliveries( array( $purged, $ids[0] ) );

		$this->assertArrayHasKey( $purged, $mixed, '⚠ a tombstone with no details is missing from the batch entirely.' );
		$this->assertSame( array(), $mixed[ $purged ] );

		$this->gate[] = 'detail batch: 15 deliveries × 2 attempts read in ONE statement; a delivery with no detail '
			. 'rows still gets an (empty) entry, so purged is distinguishable from unasked';
	}

	/**
	 * THE INDEX THE ORDERING AND THE DATE RANGE NEED IS REALLY IN THE DATABASE.
	 *
	 * ⚠ READ OUT OF THE LIVE SCHEMA, NOT OUT OF THE CONSTANT. `dbDelta()` fails
	 * silently — that is the stated reason `Migrator::verify_schema()` exists at all —
	 * so "the constant lists it" is not evidence that the index exists on any store.
	 *
	 * @return void
	 */
	public function test_the_history_index_exists_in_the_live_schema() {
		global $wpdb;

		$table = Migrator::table( 'deliveries' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test-only schema introspection of a plugin-owned table.
		$rows = (array) $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );

		$columns = array();

		foreach ( $rows as $row ) {
			if ( 'history_recent' === (string) $row['Key_name'] ) {
				$columns[ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
			}
		}

		ksort( $columns );

		$this->assertSame(
			array( 1 => 'first_claimed_at', 2 => 'id' ),
			$columns,
			'⚠ the history_recent index is missing or has the wrong columns, so the default newest-first view is a '
				. 'full scan plus a filesort of a table that is never purged.'
		);

		// And the schema verifier — which compares the EXACT index set — still passes,
		// so the new index was declared rather than merely created.
		$this->assertTrue( true === Migrator::verify_schema( false ), 'the live schema no longer matches Migrator::SCHEMA.' );

		$this->gate[] = 'index: history_recent (first_claimed_at, id) is present in the LIVE schema and '
			. 'verify_schema() still matches the exact declared index set';
	}

	/**
	 * The repository's own constants have not drifted from what the screen assumes.
	 *
	 * @return void
	 */
	public function test_the_filter_allowlists_name_real_columns() {
		global $wpdb;

		$table = Migrator::table( 'deliveries' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test-only schema introspection of a plugin-owned table.
		$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );

		$named = array_merge(
			array_values( DeliveryRepository::HISTORY_INT_FILTERS ),
			array_values( DeliveryRepository::HISTORY_STRING_FILTERS ),
			array( 'first_claimed_at' )
		);

		foreach ( $named as $column ) {
			$this->assertContains(
				$column,
				$columns,
				'⚠ the history filter allowlist names "' . $column . '", which is not a column — the filter would be a SQL error.'
			);
		}

		$this->gate[] = 'filter allowlist: every identifier the WHERE builder can emit is a real column of the '
			. 'tombstone table, read from SHOW COLUMNS';
	}
}
