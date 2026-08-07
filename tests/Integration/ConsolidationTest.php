<?php
/**
 * CONSOLIDATION END TO END (ADR-0016).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\Consolidation;
use Extonify\WCEP\Delivery\PlaceholderResolver;
use Extonify\WCEP\Delivery\PlaceholderValues;
use Extonify\WCEP\Delivery\RunOutcome;
use Extonify\WCEP\Domain\Json;
use Extonify\WCEP\Domain\TriggerEvent;

/**
 * ONE DECISION, N MESSAGES, ONE TOMBSTONE.
 *
 * Every "an email was sent" assertion reads the `pre_wp_mail` capture — the actual
 * payload WooCommerce handed the mailer — never a return value or an internal flag.
 *
 * ⚠ THE PROPERTY THESE TESTS EXIST FOR (ADR-0016 §3). Consolidation is the first
 * feature that changes how many messages one delivery identity produces, so the
 * question is not only "did N messages go out" but "did N messages go out under ONE
 * claim, each about its own product, with the tombstone telling the truth about the
 * set". A fan-out that got the count right and the identity wrong would re-send to
 * every order on the next trigger.
 */
final class ConsolidationTest extends ScheduledDeliveryTestCase {

	/**
	 * Gate 25 rows collected as they are proved.
	 *
	 * @var string[]
	 */
	private $gate = array();

	/**
	 * Callbacks registered by a test, removed on teardown.
	 *
	 * @var array[]
	 */
	private $hooks = array();

	/**
	 * Print the gate 25 table once, after the last test in the class.
	 *
	 * @after
	 * @return void
	 */
	protected function report_gate_rows() {
		if ( array() !== $this->gate ) {
			fwrite( STDERR, "\n[8A / gates 25-26] " . implode( "\n[8A / gates 25-26] ", $this->gate ) . "\n" );
		}

		$this->gate = array();
	}

	/**
	 * Remove anything a test hooked.
	 *
	 * @after
	 * @return void
	 */
	protected function tear_down_consolidation_hooks() {
		foreach ( $this->hooks as $hook ) {
			list( $tag, $callback, $priority ) = $hook;
			remove_filter( $tag, $callback, $priority );
		}

		$this->hooks = array();
	}

	/**
	 * Register a callback and remember it for teardown.
	 *
	 * @param string   $tag      Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted arguments.
	 * @return void
	 */
	private function hook( string $tag, callable $callback, int $priority = 10, int $args = 4 ): void {
		add_filter( $tag, $callback, $priority, $args );
		$this->hooks[] = array( $tag, $callback, $priority );
	}

	/**
	 * A product with a known name and SKU, so a message can be attributed to it.
	 *
	 * @param string $label Short label, used for the name and the SKU.
	 * @return int Product id.
	 */
	private function labelled_product( string $label ): int {
		$product_id = $this->make_simple_product( 'WCEP ' . $label );

		$product = wc_get_product( $product_id );
		$product->set_sku( 'SKU-' . strtoupper( $label ) . '-' . $product_id );
		$product->save();

		return $product_id;
	}

	/**
	 * A separate-mode rule consolidating per product over several products.
	 *
	 * @param int[] $product_ids Products to target.
	 * @param array $overrides   Rule fields to replace.
	 * @return int Rule id.
	 */
	private function make_fanout_rule( array $product_ids, array $overrides = array() ): int {
		return $this->make_rule(
			array_merge(
				array(
					'name'          => 'WCEP consolidation fixture',
					'status'        => 'active',
					'delivery_mode' => 'separate',
					'trigger_type'  => 'status',
					'trigger_value' => 'completed',
					'consolidation' => Consolidation::PER_PRODUCT,
					'targeting'     => array( 'include' => array( 'products' => array_values( $product_ids ) ) ),
					'recipients'    => array( 'to' => array( 'customer' ) ),
					'subject'       => 'About {product_name}',
					'heading'       => 'Your {product_name}',
					'content'       => '<p>ONE=[{product_name}] SKU=[{product_sku}] ALL=[{product_names}]</p>',
				),
				$overrides
			)
		);
	}

	/**
	 * The captured subjects, in send order.
	 *
	 * @return string[]
	 */
	private function subjects(): array {
		return array_map(
			static function ( array $mail ): string {
				return (string) ( $mail['subject'] ?? '' );
			},
			$this->captured_mail
		);
	}

	/**
	 * One captured message's body.
	 *
	 * @param int $offset Zero-based send order.
	 * @return string
	 */
	private function body( int $offset ): string {
		return (string) ( $this->captured_mail[ $offset ]['message'] ?? '' );
	}

	/**
	 * The tombstone this order and rule produced, asserted to be the ONLY one.
	 *
	 * ⚠ THE SINGLE MOST IMPORTANT ASSERTION IN THIS FILE (ADR-0016 §3). One claim per
	 * fan-out, regardless of message count: if consolidation had grown the identity, a
	 * re-fired trigger would form unclaimed identities and re-send to customers who
	 * already had every message.
	 *
	 * @param int    $order_id Order id.
	 * @param int    $rule_id  Rule id.
	 * @param string $identity Trigger identity.
	 * @return array
	 */
	private function sole_tombstone( int $order_id, int $rule_id, string $identity = 'status:completed' ): array {
		$all = $this->tombstones_for( $order_id );

		$this->assertCount( 1, $all, '⚠ a fan-out consumed more than one delivery identity.' );

		$tombstone = $this->tombstone( $order_id, $rule_id, $identity );

		$this->assertNotNull( $tombstone, 'the fan-out never claimed its identity' );
		$this->track_delivery( (int) $tombstone['id'] );

		return $tombstone;
	}

	/**
	 * One detail row's decoded `snapshot`.
	 *
	 * `DeliveryDetailRepository::find_for_delivery()` hydrates the column, so this is
	 * already an array; the string branch is here so the helper reads correctly against
	 * a raw row too.
	 *
	 * @param array $row Detail row.
	 * @return array
	 */
	private function snapshot_of_row( array $row ): array {
		$snapshot = $row['snapshot'] ?? array();

		return is_array( $snapshot ) ? $snapshot : Json::decode( (string) $snapshot );
	}

	/**
	 * The `snapshot.consolidation` payload on one detail row.
	 *
	 * @param array $row Detail row.
	 * @return array
	 */
	private function consolidation_of( array $row ): array {
		return (array) ( $this->snapshot_of_row( $row )['consolidation'] ?? array() );
	}

	/**
	 * Write a `consolidation` value straight into the column, bypassing the repository.
	 *
	 * ⚠ THE ONLY WAY TO BUILD A LEGACY INVALID ROW NOW, and the fixture models an
	 * ORDINARY upgrade rather than sabotage: `daily`, `weekly` and `per_order` were
	 * LEGITIMATELY STORABLE from Prompt 5B to Prompt 8, so any store that used one has a
	 * row exactly like this with no direct SQL in its history. The repository refuses
	 * them now (ADR-0016 §1a), which is precisely why the fixture has to go round it.
	 *
	 * @param int    $rule_id Rule id.
	 * @param string $value   Raw column value.
	 * @return void
	 */
	private function force_raw_consolidation( int $rule_id, string $value ): void {
		global $wpdb;

		$updated = $wpdb->update(
			\Extonify\WCEP\Install\Migrator::table( 'rules' ),
			array( 'consolidation' => $value ),
			array( 'id' => $rule_id ),
			array( '%s' ),
			array( '%d' )
		);

		$this->assertNotFalse( $updated, 'Could not write the raw consolidation fixture.' );
		$this->assertSame(
			$value,
			(string) $this->rules->find( $rule_id )['consolidation'],
			'The raw consolidation fixture did not land.'
		);
	}

	/**
	 * Run one delivery with the store's email format switched, and return the body.
	 *
	 * ⚠ THE FORMAT IS A STORE SETTING, SO IT IS SWITCHED AT THE SETTING (ADR-0002).
	 * Reading `Custom_Email::$delivery_content_plain` after the fact cannot work — it is
	 * a `RUNTIME_FIELDS` member, so `trigger()`'s `finally` has already restored it
	 * (ADR-0012 §11a) — and the `pre_wp_mail` capture holds only whichever format
	 * WooCommerce actually rendered. So the plain body is observed by rendering in plain,
	 * exactly as `PlaceholderTest` does.
	 *
	 * @param string    $format `html` or `plain`.
	 * @param \WC_Order $order  Order to deliver for.
	 * @return string The captured body for that format.
	 */
	private function body_in_format( string $format, \WC_Order $order ): string {
		$before = count( $this->captured_mail );

		$this->set_email_setting( 'email_type', $format );

		try {
			$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );
		} finally {
			$this->set_email_setting( 'email_type', 'html' );
		}

		$this->assertCount( $before + 1, $this->captured_mail, "The {$format} delivery did not send exactly one message." );

