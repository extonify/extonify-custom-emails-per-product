<?php
/**
 * Insert-rule storage validation (ADR-0013 §2).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

/**
 * `native_email_id` is the single source of truth for an insert rule, and the
 * repository refuses anything that would make a rule silently unable to fire.
 */
final class InsertRuleStorageTest extends MatchingTestCase {

	/**
	 * An insert rule stores EMPTY trigger fields and skips trigger validation.
	 *
	 * Trigger emptiness is what makes phase isolation structural rather than
	 * conventional: `find_active_for_trigger()` refuses an empty type before it
	 * queries, so no insert rule can ever be handed to the separate path.
	 *
	 * @return void
	 */
	public function test_an_insert_rule_stores_empty_trigger_fields() {
		$product_id = $this->make_simple_product( 'WCEP Storage Trigger' );

		$rule_id = $this->make_rule(
			array(
				'delivery_mode'   => 'insert',
				'native_email_id' => 'customer_processing_order',
				// Deliberately supplied, and deliberately ignored.
				'trigger_type'    => 'status',
				'trigger_value'   => 'completed',
				'targeting'       => array( 'include' => array( 'products' => array( $product_id ) ) ),
			)
		);

		$row = $this->rules->find( $rule_id );

		$this->assertSame( 'insert', $row['delivery_mode'] );
		$this->assertSame( '', $row['trigger_type'], 'An insert rule stored a trigger type.' );
		$this->assertSame( '', $row['trigger_value'], 'An insert rule stored a trigger value.' );
		$this->assertSame( 'customer_processing_order', $row['native_email_id'] );

		// And it is unreachable from the separate path, by construction.
		$this->assertSame( array(), $this->rules->find_active_for_trigger( 'status', 'completed' ) );
		$this->assertSame( array(), $this->rules->find_active_for_trigger( '', '' ) );
	}

	/**
	 * An insert rule with a NON-ZERO delay is REFUSED, not silently coerced.
	 *
	 * There is no way to insert content into an email that is already sending,
	 * seven days from now. Coercing the delay to zero would leave the merchant
	 * with a rule whose editor says seven days and whose behaviour says none.
	 *
	 * @return void
	 */
	public function test_an_insert_rule_with_a_delay_is_refused() {
		$refused = $this->rules->insert(
			array(
				'name'            => 'delayed insert',
				'status'          => 'active',
				'delivery_mode'   => 'insert',
				'native_email_id' => 'customer_processing_order',
				'delay_seconds'   => 604800,
			)
		);

		$this->assertSame( 0, $refused, 'An insert rule with a delay was stored.' );

		// And an existing insert rule cannot be given one by update.
		$rule_id = $this->make_rule(
			array(
				'delivery_mode'   => 'insert',
				'native_email_id' => 'customer_processing_order',
			)
		);

		$this->assertFalse(
			$this->rules->update( $rule_id, array( 'delay_seconds' => 3600 ) ),
			'An insert rule was given a delay by update.'
		);

		$this->assertSame( 0, (int) $this->rules->find( $rule_id )['delay_seconds'] );

		// A SEPARATE rule with a delay is still perfectly legal — it belongs to
		// Prompt 6, and refusing it here would be the wrong boundary.
		$delayed_separate = $this->rules->insert(
			array(
				'name'          => 'delayed separate',
				'status'        => 'active',
				'delivery_mode' => 'separate',
				'trigger_type'  => 'status',
				'trigger_value' => 'completed',
				'delay_seconds' => 604800,
			)
		);

		$this->assertGreaterThan( 0, $delayed_separate );
		$this->track_rule( $delayed_separate );
	}

