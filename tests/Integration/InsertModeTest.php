<?php
/**
 * Insert mode end to end (ADR-0013).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Render\Injector;
use Extonify\WCEP\Render\RenderEvents;

/**
 * Content injected into WooCommerce's OWN emails.
 *
 * Every "the content appeared" assertion reads the `pre_wp_mail` capture — the
 * actual message WooCommerce handed the mailer — never a return value or an
 * internal flag.
 */
final class InsertModeTest extends InsertModeTestCase {

	/**
	 * 1. END TO END: a matching product, a rule on `customer_processing_order` at
	 *    "after order table" — the content appears in the native processing
	 *    email, in BOTH formats, and ONE insert record is written.
	 *
	 * @return void
	 */
	public function test_end_to_end_insert_in_html_and_plain_text() {
		$product_id = $this->make_simple_product( 'WCEP Insert E2E' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_insert_rule( $product_id, array( 'content' => '<p>CARE GUIDE BLOCK.</p>' ) );

		// --- HTML ------------------------------------------------------------
		$html = $this->send_native( $order_id );

		$this->assertMailCount( 1 );
		$this->assertBody( $html, 'CARE GUIDE BLOCK.', true, 'The content never reached the native HTML email.' );

		$tombstone = $this->insert_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone, 'No insert record was written (ADR-0013 §1).' );

		$this->assertSame( 'insert', $tombstone['mode'] );
		$this->assertSame( 'native:customer_processing_order', $tombstone['trigger_identity'] );
		$this->assertSame( 'sent', $tombstone['final_status'] );

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 1, $rows, 'Exactly one record per rule per native email.' );
		$this->assertSame( 'sent', $rows[0]['state'] );
		$this->assertStringContainsString( 'customer_processing_order', (string) $rows[0]['reason'] );
		$this->assertStringContainsString( 'after_order_table', (string) $rows[0]['reason'] );

		// --- PLAIN TEXT ------------------------------------------------------
		$plain = $this->send_native( $order_id, 'WC_Email_Customer_Processing_Order', true );

		$this->assertMailCount( 2 );
		$this->assertBody( $plain, 'CARE GUIDE BLOCK.', true, 'The content never reached the native plain-text email.' );
		$this->assertBody( $plain, '<p>', false, 'The plain-text render emitted markup.' );

