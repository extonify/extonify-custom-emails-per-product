<?php
/**
 * The send-observation stack, terminal-promotion priority and token residue
 * (ADR-0013 §5e, §5g, §5h — Prompt 5D).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Render\RenderEvents;
use Extonify\WCEP\Render\RenderLedger;

/**
 * THE FIFTH AND LAST LOCATION OF ONE DEFECT.
 *
 * The same correlation defect has appeared at `current_render()`, at `bind()`, at
 * `reserve()`, at `take_completed()` and finally at `observe_send()` — one hook
 * further out each time. Every one of them was an attempt to tell two renders or
 * two sends apart by their IDENTITY, on a plugin where one shared `WC_Email`
 * object serves every delivery of its type (ADR-0002). This file holds the tests
 * for the last of them, plus the two ordinary-severity defects found alongside it.
 */
final class SendScopeTest extends InsertModeTestCase {

	/**
	 * 5D-1. A SEND NESTED FROM `woocommerce_email_attachments` FINALIZES ITS OWN
	 *       TOKEN (ADR-0013 §5g).
	 *
	 * @return void
	 */
	public function test_a_send_nested_from_attachments_finalizes_its_own_token() {
		$this->assert_nested_send_keeps_its_own_token( 'woocommerce_email_attachments', 10 );
	}

	/**
	 * 5D-1. The same, nested from `woocommerce_email_headers` by a third party that
	 * also wants to run last — so the enclosing send's frame is ALREADY OPEN when the
	 * inner send begins, which is the exact sequence the defect trace describes:
	 *
	 *     outer headers -> takes outer token
	 *     inner headers -> descriptor matches -> returns the OUTER token
	 *
	 * @return void
	 */
	public function test_a_send_nested_from_headers_finalizes_its_own_token() {
		$this->assert_nested_send_keeps_its_own_token( 'woocommerce_email_headers', PHP_INT_MAX );
	}

	/**
	 * 5D-1. `woocommerce_email_headers` again, from an ORDINARY priority.
	 *
	 * The inner send then completes before this plugin's own observation runs at all,
	 * so no frame is open when it begins — the arrangement the pre-5D handoff DID
	 * survive. Kept so the fix is pinned for both orderings rather than only the one
	 * that used to break.
	 *
	 * @return void
	 */
	public function test_a_send_nested_from_headers_before_the_observation_keeps_both_tokens() {
		$this->assert_nested_send_keeps_its_own_token( 'woocommerce_email_headers', 10 );
	}

