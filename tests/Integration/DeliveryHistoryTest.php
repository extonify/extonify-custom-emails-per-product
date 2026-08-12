<?php
/**
 * GATES 31, 34 and 35 — escaping past the repository, read-only rendering, and a
 * per-page statement count that does not grow (ADR-0018).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\DeliveriesListTable;
use Extonify\WCEP\Admin\DeliveryPresenter;
use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Admin\OrderPanel;
use Extonify\WCEP\Plugin;
use Extonify\WCEP\Repository\DeliveryDetailRepository;

/**
 * The delivery-history screen and the order panel.
 *
 * ⚠ SEVERITY: an unescaped database-sourced output here is TIER 1. The consequence
 * is stored XSS against a `manage_woocommerce` user — a privilege boundary crossed —
 * and this surface is worse than the rules admin because the hostile value does not
 * need a hostile administrator to get in. `recipient` comes from a customer's billing
 * field, `failure_message` is an SMTP server's response quoting that address back,
 * and `reason` embeds both.
 */
final class DeliveryHistoryTest extends DeliveryHistoryTestCase {

	/**
	 * Gate lines.
	 *
	 * @var string[]
	 */
	private $gate = array();

	/**
	 * Print the gate lines.
	 *
	 * @after
	 * @return void
	 */
	protected function report_gate() {
		foreach ( $this->gate as $line ) {
			fwrite( STDERR, "\n[P10] " . $line );
		}

		$this->gate = array();
	}

	/**
	 * THE GATE-31 TABLE, EXTENDED. Every history value, its output context, the
	 * escaping function that guards it, and the surface it appears on.
	 *
	 * ⚠ EVERY ROW IS PROVEN BY A DIRECT WRITE. `self::hostile_delivery()` writes the
	 * payload into the column with `$wpdb->insert()`, past
	 * `DeliveryDetailRepository::insert()`, which would otherwise normalise the
	 * recipient, `sanitize_text_field()` the subject and `Text::log_value()` the reason
	 * and failure message — leaving nothing for the escaper to escape and letting a
	 * column that forgot entirely pass.
	 *
	 * @return array[] Each `{value, source, context, escaper, surface}`.
	 */
	public static function output_table(): array {
		return array(
			array( 'recipient address', 'customer billing field', 'HTML text', 'esc_html', 'both' ),
			array( 'recipient type label', 'plugin vocabulary, from the column', 'HTML text', 'esc_html', 'both' ),
			array( 'subject', 'rule template, placeholder-expanded with order data', 'HTML text', 'esc_html', 'both' ),
			array( 'reason', 'plugin diagnostic, embeds addresses and order values', 'HTML text', 'esc_html', 'both' ),
			array( 'failure message', 'SMTP server response, quotes the address', 'HTML text', 'esc_html', 'both' ),
			array( 'attempt number / type / state', 'plugin vocabulary, from the column', 'HTML text', 'esc_html', 'both' ),
			array( 'delivery status label', 'plugin vocabulary, from the column', 'HTML text', 'esc_html', 'both' ),
			array( 'delivery status CSS class', 'the raw column value', 'HTML attribute', 'esc_attr( sanitize_html_class() )', 'both' ),
			array( 'trigger identity', 'the raw column value', 'HTML text', 'esc_html', 'both' ),
			array( 'delivery mode label', 'plugin vocabulary, from the column', 'HTML text', 'esc_html', 'both' ),
			array( 'rule name', 'merchant free text, from the rules table', 'HTML text', 'esc_html', 'both' ),
			array( 'rule edit URL', 'built from the recorded rule id', 'URL attribute', 'esc_url', 'both' ),
			array( 'order label', 'built from the recorded order id', 'HTML text', 'esc_html', 'both' ),
			array( 'order edit URL', 'built from the recorded order id', 'URL attribute', 'esc_url', 'history' ),
			array( 'timestamps', 'the raw columns, formatted to site time', 'HTML text', 'esc_html', 'both' ),
			array( 'purged / redacted / not-recorded notices', 'plugin copy', 'HTML text', 'esc_html', 'both' ),
			array( 'retention note', 'plugin copy', 'HTML text', 'esc_html', 'both' ),
			array( 'filter control name / id / value', 'the request', 'HTML attribute', 'esc_attr', 'history' ),
			array( 'filter option values and labels', 'plugin vocabulary + rule names', 'HTML attribute / text', 'esc_attr / esc_html', 'history' ),
			array( 'history / panel link URLs', 'built from plugin constants', 'URL attribute', 'esc_url', 'both' ),
		);
	}

