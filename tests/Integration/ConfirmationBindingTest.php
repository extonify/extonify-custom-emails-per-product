<?php
/**
 * PROMPT 13A ITEM 4 — a confirmation authorises THIS action, on THIS subject, to THESE
 * people.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\ConfirmationToken;
use Extonify\WCEP\Admin\DeliveryActions;
use Extonify\WCEP\Admin\DeliveryConfirm;
use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Repository\DeliveryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * THE CONFIRMATION AND THE SEND MUST AGREE — BY CONSTRUCTION FIRST, BY CHECK SECOND.
 *
 * ⚠ THE DEFECT WAS SEND NOW, AND IT NEEDED NO ATTACKER. `ConfirmationToken::issue()`
 * took no arguments and `consume()` checked only existence and expiry, so a token said
 * "the merchant confirmed something" and never "the merchant confirmed THIS". On its own
 * that is a weak replay story — the nonce is already action- and subject-specific. What
 * made it Tier 1 is that the Send Now screen resolved recipients from the **current
 * rule** while execution sends from the **scheduled snapshot** (ADR-0015 §2). Edit the
 * rule's recipients during the delay and the merchant read one address while another
 * received the email: precisely the outcome ADR-0019 §5's confirmation exists to
 * prevent, reached by an ordinary edit.
 *
 * ⚠ TWO FIXES, AND NEITHER REPLACES THE OTHER:
 *
 *   1. the confirmation for a SCHEDULED delivery now resolves from the SNAPSHOT, exactly
 *      as the send will — so the screen and the send agree by construction rather than
 *      by coincidence;
 *   2. the token binds the action, the subject ids and a fingerprint of the recipients
 *      SHOWN — so where the world moves anyway (the order's billing address changing,
 *      a live-rule action's recipients being edited) the confirmation is REFUSED rather
 *      than executed against people the merchant never saw.
 */
final class ConfirmationBindingTest extends ManualDeliveryTestCase {

	/**
	 * The address the SNAPSHOT names.
	 */
	const SNAPSHOTTED = 'wcep-snapshot@example.test';

	/**
	 * The address the LIVE rule names after the merchant's edit.
	 */
	const EDITED = 'wcep-edited@example.test';

	/**
	 * Gate lines.
	 *
	 * @var string[]
	 */
	private $gate = array();

	/**
	 * Print them.
	 *
	 * @after
	 * @return void
	 */
	protected function report_gate() {
		foreach ( $this->gate as $line ) {
			fwrite( STDERR, "\n[P13A item 4] " . $line );
		}

		$this->gate = array();
	}

	/**
	 * 4a. A SEND NOW CONFIRMATION SHOWS THE SNAPSHOT'S RECIPIENTS, AND THE SEND MATCHES.
	 *
	 * ⚠ THE RULE IS EDITED BETWEEN SCHEDULING AND CONFIRMING, which is the whole
	 * scenario. Before the fix the screen printed the EDITED address and the send went to
	 * the SNAPSHOTTED one — two different real people, with nothing anywhere saying so.
	 *
	 * @return void
	 */
	public function test_a_send_now_confirmation_shows_the_snapshot_recipients_and_the_send_matches() {
		$this->become_manager();

		$fixture = $this->scheduled_delivery( array( 'recipients' => array( 'to' => array( self::SNAPSHOTTED ) ) ) );

		// THE MERCHANT EDITS THE RULE DURING THE DELAY. Recipients only: the delay, the
		// mode and the consolidation are untouched, so ADR-0015 §4's phase check still
		// passes and the delivery is still owed.
		$this->rules->update( $fixture['rule'], array( 'recipients' => array( 'to' => array( self::EDITED ) ) ) );

		$this->assertSame(
			array( self::EDITED ),
			(array) $this->rules->find( $fixture['rule'] )['recipients']['to'],
			'the fixture edit did not land, so this test would prove nothing.'
		);

		// 1. THE SCREEN. It must show the snapshot's address and NOT the edited one.
		$markup = $this->render_confirmation( DeliveryActions::ACTION_SEND_NOW, $fixture['delivery'] );

		$this->assertStringContainsString(
			self::SNAPSHOTTED,
			$markup,
			'⚠ TIER 1: the Send Now confirmation did not show the address the send will actually use.'
		);

		$this->assertStringNotContainsString(
			self::EDITED,
			$markup,
			'⚠ TIER 1: the Send Now confirmation showed the CURRENT rule\'s recipients while the send runs from '
				. 'the scheduled snapshot.'
		);

		// 2. THE SEND. It must reach exactly what the screen showed.
		$outcome = $this->submit( DeliveryActions::ACTION_SEND_NOW, array( 'delivery' => $fixture['delivery'] ) );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'send now was refused: ' . $this->refusal_of( $outcome ) );
		$this->assertMailCount( 1, 'send now did not send exactly one message.' );

		$reached = $this->every_recipient( (array) $this->last_mail() );