	/**
	 * An insert rule with an EMPTY `native_email_id` is refused: it targets no
	 * email, can never fire, and can never say why.
	 *
	 * @return void
	 */
	public function test_an_insert_rule_without_a_native_email_id_is_refused() {
		$this->assertSame(
			0,
			$this->rules->insert(
				array(
					'name'          => 'no target',
					'status'        => 'active',
					'delivery_mode' => 'insert',
				)
			),
			'An insert rule with no native_email_id was stored.'
		);

		$this->assertSame(
			0,
			$this->rules->insert(
				array(
					'name'            => 'blank target',
					'status'          => 'active',
					'delivery_mode'   => 'insert',
					'native_email_id' => '   ',
				)
			),
			'An insert rule with a blank native_email_id was stored.'
		);
	}

	/**
	 * A `native_email_id` that WooCommerce does not have is refused when the
	 * mailer is available to say so.
	 *
	 * @return void
	 */
	public function test_an_unregistered_native_email_id_is_refused() {
		WC()->mailer();

		$this->assertSame(
			0,
			$this->rules->insert(
				array(
					'name'            => 'unknown email',
					'status'          => 'active',
					'delivery_mode'   => 'insert',
					'native_email_id' => 'no_such_woocommerce_email',
				)
			),
			'An insert rule targeting an email WooCommerce does not have was stored.'
		);
	}

	/**
	 * `find_active_for_native_email()` returns active, undelayed insert rules for
	 * one email — and nothing else.
	 *
	 * @return void
	 */
	public function test_the_fetch_returns_only_this_phase_s_rules() {
		$product_id = $this->make_simple_product( 'WCEP Storage Fetch' );

		$targeting = array( 'include' => array( 'products' => array( $product_id ) ) );

		$wanted = $this->make_rule(
			array(
				'name'            => 'wanted',
				'delivery_mode'   => 'insert',
				'native_email_id' => 'customer_processing_order',
				'targeting'       => $targeting,
			)
		);

		$this->make_rule(
			array(
				'name'            => 'other email',
				'delivery_mode'   => 'insert',
				'native_email_id' => 'customer_completed_order',
				'targeting'       => $targeting,
			)
		);

		$this->make_rule(
			array(
				'name'            => 'inactive',
				'status'          => 'inactive',
				'delivery_mode'   => 'insert',
				'native_email_id' => 'customer_processing_order',
				'targeting'       => $targeting,
			)
		);

		$this->make_rule(
			array(
				'name'          => 'separate',
				'delivery_mode' => 'separate',
				'trigger_type'  => 'status',
				'trigger_value' => 'processing',
				'targeting'     => $targeting,
			)
		);

		$found = array();
		foreach ( $this->rules->find_active_for_native_email( 'customer_processing_order' ) as $row ) {
			if ( in_array( (int) $row['id'], $this->rule_ids, true ) ) {
				$found[] = (int) $row['id'];
			}
		}

		$this->assertSame( array( $wanted ), $found );

		$this->assertSame( array(), $this->rules->find_active_for_native_email( '' ) );
	}

	/**
	 * 5A-3a. CONVERTING A SEPARATE RULE TO INSERT CLEARS BOTH TRIGGER FIELDS,
	 * even though the caller never mentioned them (ADR-0013 §2).
	 *
	 * `sanitize()` writes only the keys a partial update supplies, so the
	 * resolved-empty trigger was computed and then dropped on the floor: the row
	 * kept `status`/`completed` and advertised a trigger the engine does not
	 * consult.
	 *
	 * @return void
	 */
	public function test_converting_a_separate_rule_to_insert_clears_the_trigger_fields() {
		$product_id = $this->make_simple_product( 'WCEP Conversion' );

		$rule_id = $this->make_rule(
			array(
				'name'          => 'starts separate',
				'delivery_mode' => 'separate',
				'trigger_type'  => 'status',
				'trigger_value' => 'completed',
				'targeting'     => array( 'include' => array( 'products' => array( $product_id ) ) ),
			)
		);

		$before = $this->rules->find( $rule_id );
		$this->assertSame( 'status', $before['trigger_type'] );
		$this->assertSame( 'completed', $before['trigger_value'] );

		// A PARTIAL update: only the mode and the target are named.
		$this->assertTrue(
			$this->rules->update(
				$rule_id,
				array(
					'delivery_mode'   => 'insert',
					'native_email_id' => 'customer_processing_order',
				)
			)
		);

		$after = $this->rules->find( $rule_id );

		$this->assertSame( 'insert', $after['delivery_mode'] );
		$this->assertSame( '', $after['trigger_type'], 'The converted rule kept its trigger type.' );
		$this->assertSame( '', $after['trigger_value'], 'The converted rule kept its trigger value.' );
		$this->assertSame( 0, (int) $after['delay_seconds'] );
		$this->assertSame( 'customer_processing_order', $after['native_email_id'] );

		// And it is gone from the trigger fetch it used to answer.
		foreach ( $this->rules->find_active_for_trigger( 'status', 'completed' ) as $row ) {
			$this->assertNotSame( $rule_id, (int) $row['id'], 'The converted rule is still reachable from the separate path.' );
		}

		// While the insert fetch now finds it.
		$found = array();
		foreach ( $this->rules->find_active_for_native_email( 'customer_processing_order' ) as $row ) {
			$found[] = (int) $row['id'];
		}
		$this->assertContains( $rule_id, $found );
	}