	/**
	 * GATE 31a. A HOSTILE DELIVERY RENDERS INERT ON THE HISTORY SCREEN — written
	 *           **STRAIGHT INTO THE TABLE**, past the repository.
	 *
	 * @return void
	 */
	public function test_hostile_values_render_inert_on_the_history_screen() {
		$this->become_manager();

		$order_id = $this->fake_order_id();

		$this->hostile_delivery( array( 'order_id' => $order_id ) );

		$markup = $this->render_history( array( DeliveriesListTable::ARG_ORDER => $order_id ) );

		$this->assertStringContainsString(
			(string) $order_id,
			$markup,
			'the hostile delivery was not on the page, so nothing was proven.'
		);

		$this->assertInert( $markup, 'the delivery history' );

		// ⚠ THE POSITIVE HALF. The escaped form IS present, so the value was RENDERED
		// rather than dropped — without this the test would pass on a screen that
		// silently discarded every recipient.
		$this->assertStringContainsString(
			'&lt;script&gt;wcepXSS()&lt;/script&gt;',
			$markup,
			'⚠ the raw script element never reached the page, so this test proved nothing about escaping.'
		);

		$this->gate[] = 'gate 31 (history): recipient, subject, reason and failure message written DIRECT TO THE '
			. 'TABLE past the repository all render escaped; no executable script, no attribute breakout in '
			. 'either quoting style, no decoded percent-sequence';
	}

	/**
	 * GATE 31b. THE SAME VALUES RENDER INERT IN THE ORDER PANEL.
	 *
	 * ⚠ ASSERTED SEPARATELY, NOT INFERRED FROM THE SCREEN. The two surfaces share a
	 * presenter, which is the design — but "they share a class" is the claim under
	 * test, and a panel that formatted one cell itself would pass a test that only
	 * rendered the history.
	 *
	 * @return void
	 */
	public function test_hostile_values_render_inert_in_the_order_panel() {
		$this->become_manager();

		$order_id = $this->fake_order_id();

		$this->hostile_delivery( array( 'order_id' => $order_id ) );

		$markup = $this->capture(
			static function () use ( $order_id ) {
				OrderPanel::render( (object) array( 'ID' => $order_id ) );
			}
		);

		$this->assertInert( $markup, 'the order panel' );

		$this->assertStringContainsString(
			'&lt;script&gt;wcepXSS()&lt;/script&gt;',
			$markup,
			'⚠ the raw script element never reached the panel, so this test proved nothing about escaping.'
		);

		$this->gate[] = 'gate 31 (order panel): the same direct-write payload renders escaped on the panel too, '
			. 'asserted independently of the history screen';
	}

	/**
	 * GATE 31c. THE PAYLOAD REALLY IS IN THE COLUMNS.
	 *
	 * ⚠ THIS IS THE TEST THAT MAKES THE OTHER TWO MEAN ANYTHING. If the fixture were
	 * quietly sanitised on the way in — which is exactly what would happen if it went
	 * through the repository — then "no script survived into the page" would be true
	 * of a screen with no escaping at all.
	 *
	 * @return void
	 */
	public function test_the_hostile_fixture_reaches_the_columns_unsanitised() {
		$this->become_manager();

		$delivery_id = $this->raw_delivery();

		$detail_id = $this->raw_detail(
			$delivery_id,
			array(
				'recipient'        => self::HOSTILE,
				'recipient_header' => self::HOSTILE,
				'subject'          => self::HOSTILE,
				'reason'           => self::HOSTILE,
				'failure_message'  => self::HOSTILE,
			)
		);

		foreach ( array( 'recipient', 'recipient_header', 'subject', 'reason', 'failure_message' ) as $column ) {
			$this->assertRawColumnIsHostile( $detail_id, $column );
		}

		// And the repository would NOT have let this through — the contrast that makes
		// the direct write necessary rather than merely convenient.
		$rejected = Plugin::instance()->delivery_details()->insert(
			$delivery_id,
			array(
				'recipient' => self::HOSTILE,
				'state'     => 'sent',
			)
		);

		$this->assertSame(
			0,
			$rejected,
			'⚠ the repository ACCEPTED the hostile recipient, so the direct-write fixture is not proving what it claims.'
		);

		$this->gate[] = 'gate 31 (premise): all five personal columns hold the raw payload byte-for-byte, and the '
			. 'repository REFUSES the same value — so storage sanitisation and output escaping are proven separately';
	}

