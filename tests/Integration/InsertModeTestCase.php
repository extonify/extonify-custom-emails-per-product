<?php
/**
 * Fixtures shared by the insert-mode integration tests (ADR-0013).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\DeliveryLogger;
use Extonify\WCEP\Delivery\InsertPhase;
use Extonify\WCEP\Domain\DeliveryIdentity;
use Extonify\WCEP\Matching\ItemResolver;
use Extonify\WCEP\Render\RenderContext;
use Extonify\WCEP\Render\RenderEvents;
use Extonify\WCEP\Render\RenderLedger;

/**
 * Real native WooCommerce emails, real templates, real render context, and NOT
 * ONE REAL MESSAGE.
 *
 * THE RENDER SINGLETONS ARE REPLACED PER TEST. `RenderEvents` holds one context,
 * one ledger and one phase for the whole request — which is correct in
 * production and would leak render state between tests here, since the whole
 * suite is one PHP process.
 */
abstract class InsertModeTestCase extends DeliveryTestCase {

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
	protected function set_up_render_singletons() {
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
	protected function tear_down_render_singletons() {
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

	/**
	 * An active insert rule.
	 *
	 * @param int   $product_id Product to target.
	 * @param array $overrides  Rule fields to replace.
	 * @return int Rule id.
	 */
	protected function make_insert_rule( int $product_id, array $overrides = array() ): int {
		return $this->make_rule(
			array_merge(
				array(
					'name'            => 'WCEP insert fixture',
					'delivery_mode'   => 'insert',
					'native_email_id' => 'customer_processing_order',
					'insert_position' => 'after_order_table',
					'targeting'       => array( 'include' => array( 'products' => array( $product_id ) ) ),
					'content'         => '<p>INSERTED BLOCK.</p>',
				),
				$overrides
			)
		);
	}

	/**
	 * The live registered native WooCommerce email object.
	 *
	 * @param string $class_name WooCommerce email class name.
	 * @return \WC_Email
	 */
	protected function native_email( string $class_name = 'WC_Email_Customer_Processing_Order' ): \WC_Email {
		$emails = WC()->mailer()->get_emails();

		$this->assertArrayHasKey( $class_name, $emails, 'The native email fixture is not registered.' );

		return $emails[ $class_name ];
	}

	/**
	 * Send one native WooCommerce email and return what the mailer was handed.
	 *
	 * Driven through the email object's own `trigger()`, so the real templates,
	 * the real hook order and the real send path all run.
	 *
	 * @param int    $order_id   Order id.
	 * @param string $class_name WooCommerce email class name.
	 * @param bool   $plain_text Render the plain-text template.
	 * @return array|null Captured message.
	 */
	protected function send_native( int $order_id, string $class_name = 'WC_Email_Customer_Processing_Order', bool $plain_text = false ): ?array {
		$email = $this->native_email( $class_name );

		$before = $email->email_type;
		$email->email_type = $plain_text ? 'plain' : 'html';

		try {
			$email->trigger( $order_id );
		} finally {
			$email->email_type = $before;
		}

		return $this->last_mail();
	}

	/**
	 * Make every subsequent send REPORT FAILURE without changing anything else.
	 *
	 * Registered at `pre_wp_mail` priority 11, i.e. AFTER `DeliveryTestCase`'s
	 * capture at priority 1 AND after `MatchingTestCase`'s blocker at 10 — both of
	 * which short-circuit with `true`. The message is therefore still captured,
	 * still never reaches PHPMailer, and the only thing that differs is the
	 * boolean `wp_mail()` returns, which is exactly what `woocommerce_email_sent`
	 * reports.
	 *
	 * @return void
	 */
	protected function make_sends_report_failure(): void {
		$this->hook( 'pre_wp_mail', '__return_false', 11, 2 );
	}

	/**
	 * Record the send-candidate queue AT THE MOMENT the handoff is taken.
	 *
	 * Hooked one priority BELOW `RenderEvents::on_send_headers()`, so it observes the
	 * queue exactly as that callback is about to read it. This is the direct
	 * observation of the ADR-0013 §5e ordering invariant: whatever sits at the TAIL
	 * here is what the send will take.
	 *
	 * @param array $queue Receives the tokens, tail last.
	 * @return void
	 */
	protected function capture_candidates_at_send( array &$queue ): void {
		$this->hook(
			'woocommerce_email_headers',
			function ( $headers, $id = '', $subject = null, $email = null ) use ( &$queue ) {
				if ( array() !== $queue || ! is_object( $email ) ) {
					return $headers;
				}

				$candidates = RenderEvents::ledger()->candidates();

				foreach ( (array) ( $candidates[ spl_object_id( $email ) ] ?? array() ) as $candidate ) {
					$queue[] = (string) $candidate['token'];
				}

				return $headers;
			},
			PHP_INT_MAX - 1,
			4
		);
	}

	/**
	 * The `insert` block of one attempt row's structured snapshot.
	 *
	 * @param array $row Detail row, already hydrated.
	 * @return array
	 */
	protected function attempt_snapshot( array $row ): array {
		$snapshot = (array) ( $row['snapshot'] ?? array() );

		return (array) ( $snapshot['insert'] ?? array() );
	}

	/**
	 * The insert tombstone for one rule on one native email.
	 *
	 * @param int    $order_id        Order id.
	 * @param int    $rule_id         Rule id.
	 * @param string $native_email_id Native email id.
	 * @return array|null
	 */
	protected function insert_tombstone( int $order_id, int $rule_id, string $native_email_id = 'customer_processing_order' ): ?array {
		$row = $this->deliveries->find(
			$order_id,
			$rule_id,
			DeliveryLogger::MODE_INSERT,
			DeliveryIdentity::native( $native_email_id )
		);

		if ( null !== $row ) {
			$this->track_delivery( (int) $row['id'] );
		}

		return $row;
	}

	/**
	 * Run the shutdown sweep the way the real `shutdown` action would.
	 *
	 * @return void
	 */
	protected function run_shutdown_sweep(): void {
		RenderEvents::on_shutdown();
	}

	/**
	 * Assert a captured message carries — or does not carry — a fragment.
	 *
	 * @param array  $mail     Captured message.
	 * @param string $fragment Fragment.
	 * @param bool   $expected Whether it should be present.
	 * @param string $message  Failure message.
	 * @return void
	 */
	protected function assertBody( ?array $mail, string $fragment, bool $expected, string $message = '' ): void {
		$this->assertNotNull( $mail, 'No message was captured.' );

		$body = (string) $mail['message'];

		if ( $expected ) {
			$this->assertStringContainsString( $fragment, $body, $message );
			return;
		}

		$this->assertStringNotContainsString( $fragment, $body, $message );
	}
}