		$this->assertContains(
			self::SNAPSHOTTED,
			$reached,
			'⚠ TIER 1: the send did not reach the address the confirmation showed.'
		);

		$this->assertNotContains(
			self::EDITED,
			$reached,
			'⚠ TIER 1: the send reached an address the confirmation never showed.'
		);

		$this->gate[] = 'send now: the rule was edited during the delay; the confirmation showed ' . self::SNAPSHOTTED
			. ' (the snapshot) and NOT ' . self::EDITED . ' (the live rule), and the send reached ['
			. implode( ', ', $reached ) . ']';
	}

	/**
	 * 4b. EDITING THE RULE'S RECIPIENTS AFTER THE CONFIRMATION IS RENDERED REFUSES IT.
	 *
	 * ⚠ THE LIVE-RULE ACTIONS ARE WHERE THIS BITES. A manual send and a resend render
	 * from the rule AS IT IS NOW (ADR-0019 §3), so an edit between drawing the screen and
	 * pressing the button really does change who receives the email — and the token's
	 * fingerprint is the only thing that can notice.
	 *
	 * @return void
	 */
	public function test_editing_the_recipients_after_the_confirmation_refuses_the_send() {
		$this->become_manager();

		$fixture = $this->undelivered_order( array( 'recipients' => array( 'to' => array( self::SNAPSHOTTED ) ) ) );

		// The merchant opens the confirmation and reads it.
		$token = $this->confirmation_token( DeliveryActions::ACTION_MANUAL, 0, $fixture['order_id'], $fixture['rule'] );

		$this->assertNotSame( '', $token, 'the confirmation issued no token.' );

		// … and somebody edits the rule before they press the button.
		$this->rules->update( $fixture['rule'], array( 'recipients' => array( 'to' => array( self::EDITED ) ) ) );

		$outcome = $this->submit(
			DeliveryActions::ACTION_MANUAL,
			array(
				'order' => $fixture['order_id'],
				'rule'  => $fixture['rule'],
			),
			$token
		);

		$this->assertSame(
			ConfirmationToken::CONTEXT_CHANGED,
			$this->refusal_of( $outcome ),
			'⚠ TIER 1: a confirmation was executed against recipients the merchant never saw.'
		);

		$this->assertMailCount( 0, '⚠ TIER 1: a stale confirmation still sent an email.' );

		$this->assertSame( array(), $this->tombstones_for( $fixture['order_id'] ), 'a refused confirmation claimed an identity.' );

		$this->gate[] = 'live-rule action: recipients edited between rendering and confirming => refused '
			. ConfirmationToken::CONTEXT_CHANGED . ', 0 emails, 0 tombstones';
	}

	/**
	 * 4c. CHANGING THE ORDER UNDER A SCHEDULED CONFIRMATION REFUSES IT TOO.
	 *
	 * ⚠ THE SNAPSHOT HOLDS DEFINITIONS, NOT ADDRESSES (ADR-0015 §2a) — `customer`, not
	 * `ada@example.test` — so a scheduled confirmation still RESOLVES against the live
	 * order. Change the billing address between rendering and confirming and the send
	 * would reach somebody else, snapshot or no snapshot. This is the half that fix 1
	 * cannot cover and fix 2 does.
	 *
	 * @return void
	 */
	public function test_changing_the_order_under_a_scheduled_confirmation_refuses_it() {
		$this->become_manager();

		$fixture = $this->scheduled_delivery( array( 'recipients' => array( 'to' => array( 'customer' ) ) ) );

		$token = $this->confirmation_token( DeliveryActions::ACTION_SEND_NOW, $fixture['delivery'], 0, 0 );

		$this->assertNotSame( '', $token, 'the confirmation issued no token.' );

		$order = wc_get_order( $fixture['order_id'] );
		$order->set_billing_email( 'wcep-someone-else@example.test' );
		$order->save();

		$outcome = $this->submit( DeliveryActions::ACTION_SEND_NOW, array( 'delivery' => $fixture['delivery'] ), $token );

		$this->assertSame(
			ConfirmationToken::CONTEXT_CHANGED,
			$this->refusal_of( $outcome ),
			'⚠ TIER 1: a scheduled confirmation sent to an address that changed after it was read.'
		);

		$this->assertMailCount( 0, '⚠ TIER 1: a stale scheduled confirmation still sent.' );

		$this->assertSame(
			DeliveryRepository::SCHEDULED,
			$this->status_of( $fixture['delivery'] ),
			'a refused confirmation moved the delivery out of `scheduled`.'
		);

		$this->assertTrue(
			$this->job_exists( $fixture['delivery'], $fixture['order_id'] ),
			'a refused confirmation removed the queued job, stranding the delivery.'
		);

		$this->gate[] = 'scheduled action: the order\'s billing address changed between rendering and confirming => '
			. 'refused ' . ConfirmationToken::CONTEXT_CHANGED . ', still scheduled, job intact';
	}

	/**
	 * 4d. A TOKEN ISSUED FOR ONE SUBJECT IS REFUSED FOR ANOTHER, end to end.
	 *
	 * @return void
	 */
	public function test_a_token_issued_for_one_delivery_is_refused_for_another() {
		$this->become_manager();

		$first  = $this->completed_delivery();
		$second = $this->completed_delivery();

		$this->assertNotSame( $first['delivery'], $second['delivery'], 'the two fixtures share a tombstone.' );

		// A confirmation opened for the FIRST delivery …
		$token = $this->confirmation_token( DeliveryActions::ACTION_RESEND, $first['delivery'], 0, 0 );

		$this->assertNotSame( '', $token, 'the confirmation issued no token.' );

		// … presented for the SECOND, with the second's own valid nonce.
		$outcome = $this->submit( DeliveryActions::ACTION_RESEND, array( 'delivery' => $second['delivery'] ), $token );

		$this->assertSame(
			ConfirmationToken::CONTEXT_CHANGED,
			$this->refusal_of( $outcome ),
			'⚠ a confirmation issued for one delivery executed against another.'
		);

		$this->assertMailCount( 0, '⚠ a cross-subject confirmation sent an email.' );

		/*
		 * ⚠ AND THE MISPRESENTED TOKEN IS SPENT, WHICH IS THE INTENDED DIRECTION. The
		 * delete is the atomic ownership statement and it runs before the fingerprint is
		 * compared, so a token presented against the wrong context is consumed and
		 * refused rather than left alive. That is what should happen: every message this
		 * refusal renders tells the merchant to open the confirmation again and check
		 * who it goes to, and a token that survived would let them press a stale button
		 * instead of re-reading.
		 */
		$this->assertSame(
			ConfirmationToken::REPLAYED,
			ConfirmationToken::consume( $token, ConfirmationToken::fingerprint( DeliveryActions::ACTION_RESEND, $first['delivery'], 0, 0, array() ) ),
			'a token presented for the wrong subject survived the refusal.'
		);

		/*
		 * THE POSITIVE CONTROL: a FRESH confirmation for the first delivery works. Without
		 * it, "refused for another subject" is satisfied by a handler that refuses
		 * everything.
		 */
		$works = $this->submit( DeliveryActions::ACTION_RESEND, array( 'delivery' => $first['delivery'] ) );

		$this->assertSame( '', $this->refusal_of( $works ), 'the token was refused for its own subject: ' . $this->refusal_of( $works ) );
		$this->assertMailCount( 1, 'the token did not work for the subject it was issued for.' );

		$this->gate[] = 'cross-subject: a resend token for delivery #' . $first['delivery'] . ' is refused for #'
			. $second['delivery'] . ' (and spent by the attempt), while a fresh confirmation for #'
			. $first['delivery'] . ' sends normally';
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * The confirmation screen's markup for one action.
	 *
	 * ⚠ THE REAL SCREEN, DRIVEN THROUGH `$_GET`. The recipients row is what the merchant
	 * reads, and reading it out of `DeliveryActions::confirmation()` instead would assert
	 * on the data rather than on the page.
	 *
	 * @param string $action      The action.
	 * @param int    $delivery_id Delivery id.
	 * @return string
	 */
	private function render_confirmation( string $action, int $delivery_id ): string {
		$this->use_method( 'GET' );

		$this->request(
			array(
				'page'                          => Menu::HISTORY_PAGE,
				'wcep_action'                   => $action,
				DeliveryActions::FIELD_DELIVERY => $delivery_id,
				DeliveryActions::FIELD_ORDER    => 0,
				DeliveryActions::FIELD_RULE     => 0,
			)
		);

		return $this->capture(
			static function () {
				DeliveryConfirm::render();
			}
		);
	}

	/**
	 * Every address a captured message reached, across `to`, `Cc` and `Bcc`.
	 *
	 * @param array $mail Captured message.
	 * @return string[] Lower-cased.
	 */
	private function every_recipient( array $mail ): array {
		$found = array();

		foreach ( (array) ( is_array( $mail['to'] ?? '' ) ? $mail['to'] : array( (string) ( $mail['to'] ?? '' ) ) ) as $to ) {
			foreach ( explode( ',', (string) $to ) as $address ) {
				$found[] = $address;
			}
		}

		foreach ( preg_split( '/\r\n|\r|\n/', $this->headers_of( $mail ) ) as $line ) {
			if ( 1 === preg_match( '/^\s*(cc|bcc)\s*:\s*(.+)$/i', (string) $line, $matches ) ) {
				foreach ( explode( ',', $matches[2] ) as $address ) {
					$found[] = $address;
				}
			}
		}

		$out = array();

		foreach ( $found as $address ) {
			$address = strtolower( trim( $address ) );

			if ( '' !== $address ) {
				$out[] = $address;
			}
		}

		return array_values( array_unique( $out ) );
	}
}
