<?php
/**
 * The failure boundary around resolution, in both delivery modes
 * (ADR-0014 §10, gate 17).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\PlaceholderValues;
use Extonify\WCEP\Render\Injector;

/**
 * NOTHING THROWN DURING RESOLUTION MAY ESCAPE, STRAND A CLAIM, OR ABORT
 * WOOCOMMERCE'S OWN EMAIL.
 *
 * ⚠ WHY THIS FILE EXISTS AT ALL. Prompt 6 shipped
 * `extonify_wcep_meta_placeholder_allowed` — a PLUGIN-OWNED extension point,
 * invoked between the claim and the send in separate mode, and inside
 * WooCommerce's own rendering hooks in insert mode. A filter this plugin created
 * must not be able to strand a consumed delivery identity or kill a merchant's
 * processing email, so every callback invoked during resolution is treated as
 * hostile and the containment is asserted rather than assumed.
 *
 * Every throwing callback here is scoped to ITS OWN fixture — by order id, by meta
 * key, or by call count — so a test that fails part way cannot take the rest of
 * the suite with it. `InsertModeTestCase::hook()` removes them all on teardown.
 */
final class PlaceholderContainmentTest extends InsertModeTestCase {

	/**
	 * The exception every throwing callback in this file raises.
	 *
	 * A `RuntimeException` rather than an `Exception`, so "the detail row names
	 * the exception CLASS" is asserting something specific.
	 */
	const BOOM = 'the placeholder filter exploded';

	/**
	 * A meta key no order holds, used only to route a throw at one rule.
	 */
	const TRIGGER_KEY = 'wcep_detonator';

	/**
	 * An order with a billing identity and one public meta key.
	 *
	 * @param int[] $lines Product ids, as `make_order_with()` takes.
	 * @return \WC_Order
	 */
	private function order_for( array $lines ): \WC_Order {
		$order = $this->make_order_with( $lines );

		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_billing_email( 'ada@example.test' );
		$order->set_billing_address_1( '12 High Street' );
		$order->set_billing_city( 'London' );
		$order->set_billing_postcode( 'N1 1AA' );
		$order->set_billing_country( 'GB' );
		$order->update_meta_data( 'total_paid_note', 'Paid in full at the till' );
		$order->save();

		return $order;
	}

	/**
	 * Make the meta allow-filter throw for ONE key.
	 *
	 * @param string $key   Key that detonates.
	 * @param int[]  $calls Receives one entry per invocation, for the report.
	 * @return void
	 */
	private function detonate_on_key( string $key, array &$calls ): void {
		$this->hook(
			PlaceholderValues::META_FILTER,
			static function ( $allowed, $meta_key = '', $scope = '', $order = null ) use ( $key, &$calls ) {
				$calls[] = (string) $meta_key;

				if ( (string) $meta_key === $key ) {
					throw new \RuntimeException( self::BOOM );
				}

				return $allowed;
			},
			10,
			4
		);
	}

