<?php
/**
 * Singleton isolation on the LIVE email object (ADR-0002, ADR-0012 §5).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Domain\TriggerEvent;
use Extonify\WCEP\Email\EmailIdentity;

/**
 * ONE object serves every delivery in a request, so a field left set by
 * delivery A is a field delivery B inherits.
 *
 * That is the cost ADR-0002 accepted when it chose a single fixed `WC_Email`
 * over per-rule registration, and it is the one risk that choice creates. It is
 * therefore tested against the LIVE `WC()->mailer()->get_emails()` object —
 * never a fresh instance, which would carry no state to bleed and would prove
 * nothing.
 */
final class EmailIsolationTest extends DeliveryTestCase {

	/**
	 * 9. Two deliveries to different customers in one request: the second
	 *    inherits nothing from the first, and the object is clean afterwards.
	 *
	 * @return void
	 */
	public function test_consecutive_deliveries_share_no_state() {
		$email = $this->live_email();
		$this->assertNotNull( $email, 'The email class is not registered with WooCommerce.' );

		// Two orders, two customers, two rules with entirely different content.
		$product_a = $this->make_simple_product( 'WCEP Isolation A' );
		$product_b = $this->make_simple_product( 'WCEP Isolation B' );

		$order_a = $this->make_order_with( array( $product_a ) );
		$order_a->set_billing_email( 'alice@example.test' );
		$order_a->save();

		$order_b = $this->make_order_with( array( $product_b ) );
		$order_b->set_billing_email( 'bob@example.test' );
		$order_b->save();

		$this->make_sending_rule(
			$product_a,
			array(
				'name'       => 'rule A',
				'subject'    => 'Subject A',
				'heading'    => 'Heading A',
				'content'    => '<p>Body A.</p>',
				'recipients' => array(
					'to'  => array( 'customer' ),
					'cc'  => array( 'cc-a@example.test' ),
					'bcc' => array( 'bcc-a@example.test' ),
				),
			)
		);

		// Rule B declares NO cc and NO bcc, so anything present on the second
		// message can only have come from the first.
		$this->make_sending_rule(
			$product_b,
			array(
				'name'       => 'rule B',
				'subject'    => 'Subject B',
				'heading'    => 'Heading B',
				'content'    => '<p>Body B.</p>',
				'recipients' => array( 'to' => array( 'customer' ) ),
			)
		);

		$orchestrator = $this->orchestrator();

		$orchestrator->run( wc_get_order( $order_a->get_id() ), TriggerEvent::status( 'completed' ) );
		$orchestrator->run( wc_get_order( $order_b->get_id() ), TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 2, 'Both deliveries should have produced a message.' );

		list( $first, $second ) = $this->captured_mail;

		// The first is what rule A described.
		$this->assertSame( 'alice@example.test', $first['to'] );
		$this->assertSame( 'Subject A', $first['subject'] );
		$this->assertStringContainsString( 'Body A.', (string) $first['message'] );
		$this->assertStringContainsString( 'Heading A', (string) $first['message'] );

		// The second inherits NOTHING from it.
		$this->assertSame( 'bob@example.test', $second['to'], 'The recipient bled between deliveries.' );
		$this->assertSame( 'Subject B', $second['subject'], 'The subject bled between deliveries.' );
		$this->assertStringContainsString( 'Body B.', (string) $second['message'] );
		$this->assertStringNotContainsString( 'Body A.', (string) $second['message'], 'The content bled between deliveries.' );
		$this->assertStringNotContainsString( 'Heading A', (string) $second['message'], 'The heading bled between deliveries.' );

		$headers = $this->headers_of( $second );
		$this->assertStringNotContainsString( 'cc-a@example.test', $headers, 'The CC bled between deliveries.' );
		$this->assertStringNotContainsString( 'bcc-a@example.test', $headers, 'The BCC bled between deliveries.' );
		$this->assertStringNotContainsString( 'alice@example.test', $headers, 'Alice appeared in Bob\'s message.' );

		// And every runtime field is clear once the request is done.
		$this->assertRuntimeStateIsClean( $email );
	}

