<?php
/**
 * Inherited WC_Email state and the global mail filters (ADR-0012 §11c, §11d).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Email\Custom_Email;
use Extonify\WCEP\Email\EmailIdentity;

/**
 * A shared object can restore every field it DECLARES and still corrupt the rest
 * of the request through its PARENT's state or through the global hook registry.
 *
 * Both are covered here, because neither is visible to an audit that only walks
 * this plugin's own properties:
 *
 *   - `WC_Email::send()` registers three `wp_mail`-wide filters and removes them
 *     WITHOUT a `try`/`finally`, so a throwing mail plugin leaves this store's
 *     From address, From name and content type attached to every later message
 *     in the request;
 *   - `WC_Email::$sending` is mutated during a delivery by `get_content()` and
 *     `handle_multipart()`, and is not one of this plugin's declared fields.
 */
final class ParentStateTest extends DeliveryTestCase {

	/**
	 * Filters registered by a test, removed on teardown.
	 *
	 * @var array[]
	 */
	private $hooks = array();

	/**
	 * Remove anything a test hooked, and make certain no mail filter survives a
	 * failing test into the next one.
	 *
	 * @after
	 * @return void
	 */
	protected function tear_down_parent_state() {
		foreach ( $this->hooks as $hook ) {
			list( $tag, $callback, $priority ) = $hook;
			remove_filter( $tag, $callback, $priority );
		}

		$this->hooks = array();

		$email = $this->live_email();
		if ( null !== $email ) {
			foreach ( Custom_Email::MAIL_FILTERS as $tag => $method ) {
				remove_filter( $tag, array( $email, $method ) );
			}
		}
	}