	/**
	 * Whether the current call stack is inside `Injector::render_and_emit()`.
	 *
	 * THE ONLY HONEST DISCRIMINATOR FOR A KSES PASS. `wp_kses_allowed_html` is
	 * handed the context and the tag list, never the string being sanitised, and
	 * this plugin's pass is not distinguishable from WooCommerce's by context alone
	 * — both are `post`. Asking the stack scopes the throw to exactly the call the
	 * test is about.
	 *
	 * @return bool
	 */
	private static function inside_injector_sanitisation(): bool {
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- test-only stack scoping; see the docblock.
			if ( Injector::class === ( $frame['class'] ?? '' ) && 'render_and_emit' === ( $frame['function'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every detail row's `reason` and `failure_message`, joined.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return string
	 */
	private function evidence( int $delivery_id ): string {
		$parts = array();

		foreach ( $this->detail_rows( $delivery_id ) as $row ) {
			$parts[] = (string) ( $row['reason'] ?? '' ) . ' || ' . (string) ( $row['failure_message'] ?? '' );
		}

		return implode( "\n", $parts );
	}

	/**
	 * Assert one tombstone shows a contained failure rather than a stranded claim.
	 *
	 * @param int    $order_id Order id.
	 * @param int    $rule_id  Rule id.
	 * @param string $identity Trigger identity.
	 * @param string $where    Description for failure messages.
	 * @return string The joined evidence, for the report.
	 */
	private function assert_contained( int $order_id, int $rule_id, string $identity, string $where ): string {
		$tombstone = $this->tombstone( $order_id, $rule_id, $identity );

		$this->assertNotNull( $tombstone, $where . ': no tombstone was written at all' );
		$this->track_delivery( (int) $tombstone['id'] );

		// ⚠ THE WHOLE POINT: `claimed` with no detail row is the state that
		// consumes an identity and leaves nobody able to say why.
		$this->assertNotSame( 'claimed', (string) $tombstone['final_status'], $where . ': the tombstone was left CLAIMED' );
		$this->assertSame( 'failed', (string) $tombstone['final_status'], $where );

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertNotSame( array(), $rows, $where . ': the tombstone carries no detail row' );

		$evidence = $this->evidence( (int) $tombstone['id'] );

		$this->assertStringContainsString( 'RuntimeException', $evidence, $where . ': the exception class was not recorded' );
		$this->assertStringContainsString( self::BOOM, $evidence, $where . ': the exception message was not recorded' );

		return $evidence;
	}

	/**
	 * 1. SEPARATE MODE — A THROWING `extonify_wcep_meta_placeholder_allowed`
	 *    CALLBACK (ADR-0014 §10).
	 *
	 * The status event survives, the tombstone is `failed` with a detail row naming
	 * the exception, nothing is mailed — and the OTHER trigger family in the same
	 * status change still delivers, which is the direct evidence that the throw
	 * never left the event.
	 *
	 * @return void
	 */
	public function test_a_throwing_meta_filter_cannot_escape_a_status_change() {
		$product_id = $this->make_simple_product( 'WCEP Containment Meta' );
		$order      = $this->order_for( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$exploding = $this->make_sending_rule(
			$product_id,
			array(
				'name'          => 'throws during resolution',
				'trigger_type'  => 'status',
				'trigger_value' => 'processing',
				'content'       => '<p>note=[{order_custom_field:' . self::TRIGGER_KEY . '}]</p>',
			)
		);

		// A SECOND FAMILY ON THE SAME EVENT. `handle_status_change()` evaluates
		// `status:` and `transition:` independently; an escaping throw used to take
		// the second one with it, so this rule delivering is the proof it did not.
		$survivor = $this->make_sending_rule(
			$product_id,
			array(
				'name'          => 'other family, unaffected',
				'trigger_type'  => 'transition',
				'trigger_value' => 'pending>processing',
				'subject'       => 'Survivor for {customer_first_name}',
				'content'       => '<p>SURVIVOR BLOCK.</p>',
			)
		);

		$calls = array();
		$this->detonate_on_key( self::TRIGGER_KEY, $calls );

		// NO try/catch HERE, DELIBERATELY. If a Throwable escapes, PHPUnit reports
		// this test as errored — which is the assertion.
		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$this->assertMailCount( 1, 'the surviving family did not deliver, so the throw escaped the event' );
		$this->assertStringContainsString( 'SURVIVOR BLOCK.', (string) $this->last_mail()['message'] );
		$this->assertStringNotContainsString( self::TRIGGER_KEY, (string) $this->last_mail()['message'] );

		$evidence = $this->assert_contained( $order_id, $exploding, 'status:processing', 'separate mode / meta filter' );

		$this->assertContains( self::TRIGGER_KEY, $calls, 'the filter never ran, so nothing was contained' );

		// --- AND THE RULE IS NOT POISONED once the filter is gone. -------------
		$this->tear_down_render_singletons();
		$this->captured_mail = array();

		$later    = $this->order_for( array( $product_id ) );
		$later_id = (int) $later->get_id();

		$this->orchestrator()->handle_status_change( $later_id, 'pending', 'processing' );

		$retried = $this->tombstone( $later_id, $exploding, 'status:processing' );
		$this->assertNotNull( $retried );
		$this->track_delivery( (int) $retried['id'] );
		$this->assertSame( 'sent', (string) $retried['final_status'], 'the rule stayed broken after the filter was removed' );

		fwrite(
			STDERR,
			"\n[6A item 1 / gate 17] separate mode, throwing meta filter:\n"
			. "  status event: did not throw; the transition family still sent 1 message\n"
			. '  tombstone   : failed (never claimed-only); ' . count( $this->detail_rows( (int) $this->tombstone( $order_id, $exploding, 'status:processing' )['id'] ) ) . " detail row(s)\n"
			. '  recorded    : ' . trim( explode( "\n", $evidence )[0] ) . "\n"
			. "  after removal: the same rule delivered `sent` on a fresh identity\n"
		);
	}

	/**
	 * 2. SEPARATE MODE — A THROWING CALLBACK ON A WOOCOMMERCE FORMATTER FILTER
	 *    USED DURING RESOLUTION (ADR-0014 §10).
	 *
	 * `{billing_address}` calls `WC_Order::get_formatted_billing_address()`, which
	 * applies `woocommerce_order_formatted_billing_address` — WooCommerce's own
	 * documented filter, and not one this plugin owns. The containment must not
	 * depend on which filter threw.
	 *
	 * @return void
	 */
	public function test_a_throwing_woocommerce_formatter_cannot_escape_a_status_change() {
		$product_id = $this->make_simple_product( 'WCEP Containment Formatter' );
		$order      = $this->order_for( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule(
			$product_id,
			array(
				'trigger_value' => 'processing',
				'content'       => '<p>address=[{billing_address}]</p>',
			)
		);

		// SCOPED TO THIS ORDER. The filter is global and WooCommerce formats
		// addresses in plenty of other places; throwing for everything would be
		// testing the test harness.
		$this->hook(
			'woocommerce_order_formatted_billing_address',
			static function ( $address, $order_arg = null ) use ( $order_id ) {
				if ( is_object( $order_arg ) && (int) $order_arg->get_id() === $order_id ) {
					throw new \RuntimeException( self::BOOM );
				}

				return $address;
			},
			10,
			2
		);

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$this->assertMailCount( 0, 'a delivery whose resolution threw still sent a message' );

		$evidence = $this->assert_contained( $order_id, $rule_id, 'status:processing', 'separate mode / WooCommerce formatter' );

		fwrite(
			STDERR,
			"\n[6A item 1 / gate 17] separate mode, throwing woocommerce_order_formatted_billing_address:\n"
			. "  status event: did not throw; 0 messages sent\n"
			. '  recorded    : ' . trim( explode( "\n", $evidence )[0] ) . "\n"
		);
	}

	/**
	 * 2a. SEPARATE MODE — A THROW DURING **RECIPIENT** RESOLUTION, BEFORE THERE IS
	 *     ANY ADDRESS TO WRITE A ROW AGAINST (ADR-0014 §10).
	 *
	 * ⚠ THE HARDEST CASE FOR GATE 17'S SECOND CLAUSE. Failure rows are written one
	 * per resolved recipient, so a throw before resolution returns leaves NO
	 * recipient — and, before this prompt, would have written no row at all: a
	 * consumed identity, a finalised tombstone, and no evidence anywhere.
	 * `{store_email}` reads the `woocommerce_email_from_address` option, so
	 * `pre_option_…` is a throw inside recipient resolution and nowhere else.
	 *
	 * @return void
	 */
	public function test_a_throw_during_recipient_resolution_still_records_a_row() {
		$product_id = $this->make_simple_product( 'WCEP Containment Recipients' );
		$order      = $this->order_for( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule(
			$product_id,
			array(
				'trigger_value' => 'processing',
				'recipients'    => array( 'to' => array( 'customer' ) ),
			)
		);

		$this->hook(
			'pre_option_woocommerce_email_from_address',
			static function () {
				throw new \RuntimeException( self::BOOM );
			},
			10,
			1
		);

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$this->assertMailCount( 0 );

		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:processing' );
		$this->assertNotNull( $tombstone );
		$this->track_delivery( (int) $tombstone['id'] );

		$rows = $this->detail_rows( (int) $tombstone['id'] );

		$this->assertCount( 1, $rows, 'a throw before any recipient resolved must still write exactly one row' );
		$this->assertSame( 'failed', (string) $rows[0]['state'] );
		$this->assertNull( $rows[0]['recipient'] ?? null, 'the row invented a recipient that was never resolved' );

		$evidence = $this->assert_contained( $order_id, $rule_id, 'status:processing', 'separate mode / recipient resolution' );

		fwrite(
			STDERR,
			"\n[6A item 1 / gate 17] separate mode, throw during RECIPIENT resolution:\n"
			. "  1 recipient-less detail row written (0 before this prompt)\n"
			. '  recorded    : ' . trim( explode( '||', $evidence )[1] ) . "\n"
		);
	}

	/**
	 * 3. INSERT MODE — ONE RULE THROWS, THE NATIVE EMAIL IS STILL SENT, AND THE
	 *    OTHER MATCHED RULE STILL EMITS (ADR-0014 §10).
	 *
	 * @return void
	 */
	public function test_a_throwing_rule_does_not_abort_the_native_email() {
		$product_id = $this->make_simple_product( 'WCEP Containment Insert' );
		$order      = $this->order_for( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$exploding = $this->make_insert_rule(
			$product_id,
			array(
				'name'    => 'throws during render',
				'content' => '<p>EXPLODING BLOCK [{order_custom_field:' . self::TRIGGER_KEY . '}]</p>',
			)
		);

		$survivor = $this->make_insert_rule(
			$product_id,
			array(
				'name'    => 'renders normally',
				'content' => '<p>SURVIVOR BLOCK [{order_custom_field:total_paid_note}]</p>',
			)
		);

		$calls = array();
		$this->detonate_on_key( self::TRIGGER_KEY, $calls );

		$mail = $this->send_native( $order_id, 'WC_Email_Customer_Processing_Order' );

		// --- THE NATIVE EMAIL SURVIVED, WHOLE. ---------------------------------
		$this->assertMailCount( 1, 'the native processing email was aborted by a throwing rule' );
		$body = (string) $mail['message'];

		$this->assertStringContainsString( 'WCEP Containment Insert', $body, 'the order table did not render' );
		$this->assertStringContainsString( 'SURVIVOR BLOCK', $body, 'a second matched rule stopped emitting' );
		$this->assertStringContainsString( 'Paid in full at the till', $body, 'the survivor did not resolve its own placeholders' );

		// --- AND THE THROWING RULE EMITTED NOTHING AT ALL. ---------------------
		$this->assertStringNotContainsString( 'EXPLODING BLOCK', $body, 'a rule whose resolution threw emitted partial content' );
		$this->assertStringNotContainsString( self::TRIGGER_KEY, $body );

		// --- RECORDED, WITH A TRUTHFUL STATE. ----------------------------------
		$failed = $this->insert_tombstone( $order_id, $exploding );
		$this->assertNotNull( $failed, 'the throwing rule was not recorded at all' );
		$this->assertSame( 'failed', (string) $failed['final_status'] );

		$failed_rows = $this->detail_rows( (int) $failed['id'] );
		$reason      = (string) $failed_rows[0]['reason'];

		$this->assertStringContainsString( 'NOTHING was inserted', $reason, 'the log claims content the customer never saw' );
		$this->assertStringContainsString( 'RuntimeException', $reason );
		$this->assertStringContainsString( self::BOOM, $reason );

		/*
		 * ⚠ BOTH FACTS, SEPARATELY (ADR-0014 §10c). This used to assert
		 * `outcome === 'not_rendered'`, which was the collapse itself: the render
		 * outcome REPLACED the message outcome, so the row could no longer say that
		 * the native email had gone out perfectly well.
		 */
		$snapshot = $this->attempt_snapshot( $failed_rows[0] );

		$this->assertSame( 'sent', (string) $snapshot['outcome'], 'the message outcome was overwritten by the render outcome' );
		$this->assertSame( 'not_rendered', (string) $snapshot['render'], 'the per-rule render outcome was collapsed into the slot outcome' );

		$sent = $this->insert_tombstone( $order_id, $survivor );
		$this->assertNotNull( $sent );
		$this->assertSame( 'sent', (string) $sent['final_status'], 'the surviving rule was tarred with the failure' );

		fwrite(
			STDERR,
			"\n[6A item 1 / gate 17] insert mode, one rule throws:\n"
			. "  native email  : SENT, order table complete\n"
			. "  throwing rule : emitted nothing, tombstone failed, outcome not_rendered\n"
			. "  other rule    : emitted and resolved normally, tombstone sent\n"
			. '  recorded      : ' . $reason . "\n"
		);
	}

	/**
	 * 4. INSERT MODE — A THROW AT THE PER-ITEM POSITION LEAVES THE ORDER TABLE AND
	 *    EVERY OTHER ITEM INTACT (ADR-0014 §10).
	 *
	 * The throw is routed by CALL ORDER rather than by item, because the meta
	 * filter is handed the key and the order but not the line item. Items render in
	 * `WC_Order::get_items()` order, so "the first invocation" is deterministically
	 * the first matched item's block.
	 *
	 * @return void
	 */
	public function test_a_throw_at_the_per_item_position_leaves_the_order_table_intact() {
		$first  = $this->make_simple_product( 'WCEP PerItem Alpha' );
		$second = $this->make_simple_product( 'WCEP PerItem Beta' );

		$order    = $this->order_for( array( $first, $second ) );
		$order_id = (int) $order->get_id();

		foreach ( $order->get_items() as $item ) {
			$item->update_meta_data( 'gift_note', 'note for ' . $item->get_name() );
			$item->save();
		}

		$rule_id = $this->make_insert_rule(
			$first,
			array(
				'insert_position' => 'item_meta',
				'targeting'       => array( 'include' => array( 'products' => array( $first, $second ) ) ),
				'content'         => '<p>PERITEM [{item_custom_field:gift_note}]</p>',
			)
		);

		$fired = 0;

		$this->hook(
			PlaceholderValues::META_FILTER,
			static function ( $allowed, $meta_key = '', $scope = '', $order_arg = null ) use ( &$fired ) {
				++$fired;

				if ( 1 === $fired ) {
					throw new \RuntimeException( self::BOOM );
				}

				return $allowed;
			},
			10,
			4
		);

		$mail = $this->send_native( $order_id, 'WC_Email_Customer_Processing_Order' );

		$this->assertMailCount( 1, 'the native email was aborted by a per-item throw' );
		$body = (string) $mail['message'];

		// THE ORDER TABLE RENDERED COMPLETELY: both line items are still listed.
		$this->assertStringContainsString( 'WCEP PerItem Alpha', $body, 'the order table lost a line item' );
		$this->assertStringContainsString( 'WCEP PerItem Beta', $body, 'the order table was truncated at the throw' );

		/*
		 * The item whose resolution threw got NO block; the other one is untouched.
		 *
		 * ⚠ ASSERTED ON THE `PERITEM [...]` MARKER, NOT ON THE NOTE TEXT.
		 * WooCommerce's own order-items template prints every PUBLIC item meta key
		 * it finds, so `note for …` appears in the order table for both items
		 * whatever this plugin does — which is itself the evidence that the table
		 * rendered completely.
		 */
		$this->assertSame( 1, substr_count( $body, 'PERITEM [' ), 'the per-item blocks did not survive the throw one-for-one' );
		$this->assertStringContainsString( 'PERITEM [note for WCEP PerItem Beta]', $body, 'the surviving item lost its own meta' );
		$this->assertStringNotContainsString( 'PERITEM [note for WCEP PerItem Alpha]', $body );

		$this->assertSame( 2, $fired, 'the filter did not run once per line item' );

		// EMITTED FOR ONE ITEM AND THREW FOR THE OTHER, so the rule DID reach the
		// customer and must not be recorded as `not_rendered`. The throw is still
		// on the record.
		$tombstone = $this->insert_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone );
		$this->assertSame( 'sent', (string) $tombstone['final_status'] );

		$reason = (string) $this->detail_rows( (int) $tombstone['id'] )[0]['reason'];
		$this->assertStringContainsString( 'threw and that block was withheld', $reason, 'a partial per-item failure left no trace' );

		fwrite(
			STDERR,
			"\n[6A item 1 / gate 17] insert mode, throw at the per-item position:\n"
			. "  order table   : both line items rendered\n"
			. "  blocks emitted: 1 of 2 (the throwing item emitted nothing)\n"
			. '  recorded      : ' . $reason . "\n"
		);
	}

	/**
	 * 5. 6B ITEM 2 / GATE 18. ⚠ A THROW FROM THE **FINAL SANITISATION** LEAVES
	 *    `emitted = false` (ADR-0014 §10b).
	 *
	 * THE DEFECT THIS CATCHES, AND WHY NO EARLIER TEST COULD SEE IT. `Injector`
	 * registered the rule against the ledger and THEN evaluated
	 * `wp_kses_post( wpautop( $html ) )` inside the echo — while its own docblock
	 * claimed "the echo is last, deliberately: everything that can throw has
	 * finished by then". `wp_kses_post()` fires `wp_kses_allowed_html`, so it can
	 * throw; and when it did, the ledger already held `emitted = true`, the catch
	 * registered `false`, `register()` ORed them, and the result stayed `true`.
	 * Nothing was printed and the history said content was inserted.
	 *
	 * Every previous containment test threw during PLACEHOLDER RESOLUTION, which
	 * happens before the registration either way — so all of them passed against
	 * the broken ordering.
	 *
	 * @return void
	 */
	public function test_a_throw_during_final_sanitisation_records_nothing_emitted() {
		$product_id = $this->make_simple_product( 'WCEP Sanitise Throw' );
		$order      = $this->order_for( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$exploding = $this->make_insert_rule(
			$product_id,
			array(
				'name'    => 'throws in wp_kses_post',
				'content' => '<p>SANITISE BLOCK for {customer_first_name}.</p>',
			)
		);

		$survivor = $this->make_insert_rule(
			$product_id,
			array(
				'name'    => 'renders normally',
				'content' => '<p>SURVIVOR BLOCK.</p>',
			)
		);

		/*
		 * SCOPED TO THE INJECTOR'S OWN KSES PASS, AND TO THE FIRST OF THEM.
		 * `wp_kses_allowed_html` fires for every kses pass in the request —
		 * WooCommerce's own templates included — so an unconditional throw would be
		 * testing the harness rather than the boundary. The stack is the only honest
		 * discriminator available here: the filter is handed the context and the tag
		 * list, never the string being sanitised.
		 *
		 * The FIRST such pass is the exploding rule's: equal-specificity rules are
		 * ordered by ascending id (`Domain\Specificity::compare()`), and it was
		 * created first.
		 */
		$fired = 0;

		$this->hook(
			'wp_kses_allowed_html',
			static function ( $tags, $context = '' ) use ( &$fired ) {
				if ( ! self::inside_injector_sanitisation() ) {
					return $tags;
				}

				++$fired;

				if ( 1 === $fired ) {
					throw new \RuntimeException( self::BOOM );
				}

				return $tags;
			},
			10,
			2
		);

		$mail = $this->send_native( $order_id, 'WC_Email_Customer_Processing_Order' );

		// --- THE NATIVE EMAIL SURVIVED. ---------------------------------------
		$this->assertMailCount( 1, 'a throwing sanitiser aborted the native email' );
		$body = (string) $mail['message'];

		$this->assertStringContainsString( 'WCEP Sanitise Throw', $body, 'the order table did not render' );

		// --- AND THE THROWING RULE'S OUTPUT IS ABSENT FROM THE MESSAGE. --------
		$this->assertStringNotContainsString( 'SANITISE BLOCK', $body, 'the rule emitted despite the sanitiser throwing' );

		// --- THE LEDGER SAYS `emitted = false`, WHICH IS THE ASSERTION. -------
		$failed = $this->insert_tombstone( $order_id, $exploding );
		$this->assertNotNull( $failed, 'the throwing rule was not recorded at all' );

		$rows     = $this->detail_rows( (int) $failed['id'] );
		$reason   = (string) $rows[0]['reason'];
		$snapshot = $this->attempt_snapshot( $rows[0] );

		$this->assertSame(
			'not_rendered',
			(string) $snapshot['render'],
			'⚠ the ledger claimed content was emitted that was never printed'
		);
		$this->assertSame( 'sent', (string) $snapshot['outcome'], 'the message outcome was overwritten by the render outcome' );
		$this->assertSame( 'failed', (string) $rows[0]['state'] );

		$this->assertStringContainsString( 'NOTHING was inserted', $reason );
		$this->assertStringContainsString( 'RuntimeException', $reason, 'the throw was not named' );
		$this->assertStringContainsString( self::BOOM, $reason );

		// The OTHER rule is untouched, because containment is per rule.
		$sent = $this->insert_tombstone( $order_id, $survivor );
		$this->assertNotNull( $sent );
		$this->assertSame( 'sent', (string) $sent['final_status'], 'the surviving rule was tarred with the failure' );
		$this->assertStringContainsString( 'SURVIVOR BLOCK', $body );

		fwrite(
			STDERR,
			"\n[6B item 2 / gate 18] throw inside wp_kses_post():\n"
			. "  native email : SENT, order table complete\n"
			. "  that rule    : absent from the message; ledger emitted=FALSE\n"
			. '  recorded     : message=' . $snapshot['outcome'] . ', render=' . $snapshot['render']
				. ', state=' . $rows[0]['state'] . "\n"
			. '  reason       : ' . $reason . "\n"
		);
	}

	/**
	 * 6. 6B ITEM 3 / GATE 18. ⚠ AN **ABANDONED** RENDER WHOSE RESOLUTION FAILED IS
	 *    NOT A GENUINE ATTEMPT, SO THE NEXT FIRST SEND IS `auto` (ADR-0014 §10c).
	 *
	 * THE REGRESSION PATH, EXACTLY. `not_rendered` used to REPLACE the message
	 * outcome, and `not_rendered` stores as `state = 'failed'`, which
	 * `DeliveryDetailRepository::GENUINE_ATTEMPT_STATES` counts as a real send. So
	 * an abandoned render that also failed to resolve produced a `failed` row, and
	 * the merchant's FIRST genuine delivery was then typed `resend` — precisely the
	 * ADR-0013 §6b defect Prompt 5C fixed, reintroduced through a different column.
	 *
	 * `InsertModeTest::test_an_abandoned_render_does_not_make_the_first_real_send_a_resend()`
	 * covers the same sequence with a render that SUCCEEDED; this is the half that
	 * went through the collapsing branch, and it passed for as long as the two facts
	 * were one.
	 *
	 * @return void
	 */
	public function test_an_abandoned_render_that_failed_to_resolve_is_not_a_genuine_attempt() {
		$product_id = $this->make_simple_product( 'WCEP Abandoned Throw' );
		$order      = $this->order_for( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_insert_rule(
			$product_id,
			array(
				'name'    => 'throws, then recovers',
				'content' => '<p>RECOVERED BLOCK [{order_custom_field:' . self::TRIGGER_KEY . '}]</p>',
			)
		);

		$calls = array();
		$this->detonate_on_key( self::TRIGGER_KEY, $calls );

		// A third party renders and throws the render away. Resolution throws
		// inside it, so this render is BOTH abandoned AND not_rendered.
		$email         = $this->native_email();
		$email->object = wc_get_order( $order_id );
		$email->get_content();

		$this->run_shutdown_sweep();

		$this->assertNotSame( array(), $calls, 'the filter never ran, so the render did not fail' );

		$after_abandon = $this->insert_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $after_abandon, 'the abandoned render was not recorded at all' );

		$abandoned_rows = $this->detail_rows( (int) $after_abandon['id'] );
		$this->assertCount( 1, $abandoned_rows );

		$first = $this->attempt_snapshot( $abandoned_rows[0] );

		// ROW 1: the message was ABANDONED; the rule was NOT RENDERED. Both facts.
		$this->assertSame( 'auto', (string) $abandoned_rows[0]['type'] );
		$this->assertSame(
			'abandoned',
			(string) $abandoned_rows[0]['state'],
			'⚠ an abandoned render was stored as `failed`, which counts as a genuine attempt'
		);
		$this->assertSame( 'abandoned', (string) $first['outcome'], 'the message outcome was lost' );
		$this->assertSame( 'not_rendered', (string) $first['render'], 'the render outcome was lost' );
		$this->assertSame( 'abandoned', (string) $after_abandon['final_status'] );

		// AND THE REASON DOES NOT CLAIM THE EMAIL WAS SENT.
		$abandoned_reason = (string) $abandoned_rows[0]['reason'];
		$this->assertStringContainsString( 'NOTHING was inserted', $abandoned_reason );
		$this->assertStringContainsString( 'never reached a send at all', $abandoned_reason );
		$this->assertStringNotContainsString(
			'the native email was sent without it',
			$abandoned_reason,
			'⚠ the log claims a message was sent that was never sent'
		);

		/*
		 * --- NOW THE FILTER IS GONE AND WOOCOMMERCE SENDS FOR THE FIRST TIME. ---
		 *
		 * `tear_down_render_singletons()` removes the detonating filter AND drops the
		 * render context, ledger and phase; `set_up_render_singletons()` rebuilds
		 * them, so the send below happens against fresh per-request state exactly as
		 * a later real request would.
		 */
		$this->tear_down_render_singletons();
		$this->set_up_render_singletons();
		$this->captured_mail = array();

		$mail = $this->send_native( $order_id, 'WC_Email_Customer_Processing_Order' );
		$this->assertMailCount( 1 );
		$this->assertStringContainsString( 'RECOVERED BLOCK', (string) $mail['message'], 'the rule stayed broken after the filter was removed' );

		$tombstone = $this->insert_tombstone( $order_id, $rule_id );
		$rows      = $this->detail_rows( (int) $tombstone['id'] );

		$this->assertCount( 2, $rows );

		$second = $this->attempt_snapshot( $rows[1] );

		// ROW 2: THE HEADLINE ASSERTION.
		$this->assertSame(
			'auto',
			(string) $rows[1]['type'],
			'⚠ the FIRST genuine delivery was typed `resend` because an abandoned render counted as an attempt'
		);
		$this->assertSame( 'sent', (string) $rows[1]['state'] );
		$this->assertSame( 'sent', (string) $second['outcome'] );
		$this->assertSame( 'rendered', (string) $second['render'] );
		$this->assertSame( 2, (int) $rows[1]['attempt'], 'the abandoned row lost its place in the sequence' );
		$this->assertSame( 'sent', (string) $tombstone['final_status'] );

		fwrite(
			STDERR,
			"\n[6B item 3 / gate 18] abandoned + not_rendered, then the first real send:\n"
			. sprintf(
				"  #%d %s/%s   message=%s render=%s\n",
				(int) $rows[0]['attempt'],
				$rows[0]['type'],
				$rows[0]['state'],
				$first['outcome'],
				$first['render']
			)
			. sprintf(
				"  #%d %s/%s        message=%s render=%s   <- NOT `resend`\n",
				(int) $rows[1]['attempt'],
				$rows[1]['type'],
				$rows[1]['state'],
				$second['outcome'],
				$second['render']
			)
			. '  final_status : ' . $tombstone['final_status'] . "\n"
			. '  row 1 reason : ' . $abandoned_reason . "\n"
		);
	}

	/**
	 * 6a. 6B ITEM 3 / GATE 18. THE REMAINING TWO ROWS OF THE §10c MATRIX: a rule
	 *     that failed to render into a message that **itself failed**, and into one
	 *     whose outcome was **never reported**.
	 *
	 * ⚠ THESE ARE THE ROWS THE OLD CODE COULD NOT EXPRESS AT ALL. `not_rendered`
	 * replaced the message outcome, so both of these were stored `failed` under a
	 * sentence ending "and the native email was sent without it" — untrue in the
	 * first case about the sending, and untrue in the second about knowing.
	 *
	 * @dataProvider unsent_message_provider
	 *
	 * @param string $label    Case name.
	 * @param string $expected Expected message outcome.
	 * @param string $state    Expected stored attempt state.
	 * @param string $clause   Fragment the reason must carry.
	 * @return void
	 */
	public function test_a_rule_that_failed_to_render_keeps_the_real_message_outcome( string $label, string $expected, string $state, string $clause ) {
		$product_id = $this->make_simple_product( 'WCEP Unsent ' . $label );
		$order      = $this->order_for( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_insert_rule(
			$product_id,
			array(
				'name'    => 'throws into an unsent message',
				'content' => '<p>UNSENT BLOCK [{order_custom_field:' . self::TRIGGER_KEY . '}]</p>',
			)
		);

		$calls = array();
		$this->detonate_on_key( self::TRIGGER_KEY, $calls );

		if ( 'failed' === $expected ) {
			// `pre_wp_mail` returns false AFTER the capture, so the message is still
			// intercepted and the only thing that differs is what
			// `woocommerce_email_sent` reports.
			$this->make_sends_report_failure();
			$this->send_native( $order_id, 'WC_Email_Customer_Processing_Order' );
		} else {
			// A throwing mailer: `woocommerce_email_sent` never fires, so the
			// outcome is genuinely unknown and the sweep records it (ADR-0013 §6).
			$thrower = static function () {
				throw new \RuntimeException( 'SMTP plugin exploded mid-send' );
			};

			add_filter( 'pre_wp_mail', $thrower, 0 );

			try {
				$this->native_email()->trigger( $order_id );
			} catch ( \RuntimeException $error ) {
				$this->assertSame( 'SMTP plugin exploded mid-send', $error->getMessage() );
			} finally {
				remove_filter( 'pre_wp_mail', $thrower, 0 );
			}

			$this->run_shutdown_sweep();
		}

		$this->assertNotSame( array(), $calls, $label . ': the rule never failed to render' );

		$tombstone = $this->insert_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone, $label . ': nothing was recorded at all' );

		$rows     = $this->detail_rows( (int) $tombstone['id'] );
		$snapshot = $this->attempt_snapshot( $rows[0] );
		$reason   = (string) $rows[0]['reason'];

		// BOTH FACTS SURVIVE.
		$this->assertSame( $expected, (string) $snapshot['outcome'], $label . ': the message outcome was overwritten' );
		$this->assertSame( 'not_rendered', (string) $snapshot['render'], $label . ': the render outcome was lost' );
		$this->assertSame( $state, (string) $rows[0]['state'], $label . ': the stored state does not match the §10c matrix' );

		// AND THE REASON IS TRUE.
		$this->assertStringContainsString( 'NOTHING was inserted', $reason, $label );
		$this->assertStringContainsString( $clause, $reason, $label . ': the reason does not describe the real message outcome' );
		$this->assertStringNotContainsString(
			'the native email was sent without it',
			$reason,
			$label . ': ⚠ the log claims the native email was sent when it was not'
		);

		fwrite(
			STDERR,
			sprintf(
				"  %-28s message=%-11s render=%-12s state=%-11s\n    %s\n",
				$label,
				$snapshot['outcome'],
				$snapshot['render'],
				$rows[0]['state'],
				$reason
			)
		);
	}

	/**
	 * The two message outcomes that are neither `sent` nor `abandoned`.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string}>
	 */
	public function unsent_message_provider(): array {
		return array(
			'message failed'     => array(
				'message failed',
				'failed',
				'failed',
				'WooCommerce reported the native email itself as not sent',
			),
			'message unresolved' => array(
				'message unresolved',
				'unresolved',
				'unresolved',
				"the native email's send outcome was never reported",
			),
		);
	}

	/**
	 * 7. 6B ITEM 4b. ⚠ NOTES TAKEN **BEFORE** A THROW SURVIVE INTO THE FAILURE ROW,
	 *    IN BOTH MODES (ADR-0014 §1c).
	 *
	 * Notes used to be read off `PlaceholderValues` only once subject, heading and
	 * both body formats had all resolved — and the insert catch passed `null` for
	 * the value set entirely. So an unknown token noticed BEFORE a throw vanished
	 * from the record, leaving the merchant with the exception and no sight of the
	 * earlier problem, which is the one they can actually fix.
	 *
	 * Each rule body carries the unknown token FIRST and the detonating meta key
	 * SECOND, so resolution demonstrably reaches one before the other.
	 *
	 * @return void
	 */
	public function test_partial_notes_survive_a_throw_in_both_modes() {
		$product_id = $this->make_simple_product( 'WCEP Partial Notes' );
		$order      = $this->order_for( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$separate = $this->make_sending_rule(
			$product_id,
			array(
				'name'          => 'separate, partial notes',
				'trigger_type'  => 'status',
				'trigger_value' => 'processing',
				'content'       => '<p>a=[{totally_made_up_token}] b=[{order_custom_field:' . self::TRIGGER_KEY . '}]</p>',
			)
		);

		$insert = $this->make_insert_rule(
			$product_id,
			array(
				'name'    => 'insert, partial notes',
				'content' => '<p>a=[{another_made_up_token}] b=[{order_custom_field:' . self::TRIGGER_KEY . '}]</p>',
			)
		);

		$calls = array();
		$this->detonate_on_key( self::TRIGGER_KEY, $calls );

		// --- SEPARATE MODE ----------------------------------------------------
		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$separate_tombstone = $this->tombstone( $order_id, $separate, 'status:processing' );
		$this->assertNotNull( $separate_tombstone );
		$this->track_delivery( (int) $separate_tombstone['id'] );

		$separate_rows   = $this->detail_rows( (int) $separate_tombstone['id'] );
		$separate_reason = (string) $separate_rows[0]['reason'] . ' ' . (string) $separate_rows[0]['failure_message'];

		$this->assertStringContainsString( 'RuntimeException', $separate_reason, 'the throw was not recorded' );
		$this->assertStringContainsString(
			'unknown placeholder {totally_made_up_token}',
			$separate_reason,
			'⚠ separate mode: a note taken before the throw was discarded'
		);

		// --- INSERT MODE ------------------------------------------------------
		$this->send_native( $order_id, 'WC_Email_Customer_Processing_Order' );

		$insert_tombstone = $this->insert_tombstone( $order_id, $insert );
		$this->assertNotNull( $insert_tombstone );

		$insert_reason = (string) $this->detail_rows( (int) $insert_tombstone['id'] )[0]['reason'];

		$this->assertStringContainsString( 'RuntimeException', $insert_reason, 'the throw was not recorded' );
		$this->assertStringContainsString(
			'unknown placeholder {another_made_up_token}',
			$insert_reason,
			'⚠ insert mode: the catch passed a null value set, so the earlier note was lost'
		);

		fwrite(
			STDERR,
			"\n[6B item 4b] partial notes retained through a throw:\n"
			. '  separate : ' . trim( $separate_reason ) . "\n"
			. '  insert   : ' . $insert_reason . "\n"
		);
	}
}
