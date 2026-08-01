<?php
/**
 * Fixtures shared by the delivery-spine integration tests (ADR-0012).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\DeliveryLogger;
use Extonify\WCEP\Delivery\Orchestrator;
use Extonify\WCEP\Email\Custom_Email;
use Extonify\WCEP\Email\EmailIdentity;
use Extonify\WCEP\Matching\ItemResolver;
use Extonify\WCEP\Matching\RuleMatcher;
use Extonify\WCEP\Repository\DeliveryDetailRepository;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * Real orders, real rules, the real registered email object, and NOT ONE REAL
 * MESSAGE.
 *
 * Every send is intercepted at `pre_wp_mail`, which short-circuits before
 * PHPMailer is touched, and the captured payload is what the assertions read.
 * `MatchingTestCase` blocks mail for tidiness; here the capture IS the
 * evidence — "an email was sent" is only ever asserted against this array.
 */
abstract class DeliveryTestCase extends MatchingTestCase {

	/**
	 * Mail captured while this test ran, newest last.
	 *
	 * @var array[]
	 */
	protected $captured_mail = array();

	/**
	 * Tombstone repository.
	 *
	 * @var DeliveryRepository|null
	 */
	protected $deliveries = null;

	/**
	 * Detail repository.
	 *
	 * @var DeliveryDetailRepository|null
	 */
	protected $details = null;

	/**
	 * The capture filter, kept so it can be removed again.
	 *
	 * @var callable|null
	 */
	private $capture = null;

	/**
	 * The email settings as they were before the test touched them.
	 *
	 * @var mixed
	 */
	private $email_settings_backup = null;

	/**
	 * Install the capture and build the repositories.
	 *
	 * Runs at priority 1 so it wins over `MatchingTestCase`'s blocker and this
	 * class sees every attempt.
	 *
	 * @before
	 * @return void
	 */
	protected function set_up_delivery() {
		$this->deliveries    = new DeliveryRepository();
		$this->details       = new DeliveryDetailRepository();
		$this->captured_mail = array();

		$this->capture = function ( $short_circuit, $atts ) {
			$this->captured_mail[] = $atts;
			return true; // Short-circuit: nothing reaches PHPMailer.
		};

		add_filter( 'pre_wp_mail', $this->capture, 1, 2 );

		// INITIALISE THE MAILER FIRST. `Custom_Email extends \WC_Email`, and
		// WooCommerce only includes the parent class when `WC_Emails::init()`
		// runs — so touching the subclass before this point autoloads a child
		// whose parent does not exist yet. `EmailIdentity` carries the constants
		// precisely so ordinary lookups never depend on this, but the live
		// object obviously does.
		WC()->mailer();

		$this->email_settings_backup = get_option( $this->email_settings_key(), null );

		// Start from a known-good, ENABLED state: another test's kill-switch
		// run must not silently disable this one.
		$this->set_email_setting( 'enabled', 'yes' );
	}

	/**
	 * Remove the capture and restore the email settings.
	 *
	 * @after
	 * @return void
	 */
	protected function tear_down_delivery() {
		if ( null !== $this->capture ) {
			remove_filter( 'pre_wp_mail', $this->capture, 1 );
			$this->capture = null;
		}

		if ( null === $this->email_settings_backup ) {
			delete_option( $this->email_settings_key() );
		} else {
			update_option( $this->email_settings_key(), $this->email_settings_backup );
		}

		$this->reload_email_settings();
	}

	/**
	 * The option key WooCommerce stores this email's settings under.
	 *
	 * @return string
	 */
	protected function email_settings_key(): string {
		return EmailIdentity::settings_key();
	}

	/**
	 * Write one of the email's WooCommerce settings and reload the live object.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Setting value.
	 * @return void
	 */
	protected function set_email_setting( string $key, $value ): void {
		$settings         = (array) get_option( $this->email_settings_key(), array() );
		$settings[ $key ] = $value;

		update_option( $this->email_settings_key(), $settings );

		$this->reload_email_settings();
	}