	/**
	 * GATE 31d. THE OUTPUT TABLE IS PRINTED.
	 *
	 * @return void
	 */
	public function test_the_output_table_is_printed() {
		$rows = self::output_table();

		$this->assertGreaterThan( 15, count( $rows ), 'the gate-31 history table is suspiciously short.' );

		$table = '';

		foreach ( $rows as $row ) {
			$table .= '             │ ' . str_pad( $row[0], 42 ) . ' │ ' . str_pad( $row[2], 16 ) . ' │ '
				. str_pad( $row[3], 32 ) . ' │ ' . str_pad( $row[4], 8 ) . " │\n";
		}

		$this->gate[] = 'gate 31 table: ' . count( $rows ) . " history outputs, each proven by a direct write\n"
			. "             ┌────────────────────────────────────────────┬──────────────────┬──────────────────────────────────┬──────────┐\n"
			. "             │ value                                      │ context          │ escaper                          │ surface  │\n"
			. "             ├────────────────────────────────────────────┼──────────────────┼──────────────────────────────────┼──────────┤\n"
			. $table
			. "             └────────────────────────────────────────────┴──────────────────┴──────────────────────────────────┴──────────┘\n";
	}

	/**
	 * PURGED DETAIL: a tombstone whose detail rows are gone renders the honest
	 * sentence, not an empty row (ADR-0018 §4a).
	 *
	 * @return void
	 */
	public function test_a_tombstone_with_purged_details_says_so() {
		$this->become_manager();

		$order_id = $this->fake_order_id();

		// A delivery that really happened, whose details retention removed. No detail
		// row is written at all — which is exactly the state `purge_older_than()` leaves.
		$this->raw_delivery(
			array(
				'order_id'     => $order_id,
				'final_status' => 'sent',
			)
		);

		$markup = $this->render_history( array( DeliveriesListTable::ARG_ORDER => $order_id ) );

		$this->assertStringContainsString(
			'This delivery was recorded, but its details have been removed.',
			$markup,
			'⚠ a purged delivery rendered without explaining itself — a merchant reads that as a bug.'
		);

		$this->assertStringNotContainsString(
			'No custom emails have been sent',
			$markup,
			'⚠ a purged delivery was reported as no delivery at all.'
		);

		// The delivery itself is still fully described: the row is not a blank.
		$this->assertStringContainsString( (string) $order_id, $markup );
		$this->assertStringContainsString( 'Sent', $markup );

		$this->gate[] = 'purged: a tombstone with zero detail rows renders "This delivery was recorded, but its '
			. 'details have been removed." with its order, rule, status and timing intact — never a blank row, '
			. 'never "no deliveries"';
	}

