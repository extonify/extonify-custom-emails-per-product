<?php
/**
 * Phase filtering, rule snapshotting and send-exception containment
 * (ADR-0012 §3, §9, §10).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\DeliveryLogger;
use Extonify\WCEP\Delivery\Orchestrator;
use Extonify\WCEP\Delivery\ScheduledPhase;
use Extonify\WCEP\Domain\MatchDecision;
use Extonify\WCEP\Domain\TriggerEvent;

/**
 * "Out of scope" has to mean LEFT ALONE, not "processed by whatever path
 * happens to exist".
 *
 * An insert rule reaching this phase was matched, claimed under a mode it never
 * had, and delivered to the customer as a standalone email — content meant to
 * appear inside their normal order email arriving as a surprise message. These
 * tests are what stop that returning.
 */
final class DeliveryPhaseTest extends DeliveryTestCase {

	/**
	 * 1. A rule belonging to ANOTHER PHASE produces nothing at all: no email,
	 *    and no tombstone under EITHER mode.
	 *
	 * @dataProvider out_of_phase_rule_provider
	 *
	 * @param array  $overrides Rule fields putting it in another phase.
	 * @param string $label     Which phase owns it.
	 * @return void
	 */
	public function test_a_rule_from_another_phase_is_left_entirely_untouched( array $overrides, string $label ) {
		$product_id = $this->make_simple_product( 'WCEP Phase ' . md5( $label ) );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule( $product_id, $overrides );

		$result = $this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 0, $label . ' was delivered by this phase.' );

		// The rule was never even EVALUATED, so it has no decision.
		$this->assertNull(
			$result->evaluation()->decision_for( $rule_id ),
			$label . ' reached the matcher; the filter must run BEFORE evaluation.'
		);

		// NO TOMBSTONE UNDER ANY MODE. Claiming under `separate` would consume
		// an identity the rule never had, so the phase that owns it could never
		// deliver it.
		foreach ( array( 'separate', 'insert' ) as $mode ) {
			$this->assertNull(
				$this->deliveries->find( $order_id, $rule_id, $mode, 'status:completed' ),
				$label . ' consumed an identity under mode=' . $mode . '.'
			);
		}