	/**
	 * Make the live email object re-read its saved settings.
	 *
	 * @return void
	 */
	protected function reload_email_settings(): void {
		$email = $this->live_email();
		if ( null !== $email ) {
			$email->init_settings();
			$email->enabled = $email->get_option( 'enabled', 'yes' );

			/*
			 * ⚠ `email_type` IS A PROPERTY, NOT A LOOKUP. `WC_Email::__construct()`
			 * copies it out of the settings ONCE, and `get_email_type()` reads the
			 * property — so `init_settings()` alone leaves the live object on the
			 * format it booted with, and a test that switched the setting silently
			 * kept rendering the old format.
			 */
			$email->email_type = $email->get_option( 'email_type', 'html' );
		}
	}

	/**
	 * The LIVE registered email object.
	 *
	 * From `WC()->mailer()->get_emails()`, never a fresh instance: a fresh one
	 * carries none of the merchant's settings, and the singleton-isolation risk
	 * this architecture accepts only exists on the shared object.
	 *
	 * @return Custom_Email|null
	 */
	protected function live_email(): ?Custom_Email {
		$emails = WC()->mailer()->get_emails();
		$email  = $emails[ EmailIdentity::REGISTRY_KEY ] ?? null;

		return $email instanceof Custom_Email ? $email : null;
	}

	/**
	 * An orchestrator with a fresh item resolver, so no test inherits another's
	 * product cache.
	 *
	 * @return Orchestrator
	 */
	protected function orchestrator(): Orchestrator {
		return new Orchestrator(
			$this->rules,
			new RuleMatcher( $this->rules, new ItemResolver() ),
			new DeliveryLogger( $this->deliveries, $this->details )
		);
	}

	/**
	 * A rule that sends to the customer, matching one product.
	 *
	 * @param int   $product_id Product to target.
	 * @param array $overrides  Rule fields to replace.
	 * @return int Rule id.
	 */
	protected function make_sending_rule( int $product_id, array $overrides = array() ): int {
		return $this->make_rule(
			array_merge(
				array(
					'name'          => 'WCEP delivery fixture',
					'targeting'     => array( 'include' => array( 'products' => array( $product_id ) ) ),
					'recipients'    => array( 'to' => array( 'customer' ) ),
					'subject'       => 'Care guide for your order',
					'heading'       => 'Looking after it',
					'content'       => '<p>Hand wash only.</p>',
					'delivery_mode' => 'separate',
				),
				$overrides
			)
		);
	}

	/**
	 * The tombstone for one rule under one identity, or null.
	 *
	 * @param int    $order_id Order id.
	 * @param int    $rule_id  Rule id.
	 * @param string $identity Trigger identity.
	 * @return array|null
	 */
	protected function tombstone( int $order_id, int $rule_id, string $identity ): ?array {
		return $this->deliveries->find( $order_id, $rule_id, DeliveryLogger::MODE, $identity );
	}

	/**
	 * Detail rows for one tombstone.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return array[]
	 */
	protected function detail_rows( int $delivery_id ): array {
		return $this->details->find_for_delivery( $delivery_id );
	}

	/**
	 * Every tombstone this test's order produced.
	 *
	 * @param int $order_id Order id.
	 * @return array[]
	 */
	protected function tombstones_for( int $order_id ): array {
		$rows = array();

		foreach ( $this->deliveries->find_for_order( $order_id ) as $row ) {
			$rows[] = $row;
			$this->track_delivery( (int) $row['id'] );
		}

		return $rows;
	}

	/**
	 * Assert the number of messages captured so far.
	 *
	 * @param int    $expected Expected count.
	 * @param string $message  Failure message.
	 * @return void
	 */
	protected function assertMailCount( int $expected, string $message = '' ): void {
		$this->assertCount(
			$expected,
			$this->captured_mail,
			'' !== $message ? $message : 'Unexpected number of messages sent.'
		);
	}

	/**
	 * The most recently captured message.
	 *
	 * @return array|null
	 */
	protected function last_mail(): ?array {
		if ( array() === $this->captured_mail ) {
			return null;
		}

		return $this->captured_mail[ count( $this->captured_mail ) - 1 ];
	}

	/**
	 * The headers of a captured message as one string.
	 *
	 * @param array $mail Captured message.
	 * @return string
	 */
	protected function headers_of( array $mail ): string {
		$headers = $mail['headers'] ?? '';

		return is_array( $headers ) ? implode( "\n", $headers ) : (string) $headers;
	}
}
