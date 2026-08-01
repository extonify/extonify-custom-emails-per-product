<?php
/**
 * Placeholder resolution end to end, in both delivery modes (ADR-0014).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\PlaceholderValues;
use Extonify\WCEP\Domain\PlaceholderSyntax;

/**
 * Real orders, real products, real WooCommerce formatters, real templates — and
 * NOT ONE REAL MESSAGE.
 *
 * The unit suite proves the grammar and the escaping in isolation; this proves
 * that what a customer actually receives carries the right values, formatted the
 * way the store formats everything else, and that nothing a customer types can
 * change what the plugin resolves.
 */
final class PlaceholderTest extends InsertModeTestCase {

	/**
	 * The whole v1.0 placeholder set, as `name=[{name}]` markers (ADR-0014 §4).
	 *
	 * Written one per line so a plain-text render — which WooCommerce word-wraps
	 * at 70 characters — keeps each marker readable, and so the rendered sample
	 * in the report can be read side by side.
	 *
	 * @return string[]
	 */
	private function placeholder_names(): array {
		return array(
			'customer_first_name',
			'customer_last_name',
			'customer_full_name',
			'customer_email',
			'customer_phone',
			'order_number',
			'order_date',
			'order_status',
			'order_total',
			'payment_method',
			'shipping_method',
			'billing_address',
			'shipping_address',
			'view_order_url',
			'store_name',
			'store_email',
			'store_url',
			'my_account_url',
			'product_name',
			'product_names',
			'product_sku',
			'product_quantity',
			'product_url',
			'variation_name',
			'variation_attributes',
			'matched_product_list',
		);
	}

