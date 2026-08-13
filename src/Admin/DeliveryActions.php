<?php
/**
 * The four manual delivery handlers (ADR-0019 §5, §6; gates 28, 36, 37, 38).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

use Extonify\WCEP\Delivery\ManualDelivery;
use Extonify\WCEP\Delivery\TestDelivery;
use Extonify\WCEP\Domain\DeliveryIdentity;
use Extonify\WCEP\Plugin;
use Extonify\WCEP\Repository\DeliveryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Every request that can send a customer an email.
 *
 * ⚠ POST ONLY, WITH A FRESH ACTION-SPECIFIC NONCE, AND A SINGLE-USE TOKEN
 * (ADR-0019 §5). There is no GET link anywhere in this plugin that sends anything —
 * gate 36 asserts it on the handlers AND on the rendered markup, because a
 * nonce-carrying GET link is still a link a browser will follow from history, a
 * prefetcher will warm, and a referrer will leak.
 *
 * ⚠ THE ORDER OF THE FOUR CHECKS IS LOAD-BEARING, and it is: capability, then
 * request method, then nonce, then token. Capability first because nothing below it
 * should run for somebody who may not be here at all. The token LAST because
 * consuming it is a WRITE — a request that fails the nonce must not burn the
 * merchant's confirmation on its way to being refused, or a single mistyped URL would
 * make them re-confirm a send they had already thought about.
 */
final class DeliveryActions {

	/**
	 * The `action` values this class answers to.
	 */
	const ACTION_RESEND   = 'wcep_resend';
	const ACTION_SEND_NOW = 'wcep_send_now';
	const ACTION_CANCEL   = 'wcep_cancel';
	const ACTION_MANUAL   = 'wcep_send_manual';

	/**
	 * The test send (ADR-0020 §5).
	 *
	 * ⚠ A FIFTH MEMBER OF THIS CLASS RATHER THAN A SECOND CONFIRMATION MECHANISM. The
	 * capability → method → nonce → token order, the refusal vocabulary, the
	 * post/redirect/get shape and the gate-28, gate-36, gate-37 and gate-38 tables are
	 * all DERIVED from self::write_actions(), so joining that list is what makes a test
	 * send inherit every one of them — and what makes it fail those gates if it does not.
	 */
	const ACTION_TEST = 'wcep_send_test';

	/**
	 * The confirmation screen's own `action` value. Read-only: it shows what WOULD
	 * happen and sends nothing.
	 */
	const ACTION_CONFIRM = 'wcep_confirm';

	/**
	 * Form field names.
	 */
	const FIELD_NONCE    = 'extonify_wcep_delivery_nonce';
	const FIELD_TOKEN    = 'extonify_wcep_confirm_token';
	const FIELD_DELIVERY = 'delivery';
	const FIELD_ORDER    = 'order';
	const FIELD_RULE     = 'rule';

	/**
	 * The address a test send goes to (ADR-0020 §4b).
	 *
	 * ⚠ THE ONLY FIELD IN THIS CLASS THAT IS NOT AN INTEGER ID, so it is the only one
	 * that needs sanitising rather than casting — and it is validated with `is_email()`
	 * by `TestDelivery::address_for()` before anything is claimed, never here.
	 */
	const FIELD_ADDRESS = 'test_address';

	/**
	 * Every action that sends or changes a delivery.
	 *
	 * ⚠ THE GATE-28 AND GATE-38 TABLES ARE DERIVED FROM THIS LIST, so an action added
	 * without a capability test, a nonce test and a refusal test fails the gate rather
	 * than shipping unnoticed.
	 *
	 * @return string[]
	 */
	public static function write_actions(): array {
		return array( self::ACTION_RESEND, self::ACTION_SEND_NOW, self::ACTION_CANCEL, self::ACTION_MANUAL, self::ACTION_TEST );
	}

	/**
	 * The actions whose subject is an ORDER plus a RULE rather than a delivery.
	 *
	 * ⚠ THE NONCE'S SUBJECT DEPENDS ON THIS, so it is one list read in three places
	 * rather than three `=== ACTION_MANUAL` comparisons that can drift apart. A manual
	 * send and a test send both act on a rule and an order that may have no delivery at
	 * all; the other three act on a delivery that already exists.
	 *
	 * @return string[]
	 */
	public static function order_scoped_actions(): array {
		return array( self::ACTION_MANUAL, self::ACTION_TEST );
	}