	/**
	 * Register a filter and remember it for teardown.
	 *
	 * @param string   $tag      Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted arguments.
	 * @return void
	 */
	private function hook( string $tag, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $tag, $callback, $priority, $args );
		$this->hooks[] = array( $tag, $callback, $priority );
	}

	/**
	 * Which of WooCommerce's three mail filters currently carry the LIVE email
	 * object's callbacks.
	 *
	 * Read from the filter registry, never from object fields — the whole point
	 * is that the registry is state this plugin does not own.
	 *
	 * @return string[] Hook names still attached.
	 */
	private function attached_mail_filters(): array {
		$email    = $this->live_email();
		$attached = array();

		foreach ( Custom_Email::MAIL_FILTERS as $tag => $method ) {
			if ( false !== has_filter( $tag, array( $email, $method ) ) ) {
				$attached[] = $tag;
			}
		}

		return $attached;
	}

	/**
	 * 1. A SEND THAT THROWS MUST NOT LEAVE THIS STORE'S SENDER IDENTITY BOLTED
	 *    ONTO THE REST OF THE REQUEST (ADR-0012 §11d).
	 *
	 * Driven through the real `woocommerce_order_status_changed` hook with a
	 * throwing `pre_wp_mail` filter — the same fixture ADR-0012 §3's containment
	 * uses, because containment is precisely what makes this leak reachable: a
	 * core WooCommerce email would have died with the exception, this plugin
	 * survives it and keeps serving the request.
	 *
	 * @return void
	 */
	public function test_a_throwing_send_leaves_no_mail_filter_behind() {
		$product_id = $this->make_simple_product( 'WCEP Filter Leak' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule( $product_id, array( 'trigger_value' => 'processing' ) );

		$this->assertSame( array(), $this->attached_mail_filters(), 'A mail filter was attached before the test began.' );

		$thrower = static function () {
			throw new \RuntimeException( 'SMTP plugin exploded mid-send' );
		};

		// Priority 0: ahead of the capture at priority 1, so the throw really
		// happens instead of the capture short-circuiting first.
		add_filter( 'pre_wp_mail', $thrower, 0 );

		try {
			do_action( 'woocommerce_order_status_changed', $order_id, 'pending', 'processing', $order );
		} finally {
			remove_filter( 'pre_wp_mail', $thrower, 0 );
		}

		// The Prompt 4a containment behaviour is unchanged.
		$this->assertMailCount( 0, 'A throwing send still delivered something.' );

		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:processing' );
		$this->assertNotNull( $tombstone, 'The claim never happened, so this proves nothing about the throw.' );
		$this->track_delivery( (int) $tombstone['id'] );
		$this->assertSame( 'failed', $tombstone['final_status'] );

		// THE ASSERTION THIS TEST EXISTS FOR, read from the filter registry.
		$this->assertSame(
			array(),
			$this->attached_mail_filters(),
			'A thrown send left WooCommerce mail filters attached for the rest of the request.'
		);
	}

	/**
	 * 1b. AND THE PROOF THAT IT MATTERS: an unrelated `wp_mail()` after the
	 *     thrown send is untouched.
	 *
	 * Asserting the registry is empty is necessary but not sufficient — this is
	 * the observable consequence a merchant would actually have seen: a
	 * WordPress password reset going out as `text/html` from the store's address.
	 *
	 * @return void
	 */
	public function test_an_unrelated_wp_mail_after_a_thrown_send_is_unaffected() {
		$product_id = $this->make_simple_product( 'WCEP Filter Leak Consequence' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$this->make_sending_rule( $product_id, array( 'trigger_value' => 'processing' ) );

		// Give the store a sender identity that would be unmistakable if it
		// leaked onto somebody else's message.
		$from_backup      = get_option( 'woocommerce_email_from_address', null );
		$from_name_backup = get_option( 'woocommerce_email_from_name', null );
		update_option( 'woocommerce_email_from_address', 'leak-canary@example.test' );
		update_option( 'woocommerce_email_from_name', 'Leak Canary Store' );

		$thrower = static function () {
			throw new \RuntimeException( 'SMTP plugin exploded mid-send' );
		};

		$observed = array();

		// Observe what a LATER, unrelated wp_mail() would resolve. `pre_wp_mail`
		// short-circuits before WordPress applies these, so they are applied
		// here from inside it — the same state wp_mail() would have seen.
		$this->hook(
			'pre_wp_mail',
			static function ( $short_circuit, $atts = array() ) use ( &$observed ) {
				if ( is_array( $atts ) && isset( $atts['subject'] ) && 'Unrelated later message' === $atts['subject'] ) {
					$observed['from']         = apply_filters( 'wp_mail_from', 'wordpress@example.test' );
					$observed['from_name']    = apply_filters( 'wp_mail_from_name', 'WordPress' );
					$observed['content_type'] = apply_filters( 'wp_mail_content_type', 'text/plain' );
				}
				return $short_circuit;
			},
			2,
			2
		);

		try {
			add_filter( 'pre_wp_mail', $thrower, 0 );
			try {
				do_action( 'woocommerce_order_status_changed', $order_id, 'pending', 'processing', $order );
			} finally {
				remove_filter( 'pre_wp_mail', $thrower, 0 );
			}

			// Somebody else's message, later in the same request.
			wp_mail( 'someone@example.test', 'Unrelated later message', 'Body.' );
		} finally {
			if ( null === $from_backup ) {
				delete_option( 'woocommerce_email_from_address' );
			} else {
				update_option( 'woocommerce_email_from_address', $from_backup );
			}
			if ( null === $from_name_backup ) {
				delete_option( 'woocommerce_email_from_name' );
			} else {
				update_option( 'woocommerce_email_from_name', $from_name_backup );
			}
		}

		$this->assertArrayHasKey( 'from', $observed, 'The unrelated message never went through wp_mail().' );

		$this->assertSame(
			'wordpress@example.test',
			$observed['from'],
			"An unrelated message was sent from the store's address after a thrown delivery."
		);
		$this->assertSame(
			'WordPress',
			$observed['from_name'],
			"An unrelated message was sent under the store's name after a thrown delivery."
		);
		$this->assertSame(
			'text/plain',
			$observed['content_type'],
			'An unrelated plain-text message was switched to the store email content type.'
		);

		fwrite(
			STDERR,
			"\n[4d item 1] after a thrown send — attached mail filters: "
			. ( array() === $this->attached_mail_filters() ? 'none' : implode( ', ', $this->attached_mail_filters() ) )
			. "; later wp_mail() resolved from={$observed['from']} name=\"{$observed['from_name']}\" type={$observed['content_type']}\n"
		);
	}

	/**
	 * 1c. A CLEAN send leaves the registry exactly as it found it too — so the
	 *     assertions above are about the throw, not about a wrapper that removes
	 *     things unconditionally.
	 *
	 * @return void
	 */
	public function test_a_clean_send_also_leaves_no_mail_filter_behind() {
		$product_id = $this->make_simple_product( 'WCEP Filter Clean' );
		$order      = $this->make_order_with( array( $product_id ) );

		$this->make_sending_rule( $product_id );

		$this->orchestrator()->run( $order, \Extonify\WCEP\Domain\TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 1 );
		$this->tombstones_for( (int) $order->get_id() );

		$this->assertSame( array(), $this->attached_mail_filters() );
	}

	/**
	 * 1d. THE THREE FILTERS ARE LIVE DURING THE SEND — otherwise "they are gone
	 *     afterwards" would be satisfied by never registering them at all, and
	 *     the sender-identity inheritance proven in Prompt 4b would be lost.
	 *
	 * @return void
	 */
	public function test_the_mail_filters_are_attached_while_sending() {
		$product_id = $this->make_simple_product( 'WCEP Filter Live' );
		$order      = $this->make_order_with( array( $product_id ) );

		$this->make_sending_rule( $product_id );

		$during = array();

		$this->hook(
			'pre_wp_mail',
			function ( $short_circuit ) use ( &$during ) {
				$during = $this->attached_mail_filters();
				return $short_circuit;
			},
			2,
			2
		);

		$this->orchestrator()->run( $order, \Extonify\WCEP\Domain\TriggerEvent::status( 'completed' ) );

		$this->tombstones_for( (int) $order->get_id() );

		sort( $during );
		$expected = array_keys( Custom_Email::MAIL_FILTERS );
		sort( $expected );

		$this->assertSame( $expected, $during, 'WooCommerce sender identity was not applied during the send.' );
	}

	/**
	 * 2. NESTED SENDER FILTERS: an inner delivery completing inside the outer
	 *    one must not strip the outer send's registrations.
	 *
	 * `WC_Email::send()` removes the three filters unconditionally when it
	 * finishes, so a nested send's normal completion is enough to disarm the
	 * outer one — no exception required.
	 *
	 * @return void
	 */
	public function test_a_nested_send_does_not_disarm_the_outer_sender_filters() {
		foreach ( array( 'new_order', 'customer_processing_order', 'customer_completed_order' ) as $core ) {
			$this->hook( 'woocommerce_email_enabled_' . $core, '__return_false', 10, 3 );
		}

		$outer_product = $this->make_simple_product( 'WCEP Nested Filters Outer' );
		$inner_product = $this->make_simple_product( 'WCEP Nested Filters Inner' );

		$outer_order = $this->make_order_with( array( $outer_product ) );
		$outer_id    = (int) $outer_order->get_id();

		$inner_order = $this->make_order_with( array( $inner_product ) );
		$inner_id    = (int) $inner_order->get_id();

		$this->make_sending_rule( $outer_product, array( 'subject' => 'OUTER filters' ) );
		$this->make_sending_rule(
			$inner_product,
			array(
				'trigger_value' => 'processing',
				'subject'       => 'INNER filters',
			)
		);

		$orchestrator = $this->orchestrator();

		$this->hook(
			'woocommerce_order_status_changed',
			static function ( $order_id, $from = '', $to = '' ) use ( $orchestrator, $inner_id ) {
				if ( (int) $order_id === $inner_id ) {
					$orchestrator->handle_status_change( (int) $order_id, (string) $from, (string) $to );
				}
			},
			10,
			3
		);

		$fired = false;
		$after = array();

		// Re-enter from the OUTER send's own content filter, then look at what
		// the outer send still has once the inner one has finished.
		$this->hook(
			'woocommerce_mail_content',
			function ( $message ) use ( &$fired, &$after, $inner_id ) {
				if ( ! $fired ) {
					$fired = true;
					wc_get_order( $inner_id )->update_status( 'processing', 'nested filter fixture' );
					$after = $this->attached_mail_filters();
				}
				return $message;
			},
			1,
			1
		);

		$orchestrator->run( wc_get_order( $outer_id ), \Extonify\WCEP\Domain\TriggerEvent::status( 'completed' ) );

		$this->assertTrue( $fired, 'The nested send never happened.' );
		$this->assertMailCount( 2 );

		sort( $after );
		$expected = array_keys( Custom_Email::MAIL_FILTERS );
		sort( $expected );

		$this->assertSame(
			$expected,
			$after,
			'A nested send removed the OUTER send\'s sender filters, so the outer message lost the store identity.'
		);

		// And once the top-level call returns, none of them survives.
		$this->assertSame( array(), $this->attached_mail_filters() );

		foreach ( array( $outer_id, $inner_id ) as $id ) {
			$this->tombstones_for( $id );
		}

		fwrite(
			STDERR,
			"\n[4d item 2] outer send after a nested delivery completed — attached: " . implode( ', ', $after ) . "\n"
		);
	}

	/**
	 * 3. `WC_Email::$sending` IS PART OF THE FRAME (ADR-0012 §11c).
	 *
	 * HOW THE MULTIPART HANDLER IS MADE TO RUN, AND WHAT THAT DOES AND DOES NOT
	 * PROVE. WooCommerce calls `handle_multipart()` from `phpmailer_init`. This
	 * suite intercepts every message at `pre_wp_mail`, which short-circuits
	 * `wp_mail()` long before `phpmailer_init` — so WooCommerce's real multipart
	 * path never runs anywhere in this suite, and a test that simply nested two
	 * sends would pass whether or not `sending` is framed. That is a true
	 * observation about our own harness, not about the plugin.
	 *
	 * So the inner delivery calls `handle_multipart()` itself, at the point
	 * `phpmailer_init` would have fired, with a PHPMailer stand-in.
	 *
	 * WHAT THIS PROVES: `handle_multipart()` — WooCommerce's own unmodified
	 * method — clears the shared `sending` flag during the inner delivery, and
	 * the outer delivery still gets it back, so the outer's own multipart pass
	 * generates its plain-text alternative instead of returning early.
	 * WHAT IT DOES NOT PROVE: that core's `phpmailer_init` wiring fires as
	 * expected. That is WooCommerce's, untouched by this plugin, and unreachable
	 * while mail is intercepted.
	 *
	 * @return void
	 */
	public function test_the_sending_flag_survives_a_nested_delivery() {
		$email = $this->live_email();

		$this->assertArrayHasKey(
			'sending',
			Custom_Email::RUNTIME_FIELDS,
			'The inherited `sending` flag is not part of the captured frame.'
		);

		// An OUTER delivery that has reached its content: WooCommerce's
		// `get_content()` has set the flag. Written directly, so the fixture
		// holds whether or not the field is framed.
		$email->recipient = 'outer@example.test';
		$email->sending   = true;

		$inner_multipart = null;

		// The inner delivery's multipart pass, at the point `phpmailer_init`
		// would fire. `handle_multipart()` is WooCommerce's own method, called
		// unmodified.
		$this->hook(
			'woocommerce_mail_content',
			static function ( $message ) use ( $email, &$inner_multipart ) {
				if ( null === $inner_multipart ) {
					$mailer          = new \stdClass();
					$mailer->AltBody = '';
					$email->handle_multipart( $mailer );
					$inner_multipart = $email->sending;
				}
				return $message;
			},
			1,
			1
		);

		$email->trigger(
			array(
				'recipient' => 'inner@example.test',
				'subject'   => 'Inner multipart',
				'content'   => '<p>Inner body.</p>',
			)
		);

		$this->assertFalse(
			$inner_multipart,
			'The fixture never ran the inner multipart pass, so this proves nothing.'
		);

		// THE ASSERTIONS THIS TEST EXISTS FOR.
		$this->assertTrue(
			$email->sending,
			'A nested delivery switched the OUTER send off; its plain-text alternative would never be generated.'
		);
		$this->assertSame( 'outer@example.test', $email->recipient, 'The outer frame was not restored.' );

		// And the outer's own multipart pass consequently still does its work.
		$email->delivery_content = '<p>Outer body for the plain-text alternative.</p>';

		$mailer          = new \stdClass();
		$mailer->AltBody = 'untouched';

		$email->handle_multipart( $mailer );

		$this->assertNotSame(
			'untouched',
			$mailer->AltBody,
			'handle_multipart() returned early because `sending` had been cleared by the nested delivery.'
		);
		$this->assertFalse( $email->sending, 'handle_multipart() should clear the flag it consumed.' );

		$email->reset_runtime_state();
	}

	/**
	 * 3b. A fresh delivery starts with `sending` false, so it cannot inherit a
	 *     previous delivery's flag.
	 *
	 * @return void
	 */
	public function test_a_new_delivery_starts_not_sending() {
		$email = $this->live_email();

		$email->restore_runtime_state( array( 'sending' => true ) );

		$observed = null;

		$this->hook(
			'pre_wp_mail',
			static function ( $short_circuit ) use ( $email, &$observed ) {
				// By this point `get_content()` has run, so WooCommerce itself
				// has set the flag for THIS delivery.
				$observed = $email->sending;
				return $short_circuit;
			},
			2,
			2
		);

		$email->trigger(
			array(
				'recipient' => 'fresh@example.test',
				'subject'   => 'Fresh delivery',
				'content'   => '<p>Body.</p>',
			)
		);

		$this->assertTrue( $observed, 'WooCommerce did not set `sending` for this delivery.' );
		$this->assertTrue( $email->sending, 'The captured outer value was not restored.' );

		$email->reset_runtime_state();
		$this->assertFalse( $email->sending );
	}

	/**
	 * 4. COMPLETENESS GUARD (ADR-0012 §11c): every property on the shared email
	 *    object is either part of the captured frame or explicitly per-request.
	 *
	 * This is the check the Prompt 4c backlog recorded as owed. A per-delivery
	 * property added later — by this plugin or by a WooCommerce upgrade — is
	 * captured by none of capture, apply or restore and leaks silently between
	 * deliveries. Failing here forces a human decision instead.
	 *
	 * @return void
	 */
	public function test_every_property_is_either_framed_or_declared_per_request() {
		$framed = array_keys( Custom_Email::RUNTIME_FIELDS );

		/*
		 * PER-REQUEST BY EXPLICIT DECISION (ADR-0012 §11c). Everything here is
		 * assigned in a constructor, in `init_form_fields()`, or never — not
		 * during a delivery. `mime_boundary` and `mime_boundary_header` are
		 * declared by WC_Email and assigned NOWHERE in WC 10.9.4; the
		 * behavioural assertion below is what guards that, because a
		 * declaration audit cannot see a future assignment.
		 */
		$per_request = array(
			// Identity and configuration.
			'id',
			'title',
			'description',
			'enabled',
			'email_type',
			'email_group',
			'heading',
			'subject',
			// Templates.
			'template_plain',
			'template_html',
			'template_block',
			'template_base',
			'template_block_content',
			// Plain-text conversion tables and placeholder plumbing.
			'plain_search',
			'plain_replace',
			'placeholders',
			'find',
			'replace',
			// Vestigial in WC 10.9.4: declared, never assigned.
			'mime_boundary',
			'mime_boundary_header',
			// Feature flags and services, resolved once in the constructor.
			'email_improvements_enabled',
			'block_email_editor_enabled',
			'personalizer',
			'manual',
			'customer_email',
			// WC_Settings_API plumbing.
			'plugin_id',
			'settings',
			'form_fields',
			'data',
			'errors',
			'sanitized_fields',
		);

		/*
		 * ⚠ A THIRD CATEGORY, AND IT IS NEITHER OF THE OTHER TWO ON PURPOSE (ADR-0012
		 * §11c, ADR-0020 §4b-i). These are OUTCOMES of one `WC_Email::send()` rather than
		 * inputs to one delivery or configuration set once per request:
		 *
		 *   - they are written in `Custom_Email::send()`'s `finally`, which runs AFTER
		 *     `trigger()` would restore a captured frame — so a `RUNTIME_FIELDS` entry
		 *     would be wiped before the caller could read it;
		 *   - and `trigger()` therefore clears them on ENTRY instead, so a delivery that
		 *     returns before reaching `send()` cannot report the PREVIOUS delivery's
		 *     answer. That is the same state-bleed rule the framed fields obey, applied
		 *     at the other end of the call.
		 *
		 * Last write wins, which is correct under nesting rather than in spite of it: an
		 * inner send completes — and writes — before the outer send's `finally` writes
		 * its own.
		 *
		 * ⚠ THIS LIST IS WHY THE TEST IS STILL A COMPLETENESS GUARD. Adding a property
		 * here is a decision somebody has to write down; leaving one out is a red test.
		 */
		$per_send_outcome = array(
			'lock_outcome',
			'lock_stack_residue',
		);

		$unclassified = array();

		foreach ( ( new \ReflectionClass( Custom_Email::class ) )->getProperties() as $property ) {
			if ( $property->isStatic() ) {
				continue;
			}

			$name = $property->getName();

			if ( in_array( $name, $framed, true )
				|| in_array( $name, $per_request, true )
				|| in_array( $name, $per_send_outcome, true ) ) {
				continue;
			}

			$unclassified[] = $name;
		}

		sort( $unclassified );

		$this->assertSame(
			array(),
			$unclassified,
			"Unclassified propert(ies) on the shared email object: " . implode( ', ', $unclassified )
			. '. Each must be added to Custom_Email::RUNTIME_FIELDS (per delivery), to this'
			. ' test\'s per-request allow-list, or to its per-send-outcome allow-list, with the'
			. ' reason recorded in ADR-0012 §11c.'
		);

		// Every framed field must actually exist, so a typo in RUNTIME_FIELDS
		// cannot silently frame nothing.
		foreach ( $framed as $field ) {
			$this->assertTrue(
				property_exists( Custom_Email::class, $field ),
				"RUNTIME_FIELDS names `{$field}`, which is not a property of the email object."
			);
		}
	}

	/**
	 * 4b. The two vestigial multipart-boundary properties really are untouched
	 *     by a delivery on this WooCommerce version.
	 *
	 * The allow-list above excludes them on the strength of a source audit; this
	 * asserts the behaviour, so a WooCommerce version that starts using them
	 * fails here rather than leaking.
	 *
	 * @return void
	 */
	public function test_the_multipart_boundary_properties_stay_untouched() {
		$product_id = $this->make_simple_product( 'WCEP Boundary' );
		$order      = $this->make_order_with( array( $product_id ) );

		$this->make_sending_rule( $product_id );

		$email = $this->live_email();

		$this->assertNull( $email->mime_boundary, 'mime_boundary was already set before the delivery.' );
		$this->assertNull( $email->mime_boundary_header, 'mime_boundary_header was already set before the delivery.' );

		$this->orchestrator()->run( $order, \Extonify\WCEP\Domain\TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 1 );
		$this->tombstones_for( (int) $order->get_id() );

		$this->assertNull(
			$email->mime_boundary,
			'WooCommerce assigned mime_boundary during a delivery; it must join the captured frame (ADR-0012 §11c).'
		);
		$this->assertNull(
			$email->mime_boundary_header,
			'WooCommerce assigned mime_boundary_header during a delivery; it must join the captured frame (ADR-0012 §11c).'
		);
	}

	/**
	 * 5. THE `AltBody` FINDING, asserted rather than assumed.
	 *
	 * `WC_Email::send()` clears PHPMailer's `AltBody` after the mail callback
	 * and skips that on a throw. It cannot leak, because `wp_mail()` empties
	 * both `Body` and `AltBody` BEFORE it fires `phpmailer_init` — the only hook
	 * that sets `AltBody`. This asserts the ordering that makes replicating the
	 * clearing unnecessary.
	 *
	 * @return void
	 */
	public function test_wp_mail_empties_alt_body_before_phpmailer_init() {
		$source = file_get_contents( ABSPATH . WPINC . '/pluggable.php' );

		$this->assertIsString( $source, 'Could not read wp-includes/pluggable.php.' );

		$reset = strpos( $source, '$phpmailer->AltBody = \'\';' );
		$init  = strpos( $source, "do_action_ref_array( 'phpmailer_init'" );

		$this->assertNotFalse( $reset, 'wp_mail() no longer empties AltBody; re-check ADR-0012 §11d.' );
		$this->assertNotFalse( $init, 'Could not locate phpmailer_init in wp_mail().' );

		$this->assertLessThan(
			$init,
			$reset,
			'wp_mail() now empties AltBody AFTER phpmailer_init, so a stale AltBody CAN leak '
			. 'and Custom_Email::send() must clear it (ADR-0012 §11d).'
		);

		// And the email id is still what the settings key depends on, so the
		// live object under test is the registered one.
		$this->assertSame( EmailIdentity::EMAIL_ID, $this->live_email()->id );
	}
}