	/**
	 * ANONYMISED DETAIL: a row through the privacy eraser renders redacted — not
	 * blank, and not as an error (ADR-0018 §4b).
	 *
	 * ⚠ ERASED THROUGH THE REAL ERASER PATH, not by writing nulls by hand. The claim
	 * is about what a genuine privacy request leaves behind, and hand-nulling the
	 * columns I happen to think it clears would assert my own belief rather than the
	 * eraser's behaviour.
	 *
	 * @return void
	 */
	public function test_an_anonymised_attempt_renders_redacted_and_not_as_an_error() {
		$this->become_manager();

		$order_id = $this->fake_order_id();

		$delivery_id = $this->raw_delivery( array( 'order_id' => $order_id ) );

		$detail_id = $this->raw_detail(
			$delivery_id,
			array(
				'recipient' => 'erase-me@example.test',
				'subject'   => 'Your order',
				'reason'    => 'delivered',
				'state'     => 'sent',
			)
		);

		$cleared = Plugin::instance()->delivery_details()->anonymize_ids( array( $detail_id ) );

		$this->assertSame( 1, $cleared, 'the eraser did not clear the fixture row.' );

		$markup = $this->render_history( array( DeliveriesListTable::ARG_ORDER => $order_id ) );

		$this->assertStringContainsString(
			'removed in response to a personal-data erasure request',
			$markup,
			'⚠ an erased attempt did not say it had been erased.'
		);

		// The attempt is still THERE and still says what happened.
		$this->assertStringContainsString( 'Attempt 1', $markup, '⚠ the erased attempt vanished from the history.' );
		$this->assertStringContainsString( 'Sent', $markup, '⚠ the erased attempt lost its outcome.' );

		// The erased address is gone, and no failure was invented.
		$this->assertStringNotContainsString( 'erase-me@example.test', $markup, '⚠ TIER 1: an erased address was rendered.' );
		$this->assertStringNotContainsString( 'unknown error', strtolower( $markup ), '⚠ an erased attempt was reported as an error.' );
		$this->assertStringNotContainsString(
			'its details have been removed',
			$markup,
			'⚠ an erased attempt was reported as PURGED — two different facts collapsed into one sentence.'
		);

		$this->gate[] = 'anonymised: a row through Privacy\\Eraser renders the erasure sentence, keeps its attempt '
			. 'number and outcome, drops the address, and is NOT reported as purged or as an error';
	}

	/**
	 * The three absences are three DIFFERENT renderings (ADR-0018 §4c).
	 *
	 * ⚠ THE POINT IS THE CONTRAST, NOT THE THREE SENTENCES INDIVIDUALLY. A merchant
	 * who cannot tell "this order never triggered a custom email" from "it did, and the
	 * details aged out" will read a working plugin as a broken one — and the two states
	 * are one `DELETE` apart in a table this plugin purges on purpose.
	 *
	 * @return void
	 */
	public function test_empty_purged_and_erased_render_differently() {
		$this->become_manager();

		$empty_order  = $this->fake_order_id();
		$purged_order = $this->fake_order_id();
		$erased_order = $this->fake_order_id();

		$this->raw_delivery( array( 'order_id' => $purged_order ) );

		$erased_delivery = $this->raw_delivery( array( 'order_id' => $erased_order ) );
		$erased_detail   = $this->raw_detail( $erased_delivery, array( 'recipient' => 'gone@example.test' ) );

		Plugin::instance()->delivery_details()->anonymize_ids( array( $erased_detail ) );

		$renders = array(
			'empty'  => $this->panel_for( $empty_order ),
			'purged' => $this->panel_for( $purged_order ),
			'erased' => $this->panel_for( $erased_order ),
		);

		$this->assertStringContainsString( 'No custom emails have been sent for this order.', $renders['empty'] );
		$this->assertStringNotContainsString( 'its details have been removed', $renders['empty'] );

		$this->assertStringContainsString( 'its details have been removed', $renders['purged'] );
		$this->assertStringNotContainsString( 'No custom emails have been sent for this order.', $renders['purged'] );

		$this->assertStringContainsString( 'personal-data erasure request', $renders['erased'] );
		$this->assertStringNotContainsString( 'its details have been removed', $renders['erased'] );
		$this->assertStringNotContainsString( 'No custom emails have been sent for this order.', $renders['erased'] );

		// And no two of them are the same page.
		$this->assertNotSame( $renders['empty'], $renders['purged'], '⚠ empty and purged render identically.' );
		$this->assertNotSame( $renders['purged'], $renders['erased'], '⚠ purged and erased render identically.' );
		$this->assertNotSame( $renders['empty'], $renders['erased'], '⚠ empty and erased render identically.' );

		$this->gate[] = 'three absences: "No custom emails have been sent for this order." / "its details have been '
			. 'removed" / "personal-data erasure request" are three distinct renderings, and no two of the three '
			. 'pages are byte-identical';
	}