	/**
	 * Whether an action's nonce subject is the order rather than the delivery.
	 *
	 * @param string $action The action.
	 * @return bool
	 */
	public static function is_order_scoped( string $action ): bool {
		return in_array( $action, self::order_scoped_actions(), true );
	}

	/**
	 * Whether an action is one of ours.
	 *
	 * @param string $action Requested action.
	 * @return bool
	 */
	public static function is_write_action( string $action ): bool {
		return in_array( $action, self::write_actions(), true );
	}

	/**
	 * The nonce action for one delivery action on one subject.
	 *
	 * ⚠ ACTION-SPECIFIC **AND** SUBJECT-SPECIFIC, for the reason
	 * `RuleActions::row_nonce_action()` gives: a single shared nonce would let a
	 * "cancel delivery 4" form, once leaked, be replayed as "resend delivery 9" — the
	 * nonce would verify, because it would be the same nonce.
	 *
	 * @param string $action  One of self::write_actions().
	 * @param int    $subject Delivery id, or order id for a manual send.
	 * @param int    $rule_id Rule id for a manual send, else 0.
	 * @return string
	 */
	public static function nonce_action( string $action, int $subject, int $rule_id = 0 ): string {
		return 'extonify_wcep_' . $action . '_' . $subject . '_' . $rule_id;
	}

	/**
	 * Handle one delivery action.
	 *
	 * @param string $action Requested action, already sanitised.
	 * @param array  $post   Raw `$_POST`.
	 * @return array Outcome in the `RuleActions` shape.
	 */
	public static function handle( string $action, array $post ): array {
		// 1. ⚠ THE CAPABILITY FIRST, ON EVERY HANDLER, TRUSTING NOTHING UPSTREAM.
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			return self::denied( __( 'You are not allowed to send custom product emails.', 'extonify-custom-emails-per-product' ) );
		}

		if ( ! self::is_write_action( $action ) ) {
			return self::denied( __( 'Unknown action.', 'extonify-custom-emails-per-product' ) );
		}

		// 2. ⚠ POST ONLY (gate 36). A GET that sends is a link, and a link is followed
		// by history, by a prefetcher and by anything that reads a referrer.
		if ( ! self::is_post_request() ) {
			return self::denied( __( 'This action can only be run from its confirmation form.', 'extonify-custom-emails-per-product' ) );
		}

		$delivery_id = isset( $post[ self::FIELD_DELIVERY ] ) ? max( 0, (int) wp_unslash( $post[ self::FIELD_DELIVERY ] ) ) : 0;
		$order_id    = isset( $post[ self::FIELD_ORDER ] ) ? max( 0, (int) wp_unslash( $post[ self::FIELD_ORDER ] ) ) : 0;
		$rule_id     = isset( $post[ self::FIELD_RULE ] ) ? max( 0, (int) wp_unslash( $post[ self::FIELD_RULE ] ) ) : 0;

		$subject = self::is_order_scoped( $action ) ? $order_id : $delivery_id;

		// 3. The nonce, before anything is written.
		$nonce = isset( $post[ self::FIELD_NONCE ] ) ? sanitize_text_field( wp_unslash( $post[ self::FIELD_NONCE ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::nonce_action( $action, $subject, $rule_id ) ) ) {
			return self::denied( __( 'This confirmation has expired. Open the delivery again and confirm once more.', 'extonify-custom-emails-per-product' ) );
		}

		// 4. ⚠ THE TOKEN, CONSUMED ATOMICALLY, AND ONLY NOW (ADR-0019 §5, gate 37).
		// Whoever wins this single statement owns the one execution of this
		// confirmation; everybody else is a replay and sends nothing.
		$token = isset( $post[ self::FIELD_TOKEN ] ) ? sanitize_text_field( wp_unslash( $post[ self::FIELD_TOKEN ] ) ) : '';

		if ( ! ConfirmationToken::consume( $token ) ) {
			return self::refused( 'replayed', $delivery_id, $order_id );
		}

		return self::dispatch( $action, $delivery_id, $order_id, $rule_id, $token, self::submitted_address( $post ) );
	}