	/**
	 * 9b. An exception thrown mid-send still leaves the object clean, because
	 *     the reset is in a `finally`.
	 *
	 * Without it, a third-party `pre_wp_mail` filter that throws would leave the
	 * object addressed to the last customer — and the NEXT delivery would
	 * inherit that recipient.
	 *
	 * @return void
	 */
	public function test_an_exception_mid_send_still_resets_the_object() {
		$email = $this->live_email();

		$thrower = static function () {
			throw new \RuntimeException( 'third-party mail filter exploded' );
		};

		add_filter( 'pre_wp_mail', $thrower, 5 );

		try {
			$email->trigger(
				array(
					'recipient' => 'victim@example.test',
					'cc'        => 'cc@example.test',
					'bcc'       => 'bcc@example.test',
					'subject'   => 'Exploding subject',
					'heading'   => 'Exploding heading',
					'content'   => '<p>Exploding body.</p>',
					'object'    => null,
				)
			);
			$this->fail( 'The fixture filter did not throw.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'third-party mail filter exploded', $e->getMessage() );
		} finally {
			remove_filter( 'pre_wp_mail', $thrower, 5 );
		}

		$this->assertRuntimeStateIsClean( $email );
	}

	/**
	 * 9c. A delivery that supplies FEWER fields than the previous one still
	 *     starts from empty.
	 *
	 * This is the case a conditional assignment (`if ( isset( $args['cc'] ) )`)
	 * would get wrong: "not supplied" must mean "empty", never "whatever the
	 * last delivery used".
	 *
	 * @return void
	 */
	public function test_an_omitted_field_is_empty_not_inherited() {
		$email = $this->live_email();

		$email->trigger(
			array(
				'recipient' => 'first@example.test',
				'cc'        => 'cc@example.test',
				'bcc'       => 'bcc@example.test',
				'subject'   => 'First subject',
				'heading'   => 'First heading',
				'content'   => '<p>First body.</p>',
			)
		);

		$this->captured_mail = array();

		// Only a recipient this time. Everything else must come out empty.
		$email->trigger( array( 'recipient' => 'second@example.test' ) );

		$this->assertMailCount( 1 );
		$mail = $this->last_mail();

		$this->assertSame( 'second@example.test', $mail['to'] );
		$this->assertSame( '', $mail['subject'], 'An omitted subject was inherited.' );

		$headers = $this->headers_of( $mail );
		$this->assertStringNotContainsString( 'cc@example.test', $headers, 'An omitted CC was inherited.' );
		$this->assertStringNotContainsString( 'bcc@example.test', $headers, 'An omitted BCC was inherited.' );
		$this->assertStringNotContainsString( 'First body.', (string) $mail['message'], 'An omitted body was inherited.' );

		$this->assertRuntimeStateIsClean( $email );
	}

	/**
	 * The settings-owned fields survive a delivery: `email_type` is store
	 * configuration, not runtime state, and resetting it would silently switch
	 * every later message to the wrong format.
	 *
	 * @return void
	 */
	public function test_the_reset_does_not_clear_store_settings() {
		$email = $this->live_email();

		$before = $email->get_email_type();

		$email->trigger(
			array(
				'recipient' => 'settings@example.test',
				'subject'   => 'Settings check',
				'content'   => '<p>Body.</p>',
			)
		);

		$this->assertSame( $before, $email->get_email_type(), 'The reset cleared a store setting.' );
		$this->assertSame( EmailIdentity::EMAIL_ID, $email->id, 'The reset cleared the email id.' );
		$this->assertTrue( $email->is_enabled(), 'The reset cleared the enabled flag.' );
	}

	/**
	 * `init_form_fields()` exposes ONLY the kill switch and the email type
	 * (ADR-0002): subject, content and recipients are rule-owned, and a second
	 * place to configure them would eventually disagree with the first.
	 *
	 * @return void
	 */
	public function test_the_settings_screen_exposes_only_two_fields() {
		$email = $this->live_email();
		$email->init_form_fields();

		$keys = array_keys( $email->form_fields );
		sort( $keys );

		$this->assertSame( array( 'email_type', 'enabled' ), $keys );
	}

	/**
	 * The class really is in the LIVE registry under its stable key.
	 *
	 * ADR-0002: `woocommerce_email_classes` is applied once, when `WC_Emails`
	 * first builds its list. A class registered after that point exists but is
	 * unreachable — the orchestrator could never find it and no merchant could
	 * switch it off.
	 *
	 * @return void
	 */
	public function test_the_email_is_registered_before_the_mailer_initialises() {
		$emails = WC()->mailer()->get_emails();

		$this->assertArrayHasKey( EmailIdentity::REGISTRY_KEY, $emails );
		$this->assertSame( EmailIdentity::EMAIL_ID, $emails[ EmailIdentity::REGISTRY_KEY ]->id );
	}

	/**
	 * Assert every per-delivery field on the shared object is clear.
	 *
	 * @param \Extonify\WCEP\Email\Custom_Email $email The live object.
	 * @return void
	 */
	private function assertRuntimeStateIsClean( $email ): void {
		$this->assertSame( '', $email->recipient, 'recipient survived the reset.' );
		$this->assertSame( '', $email->cc, 'cc survived the reset.' );
		$this->assertSame( '', $email->bcc, 'bcc survived the reset.' );
		$this->assertSame( '', $email->delivery_subject, 'subject survived the reset.' );
		$this->assertSame( '', $email->delivery_heading, 'heading survived the reset.' );
		$this->assertSame( '', $email->delivery_content, 'content survived the reset.' );
		$this->assertSame( array(), $email->matched_items, 'matched items survived the reset.' );
		$this->assertSame( 0, $email->delivery_id, 'the delivery reference survived the reset.' );
		$this->assertNull( $email->object, 'the order object survived the reset.' );
	}
}