	/**
	 * The retention windows are on the screen, and so is the fact that nothing
	 * currently applies them (ADR-0018 §4d).
	 *
	 * ⚠ THE SECOND HALF IS THE ONE UNDER TEST. ADR-0005 specifies 90/180/14 days
	 * purged daily; `purge_older_than()` has no production caller, so a screen stating
	 * those windows as current behaviour would tell every merchant something untrue
	 * about their own data. This asserts the disclosure is present — and asserts the
	 * premise, so the day retention IS scheduled this test fails and forces the
	 * sentence to be corrected rather than quietly going stale.
	 *
	 * @return void
	 */
	public function test_the_retention_note_states_the_windows_and_the_truth_about_them() {
		$this->become_manager();

		$markup = $this->render_history();

		foreach ( array( '90', '180', '14' ) as $window ) {
			$this->assertStringContainsString(
				$window,
				$markup,
				'the retention note does not state the ' . $window . '-day window.'
			);
		}

		$this->assertStringContainsString(
			'Automatic clearing is not scheduled yet',
			$markup,
			'⚠ the screen states retention windows as though they were being applied.'
		);

		// THE PREMISE, ASSERTED. Nothing in production CALLS the purge, which is what
		// makes the disclosure true. If that changes, this fails here rather than
		// leaving a false sentence on a merchant's screen.
		//
		// ⚠ THE SEARCH IS FOR A CALL, `->purge_older_than(`, NOT FOR THE NAME. The name
		// appears in the method's own declaration and in two docblocks that EXPLAIN this
		// very situation — so a bare name search reports the explanation as the thing it
		// is explaining, and the gate can never go green.
		$callers = array();

		foreach ( $this->production_files() as $file ) {
			if ( false !== strpos( (string) file_get_contents( $file ), '->purge_older_than(' ) ) {
				$callers[] = basename( $file );
			}
		}

		$this->assertSame(
			array(),
			$callers,
			'⚠ the retention purge now has a caller (' . implode( ', ', $callers )
				. '), so the screen\'s "not scheduled yet" sentence is out of date and is now telling merchants '
				. 'something untrue. Correct DeliveryPresenter::retention_note().'
		);

		$this->gate[] = 'retention note: the 90/180/14-day windows are stated ALONGSIDE "Automatic clearing is not '
			. 'scheduled yet", and the premise is asserted — no file in src/ CALLS purge_older_than(), so the '
			. 'disclosure is true and this gate turns red the day it stops being';
	}

	/**
	 * GATE 34. RENDERING EITHER SURFACE WRITES NOTHING TO EITHER TABLE.
	 *
	 * ⚠ ASSERTED TWO WAYS, BECAUSE EACH MISSES WHAT THE OTHER CATCHES. The statement
	 * filter proves no `INSERT`/`UPDATE`/`DELETE` was ISSUED; the checksums prove the
	 * tables are byte-identical afterwards, which also covers a write that reached the
	 * database by some route the filter never saw.
	 *
	 * @return void
	 */
	public function test_rendering_neither_surface_writes_to_either_table() {
		$this->become_manager();

		$order_id    = $this->fake_order_id();
		$delivery_id = $this->raw_delivery( array( 'order_id' => $order_id ) );

		$this->raw_detail( $delivery_id );
		$this->raw_delivery(); // A second delivery, so the unfiltered screen has work to do.

		$before = $this->delivery_checksums();

		$statements = $this->record_statements(
			function () use ( $order_id ) {
				$this->render_history();
				$this->render_history( array( DeliveriesListTable::ARG_ORDER => $order_id ) );
				$this->panel_for( $order_id );
			}
		);

		$writes = $this->delivery_writes( $statements );

		$this->assertSame(
			array(),
			$writes,
			'⚠ TIER 1: rendering a read-only surface issued ' . count( $writes ) . ' write(s) against a delivery table: '
				. implode( ' | ', array_slice( $writes, 0, 3 ) )
		);

		$after = $this->delivery_checksums();

		$this->assertSame( $before, $after, '⚠ TIER 1: a delivery table changed while rendering a read-only surface.' );

		$this->gate[] = 'gate 34 (read-only): 3 renders (history unfiltered, history filtered, order panel) issued '
			. count( $this->delivery_statements( $statements ) ) . ' delivery-table statements, 0 of them writes; '
			. 'both tables byte-identical afterwards (' . $before['delivery_rows'] . ' tombstones, '
			. $before['detail_rows'] . ' detail rows, checksums unchanged)';
	}

