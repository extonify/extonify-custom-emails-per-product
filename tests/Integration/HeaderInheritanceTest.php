<?php
/**
 * The store's sender identity survives this plugin (ADR-0012 §5b).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Domain\TriggerEvent;

/**
 * `Custom_Email::get_headers()` used to reimplement `WC_Email::get_headers()`
 * and stopped after Content-Type, Cc and Bcc — so it never emitted **Reply-To**,
 * and EVERY message this plugin sent ignored the merchant's configured reply
 * address. Customer replies went to the From address instead of wherever the
 * store had directed them.
 *
 * The parent builds the headers now. These tests prove the inheritance rather
 * than asserting a hand-written string, by comparing against what a CORE
 * WooCommerce email produces for the same store settings — so a future
 * WooCommerce change to sender identity is inherited instead of diverging.
 */
final class HeaderInheritanceTest extends DeliveryTestCase {

	/**
	 * Store options this test changed, restored on teardown.
	 *
	 * @var array<string,mixed>
	 */
	private $option_backup = array();

	/**
	 * Filters registered by a test, removed on teardown.
	 *
	 * @var array[]
	 */
	private $hooks = array();

	/**
	 * Restore options and remove filters.
	 *
	 * @after
	 * @return void
	 */
	protected function tear_down_header_fixtures() {
		foreach ( $this->option_backup as $name => $value ) {
			if ( null === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value );
			}
		}

		$this->option_backup = array();

		foreach ( $this->hooks as $hook ) {
			list( $tag, $callback, $priority ) = $hook;
			remove_filter( $tag, $callback, $priority );
		}