	/**
	 * The test address this request carries, sanitised but NOT yet validated.
	 *
	 * ⚠ SANITISED HERE, VALIDATED IN `TestDelivery::address_for()`, AND THE SPLIT IS
	 * DELIBERATE. `sanitize_text_field()` makes the value safe to pass around;
	 * `is_email()` decides whether it may be mailed. Doing the second one here would put
	 * the "who does this go to" decision in the request layer, and gate 42 needs it in
	 * exactly one place.
	 *
	 * @param array $post Raw `$_POST`.
	 * @return string
	 */
	private static function submitted_address( array $post ): string {
		return isset( $post[ self::FIELD_ADDRESS ] )
			? sanitize_text_field( wp_unslash( $post[ self::FIELD_ADDRESS ] ) )
			: '';
	}

	/**
	 * Run the confirmed action.
	 *
	 * @param string $action      The action.
	 * @param int    $delivery_id Delivery id.
	 * @param int    $order_id    Order id.
	 * @param int    $rule_id     Rule id.
	 * @param string $token       The consumed token.
	 * @param string $address     Test address, for the test action only.
	 * @return array Outcome.
	 */
	private static function dispatch( string $action, int $delivery_id, int $order_id, int $rule_id, string $token, string $address = '' ): array {
		if ( self::ACTION_RESEND === $action ) {
			return self::report( $action, ManualDelivery::resend( $delivery_id ), $delivery_id, $order_id );
		}

		if ( self::ACTION_SEND_NOW === $action ) {
			return self::report( $action, ManualDelivery::send_now( $delivery_id ), $delivery_id, $order_id );
		}

		if ( self::ACTION_CANCEL === $action ) {
			return self::report( $action, ManualDelivery::cancel( $delivery_id ), $delivery_id, $order_id );
		}

		if ( self::ACTION_TEST === $action ) {
			return self::report( $action, TestDelivery::send( $order_id, $rule_id, $address, $token ), 0, $order_id, $rule_id );
		}

		return self::report( $action, ManualDelivery::send_manual( $order_id, $rule_id, $token ), 0, $order_id );
	}

	/**
	 * Turn a `ManualDelivery` result into a redirect or a refusal.
	 *
	 * ⚠ EVERY OUTCOME REDIRECTS (post/redirect/get), INCLUDING A REFUSAL. This differs
	 * from the rule editor, where ADR-0017 §3.2 forbids redirecting a refusal because
	 * doing so would throw away the merchant's typed input. There is no typed input
	 * here — the form carries three ids — and NOT redirecting would leave a POST in the
	 * browser's history that a reload would re-submit. The refusal's specific reason
	 * travels in the query string and is matched against a closed map, never echoed.
	 *
	 * @param string $action      The action.
	 * @param array  $result      Result from `ManualDelivery` or `TestDelivery`.
	 * @param int    $delivery_id Delivery id.
	 * @param int    $order_id    Order id.
	 * @param int    $rule_id     Rule id, for the actions that return to a rule screen.
	 * @return array Outcome.
	 */
	private static function report( string $action, array $result, int $delivery_id, int $order_id, int $rule_id = 0 ): array {
		$code = (string) ( $result['code'] ?? '' );

		if ( ManualDelivery::OK !== ( $result['outcome'] ?? '' ) ) {
			return self::refused( $code, $delivery_id, $order_id, $action, $rule_id );
		}

		return self::redirect( self::success_message( $action, $code ), $delivery_id, $order_id, $action, $rule_id );
	}

	/**
	 * The notice code one successful action reports.
	 *
	 * @param string $action The action.
	 * @param string $code   The result's own code.
	 * @return string
	 */
	private static function success_message( string $action, string $code ): string {
		if ( self::ACTION_SEND_NOW === $action ) {
			// ⚠ THE TOMBSTONE'S OWN STATUS, NOT "sent". `send_now()` runs the scheduler's
			// full re-validation (ADR-0019 §7), so a delivery whose rule was disabled
			// after scheduling is CANCELLED rather than sent — and telling the merchant
			// "sent" would be untrue about an email that never went out.
			return 'sent' === $code ? 'wcep_sent_now' : 'wcep_send_now_' . ( 'cancelled' === $code ? 'cancelled' : 'other' );
		}

		if ( self::ACTION_CANCEL === $action ) {
			return 'wcep_cancelled';
		}

		if ( self::ACTION_TEST === $action ) {
			return 'wcep_sent_test';
		}

		return self::ACTION_MANUAL === $action ? 'wcep_sent_manual' : 'wcep_resent';
	}

	/**
	 * Whether this is a POST request.
	 *
	 * @return bool
	 */
	private static function is_post_request(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		return 'POST' === $method;
	}