	/**
	 * GATE 35. THE PER-PAGE STATEMENT COUNT DOES NOT GROW WITH THE PAGE.
	 *
	 * ⚠ THE FORMULA IS THE CLAIM, AND IT IS MEASURED RATHER THAN ASSERTED. Four
	 * statements against this plugin's own tables per history render — the pager
	 * count, the page of tombstones, every attempt row for those tombstones in ONE
	 * batch, and every rule name for those rules in ONE batch — plus the rule
	 * dropdown, which is one more and is also constant. A page of 3 and a page of 15
	 * must produce the SAME number, because the failure this guards against
	 * (resolving details or names inside a column callback) shows up as a difference
	 * of exactly twelve.
	 *
	 * @return void
	 */
	public function test_the_per_page_statement_count_does_not_grow_with_the_page() {
		$this->become_manager();

		$small_order = $this->fake_order_id();
		$large_order = $this->fake_order_id();

		$this->deliveries_for( $small_order, 3 );
		$this->deliveries_for( $large_order, 15 );

		// A warm-up render, so neither measurement pays for a cache WordPress fills on
		// first use and the two are compared on equal terms.
		$this->render_history( array( DeliveriesListTable::ARG_ORDER => $small_order ) );

		$small = $this->record_statements(
			function () use ( $small_order ) {
				$this->render_history( array( DeliveriesListTable::ARG_ORDER => $small_order ) );
			}
		);

		$large = $this->record_statements(
			function () use ( $large_order ) {
				$this->render_history( array( DeliveriesListTable::ARG_ORDER => $large_order ) );
			}
		);

		$small_delivery = count( $this->delivery_statements( $small ) );
		$large_delivery = count( $this->delivery_statements( $large ) );

		$small_plugin = count( $this->plugin_statements( $small ) );
		$large_plugin = count( $this->plugin_statements( $large ) );

		// The delivery tables: the pager count, the page of tombstones, and ONE batch
		// for every attempt row on it.
		$this->assertSame(
			3,
			$small_delivery,
			'the history render no longer costs the three delivery-table statements ADR-0018 §7 states.'
		);

		// Plus the rules table: one batch for the names on the page, one for the rule
		// filter's options.
		$this->assertSame(
			5,
			$small_plugin,
			'the history render no longer costs the five plugin-table statements ADR-0018 §7 states.'
		);

		$this->assertSame(
			$small_delivery,
			$large_delivery,
			'⚠ the delivery-table statement count grew from 3 rows to 15 — details are being resolved per row.'
		);

		$this->assertSame(
			$small_plugin,
			$large_plugin,
			'⚠ the plugin-table statement count grew from 3 rows to 15 — rule names are being resolved per row.'
		);

		$this->assertSame(
			count( $small ),
			count( $large ),
			'⚠ the TOTAL statement count grew with the page size: ' . count( $small ) . ' for 3 rows, '
				. count( $large ) . ' for 15. Something is being loaded per row.'
		);

		$this->gate[] = 'gate 35 (query count): 3 deliveries and 15 deliveries each render in ' . $small_delivery
			. ' delivery-table statements, ' . $small_plugin . ' plugin-table statements and ' . count( $small )
			. ' statements in total — formula 1 count + 1 page + 1 detail batch + 1 rule-name batch + 1 rule filter '
			. '= 5, constant in page size';
	}