	/**
	 * Drive one nested-send case end to end.
	 *
	 * SAME singleton, SAME native email id, SAME audience — only the ORDER differs,
	 * and the order is exactly what the handoff is forbidden to validate against
	 * (ADR-0013 §5d: WooCommerce does not restore `$email->object` after a nested
	 * render). So nothing about identity can separate the two sends and only the
	 * depth of the observation stack can.
	 *
	 * ⚠ WHAT THIS FAILED AS BEFORE PROMPT 5D. The handoff held one token plus one
	 * `{spl, email_id}` descriptor, and the descriptor decided whether an
	 * observation belonged to the send already in progress. For a nested send
	 * through one singleton that equality is TRUE, so:
	 *
	 *     outer headers      -> takes the outer token
	 *     inner headers      -> descriptor matches -> returns the OUTER token
	 *     inner send         -> FINALIZES the OUTER token with the INNER result
	 *     outer attachments  -> descriptor cleared -> takes the remaining
	 *                           candidate, which is the INNER token
	 *     outer send         -> FINALIZES the INNER token
	 *
	 * Each message recorded the other's delivery.
	 *
	 * @param string $hook     Observation filter to nest the second send from.
	 * @param int    $priority Priority the third party nests at. Below `PHP_INT_MAX`
	 *                         it runs before this plugin's own observation; at
	 *                         `PHP_INT_MAX` it is registered later than ours and
	 *                         therefore runs after it.
	 * @return void
	 */
	private function assert_nested_send_keeps_its_own_token( string $hook, int $priority ) {
		$tag           = md5( $hook . '|' . $priority );
		$outer_product = $this->make_simple_product( 'WCEP Nested Send Outer ' . $tag );
		$inner_product = $this->make_simple_product( 'WCEP Nested Send Inner ' . $tag );

		$outer_id = $this->order_for( $outer_product, 'wcep-outer-' . $tag . '@example.test' );
		$inner_id = $this->order_for( $inner_product, 'wcep-inner-' . $tag . '@example.test' );

		$outer_rule = $this->make_insert_rule( $outer_product, array( 'content' => '<p>OUTER SEND BLOCK.</p>' ) );
		$inner_rule = $this->make_insert_rule( $inner_product, array( 'content' => '<p>INNER SEND BLOCK.</p>' ) );

		/*
		 * MAKE THE SUBJECT NAME ITS OWN ORDER. `WC_Email` evaluates `get_subject()`
		 * before `get_content()` and before either observation filter, so each send's
		 * subject is computed while `$email->object` still points at that send's own
		 * order — which is what lets the assertions below pair a captured subject
		 * with the order it was for.
		 */
		$this->hook(
			'woocommerce_email_subject_customer_processing_order',
			static function ( $subject, $object = null, $email = null ) {
				return $subject . ' #' . ( is_object( $object ) ? (string) $object->get_id() : '0' );
			},
			10,
			3
		);

		$fired = false;

		/*
		 * A GUARDED, COMPLETE, NESTED NATIVE SEND, through the SAME singleton — the
		 * shape a shipping plugin, an accounting plugin or an ERP bridge produces
		 * when it sends its own notification from a mail hook.
		 */
		$this->hook(
			$hook,
			function ( $value, $id = '', $subject = null, $email = null ) use ( &$fired, $inner_id ) {
				if ( ! $fired && is_object( $email ) ) {
					$fired = true;
					$email->trigger( $inner_id );
				}

				return $value;
			},
			$priority,
			4
		);

		$this->native_email()->trigger( $outer_id );

		$this->assertTrue( $fired, 'The nested send never happened.' );
		$this->assertMailCount( 2, 'The nested send did not produce its own message.' );

		// --- Each MESSAGE carries its own order's content, recipient and subject.
		$inner_mail = $this->mail_to( 'wcep-inner-' . $tag . '@example.test' );
		$outer_mail = $this->mail_to( 'wcep-outer-' . $tag . '@example.test' );

		$this->assertBody( $inner_mail, 'INNER SEND BLOCK.', true, 'The inner message lost its own content.' );
		$this->assertBody( $inner_mail, 'OUTER SEND BLOCK.', false, 'The inner message carried the outer order.' );
		$this->assertBody( $outer_mail, 'OUTER SEND BLOCK.', true, 'The outer message lost its own content.' );
		$this->assertBody( $outer_mail, 'INNER SEND BLOCK.', false, 'The outer message carried the inner order.' );

		$this->assertStringContainsString(
			(string) $inner_id,
			(string) $inner_mail['subject'],
			'The inner message did not carry the inner order number.'
		);
		$this->assertStringContainsString(
			(string) $outer_id,
			(string) $outer_mail['subject'],
			'The outer message did not carry the outer order number.'
		);

		/*
		 * --- AND EACH SEND FINALIZED ITS OWN TOKEN. -------------------------------
		 * The inner send completes first in both cases, so its finalization is
		 * recorded first. What matters is the pairing, not the order: the token each
		 * send bound is the token of the render that produced ITS message.
		 */
		$finalizations = RenderEvents::ledger()->finalizations();
		$this->assertCount( 2, $finalizations, 'Two sends did not produce two finalizations.' );

		$this->assertSame( $finalizations[0]['bound'], $finalizations[0]['resolved'] );
		$this->assertSame( $finalizations[1]['bound'], $finalizations[1]['resolved'] );
		$this->assertNotSame( $finalizations[0]['resolved'], $finalizations[1]['resolved'], 'Both sends finalized ONE token.' );

		$this->assertSame( $inner_id, (int) $finalizations[0]['order_id'], 'The inner send finalized the OUTER render.' );
		$this->assertSame( $outer_id, (int) $finalizations[1]['order_id'], 'The outer send finalized the INNER render.' );

		// --- The delivery log says the same thing, in both directions. ------------
		$inner_tombstone = $this->insert_tombstone( $inner_id, $inner_rule );
		$outer_tombstone = $this->insert_tombstone( $outer_id, $outer_rule );

		$this->assertNotNull( $inner_tombstone, 'The nested send recorded no delivery.' );
		$this->assertNotNull( $outer_tombstone, 'The enclosing send recorded no delivery.' );
		$this->assertSame( 'sent', $inner_tombstone['final_status'] );
		$this->assertSame( 'sent', $outer_tombstone['final_status'] );

		$this->assertNull( $this->insert_tombstone( $outer_id, $inner_rule ), 'The inner rule was recorded against the outer order.' );
		$this->assertNull( $this->insert_tombstone( $inner_id, $outer_rule ), 'The outer rule was recorded against the inner order.' );

		// --- Neither slot was abandoned, and no token was released. ---------------
		$this->assertSame( array(), RenderEvents::ledger()->slots(), 'A slot survived two complete sends.' );
		$this->assertSame( 0, RenderEvents::ledger()->released_tokens(), 'A taken token was dropped.' );
		$this->assertSame( 0, RenderEvents::ledger()->unidentified_sends(), 'A send could not identify its render.' );
		$this->assertSame( array(), RenderEvents::ledger()->send_frames(), 'A send-observation frame survived its send.' );

		$this->run_shutdown_sweep();

		foreach ( array( $inner_tombstone, $outer_tombstone ) as $tombstone ) {
			$rows = $this->detail_rows( (int) $tombstone['id'] );
			$this->assertCount( 1, $rows, 'A completed send grew a second attempt row at the sweep.' );
			$this->assertSame( 'sent', $rows[0]['state'] );
			$this->assertSame( 'auto', $rows[0]['type'] );
		}

		$reservations = RenderEvents::ledger()->reservation_log();
		$this->assertCount( 2, $reservations );

		fwrite(
			STDERR,
			sprintf(
				"\n[5D item 1] send nested from %s at priority %s — outer order %d, inner order %d:\n"
				. "  reserved  %s (enclosed by %d further send frames)  -> finalized %s -> order %d  [INNER message]\n"
				. "  reserved  %s (enclosed by %d further send frames)  -> finalized %s -> order %d  [OUTER message]\n"
				. "  released tokens %d, unidentified sends %d\n",
				$hook,
				PHP_INT_MAX === $priority ? 'PHP_INT_MAX' : (string) $priority,
				$outer_id,
				$inner_id,
				(string) $reservations[0]['token'],
				(int) $reservations[0]['enclosed'],
				(string) $finalizations[0]['resolved'],
				(int) $finalizations[0]['order_id'],
				(string) $reservations[1]['token'],
				(int) $reservations[1]['enclosed'],
				(string) $finalizations[1]['resolved'],
				(int) $finalizations[1]['order_id'],
				RenderEvents::ledger()->released_tokens(),
				RenderEvents::ledger()->unidentified_sends()
			)
		);
	}

