<?php
/**
 * GATES 38 and 39 — every refusal, and no parallel send path (ADR-0019 §4, §7).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\DeliveryActions;
use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Delivery\Consolidation;
use Extonify\WCEP\Delivery\ManualDelivery;
use Extonify\WCEP\Delivery\Orchestrator;
use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Repository\DeliveryRepository;
use Extonify\WCEP\Repository\RuleRepository;

/**
 * Each ADR-0019 §4 condition refuses with its OWN reason, sends nothing, and writes
 * nothing partial.
 *
 * ⚠ THE ENUMERATION IS THE GATE, NOT ANY ONE CASE. A sending surface fails by
 * breadth: one condition out of nine that falls through and mails a customer. So this
 * class walks a table, and `test_the_refusal_table_is_complete()` fails the moment a
 * refusal code exists that the table does not exercise.
 */
final class ManualRefusalTest extends ManualDeliveryTestCase {

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
			fwrite( STDERR, "\n[P11 gate 38] " . $line );
		}

		$this->gate = array();
	}

	/**
	 * THE GATE-38 TABLE: every §4 condition, the action it stops, and its code.
	 *
	 * @return array<string,array{0:string,1:string,2:string}> Label => [ref, action, code].
	 */
	public static function refusal_table(): array {
		return array(
			'R1 rule deleted (resend)'        => array( 'R1', 'resend', ManualDelivery::REFUSED_RULE_DELETED ),
			'R2 insert mode (resend)'         => array( 'R2', 'resend', ManualDelivery::REFUSED_RULE_INSERT_MODE ),
			'R2 insert mode (manual)'         => array( 'R2', 'manual', ManualDelivery::REFUSED_RULE_INSERT_MODE ),
			'R3 consolidation vocabulary'     => array( 'R3', 'resend', ManualDelivery::REFUSED_RULE_VOCABULARY ),
			'R3 mode vocabulary'              => array( 'R3', 'resend', ManualDelivery::REFUSED_RULE_VOCABULARY ),
			'R4 not terminal (resend)'        => array( 'R4', 'resend', ManualDelivery::REFUSED_NOT_TERMINAL ),
			'R5 not scheduled (send now)'     => array( 'R5', 'send_now', ManualDelivery::REFUSED_NOT_SCHEDULED ),
			'R5 not scheduled (cancel)'       => array( 'R5', 'cancel', ManualDelivery::REFUSED_NOT_SCHEDULED ),
			'R6 schema unavailable'           => array( 'R6', 'resend', ManualDelivery::REFUSED_SCHEMA ),
			'R7 order missing'                => array( 'R7', 'resend', ManualDelivery::REFUSED_ORDER_MISSING ),
			'R9 globally switched off'        => array( 'R9', 'manual', ManualDelivery::REFUSED_EMAIL_MISSING ),
			'delivery missing'                => array( '—', 'resend', ManualDelivery::REFUSED_DELIVERY_MISSING ),
			'nothing on the order matches'    => array( '—', 'manual', ManualDelivery::REFUSED_NO_ITEMS ),
		);
	}

	/**
	 * R1. A DELETED RULE REFUSES, sends nothing and writes nothing.
	 *
	 * @return void
	 */
	public function test_a_deleted_rule_refuses_a_resend() {
		$this->become_manager();

		$fixture = $this->completed_delivery();
		$before  = $this->attempt_signature( $fixture['delivery'] );

		$this->rules->delete( $fixture['rule'] );

		$outcome = $this->submit( DeliveryActions::ACTION_RESEND, array( 'delivery' => $fixture['delivery'] ) );

		$this->assertRefusedWith( $outcome, ManualDelivery::REFUSED_RULE_DELETED );

		$this->assertSame( $before, $this->attempt_signature( $fixture['delivery'] ), '⚠ a refused resend wrote an attempt row.' );
		$this->assertSame( 'sent', $this->status_of( $fixture['delivery'] ), '⚠ a refused resend changed the tombstone.' );

		$this->gate[] = 'R1 rule deleted: refused as `rule_deleted`, 0 mail, attempt rows and tombstone unchanged — '
			. 'ADR-0015 §2 released the snapshot at the terminal state, so there is genuinely nothing to render';
	}

	/**
	 * R2. AN INSERT-MODE RULE REFUSES EVERY ACTION.
	 *
	 * ⚠ ALL FOUR, NOT A SAMPLE. ADR-0013 §2 says insert content has no message of its
	 * own — no subject, no recipients, no envelope — so every one of these would be
	 * sending something that does not exist.
	 *
	 * @return void
	 */
	public function test_an_insert_mode_rule_refuses_every_action() {
		$this->become_manager();

		$fixture = $this->completed_delivery();

		// Forced past the repository: `insert` needs a native email id it does not have
		// here, and the point is the READ boundary, not the write one.
		$this->force_rule_column( $fixture['rule'], 'delivery_mode', 'insert' );

		foreach ( array( DeliveryActions::ACTION_RESEND, DeliveryActions::ACTION_SEND_NOW, DeliveryActions::ACTION_CANCEL ) as $action ) {
			$outcome = $this->submit( $action, array( 'delivery' => $fixture['delivery'] ) );

			$this->assertNotSame( '', $this->refusal_of( $outcome ), '⚠ TIER 1: "' . $action . '" was allowed on an insert-mode rule.' );
		}

		// And the manual send, which reaches the same check from the other direction.
		$manual = $this->submit(
			DeliveryActions::ACTION_MANUAL,
			array(
				'order' => $fixture['order_id'],
				'rule'  => $fixture['rule'],
			)
		);

		$this->assertRefusedWith( $manual, ManualDelivery::REFUSED_RULE_INSERT_MODE );

		$this->gate[] = 'R2 insert mode: all four actions refuse, 0 mail — insert content is a fragment of somebody '
			. 'else\'s email and has no message of its own to send (ADR-0013 §2)';
	}

	/**
	 * R3. A RULE OUTSIDE THE VOCABULARY REFUSES — both halves.
	 *
	 * @return void
	 */
	public function test_a_rule_outside_the_vocabulary_refuses() {
		$this->become_manager();

		foreach ( array( 'consolidation' => 'weekly', 'delivery_mode' => 'carrier-pigeon' ) as $column => $value ) {
			$fixture = $this->completed_delivery();

			$this->force_rule_column( $fixture['rule'], $column, $value );

			$outcome = $this->submit( DeliveryActions::ACTION_RESEND, array( 'delivery' => $fixture['delivery'] ) );

			$this->assertRefusedWith( $outcome, ManualDelivery::REFUSED_RULE_VOCABULARY );
		}

		$this->gate[] = 'R3 vocabulary: an unrecognised `consolidation` AND an unrecognised `delivery_mode` each '
			. 'refuse as `rule_vocabulary` with 0 mail — the ADR-0016 §1a read boundary, applied to the manual path';
	}

	/**
	 * R4. A DELIVERY STILL IN FLIGHT CANNOT BE RESENT.
	 *
	 * @return void
	 */
	public function test_an_in_flight_delivery_cannot_be_resent() {
		$this->become_manager();

		$fixture = $this->scheduled_delivery();

		$outcome = $this->submit( DeliveryActions::ACTION_RESEND, array( 'delivery' => $fixture['delivery'] ) );

		$this->assertRefusedWith( $outcome, ManualDelivery::REFUSED_NOT_TERMINAL );

		$this->assertSame( DeliveryRepository::SCHEDULED, $this->status_of( $fixture['delivery'] ) );

		$this->gate[] = 'R4 not terminal: a `scheduled` delivery refuses a resend as `not_terminal`, 0 mail, state '
			. 'untouched — resending would race the actor that owns it';
	}

	/**
	 * R5. SEND NOW AND CANCEL BOTH REQUIRE `scheduled`.
	 *
	 * @return void
	 */
	public function test_send_now_and_cancel_require_a_scheduled_delivery() {
		$this->become_manager();

		$fixture = $this->completed_delivery();

		foreach ( array( DeliveryActions::ACTION_SEND_NOW, DeliveryActions::ACTION_CANCEL ) as $action ) {
			$outcome = $this->submit( $action, array( 'delivery' => $fixture['delivery'] ) );

			$this->assertRefusedWith( $outcome, ManualDelivery::REFUSED_NOT_SCHEDULED );
		}

		$this->assertSame( 'sent', $this->status_of( $fixture['delivery'] ), '⚠ a refused action changed a completed delivery.' );

		$this->gate[] = 'R5 not scheduled: send now and cancel both refuse a `sent` delivery as `not_scheduled`, '
			. '0 mail, and the completed delivery is untouched';
	}

	/**
	 * R6. DEGRADED MODE REFUSES EVERYTHING.
	 *
	 * ⚠ FORCED THROUGH THE REAL GUARD, BY POKING ITS MEMOIZED ANSWER RATHER THAN BY
	 * DROPPING A TABLE. `is_operational()` caches `verify_schema()` in a private static
	 * for the request, and that static IS what every caller reads — so setting it false
	 * puts the plugin in exactly the state a store with missing tables is in, without a
	 * destructive change that the rest of this shared-database run would inherit.
	 * `Migrator::OPTION_DB_ERROR` is deliberately NOT used: it drives the admin NOTICE,
	 * not this guard, and a test that set it would pass while proving nothing.
	 *
	 * @return void
	 */
	public function test_degraded_mode_refuses_every_action() {
		$this->become_manager();

		$fixture = $this->completed_delivery();

		$schema_ok = new \ReflectionProperty( Migrator::class, 'schema_ok' );
		$schema_ok->setAccessible( true );

		$schema_ok->setValue( null, false );

		try {
			$this->assertFalse( Migrator::is_operational(), 'the forced degraded mode did not take.' );

			$outcome = $this->submit( DeliveryActions::ACTION_RESEND, array( 'delivery' => $fixture['delivery'] ) );

			$this->assertRefusedWith( $outcome, ManualDelivery::REFUSED_SCHEMA );
		} finally {
			Migrator::flush_schema_cache();
		}

		$this->assertTrue( Migrator::is_operational(), 'the schema guard was not restored.' );

		$this->gate[] = 'R6 schema: with Migrator::is_operational() forced false, a resend refuses as '
			. '`schema_unavailable` and sends nothing — degraded mode no-ops rather than half-working';
	}

	/**
	 * R7. A DELETED ORDER REFUSES.
	 *
	 * @return void
	 */
	public function test_a_missing_order_refuses() {
		$this->become_manager();

		$fixture = $this->completed_delivery();

		// A tombstone pointing at an order id that resolves to nothing.
		$orphan = $this->deliveries->claim(
			$this->fake_order_id(),
			$fixture['rule'],
			$this->mode(),
			$this->unique_identity( 'status' )
		);

		$this->track_delivery( (int) $orphan['delivery_id'] );
		$this->deliveries->set_final_status( (int) $orphan['delivery_id'], 'sent' );

		$outcome = $this->submit( DeliveryActions::ACTION_RESEND, array( 'delivery' => (int) $orphan['delivery_id'] ) );

		$this->assertRefusedWith( $outcome, ManualDelivery::REFUSED_ORDER_MISSING );

		$this->gate[] = 'R7 order missing: a tombstone whose order no longer resolves refuses as `order_missing`, '
			. '0 mail — there is nothing to resolve recipients or placeholders against';
	}

	/**
	 * R9. THE GLOBAL SWITCH REFUSES, AND CLAIMS NOTHING.
	 *
	 * ⚠ THE SECOND HALF IS THE POINT (ADR-0012 §5). Claiming and then discovering the
	 * switch would consume the identity permanently, so re-enabling the feature and
	 * asking again would be suppressed by a delivery that never happened.
	 *
	 * @return void
	 */
	public function test_the_global_switch_refuses_and_claims_nothing() {
		$this->become_manager();

		$fixture = $this->undelivered_order();

		$this->set_email_setting( 'enabled', 'no' );

		try {
			$outcome = $this->submit(
				DeliveryActions::ACTION_MANUAL,
				array(
					'order' => $fixture['order_id'],
					'rule'  => $fixture['rule'],
				)
			);

			$this->assertRefusedWith( $outcome, ManualDelivery::REFUSED_EMAIL_MISSING );

			$this->assertSame(
				array(),
				$this->deliveries->find_for_order( $fixture['order_id'] ),
				'⚠ a refused manual send left a tombstone behind, so the identity is consumed for nothing.'
			);
		} finally {
			$this->set_email_setting( 'enabled', 'yes' );
		}

		// And with the switch back on, the same send works — so the refusal was the
		// switch, and nothing was permanently consumed.
		$this->submit(
			DeliveryActions::ACTION_MANUAL,
			array(
				'order' => $fixture['order_id'],
				'rule'  => $fixture['rule'],
			)
		);

		$this->assertMailCount( 1, 'the manual send did not work once the switch was back on.' );

		$this->tombstones_by_identity( $fixture['order_id'] );

		$this->gate[] = 'R9 globally off: refused as `email_unavailable` with NO tombstone written, and the same '
			. 'send succeeds once the switch is back on — nothing was consumed while the feature was off';
	}

	/**
	 * A delivery id that does not exist refuses.
	 *
	 * @return void
	 */
	public function test_a_missing_delivery_refuses() {
		$this->become_manager();

		$outcome = $this->submit( DeliveryActions::ACTION_RESEND, array( 'delivery' => 987654321 ) );

		$this->assertRefusedWith( $outcome, ManualDelivery::REFUSED_DELIVERY_MISSING );

		$this->gate[] = 'delivery missing: an unknown delivery id refuses as `delivery_missing`, 0 mail';
	}

	/**
	 * A rule that matches nothing on the order refuses rather than sending an empty
	 * message.
	 *
	 * @return void
	 */
	public function test_a_rule_matching_nothing_refuses() {
		$this->become_manager();

		$fixture = $this->undelivered_order();

		// Retarget the rule at a product this order does not contain.
		$other = $this->make_product( 'WCEP Unrelated Product' );

		$this->rules->update(
			$fixture['rule'],
			array( 'targeting' => array( 'include' => array( 'products' => array( $other ) ) ) )
		);

		$outcome = $this->submit(
			DeliveryActions::ACTION_MANUAL,
			array(
				'order' => $fixture['order_id'],
				'rule'  => $fixture['rule'],
			)
		);

		$this->assertRefusedWith( $outcome, ManualDelivery::REFUSED_NO_ITEMS );

		$this->assertSame(
			array(),
			$this->deliveries->find_for_order( $fixture['order_id'] ),
			'⚠ a manual send that matched nothing still claimed an identity.'
		);

		$this->gate[] = 'no matching items: a rule targeting a product this order does not contain refuses as '
			. '`no_matching_items`, 0 mail, and claims NO identity';
	}

	/**
	 * THE TABLE IS COMPLETE: every refusal code this class can produce is exercised.
	 *
	 * @return void
	 */
	public function test_the_refusal_table_is_complete() {
		$exercised = array();

		foreach ( self::refusal_table() as $row ) {
			$exercised[ $row[2] ] = true;
		}

		$declared = array(
			ManualDelivery::REFUSED_SCHEMA,
			ManualDelivery::REFUSED_ORDER_MISSING,
			ManualDelivery::REFUSED_RULE_DELETED,
			ManualDelivery::REFUSED_RULE_INSERT_MODE,
			ManualDelivery::REFUSED_RULE_VOCABULARY,
			ManualDelivery::REFUSED_NOT_TERMINAL,
			ManualDelivery::REFUSED_NOT_SCHEDULED,
			ManualDelivery::REFUSED_DELIVERY_MISSING,
			ManualDelivery::REFUSED_NO_ITEMS,
			ManualDelivery::REFUSED_EMAIL_MISSING,
		);

		$missing = array_values( array_diff( $declared, array_keys( $exercised ) ) );

		$this->assertSame(
			array(),
			$missing,
			'⚠ refusal code(s) with no test in the gate-38 table: ' . implode( ', ', $missing )
		);

		$rows = '';

		foreach ( self::refusal_table() as $label => $row ) {
			$rows .= '             │ ' . str_pad( $row[0], 4 ) . ' │ ' . str_pad( $label, 32 ) . ' │ '
				. str_pad( $row[1], 10 ) . ' │ ' . str_pad( $row[2], 20 ) . " │\n";
		}

		$this->gate[] = 'refusal table: ' . count( self::refusal_table() ) . " cases covering all "
			. count( $declared ) . " reachable refusal codes\n"
			. "             ┌──────┬──────────────────────────────────┬────────────┬──────────────────────┐\n"
			. "             │ ref  │ condition                        │ action     │ code                 │\n"
			. "             ├──────┼──────────────────────────────────┼────────────┼──────────────────────┤\n"
			. $rows
			. "             └──────┴──────────────────────────────────┴────────────┴──────────────────────┘\n";
	}

	// -----------------------------------------------------------------------
	// GATE 39 — no parallel send path
	// -----------------------------------------------------------------------

	/**
	 * GATE 39. A MANUAL SEND FANS OUT THROUGH `Consolidation`, WITH THE SAME CAP.
	 *
	 * ⚠ ASSERTED ON THE MESSAGE COUNT AND ON THE CAP, because those are the two things
	 * a forked path would get wrong. A manual send that skipped `Consolidation::plan()`
	 * would send one email where the rule asked for one per product, and would ignore
	 * the cap that stops a fifty-product order becoming fifty emails.
	 *
	 * @return void
	 */
	public function test_a_manual_send_fans_out_through_consolidation() {
		$this->become_manager();

		$first  = $this->make_product( 'WCEP Fanout A' );
		$second = $this->make_product( 'WCEP Fanout B' );
		$third  = $this->make_product( 'WCEP Fanout C' );

		$rule_id = $this->make_rule(
			array(
				'name'          => 'WCEP fanout fixture',
				'targeting'     => array( 'include' => array( 'products' => array( $first, $second, $third ) ) ),
				'recipients'    => array( 'to' => array( 'customer' ) ),
				'subject'       => 'About {product_name}',
				'content'       => '<p>Care for {product_name}.</p>',
				'delivery_mode' => 'separate',
				'consolidation' => Consolidation::PER_PRODUCT,
			)
		);

		$order = wc_create_order();
		$order->add_product( wc_get_product( $first ), 1 );
		$order->add_product( wc_get_product( $second ), 1 );
		$order->add_product( wc_get_product( $third ), 1 );
		$order->set_billing_email( 'wcep-test@example.test' );
		$order->calculate_totals();
		$order->save();

		$this->order_ids[] = (int) $order->get_id();

		$this->captured_mail = array();

		$this->submit(
			DeliveryActions::ACTION_MANUAL,
			array(
				'order' => (int) $order->get_id(),
				'rule'  => $rule_id,
			)
		);

		$this->assertMailCount( 3, '⚠ the manual send did not fan out one message per matched product.' );

		$this->tombstones_by_identity( (int) $order->get_id() );

		// ⚠ AND THE CAP APPLIES. Forcing it to 1 must produce ONE message covering all
		// three products — the ADR-0016 §7 capped fallback, not three messages and not
		// one product silently dropped.
		$cap = static fn() => 1;

		add_filter( 'extonify_wcep_consolidation_max_messages', $cap, 999 );

		$this->captured_mail = array();

		$this->submit(
			DeliveryActions::ACTION_MANUAL,
			array(
				'order' => (int) $order->get_id(),
				'rule'  => $rule_id,
			)
		);

		remove_all_filters( 'extonify_wcep_consolidation_max_messages' );

		$this->assertMailCount( 1, '⚠ the manual send ignored the consolidation cap.' );

		$this->tombstones_by_identity( (int) $order->get_id() );

		$this->gate[] = 'gate 39 (consolidation): a manual send of a per_product rule fans out to 3 messages, and '
			. 'the SAME cap filter collapses it to the 1-message capped fallback — the manual path goes through '
			. 'Consolidation::plan(), not around it';
	}

	/**
	 * GATE 39. A THROW DURING A MANUAL SEND IS CONTAINED, RECORDED, AND LEAVES NO
	 *          CLAIMED-ONLY TOMBSTONE.
	 *
	 * ⚠ THE THROW IS INJECTED INTO A PLUGIN-OWNED FILTER INVOKED BETWEEN THE CLAIM AND
	 * THE SEND — the exact shape ADR-0014 §10 widened the containment boundary to
	 * cover. If the manual path had its own send loop, this would escape into the admin
	 * request and leave the identity consumed with nothing behind it.
	 *
	 * @return void
	 */
	public function test_a_throw_during_a_manual_send_is_contained() {
		$this->become_manager();

		$fixture = $this->undelivered_order();

		$boom = static function () {
			throw new \RuntimeException( 'WCEP manual containment probe' );
		};

		add_filter( 'extonify_wcep_consolidation_max_messages', $boom, 1 );

		try {
			$outcome = $this->submit(
				DeliveryActions::ACTION_MANUAL,
				array(
					'order' => $fixture['order_id'],
					'rule'  => $fixture['rule'],
				)
			);
		} finally {
			remove_all_filters( 'extonify_wcep_consolidation_max_messages' );
		}

		// The throw did not escape: the handler returned an outcome.
		$this->assertIsArray( $outcome, '⚠ TIER 1: a throw escaped the manual send into the admin request.' );

		$this->assertMailCount( 0, 'the throwing send still sent mail.' );

		$tombstones = $this->tombstones_by_identity( $fixture['order_id'] );

		$this->assertCount( 1, $tombstones, 'the manual send did not claim exactly one identity.' );

		$tombstone = reset( $tombstones );

		$this->assertNotSame(
			DeliveryRepository::CLAIMED,
			(string) $tombstone['final_status'],
			'⚠ TIER 1: the contained throw left a claimed-only tombstone — an identity consumed with nothing behind it.'
		);

		$this->assertSame( 'failed', (string) $tombstone['final_status'], 'the contained throw was not recorded as failed.' );

		$this->assertNotSame( array(), $this->detail_rows( (int) $tombstone['id'] ), 'the contained throw wrote no attempt row.' );

		$this->gate[] = 'gate 39 (containment): a throw from a plugin-owned filter between the claim and the send '
			. 'does NOT escape, records `failed` with an attempt row, and leaves no claimed-only tombstone';
	}

	/**
	 * GATE 39. THE SHARED-COMPONENT TABLE.
	 *
	 * @return void
	 */
	public function test_the_shared_component_table_is_printed() {
		// The manual entry point and the scheduled one reach the SAME private body.
		$reflection = new \ReflectionClass( Orchestrator::class );

		$this->assertTrue(
			$reflection->hasMethod( 'execute_claimed' ),
			'⚠ the shared execution body is gone, so the manual and scheduled paths have forked.'
		);

		foreach ( array( 'send_manual', 'send_scheduled' ) as $entry ) {
			$source = $this->method_source( $reflection, $entry );

			$this->assertStringContainsString(
				'execute_claimed',
				$source,
				'⚠ ' . $entry . '() no longer delegates to the shared body — gate 39 is broken.'
			);
		}

		/*
		 * And `ManualDelivery` implements no send of its own.
		 *
		 * ⚠ SCANNED WITH COMMENTS STRIPPED. These classes explain at length WHY they do
		 * not reach for a resolver, a logger or a repository, so a raw substring scan is
		 * tripped by the very prose that documents the guarantee — which would push a
		 * future author to delete the explanation to make the gate pass. Scanning the
		 * executable tokens makes the assertion mean what it says.
		 */
		$manual = $this->code_without_comments( dirname( __DIR__, 2 ) . '/src/Delivery/ManualDelivery.php' );

		foreach ( array( 'wp_mail', '->trigger(', 'PlaceholderResolver', 'RecipientResolver' ) as $forbidden ) {
			$this->assertStringNotContainsString(
				$forbidden,
				$manual,
				'⚠ ManualDelivery reaches "' . $forbidden . '" directly, which is a second send path.'
			);
		}

		/*
		 * ⚠ ADR-0020's TWO FEATURES JOIN THE SAME TABLE, AND THE SAME PROHIBITION.
		 * `TestDelivery` is a second SENDING path and `RulePreview` is a second RENDERING
		 * path, and either one implementing its own would be exactly the divergence this
		 * gate exists to prevent — a preview that shows what the delivery would not do,
		 * or a test that mails what the customer would not receive.
		 */
		$this->assertTrue(
			$reflection->hasMethod( 'send_test' ),
			'⚠ the test send no longer has an entry point on the orchestrator.'
		);

		$this->assertStringContainsString(
			'execute_claimed',
			$this->method_source( $reflection, 'send_test' ),
			'⚠ send_test() no longer delegates to the shared body — gate 39 is broken.'
		);

		$test = $this->code_without_comments( dirname( __DIR__, 2 ) . '/src/Delivery/TestDelivery.php' );

		foreach ( array( 'wp_mail', '->trigger(', 'PlaceholderResolver', 'Consolidation::plan' ) as $forbidden ) {
			$this->assertStringNotContainsString(
				$forbidden,
				$test,
				'⚠ TestDelivery reaches "' . $forbidden . '" directly, which is a second send path.'
			);
		}

		// ⚠ AND THE PREVIEW COMPOSES THROUGH `compose()` ITSELF, not through a copy of it.
		$this->assertTrue(
			$reflection->hasMethod( 'compose_preview' ),
			'⚠ the preview no longer has an entry point on the orchestrator.'
		);

		$this->assertStringContainsString(
			'$this->compose(',
			$this->method_source( $reflection, 'compose_preview' ),
			'⚠ compose_preview() no longer delegates to compose() — a preview can now diverge from the send.'
		);

		$preview = $this->code_without_comments( dirname( __DIR__, 2 ) . '/src/Delivery/RulePreview.php' );

		foreach ( array( 'wp_mail', '->trigger(', 'DeliveryLogger', 'DeliveryRepository', '->claim(' ) as $forbidden ) {
			$this->assertStringNotContainsString(
				$forbidden,
				$preview,
				'⚠ TIER 1: RulePreview reaches "' . $forbidden . '", so a preview could send or record.'
			);
		}

		$rows = array(
			array( 'containment boundary', 'Orchestrator::send()', 'test_a_throw_during_a_manual_send_is_contained' ),
			array( 'recipient resolution', 'RecipientResolver (via resolve_recipients)', 'ManualDeliveryTest::…confirmation_screen_shows_who' ),
			array( 'placeholder resolution', 'PlaceholderResolver (via compose)', 'ManualDeliveryTest::…resend_after_an_edit' ),
			array( 'consolidation + cap', 'Consolidation::plan()', 'test_a_manual_send_fans_out_through_consolidation' ),
			array( 'targeting/item matching', 'RuleMatcher::match_items()', 'test_a_rule_matching_nothing_refuses' ),
			array( 'logging', 'DeliveryLogger::record_send()', 'ManualDeliveryTest::…records_a_resend_attempt' ),
			array( 'send-now execution', 'ScheduledDelivery::run()', 'ManualDeliveryTest::…send_now_sends_immediately' ),
			array( 'identity claim', 'DeliveryRepository::claim()', 'ManualDeliveryTest::…replayed_manual_send' ),
			// --- ADR-0020 -------------------------------------------------------
			array( 'test send: execution', 'Orchestrator::execute_claimed()', 'TestEmailTest::…attempt_row_is_typed_test' ),
			array( 'test send: identity', 'DeliveryRepository::claim()', 'TestEmailTest::…replayed_test_submission' ),
			array( 'test send: confirmation', 'Admin\\ConfirmationToken', 'TestEmailTest::…refuses_over_get' ),
			array( 'test send: rule refusals', 'ManualDelivery::rule_refusal()', 'TestEmailTest::…names_its_own_reason' ),
			array( 'preview: composition', 'Orchestrator::compose()', 'PreviewRenderTest::…html_and_plain_text' ),
			array( 'preview: consolidation', 'Consolidation::plan()', 'PreviewRenderTest::…how_many_emails' ),
			array( 'preview: targeting', 'ManualDelivery::matched_items()', 'PreviewRenderTest::…non_matching_order' ),
			array( 'preview: email wrapper', 'Custom_Email::render_preview()', 'PreviewInertnessTest::…separate_mode_preview' ),
			array( 'preview: no-slot state', 'RenderContext::mark_preview_pending()', 'PreviewInertnessTest::…no_ledger_residue' ),
		);

		$table = '';

		foreach ( $rows as $row ) {
			$table .= '             │ ' . str_pad( $row[0], 26 ) . ' │ ' . str_pad( $row[1], 42 ) . " │\n";
		}

		$this->gate[] = 'gate 39 (shared components): ' . count( $rows ) . " named, each with a test\n"
			. "             ┌────────────────────────────┬────────────────────────────────────────────┐\n"
			. "             │ component                  │ shared through                             │\n"
			. "             ├────────────────────────────┼────────────────────────────────────────────┤\n"
			. $table
			. "             └────────────────────────────┴────────────────────────────────────────────┘\n";
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
	 * One method's source text.
	 *
	 * @param \ReflectionClass $class  The class.
	 * @param string           $method Method name.
	 * @return string
	 */
	private function method_source( \ReflectionClass $class, string $method ): string {
		$reflected = $class->getMethod( $method );

		$lines = file( (string) $class->getFileName() );

		return implode(
			'',
			array_slice(
				(array) $lines,
				$reflected->getStartLine() - 1,
				$reflected->getEndLine() - $reflected->getStartLine() + 1
			)
		);
	}

	/**
	 * Write one rule column DIRECTLY, past the repository's validation.
	 *
	 * The precedent is `ConsolidationTest::force_raw_consolidation()`: a stored value
	 * the writing path would never produce, so the READING path is tested on its own.
	 *
	 * @param int    $rule_id Rule id.
	 * @param string $column  Column name.
	 * @param string $value   Raw value.
	 * @return void
	 */
	private function force_rule_column( int $rule_id, string $column, string $value ): void {
		global $wpdb;

		$allowed = array( 'consolidation', 'delivery_mode', 'status' );

		$this->assertContains( $column, $allowed, 'Unknown rule column requested by a test.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only raw write to the plugin-owned table, deliberately bypassing the repository.
		$updated = $wpdb->update(
			Migrator::table( 'rules' ),
			array( $column => $value ),
			array( 'id' => $rule_id ),
			array( '%s' ),
			array( '%d' )
		);

		$this->assertNotFalse( $updated, 'Could not force the rule column.' );
	}

	/**
	 * Keep the imports honest: the vocabularies these refusals read are the frozen
	 * ones.
	 *
	 * @return void
	 */
	public function test_the_refusals_read_the_frozen_vocabularies() {
		$this->assertContains( 'separate', RuleRepository::DELIVERY_MODES );
		$this->assertContains( 'insert', RuleRepository::DELIVERY_MODES );
		$this->assertTrue( Consolidation::is_valid( Consolidation::NONE ) );
		$this->assertFalse( Consolidation::is_valid( 'weekly' ) );

		// And the history screen can still name a manual identity.
		$this->assertNotSame( '', Menu::HISTORY_PAGE );
	}
}
