<?php
/**
 * Fixtures for the manual delivery actions (ADR-0019).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\ConfirmationToken;
use Extonify\WCEP\Admin\DeliveryActions;
use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Delivery\DeliveryLogger;
use Extonify\WCEP\Delivery\ScheduledDelivery;
use Extonify\WCEP\Domain\TriggerEvent;
use Extonify\WCEP\Repository\DeliveryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * An admin request that sends a customer an email.
 *
 * ⚠ IT NEEDS BOTH HARNESSES, WHICH IS WHY `AdminHarness` IS A TRAIT. The delivery
 * half (mail capture, the live email object, its WooCommerce settings) comes from
 * `DeliveryTestCase`; the admin half (users, nonces, `wp_die()` that throws, the
 * request method) comes from the trait. The thing under test is the join between
 * them, so a test that had only one would be testing something else.
 *
 * ⚠ EVERY CONFIRMED ACTION IS SUBMITTED THE WAY A BROWSER WOULD — POST, with a real
 * nonce and a real single-use token. A test that called `ManualDelivery` directly
 * would prove the delivery logic and nothing about the confirmation gate, which is
 * where the Tier 1 risk on this prompt lives.
 */
abstract class ManualDeliveryTestCase extends DeliveryTestCase {

	use AdminHarness;

	/**
	 * Run one confirmed action exactly as the confirmation form submits it.
	 *
	 * @param string $action      The action.
	 * @param array  $fields      `delivery`, `order`, `rule` as needed.
	 * @param string $token       Token to carry; '' issues a fresh one.
	 * @param string $nonce       Nonce to carry; '' mints the correct one.
	 * @return array The handler's outcome.
	 */
	protected function submit( string $action, array $fields, string $token = '', string $nonce = '' ): array {
		$post = $this->confirmed_post( $action, $fields, $token, $nonce );

		$this->use_method( 'POST' );
		$this->request( array( 'page' => Menu::HISTORY_PAGE ), $post );

		return DeliveryActions::handle( $action, $post );
	}

	/**
	 * The `$_POST` a confirmation form would submit.
	 *
	 * @param string $action The action.
	 * @param array  $fields `delivery`, `order`, `rule`.
	 * @param string $token  Token to carry; '' issues a fresh one.
	 * @param string $nonce  Nonce to carry; '' mints the correct one.
	 * @return array
	 */
	protected function confirmed_post( string $action, array $fields, string $token = '', string $nonce = '' ): array {
		$delivery_id = (int) ( $fields['delivery'] ?? 0 );
		$order_id    = (int) ( $fields['order'] ?? 0 );
		$rule_id     = (int) ( $fields['rule'] ?? 0 );

		$subject = DeliveryActions::ACTION_MANUAL === $action ? $order_id : $delivery_id;

		return array(
			'action'                        => $action,
			DeliveryActions::FIELD_DELIVERY => $delivery_id,
			DeliveryActions::FIELD_ORDER    => $order_id,
			DeliveryActions::FIELD_RULE     => $rule_id,
			DeliveryActions::FIELD_TOKEN    => '' !== $token ? $token : ConfirmationToken::issue(),
			DeliveryActions::FIELD_NONCE    => '' !== $nonce
				? $nonce
				: wp_create_nonce( DeliveryActions::nonce_action( $action, $subject, $rule_id ) ),
		);
	}

	/**
	 * Submit an already-built `$_POST` again, byte for byte.
	 *
	 * ⚠ THIS IS THE REPLAY GATE 37 IS ABOUT. A browser reload, a back-and-resubmit and
	 * a double-click all produce EXACTLY this: the same token, the same nonce, the same
	 * ids, a second time.
	 *
	 * @param string $action The action.
	 * @param array  $post   The identical `$_POST`.
	 * @return array The handler's outcome.
	 */
	protected function resubmit( string $action, array $post ): array {
		$this->use_method( 'POST' );
		$this->request( array( 'page' => Menu::HISTORY_PAGE ), $post );

		return DeliveryActions::handle( $action, $post );
	}

	/**
	 * The refusal code a handler outcome reports, or '' on success.
	 *
	 * @param array $outcome Handler outcome.
	 * @return string
	 */
	protected function refusal_of( array $outcome ): string {
		return (string) ( $outcome['refusal'] ?? '' );
	}

	/**
	 * Assert an outcome refused with one specific reason, and that nothing was sent.
	 *
	 * @param array  $outcome  Handler outcome.
	 * @param string $expected Expected refusal code.
	 * @param int    $mail     Mail count expected (usually 0).
	 * @return void
	 */
	protected function assertRefusedWith( array $outcome, string $expected, int $mail = 0 ): void {
		$this->assertSame(
			$expected,
			$this->refusal_of( $outcome ),
			'⚠ the action refused for the wrong reason, so the merchant would be told the wrong thing.'
		);

		$this->assertMailCount( $mail, '⚠ TIER 1: a refused action sent mail.' );
	}