		return $this->body( $before );
	}

	// -----------------------------------------------------------------------
	// TEST 1 — `none` IS UNCHANGED.
	// -----------------------------------------------------------------------

	/**
	 * 1. A `none` rule matching three products sends EXACTLY ONE message containing
	 *    all three, and writes no consolidation payload at all.
	 *
	 * ⚠ THE REGRESSION GUARD FOR EVERY DELIVERY THAT PREDATES THIS ADR. `none` is the
	 * default, so any change to its writes would change behaviour for every existing
	 * rule — which is why the snapshot assertion is here rather than only in the unit
	 * suite: a stray key in the diagnostic payload is a change to what every merchant's
	 * delivery history holds.
	 *
	 * @return void
	 */
	public function test_none_sends_one_message_containing_every_matched_product() {
		$products = array( $this->labelled_product( 'None A' ), $this->labelled_product( 'None B' ), $this->labelled_product( 'None C' ) );
		$order    = $this->delayed_order( $products );
		$order_id = (int) $order->get_id();

		$rule_id = $this->make_fanout_rule(
			$products,
			array(
				'consolidation' => Consolidation::NONE,
				'subject'       => 'Combined',
			)
		);

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 1, 'A `none` rule stopped sending exactly one message.' );

		$body = $this->body( 0 );

		foreach ( $products as $product_id ) {
			$this->assertStringContainsString(
				(string) wc_get_product( $product_id )->get_name(),
				$body,
				'The combined message lost a matched product.'
			);
		}

		$tombstone = $this->sole_tombstone( $order_id, $rule_id );
		$this->assertSame( 'sent', $tombstone['final_status'] );

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 1, $rows, 'A `none` delivery wrote more than one attempt row.' );
		$this->assertSame( 'sent', $rows[0]['state'] );
		$this->assertSame(
			array(),
			$this->consolidation_of( $rows[0] ),
			'⚠ a plain `none` rule gained a consolidation payload; its rows are no longer byte-identical.'
		);

		$this->gate[] = '`none` unchanged: 1 message, 1 tombstone, 1 attempt row, no consolidation payload';
	}

	// -----------------------------------------------------------------------
	// TEST 2 — THE FAN-OUT.
	// -----------------------------------------------------------------------

	/**
	 * 2 / gate 25. THREE MATCHED PRODUCTS PRODUCE THREE MESSAGES, ONE TOMBSTONE,
	 *              THREE ATTEMPT ROWS AND `final_status = sent`.
	 *
	 * @return void
	 */
	public function test_per_product_fans_out_into_one_message_per_product() {
		$products = array( $this->labelled_product( 'Alpha' ), $this->labelled_product( 'Beta' ), $this->labelled_product( 'Gamma' ) );
		$order    = $this->delayed_order( $products );
		$order_id = (int) $order->get_id();

		$rule_id = $this->make_fanout_rule( $products );

		$outcome = $this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 3, 'The fan-out did not send one message per matched product.' );

		// ORDERING IS DETERMINISTIC: line-item order (ADR-0016 §4).
		$this->assertSame(
			array( 'About WCEP Alpha', 'About WCEP Beta', 'About WCEP Gamma' ),
			$this->subjects(),
			'The fan-out sequence is not line-item order.'
		);

		// ONE CLAIM (ADR-0016 §3).
		$tombstone = $this->sole_tombstone( $order_id, $rule_id );
		$this->assertSame( 'sent', $tombstone['final_status'] );

		// THREE ATTEMPT ROWS, one per message, each `sent`.
		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 3, $rows );
		$this->assertSame( array( 'sent', 'sent', 'sent' ), array_column( $rows, 'state' ) );

		// AND EACH ROW NAMES ITS OWN UNIT AND ITS PLACE IN THE SET.
		$units = array();
		foreach ( $rows as $offset => $row ) {
			$payload = $this->consolidation_of( $row );

			$this->assertSame( Consolidation::PER_PRODUCT, $payload['requested'] );
			$this->assertSame( Consolidation::PER_PRODUCT, $payload['mode'] );
			$this->assertSame( $offset + 1, (int) $payload['index'] );
			$this->assertSame( 3, (int) $payload['count'] );
			$this->assertArrayNotHasKey( 'fallback', $payload, 'an uncapped fan-out recorded a fallback' );

			$units[] = (string) $payload['unit'];
		}

		$this->assertSame(
			array( 'product:' . $products[0], 'product:' . $products[1], 'product:' . $products[2] ),
			$units,
			'⚠ a message was attributed to another message\'s product.'
		);

		// ⚠ THE ATTEMPT COLUMN IS *NOT* THE FAN-OUT AXIS (ADR-0016 §5). The three
		// messages are ONE attempt at one decision, so they share attempt 1.
		$this->assertSame(
			array( 1, 1, 1 ),
			array_map( 'intval', array_column( $rows, 'attempt' ) ),
			'⚠ the fan-out axis leaked into the `attempt` column, which means resends.'
		);

		// The RUN reports one record for the rule, not one per message.
		$this->assertCount( 1, $outcome->records() );
		$this->assertSame( 1, $outcome->count_of( RunOutcome::SENT ) );
		$this->assertTrue( $outcome->is_fully_recorded() );

		$this->gate[] = 'fan-out: 3 messages, 1 tombstone, 3 attempt rows sharing attempt 1, final_status=sent';
	}

	// -----------------------------------------------------------------------
	// TEST 3 — QUANTITY AND DUPLICATE LINE ITEMS.
	// -----------------------------------------------------------------------

	/**
	 * 3 / gate 25. QUANTITY 5 AND TWO LINE ITEMS OF ONE PRODUCT PRODUCE **ONE**
	 *              MESSAGE FOR THAT PRODUCT (ADR-0011 §3, ADR-0016 §4).
	 *
	 * ⚠ THE CUSTOMER-FACING CASE. A rule that matched once must not become five emails
	 * because a customer bought five, and a re-added cart line is not a second product.
	 *
	 * @return void
	 */
	public function test_quantity_and_duplicate_line_items_produce_one_message_each() {
		$bulk  = $this->labelled_product( 'Bulk' );
		$other = $this->labelled_product( 'Other' );

		// Quantity 5 of `bulk`, then a SECOND line item of `bulk`, then one of `other`.
		$order = $this->delayed_order( array( array( $bulk, 5 ), array( $bulk, 2 ), $other ) );

		// Two distinct line items really exist, or this test proves nothing.
		$this->assertCount( 3, $order->get_items() );

		$order_id = (int) $order->get_id();
		$rule_id  = $this->make_fanout_rule( array( $bulk, $other ) );

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 2, '⚠ quantity or a duplicate line item multiplied the message count.' );
		$this->assertSame( array( 'About WCEP Bulk', 'About WCEP Other' ), $this->subjects() );

		$rows = $this->detail_rows( (int) $this->sole_tombstone( $order_id, $rule_id )['id'] );

		$this->assertSame(
			array( 'product:' . $bulk, 'product:' . $other ),
			array_column( array_map( array( $this, 'consolidation_of' ), $rows ), 'unit' )
		);
		$this->assertSame( array( 2, 2 ), array_map( 'intval', array_column( array_map( array( $this, 'consolidation_of' ), $rows ), 'count' ) ) );

		$this->gate[] = 'quantity 5 + a duplicate line item: 2 units, 2 messages — the count is unaffected';
	}

	// -----------------------------------------------------------------------
	// TEST 4 — VARIATIONS.
	// -----------------------------------------------------------------------

	/**
	 * 4 / gate 25. TWO VARIATIONS OF ONE PARENT PRODUCE TWO MESSAGES.
	 *
	 * @return void
	 */
	public function test_two_variations_of_one_parent_produce_two_messages() {
		$variable = $this->make_variable_product( 'WCEP Variable', array( 'Small', 'Large' ) );
		$order    = $this->delayed_order( array( $variable['variations'][0], $variable['variations'][1] ) );
		$order_id = (int) $order->get_id();

		$rule_id = $this->make_fanout_rule(
			array(),
			array(
				'targeting' => array( 'include' => array( 'products' => array( $variable['parent'] ) ) ),
				'subject'   => 'Variation [{variation_name}]',
			)
		);

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 2, 'Two variations of one parent did not produce two messages.' );

		$rows  = $this->detail_rows( (int) $this->sole_tombstone( $order_id, $rule_id )['id'] );
		$units = array_column( array_map( array( $this, 'consolidation_of' ), $rows ), 'unit' );

		$this->assertSame(
			array( 'variation:' . $variable['variations'][0], 'variation:' . $variable['variations'][1] ),
			$units,
			'A variation was not distinct from its sibling.'
		);

		// The two subjects differ, so the messages really are about different things.
		$this->assertCount( 2, array_unique( $this->subjects() ), 'Both variation messages carried the same subject.' );

		$this->gate[] = 'variations: 2 siblings of one parent = 2 units, distinct from the parent and each other';
	}

	/**
	 * 4b / gate 25. A PARTIALLY RESOLVED VARIATION FALLS BACK TO THE PARENT AS ITS
	 *               UNIT (ADR-0011 §4, ADR-0016 §4).
	 *
	 * ⚠ A dead variation's identity is still knowable while its own facts are not, so a
	 * message about it has nothing to say that distinguishes it from its parent. Two
	 * dead siblings therefore collapse into ONE message: neither can be told from the
	 * other.
	 *
	 * @return void
	 */
	public function test_a_partially_resolved_variation_falls_back_to_its_parent() {
		$variable = $this->make_variable_product( 'WCEP Dead', array( 'Small', 'Large' ) );
		$order    = $this->delayed_order( array( $variable['variations'][0], $variable['variations'][1] ) );
		$order_id = (int) $order->get_id();

		$rule_id = $this->make_fanout_rule(
			array(),
			array( 'targeting' => array( 'include' => array( 'products' => array( $variable['parent'] ) ) ) )
		);

		// BOTH VARIATIONS DELETED, PARENT ALIVE — ADR-0011 §4's `partially_resolved`.
		foreach ( $variable['variations'] as $variation_id ) {
			wp_delete_post( $variation_id, true );
		}

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 1, 'Two unidentifiable variations produced more than one message.' );

		$rows = $this->detail_rows( (int) $this->sole_tombstone( $order_id, $rule_id )['id'] );

		$this->assertCount( 1, $rows );
		$this->assertSame(
			'product:' . $variable['parent'],
			(string) $this->consolidation_of( $rows[0] )['unit'],
			'⚠ a message was attributed to a variation nobody can identify.'
		);

		$this->gate[] = 'partially resolved variations: both fall back to product:{parent} — 1 unit, 1 message';
	}

	// -----------------------------------------------------------------------
	// TEST 5 — PLACEHOLDER BINDING.
	// -----------------------------------------------------------------------

	/**
	 * 5. EACH MESSAGE'S SINGULAR PLACEHOLDERS RESOLVE TO ITS OWN PRODUCT, and the
	 *    PLURAL forms list all of them (ADR-0016 §6).
	 *
	 * ⚠ THE AXIS-BINDING PROBLEM ADR-0014 §5a SOLVED FOR THE PER-ITEM INSERT POSITION,
	 * on a different axis. Getting it wrong sends every message about the FIRST matched
	 * product with three different customers' worth of nothing to distinguish them —
	 * and `{item_custom_field:…}` getting it wrong puts one product's gift note on
	 * another product's email.
	 *
	 * @return void
	 */
	public function test_each_message_resolves_its_own_product() {
		$products = array( $this->labelled_product( 'Bind1' ), $this->labelled_product( 'Bind2' ) );
		$order    = $this->delayed_order( $products );
		$order_id = (int) $order->get_id();

		// A DIFFERENT gift note per line item, so a mis-bound message is visible.
		$notes = array();
		foreach ( $order->get_items() as $item ) {
			$note                              = 'NOTE-' . $item->get_product_id();
			$notes[ (int) $item->get_product_id() ] = $note;

			$item->add_meta_data( 'gift_note', $note, true );
			$item->save();
		}
		$order->save();

		$rule_id = $this->make_fanout_rule(
			$products,
			array(
				'content' => '<p>ONE=[{product_name}] SKU=[{product_sku}] NOTE=[{item_custom_field:gift_note}]'
					. ' ALL=[{product_names}] LIST=[{matched_product_list}]</p>',
			)
		);

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 2 );

		foreach ( $products as $offset => $product_id ) {
			$product = wc_get_product( $product_id );
			$body    = $this->body( $offset );
			$other   = wc_get_product( $products[ 1 - $offset ] );

			// --- ITS OWN PRODUCT ---------------------------------------------
			$this->assertStringContainsString( 'ONE=[' . $product->get_name() . ']', $body );
			$this->assertStringContainsString( 'SKU=[' . $product->get_sku() . ']', $body );
			$this->assertStringContainsString( 'NOTE=[' . $notes[ $product_id ] . ']', $body );

			// --- AND NOT THE OTHER'S -----------------------------------------
			$this->assertStringNotContainsString( 'ONE=[' . $other->get_name() . ']', $body, '⚠ a message named the wrong product.' );
			$this->assertStringNotContainsString( 'SKU=[' . $other->get_sku() . ']', $body, '⚠ a message carried the wrong SKU.' );
			$this->assertStringNotContainsString(
				'NOTE=[' . $notes[ $products[ 1 - $offset ] ] . ']',
				$body,
				'⚠ a message carried another product line\'s gift note.'
			);

			// --- THE SUBJECT IS BOUND TOO -------------------------------------
			$this->assertSame( 'About ' . $product->get_name(), $this->subjects()[ $offset ] );

			/*
			 * --- THE PLURAL FORMS LIST *ALL* MATCHED PRODUCTS -----------------
			 *
			 * ADR-0016 §6, stated rather than left to be discovered: a merchant writing
			 * `{product_names}` asked for the whole set, and narrowing it would make it a
			 * duplicate of `{product_name}` under a misleading name.
			 */
			foreach ( $products as $listed ) {
				$this->assertStringContainsString(
					(string) wc_get_product( $listed )->get_name(),
					substr( $body, (int) strpos( $body, 'ALL=[' ) ),
					'A plural placeholder was narrowed to the message\'s own product.'
				);
			}
		}

		// Each message's SUBJECT differs, which is what makes the rows tellable apart.
		$rows = $this->detail_rows( (int) $this->sole_tombstone( $order_id, $rule_id )['id'] );
		$this->assertSame(
			array( 'About WCEP Bind1', 'About WCEP Bind2' ),
			array_column( $rows, 'subject' ),
			'The attempt rows do not record each message\'s own subject.'
		);

		/*
		 * THE TWO MESSAGES, SIDE BY SIDE, PRINTED FROM THE REAL CAPTURE. The assertions
		 * above are the gate; this is the artifact — one glance shows the singular forms
		 * moving with the message and the plural forms standing still, which is the whole
		 * of ADR-0016 §6 in eight lines.
		 */
		$sample = '';
		foreach ( $products as $offset => $product_id ) {
			// The rule's OWN block, lifted out of WooCommerce's surrounding template.
			preg_match( '/ONE=\[.*?LIST=\[[^\]]*\]/s', wp_strip_all_tags( $this->body( $offset ) ), $block );

			$sample .= sprintf(
				"           message %d  unit=%-16s subject=%s\n                      %s\n",
				$offset + 1,
				(string) $this->consolidation_of( $rows[ $offset ] )['unit'],
				$this->subjects()[ $offset ],
				preg_replace( '/\s+/', ' ', (string) ( $block[0] ?? '' ) )
			);
		}

		fwrite( STDERR, "\n[8 / rendered sample] one order, one rule, one claim, two messages:\n" . $sample );

		$this->gate[] = 'placeholder binding: singular forms per message, plural forms list the whole matched set';
	}

	// -----------------------------------------------------------------------
	// TEST 6 — PARTIAL FAILURE.
	// -----------------------------------------------------------------------

	/**
	 * 6 / gate 18. MESSAGE 2 OF 3 FAILS: 1 AND 3 STILL SEND, the three rows carry
	 *              their OWN outcomes, and the tombstone aggregates to `failed`.
	 *
	 * ⚠ THE CASE A PER-MESSAGE FINALISE WOULD GET WRONG (ADR-0016 §5). The failure is
	 * not the last thing written, so last-write-wins would report `sent` and the
	 * merchant would never learn that message 2 never reached its customer.
	 *
	 * @return void
	 */
	public function test_one_failed_message_does_not_stop_the_others_and_the_tombstone_says_failed() {
		$products = array( $this->labelled_product( 'Fail1' ), $this->labelled_product( 'Fail2' ), $this->labelled_product( 'Fail3' ) );
		$order    = $this->delayed_order( $products );
		$order_id = (int) $order->get_id();

		$rule_id = $this->make_fanout_rule( $products );

		/*
		 * ⚠ PRIORITY 11 — AFTER THE CAPTURE, WHICH IS THE ONLY WAY TO MODEL A TRANSPORT
		 * FAILURE HERE. `pre_wp_mail` does not short-circuit its own chain: WordPress
		 * applies every callback and then acts on the LAST return value, and this suite
		 * has `MailGuard` at `PHP_INT_MIN`, `DeliveryTestCase`'s capture at 1 and
		 * `MatchingTestCase`'s blocker at 10, each returning `true`. A breaker at
		 * priority 0 is therefore overwritten by all three. 11 is last and wins — the
		 * same position `InsertModeTestCase` uses for `__return_false`.
		 *
		 * So message 2 IS captured and reported NOT SENT, which is exactly what a real
		 * transport failure looks like: the mailer was handed the message and rejected
		 * it. That makes this a stronger test of "one failure does not abort the rest" —
		 * message 3 was composed and handed over AFTER message 2 had already failed.
		 */
		$breaker = static function ( $short_circuit, $atts ) {
			if ( false !== strpos( (string) ( $atts['subject'] ?? '' ), 'Fail2' ) ) {
				return false;
			}

			return $short_circuit;
		};

		add_filter( 'pre_wp_mail', $breaker, 11, 2 );

		try {
			$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );
		} finally {
			remove_filter( 'pre_wp_mail', $breaker, 11 );
		}

		// ALL THREE REACHED THE MAILER — the fan-out did not stop at the failure.
		$this->assertMailCount( 3, '⚠ one failing message aborted the rest of the fan-out.' );
		$this->assertSame(
			array( 'About WCEP Fail1', 'About WCEP Fail2', 'About WCEP Fail3' ),
			$this->subjects(),
			'Message 3 was never composed, so the loop stopped at the failure.'
		);

		$tombstone = $this->sole_tombstone( $order_id, $rule_id );

		// THE AGGREGATE IS HONEST.
		$this->assertSame( 'failed', $tombstone['final_status'], '⚠ a fan-out with a failed message reported success.' );

		// AND EVERY ROW CARRIES ITS OWN TRUE OUTCOME.
		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 3, $rows );
		$this->assertSame( array( 'sent', 'failed', 'sent' ), array_column( $rows, 'state' ) );
		$this->assertSame(
			array( 'product:' . $products[0], 'product:' . $products[1], 'product:' . $products[2] ),
			array_column( array_map( array( $this, 'consolidation_of' ), $rows ), 'unit' )
		);
		$this->assertStringContainsString(
			'the mailer reported the message as not sent',
			(string) $rows[1]['failure_message'],
			'The failed message did not say why.'
		);
		$this->assertNull( $rows[0]['failure_message'], 'A successful message recorded a failure message.' );

		$this->gate[] = 'partial failure: message 2 of 3 failed, 1 and 3 sent, rows sent/failed/sent, tombstone=failed';
	}

	// -----------------------------------------------------------------------
	// TEST 7 — CONTAINMENT.
	// -----------------------------------------------------------------------

	/**
	 * 7 / gate 17. A `\Throwable` WHILE RENDERING MESSAGE 2 DOES NOT ABORT 1 AND 3,
	 *              DOES NOT ESCAPE THE ORDER EVENT, and IS RECORDED AGAINST THAT
	 *              MESSAGE.
	 *
	 * Driven through the real `woocommerce_order_status_changed` hook, because the point
	 * of per-message containment is what happens to the MERCHANT'S ORDER UPDATE. The
	 * throw comes from `extonify_wcep_meta_placeholder_allowed` — a PLUGIN-OWNED
	 * extension point invoked in the middle of resolution, which is exactly what
	 * ADR-0014 §10 widened the boundary to cover.
	 *
	 * @return void
	 */
	public function test_a_throw_while_rendering_one_message_is_contained_to_that_message() {
		$products = array( $this->labelled_product( 'Throw1' ), $this->labelled_product( 'Throw2' ), $this->labelled_product( 'Throw3' ) );
		$order    = $this->delayed_order( $products );
		$order_id = (int) $order->get_id();

		$rule_id = $this->make_fanout_rule(
			$products,
			array(
				'trigger_value' => 'processing',
				'content'       => '<p>ONE=[{product_name}] NOTE=[{item_custom_field:gift_note}]</p>',
			)
		);

		/*
		 * ONE FILTER CALL PER MESSAGE. The value set memoises by `name:parameter`, so
		 * `{item_custom_field:gift_note}` resolves ONCE per message however many times
		 * and formats it appears in — which is what makes "throw on the second call"
		 * mean "throw while rendering message 2".
		 */
		$calls   = 0;
		$thrower = static function ( $allowed, $key ) use ( &$calls ) {
			if ( 'gift_note' !== $key ) {
				return $allowed;
			}

			++$calls;

			if ( 2 === $calls ) {
				throw new \RuntimeException( 'a meta filter exploded mid-render' );
			}

			return $allowed;
		};

		add_filter( PlaceholderValues::META_FILTER, $thrower, 10, 2 );

		try {
			// THE ASSERTION THAT MATTERS: this does not throw.
			do_action( 'woocommerce_order_status_changed', $order_id, 'pending', 'processing', $order );
		} finally {
			remove_filter( PlaceholderValues::META_FILTER, $thrower, 10 );
		}

		$this->assertSame( 3, $calls, 'the fixture did not reach every message, so this proves nothing' );

		// MESSAGES 1 AND 3 STILL WENT OUT.
		$this->assertMailCount( 2, '⚠ a throw in one message aborted the rest of the fan-out.' );
		$this->assertSame( array( 'About WCEP Throw1', 'About WCEP Throw3' ), $this->subjects() );

		$tombstone = $this->sole_tombstone( $order_id, $rule_id, 'status:processing' );
		$this->assertSame( 'failed', $tombstone['final_status'] );

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 3, $rows );
		$this->assertSame( array( 'sent', 'failed', 'sent' ), array_column( $rows, 'state' ) );

		// RECORDED AGAINST *THAT* MESSAGE, naming the exception.
		$this->assertStringContainsString( 'RuntimeException', (string) $rows[1]['failure_message'] );
		$this->assertStringContainsString( 'a meta filter exploded mid-render', (string) $rows[1]['failure_message'] );
		$this->assertSame(
			'product:' . $products[1],
			(string) $this->consolidation_of( $rows[1] )['unit'],
			'⚠ the throw was recorded against the wrong product.'
		);

		// ⚠ AND NOTHING BLED BETWEEN MESSAGES. Message 3's row must not carry message
		// 2's subject or notes — `values` and `subject` are reset per message precisely
		// so a throw cannot leave the previous message's state behind.
		$this->assertSame( 'About WCEP Throw3', (string) $rows[2]['subject'] );
		$this->assertNull( $rows[2]['failure_message'] );

		// The shared email object is clean, so the next delivery is unaffected.
		$email = $this->live_email();
		$this->assertSame( '', $email->recipient );
		$this->assertSame( '', $email->delivery_subject );
		$this->assertNull( $email->object );

		$this->gate[] = 'containment: a throw in message 2 left 1 and 3 sending, escaped nothing, and was recorded on message 2';
	}

	// -----------------------------------------------------------------------
	// TEST 8 — IDEMPOTENCY.
	// -----------------------------------------------------------------------

	/**
	 * 8 / gate 25. THE SAME TRIGGER FIRED TWICE SENDS THE FAN-OUT **ONCE**.
	 *
	 * ⚠ THE PROPERTY ADR-0016 §3 EXISTS TO PRESERVE. Consolidation fans one DECISION
	 * into N messages; the decision is still claimed once, ever, so the second trigger
	 * is suppressed by the same atomic claim and sends nothing — not one message, not
	 * three.
	 *
	 * @return void
	 */
	public function test_the_same_trigger_twice_sends_the_fan_out_once() {
		$products = array( $this->labelled_product( 'Idem1' ), $this->labelled_product( 'Idem2' ), $this->labelled_product( 'Idem3' ) );
		$order    = $this->delayed_order( $products );
		$order_id = (int) $order->get_id();

		$rule_id = $this->make_fanout_rule( $products );

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );
		$this->assertMailCount( 3 );

		// SAME TRIGGER, SAME IDENTITY.
		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 3, '⚠ a re-fired trigger re-sent the fan-out.' );

		$tombstone = $this->sole_tombstone( $order_id, $rule_id );

		$this->assertSame( 'sent', $tombstone['final_status'] );
		$this->assertSame( 1, (int) $tombstone['suppressed_count'], 'the duplicate was not counted' );
		$this->assertCount( 3, $this->detail_rows( (int) $tombstone['id'] ), 'the duplicate wrote extra attempt rows' );

		$this->gate[] = 'idempotency: the trigger fired twice, 3 messages total, suppressed_count=1';
	}

	// -----------------------------------------------------------------------
	// TEST 9 — THE CAP.
	// -----------------------------------------------------------------------

	/**
	 * 9 / gate 25. A RULE MATCHING `cap + 1` PRODUCTS FALLS BACK TO ONE COMBINED
	 *              MESSAGE, and the fallback is RECORDED WITH THE COUNT.
	 *
	 * ⚠ THE CUSTOMER-FACING SAFETY LIMIT (ADR-0016 §7). Without it a category-targeted
	 * rule meeting a wholesale order would send that customer sixty emails.
	 *
	 * A CATEGORY rule rather than eleven product ids, because that is the shape the
	 * failure actually arrives in.
	 *
	 * @return void
	 */
	public function test_over_the_cap_it_falls_back_to_one_combined_message() {
		$category = $this->make_term( 'product_cat', 'WCEP Wholesale ' . uniqid() );
		$count    = Consolidation::MAX_MESSAGES + 1;

		$lines = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$lines[] = $this->make_simple_product( 'WCEP Cap ' . $i, array( 'category_ids' => array( $category ) ) );
		}

		$order    = $this->delayed_order( $lines );
		$order_id = (int) $order->get_id();

		$rule_id = $this->make_fanout_rule(
			array(),
			array( 'targeting' => array( 'include' => array( 'categories' => array( $category ) ) ) )
		);

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 1, '⚠ the fan-out cap did not hold; a customer received an email storm.' );

		$tombstone = $this->sole_tombstone( $order_id, $rule_id );
		$this->assertSame( 'sent', $tombstone['final_status'] );

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 1, $rows );

		// THE FALLBACK IS RECORDED IN BOTH PLACES A MERCHANT LOOKS.
		$payload = $this->consolidation_of( $rows[0] );

		$this->assertSame( Consolidation::FALLBACK_CAP_EXCEEDED, (string) $payload['fallback'] );
		$this->assertSame( $count, (int) $payload['units'], 'the fallback lost the matched-unit count' );
		$this->assertSame( Consolidation::MAX_MESSAGES, (int) $payload['cap'] );
		$this->assertSame( Consolidation::PER_PRODUCT, (string) $payload['requested'] );
		$this->assertSame( Consolidation::NONE, (string) $payload['mode'] );

		$this->assertStringContainsString(
			$count . ' matched products exceeds the cap of ' . Consolidation::MAX_MESSAGES,
			(string) $rows[0]['reason'],
			'The fallback was not explained in words.'
		);

		/*
		 * ⚠ NOTHING WAS TRUNCATED AWAY. But note WHAT THIS FIXTURE CAN AND CANNOT PROVE:
		 * its template carries `ALL=[{product_names}]`, so the products would appear here
		 * even under the Prompt 8 mechanism that bound the message to the FIRST matched
		 * item. That is exactly how the defect survived review.
		 * self::test_the_cap_fallback_names_every_product_from_a_singular_only_template()
		 * is the assertion that actually holds the mechanism; this one holds the plural
		 * template's behaviour, which must also keep working.
		 */
		$body = $this->body( 0 );
		foreach ( $lines as $product_id ) {
			$this->assertStringContainsString(
				(string) wc_get_product( $product_id )->get_name(),
				$body,
				'The cap fallback silently lost a product the merchant asked to have mentioned.'
			);
		}

		$this->gate[] = 'cap (PLURAL template): ' . $count . ' units > cap ' . Consolidation::MAX_MESSAGES
			. ' -> 1 message, fallback=cap_exceeded recorded with the count, no product lost'
			. ' — see the singular-only test for the case this fixture cannot prove';
	}

	// -----------------------------------------------------------------------
	// 8A ITEM 1 — THE CAP FALLBACK MUST NOT DROP PRODUCTS.
	// -----------------------------------------------------------------------

	/**
	 * 8A-1 / gate 25. `cap + 1` PRODUCTS WITH A TEMPLATE CONTAINING **ONLY**
	 *                 `{product_name}`: one message, and EVERY matched product is in
	 *                 the body.
	 *
	 * ⚠ THE TEST PROMPT 8's FIXTURE COULD NOT MAKE. That fixture's template carried
	 * `ALL=[{product_names}]`, so the products appeared because the TEMPLATE asked for
	 * them — and the assertion "every product name appears in the body" passed while the
	 * mechanism bound `item_id = 0` and rendered `{product_name}` as PRODUCT ONE. **This
	 * template contains no plural placeholder at all**, which is the natural template for
	 * a per-product rule and the one that exposed the defect.
	 *
	 * @return void
	 */
	public function test_the_cap_fallback_names_every_product_from_a_singular_only_template() {
		$category = $this->make_term( 'product_cat', 'WCEP CapSingular ' . uniqid() );
		$count    = Consolidation::MAX_MESSAGES + 1;

		$lines = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$lines[] = $this->make_simple_product( 'WCEP Single ' . $i, array( 'category_ids' => array( $category ) ) );
		}

		$order    = $this->delayed_order( $lines );
		$order_id = (int) $order->get_id();

		$rule_id = $this->make_fanout_rule(
			array(),
			array(
				'targeting' => array( 'include' => array( 'categories' => array( $category ) ) ),
				'subject'   => 'Care instructions for {product_name}',
				'heading'   => 'Looking after your {product_name}',
				// ⚠ SINGULAR ONLY. No `{product_names}`, no `{matched_product_list}`.
				'content'   => '<p>Here is the care guide for {product_name}.</p>',
			)
		);

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 1, 'The cap fallback did not send exactly one message.' );

		$body = $this->body( 0 );

		// ⚠ EVERY MATCHED PRODUCT IS PRESENT. That is the guarantee, and it holds for
		// every unit — the label is what carries it (ADR-0016 §7).
		foreach ( $lines as $product_id ) {
			$this->assertStringContainsString(
				(string) wc_get_product( $product_id )->get_name(),
				$body,
				'⚠ the cap fallback silently omitted a matched product from the customer-visible body.'
			);
		}

		/*
		 * ⚠ AND THE FIRST `cap` UNITS CARRY THEIR OWN CARE GUIDE, THE REST THEIR NAME
		 * (ADR-0016 §7a). 8B bounded the body-carrying sections at the cap: rendering
		 * the merchant's block once per unit with no bound made the message QUADRATIC
		 * for any template containing a full-set plural, because such a placeholder
		 * resolves to all N products in every one of the N sections. The bound is the
		 * cap because the fallback moved that repetition out of N messages and into one
		 * body, and 8A moved it without moving the limit with it.
		 */
		$rendered = Consolidation::rendered_sections( $count, Consolidation::MAX_MESSAGES );

		$this->assertSame( Consolidation::MAX_MESSAGES, $rendered );

		foreach ( array_slice( $lines, 0, $rendered ) as $product_id ) {
			$this->assertStringContainsString(
				'Here is the care guide for ' . wc_get_product( $product_id )->get_name() . '.',
				$body,
				'⚠ a unit inside the bound lost its per-product CONTENT.'
			);
		}

		foreach ( array_slice( $lines, $rendered ) as $product_id ) {
			$this->assertStringNotContainsString(
				'Here is the care guide for ' . wc_get_product( $product_id )->get_name() . '.',
				$body,
				'⚠ a unit past the bound rendered the body anyway; the bound is not holding.'
			);
		}

		// The subject and heading bind to the FIRST unit — exactly what `none` does, so
		// the fallback introduces no new header semantics (ADR-0016 §7).
		$first = (string) wc_get_product( $lines[0] )->get_name();
		$this->assertSame( 'Care instructions for ' . $first, $this->subjects()[0] );

		// And the fallback is still recorded with its count.
		$rows    = $this->detail_rows( (int) $this->sole_tombstone( $order_id, $rule_id )['id'] );
		$payload = $this->consolidation_of( $rows[0] );

		$this->assertSame( Consolidation::FALLBACK_CAP_EXCEEDED, (string) $payload['fallback'] );
		$this->assertSame( $count, (int) $payload['units'] );

		/*
		 * ⚠ ANCHORED ON `<strong>` RATHER THAN `<p><strong>`: WooCommerce's
		 * `style_inline()` rewrites the paragraph as `<p style="…"><strong>`, so a
		 * `<p><strong>` anchor never matches and the sample silently dumps the whole
		 * template. Found by reading the printed output rather than trusting it.
		 */
		$last  = (string) wc_get_product( $lines[ $count - 1 ] )->get_name();
		$block = $this->section_block( $body, '<strong>' . $first . '</strong>', '<strong>' . $last . '</strong></p>' );

		fwrite(
			STDERR,
			"\n[8B] cap fallback, SINGULAR-ONLY template — `<p>Here is the care guide for {product_name}.</p>`\n"
			. '           ' . $count . ' units > cap ' . Consolidation::MAX_MESSAGES . ', so ONE message: '
			. $rendered . " sections carrying the body, the rest named (ADR-0016 §7a):\n"
			. '           subject   = ' . $this->subjects()[0] . "\n"
			. '           heading   = Looking after your ' . $first . "\n"
			. '           HTML body = ' . preg_replace( '/\s+/', ' ', trim( preg_replace( '/ style="[^"]*"/', '', $block ) ) ) . "\n"
			. '           as text   = ' . preg_replace( '/\s+/', ' ', trim( wp_strip_all_tags( $block ) ) ) . "\n"
		);

		$this->gate[] = 'cap fallback: a singular-only template names all ' . $count
			. ' products and carries the content of the first ' . $rendered;
	}

	/**
	 * 8A-1 / gate 25. AN **EMPTY** CONTENT BODY: every product is still present.
	 *
	 * ⚠ THE CASE THAT MAKES THE SECTION LABEL A CORRECTNESS REQUIREMENT RATHER THAN
	 * DECORATION (ADR-0016 §7). Repeating an empty body eleven times names nothing, so a
	 * mechanism built on repetition alone would fail requirement 2 here — and it would
	 * fail identically for `Hand wash only.`, a template with no placeholder in it, which
	 * is entirely reasonable for a single-product rule.
	 *
	 * @dataProvider contentless_template_provider
	 *
	 * @param string $content Rule body.
	 * @param string $label   What the case proves.
	 * @return void
	 */
	public function test_the_cap_fallback_names_every_product_with_no_placeholder_at_all( string $content, string $label ) {
		$category = $this->make_term( 'product_cat', 'WCEP CapBare ' . uniqid() );
		$count    = Consolidation::MAX_MESSAGES + 1;

		$lines = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$lines[] = $this->make_simple_product( 'WCEP Bare' . $i . ' ' . substr( md5( $label ), 0, 6 ), array( 'category_ids' => array( $category ) ) );
		}

		$order = $this->delayed_order( $lines );

		$this->make_fanout_rule(
			array(),
			array(
				'targeting' => array( 'include' => array( 'categories' => array( $category ) ) ),
				'subject'   => 'Your order',
				'heading'   => 'Your order',
				'content'   => $content,
			)
		);

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 1, $label . ': the fallback did not send exactly one message.' );

		$body = $this->body( 0 );

		foreach ( $lines as $product_id ) {
			$this->assertStringContainsString(
				(string) wc_get_product( $product_id )->get_name(),
				$body,
				'⚠ ' . $label . ': a matched product was absent from the customer-visible body.'
			);
		}

		$first = (string) wc_get_product( $lines[0] )->get_name();
		$last  = (string) wc_get_product( $lines[ $count - 1 ] )->get_name();
		$block = $this->section_block(
			$body,
			'<strong>' . $first . '</strong>',
			// ENDS ON THE LAST SECTION'S LABEL in both cases. For a placeholder-less
			// template the block that follows it is byte-identical to the ten before it,
			// so cutting there loses nothing a reader needs — and the template itself is
			// printed above.
			'<strong>' . $last . '</strong></p>'
		);

		fwrite(
			STDERR,
			"\n[8A item 1] cap fallback, " . strtoupper( $label ) . " — template = "
			. ( '' === $content ? '(nothing at all)' : '`' . $content . '`' ) . "\n"
			. '           HTML body = ' . preg_replace( '/\s+/', ' ', trim( preg_replace( '/ style="[^"]*"/', '', $block ) ) ) . "\n"
			. '           as text   = ' . preg_replace( '/\s+/', ' ', trim( wp_strip_all_tags( $block ) ) ) . "\n"
		);

		$this->gate[] = 'cap fallback: ' . $label . ' still names all ' . $count . ' products';
	}

	/**
	 * Templates that name no product on their own.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function contentless_template_provider(): array {
		return array(
			'an empty body'          => array( '', 'an empty body' ),
			'a placeholder-less body' => array( '<p>Hand wash only.</p>', 'a body with no placeholder' ),
		);
	}

	/**
	 * 8A-1. A PRODUCT NAME CARRYING HOSTILE MARKUP is escaped in HTML and readable in
	 *       plain text.
	 *
	 * The section label is a VALUE, so it goes through `PlaceholderSyntax::escape()` for
	 * its destination exactly as every placeholder value does — it is not an exception
	 * carved out for plugin-authored markup, because it is not plugin-authored text.
	 *
	 * @return void
	 */
	public function test_a_hostile_product_name_is_escaped_in_the_fallback_sections() {
		$category = $this->make_term( 'product_cat', 'WCEP CapHostile ' . uniqid() );
		$count    = Consolidation::MAX_MESSAGES + 1;
		$hostile  = '<script>alert("x")</script> & "quoted"';

		$lines = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$lines[] = $this->make_simple_product( 'WCEP Hostile ' . $i, array( 'category_ids' => array( $category ) ) );
		}

		/*
		 * ⚠ ONE ORDER PER FORMAT, BECAUSE THE IDENTITY IS CONSUMED ONCE. Both renders
		 * fire the SAME trigger, so a second run against the same order is suppressed by
		 * ADR-0004's atomic claim and sends nothing — which is the plugin working, not a
		 * fixture problem. Two orders give two identities.
		 *
		 * ⚠ AND THE HOSTILE STRING GOES ON THE **LINE ITEM**, NOT THE PRODUCT. That is
		 * the precise fixture rather than a convenience: `{product_name}` resolves to
		 * `WC_Order_Item_Product::get_name()` — what the customer bought, as the order
		 * recorded it (ADR-0014 §5) — and the section label reads the same value. A
		 * PRODUCT name cannot carry raw markup at all: `wp_insert_post()` kses-filters
		 * the title for a user without `unfiltered_html`, so setting it there stores
		 * `alert("x") &amp; "quoted"` and the test would be asserting against a string
		 * WordPress had already neutralised, proving nothing about this plugin.
		 * `WC_Order_Item::set_name()` applies no such filter, so this is the field that
		 * can genuinely arrive hostile.
		 */
		$make_order = function () use ( $lines, $hostile ): \WC_Order {
			$order = $this->delayed_order( $lines );

			foreach ( $order->get_items() as $item ) {
				$item->set_name( $hostile );
				$item->save();
				break;
			}

			$order->save();

			return wc_get_order( (int) $order->get_id() );
		};

		$this->make_fanout_rule(
			array(),
			array(
				'targeting' => array( 'include' => array( 'categories' => array( $category ) ) ),
				'subject'   => 'Your order',
				'heading'   => 'Your order',
				'content'   => '<p>Care guide.</p>',
			)
		);

		// --- HTML: escaped ---------------------------------------------------
		$html = $this->body_in_format( 'html', $make_order() );

		$this->assertStringNotContainsString(
			'<script>',
			$html,
			'⚠ SECURITY: a product name\'s markup reached the HTML body unescaped.'
		);
		$this->assertStringContainsString( '&lt;script&gt;', $html, 'the hostile name was dropped rather than escaped' );
		$this->assertStringContainsString( '&amp;', $html );

		// --- PLAIN: readable, not escaped ------------------------------------
		$plain = $this->body_in_format( 'plain', $make_order() );

		$this->assertStringNotContainsString( '&lt;', $plain, 'HTML escaping leaked into the text/plain body' );
		$this->assertStringNotContainsString( '&amp;', $plain, 'HTML escaping leaked into the text/plain body' );
		$this->assertStringContainsString( '& "quoted"', $plain, 'the plain-text label is not readable' );

		// Both formats still carry every OTHER product too.
		foreach ( array_slice( $lines, 1 ) as $product_id ) {
			$name = (string) wc_get_product( $product_id )->get_name();

			$this->assertStringContainsString( $name, $html );
			$this->assertStringContainsString( $name, $plain );
		}

		$this->gate[] = 'cap fallback: a hostile product name is escaped in HTML and readable in plain text';
	}

	/**
	 * 8A-1. A CAP RAISED ABOVE THE UNIT COUNT fans out normally — no fallback, no
	 *       sections.
	 *
	 * @return void
	 */
	public function test_a_cap_above_the_unit_count_produces_a_normal_fan_out() {
		$products = array( $this->labelled_product( 'NoFall1' ), $this->labelled_product( 'NoFall2' ) );
		$order    = $this->delayed_order( $products );
		$order_id = (int) $order->get_id();

		$rule_id = $this->make_fanout_rule( $products, array( 'content' => '<p>ONE=[{product_name}]</p>' ) );

		$raise = static function () {
			return 50;
		};

		add_filter( Consolidation::MAX_MESSAGES_FILTER, $raise, 10, 3 );

		try {
			$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );
		} finally {
			remove_filter( Consolidation::MAX_MESSAGES_FILTER, $raise, 10 );
		}

		$this->assertMailCount( 2, 'A cap above the unit count did not fan out.' );

		$rows = $this->detail_rows( (int) $this->sole_tombstone( $order_id, $rule_id )['id'] );

		$this->assertCount( 2, $rows );
		foreach ( $rows as $row ) {
			$this->assertArrayNotHasKey( 'fallback', $this->consolidation_of( $row ) );
		}
	}

	/**
	 * 8A-1. A CAP LOWERED BELOW THE UNIT COUNT gets the SAME guaranteed fallback.
	 *
	 * The guarantee is a property of the fallback, not of the default cap — so lowering
	 * the cap by filter must produce a message containing every product just as
	 * exceeding the default does.
	 *
	 * @return void
	 */
	public function test_a_cap_lowered_below_the_unit_count_still_names_every_product() {
		$products = array(
			$this->labelled_product( 'Low1' ),
			$this->labelled_product( 'Low2' ),
			$this->labelled_product( 'Low3' ),
		);
		$order    = $this->delayed_order( $products );
		$order_id = (int) $order->get_id();

		$rule_id = $this->make_fanout_rule(
			$products,
			array(
				'subject' => 'About {product_name}',
				// Singular only, again — the fallback must not depend on the template.
				'content' => '<p>Guide for {product_name}.</p>',
			)
		);

		$lower = static function () {
			return 2;
		};

		add_filter( Consolidation::MAX_MESSAGES_FILTER, $lower, 10, 3 );

		try {
			$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );
		} finally {
			remove_filter( Consolidation::MAX_MESSAGES_FILTER, $lower, 10 );
		}

		$this->assertMailCount( 1, 'A lowered cap did not fall back to one message.' );

		$body = $this->body( 0 );

		// EVERY PRODUCT IS NAMED — the guarantee is representation, and it does not
		// depend on the cap being the default one.
		foreach ( $products as $product_id ) {
			$this->assertStringContainsString(
				(string) wc_get_product( $product_id )->get_name(),
				$body,
				'⚠ a lowered cap dropped a product from the customer-visible body.'
			);
		}

		/*
		 * ⚠ AND THE BODY-CARRYING BOUND FOLLOWS THE FILTERED CAP (ADR-0016 §7a). One
		 * knob, not two: a merchant who moves the cap moves how many messages a fan-out
		 * may send AND how many sections the fallback may render, because both are the
		 * same judgement about the same customer.
		 */
		foreach ( array_slice( $products, 0, 2 ) as $product_id ) {
			$this->assertStringContainsString(
				'Guide for ' . wc_get_product( $product_id )->get_name() . '.',
				$body,
				'⚠ a unit inside the filtered bound lost its content.'
			);
		}

		$this->assertStringNotContainsString(
			'Guide for ' . wc_get_product( $products[2] )->get_name() . '.',
			$body,
			'⚠ the body-carrying bound did not follow the filtered cap.'
		);

		$payload = $this->consolidation_of( $this->detail_rows( (int) $this->sole_tombstone( $order_id, $rule_id )['id'] )[0] );

		$this->assertSame( Consolidation::FALLBACK_CAP_EXCEEDED, (string) $payload['fallback'] );
		$this->assertSame( 3, (int) $payload['units'] );
		$this->assertSame( 2, (int) $payload['cap'] );
		$this->assertSame( 2, (int) $payload['rendered'], 'the recorded body-carrying count is wrong' );

		$this->gate[] = 'cap fallback: a filtered cap of 2 below 3 units names all 3 and renders 2 bodies';
	}

	/**
	 * 8A-1. AN ORDINARY `none` RULE IS BYTE-IDENTICAL to before this prompt.
	 *
	 * The sectioned renderer must be the cap fallback's alone. `none` is the default, so
	 * any change to its body would change behaviour for every existing rule.
	 *
	 * @return void
	 */
	public function test_a_none_rule_body_is_unchanged_by_the_sectioned_renderer() {
		$products = array( $this->labelled_product( 'Byte1' ), $this->labelled_product( 'Byte2' ) );
		$order    = $this->delayed_order( $products );
		$order_id = (int) $order->get_id();

		$rule_id = $this->make_fanout_rule(
			$products,
			array(
				'consolidation' => Consolidation::NONE,
				'subject'       => 'About {product_name}',
				'content'       => '<p>ONE=[{product_name}] ALL=[{product_names}]</p>',
			)
		);

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 1 );

		$first = (string) wc_get_product( $products[0] )->get_name();
		$body  = $this->body( 0 );

		// EXACTLY the pre-8A output: one block, `{product_name}` = the FIRST matched
		// item, no section label anywhere.
		$this->assertStringContainsString(
			'ONE=[' . $first . '] ALL=[' . $first . ', ' . wc_get_product( $products[1] )->get_name() . ']',
			$body
		);
		$this->assertStringNotContainsString(
			'<p><strong>' . $first . '</strong></p>',
			$body,
			'⚠ the cap fallback\'s section label leaked into an ordinary `none` delivery.'
		);

		$rows = $this->detail_rows( (int) $this->sole_tombstone( $order_id, $rule_id )['id'] );

		$this->assertCount( 1, $rows );
		$this->assertSame( array(), $this->consolidation_of( $rows[0] ) );
	}

	// -----------------------------------------------------------------------
	// 8B — THE FALLBACK IS BOUNDED (gate 27, ADR-0016 §7a).
	// -----------------------------------------------------------------------

	/**
	 * The all-three-placeholder template: the one that made the fallback quadratic.
	 *
	 * `{product_name}` is scoped to the section; `{product_names}` and
	 * `{matched_product_list}` are FULL-SET plurals that resolve to every matched
	 * product in every section that renders the body (ADR-0016 §6).
	 *
	 * @var string
	 */
	const BOUND_TEMPLATE = '<p>Product: {product_name}</p><p>Names: {product_names}</p><p>List: {matched_product_list}</p>';

	/**
	 * Products whose names cannot be substrings of one another, so `substr_count()`
	 * means what it says.
	 *
	 * ⚠ ZERO-PADDED DELIBERATELY. With `WCEP-Q1 … WCEP-Q11`, counting `WCEP-Q1` also
	 * counts every occurrence of `WCEP-Q11` and the occurrence assertions below would
	 * be measuring the fixture rather than the mechanism.
	 *
	 * ⚠ AND NOT A SPACE IN THE NAME, which is WooCommerce's doing rather than a
	 * preference. `WC_Email::get_content()` runs the plain body through
	 * `wordwrap( …, 70 )` (WC 10.9.4, `class-wc-email.php:872`), and a comma-joined
	 * `{product_names}` line is far longer than 70 characters — so a product called
	 * `WCEP Bound Q09` is split as `WCEP Bound\nQ09` wherever the break happens to
	 * land, and an occurrence count over the plain body would be counting WooCommerce's
	 * line breaks. It is a real (cosmetic) WooCommerce behaviour for every plain-text
	 * delivery, not something this fallback introduced.
	 *
	 * @param string $label Short run label.
	 * @param int    $count How many.
	 * @param int    $category Category term id.
	 * @return array{0:int[],1:string[]} Product ids and names, in order.
	 */
	private function padded_products( string $label, int $count, int $category ): array {
		$ids   = array();
		$names = array();

		for ( $i = 1; $i <= $count; $i++ ) {
			$name    = 'WCEP-' . $label . '-Q' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT );
			$names[] = $name;
			$ids[]   = $this->make_simple_product( $name, array( 'category_ids' => array( $category ) ) );
		}

		return array( $ids, $names );
	}

	/**
	 * 8B / gate 27. A FULL-SET PLURAL IS NOT RESOLVED ONCE PER SECTION — in HTML and
	 *               in plain text (ADR-0016 §7a).
	 *
	 * ⚠ THE DEFECT THIS ASSERTS AWAY. A section's body is rendered against the WHOLE
	 * matched set, because §6 says the plural forms list all matched products. Prompt
	 * 8A rendered that body once per unit with no bound, so `{matched_product_list}`
	 * resolved completely in every one of N sections: **N × N entries in one message**.
	 * For a sixty-line wholesale order that is ~3,600 entries and ~100KB before the
	 * merchant's own content — past the size at which mail clients clip a message, so
	 * the customer silently loses the tail.
	 *
	 * ⚠ AND THE FIX IS NOT "SEND LESS". Every matched product is still represented:
	 * the units past the bound keep their LABEL, which is what already made an empty
	 * and a placeholder-less template safe. What is bounded is how many times the
	 * merchant's block — and therefore the full-set list inside it — is repeated.
	 *
	 * BOTH FORMATS, because they are rendered from separate templates through separate
	 * escaping (ADR-0014 §9) and a bound that held in one and not the other would be a
	 * message whose two halves disagreed about which products the customer was shown.
	 *
	 * @return void
	 */
	public function test_a_full_set_plural_is_not_repeated_once_per_section() {
		$category = $this->make_term( 'product_cat', 'WCEP Bound ' . uniqid() );
		$count    = 16;
		$cap      = Consolidation::MAX_MESSAGES;
		$rendered = Consolidation::rendered_sections( $count, $cap );

		list( $lines, $names ) = $this->padded_products( 'Bound', $count, $category );

		$this->make_fanout_rule(
			array(),
			array(
				'targeting' => array( 'include' => array( 'categories' => array( $category ) ) ),
				// NO PRODUCT PLACEHOLDER IN EITHER HEADER FIELD, so every occurrence
				// counted below comes from the BODY and the counts mean what they say.
				'subject'   => 'Your order',
				'heading'   => 'Your order',
				'content'   => self::BOUND_TEMPLATE,
			)
		);

		$bodies = array(
			'html'  => $this->body_in_format( 'html', $this->delayed_order( $lines ) ),
			'plain' => $this->body_in_format( 'plain', $this->delayed_order( $lines ) ),
		);

		/*
		 * THE DECLARED BOUND ON HOW OFTEN ONE PRODUCT'S NAME MAY APPEAR:
		 *
		 *   1 (its own label) + s (singular occurrences, in its own section only)
		 *                     + cap × p (each rendered section's two full-set lists)
		 *
		 * A constant, independent of the unit count — which is the property the old
		 * mechanism did not have, where it was 1 + s + units × p.
		 */
		$singulars   = 1;
		$plurals     = 2;
		$max_name    = 1 + $singulars + ( $cap * $plurals );
		$max_labeled = 1 + ( $cap * $plurals );

		foreach ( $bodies as $format => $body ) {
			// 1 — EVERY MATCHED PRODUCT IS REPRESENTED.
			foreach ( $names as $name ) {
				$this->assertStringContainsString(
					$name,
					$body,
					'⚠ ' . $format . ': the bounded fallback lost a matched product.'
				);
			}

			// 2 — THE FULL LIST IS NOT REPEATED ONCE PER SECTION.
			$lists = substr_count( $body, 'Names:' );

			$this->assertSame( $rendered, $lists, $format . ': the full-set list count is not the bound' );
			$this->assertSame( $rendered, substr_count( $body, 'List:' ), $format . ': the two plurals disagree' );
			$this->assertLessThan(
				$count,
				$lists,
				'⚠ ' . $format . ': the full product list is still resolved once per section.'
			);

			// 3 — EVERY NAME STAYS INSIDE THE DECLARED CONSTANT BOUND, and the exact
			//     count is asserted rather than only the ceiling: a name inside the
			//     bound appears once as a label, once in its own section and twice per
			//     rendered section; one past it loses only the second.
			foreach ( $names as $index => $name ) {
				$expected = $index < $rendered ? $max_name : $max_labeled;

				$this->assertSame(
					$expected,
					substr_count( $body, $name ),
					'⚠ ' . $format . ': ' . $name . ' does not occur the bounded number of times.'
				);
				$this->assertLessThanOrEqual( $max_name, substr_count( $body, $name ) );
			}

			// AND BOTH FORMATS CARRY THE SAME SECTIONS — the bound is one decision.
			foreach ( $names as $index => $name ) {
				$this->assertSame(
					$index < $rendered ? 1 : 0,
					substr_count( $body, 'Product: ' . $name ),
					'⚠ ' . $format . ': the body-carrying sections are not the first ' . $rendered . '.'
				);
			}
		}

		/*
		 * THE RENDERED FALLBACK, IN BOTH FORMATS. Printed rather than described: the
		 * HTML sample keeps its markup (with WooCommerce's inline styles stripped, since
		 * `style_inline()` rewrites `<p>` as `<p style="…">`), and the plain sample is
		 * the text body exactly as WooCommerce hands it to the mailer — 70-column
		 * wrapping and all.
		 */
		/*
		 * ⚠ ANCHORED ON `<strong>`, NEVER `<p><strong>`. WooCommerce's `style_inline()`
		 * rewrites the paragraph as `<p style="…"><strong>`, so a `<p><strong>` anchor
		 * matches nothing and `section_block()` falls back to the WHOLE body — footer,
		 * inline CSS and all. Found by reading the printed output rather than trusting
		 * it, which is the same trap the 8A sample fell into.
		 */
		$html_first = preg_replace(
			'/ style="[^"]*"/',
			'',
			$this->section_block( $bodies['html'], '<strong>' . $names[0] . '</strong>', '<strong>' . $names[1] . '</strong></p>' )
		);
		// THE TRANSITION: the LAST body-carrying unit followed by the first labelled one.
		$html_last = preg_replace(
			'/ style="[^"]*"/',
			'',
			$this->section_block( $bodies['html'], '<strong>' . $names[ $rendered - 1 ] . '</strong>', '<strong>' . $names[ $rendered ] . '</strong></p>' )
		);

		/*
		 * ⚠ THE PLAIN REMAINDER IS TAKEN FROM THE **LAST** `List:` ONWARDS, not from the
		 * first occurrence of unit 11's name — that name also appears inside every
		 * section's full-set list, so anchoring on it prints a slice of a list rather
		 * than the labelled tail.
		 */
		$last_list       = strrpos( $bodies['plain'], 'List: ' );
		$plain_remainder = false === $last_list ? $bodies['plain'] : substr( $bodies['plain'], $last_list );

		fwrite(
			STDERR,
			"\n[8B gate 27] all-three-placeholder template, " . $count . ' units, cap ' . $cap . ', '
			. $rendered . " body-carrying sections:\n"
			. '            template   = ' . self::BOUND_TEMPLATE . "\n"
			. '            HTML       = ' . strlen( $bodies['html'] ) . ' bytes, ' . $rendered
			. ' full-set lists, each name occurring at most ' . $max_name . " times\n"
			. '            plain      = ' . strlen( $bodies['plain'] ) . ' bytes, ' . $rendered . " full-set lists\n\n"
			. "            --- HTML, section 1 (a body-carrying unit) ---\n"
			. preg_replace( '/^/m', '            ', trim( $html_first ) ) . "\n\n"
			. '            --- HTML, the transition (' . $names[ $rendered - 1 ] . ' is the last to carry the body, '
			. $names[ $rendered ] . " is label only) ---\n"
			. preg_replace( '/^/m', '            ', trim( $html_last ) ) . "\n\n"
			. "            --- PLAIN, section 1 ---\n"
			. preg_replace(
				'/^/m',
				'            ',
				trim( $this->section_block( $bodies['plain'], $names[0], $names[1] . "\n" ) )
			) . "\n\n"
			. '            --- PLAIN, the last body-carrying unit and the labelled remainder (units '
			. ( $rendered + 1 ) . '-' . $count . ") ---\n"
			. preg_replace( '/^/m', '            ', trim( $plain_remainder ) ) . "\n"
		);

		$this->gate[] = 'fallback bound: ' . $count . ' units, ' . $rendered
			. ' full-set lists in both formats, every product represented';
	}

	/**
	 * 8B / gate 27. THE FALLBACK'S BODY AND ITS RETAINED VALUE OBJECTS GROW
	 *               **LINEARLY** IN THE UNIT COUNT (ADR-0016 §7a).
	 *
	 * ⚠ MEASURED AT THREE UNIT COUNTS AGAINST A DECLARED BOUND, not asserted with an
	 * inequality a quadratic would also satisfy at small N. The declared bound is
	 *
	 *     body(N) ≤ C × ( T + P × N × W ) + N × ( L + H )
	 *
	 * with C the cap in force, T the template bytes, P the full-set plural occurrences,
	 * W the bytes of one list entry, L the label bytes and H the per-section markup.
	 * That is **O(C·N + N)** — linear in N **for a fixed C**, which is what this test
	 * measures: it pins the cap at 5. ⚠ The qualification is real —
	 * `Consolidation::max_messages()` is filterable and is handed the ORDER, so a site
	 * returning a cap derived from the order's size makes `C = f(N)` and restores the
	 * quadratic term. That is a deliberate act on a safety limit, not a defect, and it
	 * is out of this assertion's scope by construction. The old mechanism's bound was
	 * `N × ( T + P × N × W )`, and the test also measures ONE section directly so the
	 * quadratic that shape predicts can be printed beside what was actually produced.
	 *
	 * ⚠ AND THE SECOND DIFFERENCE IS ASSERTED. A linear curve gives
	 * `size(40) − size(20) ≈ 2 × ( size(20) − size(10) )`; the quadratic gives closer to
	 * four times. That ratio is what separates the two shapes at these three points.
	 *
	 * ⚠ RETAINED MEMORY IS MEASURED WITH `WeakReference`, not with a comment. Each
	 * section's value set memoises whatever its body read, including a full-set plural
	 * string that is itself O(units); retaining one per unit would be quadratic memory
	 * beside the quadratic output. The sets must be released as their sections complete.
	 *
	 * @return void
	 */
	public function test_the_fallback_body_and_retained_sets_grow_linearly() {
		$category = $this->make_term( 'product_cat', 'WCEP Linear ' . uniqid() );
		$largest  = 40;

		list( $lines, $names ) = $this->padded_products( 'Linear', $largest, $category );

		$order   = $this->delayed_order( $lines );
		$matched = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			$matched[] = array(
				'item_id'      => (int) $item_id,
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => 0,
			);
		}

		$this->assertCount( $largest, $matched, 'the fixture order did not carry every product' );

		/*
		 * ⚠ A CAP BELOW THE SMALLEST MEASURED COUNT, so all three points are inside the
		 * fallback and measure ONE mechanism. At the default cap, ten units would fan
		 * out into ten messages and the smallest point would be measuring something
		 * else entirely.
		 */
		$cap      = 5;
		$template = self::BOUND_TEMPLATE;

		// THE BOUND'S DECLARED CONSTANTS.
		$label_bytes = max( array_map( 'strlen', $names ) );
		$entry_bytes = $label_bytes + 24;   // `999 × `, the separator and `<br />`.
		$markup      = 32;                  // `<p><strong></strong></p>` and a newline.
		$plurals     = 2;

		$bound = static function ( int $units ) use ( $cap, $template, $plurals, $entry_bytes, $label_bytes, $markup ): int {
			return ( $cap * ( strlen( $template ) + ( $plurals * $units * $entry_bytes ) ) )
				+ ( $units * ( $label_bytes + $markup ) );
		};

		$resolver = new PlaceholderResolver();
		$measured = array();
		$rows     = array();

		foreach ( array( 10, 20, 40 ) as $units ) {
			$subset = array_slice( $matched, 0, $units );
			$plan   = Consolidation::plan(
				array( 'consolidation' => Consolidation::PER_PRODUCT ),
				$subset,
				$cap
			);

			$this->assertTrue( $plan['capped'], $units . ' units did not reach the fallback' );

			$sections = $plan['messages'][0]['sections'];
			$this->assertCount( $units, $sections );

			$body = $resolver->render_sectioned_body( $order, $subset, $sections, $template );
			$size = strlen( $body['html'] );

			// ONE SECTION ON ITS OWN, so the shape the OLD mechanism produced can be
			// computed from the same fixture rather than guessed at.
			$one = $resolver->render_sectioned_body(
				$order,
				$subset,
				array( array_merge( $sections[0], array( 'content' => true ) ) ),
				$template
			);

			$quadratic = $units * strlen( $one['html'] );

			$measured[ $units ] = $size;
			$rows[]             = sprintf(
				'            %2d units: %6d bytes   bound %6d   unbounded-sections shape would be %6d',
				$units,
				$size,
				$bound( $units ),
				$quadratic
			);

			$this->assertLessThanOrEqual(
				$bound( $units ),
				$size,
				'⚠ the fallback body exceeded its declared linear bound at ' . $units . ' units.'
			);

			// EVERY PRODUCT IS STILL REPRESENTED at every measured size.
			foreach ( array_slice( $names, 0, $units ) as $name ) {
				$this->assertStringContainsString( $name, $body['html'] );
				$this->assertStringContainsString( $name, $body['plain'] );
			}

			$this->assertSame(
				$cap,
				substr_count( $body['html'], 'Names:' ),
				'the full-set list is not bounded by the cap at ' . $units . ' units'
			);
		}

		/*
		 * ⚠ THE SHAPE ASSERTION. Doubling the units doubles the increment when the
		 * curve is linear; a quadratic roughly quadruples it. 2.4 leaves room for the
		 * label term without admitting the curve this test exists to exclude.
		 */
		$first  = $measured[20] - $measured[10];
		$second = $measured[40] - $measured[20];

		$this->assertGreaterThan( 0, $first, 'the fixture produced no growth at all, so this proves nothing' );
		$this->assertLessThanOrEqual(
			2.4 * $first,
			(float) $second,
			'⚠ the fallback body is growing faster than linearly: ' . $second . ' vs 2 × ' . $first . '.'
		);

		/*
		 * RETAINED MEMORY. `WeakReference` holds no strong reference, so a set that is
		 * still alive after the render is one the renderer kept — which is the quadratic
		 * memory half of the defect (N sets, each memoising an O(N) full-set string).
		 */
		$refs    = array();
		$subset  = $matched;
		$plan    = Consolidation::plan( array( 'consolidation' => Consolidation::PER_PRODUCT ), $subset, $cap );
		$observe = static function ( PlaceholderValues $set ) use ( &$refs ): void {
			$refs[] = \WeakReference::create( $set );
		};

		$resolver->render_sectioned_body( $order, $subset, $plan['messages'][0]['sections'], $template, $observe );

		gc_collect_cycles();

		$alive = count(
			array_filter(
				array_map(
					static function ( \WeakReference $ref ) {
						return $ref->get();
					},
					$refs
				)
			)
		);

		$this->assertCount( $largest, $refs, 'the observer did not see one value set per unit' );
		$this->assertLessThanOrEqual(
			1,
			$alive,
			'⚠ the sectioned renderer retained ' . $alive . ' of ' . $largest
				. ' value sets; retained memory is not linear.'
		);

		fwrite(
			STDERR,
			"\n[8B gate 27] cap-fallback body size vs the declared linear bound (cap " . $cap . "):\n"
			. implode( "\n", $rows ) . "\n"
			. '            increments: ' . $first . ' then ' . $second . ' (linear ⇒ ~2×, quadratic ⇒ ~4×)' . "\n"
			. '            retained value sets after ' . $largest . ' sections: ' . $alive . "\n"
		);

		$this->gate[] = 'fallback bound: body linear at 10/20/40 units (' . implode(
			'/',
			array_values( $measured )
		) . ' bytes), ' . $alive . ' of ' . $largest . ' value sets retained';
	}

	/**
	 * 8B / Tier 2. A THROW IN A LATER SECTION STILL REPORTS THE EARLIER SECTIONS'
	 *              NOTES (ADR-0014 §1c).
	 *
	 * ⚠ THE COMMENT USED TO CLAIM THIS AND THE CODE DID NOT DO IT.
	 * `compose_sectioned()` handed the containment boundary the HEADER's value set, and
	 * the section sets only once EVERY section had completed — so a throw in section 3
	 * discarded sections 1 and 2's diagnostics entirely. The renderer now calls back
	 * before each section with the live set and the completed sections' merged notes.
	 *
	 * @return void
	 */
	public function test_a_throw_in_a_later_section_still_reports_the_earlier_notes() {
		$category = $this->make_term( 'product_cat', 'WCEP Partial ' . uniqid() );
		$count    = Consolidation::MAX_MESSAGES + 2;

		list( $lines ) = $this->padded_products( 'Partial', $count, $category );

		$order    = $this->delayed_order( $lines );
		$order_id = (int) $order->get_id();

		$rule_id = $this->make_fanout_rule(
			array(),
			array(
				'targeting'     => array( 'include' => array( 'categories' => array( $category ) ) ),
				'trigger_value' => 'processing',
				'subject'       => 'Your order',
				'heading'       => 'Your order',
				// ⚠ THE UNKNOWN TOKEN IS IN THE BODY ONLY. In the subject it would be
				// recorded by the HEADER's set, which reached the boundary even before
				// this fix, and the test would pass without proving anything.
				'content'       => '<p>{wcep_not_a_placeholder} NOTE=[{item_custom_field:gift_note}]</p>',
			)
		);

		$calls   = 0;
		$thrower = static function ( $allowed, $key ) use ( &$calls ) {
			if ( 'gift_note' !== $key ) {
				return $allowed;
			}

			++$calls;

			if ( 3 === $calls ) {
				throw new \RuntimeException( 'a meta filter exploded in section 3' );
			}

			return $allowed;
		};

		add_filter( PlaceholderValues::META_FILTER, $thrower, 10, 2 );

		try {
			// It does not escape into the status change.
			do_action( 'woocommerce_order_status_changed', $order_id, 'pending', 'processing', $order );
		} finally {
			remove_filter( PlaceholderValues::META_FILTER, $thrower, 10 );
		}

		$this->assertSame( 3, $calls, 'the fixture never reached section 3, so this proves nothing' );
		$this->assertMailCount( 0, 'the fallback message sent despite throwing mid-body' );

		$tombstone = $this->sole_tombstone( $order_id, $rule_id, 'status:processing' );
		$this->assertSame( 'failed', $tombstone['final_status'] );

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 1, $rows );

		$reason = (string) $rows[0]['reason'];

		// ⚠ SECTIONS 1 AND 2 HAD ALREADY RECORDED THE UNKNOWN TOKEN, and the failure
		// row reports it — de-duplicated to ONE note, because one authoring mistake is
		// one event however many sections met it (ADR-0014 §1a).
		$this->assertStringContainsString(
			'unknown placeholder {wcep_not_a_placeholder}',
			$reason,
			'⚠ the notes the completed sections had recorded never reached the failure row.'
		);
		$this->assertSame(
			1,
			substr_count( $reason, 'unknown placeholder {wcep_not_a_placeholder}' ),
			'the merged notes repeated one authoring mistake per section'
		);

		// The fallback's own diagnostic is still there beside it.
		$this->assertStringContainsString( 'exceeds the cap of ' . Consolidation::MAX_MESSAGES, $reason );

		$this->gate[] = 'partial notes: a throw in section 3 reports sections 1-2\'s notes, de-duplicated';
	}

	// -----------------------------------------------------------------------
	// 8C — A LABEL-ONLY SECTION MUST IDENTIFY ITS UNIT (gate 25, ADR-0016 §7a).
	// -----------------------------------------------------------------------

	/**
	 * A variable product whose variations WooCommerce titles WITHOUT their attributes.
	 *
	 * ⚠ THREE ATTRIBUTES, AND THAT IS THE PREMISE RATHER THAN A DETAIL. WC 10.9.4's
	 * `WC_Product_Variation_Data_Store_CPT::generate_product_title()` opens with
	 * `$should_include_attributes = count( $attributes ) < 3;`, so three attributes
	 * means the generated title is the bare parent name for every variation. (The other
	 * reachable condition is two-or-more attributes with a hyphenated attribute key,
	 * which is what a multi-word attribute name produces; three is unconditional, so it
	 * is the sturdier fixture.) Every test using this helper ASSERTS the premise, so a
	 * change in WooCommerce's naming makes the regression fail loudly rather than pass
	 * vacuously.
	 *
	 * @param string  $name  Parent product name.
	 * @param array[] $specs One attribute map per variation, e.g.
	 *                       `array( 'colour' => 'Red', 'size' => 'Large', 'fabric' => 'Cotton' )`.
	 * @param array   $args  Same optional properties as make_simple_product().
	 * @return array{parent:int,variations:int[]}
	 */
	private function make_collapsing_variable_product( string $name, array $specs, array $args = array() ): array {
		$labels     = array(
			'colour' => 'Colour',
			'size'   => 'Size',
			'fabric' => 'Fabric',
		);
		$attributes = array();

		foreach ( $labels as $key => $label ) {
			$options = array();

			foreach ( $specs as $spec ) {
				if ( isset( $spec[ $key ] ) ) {
					$options[] = (string) $spec[ $key ];
				}
			}

			$attribute = new \WC_Product_Attribute();
			$attribute->set_name( $label );
			$attribute->set_options( array_values( array_unique( $options ) ) );
			$attribute->set_visible( true );
			$attribute->set_variation( true );

			$attributes[] = $attribute;
		}

		$parent = new \WC_Product_Variable();
		$parent->set_name( $name );
		$parent->set_status( 'publish' );
		$parent->set_attributes( $attributes );

		if ( ! empty( $args['category_ids'] ) ) {
			$parent->set_category_ids( array_map( 'intval', $args['category_ids'] ) );
		}

		$parent_id = (int) $parent->save();
		$this->assertGreaterThan( 0, $parent_id, 'Could not create the variable product fixture.' );
		$this->product_ids[] = $parent_id;

		$variations = array();

		foreach ( $specs as $spec ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( $spec );
			$variation->set_regular_price( '12.00' );
			$variation->set_status( 'publish' );

			$variation_id = (int) $variation->save();
			$this->assertGreaterThan( 0, $variation_id, 'Could not create the variation fixture.' );

			$this->product_ids[] = $variation_id;
			$variations[]        = $variation_id;
		}

		return array(
			'parent'     => $parent_id,
			'variations' => $variations,
		);
	}

	/**
	 * Assert the premise this whole group depends on: WooCommerce titled every one of
	 * these variations with the BARE PARENT NAME.
	 *
	 * @param array  $variable Result of self::make_collapsing_variable_product().
	 * @param string $expected The parent name.
	 * @return void
	 */
	private function assertVariationTitlesCollapsed( array $variable, string $expected ): void {
		foreach ( $variable['variations'] as $variation_id ) {
			$this->assertSame(
				$expected,
				(string) wc_get_product( $variation_id )->get_name(),
				'⚠ PREMISE FAILED: WooCommerce no longer omits a 3-attribute variation\'s attributes from its '
					. 'generated title, so this test can no longer prove what it exists to prove. Re-read '
					. 'WC_Product_Variation_Data_Store_CPT::generate_product_title() and ADR-0016 §7a.'
			);
		}
	}

	/**
	 * The `<strong>` labels this plugin emitted, in order.
	 *
	 * ⚠ ANCHORED ON `<strong>` ALONE. WooCommerce's `style_inline()` rewrites the
	 * paragraph as `<p style="…"><strong>`, so a `<p><strong>` pattern matches nothing.
	 * Filtered to the fixture prefix so WooCommerce's own wrapper cannot contribute.
	 *
	 * @param string $body   Captured HTML body.
	 * @param string $prefix Fixture marker every label carries.
	 * @return string[]
	 */
	private function section_labels( string $body, string $prefix ): array {
		preg_match_all( '/<strong>(.*?)<\/strong>/s', $body, $found );

		return array_values(
			array_filter(
				$found[1],
				static function ( string $label ) use ( $prefix ): bool {
					return false !== strpos( $label, $prefix );
				}
			)
		);
	}

	/**
	 * A `per_product` rule over one category, with the cap filtered down.
	 *
	 * @param int    $category Category term id.
	 * @param int    $cap      Cap to force.
	 * @param string $content  Rule body.
	 * @return int Rule id.
	 */
	private function make_capped_rule( int $category, int $cap, string $content ): int {
		$this->hook(
			Consolidation::MAX_MESSAGES_FILTER,
			static function () use ( $cap ) {
				return $cap;
			},
			10,
			3
		);

		return $this->make_fanout_rule(
			array(),
			array(
				'targeting' => array( 'include' => array( 'categories' => array( $category ) ) ),
				'subject'   => 'Your order',
				'heading'   => 'Your order',
				'content'   => $content,
			)
		);
	}

	/**
	 * 8C-1 / gate 25. TWO LIVE SIBLING VARIATIONS ARE DISTINGUISHABLE IN A LABEL-ONLY
	 *                 SECTION, in BOTH formats (ADR-0016 §7a).
	 *
	 * ⚠ THE DEFECT 8B's OWN FIX MADE MATTER. A unit past the section bound carries its
	 * label and NOTHING ELSE — no merchant body, no placeholders — so the label is the
	 * whole of what the customer receives about it. `{product_name}` is the order item's
	 * stored name, and WooCommerce omits a variation's attributes from its generated
	 * title whenever the variation has three or more of them, so
	 * `variation:101` and `variation:102` both rendered as `T-Shirt`. Either line could
	 * be either unit, which is not "represented".
	 *
	 * ⚠ IN A **BODY** SECTION THIS WAS NEVER THE PLUGIN'S PROBLEM: the merchant can
	 * write `{variation_attributes}` and that is their tool and their choice. In a
	 * label-only section the label is OURS and they have no way to influence it.
	 *
	 * CAP FILTERED TO 1 so the second sibling is label-only, and an EMPTY body so
	 * nothing but the label can be doing the work.
	 *
	 * @return void
	 */
	public function test_two_sibling_variations_are_distinguishable_in_a_label_only_section() {
		$category = $this->make_term( 'product_cat', 'WCEP Sib ' . uniqid() );
		$parent   = 'WCEP Sibling Shirt';

		$variable = $this->make_collapsing_variable_product(
			$parent,
			array(
				array(
					'colour' => 'Red & Blue',
					'size'   => 'Large',
					'fabric' => 'Cotton',
				),
				array(
					'colour' => 'Green',
					'size'   => 'Small',
					'fabric' => 'Linen',
				),
			),
			array( 'category_ids' => array( $category ) )
		);

		// ⚠ THE PREMISE, ASSERTED. Both variations are titled with the bare parent name.
		$this->assertVariationTitlesCollapsed( $variable, $parent );

		$this->make_capped_rule( $category, 1, '' );

		$html  = $this->body_in_format( 'html', $this->delayed_order( $variable['variations'] ) );
		$plain = $this->body_in_format( 'plain', $this->delayed_order( $variable['variations'] ) );

		foreach ( array( 'html' => $html, 'plain' => $plain ) as $format => $body ) {
			// BOTH UNITS ARE IDENTIFIABLE — the attributes the title omitted are there.
			$this->assertStringContainsString( 'Size: Large', $body, $format . ': the first sibling is not identified' );
			$this->assertStringContainsString( 'Size: Small', $body, $format . ': the second sibling is not identified' );
			$this->assertStringContainsString( 'Fabric: Cotton', $body );
			$this->assertStringContainsString( 'Fabric: Linen', $body );

			// AND THEY ARE DIFFERENT LINES, which is the invariant.
			$this->assertNotSame(
				trim( (string) strstr( $body, 'Size: Large' ) ),
				trim( (string) strstr( $body, 'Size: Small' ) ),
				'⚠ two distinct units produced the same label.'
			);
		}

		/*
		 * ESCAPED PER DESTINATION (gate 16). The label is a VALUE — the attribute value
		 * carries an `&`, which must be an entity in HTML and a bare ampersand in text,
		 * and no raw markup may reach either.
		 */
		$this->assertStringContainsString( 'Colour: Red &amp; Blue', $html, 'the HTML label was not escaped' );
		$this->assertStringNotContainsString( 'Colour: Red & Blue', $html );
		$this->assertStringContainsString( 'Colour: Red & Blue', $plain, 'HTML escaping leaked into the text body' );
		$this->assertStringNotContainsString( '&amp;', $plain );

		// THE LABELS ARE THE ONLY THING IN THE BODY, and there are exactly two of them.
		$labels = $this->section_labels( $html, 'WCEP Sibling Shirt' );

		$this->assertCount( 2, $labels );
		$this->assertCount( 2, array_unique( $labels ), '⚠ two distinct units produced the same label.' );

		fwrite(
			STDERR,
			"\n[8C gate 25] two live siblings, 3 attributes, cap 1, EMPTY body — WooCommerce titled BOTH `"
			. $parent . "`:\n"
			. '            HTML  = ' . implode(
				' | ',
				array_map(
					static function ( string $label ): string {
						return '<p><strong>' . $label . '</strong></p>';
					},
					$labels
				)
			) . "\n"
			. "            plain =\n"
			// PRINTED WITH ITS LINE BREAKS INTACT: WooCommerce wraps every plain body at
			// 70 columns (`class-wc-email.php:872`), so a long label wraps, and hiding
			// that would be printing something the customer does not receive.
			. preg_replace( '/^/m', '              ', trim( $this->section_block( $plain, $parent, 'Linen' ) ) ) . "\n"
		);

		$this->gate[] = 'unit label: two sibling variations WooCommerce names identically are distinguishable in both formats';
	}

	/**
	 * 8C-2. THE SAME HOLDS FOR A PLACEHOLDER-FREE AND A SINGULAR-ONLY BODY.
	 *
	 * Both are ordinary templates for a single-product rule, and neither says anything
	 * that could distinguish two siblings — the placeholder-free one says nothing at
	 * all, and `{product_name}` resolves to the SAME collapsed title for both.
	 *
	 * @dataProvider sibling_template_provider
	 *
	 * @param string $content Rule body.
	 * @param string $label   What the case proves.
	 * @return void
	 */
	public function test_sibling_variations_stay_distinguishable_whatever_the_body_says( string $content, string $label ) {
		$category = $this->make_term( 'product_cat', 'WCEP SibT ' . uniqid() );
		$parent   = 'WCEP Body' . substr( md5( $label ), 0, 6 );

		$variable = $this->make_collapsing_variable_product(
			$parent,
			array(
				array(
					'colour' => 'Ochre',
					'size'   => 'Large',
					'fabric' => 'Cotton',
				),
				array(
					'colour' => 'Indigo',
					'size'   => 'Small',
					'fabric' => 'Linen',
				),
			),
			array( 'category_ids' => array( $category ) )
		);

		$this->assertVariationTitlesCollapsed( $variable, $parent );

		$this->make_capped_rule( $category, 1, $content );

		$body = $this->body_in_format( 'html', $this->delayed_order( $variable['variations'] ) );

		$labels = $this->section_labels( $body, $parent );

		$this->assertCount( 2, $labels, $label . ': a unit lost its section' );
		$this->assertCount( 2, array_unique( $labels ), '⚠ ' . $label . ': two distinct units produced the same label.' );
		$this->assertStringContainsString( 'Colour: Ochre', $body );
		$this->assertStringContainsString( 'Colour: Indigo', $body );

		/*
		 * ⚠ AND THE MERCHANT'S OWN `{product_name}` IS UNTOUCHED. The unit label is
		 * ours; the placeholder is theirs, and it still resolves to the line item's
		 * stored name exactly as ADR-0014 §5 says. Widening the placeholder would change
		 * every subject line in the plugin.
		 */
		if ( false !== strpos( $content, '{product_name}' ) ) {
			$this->assertStringContainsString( 'Guide for ' . $parent . '.', $body );
			$this->assertStringNotContainsString( 'Guide for ' . $parent . ' – ', $body );
		}

		$this->gate[] = 'unit label: ' . $label . ' still distinguishes two siblings';
	}

	/**
	 * Bodies that cannot distinguish two siblings on their own.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function sibling_template_provider(): array {
		return array(
			'a placeholder-free body' => array( '<p>Hand wash only.</p>', 'a placeholder-free body' ),
			'a singular-only body'    => array( '<p>Guide for {product_name}.</p>', 'a singular-only body' ),
		);
	}

	/**
	 * 8C-3. A PARTIALLY RESOLVED VARIATION KEEPS THE PARENT LABEL, with no invented
	 *       attributes (ADR-0011 §4, ADR-0016 §4).
	 *
	 * ⚠ THE ONE CASE WHERE ADDING DETAIL WOULD BE WRONG. ADR-0016 §4 collapses a deleted
	 * variation onto its parent unit precisely because the variation's own facts are no
	 * longer knowable; the parent name is then the correct and honest label, and
	 * inventing attributes for it would tell the customer about a variation nobody can
	 * identify. `unit_label()` reuses `{variation_attributes}`'s own guard for this.
	 *
	 * @return void
	 */
	public function test_a_partially_resolved_variation_keeps_the_parent_label() {
		$category = $this->make_term( 'product_cat', 'WCEP Dead8C ' . uniqid() );
		$parent   = 'WCEP Ghost Shirt';

		$variable = $this->make_collapsing_variable_product(
			$parent,
			array(
				array(
					'colour' => 'Rust',
					'size'   => 'Large',
					'fabric' => 'Cotton',
				),
				array(
					'colour' => 'Slate',
					'size'   => 'Small',
					'fabric' => 'Linen',
				),
			),
			array( 'category_ids' => array( $category ) )
		);

		$this->assertVariationTitlesCollapsed( $variable, $parent );

		$extra = $this->make_simple_product( 'WCEP Ghost Companion', array( 'category_ids' => array( $category ) ) );
		$order = $this->delayed_order( array( $variable['variations'][0], $variable['variations'][1], $extra ) );

		$this->make_capped_rule( $category, 1, '' );

		// BOTH VARIATIONS DELETED, PARENT ALIVE — they collapse into ONE parent unit.
		foreach ( $variable['variations'] as $variation_id ) {
			wp_delete_post( $variation_id, true );
		}

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 1 );

		$body   = $this->body( 0 );
		$labels = $this->section_labels( $body, 'WCEP Ghost' );

		// TWO UNITS: the collapsed parent, and the simple product.
		$this->assertSame( array( $parent, 'WCEP Ghost Companion' ), $labels );

		// ⚠ NOTHING INVENTED. No separator, no attribute the plugin could not read.
		$this->assertStringNotContainsString( PlaceholderValues::LABEL_SEPARATOR, $body, '⚠ attributes were invented for a variation nobody can identify.' );
		$this->assertStringNotContainsString( 'Colour:', $body );
		$this->assertStringNotContainsString( 'Rust', $body );
		$this->assertStringNotContainsString( 'Slate', $body );

		$this->gate[] = 'unit label: a partially resolved variation keeps the bare parent label, no invented attributes';
	}

	/**
	 * 8C-4. A SIMPLE PRODUCT'S LABEL IS BYTE-IDENTICAL to `{product_name}`.
	 *
	 * No churn where there is no problem: the change adds detail to a live VARIATION and
	 * to nothing else, so every existing assertion about a simple product's label stays
	 * exactly as true as it was.
	 *
	 * @return void
	 */
	public function test_a_simple_products_label_is_unchanged() {
		$category = $this->make_term( 'product_cat', 'WCEP Plain8C ' . uniqid() );
		$count    = Consolidation::MAX_MESSAGES + 1;

		list( $lines, $names ) = $this->padded_products( 'Plain8C', $count, $category );

		$this->make_fanout_rule(
			array(),
			array(
				'targeting' => array( 'include' => array( 'categories' => array( $category ) ) ),
				'subject'   => 'Your order',
				'heading'   => 'Your order',
				'content'   => '',
			)
		);

		$this->orchestrator()->run( $this->delayed_order( $lines ), TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 1 );

		$body = $this->body( 0 );

		// EXACTLY THE ITEM NAME, nothing appended, for every unit.
		$this->assertSame( $names, $this->section_labels( $body, 'WCEP-Plain8C' ) );
		$this->assertStringNotContainsString(
			PlaceholderValues::LABEL_SEPARATOR,
			$body,
			'⚠ a simple product\'s label gained detail it never had.'
		);

		$this->gate[] = 'unit label: all ' . $count . ' simple-product labels byte-identical to {product_name}';
	}

	/**
	 * 8C-5 / gate 25. THE INVARIANT, DIRECTLY: no two distinct fan-out units produce the
	 *                 same label.
	 *
	 * One fan-out carrying every unit shape ADR-0016 §4 defines — two simple products,
	 * two live siblings of one variable product whose titles collapse, and a partially
	 * resolved variation of a SECOND variable product — with the cap forced to 1 so
	 * every one of them is a label-only section.
	 *
	 * @return void
	 */
	public function test_no_two_units_produce_the_same_label() {
		$category = $this->make_term( 'product_cat', 'WCEP Inv ' . uniqid() );
		$live     = 'WCEP Inv Live';
		$ghost    = 'WCEP Inv Ghost';

		$siblings = $this->make_collapsing_variable_product(
			$live,
			array(
				array(
					'colour' => 'Amber',
					'size'   => 'Large',
					'fabric' => 'Cotton',
				),
				array(
					'colour' => 'Cobalt',
					'size'   => 'Small',
					'fabric' => 'Linen',
				),
			),
			array( 'category_ids' => array( $category ) )
		);
		$dead     = $this->make_collapsing_variable_product(
			$ghost,
			array(
				array(
					'colour' => 'Sand',
					'size'   => 'Large',
					'fabric' => 'Cotton',
				),
			),
			array( 'category_ids' => array( $category ) )
		);

		$this->assertVariationTitlesCollapsed( $siblings, $live );
		$this->assertVariationTitlesCollapsed( $dead, $ghost );

		$simple = array(
			$this->make_simple_product( 'WCEP Inv Simple A', array( 'category_ids' => array( $category ) ) ),
			$this->make_simple_product( 'WCEP Inv Simple B', array( 'category_ids' => array( $category ) ) ),
		);

		$order = $this->delayed_order(
			array_merge( $simple, $siblings['variations'], array( $dead['variations'][0] ) )
		);

		$this->make_capped_rule( $category, 1, '' );

		wp_delete_post( $dead['variations'][0], true );

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 1 );

		$body   = $this->body( 0 );
		$labels = $this->section_labels( $body, 'WCEP Inv' );

		// FIVE UNITS: two simple, two live siblings, one collapsed parent.
		$this->assertCount( 5, $labels, 'the fan-out did not produce one label per unit' );

		// ⚠ THE INVARIANT.
		$this->assertSame(
			count( $labels ),
			count( array_unique( $labels ) ),
			'⚠ two distinct fan-out units produced the same label: ' . implode( ' | ', $labels )
		);

		// And the collapsed parent is labelled by its bare name, beside two siblings
		// that are not — which is the asymmetry ADR-0016 §4 predicts.
		$this->assertContains( $ghost, $labels );
		$this->assertNotContains( $live, $labels );

		fwrite(
			STDERR,
			"\n[8C gate 25] the invariant, over every unit shape ADR-0016 §4 defines:\n"
			. '            ' . implode( "\n            ", $labels ) . "\n"
		);

		$this->gate[] = 'unit label INVARIANT: 5 distinct units (2 simple, 2 live siblings, 1 collapsed parent) = 5 distinct labels';
	}

	/**
	 * The rule's own sections, lifted out of WooCommerce's surrounding template.
	 *
	 * Anchored EXPLICITLY at both ends by the caller, because each template ends
	 * differently — a body with content ends on its own last sentence, an EMPTY one ends
	 * on the final label — and a greedy tail ran into WooCommerce's footer, whose inline
	 * `rgba(0,0,0,.2)` supplied the full stop a looser pattern was hunting for. The
	 * assertions are exact regardless; this only shapes the printed evidence.
	 *
	 * @param string $body  Captured message body.
	 * @param string $start Opening anchor.
	 * @param string $end   Closing anchor.
	 * @return string
	 */
	private function section_block( string $body, string $start, string $end ): string {
		$pattern = '/' . preg_quote( $start, '/' ) . '.*?' . preg_quote( $end, '/' ) . '/s';

		preg_match( $pattern, $body, $found );

		return (string) ( $found[0] ?? $body );
	}

	/**
	 * 9b. THE FILTER CAN RAISE THE CAP, and only an INTEGER decides.
	 *
	 * ⚠ ADR-0014 §6.5's GENERAL RULE, THIRD APPLICATION. The consent this filter grants
	 * is permission to send a customer more email than the plugin's own default allows,
	 * so a `WP_Error` — what a callback returns when it FAILED to decide — must not be
	 * read as a raised cap.
	 *
	 * @return void
	 */
	public function test_the_cap_filter_raises_it_and_only_an_integer_decides() {
		$this->assertSame( Consolidation::MAX_MESSAGES, Consolidation::max_messages(), 'the unfiltered cap is not the default' );

		$cases = array(
			'an integer raises it'   => array( 20, 20 ),
			'a float does not'       => array( 20.5, Consolidation::MAX_MESSAGES ),
			'a numeric string does not' => array( '20', Consolidation::MAX_MESSAGES ),
			'true does not'          => array( true, Consolidation::MAX_MESSAGES ),
			'null does not'          => array( null, Consolidation::MAX_MESSAGES ),
			'zero floors at one'     => array( 0, 1 ),
			'negative floors at one' => array( -5, 1 ),
		);

		foreach ( $cases as $label => $case ) {
			list( $returned, $expected ) = $case;

			$filter = static function () use ( $returned ) {
				return $returned;
			};

			add_filter( Consolidation::MAX_MESSAGES_FILTER, $filter, 10, 3 );

			try {
				$this->assertSame( $expected, Consolidation::max_messages(), $label );
			} finally {
				remove_filter( Consolidation::MAX_MESSAGES_FILTER, $filter, 10 );
			}
		}

		// ⚠ A `WP_Error` IS ITS OWN CASE, and it is the one the rule exists for.
		$errored = static function () {
			return new \WP_Error( 'undecided', 'the callback could not work out a cap' );
		};

		add_filter( Consolidation::MAX_MESSAGES_FILTER, $errored, 10, 3 );

		try {
			$this->assertSame(
				Consolidation::MAX_MESSAGES,
				Consolidation::max_messages(),
				'⚠ SECURITY: a WP_Error was read as permission to send a customer more email.'
			);
		} finally {
			remove_filter( Consolidation::MAX_MESSAGES_FILTER, $errored, 10 );
		}

		$this->gate[] = 'cap filter: an integer raises it, WP_Error and every non-integer leave the default, floor is 1';
	}

	/**
	 * 9c. A RAISED CAP REALLY FANS OUT, end to end.
	 *
	 * Without this, the fallback test above would be satisfied by a cap nothing can
	 * move.
	 *
	 * @return void
	 */
	public function test_a_raised_cap_fans_out_past_the_default() {
		$count = Consolidation::MAX_MESSAGES + 1;

		$lines = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$lines[] = $this->make_simple_product( 'WCEP Raised ' . $i );
		}

		$order    = $this->delayed_order( $lines );
		$order_id = (int) $order->get_id();
		$rule_id  = $this->make_fanout_rule( $lines );

		$raise = static function () use ( $count ) {
			return $count + 5;
		};

		add_filter( Consolidation::MAX_MESSAGES_FILTER, $raise, 10, 3 );

		try {
			$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );
		} finally {
			remove_filter( Consolidation::MAX_MESSAGES_FILTER, $raise, 10 );
		}

		$this->assertMailCount( $count, 'A raised cap did not fan out.' );

		$rows = $this->detail_rows( (int) $this->sole_tombstone( $order_id, $rule_id )['id'] );

		$this->assertCount( $count, $rows );
		$this->assertSame( $count + 5, (int) $this->consolidation_of( $rows[0] )['cap'] );
		$this->assertArrayNotHasKey( 'fallback', $this->consolidation_of( $rows[0] ) );

		$this->gate[] = 'raised cap: ' . $count . ' messages sent under a filtered cap of ' . ( $count + 5 );
	}

	// -----------------------------------------------------------------------
	// TEST 10 — DELAYED PLUS `per_product`.
	// -----------------------------------------------------------------------

	/**
	 * 10 / gate 25. ONE JOB IS SCHEDULED; at execution it FANS OUT; a re-run sends
	 *               nothing (ADR-0016 §8).
	 *
	 * ⚠ ONE JOB, NEVER N. N jobs would multiply the identity problem into the
	 * scheduler, where they would contend for one tombstone's lease and a partial
	 * failure would leave some of one delivery's messages queued and others not.
	 *
	 * @return void
	 */
	public function test_a_delayed_per_product_rule_schedules_one_job_and_fans_out_at_execution() {
		$products = array( $this->labelled_product( 'Delay1' ), $this->labelled_product( 'Delay2' ), $this->labelled_product( 'Delay3' ) );
		$order    = $this->delayed_order( $products );
		$order_id = (int) $order->get_id();

		$rule_id = $this->make_delayed_rule(
			$products[0],
			array(
				'consolidation' => Consolidation::PER_PRODUCT,
				'targeting'     => array( 'include' => array( 'products' => $products ) ),
				'subject'       => 'Delayed about {product_name}',
				'content'       => '<p>ONE=[{product_name}]</p>',
			)
		);

		$this->orchestrator()->run( $order, TriggerEvent::status( 'processing' ) );

		// --- SCHEDULING: ONE CLAIM, ONE JOB, NOTHING SENT YET -----------------
		$this->assertMailCount( 0, 'A delayed fan-out sent inline.' );

		$tombstone   = $this->scheduled_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone, 'The delayed fan-out never claimed.' );
		$delivery_id = (int) $tombstone['id'];

		$this->assertSame( 'scheduled', (string) $tombstone['final_status'] );
		$this->assertTrue( $this->has_job( $delivery_id, $order_id ), 'No job was queued.' );
		$this->assertCount( 1, $this->tombstones_for( $order_id ), '⚠ a delayed fan-out consumed more than one identity.' );

		// The SNAPSHOT carries the consolidation, or the job would fan out as `none`.
		$snapshot = $this->snapshot_of( $delivery_id );
		$this->assertSame( Consolidation::PER_PRODUCT, (string) $snapshot['consolidation'] );

		// --- EXECUTION: THE FAN-OUT HAPPENS NOW ------------------------------
		$this->run_job( $delivery_id, $order_id );

		$this->assertMailCount( 3, 'The delayed job did not fan out at execution.' );
		$this->assertSame(
			array( 'Delayed about WCEP Delay1', 'Delayed about WCEP Delay2', 'Delayed about WCEP Delay3' ),
			$this->subjects()
		);

		$this->assertSame( 'sent', $this->status_of( $delivery_id ) );

		$rows = $this->detail_rows( $delivery_id );

		// One `scheduled` diagnostic row plus three message rows.
		$this->assertSame( array( 'scheduled', 'sent', 'sent', 'sent' ), array_column( $rows, 'state' ) );
		$this->assertSame(
			array( 'product:' . $products[0], 'product:' . $products[1], 'product:' . $products[2] ),
			array_column( array_map( array( $this, 'consolidation_of' ), array_slice( $rows, 1 ) ), 'unit' )
		);

		/*
		 * --- A RE-RUN SENDS NOTHING ------------------------------------------
		 *
		 * `run_job()` clears the capture first (see its docblock), so the count after a
		 * re-run is what THAT run sent — and it must be zero. The tombstone is already
		 * terminal, so `run()` cannot take the `executing` lease and stops before it
		 * reaches the fan-out at all.
		 */
		$this->run_job( $delivery_id, $order_id );

		$this->assertMailCount( 0, '⚠ a re-run of a completed delayed fan-out re-sent its messages.' );
		$this->assertSame( 'sent', $this->status_of( $delivery_id ) );

		// AND IT WROTE NO EXTRA ROWS: the four from the first run are still all there is.
		$this->assertCount( 4, $this->detail_rows( $delivery_id ), 'The re-run wrote extra attempt rows.' );

		$this->gate[] = 'delayed fan-out: 1 claim, 1 job, 3 messages at execution, re-run sent 0';
	}

	// -----------------------------------------------------------------------
	// TEST 13 — RECIPIENTS.
	// -----------------------------------------------------------------------

	/**
	 * 13. A FAN-OUT WITH CUSTOMER + CC WRITES `messages x recipients` DETAIL ROWS,
	 *     each with its correct `recipient_type`.
	 *
	 * ⚠ ONE ROW PER RECIPIENT PER MESSAGE, NEVER A JOINED LIST. The privacy eraser
	 * finds rows with `WHERE recipient = %s` (ADR-0009), so a joined list would be
	 * invisible to a legally-required erasure request — and a fan-out multiplies the
	 * number of rows that have to be right.
	 *
	 * @return void
	 */
	public function test_a_fan_out_writes_one_row_per_recipient_per_message() {
		$products = array( $this->labelled_product( 'Rcpt1' ), $this->labelled_product( 'Rcpt2' ) );
		$order    = $this->delayed_order( $products );
		$order_id = (int) $order->get_id();

		$rule_id = $this->make_fanout_rule(
			$products,
			array(
				'recipients' => array(
					'to' => array( 'customer' ),
					'cc' => array( 'concierge@example.test' ),
				),
			)
		);

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 2 );

		$rows = $this->detail_rows( (int) $this->sole_tombstone( $order_id, $rule_id )['id'] );

		// 2 MESSAGES x 2 RECIPIENTS.
		$this->assertCount( 4, $rows, 'The row count is not messages x recipients.' );

		$pairs = array();
		foreach ( $rows as $row ) {
			$payload = $this->consolidation_of( $row );

			$pairs[] = array( (int) $payload['index'], (string) $row['recipient'], (string) $row['recipient_type'], (string) $row['state'] );
		}

		$this->assertSame(
			array(
				array( 1, 'ada@example.test', 'to', 'sent' ),
				array( 1, 'concierge@example.test', 'cc', 'sent' ),
				array( 2, 'ada@example.test', 'to', 'sent' ),
				array( 2, 'concierge@example.test', 'cc', 'sent' ),
			),
			$pairs,
			'⚠ a recipient row lost its message, its address or its type.'
		);

		$this->gate[] = 'recipients: 2 messages x 2 recipients = 4 rows, each with its own index, address and recipient_type';
	}

	// -----------------------------------------------------------------------
	// GATE 6 — THE FAN-OUT'S COST.
	// -----------------------------------------------------------------------

	/**
	 * GATE 6. A FAN-OUT OF N DOES NOT COST N TIMES A SINGLE DELIVERY'S MATCHING OR
	 *         RESOLUTION WORK.
	 *
	 * Stated as a formula rather than an absolute: the matcher runs ONCE, the product
	 * cache is shared, the order's line items are memoised on the order object, and
	 * recipients resolve once — so the per-message cost is the message itself and the
	 * difference between a fan-out of N and a combined delivery over the same products
	 * must not grow with N.
	 *
	 * ⚠ MEASURED BY WHAT THE QUERIES *ARE*, NOT ONLY BY HOW MANY. An inequality against
	 * `4 x` would pass even if the matcher ran twice, so the statements themselves are
	 * captured and classified: the RULES fetch and the ORDER-ITEM reads are counted, and
	 * a fan-out of 4 must issue exactly as many of each as a combined delivery over the
	 * same products. Whatever remains is the messages, which a fan-out genuinely has
	 * four of.
	 *
	 *     cost(fan-out of N) = matching(order) + resolution(order) + N x message-send
	 *
	 * @return void
	 */
	public function test_a_fan_out_does_not_multiply_the_matching_or_resolution_cost() {
		$products = array( $this->labelled_product( 'Cost1' ), $this->labelled_product( 'Cost2' ), $this->labelled_product( 'Cost3' ), $this->labelled_product( 'Cost4' ) );

		$measure = function ( string $consolidation ) use ( $products ): array {
			$rule_id = $this->make_fanout_rule( $products, array( 'consolidation' => $consolidation ) );

			// WARM EVERYTHING THAT IS NOT THE DELIVERY: option lookups, term caches and
			// the template's own reads all happen on any first delivery in a request, and
			// counting them would measure the cold cache rather than the fan-out.
			$this->orchestrator()->run( $this->delayed_order( $products ), TriggerEvent::status( 'completed' ) );

			$second = $this->delayed_order( $products );

			$seen     = array();
			$recorder = static function ( $query ) use ( &$seen ) {
				$seen[] = (string) $query;
				return $query;
			};

			add_filter( 'query', $recorder );

			try {
				$this->orchestrator()->run( $second, TriggerEvent::status( 'completed' ) );
			} finally {
				remove_filter( 'query', $recorder );
			}

			/*
			 * ⚠ DEACTIVATED BEFORE THE NEXT MEASUREMENT, AND WITHOUT THIS THE TEST WOULD
			 * MEASURE NOTHING. Rules persist for the whole test method, so the second call
			 * would have BOTH rules active on its orders — the `none` rule delivering
			 * alongside the `per_product` one — and the "fan-out" figure would include an
			 * entire second delivery. It is deactivated rather than deleted so its
			 * tombstones stay reachable for teardown.
			 */
			$this->assertTrue( $this->rules->update( $rule_id, array( 'status' => 'inactive' ) ) );

			$count = static function ( array $queries, string $pattern ): int {
				$matches = 0;

				foreach ( $queries as $query ) {
					if ( 1 === preg_match( $pattern, $query ) ) {
						++$matches;
					}
				}

				return $matches;
			};

			return array(
				'total'   => count( $seen ),
				// MATCHING: the one indexed rules fetch per trigger (ADR-0011 §7a).
				'rules'   => $count( $seen, '/FROM\s+\S*extonify_wcep_rules\s+WHERE\s+status/i' ),
				// RESOLUTION: every read of the order's line items and their meta.
				'items'   => $count( $seen, '/woocommerce_order_item(?:meta)?/i' ),
				/*
				 * RESOLUTION: every SELECT against the post tables that NAMES one of the
				 * matched products. Scoped to those ids on purpose — an unqualified
				 * `wp_posts` count also catches the order-note lookups below, which are
				 * WooCommerce's per-send cost rather than this plugin's resolution, and
				 * would make the measurement say the opposite of what it means.
				 */
				'product' => $count( $seen, '/^SELECT\b.*\bwp_post(?:s|meta)\b.*\b(?:' . implode( '|', array_map( 'intval', $products ) ) . ')\b/is' ),
				/*
				 * ⚠ NOT OURS, AND NAMED SO THE REMAINDER IS NOT MISREAD AS RESOLUTION.
				 * WooCommerce's own `EmailLogger` hooks `woocommerce_email_sent` and calls
				 * `WC_Order::add_order_note()`, which reaches `wp_update_comment_count()` and
				 * `get_post( $order_id )` — a MISS under HPOS, where an order is not a post,
				 * and WordPress does not cache misses. So every message this plugin sends
				 * costs a comment insert plus a handful of uncached order lookups, exactly as
				 * it would if the merchant had written N separate rules. Traced to that call
				 * chain rather than assumed.
				 */
				'notes'   => $count( $seen, '/wp_comment(?:s|meta)\b/i' ),
			);
		};

		$combined = $measure( Consolidation::NONE );
		$fanned   = $measure( Consolidation::PER_PRODUCT );

		// ⚠ THE MATCHING WORK IS IDENTICAL. One rules fetch for the trigger, whether the
		// decision becomes one message or four.
		$this->assertSame( 1, $combined['rules'], 'the combined baseline did not fetch its rules once' );
		$this->assertSame(
			$combined['rules'],
			$fanned['rules'],
			'⚠ a fan-out re-ran the matcher per message.'
		);

		// ⚠ AND SO IS THE RESOLUTION WORK. `WC_Order::get_items()` is memoised on the
		// order object and `ItemResolver`'s product cache is shared for the request, so
		// four messages read the order's contents and its products no more often than one
		// message does.
		$this->assertSame(
			$combined['items'],
			$fanned['items'],
			'⚠ a fan-out re-read the order\'s line items per message.'
		);
		$this->assertSame(
			$combined['product'],
			$fanned['product'],
			'⚠ a fan-out re-loaded the matched products per message.'
		);

		/*
		 * ⚠ AND THE REMAINDER IS ACCOUNTED FOR, so "the rest is messages" is a
		 * measurement rather than an assumption. Every query the fan-out issues beyond the
		 * combined baseline is either its own attempt rows or WooCommerce's per-send order
		 * note; the per-message increment must therefore be well under a whole delivery.
		 */
		$per_message = (int) round( ( $fanned['total'] - $combined['total'] ) / 3 );

		$this->assertLessThan(
			$combined['total'],
			$per_message,
			sprintf(
				'⚠ each extra message cost %d queries against a whole combined delivery\'s %d, '
					. 'so something delivery-wide is being repeated per message.',
				$per_message,
				$combined['total']
			)
		);

		// 1 + 1 combined, then 4 + 4 fanned — proof that each measurement really
		// delivered what its consolidation says, and that only one rule was active.
		$this->assertMailCount( 1 + 1 + 4 + 4 );

		fwrite(
			STDERR,
			sprintf(
				"\n[8 / gate 6] fan-out cost over the SAME 4 products, warm cache:\n"
					. "           none        (1 message):  %2d queries  [rules %d | order items %d | products %d | WC order notes %d]\n"
					. "           per_product (4 messages): %2d queries  [rules %d | order items %d | products %d | WC order notes %d]\n"
					. "           FORMULA  cost(N) = matching(%d) + resolution(%d items + %d product) + N x message-send\n"
					. "           The matching and resolution terms are IDENTICAL for N=1 and N=4, so NOTHING scales\n"
					. "           with N except the messages: +%d queries for 3 extra messages (%d each), against %d\n"
					. "           for a whole single delivery. Most of the per-message cost is WooCommerce's own\n"
					. "           EmailLogger order note, not this plugin.\n",
				$combined['total'],
				$combined['rules'],
				$combined['items'],
				$combined['product'],
				$combined['notes'],
				$fanned['total'],
				$fanned['rules'],
				$fanned['items'],
				$fanned['product'],
				$fanned['notes'],
				$fanned['rules'],
				$fanned['items'],
				$fanned['product'],
				$fanned['total'] - $combined['total'],
				$per_message,
				$combined['total']
			)
		);

		$this->gate[] = sprintf(
			'cost: a fan-out of 4 repeats NO matching (%d=%d) or resolution (items %d=%d, products %d=%d) work; '
				. 'total %d vs %d, i.e. %d per extra message against %d for a whole delivery',
			$combined['rules'],
			$fanned['rules'],
			$combined['items'],
			$fanned['items'],
			$combined['product'],
			$fanned['product'],
			$combined['total'],
			$fanned['total'],
			$per_message,
			$combined['total']
		);
	}

	// -----------------------------------------------------------------------
	// GATE 25 — THE INTEGRITY CLAUSES THAT ARE NOT OTHERWISE COVERED.
	// -----------------------------------------------------------------------

	/**
	 * GATE 25. NO MESSAGE CAN BE ATTRIBUTED TO ANOTHER'S PRODUCT — asserted over the
	 *          stored rows rather than only over the sent bodies.
	 *
	 * The bodies prove what the customer read; the rows prove what SUPPORT will read
	 * when a merchant asks "which one failed". Both have to be right, and they are
	 * written by different code.
	 *
	 * @return void
	 */
	public function test_no_stored_row_can_be_attributed_to_another_messages_product() {
		$products = array( $this->labelled_product( 'Attr1' ), $this->labelled_product( 'Attr2' ), $this->labelled_product( 'Attr3' ) );
		$order    = $this->delayed_order( $products );
		$order_id = (int) $order->get_id();

		$rule_id = $this->make_fanout_rule( $products );

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$rows = $this->detail_rows( (int) $this->sole_tombstone( $order_id, $rule_id )['id'] );

		$this->assertCount( 3, $rows );

		$units    = array();
		$subjects = array();

		foreach ( $rows as $row ) {
			$payload = $this->consolidation_of( $row );

			$units[]    = (string) $payload['unit'];
			$subjects[] = (string) $row['subject'];

			// The unit's product id and the subject's product name must describe the
			// SAME product.
			$this->assertSame(
				'About ' . wc_get_product( (int) $payload['product_id'] )->get_name(),
				(string) $row['subject'],
				'⚠ a row\'s recorded unit and its recorded subject describe different products.'
			);
		}

		$this->assertSame( $units, array_unique( $units ), 'Two messages claimed the same unit.' );
		$this->assertSame( $subjects, array_unique( $subjects ), 'Two messages carried the same subject.' );

		$this->gate[] = 'attribution: every row\'s unit, product id and subject describe the same product, and no two agree';
	}

	/**
	 * GATE 25. A CONSOLIDATED RULE AND A PLAIN ONE ON THE SAME ORDER STAY SEPARATE:
	 *          two decisions, two tombstones, and the fan-out does not touch the other
	 *          rule's message.
	 *
	 * ⚠ THIS IS WHERE CROSS-RULE MERGING WOULD HAVE LEAKED IN. `per_order` is out of
	 * scope (ADR-0016 §1), so two rules matching one order remain two independent
	 * deliveries with their own subjects, recipients and identities — which is exactly
	 * what makes merging them a separate feature rather than a third enum value.
	 *
	 * @return void
	 */
	public function test_a_consolidated_rule_and_a_plain_one_stay_independent() {
		$products = array( $this->labelled_product( 'Mix1' ), $this->labelled_product( 'Mix2' ) );
		$order    = $this->delayed_order( $products );
		$order_id = (int) $order->get_id();

		$fanned = $this->make_fanout_rule( $products, array( 'priority' => 5 ) );
		$plain  = $this->make_fanout_rule(
			$products,
			array(
				'priority'      => 10,
				'consolidation' => Consolidation::NONE,
				'subject'       => 'Plain combined',
			)
		);

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		// 2 from the fan-out + 1 from the plain rule.
		$this->assertMailCount( 3 );
		$this->assertSame(
			array( 'About WCEP Mix1', 'About WCEP Mix2', 'Plain combined' ),
			$this->subjects()
		);

		// TWO TOMBSTONES — one per rule, as ADR-0004 has always required.
		$this->assertCount( 2, $this->tombstones_for( $order_id ) );

		$fanned_tombstone = $this->tombstone( $order_id, $fanned, 'status:completed' );
		$plain_tombstone  = $this->tombstone( $order_id, $plain, 'status:completed' );

		$this->assertNotNull( $fanned_tombstone );
		$this->assertNotNull( $plain_tombstone );
		$this->assertSame( 'sent', $fanned_tombstone['final_status'] );
		$this->assertSame( 'sent', $plain_tombstone['final_status'] );

		$this->assertCount( 2, $this->detail_rows( (int) $fanned_tombstone['id'] ) );
		$this->assertCount( 1, $this->detail_rows( (int) $plain_tombstone['id'] ) );

		$this->gate[] = 'cross-rule: a consolidated rule and a plain one on one order = 2 tombstones, 2 + 1 messages, no merging';
	}

	/**
	 * GATE 25. A FAN-OUT UNDER A `stop_processing` HALT still records the halt on ITS
	 *          OWN rows, once per message, and the blocked rule claims nothing.
	 *
	 * The halt payload and the consolidation payload share the `snapshot` column, so
	 * this is the assertion that adding the second one did not displace the first
	 * (ADR-0012 §2).
	 *
	 * @return void
	 */
	public function test_a_fan_out_that_halts_records_both_payloads() {
		$products = array( $this->labelled_product( 'Halt1' ), $this->labelled_product( 'Halt2' ) );
		$order    = $this->delayed_order( $products );
		$order_id = (int) $order->get_id();

		$halter  = $this->make_fanout_rule( $products, array( 'priority' => 1, 'stop_processing' => 1 ) );
		$blocked = $this->make_fanout_rule(
			$products,
			array(
				'priority'      => 10,
				'consolidation' => Consolidation::NONE,
				'subject'       => 'Never sent',
			)
		);

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 2, 'The halting fan-out did not send its own messages.' );
		$this->assertNotContains( 'Never sent', $this->subjects() );

		// ADR-0012 §2: the BLOCKED rule claims nothing.
		$this->assertNull( $this->tombstone( $order_id, $blocked, 'status:completed' ), 'A blocked rule consumed an identity.' );
		$this->assertCount( 1, $this->tombstones_for( $order_id ) );

		$rows = $this->detail_rows( (int) $this->tombstone( $order_id, $halter, 'status:completed' )['id'] );

		$this->assertCount( 2, $rows );

		foreach ( $rows as $row ) {
			$snapshot = $this->snapshot_of_row( $row );

			$this->assertArrayHasKey( 'consolidation', $snapshot, 'the consolidation payload was displaced' );
			$this->assertArrayHasKey( 'blocked_by_stop_flag', $snapshot, '⚠ the halt record was displaced by the consolidation payload' );
			$this->assertSame( array( $blocked ), $snapshot['blocked_by_stop_flag']['rule_ids'] );
		}

		$this->gate[] = 'halt + fan-out: both snapshot payloads survive on every message row; the blocked rule claims nothing';
	}

	// -----------------------------------------------------------------------
	// 8A ITEM 2 / GATE 26 — AN INVALID STORED VALUE MUST NOT DELIVER.
	// -----------------------------------------------------------------------

	/**
	 * 8A-2 / gate 26. A LEGACY INVALID ROW DELIVERS NOTHING, IN EITHER SEPARATE-MODE
	 *                 PHASE: no claim, no mail, no job, no record.
	 *
	 * ⚠ ADR-0016 §1a USED TO SAY THIS ROW SHOULD SEND ONE COMBINED MESSAGE, and that was
	 * the defect: a delivery the merchant never configured is not "defence in depth", it
	 * is a wrong email with a note attached. `daily`, `weekly` and `per_order` were
	 * LEGITIMATELY STORABLE from Prompt 5B to Prompt 8, so this is the ordinary upgrade
	 * path — the fixture writes the column directly only because the repository now
	 * refuses these values, which is the whole point.
	 *
	 * @dataProvider legacy_invalid_provider
	 *
	 * @param string $value Legacy consolidation value.
	 * @return void
	 */
	public function test_a_legacy_invalid_rule_delivers_nothing_in_either_phase( string $value ) {
		$product_id = $this->labelled_product( 'Legacy' . ucfirst( $value ) );

		// --- THE IMMEDIATE PHASE ---------------------------------------------
		$immediate_order = $this->delayed_order( array( $product_id ) );
		$immediate_id    = (int) $immediate_order->get_id();

		$immediate_rule = $this->make_fanout_rule( array( $product_id ) );
		$this->force_raw_consolidation( $immediate_rule, $value );

		$result = $this->orchestrator()->run( $immediate_order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 0, "consolidation={$value} sent a message from the immediate phase." );
		$this->assertSame( array(), $this->tombstones_for( $immediate_id ), '⚠ an invalid rule consumed a delivery identity.' );

		// NEVER EVALUATED — the filter runs BEFORE the matcher, which is what stops it
		// halting a supported rule (see the next test).
		$this->assertNull(
			$result->evaluation()->decision_for( $immediate_rule ),
			'⚠ an invalid rule reached RuleMatcher; the read boundary must run BEFORE evaluation.'
		);

		// --- THE DELAYED PHASE ------------------------------------------------
		$delayed_order = $this->delayed_order( array( $product_id ) );
		$delayed_id    = (int) $delayed_order->get_id();

		$delayed_rule = $this->make_delayed_rule(
			$product_id,
			array(
				'consolidation' => Consolidation::PER_PRODUCT,
				'targeting'     => array( 'include' => array( 'products' => array( $product_id ) ) ),
			)
		);
		$this->force_raw_consolidation( $delayed_rule, $value );

		$this->orchestrator()->run( $delayed_order, TriggerEvent::status( 'processing' ) );

		$this->assertMailCount( 0, "consolidation={$value} sent a message from the delayed phase." );
		$this->assertSame( array(), $this->tombstones_for( $delayed_id ), '⚠ an invalid delayed rule consumed an identity.' );
		$this->assertNull(
			$this->scheduled_tombstone( $delayed_id, $delayed_rule ),
			'⚠ an invalid delayed rule was queued; the job could only have cancelled itself hours later.'
		);

		$this->gate[] = 'read boundary: consolidation=' . $value . ' delivers nothing in either phase — 0 mail, 0 tombstones, 0 jobs, never evaluated';
	}

	/**
	 * Queue one delayed delivery and return its ids.
	 *
	 * A local equivalent of `ScheduledRevalidationTest`'s own fixture, which is private
	 * to that class. Duplicated rather than hoisted into the shared test case because the
	 * two want different rules: that one is checking §4's re-validation checks in
	 * general, this one needs a `per_product` rule whose consolidation it can then
	 * corrupt.
	 *
	 * @return array{order_id:int,rule_id:int,delivery_id:int}
	 */
	private function schedule_one_delayed(): array {
		$product_id = $this->labelled_product( 'SchedLegacy' );
		$order      = $this->delayed_order( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_delayed_rule(
			$product_id,
			array(
				'consolidation' => Consolidation::PER_PRODUCT,
				'targeting'     => array( 'include' => array( 'products' => array( $product_id ) ) ),
			)
		);

		$this->orchestrator()->run( $order, TriggerEvent::status( 'processing' ) );

		$tombstone = $this->scheduled_tombstone( $order_id, $rule_id );

		$this->assertNotNull( $tombstone, 'the fixture did not schedule' );
		$this->assertSame( 'scheduled', (string) $tombstone['final_status'] );

		return array(
			'order_id'    => $order_id,
			'rule_id'     => $rule_id,
			'delivery_id' => (int) $tombstone['id'],
		);
	}

	/**
	 * The consolidation values that were storable before Prompt 8.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function legacy_invalid_provider(): array {
		return array(
			'daily'     => array( 'daily' ),
			'weekly'    => array( 'weekly' ),
			'per_order' => array( 'per_order' ),
		);
	}

	/**
	 * 8A-2 / gate 26. AN INVALID RULE CARRYING `stop_processing` AT PRIORITY 1 CANNOT
	 *                 HALT A VALID RULE AT PRIORITY 2.
	 *
	 * ⚠ THIS IS WHY THE DEFECT WAS TIER 1 RATHER THAN MERELY WRONG. Before this prompt
	 * `deliverable_in_this_phase()` checked mode, delay and `behaviour_is_implemented()`
	 * — and ADR-0016 §9 emptied that enumeration, so it returns true for everything.
	 * With no vocabulary check the invalid rule ENTERED `RuleMatcher`, matched, and its
	 * stop flag halted every rule behind it: the customer received an email the merchant
	 * never configured **and lost the one they did**.
	 *
	 * The same ordering argument ADR-0012 §9 makes for insert-mode rules: a filter
	 * applied after evaluation could not undo a halt that had already changed every
	 * later decision.
	 *
	 * @return void
	 */
	public function test_an_invalid_rule_with_stop_processing_cannot_halt_a_valid_one() {
		$product_id = $this->labelled_product( 'HaltLegacy' );
		$order      = $this->delayed_order( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$halter = $this->make_fanout_rule(
			array( $product_id ),
			array(
				'name'            => 'legacy daily rule that would halt',
				'priority'        => 1,
				'stop_processing' => 1,
				'subject'         => 'NEVER SENT',
			)
		);
		$this->force_raw_consolidation( $halter, 'daily' );

		$valid = $this->make_fanout_rule(
			array( $product_id ),
			array(
				'name'          => 'valid rule that must still send',
				'priority'      => 10,
				'consolidation' => Consolidation::NONE,
				'subject'       => 'STILL SENT',
			)
		);

		$result = $this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		// THE VALID RULE STILL SENDS.
		$this->assertMailCount( 1, '⚠ an invalid rule halted a valid one.' );
		$this->assertSame( array( 'STILL SENT' ), $this->subjects() );

		// THE INVALID RULE WAS NEVER EVALUATED, so it had no opportunity to halt.
		$this->assertNull(
			$result->evaluation()->decision_for( $halter ),
			'⚠ the invalid rule reached the matcher.'
		);
		$this->assertSame(
			\Extonify\WCEP\Domain\MatchDecision::MATCHED,
			$result->evaluation()->decision_for( $valid )->reason(),
			'the valid rule was blocked'
		);

		// ONE tombstone — the valid rule's. The invalid one claimed nothing.
		$tombstones = $this->tombstones_for( $order_id );
		$this->assertCount( 1, $tombstones );
		$this->assertSame( 'sent', $tombstones[0]['final_status'] );
		$this->assertNull( $this->tombstone( $order_id, $halter, 'status:completed' ) );

		$this->gate[] = 'read boundary: an invalid rule with stop_processing at priority 1 never evaluated; the valid rule at 10 still sent';
	}

	/**
	 * 8A-2 / gate 13. AN UNRELATED PARTIAL UPDATE TO A LEGACY INVALID ROW IS REFUSED,
	 *                 and the row is left BYTE-IDENTICAL.
	 *
	 * ⚠ THE EFFECTIVE VALUE IS VALIDATED ON EVERY WRITE, not only when the field is
	 * supplied (ADR-0016 §1a) — the `effective_mode()` precedent. Without it, renaming a
	 * legacy `daily` rule succeeded, bumped its revision, and left it exactly as
	 * undeliverable as before while the merchant's save appeared to work.
	 *
	 * @return void
	 */
	public function test_an_unrelated_update_to_a_legacy_invalid_row_is_refused() {
		$product_id = $this->labelled_product( 'PartialLegacy' );

		$rule_id = $this->make_fanout_rule( array( $product_id ) );
		$this->force_raw_consolidation( $rule_id, 'daily' );

		$before = $this->rules->find( $rule_id );

		// An edit that says nothing about consolidation at all.
		$this->assertFalse(
			$this->rules->update( $rule_id, array( 'name' => 'a new name' ) ),
			'⚠ an unrelated edit to a legacy invalid row was accepted.'
		);
		$this->assertSame( $before, $this->rules->find( $rule_id ), 'The refused update changed the stored row.' );

		// Nor can it be re-saved under a DIFFERENT invalid value.
		$this->assertFalse( $this->rules->update( $rule_id, array( 'consolidation' => 'weekly' ) ) );
		$this->assertSame( $before, $this->rules->find( $rule_id ) );

		// --- BUT AN EXPLICIT CORRECTION IS ACCEPTED ---------------------------
		foreach ( array( Consolidation::NONE, Consolidation::PER_PRODUCT ) as $corrected ) {
			$this->assertTrue(
				$this->rules->update( $rule_id, array( 'consolidation' => $corrected ) ),
				"A correction to {$corrected} was refused."
			);
			$this->assertSame( $corrected, (string) $this->rules->find( $rule_id )['consolidation'] );
		}

		// And once corrected, the ordinary edit that was refused now lands.
		$this->assertTrue( $this->rules->update( $rule_id, array( 'name' => 'a new name' ) ) );
		$this->assertSame( 'a new name', (string) $this->rules->find( $rule_id )['name'] );

		$this->gate[] = 'read boundary: a legacy invalid row refuses every write except an explicit correction, and is left byte-identical';
	}

	/**
	 * 8A-2 / gate 26. A SCHEDULED DELIVERY WHOSE RULE BECOMES INVALID DURING THE DELAY
	 *                 IS CANCELLED WITH ITS OWN DISTINCT REASON.
	 *
	 * `consolidation_invalid`, not `rule_left_phase`: check 3 catches a merchant CHANGING
	 * the rule, and this catches CORRUPT DATA. Telling the merchant they "changed the
	 * rule" would send them looking for an edit they never made (ADR-0015 §4 check 3a).
	 *
	 * @return void
	 */
	public function test_a_scheduled_delivery_whose_rule_became_invalid_is_cancelled() {
		$fixture = $this->schedule_one_delayed();

		// The rule becomes invalid AFTER the job is queued — a restore, a migration, or
		// simply a `daily` row that was storable when this job was scheduled.
		$this->force_raw_consolidation( $fixture['rule_id'], 'daily' );

		$this->run_job( $fixture['delivery_id'], $fixture['order_id'] );

		$this->assertMailCount( 0, '⚠ a delivery whose rule became invalid still sent.' );

		$this->assertSame(
			\Extonify\WCEP\Delivery\ScheduledDelivery::REASON_CONSOLIDATION_INVALID,
			$this->cancellation_code( $fixture['delivery_id'] ),
			'⚠ the cancellation used the wrong reason; a merchant would look for an edit they never made.'
		);
		$this->assertSame( 'cancelled', $this->status_of( $fixture['delivery_id'] ) );
		$this->assertStringContainsString(
			'not one this plugin recognises',
			$this->reasons_of( $fixture['delivery_id'] ),
			'The cancellation did not say what to do about it.'
		);

		$this->gate[] = 'read boundary: a rule that became invalid during the delay cancels `consolidation_invalid`, 0 mail';
	}

	/**
	 * GATE 10 / GATE 25. A DELIVERY STARTED FROM INSIDE MESSAGE 1 OF A FAN-OUT DOES NOT
	 *                    CORRUPT THE REST OF IT (ADR-0012 §11, ADR-0016 §3).
	 *
	 * ⚠ THE FAILURE SHAPE THIS PROJECT HAS NOW HIT FIVE TIMES, ARRIVING AT A NEW SCALE.
	 * One `Orchestrator` serves the whole request, and a send runs arbitrary third-party
	 * code that routinely changes another order's status — so an INNER delivery runs to
	 * completion in the middle of the outer fan-out's loop. Every per-run value the loop
	 * depends on (the plan, the `FanOutResult`, the containment state, the per-message
	 * binding) is a local or an argument for exactly this reason; anything parked on the
	 * shared object would be overwritten before the outer loop resumed at message 2.
	 *
	 * The re-entry point is the OUTER delivery's own `woocommerce_email_enabled_{id}`
	 * filter, which `trigger()` applies BEFORE recipient, content and headers are
	 * evaluated (ADR-0012 §11a) — the earliest point there is.
	 *
	 * @return void
	 */
	public function test_a_nested_delivery_inside_a_fan_out_leaves_the_remaining_messages_intact() {
		// WooCommerce's own notifications would otherwise join the count and make it
		// measure core rather than this plugin. This plugin's email is untouched.
		foreach ( array( 'new_order', 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order' ) as $native ) {
			$this->hook( 'woocommerce_email_enabled_' . $native, '__return_false', 10, 3 );
		}

		$outer_products = array( $this->labelled_product( 'Nest1' ), $this->labelled_product( 'Nest2' ), $this->labelled_product( 'Nest3' ) );
		$inner_product  = $this->labelled_product( 'NestInner' );

		$outer_order = $this->delayed_order( $outer_products );
		$outer_id    = (int) $outer_order->get_id();

		$inner_order = $this->delayed_order( array( $inner_product ) );
		$inner_id    = (int) $inner_order->get_id();

		$outer_rule = $this->make_fanout_rule( $outer_products );
		$inner_rule = $this->make_rule(
			array(
				'name'          => 'inner (nested in a fan-out)',
				'status'        => 'active',
				'delivery_mode' => 'separate',
				'trigger_type'  => 'status',
				'trigger_value' => 'processing',
				'targeting'     => array( 'include' => array( 'products' => array( $inner_product ) ) ),
				'recipients'    => array( 'to' => array( 'inner@example.test' ) ),
				'subject'       => 'INNER subject',
				'content'       => '<p>INNER body for {product_name}.</p>',
			)
		);

		$orchestrator = $this->orchestrator();

		// The inner event reaches the SAME orchestrator, exactly as `Events` would.
		$this->hook(
			'woocommerce_order_status_changed',
			static function ( $order_id, $from = '', $to = '' ) use ( $orchestrator, $inner_id ) {
				if ( (int) $order_id === $inner_id ) {
					$orchestrator->handle_status_change( (int) $order_id, (string) $from, (string) $to );
				}

				return $order_id;
			},
			10,
			3
		);

		// ONE SHOT, from the outer delivery's own enabled filter — so it lands inside
		// MESSAGE 1's `trigger()`, before that message has a recipient or a body.
		$fired = new \stdClass();
		$fired->yes = false;

		$this->hook(
			'woocommerce_email_enabled_' . \Extonify\WCEP\Email\EmailIdentity::EMAIL_ID,
			static function ( $enabled = true ) use ( $fired, $inner_id ) {
				if ( ! $fired->yes ) {
					$fired->yes = true;
					wc_get_order( $inner_id )->update_status( 'processing', 'nested fan-out fixture' );
				}

				return $enabled;
			},
			1,
			3
		);

		$outcome = $orchestrator->run( $outer_order, TriggerEvent::status( 'completed' ) );

		$this->assertTrue( $fired->yes, 'the fixture never re-entered, so this proves nothing' );

		// --- 3 OUTER MESSAGES + 1 INNER, AND THE INNER ONE LANDED FIRST -------
		$this->assertMailCount( 4, 'The nested delivery cost the fan-out a message.' );
		$this->assertSame(
			array( 'INNER subject', 'About WCEP Nest1', 'About WCEP Nest2', 'About WCEP Nest3' ),
			$this->subjects(),
			'⚠ the outer fan-out lost, duplicated or mis-bound a message after the nested delivery.'
		);

		// --- THE OUTER FAN-OUT IS INTACT: one tombstone, three rows, right units.
		$outer_tombstone = $this->tombstone( $outer_id, $outer_rule, 'status:completed' );
		$this->assertNotNull( $outer_tombstone );
		$this->track_delivery( (int) $outer_tombstone['id'] );

		$this->assertSame( 'sent', $outer_tombstone['final_status'], '⚠ the nested delivery corrupted the outer aggregate.' );

		$outer_rows = $this->detail_rows( (int) $outer_tombstone['id'] );

		$this->assertCount( 3, $outer_rows );
		$this->assertSame( array( 'sent', 'sent', 'sent' ), array_column( $outer_rows, 'state' ) );
		$this->assertSame(
			array( 'product:' . $outer_products[0], 'product:' . $outer_products[1], 'product:' . $outer_products[2] ),
			array_column( array_map( array( $this, 'consolidation_of' ), $outer_rows ), 'unit' ),
			'⚠ the nested delivery displaced the outer fan-out\'s per-message binding.'
		);
		$this->assertSame(
			array( 'About WCEP Nest1', 'About WCEP Nest2', 'About WCEP Nest3' ),
			array_column( $outer_rows, 'subject' )
		);

		// --- AND THE INNER DELIVERY COMPLETED ON ITS OWN TOMBSTONE ------------
		$inner_tombstone = $this->tombstone( $inner_id, $inner_rule, 'status:processing' );
		$this->assertNotNull( $inner_tombstone, 'The nested delivery never claimed.' );
		$this->track_delivery( (int) $inner_tombstone['id'] );

		$this->assertSame( 'sent', $inner_tombstone['final_status'] );
		$this->assertCount( 1, $this->detail_rows( (int) $inner_tombstone['id'] ) );

		// The RUN still reports one record for the outer rule.
		$this->assertSame( 1, $outcome->count_of( RunOutcome::SENT ) );
		$this->assertTrue( $outcome->is_fully_recorded() );

		// The shared email object ends clean, so nothing later inherits a frame.
		$email = $this->live_email();
		$this->assertSame( '', $email->recipient );
		$this->assertSame( '', $email->delivery_subject );
		$this->assertNull( $email->object );

		$this->gate[] = 're-entrancy: a nested delivery inside message 1 left all 3 outer messages, their bindings '
			. 'and both tombstones intact';
	}

	/**
	 * GATE 25. THE GLOBAL KILL SWITCH IS STILL CHECKED BEFORE ANYTHING IS CLAIMED, so
	 *          a switched-off store consumes no identity and sends no message of a
	 *          fan-out (ADR-0012 §5).
	 *
	 * @return void
	 */
	public function test_the_kill_switch_stops_a_fan_out_before_it_claims() {
		$products = array( $this->labelled_product( 'Kill1' ), $this->labelled_product( 'Kill2' ) );
		$order    = $this->delayed_order( $products );
		$order_id = (int) $order->get_id();

		$this->make_fanout_rule( $products );

		$this->set_email_setting( 'enabled', 'no' );

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 0, 'A fan-out sent while the feature was switched off.' );
		$this->assertSame( array(), $this->tombstones_for( $order_id ), '⚠ a switched-off store consumed a delivery identity.' );

		// AND SWITCHING IT BACK ON IS NOT A ONE-WAY DOOR: the same trigger now delivers.
		$this->set_email_setting( 'enabled', 'yes' );

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 2, 'Re-enabling the feature did not restore the fan-out.' );

		$this->gate[] = 'kill switch: 0 messages and 0 tombstones while off; re-enabling delivers all 2';
	}
}