	/**
	 * 5A-3b. A MALFORMED `native_email_id` IS REFUSED, NOT REPAIRED.
	 *
	 * `sanitize_key()` ran before validation, so `customer_processing_order!` was
	 * repaired into a valid, registered id and stored — the rule then targeted an
	 * email nobody chose. The identical coercion shape Prompt 3B removed from
	 * trigger values, on a new column.
	 *
	 * @return void
	 */
	public function test_a_malformed_native_email_id_is_refused_not_repaired() {
		WC()->mailer();

		$refused = array(
			'trailing punctuation' => 'customer_processing_order!',
			'uppercase'            => 'CUSTOMER_PROCESSING_ORDER',
			'whitespace-wrapped'   => ' customer_processing_order ',
			'tag-bearing'          => '<b>customer_processing_order</b>',
			'percent-encoded'      => 'customer_processing_order%20',
		);

		$table = \Extonify\WCEP\Install\Migrator::table( 'rules' );

		global $wpdb;

		foreach ( $refused as $label => $value ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- row-count probe of the plugin-owned rules table inside the test suite.
			$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

			$this->assertSame(
				0,
				$this->rules->insert(
					array(
						'name'            => 'malformed ' . $label,
						'status'          => 'active',
						'delivery_mode'   => 'insert',
						'native_email_id' => $value,
					)
				),
				'A ' . $label . ' native_email_id was stored.'
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- row-count probe of the plugin-owned rules table inside the test suite.
			$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

			$this->assertSame( $before, $after, 'A refused ' . $label . ' write still touched the table.' );
		}

		// An EXACT, registered id is accepted.
		$accepted = $this->make_rule(
			array(
				'name'            => 'exact id',
				'delivery_mode'   => 'insert',
				'native_email_id' => 'customer_processing_order',
			)
		);

		$this->assertSame( 'customer_processing_order', $this->rules->find( $accepted )['native_email_id'] );

		// A stored rule cannot be given a malformed target by update either, and
		// the row is left exactly as it was.
		$this->assertFalse(
			$this->rules->update( $accepted, array( 'native_email_id' => 'customer_processing_order!' ) ),
			'A malformed native_email_id was accepted by update.'
		);

		$this->assertSame( 'customer_processing_order', $this->rules->find( $accepted )['native_email_id'] );

		// And the read side refuses exactly what the write side refuses, rather
		// than repairing a lookup into one that matches rows nobody asked for.
		$this->assertSame( array(), $this->rules->find_active_for_native_email( 'CUSTOMER_PROCESSING_ORDER' ) );
		$this->assertSame( array(), $this->rules->find_active_for_native_email( 'customer_processing_order!' ) );

		fwrite(
			STDERR,
			"\n[5A item 3b] refused native_email_id values: " . implode( ', ', array_keys( $refused ) )
			. "; exact `customer_processing_order` accepted\n"
		);
	}
}
