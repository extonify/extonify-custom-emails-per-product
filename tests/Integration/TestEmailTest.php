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
use Extonify\WCEP\Email\Custom_Email;
use Extonify\WCEP\Email\EmailIdentity;
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
			. "             │ woocommerce_email_recipient_{id}   │ EXCLUDED — the send-boundary lock, P13A  │\n"
			. "             │ woocommerce_email_headers          │ EXCLUDED — Cc/Bcc stripped at the lock   │\n"
			. "             │ woocommerce_email_cc_recipient_{id} │ same                                    │\n"
			. "             │ woocommerce_mail_callback_params   │ EXCLUDED — re-asserted at PHP_INT_MAX    │\n"
			. "             │ wp_mail (wp_mail()'s own filter)   │ EXCLUDED — identified at PHP_INT_MIN,    │
             │                                    │ enforced at PHP_INT_MAX                  │\n"
			. "             ├────────────────────────────────────┼──────────────────────────────────────────┤\n"
			. "             │ phpmailer_init                     │ STATED BOUNDARY — not locked, and why:   │\n"
			. "             │                                    │ ADR-0020 §4b / gate 42e. Past wp_mail's  │\n"
			. "             │                                    │ arguments the transport is the site's.   │\n"
			. "             └────────────────────────────────────┴──────────────────────────────────────────┘\n";
	}

	/**
	 * GATE 42c. THE LOCK, ASSERTED ON THE OUTCOME AGAINST FILTERS THAT ARE REALLY
	 *           TRYING TO INJECT A CUSTOMER ADDRESS (PROMPT 13A, ITEM 2, TIER 1).
	 *
	 * ⚠ THE OVERRIDE WAS NOT THE GUARANTEE, AND THIS IS THE TEST THAT SAYS SO.
	 * `TestDelivery` builds the recipient itself and never reads the rule's document —
	 * gate 42b asserts that on the code, and it was already true. But
	 * `Custom_Email::trigger()` passes `WC_Email::get_recipient()`, which applies
	 * `woocommerce_email_recipient_{id}`, and the header block goes through
	 * `woocommerce_email_headers` and WooCommerce's own Cc/Bcc accessors. Gate 42
	 * requires the ADDRESS THE MESSAGE REACHED, not the address the plugin chose.
	 *
	 * ⚠ AND THIS IS NOT A HYPOTHETICAL PLUGIN. "Send a copy of every WooCommerce email
	 * to the manager" is an ordinary category of plugin; each callback below is the
	 * two-line shape such a plugin uses. On a store running one, a test send used to
	 * mail a real person a real customer's order details.
	 *
	 * @return void
	 */
	public function test_a_test_send_is_locked_against_every_recipient_filter() {
		$this->become_manager();

		$fixture = $this->previewable();

		$customer = strtolower( (string) $fixture['order']->get_billing_email() );

		$this->assertNotSame( '', $customer, 'the order fixture has no billing email, so this test would prove nothing.' );

		$intruder = 'wcep-manager@example.test';
		$id       = EmailIdentity::EMAIL_ID;

		// 1. The recipient filter WooCommerce applies inside `get_recipient()`.
		$this->hook(
			'woocommerce_email_recipient_' . $id,
			static function ( $recipient ) use ( $customer, $intruder ) {
				return trim( (string) $recipient . ', ' . $customer . ', ' . $intruder, ', ' );
			},
			10,
			1
		);

		// 2. The header block, appended to AFTER this plugin's own injector has run.
		$this->hook(
			'woocommerce_email_headers',
			static function ( $headers ) use ( $customer, $intruder ) {
				return (string) $headers . 'Cc: ' . $customer . "\r\n" . 'Bcc: ' . $intruder . "\r\n";
			},
			PHP_INT_MAX,
			1
		);

		// 3. WooCommerce's own Cc/Bcc accessors, where the version offers them.
		foreach ( array( 'cc', 'bcc' ) as $channel ) {
			$this->hook(
				'woocommerce_email_' . $channel . '_recipient_' . $id,
				static function () use ( $customer ) {
					return $customer;
				},
				10,
				1
			);
		}

		// 4. The last hook before the mailer is CALLED.
		$this->hook(
			'woocommerce_mail_callback_params',
			static function ( $params ) use ( $customer ) {
				if ( is_array( $params ) ) {
					$params[0] = $customer;
				}

				return $params;
			},
			10,
			2
		);

		/*
		 * 5. ⚠ AND THE FIRST HOOK INSIDE THE MAILER, WHICH IS THE PROMPT 13B ADDITION.
		 * `wp_mail()` opens with `apply_filters( 'wp_mail', compact( 'to', 'subject',
		 * 'message', 'headers', 'attachments' ) )` — AFTER everything WooCommerce
		 * applies and after every guarantee `WC_Email::send()` is able to make. Until
		 * Prompt 13B nothing of this plugin's covered it, so a site-wide "Bcc every
		 * outgoing message" integration hooked here put a third party on a test send.
		 *
		 * The capture this test asserts against reads `pre_wp_mail`, which WordPress
		 * fires on the NEXT statement — so this filter's changes are genuinely in the
		 * captured message unless the lock removes them.
		 */
		$this->hook(
			'wp_mail',
			static function ( $args ) use ( $customer, $intruder ) {
				if ( ! is_array( $args ) ) {
					return $args;
				}

				$args['to']      = $customer;
				$args['headers'] = (string) ( $args['headers'] ?? '' )
					. 'Cc: ' . $customer . "\r\n"
					. 'Bcc: ' . $intruder . "\r\n";

				return $args;
			},
			10,
			1
		);

		$outcome = $this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT ) );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'the test send was refused: ' . $this->refusal_of( $outcome ) );
		$this->assertMailCount( 1, 'the test send did not send exactly one message.' );

		$mail       = $this->last_mail();
		$recipients = $this->every_recipient_of( (array) $mail );

		// ⚠ ASSERTED ON THE CAPTURED MESSAGE, NOT ON THE OVERRIDE. That is the whole
		// difference between this test and gate 42b.
		$this->assertSame(
			array( strtolower( self::MERCHANT ) ),
			$recipients,
			'⚠ TIER 1: with recipient filters active, a test email reached: ' . implode( ', ', $recipients )
		);

		$this->assertNotContains( $customer, $recipients, '⚠ TIER 1: a filter put the CUSTOMER on a test email.' );
		$this->assertNotContains( $intruder, $recipients, '⚠ TIER 1: a filter put a third party on a test email.' );

		$headers = $this->headers_of( (array) $mail );

		$this->assertSame( 0, preg_match( '/^\s*cc\s*:/mi', $headers ), '⚠ TIER 1: a filtered test email carried a Cc header.' );
		$this->assertSame( 0, preg_match( '/^\s*bcc\s*:/mi', $headers ), '⚠ TIER 1: a filtered test email carried a Bcc header.' );

		$this->gate[] = 'gate 42c (outcome under hostile filters): with woocommerce_email_recipient_{id}, '
			. 'woocommerce_email_headers, woocommerce_email_cc/bcc_recipient_{id}, '
			. 'woocommerce_mail_callback_params AND wp_mail ALL adding the customer, the captured test message '
			. 'reached EXACTLY [' . implode( ', ', $recipients ) . '] with 0 Cc and 0 Bcc headers';
	}

	/**
	 * GATE 42e. THE LOCK'S LAST STEP IS `wp_mail`, AND `phpmailer_init` IS A DECIDED
	 *           BOUNDARY RATHER THAN AN OVERSIGHT (PROMPT 13B, ITEM 1).
	 *
	 * ⚠ WHY A BOUNDARY GETS A TEST AT ALL. Gate 42c proves the OUTCOME through
	 * `wp_mail`; this proves the SHAPE that outcome rests on, so the claims
	 * `Custom_Email::arm_wp_mail_lock()` and ADR-0020 §4b make in prose cannot drift
	 * away from the code without a red test:
	 *
	 *   1. the lock really is registered on `wp_mail` at BOTH ends while the send is in
	 *      progress — `PHP_INT_MIN` to identify the message before any other callback has
	 *      touched it, `PHP_INT_MAX` to enforce the recipient after they all have. ⚠ THE
	 *      TWO PRIORITIES ARE THE MECHANISM, NOT AN IMPLEMENTATION DETAIL (Part A2): one
	 *      callback doing both jobs at either end is a Tier 1 defect in one direction or
	 *      the other, and gate 42i is what it looks like;
	 *   2. this plugin registers NOTHING on `phpmailer_init` for the lock — the
	 *      boundary is where the documentation says it is;
	 *   3. BOTH callbacks are GONE afterwards. A `wp_mail` filter left attached would
	 *      rewrite the recipient of the next unrelated message the request sends,
	 *      which is Tier 1 in its own right and is the standing cost of this design.
	 *
	 * @return void
	 */
	public function test_the_lock_stops_at_wp_mail_and_leaves_no_filter_behind() {
		$this->become_manager();

		$fixture = $this->previewable();

		$base_wp_mail        = $this->callbacks_at( 'wp_mail', PHP_INT_MAX );
		$base_identify       = $this->callbacks_at( 'wp_mail', PHP_INT_MIN );
		$base_phpmailer_init = $this->callback_count( 'phpmailer_init' );

		$observed = array();

		/*
		 * OBSERVED FROM INSIDE `wp_mail()` ITSELF, at a priority BELOW the lock's — the
		 * one-shot removes itself the instant it fires, so `pre_wp_mail` would already
		 * be too late to see it attached.
		 */
		$this->hook(
			'wp_mail',
			function ( $args ) use ( &$observed ) {
				$observed[] = array(
					'wp_mail'        => $this->callbacks_at( 'wp_mail', PHP_INT_MAX ),
					'identify'       => $this->callbacks_at( 'wp_mail', PHP_INT_MIN ),
					'phpmailer_init' => $this->callback_count( 'phpmailer_init' ),
				);

				return $args;
			},
			10,
			1
		);

		$outcome = $this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT ) );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'the test send was refused: ' . $this->refusal_of( $outcome ) );
		$this->assertMailCount( 1, 'the test send did not send exactly one message.' );

		$this->assertCount( 1, $observed, 'the wp_mail filter did not fire exactly once for the test send.' );

		$this->assertSame(
			$base_wp_mail + 1,
			$observed[0]['wp_mail'],
			'⚠ TIER 1: the recipient lock was not registered on wp_mail at PHP_INT_MAX during the test send.'
		);

		/*
		 * ⚠ OBSERVED FROM PRIORITY 10, SO THE IDENTIFY CALLBACK HAS ALREADY RUN AND IS
		 * STILL ATTACHED. It must be, because a filter between the two ends may call
		 * `wp_mail()` itself and that nested invocation needs its own entry pushed.
		 */
		$this->assertSame(
			$base_identify + 1,
			$observed[0]['identify'],
			'⚠ TIER 1: the recipient lock was not registered on wp_mail at PHP_INT_MIN during the test send, so '
				. 'the message is being identified AFTER third-party callbacks have had a chance to rewrite it — '
				. 'the Part A2 defect, restored. See gate 42i.'
		);

		$this->assertSame(
			$base_phpmailer_init,
			$observed[0]['phpmailer_init'],
			'the lock registered a phpmailer_init callback. That is a DIFFERENT design from the one '
				. 'ADR-0020 §4b and docs/p2-backlog.md describe — update both, and prove the new one against '
				. 'a real PHPMailer, before changing this assertion.'
		);

		$this->assertSame(
			$base_wp_mail,
			$this->callbacks_at( 'wp_mail', PHP_INT_MAX ),
			'⚠ TIER 1: the enforcing wp_mail callback is still attached after the send — the next unrelated '
				. 'message this request sends would be redirected to the test address.'
		);

		$this->assertSame(
			$base_identify,
			$this->callbacks_at( 'wp_mail', PHP_INT_MIN ),
			'⚠ the identifying wp_mail callback is still attached after the send.'
		);

		$this->gate[] = 'gate 42e (lock shape): during the test send wp_mail carried '
			. $observed[0]['identify'] . ' callback(s) at PHP_INT_MIN (' . $base_identify . ' before it) and '
			. $observed[0]['wp_mail'] . ' at PHP_INT_MAX (' . $base_wp_mail . ' before it) — identify early, '
			. 'enforce late — while phpmailer_init carried ' . $observed[0]['phpmailer_init'] . ', unchanged, the '
			. 'STATED BOUNDARY. After the send wp_mail is back to '
			. $this->callbacks_at( 'wp_mail', PHP_INT_MIN ) . ' at PHP_INT_MIN and '
			. $this->callbacks_at( 'wp_mail', PHP_INT_MAX ) . ' at PHP_INT_MAX';
	}

	/**
	 * GATE 42f. THE LOCK NEVER TOUCHES A MESSAGE THAT IS NOT THIS SEND'S.
	 *
	 * ⚠ THIS IS THE OTHER HALF OF ITEM 1, AND WITHOUT IT THE FIX IS ITS OWN DEFECT.
	 * `wp_mail` carries no identifying argument, so a lock held open for the duration
	 * of `WC_Email::send()` would rewrite the recipient of EVERY message sent from
	 * inside it — and third-party callbacks on `woocommerce_mail_content` and
	 * `pre_wp_mail` genuinely do send other messages. Redirecting somebody else's mail
	 * to the merchant's test address is the same tier of harm as leaking a test to a
	 * customer, pointing the other way.
	 *
	 * The scope is structural: the one-shot is armed on the statement before the mail
	 * callback runs and removes itself as it fires, so a message sent from anywhere
	 * later inside the same `wp_mail()` call cannot reach it.
	 *
	 * @return void
	 */
	public function test_the_lock_does_not_touch_another_message_sent_during_it() {
		$this->become_manager();

		$fixture = $this->previewable();

		$nested = 'wcep-nested@example.test';
		$sent   = false;

		// A third party sending its own notification from inside this very `wp_mail()`
		// call — the shape a mail-logging or CRM integration uses.
		$this->hook(
			'pre_wp_mail',
			static function ( $short_circuit, $atts ) use ( $nested, &$sent ) {
				if ( ! $sent ) {
					$sent = true;
					wp_mail( $nested, 'A third party\'s own message', 'body' );
				}

				return $short_circuit;
			},
			2,
			2
		);

		$outcome = $this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT ) );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'the test send was refused: ' . $this->refusal_of( $outcome ) );
		$this->assertTrue( $sent, 'the nested message was never sent, so this test would prove nothing.' );
		$this->assertMailCount( 2, 'the test send and the nested message did not both reach the capture.' );

		// The capture is at `pre_wp_mail` priority 1 and the nested send happens at
		// priority 2, so the test message is captured first and the nested one second.
		$ours   = $this->every_recipient_of( (array) $this->captured_mail[0] );
		$theirs = $this->every_recipient_of( (array) $this->captured_mail[1] );

		$this->assertSame( array( strtolower( self::MERCHANT ) ), $ours, 'the test message did not reach the merchant alone.' );

		$this->assertSame(
			array( $nested ),
			$theirs,
			'⚠ TIER 1: the recipient lock redirected ANOTHER plugin\'s message to the test address. '
				. 'It reached: ' . implode( ', ', $theirs )
		);

		$this->gate[] = 'gate 42f (scope, nested message): a third party sending its own message from inside '
			. 'the locked send reached [' . implode( ', ', $theirs ) . '] while the test reached ['
			. implode( ', ', $ours ) . '] — the lock applied to exactly one of the two';
	}

	/**
	 * GATE 42g. THE LOCK IDENTIFIES **THIS MESSAGE**, NOT THE NEXT MAIL CALL
	 *           (PROMPT 13C, ITEM 1, TIER 1).
	 *
	 * ⚠ THE HOLE THIS CLOSES IS IN THE MECHANISM BUILT TO CLOSE A HOLE.
	 * `WC_Email::send()` reads:
	 *
	 *     $mail_callback        = apply_filters( 'woocommerce_mail_callback', 'wp_mail', $this );
	 *     $mail_callback_params = apply_filters( 'woocommerce_mail_callback_params', [...], $this );
	 *     $return               = (bool) call_user_func_array( $mail_callback, $mail_callback_params );
	 *
	 * The one-shot is armed on the middle line. Until Prompt 13C it carried no identity
	 * and fired on WHATEVER `wp_mail` CALL CAME NEXT — so a replacement callback that
	 * mails something of its own **before** forwarding ours consumed the shot on that
	 * message. Two Tier 1 outcomes at once: a stranger's email redirected to the
	 * merchant's test address, and the real test message left unguarded through the one
	 * filter the shot exists for.
	 *
	 * ⚠ REACHABILITY, NOT INFLATED. The common `woocommerce_mail_callback` users are
	 * SMTP and API senders that replace `wp_mail()` outright and never call it — gate
	 * 42h is that case. A wrapper that mails first and forwards second is unusual. It is
	 * still a hole.
	 *
	 * ⚠ FOUR THINGS ARE ASSERTED, AND THE FOURTH IS WHY THE FIX IS NOT ITS OWN DEFECT:
	 *   1. the wrapper's own message keeps its own recipient;
	 *   2. the test message reaches the confirmed address alone, no Cc, no Bcc;
	 *   3. an AUTOMATIC send under the same wrapper and the same filters still honours
	 *      them — the lock stays test-only;
	 *   4. nothing of ours is left registered on either hook afterwards.
	 *
	 * @return void
	 */
	public function test_the_lock_identifies_this_message_and_not_the_next_mail_call() {
		$this->become_manager();

		$fixture = $this->previewable();

		$customer = strtolower( (string) $fixture['order']->get_billing_email() );

		$this->assertNotSame( '', $customer, 'the order fixture has no billing email, so this test would prove nothing.' );

		$nested   = 'wcep-nested@example.test';
		$intruder = 'wcep-manager@example.test';

		$base_wp_mail = $this->callbacks_at( 'wp_mail', PHP_INT_MAX );
		$base_params  = $this->callbacks_at( 'woocommerce_mail_callback_params', PHP_INT_MAX );

		/*
		 * ⚠ THE WRAPPER. It mails its own notification through `wp_mail()` FIRST, then
		 * forwards the parameters it was handed — the shape a CRM or audit integration
		 * takes when it wants to observe every WooCommerce email without replacing the
		 * transport.
		 */
		$this->hook(
			'woocommerce_mail_callback',
			static function () use ( $nested ) {
				return static function ( $to, $subject, $message, $headers = '', $attachments = array() ) use ( $nested ) {
					wp_mail( $nested, 'A wrapper\'s own notification', 'Nothing to do with the test send.' );

					return wp_mail( $to, $subject, $message, $headers, $attachments );
				};
			},
			10,
			2
		);

		/*
		 * ⚠ AND THE HOSTILE `wp_mail` FILTERS ALONGSIDE, because the point is the
		 * OUTCOME under a store that is genuinely rewriting recipients — the gate 42c
		 * condition, with the wrapper added. `to` is APPENDED to rather than replaced so
		 * that "the wrapper's message kept its own recipient" stays a real assertion:
		 * anything this filter adds is the site's own doing, and anything the LOCK adds
		 * is not.
		 */
		$this->hook(
			'wp_mail',
			static function ( $args ) use ( $customer, $intruder ) {
				if ( ! is_array( $args ) ) {
					return $args;
				}

				$args['to']      = trim( (string) ( $args['to'] ?? '' ) . ', ' . $customer, ', ' );
				$args['headers'] = (string) ( $args['headers'] ?? '' )
					. 'Cc: ' . $customer . "\r\n"
					. 'Bcc: ' . $intruder . "\r\n";

				return $args;
			},
			10,
			1
		);

		$outcome = $this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT ) );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'the test send was refused: ' . $this->refusal_of( $outcome ) );

		$this->assertMailCount( 2, 'the wrapper\'s own message and the forwarded test message did not both reach the capture.' );

		// The wrapper mails first and forwards second, so the capture holds them in
		// that order.
		$theirs = $this->every_recipient_of( (array) $this->captured_mail[0] );
		$ours   = $this->every_recipient_of( (array) $this->captured_mail[1] );

		$this->assertContains(
			$nested,
			$theirs,
			'⚠ TIER 1: the wrapper\'s own message did not reach its own address at all. It reached ['
				. implode( ', ', $theirs ) . '] and the test message reached [' . implode( ', ', $ours ) . '] — which is '
				. 'the shot being spent on the wrong message, in both directions at once.'
		);

		$this->assertNotContains(
			strtolower( self::MERCHANT ),
			$theirs,
			'⚠ TIER 1: the one-shot fired on the WRAPPER\'S OWN MESSAGE and redirected it to the test address. '
				. 'It reached: ' . implode( ', ', $theirs )
		);

		$this->assertSame(
			array( strtolower( self::MERCHANT ) ),
			$ours,
			'⚠ TIER 1: with the shot spent on another message, the test email reached: ' . implode( ', ', $ours )
		);

		$this->assertNotContains( $customer, $ours, '⚠ TIER 1: the test email reached the ORDER\'S CUSTOMER.' );
		$this->assertNotContains( $intruder, $ours, '⚠ TIER 1: the test email reached a third party.' );

		$headers = $this->headers_of( (array) $this->captured_mail[1] );

		$this->assertSame( 0, preg_match( '/^\s*cc\s*:/mi', $headers ), '⚠ TIER 1: the test email carried a Cc header.' );
		$this->assertSame( 0, preg_match( '/^\s*bcc\s*:/mi', $headers ), '⚠ TIER 1: the test email carried a Bcc header.' );

		// ⚠ AND THE LOCK SAYS SO ITSELF. `applied` means the one-shot recognised this
		// send's own message rather than merely having been registered.
		$this->assertSame(
			Custom_Email::LOCK_APPLIED,
			$this->live_email()->lock_outcome(),
			'the lock did not report that it recognised and locked this send\'s own message.'
		);

		/*
		 * PHASE 2 — THE SAME WRAPPER, THE SAME FILTERS, AN AUTOMATIC SEND. Without this
		 * the fix would be its own defect: a merchant's recipient customisation is
		 * legitimate on a real send, and the lock exists only on the test path.
		 */
		$this->captured_mail = array();

		$this->orchestrator()->run( $fixture['order'], TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 2, 'the automatic delivery did not produce the wrapper\'s message and its own.' );

		$auto_theirs = $this->every_recipient_of( (array) $this->captured_mail[0] );
		$auto_ours   = $this->every_recipient_of( (array) $this->captured_mail[1] );

		$this->assertContains( $nested, $auto_theirs, 'the wrapper stopped mailing its own notification on the automatic path.' );

		$this->assertContains(
			$customer,
			$auto_ours,
			'the automatic delivery did not reach the customer.'
		);

		$this->assertContains(
			$intruder,
			$auto_ours,
			'⚠ the lock leaked onto the automatic path: a site-wide wp_mail Bcc was stripped from a REAL send.'
		);

		$this->assertSame(
			Custom_Email::LOCK_NONE,
			$this->live_email()->lock_outcome(),
			'⚠ an automatic send reported a recipient lock. The lock is set by Orchestrator::send_test() and by nothing else.'
		);

		// NOTHING LEFT REGISTERED, on either hook the lock uses.
		$this->assertSame(
			$base_wp_mail,
			$this->callbacks_at( 'wp_mail', PHP_INT_MAX ),
			'⚠ TIER 1: the one-shot wp_mail lock is still attached after the send.'
		);

		$this->assertSame(
			$base_params,
			$this->callbacks_at( 'woocommerce_mail_callback_params', PHP_INT_MAX ),
			'⚠ the woocommerce_mail_callback_params lock is still attached after the send.'
		);

		$this->gate[] = 'gate 42g (identity, not position): with a woocommerce_mail_callback wrapper that mails '
			. '[' . $nested . '] before forwarding, and hostile wp_mail To/Cc/Bcc filters active — the wrapper\'s own '
			. 'message reached [' . implode( ', ', $theirs ) . '], the test reached [' . implode( ', ', $ours ) . '] '
			. 'with 0 Cc and 0 Bcc (lock_outcome=' . Custom_Email::LOCK_APPLIED . '), and an AUTOMATIC send under the '
			. 'same wrapper still reached [' . implode( ', ', $auto_ours ) . ']. Both hooks are back to their baseline '
			. 'registration counts';
	}

	/**
	 * GATE 42h. A MAIL CALLBACK THAT NEVER CALLS `wp_mail()` IS THE NORMAL CASE, AND IT
	 *           IS RECORDED RATHER THAN DISCARDED (PROMPT 13C, ITEM 1).
	 *
	 * ⚠ THIS IS WHAT AN SMTP OR API SENDER LOOKS LIKE, and it is by far the commonest
	 * use of `woocommerce_mail_callback`. The one-shot is armed and never fires, because
	 * no `wp_mail()` call ever happens — so the `woocommerce_mail_callback_params` lock
	 * is the last word, and it still delivered exactly one `to` with no copy headers.
	 *
	 * ⚠ WHY IT NEEDS A TEST AT ALL. "Armed and `wp_mail()` never entered" is a
	 * DISTINGUISHABLE condition, and discarding it would throw away the only signal that
	 * this plugin's final guarantee did not reach the transport — so it is recorded on the
	 * object AND written into the delivery record.
	 *
	 * ⚠ IT USED TO COVER TWO CASES AND NOW COVERS ONE (PART A2). `unfired` also meant
	 * "`wp_mail()` ran and nothing in it carried this message", which was the ordinary
	 * outcome whenever a third party rewrote the subject or body. That case is now
	 * `unmatched` (gate 42l), and the two say different things about a store: this one is
	 * a replacement transport, that one is content mutation. The assertion below is on
	 * `unfired` specifically, so the split cannot quietly collapse again.
	 *
	 * @return void
	 */
	public function test_a_mail_callback_that_never_calls_wp_mail_is_recorded() {
		$this->become_manager();

		$fixture = $this->previewable();

		$handed = array();

		$this->hook(
			'woocommerce_mail_callback',
			static function () use ( &$handed ) {
				return static function ( $to, $subject, $message, $headers = '', $attachments = array() ) use ( &$handed ) {
					$handed[] = array(
						'to'      => $to,
						'headers' => $headers,
					);

					// An SMTP sender: it talks to the transport itself and `wp_mail()`
					// is never entered.
					return true;
				};
			},
			10,
			2
		);

		$base_wp_mail = $this->callbacks_at( 'wp_mail', PHP_INT_MAX );

		$outcome = $this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT ) );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'the test send was refused: ' . $this->refusal_of( $outcome ) );

		$this->assertMailCount( 0, 'wp_mail() was entered, so this is not the replacement-sender case.' );
		$this->assertCount( 1, $handed, 'the replacement mail callback was not invoked exactly once.' );

		// The earlier lock still governed what the custom sender received.
		$this->assertSame(
			self::MERCHANT,
			(string) $handed[0]['to'],
			'⚠ TIER 1: the replacement sender was handed an address other than the confirmed one.'
		);

		$this->assertSame(
			0,
			preg_match( '/^\s*b?cc\s*:/mi', (string) $handed[0]['headers'] ),
			'⚠ TIER 1: the replacement sender was handed a Cc or Bcc header.'
		);

		$this->assertSame(
			Custom_Email::LOCK_UNFIRED,
			$this->live_email()->lock_outcome(),
			'the lock did not report that it was armed and never fired.'
		);

		$this->assertSame(
			$base_wp_mail,
			$this->callbacks_at( 'wp_mail', PHP_INT_MAX ),
			'⚠ TIER 1: the one-shot is still attached after a send that never reached wp_mail().'
		);

		// ⚠ AND IT IS IN THE MERCHANT-VISIBLE RECORD, not only on the object.
		$tombstones = $this->deliveries->find_for_order( $fixture['order_id'] );

		$this->assertCount( 1, $tombstones, 'expected exactly one tombstone.' );

		$this->track_delivery( (int) $tombstones[0]['id'] );

		$rows = $this->detail_rows( (int) $tombstones[0]['id'] );

		$this->assertCount( 1, $rows, 'expected exactly one attempt row.' );

		$this->assertStringContainsString(
			'was not applied at wp_mail()',
			(string) $rows[0]['reason'],
			'⚠ the delivery record does not say that the lock stopped short of the transport, so a merchant '
				. 'reading it would believe a guarantee the send did not make.'
		);

		$this->gate[] = 'gate 42h (the stated boundary, recorded): a woocommerce_mail_callback replacement that never '
			. 'calls wp_mail() was handed to=[' . $handed[0]['to'] . '] with 0 Cc and 0 Bcc; the send reports '
			. 'lock_outcome=' . Custom_Email::LOCK_UNFIRED . ' (wp_mail() never entered, distinct from '
			. Custom_Email::LOCK_UNMATCHED . '), the lock is gone, and the attempt row records it in the '
			. 'delivery history';
	}

	/**
	 * GATE 42i. IDENTIFY EARLY, ENFORCE LATE — one `wp_mail` callback that REWRITES the
	 *           message AND INJECTS a recipient (PROMPT 13C PART A2, ITEM 1, TIER 1).
	 *
	 * ⚠ WHY THIS IS TIER 1 AND NOT THE TIER 3 THE SOURCE USED TO CLAIM. Prompt 13C
	 * bound the one-shot to a subject-and-body fingerprint and recorded, as residual 2,
	 * that a footer injector or a subject prefixer would make the fingerprint miss — the
	 * lock would decline, the send would record `unfired`, and nothing would be worse
	 * than before. **That is only true when the mutation happens alone.**
	 *
	 * Subject/body rewriting and recipient injection are both ordinary `wp_mail`
	 * behaviours and they frequently ship in the SAME plugin: brand every outgoing
	 * message, and Bcc the archive. When they coincide the fingerprint misses BECAUSE OF
	 * the branding and the lock therefore declines to strip the Bcc — so a confirmed test
	 * send reaches an address the merchant did not choose. Gate 42's invariant fails, on
	 * a mainline shape rather than an exotic one.
	 *
	 * ⚠ THE INVARIANT, AND IT IS TWO QUESTIONS AT TWO MOMENTS:
	 *
	 *     identify the intended invocation BEFORE mutable `wp_mail` filters run;
	 *     enforce the recipient AFTER they have finished.
	 *
	 * The fix compares the fingerprint at `PHP_INT_MIN`, where the content is still
	 * pristine, and enforces at `PHP_INT_MAX`, after every third party has had its say.
	 * So the assertion this test exists for is the last one: the subject and the body
	 * ARE rewritten — the site's customisation is honoured — and the recipient is still
	 * the confirmed address alone.
	 *
	 * @return void
	 */
	public function test_a_wp_mail_filter_that_rewrites_and_injects_cannot_add_a_recipient() {
		$this->become_manager();

		$fixture = $this->previewable();

		$customer = strtolower( (string) $fixture['order']->get_billing_email() );

		$this->assertNotSame( '', $customer, 'the order fixture has no billing email, so this test would prove nothing.' );

		$intruder = 'wcep-manager@example.test';
		$footer   = 'Sent with Brandy.';

		$base_min = $this->callbacks_at( 'wp_mail', PHP_INT_MIN );
		$base_max = $this->callbacks_at( 'wp_mail', PHP_INT_MAX );

		/*
		 * ⚠ ONE CALLBACK DOING BOTH THINGS, WHICH IS THE POINT. Split across two
		 * plugins each half is harmless on its own; shipped together — and they are
		 * routinely shipped together — the branding is what defeats the identification
		 * and the injection is what the identification existed to stop.
		 */
		$this->hook(
			'wp_mail',
			static function ( $args ) use ( $customer, $intruder, $footer ) {
				if ( ! is_array( $args ) ) {
					return $args;
				}

				$args['subject'] = '[Brandy] ' . (string) ( $args['subject'] ?? '' );
				$args['message'] = (string) ( $args['message'] ?? '' ) . "\n" . $footer;
				$args['to']      = trim( (string) ( $args['to'] ?? '' ) . ', ' . $customer, ', ' );
				$args['headers'] = (string) ( $args['headers'] ?? '' )
					. 'Cc: ' . $customer . "\r\n"
					. 'Bcc: ' . $intruder . "\r\n";

				return $args;
			},
			10,
			1
		);

		$outcome = $this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT ) );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'the test send was refused: ' . $this->refusal_of( $outcome ) );
		$this->assertMailCount( 1, 'the test send did not send exactly one message.' );

		$mail       = $this->last_mail();
		$recipients = $this->every_recipient_of( (array) $mail );

		$this->assertSame(
			array( strtolower( self::MERCHANT ) ),
			$recipients,
			'⚠ TIER 1: a wp_mail callback that rewrote the subject and body AND injected recipients put an '
				. 'address the merchant did not choose on a confirmed test send. It reached: ' . implode( ', ', $recipients )
		);

		$this->assertNotContains( $customer, $recipients, '⚠ TIER 1: the test email reached the ORDER\'S CUSTOMER.' );
		$this->assertNotContains( $intruder, $recipients, '⚠ TIER 1: the test email reached a third party.' );

		$headers = $this->headers_of( (array) $mail );

		$this->assertSame( 0, preg_match( '/^\s*cc\s*:/mi', $headers ), '⚠ TIER 1: the test email carried a Cc header.' );
		$this->assertSame( 0, preg_match( '/^\s*bcc\s*:/mi', $headers ), '⚠ TIER 1: the test email carried a Bcc header.' );

		/*
		 * ⚠ AND THE SITE'S OWN CUSTOMISATION SURVIVED. The lock takes the recipient and
		 * nothing else: a fix that worked by refusing the rewrite would be a different
		 * defect — this plugin overriding a store's branding on its own messages.
		 */
		$this->assertStringStartsWith(
			'[Brandy] ',
			(string) ( $mail['subject'] ?? '' ),
			'the third party\'s subject prefix was lost, so the lock is doing more than taking the recipient.'
		);

		$this->assertStringContainsString(
			$footer,
			(string) ( $mail['message'] ?? '' ),
			'the third party\'s body footer was lost, so the lock is doing more than taking the recipient.'
		);

		$this->assertSame(
			Custom_Email::LOCK_APPLIED,
			$this->live_email()->lock_outcome(),
			'⚠ the lock did not report `applied` for a message it identified before the rewrite. A rewritten '
				. 'subject or body is no longer an accepted `unfired` case (ADR-0020 §4b).'
		);

		/*
		 * PHASE 2 — THE NEGATIVE CONTROL, UNCHANGED. The same filter, an AUTOMATIC send:
		 * every one of its four mutations is honoured, including the two the test send
		 * refused. A merchant's site-wide Cc/Bcc integration is legitimate on a real
		 * delivery and the lock exists only on the test path.
		 */
		$this->captured_mail = array();

		$this->orchestrator()->run( $fixture['order'], TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 1, 'the automatic delivery did not send exactly one message.' );

		$auto      = $this->last_mail();
		$auto_sent = $this->every_recipient_of( (array) $auto );

		$this->assertContains( $customer, $auto_sent, 'the automatic delivery did not reach the customer.' );

		$this->assertContains(
			$intruder,
			$auto_sent,
			'⚠ the lock leaked onto the automatic path: a site-wide wp_mail Bcc was stripped from a REAL send.'
		);

		$this->assertStringStartsWith( '[Brandy] ', (string) ( $auto['subject'] ?? '' ), 'the automatic send lost the site\'s subject prefix.' );

		$this->assertSame(
			Custom_Email::LOCK_NONE,
			$this->live_email()->lock_outcome(),
			'⚠ an automatic send reported a recipient lock. The lock is set by Orchestrator::send_test() and by nothing else.'
		);

		// NOTHING LEFT REGISTERED AT EITHER END OF THE PRIORITY RANGE.
		$this->assertSame( $base_min, $this->callbacks_at( 'wp_mail', PHP_INT_MIN ), '⚠ the identify callback is still attached after the send.' );
		$this->assertSame( $base_max, $this->callbacks_at( 'wp_mail', PHP_INT_MAX ), '⚠ TIER 1: the enforce callback is still attached after the send.' );

		$this->gate[] = 'gate 42i (early identify, late enforce): a single wp_mail callback that prefixed the subject, '
			. 'appended to the body, appended the customer to `to` and added a Cc and a Bcc — the test reached ['
			. implode( ', ', $recipients ) . '] with 0 Cc and 0 Bcc (lock_outcome=' . Custom_Email::LOCK_APPLIED . ') '
			. 'while KEEPING the rewritten subject and body, and the same filter on an AUTOMATIC send still reached ['
			. implode( ', ', $auto_sent ) . ']';
	}

	/**
	 * GATE 42j. A `wp_mail` FILTER THAT SENDS ITS OWN MESSAGE MID-CHAIN — THE CASE THAT
	 *           NEEDS A STACK RATHER THAN A FLAG (PART A2, ITEM 1).
	 *
	 * ⚠ WHY A FLAG WOULD NOT DO, AND THIS TEST IS THE PROOF. Identification happens at
	 * `PHP_INT_MIN` and enforcement at `PHP_INT_MAX`, so between the two the whole rest
	 * of the `wp_mail` chain runs — and a callback there is free to call `wp_mail()`
	 * itself, which mail-logging and CRM integrations genuinely do:
	 *
	 *     our wp_mail()      PHP_INT_MIN   → MATCH
	 *       priority 10 sends its own message
	 *         nested wp_mail() PHP_INT_MIN → NO_MATCH
	 *         nested wp_mail() PHP_INT_MAX → pops NO_MATCH, untouched
	 *     our wp_mail()      PHP_INT_MAX   → pops MATCH, locked
	 *
	 * With ONE boolean the nested invocation's "no" would overwrite the outer's "yes"
	 * and the test message would go out unlocked — with a hostile filter alongside, that
	 * is gate 42's Tier 1 failure. PHP unwinds the nested call completely before the
	 * outer chain resumes, so LIFO gives each invocation its own answer with no identity
	 * to compare: gate 12's depth-keyed rule, on a different surface.
	 *
	 * @return void
	 */
	public function test_a_nested_wp_mail_from_an_intermediate_filter_keeps_its_own_recipient() {
		$this->become_manager();

		$fixture = $this->previewable();

		$nested   = 'wcep-nested@example.test';
		$customer = strtolower( (string) $fixture['order']->get_billing_email() );
		$sent     = false;

		$base_min = $this->callbacks_at( 'wp_mail', PHP_INT_MIN );
		$base_max = $this->callbacks_at( 'wp_mail', PHP_INT_MAX );

		/*
		 * ⚠ IT MAILS **AND** MUTATES, IN THAT ORDER. The nested send happens while this
		 * invocation is still open, and the injection that follows is what makes an
		 * unlocked outer message actually harmful rather than merely unlocked.
		 */
		$this->hook(
			'wp_mail',
			static function ( $args ) use ( $nested, $customer, &$sent ) {
				if ( ! $sent ) {
					$sent = true;
					wp_mail( $nested, 'A third party\'s own message', 'Nothing to do with the test send.' );
				}

				if ( ! is_array( $args ) ) {
					return $args;
				}

				$args['subject'] = '[Brandy] ' . (string) ( $args['subject'] ?? '' );
				$args['headers'] = (string) ( $args['headers'] ?? '' ) . 'Bcc: ' . $customer . "\r\n";

				return $args;
			},
			10,
			1
		);

		$outcome = $this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT ) );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'the test send was refused: ' . $this->refusal_of( $outcome ) );
		$this->assertTrue( $sent, 'the nested message was never sent, so this test would prove nothing.' );
		$this->assertMailCount( 2, 'the test send and the nested message did not both reach the capture.' );

		// The nested call completes inside the outer chain, so it is captured first.
		$theirs = $this->every_recipient_of( (array) $this->captured_mail[0] );
		$ours   = $this->every_recipient_of( (array) $this->captured_mail[1] );

		/*
		 * ⚠ ASSERTED AS "ITS OWN ADDRESS, NOT OURS" RATHER THAN AS AN EXACT SET. The
		 * filter above mutates EVERY message it sees, its own included, so the nested
		 * message legitimately carries the site's Bcc — that is the site's doing and it
		 * stays. What must not be there is the merchant's test address.
		 */
		$this->assertContains(
			$nested,
			$theirs,
			'⚠ TIER 1: the message sent from inside the lock\'s own filter chain lost its own address. '
				. 'It reached: ' . implode( ', ', $theirs )
		);

		$this->assertNotContains(
			strtolower( self::MERCHANT ),
			$theirs,
			'⚠ TIER 1: the recipient lock redirected a message sent from inside its own filter chain to the '
				. 'test address. It reached: ' . implode( ', ', $theirs )
		);

		$this->assertSame(
			array( strtolower( self::MERCHANT ) ),
			$ours,
			'⚠ TIER 1: the nested invocation consumed the outer message\'s answer — a flag rather than a stack. '
				. 'The test reached: ' . implode( ', ', $ours )
		);

		$this->assertSame(
			Custom_Email::LOCK_APPLIED,
			$this->live_email()->lock_outcome(),
			'the lock did not report that it recognised and locked this send\'s own message.'
		);

		// ⚠ AND THE STACK IS BACK TO BASELINE: every entry pushed at PHP_INT_MIN was
		// popped at PHP_INT_MAX, so the `finally` had nothing to discard.
		$this->assertSame(
			0,
			$this->live_email()->lock_stack_residue(),
			'⚠ the wp_mail lock stack was left dirty by a nested invocation: the pushes and the pops did not pair up.'
		);

		$this->assertSame( $base_min, $this->callbacks_at( 'wp_mail', PHP_INT_MIN ), '⚠ the identify callback is still attached after the send.' );
		$this->assertSame( $base_max, $this->callbacks_at( 'wp_mail', PHP_INT_MAX ), '⚠ TIER 1: the enforce callback is still attached after the send.' );

		$this->gate[] = 'gate 42j (nesting, depth-keyed): a priority-10 wp_mail filter that sent its own message '
			. 'mid-chain and then injected a Bcc — the nested message reached [' . implode( ', ', $theirs ) . '], '
			. 'the test reached [' . implode( ', ', $ours ) . '] (lock_outcome=' . Custom_Email::LOCK_APPLIED . '), '
			. 'and the lock stack ended empty with 0 entries discarded';
	}

	/**
	 * GATE 42k. BOTH AT ONCE — a mail-callback wrapper that mails first, and a `wp_mail`
	 *           filter that rewrites and injects on the message it forwards.
	 *
	 * ⚠ THE TWO HALVES OF THIS MECHANISM'S HISTORY, IN ONE SEND. Prompt 13C's defect was
	 * a wrapper consuming a positional shot; Part A2's was a rewrite defeating a
	 * late comparison. Each was fixed against its own regression, and neither regression
	 * exercises the other's shape — a store running an audit wrapper AND a branding
	 * plugin gets both at once, and the two fixes have to hold together rather than
	 * separately.
	 *
	 * The wrapper's own message goes through the same rewriting filter, and everything
	 * that filter does to it is the SITE'S own doing and stays. What must not happen is
	 * this plugin's test address appearing on it, or the test message carrying anything
	 * but the confirmed address.
	 *
	 * @return void
	 */
	public function test_a_wrapper_that_mails_first_and_a_filter_that_rewrites_hold_together() {
		$this->become_manager();

		$fixture = $this->previewable();

		$customer = strtolower( (string) $fixture['order']->get_billing_email() );

		$this->assertNotSame( '', $customer, 'the order fixture has no billing email, so this test would prove nothing.' );

		$nested   = 'wcep-nested@example.test';
		$intruder = 'wcep-manager@example.test';

		$base_min = $this->callbacks_at( 'wp_mail', PHP_INT_MIN );
		$base_max = $this->callbacks_at( 'wp_mail', PHP_INT_MAX );

		// The audit wrapper: it mails its own notification, then forwards ours.
		$this->hook(
			'woocommerce_mail_callback',
			static function () use ( $nested ) {
				return static function ( $to, $subject, $message, $headers = '', $attachments = array() ) use ( $nested ) {
					wp_mail( $nested, 'A wrapper\'s own notification', 'Nothing to do with the test send.' );

					return wp_mail( $to, $subject, $message, $headers, $attachments );
				};
			},
			10,
			2
		);

		// The branding plugin: it rewrites the content of every message and copies the
		// archive on every message.
		$this->hook(
			'wp_mail',
			static function ( $args ) use ( $customer, $intruder ) {
				if ( ! is_array( $args ) ) {
					return $args;
				}

				$args['subject'] = '[Brandy] ' . (string) ( $args['subject'] ?? '' );
				$args['message'] = (string) ( $args['message'] ?? '' ) . "\nSent with Brandy.";
				$args['to']      = trim( (string) ( $args['to'] ?? '' ) . ', ' . $customer, ', ' );
				$args['headers'] = (string) ( $args['headers'] ?? '' ) . 'Bcc: ' . $intruder . "\r\n";

				return $args;
			},
			10,
			1
		);

		$outcome = $this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT ) );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'the test send was refused: ' . $this->refusal_of( $outcome ) );
		$this->assertMailCount( 2, 'the wrapper\'s own message and the forwarded test message did not both reach the capture.' );

		$theirs = $this->every_recipient_of( (array) $this->captured_mail[0] );
		$ours   = $this->every_recipient_of( (array) $this->captured_mail[1] );

		$this->assertContains(
			$nested,
			$theirs,
			'⚠ TIER 1: the wrapper\'s own message did not reach its own address. It reached: ' . implode( ', ', $theirs )
		);

		$this->assertNotContains(
			strtolower( self::MERCHANT ),
			$theirs,
			'⚠ TIER 1: the lock fired on the WRAPPER\'S OWN MESSAGE and redirected it to the test address. '
				. 'It reached: ' . implode( ', ', $theirs )
		);

		$this->assertSame(
			array( strtolower( self::MERCHANT ) ),
			$ours,
			'⚠ TIER 1: with a wrapper mailing first AND a filter rewriting the forwarded message, the test email '
				. 'reached: ' . implode( ', ', $ours )
		);

		$headers = $this->headers_of( (array) $this->captured_mail[1] );

		$this->assertSame( 0, preg_match( '/^\s*cc\s*:/mi', $headers ), '⚠ TIER 1: the test email carried a Cc header.' );
		$this->assertSame( 0, preg_match( '/^\s*bcc\s*:/mi', $headers ), '⚠ TIER 1: the test email carried a Bcc header.' );

		$this->assertStringStartsWith(
			'[Brandy] ',
			(string) ( $this->captured_mail[1]['subject'] ?? '' ),
			'the third party\'s subject prefix was lost from the forwarded message.'
		);

		$this->assertSame(
			Custom_Email::LOCK_APPLIED,
			$this->live_email()->lock_outcome(),
			'the lock did not report that it recognised and locked this send\'s own message.'
		);

		$this->assertSame( 0, $this->live_email()->lock_stack_residue(), '⚠ the wp_mail lock stack was left dirty.' );

		$this->assertSame( $base_min, $this->callbacks_at( 'wp_mail', PHP_INT_MIN ), '⚠ the identify callback is still attached after the send.' );
		$this->assertSame( $base_max, $this->callbacks_at( 'wp_mail', PHP_INT_MAX ), '⚠ TIER 1: the enforce callback is still attached after the send.' );

		$this->gate[] = 'gate 42k (both at once): with a woocommerce_mail_callback wrapper mailing [' . $nested . '] '
			. 'before forwarding AND a wp_mail filter rewriting the subject and body while injecting a recipient — '
			. 'the wrapper\'s message reached [' . implode( ', ', $theirs ) . '], the test reached ['
			. implode( ', ', $ours ) . '] with 0 Cc and 0 Bcc and its subject still rewritten (lock_outcome='
			. Custom_Email::LOCK_APPLIED . ')';
	}

	/**
	 * GATE 42l. THE LOCK LEAVES NOTHING BEHIND — NOT A CALLBACK, NOT A STACK ENTRY —
	 *           INCLUDING WHEN A `wp_mail` FILTER THROWS (PART A2, ITEM 1).
	 *
	 * ⚠ TWO CALLBACKS AND A SET OF DEPTH-KEYED ENTRIES ARE THREE THINGS TO LEAK INSTEAD
	 * OF ONE, AND NOTHING CLEANS THEM UP EARLY. The pair stays armed for the whole of the
	 * parent `WC_Email::send()` — Part A3 removed the self-retirement it used to perform
	 * on a match, because "fires once" was a positional artefact in a mechanism whose
	 * premise is identity. `send()`'s `finally` is the sole cleanup owner, and it is
	 * unconditional.
	 *
	 * That matters here because an invocation can still end without ever reaching
	 * `PHP_INT_MAX`, and the case that skips the most is a throw: a `wp_mail` callback
	 * that raises between the two ends leaves behind the entry its own invocation wrote,
	 * and — before this plugin wrapped the parent — would have left both callbacks
	 * registered for the rest of the request. A `wp_mail` filter still attached after its
	 * owning send is a Tier 1 defect on its own terms: it would meet the next unrelated
	 * message the request sends.
	 *
	 * ⚠ THE STRANDED ENTRY IS COUNTED RATHER THAN HIDDEN. The `finally` removes both
	 * callbacks, then counts and discards whatever depth-keyed entries survived, so
	 * "nothing outlived the send" is an observation instead of a claim.
	 * `lock_stack_residue()` reports that count — 0 on a clean send, exactly 1 after one
	 * invocation was abandoned mid-way.
	 *
	 * @return void
	 */
	public function test_the_lock_leaves_no_callback_and_no_stack_entry_behind() {
		$this->become_manager();

		$fixture = $this->previewable();

		$base_min = $this->callbacks_at( 'wp_mail', PHP_INT_MIN );
		$base_max = $this->callbacks_at( 'wp_mail', PHP_INT_MAX );

		// PHASE 1 — the ordinary send. Nothing left, nothing discarded.
		$outcome = $this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT ) );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'the test send was refused: ' . $this->refusal_of( $outcome ) );
		$this->assertMailCount( 1, 'the test send did not send exactly one message.' );

		$this->assertSame( $base_min, $this->callbacks_at( 'wp_mail', PHP_INT_MIN ), '⚠ the identify callback is still attached after a clean send.' );
		$this->assertSame( $base_max, $this->callbacks_at( 'wp_mail', PHP_INT_MAX ), '⚠ TIER 1: the enforce callback is still attached after a clean send.' );
		$this->assertSame( 0, $this->live_email()->lock_stack_residue(), 'a clean send discarded a stack entry, so the pushes and pops did not pair up.' );

		/*
		 * PHASE 2 — a `wp_mail` callback that THROWS between the two ends of the lock.
		 * ADR-0012 §3 contains it and the request continues, which is precisely why the
		 * registry cannot be left dirty: this plugin survives the throw and keeps serving
		 * the request.
		 */
		$this->captured_mail = array();

		$thrower = static function ( $args ) {
			throw new \RuntimeException( 'a wp_mail callback threw between PHP_INT_MIN and PHP_INT_MAX' );
		};

		$this->hook( 'wp_mail', $thrower, 10, 1 );

		$this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT ) );

		$this->assertMailCount( 0, 'the throwing filter did not stop the message, so this phase proves nothing.' );

		$this->assertSame(
			$base_min,
			$this->callbacks_at( 'wp_mail', PHP_INT_MIN ),
			'⚠ TIER 1: the identify callback survived a throw and is still attached.'
		);

		$this->assertSame(
			$base_max,
			$this->callbacks_at( 'wp_mail', PHP_INT_MAX ),
			'⚠ TIER 1: the enforce callback survived a throw and is still attached — the next unrelated message '
				. 'this request sends would be redirected to the test address.'
		);

		$this->assertSame(
			1,
			$this->live_email()->lock_stack_residue(),
			'the abandoned invocation\'s stack entry was not discarded by the send\'s finally, or was never pushed '
				. 'at all — in which case this phase is not exercising the throw it claims to.'
		);

		$this->assertSame(
			Custom_Email::LOCK_UNMATCHED,
			$this->live_email()->lock_outcome(),
			'a send whose wp_mail() call was abandoned mid-chain did not report that the lock never reached the '
				. 'message.'
		);

		/*
		 * ⚠ AND THE PROOF THAT MATTERS TO A THIRD PARTY: the next message the request
		 * sends is untouched. Counting registrations is the mechanism; this is the
		 * outcome.
		 */
		$this->captured_mail = array();

		remove_filter( 'wp_mail', $thrower, 10 );

		wp_mail( 'wcep-afterwards@example.test', 'An unrelated later message', 'body' );

		$this->assertMailCount( 1, 'the follow-up message was not captured.' );

		$after = $this->every_recipient_of( (array) $this->last_mail() );

		$this->assertSame(
			array( 'wcep-afterwards@example.test' ),
			$after,
			'⚠ TIER 1: a message sent AFTER the failed test send was redirected by a lock that outlived its send. '
				. 'It reached: ' . implode( ', ', $after )
		);

		$this->gate[] = 'gate 42l (nothing left behind): after a clean test send and after one whose wp_mail '
			. 'callback THREW, wp_mail carries ' . $this->callbacks_at( 'wp_mail', PHP_INT_MIN ) . ' callback(s) at '
			. 'PHP_INT_MIN and ' . $this->callbacks_at( 'wp_mail', PHP_INT_MAX ) . ' at PHP_INT_MAX — both the '
			. 'baseline — the abandoned invocation left exactly 1 stack entry which the finally discarded '
			. '(lock_outcome=' . Custom_Email::LOCK_UNMATCHED . '), and the next unrelated message reached ['
			. implode( ', ', $after ) . ']';
	}

	/**
	 * GATE 42m. THE DECLARED BOUNDARY ITSELF, ASSERTED RATHER THAN LEFT IN PROSE — a
	 *           mail-callback replacement that ALTERS the message before forwarding it
	 *           (PART A2 item 1; reclassified as a declared boundary in PART A3 item 3).
	 *
	 * ⚠ WHAT IS ACTUALLY LEFT AFTER THE FIX. Identification happens at `PHP_INT_MIN`, so a
	 * `wp_mail` callback that rewrites the content can no longer defeat it — gate 42i.
	 * What CAN is a `woocommerce_mail_callback` replacement that alters the message
	 * *before* calling `wp_mail()`, because it acts between the fingerprint and the
	 * invocation. The lock then declines, and this test asserts what declining looks like.
	 *
	 * ⚠ IT IS A DECLARED BOUNDARY, NOT A FALLBACK, AND PART A3 CORRECTED THAT (item 3).
	 * This docblock used to say the message "goes out with the
	 * `woocommerce_mail_callback_params` lock as its last word — the guarantee the previous
	 * design made". **That reasoning was incomplete: the parameters are not the last
	 * mutation point.** `wp_mail`'s own filters run after them, so a Bcc injected at
	 * priority 10 survives on a message the lock could not identify. Past a mail callback
	 * that alters what it forwards there is nothing left to identify the message by, so
	 * ADR-0020 §4b now *declares* where the guarantee stops — the same kind of statement it
	 * already makes about `phpmailer_init` — rather than inferring through it.
	 *
	 * ⚠ WHICH IS WHY THIS TEST RUNS WITHOUT AN INJECTING FILTER. What it asserts is the
	 * DECLINE and its bookkeeping, not a recipient guarantee the plugin no longer claims
	 * at this step. Three things have to hold for the boundary to be honest rather than a
	 * quiet failure:
	 *
	 *   1. what the params lock did deliver is still what arrives, on a store that adds
	 *      nothing after them;
	 *   2. the send reports `unmatched` and NOT `unfired` — `wp_mail()` did run, and
	 *      saying otherwise would describe a store that does not exist;
	 *   3. the merchant-visible delivery record says the final step did not run, in its
	 *      own sentence rather than the replacement-transport one.
	 *
	 * @return void
	 */
	public function test_a_wrapper_that_rewrites_before_forwarding_declines_and_records_it() {
		$this->become_manager();

		$fixture = $this->previewable();

		$base_min = $this->callbacks_at( 'wp_mail', PHP_INT_MIN );
		$base_max = $this->callbacks_at( 'wp_mail', PHP_INT_MAX );

		/*
		 * ⚠ IT REWRITES AND THEN FORWARDS — the one position from which content mutation
		 * still beats the identification, because `woocommerce_mail_callback_params` has
		 * already run and `wp_mail` has not yet been entered.
		 */
		$this->hook(
			'woocommerce_mail_callback',
			static function () {
				return static function ( $to, $subject, $message, $headers = '', $attachments = array() ) {
					return wp_mail( $to, '[Wrapped] ' . $subject, $message, $headers, $attachments );
				};
			},
			10,
			2
		);

		$outcome = $this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT ) );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'the test send was refused: ' . $this->refusal_of( $outcome ) );
		$this->assertMailCount( 1, 'the wrapper did not forward the message to wp_mail() exactly once.' );

		$mail       = $this->last_mail();
		$recipients = $this->every_recipient_of( (array) $mail );

		// 1. WHAT THE PARAMS LOCK DELIVERED IS WHAT ARRIVED — on this store, which adds
		// nothing after them. Past the declared boundary that is the plugin's last
		// enforced step, not a guarantee about what other filters may still do.
		$this->assertSame(
			array( strtolower( self::MERCHANT ) ),
			$recipients,
			'⚠ TIER 1: with the wp_mail lock declining, the woocommerce_mail_callback_params lock did not deliver '
				. 'the confirmed address alone. The message reached: ' . implode( ', ', $recipients )
		);

		$headers = $this->headers_of( (array) $mail );

		$this->assertSame( 0, preg_match( '/^\s*b?cc\s*:/mi', $headers ), '⚠ TIER 1: the test email carried a Cc or Bcc header.' );

		$this->assertStringStartsWith(
			'[Wrapped] ',
			(string) ( $mail['subject'] ?? '' ),
			'the wrapper did not actually rewrite the subject, so this test is not exercising the residual it names.'
		);

		// 2. AND IT SAYS SO WITH THE RIGHT WORD.
		$this->assertSame(
			Custom_Email::LOCK_UNMATCHED,
			$this->live_email()->lock_outcome(),
			'⚠ a send whose message was rewritten before wp_mail() was entered did not report `unmatched`. '
				. 'Reporting `unfired` instead would describe a replacement transport, which this store does not have.'
		);

		$this->assertSame( $base_min, $this->callbacks_at( 'wp_mail', PHP_INT_MIN ), '⚠ the identify callback is still attached after the send.' );
		$this->assertSame( $base_max, $this->callbacks_at( 'wp_mail', PHP_INT_MAX ), '⚠ TIER 1: the enforce callback is still attached after the send.' );

		// 3. AND IT IS IN THE MERCHANT-VISIBLE RECORD, not only on the object.
		$tombstones = $this->deliveries->find_for_order( $fixture['order_id'] );

		$this->assertCount( 1, $tombstones, 'expected exactly one tombstone.' );

		$this->track_delivery( (int) $tombstones[0]['id'] );

		$rows = $this->detail_rows( (int) $tombstones[0]['id'] );

		$this->assertCount( 1, $rows, 'expected exactly one attempt row.' );

		$this->assertStringContainsString(
			'was not applied at wp_mail()',
			(string) $rows[0]['reason'],
			'⚠ the delivery record does not say that the lock stopped short of the transport, so a merchant '
				. 'reading it would believe a guarantee the send did not make.'
		);

		$this->assertStringContainsString(
			'wp_mail() ran',
			(string) $rows[0]['reason'],
			'⚠ the delivery record gives the REPLACEMENT-TRANSPORT sentence for a send that did enter wp_mail(). '
				. 'The two conditions have separate outcomes precisely so the record can tell them apart.'
		);

		$this->gate[] = 'gate 42m (the declared mail-callback boundary): a woocommerce_mail_callback wrapper that '
			. 'rewrote the subject before forwarding made the lock DECLINE — the message reached ['
			. implode( ', ', $recipients ) . '] with 0 Cc and 0 Bcc off the params lock, the send reports '
			. 'lock_outcome=' . Custom_Email::LOCK_UNMATCHED . ' (not ' . Custom_Email::LOCK_UNFIRED . '), and the '
			. 'attempt row says wp_mail() ran without carrying this message';
	}

	/**
	 * GATE 42n. A NESTED `wp_mail()` THAT DIES MID-CHAIN MUST NOT ANSWER FOR THE OUTER
	 *           MESSAGE (PART A3, ITEM 1, TIER 1).
	 *
	 * ⚠ A BLIND `array_pop()` IS NOT DEPTH-KEYING, AND THE DIFFERENCE IS THE ONE MESSAGE
	 * THIS LOCK EXISTS FOR. Pairing identification with enforcement by push/pop is only
	 * correct while every invocation reaches BOTH ends. A third party that wraps its own
	 * nested `wp_mail()` in `try`/`catch` — ordinary defensive code — breaks that:
	 *
	 *     outer test wp_mail
	 *       PHP_INT_MIN  → push MATCH
	 *       priority 10 plugin:
	 *           try { nested wp_mail
	 *                   PHP_INT_MIN → push NO_MATCH
	 *                   a later filter throws — its PHP_INT_MAX never runs }
	 *           catch { swallow, carry on }
	 *           → adds Bcc to the OUTER message
	 *       PHP_INT_MAX  → pops the STRANDED NO_MATCH, declines
	 *     the outer test message leaves carrying the Bcc
	 *
	 * ⚠ AND "IT FAILS CLOSED" WAS THE WRONG READING, WHICH IS WHY THIS IS TIER 1 RATHER
	 * THAN A TIDINESS FIX. Declining is safe OUTWARD — a stranger's message is never
	 * mislabelled. Gate 42's invariant is not about strangers: it is *the confirmed
	 * address and no other*, and declining leaves whatever the earlier filters did. It
	 * fails **open inward**, on the one message the lock was built to protect.
	 *
	 * The fix keys on the real `wp_mail` nesting depth, which unwinds correctly however a
	 * nested call terminates: the stranded entry sits at depth N+1 and the outer
	 * enforcement at depth N never reads it.
	 *
	 * @return void
	 */
	public function test_a_swallowed_throw_in_a_nested_wp_mail_cannot_unlock_the_outer_message() {
		$this->become_manager();

		$fixture = $this->previewable();

		$intruder = 'wcep-manager@example.test';
		$nested   = 'wcep-nested@example.test';
		$sent     = false;

		$base_min = $this->callbacks_at( 'wp_mail', PHP_INT_MIN );
		$base_max = $this->callbacks_at( 'wp_mail', PHP_INT_MAX );

		/*
		 * ⚠ THE THROWER SITS BETWEEN THE TWO ENDS OF THE LOCK, and fires ONLY on the
		 * nested message — the outer send must survive, or the test proves nothing about
		 * what the outer message carried.
		 */
		$this->hook(
			'wp_mail',
			static function ( $args ) {
				if ( is_array( $args ) && false !== strpos( (string) ( $args['subject'] ?? '' ), 'WCEP NESTED' ) ) {
					throw new \RuntimeException( 'a later wp_mail filter threw on the nested message' );
				}

				return $args;
			},
			20,
			1
		);

		// A plugin that mails defensively and then decorates the message it was given.
		$this->hook(
			'wp_mail',
			static function ( $args ) use ( $nested, $intruder, &$sent ) {
				if ( ! $sent ) {
					$sent = true;

					try {
						wp_mail( $nested, 'WCEP NESTED notification', 'Nothing to do with the test send.' );
					} catch ( \Throwable $e ) {
						// Swallowed on purpose: this is the shape the defect needs.
						$sent = true;
					}
				}

				if ( ! is_array( $args ) ) {
					return $args;
				}

				$args['headers'] = (string) ( $args['headers'] ?? '' ) . 'Bcc: ' . $intruder . "\r\n";

				return $args;
			},
			10,
			1
		);

		$outcome = $this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT ) );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'the test send was refused: ' . $this->refusal_of( $outcome ) );
		$this->assertTrue( $sent, 'the nested message was never attempted, so this test would prove nothing.' );

		// The nested message dies at priority 20, before `pre_wp_mail`, so it never
		// reaches the capture. Only the outer test message does.
		$this->assertMailCount( 1, 'the outer test message did not reach the capture exactly once.' );

		$mail       = $this->last_mail();
		$recipients = $this->every_recipient_of( (array) $mail );

		$this->assertSame(
			array( strtolower( self::MERCHANT ) ),
			$recipients,
			'⚠ TIER 1: a stranded entry from a nested wp_mail() that died mid-chain answered for the OUTER '
				. 'message, so the lock declined and an injected Bcc survived on a confirmed test send. '
				. 'It reached: ' . implode( ', ', $recipients )
		);

		$headers = $this->headers_of( (array) $mail );

		$this->assertSame( 0, preg_match( '/^\s*b?cc\s*:/mi', $headers ), '⚠ TIER 1: the test email carried a Cc or Bcc header.' );

		$this->assertSame(
			Custom_Email::LOCK_APPLIED,
			$this->live_email()->lock_outcome(),
			'⚠ the lock declined the message it had already identified, because a deeper invocation\'s entry was '
				. 'read at the outer depth.'
		);

		/*
		 * ⚠ AND THE STRANDED ENTRY IS REPORTED RATHER THAN SWEPT UP SILENTLY. Exactly one
		 * invocation was abandoned between its two ends, so exactly one entry survives to
		 * the `finally`. Zero here would mean the entry was consumed by somebody — which
		 * is the defect.
		 */
		$this->assertSame(
			1,
			$this->live_email()->lock_stack_residue(),
			'the abandoned nested invocation\'s entry was not reported as residue. If it is 0, something '
				. 'consumed it; if it is more than 1, the depth accounting is wrong.'
		);

		$this->assertSame( $base_min, $this->callbacks_at( 'wp_mail', PHP_INT_MIN ), '⚠ the identify callback is still attached after the send.' );
		$this->assertSame( $base_max, $this->callbacks_at( 'wp_mail', PHP_INT_MAX ), '⚠ TIER 1: the enforce callback is still attached after the send.' );

		$this->gate[] = 'gate 42n (true depth): a priority-10 filter that swallowed a throw from its own nested '
			. 'wp_mail() and then injected a Bcc — the outer test still reached [' . implode( ', ', $recipients )
			. '] with 0 Cc and 0 Bcc (lock_outcome=' . Custom_Email::LOCK_APPLIED . '), and the abandoned nested '
			. 'invocation left exactly 1 stranded entry, reported as residue';
	}

	/**
	 * GATE 42o. IDENTITY INCLUDES THE RECIPIENT — an archival COPY of this very message
	 *           keeps its own address (PART A3, ITEM 2, TIER 1).
	 *
	 * ⚠ "A COPY OF THIS VERY MESSAGE, NOT A STRANGER'S MAIL" WAS THE WRONG CONCLUSION.
	 * Residual 1 has been recorded and accepted twice on the reasoning that a byte-identical
	 * subject and body means a duplicate of our own message, so locking it harms nobody.
	 * The reachable shape is an archival wrapper:
	 *
	 *     wp_mail( 'archive@example.test', $subject, $body, ... );   // the copy
	 *     return wp_mail( $to, $subject, $body, ... );               // the forward
	 *
	 * The copy IS byte-identical in subject and body — and it was **addressed
	 * deliberately, to somewhere else**. Rewriting its recipient is exactly the harm this
	 * mechanism exists to prevent, pointing outward: an email explicitly sent to the
	 * archive is delivered to the merchant instead.
	 *
	 * ⚠ THE FIX IS IDENTITY, NOT SCOPE. The parameters handed to the mail callback already
	 * carry the recipient, and the `woocommerce_mail_callback_params` lock has already set
	 * it to the confirmed address — so the intended invocation still matches and the copy,
	 * which differs in exactly that field, does not.
	 *
	 * @return void
	 */
	public function test_an_archival_copy_of_this_message_keeps_its_own_recipient() {
		$this->become_manager();

		$fixture = $this->previewable();

		$archive  = 'wcep-archive@example.test';
		$intruder = 'wcep-manager@example.test';

		$base_min = $this->callbacks_at( 'wp_mail', PHP_INT_MIN );
		$base_max = $this->callbacks_at( 'wp_mail', PHP_INT_MAX );

		/*
		 * ⚠ A BYTE-IDENTICAL COPY, WHICH IS THE POINT. Everything but the recipient is the
		 * same array the mailer was handed, so subject-and-body identity cannot tell the
		 * two apart.
		 */
		$this->hook(
			'woocommerce_mail_callback',
			static function () use ( $archive ) {
				return static function ( $to, $subject, $message, $headers = '', $attachments = array() ) use ( $archive ) {
					wp_mail( $archive, $subject, $message, $headers, $attachments );

					return wp_mail( $to, $subject, $message, $headers, $attachments );
				};
			},
			10,
			2
		);

		// A site-wide Bcc alongside, so "the forwarded message is still locked" is a real
		// assertion rather than one that would pass on an unfiltered store.
		$this->hook(
			'wp_mail',
			static function ( $args ) use ( $intruder ) {
				if ( ! is_array( $args ) ) {
					return $args;
				}

				$args['headers'] = (string) ( $args['headers'] ?? '' ) . 'Bcc: ' . $intruder . "\r\n";

				return $args;
			},
			10,
			1
		);

		$outcome = $this->submit_test( $this->test_post( $fixture['order_id'], $fixture['rule'], self::MERCHANT ) );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'the test send was refused: ' . $this->refusal_of( $outcome ) );
		$this->assertMailCount( 2, 'the archival copy and the forwarded message did not both reach the capture.' );

		// The copy is sent first and the forward second.
		$theirs = $this->every_recipient_of( (array) $this->captured_mail[0] );
		$ours   = $this->every_recipient_of( (array) $this->captured_mail[1] );

		$this->assertContains(
			$archive,
			$theirs,
			'⚠ TIER 1: an email addressed to the archive was delivered somewhere else — the lock matched a '
				. 'byte-identical COPY of this message and rewrote its recipient. It reached: ' . implode( ', ', $theirs )
		);

		$this->assertNotContains(
			strtolower( self::MERCHANT ),
			$theirs,
			'⚠ TIER 1: the archival copy was redirected to the merchant\'s test address. It reached: '
				. implode( ', ', $theirs )
		);

		$this->assertSame(
			array( strtolower( self::MERCHANT ) ),
			$ours,
			'⚠ TIER 1: the forwarded test message did not reach the confirmed address alone. It reached: '
				. implode( ', ', $ours )
		);

		$headers = $this->headers_of( (array) $this->captured_mail[1] );

		$this->assertSame( 0, preg_match( '/^\s*b?cc\s*:/mi', $headers ), '⚠ TIER 1: the test email carried a Cc or Bcc header.' );

		$this->assertSame(
			Custom_Email::LOCK_APPLIED,
			$this->live_email()->lock_outcome(),
			'the lock did not report that it recognised and locked this send\'s own message.'
		);

		$this->assertSame( 0, $this->live_email()->lock_stack_residue(), '⚠ the lock\'s depth accounting was left dirty.' );

		$this->assertSame( $base_min, $this->callbacks_at( 'wp_mail', PHP_INT_MIN ), '⚠ the identify callback is still attached after the send.' );
		$this->assertSame( $base_max, $this->callbacks_at( 'wp_mail', PHP_INT_MAX ), '⚠ TIER 1: the enforce callback is still attached after the send.' );

		$this->gate[] = 'gate 42o (identity includes the recipient): a woocommerce_mail_callback wrapper that sent a '
			. 'BYTE-IDENTICAL copy to [' . $archive . '] before forwarding — the copy reached ['
			. implode( ', ', $theirs ) . '] and kept its own address, the forwarded test reached ['
			. implode( ', ', $ours ) . '] with 0 Cc and 0 Bcc (lock_outcome=' . Custom_Email::LOCK_APPLIED . ')';
	}

	/**
	 * Callbacks registered on one hook at one exact priority.
	 *
	 * @param string $tag      Hook name.
	 * @param int    $priority Priority.
	 * @return int
	 */
	private function callbacks_at( string $tag, int $priority ): int {
		$hook = $GLOBALS['wp_filter'][ $tag ] ?? null;

		if ( ! $hook instanceof \WP_Hook ) {
			return 0;
		}

		return count( $hook->callbacks[ $priority ] ?? array() );
	}

	/**
	 * Callbacks registered on one hook, across every priority.
	 *
	 * @param string $tag Hook name.
	 * @return int
	 */
	private function callback_count( string $tag ): int {
		$hook = $GLOBALS['wp_filter'][ $tag ] ?? null;

		if ( ! $hook instanceof \WP_Hook ) {
			return 0;
		}

		$count = 0;

		foreach ( $hook->callbacks as $callbacks ) {
			$count += count( (array) $callbacks );
		}

		return $count;
	}

	/**
	 * GATE 42d. THE LOCK IS SCOPED TO THE TEST PATH — an AUTOMATIC send still honours
	 *           the same filter.
	 *
	 * ⚠ WITHOUT THIS, THE FIX ABOVE WOULD BE ITS OWN DEFECT. A merchant's recipient
	 * customisation is legitimate on a real send; silently discarding it everywhere
	 * would break a supported WooCommerce extension point to close a hole in one
	 * feature. The lock is set by `Orchestrator::send_test()` and by nothing else, and
	 * this is the assertion that keeps it that way.
	 *
	 * @return void
	 */
	public function test_an_automatic_send_still_honours_the_recipient_filter() {
		$this->become_manager();

		$fixture = $this->previewable();

		$extra = 'wcep-manager@example.test';

		$this->hook(
			'woocommerce_email_recipient_' . EmailIdentity::EMAIL_ID,
			static function ( $recipient ) use ( $extra ) {
				return trim( (string) $recipient . ', ' . $extra, ', ' );
			},
			10,
			1
		);

		// ⚠ AND THE `wp_mail` STEP TOO (Prompt 13B). The one-shot added there is armed
		// only while a LOCKED send is in progress, so a "Bcc every outgoing message"
		// integration must still work on a real delivery — the same rule that keeps
		// `woocommerce_email_recipient_{id}` honoured above, one filter later.
		$copied = 'wcep-archive@example.test';

		$this->hook(
			'wp_mail',
			static function ( $args ) use ( $copied ) {
				if ( is_array( $args ) ) {
					$args['headers'] = (string) ( $args['headers'] ?? '' ) . 'Bcc: ' . $copied . "\r\n";
				}

				return $args;
			},
			10,
			1
		);

		$this->captured_mail = array();

		$this->orchestrator()->run( $fixture['order'], TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 1, 'the automatic delivery did not send exactly one message.' );

		$recipients = $this->every_recipient_of( (array) $this->last_mail() );

		$this->assertContains(
			strtolower( (string) $fixture['order']->get_billing_email() ),
			$recipients,
			'the automatic delivery did not reach the customer.'
		);

		$this->assertContains(
			$extra,
			$recipients,
			'⚠ the recipient lock leaked onto the automatic path: a merchant\'s own '
				. 'woocommerce_email_recipient_{id} customisation was discarded on a REAL send.'
		);

		$this->assertContains(
			$copied,
			$recipients,
			'⚠ the wp_mail one-shot leaked onto the automatic path: a site-wide copy-every-message '
				. 'integration was stripped from a REAL send.'
		);

		$this->gate[] = 'gate 42d (scope): an AUTOMATIC send still honours woocommerce_email_recipient_{id} '
			. 'AND a wp_mail Bcc — the captured message reached [' . implode( ', ', $recipients ) . ']';
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

		// ⚠ RESTORED WITH ITS ORIGINAL CONTEXT FINGERPRINT (Prompt 13A item 4). The point
		// of this step is to take the TOKEN guard out of the way and leave the IDENTITY
		// index standing alone; a row restored without the fingerprint would be refused
		// as `confirmation_changed` — the token guard working — and prove nothing about
		// the index.
		add_option(
			ConfirmationToken::PREFIX . $post[ DeliveryActions::FIELD_TOKEN ],
			(string) ( time() + 600 ) . ConfirmationToken::SEPARATOR . ConfirmationToken::fingerprint(
				DeliveryActions::ACTION_TEST,
				0,
				(int) $fixture['order_id'],
				(int) $fixture['rule'],
				array( 'to' => array( self::MERCHANT ) )
			),
			'',
			'no'
		);

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
		// does not have to re-confirm because of a mistyped URL. Consumed here with the
		// SAME context the confirmation screen bound it to (Prompt 13A item 4), which is
		// the only way to prove the row is still there AND still usable.
		$this->assertSame(
			ConfirmationToken::OK,
			ConfirmationToken::consume(
				(string) $post[ DeliveryActions::FIELD_TOKEN ],
				ConfirmationToken::fingerprint(
					DeliveryActions::ACTION_TEST,
					0,
					(int) $fixture['order_id'],
					(int) $fixture['rule'],
					array( 'to' => array( self::MERCHANT ) )
				)
			),
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

		/*
		 * It states the two facts a merchant cannot infer.
		 *
		 * ⚠ THE WORDING CHANGED IN PROMPT 13C (item 3, Tier 2) AND THE ASSERTION MOVED
		 * WITH IT. The screen used to promise the message went to the confirmed address
		 * "and to nobody else", which is stronger than the plugin enforces: ADR-0020 §4b
		 * records `phpmailer_init` as an accepted boundary. What it says now is what the
		 * code does — the rule's own recipients are REPLACED — and the assertion is
		 * written against that claim rather than against a promise nobody keeps.
		 *
		 * ⚠ AND IT CHANGED AGAIN IN PART L, IN THE OTHER DIRECTION. The replacement,
		 * *"the customer is not used"*, was itself false: the customer is not excluded
		 * by CATEGORY — a merchant may type the customer's own address into the field
		 * and the test goes there, exactly as asked. Part L did NOT answer that by
		 * making the sentence vaguer. `TestDelivery::recipients_for()` builds the
		 * recipient set from the supplied address alone and never calls
		 * `RecipientResolver`, so the rule's To, Cc, Bcc and its `customer` token are
		 * STRUCTURALLY unreachable here — a stronger guarantee than the false one, and
		 * the one the screen now makes. Both failure directions are pinned below: the
		 * over-promise ("nobody else") and the category claim ("the customer is not
		 * used") must each stay off the screen.
		 */
		// ⚠ THE FRAGMENT CARRIES NO APOSTROPHE, DELIBERATELY. The screen escapes the
		// sentence for HTML, so `rule's` reaches the markup as `rule&#039;s` and an
		// assertion written with a literal apostrophe fails on correct output.
		$this->assertStringContainsString(
			'own recipients are not used',
			$markup,
			'the confirmation does not say the rule\'s own recipients are not used.'
		);

		$this->assertStringContainsString(
			self::MERCHANT,
			$markup,
			'the confirmation does not state the address the test actually goes to.'
		);

		$this->assertStringNotContainsString(
			'nobody else',
			$markup,
			'⚠ the confirmation is back to promising more than the plugin enforces (ADR-0020 §4b).'
		);

		$this->assertStringNotContainsString(
			'the customer is not used',
			$markup,
			'⚠ the confirmation is back to excluding the customer by CATEGORY — a merchant may type the '
			. 'customer\'s own address, and the test then goes there.'
		);

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
			. 'states "The rule\'s own recipients are not used" and "marked as a test" — and neither the '
			. 'un-enforceable "and to nobody else" nor the false category claim "the customer is not used" — '
			. 'and renders a POST form with a nonce and a token and 0 sending GET links';
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