		$this->hooks = array();
	}

	/**
	 * Set a store option, remembering the original.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 * @return void
	 */
	private function set_option( string $name, $value ): void {
		if ( ! array_key_exists( $name, $this->option_backup ) ) {
			$this->option_backup[ $name ] = get_option( $name, null );
		}

		update_option( $name, $value );
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
	private function hook( string $tag, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $tag, $callback, $priority, $args );
		$this->hooks[] = array( $tag, $callback, $priority );
	}

	/**
	 * A live core WooCommerce email to compare against.
	 *
	 * `customer_completed_order` is a customer-facing email, so it takes the
	 * SAME Reply-To branch this plugin's email does — the admin-notification
	 * branch (`new_order`, `cancelled_order`, `failed_order`) is a different one
	 * and would prove nothing.
	 *
	 * @return \WC_Email
	 */
	private function core_email(): \WC_Email {
		$emails = WC()->mailer()->get_emails();
		$core   = $emails['WC_Email_Customer_Completed_Order'] ?? null;

		$this->assertInstanceOf( \WC_Email::class, $core, 'The core comparison email is not registered.' );

		return $core;
	}

	/**
	 * Pull one header line out of a raw header block.
	 *
	 * @param string $headers Header block.
	 * @param string $name    Header name.
	 * @return string|null Trimmed line, or null when absent.
	 */
	private function header_line( string $headers, string $name ): ?string {
		foreach ( preg_split( '/\r\n|\r|\n/', $headers ) as $line ) {
			if ( 1 === preg_match( '/^' . preg_quote( $name, '/' ) . ':/i', trim( $line ) ) ) {
				return trim( $line );
			}
		}

		return null;
	}

	/**
	 * Every header line of one captured message, as one block.
	 *
	 * @param array $mail Captured message.
	 * @return string
	 */
	private function header_block( array $mail ): string {
		return $this->headers_of( $mail );
	}

	/**
	 * Deliver one message and return what the mailer was handed.
	 *
	 * @param array $recipients Recipients document.
	 * @return array Captured message.
	 */
	private function deliver_once( array $recipients = array( 'to' => array( 'customer' ) ) ): array {
		$product_id = $this->make_simple_product( 'WCEP Headers ' . wp_generate_password( 6, false, false ) );
		$order      = $this->make_order_with( array( $product_id ) );

		$this->make_sending_rule( $product_id, array( 'recipients' => $recipients ) );

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 1, 'The header fixture did not produce exactly one message.' );

		// Registers every tombstone this delivery wrote for teardown.
		$this->tombstones_for( (int) $order->get_id() );

		return $this->last_mail();
	}

	/**
	 * REPLY-TO DISABLED: this plugin still emits exactly the line a core
	 * WooCommerce email emits — which, with the setting off, is core's fallback
	 * to `From name <From address>`.
	 *
	 * @return void
	 */
	public function test_reply_to_matches_core_when_the_setting_is_disabled() {
		$this->set_option( 'woocommerce_email_reply_to_enabled', 'no' );
		$this->set_option( 'woocommerce_email_from_name', 'Extonify Test Store' );
		$this->set_option( 'woocommerce_email_from_address', 'store@example.test' );

		$expected = $this->header_line( (string) $this->core_email()->get_headers(), 'Reply-to' );

		$mail = $this->deliver_once();
		$ours = $this->header_line( $this->header_block( $mail ), 'Reply-to' );

		$this->assertNotNull( $ours, 'No Reply-To header was sent at all — the original defect.' );
		$this->assertSame( $expected, $ours, 'The Reply-To differs from what a core WooCommerce email would send.' );
		$this->assertStringContainsString( 'store@example.test', (string) $ours );
	}

	/**
	 * A CONFIGURED REPLY-TO is honoured, address and name.
	 *
	 * @return void
	 */
	public function test_a_configured_reply_to_is_used() {
		$this->set_option( 'woocommerce_email_reply_to_enabled', 'yes' );
		$this->set_option( 'woocommerce_email_reply_to_address', 'replies@example.test' );
		$this->set_option( 'woocommerce_email_reply_to_name', 'Extonify Support' );
		$this->set_option( 'woocommerce_email_from_name', 'Extonify Test Store' );
		$this->set_option( 'woocommerce_email_from_address', 'store@example.test' );

		if ( ! method_exists( $this->core_email(), 'get_reply_to_enabled' ) ) {
			$this->markTestSkipped( 'This WooCommerce version has no configurable reply-to.' );
		}

		$expected = $this->header_line( (string) $this->core_email()->get_headers(), 'Reply-to' );

		$mail = $this->deliver_once();
		$ours = $this->header_line( $this->header_block( $mail ), 'Reply-to' );

		$this->assertSame( $expected, $ours );
		$this->assertSame( 'Reply-to: Extonify Support <replies@example.test>', $ours );
	}

	/**
	 * THE FALLBACK REPLY-TO NAME: with reply-to enabled and an address set but
	 * NO name, WooCommerce falls back to the From name. Inherited, not
	 * reimplemented.
	 *
	 * @return void
	 */
	public function test_the_reply_to_name_falls_back_to_the_from_name() {
		$this->set_option( 'woocommerce_email_reply_to_enabled', 'yes' );
		$this->set_option( 'woocommerce_email_reply_to_address', 'replies@example.test' );
		$this->set_option( 'woocommerce_email_reply_to_name', '' );
		$this->set_option( 'woocommerce_email_from_name', 'Extonify Fallback Name' );
		$this->set_option( 'woocommerce_email_from_address', 'store@example.test' );

		if ( ! method_exists( $this->core_email(), 'get_reply_to_enabled' ) ) {
			$this->markTestSkipped( 'This WooCommerce version has no configurable reply-to.' );
		}

		$expected = $this->header_line( (string) $this->core_email()->get_headers(), 'Reply-to' );

		$mail = $this->deliver_once();
		$ours = $this->header_line( $this->header_block( $mail ), 'Reply-to' );

		$this->assertSame( $expected, $ours );
		$this->assertSame( 'Reply-to: Extonify Fallback Name <replies@example.test>', $ours );
	}

	/**
	 * FROM ADDRESS AND FROM NAME resolve through WooCommerce's own accessors at
	 * the moment of sending.
	 *
	 * `From` is not a header this plugin writes on any WooCommerce version:
	 * `WC_Email::send()` binds `get_from_address()` / `get_from_name()` onto
	 * `wp_mail_from` / `wp_mail_from_name` for the duration of the send. The
	 * capture short-circuits `wp_mail()` before it applies those filters, so
	 * they are applied here INSTEAD — from inside the capture, while the
	 * bindings are live, which is exactly the state `wp_mail()` would have seen.
	 *
	 * @return void
	 */
	public function test_from_resolves_through_woocommerce_at_send_time() {
		$this->set_option( 'woocommerce_email_from_name', 'Extonify Sender Name' );
		$this->set_option( 'woocommerce_email_from_address', 'sender@example.test' );

		$observed = array();

		$this->hook(
			'pre_wp_mail',
			static function ( $short_circuit ) use ( &$observed ) {
				$observed['from']      = apply_filters( 'wp_mail_from', 'wordpress@fallback.invalid' );
				$observed['from_name'] = apply_filters( 'wp_mail_from_name', 'WordPress' );
				return $short_circuit;
			},
			2,
			2
		);

		$core = $this->core_email();

		$this->deliver_once();

		$this->assertSame(
			$core->get_from_address(),
			$observed['from'] ?? null,
			'The From address differs from what a core WooCommerce email would use.'
		);
		$this->assertSame(
			$core->get_from_name(),
			$observed['from_name'] ?? null,
			'The From name differs from what a core WooCommerce email would use.'
		);

		$this->assertSame( 'sender@example.test', $observed['from'] );
		$this->assertSame( 'Extonify Sender Name', $observed['from_name'] );
	}

	/**
	 * The store's `woocommerce_email_from_address` filter still reaches the
	 * From used for this plugin's messages — the same third-party seam a core
	 * email offers.
	 *
	 * @return void
	 */
	public function test_a_third_party_from_filter_still_applies() {
		$this->set_option( 'woocommerce_email_from_address', 'sender@example.test' );

		$this->hook(
			'woocommerce_email_from_address',
			static function () {
				return 'filtered@example.test';
			},
			10,
			3
		);

		$observed = null;

		$this->hook(
			'pre_wp_mail',
			static function ( $short_circuit ) use ( &$observed ) {
				$observed = apply_filters( 'wp_mail_from', 'wordpress@fallback.invalid' );
				return $short_circuit;
			},
			2,
			2
		);

		$this->deliver_once();

		$this->assertSame( 'filtered@example.test', $observed );
	}

	/**
	 * CC AND BCC SURVIVE the move to the parent's header construction, exactly
	 * once each, and the whole block still carries Content-Type and Reply-To.
	 *
	 * @return void
	 */
	public function test_cc_and_bcc_are_retained_alongside_the_inherited_headers() {
		$this->set_option( 'woocommerce_email_from_name', 'Extonify Test Store' );
		$this->set_option( 'woocommerce_email_from_address', 'store@example.test' );

		$mail = $this->deliver_once(
			array(
				'to'  => array( 'customer' ),
				'cc'  => array( 'shop@example.test' ),
				'bcc' => array( 'archive@example.test' ),
			)
		);

		$headers = $this->header_block( $mail );

		$this->assertSame( 'Cc: shop@example.test', $this->header_line( $headers, 'Cc' ) );
		$this->assertSame( 'Bcc: archive@example.test', $this->header_line( $headers, 'Bcc' ) );

		// EXACTLY ONE of each: the parent emits them itself on WooCommerce
		// versions whose `email_improvements` path is active, and the injector
		// must not add a second.
		$this->assertSame( 1, preg_match_all( '/^Cc:/mi', $headers ), 'A duplicate Cc line was composed.' );
		$this->assertSame( 1, preg_match_all( '/^Bcc:/mi', $headers ), 'A duplicate Bcc line was composed.' );

		$this->assertNotNull( $this->header_line( $headers, 'Content-Type' ) );
		$this->assertNotNull( $this->header_line( $headers, 'Reply-to' ) );

		fwrite( STDERR, "\n[4b item 4] header dump for one delivery:\n" . trim( $headers ) . "\n" );
	}

	/**
	 * `woocommerce_email_headers` IS APPLIED EXACTLY ONCE, and every callback on
	 * it sees this plugin's Cc and Bcc.
	 *
	 * Appending Cc/Bcc to the parent's RETURN value would also have produced a
	 * single application — and an SMTP or deliverability plugin reading the
	 * block would have been reading an incomplete one. Both halves are asserted.
	 *
	 * @return void
	 */
	public function test_the_header_filter_fires_once_and_sees_the_copy_headers() {
		$seen  = array();
		$count = 0;

		$this->hook(
			'woocommerce_email_headers',
			static function ( $headers, $email_id = '', $mail_object = null, $email = null ) use ( &$seen, &$count ) {
				if ( \Extonify\WCEP\Email\EmailIdentity::EMAIL_ID === (string) $email_id ) {
					++$count;
					$seen[] = (string) $headers;
				}
				return $headers;
			},
			10,
			4
		);

		$this->deliver_once(
			array(
				'to'  => array( 'customer' ),
				'cc'  => array( 'shop@example.test' ),
				'bcc' => array( 'archive@example.test' ),
			)
		);

		$this->assertSame( 1, $count, 'woocommerce_email_headers was applied ' . $count . ' times, not once.' );

		$this->assertStringContainsString( 'shop@example.test', $seen[0], 'The filter could not see this plugin\'s Cc.' );
		$this->assertStringContainsString( 'archive@example.test', $seen[0], 'The filter could not see this plugin\'s Bcc.' );
		$this->assertStringContainsString( 'Reply-to:', $seen[0], 'The filter saw a block with no Reply-To.' );

		fwrite( STDERR, "\n[4b item 4] woocommerce_email_headers applications for one delivery: {$count}\n" );
	}

	/**
	 * A third party filtering the header block still has the last word: what it
	 * returns is what is sent.
	 *
	 * @return void
	 */
	public function test_a_third_party_header_filter_still_has_the_last_word() {
		$this->hook(
			'woocommerce_email_headers',
			static function ( $headers, $email_id = '' ) {
				if ( \Extonify\WCEP\Email\EmailIdentity::EMAIL_ID === (string) $email_id ) {
					return $headers . "X-Extonify-Test: yes\r\n";
				}
				return $headers;
			},
			10,
			4
		);

		$mail = $this->deliver_once();

		$this->assertSame( 'X-Extonify-Test: yes', $this->header_line( $this->header_block( $mail ), 'X-Extonify-Test' ) );
	}
}