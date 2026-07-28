<?php
/**
 * Re-entrancy: a delivery started from inside a delivery (ADR-0012 §11).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\Events;
use Extonify\WCEP\Delivery\Orchestrator;
use Extonify\WCEP\Domain\TriggerEvent;

/**
 * ONE orchestrator serves a whole request, and a send can re-enter the delivery
 * lifecycle.
 *
 * `Events` builds the orchestrator once and reuses it, so that several events in
 * one request share the item-resolver cache. Meanwhile a send runs arbitrary
 * third-party code: SMTP plugins, `woocommerce_email_sent` listeners, fulfilment
 * integrations. Any of them may change ANOTHER order's status, and that inner
 * event reaches the same orchestrator — to completion — before the outer run's
 * loop has finished.
 *
 * While the rule snapshot lived on the orchestrator, the inner run REPLACED it.
 * The outer loop then resumed at its second rule and looked that rule up in the
 * inner run's map: either it was absent and the email was silently never sent,
 * or an unrelated inner rule with the same id answered and the customer received
 * THAT rule's subject, content and recipients, with `rule_revision_sent`
 * recording a revision belonging to a different rule entirely.
 *
 * This is the identical nested-lifecycle failure the render-context POC spent
 * four passes on, arriving on a different object.
 */
final class NestedDeliveryTest extends DeliveryTestCase {

	/**
	 * Callbacks registered by a test, removed on teardown.
	 *
	 * @var array[]
	 */
	private $hooks = array();

	/**
	 * Remove anything a test hooked.
	 *
	 * @after
	 * @return void
	 */
	protected function tear_down_nested_hooks() {
		foreach ( $this->hooks as $hook ) {
			list( $tag, $callback, $priority ) = $hook;
			remove_action( $tag, $callback, $priority );
		}

		$this->hooks = array();
	}

	/**
	 * Register a callback and remember it for teardown.
	 *
	 * @param string   $tag      Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted arguments.
	 * @return void
	 */
	private function hook( string $tag, callable $callback, int $priority = 10, int $args = 4 ): void {
		add_action( $tag, $callback, $priority, $args );
		$this->hooks[] = array( $tag, $callback, $priority );
	}