	/**
	 * 5D-2. THE TERMINAL PROMOTION RUNS AT THE HIGHEST PRIORITY AVAILABLE, and the
	 *       observation still runs after it (ADR-0013 §5e).
	 *
	 * ⚠ 999 IS NOT "AFTER ANY THIRD PARTY", WHICH IS WHAT THE OLD DOCBLOCK CLAIMED.
	 * WordPress runs LOWER priority numbers FIRST, so every callback at 1000 or above
	 * ran after this plugin's promotion.
	 *
	 * @return void
	 */
	public function test_the_render_callbacks_are_registered_at_the_priorities_the_adr_states() {
		$expected = array(
			array( 'woocommerce_email_order_details', 'on_render_start', 5 ),
			array( 'woocommerce_email_order_details', 'on_render_end', 15 ),
			array( 'woocommerce_email_customer_details', 'on_emissions_end', PHP_INT_MAX ),
			array( 'woocommerce_email_footer', 'on_footer', PHP_INT_MAX ),
			array( 'woocommerce_pos_email_footer', 'on_footer', PHP_INT_MAX ),
			array( 'woocommerce_email_headers', 'on_send_headers', PHP_INT_MAX ),
			array( 'woocommerce_email_attachments', 'on_send_attachments', PHP_INT_MAX ),
			array( 'woocommerce_mail_content', 'on_mail_content', PHP_INT_MIN ),
			array( 'woocommerce_mail_callback_params', 'on_mail_params', 9999 ),
			array( 'woocommerce_email_sent', 'on_email_sent', 10 ),
		);

		$registered = array();

		foreach ( $expected as $entry ) {
			list( $hook, $method, $priority ) = $entry;

			$actual = has_filter( $hook, array( RenderEvents::class, $method ) );

			$this->assertSame(
				$priority,
				$actual,
				sprintf( '%s::%s() is registered on %s at %s.', 'RenderEvents', $method, $hook, var_export( $actual, true ) )
			);

			$registered[] = sprintf( '%s@%s', $method, PHP_INT_MAX === $priority ? 'PHP_INT_MAX' : ( PHP_INT_MIN === $priority ? 'PHP_INT_MIN' : (string) $priority ) );
		}

		fwrite( STDERR, "\n[5D item 2] registrations: " . implode( '  ', $registered ) . "\n" );
	}

