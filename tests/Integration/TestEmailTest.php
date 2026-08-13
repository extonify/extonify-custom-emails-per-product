<?php
/**
 * GATES 42 and 43 — a test email reaches only the merchant, and consumes no identity.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\ConfirmationToken;
use Extonify\WCEP\Admin\DeliveryActions;
use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Admin\Notices;
use Extonify\WCEP\Delivery\DeliveryLogger;
use Extonify\WCEP\Delivery\ManualDelivery;
use Extonify\WCEP\Delivery\TestDelivery;
use Extonify\WCEP\Domain\DeliveryIdentity;
use Extonify\WCEP\Domain\TriggerEvent;
use Extonify\WCEP\Repository\DeliveryDetailRepository;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * The test send: a real email that must never reach a customer.
 *
 * ⚠ SEVERITY: gate 42 is TIER 1 and is the reason this file exists. A test email that
 * reaches a customer's address is an unintended email to a real person, sent by a
 * feature whose entire purpose is to avoid that. Gate 43 is TIER 1 too, in the other
 * direction: a test that consumed the automatic identity would silently disable the rule
 * for that order, and nothing on any screen would say so.
 */
final class TestEmailTest extends PreviewTestCase {

	/**
	 * The address a test is expected to reach.
	 */
	const MERCHANT = 'wcep-merchant@example.test';

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
			fwrite( STDERR, "\n[P12 gates 42-43] " . $line );
		}

		$this->gate = array();
	}

	/**
	 * GATE 42. A TEST GOES TO THE SUPPLIED ADDRESS AND TO NOBODY ELSE — for a rule whose
	 *          recipients include the CUSTOMER, which is the case that can actually fail.
	 *
	 * @return void
	 */
	public function test_a_test_email_never_reaches_the_customer() {
		$this->become_manager();

		$fixture = $this->previewable(
			array(
				// ⚠ EVERY CHANNEL POPULATED, DELIBERATELY. A rule addressing the customer
				// on `to`, the store admin on `cc` and a literal address on `bcc` is the
				// worst case: three independent sources, any one of which leaking would
				// put a real person on a test message.
				'recipients' => array(
					'to'  => array( 'customer' ),
					'cc'  => array( 'admin' ),
					'bcc' => array( 'ops@example.test' ),
				),
			)
		);

		$customer = strtolower( (string) $fixture['order']->get_billing_email() );

		$this->assertNotSame( '', $customer, 'the order fixture has no billing email, so this test would prove nothing.' );

		$outcome = $this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT ) );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'the test send was refused: ' . $this->refusal_of( $outcome ) );

		$this->assertMailCount( 1, 'the test send did not send exactly one message.' );

		$mail       = $this->last_mail();
		$recipients = $this->every_recipient_of( (array) $mail );

		$this->assertSame(
			array( strtolower( self::MERCHANT ) ),
			$recipients,
			'⚠ TIER 1: a test email reached an address other than the one the merchant supplied: '
			. implode( ', ', $recipients )
		);

		// Named explicitly, so a failure says WHICH source leaked.
		$this->assertNotContains( $customer, $recipients, '⚠ TIER 1: the test email reached the ORDER\'S CUSTOMER.' );
		$this->assertNotContains( strtolower( (string) get_option( 'admin_email', '' ) ), $recipients, '⚠ TIER 1: the test email reached the STORE ADMIN.' );
		$this->assertNotContains( 'ops@example.test', $recipients, '⚠ TIER 1: the test email reached the rule\'s literal BCC address.' );

		// And no Cc/Bcc header was emitted at all.
		$headers = $this->headers_of( (array) $mail );

		$this->assertSame( 0, preg_match( '/^\s*cc\s*:/mi', $headers ), '⚠ TIER 1: a test email carried a Cc header.' );
		$this->assertSame( 0, preg_match( '/^\s*bcc\s*:/mi', $headers ), '⚠ TIER 1: a test email carried a Bcc header.' );

		$this->gate[] = "gate 42 (recipient sources): a rule addressing to=customer, cc=admin, bcc=ops@example.test sent "
			. "its test to EXACTLY [" . implode( ', ', $recipients ) . "] — 1 address, 0 Cc headers, 0 Bcc headers\n"
			. "             ┌────────────────────────────────────┬──────────────────────────────────────────┐\n"
			. "             │ recipient source                   │ excluded because                         │\n"
			. "             ├────────────────────────────────────┼──────────────────────────────────────────┤\n"
			. "             │ rule recipients `to`               │ resolve_recipients() is never called     │\n"
			. "             │ rule recipients `cc`               │ same — override replaces the document    │\n"
			. "             │ rule recipients `bcc`              │ same — override replaces the document    │\n"
			. "             │ TOKEN_CUSTOMER (billing email)     │ same — the resolver never runs           │\n"
			. "             │ TOKEN_ADMIN (admin_email option)   │ same — the resolver never runs           │\n"
			. "             │ TOKEN_STORE (store address)        │ same — the resolver never runs           │\n"
			. "             │ {customer_email} placeholder       │ same — recipient placeholders unresolved │\n"
			. "             │ {store_email} placeholder          │ same — recipient placeholders unresolved │\n"
			. "             │ Custom_Email::inject_copy_headers  │ cc and bcc are '' so it adds nothing     │\n"
			. "             │ woocommerce_email_recipient_{id}   │ ⚠ NOT excluded — WooCommerce's own hook, │\n"
			. "             │                                    │   outside this plugin; ADR-0020 records  │\n"
			. "             │                                    │   it as an accepted residual risk        │\n"
			. "             └────────────────────────────────────┴──────────────────────────────────────────┘\n";
	}

	/**
	 * GATE 42b. THE RECIPIENT OVERRIDE IS THE ONLY SOURCE — asserted on the value, not
	 *           only on the captured mail.
	 *
	 * @return void
	 */
	public function test_the_test_recipient_set_holds_one_address_on_one_channel() {
		$recipients = TestDelivery::recipients_for( self::MERCHANT );

		$this->assertSame( array( self::MERCHANT ), $recipients->addresses( 'to' ), 'the test recipient set is wrong.' );
		$this->assertSame( array(), $recipients->addresses( 'cc' ), '⚠ TIER 1: the test recipient set has a cc.' );
		$this->assertSame( array(), $recipients->addresses( 'bcc' ), '⚠ TIER 1: the test recipient set has a bcc.' );
		$this->assertTrue( $recipients->is_deliverable(), 'the test recipient set is not deliverable.' );
		$this->assertCount( 1, $recipients->entries(), '⚠ TIER 1: the test recipient set holds more than one entry.' );

		/*
		 * And `TestDelivery` never reaches any of them, asserted on its CODE.
		 *
		 * ⚠ COMMENTS ARE STRIPPED FIRST, AND THAT IS NOT A CONVENIENCE. This class
		 * explains at length WHY it does not use `RecipientResolver`, so a raw substring
		 * scan is tripped by the very prose that documents the guarantee — which would
		 * push a future author to delete the explanation to make the test pass. Scanning
		 * the executable tokens makes the assertion mean what it says.
		 */
		$code = $this->code_without_comments( dirname( __DIR__, 2 ) . '/src/Delivery/TestDelivery.php' );

		foreach ( array( 'RecipientResolver', 'resolve_recipients', 'get_billing_email', 'admin_email' ) as $forbidden ) {
			$this->assertStringNotContainsString(
				$forbidden,
				$code,
				'⚠ TIER 1: TestDelivery reaches "' . $forbidden . '" in code, which is a path to an address the '
				. 'merchant did not type.'
			);
		}

		// The premise: the scan really did keep the code it is meant to search.
		$this->assertStringContainsString(
			'ResolvedRecipients::create',
			$code,
			'the comment-stripped scan lost the code it is supposed to be searching.'
		);

		$this->gate[] = 'gate 42 (source): the override holds exactly 1 entry on `to`, 0 on cc, 0 on bcc; and '
			. 'TestDelivery.php contains no reference to RecipientResolver, resolve_recipients, get_billing_email or admin_email';
	}

	/**
	 * GATE 43. A TEST CONSUMES NO AUTOMATIC IDENTITY: the real delivery still sends.
	 *
	 * @return void
	 */
	public function test_a_test_consumes_no_identity_and_the_real_delivery_still_sends() {
		$this->become_manager();

		$fixture = $this->previewable();

		$outcome = $this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT ) );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'the test send was refused: ' . $this->refusal_of( $outcome ) );
		$this->assertMailCount( 1, 'the test did not send.' );

		// NOW LET THE AUTOMATIC TRIGGER FIRE, for the same rule, order and trigger.
		$this->captured_mail = array();

		$this->orchestrator()->run( $fixture['order'], TriggerEvent::status( 'completed' ) );

		$automatic = $this->tombstone( $fixture['order_id'], $fixture['rule'], 'status:completed' );

		$this->assertIsArray(
			$automatic,
			'⚠ TIER 1: after a test send, the automatic delivery claimed NO identity — the test consumed it.'
		);

		$this->track_delivery( (int) $automatic['id'] );

		$this->assertSame(
			'sent',
			(string) $automatic['final_status'],
			'⚠ TIER 1: after a test send, the real automatic delivery did not send.'
		);

		$this->assertMailCount( 1, '⚠ TIER 1: the real automatic delivery did not produce its message.' );

		// The customer got the REAL one, with no test marker on it.
		$real = $this->last_mail();

		$this->assertContains(
			strtolower( (string) $fixture['order']->get_billing_email() ),
			$this->every_recipient_of( (array) $real ),
			'the automatic delivery did not reach the customer.'
		);

		$this->assertStringNotContainsString(
			'[Test]',
			(string) $real['subject'],
			'⚠ the real customer email carries the test marker.'
		);

		// TWO tombstones, under two disjoint identities.
		$identities = array();

		foreach ( $this->deliveries->find_for_order( $fixture['order_id'] ) as $row ) {
			$this->track_delivery( (int) $row['id'] );

			$identities[] = (string) $row['trigger_identity'];
		}

		$this->assertCount( 2, $identities, 'expected exactly two tombstones: one test, one automatic.' );

		$test_identities = array_values( array_filter( $identities, array( DeliveryIdentity::class, 'is_test' ) ) );

		$this->assertCount( 1, $test_identities, 'expected exactly one `test:` identity.' );

		$this->assertStringStartsWith(
			DeliveryIdentity::TEST_PREFIX . ':',
			$test_identities[0],
			'the test tombstone does not carry a `test:` identity.'
		);

		$this->assertContains( 'status:completed', $identities, 'the automatic identity is missing.' );

		$this->gate[] = 'gate 43 (identity): after a confirmed test, the SAME rule/order/trigger automatic delivery still '
			. 'claims `status:completed`, sends to the customer and records `sent`. Two tombstones exist under two '
			. 'disjoint identities (' . implode( ' + ', $identities ) . '), and the customer\'s copy carries no [Test] marker';
	}

	/**
	 * THE ATTEMPT ROW IS TYPED `test`, which is what makes it tellable from a real send.
	 *
	 * @return void
	 */
	public function test_the_attempt_row_is_typed_test() {
		$this->become_manager();

		$fixture = $this->previewable();

		$outcome = $this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT ) );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'the test send was refused.' );

		$tombstones = $this->deliveries->find_for_order( $fixture['order_id'] );

		$this->assertCount( 1, $tombstones, 'expected exactly one tombstone.' );

		$delivery_id = (int) $tombstones[0]['id'];

		$this->track_delivery( $delivery_id );

		$this->assertSame( 'sent', (string) $tombstones[0]['final_status'], 'the test did not record as sent.' );
		$this->assertSame( DeliveryLogger::MODE, (string) $tombstones[0]['mode'], 'the test was not recorded in separate mode.' );

		$rows = $this->detail_rows( $delivery_id );

		$this->assertCount( 1, $rows, 'expected exactly one attempt row for a test send.' );

		$this->assertSame(
			'test',
			(string) $rows[0]['type'],
			'⚠ the test send\'s attempt row is typed "' . $rows[0]['type'] . '", so the history cannot tell it from a real send.'
		);

		$this->assertContains(
			'test',
			DeliveryDetailRepository::TYPES,
			'`test` is not in the storage vocabulary.'
		);

		// The row records the merchant's address, not the customer's.
		$this->assertSame(
			self::MERCHANT,
			(string) $rows[0]['recipient'],
			'⚠ TIER 1: the attempt row records a recipient other than the test address.'
		);

		$this->gate[] = 'typing: a confirmed test writes 1 tombstone (sent, mode=separate, identity test:…) and exactly 1 '
			. 'attempt row typed `test` recording the merchant\'s address — the first code path ever to write that type';
	}

	/**
	 * THE SUBJECT IS VISIBLY MARKED AS A TEST, in the message AND in the record.
	 *
	 * @return void
	 */
	public function test_the_test_subject_is_marked() {
		$this->become_manager();

		$fixture = $this->previewable( array( 'subject' => 'Care guide for your order' ) );

		$this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT ) );

		$this->assertMailCount( 1, 'the test did not send.' );

		$mail = $this->last_mail();

		$this->assertStringStartsWith(
			'[Test]',
			(string) $mail['subject'],
			'⚠ the test email\'s subject is not marked, so it could be mistaken for a live customer email.'
		);

		$this->assertStringContainsString(
			'Care guide for your order',
			(string) $mail['subject'],
			'the marker replaced the subject instead of prefixing it.'
		);

		// ⚠ AND THE RECORDED SUBJECT CARRIES IT TOO. A history row showing a plain
		// subject while the mail said `[Test] …` would be the history lying about a
		// message that exists.
		$tombstones = $this->deliveries->find_for_order( $fixture['order_id'] );

		$this->track_delivery( (int) $tombstones[0]['id'] );

		$rows = $this->detail_rows( (int) $tombstones[0]['id'] );

		$this->assertStringStartsWith(
			'[Test]',
			(string) $rows[0]['subject'],
			'⚠ the recorded subject does not carry the marker the customer-facing one did.'
		);

		$this->gate[] = 'marker: the sent subject and the RECORDED subject both begin "[Test]" and both still contain the '
			. 'merchant\'s own resolved subject line';
	}

	/**
	 * A TEST RENDERS THE SAME CONTENT A REAL SEND WOULD.
	 *
	 * @return void
	 */
	public function test_a_test_renders_the_same_body_a_real_send_would() {
		$this->become_manager();

		$fixture = $this->previewable(
			array( 'content' => '<p>Care for {product_name}, {customer_first_name}.</p>' )
		);

		$this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT ) );

		$this->assertMailCount( 1, 'the test did not send.' );

		$test_body = (string) $this->last_mail()['message'];

		$this->assertStringContainsString(
			'WCEP Preview Product',
			$test_body,
			'the test did not resolve {product_name} against the real order.'
		);

		// Now the REAL one, and compare the bodies.
		$this->captured_mail = array();

		$this->orchestrator()->run( $fixture['order'], TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 1, 'the real delivery did not send.' );

		$real_body = (string) $this->last_mail()['message'];

		foreach ( $this->deliveries->find_for_order( $fixture['order_id'] ) as $row ) {
			$this->track_delivery( (int) $row['id'] );
		}

		$this->assertSame(
			$real_body,
			$test_body,
			'⚠ the test email\'s body differs from the real delivery\'s, so a merchant is testing something the '
			. 'customer will never receive.'
		);

		$this->gate[] = 'content parity: the test message body is BYTE-IDENTICAL to the body the automatic delivery sent '
			. 'to the customer for the same rule and order';
	}

	/**
	 * GATE 37, EXTENDED. A REPLAYED TEST SUBMISSION SENDS ONE EMAIL AND WRITES ONE ROW.
	 *
	 * @return void
	 */
	public function test_a_replayed_test_submission_sends_exactly_one_email() {
		$this->become_manager();

		$fixture = $this->previewable();

		$post = $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT );

		$first = $this->submit_test( $post );

		$this->assertSame( '', $this->refusal_of( $first ), 'the first submission was refused.' );

		// ⚠ THE SAME `$_POST`, BYTE FOR BYTE, THREE MORE TIMES. A browser reload, a
		// back-and-resubmit and a double-click all produce exactly this.
		for ( $replay = 0; $replay < 3; $replay++ ) {
			$again = $this->submit_test( $post );

			$this->assertSame(
				'replayed',
				$this->refusal_of( $again ),
				'⚠ TIER 1: a replayed test submission was not refused as a replay.'
			);
		}

		$this->assertMailCount( 1, '⚠ TIER 1: replaying a confirmed test sent more than one email.' );

		$tombstones = $this->deliveries->find_for_order( $fixture['order_id'] );

		$this->assertCount( 1, $tombstones, '⚠ replaying a test created more than one tombstone.' );

		$this->track_delivery( (int) $tombstones[0]['id'] );

		$this->assertCount(
			1,
			$this->detail_rows( (int) $tombstones[0]['id'] ),
			'⚠ replaying a test wrote more than one attempt row.'
		);

		// ⚠ AND THE SECOND BARRIER HOLDS ON ITS OWN. Re-issue the token store's answer by
		// submitting with a FRESH token but the SAME identity value — which is what a
		// merchant would get if the option row were wiped. `claim()` suppresses it off
		// the UNIQUE index, exactly as ADR-0019 §5 says.
		$revived = $post;

		$revived[ DeliveryActions::FIELD_TOKEN ] = $post[ DeliveryActions::FIELD_TOKEN ];

		add_option( ConfirmationToken::PREFIX . $post[ DeliveryActions::FIELD_TOKEN ], (string) ( time() + 600 ), '', 'no' );

		$second = $this->submit_test( $revived );

		$this->assertSame(
			ManualDelivery::REFUSED_ALREADY_EXISTS,
			$this->refusal_of( $second ),
			'⚠ TIER 1: with the token row restored, a replay was NOT suppressed by the identity index.'
		);

		$this->assertMailCount( 1, '⚠ TIER 1: the identity backstop let a second email out.' );

		$this->gate[] = 'gate 37 (test): 1 confirmation + 3 identical replays + 1 replay with the token row RESTORED => '
			. '1 email, 1 tombstone, 1 attempt row; the replays refuse `replayed` and the restored-token replay refuses '
			. '`already_delivered` off the UNIQUE index';
	}

	/**
	 * TWO DELIBERATE CONFIRMATIONS SEND TWO TESTS — the positive control for the replay
	 * test above, without which "one email" could just mean "sending is broken".
	 *
	 * @return void
	 */
	public function test_two_deliberate_confirmations_send_two_tests() {
		$this->become_manager();

		$fixture = $this->previewable();

		$this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT ) );
		$this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT ) );

		$this->assertMailCount( 2, 'two deliberate confirmations did not send two tests.' );

		$tombstones = $this->deliveries->find_for_order( $fixture['order_id'] );

		$this->assertCount( 2, $tombstones, 'two deliberate tests did not produce two tombstones.' );

		$identities = array();

		foreach ( $tombstones as $row ) {
			$this->track_delivery( (int) $row['id'] );

			$identities[] = (string) $row['trigger_identity'];
		}

		$this->assertCount( 2, array_unique( $identities ), 'the two tests shared an identity.' );

		$this->gate[] = 'gate 37 (positive control): two DELIBERATE confirmations send two tests under two distinct '
			. '`test:` identities, so the replay assertions above cannot pass because sending is broken';
	}

	/**
	 * A BLANK ADDRESS DEFAULTS TO THE SIGNED-IN MERCHANT'S OWN.
	 *
	 * @return void
	 */
	public function test_a_blank_address_defaults_to_the_current_user() {
		$user_id = $this->become_manager();

		$mine = strtolower( (string) get_userdata( $user_id )->user_email );

		$this->assertNotSame( '', $mine, 'the manager fixture has no email address.' );

		$fixture = $this->previewable();

		$outcome = $this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], '' ) );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'the default-address test was refused.' );
		$this->assertMailCount( 1, 'the default-address test did not send.' );

		$this->assertSame(
			array( $mine ),
			$this->every_recipient_of( (array) $this->last_mail() ),
			'⚠ a blank address did not default to the signed-in merchant\'s own.'
		);

		foreach ( $this->deliveries->find_for_order( $fixture['order_id'] ) as $row ) {
			$this->track_delivery( (int) $row['id'] );
		}

		$this->gate[] = 'default address: an empty address field sends to the SIGNED-IN USER\'s own address (' . $mine . '), '
			. 'never to the order or the rule';
	}

	/**
	 * EVERY TEST-SEND REFUSAL NAMES ITS OWN REASON, sends nothing and writes nothing.
	 *
	 * @dataProvider refusal_provider
	 *
	 * @param string $expected Refusal code.
	 * @param string $scenario What is being set up.
	 * @return void
	 */
	public function test_every_test_refusal_names_its_own_reason( string $expected, string $scenario ) {
		$this->become_manager();

		$order_id = 0;
		$rule_id  = 0;
		$address  = self::MERCHANT;

		if ( 'rule_deleted' === $scenario ) {
			$fixture  = $this->previewable();
			$order_id = $fixture['order_id'];
			$rule_id  = 999999;
		}

		if ( 'insert_mode' === $scenario ) {
			$fixture  = $this->previewable_insert();
			$order_id = $fixture['order_id'];
			$rule_id  = $fixture['rule'];
		}

		if ( 'vocabulary' === $scenario ) {
			$fixture  = $this->previewable();
			$order_id = $fixture['order_id'];
			$rule_id  = $fixture['rule'];

			$this->write_raw_consolidation( $rule_id, 'weekly_digest' );
		}

		if ( 'bad_address' === $scenario ) {
			$fixture  = $this->previewable();
			$order_id = $fixture['order_id'];
			$rule_id  = $fixture['rule'];
			$address  = 'not an address';
		}

		if ( 'order_missing' === $scenario ) {
			$fixture  = $this->previewable();
			$rule_id  = $fixture['rule'];
			$order_id = 999999;
		}

		if ( 'no_items' === $scenario ) {
			$targeted = $this->make_product( 'WCEP Test Targeted' );
			$other    = $this->make_product( 'WCEP Test Other' );

			$rule_id  = $this->make_sending_rule( $targeted );
			$order    = $this->make_order( $other );
			$order_id = (int) $order->get_id();

			$this->captured_mail = array();
		}

		if ( 'globally_off' === $scenario ) {
			$fixture  = $this->previewable();
			$order_id = $fixture['order_id'];
			$rule_id  = $fixture['rule'];

			$this->set_email_setting( 'enabled', 'no' );
		}

		$before = $this->delivery_checksums();

		$outcome = $this->submit_test( $this->test_post( $order_id, $rule_id, $address ) );

		$this->assertRefusedWith( $outcome, $expected );

		$this->assertSame(
			$before,
			$this->delivery_checksums(),
			'⚠ TIER 1: a refused test send (' . $scenario . ') wrote to a delivery table — a consumed identity with '
			. 'nothing behind it.'
		);

		// And the refusal has its own merchant-facing sentence (gate 38).
		$messages = Notices::delivery_messages();

		$this->assertArrayHasKey(
			'wcep_refused_' . $expected,
			$messages,
			'⚠ refusal "' . $expected . '" has no merchant-facing sentence.'
		);

		$this->gate[] = 'refusal: ' . str_pad( $scenario, 14 ) . ' => ' . $expected . ' (0 mail, 0 rows, own sentence)';
	}

	/**
	 * The refusal cases.
	 *
	 * @return array[]
	 */
	public function refusal_provider(): array {
		return array(
			'a deleted rule'                => array( ManualDelivery::REFUSED_RULE_DELETED, 'rule_deleted' ),
			'an insert-mode rule'           => array( ManualDelivery::REFUSED_RULE_INSERT_MODE, 'insert_mode' ),
			'a rule outside the vocabulary' => array( ManualDelivery::REFUSED_RULE_VOCABULARY, 'vocabulary' ),
			'an unusable address'           => array( TestDelivery::REFUSED_BAD_ADDRESS, 'bad_address' ),
			'a missing order'               => array( ManualDelivery::REFUSED_ORDER_MISSING, 'order_missing' ),
			'a rule matching nothing'       => array( ManualDelivery::REFUSED_NO_ITEMS, 'no_items' ),
			'custom emails switched off'    => array( ManualDelivery::REFUSED_EMAIL_MISSING, 'globally_off' ),
		);
	}

	/**
	 * A TEST SEND REFUSES OVER GET (gate 36).
	 *
	 * @return void
	 */
	public function test_a_test_send_refuses_over_get() {
		$this->become_manager();

		$fixture = $this->previewable();

		$post = $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT );

		$this->use_method( 'GET' );
		$this->request( array( 'page' => Menu::HISTORY_PAGE ), $post );

		$outcome = DeliveryActions::handle( DeliveryActions::ACTION_TEST, $post );

		$this->assertSame(
			'denied',
			$this->refusal_of( $outcome ),
			'⚠ TIER 1: a test send was accepted over GET.'
		);

		$this->assertMailCount( 0, '⚠ TIER 1: a GET test send sent mail.' );

		// ⚠ AND IT DID NOT BURN THE TOKEN ON ITS WAY TO BEING REFUSED, so the merchant
		// does not have to re-confirm because of a mistyped URL.
		$this->assertTrue(
			ConfirmationToken::consume( (string) $post[ DeliveryActions::FIELD_TOKEN ] ),
			'⚠ a refused GET consumed the merchant\'s single-use confirmation token.'
		);

		$this->gate[] = 'gate 36 (test): a fully valid confirmed test refuses over GET, sends nothing, and does NOT '
			. 'consume the confirmation token';
	}

	/**
	 * THE CONFIRMATION SCREEN SHOWS THE TEST ADDRESS, NEVER THE CUSTOMER'S.
	 *
	 * @return void
	 */
	public function test_the_confirmation_screen_shows_the_test_address_only() {
		$this->become_manager();

		$fixture = $this->previewable( array( 'recipients' => array( 'to' => array( 'customer' ) ) ) );

		$customer = (string) $fixture['order']->get_billing_email();

		$this->request(
			array(
				'page'                         => Menu::HISTORY_PAGE,
				'wcep_action'                  => DeliveryActions::ACTION_TEST,
				DeliveryActions::FIELD_ORDER   => $fixture['order_id'],
				DeliveryActions::FIELD_RULE    => $fixture['rule'],
				DeliveryActions::FIELD_ADDRESS => self::MERCHANT,
			)
		);

		$markup = $this->capture(
			static function () {
				Menu::render_history();
			}
		);

		$this->assertStringContainsString( self::MERCHANT, $markup, 'the confirmation did not show the test address.' );

		$this->assertStringNotContainsString(
			$customer,
			$markup,
			'⚠ TIER 1: the test confirmation screen showed the CUSTOMER\'S address, telling the merchant the exact '
			. 'opposite of what is about to happen.'
		);

		// It states the two facts a merchant cannot infer.
		$this->assertStringContainsString( 'not to the customer', $markup, 'the confirmation does not say who it will not reach.' );
		$this->assertStringContainsString( 'marked as a test', $markup, 'the confirmation does not mention the subject marker.' );

		// It is a POST form carrying a nonce and a token, and no send URL.
		$this->assertStringContainsString( 'method="post"', $markup, 'the confirmation is not a POST form.' );
		$this->assertStringContainsString( DeliveryActions::FIELD_TOKEN, $markup, 'the confirmation carries no token.' );
		$this->assertStringContainsString( DeliveryActions::FIELD_NONCE, $markup, 'the confirmation carries no nonce.' );

		$this->assertSame(
			0,
			preg_match( '/href="[^"]*action=' . preg_quote( DeliveryActions::ACTION_TEST, '/' ) . '/', $markup ),
			'⚠ TIER 1 (gate 36): the confirmation screen rendered a GET link that would send.'
		);

		$this->gate[] = 'confirmation: the test confirmation shows ' . self::MERCHANT . ' and NOT the customer\'s address, '
			. 'states "not to the customer" and "marked as a test", and renders a POST form with a nonce and a token and '
			. '0 sending GET links';
	}

	/**
	 * THE TEST TOMBSTONE IS NOT IN FLIGHT AND BLOCKS NOTHING.
	 *
	 * @return void
	 */
	public function test_a_test_identity_is_disjoint_from_every_automatic_prefix() {
		$token = str_repeat( 'a', 32 );

		$identity = DeliveryIdentity::test( $token );

		$this->assertSame( 'test:' . $token, $identity, 'the test identity has the wrong shape.' );
		$this->assertTrue( DeliveryIdentity::is_valid_trigger_identity( $identity ), 'the test identity is not a valid trigger identity.' );
		$this->assertTrue( DeliveryIdentity::is_test( $identity ), 'is_test() does not recognise its own output.' );
		$this->assertFalse( DeliveryIdentity::is_manual( $identity ), 'a test identity reads as a manual one.' );
		$this->assertContains( DeliveryIdentity::TEST_PREFIX, DeliveryIdentity::TRIGGER_PREFIXES, '`test` is not a recognised prefix.' );

		// ⚠ THE HASH DIFFERS FROM EVERY AUTOMATIC IDENTITY FOR THE SAME ORDER AND RULE,
		// which is what "consumes no identity" means at the storage layer.
		$test_hash = DeliveryIdentity::hash( 7, 9, DeliveryLogger::MODE, $identity );

		foreach ( array( 'status:completed', 'transition:pending>processing', 'refund:12', 'native:customer_processing_order', 'manual:' . $token ) as $other ) {
			$this->assertNotSame(
				$test_hash,
				DeliveryIdentity::hash( 7, 9, DeliveryLogger::MODE, $other ),
				'⚠ TIER 1: a test identity hashes the same as "' . $other . '" — it would suppress that delivery.'
			);
		}

		$this->assertNotContains(
			'test',
			DeliveryRepository::IN_FLIGHT_STATUSES,
			'the vocabulary check is malformed.'
		);

		$this->gate[] = 'gate 43 (disjointness): `test:<token>` is a valid trigger identity, is recognised by is_test(), '
			. 'is NOT manual, and hashes differently from status:, transition:, refund:, native: and manual: for the '
			. 'same order and rule';
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * One production file's source with every comment removed.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private function code_without_comments( string $path ): string {
		$code = '';

		foreach ( token_get_all( (string) file_get_contents( $path ) ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			$code .= is_array( $token ) ? $token[1] : $token;
		}

		return $code;
	}

	/**
	 * Put a value this plugin does not accept into a rule's `consolidation` column,
	 * past the repository's validator.
	 *
	 * @param int    $rule_id Rule id.
	 * @param string $value   Raw column value.
	 * @return void
	 */
	private function write_raw_consolidation( int $rule_id, string $value ): void {
		global $wpdb;

		$table = \Extonify\WCEP\Install\Migrator::table( 'rules' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only direct write of the plugin-owned table; bypassing the validator IS the point.
		$wpdb->update( $table, array( 'consolidation' => $value ), array( 'id' => $rule_id ) );
	}
}