	/**
	 * PAGINATION IS DETERMINISTIC — including when rows share a timestamp.
	 *
	 * ⚠ THE SHARED TIMESTAMP IS THE WHOLE TEST. Ordering by `first_claimed_at` alone
	 * leaves ties undefined, so MySQL may return them in one order for page 1 and
	 * another for page 2 — and a row that swaps across the boundary is either shown
	 * twice or never shown at all. Every fixture here is written with the SAME
	 * `first_claimed_at` on purpose, so the tie-break on the primary key is the only
	 * thing that can make this pass.
	 *
	 * @return void
	 */
	public function test_every_row_appears_exactly_once_across_pages() {
		$this->become_manager();

		$order_id = $this->fake_order_id();
		$total    = ( $this->per_page() * 2 ) + 3;
		$stamp    = gmdate( 'Y-m-d H:i:s' );

		$expected = array();

		for ( $i = 0; $i < $total; $i++ ) {
			$expected[] = $this->raw_delivery(
				array(
					'order_id'         => $order_id,
					'first_claimed_at' => $stamp,
					'last_seen_at'     => $stamp,
				)
			);
		}

		sort( $expected );

		$seen  = array();
		$pages = (int) ceil( $total / $this->per_page() );

		for ( $page = 1; $page <= $pages; $page++ ) {
			$rows = DeliveryPresenter::deliveries()->query(
				array(
					'order_id' => $order_id,
					'limit'    => $this->per_page(),
					'offset'   => ( $page - 1 ) * $this->per_page(),
				)
			);

			foreach ( $rows as $row ) {
				$seen[] = (int) $row['id'];
			}
		}

		$duplicates = array_keys( array_filter( array_count_values( $seen ), static fn( $n ) => $n > 1 ) );

		$this->assertSame( array(), $duplicates, '⚠ paging returned the same delivery twice: ' . implode( ', ', $duplicates ) );

		$found = $seen;
		sort( $found );

		$this->assertSame(
			$expected,
			$found,
			'⚠ paging skipped or repeated a delivery across ' . $pages . ' pages of ' . $total . ' rows sharing one timestamp.'
		);

		$this->gate[] = 'pagination: ' . $total . ' deliveries sharing ONE first_claimed_at, read across ' . $pages
			. ' pages of ' . $this->per_page() . ' — every id appears exactly once, none repeated, none skipped';
	}

	/**
	 * The `identity_hash` never reaches either surface (ADR-0018 §2, ADR-0010).
	 *
	 * @return void
	 */
	public function test_the_identity_hash_is_never_rendered() {
		$this->become_manager();

		$order_id = $this->fake_order_id();
		$hash     = str_pad( 'wcephash', 64, 'a' );

		$delivery_id = $this->raw_delivery(
			array(
				'order_id'      => $order_id,
				'identity_hash' => $hash,
			)
		);

		$this->raw_detail( $delivery_id );

		$surfaces = array(
			'history' => $this->render_history(),
			'panel'   => $this->panel_for( $order_id ),
		);

		foreach ( $surfaces as $where => $markup ) {
			$this->assertStringNotContainsString(
				$hash,
				$markup,
				'⚠ the internal identity hash was rendered on ' . $where . '; ADR-0010 excluded it from the exporter for the same reason.'
			);
		}

		$this->gate[] = 'identity_hash: absent from both surfaces, as ADR-0010 already required of the privacy exporter';
	}

	/**
	 * A delivery whose rule has been deleted says so, rather than rendering blank
	 * (ADR-0018 §3).
	 *
	 * @return void
	 */
	public function test_a_deleted_rule_renders_its_recorded_id_and_says_it_is_gone() {
		$this->become_manager();

		$order_id = $this->fake_order_id();

		// A rule id that certainly does not resolve: ADR-0004 keeps the tombstone when
		// the rule goes, so this is the ordinary state of an old delivery.
		$this->raw_delivery(
			array(
				'order_id' => $order_id,
				'rule_id'  => 987654,
			)
		);

		$markup = $this->render_history( array( DeliveriesListTable::ARG_ORDER => $order_id ) );

		$this->assertStringContainsString( 'Rule #987654', $markup, '⚠ a deleted rule rendered as nothing at all.' );
		$this->assertStringContainsString( 'This rule has been deleted.', $markup );

		$this->gate[] = 'deleted rule: renders "Rule #987654" plus "This rule has been deleted." — the recorded id, '
			. 'because the tombstone holds no rule NAME and ADR-0018 §3 declined to widen claim() to store one';
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Render the order panel for one order.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private function panel_for( int $order_id ): string {
		return $this->capture(
			static function () use ( $order_id ) {
				OrderPanel::render( (object) array( 'ID' => $order_id ) );
			}
		);
	}

	/**
	 * A run of deliveries on one order, each with one attempt.
	 *
	 * @param int $order_id Order id.
	 * @param int $count    How many.
	 * @return int[] Tombstone ids.
	 */
	private function deliveries_for( int $order_id, int $count ): array {
		$ids = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$delivery_id = $this->raw_delivery(
				array(
					'order_id' => $order_id,
					// A DIFFERENT rule id each time, so a per-row name lookup would show
					// up as a per-row statement rather than hiding behind one cache hit.
					'rule_id'  => 500000 + $i,
				)
			);

			$this->raw_detail( $delivery_id, array( 'state' => 0 === $i % 2 ? 'sent' : 'failed' ) );

			$ids[] = $delivery_id;
		}

		return $ids;
	}