	/**
	 * 5D-2. PROMOTION STILL HAPPENS BEFORE THE SEND OBSERVATION, though both now sit
	 *       at `PHP_INT_MAX`.
	 *
	 * They are on DIFFERENT hooks — `woocommerce_email_customer_details` and the
	 * footers fire while `get_content()` is running, and `woocommerce_email_headers`
	 * fires afterwards as the next argument of the same `send()` call — so raising
	 * promotion to `PHP_INT_MAX` cannot reorder them. Asserted rather than argued,
	 * because that is the relationship the whole handoff rests on.
	 *
	 * @return void
	 */
	public function test_promotion_runs_before_the_send_observation() {
		$product_id = $this->make_simple_product( 'WCEP Promotion Order' );
		$order_id   = $this->order_for( $product_id );
		$rule_id    = $this->make_insert_rule( $product_id, array( 'content' => '<p>ORDERING BLOCK.</p>' ) );

		$sequence = array();

		// Registered AFTER this plugin's own callbacks at the same priority, so each
		// records the moment just after ours has run.
		$this->hook(
			'woocommerce_email_customer_details',
			function ( $order = null, $admin = false, $plain = false, $email = null ) use ( &$sequence ) {
				if ( is_object( $email ) ) {
					$sequence[] = 'promoted:' . count( (array) ( RenderEvents::ledger()->candidates()[ spl_object_id( $email ) ] ?? array() ) );
				}
			},
			PHP_INT_MAX,
			4
		);

		$this->hook(
			'woocommerce_email_headers',
			function ( $headers, $id = '', $subject = null, $email = null ) use ( &$sequence ) {
				if ( is_object( $email ) ) {
					$sequence[] = 'observed:' . count( RenderEvents::ledger()->send_frames() );
				}

				return $headers;
			},
			PHP_INT_MAX,
			4
		);

		$mail = $this->send_native( $order_id );

		$this->assertBody( $mail, 'ORDERING BLOCK.', true );
		$this->assertSame(
			array( 'promoted:1', 'observed:1' ),
			$sequence,
			'The terminal promotion and the send observation changed order.'
		);

		$this->assertSame( 'sent', $this->insert_tombstone( $order_id, $rule_id )['final_status'] );

		fwrite( STDERR, "\n[5D item 2] runtime order at PHP_INT_MAX: " . implode( ' -> ', $sequence ) . "\n" );
	}