		$this->assertSame( array(), $this->tombstones_for( $order_id ), $label . ' wrote a tombstone.' );
	}

	/**
	 * Rules the IMMEDIATE phase must not deliver.
	 *
	 * ⚠ THE DELAYED CASES LEFT THIS PROVIDER IN PROMPT 7 (ADR-0015 §7). They
	 * asserted that a delayed rule produced *nothing at all* — no mail and no
	 * tombstone — which was true only while nobody implemented delay. It now
	 * SCHEDULES: it claims its identity, stores a snapshot and queues a job, and
	 * `ScheduledDeliveryTest::test_immediate_and_delayed_rules_are_disjoint()`
	 * asserts the half that still belongs here — that it does not ALSO send
	 * inline. Leaving the old cases in place would have meant either a failing
	 * suite or, worse, weakening them into passing against the new behaviour.
	 *
	 * ⚠ THE CONSOLIDATION CASES LEFT THIS PROVIDER IN PROMPT 8, AND THEY LEFT FOR A
	 * STRICTLY STRONGER REASON THAN THE DELAYED ONES DID (ADR-0016 §1). They asserted
	 * that a rule carrying `daily` produced nothing at all, which was true only while
	 * `consolidation` was validated by SHAPE and filtered out of every phase. It is now
	 * an EXHAUSTIVE ENUMERATION at the repository boundary, so `daily`, `weekly` and
	 * `per_order` cannot be STORED — which is stronger than a phase filter that had to
	 * remember them, and is asserted in
	 * self::test_an_unrecognised_consolidation_cannot_be_stored_at_all(). Leaving the
	 * old cases here would have meant a failing suite, because the fixture itself can no
	 * longer be created.
	 *
	 * What remains is insert mode, which is owned by a phase this one must not touch.
	 *
	 * @return array<string,array{0:array,1:string}>
	 */
	public static function out_of_phase_rule_provider(): array {
		return array(
			'insert mode' => array(
				array(
					'delivery_mode'   => 'insert',
					'native_email_id' => 'customer_completed_order',
					'insert_position' => 'after_order_table',
				),
				'an insert-mode rule',
			),
		);
	}

	/**
	 * 1a-ii / gate 13. The consolidation values that used to be FILTERED can no
	 *                  longer be STORED (ADR-0016 §1).
	 *
	 * ⚠ WHY THIS REPLACES A PHASE-FILTER ASSERTION RATHER THAN JOINING IT. Under shape
	 * validation `daily` was storable and the phase filter was the ONLY thing keeping
	 * it from being delivered with ordinary immediate, once-per-trigger behaviour —
	 * `none`'s behaviour under another name, out-of-scope behaviour reachable through
	 * DATA rather than through code. One missing filter turned a merchant's digest
	 * request into an email per order. A value that cannot exist needs no filter.
	 *
	 * @dataProvider refused_consolidation_provider
	 *
	 * @param string $value Raw consolidation value.
	 * @return void
	 */
	public function test_an_unrecognised_consolidation_cannot_be_stored_at_all( string $value ) {
		$refused = $this->rules->insert(
			array(
				'name'          => 'consolidation ' . $value,
				'status'        => 'active',
				'delivery_mode' => 'separate',
				'trigger_type'  => 'status',
				'trigger_value' => 'completed',
				'consolidation' => $value,
			)
		);

		$this->assertSame( 0, $refused, sprintf( 'consolidation "%s" was stored.', $value ) );
	}

	/**
	 * Every consolidation value outside the closed vocabulary.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function refused_consolidation_provider(): array {
		return array(
			'daily'     => array( 'daily' ),
			'weekly'    => array( 'weekly' ),
			// ⚠ CROSS-RULE MERGING IS NOT A VALUE OF THIS COLUMN (ADR-0016 §1). It
			// merges across different delivery identities and needs a superseding ADR,
			// not an extra enumeration member.
			'per_order' => array( 'per_order' ),
		);
	}

	/**
	 * 1a. A rule belonging to BOTH other phases — insert AND delayed — cannot be
	 *     stored at all since Prompt 5.
	 *
	 * This data set used to live in the provider above, where it asserted that
	 * the phase filter left such a rule untouched. ADR-0013 §2 now refuses the
	 * combination at the REPOSITORY, which is strictly stronger: the rule never
	 * reaches the filter because it never reaches the database. Asserted here so
	 * the case is still covered and the reason it moved is on the record.
	 *
	 * @return void
	 */
	public function test_an_insert_rule_with_a_delay_cannot_be_stored_at_all() {
		$refused = $this->rules->insert(
			array(
				'name'            => 'insert and delayed',
				'status'          => 'active',
				'delivery_mode'   => 'insert',
				'native_email_id' => 'customer_completed_order',
				'delay_seconds'   => 604800,
			)
		);

		$this->assertSame( 0, $refused, 'A rule belonging to two other phases was stored.' );
	}

	/**
	 * 1b. THE ORDERING MATTERS: an insert rule carrying `stop_processing` must
	 *     not halt the separate rules, because this is not its phase.
	 *
	 * A filter applied AFTER evaluation could not undo a halt that had already
	 * changed every later decision.
	 *
	 * @return void
	 */
	public function test_an_insert_rule_with_stop_processing_does_not_halt_this_phase() {
		$product_id = $this->make_simple_product( 'WCEP Phase Halt' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		// Lower priority, so it would run FIRST and halt everything after it.
		$insert_halter = $this->make_sending_rule(
			$product_id,
			array(
				'name'            => 'insert rule that halts',
				'priority'        => 1,
				'delivery_mode'   => 'insert',
				// Required since Prompt 5 for the rule to be storable at all
				// (ADR-0013 §2); irrelevant to what this test asserts.
				'native_email_id' => 'customer_completed_order',
				'stop_processing' => 1,
			)
		);
		$separate      = $this->make_sending_rule(
			$product_id,
			array(
				'name'     => 'separate rule that must still send',
				'priority' => 10,
			)
		);

		$result = $this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertNull( $result->evaluation()->decision_for( $insert_halter ), 'The insert rule was evaluated.' );
		$this->assertSame(
			MatchDecision::MATCHED,
			$result->evaluation()->decision_for( $separate )->reason(),
			'An out-of-phase rule halted this phase.'
		);

		$this->assertMailCount( 1 );

		$tombstone = $this->tombstone( $order_id, $separate, 'status:completed' );
		$this->assertNotNull( $tombstone );
		$this->track_delivery( (int) $tombstone['id'] );
		$this->assertSame( 'sent', $tombstone['final_status'] );

		$this->assertNull( $this->deliveries->find( $order_id, $insert_halter, 'separate', 'status:completed' ) );
		$this->assertNull( $this->deliveries->find( $order_id, $insert_halter, 'insert', 'status:completed' ) );
	}

	/**
	 * 1c. The in-phase case is unchanged: separate + no delay delivers normally.
	 *
	 * @return void
	 */
	public function test_a_separate_undelayed_rule_still_delivers() {
		$product_id = $this->make_simple_product( 'WCEP In Phase' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule( $product_id, array( 'delay_seconds' => 0 ) );

		$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );

		$this->assertMailCount( 1 );
		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:completed' );
		$this->track_delivery( (int) $tombstone['id'] );
		$this->assertSame( 'sent', $tombstone['final_status'] );
	}

	/**
	 * 1d. The filter is a pure function, so its boundary is provable directly.
	 *
	 * @return void
	 */
	public function test_the_phase_filter_boundary() {
		$rules = array(
			array( 'id' => 1, 'delivery_mode' => 'separate', 'delay_seconds' => 0, 'consolidation' => 'none' ),
			array( 'id' => 2, 'delivery_mode' => 'insert', 'delay_seconds' => 0, 'consolidation' => 'none' ),
			array( 'id' => 3, 'delivery_mode' => 'separate', 'delay_seconds' => 1, 'consolidation' => 'none' ),
			array( 'id' => 4, 'delivery_mode' => 'insert', 'delay_seconds' => 86400, 'consolidation' => 'none' ),
			array( 'id' => 5, 'delivery_mode' => '', 'delay_seconds' => 0, 'consolidation' => 'none' ),
			array( 'id' => 6 ),
			/*
			 * ⚠ CONSOLIDATION NOW COMPOSES WITH THE PHASES RATHER THAN EXCLUDING A RULE
			 * FROM ALL OF THEM (ADR-0016 §8). 7 and 8 used to be filtered out of every
			 * phase because `consolidation` was unimplemented behaviour; Prompt 8
			 * implements it, so a `per_product` rule belongs to the phase its MODE and
			 * DELAY put it in, and the fan-out happens BELOW the claim.
			 */
			array( 'id' => 7, 'delivery_mode' => 'separate', 'delay_seconds' => 0, 'consolidation' => 'per_product' ),
			array( 'id' => 8, 'delivery_mode' => 'separate', 'delay_seconds' => 1, 'consolidation' => 'per_product' ),
			/*
			 * ⚠ AN INVALID VALUE REACHES **NO** PHASE — gate 26, and this ROW CHANGED IN
			 * PROMPT 8A (ADR-0016 §1a, superseding its own first draft).
			 *
			 * Row 9 used to assert that the immediate phase DELIVERED it, on the reasoning
			 * that "one explained email beats a rule that is silently invisible". That was
			 * wrong twice over: sending a delivery the merchant never configured is not
			 * defence in depth, and — because ADR-0016 §9 emptied the enumeration that
			 * `behaviour_is_implemented()` reads — the invalid rule ENTERED `RuleMatcher`,
			 * where `stop_processing` could halt a valid rule behind it. The customer got
			 * an unintended email AND lost the correct one.
			 *
			 * ⚠ AND THESE ROWS ARE NOT HAND-EDITED-DATABASE EXOTICA. `daily` was
			 * LEGITIMATELY STORABLE from Prompt 5B to Prompt 8, so this is the upgrade path
			 * for any store that used one.
			 */
			array( 'id' => 9, 'delivery_mode' => 'separate', 'delay_seconds' => 0, 'consolidation' => 'daily' ),
			array( 'id' => 10, 'delivery_mode' => 'separate', 'delay_seconds' => 1, 'consolidation' => 'daily' ),
			array( 'id' => 12, 'delivery_mode' => 'separate', 'delay_seconds' => 0, 'consolidation' => 'per_order' ),
			array( 'id' => 13, 'delivery_mode' => 'separate', 'delay_seconds' => 0, 'consolidation' => '' ),
			// A row with the column absent keeps the default, so it is deliverable —
			// the filter must not reject a rule for a column it never carried.
			array( 'id' => 11, 'delivery_mode' => 'separate', 'delay_seconds' => 0 ),
		);

		$this->assertSame(
			array( 1, 7, 11 ),
			array_column( Orchestrator::deliverable_in_this_phase( $rules ), 'id' ),
			'The immediate phase is separate + delay_seconds = 0 + a consolidation INSIDE the vocabulary.'
		);

		/*
		 * ⚠ AND THE THREE PHASES ARE DISJOINT (ADR-0015 §7). Stated as a partition
		 * rather than three separate assertions, because the failure being guarded
		 * is a rule reaching TWO phases — delivered inline AND from the queue, so
		 * the customer gets it twice. Rules 3 and 8 are the delayed ones; nothing else
		 * may join them, and no rule may appear in more than one column.
		 */
		/*
		 * ⚠ GATE 26, THE OTHER HALF: an invalid value reaches NEITHER phase, so rows 9,
		 * 10, 12 and 13 appear in no column at all. A rule that reaches no phase cannot
		 * be evaluated, and a rule that is never evaluated cannot halt another one.
		 */
		$this->assertSame(
			array(),
			array_intersect(
				array( 9, 10, 12, 13 ),
				array_merge(
					array_column( Orchestrator::deliverable_in_this_phase( $rules ), 'id' ),
					array_column( ScheduledPhase::deliverable_in_this_phase( $rules ), 'id' )
				)
			),
			'⚠ a rule whose consolidation is outside the vocabulary reached a delivery phase.'
		);

		$this->assertSame(
			array( 3, 8 ),
			array_column( ScheduledPhase::deliverable_in_this_phase( $rules ), 'id' ),
			'The scheduled phase is separate + delay_seconds > 0 + a consolidation in the vocabulary.'
		);

		$immediate = array_column( Orchestrator::deliverable_in_this_phase( $rules ), 'id' );
		$scheduled = array_column( ScheduledPhase::deliverable_in_this_phase( $rules ), 'id' );

		$this->assertSame(
			array(),
			array_intersect( $immediate, $scheduled ),
			'⚠ a rule belongs to BOTH the immediate and the scheduled phase, so it would be delivered twice.'
		);
	}

	/**
	 * 1e / gate 15. THE UNIMPLEMENTED-BEHAVIOUR ENUMERATION IS **EMPTY**, and the
	 *               mechanism that reads it is still wired to both phases.
	 *
	 * ⚠ THE ASSERTION IS EMPTINESS, NOT ABSENCE, AND THE TEST IS DELIBERATELY NOT
	 * DELETED (ADR-0016 §9). Every column that ever lived in this list has been
	 * implemented — `delay_seconds` left in Prompt 7 to become a phase discriminator,
	 * `consolidation` leaves in Prompt 8 because ADR-0016 gives it behaviour — so there
	 * is nothing left to hold. Deleting the list because it is empty would remove the
	 * discipline that caught `consolidation` in the first place: Prompt 5B gave that
	 * column validated storage and no filter, and a stored `daily` rule was delivered
	 * immediately and once per trigger, which is `none`'s behaviour under another name.
	 *
	 * Asserting emptiness makes a future column **re-arm the gate automatically**: add
	 * one to the constant and this assertion fails until the addition is written down,
	 * and both phases inherit the filtering with no new plumbing.
	 *
	 * @return void
	 */
	public function test_every_unimplemented_behaviour_column_is_filtered() {
		$columns = Orchestrator::UNIMPLEMENTED_BEHAVIOUR_DEFAULTS;

		$this->assertSame(
			array(),
			$columns,
			'⚠ The unimplemented-behaviour enumeration gained an entry. Add its filtering to BOTH phases '
				. '(Orchestrator::deliverable_in_this_phase and ScheduledPhase::owns already read this list), '
				. 'then update this assertion deliberately — gate 15 depends on it.'
		);

		// THE MECHANISM IS STILL LIVE. With an empty list it answers `true` for every
		// rule, which is correct, and that is exactly the call site a future entry
		// inherits — so the two facts are asserted together rather than one being taken
		// on trust.
		$this->assertTrue(
			Orchestrator::behaviour_is_implemented(
				array( 'id' => 1, 'delivery_mode' => 'separate', 'delay_seconds' => 0, 'consolidation' => 'none' )
			),
			'The all-defaults rule is not deliverable.'
		);

		// And the columns that LEFT are genuinely owned now, not merely unlisted.
		$delayed = array(
			'id'            => 1,
			'delivery_mode' => 'separate',
			'delay_seconds' => HOUR_IN_SECONDS,
			'consolidation' => 'none',
		);

		$this->assertSame( array(), Orchestrator::deliverable_in_this_phase( array( $delayed ) ), 'a delayed rule reached the IMMEDIATE phase' );
		$this->assertSame( array( $delayed ), ScheduledPhase::deliverable_in_this_phase( array( $delayed ) ), 'a delayed rule reached NO phase' );

		$consolidated = array(
			'id'            => 2,
			'delivery_mode' => 'separate',
			'delay_seconds' => 0,
			'consolidation' => 'per_product',
		);

		$this->assertSame(
			array( $consolidated ),
			Orchestrator::deliverable_in_this_phase( array( $consolidated ) ),
			'a per_product rule reached NO phase, so its fan-out would never happen'
		);

		// ⚠ AND THE VOCABULARY THAT USED TO BE FILTERED IS NOW REFUSED ONE LAYER
		// EARLIER, which is what makes the empty list safe (ADR-0016 §1).
		foreach ( array( 'daily', 'weekly', 'per_order', 'anything', '' ) as $value ) {
			$this->assertFalse(
				\Extonify\WCEP\Delivery\Consolidation::is_valid( $value ),
				sprintf( 'consolidation "%s" is inside the closed vocabulary.', $value )
			);
		}

		fwrite(
			STDERR,
			"\n[8 item 9 / gate 15] UNIMPLEMENTED_BEHAVIOUR_DEFAULTS is EMPTY; the mechanism stays wired to "
			. "both phases, and consolidation is now refused by vocabulary at the write boundary\n"
		);
	}

	/**
	 * 2. ONE SNAPSHOT: an admin save between matching and sending does not make
	 *    the rule match on the old targeting and send the new content.
	 *
	 * The edit is performed from inside the matcher's own call, which is the
	 * only place it can land between the two — a `query` filter fires while the
	 * rule fetch is in flight.
	 *
	 * @return void
	 */
	public function test_a_concurrent_edit_cannot_split_matching_from_sending() {
		$product_id = $this->make_simple_product( 'WCEP Snapshot' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule(
			$product_id,
			array(
				'subject'    => 'ORIGINAL subject',
				'content'    => '<p>ORIGINAL content.</p>',
				'recipients' => array( 'to' => array( 'customer' ) ),
			)
		);

		$before = $this->rules->find( $rule_id );

		/*
		 * The admin saves NEW content and NEW recipients AFTER the rules were
		 * fetched but BEFORE anything is sent.
		 *
		 * The `query` filter fires BEFORE a statement executes, so editing when
		 * the rules SELECT is seen would land before the fetch and prove
		 * nothing. Instead the filter ARMS on that SELECT and performs the edit
		 * on the next statement — by which time the fetch has returned and the
		 * snapshot is built.
		 */
		$armed  = false;
		$busy   = false;
		$edited = false;

		$editor = function ( $query ) use ( &$armed, &$busy, &$edited, $rule_id ) {
			if ( $busy || $edited ) {
				return $query;
			}

			if ( $armed ) {
				$armed  = false;
				$edited = true;
				$busy   = true;
				$this->rules->update(
					$rule_id,
					array(
						'subject'    => 'EDITED subject',
						'content'    => '<p>EDITED content.</p>',
						'recipients' => array( 'to' => array( 'someone-else@example.test' ) ),
					)
				);
				$busy = false;
				return $query;
			}

			// The backticks are optional: `%i` renders a backticked identifier, the old
			// interpolation rendered a bare one (Prompt 13A item 6).
			if ( 1 === preg_match( '/SELECT \* FROM `?\S*extonify_wcep_rules`? WHERE status/i', (string) $query ) ) {
				$armed = true;
			}

			return $query;
		};

		add_filter( 'query', $editor );
		try {
			$this->orchestrator()->run( $order, TriggerEvent::status( 'completed' ) );
		} finally {
			remove_filter( 'query', $editor );
		}

		$this->assertTrue( $edited, 'The fixture never performed the concurrent edit.' );

		$after = $this->rules->find( $rule_id );
		$this->assertSame( 'EDITED subject', $after['subject'], 'The concurrent edit did not land.' );
		$this->assertGreaterThan( (int) $before['revision'], (int) $after['revision'] );

		// THE DELIVERY USED THE MATCHED SNAPSHOT THROUGHOUT.
		$this->assertMailCount( 1 );
		$mail = $this->last_mail();

		$this->assertSame( 'ORIGINAL subject', $mail['subject'], 'The send used content the matcher never saw.' );
		$this->assertStringContainsString( 'ORIGINAL content.', (string) $mail['message'] );
		$this->assertSame( 'wcep-matching@example.test', $mail['to'], 'The send used recipients the matcher never saw.' );

		// And the audit records the revision that ACTUALLY matched.
		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:completed' );
		$this->track_delivery( (int) $tombstone['id'] );

		$this->assertSame(
			(int) $before['revision'],
			(int) $tombstone['rule_revision_sent'],
			'The audit recorded a revision that never produced this match.'
		);
	}

	/**
	 * 3. A SEND THAT THROWS is contained: the WooCommerce status hook does not
	 *    throw, nothing is sent, and the failure is recorded.
	 *
	 * Driven through the real `woocommerce_order_status_changed` hook, because
	 * the point of the fix is what happens to the MERCHANT'S ORDER UPDATE.
	 *
	 * @return void
	 */
	public function test_a_throwing_send_never_escapes_the_order_event() {
		$product_id = $this->make_simple_product( 'WCEP Throwing Send' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_sending_rule( $product_id, array( 'trigger_value' => 'processing' ) );

		$thrower = static function () {
			throw new \RuntimeException( 'SMTP plugin exploded mid-send' );
		};

		// Priority 0: AHEAD of the capture filter at priority 1, so the throw
		// happens instead of the capture short-circuiting the send.
		add_filter( 'pre_wp_mail', $thrower, 0 );

		try {
			// THE ASSERTION THAT MATTERS: this does not throw.
			do_action( 'woocommerce_order_status_changed', $order_id, 'pending', 'processing', $order );
		} finally {
			remove_filter( 'pre_wp_mail', $thrower, 0 );
		}

		$this->assertMailCount( 0, 'A throwing send still delivered something.' );

		$tombstone = $this->tombstone( $order_id, $rule_id, 'status:processing' );
		$this->assertNotNull( $tombstone, 'The claim never happened, so this proves nothing about the throw.' );
		$this->track_delivery( (int) $tombstone['id'] );

		$this->assertSame(
			'failed',
			$tombstone['final_status'],
			'The tombstone was left `claimed` forever while the identity blocked every retry.'
		);

		$rows = $this->detail_rows( (int) $tombstone['id'] );
		$this->assertCount( 1, $rows, 'A throwing send wrote no detail row.' );
		$this->assertSame( 'failed', $rows[0]['state'] );
		$this->assertStringContainsString( 'SMTP plugin exploded mid-send', (string) $rows[0]['failure_message'] );
		$this->assertStringContainsString( 'RuntimeException', (string) $rows[0]['failure_message'] );

		// The shared email object is clean, so the NEXT delivery is unaffected.
		$email = $this->live_email();
		$this->assertSame( '', $email->recipient );
		$this->assertSame( '', $email->delivery_subject );
		$this->assertNull( $email->object );
	}

	/**
	 * 3b. A `\TypeError` — not an `\Exception` — is contained too. A badly-typed
	 *     third-party filter is exactly as fatal to the order update.
	 *
	 * @return void
	 */
	public function test_a_typeerror_during_send_is_also_contained() {
		$product_id = $this->make_simple_product( 'WCEP TypeError' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$this->make_sending_rule( $product_id, array( 'trigger_value' => 'processing' ) );

		$thrower = static function () {
			throw new \TypeError( 'a third-party filter returned the wrong type' );
		};

		add_filter( 'pre_wp_mail', $thrower, 0 );

		try {
			do_action( 'woocommerce_order_status_changed', $order_id, 'pending', 'processing', $order );
		} finally {
			remove_filter( 'pre_wp_mail', $thrower, 0 );
		}

		$this->assertMailCount( 0 );

		$tombstones = $this->tombstones_for( $order_id );
		$this->assertCount( 1, $tombstones );
		$this->assertSame( 'failed', $tombstones[0]['final_status'] );
	}

	/**
	 * 4. A DETAIL-ROW SHORTFALL is detected and reported rather than reported as
	 *    a successful send.
	 *
	 * @return void
	 */
	public function test_a_failed_detail_insert_is_reported() {
		$product_id = $this->make_simple_product( 'WCEP Shortfall' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();
		$rule_id    = $this->make_sending_rule( $product_id );

		$claim = $this->deliveries->claim( $order_id, $rule_id, DeliveryLogger::MODE, 'status:completed' );
		$this->track_delivery( (int) $claim['delivery_id'] );

		$recipients = \Extonify\WCEP\Delivery\RecipientResolver::resolve(
			array( 'to' => array( 'customer' ), 'cc' => array( 'cc@example.test' ) ),
			array( 'customer' => 'shortfall@example.test', 'admin' => 'admin@example.test' )
		);
		$this->assertCount( 2, $recipients->entries() );

		$logger = $this->logger_with_failing_details();
		$result = $logger->record_send( (int) $claim['delivery_id'], $recipients, 'Shortfall subject', true );

		$this->assertFalse( $result['success'], 'A total detail-row failure was reported as success.' );
		$this->assertSame( 2, $result['rows_expected'] );
		$this->assertSame( 0, $result['rows_written'] );
		$this->assertNotSame( array(), $logger->recorded_errors, 'The shortfall was not logged.' );
		$this->assertStringContainsString( 'was not fully recorded', $logger->recorded_errors[0] );
		$this->assertStringContainsString( (string) $claim['delivery_id'], $logger->recorded_errors[0] );
	}

	/**
	 * 4b. A FINALISATION failure is detected and reported.
	 *
	 * @return void
	 */
	public function test_a_failed_finalisation_is_reported() {
		$product_id = $this->make_simple_product( 'WCEP Finalise Fail' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();
		$rule_id    = $this->make_sending_rule( $product_id );

		$claim = $this->deliveries->claim( $order_id, $rule_id, DeliveryLogger::MODE, 'status:completed' );
		$this->track_delivery( (int) $claim['delivery_id'] );

		$logger = $this->logger_with_failing_finalisation();
		$result = $logger->record_skip( $claim, 'a reason' );

		$this->assertFalse( $result['success'], 'A finalisation failure was reported as success.' );
		$this->assertFalse( $result['finalized'] );
		$this->assertSame( 1, $result['rows_written'], 'The detail row itself should still have been written.' );
		$this->assertNotSame( array(), $logger->recorded_errors );
		$this->assertStringContainsString( 'NOT finalized', $logger->recorded_errors[0] );
	}

	/**
	 * 4c. The happy path reports success with matching counts, so the failure
	 *     assertions above are about failure and not about a broken shape.
	 *
	 * @return void
	 */
	public function test_a_clean_send_reports_success() {
		$product_id = $this->make_simple_product( 'WCEP Clean Result' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();
		$rule_id    = $this->make_sending_rule( $product_id );

		$claim = $this->deliveries->claim( $order_id, $rule_id, DeliveryLogger::MODE, 'status:completed' );
		$this->track_delivery( (int) $claim['delivery_id'] );

		$recipients = \Extonify\WCEP\Delivery\RecipientResolver::resolve(
			array( 'to' => array( 'customer' ) ),
			array( 'customer' => 'clean@example.test', 'admin' => 'admin@example.test' )
		);

		$result = ( new DeliveryLogger( $this->deliveries, $this->details ) )
			->record_send( (int) $claim['delivery_id'], $recipients, 'Clean subject', true );

		// ⚠ THE FIFTH KEY IS THE GUARDED WRITE'S OWN OUTCOME (ADR-0015 §8.1a). An
		// immediate send reaches it through the unconditional writer, so it reports
		// `changed`; a delayed one carries the transition it won.
		$this->assertSame(
			array( 'success', 'rows_expected', 'rows_written', 'finalized', 'transition' ),
			array_keys( $result )
		);
		$this->assertTrue( (bool) $result['success'] );
		$this->assertSame( 1, (int) $result['rows_expected'] );
		$this->assertSame( 1, (int) $result['rows_written'] );
		$this->assertTrue( (bool) $result['finalized'] );
		$this->assertTrue( $result['transition']->won() );
	}

	/**
	 * A logger whose detail inserts always fail, capturing its own error log.
	 *
	 * Captured by SUBCLASSING rather than by hooking WooCommerce: `WC_Logger`
	 * exposes no "a line was logged" action, and registering a log handler would
	 * make this test depend on WooCommerce's handler pipeline instead of on the
	 * behaviour under test.
	 *
	 * @return DeliveryLogger
	 */
	private function logger_with_failing_details(): DeliveryLogger {
		$details = new class() extends \Extonify\WCEP\Repository\DeliveryDetailRepository {
			/**
			 * Always fail.
			 *
			 * @param int   $delivery_id Tombstone id.
			 * @param array $data        Row.
			 * @return int
			 */
			public function insert( int $delivery_id, array $data ): int {
				return 0;
			}
		};

		return $this->recording_logger( $this->deliveries, $details );
	}

	/**
	 * A logger whose finalisation always fails, capturing its own error log.
	 *
	 * @return DeliveryLogger
	 */
	private function logger_with_failing_finalisation(): DeliveryLogger {
		$deliveries = new class() extends \Extonify\WCEP\Repository\DeliveryRepository {
			/**
			 * Always fail.
			 *
			 * @param int    $delivery_id        Tombstone id.
			 * @param string $final_status       Status.
			 * @param int    $rule_revision_sent Revision.
			 * @return bool
			 */
			public function set_final_status( int $delivery_id, string $final_status, int $rule_revision_sent = 0 ): bool {
				return false;
			}
		};

		return $this->recording_logger( $deliveries, $this->details );
	}

	/**
	 * A `DeliveryLogger` that records what it would have sent to the
	 * WooCommerce error log.
	 *
	 * @param \Extonify\WCEP\Repository\DeliveryRepository       $deliveries Tombstone storage.
	 * @param \Extonify\WCEP\Repository\DeliveryDetailRepository $details    Detail storage.
	 * @return DeliveryLogger
	 */
	private function recording_logger( $deliveries, $details ): DeliveryLogger {
		return new class( $deliveries, $details ) extends DeliveryLogger {
			/**
			 * Errors this logger recorded.
			 *
			 * @var string[]
			 */
			public $recorded_errors = array();

			/**
			 * Capture instead of writing to the WooCommerce log.
			 *
			 * @param string $message Detail.
			 * @return void
			 */
			protected function log_error( string $message ): void {
				$this->recorded_errors[] = $message;
			}
		};
	}
}
