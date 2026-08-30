<?php
/**
 * GATES 36–39 — confirmation, single execution, refusal completeness and no
 * parallel send path (ADR-0019).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\ConfirmationToken;
use Extonify\WCEP\Admin\DeliveryActions;
use Extonify\WCEP\Admin\DeliveryConfirm;
use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Admin\Notices;
use Extonify\WCEP\Admin\RuleActions;
use Extonify\WCEP\Delivery\ManualDelivery;
use Extonify\WCEP\Domain\DeliveryIdentity;
use Extonify\WCEP\Domain\TriggerEvent;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * The four actions that mail a customer on a click.
 *
 * ⚠ SEVERITY: on this prompt, ANY path that can send more than one email per
 * confirmed click, or send without a confirmed click, is TIER 1. Every assertion
 * about a send count below is made on the MAIL CAPTURE — what actually reached
 * `pre_wp_mail` — never on a row count, because a row count cannot tell the
 * difference between one email recorded twice and two emails.
 */
final class ManualDeliveryTest extends ManualDeliveryTestCase {

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
			fwrite( STDERR, "\n[P11] " . $line );
		}

		$this->gate = array();
	}

	// -----------------------------------------------------------------------
	// GATE 36 — confirmation
	// -----------------------------------------------------------------------

	/**
	 * GATE 36a. NO GET REQUEST SENDS ANYTHING.
	 *
	 * ⚠ THE NONCE IS VALID AND EVERYTHING ELSE IS CORRECT, so the request method is
	 * the only thing that can refuse it. A GET that sends is a link — followed from
	 * history, warmed by a prefetcher, leaked in a referrer — and no nonce makes that
	 * acceptable.
	 *
	 * @return void
	 */
	public function test_no_get_request_can_send() {
		$this->become_manager();

		$fixture = $this->completed_delivery();

		$post = $this->confirmed_post( DeliveryActions::ACTION_RESEND, array( 'delivery' => $fixture['delivery'] ) );

		$this->use_method( 'GET' );
		$this->request( array( 'page' => Menu::HISTORY_PAGE ), $post );

		$outcome = DeliveryActions::handle( DeliveryActions::ACTION_RESEND, $post );

		$this->assertSame(
			RuleActions::OUTCOME_DENIED,
			$outcome['outcome'] ?? '',
			'⚠ TIER 1: a GET request ran a sending action.'
		);

		$this->assertMailCount( 0, '⚠ TIER 1: a GET request sent an email.' );

		// And the token was NOT consumed, so the merchant's confirmation still works.
		$this->use_method( 'POST' );

		$again = DeliveryActions::handle( DeliveryActions::ACTION_RESEND, $post );

		$this->assertSame( '', $this->refusal_of( $again ), 'the refused GET burned the confirmation token.' );
		$this->assertMailCount( 1, 'the same confirmation did not work over POST.' );

		$this->gate[] = 'gate 36 (GET): a fully valid confirmed action refuses over GET, sends nothing, and does NOT '
			. 'consume its token — the same POST then succeeds';
	}

	/**
	 * GATE 36b. NO RENDERED CONTROL IS A SEND LINK.
	 *
	 * ⚠ ASSERTED ON THE MARKUP, because "the handler refuses GET" is only half of it.
	 * A rendered `<a>` carrying an action the handler recognises would still be an
	 * invitation for a browser to try, and would put a sending action in the history
	 * dropdown of every merchant who ever hovered it.
	 *
	 * @return void
	 */
	public function test_no_rendered_control_links_to_a_send() {
		$this->become_manager();

		$fixture = $this->completed_delivery();

		$markup = $this->render_history( $fixture['order_id'] );

		$this->assertStringContainsString( 'Resend', $markup, 'the resend control is not on the page at all.' );

		/*
		 * ⚠ ASSERTED ON THE PARSED QUERY STRING, NOT ON A SUBSTRING. The confirmation
		 * URL carries `wcep_action=wcep_resend`, which CONTAINS `action=wcep_resend` —
		 * so a substring search reports the safe link as a send link and the test fails
		 * for the wrong reason. What matters is whether any href has an `action`
		 * parameter naming a sending action, and only a parser can answer that.
		 */
		preg_match_all( '/href="([^"]+)"/', $markup, $hrefs );

		$offenders = array();

		foreach ( (array) $hrefs[1] as $href ) {
			$query = wp_parse_url( html_entity_decode( $href ), PHP_URL_QUERY );

			if ( ! is_string( $query ) ) {
				continue;
			}

			parse_str( $query, $args );

			if ( DeliveryActions::is_write_action( (string) ( $args['action'] ?? '' ) ) ) {
				$offenders[] = $href;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'⚠ TIER 1: the history screen renders GET link(s) whose `action` names a sending action: '
				. implode( ' | ', $offenders )
		);

		// A floor, so "no offenders" cannot mean "no links were examined".
		$this->assertGreaterThan( 1, count( (array) $hrefs[1] ), 'suspiciously few links on the page to check.' );

		$this->assertStringContainsString(
			'wcep_action=' . DeliveryActions::ACTION_RESEND,
			$markup,
			'the resend control does not point at a confirmation screen.'
		);

		$this->gate[] = 'gate 36 (markup): the history screen renders confirmation links (wcep_action=…) and NOT ONE '
			. 'GET link carrying any of the ' . count( DeliveryActions::write_actions() ) . ' sending actions';
	}

	/**
	 * GATE 36c. THE CONFIRMATION SCREEN SHOWS THE RESOLVED RECIPIENTS AND THE
	 *           CONSEQUENCE, AND SENDS NOTHING.
	 *
	 * @return void
	 */
	public function test_the_confirmation_screen_shows_who_and_sends_nothing() {
		$this->become_manager();

		$fixture = $this->undelivered_order();

		$markup = $this->render_confirmation(
			DeliveryActions::ACTION_MANUAL,
			array(
				'order' => $fixture['order_id'],
				'rule'  => $fixture['rule'],
			)
		);

		$this->assertMailCount( 0, '⚠ TIER 1: rendering a confirmation screen sent an email.' );

		// The address that will really be used, not `{customer_email}`.
		$this->assertStringContainsString( 'wcep-test@example.test', $markup, 'the confirmation does not show the resolved recipient.' );
		$this->assertStringNotContainsString( '{customer_email}', $markup, 'the confirmation shows the rule definition rather than the resolved address.' );

		// The §2 note, which is the one consequence a merchant cannot infer.
		$this->assertStringContainsString(
			'does not replace or switch off the rule',
			$markup,
			'⚠ the manual-send confirmation does not warn that the automatic delivery still fires.'
		);

		// A POST form, with a nonce and a single-use token.
		$this->assertStringContainsString( 'method="post"', $markup );
		$this->assertStringContainsString( DeliveryActions::FIELD_TOKEN, $markup );
		$this->assertStringContainsString( DeliveryActions::FIELD_NONCE, $markup );

		$this->gate[] = 'gate 36 (confirmation): the screen renders the RESOLVED recipient address, the '
			. '"does not replace the automatic delivery" note, and a POST form carrying a nonce and a single-use '
			. 'token — and sends nothing';
	}

	/**
	 * GATE 36d. EVERY HANDLER REFUSES A MISSING, WRONG-ACTION AND EXPIRED NONCE —
	 *           while the user IS privileged, so the nonce is what refused.
	 *
	 * @return void
	 */
	public function test_every_handler_refuses_a_bad_nonce() {
		$this->become_manager();

		$fixture = $this->completed_delivery();

		$cases = array(
			'missing'      => '',
			'garbage'      => 'deadbeef',
			// A nonce minted for a DIFFERENT action on the same delivery.
			'wrong action' => wp_create_nonce(
				DeliveryActions::nonce_action( DeliveryActions::ACTION_CANCEL, $fixture['delivery'], 0 )
			),
			// A nonce minted for the same action on a DIFFERENT delivery.
			'wrong subject' => wp_create_nonce(
				DeliveryActions::nonce_action( DeliveryActions::ACTION_RESEND, $fixture['delivery'] + 99, 0 )
			),
		);

		foreach ( $cases as $label => $nonce ) {
			$outcome = $this->submit(
				DeliveryActions::ACTION_RESEND,
				array( 'delivery' => $fixture['delivery'] ),
				'',
				'' === $nonce ? 'none' : $nonce
			);

			$this->assertSame(
				RuleActions::OUTCOME_DENIED,
				$outcome['outcome'] ?? '',
				'⚠ the resend handler accepted a ' . $label . ' nonce.'
			);
		}

		// EXPIRED: minted under one nonce tick, verified under another.
		$post = $this->confirmed_post( DeliveryActions::ACTION_RESEND, array( 'delivery' => $fixture['delivery'] ) );

		add_filter( 'nonce_life', static fn() => 2, 999 );

		$expired = $this->resubmit( DeliveryActions::ACTION_RESEND, $post );

		remove_all_filters( 'nonce_life' );

		$this->assertSame( RuleActions::OUTCOME_DENIED, $expired['outcome'] ?? '', '⚠ the resend handler accepted an expired nonce.' );

		$this->assertMailCount( 0, '⚠ TIER 1: a bad-nonce request sent mail.' );

		$this->gate[] = 'gate 36 (nonce): missing, garbage, wrong-action, wrong-subject and expired nonces are all '
			. 'refused with the user PRIVILEGED, and none of them sent mail';
	}

	// -----------------------------------------------------------------------
	// GATE 37 — single execution
	// -----------------------------------------------------------------------

	/**
	 * GATE 37a. A REPLAYED RESEND PRODUCES EXACTLY ONE EMAIL AND ONE ATTEMPT ROW.
	 *
	 * ⚠ MEASURED ON THE MAIL CAPTURE, NOT INFERRED FROM ROWS. A row count cannot tell
	 * one email recorded twice from two emails, and the thing that matters here is what
	 * reached the customer's inbox.
	 *
	 * @return void
	 */
	public function test_a_replayed_resend_sends_exactly_one_email() {
		$this->become_manager();

		$fixture = $this->completed_delivery();

		$before = count( $this->detail_rows( $fixture['delivery'] ) );

		$post = $this->confirmed_post( DeliveryActions::ACTION_RESEND, array( 'delivery' => $fixture['delivery'] ) );

		$first = $this->resubmit( DeliveryActions::ACTION_RESEND, $post );

		$this->assertSame( '', $this->refusal_of( $first ), 'the first resend was refused.' );
		$this->assertMailCount( 1, 'the first resend did not send exactly one email.' );

		// ⚠ THE REPLAY: the identical POST, three more times.
		foreach ( array( 'reload', 'back-and-resubmit', 'double-click' ) as $replay ) {
			$again = $this->resubmit( DeliveryActions::ACTION_RESEND, $post );

			$this->assertSame(
				'replayed',
				$this->refusal_of( $again ),
				'⚠ TIER 1: a ' . $replay . ' was not recognised as a replay.'
			);
		}

		$this->assertMailCount( 1, '⚠ TIER 1: replaying a confirmed resend sent more than one email.' );

		$this->assertCount(
			$before + 1,
			$this->detail_rows( $fixture['delivery'] ),
			'⚠ TIER 1: replaying a confirmed resend wrote more than one attempt row.'
		);

		$this->gate[] = 'gate 37 (resend): 1 confirmation + 3 identical replays => 1 email captured, 1 new attempt '
			. 'row; every replay refused as "replayed"';
	}

	/**
	 * GATE 37b. A REPLAYED MANUAL SEND PRODUCES EXACTLY ONE EMAIL — and the second
	 *           barrier is the identity itself.
	 *
	 * ⚠ TWO INDEPENDENT GUARDS, ASSERTED SEPARATELY (ADR-0019 §5). The token is
	 * consumed by one atomic statement; underneath it the token IS the trigger
	 * identity, so a replay hashes to a row the UNIQUE index already holds. The second
	 * assertion deliberately bypasses the token to prove the identity barrier alone.
	 *
	 * @return void
	 */
	public function test_a_replayed_manual_send_sends_exactly_one_email() {
		$this->become_manager();

		$fixture = $this->undelivered_order();

		$post = $this->confirmed_post(
			DeliveryActions::ACTION_MANUAL,
			array(
				'order' => $fixture['order_id'],
				'rule'  => $fixture['rule'],
			)
		);

		$token = (string) $post[ DeliveryActions::FIELD_TOKEN ];

		$first = $this->resubmit( DeliveryActions::ACTION_MANUAL, $post );

		$this->assertSame( '', $this->refusal_of( $first ), 'the manual send was refused.' );
		$this->assertMailCount( 1, 'the manual send did not send exactly one email.' );

		$replayed = $this->resubmit( DeliveryActions::ACTION_MANUAL, $post );

		$this->assertSame( 'replayed', $this->refusal_of( $replayed ), '⚠ TIER 1: the replayed manual send was not refused.' );
		$this->assertMailCount( 1, '⚠ TIER 1: replaying a manual send sent a second email.' );

		/*
		 * ⚠ NOW WITHOUT THE TOKEN GUARD AT ALL. Re-issue the SAME token so
		 * `ConfirmationToken::consume()` succeeds, and prove the identity's UNIQUE index
		 * refuses on its own — this is the barrier that survives the token store being
		 * wiped, and it is permanent.
		 *
		 * ⚠ THE RESTORED ROW CARRIES THE SAME CONTEXT FINGERPRINT (Prompt 13A item 4),
		 * because the point of this step is to remove the TOKEN guard and leave the
		 * IDENTITY guard standing alone. A row restored with no fingerprint would be
		 * refused as `confirmation_changed`, which is the token guard working — and
		 * would leave the identity index untested.
		 */
		add_option(
			ConfirmationToken::PREFIX . $token,
			(string) ( time() + 600 ) . ConfirmationToken::SEPARATOR . ConfirmationToken::fingerprint(
				DeliveryActions::ACTION_MANUAL,
				0,
				(int) $fixture['order_id'],
				(int) $fixture['rule'],
				DeliveryActions::preview_recipients( wc_get_order( (int) $fixture['order_id'] ), (array) $this->rules->find( (int) $fixture['rule'] ) )
			),
			'',
			'no'
		);

		$bypassed = $this->resubmit( DeliveryActions::ACTION_MANUAL, $post );

		$this->assertSame(
			ManualDelivery::REFUSED_ALREADY_EXISTS,
			$this->refusal_of( $bypassed ),
			'⚠ TIER 1: with the token re-issued, the identity did not refuse the duplicate on its own.'
		);

		$this->assertMailCount( 1, '⚠ TIER 1: the identity barrier let a duplicate manual send through.' );

		$this->gate[] = 'gate 37 (manual): 1 confirmation + 1 replay + 1 replay with the token RE-ISSUED => 1 email; '
			. 'the second refusal is the token, the third is the ADR-0004 UNIQUE index on identity_hash';
	}

	/**
	 * GATE 37c. A DELIBERATE SECOND CONFIRMATION **DOES** SEND AGAIN.
	 *
	 * ⚠ THE POSITIVE CONTROL, AND IT IS NOT A FORMALITY. Every assertion above is that
	 * something did NOT happen, and a handler that refused everything would satisfy all
	 * of them. The merchant asked twice, deliberately, and ADR-0019 §2 says they get two
	 * deliveries.
	 *
	 * @return void
	 */
	public function test_a_deliberate_second_confirmation_sends_again() {
		$this->become_manager();

		$fixture = $this->undelivered_order();

		$fields = array(
			'order' => $fixture['order_id'],
			'rule'  => $fixture['rule'],
		);

		$this->submit( DeliveryActions::ACTION_MANUAL, $fields );
		$this->assertMailCount( 1 );

		// A FRESH confirmation: new token, new identity.
		$second = $this->submit( DeliveryActions::ACTION_MANUAL, $fields );

		$this->assertSame( '', $this->refusal_of( $second ), '⚠ a deliberate second confirmation was refused as a replay.' );
		$this->assertMailCount( 2, 'a deliberate second confirmation did not send a second email.' );

		$identities = array_keys( $this->tombstones_by_identity( $fixture['order_id'] ) );

		$this->assertCount( 2, $identities, 'two deliberate manual sends did not produce two identities.' );

		foreach ( $identities as $identity ) {
			$this->assertTrue(
				DeliveryIdentity::is_manual( $identity ),
				'a manual send produced an identity that does not read as manual: ' . $identity
			);
		}

		$this->gate[] = 'gate 37 (positive control): two DELIBERATE confirmations send two emails under two distinct '
			. 'manual: identities — so the replay refusals above are refusals, not a handler that never works';
	}

	/**
	 * GATE 37d. THE TOKEN IS CONSUMED BY A SINGLE STATEMENT, and a second consumer
	 *           of the same token loses.
	 *
	 * @return void
	 */
	public function test_a_token_can_only_be_consumed_once() {
		$print = ConfirmationToken::fingerprint(
			DeliveryActions::ACTION_MANUAL,
			0,
			41,
			7,
			array( 'to' => array( 'someone@example.test' ) )
		);

		$token = ConfirmationToken::issue( $print );

		$this->assertNotSame( '', $token, 'no token was issued.' );
		$this->assertTrue( ConfirmationToken::is_well_formed( $token ) );

		$this->assertSame( ConfirmationToken::OK, ConfirmationToken::consume( $token, $print ), 'the first consumer did not win the token.' );
		$this->assertSame( ConfirmationToken::REPLAYED, ConfirmationToken::consume( $token, $print ), '⚠ TIER 1: the same token was consumed twice.' );

		// A token that was never issued, and a malformed one, both lose.
		$this->assertSame( ConfirmationToken::REPLAYED, ConfirmationToken::consume( str_repeat( 'a', 32 ), $print ) );
		$this->assertSame( ConfirmationToken::REPLAYED, ConfirmationToken::consume( 'not-a-token', $print ) );
		$this->assertSame( ConfirmationToken::REPLAYED, ConfirmationToken::consume( '', $print ) );

		// An EXPIRED token is consumed and refused, never left for a later replay.
		$expired = ConfirmationToken::issue( $print );
		update_option( ConfirmationToken::PREFIX . $expired, (string) ( time() - 10 ) . ConfirmationToken::SEPARATOR . $print );

		$this->assertSame( ConfirmationToken::REPLAYED, ConfirmationToken::consume( $expired, $print ), 'an expired token was accepted.' );
		$this->assertSame( ConfirmationToken::REPLAYED, ConfirmationToken::consume( $expired, $print ), 'an expired token survived its own rejection.' );

		$this->gate[] = 'gate 37 (token): issue/consume is single-use; a second consume, an unissued token, a '
			. 'malformed token, an empty token and an expired token all lose — and the expired one is deleted as it '
			. 'is refused';
	}

	/**
	 * PROMPT 13A ITEM 4. A TOKEN BINDS ITS CONTEXT, so it authorises ONE action on ONE
	 *                    subject for ONE set of people.
	 *
	 * ⚠ WITHOUT THE BINDING, `issue()` TOOK NO ARGUMENTS AND `consume()` CHECKED ONLY
	 * EXISTENCE AND EXPIRY — the token said "the merchant confirmed something", never
	 * "the merchant confirmed THIS". Each case below is one axis of that.
	 *
	 * @return void
	 */
	public function test_a_token_is_refused_for_a_different_context() {
		$people = array( 'to' => array( 'buyer@example.test' ) );

		$issued = ConfirmationToken::fingerprint( DeliveryActions::ACTION_MANUAL, 0, 41, 7, $people );

		foreach ( array(
			'a different action'    => ConfirmationToken::fingerprint( DeliveryActions::ACTION_RESEND, 0, 41, 7, $people ),
			'a different order'     => ConfirmationToken::fingerprint( DeliveryActions::ACTION_MANUAL, 0, 42, 7, $people ),
			'a different rule'      => ConfirmationToken::fingerprint( DeliveryActions::ACTION_MANUAL, 0, 41, 8, $people ),
			'a different delivery'  => ConfirmationToken::fingerprint( DeliveryActions::ACTION_MANUAL, 9, 41, 7, $people ),
			'a different recipient' => ConfirmationToken::fingerprint( DeliveryActions::ACTION_MANUAL, 0, 41, 7, array( 'to' => array( 'someone-else@example.test' ) ) ),
			'an added cc'           => ConfirmationToken::fingerprint( DeliveryActions::ACTION_MANUAL, 0, 41, 7, $people + array( 'cc' => array( 'boss@example.test' ) ) ),
			'no fingerprint at all' => '',
		) as $label => $presented ) {
			$token = ConfirmationToken::issue( $issued );

			$this->assertSame(
				ConfirmationToken::CONTEXT_CHANGED,
				ConfirmationToken::consume( $token, $presented ),
				'⚠ a confirmation token was accepted for ' . $label . '.'
			);
		}

		/*
		 * ⚠ THE POSITIVE CONTROLS. Without these, a `fingerprint()` that returned a
		 * constant — or one that hashed the microsecond — would satisfy every
		 * assertion above.
		 */
		$token = ConfirmationToken::issue( $issued );

		$this->assertSame(
			ConfirmationToken::OK,
			ConfirmationToken::consume( $token, ConfirmationToken::fingerprint( DeliveryActions::ACTION_MANUAL, 0, 41, 7, $people ) ),
			'the SAME context was refused, so the binding refuses everything.'
		);

		// CASE AND ORDER ARE NOT CONTEXT: the same audience written differently is the
		// same audience, and refusing it would train merchants to click through.
		$token = ConfirmationToken::issue(
			ConfirmationToken::fingerprint( DeliveryActions::ACTION_MANUAL, 0, 41, 7, array( 'to' => array( 'a@example.test', 'B@Example.test' ) ) )
		);

		$this->assertSame(
			ConfirmationToken::OK,
			ConfirmationToken::consume(
				$token,
				ConfirmationToken::fingerprint( DeliveryActions::ACTION_MANUAL, 0, 41, 7, array( 'to' => array( 'b@example.test', 'a@example.test' ) ) ) )
			,
			'the same two addresses in a different order and case were treated as a different audience.'
		);

		$this->gate[] = 'item 4 (binding): a token issued for one action/order/rule/delivery/recipient set is REFUSED '
			. 'for each of the other five and for a missing fingerprint, ACCEPTED for its own, and case- and '
			. 'order-insensitive across the recipient list';
	}

	// -----------------------------------------------------------------------
	// Behaviour: resend
	// -----------------------------------------------------------------------

	/**
	 * A resend writes ONE attempt typed `resend`, keeps the parent's history, and
	 * records the CURRENT revision.
	 *
	 * @return void
	 */
	public function test_a_resend_records_a_resend_attempt_and_keeps_the_history() {
		$this->become_manager();

		$fixture = $this->completed_delivery();

		$this->assertSame( array( 'auto:sent' ), $this->attempt_signature( $fixture['delivery'] ) );

		$this->submit( DeliveryActions::ACTION_RESEND, array( 'delivery' => $fixture['delivery'] ) );

		$this->assertMailCount( 1, 'the resend did not send.' );

		$this->assertSame(
			array( 'auto:sent', 'resend:sent' ),
			$this->attempt_signature( $fixture['delivery'] ),
			'⚠ the resend did not add exactly one `resend` attempt beside the original.'
		);

		$this->assertSame( 'sent', $this->status_of( $fixture['delivery'] ) );

		$this->gate[] = 'resend: one new attempt typed `resend` on the SAME tombstone, the original `auto` attempt '
			. 'intact, tombstone still `sent`';
	}

	/**
	 * A resend after a rule edit sends the NEW content and records the NEW revision.
	 *
	 * @return void
	 */
	public function test_a_resend_after_an_edit_sends_the_new_content() {
		$this->become_manager();

		$fixture = $this->completed_delivery();

		$before = $this->deliveries->find_by_id( $fixture['delivery'] );

		$this->rules->update(
			$fixture['rule'],
			array(
				'subject' => 'Corrected subject',
				'content' => '<p>Corrected body.</p>',
			)
		);

		$after_rule = $this->rules->find( $fixture['rule'] );

		$this->assertGreaterThan(
			(int) $before['rule_revision_sent'],
			(int) $after_rule['revision'],
			'the edit did not bump the rule revision, so this test could not prove anything.'
		);

		$this->submit( DeliveryActions::ACTION_RESEND, array( 'delivery' => $fixture['delivery'] ) );

		$this->assertMailCount( 1 );

		$mail = $this->last_mail();

		$this->assertStringContainsString( 'Corrected subject', (string) $mail['subject'], '⚠ the resend used the OLD subject.' );
		$this->assertStringContainsString( 'Corrected body', (string) $mail['message'], '⚠ the resend used the OLD body.' );

		$this->assertSame(
			(int) $after_rule['revision'],
			(int) $this->deliveries->find_by_id( $fixture['delivery'] )['rule_revision_sent'],
			'⚠ the resend did not record the revision it actually sent.'
		);

		$this->gate[] = 'resend after edit: the CURRENT subject and body are sent (ADR-0019 §3) and '
			. 'rule_revision_sent moves to the current revision, so the history stays honest about what went out';
	}

	// -----------------------------------------------------------------------
	// Behaviour: manual send
	// -----------------------------------------------------------------------

	/**
	 * A manual send does NOT consume the automatic identity — the trigger still fires.
	 *
	 * ⚠ THIS IS THE §2 DECISION, ASSERTED. If a manual send consumed the automatic
	 * identity, the rule would be silently disabled for that order with nothing on any
	 * screen to say so. The customer receiving the email twice is the disclosed cost.
	 *
	 * @return void
	 */
	public function test_a_manual_send_does_not_suppress_the_automatic_delivery() {
		$this->become_manager();

		$fixture = $this->undelivered_order();

		$this->submit(
			DeliveryActions::ACTION_MANUAL,
			array(
				'order' => $fixture['order_id'],
				'rule'  => $fixture['rule'],
			)
		);

		$this->assertMailCount( 1, 'the manual send did not send.' );

		$identities = $this->tombstones_by_identity( $fixture['order_id'] );

		$this->assertCount( 1, $identities );

		$manual_identity = array_key_first( $identities );

		$this->assertTrue( DeliveryIdentity::is_manual( $manual_identity ), 'the manual send did not use a manual identity.' );

		$this->assertSame(
			array( 'manual:sent' ),
			$this->attempt_signature( (int) $identities[ $manual_identity ]['id'] ),
			'⚠ the manual send was not typed `manual` — the type this prompt defines.'
		);

		// ⚠ NOW THE TRIGGER FIRES, and it must still send.
		$this->orchestrator()->run( $fixture['order'], TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 2, '⚠ TIER 1: the manual send consumed the automatic identity and suppressed the rule.' );

		$after = $this->tombstones_by_identity( $fixture['order_id'] );

		$this->assertCount( 2, $after, 'the automatic delivery did not create its own tombstone.' );
		$this->assertArrayHasKey( 'status:completed', $after, 'the automatic identity was not claimed.' );
		$this->assertSame( 'sent', (string) $after['status:completed']['final_status'] );

		$this->gate[] = 'manual send: creates its own `manual:<token>` identity typed `manual`, and the automatic '
			. 'status:completed trigger STILL sends afterwards — 2 emails, 2 identities, no automatic identity consumed';
	}

	// -----------------------------------------------------------------------
	// Behaviour: send now and cancel
	// -----------------------------------------------------------------------

	/**
	 * Send now: sends immediately, unschedules the job, and a later scheduler run
	 * sends nothing.
	 *
	 * @return void
	 */
	public function test_send_now_sends_immediately_and_the_job_cannot_send_again() {
		$this->become_manager();

		$fixture = $this->scheduled_delivery();

		$this->assertTrue( $this->job_exists( $fixture['delivery'], $fixture['order_id'] ), 'the fixture has no queued job.' );

		$outcome = $this->submit( DeliveryActions::ACTION_SEND_NOW, array( 'delivery' => $fixture['delivery'] ) );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'send now was refused.' );
		$this->assertMailCount( 1, 'send now did not send exactly one email.' );
		$this->assertSame( 'sent', $this->status_of( $fixture['delivery'] ) );

		$this->assertFalse(
			$this->job_exists( $fixture['delivery'], $fixture['order_id'] ),
			'the queued job survived send now.'
		);

		// ⚠ AND THE LEASE IS THE REAL GUARD: running the job anyway sends nothing,
		// because the row is terminal and the lease cannot be taken.
		\Extonify\WCEP\Delivery\ScheduledDelivery::run( $fixture['delivery'], $fixture['order_id'] );

		$this->assertMailCount( 1, '⚠ TIER 1: the scheduled job sent a second email after send now.' );

		$this->gate[] = 'send now: 1 email, tombstone `sent`, job unscheduled — and running the job anyway sends '
			. 'NOTHING, because the lease refuses a terminal row';
	}

	/**
	 * Send now that loses the lease reports honestly and sends nothing.
	 *
	 * ⚠ THE RACE IS FORCED BY TAKING THE LEASE FIRST, which is exactly what a worker
	 * that picked the job up microseconds earlier would have done.
	 *
	 * @return void
	 */
	public function test_send_now_losing_the_race_sends_nothing_and_says_so() {
		$this->become_manager();

		$fixture = $this->scheduled_delivery();

		// Another actor takes the lease.
		$lease = $this->deliveries->transition(
			$fixture['delivery'],
			DeliveryRepository::SCHEDULED,
			DeliveryRepository::EXECUTING
		);

		$this->assertTrue( $lease->won(), 'the forced lease did not take.' );

		$outcome = $this->submit( DeliveryActions::ACTION_SEND_NOW, array( 'delivery' => $fixture['delivery'] ) );

		$this->assertRefusedWith( $outcome, ManualDelivery::REFUSED_NOT_SCHEDULED );

		$this->assertSame(
			DeliveryRepository::EXECUTING,
			$this->status_of( $fixture['delivery'] ),
			'⚠ send now disturbed a delivery another actor owns.'
		);

		$this->gate[] = 'send now (lost race): a delivery another actor already leased is refused as '
			. '"not scheduled", sends nothing, and its state is left exactly as the owner left it';
	}

	/**
	 * Cancel: transitions, unschedules, sends nothing, and consumes the identity.
	 *
	 * @return void
	 */
	public function test_cancel_consumes_the_identity_and_removes_the_job() {
		$this->become_manager();

		$fixture = $this->scheduled_delivery();

		$outcome = $this->submit( DeliveryActions::ACTION_CANCEL, array( 'delivery' => $fixture['delivery'] ) );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'cancel was refused.' );
		$this->assertMailCount( 0, '⚠ TIER 1: cancelling a delivery sent an email.' );

		$this->assertSame( 'cancelled', $this->status_of( $fixture['delivery'] ) );

		$this->assertFalse(
			$this->job_exists( $fixture['delivery'], $fixture['order_id'] ),
			'the queued job survived the cancellation.'
		);

		/*
		 * A merchant's cancellation is typed `manual`, not `auto` (ADR-0019 §8). The
		 * `auto:scheduled` row before it is the scheduling record the delayed phase
		 * wrote — history, and it stays.
		 */
		$signature = $this->attempt_signature( $fixture['delivery'] );

		$this->assertSame( 'auto:scheduled', $signature[0], 'the scheduling record was lost.' );

		$this->assertSame(
			'manual:cancelled',
			end( $signature ),
			'⚠ the merchant cancellation is indistinguishable from an automatic one.'
		);

		$this->assertCount( 2, $signature, 'the cancellation wrote more rows than the one attempt it is.' );

		// ⚠ THE IDENTITY IS CONSUMED (ADR-0015 §1a): the same trigger firing again
		// sends nothing, which is what the confirmation warned about.
		$this->orchestrator()->run( $fixture['order'], TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 0, 'the cancelled identity was re-armed by a second trigger.' );
		$this->assertSame( 'cancelled', $this->status_of( $fixture['delivery'] ) );

		$this->gate[] = 'cancel: tombstone `cancelled` with a `manual` attempt row, job removed, no mail — and the '
			. 'same trigger firing again sends nothing, proving the identity is permanently consumed';
	}

	/**
	 * A cancellation whose transition FAILS leaves the job queued and reports no
	 * success.
	 *
	 * ⚠ THE FAILURE IS FORCED THROUGH `query`, so the transition really does fail
	 * rather than the test asserting a branch it reasoned about. The property under
	 * test is the Prompt 7B Group A ordering: unschedule only AFTER a transition that
	 * actually changed a row.
	 *
	 * @return void
	 */
	public function test_a_failed_cancellation_leaves_the_job_queued() {
		$this->become_manager();

		$fixture = $this->scheduled_delivery();

		$deliveries_table = \Extonify\WCEP\Install\Migrator::table( 'deliveries' );

		$break = static function ( $query ) use ( $deliveries_table ) {
			// ⚠ THE BACKTICKS ARE OPTIONAL. The table is bound with `%i` now (Prompt 13A
			// item 6), so `prepare()` renders it backticked; a pattern that insisted on
			// a bare name would never match and this test would pass by never breaking
			// anything.
			if ( 1 === preg_match( '/^\s*UPDATE\s+`?' . preg_quote( $deliveries_table, '/' ) . '`?\s/i', (string) $query ) ) {
				// A statement that parses, touches nothing, and fails the guarded write.
				return 'UPDATE ' . $deliveries_table . ' SET final_status = final_status WHERE 1 = 0';
			}

			return $query;
		};

		add_filter( 'query', $break, 1 );

		$outcome = $this->submit( DeliveryActions::ACTION_CANCEL, array( 'delivery' => $fixture['delivery'] ) );

		remove_filter( 'query', $break, 1 );

		$this->assertNotSame( '', $this->refusal_of( $outcome ), '⚠ TIER 1: a failed cancellation reported success.' );

		$this->assertSame(
			DeliveryRepository::SCHEDULED,
			$this->status_of( $fixture['delivery'] ),
			'the row changed despite the transition failing.'
		);

		$this->assertTrue(
			$this->job_exists( $fixture['delivery'], $fixture['order_id'] ),
			'⚠ TIER 1: the job was unscheduled even though the cancellation did not take — the delivery would be '
				. 'stranded `scheduled` with nothing able to run it.'
		);

		$this->assertMailCount( 0 );

		$this->gate[] = 'cancel (transition fails): refusal reported, tombstone still `scheduled`, and THE JOB IS '
			. 'STILL QUEUED — the Prompt 7B Group A ordering holds under a forced write failure';
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Render the delivery history for one order.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private function render_history( int $order_id ): string {
		set_current_screen( 'woocommerce_page_' . Menu::HISTORY_PAGE );

		$this->use_method( 'GET' );
		$this->request(
			array(
				'page'       => Menu::HISTORY_PAGE,
				'wcep_order' => $order_id,
			)
		);

		\Extonify\WCEP\Admin\DeliveryHistory::reset();

		return $this->capture(
			static function () {
				\Extonify\WCEP\Admin\DeliveryHistory::prepare();
				\Extonify\WCEP\Admin\DeliveryHistory::render();
			}
		);
	}

	/**
	 * Render one confirmation screen.
	 *
	 * @param string $action The action.
	 * @param array  $fields `delivery`, `order`, `rule`.
	 * @return string
	 */
	private function render_confirmation( string $action, array $fields ): string {
		set_current_screen( 'woocommerce_page_' . Menu::HISTORY_PAGE );

		$this->use_method( 'GET' );
		$this->request(
			array(
				'page'                          => Menu::HISTORY_PAGE,
				'wcep_action'                   => $action,
				DeliveryActions::FIELD_DELIVERY => (int) ( $fields['delivery'] ?? 0 ),
				DeliveryActions::FIELD_ORDER    => (int) ( $fields['order'] ?? 0 ),
				DeliveryActions::FIELD_RULE     => (int) ( $fields['rule'] ?? 0 ),
			)
		);

		return $this->capture(
			static function () {
				DeliveryConfirm::render();
			}
		);
	}

	/**
	 * Every refusal code has a merchant-facing sentence (gate 38).
	 *
	 * @return void
	 */
	public function test_every_refusal_code_has_its_own_sentence() {
		$messages = Notices::delivery_messages();

		$codes = array(
			ManualDelivery::REFUSED_SCHEMA,
			ManualDelivery::REFUSED_ORDER_MISSING,
			ManualDelivery::REFUSED_RULE_DELETED,
			ManualDelivery::REFUSED_RULE_INSERT_MODE,
			ManualDelivery::REFUSED_RULE_VOCABULARY,
			ManualDelivery::REFUSED_NOT_TERMINAL,
			ManualDelivery::REFUSED_NOT_SCHEDULED,
			ManualDelivery::REFUSED_ALREADY_EXISTS,
			ManualDelivery::REFUSED_DELIVERY_MISSING,
			ManualDelivery::REFUSED_NO_ITEMS,
			ManualDelivery::REFUSED_LOST_RACE,
			ManualDelivery::REFUSED_WRITE_FAILED,
			ManualDelivery::REFUSED_EMAIL_MISSING,
			'replayed',
			'denied',
		);

		$sentences = array();

		foreach ( $codes as $code ) {
			$key = 'wcep_refused_' . $code;

			$this->assertArrayHasKey(
				$key,
				$messages,
				'⚠ refusal code "' . $code . '" has no sentence, so a merchant would be told nothing they can act on.'
			);

			$sentence = (string) $messages[ $key ][1];

			$this->assertNotSame( '', trim( $sentence ) );

			$sentences[ $sentence ] = ( $sentences[ $sentence ] ?? 0 ) + 1;
		}

		// ⚠ AND NO TWO REFUSALS SHARE A SENTENCE. Two codes with one message is a
		// generic message wearing two names, which is the thing gate 38 forbids.
		$shared = array_filter( $sentences, static fn( $n ) => $n > 1 );

		$this->assertSame( array(), $shared, '⚠ two refusal codes share a sentence: ' . implode( ' | ', array_keys( $shared ) ) );

		$this->gate[] = 'gate 38 (sentences): ' . count( $codes ) . ' refusal codes, ' . count( $codes )
			. ' distinct merchant-facing sentences, none shared';
	}
}