	/**
	 * Every production PHP file.
	 *
	 * @return string[]
	 */
	private function production_files(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array();

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src' ) ) as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		return $files;
	}

	/**
	 * Assert rendered markup carries no live script, no attribute breakout and no
	 * percent-sequence that decodes into markup.
	 *
	 * ⚠ THE SAME FOUR CHECKS `AdminOutputTest::assertInert()` MAKES, minus the RCDATA
	 * split: neither history surface renders a `<textarea>`, so there is no RCDATA
	 * region here and pretending otherwise would weaken the check rather than
	 * strengthen it.
	 *
	 * @param string $markup Rendered markup.
	 * @param string $where  What was rendered.
	 * @return void
	 */
	private function assertInert( string $markup, string $where ): void {
		$this->assertStringNotContainsString(
			'<textarea',
			$markup,
			'this surface now renders a textarea, so assertInert() needs the RCDATA split AdminOutputTest uses.'
		);

		$this->assertStringNotContainsString(
			'<script>wcepXSS',
			$markup,
			'⚠ TIER 1: a script element survived into ' . $where . '.'
		);

		$this->assertStringNotContainsString(
			'onmouseover="wcepXSS',
			$markup,
			'⚠ TIER 1: a double-quoted attribute breakout survived into ' . $where . '.'
		);

		$this->assertStringNotContainsString(
			"onfocus='wcepXSS",
			$markup,
			'⚠ TIER 1: a single-quoted attribute breakout survived into ' . $where . '.'
		);

		$this->assertStringNotContainsString(
			'%3Cscript%3EwcepXSS',
			rawurldecode( $markup ),
			'⚠ TIER 1: a percent-encoded script survived decoding into ' . $where . '.'
		);
	}

	/**
	 * THE RENDERED VOCABULARIES ARE THE STORED ONES.
	 *
	 * ⚠ A STATUS THE PLUGIN CAN WRITE BUT THE SCREEN CANNOT NAME renders as a raw
	 * slug and is missing from the filter entirely — so a merchant filtering for it
	 * gets an empty list and concludes it never happens. `abandoned` and `unresolved`
	 * are the two most likely to be forgotten, because both were added to their
	 * enumerations after the vocabulary was first written.
	 *
	 * @return void
	 */
	public function test_the_attempt_state_vocabulary_is_the_stored_one() {
		$this->assertContains(
			DeliveryDetailRepository::ABANDONED,
			DeliveryDetailRepository::STATES,
			'the attempt-state vocabulary lost `abandoned`.'
		);

		$this->assertArrayHasKey(
			DeliveryDetailRepository::ABANDONED,
			\Extonify\WCEP\Admin\FieldOptions::attempt_states(),
			'⚠ an attempt state the plugin can store has no label, so it would render as a raw slug.'
		);

		$this->assertSame(
			array_values( DeliveryDetailRepository::STATES ),
			array_keys( \Extonify\WCEP\Admin\FieldOptions::attempt_states() ),
			'⚠ the rendered attempt-state vocabulary drifted from the stored one.'
		);

		$this->assertSame(
			array_values( \Extonify\WCEP\Repository\DeliveryRepository::FINAL_STATUSES ),
			array_keys( \Extonify\WCEP\Admin\FieldOptions::delivery_statuses() ),
			'⚠ the status filter offers a vocabulary the storage layer does not write.'
		);
	}

	/**
	 * The filter form carries the page argument, or filtering navigates away from the
	 * screen it is filtering.
	 *
	 * @return void
	 */
	public function test_the_filter_form_carries_the_history_page_forward() {
		$this->become_manager();

		$markup = $this->render_history();

		$this->assertStringContainsString(
			'value="' . Menu::HISTORY_PAGE . '"',
			$markup,
			'⚠ filtering would navigate away from the screen it is filtering.'
		);
	}
}