		fwrite(
			STDERR,
			"\n[p5 item 1] plain-text render, injected block:\n  "
			. trim( self::extract( (string) $plain['message'], 'CARE GUIDE BLOCK.' ) ) . "\n"
		);
	}

	/**
	 * 2. ALL FIVE POSITIONS render, in both formats, and the per-item position
	 *    renders BESIDE THE MATCHED ITEM ONLY.
	 *
	 * @return void
	 */
	public function test_all_five_positions_render_in_both_formats() {
		$matched   = $this->make_simple_product( 'WCEP Position Matched' );
		$unmatched = $this->make_simple_product( 'WCEP Position Unmatched' );

		$order    = $this->make_order_with( array( $matched, $unmatched ) );
		$order_id = (int) $order->get_id();

		$positions = array_keys( Injector::POSITIONS );

		foreach ( $positions as $index => $position ) {
			$this->make_insert_rule(
				$matched,
				array(
					'name'            => 'position ' . $position,
					'priority'        => 10 + $index,
					'insert_position' => $position,
					'content'         => '<p>BLOCK-' . strtoupper( str_replace( '_', '-', $position ) ) . '</p>',
				)
			);
		}

		foreach ( array( false, true ) as $plain_text ) {
			$this->captured_mail = array();

			$mail  = $this->send_native( $order_id, 'WC_Email_Customer_Processing_Order', $plain_text );
			$label = $plain_text ? 'plain text' : 'HTML';

			foreach ( $positions as $position ) {
				$this->assertBody(
					$mail,
					'BLOCK-' . strtoupper( str_replace( '_', '-', $position ) ),
					true,
					'Position ' . $position . ' did not render in ' . $label . '.'
				);
			}

			// PER-ITEM CONTENT SITS BESIDE THE MATCHED ITEM ONLY: exactly one
			// occurrence, even though the order carries two line items.
			$this->assertSame(
				1,
				substr_count( (string) $mail['message'], 'BLOCK-ITEM-META' ),
				'The per-item block rendered beside the wrong number of items in ' . $label . '.'
			);
		}

		fwrite(
			STDERR,
			"\n[p5 item 2] five positions rendered in HTML and plain text: " . implode( ', ', $positions ) . "\n"
		);
	}

	/**
	 * 3. FRONT-END NEGATIVE GATE: the storefront order templates inject NOTHING
	 *    and raise NO PHP notices (ADR-0003, ADR-0013 §4).
	 *
	 * The shared `woocommerce_order_item_meta_end` hook fires in all three of
	 * these templates as well as in emails, which is the entire reason it is
	 * gated on a render frame rather than on its own arguments.
	 *
	 * @return void
	 */
	public function test_front_end_templates_inject_nothing_and_raise_no_notices() {
		$product_id = $this->make_simple_product( 'WCEP Front End' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$this->make_insert_rule(
			$product_id,
			array(
				'insert_position' => 'item_meta',
				'content'         => '<p>MUST NOT LEAK TO THE STOREFRONT.</p>',
			)
		);

		$notices = array();

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- a test asserting the ABSENCE of notices has to observe them.
		set_error_handler(
			static function ( $errno, $errstr ) use ( &$notices ) {
				$notices[] = $errno . ': ' . $errstr;
				return true;
			}
		);

		$rendered = array();

		try {
			/*
			 * THE ARGUMENTS WOOCOMMERCE'S OWN LOADERS PASS. Omitting them makes
			 * core's templates raise their own undefined-variable notices, which
			 * would make this gate assert something about WooCommerce rather than
			 * about this plugin.
			 */
			$templates = array(
				'order/order-details.php' => array(
					'order'          => $order,
					'order_id'       => $order_id,
					'show_downloads' => false,
				),
				'checkout/form-pay.php'   => array(
					'order'             => $order,
					'order_id'          => $order_id,
					'available_gateways' => array(),
					'order_button_text' => 'Pay for order',
				),
			);

			foreach ( $templates as $template => $args ) {
				ob_start();
				wc_get_template( $template, $args );
				$rendered[ $template ] = (string) ob_get_clean();
			}

			// The SHARED hook fired directly, exactly as a storefront template
			// with no frame open does it — including the three-argument form
			// stale theme overrides still use.
			ob_start();
			foreach ( $order->get_items() as $item_id => $item ) {
				do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order, false );
				do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order );
			}
			$rendered['direct hook'] = (string) ob_get_clean();
		} finally {
			restore_error_handler();
		}

		foreach ( $rendered as $where => $output ) {
			$this->assertStringNotContainsString(
				'MUST NOT LEAK TO THE STOREFRONT.',
				$output,
				'Email-only content leaked onto ' . $where . '.'
			);
		}

		$this->assertSame( array(), $notices, 'The storefront render raised PHP notices: ' . implode( ' | ', $notices ) );

		// And nothing was recorded, because nothing was inserted.
		$this->assertSame( array(), $this->tombstones_for( $order_id ) );

		fwrite(
			STDERR,
			"\n[p5 item 3] front-end negative gate: " . count( $rendered ) . " render paths, 0 injected fragments, 0 notices\n"
		);
	}

	/**
	 * 4. AUDIENCE AND EMAIL ISOLATION: a `customer_processing_order` rule appears
	 *    there and NOWHERE else.
	 *
	 * @return void
	 */
	public function test_a_rule_appears_only_in_its_own_email_for_its_own_order() {
		$product_id = $this->make_simple_product( 'WCEP Isolation' );
		$other_id   = $this->make_simple_product( 'WCEP Isolation Other' );

		$order = $this->make_order_with( array( $product_id ) );
		$mine  = (int) $order->get_id();

		$foreign_order = $this->make_order_with( array( $other_id ) );
		$foreign       = (int) $foreign_order->get_id();

		$this->make_insert_rule( $product_id, array( 'content' => '<p>PROCESSING ONLY BLOCK.</p>' ) );

		$matrix = array();

		foreach (
			array(
				'customer_processing_order / matching order' => array( $mine, 'WC_Email_Customer_Processing_Order', true ),
				'admin_new_order / matching order'           => array( $mine, 'WC_Email_New_Order', false ),
				'customer_completed_order / matching order'  => array( $mine, 'WC_Email_Customer_Completed_Order', false ),
				'customer_processing_order / other order'    => array( $foreign, 'WC_Email_Customer_Processing_Order', false ),
			) as $label => $case
		) {
			list( $order_id, $class_name, $expected ) = $case;

			$this->captured_mail = array();

			$mail = $this->send_native( $order_id, $class_name );

			$present = null !== $mail && false !== strpos( (string) $mail['message'], 'PROCESSING ONLY BLOCK.' );

			$matrix[ $label ] = $present;

			$this->assertSame( $expected, $present, $label . ': wrong injection outcome.' );
		}

		$lines = array();
		foreach ( $matrix as $label => $present ) {
			$lines[] = sprintf( '  %-44s %s', $label, $present ? 'INJECTED' : 'clean' );
		}

		fwrite( STDERR, "\n[p5 item 4] audience-isolation matrix:\n" . implode( "\n", $lines ) . "\n" );
	}

	/**
	 * 5. PREVIEW SAFETY: a preview render writes ZERO records, leaves the ledger
	 *    empty, and does not stop a real send later in the same request.
	 *
	 * Preview safety is STRUCTURAL (ADR-0013 §5): a preview gets a frame and NO
	 * slot, so there is nothing to register into and nothing to finalize.
	 *
	 * @return void
	 */
	public function test_a_preview_writes_no_records_and_does_not_suppress_a_later_send() {
		$product_id = $this->make_simple_product( 'WCEP Preview' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_insert_rule( $product_id, array( 'content' => '<p>PREVIEW BLOCK.</p>' ) );

		// --- A preview render, using WooCommerce's own signal. ----------------
		$preview = '__return_true';
		add_filter( 'woocommerce_is_email_preview', $preview );

		try {
			$email = $this->native_email();
			$email->object = wc_get_order( $order_id );
			$email->get_content();
		} finally {
			remove_filter( 'woocommerce_is_email_preview', $preview );
		}

		$this->assertMailCount( 0, 'A preview render sent a message.' );
		$this->assertSame( array(), RenderEvents::ledger()->slots(), 'A preview created a delivery slot.' );
		$this->assertSame( array(), $this->tombstones_for( $order_id ), 'A preview wrote a delivery record.' );

		// --- A REAL send afterwards, same request, no intervention. ----------
		$mail = $this->send_native( $order_id );

		$this->assertMailCount( 1 );
		$this->assertBody( $mail, 'PREVIEW BLOCK.', true );

		$tombstone = $this->insert_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone, 'The real send after a preview recorded nothing.' );
		$this->assertSame( 'sent', $tombstone['final_status'] );

		fwrite( STDERR, "\n[p5 item 5] preview: 0 slots, 0 records; the following real send recorded normally\n" );
	}

	/**
	 * 6. AN INTERRUPTED PREVIEW does not suppress a later real send.
	 *
	 * ⚠ `EmailPreview::render_preview_email()` has no `try`/`finally`, so a
	 * preview that throws leaves `woocommerce_is_email_preview` returning true
	 * for the rest of the request. Reconciliation must notice and demote the
	 * signal, or the next real send classifies itself as a preview and silently
	 * records nothing.
	 *
	 * @return void
	 */
	public function test_an_interrupted_preview_does_not_suppress_a_later_send() {
		$product_id = $this->make_simple_product( 'WCEP Interrupted Preview' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_insert_rule( $product_id, array( 'content' => '<p>AFTER INTERRUPT BLOCK.</p>' ) );

		// The leaked signal: attached and NEVER removed, exactly as core leaves
		// it after an interrupted preview.
		$this->hook( 'woocommerce_is_email_preview', '__return_true', 10, 1 );

		$exploder = static function () {
			throw new \RuntimeException( 'preview render exploded' );
		};

		/*
		 * THROWN BETWEEN PUSH AND POP. `woocommerce_email_before_order_table`
		 * fires inside the order-table render, i.e. between
		 * `woocommerce_email_order_details` priority 5 and priority 15 — so the
		 * preview's frame is still open when the stack unwinds, which is exactly
		 * the residue reconciliation exists to find. A throw from a hook that
		 * fires AFTER priority 15 would leave no frame and prove nothing.
		 */
		add_action( 'woocommerce_email_before_order_table', $exploder, 1 );

		// ⚠ `wc_get_template_html()` calls `ob_start()` with no `try`/`finally`,
		// so an interrupted render leaves output buffers open — the behaviour
		// already recorded in docs/p2-backlog.md. Unwinding them here keeps the
		// assertion about this plugin rather than about that.
		$buffer_level = ob_get_level();

		try {
			$email         = $this->native_email();
			$email->object = wc_get_order( $order_id );
			$email->get_content();
			$this->fail( 'The preview fixture did not throw.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'preview render exploded', $error->getMessage() );
		} finally {
			remove_action( 'woocommerce_email_before_order_table', $exploder, 1 );

			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
		}

		// A REAL send now, with core's signal STILL leaking true.
		$mail = $this->send_native( $order_id );

		$this->assertTrue(
			RenderEvents::context()->signal_leaked(),
			'The leaked preview signal was not detected, so detection is still trusting it.'
		);

		$this->assertMailCount( 1 );
		$this->assertBody( $mail, 'AFTER INTERRUPT BLOCK.', true );

		$tombstone = $this->insert_tombstone( $order_id, $rule_id );
		$this->assertNotNull(
			$tombstone,
			'A real send after an interrupted preview was classified as a preview and recorded nothing.'
		);

		fwrite( STDERR, "\n[p5 item 6] interrupted preview: signal demoted, later real send recorded normally\n" );
	}

	/**
	 * 7. NESTED RENDER: outer and inner native emails through the same singleton.
	 *    Each finalizes against its OWN recorded order, and no stale slot remains.
	 *
	 * ⚠ This is the case `$email->object` cannot answer: WooCommerce does not
	 * restore it after the inner render, so both sends report the INNER order.
	 * The order always comes from the slot recorded at push.
	 *
	 * @return void
	 */
	public function test_a_nested_render_finalizes_against_its_own_order() {
		$outer_product = $this->make_simple_product( 'WCEP Nested Outer' );
		$inner_product = $this->make_simple_product( 'WCEP Nested Inner' );

		$outer_order = $this->make_order_with( array( $outer_product ) );
		$outer_id    = (int) $outer_order->get_id();

		$inner_order = $this->make_order_with( array( $inner_product ) );
		$inner_id    = (int) $inner_order->get_id();

		$outer_rule = $this->make_insert_rule( $outer_product, array( 'content' => '<p>OUTER NESTED BLOCK.</p>' ) );
		$inner_rule = $this->make_insert_rule( $inner_product, array( 'content' => '<p>INNER NESTED BLOCK.</p>' ) );

		$fired = false;

		// A third party rendering ANOTHER order's email from inside the outer
		// send, through the SAME shared email object.
		$this->hook(
			'woocommerce_mail_content',
			function ( $message ) use ( &$fired, $inner_id ) {
				if ( ! $fired ) {
					$fired = true;
					$this->native_email()->trigger( $inner_id );
				}
				return $message;
			},
			1,
			1
		);

		$this->native_email()->trigger( $outer_id );

		$this->assertTrue( $fired, 'The nested render never happened.' );
		$this->assertMailCount( 2 );

		$outer_tombstone = $this->insert_tombstone( $outer_id, $outer_rule );
		$inner_tombstone = $this->insert_tombstone( $inner_id, $inner_rule );

		$this->assertNotNull( $outer_tombstone, 'The OUTER render recorded nothing — it finalized against the inner order.' );
		$this->assertNotNull( $inner_tombstone, 'The inner render recorded nothing.' );

		$this->assertSame( $outer_id, (int) $outer_tombstone['order_id'] );
		$this->assertSame( $inner_id, (int) $inner_tombstone['order_id'] );

		// Neither render's content leaked into the other's message.
		list( $inner_mail, $outer_mail ) = $this->captured_mail;
		$this->assertBody( $inner_mail, 'INNER NESTED BLOCK.', true );
		$this->assertBody( $inner_mail, 'OUTER NESTED BLOCK.', false );
		$this->assertBody( $outer_mail, 'OUTER NESTED BLOCK.', true );

		// ZERO STALE SLOTS.
		$this->assertSame( array(), RenderEvents::ledger()->slots(), 'A slot survived the nested pair.' );

		$trace = array();
		foreach ( RenderEvents::ledger()->finalizations() as $entry ) {
			$trace[] = sprintf(
				'  bound=%s resolved=%s recorded_order=%s',
				$entry['bound'],
				(string) $entry['resolved'],
				(string) $entry['order_id']
			);
		}

		fwrite(
			STDERR,
			"\n[p5 item 7] nested render — outer order {$outer_id}, inner order {$inner_id}:\n"
			. implode( "\n", $trace ) . "\n"
		);
	}

	/**
	 * 8. RESEND: a deliberate native-email resend inserts the content AGAIN and
	 *    increments the count — ADR-0004's stated behaviour, not a duplicate bug.
	 *
	 * @return void
	 */
	public function test_a_resend_inserts_again_and_increments_the_count() {
		$product_id = $this->make_simple_product( 'WCEP Resend' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_insert_rule( $product_id, array( 'content' => '<p>RESEND BLOCK.</p>' ) );

		$this->send_native( $order_id );
		$this->send_native( $order_id );

		$this->assertMailCount( 2, 'The resend did not produce a second native email.' );

		foreach ( $this->captured_mail as $index => $mail ) {
			$this->assertBody( $mail, 'RESEND BLOCK.', true, 'Message ' . ( $index + 1 ) . ' lost the inserted block.' );
		}

		$tombstone = $this->insert_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone );

		$this->assertSame( 1, (int) $tombstone['suppressed_count'], 'The repeat was not counted.' );
		$this->assertSame( 'sent', $tombstone['final_status'] );

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 2, $rows, 'A resend must write a second attempt row (ADR-0004).' );

		$states = array();
		foreach ( $rows as $row ) {
			$states[] = $row['state'] . '/' . $row['type'];
		}
		sort( $states );

		$this->assertSame( array( 'sent/auto', 'sent/resend' ), $states );

		fwrite(
			STDERR,
			"\n[p5 item 8] resend: 2 messages, 2 attempt rows, suppressed_count="
			. (int) $tombstone['suppressed_count'] . "\n"
		);
	}

	/**
	 * 9. IDEMPOTENCY WITHIN ONE RENDER: a rule matching two line items inserts
	 *    ONCE at a non-per-item position (ADR-0004, ADR-0013 §1).
	 *
	 * @return void
	 */
	public function test_a_rule_matching_two_items_inserts_once() {
		$first  = $this->make_simple_product( 'WCEP Once A' );
		$second = $this->make_simple_product( 'WCEP Once B' );

		$order    = $this->make_order_with( array( $first, $second ) );
		$order_id = (int) $order->get_id();

		$rule_id = $this->make_insert_rule(
			$first,
			array(
				'targeting' => array( 'include' => array( 'products' => array( $first, $second ) ) ),
				'content'   => '<p>ONCE ONLY BLOCK.</p>',
			)
		);

		$mail = $this->send_native( $order_id );

		$this->assertSame(
			1,
			substr_count( (string) $mail['message'], 'ONCE ONLY BLOCK.' ),
			'A rule matching two line items rendered twice at a non-per-item position.'
		);

		$tombstone = $this->insert_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone );
		$this->assertCount( 1, $this->detail_rows( (int) $tombstone['id'] ), 'One render must write one record.' );
	}

	/**
	 * 10. ORDERING AND THE STOP FLAG across several insert rules (ADR-0013 §7).
	 *
	 * @return void
	 */
	public function test_ordering_and_the_stop_flag() {
		$product_id = $this->make_simple_product( 'WCEP Ordering' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$first  = $this->make_insert_rule( $product_id, array( 'name' => 'first', 'priority' => 10, 'content' => '<p>ALPHA</p>' ) );
		$second = $this->make_insert_rule( $product_id, array( 'name' => 'second', 'priority' => 20, 'content' => '<p>BETA</p>', 'stop_processing' => 1 ) );
		$third  = $this->make_insert_rule( $product_id, array( 'name' => 'third', 'priority' => 30, 'content' => '<p>GAMMA</p>' ) );

		$mail = $this->send_native( $order_id );
		$body = (string) $mail['message'];

		$this->assertStringContainsString( 'ALPHA', $body );
		$this->assertStringContainsString( 'BETA', $body );
		$this->assertStringNotContainsString( 'GAMMA', $body, 'stop_processing did not halt the phase.' );

		// ORDER: priority ascending.
		$this->assertLessThan(
			strpos( $body, 'BETA' ),
			strpos( $body, 'ALPHA' ),
			'Insert rules rendered out of ADR-0011 order.'
		);

		$this->assertNotNull( $this->insert_tombstone( $order_id, $first ) );
		$this->assertNotNull( $this->insert_tombstone( $order_id, $second ) );
		$this->assertNull(
			$this->insert_tombstone( $order_id, $third ),
			'A rule halted by stop_processing consumed an identity.'
		);
	}

	/**
	 * 5C-2 / gate 15. AN INSERT RULE CARRYING UNIMPLEMENTED CONSOLIDATION IS LEFT
	 *                ENTIRELY UNTOUCHED (ADR-0013 §8a).
	 *
	 * ⚠ A LIVE DEFECT, NOT A GAP. Prompt 5B gave `consolidation` validated storage
	 * without giving either phase a filter for it, so a merchant could store `daily`
	 * and the rule was inserted into EVERY matching email immediately — which is
	 * `none`'s behaviour under another name. Out of scope silently meant "handled by
	 * whatever path exists", which is the Prompt 4 defect repeating.
	 *
	 * @dataProvider unsupported_consolidation_provider
	 *
	 * @param string $consolidation Stored consolidation value.
	 * @return void
	 */
	public function test_an_insert_rule_with_unsupported_consolidation_is_left_untouched( string $consolidation ) {
		$product_id = $this->make_simple_product( 'WCEP Consolidation ' . $consolidation );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_insert_rule(
			$product_id,
			array(
				'consolidation' => $consolidation,
				'content'       => '<p>CONSOLIDATED BLOCK.</p>',
			)
		);

		$this->assertGreaterThan( 0, $rule_id, 'The fixture rule was not storable, so the test proves nothing.' );

		$mail = $this->send_native( $order_id );

		// --- NOTHING OF OURS WENT INTO THE MESSAGE. ---------------------------
		$this->assertBody( $mail, 'CONSOLIDATED BLOCK.', false, 'An unsupported consolidation rule was inserted.' );

		// --- NO SLOT AND NO RENDER RECORD CARRIED IT. -------------------------
		foreach ( RenderEvents::ledger()->slots() as $slot ) {
			$this->assertSame( array(), $slot['rules'], 'An unsupported consolidation rule reached a ledger slot.' );
		}

		foreach ( RenderEvents::context()->renders() as $render ) {
			$this->assertSame( array(), $render['rules'], 'An unsupported consolidation rule reached a render record.' );
		}

		// --- AND NO AUDIT AT ALL, UNDER EITHER MODE. --------------------------
		$this->run_shutdown_sweep();

		$this->assertNull( $this->insert_tombstone( $order_id, $rule_id ), 'It consumed an insert identity.' );
		$this->assertSame( array(), $this->tombstones_for( $order_id ), 'It wrote a delivery record.' );

		fwrite(
			STDERR,
			"\n[5C item 2 / gate 15] insert + consolidation={$consolidation}: 0 insertions, 0 slots carrying rules,"
			. " 0 render records carrying rules, 0 tombstones\n"
		);
	}

	/**
	 * Consolidation values whose behaviour no phase implements.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function unsupported_consolidation_provider(): array {
		return array(
			'daily'     => array( 'daily' ),
			'weekly'    => array( 'weekly' ),
			'per_order' => array( 'per_order' ),
		);
	}

	/**
	 * 5C-2 / gate 15. AN UNSUPPORTED-CONSOLIDATION RULE CANNOT HALT A SUPPORTED
	 *                ONE, because it is filtered BEFORE evaluation.
	 *
	 * A filter applied after evaluation could not undo a halt that had already
	 * changed every later decision — the same ordering argument ADR-0012 §9 makes
	 * for insert-mode rules reaching the separate phase.
	 *
	 * @return void
	 */
	public function test_an_unsupported_consolidation_rule_does_not_halt_a_supported_one() {
		$product_id = $this->make_simple_product( 'WCEP Consolidation Halt' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		// Lower priority, so it would be evaluated FIRST and halt everything after.
		$halter = $this->make_insert_rule(
			$product_id,
			array(
				'name'            => 'daily rule that would halt',
				'priority'        => 1,
				'consolidation'   => 'daily',
				'stop_processing' => 1,
				'content'         => '<p>HALTER BLOCK.</p>',
			)
		);

		$supported = $this->make_insert_rule(
			$product_id,
			array(
				'name'     => 'supported rule that must still insert',
				'priority' => 10,
				'content'  => '<p>SUPPORTED BLOCK.</p>',
			)
		);

		$mail = $this->send_native( $order_id );

		$this->assertBody( $mail, 'HALTER BLOCK.', false, 'The unsupported rule inserted its content.' );
		$this->assertBody(
			$mail,
			'SUPPORTED BLOCK.',
			true,
			'An out-of-phase rule halted a supported one — the filter ran after evaluation.'
		);

		$this->assertNotNull( $this->insert_tombstone( $order_id, $supported ), 'The supported rule recorded nothing.' );
		$this->assertNull( $this->insert_tombstone( $order_id, $halter ), 'The unsupported rule consumed an identity.' );

		fwrite(
			STDERR,
			"\n[5C item 2 / gate 15] stop_processing on a consolidation=daily rule: supported rule still inserted and recorded;"
			. " the halter was never evaluated\n"
		);
	}

	/**
	 * 5C-2. `consolidation = none` still delivers normally in insert mode.
	 *
	 * The counterpart the filter tests need, or they would prove only that
	 * everything is filtered.
	 *
	 * @return void
	 */
	public function test_an_insert_rule_with_default_consolidation_still_delivers() {
		$product_id = $this->make_simple_product( 'WCEP Consolidation None' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_insert_rule(
			$product_id,
			array(
				'consolidation' => 'none',
				'content'       => '<p>DEFAULT CONSOLIDATION BLOCK.</p>',
			)
		);

		$mail = $this->send_native( $order_id );

		$this->assertBody( $mail, 'DEFAULT CONSOLIDATION BLOCK.', true, 'A supported rule stopped delivering.' );

		$tombstone = $this->insert_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone );
		$this->assertSame( 'sent', $tombstone['final_status'] );
	}

	/**
	 * 11. PHASE ISOLATION, BOTH DIRECTIONS (ADR-0012 §9, ADR-0013 §8).
	 *
	 * @return void
	 */
	public function test_phase_isolation_in_both_directions() {
		$product_id = $this->make_simple_product( 'WCEP Phase Isolation' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$separate_rule = $this->make_sending_rule(
			$product_id,
			array(
				'name'    => 'separate rule',
				'subject' => 'SEPARATE SUBJECT',
				'content' => '<p>SEPARATE BODY.</p>',
			)
		);

		$insert_rule = $this->make_insert_rule( $product_id, array( 'content' => '<p>INSERT BODY.</p>' ) );

		// --- A NATIVE render must not act on the separate rule. --------------
		$mail = $this->send_native( $order_id );

		$this->assertBody( $mail, 'INSERT BODY.', true );
		$this->assertBody( $mail, 'SEPARATE BODY.', false, 'A separate rule was injected into a native email.' );

		$this->assertNull(
			$this->deliveries->find( $order_id, $separate_rule, 'insert', 'native:customer_processing_order' ),
			'A separate rule claimed an INSERT identity.'
		);

		// --- A SEPARATE-MODE trigger must not act on the insert rule. --------
		$this->captured_mail = array();

		$this->orchestrator()->run( wc_get_order( $order_id ), \Extonify\WCEP\Domain\TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 1, 'The separate phase should have sent exactly its own email.' );
		$this->assertSame( 'SEPARATE SUBJECT', $this->last_mail()['subject'] );

		foreach ( array( 'separate', 'insert' ) as $mode ) {
			$this->assertNull(
				$this->deliveries->find( $order_id, $insert_rule, $mode, 'status:completed' ),
				'An insert rule was claimed by the separate phase under mode=' . $mode . '.'
			);
		}

		fwrite( STDERR, "\n[p5 item 11] phase isolation: native render ignored the separate rule; the separate trigger ignored the insert rule\n" );
	}

	/**
	 * 12. A THROWN SEND: the slot ends `unresolved` and is RECORDED
	 *     (ADR-0013 §6), and the event does not throw.
	 *
	 * @return void
	 */
	public function test_a_thrown_send_records_an_unresolved_insert() {
		$product_id = $this->make_simple_product( 'WCEP Unresolved' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_insert_rule( $product_id, array( 'content' => '<p>UNRESOLVED BLOCK.</p>' ) );

		$thrower = static function () {
			throw new \RuntimeException( 'SMTP plugin exploded mid-send' );
		};

		add_filter( 'pre_wp_mail', $thrower, 0 );

		try {
			// WooCommerce's own email has no `\Throwable` containment, so the
			// throw escapes `trigger()` — which is the case ADR-0012 §11e
			// describes: `woocommerce_email_sent` never fires.
			try {
				$this->native_email()->trigger( $order_id );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'SMTP plugin exploded mid-send', $error->getMessage() );
			}
		} finally {
			remove_filter( 'pre_wp_mail', $thrower, 0 );
		}

		$this->assertMailCount( 0 );

		// Nothing is recorded until the sweep — the outcome is genuinely unknown
		// while the request is still running.
		$this->assertNull( $this->insert_tombstone( $order_id, $rule_id ) );

		$this->run_shutdown_sweep();

		$tombstone = $this->insert_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone, 'Rendered content vanished from the log entirely.' );
		$this->assertSame( 'unresolved', $tombstone['final_status'] );

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'unresolved', $rows[0]['state'] );
		$this->assertNotSame( 'failed', $rows[0]['state'], 'An unknown outcome was asserted as a failure.' );
		$this->assertStringContainsString( 'never reported', (string) $rows[0]['reason'] );

		fwrite(
			STDERR,
			"\n[p5 item 12] thrown send: final_status=" . $tombstone['final_status']
			. ", detail state=" . $rows[0]['state'] . "\n"
		);
	}

	/**
	 * Gate 6. PER-RENDER QUERY COST: one evaluation per render, not one per
	 * position, and not one per rule times item (ADR-0013 §3).
	 *
	 * @return void
	 */
	public function test_per_render_query_cost_does_not_scale_with_positions_or_rules() {
		global $wpdb;

		$products = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$products[] = $this->make_simple_product( 'WCEP Render Cost ' . $i );
		}

		// Targeted by every NOISE rule and present on no order, so those rules
		// are fetched and evaluated in full and none of them inserts. That is
		// what isolates EVALUATION cost from RECORDING cost: recording is one
		// claim plus one row per rule that actually inserted, which is linear in
		// inserting rules by design and is not what this gate is about.
		$absent = $this->make_simple_product( 'WCEP Render Cost Absent' );

		$order    = $this->make_order_with( $products );
		$order_id = (int) $order->get_id();

		$positions = array_keys( Injector::POSITIONS );

		$measure = function ( int $order_id ) use ( $wpdb ): int {
			$this->captured_mail = array();
			wp_cache_flush();

			$before = $wpdb->num_queries;
			$this->send_native( $order_id );

			return $wpdb->num_queries - $before;
		};

		// ONE inserting rule, ONE position.
		$this->make_insert_rule(
			$products[0],
			array(
				'name'            => 'the one that inserts',
				'priority'        => 1,
				'insert_position' => $positions[0],
				'content'         => '<p>COST BLOCK.</p>',
			)
		);

		$one_position = $measure( $order_id );

		// FOUR MORE POSITIONS, each with a rule that inserts. If the cost scaled
		// with positions, evaluation would run five times instead of once.
		foreach ( array_slice( $positions, 1 ) as $index => $position ) {
			$this->make_insert_rule(
				$products[0],
				array(
					'name'            => 'position ' . $position,
					'priority'        => 2 + $index,
					'insert_position' => $position,
					'content'         => '<p>COST BLOCK ' . $position . '.</p>',
				)
			);
		}

		$five_positions = $measure( $order_id );

		// TWENTY MORE CANDIDATE RULES that match nothing.
		for ( $i = 0; $i < 20; $i++ ) {
			$this->make_insert_rule(
				$absent,
				array(
					'name'            => 'noise ' . $i,
					'priority'        => 50 + $i,
					'insert_position' => $positions[ $i % 5 ],
					'content'         => '<p>NOISE-' . $i . '</p>',
				)
			);
		}

		$with_noise = $measure( $order_id );

		$this->assertMailCount( 1 );
		$this->tombstones_for( $order_id );

		/*
		 * THE BOUND IS STATED AS A BOUND, NOT AN EQUALITY. A whole native render
		 * runs WooCommerce's own templates, options and transients, so its
		 * absolute query count carries a few queries of variance between runs
		 * that has nothing to do with this plugin. What the gate actually claims
		 * is proven exactly, twice, below: evaluation happens ONCE per render
		 * whatever the positions, and its cost does not grow with the candidate
		 * set.
		 */
		$this->assertLessThan(
			125,
			$with_noise,
			"A render against 25 candidate rules on a 5-item order took {$with_noise} queries; rules x items would be 125."
		);

		$this->assertLessThan(
			$one_position + ( 5 * 20 ),
			$with_noise,
			'The render cost grew with the number of injection positions.'
		);

		fwrite(
			STDERR,
			"\n[gate 6] whole-render query cost, 5-item order:"
			. " 1 position/1 inserting rule = {$one_position};"
			. " 5 positions/5 inserting rules = {$five_positions};"
			. " same 5 plus 20 non-matching candidates = {$with_noise}.\n"
		);
	}

	/**
	 * Gate 6, exactly. EVALUATION HAPPENS ONCE PER RENDER (ADR-0013 §3), however
	 * many positions emit.
	 *
	 * Counted directly, because this is the claim — the whole-render query count
	 * above is an observation around it, and carries WooCommerce's own variance.
	 *
	 * @return void
	 */
	public function test_the_matcher_runs_once_per_render_whatever_the_positions() {
		$product_id = $this->make_simple_product( 'WCEP Eval Once' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		foreach ( array_keys( Injector::POSITIONS ) as $index => $position ) {
			$this->make_insert_rule(
				$product_id,
				array(
					'name'            => 'eval ' . $position,
					'priority'        => 10 + $index,
					'insert_position' => $position,
					'content'         => '<p>EVAL-' . strtoupper( $position ) . '</p>',
				)
			);
		}

		$counter = new class( $this->rules, new \Extonify\WCEP\Matching\ItemResolver(), new \Extonify\WCEP\Delivery\DeliveryLogger( $this->deliveries, $this->details ) ) extends \Extonify\WCEP\Delivery\InsertPhase {
			/**
			 * Evaluations performed.
			 *
			 * @var int
			 */
			public $evaluations = 0;

			/**
			 * Count, then delegate.
			 *
			 * @param string    $native_email_id Email id.
			 * @param \WC_Order $order           Order.
			 * @return array[]
			 */
			public function evaluate( string $native_email_id, \WC_Order $order ): array {
				++$this->evaluations;

				return parent::evaluate( $native_email_id, $order );
			}
		};

		RenderEvents::set_collaborators(
			new \Extonify\WCEP\Render\RenderContext(),
			new \Extonify\WCEP\Render\RenderLedger(),
			$counter
		);

		$mail = $this->send_native( $order_id );

		// All five positions really did emit, so "once" is not "never".
		foreach ( array_keys( Injector::POSITIONS ) as $position ) {
			$this->assertBody( $mail, 'EVAL-' . strtoupper( $position ), true );
		}

		$this->assertSame(
			1,
			$counter->evaluations,
			'The matcher ran ' . $counter->evaluations . ' times for one render; ADR-0013 §3 requires exactly one.'
		);

		$this->tombstones_for( $order_id );

		fwrite(
			STDERR,
			"\n[gate 6] five positions emitted from ONE evaluation: {$counter->evaluations} matcher run per render.\n"
		);
	}

	/**
	 * Gate 6. Evaluation cost does not grow with the candidate rule set.
	 *
	 * @return void
	 */
	public function test_evaluation_cost_does_not_grow_with_the_candidate_set() {
		global $wpdb;

		$product_id = $this->make_simple_product( 'WCEP Eval Cost' );
		$absent     = $this->make_simple_product( 'WCEP Eval Cost Absent' );
		$order      = $this->make_order_with( array( $product_id ) );

		$this->make_insert_rule( $product_id, array( 'name' => 'matches' ) );

		$measure = function () use ( $order, $wpdb ): int {
			// A FRESH RESOLVER AND A COLD OBJECT CACHE EACH TIME, so both
			// measurements pay the same honest price and the only difference
			// between them is the size of the candidate set.
			$cold = new \Extonify\WCEP\Delivery\InsertPhase(
				$this->rules,
				new \Extonify\WCEP\Matching\ItemResolver(),
				new \Extonify\WCEP\Delivery\DeliveryLogger( $this->deliveries, $this->details )
			);

			wp_cache_flush();

			$before = $wpdb->num_queries;
			$cold->evaluate( 'customer_processing_order', $order );

			return $wpdb->num_queries - $before;
		};

		$with_one = $measure();

		for ( $i = 0; $i < 24; $i++ ) {
			$this->make_insert_rule( $absent, array( 'name' => 'candidate ' . $i, 'priority' => 50 + $i ) );
		}

		$with_many = $measure();

		$this->assertSame(
			$with_one,
			$with_many,
			"Evaluating 25 candidate rules cost {$with_many} queries against {$with_one} for a single rule."
		);

		fwrite(
			STDERR,
			"\n[gate 6] evaluation cost: {$with_one} queries for 1 candidate rule, {$with_many} for 25"
			. " (one rule fetch plus the order's items, independent of the candidate set).\n"
		);
	}

	/**
	 * 5A-1. A REPORTED FAILURE IS RECORDED AS A FAILURE (ADR-0013 §1a).
	 *
	 * `woocommerce_email_sent` carries the mail callback's own return value. It
	 * used to be ignored and every finalized render was written `sent`, so a
	 * definite failure WooCommerce had explicitly reported became a delivery
	 * history claiming the content went out.
	 *
	 * @return void
	 */
	public function test_a_reported_send_failure_is_recorded_as_failed() {
		$product_id = $this->make_simple_product( 'WCEP Outcome' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_insert_rule( $product_id, array( 'content' => '<p>OUTCOME BLOCK.</p>' ) );

		// --- The mailer reports SUCCESS. -------------------------------------
		$this->send_native( $order_id );

		$sent_tombstone = $this->insert_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $sent_tombstone );
		$this->assertSame( 'sent', $sent_tombstone['final_status'] );

		$sent_rows = $this->detail_rows( (int) $sent_tombstone['id'] );
		$this->assertCount( 1, $sent_rows );
		$this->assertSame( 'sent', $sent_rows[0]['state'] );
		$this->assertSame( 'sent', $this->attempt_snapshot( $sent_rows[0] )['outcome'] );

		// --- A DIFFERENT order, whose send the mailer REJECTS. ----------------
		$failing_order = $this->make_order_with( array( $product_id ) );
		$failing_id    = (int) $failing_order->get_id();

		$this->make_sends_report_failure();

		$this->send_native( $failing_id );

		$this->assertMailCount( 2, 'The failing send never reached the mailer at all.' );

		$failed_tombstone = $this->insert_tombstone( $failing_id, $rule_id );
		$this->assertNotNull( $failed_tombstone, 'A failed send recorded nothing.' );

		$this->assertSame(
			'failed',
			$failed_tombstone['final_status'],
			'A send WooCommerce reported as FAILED was finalised as sent.'
		);

		$failed_rows = $this->detail_rows( (int) $failed_tombstone['id'] );
		$this->assertCount( 1, $failed_rows );
		$this->assertSame( 'failed', $failed_rows[0]['state'], 'The attempt row claimed a failed message was sent.' );
		$this->assertNotSame( 'unresolved', $failed_rows[0]['state'], 'A REPORTED failure was recorded as an unknown outcome.' );
		$this->assertSame( 'failed', $this->attempt_snapshot( $failed_rows[0] )['outcome'] );
		$this->assertStringContainsString( 'not sent', (string) $failed_rows[0]['reason'] );

		fwrite(
			STDERR,
			"\n[5A item 1] outcome matrix: mailer true -> attempt sent / tombstone "
			. $sent_tombstone['final_status'] . '; mailer false -> attempt '
			. $failed_rows[0]['state'] . ' / tombstone ' . $failed_tombstone['final_status'] . "\n"
		);
	}

	/**
	 * 5A-1 + 5A-5. FIRST SEND SUCCEEDS, RESEND FAILS: both attempt rows carry
	 * their own outcome AND their own rule revision, and the aggregate reflects
	 * the LATEST attempt.
	 *
	 * @return void
	 */
	public function test_a_successful_send_then_a_failed_resend_records_both() {
		$product_id = $this->make_simple_product( 'WCEP Revision Audit' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_insert_rule( $product_id, array( 'content' => '<p>REVISION ONE.</p>' ) );

		$this->assertSame( 1, (int) $this->rules->find( $rule_id )['revision'] );

		$this->send_native( $order_id );

		// The merchant edits the rule: the CONTENT that goes out next is a
		// different body, and the audit has to be able to say so.
		$this->assertTrue( $this->rules->update( $rule_id, array( 'content' => '<p>REVISION TWO.</p>' ) ) );
		$this->assertSame( 2, (int) $this->rules->find( $rule_id )['revision'] );

		$this->make_sends_report_failure();

		$resend = $this->send_native( $order_id );

		$this->assertBody( $resend, 'REVISION TWO.', true, 'The resend did not carry the edited body.' );

		$tombstone = $this->insert_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone );

		$this->assertSame( 1, (int) $tombstone['suppressed_count'] );
		$this->assertSame(
			'failed',
			$tombstone['final_status'],
			'final_status must describe the LATEST attempt (ADR-0013 §1a).'
		);
		$this->assertSame(
			2,
			(int) $tombstone['rule_revision_sent'],
			'The tombstone still advertised the revision of the FIRST attempt.'
		);

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 2, $rows );

		$first  = $this->attempt_snapshot( $rows[0] );
		$second = $this->attempt_snapshot( $rows[1] );

		$this->assertSame( 'auto', $rows[0]['type'] );
		$this->assertSame( 'sent', $rows[0]['state'] );
		$this->assertSame( 1, (int) $first['rule_revision'] );
		$this->assertSame( 'sent', $first['outcome'] );
		$this->assertSame( 'customer_processing_order', $first['native_email_id'] );
		$this->assertSame( 'after_order_table', $first['position'] );

		$this->assertSame( 'resend', $rows[1]['type'] );
		$this->assertSame( 'failed', $rows[1]['state'] );
		$this->assertSame( 2, (int) $second['rule_revision'] );
		$this->assertSame( 'failed', $second['outcome'] );

		fwrite(
			STDERR,
			"\n[5A items 1+5] attempt rows:\n"
			. sprintf( "  #%d %s/%s revision=%s\n", (int) $rows[0]['attempt'], $rows[0]['type'], $rows[0]['state'], $first['rule_revision'] )
			. sprintf( "  #%d %s/%s revision=%s\n", (int) $rows[1]['attempt'], $rows[1]['type'], $rows[1]['state'], $second['rule_revision'] )
			. '  tombstone final_status=' . $tombstone['final_status']
			. ' rule_revision_sent=' . (int) $tombstone['rule_revision_sent'] . "\n"
		);
	}

	/**
	 * 5A-5. AN UNRESOLVED RESEND IS TYPED `resend`.
	 *
	 * The repeat was computed and then thrown away: the row was written `auto`
	 * whatever it was, so the second attempt of a resent email was
	 * indistinguishable from the first.
	 *
	 * @return void
	 */
	public function test_an_unresolved_resend_is_labelled_a_resend() {
		$product_id = $this->make_simple_product( 'WCEP Unresolved Resend' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_insert_rule( $product_id, array( 'content' => '<p>UNRESOLVED RESEND BLOCK.</p>' ) );

		// A first, ordinary, successful send.
		$this->send_native( $order_id );

		// A second render for the same identity that never reaches a send.
		$email         = $this->native_email();
		$email->object = wc_get_order( $order_id );
		$email->get_content();

		$this->run_shutdown_sweep();

		$tombstone = $this->insert_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone );

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 2, $rows );

		$this->assertSame( 'auto', $rows[0]['type'] );
		$this->assertSame( 'sent', $rows[0]['state'] );

		$this->assertSame( 'resend', $rows[1]['type'], 'An abandoned REPEAT was labelled a first attempt.' );
		$this->assertSame( 2, (int) $rows[1]['attempt'] );

		/*
		 * 5B ITEM C. THE SECOND RENDER NEVER REACHED A SEND, so it is `abandoned`
		 * rather than `unresolved` — and the tombstone KEEPS the `sent` status of the
		 * attempt that really happened. Under the literal latest-attempt rule this
		 * tombstone read `unresolved`, which told the merchant that an email they had
		 * successfully received was of unknown fate, because a third party rendered
		 * the same email again and threw it away (ADR-0013 §6a).
		 */
		$this->assertSame( 'abandoned', $rows[1]['state'] );
		$this->assertSame( 'abandoned', $this->attempt_snapshot( $rows[1] )['outcome'] );
		$this->assertSame(
			'sent',
			$tombstone['final_status'],
			'An abandoned render downgraded a delivery that succeeded.'
		);

		fwrite(
			STDERR,
			"\n[5B item 3] abandoned re-render: attempt #2 type=" . $rows[1]['type']
			. ' state=' . $rows[1]['state']
			. '; tombstone final_status=' . $tombstone['final_status'] . " (kept)\n"
		);
	}

	/**
	 * 5A-2. RENDER-ONLY NESTING CANNOT STEAL A SEND (ADR-0013 §5).
	 *
	 * A third party calls `get_content()` on the SAME email object for ANOTHER
	 * order from inside `woocommerce_mail_content`, and never sends it. Under the
	 * rejected "most recent `awaiting_send`" selection that inner render became
	 * the newest eligible slot and the outer send finalized IT — wrong order,
	 * wrong rules, and the real delivery reported unresolved.
	 *
	 * The existing nested test cannot catch this: it calls `trigger()`, which
	 * sends and resolves immediately.
	 *
	 * @return void
	 */
	public function test_a_render_only_nested_call_cannot_steal_the_outer_send() {
		$outer_product = $this->make_simple_product( 'WCEP Steal Outer' );
		$inner_product = $this->make_simple_product( 'WCEP Steal Inner' );

		$outer_order = $this->make_order_with( array( $outer_product ) );
		$outer_id    = (int) $outer_order->get_id();

		$inner_order = $this->make_order_with( array( $inner_product ) );
		$inner_id    = (int) $inner_order->get_id();

		$outer_rule = $this->make_insert_rule( $outer_product, array( 'content' => '<p>OUTER REAL BLOCK.</p>' ) );
		$inner_rule = $this->make_insert_rule( $inner_product, array( 'content' => '<p>INNER RENDER-ONLY BLOCK.</p>' ) );

		$fired = false;

		$this->hook(
			'woocommerce_mail_content',
			function ( $message ) use ( &$fired, $inner_id ) {
				if ( ! $fired ) {
					$fired = true;

					// RENDERS AND NEVER SENDS, through the SAME shared object.
					$email         = $this->native_email();
					$email->object = wc_get_order( $inner_id );
					$email->get_content();
				}

				return $message;
			},
			1,
			1
		);

		$this->native_email()->trigger( $outer_id );

		$this->assertTrue( $fired, 'The render-only nesting never happened.' );
		$this->assertMailCount( 1, 'A render-only nested call produced a message.' );

		// --- The OUTER send finalized the OUTER slot. ------------------------
		$finalizations = RenderEvents::ledger()->finalizations();
		$this->assertCount( 1, $finalizations );
		$this->assertSame( $finalizations[0]['bound'], $finalizations[0]['resolved'] );
		$this->assertSame( $outer_id, (int) $finalizations[0]['order_id'], 'The outer send finalized the INNER render.' );

		$outer_tombstone = $this->insert_tombstone( $outer_id, $outer_rule );
		$this->assertNotNull( $outer_tombstone, 'The outer send recorded nothing — its slot was consumed by the inner render.' );
		$this->assertSame( $outer_id, (int) $outer_tombstone['order_id'] );
		$this->assertSame( 'sent', $outer_tombstone['final_status'] );

		// --- Neither order was attributed to the other. ----------------------
		$this->assertNull(
			$this->insert_tombstone( $inner_id, $outer_rule ),
			'The outer rule was recorded against the inner order.'
		);
		$this->assertNull(
			$this->insert_tombstone( $outer_id, $inner_rule ),
			'The inner rule was recorded against the outer order.'
		);

		// --- The inner render is still open, and never reached a send. --------
		$open = RenderEvents::ledger()->slots();
		$this->assertCount( 1, $open, 'The inner render-only slot did not survive as an open slot.' );
		$this->assertSame( $inner_id, (int) $open[0]['order_id'] );
		$this->assertSame( 'awaiting_send', $open[0]['state'] );

		$inner_token = $open[0]['token'];

		$this->run_shutdown_sweep();

		// ADR-0013 §6a: no send was attempted for this render, so the record says
		// exactly that rather than claiming an unknown delivery outcome.
		$inner_tombstone = $this->insert_tombstone( $inner_id, $inner_rule );
		$this->assertNotNull( $inner_tombstone, 'The render-only slot vanished from the log.' );
		$this->assertSame( 'abandoned', $inner_tombstone['final_status'] );

		$inner_rows = $this->detail_rows( (int) $inner_tombstone['id'] );
		$this->assertCount( 1, $inner_rows );
		$this->assertSame( 'abandoned', $inner_rows[0]['state'] );

		$reservations = RenderEvents::ledger()->reservation_log();
		$this->assertCount( 1, $reservations, 'The send took more or fewer than one reservation.' );
		$this->assertSame( $finalizations[0]['resolved'], $reservations[0]['token'] );
		$this->assertTrue( $reservations[0]['handoff'], 'The send reserved without an exact handoff.' );

		fwrite(
			STDERR,
			"\n[5A item 2] render-only nesting trace, outer order {$outer_id} / inner order {$inner_id}:\n"
			. sprintf( "  reserved   %s (exact handoff, at mail_content)\n", (string) $reservations[0]['token'] )
			. sprintf( "  bound      %s (at mail_callback_params)\n", (string) $finalizations[0]['bound'] )
			. sprintf( "  finalized  %s -> order %d\n", (string) $finalizations[0]['resolved'], (int) $finalizations[0]['order_id'] )
			. sprintf( "  abandoned  %s -> order %d (inner, never sent)\n", $inner_token, $inner_id )
		);
	}

	/**
	 * 5C-3. AN ABANDONED RENDER MUST NOT MAKE THE FIRST REAL SEND A RESEND
	 *       (ADR-0013 §6b).
	 *
	 * ⚠ THE POLICY WAS HALF-APPLIED. ADR-0013 §6a established that an abandoned
	 * render is not a delivery attempt, and protected `final_status` accordingly —
	 * but the attempt TYPE was still derived from the claim result. An abandoned
	 * render claims the identity and creates the tombstone, so WooCommerce's first
	 * genuine send found the claim suppressed and was written `resend`: the
	 * merchant's FIRST delivery recorded as a repeat of a message that had never
	 * gone out.
	 *
	 * @return void
	 */
	public function test_an_abandoned_render_does_not_make_the_first_real_send_a_resend() {
		$product_id = $this->make_simple_product( 'WCEP Abandoned First' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_insert_rule( $product_id, array( 'content' => '<p>FIRST SEND BLOCK.</p>' ) );

		// A third party renders and throws the render away, BEFORE any real send.
		$email         = $this->native_email();
		$email->object = wc_get_order( $order_id );
		$email->get_content();

		$this->run_shutdown_sweep();

		$after_abandon = $this->insert_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $after_abandon, 'The abandoned render was not recorded at all.' );
		$this->assertSame( 'abandoned', $after_abandon['final_status'] );

		// Now WooCommerce sends the email for the FIRST time.
		$mail = $this->send_native( $order_id );
		$this->assertBody( $mail, 'FIRST SEND BLOCK.', true );

		$tombstone = $this->insert_tombstone( $order_id, $rule_id );
		$rows      = $this->detail_rows( (int) $tombstone['id'] );

		$this->assertCount( 2, $rows );

		$this->assertSame( 'auto', $rows[0]['type'] );
		$this->assertSame( 'abandoned', $rows[0]['state'] );

		$this->assertSame(
			'auto',
			$rows[1]['type'],
			'The FIRST genuine delivery was recorded as a resend of a message that never went out.'
		);
		$this->assertSame( 'sent', $rows[1]['state'] );
		$this->assertSame( 2, (int) $rows[1]['attempt'], 'The abandoned row lost its place in the sequence.' );
		$this->assertSame( 'sent', $tombstone['final_status'] );

		// The reason no longer offers a second, weaker answer to "is this a repeat".
		$this->assertStringNotContainsString( 're-inserted', (string) $rows[1]['reason'] );

		fwrite(
			STDERR,
			sprintf(
				"\n[5C item 3] abandoned-then-first-send: #%d %s/%s, #%d %s/%s; tombstone=%s\n",
				(int) $rows[0]['attempt'],
				$rows[0]['type'],
				$rows[0]['state'],
				(int) $rows[1]['attempt'],
				$rows[1]['type'],
				$rows[1]['state'],
				$tombstone['final_status']
			)
		);
	}

	/**
	 * 5C-3. TWO REAL SENDS ARE STILL `auto` THEN `resend` — unchanged.
	 *
	 * @return void
	 */
	public function test_a_second_real_send_is_still_a_resend() {
		$product_id = $this->make_simple_product( 'WCEP Real Resend' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_insert_rule( $product_id, array( 'content' => '<p>REAL RESEND BLOCK.</p>' ) );

		$this->send_native( $order_id );
		$this->send_native( $order_id );

		$tombstone = $this->insert_tombstone( $order_id, $rule_id );
		$rows      = $this->detail_rows( (int) $tombstone['id'] );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'auto', $rows[0]['type'] );
		$this->assertSame( 'sent', $rows[0]['state'] );
		$this->assertSame( 'resend', $rows[1]['type'], 'A genuine second delivery stopped being a resend.' );
		$this->assertSame( 'sent', $rows[1]['state'] );

		fwrite(
			STDERR,
			"\n[5C item 3] two real sends: #1 {$rows[0]['type']}/{$rows[0]['state']}, #2 {$rows[1]['type']}/{$rows[1]['state']}\n"
		);
	}

	/**
	 * 5C-3. ABANDONED, REAL, ABANDONED, REAL — the SECOND real send is the resend.
	 *
	 * The interleaved case: abandoned rows keep their sequential attempt numbers
	 * and stay visible, but only genuine deliveries decide the type.
	 *
	 * @return void
	 */
	public function test_only_genuine_attempts_decide_the_resend_typing() {
		$product_id = $this->make_simple_product( 'WCEP Interleaved' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_insert_rule( $product_id, array( 'content' => '<p>INTERLEAVED BLOCK.</p>' ) );

		$abandon = function () use ( $order_id ) {
			$email         = $this->native_email();
			$email->object = wc_get_order( $order_id );
			$email->get_content();
			$this->run_shutdown_sweep();
		};

		$abandon();
		$this->send_native( $order_id );
		$abandon();
		$this->send_native( $order_id );

		$tombstone = $this->insert_tombstone( $order_id, $rule_id );
		$rows      = $this->detail_rows( (int) $tombstone['id'] );

		$this->assertCount( 4, $rows );

		$actual = array();
		foreach ( $rows as $row ) {
			$actual[] = (int) $row['attempt'] . ':' . $row['type'] . '/' . $row['state'];
		}

		/*
		 * THE TWO COLUMNS ANSWER DIFFERENT QUESTIONS, and row 3 is where that shows.
		 * `type` answers "has this identity delivered before?" — by attempt 3 it has
		 * (attempt 2 sent), so a further render of it is a REPEAT, whatever became of
		 * that render. `state` answers "what happened this time?" — that render was
		 * thrown away, so `abandoned`. `resend/abandoned` is therefore the truthful
		 * pair, and the defect this test exists for is the opposite one: attempt 2,
		 * the FIRST genuine delivery, must be `auto` even though attempt 1 had
		 * already claimed the identity.
		 */
		$this->assertSame(
			array( '1:auto/abandoned', '2:auto/sent', '3:resend/abandoned', '4:resend/sent' ),
			$actual,
			'Attempt typing did not follow genuine delivery history.'
		);
		$this->assertSame( 'sent', $tombstone['final_status'] );

		fwrite( STDERR, "\n[5C item 3] interleaved: " . implode( '  ', $actual ) . "\n" );
	}

	/**
	 * 5C-1. POST-FRAME NESTING CANNOT DISPLACE THE RENDER THAT ENCLOSES IT
	 *       (ADR-0013 §5e).
	 *
	 * ⚠ THE CASE THAT BROKE THE PUBLISH-AT-PRIORITY-15 RULE. Prompt 5B published a
	 * render's token at `order_details:15` and took the stack top at send time, on
	 * the reasoning that an enclosing render always finishes last. That holds only
	 * for nesting sites INSIDE the frame window. This plugin injects at two
	 * positions that fire AFTER priority 15, and a third party rendering from one of
	 * them published SECOND:
	 *
	 *     outer publishes token 1 at order_details:15
	 *     outer reaches order_meta -> third party renders -> token 2 lands on top
	 *     outer's send takes the top: token 2, the INNER render
	 *
	 * The outer message then finalised the inner slot and the genuine outer slot was
	 * swept as never sent. Promotion at each render's own terminal position fixes the
	 * order at its source.
	 *
	 * @return void
	 */
	public function test_a_render_nested_from_order_meta_cannot_displace_the_outer_send() {
		$this->assert_post_frame_nesting_keeps_the_outer_send( 'woocommerce_email_order_meta', false );
	}

	/**
	 * 5C-1. The same, nested from the TERMINAL position itself.
	 *
	 * Sharper than the `order_meta` case: the third party renders from inside the
	 * very hook on which this plugin promotes. Our callback sits at priority 999, so
	 * the nested render has completed and promoted itself before the enclosing
	 * render promotes — which is what puts the enclosing render back at the tail.
	 *
	 * @return void
	 */
	public function test_a_render_nested_from_customer_details_cannot_displace_the_outer_send() {
		$this->assert_post_frame_nesting_keeps_the_outer_send( 'woocommerce_email_customer_details', false );
	}

	/**
	 * 5C-1. The `order_meta` case again, in PLAIN TEXT.
	 *
	 * ⚠ The terminal position differs by format for the BACKSTOP but not for the
	 * mechanism: verified across every WC 10.9.4 order-email template, plain-text
	 * templates fire `customer_details` and (with one exception) never fire
	 * `woocommerce_email_footer`. A promotion rule that relied on the footer would
	 * therefore never promote a plain-text render at all.
	 *
	 * @return void
	 */
	public function test_a_plain_text_render_nested_from_order_meta_cannot_displace_the_outer_send() {
		$this->assert_post_frame_nesting_keeps_the_outer_send( 'woocommerce_email_order_meta', true );
	}

	/**
	 * Drive one post-frame nesting case end to end.
	 *
	 * SAME singleton, SAME native email id, SAME order, SAME audience — so nothing
	 * about identity can separate the two renders and only the promotion order can.
	 *
	 * @param string $hook       Post-frame position to nest from.
	 * @param bool   $plain_text Render the plain-text template.
	 * @return void
	 */
	private function assert_post_frame_nesting_keeps_the_outer_send( string $hook, bool $plain_text ) {
		$label      = $plain_text ? 'plain' : 'html';
		$product_id = $this->make_simple_product( 'WCEP Post Frame ' . $hook . ' ' . $label );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_insert_rule(
			$product_id,
			array(
				'insert_position' => 'after_order_table',
				'content'         => '<p>POST FRAME NESTING BLOCK.</p>',
			)
		);

		$fired       = false;
		$inner_token = null;
		$queue       = array();

		$this->capture_candidates_at_send( $queue );

		/*
		 * PRIORITY 1: before this plugin's injector at 10 and before its promotion
		 * at 999, which is where a third party's callback would realistically sit.
		 */
		$this->hook(
			$hook,
			function ( $order_arg = null, $sent_to_admin = false, $is_plain = false, $email_arg = null ) use ( &$fired, &$inner_token, $order_id ) {
				if ( $fired ) {
					return;
				}

				$fired = true;

				// SAME singleton, SAME email id, SAME order, SAME audience.
				$nested         = $this->native_email();
				$nested->object = wc_get_order( $order_id );
				$nested->get_content();

				$slots = RenderEvents::ledger()->slots();
				$this->assertNotEmpty( $slots, 'The nested render opened no slot.' );
				$inner_token = end( $slots )['token'];
			},
			1,
			4
		);

		$mail = $this->send_native( $order_id, 'WC_Email_Customer_Processing_Order', $plain_text );

		$this->assertTrue( $fired, 'The post-frame nesting never happened.' );
		$this->assertMailCount( 1, 'The nested render produced a message of its own.' );
		$this->assertBody( $mail, 'POST FRAME NESTING BLOCK.', true, 'The outer render lost its insertion.' );

		// --- THE INVARIANT, OBSERVED AT THE HANDOFF. --------------------------
		$this->assertCount( 2, $queue, 'Both renders should have been promoted before the send.' );
		$this->assertSame( $inner_token, $queue[0], 'The inner render did not promote FIRST.' );

		$outer_token = $queue[1];
		$this->assertNotSame( $inner_token, $outer_token, 'The two renders shared a token.' );

		// --- AND THE SEND TOOK THE TAIL, WHICH IS THE OUTER RENDER. -----------
		$finalizations = RenderEvents::ledger()->finalizations();
		$this->assertCount( 1, $finalizations );
		$this->assertSame(
			$outer_token,
			$finalizations[0]['resolved'],
			'The outer send finalised the render NESTED INSIDE IT.'
		);
		$this->assertSame( $order_id, (int) $finalizations[0]['order_id'] );

		$tombstone = $this->insert_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone, 'The outer send recorded no delivery.' );
		$this->assertSame( 'sent', $tombstone['final_status'] );

		// --- The inner render never sent, and says so. ------------------------
		$open = RenderEvents::ledger()->slots();
		$this->assertCount( 1, $open );
		$this->assertSame( $inner_token, $open[0]['token'] );

		$this->run_shutdown_sweep();

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'sent', $rows[0]['state'] );
		$this->assertSame( 'abandoned', $rows[1]['state'] );
		$this->assertSame(
			'sent',
			$this->insert_tombstone( $order_id, $rule_id )['final_status'],
			'The abandoned inner render downgraded the delivery that succeeded.'
		);

		fwrite(
			STDERR,
			sprintf(
				"\n[5C item 1] nested from %s (%s): promotion queue at handoff = [%s]\n"
				. "  inner %s promoted FIRST (its own terminal position)\n"
				. "  outer %s promoted LAST  -> taken by the send, finalised `sent`\n"
				. "  attempts: #1 %s/%s  #2 %s/%s\n",
				$hook,
				$label,
				implode( ', ', $queue ),
				(string) $inner_token,
				(string) $outer_token,
				$rows[0]['type'],
				$rows[0]['state'],
				$rows[1]['type'],
				$rows[1]['state']
			)
		);
	}

	/**
	 * 5B-3. ONE SEND TAKES EXACTLY ONE TOKEN, though BOTH observation filters fire.
	 *
	 * `get_headers()` and `get_attachments()` are two arguments to one `send()`
	 * call, so `woocommerce_email_headers` and `woocommerce_email_attachments` both
	 * fire for a single send. A handoff that took a token per observation would
	 * consume two renders' tokens for one message.
	 *
	 * @return void
	 */
	public function test_one_send_consumes_exactly_one_completed_token() {
		$product_id = $this->make_simple_product( 'WCEP One Token' );

		$first    = $this->make_order_with( array( $product_id ) );
		$first_id = (int) $first->get_id();

		$second    = $this->make_order_with( array( $product_id ) );
		$second_id = (int) $second->get_id();

		$this->make_insert_rule( $product_id, array( 'content' => '<p>ONE TOKEN BLOCK.</p>' ) );

		$email  = $this->native_email();
		$ledger = RenderEvents::ledger();
		$spl    = spl_object_id( $email );

		// TWO complete renders, neither sent yet: two published tokens.
		foreach ( array( $first_id, $second_id ) as $order_id ) {
			$email->object = wc_get_order( $order_id );
			$email->get_content();
		}

		$published = $ledger->candidates();
		$this->assertArrayHasKey( $spl, $published );
		$this->assertCount( 2, $published[ $spl ], 'Two completed renders did not promote two candidates.' );

		$second_token = $published[ $spl ][1]['token'];

		// BOTH observation filters, as one send fires them — in the order
		// `WC_Email` evaluates them as arguments to `send()`.
		RenderEvents::on_send_headers( '', 'customer_processing_order', null, $email );
		$after_headers = $ledger->send_token();

		RenderEvents::on_send_attachments( array(), 'customer_processing_order', null, $email );
		$after_attachments = $ledger->send_token();

		$this->assertSame( $second_token, $after_headers, 'The send did not take the render that completed last.' );
		$this->assertSame( $after_headers, $after_attachments, 'The second observation took a SECOND token.' );

		$this->assertCount(
			1,
			$ledger->send_frames(),
			'The second observation of one send opened a SECOND send frame.'
		);

		$remaining = $ledger->candidates();
		$this->assertCount( 1, $remaining[ $spl ], 'One send consumed more than one promoted candidate.' );

		// And that one token is what the reservation uses.
		$reserved = RenderEvents::on_mail_content( 'body' );
		$this->assertSame( 'body', $reserved, 'The message was modified.' );
		$this->assertSame( $second_token, $ledger->reservations()[0]['token'] );
		$this->assertNull( $ledger->send_token(), 'The taken token survived its reservation.' );

		fwrite(
			STDERR,
			"\n[5B item 3] two completed renders, one send: headers took {$after_headers}, attachments took {$after_attachments}"
			. ' (same token), 1 of 2 published tokens consumed' . "\n"
		);

		$this->run_shutdown_sweep();
	}

	/**
	 * 5A-2. NO RESERVATION MEANS FINALIZE NOTHING, and consume nothing.
	 *
	 * The mail-callback and sent events are fired directly, without the
	 * `woocommerce_mail_content` that would have reserved a slot — the shape a
	 * third party produces when it calls `WC_Email::send()`'s hooks out of band.
	 * The armed slot must survive untouched rather than be claimed by a guess.
	 *
	 * @return void
	 */
	public function test_nothing_is_finalized_without_a_reservation() {
		$product_id = $this->make_simple_product( 'WCEP No Reservation' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_insert_rule( $product_id, array( 'content' => '<p>NO RESERVATION BLOCK.</p>' ) );

		// A render, and nothing else: the slot is armed and unreserved.
		$email         = $this->native_email();
		$email->object = wc_get_order( $order_id );
		$email->get_content();

		$before = RenderEvents::ledger()->slots();
		$this->assertCount( 1, $before );
		$this->assertSame( 'awaiting_send', $before[0]['state'] );

		// The two events that follow a reservation, WITHOUT one.
		RenderEvents::on_mail_params( array(), $email );
		RenderEvents::on_email_sent( true, 'customer_processing_order', $email );

		$this->assertSame( array(), RenderEvents::ledger()->finalizations(), 'A send with no reservation finalized something.' );
		$this->assertSame( array(), $this->tombstones_for( $order_id ), 'A send with no reservation wrote a record.' );

		$after = RenderEvents::ledger()->slots();
		$this->assertCount( 1, $after, 'The unreserved slot was consumed.' );
		$this->assertSame( $before[0]['token'], $after[0]['token'] );
		$this->assertSame( 'awaiting_send', $after[0]['state'], 'The unreserved slot changed state.' );

		// And it is still reported honestly at shutdown: the render never reached
		// the send path, so it is an abandoned render (ADR-0013 §6a).
		$this->run_shutdown_sweep();

		$tombstone = $this->insert_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone );
		$this->assertSame( 'abandoned', $tombstone['final_status'] );

		fwrite( STDERR, "\n[5A item 2] no reservation: 0 finalizations, 0 records, slot preserved and swept as abandoned\n" );
	}

	/**
	 * 5A-4. PLAIN TEXT CARRIES NO HTML ENTITIES AND NO MARKUP (ADR-0013 §4b).
	 *
	 * ⚠ WooCommerce's own plain pipeline DELETES entities it does not recognise
	 * (`WC_Email::$plain_search`, last-but-one pattern), so an un-decoded
	 * `caf&eacute;` reached the customer as `caf`. The HTML branch keeps its
	 * escaping, which is asserted here too.
	 *
	 * @return void
	 */
	public function test_plain_text_decodes_entities_and_keeps_html_escaped() {
		$product_id = $this->make_simple_product( 'WCEP Entities' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$this->make_insert_rule(
			$product_id,
			array(
				// A named entity, a numeric entity, and a literal non-ASCII
				// character that must survive untouched.
				'content' => '<p>Use A &amp; B safely.</p><p>Store at 40&#176;C. Caf&eacute; — ok.</p>',
			)
		);

		// --- The pure transformation, stated exactly. ------------------------
		$this->assertSame(
			'Use A & B safely.',
			Injector::to_plain_text( '<p>Use A &amp; B safely.</p>' ),
			'to_plain_text() did not decode entities.'
		);

		// --- PLAIN TEXT, end to end. -----------------------------------------
		$plain = $this->send_native( $order_id, 'WC_Email_Customer_Processing_Order', true );
		$body  = (string) $plain['message'];

		$this->assertStringContainsString( 'Use A & B safely.', $body );
		$this->assertStringContainsString( '40°C', $body, 'A numeric entity was not decoded and WooCommerce deleted it.' );
		$this->assertStringContainsString( 'Café', $body, 'A named entity was not decoded and WooCommerce deleted it.' );
		$this->assertStringContainsString( '— ok.', $body, 'A literal non-ASCII character did not survive.' );

		$this->assertStringNotContainsString( '&amp;', $body, 'The plain-text body carries an HTML entity.' );
		$this->assertStringNotContainsString( '&#176;', $body );
		$this->assertStringNotContainsString( '&eacute;', $body );
		$this->assertStringNotContainsString( '<p>', $body, 'The plain-text render emitted markup.' );

		// --- HTML keeps its escaping, unchanged. -----------------------------
		$this->captured_mail = array();

		$html = $this->send_native( $order_id );

		$this->assertStringContainsString(
			'Use A &amp; B safely.',
			(string) $html['message'],
			'The HTML branch stopped escaping.'
		);

		fwrite(
			STDERR,
			"\n[5A item 4] plain text before/after:\n"
			. "  stored   <p>Use A &amp; B safely.</p><p>Store at 40&#176;C. Caf&eacute; — ok.</p>\n"
			. '  delivered ' . trim( self::extract( $body, 'Use A & B safely.' ) ) . ' / '
			. trim( self::extract( $body, '40°C' ) ) . "\n"
		);
	}

	/**
	 * 5A-6a. A STALE RENDER RECORD CANNOT SERVE A LATER, DIFFERENT EMAIL.
	 *
	 * The §4a render record is a second validation path with its own lifetime,
	 * because `woocommerce_email_order_meta` and
	 * `woocommerce_email_customer_details` fire after the priority-15 pop. It
	 * validates email id, order AND audience, and is retired at finalization —
	 * so a completed email's record can never supply another one's rules.
	 *
	 * @return void
	 */
	public function test_a_stale_render_record_cannot_serve_a_later_different_email() {
		$first_product  = $this->make_simple_product( 'WCEP Record First' );
		$second_product = $this->make_simple_product( 'WCEP Record Second' );

		$first_order  = $this->make_order_with( array( $first_product ) );
		$first_id     = (int) $first_order->get_id();
		$second_order = $this->make_order_with( array( $second_product ) );
		$second_id    = (int) $second_order->get_id();

		// Both rules sit at an EMAIL-ONLY position that fires OUTSIDE the frame —
		// the exact positions the render record exists to serve.
		$first_rule = $this->make_insert_rule(
			$first_product,
			array(
				'insert_position' => 'order_meta',
				'content'         => '<p>FIRST EMAIL BLOCK.</p>',
			)
		);

		$second_rule = $this->make_insert_rule(
			$second_product,
			array(
				'native_email_id' => 'customer_completed_order',
				'insert_position' => 'customer_details',
				'content'         => '<p>SECOND EMAIL BLOCK.</p>',
			)
		);

		$first_mail = $this->send_native( $first_id );
		$this->assertBody( $first_mail, 'FIRST EMAIL BLOCK.', true );

		// The first render is over, so its record is gone.
		$this->assertSame(
			array(),
			RenderEvents::context()->renders(),
			'A finalized render kept its render record.'
		);

		// A DIFFERENT email, for a DIFFERENT order, in the same request.
		$this->captured_mail = array();

		$second_mail = $this->send_native( $second_id, 'WC_Email_Customer_Completed_Order' );

		$this->assertBody( $second_mail, 'SECOND EMAIL BLOCK.', true, 'The second email got no rules of its own.' );
		$this->assertBody( $second_mail, 'FIRST EMAIL BLOCK.', false, 'A stale render record served the second email.' );

		$this->assertNotNull( $this->insert_tombstone( $second_id, $second_rule, 'customer_completed_order' ) );
		$this->assertNull(
			$this->insert_tombstone( $second_id, $first_rule, 'customer_completed_order' ),
			'The first email\'s rule was recorded against the second email.'
		);
		$this->assertNull(
			$this->insert_tombstone( $second_id, $first_rule ),
			'The first email\'s rule was recorded against the second order.'
		);

		fwrite(
			STDERR,
			"\n[5A item 6a] render-record lifetime: record retired at finalization;"
			. " the later customer_completed_order render got its own identity and rules only\n"
		);
	}

	/**
	 * 5B-1. SAME ORDER, SAME EMAIL, SAME AUDIENCE, NESTED RENDER-ONLY: the
	 * post-frame positions register on the OUTER token (ADR-0013 §4a).
	 *
	 * This is the case the identity match cannot answer on its own. Two renders
	 * of the same email for the same order are identical in every field the
	 * render record carries — and carry DIFFERENT tokens and DIFFERENT delivery
	 * slots, so "which record" decides which slot receives the insertion.
	 * Newest-first returned the inner one, so content went into the OUTER message
	 * and was recorded against the INNER slot: the outer finalised with no rules,
	 * and the inner was reported `unresolved` while its registered content had
	 * demonstrably gone out.
	 *
	 * @return void
	 */
	public function test_a_nested_same_order_render_cannot_capture_the_outer_emission() {
		$product_id = $this->make_simple_product( 'WCEP Same Order Nesting' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		// A POST-FRAME position — the only kind that reads the emission scope.
		$rule_id = $this->make_insert_rule(
			$product_id,
			array(
				'insert_position' => 'order_meta',
				'content'         => '<p>POST FRAME BLOCK.</p>',
			)
		);

		$fired       = false;
		$inner_token = null;

		/*
		 * NESTED FROM INSIDE THE OUTER'S ORDER-DETAILS ACTION, which is the only
		 * place a nested render still leaves the outer's post-frame positions
		 * pending. Priority 1, so it runs before this plugin's own emission at 10.
		 */
		$this->hook(
			'woocommerce_email_after_order_table',
			function ( $order_arg = null, $sent_to_admin = false, $plain_text = false, $email = null ) use ( &$fired, &$inner_token, $order_id ) {
				if ( $fired ) {
					return;
				}

				$fired = true;

				// SAME singleton, SAME email id, SAME order, SAME audience.
				$nested         = $this->native_email();
				$nested->object = wc_get_order( $order_id );
				$nested->get_content();

				$slots = RenderEvents::ledger()->slots();
				$this->assertNotEmpty( $slots, 'The nested render opened no slot.' );
				$inner_token = end( $slots )['token'];
			},
			1,
			4
		);

		$mail = $this->send_native( $order_id );

		$this->assertTrue( $fired, 'The nested same-order render never happened.' );
		$this->assertMailCount( 1, 'The nested render-only call produced a message.' );

		// --- The content is physically in the outer message. ------------------
		$this->assertBody( $mail, 'POST FRAME BLOCK.', true, 'The post-frame position emitted nothing at all.' );

		// --- The OUTER send finalised the OUTER render's exact token. ---------
		$finalizations = RenderEvents::ledger()->finalizations();
		$this->assertCount( 1, $finalizations, 'The outer send finalised nothing, or finalised twice.' );

		$outer_token = $finalizations[0]['resolved'];

		$this->assertNotNull( $inner_token, 'The nested render was never observed.' );
		$this->assertNotSame( $inner_token, $outer_token, 'The two renders shared a token.' );
		$this->assertSame( $order_id, (int) $finalizations[0]['order_id'] );

		// One send, one token: the handoff was taken once even though BOTH
		// observation filters fired (ADR-0013 §5d).
		$reservations = RenderEvents::ledger()->reservation_log();
		$this->assertCount( 1, $reservations, 'One send took more or fewer than one reservation.' );
		$this->assertSame( $outer_token, $reservations[0]['token'] );
		$this->assertTrue( $reservations[0]['handoff'], 'The send reserved without an exact handoff.' );

		$tombstone = $this->insert_tombstone( $order_id, $rule_id );
		$this->assertNotNull(
			$tombstone,
			'The outer send finalised with NO rules — the post-frame emission was registered against the nested slot.'
		);
		$this->assertSame( 'sent', $tombstone['final_status'] );

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 1, $rows, 'The emission was recorded more than once.' );
		$this->assertSame( 'sent', $rows[0]['state'] );
		$this->assertSame( 1, (int) $rows[0]['attempt'] );
		$this->assertSame( 'order_meta', $this->attempt_snapshot( $rows[0] )['position'] );

		/*
		 * --- ATTRIBUTION, NOT ABSENCE. -----------------------------------------
		 *
		 * The inner call renders THE SAME RULE FOR THE SAME ORDER and fires its own
		 * `order_meta`, so it legitimately registers that rule against ITS OWN slot.
		 * Expecting the inner slot to be empty would have been asserting a bug: what
		 * matters is that each emission belongs to the render that produced it, and
		 * that the outer send consumed the OUTER token.
		 */
		$open = RenderEvents::ledger()->slots();
		$this->assertCount( 1, $open, 'The nested slot did not survive as the only open slot.' );
		$this->assertSame( $inner_token, $open[0]['token'] );
		$this->assertSame(
			array( $rule_id ),
			array_keys( $open[0]['rules'] ),
			'The inner render did not register its OWN emission.'
		);
		$this->assertSame( 'awaiting_send', $open[0]['state'], 'The inner render reached the send path.' );

		$this->run_shutdown_sweep();

		/*
		 * --- AND THE ABANDONED RENDER DOES NOT DOWNGRADE THE DELIVERY. ---------
		 *
		 * The inner render never reached a send, so it is recorded as an ABANDONED
		 * RENDER (ADR-0013 §6a) — visible as its own attempt row, and pointedly NOT
		 * as a later `unresolved` attempt, which under the ADR-0013 §1a
		 * latest-attempt rule would report the message that demonstrably went out
		 * (asserted above, in the body) as unresolved.
		 */
		$this->assertCount(
			1,
			$this->tombstones_for( $order_id ),
			'The abandoned render created a SECOND tombstone for the same identity.'
		);

		$after = $this->insert_tombstone( $order_id, $rule_id );
		$this->assertSame(
			'sent',
			$after['final_status'],
			'An abandoned render reported a delivery that succeeded as something else.'
		);

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 2, $rows, 'The abandoned render was not recorded at all.' );
		$this->assertSame( 'sent', $rows[0]['state'] );
		$this->assertSame( 'abandoned', $rows[1]['state'] );
		$this->assertSame( 'resend', $rows[1]['type'] );
		$this->assertSame( 2, (int) $rows[1]['attempt'], 'The attempt numbers are not consecutive.' );
		$this->assertStringContainsString( 'never sent', (string) $rows[1]['reason'] );

		fwrite(
			STDERR,
			"\n[5B item 3] same-order nested render trace:\n"
			. sprintf( "  outer token %s  <- published LAST, taken by the send, finalised `sent`\n", (string) $outer_token )
			. sprintf( "  inner token %s  <- its own order_meta emission, never sent, swept `abandoned`\n", (string) $inner_token )
			. sprintf( "  attempt #1 %s/%s   attempt #2 %s/%s\n", $rows[0]['type'], $rows[0]['state'], $rows[1]['type'], $rows[1]['state'] )
			. '  tombstone final_status=' . $after['final_status'] . " (NOT downgraded by the abandoned render)\n"
		);
	}

	/**
	 * 5B-1. NO EXACT SCOPE MEANS EMIT NOTHING.
	 *
	 * An email-only position fired with arguments that do not describe the render
	 * currently emitting must produce no output and no registration, rather than
	 * fall back to whichever record happens to match.
	 *
	 * @return void
	 */
	public function test_an_email_only_position_with_foreign_arguments_emits_nothing() {
		$product_id = $this->make_simple_product( 'WCEP Foreign Args' );

		$order    = $this->make_order_with( array( $product_id ) );
		$order_id = (int) $order->get_id();

		$other    = $this->make_order_with( array( $product_id ) );
		$other_id = (int) $other->get_id();

		$this->make_insert_rule(
			$product_id,
			array(
				'insert_position' => 'order_meta',
				'content'         => '<p>FOREIGN ARG BLOCK.</p>',
			)
		);

		$email         = $this->native_email();
		$email->object = wc_get_order( $order_id );

		$leaked = '';
		$fired  = false;

		/*
		 * ⚠ THE ONE-TIME GUARD IS NOT OPTIONAL AND IT IS NOT A STYLE CHOICE. This
		 * callback fires `woocommerce_email_order_meta` from INSIDE a callback on
		 * that same hook, and WordPress re-enters every callback registered on a
		 * tag it is already dispatching. Without the guard each level added a stack
		 * frame, an `ob_start()` buffer and a `wc_get_order()` object until PHP ran
		 * out of memory — the recursion is the HARNESS's, not the plugin's, and it
		 * exhausted the process before the assertions below could run. The sibling
		 * test at test_a_nested_same_order_render_cannot_capture_the_outer_emission()
		 * has carried the same guard from the start.
		 *
		 * The buffer is additionally protected by try/finally: `do_action()` runs
		 * third-party and WooCommerce code, and an exception escaping with the
		 * buffer still open would corrupt every later assertion in the process,
		 * PHPUnit's own output included.
		 */
		$this->hook(
			'woocommerce_email_order_meta',
			function ( $order_arg = null, $sent_to_admin = false, $plain_text = false, $email_arg = null ) use ( &$leaked, &$fired, $other_id ) {
				if ( $fired ) {
					return;
				}

				$fired = true;

				// The SAME position, fired for a DIFFERENT order and a DIFFERENT
				// audience while this render's scope is the one that is open.
				ob_start();

				try {
					do_action( 'woocommerce_email_order_meta', wc_get_order( $other_id ), true, false, $email_arg );
				} finally {
					$leaked .= (string) ob_get_clean();
				}
			},
			1,
			4
		);

		$email->get_content();

		$this->assertTrue( $fired, 'The foreign-argument emission never happened.' );
		$this->assertSame( '', trim( $leaked ), 'A foreign-argument emission produced output.' );

		$unresolved = RenderEvents::context()->unresolved_emissions();
		$this->assertNotEmpty( $unresolved, 'The refused emission was not recorded.' );

		$this->assertSame( $other_id, (int) $unresolved[0]['order_id'] );
		$this->assertSame( 0, (int) $unresolved[0]['matches'] );

		fwrite(
			STDERR,
			"\n[5B item 1] foreign-argument emission refused: 0 exact scopes, order #"
			. (int) $unresolved[0]['order_id'] . " asked while order #{$order_id} was emitting\n"
		);
	}

	/**
	 * The line of a body containing a fragment, for the report.
	 *
	 * @param string $body     Message body.
	 * @param string $fragment Fragment.
	 * @return string
	 */
	private static function extract( string $body, string $fragment ): string {
		foreach ( preg_split( '/\r\n|\r|\n/', $body ) as $line ) {
			if ( false !== strpos( $line, $fragment ) ) {
				return $line;
			}
		}

		return '';
	}
}