	/**
	 * Switch WooCommerce's OWN transactional emails off for this test.
	 *
	 * The nested event is a REAL `update_status()` call, so WooCommerce would
	 * otherwise send its processing/new-order notifications too and the exact
	 * message counts below would be measuring core rather than this plugin.
	 * This plugin's own email is untouched.
	 *
	 * @return void
	 */
	private function silence_core_emails(): void {
		foreach ( array( 'new_order', 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order' ) as $id ) {
			$this->hook( 'woocommerce_email_enabled_' . $id, '__return_false', 10, 3 );
		}
	}

	/**
	 * Record what the SHARED email object held at the moment each message went
	 * out, read from `woocommerce_email_sent` — the hook WooCommerce itself
	 * raises inside `WC_Email::send()`, with `$this` passed through.
	 *
	 * This is the observation that catches a destroyed frame: a listener on the
	 * OUTER send used to read `object === null` and `delivery_id === 0`, because
	 * the nested call's `finally` had already cleared them.
	 *
	 * @param array $log Collected observations, by reference.
	 * @return void
	 */
	private function observe_sends( array &$log ): void {
		$this->hook(
			'woocommerce_email_sent',
			static function ( $sent, $id = '', $email = null ) use ( &$log ) {
				if ( ! $email instanceof \Extonify\WCEP\Email\Custom_Email ) {
					return;
				}

				$log[] = array(
					'subject'     => $email->get_subject(),
					'recipient'   => $email->recipient,
					'cc'          => $email->cc,
					'bcc'         => $email->bcc,
					'delivery_id' => $email->delivery_id,
					'order_id'    => $email->object instanceof \WC_Order ? (int) $email->object->get_id() : 0,
				);
			},
			20,
			3
		);
	}

	/**
	 * Wire a one-shot re-entry: the first time `$tag` fires, change the inner
	 * order's status for real, which reaches the shared orchestrator through the
	 * real `woocommerce_order_status_changed` hook.
	 *
	 * @param string       $tag          Hook to re-enter from.
	 * @param Orchestrator $orchestrator The SHARED orchestrator, as `Events` holds one.
	 * @param int          $inner_id     Order to transition.
	 * @param int          $accepted     Arguments the hook passes.
	 * @return \stdClass Carries `fired`, so a test can assert the re-entry happened.
	 */
	private function reenter_from( string $tag, Orchestrator $orchestrator, int $inner_id, int $accepted = 3 ): \stdClass {
		$state        = new \stdClass();
		$state->fired = false;

		$this->hook(
			'woocommerce_order_status_changed',
			static function ( $order_id, $from = '', $to = '' ) use ( $orchestrator, $inner_id ) {
				if ( (int) $order_id === $inner_id ) {
					$orchestrator->handle_status_change( (int) $order_id, (string) $from, (string) $to );
				}
			},
			10,
			3
		);

		// Priority 1 so the re-entry happens as early as possible within the
		// hook, before anything else has a chance to read the shared object.
		$this->hook(
			$tag,
			static function ( $value = null ) use ( $state, $inner_id ) {
				if ( ! $state->fired ) {
					$state->fired = true;
					wc_get_order( $inner_id )->update_status( 'processing', 'nested fixture' );
				}
				return $value;
			},
			1,
			$accepted
		);

		return $state;
	}

	/**
	 * THE EARLIEST RE-ENTRY POINT THERE IS: the outer delivery's own
	 * `woocommerce_email_enabled_{id}` filter (ADR-0012 §11a).
	 *
	 * `trigger()` sets every runtime field, then calls `is_enabled()` — which
	 * applies that third-party filter BEFORE `get_recipient()`, `get_content()`
	 * and `get_headers()` are ever evaluated. A filter there that changes another
	 * order's status runs an entire inner delivery through this same shared
	 * object first.
	 *
	 * Under the previous clearing `finally`, the inner call handed the outer one
	 * an EMPTY object: the outer saw `recipient === ''`, returned `no_recipient`,
	 * and its ALREADY-CLAIMED identity was permanently recorded as skipped. The
	 * customer's email was silently never sent and could never be retried.
	 *
	 * @return void
	 */
	public function test_re_entry_from_the_enabled_filter_leaves_the_outer_delivery_intact() {
		$this->silence_core_emails();

		$outer_product = $this->make_simple_product( 'WCEP Early Outer' );
		$inner_product = $this->make_simple_product( 'WCEP Early Inner' );

		$outer_order = $this->make_order_with( array( $outer_product ) );
		$outer_order->set_billing_email( 'outer-early@example.test' );
		$outer_order->save();
		$outer_id = (int) $outer_order->get_id();

		$inner_order = $this->make_order_with( array( $inner_product ) );
		$inner_id    = (int) $inner_order->get_id();

		$outer_rule = $this->make_sending_rule(
			$outer_product,
			array(
				'name'       => 'outer (early re-entry)',
				'subject'    => 'OUTER early subject',
				'content'    => '<p>OUTER early body.</p>',
				'recipients' => array(
					'to'  => array( 'customer' ),
					'cc'  => array( 'outer-cc@example.test' ),
					'bcc' => array( 'outer-bcc@example.test' ),
				),
			)
		);

		$inner_rule = $this->make_sending_rule(
			$inner_product,
			array(
				'name'          => 'inner (early re-entry)',
				'trigger_value' => 'processing',
				'subject'       => 'INNER early subject',
				'content'       => '<p>INNER early body.</p>',
				'recipients'    => array( 'to' => array( 'inner-early@example.test' ) ),
			)
		);

		$orchestrator = $this->orchestrator();

		$log = array();
		$this->observe_sends( $log );

		$state = $this->reenter_from(
			'woocommerce_email_enabled_' . \Extonify\WCEP\Email\EmailIdentity::EMAIL_ID,
			$orchestrator,
			$inner_id,
			3
		);

		$outcome = $orchestrator->run( wc_get_order( $outer_id ), TriggerEvent::status( 'completed' ) );

		$this->assertTrue( $state->fired, 'The enabled filter never re-entered, so this test proves nothing.' );

		// --- Two deliveries: the inner one completed inside the outer one. ---
		$this->assertMailCount( 2, 'Expected the nested inner delivery and then the outer one.' );

		list( $inner_mail, $outer_mail ) = $this->captured_mail;

		$this->assertSame( 'INNER early subject', $inner_mail['subject'] );
		$this->assertSame( 'inner-early@example.test', $inner_mail['to'] );
		$this->assertStringContainsString( 'INNER early body.', (string) $inner_mail['message'] );

		// THE ASSERTION THIS TEST EXISTS FOR. Without capture-and-restore the
		// outer message does not exist at all.
		$this->assertSame( 'OUTER early subject', $outer_mail['subject'], 'The outer delivery lost its subject to the nested call.' );
		$this->assertSame( 'outer-early@example.test', $outer_mail['to'], 'The outer delivery lost its recipient to the nested call.' );
		$this->assertStringContainsString( 'OUTER early body.', (string) $outer_mail['message'] );
		$this->assertStringNotContainsString( 'INNER early body.', (string) $outer_mail['message'] );

		$outer_headers = $this->headers_of( $outer_mail );
		$this->assertStringContainsString( 'outer-cc@example.test', $outer_headers, 'The outer Cc was destroyed by the nested call.' );
		$this->assertStringContainsString( 'outer-bcc@example.test', $outer_headers, 'The outer Bcc was destroyed by the nested call.' );

		$inner_headers = $this->headers_of( $inner_mail );
		$this->assertStringNotContainsString( 'outer-cc@example.test', $inner_headers, 'The outer Cc leaked into the inner message.' );
		$this->assertStringNotContainsString( 'outer-bcc@example.test', $inner_headers, 'The outer Bcc leaked into the inner message.' );

		// --- The outer identity finalises SENT, not skipped. -----------------
		$outer_tombstone = $this->tombstone( $outer_id, $outer_rule, 'status:completed' );
		$this->assertNotNull( $outer_tombstone );
		$this->track_delivery( (int) $outer_tombstone['id'] );
		$this->assertSame(
			'sent',
			$outer_tombstone['final_status'],
			'The outer delivery was recorded as skipped on an identity it had already claimed.'
		);

		$outer_rows = $this->detail_rows( (int) $outer_tombstone['id'] );
		$this->assertCount( 3, $outer_rows, 'One row per resolved recipient: to, cc, bcc.' );
		foreach ( $outer_rows as $row ) {
			$this->assertSame( 'sent', $row['state'] );
			$this->assertStringNotContainsString( 'no recipient', (string) $row['reason'] );
		}

		$inner_tombstone = $this->tombstone( $inner_id, $inner_rule, 'status:processing' );
		$this->assertNotNull( $inner_tombstone );
		$this->track_delivery( (int) $inner_tombstone['id'] );
		$this->assertSame( 'sent', $inner_tombstone['final_status'] );

		// --- What the SHARED object held at each send. -----------------------
		$this->assertCount( 2, $log, 'woocommerce_email_sent did not fire once per delivery.' );

		$this->assertSame(
			array(
				'subject'     => 'INNER early subject',
				'recipient'   => 'inner-early@example.test',
				'cc'          => '',
				'bcc'         => '',
				'delivery_id' => (int) $inner_tombstone['id'],
				'order_id'    => $inner_id,
			),
			$log[0],
			'The inner send did not see its own state.'
		);

		$this->assertSame(
			array(
				'subject'     => 'OUTER early subject',
				'recipient'   => 'outer-early@example.test',
				'cc'          => 'outer-cc@example.test',
				'bcc'         => 'outer-bcc@example.test',
				'delivery_id' => (int) $outer_tombstone['id'],
				'order_id'    => $outer_id,
			),
			$log[1],
			'The outer send saw a frame the nested call had destroyed.'
		);

		// --- And the shared object is empty once the top-level call returns. -
		$this->assertRuntimeStateIsEmpty();

		$this->assertNotNull( $outcome );
		$this->assertSame( 1, $outcome->count_of( \Extonify\WCEP\Delivery\RunOutcome::SENT ) );
		$this->assertSame( 0, $outcome->count_of( \Extonify\WCEP\Delivery\RunOutcome::SKIPPED ) );

		fwrite(
			STDERR,
			"\n[4c item 1] early re-entry (woocommerce_email_enabled_{id}):\n"
			. sprintf( "  inner  subject=%-22s to=%-26s delivery_id=%d order=%d\n", $log[0]['subject'], $log[0]['recipient'], $log[0]['delivery_id'], $log[0]['order_id'] )
			. sprintf( "  outer  subject=%-22s to=%-26s delivery_id=%d order=%d\n", $log[1]['subject'], $log[1]['recipient'], $log[1]['delivery_id'], $log[1]['order_id'] )
			. sprintf( "  outer tombstone final_status=%s\n", $outer_tombstone['final_status'] )
		);
	}

	/**
	 * RE-ENTRY DURING OUTER CONTENT CONSTRUCTION: `woocommerce_mail_content`,
	 * applied by `WC_Email::send()` after the message is styled and before the
	 * mail callback runs.
	 *
	 * The send arguments are already evaluated by this point, so the message the
	 * outer customer receives was never at risk here — but `$this->object` and
	 * `$this->delivery_id` are read AFTERWARDS, by `woocommerce_email_sent`. The
	 * clearing `finally` wiped both, so every listener on the outer send saw a
	 * null order and delivery id 0.
	 *
	 * @return void
	 */
	public function test_re_entry_during_content_construction_leaves_the_outer_frame_intact() {
		$this->silence_core_emails();

		$outer_product = $this->make_simple_product( 'WCEP Content Outer' );
		$inner_product = $this->make_simple_product( 'WCEP Content Inner' );

		$outer_order = $this->make_order_with( array( $outer_product ) );
		$outer_order->set_billing_email( 'outer-content@example.test' );
		$outer_order->save();
		$outer_id = (int) $outer_order->get_id();

		$inner_order = $this->make_order_with( array( $inner_product ) );
		$inner_id    = (int) $inner_order->get_id();

		$outer_rule = $this->make_sending_rule(
			$outer_product,
			array(
				'name'       => 'outer (content re-entry)',
				'subject'    => 'OUTER content subject',
				'content'    => '<p>OUTER content body.</p>',
				'recipients' => array(
					'to' => array( 'customer' ),
					'cc' => array( 'outer-content-cc@example.test' ),
				),
			)
		);

		$inner_rule = $this->make_sending_rule(
			$inner_product,
			array(
				'name'          => 'inner (content re-entry)',
				'trigger_value' => 'processing',
				'subject'       => 'INNER content subject',
				'content'       => '<p>INNER content body.</p>',
				'recipients'    => array( 'to' => array( 'inner-content@example.test' ) ),
			)
		);

		$orchestrator = $this->orchestrator();

		$log = array();
		$this->observe_sends( $log );

		$state = $this->reenter_from( 'woocommerce_mail_content', $orchestrator, $inner_id, 1 );

		$orchestrator->run( wc_get_order( $outer_id ), TriggerEvent::status( 'completed' ) );

		$this->assertTrue( $state->fired, 'The content filter never re-entered, so this test proves nothing.' );
		$this->assertMailCount( 2 );

		list( $inner_mail, $outer_mail ) = $this->captured_mail;

		$this->assertSame( 'INNER content subject', $inner_mail['subject'] );
		$this->assertSame( 'inner-content@example.test', $inner_mail['to'] );

		$this->assertSame( 'OUTER content subject', $outer_mail['subject'] );
		$this->assertSame( 'outer-content@example.test', $outer_mail['to'] );
		$this->assertStringContainsString( 'OUTER content body.', (string) $outer_mail['message'] );
		$this->assertStringNotContainsString( 'INNER content body.', (string) $outer_mail['message'] );

		$outer_tombstone = $this->tombstone( $outer_id, $outer_rule, 'status:completed' );
		$this->assertNotNull( $outer_tombstone );
		$this->track_delivery( (int) $outer_tombstone['id'] );
		$this->assertSame( 'sent', $outer_tombstone['final_status'] );

		$inner_tombstone = $this->tombstone( $inner_id, $inner_rule, 'status:processing' );
		$this->assertNotNull( $inner_tombstone );
		$this->track_delivery( (int) $inner_tombstone['id'] );

		// THE ASSERTION THIS TEST EXISTS FOR: the outer `woocommerce_email_sent`
		// still carries the outer order and the outer tombstone id.
		$this->assertCount( 2, $log );

		$this->assertSame( $inner_id, $log[0]['order_id'] );
		$this->assertSame( (int) $inner_tombstone['id'], $log[0]['delivery_id'] );

		$this->assertSame(
			$outer_id,
			$log[1]['order_id'],
			'The outer woocommerce_email_sent fired with no order attached — the nested call had cleared it.'
		);
		$this->assertSame(
			(int) $outer_tombstone['id'],
			$log[1]['delivery_id'],
			'The outer woocommerce_email_sent fired with delivery_id 0 — correlation was destroyed.'
		);
		$this->assertSame( 'outer-content-cc@example.test', $log[1]['cc'] );

		$this->assertRuntimeStateIsEmpty();

		fwrite(
			STDERR,
			"\n[4c item 1] re-entry during content construction (woocommerce_mail_content):\n"
			. sprintf( "  inner  subject=%-24s to=%-28s delivery_id=%d order=%d\n", $log[0]['subject'], $log[0]['recipient'], $log[0]['delivery_id'], $log[0]['order_id'] )
			. sprintf( "  outer  subject=%-24s to=%-28s delivery_id=%d order=%d\n", $log[1]['subject'], $log[1]['recipient'], $log[1]['delivery_id'], $log[1]['order_id'] )
		);
	}

	/**
	 * A nested `get_headers()` must not strip the outer call's header injector.
	 *
	 * The registration used to be `array( $this, 'inject_copy_headers' )`, which
	 * has ONE callback identity on this shared object — so an inner
	 * `remove_filter()` removed the outer frame's registration too. A per-call
	 * closure gives each frame its own.
	 *
	 * @return void
	 */
	public function test_a_nested_send_does_not_strip_the_outer_header_injector() {
		$this->silence_core_emails();

		$outer_product = $this->make_simple_product( 'WCEP Header Outer' );
		$inner_product = $this->make_simple_product( 'WCEP Header Inner' );

		$outer_order = $this->make_order_with( array( $outer_product ) );
		$outer_id    = (int) $outer_order->get_id();

		$inner_order = $this->make_order_with( array( $inner_product ) );
		$inner_id    = (int) $inner_order->get_id();

		$this->make_sending_rule(
			$outer_product,
			array(
				'subject'    => 'OUTER header subject',
				'recipients' => array(
					'to'  => array( 'customer' ),
					'cc'  => array( 'outer-hdr-cc@example.test' ),
					'bcc' => array( 'outer-hdr-bcc@example.test' ),
				),
			)
		);

		$this->make_sending_rule(
			$inner_product,
			array(
				'trigger_value' => 'processing',
				'subject'       => 'INNER header subject',
				'recipients'    => array(
					'to' => array( 'inner-hdr@example.test' ),
					'cc' => array( 'inner-hdr-cc@example.test' ),
				),
			)
		);

		$orchestrator = $this->orchestrator();

		// Re-enter from INSIDE the outer header build, which is the only place
		// the shared registration could be removed mid-use.
		$state = $this->reenter_from( 'woocommerce_email_headers', $orchestrator, $inner_id, 4 );

		$orchestrator->run( wc_get_order( $outer_id ), TriggerEvent::status( 'completed' ) );

		$this->assertTrue( $state->fired, 'The header filter never re-entered.' );
		$this->assertMailCount( 2 );

		list( $inner_mail, $outer_mail ) = $this->captured_mail;

		$outer_headers = $this->headers_of( $outer_mail );
		$this->assertStringContainsString( 'outer-hdr-cc@example.test', $outer_headers, 'The nested call stripped the outer Cc injector.' );
		$this->assertStringContainsString( 'outer-hdr-bcc@example.test', $outer_headers, 'The nested call stripped the outer Bcc injector.' );
		$this->assertSame( 1, preg_match_all( '/^Cc:/mi', $outer_headers ), 'A duplicate Cc line was composed under nesting.' );
		$this->assertSame( 1, preg_match_all( '/^Bcc:/mi', $outer_headers ), 'A duplicate Bcc line was composed under nesting.' );

		$inner_headers = $this->headers_of( $inner_mail );
		$this->assertStringContainsString( 'inner-hdr-cc@example.test', $inner_headers );
		$this->assertStringNotContainsString( 'outer-hdr-cc@example.test', $inner_headers, 'The outer Cc leaked into the nested message.' );
		$this->assertSame( 1, preg_match_all( '/^Cc:/mi', $inner_headers ) );

		// No registration survives the run.
		$this->assertFalse(
			(bool) has_filter( 'woocommerce_email_headers' ) && $this->injector_still_registered(),
			'A header injector registration outlived its send.'
		);

		$this->assertRuntimeStateIsEmpty();
	}

	/**
	 * Whether any closure this plugin registered on `woocommerce_email_headers`
	 * is still attached at the injector's priority.
	 *
	 * @return bool
	 */
	private function injector_still_registered(): bool {
		global $wp_filter;

		$hook     = $wp_filter['woocommerce_email_headers'] ?? null;
		$priority = \Extonify\WCEP\Email\Custom_Email::HEADER_INJECT_PRIORITY;

		if ( ! $hook instanceof \WP_Hook ) {
			return false;
		}

		return array() !== ( $hook->callbacks[ $priority ] ?? array() );
	}

	/**
	 * Assert every per-delivery field on the shared object is clear.
	 *
	 * @return void
	 */
	private function assertRuntimeStateIsEmpty(): void {
		$email = $this->live_email();

		$this->assertNotNull( $email );
		$this->assertSame( '', $email->recipient, 'recipient survived the top-level call.' );
		$this->assertSame( '', $email->cc, 'cc survived the top-level call.' );
		$this->assertSame( '', $email->bcc, 'bcc survived the top-level call.' );
		$this->assertSame( '', $email->delivery_subject, 'subject survived the top-level call.' );
		$this->assertSame( '', $email->delivery_heading, 'heading survived the top-level call.' );
		$this->assertSame( '', $email->delivery_content, 'content survived the top-level call.' );
		$this->assertSame( array(), $email->matched_items, 'matched items survived the top-level call.' );
		$this->assertSame( 0, $email->delivery_id, 'the delivery reference survived the top-level call.' );
		$this->assertNull( $email->object, 'the order object survived the top-level call.' );
	}

	/**
	 * AN INNER DELIVERY MUST NOT DISPLACE THE OUTER RUN'S RULE SNAPSHOT.
	 *
	 * Outer order: two matching rules, A then B. Sending A fires
	 * `woocommerce_email_sent` — a real WooCommerce hook, raised inside
	 * `WC_Email::send()` — and the listener performs a REAL status change on a
	 * second order. That fires the real `woocommerce_order_status_changed`, whose
	 * listener drives the SAME orchestrator instance the outer run is using,
	 * exactly as `Events` does in production.
	 *
	 * All three deliveries are then asserted individually: subject, recipient,
	 * body and `rule_revision_sent`.
	 *
	 * @return void
	 */
	public function test_a_nested_event_does_not_corrupt_the_outer_run() {
		$this->silence_core_emails();

		// --- Fixtures: two outer products/rules, one inner. ------------------
		$outer_product_a = $this->make_simple_product( 'WCEP Nested Outer A' );
		$outer_product_b = $this->make_simple_product( 'WCEP Nested Outer B' );
		$inner_product   = $this->make_simple_product( 'WCEP Nested Inner' );

		$outer_order = $this->make_order_with( array( $outer_product_a, $outer_product_b ) );
		$outer_order->set_billing_email( 'outer-customer@example.test' );
		$outer_order->save();
		$outer_id = (int) $outer_order->get_id();

		$inner_order = $this->make_order_with( array( $inner_product ) );
		$inner_order->set_billing_email( 'inner-customer@example.test' );
		$inner_order->save();
		$inner_id = (int) $inner_order->get_id();

		$rule_a = $this->make_sending_rule(
			$outer_product_a,
			array(
				'name'       => 'outer A',
				'priority'   => 10,
				'subject'    => 'OUTER A subject',
				'content'    => '<p>OUTER A body.</p>',
				'recipients' => array( 'to' => array( 'outer-a@example.test' ) ),
			)
		);

		$rule_b = $this->make_sending_rule(
			$outer_product_b,
			array(
				'name'       => 'outer B',
				'priority'   => 20,
				'subject'    => 'OUTER B subject',
				'content'    => '<p>OUTER B body.</p>',
				'recipients' => array( 'to' => array( 'outer-b@example.test' ) ),
			)
		);

		$rule_inner = $this->make_sending_rule(
			$inner_product,
			array(
				'name'          => 'inner',
				'trigger_value' => 'processing',
				'subject'       => 'INNER subject',
				'content'       => '<p>INNER body.</p>',
				'recipients'    => array( 'to' => array( 'inner@example.test' ) ),
			)
		);

		// DISTINCT REVISIONS, so a snapshot mix-up is visible in the audit and
		// not just in the message. Every edit bumps the rule's revision.
		$this->rules->update( $rule_b, array( 'heading' => 'B heading v2' ) );
		$this->rules->update( $rule_b, array( 'heading' => 'B heading v3' ) );
		$this->rules->update( $rule_inner, array( 'heading' => 'Inner heading v2' ) );

		$revision_a     = (int) $this->rules->find( $rule_a )['revision'];
		$revision_b     = (int) $this->rules->find( $rule_b )['revision'];
		$revision_inner = (int) $this->rules->find( $rule_inner )['revision'];

		$this->assertNotSame( $revision_a, $revision_b, 'The fixture revisions must differ to be diagnostic.' );

		// --- ONE orchestrator, exactly as `Events` shares one per request. ---
		$orchestrator = $this->orchestrator();

		// The inner event is delivered by the SAME instance, through the real
		// WooCommerce status hook.
		$this->hook(
			'woocommerce_order_status_changed',
			static function ( $order_id, $from = '', $to = '' ) use ( $orchestrator, $inner_id ) {
				if ( (int) $order_id !== $inner_id ) {
					return;
				}
				$orchestrator->handle_status_change( (int) $order_id, (string) $from, (string) $to );
			},
			10,
			3
		);

		// A third party reacting to the OUTER send by touching another order.
		// `woocommerce_email_sent` is raised by `WC_Email::send()` itself, so
		// this genuinely runs inside the outer delivery.
		$fired = false;
		$this->hook(
			'woocommerce_email_sent',
			function () use ( &$fired, $inner_id ) {
				if ( $fired ) {
					return;
				}
				$fired = true;

				$inner = wc_get_order( $inner_id );
				$inner->update_status( 'processing', 'nested fixture' );
			},
			10,
			3
		);

		// --- The outer run. --------------------------------------------------
		$outcome = $orchestrator->run( wc_get_order( $outer_id ), TriggerEvent::status( 'completed' ) );

		$this->assertTrue( $fired, 'The nested event never fired, so this test proves nothing.' );

		// --- THREE deliveries, each intact. ----------------------------------
		$this->assertMailCount( 3, 'Expected outer A, then the nested inner delivery, then outer B.' );

		list( $first, $second, $third ) = $this->captured_mail;

		$this->assertDelivery( $first, 'OUTER A subject', 'outer-a@example.test', 'OUTER A body.' );
		$this->assertDelivery( $second, 'INNER subject', 'inner@example.test', 'INNER body.' );

		// THE ASSERTION THIS TEST EXISTS FOR. Rule B is the outer run's second
		// rule, resumed after the inner run completed. It must still send from
		// the OUTER snapshot.
		$this->assertDelivery( $third, 'OUTER B subject', 'outer-b@example.test', 'OUTER B body.' );
		$this->assertStringNotContainsString(
			'INNER body.',
			(string) $third['message'],
			'The outer run resumed against the INNER run snapshot.'
		);

		// --- And each tombstone records its OWN rule's revision. -------------
		$this->assertRevision( $outer_id, $rule_a, 'status:completed', $revision_a );
		$this->assertRevision( $outer_id, $rule_b, 'status:completed', $revision_b );
		$this->assertRevision( $inner_id, $rule_inner, 'status:processing', $revision_inner );

		// The outer run's own account is complete and belongs to the outer run.
		$this->assertNotNull( $outcome );
		$this->assertSame( 2, $outcome->count_of( \Extonify\WCEP\Delivery\RunOutcome::SENT ) );
		$this->assertTrue( $outcome->is_fully_recorded() );
	}

	/**
	 * A nested delivery must not leave the shared email object holding the inner
	 * order, either. The outer send's arguments are evaluated before the nested
	 * event can fire, and `trigger()` resets at both ends — but the object is
	 * shared, so this is asserted rather than reasoned about (ADR-0012 §5).
	 *
	 * @return void
	 */
	public function test_the_shared_email_object_is_clean_after_a_nested_delivery() {
		$this->silence_core_emails();

		$outer_product = $this->make_simple_product( 'WCEP Nested Clean Outer' );
		$inner_product = $this->make_simple_product( 'WCEP Nested Clean Inner' );

		$outer_order = $this->make_order_with( array( $outer_product ) );
		$outer_id    = (int) $outer_order->get_id();

		$inner_order = $this->make_order_with( array( $inner_product ) );
		$inner_id    = (int) $inner_order->get_id();

		$this->make_sending_rule( $outer_product, array( 'subject' => 'CLEAN outer' ) );
		$this->make_sending_rule(
			$inner_product,
			array(
				'trigger_value' => 'processing',
				'subject'       => 'CLEAN inner',
			)
		);

		$orchestrator = $this->orchestrator();

		$this->hook(
			'woocommerce_order_status_changed',
			static function ( $order_id, $from = '', $to = '' ) use ( $orchestrator, $inner_id ) {
				if ( (int) $order_id === $inner_id ) {
					$orchestrator->handle_status_change( (int) $order_id, (string) $from, (string) $to );
				}
			},
			10,
			3
		);

		$fired = false;
		$this->hook(
			'woocommerce_email_sent',
			function () use ( &$fired, $inner_id ) {
				if ( $fired ) {
					return;
				}
				$fired = true;
				wc_get_order( $inner_id )->update_status( 'processing', 'nested fixture' );
			},
			10,
			3
		);

		$orchestrator->run( wc_get_order( $outer_id ), TriggerEvent::status( 'completed' ) );

		$this->assertTrue( $fired );
		$this->assertMailCount( 2 );

		$email = $this->live_email();
		$this->assertSame( '', $email->recipient, 'The nested delivery left a recipient behind.' );
		$this->assertSame( '', $email->delivery_subject, 'The nested delivery left a subject behind.' );
		$this->assertNull( $email->object, 'The nested delivery left an order attached.' );
	}

	/**
	 * `Events` shares one orchestrator per request, which is the precondition
	 * that makes the nested case reachable at all. Asserted so a future change
	 * to that sharing is a deliberate one.
	 *
	 * @return void
	 */
	public function test_events_shares_one_orchestrator_per_request() {
		$reader = new class() extends Events {
			/**
			 * Expose the shared instance.
			 *
			 * @return Orchestrator
			 */
			public static function shared(): Orchestrator {
				return self::orchestrator();
			}
		};

		$this->assertSame(
			$reader::shared(),
			$reader::shared(),
			'Events stopped sharing its orchestrator; ADR-0012 §11 assumes it does.'
		);
	}

	/**
	 * Assert one captured message's subject, recipient and body.
	 *
	 * @param array  $mail      Captured message.
	 * @param string $subject   Expected subject.
	 * @param string $recipient Expected `to`.
	 * @param string $body      Expected body fragment.
	 * @return void
	 */
	private function assertDelivery( array $mail, string $subject, string $recipient, string $body ): void {
		$this->assertSame( $subject, $mail['subject'], 'Wrong subject for ' . $recipient . '.' );
		$this->assertSame( $recipient, $mail['to'], 'Wrong recipient for "' . $subject . '".' );
		$this->assertStringContainsString( $body, (string) $mail['message'], 'Wrong body for "' . $subject . '".' );
	}

	/**
	 * Assert the revision recorded on one tombstone.
	 *
	 * @param int    $order_id Order id.
	 * @param int    $rule_id  Rule id.
	 * @param string $identity Trigger identity.
	 * @param int    $expected Expected revision.
	 * @return void
	 */
	private function assertRevision( int $order_id, int $rule_id, string $identity, int $expected ): void {
		$tombstone = $this->tombstone( $order_id, $rule_id, $identity );

		$this->assertNotNull( $tombstone, 'Rule #' . $rule_id . ' never claimed under ' . $identity . '.' );
		$this->track_delivery( (int) $tombstone['id'] );

		$this->assertSame(
			$expected,
			(int) $tombstone['rule_revision_sent'],
			'Rule #' . $rule_id . ' recorded another rule\'s revision.'
		);
		$this->assertSame( 'sent', $tombstone['final_status'], 'Rule #' . $rule_id . ' did not finish as sent.' );
	}
}