	/**
	 * A refusal outcome.
	 *
	 * @param string $code        Refusal code.
	 * @param int    $delivery_id Delivery id.
	 * @param int    $order_id    Order id.
	 * @param string $action      The action, which decides where the merchant lands.
	 * @param int    $rule_id     Rule id, for the actions that return to a rule screen.
	 * @return array
	 */
	private static function refused( string $code, int $delivery_id, int $order_id, string $action = '', int $rule_id = 0 ): array {
		return array(
			'outcome'     => RuleActions::OUTCOME_REDIRECT,
			'refusal'     => $code,
			'delivery_id' => $delivery_id,
			'url'         => self::return_url( 'wcep_refused_' . $code, $delivery_id, $order_id, $action, $rule_id ),
		);
	}

	/**
	 * A success outcome.
	 *
	 * @param string $message     Notice code.
	 * @param int    $delivery_id Delivery id.
	 * @param int    $order_id    Order id.
	 * @param string $action      The action, which decides where the merchant lands.
	 * @param int    $rule_id     Rule id, for the actions that return to a rule screen.
	 * @return array
	 */
	private static function redirect( string $message, int $delivery_id, int $order_id, string $action = '', int $rule_id = 0 ): array {
		return array(
			'outcome'     => RuleActions::OUTCOME_REDIRECT,
			'refusal'     => '',
			'delivery_id' => $delivery_id,
			'url'         => self::return_url( $message, $delivery_id, $order_id, $action, $rule_id ),
		);
	}

	/**
	 * A denial.
	 *
	 * @param string $message Message.
	 * @return array
	 */
	private static function denied( string $message ): array {
		return array(
			'outcome' => RuleActions::OUTCOME_DENIED,
			'message' => $message,
			'refusal' => 'denied',
		);
	}

	/**
	 * Where to send the merchant afterwards.
	 *
	 * ⚠ A TEST SEND GOES BACK TO THE PREVIEW SCREEN IT WAS SUBMITTED FROM, not to the
	 * delivery history (ADR-0020 §5). The merchant is looking at a rule and iterating on
	 * it; landing them on a different screen with their preview gone would make "send a
	 * test" cost them their place. Every other action acts on a delivery, and the history
	 * is where a delivery lives.
	 *
	 * @param string $message     Notice code.
	 * @param int    $delivery_id Delivery id.
	 * @param int    $order_id    Order id.
	 * @param string $action      The action, which decides where the merchant lands.
	 * @param int    $rule_id     Rule id, for the actions that return to a rule screen.
	 * @return string
	 */
	private static function return_url( string $message, int $delivery_id, int $order_id, string $action = '', int $rule_id = 0 ): string {
		if ( self::ACTION_TEST === $action ) {
			return Menu::url(
				array(
					'action'          => Menu::ACTION_PREVIEW,
					'rule'            => $rule_id,
					self::FIELD_ORDER => $order_id,
					Notices::ARG      => $message,
				)
			);
		}

		$args = array( Notices::ARG => $message );

		if ( $order_id > 0 ) {
			$args[ DeliveriesListTable::ARG_ORDER ] = $order_id;
		} elseif ( $delivery_id > 0 ) {
			$order = Plugin::instance()->deliveries()->find_by_id( $delivery_id );

			if ( null !== $order ) {
				$args[ DeliveriesListTable::ARG_ORDER ] = (int) $order['order_id'];
			}
		}

		return Menu::history_url( $args );
	}

	// -----------------------------------------------------------------------
	// The confirmation screen (ADR-0019 §6)
	// -----------------------------------------------------------------------