	/**
	 * A template carrying every placeholder in the set, each on its own line.
	 *
	 * @param string[] $extra Additional raw placeholder spellings.
	 * @return string
	 */
	private function full_template( array $extra = array() ): string {
		$lines = array();

		foreach ( array_merge( $this->placeholder_names(), $extra ) as $name ) {
			$key     = false === strpos( $name, ':' ) ? $name : strtok( $name, ':' ) . '_param';
			$lines[] = '<p>' . $key . '=[{' . $name . '}]</p>';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Pull every `name=[value]` marker out of a rendered body.
	 *
	 * @param string $body Rendered message.
	 * @return array<string,string>
	 */
	private function markers( string $body ): array {
		$found = array();

		if ( ! preg_match_all( '/([a-z_]+)=\[(.*?)\]/s', $body, $matches, PREG_SET_ORDER ) ) {
			return $found;
		}

		foreach ( $matches as $match ) {
			// A plain-text body is word-wrapped by WooCommerce at 70 characters,
			// so a long value arrives with newlines the merchant never typed.
			$found[ $match[1] ] = trim( preg_replace( '/\s+/', ' ', $match[2] ) );
		}

		return $found;
	}

	/**
	 * The same markers with the HTML line breaks folded back to spaces.
	 *
	 * ⚠ THE `<br />` IS INTENDED AND IS ASSERTED SEPARATELY (ADR-0014 §3): the
	 * HTML context converts a value's newlines to `<br />` AFTER escaping, so a
	 * multi-line address reads correctly in HTML and stays plain text in a
	 * text/plain body. Folding it here lets one set of value assertions serve
	 * both formats.
	 *
	 * @param array<string,string> $markers Markers.
	 * @return array<string,string>
	 */
	private function fold_breaks( array $markers ): array {
		foreach ( $markers as $name => $value ) {
			$markers[ $name ] = trim( preg_replace( '/\s+/', ' ', str_ireplace( array( '<br />', '<br/>', '<br>' ), ' ', $value ) ) );
		}

		return $markers;
	}

	/**
	 * An order with every field the placeholder set reads.
	 *
	 * @param int[] $lines Product ids and quantities, as `make_order_with()` takes.
	 * @return \WC_Order
	 */
	private function full_order( array $lines ): \WC_Order {
		$order = $this->make_order_with( $lines );

		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_billing_email( 'ada@example.test' );
		$order->set_billing_phone( '+44 20 7946 0000' );
		$order->set_billing_address_1( '12 High Street' );
		$order->set_billing_city( 'London' );
		$order->set_billing_postcode( 'N1 1AA' );
		$order->set_billing_country( 'GB' );

		$order->set_shipping_first_name( 'Ada' );
		$order->set_shipping_last_name( 'Lovelace' );
		$order->set_shipping_address_1( '3 Rue Lafayette' );
		$order->set_shipping_city( 'Paris' );
		$order->set_shipping_postcode( '75009' );
		$order->set_shipping_country( 'FR' );

		$order->set_payment_method_title( 'Test Gateway' );

		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_title( 'Flat rate' );
		$shipping->set_total( '4.00' );
		$order->add_item( $shipping );

		$order->update_meta_data( 'total_paid_note', 'Paid in full at the till' );
		$order->update_meta_data( 'structured_note', array( 'a', 'b' ) );

		/*
		 * ⚠ THE PROTECTED KEYS ARE THIRD-PARTY-SHAPED ON PURPOSE. WooCommerce
		 * REFUSES `update_meta_data()` for its OWN internal keys — `WC_Data`
		 * calls `wc_doing_it_wrong()` for `_customer_ip_address` and the like —
		 * so the fixture stores the sort of underscore key a payment or ERP
		 * plugin really writes, which is exactly the class ADR-0014 §6 exists to
		 * keep out of customer emails. The customer IP goes in through
		 * WooCommerce's own setter.
		 */
		$order->update_meta_data( '_stripe_source_id', 'src_SECRET_TOKEN' );
		$order->update_meta_data( '_wcep_private_note', 'INTERNAL ONLY' );
		$order->set_customer_ip_address( '203.0.113.9' );

		$order->calculate_totals();
		$order->save();

		return $order;
	}

	/**
	 * Assert the whole set against one rendered body.
	 *
	 * @param array<string,string> $got    Markers pulled from the body.
	 * @param \WC_Order            $order  The order.
	 * @param int                  $sku_id Product whose SKU is expected.
	 * @param string               $format `html` or `plain`, for messages.
	 * @return void
	 */
	private function assert_full_set( array $raw, \WC_Order $order, int $sku_id, string $format ) {
		$got     = $this->fold_breaks( $raw );
		$product = wc_get_product( $sku_id );

		$this->assertSame( 'Ada', $got['customer_first_name'], $format );
		$this->assertSame( 'Lovelace', $got['customer_last_name'], $format );
		$this->assertSame( 'Ada Lovelace', $got['customer_full_name'], $format );
		$this->assertSame( 'ada@example.test', $got['customer_email'], $format );
		$this->assertSame( '+44 20 7946 0000', $got['customer_phone'], $format );

		$this->assertSame( (string) $order->get_order_number(), $got['order_number'], $format );

		// WooCommerce's own date formatter, not a raw timestamp or an ISO string.
		$this->assertSame( (string) wc_format_datetime( $order->get_date_created() ), $got['order_date'], $format );
		$this->assertSame( 0, preg_match( '/\d{4}-\d{2}-\d{2}T/', $got['order_date'] ), 'the raw ISO date reached the customer' );

		// The human-readable status name, never the slug.
		$this->assertSame( (string) wc_get_order_status_name( $order->get_status() ), $got['order_status'], $format );
		$this->assertNotSame( $order->get_status(), $got['order_status'], 'the raw status slug reached the customer' );

		// wc_price(), flattened: the store's currency formatting, and NO markup.
		$this->assertStringContainsString( (string) number_format( (float) $order->get_total(), 2 ), $got['order_total'], $format );
		$this->assertStringNotContainsString( '<span', $got['order_total'], 'wc_price() markup reached the message' );
		$this->assertStringNotContainsString( 'woocommerce-Price', $got['order_total'], $format );

		$this->assertSame( 'Test Gateway', $got['payment_method'], $format );
		$this->assertSame( 'Flat rate', $got['shipping_method'], $format );

		// get_formatted_*_address(), flattened to text: still the store's address
		// format, with no `<br/>` of WooCommerce's left in the value.
		$this->assertStringContainsString( '12 High Street', $got['billing_address'], $format );
		$this->assertStringContainsString( 'London', $got['billing_address'], $format );
		$this->assertStringContainsString( '3 Rue Lafayette', $got['shipping_address'], $format );

		// ⚠ CASE-INSENSITIVE, AND THAT IS THE POINT: WooCommerce's FRENCH address
		// format upper-cases the city (`{city_upper}`), so "PARIS" here is the
		// evidence that the STORE'S per-country formatter produced this value
		// rather than the plugin concatenating fields itself.
		$this->assertStringContainsStringIgnoringCase( 'paris', $got['shipping_address'], $format );

		$this->assertSame( (string) $order->get_view_order_url(), $got['view_order_url'], $format );

		$this->assertSame( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ), $got['store_name'], $format );
		$this->assertSame( PlaceholderValues::store_email(), $got['store_email'], $format );
		$this->assertSame( (string) home_url(), $got['store_url'], $format );
		$this->assertSame( (string) wc_get_page_permalink( 'myaccount' ), $got['my_account_url'], $format );

		$this->assertSame( 'WCEP Placeholder Widget', $got['product_name'], $format );
		$this->assertSame( 'WCEP Placeholder Widget', $got['product_names'], $format );
		$this->assertSame( 'WCEP-SKU-1', $got['product_sku'], $format );
		$this->assertSame( '2', $got['product_quantity'], $format );
		$this->assertSame( (string) $product->get_permalink(), $got['product_url'], $format );

		// A simple product has no variation, so both variation placeholders are
		// empty rather than falling back to anything (ADR-0014 §5).
		$this->assertSame( '', $got['variation_name'], $format );
		$this->assertSame( '', $got['variation_attributes'], $format );

		$this->assertSame( '2 × WCEP Placeholder Widget', $got['matched_product_list'], $format );
	}

	/**
	 * 1. EVERY PLACEHOLDER IN THE §4 SET, SEPARATE MODE, BOTH FORMATS.
	 *
	 * @return void
	 */
	public function test_every_placeholder_resolves_in_a_separate_mode_email() {
		$product_id = $this->make_simple_product( 'WCEP Placeholder Widget' );

		$product = wc_get_product( $product_id );
		$product->set_sku( 'WCEP-SKU-1' );
		$product->save();

		/*
		 * ONE ORDER PER FORMAT. The delivery identity is `order|rule|status:…`, so
		 * running the same order twice would be SUPPRESSED as a duplicate claim
		 * (ADR-0004) and the second format would never be rendered at all.
		 */
		$orders = array(
			'html'  => $this->full_order( array( array( $product_id, 2 ) ) ),
			'plain' => $this->full_order( array( array( $product_id, 2 ) ) ),
		);

		$this->make_sending_rule(
			$product_id,
			array(
				'trigger_value' => 'processing',
				'subject'       => 'Order {order_number} for {customer_first_name}',
				'heading'       => 'Thanks, {customer_full_name}',
				'content'       => $this->full_template( array( 'order_custom_field:total_paid_note', 'item_custom_field:gift_note' ) ),
			)
		);

		// A per-item meta value, so `{item_custom_field:…}` has something to read.
		foreach ( $orders as $each ) {
			foreach ( $each->get_items() as $item ) {
				$item->update_meta_data( 'gift_note', 'Happy birthday' );
				$item->save();
			}
		}

		$samples = array();

		foreach ( $orders as $format => $order ) {
			$this->captured_mail = array();
			$this->set_email_setting( 'email_type', $format );

			$this->orchestrator()->handle_status_change( (int) $order->get_id(), 'pending', 'processing' );

			$this->assertMailCount( 1, $format );
			$mail = $this->last_mail();
			$got  = $this->markers( (string) $mail['message'] );

			$this->assert_full_set( $got, $order, $product_id, $format );

			$folded = $this->fold_breaks( $got );
			$this->assertSame( 'Paid in full at the till', $folded['order_custom_field_param'], $format );
			$this->assertSame( 'Happy birthday', $folded['item_custom_field_param'], $format );

			$this->assertSame( 'Order ' . $order->get_order_number() . ' for Ada', (string) $mail['subject'], $format );

			$samples[ $format ] = $got;
		}

		// ⚠ THE SAME PLACEHOLDER RENDERS DIFFERENTLY PER FORMAT, BY DESIGN
		// (ADR-0014 §3): the address is `<br />`-joined in HTML and newline-joined
		// in text, which the marker normalisation above hides — so assert the raw
		// bodies rather than the markers.
		$this->assertMatchesRegularExpression(
			'/<br\s*\/?>/i',
			$samples['html']['billing_address'],
			'the HTML address lost its line breaks'
		);
		$this->assertStringNotContainsString( '<br', $samples['plain']['billing_address'], 'markup reached the plain-text body' );

		$this->report_sample( 'separate mode', $samples['html'], $samples['plain'] );
	}

	/**
	 * 2. THE SAME SET IN INSERT MODE, at a body position.
	 *
	 * @return void
	 */
	public function test_every_placeholder_resolves_in_insert_mode() {
		$product_id = $this->make_simple_product( 'WCEP Placeholder Widget' );

		$product = wc_get_product( $product_id );
		$product->set_sku( 'WCEP-SKU-1' );
		$product->save();

		$order    = $this->full_order( array( array( $product_id, 2 ) ) );
		$order_id = (int) $order->get_id();

		$rule_id = $this->make_insert_rule(
			$product_id,
			array( 'content' => $this->full_template( array( 'order_custom_field:total_paid_note' ) ) )
		);

		foreach ( array( false, true ) as $plain_text ) {
			$this->captured_mail = array();

			$mail = $this->send_native( $order_id, 'WC_Email_Customer_Processing_Order', $plain_text );
			$got  = $this->markers( (string) $mail['message'] );

			$this->assert_full_set( $got, $order, $product_id, $plain_text ? 'plain' : 'html' );
			$this->assertSame( 'Paid in full at the till', $got['order_custom_field_param'] );
		}

		$this->assertSame( 'sent', $this->insert_tombstone( $order_id, $rule_id )['final_status'] );

		fwrite( STDERR, "\n[P6 item 2] insert mode: the full set resolved at after_order_table, HTML and plain\n" );
	}

	/**
	 * 3. SUBJECT AND HEADING RESOLVE, AND A CRLF-BEARING BILLING NAME OPENS NO
	 *    HEADER (ADR-0014 §3).
	 *
	 * @return void
	 */
	public function test_subject_and_heading_resolve_and_cannot_open_a_header() {
		$product_id = $this->make_simple_product( 'WCEP Header Injection' );
		$order      = $this->full_order( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		// The customer's own billing field, carrying a header of its own.
		$order->set_billing_first_name( "Ada\r\nBcc: attacker@evil.test" );
		$order->save();

		$rule_id = $this->make_sending_rule(
			$product_id,
			array(
				'trigger_value' => 'processing',
				'subject'       => 'Hello {customer_first_name}',
				'heading'       => 'Hi {customer_first_name}',
				'content'       => '<p>Body for {customer_first_name}.</p>',
			)
		);

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$this->assertMailCount( 1 );
		$mail    = $this->last_mail();
		$subject = (string) $mail['subject'];
		$headers = $this->headers_of( $mail );

		// --- THE RAW MESSAGE HEADERS. -----------------------------------------
		$this->assertStringNotContainsString( "\r", $subject, 'the subject carries a carriage return' );
		$this->assertStringNotContainsString( "\n", $subject, 'the subject carries a line feed' );
		$this->assertStringContainsString( 'Ada', $subject );
		$this->assertSame( 0, preg_match( '/^bcc:/im', $subject ), 'the subject opens a header' );
		$this->assertSame( 0, preg_match( '/^bcc:\s*attacker@evil\.test/im', $headers ), 'a Bcc header was injected' );
		$this->assertStringNotContainsString( 'attacker@evil.test', (string) $mail['to'] );

		// --- AND THE ATTEMPT IS RECORDED, not silently sanitised. -------------
		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:processing' );
		$this->track_delivery( (int) $tombstone['id'] );
		$rows = $this->detail_rows( (int) $tombstone['id'] );

		$this->assertStringContainsString(
			'stripped a line break or control character from {customer_first_name}',
			(string) $rows[0]['reason'],
			'a sanitised injection attempt left no trace in the log'
		);

		fwrite(
			STDERR,
			"\n[P6 item 3] CRLF billing name — subject: " . $subject . "\n"
			. '  raw headers: ' . str_replace( array( "\r", "\n" ), array( '\\r', '\\n' ), $headers ) . "\n"
			. '  recorded: ' . (string) $rows[0]['reason'] . "\n"
		);
	}

	/**
	 * 4. A HOSTILE BILLING NAME: markup ESCAPED, a placeholder LITERAL, in both
	 *    formats (ADR-0014 §2, §3).
	 *
	 * @return void
	 */
	public function test_a_hostile_billing_name_is_escaped_and_literal() {
		// Both spellings: the one the prompt names, and one with a REAL secret
		// behind it — so "the token stayed literal" is proven against a key that
		// would otherwise have resolved to something.
		$hostile = '<script>alert(1)</script> {order_custom_field:_billing_address_index} {order_custom_field:_stripe_source_id}';

		$product_id = $this->make_simple_product( 'WCEP Hostile Name' );

		// One order per format: the same order twice would be a suppressed
		// duplicate claim, and the second format would never render.
		$orders = array();

		foreach ( array( 'html', 'plain' ) as $format ) {
			$order = $this->full_order( array( $product_id ) );
			$order->set_billing_first_name( $hostile );
			$order->save();

			$orders[ $format ] = $order;
		}

		$this->make_sending_rule(
			$product_id,
			array(
				'trigger_value' => 'processing',
				'content'       => '<p>name=[{customer_first_name}]</p>',
			)
		);

		$bodies = array();

		foreach ( $orders as $format => $order ) {
			$this->captured_mail = array();
			$this->set_email_setting( 'email_type', $format );

			$this->orchestrator()->handle_status_change( (int) $order->get_id(), 'pending', 'processing' );

			$this->assertMailCount( 1, $format );
			$body              = (string) $this->last_mail()['message'];
			$bodies[ $format ] = $body;

			// ⚠ THE SECURITY PROPERTY: the private meta value never resolves, in
			// either format, because a resolved value is never re-scanned.
			$this->assertStringNotContainsString( 'src_SECRET_TOKEN', $body, 'a single-pass violation leaked the payment token' );
			$this->assertStringContainsString( '{order_custom_field:_billing_address_index}', $body, 'the token should appear LITERALLY' );
			$this->assertStringContainsString( '{order_custom_field:_stripe_source_id}', $body, 'the token should appear LITERALLY' );
		}

		// HTML: the markup is escaped and cannot execute.
		$this->assertStringNotContainsString( '<script>', $bodies['html'], 'a script tag reached the HTML body' );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $bodies['html'] );

		/*
		 * ⚠ PLAIN TEXT: WOOCOMMERCE REMOVES THE TAG ITSELF, AND THAT IS ITS
		 * BEHAVIOUR RATHER THAN THIS PLUGIN'S. `WC_Email::get_content()` runs
		 * `wp_strip_all_tags()` over the WHOLE plain body (WC 10.9.4,
		 * `class-wc-email.php:872`) before `$plain_search`/`$plain_replace`, so a
		 * tag-like sequence cannot survive in a plain-text email whoever put it
		 * there. This plugin does not escape the value — escaping a text/plain
		 * body could only corrupt it — and the outcome is still that nothing
		 * executable reaches the customer.
		 */
		$this->assertStringNotContainsString( 'alert(1)', $bodies['plain'], 'WooCommerce no longer strips tags from plain bodies — re-check ADR-0014' );

		fwrite(
			STDERR,
			"\n[P6 item 4] hostile billing name in both formats:\n"
			. '  html : ' . trim( $this->markers( $bodies['html'] )['name'] ) . "\n"
			. '  plain: ' . trim( $this->markers( $bodies['plain'] )['name'] ) . "\n"
		);
	}

	/**
	 * 5. A PUBLIC META KEY RESOLVES; AN UNDERSCORE-PREFIXED ONE DOES NOT, AND THE
	 *    REFUSAL IS RECORDED (ADR-0014 §6).
	 *
	 * @return void
	 */
	public function test_meta_placeholders_respect_the_underscore_boundary() {
		$product_id = $this->make_simple_product( 'WCEP Meta Boundary' );
		$order      = $this->full_order( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule(
			$product_id,
			array(
				'trigger_value' => 'processing',
				'content'       => '<p>public=[{order_custom_field:total_paid_note}]</p>'
					. '<p>private=[{order_custom_field:_stripe_source_id}]</p>'
					. '<p>ip=[{order_custom_field:_customer_ip_address}]</p>'
					. '<p>malformed=[{order_custom_field:bad key}]</p>'
					. '<p>structured=[{order_custom_field:structured_note}]</p>',
			)
		);

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$got = $this->markers( (string) $this->last_mail()['message'] );

		$this->assertSame( 'Paid in full at the till', $got['public'] );
		$this->assertSame( '', $got['private'], 'a protected meta key resolved into a customer email' );
		$this->assertSame( '', $got['ip'], 'the customer IP address resolved into a customer email' );
		$this->assertSame( '', $got['structured'], 'an array meta value was serialised into a customer email' );

		// `bad key` is not a token at all — a space is not part of the grammar —
		// so it stays literal and nothing is read.
		$this->assertSame( '', $got['malformed'] );

		$body = (string) $this->last_mail()['message'];
		$this->assertStringNotContainsString( 'src_SECRET_TOKEN', $body, 'a payment token reached the message' );
		$this->assertStringNotContainsString( '203.0.113.9', $body, 'the customer IP address reached the message' );

		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:processing' );
		$this->track_delivery( (int) $tombstone['id'] );
		$reason = (string) $this->detail_rows( (int) $tombstone['id'] )[0]['reason'];

		$this->assertStringContainsString( 'refused a protected meta key {order_custom_field:_stripe_source_id}', $reason );
		$this->assertStringContainsString( 'refused a protected meta key {order_custom_field:_customer_ip_address}', $reason );
		$this->assertStringContainsString( 'refused a non-printable meta value {order_custom_field:structured_note}', $reason );

		fwrite( STDERR, "\n[P6 item 5] meta boundary recorded: " . $reason . "\n" );
	}

	/**
	 * 6A ITEM 2. AN OVERLENGTH META KEY IS REFUSED — AND, CRUCIALLY, THE RAW
	 * PLACEHOLDER IS NOT DELIVERED (ADR-0014 §1b).
	 *
	 * ⚠ END TO END THROUGH A REAL SEND, NOT AGAINST `is_valid_meta_key()`. The
	 * direct unit test passed for the whole time this was broken: the grammar
	 * capped the parameter at 255 characters, so a 256-character key was **not a
	 * token**, the validator never ran, and the entire `{order_custom_field:…}` was
	 * mailed to the customer as template text. A test that stops at the validator
	 * cannot see that.
	 *
	 * @return void
	 */
	public function test_an_overlength_meta_key_is_refused_and_never_delivered() {
		$product_id = $this->make_simple_product( 'WCEP Overlength Key' );
		$order      = $this->full_order( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		// 256 characters: one past `META_KEY_PATTERN`, and one past the
		// `varchar(255)` WordPress stores meta keys in.
		$overlength = str_repeat( 'k', 256 );
		$boundary   = str_repeat( 'j', 255 );

		$this->assertFalse( PlaceholderSyntax::is_valid_meta_key( $overlength ) );
		$this->assertTrue( PlaceholderSyntax::is_valid_meta_key( $boundary ) );

		// The 255 key EXISTS and holds a value, so "the boundary still resolves" is
		// asserted against something rather than against another blank.
		$order->update_meta_data( $boundary, 'JUST INSIDE THE LIMIT' );
		$order->save();

		$rule_id = $this->make_sending_rule(
			$product_id,
			array(
				'trigger_value' => 'processing',
				'content'       => '<p>over=[{order_custom_field:' . $overlength . '}]</p>'
					. '<p>edge=[{order_custom_field:' . $boundary . '}]</p>',
			)
		);

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$this->assertMailCount( 1 );
		$body = (string) $this->last_mail()['message'];
		$got  = $this->markers( $body );

		// 1. THE GRAMMAR RECOGNISED IT: nothing of the raw token survives.
		$this->assertStringNotContainsString( $overlength, $body, 'the raw overlength placeholder was delivered to the customer' );
		$this->assertStringNotContainsString( 'order_custom_field:', $body, 'a raw placeholder reached the message' );

		// 2. THE VALIDATOR DECIDED: empty, not a lookup of a truncated key.
		$this->assertSame( '', $got['over'] );

		// 3. AND THE BOUNDARY IS STILL A WORKING KEY, so this did not simply move
		//    the cliff one character.
		$this->assertSame( 'JUST INSIDE THE LIMIT', $got['edge'] );

		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:processing' );
		$this->track_delivery( (int) $tombstone['id'] );
		$reason = (string) $this->detail_rows( (int) $tombstone['id'] )[0]['reason'];

		$this->assertStringContainsString( 'refused a malformed meta key', $reason, 'the refusal was never recorded' );

		fwrite(
			STDERR,
			"\n[6A item 2] overlength meta key, end to end:\n"
			. '  256-char key: delivered=[' . $got['over'] . "] (raw placeholder absent from the message)\n"
			. '  255-char key: delivered=[' . $got['edge'] . "]\n"
			. '  recorded    : ' . $reason . "\n"
		);
	}

	/**
	 * 6A ITEM 4. A RECIPIENT ENTRY CONTAINING **ANY** DISALLOWED PLACEHOLDER IS
	 * REFUSED WHOLE (ADR-0014 §7a).
	 *
	 * The Prompt 6 test covered only an entry consisting SOLELY of a disallowed
	 * placeholder — the one shape where "resolve it to empty, then validate what is
	 * left" happens to fail closed. These three are the shapes where it did not.
	 *
	 * @return void
	 */
	public function test_a_recipient_entry_with_any_disallowed_placeholder_is_refused_whole() {
		$cases = array(
			'prefixed'      => array(
				'entry'  => '{customer_first_name}alice@example.test',
				'leaked' => 'alice@example.test',
				'label'  => '{customer_first_name}',
			),
			'interpolated'  => array(
				'entry'  => 'alice+{order_number}@example.test',
				'leaked' => 'alice+',
				'label'  => '{order_number}',
			),
			'parameterised' => array(
				'entry'  => '{order_custom_field:email}',
				'leaked' => '',
				'label'  => '{order_custom_field:email}',
			),
		);

		$reported = array();

		foreach ( $cases as $key => $case ) {
			$this->captured_mail = array();

			$product_id = $this->make_simple_product( 'WCEP Recipient ' . $key );
			$order      = $this->full_order( array( $product_id ) );
			$order_id   = (int) $order->get_id();

			$rule_id = $this->make_sending_rule(
				$product_id,
				array(
					'trigger_value' => 'processing',
					'targeting'     => array( 'include' => array( 'products' => array( $product_id ) ) ),
					'recipients'    => array( 'to' => array( $case['entry'] ) ),
				)
			);

			$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

			// ⚠ THE DEFECT: `{customer_first_name}alice@example.test` used to lose
			// its placeholder and deliver to the remainder — an address §7 never
			// authorised, assembled out of the field §7 exists to keep out of a
			// header.
			$this->assertMailCount( 0, $key . ': an entry carrying a disallowed placeholder still delivered' );

			$tombstone = $this->tombstone( $order_id, $rule_id, 'status:processing' );
			$this->assertNotNull( $tombstone, $key . ': the refusal was never recorded' );
			$this->track_delivery( (int) $tombstone['id'] );
			$this->assertSame( 'skipped', (string) $tombstone['final_status'], $key );

			$reason = (string) $this->detail_rows( (int) $tombstone['id'] )[0]['reason'];

			$this->assertStringContainsString(
				'refused a disallowed placeholder in a to entry: ' . $case['label'],
				$reason,
				$key . ': the refusal names no placeholder'
			);
			$this->assertStringContainsString( 'the whole entry was dropped', $reason, $key );

			if ( '' !== $case['leaked'] ) {
				$this->assertStringNotContainsString(
					$case['leaked'],
					$reason,
					$key . ': the remainder was still treated as an address'
				);
			}

			$reported[ $key ] = $case['entry'] . ' -> refused; ' . $reason;
		}

		fwrite( STDERR, "\n[6A item 4] recipient entries refused WHOLE:\n  " . implode( "\n  ", $reported ) . "\n" );
	}

	/**
	 * 5. THE ALLOW FILTER PERMITS ONE NAMED PROTECTED KEY (ADR-0014 §6.4).
	 *
	 * Tested here rather than in the unit suite deliberately: the hook system is
	 * what is under test, so a shimmed `apply_filters()` would prove nothing.
	 *
	 * @return void
	 */
	public function test_the_allow_filter_permits_one_named_protected_key() {
		$product_id = $this->make_simple_product( 'WCEP Meta Filter' );
		$order      = $this->full_order( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$this->make_sending_rule(
			$product_id,
			array(
				'trigger_value' => 'processing',
				'content'       => '<p>allowed=[{order_custom_field:_stripe_source_id}]</p>'
					. '<p>still_refused=[{order_custom_field:_wcep_private_note}]</p>',
			)
		);

		$seen = array();

		$this->hook(
			PlaceholderValues::META_FILTER,
			static function ( $allowed, $key = '', $scope = '', $order_arg = null ) use ( &$seen ) {
				$seen[] = $key . '|' . $scope . '|' . ( $allowed ? 'default-allow' : 'default-refuse' );

				// ONE key, deliberately, in the site owner's own code.
				return '_stripe_source_id' === $key ? true : $allowed;
			},
			10,
			4
		);

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$got = $this->markers( (string) $this->last_mail()['message'] );

		$this->assertSame( 'src_SECRET_TOKEN', $got['allowed'], 'the filter did not permit the key it named' );
		$this->assertSame( '', $got['still_refused'], 'the filter opened more than the key it named' );

		$this->assertContains( '_stripe_source_id|order_custom_field|default-refuse', $seen, 'the filter must arrive default-DENY for a protected key' );

		fwrite( STDERR, "\n[P6 item 5] allow filter: " . implode( '  ', $seen ) . "\n" );
	}

	/**
	 * 6B ITEM 1 / GATE 16. ⚠ ONLY A LITERAL BOOLEAN DECIDES; EVERY OTHER RETURN
	 * REFUSES THE PROTECTED KEY (ADR-0014 §6.5).
	 *
	 * THE FAIL-OPEN THIS CLOSES. The decision used to be
	 * `(bool) apply_filters( … )`, and the default for a protected key is `false` —
	 * so ANY truthy return opened it. `"no"` opened it. `"false"` opened it. `1`
	 * opened it. And **`WP_Error`** opened it, which is the worst of the set,
	 * because `WP_Error` is what a callback conventionally returns when it FAILED
	 * to decide. "I could not work out whether this key is safe" was being read as
	 * "yes, mail `_stripe_source_id` to the customer".
	 *
	 * DRIVEN THROUGH A REAL SEND, AND THROUGH THE REAL HOOK SYSTEM. A shimmed
	 * `apply_filters()` would prove nothing about the boundary that actually ships.
	 *
	 * @dataProvider non_boolean_filter_return_provider
	 *
	 * @param string $label Case name, for the report.
	 * @param mixed  $value What the callback returns.
	 * @param bool   $opens Whether the key is expected to open.
	 * @return void
	 */
	public function test_only_a_literal_boolean_opens_a_protected_meta_key( string $label, $value, bool $opens ) {
		$product_id = $this->make_simple_product( 'WCEP Filter Return ' . $label );
		$order      = $this->full_order( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule(
			$product_id,
			array(
				'trigger_value' => 'processing',
				'content'       => '<p>secret=[{order_custom_field:_stripe_source_id}]</p>',
			)
		);

		$this->hook(
			PlaceholderValues::META_FILTER,
			static function ( $allowed, $key = '', $scope = '', $order_arg = null ) use ( $value ) {
				// The `WP_Error` case is built here rather than in the provider so
				// the provider does not need WordPress loaded at suite-build time.
				if ( 'wp_error' === $value ) {
					return new \WP_Error( 'wcep_test', 'the callback could not decide' );
				}

				if ( 'std_class' === $value ) {
					return new \stdClass();
				}

				return $value;
			},
			10,
			4
		);

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$got  = $this->markers( (string) $this->last_mail()['message'] );
		$body = (string) $this->last_mail()['message'];

		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:processing' );
		$this->assertNotNull( $tombstone );
		$this->track_delivery( (int) $tombstone['id'] );
		$reason = (string) $this->detail_rows( (int) $tombstone['id'] )[0]['reason'];

		if ( $opens ) {
			$this->assertSame( 'src_SECRET_TOKEN', $got['secret'], $label . ': a literal true failed to open the key' );

			fwrite( STDERR, sprintf( "  %-24s OPENED   (the only return that may)\n", $label ) );
			return;
		}

		$this->assertSame( '', $got['secret'], $label . ': ⚠ a protected meta key was OPENED by a non-boolean return' );
		$this->assertStringNotContainsString( 'src_SECRET_TOKEN', $body, $label . ': the payment token reached the message' );

		$expected = false === $value
			? 'refused a protected meta key {order_custom_field:_stripe_source_id}'
			: 'refused an invalid non-boolean meta permission result';

		$this->assertStringContainsString( $expected, $reason, $label . ': the refusal was not recorded, or recorded under the wrong reason' );

		fwrite(
			STDERR,
			sprintf(
				"  %-24s refused  (%s)\n",
				$label,
				trim( explode( ';', str_replace( 'extonify_wcep_meta_placeholder_allowed', 'the filter', $reason ) )[0] )
			)
		);
	}

	/**
	 * Every shape a callback can hand back, and whether it opens the key.
	 *
	 * @return array<string,array{0:string,1:mixed,2:bool}>
	 */
	public function non_boolean_filter_return_provider(): array {
		return array(
			'string "true"'    => array( 'string "true"', 'true', false ),
			'string "false"'   => array( 'string "false"', 'false', false ),
			'int 1'            => array( 'int 1', 1, false ),
			'int 0'            => array( 'int 0', 0, false ),
			'empty array'      => array( 'empty array', array(), false ),
			'non-empty array'  => array( 'non-empty array', array( 'error' ), false ),
			'WP_Error'         => array( 'WP_Error', 'wp_error', false ),
			'stdClass'         => array( 'stdClass', 'std_class', false ),
			'null'             => array( 'null', null, false ),
			'literal false'    => array( 'literal false', false, false ),
			'literal true'     => array( 'literal true', true, true ),
		);
	}

	/**
	 * 6B ITEM 4a. ⚠ AN EMPTY PARAMETER IS NOT "NO PARAMETER" (ADR-0014 §1e).
	 *
	 * `{customer_email:}` used to be COERCED into `{customer_email}` and resolved
	 * to the customer's address. §7's recipient safe set authorises the exact NAME
	 * and refuses anything parameterised, so the coercion was handing the safe set
	 * a token it had never authorised — the same "junk repaired into a valid
	 * target" shape removed from the trigger, `native_email_id` and meta-key
	 * boundaries.
	 *
	 * All three consumers are asserted in one send: the body's non-parameterised
	 * name, the body's meta namespace, and a recipient field.
	 *
	 * @return void
	 */
	public function test_an_empty_parameter_is_refused_by_every_consumer() {
		$product_id = $this->make_simple_product( 'WCEP Empty Param' );
		$order      = $this->full_order( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule(
			$product_id,
			array(
				'trigger_value' => 'processing',
				'content'       => '<p>empty_name=[{customer_email:}]</p>'
					. '<p>real_name=[{customer_email}]</p>'
					. '<p>empty_meta=[{order_custom_field:}]</p>',
				'recipients'    => array(
					'to' => array( 'customer' ),
					// ⚠ THE RECIPIENT CASE. Under the coercion this resolved to
					// `ada@example.test` and DELIVERED.
					'cc' => array( '{customer_email:}' ),
				),
			)
		);

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$got = $this->markers( (string) $this->last_mail()['message'] );

		$this->assertSame( '', $got['empty_name'], '⚠ {customer_email:} was coerced into {customer_email} and resolved' );
		$this->assertSame( 'ada@example.test', $got['real_name'], 'the genuine token stopped resolving' );
		$this->assertSame( '', $got['empty_meta'] );

		// THE CC ENTRY WAS REFUSED WHOLE, so the customer's address is not in it.
		$headers = $this->headers_of( (array) $this->last_mail() );
		$this->assertStringNotContainsStringIgnoringCase( 'cc:', $headers, '⚠ an empty-parameter token authorised a Cc entry' );

		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:processing' );
		$this->track_delivery( (int) $tombstone['id'] );
		$reason = (string) $this->detail_rows( (int) $tombstone['id'] )[0]['reason'];

		$this->assertStringContainsString( 'unknown placeholder {customer_email:}', $reason, 'the coerced name was not recorded as unknown' );
		$this->assertStringContainsString( 'refused a meta placeholder with an empty key {order_custom_field:}', $reason );
		$this->assertStringContainsString( 'refused a disallowed placeholder in a cc entry: {customer_email:}', $reason );

		fwrite(
			STDERR,
			"\n[6B item 4a] empty parameter, three consumers:\n"
			. "  {customer_email:}      body      -> '' + recorded unknown (was: the customer's address)\n"
			. "  {order_custom_field:}  body      -> '' + recorded empty key\n"
			. "  {customer_email:}      cc entry  -> whole entry refused (was: DELIVERED)\n"
		);
	}

	/**
	 * 6. MULTI-MATCH: the singular placeholders take the FIRST matched item in
	 *    line-item order; the plural ones take all (ADR-0014 §5).
	 *
	 * @return void
	 */
	public function test_multi_match_singular_and_plural_placeholders() {
		$first  = $this->make_simple_product( 'WCEP Multi First' );
		$second = $this->make_simple_product( 'WCEP Multi Second' );

		$product = wc_get_product( $first );
		$product->set_sku( 'FIRST-SKU' );
		$product->save();

		$order = $this->full_order(
			array(
				array( $first, 1 ),
				array( $second, 3 ),
			)
		);
		$order_id = (int) $order->get_id();

		$this->make_sending_rule(
			$first,
			array(
				'trigger_value' => 'processing',
				'targeting'     => array( 'include' => array( 'products' => array( $first, $second ) ) ),
				'content'       => '<p>one=[{product_name}]</p><p>sku=[{product_sku}]</p><p>qty=[{product_quantity}]</p>'
					. '<p>all=[{product_names}]</p><p>list=[{matched_product_list}]</p>',
			)
		);

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$got = $this->fold_breaks( $this->markers( (string) $this->last_mail()['message'] ) );

		$this->assertSame( 'WCEP Multi First', $got['one'], 'the singular placeholder did not take the FIRST matched item' );
		$this->assertSame( 'FIRST-SKU', $got['sku'] );
		$this->assertSame( '1', $got['qty'] );
		$this->assertSame( 'WCEP Multi First, WCEP Multi Second', $got['all'] );
		$this->assertSame( '1 × WCEP Multi First 3 × WCEP Multi Second', $got['list'] );

		fwrite(
			STDERR,
			"\n[P6 item 6] multi-match: {product_name}=" . $got['one']
			. ' | {product_names}=' . $got['all'] . ' | {matched_product_list}=' . $got['list'] . "\n"
		);
	}

	/**
	 * 6. A VARIATION RESOLVES ITS OWN NAME AND ATTRIBUTES — and a PARTIALLY
	 *    RESOLVED one resolves EMPTY rather than the parent's (ADR-0011 §4,
	 *    ADR-0014 §5).
	 *
	 * @return void
	 */
	public function test_a_partially_resolved_variation_resolves_empty() {
		$variable = $this->make_variable_product( 'WCEP Variation Parent', array( 'Small', 'Large' ) );

		// TWO orders for the same variation, both built while it exists. The
		// second one is delivered AFTER the variation is deleted — a fresh
		// identity, so nothing is suppressed and the two renders are comparable.
		$order    = $this->full_order( array( array( $variable['variations'][0], 1 ) ) );
		$order_id = (int) $order->get_id();

		$later    = $this->full_order( array( array( $variable['variations'][0], 1 ) ) );
		$later_id = (int) $later->get_id();

		$this->make_sending_rule(
			$variable['parent'],
			array(
				'trigger_value' => 'processing',
				'targeting'     => array( 'include' => array( 'products' => array( $variable['parent'] ) ) ),
				'content'       => '<p>name=[{variation_name}]</p><p>attrs=[{variation_attributes}]</p><p>item=[{product_name}]</p>',
			)
		);

		// --- WHILE THE VARIATION EXISTS. ---------------------------------------
		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$live = $this->markers( (string) $this->last_mail()['message'] );

		$this->assertNotSame( '', $live['name'], 'a live variation resolved no name' );
		$this->assertStringContainsString( 'Small', $live['attrs'], 'a live variation resolved no attributes' );

		// --- NOW DELETE THE VARIATION: the item is PARTIALLY_RESOLVED. ---------
		wp_delete_post( $variable['variations'][0], true );

		$this->captured_mail = array();

		// A FRESH orchestrator, so the deleted variation is not answered out of
		// the previous run's product cache.
		$this->orchestrator()->handle_status_change( $later_id, 'pending', 'processing' );

		$this->assertMailCount( 1, 'the second delivery did not go out' );
		$after = $this->markers( (string) $this->last_mail()['message'] );

		$this->assertSame( '', $after['name'], 'a deleted variation fell back to the PARENT name' );
		$this->assertSame( '', $after['attrs'], 'a deleted variation fell back to the PARENT attributes' );
		$this->assertNotSame( '', $after['item'], 'the line item still names what the customer bought' );

		fwrite(
			STDERR,
			"\n[P6 item 6] variation: live name=[" . $live['name'] . '] attrs=[' . $live['attrs'] . ']'
			. ' -> after deletion name=[' . $after['name'] . '] attrs=[' . $after['attrs'] . '] item=[' . $after['item'] . "]\n"
		);
	}

	/**
	 * 7. `{customer_email}` IN A RECIPIENT FIELD RESOLVES AND DELIVERS
	 *    (ADR-0014 §7).
	 *
	 * @return void
	 */
	public function test_a_safe_recipient_placeholder_delivers() {
		$product_id = $this->make_simple_product( 'WCEP Recipient Safe' );
		$order      = $this->full_order( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$this->make_sending_rule(
			$product_id,
			array(
				'trigger_value' => 'processing',
				'recipients'    => array(
					'to'  => array( '{customer_email}' ),
					'bcc' => array( '{store_email}' ),
				),
			)
		);

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$this->assertMailCount( 1 );
		$mail = $this->last_mail();

		$this->assertSame( 'ada@example.test', (string) $mail['to'], '{customer_email} did not resolve in a recipient field' );
		$this->assertStringContainsString( 'Bcc: ' . PlaceholderValues::store_email(), $this->headers_of( $mail ), '{store_email} did not resolve in a bcc field' );

		fwrite( STDERR, "\n[P6 item 7] recipients: to=" . $mail['to'] . ' bcc=' . PlaceholderValues::store_email() . "\n" );
	}

	/**
	 * 7. A DISALLOWED PLACEHOLDER IN A RECIPIENT FIELD RESOLVES EMPTY, NOTHING IS
	 *    SENT, AND THE REASON IS RECORDED (ADR-0014 §7).
	 *
	 * @return void
	 */
	public function test_a_disallowed_recipient_placeholder_sends_nothing_and_is_recorded() {
		$product_id = $this->make_simple_product( 'WCEP Recipient Unsafe' );
		$order      = $this->full_order( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule(
			$product_id,
			array(
				'trigger_value' => 'processing',
				'recipients'    => array( 'to' => array( '{customer_first_name}' ) ),
			)
		);

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$this->assertMailCount( 0, 'a rule whose only recipient was a disallowed placeholder still sent' );

		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:processing' );
		$this->assertNotNull( $tombstone, 'the skip was never recorded' );
		$this->track_delivery( (int) $tombstone['id'] );

		$this->assertSame( 'skipped', $tombstone['final_status'] );

		$reason = (string) $this->detail_rows( (int) $tombstone['id'] )[0]['reason'];

		$this->assertStringContainsString( 'refused a disallowed placeholder in a to entry: {customer_first_name}', $reason );

		fwrite( STDERR, "\n[P6 item 7] disallowed recipient: 0 mail, recorded — " . $reason . "\n" );
	}

	/**
	 * 8. AN UNRECOGNISED PLACEHOLDER RESOLVES EMPTY AND IS RECORDED ONCE, not
	 *    once per occurrence (ADR-0014 §1a).
	 *
	 * @return void
	 */
	public function test_an_unknown_placeholder_is_recorded_once() {
		$product_id = $this->make_simple_product( 'WCEP Unknown Token' );
		$order      = $this->full_order( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule(
			$product_id,
			array(
				'trigger_value' => 'processing',
				'subject'       => 'Hi {customer_frist_name}',
				'content'       => '<p>a=[{customer_frist_name}]</p><p>b=[{customer_frist_name}]</p>'
					. '<p>c=[{customer_frist_name}]</p><p>d=[{no_such_thing}]</p>',
			)
		);

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$this->assertMailCount( 1 );
		$mail = $this->last_mail();
		$got  = $this->markers( (string) $mail['message'] );

		// EMPTY, never left literal: a typo must not be delivered to a customer.
		foreach ( array( 'a', 'b', 'c', 'd' ) as $marker ) {
			$this->assertSame( '', $got[ $marker ], 'an unknown placeholder was left literal' );
		}

		$this->assertStringNotContainsString( '{customer_frist_name}', (string) $mail['message'] );
		$this->assertSame( 'Hi', trim( (string) $mail['subject'] ) );

		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:processing' );
		$this->track_delivery( (int) $tombstone['id'] );
		$reason = (string) $this->detail_rows( (int) $tombstone['id'] )[0]['reason'];

		$this->assertSame(
			1,
			substr_count( $reason, 'unknown placeholder {customer_frist_name}' ),
			'the same unknown token was recorded more than once'
		);
		$this->assertStringContainsString( 'unknown placeholder {no_such_thing}', $reason, 'a second distinct token must still be recorded' );

		fwrite( STDERR, "\n[P6 item 8] unknown tokens, 4 occurrences of 2 tokens: " . $reason . "\n" );
	}

	/**
	 * 6A ITEM 3. AT THE PER-ITEM POSITION, ITEM-SCOPED PLACEHOLDERS TAKE THE ITEM
	 * BEING RENDERED (ADR-0014 §5a).
	 *
	 * Two matched line items, each carrying its own meta and its own product name.
	 * Before this fix both blocks printed the FIRST matched item's values: a gift
	 * note, an engraving or a download key against the wrong product line, in the
	 * one position built specifically to sit beside each matched item.
	 *
	 * @return void
	 */
	public function test_per_item_blocks_resolve_against_their_own_line_item() {
		$alpha = $this->make_simple_product( 'WCEP Scoped Alpha' );
		$beta  = $this->make_simple_product( 'WCEP Scoped Beta' );

		foreach ( array( $alpha => 'ALPHA-SKU', $beta => 'BETA-SKU' ) as $id => $sku ) {
			$product = wc_get_product( $id );
			$product->set_sku( $sku );
			$product->save();
		}

		$order = $this->full_order(
			array(
				array( $alpha, 1 ),
				array( $beta, 4 ),
			)
		);
		$order_id = (int) $order->get_id();

		foreach ( $order->get_items() as $item ) {
			$item->update_meta_data( 'gift_note', 'note for ' . $item->get_name() );
			$item->save();
		}

		$rule_id = $this->make_insert_rule(
			$alpha,
			array(
				'insert_position' => 'item_meta',
				'targeting'       => array( 'include' => array( 'products' => array( $alpha, $beta ) ) ),
				// The marker prefix matters: WooCommerce's own order-items template
				// prints every public item meta key it finds, so asserting on the
				// bare note text would assert WooCommerce's behaviour, not this
				// plugin's.
				'content'         => '<p>BLOCK name=[{product_name}] sku=[{product_sku}] qty=[{product_quantity}]'
					. ' note=[{item_custom_field:gift_note}] all=[{product_names}]</p>',
			)
		);

		$mail = $this->send_native( $order_id, 'WC_Email_Customer_Processing_Order' );
		$body = (string) $mail['message'];

		$this->assertSame( 2, substr_count( $body, 'BLOCK name=' ), 'one block per matched line item is the premise of this test' );

		preg_match_all( '/BLOCK name=\[(.*?)\] sku=\[(.*?)\] qty=\[(.*?)\] note=\[(.*?)\] all=\[(.*?)\]/s', $body, $blocks, PREG_SET_ORDER );

		$this->assertCount( 2, $blocks );

		// --- BLOCK 1 IS ALPHA'S, BLOCK 2 IS BETA'S. ---------------------------
		$this->assertSame( 'WCEP Scoped Alpha', $blocks[0][1] );
		$this->assertSame( 'ALPHA-SKU', $blocks[0][2] );
		$this->assertSame( '1', $blocks[0][3] );
		$this->assertSame( 'note for WCEP Scoped Alpha', $blocks[0][4] );

		$this->assertSame( 'WCEP Scoped Beta', $blocks[1][1], 'the second block named the FIRST matched product' );
		$this->assertSame( 'BETA-SKU', $blocks[1][2], 'the second block carried the FIRST product SKU' );
		$this->assertSame( '4', $blocks[1][3], 'the second block carried the FIRST item quantity' );
		$this->assertSame(
			'note for WCEP Scoped Beta',
			$blocks[1][4],
			'the second block printed the FIRST item\'s meta — a gift note on the wrong product line'
		);

		// --- AND THE PLURAL FORM IS UNAFFECTED: it lists everything, everywhere.
		$this->assertSame( 'WCEP Scoped Alpha, WCEP Scoped Beta', $blocks[0][5] );
		$this->assertSame( 'WCEP Scoped Alpha, WCEP Scoped Beta', $blocks[1][5] );

		// ONE RECORD FOR THE RULE, not one per line item (ADR-0013 §1).
		$this->assertSame( 'sent', (string) $this->insert_tombstone( $order_id, $rule_id )['final_status'] );

		fwrite(
			STDERR,
			"\n[6A item 3] per-item blocks, two matched items:\n"
			. '  beside item 1: name=' . $blocks[0][1] . ' sku=' . $blocks[0][2] . ' qty=' . $blocks[0][3] . ' note=' . $blocks[0][4] . "\n"
			. '  beside item 2: name=' . $blocks[1][1] . ' sku=' . $blocks[1][2] . ' qty=' . $blocks[1][3] . ' note=' . $blocks[1][4] . "\n"
			. '  {product_names} in both: ' . $blocks[0][5] . "\n"
		);
	}

	/**
	 * 6A ITEM 3a. EVERY OTHER POSITION, AND ALL OF SEPARATE MODE, STILL TAKE THE
	 * FIRST MATCHED ITEM (ADR-0014 §5, unchanged by §5a).
	 *
	 * The other half of the contract, asserted so §5a cannot quietly become a
	 * global change of meaning.
	 *
	 * @return void
	 */
	public function test_non_per_item_positions_still_take_the_first_matched_item() {
		$alpha = $this->make_simple_product( 'WCEP Unscoped Alpha' );
		$beta  = $this->make_simple_product( 'WCEP Unscoped Beta' );

		$order = $this->full_order(
			array(
				array( $alpha, 1 ),
				array( $beta, 4 ),
			)
		);
		$order_id = (int) $order->get_id();

		foreach ( $order->get_items() as $item ) {
			$item->update_meta_data( 'gift_note', 'note for ' . $item->get_name() );
			$item->save();
		}

		$this->make_insert_rule(
			$alpha,
			array(
				'insert_position' => 'after_order_table',
				'targeting'       => array( 'include' => array( 'products' => array( $alpha, $beta ) ) ),
				'content'         => '<p>TABLE name=[{product_name}] note=[{item_custom_field:gift_note}]</p>',
			)
		);

		$mail = $this->send_native( $order_id, 'WC_Email_Customer_Processing_Order' );
		$body = (string) $mail['message'];

		preg_match_all( '/TABLE name=\[(.*?)\] note=\[(.*?)\]/s', $body, $blocks, PREG_SET_ORDER );

		$this->assertCount( 1, $blocks, 'a non-per-item position emits once' );
		$this->assertSame( 'WCEP Unscoped Alpha', $blocks[0][1] );
		$this->assertSame( 'note for WCEP Unscoped Alpha', $blocks[0][2] );

		fwrite(
			STDERR,
			"\n[6A item 3a] after_order_table (one block): name=" . $blocks[0][1] . ' note=' . $blocks[0][2] . "\n"
		);
	}

	/**
	 * 9. COST: 25 placeholders cost what 1 costs (ADR-0014 §8, gate 6).
	 *
	 * @return void
	 */
	public function test_placeholder_count_does_not_change_the_query_count() {
		global $wpdb;

		$names = array_slice( $this->placeholder_names(), 0, 25 );
		$this->assertCount( 25, $names );

		$spell = static function ( array $list ): string {
			return '<p>' . implode(
				' ',
				array_map(
					static function ( string $name ): string {
						return '{' . $name . '}';
					},
					$list
				)
			) . '</p>';
		};

		$without_shipping = array_values( array_diff( $names, array( 'shipping_method' ) ) );

		/*
		 * SIX BODIES, BECAUSE "25 versus 1" CONFLATES TWO VARIABLES — how MANY
		 * placeholders a body carries, and what CLASS of data they read. The gate
		 * is about the first, so each comparison below isolates it.
		 */
		$variants = array(
			'order_only'    => array( 'body' => '<p>Hello {customer_first_name}.</p>' ),
			'one_product'   => array( 'body' => '<p>Thanks for the {product_name}.</p>' ),
			'shipping_only' => array( 'body' => '<p>Sent by {shipping_method}.</p>' ),
			'no_shipping'   => array( 'body' => $spell( $without_shipping ) ),
			'all_25'        => array( 'body' => $spell( $names ) ),
			'repeated_75'   => array( 'body' => $spell( $names ) . $spell( $names ) . $spell( $names ) ),
		);

		// ONE PRODUCT AND ONE RULE PER VARIANT: rules are global, so two rules
		// targeting one product would both match and the measurement would be of
		// two deliveries against one.
		foreach ( $variants as $key => $variant ) {
			$variants[ $key ]['product'] = $this->make_simple_product( 'WCEP Cost ' . $key );

			$product = wc_get_product( $variants[ $key ]['product'] );
			$product->set_sku( 'WCEP-SKU-' . $key );
			$product->save();

			$this->make_sending_rule(
				$variants[ $key ]['product'],
				array(
					'trigger_value' => 'processing',
					'targeting'     => array( 'include' => array( 'products' => array( $variants[ $key ]['product'] ) ) ),
					'content'       => $variant['body'],
				)
			);
		}

		$deliver = function ( int $product_id ) use ( $wpdb ): int {
			$order    = $this->full_order( array( array( $product_id, 2 ) ) );
			$order_id = (int) $order->get_id();

			$before = $wpdb->num_queries;
			$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );
			$cost = $wpdb->num_queries - $before;

			foreach ( $this->tombstones_for( $order_id ) as $row ) {
				$this->track_delivery( (int) $row['id'] );
			}

			return $cost;
		};

		/*
		 * COLD then WARM. The v1.0 set touches four REQUEST-LEVEL lookups the
		 * first time anything in a request resolves them — the my-account page id
		 * and its permalink (`{my_account_url}`, `{view_order_url}`), the store's
		 * from-address option, and WooCommerce's tax-rate classes by way of
		 * `wc_price()` — every one of which WordPress caches for the rest of the
		 * request. Both numbers are reported so the warming is visible rather than
		 * hidden by a warm-up (ADR-0014 §8).
		 */
		$cold = array();
		$warm = array();

		foreach ( $variants as $key => $variant ) {
			$cold[ $key ] = $deliver( $variant['product'] );
		}

		foreach ( $variants as $key => $variant ) {
			$warm[ $key ] = $deliver( $variant['product'] );
		}

		$this->assertMailCount( 12 );

		// 1. NO COST PER PLACEHOLDER: 23 more placeholders of classes the delivery
		//    has already loaded cost nothing at all.
		$this->assertSame(
			$warm['one_product'],
			$warm['no_shipping'],
			'resolution added a query per placeholder: 1 cost ' . $warm['one_product'] . ', 24 cost ' . $warm['no_shipping']
		);

		// 2. NO COST PER OCCURRENCE: the same 25 placeholders written three times
		//    over resolve once each (ADR-0014 §8's memo).
		$this->assertSame(
			$warm['all_25'],
			$warm['repeated_75'],
			'resolution added a query per OCCURRENCE: 25 cost ' . $warm['all_25'] . ', 75 cost ' . $warm['repeated_75']
		);

		/*
		 * 3. ⚠ AND THE WHOLE DIFFERENCE BETWEEN 1 AND 25 IS ONE PLACEHOLDER'S
		 *    DATA CLASS, NOT THEIR NUMBER. `{shipping_method}` calls
		 *    `WC_Order::get_shipping_method()`, which loads the order's SHIPPING
		 *    line items — a line-item TYPE nothing else in the delivery path has
		 *    read, so WooCommerce fetches them and primes their meta. It costs the
		 *    same whether the placeholder appears once or a hundred times.
		 */
		$this->assertSame(
			$warm['shipping_only'] - $warm['order_only'],
			$warm['all_25'] - $warm['no_shipping'],
			'the 1-vs-25 difference is not attributable to {shipping_method} alone'
		);

		fwrite(
			STDERR,
			"\n[P6 item 9 / gate 6] whole-delivery query cost, by body:\n"
			. sprintf( "  %-14s %-42s cold %2d   warm %2d\n", 'order_only', '{customer_first_name}', $cold['order_only'], $warm['order_only'] )
			. sprintf( "  %-14s %-42s cold %2d   warm %2d\n", 'one_product', '{product_name}', $cold['one_product'], $warm['one_product'] )
			. sprintf( "  %-14s %-42s cold %2d   warm %2d\n", 'shipping_only', '{shipping_method}', $cold['shipping_only'], $warm['shipping_only'] )
			. sprintf( "  %-14s %-42s cold %2d   warm %2d\n", 'no_shipping', '24 placeholders, no {shipping_method}', $cold['no_shipping'], $warm['no_shipping'] )
			. sprintf( "  %-14s %-42s cold %2d   warm %2d\n", 'all_25', '25 placeholders', $cold['all_25'], $warm['all_25'] )
			. sprintf( "  %-14s %-42s cold %2d   warm %2d\n", 'repeated_75', 'the same 25, written three times', $cold['repeated_75'], $warm['repeated_75'] )
			. sprintf(
				"  => no cost per placeholder (1 == 24) and none per occurrence (25 == 75);\n"
				. "     the only difference between 1 and 25 is {shipping_method}, +%d queries, once per delivery.\n",
				$warm['all_25'] - $warm['no_shipping']
			)
		);
	}

	/**
	 * 6A ITEM 5 / GATE 6 (AMENDED). ONE BOUNDED, NAMED COST PER DISTINCT DATA
	 * CLASS — enumerated, measured, and asserted against a DECLARED contract
	 * (ADR-0014 §8).
	 *
	 * ⚠ THE EXPECTED COSTS ARE DECLARED IN THIS ARRAY, ABOVE THE MEASUREMENT, AND
	 * THE TEST FAILS IF THE MEASUREMENT DISAGREES. That is the difference between a
	 * gate and a report: the previous cost test asserted only relative equalities,
	 * so a class that started costing a query would have been described accurately
	 * and passed silently. A new cost now has to be written down here by somebody
	 * who noticed it.
	 *
	 * @return void
	 */
	public function test_query_cost_is_bounded_per_data_class() {
		global $wpdb;

		/*
		 * THE CONTRACT. Each entry is one data class, the placeholders that read it,
		 * and the WARM query cost of adding it to a body that has none of it.
		 */
		$classes = array(
			'baseline'      => array( 'cost' => 0, 'body' => 'No placeholders at all.' ),
			'order_fields'  => array(
				'cost' => 0,
				'body' => '{customer_first_name} {customer_last_name} {customer_full_name} {customer_email}'
					. ' {customer_phone} {order_number} {order_date} {order_status} {payment_method} {view_order_url}',
			),
			'order_total'   => array( 'cost' => 0, 'body' => '{order_total}' ),
			'addresses'     => array( 'cost' => 0, 'body' => '{billing_address} {shipping_address}' ),
			'order_meta'    => array( 'cost' => 0, 'body' => '{order_custom_field:total_paid_note}' ),
			'line_items'    => array( 'cost' => 0, 'body' => '{product_name} {product_quantity}' ),
			'item_meta'     => array( 'cost' => 0, 'body' => '{item_custom_field:gift_note}' ),
			'products'      => array( 'cost' => 0, 'body' => '{product_sku} {product_url} {variation_name} {variation_attributes}' ),
			'product_lists' => array( 'cost' => 0, 'body' => '{product_names} {matched_product_list}' ),
			'store'         => array( 'cost' => 0, 'body' => '{store_name} {store_email} {store_url} {my_account_url}' ),
			// ⚠ THE ONE CLASS THAT COSTS ANYTHING. `WC_Order::get_shipping_method()`
			// loads the order's SHIPPING line items — a line-item TYPE nothing else
			// in the delivery path reads — so WooCommerce fetches them and primes
			// their meta. Once per delivery, however many times it appears, and only
			// bodies that ask for it pay.
			'shipping'      => array( 'cost' => 2, 'body' => '{shipping_method}' ),
		);

		foreach ( $classes as $key => $class ) {
			$classes[ $key ]['product'] = $this->make_simple_product( 'WCEP Class ' . $key );

			$product = wc_get_product( $classes[ $key ]['product'] );
			$product->set_sku( 'WCEP-CLASS-' . $key );
			$product->save();

			// ONE RULE PER PRODUCT: rules are global, so two rules targeting one
			// product would both match and the measurement would be of two
			// deliveries against one.
			$this->make_sending_rule(
				$classes[ $key ]['product'],
				array(
					'trigger_value' => 'processing',
					'targeting'     => array( 'include' => array( 'products' => array( $classes[ $key ]['product'] ) ) ),
					'content'       => '<p>' . $class['body'] . '</p>',
				)
			);
		}

		$deliver = function ( int $product_id ) use ( $wpdb ): int {
			$order    = $this->full_order( array( array( $product_id, 2 ) ) );
			$order_id = (int) $order->get_id();

			foreach ( $order->get_items() as $item ) {
				$item->update_meta_data( 'gift_note', 'Happy birthday' );
				$item->save();
			}

			// RE-READ AFTER SAVING THE ITEM META, so the delivery starts from the
			// same cache state for every variant rather than measuring whichever
			// object the fixture happened to leave warm.
			$order = wc_get_order( $order_id );

			$before = $wpdb->num_queries;
			$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );
			$cost = $wpdb->num_queries - $before;

			foreach ( $this->tombstones_for( $order_id ) as $row ) {
				$this->track_delivery( (int) $row['id'] );
			}

			return $cost;
		};

		$cold = array();
		$warm = array();

		foreach ( $classes as $key => $class ) {
			$cold[ $key ] = $deliver( $class['product'] );
		}

		foreach ( $classes as $key => $class ) {
			$warm[ $key ] = $deliver( $class['product'] );
		}

		$this->assertMailCount( count( $classes ) * 2 );

		$lines    = array();
		$measured = array();

		foreach ( $classes as $key => $class ) {
			$measured[ $key ] = $warm[ $key ] - $warm['baseline'];

			$lines[] = sprintf(
				'  %-14s %-58s cold %2d   warm %2d   delta %+d   declared %+d',
				$key,
				strlen( $class['body'] ) > 56 ? substr( $class['body'], 0, 53 ) . '...' : $class['body'],
				$cold[ $key ],
				$warm[ $key ],
				$measured[ $key ],
				$class['cost']
			);
		}

		fwrite(
			STDERR,
			"\n[6A item 5 / gate 6] whole-delivery query cost by DATA CLASS (warm delta vs baseline):\n"
			. implode( "\n", $lines ) . "\n"
			. "  => one bounded, named cost per class; every class 0 except {shipping_method}, +2 once per delivery.\n"
		);

		foreach ( $classes as $key => $class ) {
			$this->assertSame(
				(int) $class['cost'],
				$measured[ $key ],
				'the ' . $key . ' data class costs ' . $measured[ $key ] . ' queries, not the declared '
					. $class['cost'] . '. Either the cost is a regression, or the contract in ADR-0014 §8 '
					. 'and in this array must be amended DELIBERATELY, naming the class.'
			);
		}
	}

	/**
	 * Print one rendered sample, HTML beside plain, for the report.
	 *
	 * @param string               $label Sample label.
	 * @param array<string,string> $html  HTML markers.
	 * @param array<string,string> $plain Plain markers.
	 * @return void
	 */
	private function report_sample( string $label, array $html, array $plain ) {
		$lines = array( "\n[P6 item 1] rendered sample — " . $label . ' (HTML | plain):' );

		foreach ( $html as $name => $value ) {
			$lines[] = sprintf( '  %-24s %-46s | %s', $name, $this->clip( $value ), $this->clip( (string) ( $plain[ $name ] ?? '' ) ) );
		}

		fwrite( STDERR, implode( "\n", $lines ) . "\n" );
	}

	/**
	 * Shorten a value for the report.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function clip( string $value ): string {
		return strlen( $value ) > 44 ? substr( $value, 0, 41 ) . '...' : $value;
	}
}