	// -----------------------------------------------------------------------
	// Delivery fixtures
	// -----------------------------------------------------------------------

	/**
	 * A completed automatic delivery: rule, product, order, one sent email.
	 *
	 * @param array $rule_overrides Rule fields to replace.
	 * @return array{rule:int, order:\WC_Order, order_id:int, product:int, delivery:int, identity:string}
	 */
	protected function completed_delivery( array $rule_overrides = array() ): array {
		$product_id = $this->make_product( 'WCEP Manual Product' );
		$rule_id    = $this->make_sending_rule( $product_id, $rule_overrides );
		$order      = $this->make_order( $product_id );

		$identity = 'status:completed';

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$tombstone = $this->tombstone( (int) $order->get_id(), $rule_id, $identity );

		$this->assertIsArray( $tombstone, 'the automatic delivery fixture did not produce a tombstone.' );
		$this->assertSame( 'sent', (string) $tombstone['final_status'], 'the fixture delivery did not send.' );

		$this->track_delivery( (int) $tombstone['id'] );

		$this->captured_mail = array();

		return array(
			'rule'     => $rule_id,
			'order'    => $order,
			'order_id' => (int) $order->get_id(),
			'product'  => $product_id,
			'delivery' => (int) $tombstone['id'],
			'identity' => $identity,
		);
	}

	/**
	 * A rule, product and order with NO delivery yet.
	 *
	 * @param array $rule_overrides Rule fields to replace.
	 * @return array{rule:int, order:\WC_Order, order_id:int, product:int}
	 */
	protected function undelivered_order( array $rule_overrides = array() ): array {
		$product_id = $this->make_product( 'WCEP Manual Product' );
		$rule_id    = $this->make_sending_rule( $product_id, $rule_overrides );
		$order      = $this->make_order( $product_id );

		$this->captured_mail = array();

		return array(
			'rule'     => $rule_id,
			'order'    => $order,
			'order_id' => (int) $order->get_id(),
			'product'  => $product_id,
		);
	}

	/**
	 * A delivery sitting in `scheduled`, with a real queued job.
	 *
	 * @param array $rule_overrides Rule fields to replace.
	 * @return array{rule:int, order:\WC_Order, order_id:int, product:int, delivery:int}
	 */
	protected function scheduled_delivery( array $rule_overrides = array() ): array {
		$fixture = $this->undelivered_order(
			array_merge( array( 'delay_seconds' => 3600 ), $rule_overrides )
		);

		$this->orchestrator()->run( $fixture['order'], TriggerEvent::status( 'completed' ) );

		$tombstone = $this->tombstone( $fixture['order_id'], $fixture['rule'], 'status:completed' );

		$this->assertIsArray( $tombstone, 'the scheduled fixture did not produce a tombstone.' );
		$this->assertSame(
			DeliveryRepository::SCHEDULED,
			(string) $tombstone['final_status'],
			'the scheduled fixture is not in the scheduled state.'
		);

		$this->track_delivery( (int) $tombstone['id'] );

		$this->captured_mail = array();

		$fixture['delivery'] = (int) $tombstone['id'];

		return $fixture;
	}

	/**
	 * Whether a queued job exists for one delivery.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @param int $order_id    Order id.
	 * @return bool
	 */
	protected function job_exists( int $delivery_id, int $order_id ): bool {
		return ScheduledDelivery::has_any_job( $delivery_id, $order_id );
	}

	/**
	 * One tombstone's current status.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return string
	 */
	protected function status_of( int $delivery_id ): string {
		$row = $this->deliveries->find_by_id( $delivery_id );

		return null === $row ? '' : (string) $row['final_status'];
	}

	/**
	 * Every tombstone this order has, keyed by trigger identity.
	 *
	 * @param int $order_id Order id.
	 * @return array<string,array>
	 */
	protected function tombstones_by_identity( int $order_id ): array {
		$out = array();

		foreach ( $this->deliveries->find_for_order( $order_id ) as $row ) {
			$out[ (string) $row['trigger_identity'] ] = $row;

			$this->track_delivery( (int) $row['id'] );
		}

		return $out;
	}

	/**
	 * The attempt rows for one delivery, typed.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return string[] One `type:state` per row, in order.
	 */
	protected function attempt_signature( int $delivery_id ): array {
		$out = array();

		foreach ( $this->detail_rows( $delivery_id ) as $row ) {
			$out[] = (string) $row['type'] . ':' . (string) $row['state'];
		}

		return $out;
	}

	/**
	 * The mode every delivery this plugin writes uses.
	 *
	 * @return string
	 */
	protected function mode(): string {
		return DeliveryLogger::MODE;
	}
}