	/**
	 * Everything the confirmation screen needs, or the refusal that stops it.
	 *
	 * ⚠ THE REFUSALS ARE EVALUATED HERE TOO, NOT ONLY IN THE HANDLER. A merchant must
	 * not be shown a confirmation button for something the plugin is going to refuse —
	 * but the handler re-checks every one of them, because the confirmation screen is a
	 * courtesy and the handler is the boundary. Anything else would make the UI the
	 * authority.
	 *
	 * @param string $action      The action.
	 * @param int    $delivery_id Delivery id.
	 * @param int    $order_id    Order id.
	 * @param int    $rule_id     Rule id.
	 * @param string $address     Test address the merchant typed, for the test action.
	 * @return array
	 */
	public static function confirmation( string $action, int $delivery_id, int $order_id, int $rule_id, string $address = '' ): array {
		$deliveries = Plugin::instance()->deliveries();

		$tombstone = $delivery_id > 0 ? $deliveries->find_by_id( $delivery_id ) : null;

		if ( ! self::is_order_scoped( $action ) ) {
			if ( null === $tombstone ) {
				return array( 'refusal' => ManualDelivery::REFUSED_DELIVERY_MISSING );
			}

			$rule_id  = (int) $tombstone['rule_id'];
			$order_id = (int) $tombstone['order_id'];
		}

		$rule = $rule_id > 0 ? Plugin::instance()->rules()->find( $rule_id ) : null;

		$refusal = ManualDelivery::rule_refusal( $rule );

		if ( null !== $refusal ) {
			return array( 'refusal' => $refusal );
		}

		$state_refusal = self::state_refusal( $action, $tombstone );

		if ( null !== $state_refusal ) {
			return array( 'refusal' => $state_refusal );
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order ) {
			return array( 'refusal' => ManualDelivery::REFUSED_ORDER_MISSING );
		}

		/*
		 * ⚠ A TEST'S CONFIRMATION SHOWS THE TEST ADDRESS, NEVER THE RULE'S RECIPIENTS
		 * (ADR-0020 §4b, §5). Resolving the rule's recipients here would print the
		 * CUSTOMER'S ADDRESS on the confirmation screen for an action that will never
		 * mail it — telling the merchant the exact opposite of what is about to happen,
		 * on the one screen whose job is to be right about that.
		 */
		if ( self::ACTION_TEST === $action ) {
			$resolved = TestDelivery::address_for( $address );

			if ( '' === $resolved ) {
				return array( 'refusal' => TestDelivery::REFUSED_BAD_ADDRESS );
			}

			$recipients = array( 'to' => array( $resolved ) );
		} else {
			// ⚠ RESOLVED, NOT THE RULE'S DEFINITION (ADR-0019 §6). A merchant shown
			// `{customer_email}` has not been told who is about to be emailed.
			$resolved   = '';
			$recipients = self::preview_recipients( $order, (array) $rule );
		}

		return array(
			'refusal'    => '',
			'action'     => $action,
			'delivery'   => $delivery_id,
			'order'      => $order_id,
			'rule'       => $rule_id,
			'rule_row'   => (array) $rule,
			'tombstone'  => (array) $tombstone,
			'recipients' => $recipients,
			'address'    => $resolved,
			'token'      => ConfirmationToken::issue(),
		);
	}

	/**
	 * The state refusals that depend on the tombstone (R4, R5, R8).
	 *
	 * @param string     $action    The action.
	 * @param array|null $tombstone Tombstone row, or null for a manual send.
	 * @return string|null
	 */
	private static function state_refusal( string $action, ?array $tombstone ): ?string {
		if ( self::is_order_scoped( $action ) || null === $tombstone ) {
			return null;
		}

		$status = (string) $tombstone['final_status'];

		if ( self::ACTION_RESEND === $action ) {
			return in_array( $status, DeliveryRepository::IN_FLIGHT_STATUSES, true )
				? ManualDelivery::REFUSED_NOT_TERMINAL
				: null;
		}

		return DeliveryRepository::SCHEDULED === $status ? null : ManualDelivery::REFUSED_NOT_SCHEDULED;
	}

	/**
	 * The addresses this send would really reach.
	 *
	 * ⚠ THROUGH THE SAME RESOLVER THE SEND USES (gate 39). Showing a merchant a
	 * different answer from the one the send will produce would make the confirmation
	 * worse than none at all.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $rule  Rule row.
	 * @return array<string,string[]> Channel => addresses.
	 */
	public static function preview_recipients( \WC_Order $order, array $rule ): array {
		$resolved = ( new \Extonify\WCEP\Delivery\Orchestrator() )->preview_recipients( $order, $rule );

		$out = array();

		foreach ( array( 'to', 'cc', 'bcc' ) as $channel ) {
			$addresses = $resolved->addresses( $channel );

			if ( array() !== $addresses ) {
				$out[ $channel ] = $addresses;
			}
		}

		return $out;
	}

	/**
	 * Whether a tombstone was produced by a manual send (ADR-0019 §2).
	 *
	 * @param array $tombstone Tombstone row.
	 * @return bool
	 */
	public static function is_manual( array $tombstone ): bool {
		return DeliveryIdentity::is_manual( (string) ( $tombstone['trigger_identity'] ?? '' ) );
	}
}
