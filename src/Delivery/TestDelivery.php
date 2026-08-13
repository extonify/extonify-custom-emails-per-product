<?php
/**
 * The test send: a real email, to an address the merchant supplied (ADR-0020 §4).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

use Extonify\WCEP\Domain\DeliveryIdentity;
use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Plugin;
use Extonify\WCEP\Repository\DeliveryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Decides WHETHER a test may be sent. It never decides HOW.
 *
 * ⚠ THE ONE OUTCOME THIS CLASS MUST NEVER PRODUCE IS AN EMAIL REACHING A CUSTOMER
 * (gate 42, Tier 1). It therefore builds the recipient itself, from one merchant-typed
 * address, and hands it to `Orchestrator::send_test()` as an OVERRIDE — so the rule's
 * recipients document is never read on this path, `{customer_email}` is never resolved,
 * the store admin address is never added, and the `cc` and `bcc` channels are empty
 * strings rather than resolved lists.
 *
 * ⚠ IT IS ADR-0019's `send_manual()` WITH TWO THINGS CHANGED AND NOTHING ELSE. The
 * refusal order, the check-before-claim posture, the identity-as-token uniquifier and
 * the delegation to the shared execution body are all that class's, deliberately
 * unforked (gate 39). What differs is the identity prefix (`test:` rather than
 * `manual:`), the recipient override, and the subject marker.
 */
final class TestDelivery {

	/**
	 * Outcome codes.
	 */
	const OK      = 'ok';
	const REFUSED = 'refused';

	/**
	 * Refusal codes specific to this action. Everything else is reused from
	 * `ManualDelivery`, because the same condition must not have two names.
	 */
	const REFUSED_BAD_ADDRESS = 'test_address_invalid';

	/**
	 * The attempt type every row this writes carries (ADR-0020 §4a).
	 */
	const ATTEMPT_TYPE = 'test';

	/**
	 * Send one test of one rule against one order.
	 *
	 * ⚠ THE SCHEMA AND THE GLOBAL SWITCH ARE CHECKED BEFORE THE CLAIM, exactly as
	 * `Orchestrator::deliver()` and `ManualDelivery::send_manual()` do (ADR-0012 §5), so
	 * a refused test leaves no tombstone behind.
	 *
	 * @param int    $order_id Order to render against.
	 * @param int    $rule_id  Rule to test.
	 * @param string $address  Address the merchant typed; '' takes their own.
	 * @param string $token    The consumed confirmation token.
	 * @return array{outcome:string, code:string, delivery_id:int, address:string}
	 */
	public static function send( int $order_id, int $rule_id, string $address, string $token ): array {
		if ( ! Migrator::is_operational() ) {
			return self::refuse( ManualDelivery::REFUSED_SCHEMA );
		}

		$rule = Plugin::instance()->rules()->find( $rule_id );

		/*
		 * ⚠ THE SAME RULE REFUSALS THE SENDING ACTIONS USE, FROM THE SAME METHOD
		 * (gate 39). R1 deleted, R2 insert mode — an insert rule has no message of its
		 * own, so there is nothing to send as a test either — and R3 vocabulary.
		 */
		$refusal = ManualDelivery::rule_refusal( $rule );

		if ( null !== $refusal ) {
			return self::refuse( $refusal );
		}

		$recipient = self::address_for( $address );

		if ( '' === $recipient ) {
			return self::refuse( self::REFUSED_BAD_ADDRESS );
		}

		$order = self::load_order( $order_id );

		if ( null === $order ) {
			return self::refuse( ManualDelivery::REFUSED_ORDER_MISSING );
		}

		$orchestrator = new Orchestrator();

		if ( ! $orchestrator->manual_send_is_available() ) {
			// R6/R9, before anything is claimed.
			return self::refuse( ManualDelivery::REFUSED_EMAIL_MISSING );
		}

		$items = ManualDelivery::matched_items( $order, (array) $rule );

		if ( array() === $items ) {
			/*
			 * ⚠ REFUSED RATHER THAN SENT EMPTY (ADR-0020 §4c). A test exists to show the
			 * merchant what the customer would get, and a message about no products is
			 * not that. The merchant who wants to see the template against an order the
			 * rule does not match has PREVIEW for exactly that case, with the note
			 * ADR-0020 §2a attaches.
			 */
			return self::refuse( ManualDelivery::REFUSED_NO_ITEMS );
		}

		$identity = DeliveryIdentity::test( $token );

		$claim = Plugin::instance()->deliveries()->claim(
			$order_id,
			$rule_id,
			DeliveryLogger::MODE,
			$identity,
			(int) ( $rule['revision'] ?? 0 )
		);

		if ( DeliveryRepository::CLAIMED !== $claim['result'] ) {
			// SUPPRESSED is a replay of this exact confirmation; FAILED is a write that
			// did not work. Neither may send, and both are reported honestly.
			return self::refuse(
				DeliveryRepository::SUPPRESSED === $claim['result']
					? ManualDelivery::REFUSED_ALREADY_EXISTS
					: ManualDelivery::REFUSED_WRITE_FAILED,
				(int) $claim['delivery_id'],
				$recipient
			);
		}

		$delivery_id = (int) $claim['delivery_id'];

		$orchestrator->send_test(
			$order,
			(array) $rule,
			$items,
			$delivery_id,
			$identity,
			self::recipients_for( $recipient ),
			self::subject_marker()
		);

		return array(
			'outcome'     => self::OK,
			'code'        => self::ATTEMPT_TYPE,
			'delivery_id' => $delivery_id,
			'address'     => $recipient,
		);
	}