	/**
	 * 5D-2. A RENDER NESTED BY A PLUGIN AT PRIORITY 1000 CANNOT DISPLACE THE RENDER
	 *       THAT ENCLOSES IT — `customer_details`, HTML.
	 *
	 * ⚠ HONEST NOTE ON WHAT THIS ONE PROVES. In HTML the footer re-promotion
	 * (ADR-0013 §5e) already rescued this case at priority 999, so it passed before
	 * the fix as well. It is here because the prompt requires all three, and because
	 * it pins the behaviour if the footer backstop is ever weakened. The two cases
	 * that FAIL at 999 are the footer one below and the plain-text one after it.
	 *
	 * @return void
	 */
	public function test_a_render_nested_at_priority_1000_from_customer_details_cannot_displace_the_outer_send() {
		$this->assert_late_priority_nesting_keeps_the_outer_send( 'woocommerce_email_customer_details', false );
	}

	/**
	 * 5D-2. The same, from `woocommerce_email_footer` — nothing of ours fires after
	 * the footer, so at priority 999 this was unrecoverable.
	 *
	 * @return void
	 */
	public function test_a_render_nested_at_priority_1000_from_the_footer_cannot_displace_the_outer_send() {
		$this->assert_late_priority_nesting_keeps_the_outer_send( 'woocommerce_email_footer', false );
	}

	/**
	 * 5D-2. `customer_details` again, in PLAIN TEXT — where there is no footer to
	 * rescue anything (⚠ only `plain/customer-cancelled-order.php` fires one in the
	 * whole WC 10.9.4 tree), so priority 999 lost the outer render outright.
	 *
	 * @return void
	 */
	public function test_a_plain_text_render_nested_at_priority_1000_cannot_displace_the_outer_send() {
		$this->assert_late_priority_nesting_keeps_the_outer_send( 'woocommerce_email_customer_details', true );
	}

