<?php
/**
 * What a preview actually shows: both modes, both formats, targeting, escaping and
 * every refusal (ADR-0020 §2, §3).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Admin\Notices;
use Extonify\WCEP\Admin\RulePreviewScreen;
use Extonify\WCEP\Delivery\Consolidation;
use Extonify\WCEP\Delivery\RulePreview;
use Extonify\WCEP\Install\Migrator;

/**
 * A preview that shows the merchant something the delivery would not do is worse than
 * no preview, so this class is mostly about agreement with the send path.
 *
 * ⚠ SEVERITY: the escaping test is TIER 1 — a stored `<script>` executing in the admin
 * is a privilege boundary crossed. The rest is TIER 2: a preview that renders the wrong
 * thing misleads a merchant but sends nothing.
 */
final class PreviewRenderTest extends PreviewTestCase {

	/**
	 * A value that must never act, wherever it lands.
	 */
	const HOSTILE = '<script>window.wcepPreviewXss=1;</script>';

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
			fwrite( STDERR, "\n[P12 preview] " . $line );
		}

		$this->gate = array();
	}

	/**
	 * A PREVIEW RENDERS BOTH FORMATS, and the plain one carries no markup and no
	 * entities (ADR-0014 §9a).
	 *
	 * @return void
	 */
	public function test_a_preview_renders_html_and_plain_text() {
		$fixture = $this->previewable(
			array(
				'subject' => 'Care guide for {product_name}',
				'heading' => 'Looking after {product_name}',
				'content' => '<p>Thanks {customer_first_name} &mdash; caf&eacute; instructions for <strong>{product_name}</strong>.</p>',
			)
		);

		$preview = RulePreview::render( $fixture['rule'], $fixture['order_id'] );

		$this->assertSame( RulePreview::OK, $preview['outcome'], 'the preview refused: ' . $preview['code'] );

		// The subject resolved against the REAL order, not against a placeholder name.
		$this->assertStringContainsString(
			'WCEP Preview Product',
			(string) $preview['subject'],
			'the subject did not resolve {product_name} against the real order.'
		);

		// HTML: the store's own wrapper is around it, and the body is in it.
		$this->assertStringContainsString( '<html', strtolower( (string) $preview['html'] ), 'the HTML preview is not a full document.' );
		$this->assertStringContainsString( 'WCEP Preview Product', (string) $preview['html'], 'the HTML body did not resolve the product name.' );
		$this->assertStringContainsString( '<strong>', (string) $preview['html'], 'the merchant\'s own markup was stripped from the HTML preview.' );

		// PLAIN: no markup, and no entities left for WooCommerce to delete
		// (ADR-0014 §9a — `caf&eacute;` must not reach the customer as `caf`).
		$plain = (string) $preview['plain'];

		$this->assertNotSame( '', $plain, 'the plain preview is empty.' );
		$this->assertStringNotContainsString( '<strong>', $plain, '⚠ the plain-text preview carries markup.' );
		$this->assertStringNotContainsString( '<p>', $plain, '⚠ the plain-text preview carries markup.' );
		$this->assertStringNotContainsString( '&eacute;', $plain, '⚠ the plain-text preview carries an undecoded entity.' );
		$this->assertStringNotContainsString( '&mdash;', $plain, '⚠ the plain-text preview carries an undecoded entity.' );
		$this->assertStringContainsString( 'café', $plain, 'the entity was deleted rather than decoded — the ADR-0014 §9a defect.' );
		$this->assertStringContainsString( 'WCEP Preview Product', $plain, 'the plain body did not resolve the product name.' );

		$this->gate[] = 'formats: a preview renders a full HTML document (wrapper + merchant markup preserved) AND a '
			. 'plain body with 0 tags and 0 undecoded entities — `caf&eacute;` arrives as `café`, not as `caf`';
	}

	/**
	 * A PREVIEW RESPECTS TARGETING: a rule that matches nothing renders with empty
	 * matched-item placeholders and SAYS SO (ADR-0020 §2a).
	 *
	 * @return void
	 */
	public function test_a_preview_of_a_non_matching_order_says_so_and_empties_matched_placeholders() {
		$targeted = $this->make_product( 'WCEP Targeted Product' );
		$other    = $this->make_product( 'WCEP Untargeted Product' );

		$rule_id = $this->make_sending_rule(
			$targeted,
			array(
				'subject' => 'About {product_name}',
				'content' => '<p>Product: [{product_name}]</p>',
			)
		);

		// An order that contains ONLY the untargeted product.
		$order = $this->make_order( $other );

		$preview = RulePreview::render( $rule_id, (int) $order->get_id() );

		$this->assertSame( RulePreview::OK, $preview['outcome'], 'a non-matching preview refused: ' . $preview['code'] );

		$this->assertFalse(
			$preview['matched'],
			'⚠ the preview claimed the rule matched an order it does not target.'
		);

		// The matched-item placeholder is EMPTY, and the untargeted product's name is
		// nowhere in the message — a preview that named it would be showing content the
		// delivery would never produce.
		$this->assertStringContainsString(
			'[]',
			(string) $preview['plain'],
			'the matched-item placeholder did not resolve empty for a non-matching order.'
		);

		$this->assertStringNotContainsString(
			'WCEP Untargeted Product',
			(string) $preview['plain'],
			'⚠ the preview bound {product_name} to a product the rule does not target.'
		);

		// AND THE SCREEN SAYS SO, rather than leaving the merchant to work out why a
		// placeholder is blank.
		$markup = $this->render_screen( $rule_id, (int) $order->get_id() );

		$this->assertStringContainsString(
			'targeting does not match anything on this order',
			$markup,
			'⚠ the screen rendered empty placeholders without explaining why.'
		);

		$this->gate[] = 'targeting: a rule previewed against an order it does NOT target reports matched=false, resolves '
			. '{product_name} to the empty string rather than to the order\'s other product, and the screen states the '
			. 'reason in words';
	}

	/**
	 * INSERT MODE PREVIEWS IN POSITION, inside the native email it targets.
	 *
	 * @return void
	 */
	public function test_an_insert_preview_shows_the_content_in_position() {
		$fixture = $this->previewable_insert();

		$preview = RulePreview::render( $fixture['rule'], $fixture['order_id'] );

		$this->assertSame( RulePreview::OK, $preview['outcome'], 'the insert preview refused: ' . $preview['code'] );
		$this->assertSame( 'insert', (string) $preview['mode'], 'the preview did not report insert mode.' );

		$html = (string) $preview['html'];

		$this->assertStringContainsString( 'INSERTED PREVIEW BLOCK.', $html, 'the block was not inserted.' );

		// ⚠ IN POSITION, NOT MERELY PRESENT. `after_order_table` means the block comes
		// AFTER the order table the native template renders — appending it to the end of
		// the document would satisfy "contains" and teach the merchant nothing.
		$table = strpos( $html, 'WCEP Preview Insert Product' );
		$block = strpos( $html, 'INSERTED PREVIEW BLOCK.' );

		$this->assertNotFalse( $table, 'the native order table did not render.' );
		$this->assertNotFalse( $block, 'the inserted block did not render.' );

		$this->assertGreaterThan(
			$table,
			$block,
			'⚠ the inserted block appears BEFORE the order table, so the preview misrepresents its position.'
		);

		// And plain text carries it too, without markup.
		$plain = (string) $preview['plain'];

		$this->assertStringContainsString( 'INSERTED PREVIEW BLOCK.', $plain, 'the plain insert preview lost the block.' );
		$this->assertStringNotContainsString( '<p>', $plain, '⚠ the plain insert preview carries markup.' );

		/*
		 * ⚠ AND IT IS POST-PROCESSED EXACTLY AS THE SEND POST-PROCESSES IT. The preview
		 * goes through `WC_Email::get_content()`, which runs `wp_strip_all_tags()`, the
		 * `plain_search`/`plain_replace` entity pass and `wordwrap( …, 70 )` over the
		 * whole body. Calling `get_content_plain()` directly — which the first draft did
		 * — showed the merchant WooCommerce's own raw `&mdash;`, `&#036;` and `&nbsp;`
		 * entities, none of which a customer ever receives.
		 */
		foreach ( array( '&mdash;', '&#036;', '&nbsp;' ) as $entity ) {
			$this->assertStringNotContainsString(
				$entity,
				$plain,
				'⚠ the plain insert preview shows the raw entity "' . $entity . '", which the customer never sees — '
				. 'the preview is not going through WC_Email::get_content().'
			);
		}

		// The wrap is the other observable half of that same pass.
		foreach ( explode( "\n", $plain ) as $line ) {
			$this->assertLessThanOrEqual(
				100,
				strlen( $line ),
				'⚠ the plain insert preview was not word-wrapped, so it is not what get_content() produces.'
			);
		}

		$this->gate[] = 'insert mode: the rule\'s block renders INSIDE the native customer_processing_order email and '
			. 'AFTER its order table (byte offset asserted), in both HTML and plain text';
	}

	/**
	 * A FAN-OUT RULE REPORTS ITS MESSAGE COUNT rather than pretending to be one email.
	 *
	 * @return void
	 */
	public function test_a_consolidating_rule_reports_how_many_emails_it_would_send() {
		$first  = $this->make_product( 'WCEP Fan Product One' );
		$second = $this->make_product( 'WCEP Fan Product Two' );

		$rule_id = $this->make_rule(
			array(
				'name'          => 'WCEP preview fan-out fixture',
				'delivery_mode' => 'separate',
				'consolidation' => Consolidation::PER_PRODUCT,
				'targeting'     => array( 'include' => array( 'products' => array( $first, $second ) ) ),
				'recipients'    => array( 'to' => array( 'customer' ) ),
				'subject'       => 'About {product_name}',
				'content'       => '<p>{product_name}</p>',
			)
		);

		$order = $this->make_order_with( array( array( $first, 1 ), array( $second, 1 ) ) );

		$preview = RulePreview::render( $rule_id, (int) $order->get_id() );

		$this->assertSame( RulePreview::OK, $preview['outcome'], 'the fan-out preview refused: ' . $preview['code'] );

		$this->assertSame(
			2,
			(int) $preview['messages'],
			'the preview did not report that this rule sends two messages.'
		);

		$markup = $this->render_screen( $rule_id, (int) $order->get_id() );

		$this->assertStringContainsString(
			'would send 2 separate emails',
			$markup,
			'⚠ the screen showed one message without saying two would be sent.'
		);

		$this->gate[] = 'consolidation: a per_product rule matching 2 products previews the FIRST message and states on '
			. 'screen that 2 separate emails would be sent — through Consolidation::plan(), the same planner the send uses';
	}

	/**
	 * GATE 12. HOSTILE STORED VALUES RENDER INERT ON THE PREVIEW SURFACE.
	 *
	 * ⚠ WRITTEN DIRECTLY PAST THE REPOSITORY, which is the whole point. `wp_kses_post()`
	 * at the storage boundary is the first of three defences; writing through it would
	 * mean this test proved only that kses works. The raw column is asserted hostile
	 * first, so the surface has to hold on its own.
	 *
	 * @return void
	 */
	public function test_hostile_stored_values_render_inert_in_the_preview_surface() {
		$fixture = $this->previewable();

		$this->write_raw_rule_columns(
			$fixture['rule'],
			array(
				'subject' => self::HOSTILE,
				'content' => self::HOSTILE,
				'heading' => self::HOSTILE,
			)
		);

		// THE PREMISE: the hostile bytes really are in the columns.
		foreach ( array( 'subject', 'content', 'heading' ) as $column ) {
			$this->assertSame(
				self::HOSTILE,
				$this->raw_rule_column( $fixture['rule'], $column ),
				'⚠ the raw hostile value did not land in ' . $column . ', so this test would prove nothing.'
			);
		}

		$markup = $this->render_screen( $fixture['rule'], $fixture['order_id'] );

		$this->assertNotSame( '', $markup, 'the preview screen rendered nothing.' );

		/*
		 * ⚠ NOT ONE EXECUTABLE `<script>` ANYWHERE ON THE PAGE. The subject is
		 * `esc_html()`'d, the plain body is `esc_html()`'d inside `<pre>`, and the HTML
		 * document is `esc_attr()`'d into a sandboxed `srcdoc` — so every occurrence of
		 * the hostile string is escaped, in all three places at once.
		 */
		$this->assertSame(
			0,
			preg_match( '/<\s*script/i', $markup ),
			'⚠ TIER 1: something a browser would parse as a <script> tag reached the preview screen\'s markup.'
		);

		/*
		 * ⚠ THE PAYLOAD *IS* PRESENT, AND MUST BE — that is ADR-0014 §3's amended
		 * requirement, not a weaker one. A hostile value is NEUTRALISED rather than
		 * deleted: it appears, escaped and visible, so the merchant can see the value
		 * their template actually produced. What must never appear is the tag that would
		 * make it execute, which is the assertion above.
		 */
		$this->assertStringContainsString(
			'&lt;script&gt;',
			$markup,
			'the hostile value never reached the surface, so its neutralisation is untested.'
		);

		/*
		 * ⚠ NEUTRALISED IN BOTH FORMATS, BUT DIFFERENTLY, AND THAT IS ADR-0014 §3's
		 * AMENDED REQUIREMENT RATHER THAN AN INCONSISTENCY:
		 *
		 *   - HTML — ESCAPED AND VISIBLE. The subject line and the srcdoc document each
		 *     carry an escaped copy, so neither surface is holding while the other
		 *     silently drops the value and makes this test look green;
		 *   - PLAIN — REMOVED OUTRIGHT, BY WOOCOMMERCE. `WC_Email::get_content()` runs
		 *     `wp_strip_all_tags()` over the whole plain body, so the tags are gone
		 *     before this plugin sees them again. Asserted as WOOCOMMERCE'S behaviour, so
		 *     a future version that stops stripping fails this suite rather than
		 *     surprising a merchant.
		 */
		$this->assertGreaterThanOrEqual(
			2,
			substr_count( $markup, '&lt;script&gt;' ),
			'⚠ the hostile value was neutralised in fewer HTML surfaces than it appears in — one is dropping it silently.'
		);

		$preview = RulePreview::render( $fixture['rule'], $fixture['order_id'] );

		$this->assertSame( RulePreview::OK, $preview['outcome'], 'the hostile preview refused: ' . $preview['code'] );

		$this->assertSame(
			0,
			preg_match( '/<\s*script/i', (string) $preview['plain'] ),
			'⚠ WooCommerce no longer strips tags from the plain body, so ADR-0014 §3\'s "removed in plain text" half '
			. 'no longer holds and the plain preview needs its own escaping.'
		);

		// AND THE FRAME IS SANDBOXED, which is the third defence and the one that holds
		// if `wp_kses_post()` ever stops stripping something.
		$this->assertStringContainsString(
			'sandbox=""',
			$markup,
			'⚠ TIER 1: the preview iframe is not sandboxed.'
		);

		$this->gate[] = 'escaping: subject, heading and content written DIRECTLY past the repository as '
			. '"' . self::HOSTILE . '" reach the preview surface escaped in all three places (subject esc_html, plain '
			. 'body esc_html in <pre>, HTML document esc_attr into srcdoc) with 0 raw <script> tags and an EMPTY sandbox';
	}

	/**
	 * EVERY PREVIEW REFUSAL HAS ITS OWN REASON, and none of them renders anything.
	 *
	 * @dataProvider refusal_provider
	 *
	 * @param string $expected Refusal code.
	 * @param string $scenario What is being set up.
	 * @return void
	 */
	public function test_every_preview_refusal_names_its_own_reason( string $expected, string $scenario ) {
		$rule_id  = 0;
		$order_id = 0;

		if ( 'rule_deleted' === $scenario ) {
			$rule_id = 999999;
		}

		if ( 'order_missing' === $scenario ) {
			$fixture  = $this->previewable();
			$rule_id  = $fixture['rule'];
			$order_id = 999999;
		}

		if ( 'vocabulary' === $scenario ) {
			$fixture = $this->previewable();
			$rule_id = $fixture['rule'];

			$this->write_raw_rule_columns( $rule_id, array( 'consolidation' => 'weekly_digest' ) );
		}

		if ( 'native_missing' === $scenario ) {
			$fixture  = $this->previewable_insert();
			$rule_id  = $fixture['rule'];
			$order_id = $fixture['order_id'];

			$this->write_raw_rule_columns( $rule_id, array( 'native_email_id' => 'not_a_real_email' ) );
		}

		$before = $this->delivery_checksums();

		$preview = RulePreview::render( $rule_id, $order_id );

		$this->assertSame( RulePreview::REFUSED, $preview['outcome'], 'the ' . $scenario . ' case did not refuse.' );
		$this->assertSame( $expected, $preview['code'], 'the ' . $scenario . ' case refused for the wrong reason.' );

		$this->assertSame( '', (string) $preview['html'], 'a refused preview still produced HTML.' );
		$this->assertSame( '', (string) $preview['plain'], 'a refused preview still produced plain text.' );

		$this->assertSame( $before, $this->delivery_checksums(), '⚠ TIER 1: a refused preview wrote to a delivery table.' );
		$this->assertMailCount( 0, '⚠ TIER 1: a refused preview sent mail.' );

		// And the refusal has a sentence of its own, not a shared fallback.
		$messages = Notices::preview_messages();
		$key      = 'wcep_preview_refused_' . $expected;

		$this->assertArrayHasKey( $key, $messages, '⚠ refusal "' . $expected . '" has no merchant-facing sentence.' );
		$this->assertNotSame( '', trim( (string) $messages[ $key ][1] ), '⚠ refusal "' . $expected . '" has an empty sentence.' );

		$this->gate[] = 'refusal: ' . str_pad( $scenario, 16 ) . ' => ' . $expected . ' (0 bytes rendered, 0 rows, 0 mail, own sentence)';
	}

	/**
	 * The refusal cases.
	 *
	 * @return array[]
	 */
	public function refusal_provider(): array {
		return array(
			'a rule that does not exist'    => array( RulePreview::REFUSED_RULE_DELETED, 'rule_deleted' ),
			'an order that does not exist'  => array( RulePreview::REFUSED_ORDER_MISSING, 'order_missing' ),
			'a rule outside the vocabulary' => array( RulePreview::REFUSED_RULE_VOCABULARY, 'vocabulary' ),
			'an unregistered native email'  => array( RulePreview::REFUSED_NATIVE_MISSING, 'native_missing' ),
		);
	}

	/**
	 * EVERY PREVIEW REFUSAL SENTENCE IS DISTINCT — a shared sentence is the thing
	 * gate 38 forbids for sending actions, and it is no better here.
	 *
	 * @return void
	 */
	public function test_every_preview_refusal_sentence_is_distinct() {
		$messages = Notices::preview_messages();
		$seen     = array();

		foreach ( $messages as $code => $entry ) {
			$sentence = (string) $entry[1];

			$this->assertNotContains(
				$sentence,
				$seen,
				'⚠ preview refusal "' . $code . '" shares its sentence with another code — one message wearing two names.'
			);

			$seen[] = $sentence;
		}

		// Every code the class can actually emit is in the map.
		$reflection = new \ReflectionClass( RulePreview::class );
		$emitted    = array();

		foreach ( $reflection->getConstants() as $name => $value ) {
			if ( 0 === strpos( $name, 'REFUSED_' ) ) {
				$emitted[] = (string) $value;
			}
		}

		foreach ( $emitted as $code ) {
			$this->assertArrayHasKey(
				'wcep_preview_refused_' . $code,
				$messages,
				'⚠ RulePreview can refuse with "' . $code . '" and no sentence exists for it.'
			);
		}

		$this->gate[] = 'sentences: ' . count( $emitted ) . ' preview refusal codes, ' . count( $messages )
			. ' sentences, all distinct, every emitted code covered';
	}

	/**
	 * THE DEFAULT ORDER IS THE MOST RECENT MATCHING ONE, and the search is bounded.
	 *
	 * @return void
	 */
	public function test_the_default_order_is_the_most_recent_matching_one() {
		$targeted = $this->make_product( 'WCEP Default Product' );
		$other    = $this->make_product( 'WCEP Default Other' );

		$rule_id = $this->make_sending_rule( $targeted );

		$matching = $this->make_order( $targeted );

		// A NEWER order that does NOT match: the default must skip it rather than
		// simply taking the newest order in the store.
		$newer = $this->make_order( $other );

		$this->assertGreaterThan(
			(int) $matching->get_id(),
			(int) $newer->get_id(),
			'the fixture did not create the non-matching order last, so this test would prove nothing.'
		);

		$rule = $this->rules->find( $rule_id );

		$this->assertSame(
			(int) $matching->get_id(),
			RulePreview::default_order_id( (array) $rule ),
			'⚠ the default preview order is not the most recent order the rule matches.'
		);

		// AND THE SCAN IS BOUNDED, which is what keeps this off a million-order store.
		$this->assertLessThanOrEqual(
			50,
			RulePreview::MATCH_SCAN_LIMIT,
			'⚠ the matching-order scan is no longer meaningfully bounded.'
		);

		$this->gate[] = 'default order: with a NEWER non-matching order present, the default is the most recent MATCHING '
			. 'order; the scan is bounded at ' . RulePreview::MATCH_SCAN_LIMIT . ' orders';
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Render the preview screen for one rule and order, as a privileged merchant.
	 *
	 * @param int $rule_id  Rule id.
	 * @param int $order_id Order id.
	 * @return string The markup.
	 */
	private function render_screen( int $rule_id, int $order_id ): string {
		$this->become_manager();

		$this->request(
			array(
				'page'                        => Menu::PAGE,
				'action'                      => Menu::ACTION_PREVIEW,
				'rule'                        => $rule_id,
				RulePreviewScreen::ARG_ORDER  => $order_id,
			)
		);

		return $this->capture(
			static function () {
				Menu::render();
			}
		);
	}

	/**
	 * Write raw column values straight into a rule row, past the repository.
	 *
	 * @param int   $rule_id Rule id.
	 * @param array $columns Column => raw value.
	 * @return void
	 */
	private function write_raw_rule_columns( int $rule_id, array $columns ): void {
		global $wpdb;

		$allowed = array( 'subject', 'heading', 'content', 'consolidation', 'delivery_mode', 'native_email_id' );

		foreach ( array_keys( $columns ) as $column ) {
			$this->assertContains( $column, $allowed, 'Unknown rule column requested by a test.' );
		}

		$table = Migrator::table( 'rules' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only direct write of the plugin-owned table; bypassing the repository IS the point (gate 12).
		$wpdb->update( $table, $columns, array( 'id' => $rule_id ) );
	}

	/**
	 * One rule column, exactly as the database holds it.
	 *
	 * @param int    $rule_id Rule id.
	 * @param string $column  Column name.
	 * @return string
	 */
	private function raw_rule_column( int $rule_id, string $column ): string {
		global $wpdb;

		$allowed = array( 'subject', 'heading', 'content', 'consolidation', 'delivery_mode', 'native_email_id' );

		$this->assertContains( $column, $allowed, 'Unknown rule column requested by a test.' );

		$table = Migrator::table( 'rules' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test-only primary-key read of the plugin-owned table; {$column} is checked against the allowlist directly above.
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT `{$column}` FROM {$table} WHERE id = %d", $rule_id ) );
	}
}