	/**
	 * The ONE address a test goes to (ADR-0020 §4b).
	 *
	 * ⚠ NOTHING ABOUT THE ORDER OR THE RULE REACHES THIS METHOD, AND THAT IS THE POINT.
	 * It takes a string the merchant typed and, when that is blank, the CURRENT USER's
	 * own address — never the order's billing address, never the rule's `to`, never the
	 * store admin. Gate 42 enumerates the sources and shows each excluded.
	 *
	 * `is_email()` is WordPress's own validator and is what decides. `HeaderGuard::strip()`
	 * runs first so a line break in a pasted value cannot survive to be validated around.
	 *
	 * @param string $address Address the merchant typed, possibly ''.
	 * @return string A valid address, or '' when there is none.
	 */
	public static function address_for( string $address ): string {
		$candidate = HeaderGuard::strip( trim( $address ) );

		if ( '' === $candidate ) {
			$candidate = HeaderGuard::strip( (string) self::current_user_email() );
		}

		return is_email( $candidate ) ? $candidate : '';
	}

	/**
	 * The signed-in merchant's own address, or ''.
	 *
	 * @return string
	 */
	public static function current_user_email(): string {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return '';
		}

		$user = wp_get_current_user();

		return $user instanceof \WP_User ? (string) $user->user_email : '';
	}

	/**
	 * The recipient set a test uses: exactly one `to`, and no `cc` or `bcc`.
	 *
	 * ⚠ BUILT HERE RATHER THAN RESOLVED (gate 42). `RecipientResolver` exists to turn a
	 * rule's recipients document into addresses; a test has no document, has exactly one
	 * address, and must not acquire a second one from anywhere. Constructing the value
	 * directly is what makes "no channel other than `to`, no address other than this one"
	 * true by reading the code rather than by tracing a resolver.
	 *
	 * @param string $address The validated address.
	 * @return ResolvedRecipients
	 */
	public static function recipients_for( string $address ): ResolvedRecipients {
		return ResolvedRecipients::create(
			array(
				array(
					'address' => $address,
					'type'    => 'to',
					'source'  => self::ATTEMPT_TYPE,
				),
			)
		);
	}

	/**
	 * The marker a test's subject carries (ADR-0020 §4e).
	 *
	 * ⚠ A TEST LANDING IN A SHARED INBOX MUST NOT READ AS A LIVE CUSTOMER EMAIL. The
	 * trailing space is part of the marker so the resolved subject is not run into it.
	 *
	 * @return string
	 */
	public static function subject_marker(): string {
		return __( '[Test] ', 'extonify-custom-emails-per-product' );
	}

	/**
	 * Load an order, or null.
	 *
	 * @param int $order_id Order id.
	 * @return \WC_Order|null
	 */
	private static function load_order( int $order_id ): ?\WC_Order {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * A refusal.
	 *
	 * @param string $code        Refusal code.
	 * @param int    $delivery_id Tombstone id, or 0.
	 * @param string $address     Address the refusal is about, or ''.
	 * @return array
	 */
	private static function refuse( string $code, int $delivery_id = 0, string $address = '' ): array {
		return array(
			'outcome'     => self::REFUSED,
			'code'        => $code,
			'delivery_id' => $delivery_id,
			'address'     => $address,
		);
	}
}