	/**
	 * Drive one late-priority nesting case end to end.
	 *
	 * @param string $hook       Hook the third party nests from.
	 * @param bool   $plain_text Render the plain-text template.
	 * @return void
	 */
	private function assert_late_priority_nesting_keeps_the_outer_send( string $hook, bool $plain_text ) {
		$label      = $plain_text ? 'plain' : 'html';
		$product_id = $this->make_simple_product( 'WCEP Late Priority ' . $hook . ' ' . $label );
		$order_id   = $this->order_for( $product_id );

		$rule_id = $this->make_insert_rule( $product_id, array( 'content' => '<p>LATE PRIORITY BLOCK.</p>' ) );

		$fired       = false;
		$inner_token = null;
		$queue       = array();

		$this->capture_candidates_at_send( $queue );

		/*
		 * PRIORITY 1000 — one above the priority this plugin used to promote at, and
		 * an entirely ordinary number for a plugin that wants to run last.
		 */
		$this->hook(
			$hook,
			function () use ( &$fired, &$inner_token, $order_id ) {
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
			1000,
			4
		);

		$mail = $this->send_native( $order_id, 'WC_Email_Customer_Processing_Order', $plain_text );

		$this->assertTrue( $fired, 'The priority-1000 nesting never happened.' );
		$this->assertMailCount( 1, 'The nested render produced a message of its own.' );
		$this->assertBody( $mail, 'LATE PRIORITY BLOCK.', true, 'The outer render lost its insertion.' );

		// --- THE INVARIANT, OBSERVED AT THE HANDOFF. ------------------------------
		$this->assertCount( 2, $queue, 'Both renders should have been promoted before the send.' );
		$this->assertSame( $inner_token, $queue[0], 'The inner render did not promote FIRST.' );

		$outer_token = $queue[1];
		$this->assertNotSame( $inner_token, $outer_token, 'The two renders shared a token.' );

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
				"\n[5D item 2] third party at PRIORITY 1000 on %s (%s): queue at handoff = [%s]\n"
				. "  inner %s promoted first; outer %s promoted LAST and was taken by the send\n",
				$hook,
				$label,
				implode( ', ', $queue ),
				(string) $inner_token,
				(string) $outer_token
			)
		);
	}

	/**
	 * 5D-3. A POS RECEIPT LEAVES NO OPEN RENDER TOKEN (ADR-0013 §5h).
	 *
	 * ⚠ THE TWO WC 10.9.4 POS TEMPLATES FIRE `woocommerce_pos_email_footer` AND NO
	 * `woocommerce_email_footer` (`customer-pos-completed-order.php:122`,
	 * `customer-pos-refunded-order.php:133`). Prompt 5C recorded them as firing "no
	 * footer at all", which was true only of the hook this plugin was listening to —
	 * so every POS receipt left one permanent `open_footer` entry on the
	 * request-shared context.
	 *
	 * @dataProvider pos_email_classes
	 * @param string $class_name POS email class.
	 * @return void
	 */
	public function test_a_pos_receipt_leaves_no_open_render_tokens( string $class_name ) {
		$email = $this->pos_email( $class_name );

		$product_id = $this->make_simple_product( 'WCEP POS ' . $class_name );
		$order_id   = $this->order_for( $product_id );

		$before = RenderEvents::context()->open_token_counts();

		$email->trigger( $order_id, $email->id );

		$this->assertMailCount( 1, 'The POS receipt did not send.' );

		$this->assertCount(
			1,
			RenderEvents::ledger()->finalizations(),
			'The POS receipt reached no render slot at all — the template stopped firing order details.'
		);

		$after = RenderEvents::context()->open_token_counts();

		$this->assertSame(
			array(
				'open_details' => 0,
				'open_footer'  => 0,
			),
			$after,
			'A POS receipt left an open render token behind.'
		);
		$this->assertSame( $before, $after );
		$this->assertSame( array(), RenderEvents::context()->renders(), 'The POS render record was never retired.' );

		fwrite(
			STDERR,
			sprintf(
				"\n[5D item 3] %s: open_details=%d open_footer=%d after one receipt\n",
				$email->id,
				$after['open_details'],
				$after['open_footer']
			)
		);
	}

	/**
	 * The two POS receipt classes.
	 *
	 * @return array[]
	 */
	public function pos_email_classes(): array {
		return array(
			'completed' => array( 'WC_Email_Customer_POS_Completed_Order' ),
			'refunded'  => array( 'WC_Email_Customer_POS_Refunded_Order' ),
		);
	}

	/**
	 * 5D-3. TWENTY POS SENDS THROUGH ONE SINGLETON DO NOT GROW THE LEDGERS.
	 *
	 * The residue was one entry per send, permanently, on an object shared by the
	 * whole request. Not a wrong-email event — exactly the unbounded per-request
	 * growth gate 10 exists to forbid.
	 *
	 * @return void
	 */
	public function test_twenty_pos_sends_do_not_grow_the_open_token_ledgers() {
		$email = $this->pos_email( 'WC_Email_Customer_POS_Completed_Order' );

		$product_id = $this->make_simple_product( 'WCEP POS Twenty' );
		$order_id   = $this->order_for( $product_id );

		$peak = 0;

		for ( $i = 0; $i < 20; $i++ ) {
			$email->trigger( $order_id, $email->id );

			$counts = RenderEvents::context()->open_token_counts();
			$peak   = max( $peak, $counts['open_details'] + $counts['open_footer'] );
		}

		$this->assertMailCount( 20 );
		$this->assertSame( 0, $peak, 'The open-token ledgers grew across twenty POS sends.' );
		$this->assertCount( 20, RenderEvents::ledger()->finalizations() );
		$this->assertSame( array(), RenderEvents::context()->renders(), 'Twenty POS sends left twenty render records.' );
		$this->assertSame( array(), RenderEvents::ledger()->slots(), 'Twenty POS sends left twenty open slots.' );

		fwrite( STDERR, "\n[5D item 3] 20 POS receipts through one singleton: peak open tokens = {$peak}\n" );
	}

	/**
	 * 5D-3. A CUSTOM HTML TEMPLATE THAT FIRES NO FOOTER HOOK AT ALL still has its
	 *       EXACT token purged at finalization.
	 *
	 * This is why the purge exists rather than a second hook registration: knowing
	 * about `woocommerce_pos_email_footer` fixes the templates WooCommerce ships
	 * today, and nothing about the ones a theme or plugin writes.
	 *
	 * @return void
	 */
	public function test_a_template_with_no_footer_has_its_exact_token_purged_at_finalization() {
		$product_id = $this->make_simple_product( 'WCEP No Footer Template' );
		$order_id   = $this->order_for( $product_id );
		$rule_id    = $this->make_insert_rule( $product_id, array( 'content' => '<p>NO FOOTER BLOCK.</p>' ) );

		$fixture = dirname( __DIR__ ) . '/fixtures/email-no-footer.php';
		$this->assertFileExists( $fixture );

		$this->hook(
			'wc_get_template',
			static function ( $template, $template_name = '', $args = array(), $template_path = '', $default_path = '' ) use ( $fixture ) {
				return 'emails/customer-processing-order.php' === $template_name ? $fixture : $template;
			},
			10,
			5
		);

		$footer_fired = false;
		$this->hook(
			'woocommerce_email_footer',
			static function ( $email = null ) use ( &$footer_fired ) {
				$footer_fired = true;
			},
			1,
			1
		);

		$mail = $this->send_native( $order_id );

		$this->assertBody( $mail, 'NO FOOTER TEMPLATE.', true, 'The custom template was not used.' );
		$this->assertBody( $mail, 'NO FOOTER BLOCK.', true, 'The rule did not insert into the custom template.' );
		$this->assertFalse( $footer_fired, 'The fixture template fired a footer after all.' );

		$this->assertSame(
			array(
				'open_details' => 0,
				'open_footer'  => 0,
			),
			RenderEvents::context()->open_token_counts(),
			'A template firing no footer left its token open forever.'
		);

		$this->assertSame( array(), RenderEvents::context()->renders(), 'The render record outlived its send.' );
		$this->assertSame( 'sent', $this->insert_tombstone( $order_id, $rule_id )['final_status'] );

		fwrite( STDERR, "\n[5D item 3] custom template, no footer hook fired: open tokens = 0 after finalization\n" );
	}

	/**
	 * 5D-3. PLAIN TEXT IS UNCHANGED: it never fires a footer, and its token was
	 *       already purged at the priority-15 close.
	 *
	 * @return void
	 */
	public function test_plain_text_leaves_no_open_tokens() {
		$product_id = $this->make_simple_product( 'WCEP Plain Residue' );
		$order_id   = $this->order_for( $product_id );
		$rule_id    = $this->make_insert_rule( $product_id, array( 'content' => '<p>PLAIN RESIDUE BLOCK.</p>' ) );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->send_native( $order_id, 'WC_Email_Customer_Processing_Order', true );
		}

		$this->assertSame(
			array(
				'open_details' => 0,
				'open_footer'  => 0,
			),
			RenderEvents::context()->open_token_counts()
		);

		$this->assertSame( 'sent', $this->insert_tombstone( $order_id, $rule_id )['final_status'] );

		fwrite( STDERR, "\n[5D item 3] 3 plain-text sends: open tokens = 0 (unchanged behaviour)\n" );
	}

	/**
	 * 5D-3. `shutdown()` CLEARS EVERY PER-REQUEST COLLECTION IT RECONCILES.
	 *
	 * An interrupted render — one that pushes a frame and never closes it, which is
	 * what an exception inside a template produces — used to leave the frame, both of
	 * its open tokens and its record behind, so `in_email()` kept reporting an active
	 * render for the rest of the request and the shared item-meta hook stayed armed.
	 *
	 * @return void
	 */
	public function test_shutdown_clears_every_render_collection() {
		$product_id = $this->make_simple_product( 'WCEP Shutdown Residue' );
		$order_id   = $this->order_for( $product_id );

		$context = RenderEvents::context();
		$email   = $this->native_email();

		// A render that begins and never finishes: exactly what a throwing template
		// leaves behind.
		RenderEvents::on_render_start( wc_get_order( $order_id ), false, false, $email );

		$this->assertTrue( $context->in_email(), 'The interrupted render pushed no frame.' );
		$this->assertNotSame( array(), $context->renders() );
		$this->assertSame(
			array(
				'open_details' => 1,
				'open_footer'  => 1,
			),
			$context->open_token_counts()
		);

		$this->run_shutdown_sweep();

		$this->assertFalse( $context->in_email(), 'A frame survived shutdown.' );
		$this->assertSame( 0, $context->depth() );
		$this->assertSame( array(), $context->renders(), 'A render record survived shutdown.' );
		$this->assertSame(
			array(
				'open_details' => 0,
				'open_footer'  => 0,
			),
			$context->open_token_counts(),
			'An open token survived shutdown.'
		);
		$this->assertSame( array(), RenderEvents::ledger()->slots(), 'A slot survived shutdown.' );
		$this->assertSame( array(), RenderEvents::ledger()->send_frames(), 'A send frame survived shutdown.' );

		fwrite( STDERR, "\n[5D item 3 / gate 10] after shutdown: frames=0 renders=0 open_details=0 open_footer=0 slots=0 send_frames=0\n" );
	}

	/**
	 * An order carrying one product, with its own billing address.
	 *
	 * @param int    $product_id Product to buy.
	 * @param string $email      Billing email, so a captured message can be paired
	 *                           with the order it belongs to.
	 * @return int Order id.
	 */
	private function order_for( int $product_id, string $email = '' ): int {
		$order = $this->make_order_with( array( $product_id ) );

		if ( '' !== $email ) {
			$order->set_billing_email( $email );
			$order->save();
		}

		return (int) $order->get_id();
	}

	/**
	 * The captured message addressed to one recipient.
	 *
	 * @param string $recipient Billing email.
	 * @return array
	 */
	private function mail_to( string $recipient ): array {
		foreach ( $this->captured_mail as $mail ) {
			$to = is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : (string) $mail['to'];

			if ( false !== strpos( $to, $recipient ) ) {
				return $mail;
			}
		}

		$this->fail( 'No message was captured for ' . $recipient . '.' );
	}

	/**
	 * The live registered POS receipt email, or skip.
	 *
	 * ⚠ POS emails are registered ONLY when WooCommerce's `point_of_sale` feature is
	 * enabled, so this is a genuine capability check rather than a convenience.
	 *
	 * @param string $class_name POS email class.
	 * @return \WC_Email
	 */
	private function pos_email( string $class_name ): \WC_Email {
		$emails = WC()->mailer()->get_emails();

		if ( ! isset( $emails[ $class_name ] ) ) {
			$this->markTestSkipped( $class_name . ' is not registered — WooCommerce\'s point_of_sale feature is off.' );
		}

		return $emails[ $class_name ];
	}
}
