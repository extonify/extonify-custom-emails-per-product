<?php
/**
 * Rule storage, JSON columns and revision bumping (ADR-0009).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Repository\RuleRepository;

/**
 * Storage-layer behaviour only. Whether a rule MATCHES an order is the
 * matching engine's job and is deliberately not built yet.
 */
final class RuleRepositoryTest extends IntegrationTestCase {

	/**
	 * Repository under test.
	 *
	 * @var RuleRepository
	 */
	private $repo;

	/**
	 * Build the repository.
	 *
	 * @before
	 * @return void
	 */
	protected function set_up_repo() {
		$this->repo = new RuleRepository();
	}

	/**
	 * A representative rule payload.
	 *
	 * @return array
	 */
	private function payload(): array {
		return array(
			'name'            => 'Care guide for mugs',
			'status'          => 'active',
			'priority'        => 5,
			'trigger_type'    => 'status',
			'trigger_value'   => 'completed',
			'delivery_mode'   => 'insert',
			'native_email_id' => 'customer_completed_order',
			'insert_position' => 'after_order_table',
			'targeting'       => array( 'products' => array( 12, 34 ) ),
			'recipients'      => array( 'customer' ),
			'subject'         => 'How to care for your mug',
			'heading'         => 'Mug care',
			'content'         => '<p>Hand wash only.</p>',
			'delay_seconds'   => 3600,
			'consolidation'   => 'none',
			'stop_processing' => 1,
		);
	}

	/**
	 * A rule round-trips through storage with JSON columns decoded and
	 * integers typed.
	 *
	 * @return void
	 */
	public function test_insert_and_read_round_trip() {
		$id = $this->track_rule( $this->repo->insert( $this->payload() ) );

		$this->assertGreaterThan( 0, $id );

		$rule = $this->repo->find( $id );
		$this->assertNotNull( $rule );
		$this->assertSame( 'Care guide for mugs', $rule['name'] );
		$this->assertSame( 'active', $rule['status'] );
		$this->assertSame( 5, $rule['priority'] );
		$this->assertSame( 1, $rule['revision'] );
		$this->assertSame( 3600, $rule['delay_seconds'] );
		$this->assertSame( 1, $rule['stop_processing'] );
		$this->assertSame( array( 'products' => array( 12, 34 ) ), $rule['targeting'] );
		$this->assertSame( array( 'customer' ), $rule['recipients'] );
	}

	/**
	 * Unknown enum values fall back to the safe default rather than being
	 * written through.
	 *
	 * `trigger_type` is DELIBERATELY NOT in this list — falling back is safe for
	 * `status` and `delivery_mode`, whose defaults make a rule do LESS, but a
	 * trigger fallback makes a rule do something DIFFERENT. See
	 * test_an_unknown_trigger_type_is_refused_not_coerced().
	 *
	 * @return void
	 */
	public function test_unknown_enum_values_fall_back_safely() {
		$data                  = $this->payload();
		$data['status']        = 'wide-open';
		$data['delivery_mode'] = 'broadcast';

		$id   = $this->track_rule( $this->repo->insert( $data ) );
		$rule = $this->repo->find( $id );

		$this->assertSame( 'inactive', $rule['status'], 'An unknown status must fail CLOSED.' );
		$this->assertSame( 'separate', $rule['delivery_mode'] );
	}

	/**
	 * Script tags are stripped from merchant-authored content at the storage
	 * boundary.
	 *
	 * @return void
	 */
	public function test_content_is_sanitised_on_write() {
		$data            = $this->payload();
		$data['content'] = '<p>Safe</p><script>alert(1)</script>';

		$id   = $this->track_rule( $this->repo->insert( $data ) );
		$rule = $this->repo->find( $id );

		$this->assertStringContainsString( 'Safe', $rule['content'] );
		$this->assertStringNotContainsString( '<script', $rule['content'] );
	}

	/**
	 * Corrupt JSON in a column decodes to an empty array instead of breaking
	 * the read.
	 *
	 * @return void
	 */
	public function test_corrupt_json_column_degrades_to_empty_array() {
		global $wpdb;

		$id = $this->track_rule( $this->repo->insert( $this->payload() ) );

		$wpdb->update(
			Migrator::table( 'rules' ),
			array( 'targeting' => '{"products":[1,2' ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		$rule = $this->repo->find( $id );

		$this->assertSame( array(), $rule['targeting'] );
		$this->assertSame( array( 'customer' ), $rule['recipients'], 'A corrupt column affected an unrelated one.' );
	}

	/**
	 * Changing a content-bearing field bumps the revision.
	 *
	 * @return void
	 */
	public function test_content_change_bumps_revision() {
		$id = $this->track_rule( $this->repo->insert( $this->payload() ) );

		$this->assertTrue( $this->repo->update( $id, array( 'subject' => 'A new subject' ) ) );

		$rule = $this->repo->find( $id );
		$this->assertSame( 2, $rule['revision'] );
		$this->assertSame( 'A new subject', $rule['subject'] );
	}

	/**
	 * Changing a non-content field does NOT bump the revision.
	 *
	 * @return void
	 */
	public function test_non_content_change_does_not_bump_revision() {
		$id = $this->track_rule( $this->repo->insert( $this->payload() ) );

		$this->assertTrue( $this->repo->update( $id, array( 'priority' => 99 ) ) );

		$rule = $this->repo->find( $id );
		$this->assertSame( 1, $rule['revision'] );
		$this->assertSame( 99, $rule['priority'] );
	}

	/**
	 * Re-saving identical content does not bump the revision — otherwise every
	 * no-op save would invalidate scheduling snapshots.
	 *
	 * @return void
	 */
	public function test_identical_content_does_not_bump_revision() {
		$id = $this->track_rule( $this->repo->insert( $this->payload() ) );

		$this->repo->update(
			$id,
			array(
				'subject'   => 'How to care for your mug',
				'targeting' => array( 'products' => array( 12, 34 ) ),
			)
		);

		$this->assertSame( 1, $this->repo->find( $id )['revision'] );
	}

	/**
	 * The trigger index path returns only active rules for that trigger, in
	 * priority order.
	 *
	 * @return void
	 */
	public function test_find_active_for_trigger_filters_and_orders() {
		$trigger = 'wcep-test-' . wp_generate_password( 8, false, false );

		$low                  = $this->payload();
		$low['trigger_value'] = $trigger;
		$low['priority']      = 20;
		$this->track_rule( $this->repo->insert( $low ) );

		$high                  = $this->payload();
		$high['trigger_value'] = $trigger;
		$high['priority']      = 1;
		$high_id               = $this->track_rule( $this->repo->insert( $high ) );

		$inactive                  = $this->payload();
		$inactive['trigger_value'] = $trigger;
		$inactive['status']        = 'inactive';
		$this->track_rule( $this->repo->insert( $inactive ) );

		$found = $this->repo->find_active_for_trigger( 'status', $trigger );

		$this->assertCount( 2, $found, 'An inactive rule was returned.' );
		$this->assertSame( $high_id, $found[0]['id'], 'Rules are not ordered by priority.' );
		$this->assertSame( 1, $found[0]['priority'] );
	}

	/**
	 * Deleting a rule removes it. Tombstones referencing it are deliberately
	 * NOT touched — removing them would re-arm every order the rule served.
	 *
	 * @return void
	 */
	public function test_delete_removes_the_rule_only() {
		$id = $this->repo->insert( $this->payload() );

		$deliveries = new \Extonify\WCEP\Repository\DeliveryRepository();
		$claim      = $deliveries->claim( $this->fake_order_id(), $id, 'insert', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );

		$this->assertTrue( $this->repo->delete( $id ) );
		$this->assertNull( $this->repo->find( $id ) );
		$this->assertNotNull( $deliveries->find_by_hash( $claim['identity_hash'] ), 'Deleting a rule destroyed its delivery tombstones.' );
	}

	/**
	 * Updating a rule that does not exist reports failure rather than
	 * inserting one.
	 *
	 * @return void
	 */
	public function test_update_of_missing_rule_fails() {
		$this->assertFalse( $this->repo->update( 987654321, array( 'subject' => 'nope' ) ) );
	}

	// ---------------------------------------------------------------------
	// Trigger normalisation at the storage boundary (ADR-0011 §2).
	// ---------------------------------------------------------------------

	/**
	 * The stored `trigger_value` is the NORMALISED one, whatever was supplied.
	 *
	 * `find_active_for_trigger()` matches with `= %s`, so a rule stored as
	 * `wc-completed` is never returned: it never fires and never explains why —
	 * no decision, no reason code, nothing in the delivery log. ADR-0011 put
	 * this obligation on the rule editor; the repository is the only boundary
	 * every path crosses, so it lives here.
	 *
	 * @dataProvider trigger_normalisation_provider
	 *
	 * @param string $type     Trigger type.
	 * @param string $supplied Value as written by an importer or an editor.
	 * @param string $stored   Value that must end up in the column.
	 * @return void
	 */
	public function test_trigger_value_is_normalised_on_insert( string $type, string $supplied, string $stored ) {
		$data                  = $this->payload();
		$data['trigger_type']  = $type;
		$data['trigger_value'] = $supplied;

		$id = $this->track_rule( $this->repo->insert( $data ) );
		$this->assertGreaterThan( 0, $id, 'Insert refused a well-formed trigger.' );

		$this->assertSame( $stored, $this->repo->find( $id )['trigger_value'], "'{$supplied}' was stored unnormalised." );
	}

	/**
	 * The same normalisation applies on update.
	 *
	 * @dataProvider trigger_normalisation_provider
	 *
	 * @param string $type     Trigger type.
	 * @param string $supplied Value as written.
	 * @param string $stored   Value that must end up in the column.
	 * @return void
	 */
	public function test_trigger_value_is_normalised_on_update( string $type, string $supplied, string $stored ) {
		$id = $this->track_rule( $this->repo->insert( $this->payload() ) );

		$this->assertTrue(
			$this->repo->update(
				$id,
				array(
					'trigger_type'  => $type,
					'trigger_value' => $supplied,
				)
			)
		);

		$this->assertSame( $stored, $this->repo->find( $id )['trigger_value'] );
		$this->assertSame( $type, $this->repo->find( $id )['trigger_type'] );
	}

	/**
	 * Every form ADR-0011 §2 names, plus the ones a bad import produces.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function trigger_normalisation_provider(): array {
		return array(
			'status prefixed'          => array( 'status', 'wc-completed', 'completed' ),
			'status uppercase prefix'  => array( 'status', 'WC-COMPLETED', 'completed' ),
			'status mixed case'        => array( 'status', 'wc-Completed', 'completed' ),
			'status padded'            => array( 'status', '  completed  ', 'completed' ),
			'status already normal'    => array( 'status', 'completed', 'completed' ),
			'status one prefix only'   => array( 'status', 'wc-wc-completed', 'wc-completed' ),
			'transition both prefixed' => array( 'transition', 'wc-pending>wc-processing', 'pending>processing' ),
			'transition mixed case'    => array( 'transition', 'WC-Pending>Processing', 'pending>processing' ),
			'transition one side'      => array( 'transition', 'pending>wc-processing', 'pending>processing' ),
			'transition padded'        => array( 'transition', ' pending > processing ', 'pending>processing' ),
			'refund with a value'      => array( 'refund', 'wc-refunded', '' ),
			'refund already empty'     => array( 'refund', '', '' ),
		);
	}

	/**
	 * A rule inserted as `wc-completed` is subsequently FOUND by the trigger the
	 * engine actually evaluates — which is the whole point.
	 *
	 * @return void
	 */
	public function test_a_prefixed_rule_is_found_by_the_normalised_trigger() {
		$slug = 'wcep-' . wp_generate_password( 8, false, false );

		$data                  = $this->payload();
		$data['trigger_value'] = 'WC-' . $slug;

		$id = $this->track_rule( $this->repo->insert( $data ) );

		$found = $this->repo->find_active_for_trigger( 'status', strtolower( $slug ) );

		$this->assertCount( 1, $found, 'A rule stored with a wc- prefix was invisible to the engine.' );
		$this->assertSame( $id, $found[0]['id'] );
	}

	/**
	 * The read side normalises too, so the fetch cannot miss from the other
	 * direction either.
	 *
	 * @return void
	 */
	public function test_the_fetch_normalises_its_own_argument() {
		$slug = 'wcep-' . strtolower( wp_generate_password( 8, false, false ) );

		$data                  = $this->payload();
		$data['trigger_value'] = $slug;
		$id                    = $this->track_rule( $this->repo->insert( $data ) );

		foreach ( array( 'wc-' . $slug, strtoupper( $slug ), '  ' . $slug . '  ' ) as $queried ) {
			$found = $this->repo->find_active_for_trigger( 'status', $queried );
			$this->assertCount( 1, $found, "Querying '{$queried}' missed the rule." );
			$this->assertSame( $id, $found[0]['id'] );
		}
	}

	/**
	 * A malformed TRANSITION is REFUSED rather than stored.
	 *
	 * There is no repair that is not a guess, and `trigger_type = transition`
	 * with `trigger_value = completed` is a rule that can never match and never
	 * says so.
	 *
	 * @dataProvider malformed_transition_provider
	 *
	 * @param string $value Malformed transition value.
	 * @return void
	 */
	public function test_a_malformed_transition_is_refused( string $value ) {
		$data                  = $this->payload();
		$data['trigger_type']  = 'transition';
		$data['trigger_value'] = $value;

		$this->assertSame( 0, $this->repo->insert( $data ), "'{$value}' was stored as a transition." );

		// And it cannot be introduced by an update either.
		$id = $this->track_rule( $this->repo->insert( $this->payload() ) );
		$this->assertFalse(
			$this->repo->update(
				$id,
				array(
					'trigger_type'  => 'transition',
					'trigger_value' => $value,
				)
			)
		);
		$this->assertSame( 'completed', $this->repo->find( $id )['trigger_value'], 'The refused update still wrote.' );
		$this->assertSame( 'status', $this->repo->find( $id )['trigger_type'] );
	}

	/**
	 * Malformed transitions: no separator, an empty side, or more than one.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function malformed_transition_provider(): array {
		return array(
			'no separator'     => array( 'completed' ),
			'empty'            => array( '' ),
			'empty left'       => array( '>processing' ),
			'empty right'      => array( 'pending>' ),
			'both empty'       => array( '>' ),
			'two separators'   => array( 'a>b>c' ),
			'whitespace side'  => array( '   >processing' ),
			'prefix only left' => array( 'wc->processing' ),
		);
	}

	/**
	 * A partial update that changes `trigger_value` WITHOUT supplying
	 * `trigger_type` reads the existing row's type to normalise correctly.
	 *
	 * @return void
	 */
	public function test_partial_update_reads_the_existing_trigger_type() {
		// The stored rule is a TRANSITION; the update supplies only a value.
		$data                  = $this->payload();
		$data['trigger_type']  = 'transition';
		$data['trigger_value'] = 'pending>processing';
		$id                    = $this->track_rule( $this->repo->insert( $data ) );

		$this->assertTrue( $this->repo->update( $id, array( 'trigger_value' => 'WC-Pending>wc-Completed' ) ) );
		$this->assertSame( 'pending>completed', $this->repo->find( $id )['trigger_value'] );

		// A value that is fine for a STATUS rule is malformed for this one, and
		// is refused — proving the existing type really was consulted.
		$this->assertFalse( $this->repo->update( $id, array( 'trigger_value' => 'wc-completed' ) ) );
		$this->assertSame( 'pending>completed', $this->repo->find( $id )['trigger_value'] );

		// The same partial update on a STATUS rule normalises and succeeds.
		$status_id = $this->track_rule( $this->repo->insert( $this->payload() ) );
		$this->assertTrue( $this->repo->update( $status_id, array( 'trigger_value' => 'wc-Processing' ) ) );
		$this->assertSame( 'processing', $this->repo->find( $status_id )['trigger_value'] );
	}

	/**
	 * Changing the TYPE to `refund` clears the value the old type left behind,
	 * even though the caller never mentioned it — otherwise the row keeps a
	 * `completed` that no refund fetch will ever match.
	 *
	 * @return void
	 */
	public function test_changing_the_type_renormalises_the_value() {
		$id = $this->track_rule( $this->repo->insert( $this->payload() ) );
		$this->assertSame( 'completed', $this->repo->find( $id )['trigger_value'] );

		$this->assertTrue( $this->repo->update( $id, array( 'trigger_type' => 'refund' ) ) );

		$rule = $this->repo->find( $id );
		$this->assertSame( 'refund', $rule['trigger_type'] );
		$this->assertSame( '', $rule['trigger_value'], 'A refund rule must carry no trigger value (ADR-0011 §2).' );

		$found = $this->repo->find_active_for_trigger( 'refund', '' );
		$this->assertContains( $id, array_column( $found, 'id' ), 'The re-typed rule is invisible to the refund fetch.' );
	}

	/**
	 * An unrecognised trigger type is REFUSED, never coerced to `status`.
	 *
	 * Coercing is worse than storing an inert rule: junk input becomes a
	 * DIFFERENT VALID TRIGGER, and the rule fires on events its author never
	 * chose. It is the same failure shape as the `(int)` cast removed from id
	 * parsing — silent coercion of invalid input into a real value — and is
	 * refused for the same reason.
	 *
	 * @dataProvider unknown_trigger_type_provider
	 *
	 * @param string $type Unrecognised trigger type.
	 * @return void
	 */
	public function test_an_unknown_trigger_type_is_refused_not_coerced( string $type ) {
		$before = $this->rule_row_count();

		$data                  = $this->payload();
		$data['trigger_type']  = $type;
		$data['trigger_value'] = 'completed';

		$this->assertSame( 0, $this->repo->insert( $data ), "'{$type}' was stored." );
		$this->assertSame( $before, $this->rule_row_count(), 'A refused insert created a row.' );

		// Nor can it be introduced by an update.
		$id       = $this->track_rule( $this->repo->insert( $this->payload() ) );
		$original = $this->repo->find( $id );

		$this->assertFalse( $this->repo->update( $id, array( 'trigger_type' => $type ) ) );
		$this->assertSame( $original, $this->repo->find( $id ), 'A refused update modified the row.' );
	}

	/**
	 * Trigger types that must never be coerced into a working trigger.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function unknown_trigger_type_provider(): array {
		return array(
			'near miss'    => array( 'statuz' ),
			'plural'       => array( 'statuses' ),
			'invented'     => array( 'whenever' ),
			'empty'        => array( '' ),
			'transitional' => array( 'transitions' ),
			'refunds'      => array( 'refunds' ),
		);
	}

	/**
	 * A dead or malformed STATUS value is refused rather than stored.
	 *
	 * An active rule with `trigger_value = ''` stores happily and no real status
	 * event can ever fetch it — permanently dead, silently. The same applies to
	 * anything that is not a slug: validated by SHAPE, not merely trimmed and
	 * lowercased.
	 *
	 * @dataProvider malformed_status_provider
	 *
	 * @param string $value Malformed status value.
	 * @return void
	 */
	public function test_a_dead_or_malformed_status_value_is_refused( string $value ) {
		$before = $this->rule_row_count();

		$data                  = $this->payload();
		$data['trigger_value'] = $value;

		$this->assertSame( 0, $this->repo->insert( $data ), var_export( $value, true ) . ' was stored.' );
		$this->assertSame( $before, $this->rule_row_count(), 'A refused insert created a row.' );

		// A partial update producing the same value is refused too, using the
		// existing row's trigger_type because the update supplies none.
		$id       = $this->track_rule( $this->repo->insert( $this->payload() ) );
		$original = $this->repo->find( $id );

		$this->assertFalse( $this->repo->update( $id, array( 'trigger_value' => $value ) ) );
		$this->assertSame( $original, $this->repo->find( $id ), 'A refused update modified the row.' );
	}

	/**
	 * The same values on EACH SIDE of a transition are refused too.
	 *
	 * @dataProvider malformed_status_provider
	 *
	 * @param string $value Malformed status value.
	 * @return void
	 */
	public function test_a_malformed_slug_on_either_transition_side_is_refused( string $value ) {
		foreach ( array( $value . '>processing', 'pending>' . $value ) as $transition ) {
			$before = $this->rule_row_count();

			$data                  = $this->payload();
			$data['trigger_type']  = 'transition';
			$data['trigger_value'] = $transition;

			$this->assertSame( 0, $this->repo->insert( $data ), var_export( $transition, true ) . ' was stored.' );
			$this->assertSame( $before, $this->rule_row_count() );
		}
	}

	/**
	 * Status values that can never match a real event.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function malformed_status_provider(): array {
		return array(
			'empty'           => array( '' ),
			'spaces only'     => array( '   ' ),
			'tab and newline' => array( "\t\n" ),
			'prefix only'     => array( 'wc-' ),
			'internal space'  => array( 'on hold' ),
			'dot'             => array( 'on.hold' ),
			'slash'           => array( 'a/b' ),
			'percent'         => array( '100%' ),
			'quote'           => array( "completed'" ),
			'non-ascii'       => array( 'terminé' ),
			/*
			 * An embedded NUL. Listed HERE rather than with the repairable
			 * values because `sanitize_text_field()` does NOT strip it — it
			 * survives, so this was already refused before this pass. Covered so
			 * the refusal stays proven either way.
			 */
			'embedded null'   => array( "comp\0leted" ),
		);
	}

	/**
	 * Input that SANITISATION WOULD REPAIR is refused instead.
	 *
	 * `resolve_trigger()` used to run `sanitize_text_field()` before validating,
	 * so `"<b>completed</b>"` and `"completed%20"` were stripped down to
	 * `completed` and stored as working triggers. That is the identical
	 * silent-coercion shape removed from trigger types and id parsing —
	 * surviving one layer up, in the very function that removed it elsewhere.
	 *
	 * @dataProvider repairable_status_provider
	 *
	 * @param string $value Value sanitisation would have repaired.
	 * @return void
	 */
	public function test_a_value_sanitisation_would_repair_is_refused( string $value ) {
		// Pin the premise: WordPress really would turn this into a valid slug.
		$this->assertTrue(
			\Extonify\WCEP\Domain\TriggerEvent::is_valid_status_slug( sanitize_text_field( $value ) ),
			'The fixture is not something sanitisation repairs; it proves nothing.'
		);

		$before = $this->rule_row_count();

		$data                  = $this->payload();
		$data['trigger_value'] = $value;

		$this->assertSame( 0, $this->repo->insert( $data ), var_export( $value, true ) . ' was repaired and stored.' );
		$this->assertSame( $before, $this->rule_row_count(), 'A refused insert created a row.' );

		$id       = $this->track_rule( $this->repo->insert( $this->payload() ) );
		$original = $this->repo->find( $id );

		$this->assertFalse( $this->repo->update( $id, array( 'trigger_value' => $value ) ) );
		$this->assertSame( $original, $this->repo->find( $id ), 'A refused update modified the row.' );
	}

	/**
	 * The same values on EACH SIDE of a transition are refused too.
	 *
	 * @dataProvider repairable_status_provider
	 *
	 * @param string $value Value sanitisation would have repaired.
	 * @return void
	 */
	public function test_a_repairable_value_on_either_transition_side_is_refused( string $value ) {
		foreach ( array( $value . '>processing', 'pending>' . $value ) as $transition ) {
			$before = $this->rule_row_count();

			$data                  = $this->payload();
			$data['trigger_type']  = 'transition';
			$data['trigger_value'] = $transition;

			$this->assertSame( 0, $this->repo->insert( $data ), var_export( $transition, true ) . ' was repaired and stored.' );
			$this->assertSame( $before, $this->rule_row_count() );
		}
	}

	/**
	 * Values `sanitize_text_field()` strips into validity.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function repairable_status_provider(): array {
		return array(
			'wrapped in tags' => array( '<b>completed</b>' ),
			'trailing tag'    => array( 'completed<script>' ),
			'percent-encoded' => array( 'completed%20' ),
			'encoded slash'   => array( 'completed%2F' ),
		);
	}

	/**
	 * `trigger_type` is validated raw, not sanitised into one of the allowed
	 * values.
	 *
	 * `sanitize_key()` lowercases and strips, so `'STATUS!'` became `'status'`
	 * and passed. It lands on a valid value by accident, and leaving the two
	 * paths inconsistent invites the value path to be "fixed" back later.
	 *
	 * @dataProvider repairable_trigger_type_provider
	 *
	 * @param string $type Trigger type sanitisation would have repaired.
	 * @return void
	 */
	public function test_a_trigger_type_sanitisation_would_repair_is_refused( string $type ) {
		// Pin the premise: sanitize_key() really would turn this into `status`.
		$this->assertSame( 'status', sanitize_key( $type ), 'The fixture is not something sanitize_key() repairs.' );

		$before = $this->rule_row_count();

		$data                 = $this->payload();
		$data['trigger_type'] = $type;

		$this->assertSame( 0, $this->repo->insert( $data ), var_export( $type, true ) . ' was repaired and stored.' );
		$this->assertSame( $before, $this->rule_row_count() );

		$id       = $this->track_rule( $this->repo->insert( $this->payload() ) );
		$original = $this->repo->find( $id );

		$this->assertFalse( $this->repo->update( $id, array( 'trigger_type' => $type ) ) );
		$this->assertSame( $original, $this->repo->find( $id ), 'A refused update modified the row.' );
	}

	/**
	 * Trigger types `sanitize_key()` folds into `status`.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function repairable_trigger_type_provider(): array {
		return array(
			'uppercase'  => array( 'STATUS' ),
			'punctuated' => array( 'status!' ),
			'padded'     => array( ' status ' ),
		);
	}

	/**
	 * The PERMITTED transformations still work: case folding and one `wc-`
	 * prefix are normalisation, not repair.
	 *
	 * @return void
	 */
	public function test_permitted_transformations_are_still_applied() {
		foreach ( array( 'wc-Completed', 'WC-COMPLETED', '  completed  ' ) as $value ) {
			$data                  = $this->payload();
			$data['trigger_value'] = $value;

			$id = $this->track_rule( $this->repo->insert( $data ) );
			$this->assertGreaterThan( 0, $id, var_export( $value, true ) . ' was refused; it is a permitted transformation.' );
			$this->assertSame( 'completed', $this->repo->find( $id )['trigger_value'] );
		}
	}

	/**
	 * The READ path applies the identical raw contract, so write and read cannot
	 * drift about which inputs are acceptable.
	 *
	 * @return void
	 */
	public function test_the_fetch_refuses_what_the_write_refuses() {
		$slug = 'wcep-' . strtolower( wp_generate_password( 8, false, false ) );

		$data                  = $this->payload();
		$data['trigger_value'] = $slug;
		$id                    = $this->track_rule( $this->repo->insert( $data ) );

		// The permitted transformations still find it.
		$this->assertCount( 1, $this->repo->find_active_for_trigger( 'status', 'WC-' . strtoupper( $slug ) ) );
		$this->assertSame( $id, $this->repo->find_active_for_trigger( 'status', 'wc-' . $slug )[0]['id'] );

		// Anything needing repair finds nothing, rather than being repaired into
		// a match the stored value never had.
		foreach ( array( '<b>' . $slug . '</b>', $slug . '%20', 'STATUS!' ) as $queried ) {
			$this->assertSame( array(), $this->repo->find_active_for_trigger( 'status', $queried ), $queried );
		}
		$this->assertSame( array(), $this->repo->find_active_for_trigger( 'STATUS', $slug ), 'The type was repaired on read.' );
	}

	/**
	 * The pure slug validator agrees with WordPress's real `sanitize_key()`.
	 *
	 * `TriggerEvent` is deliberately free of WordPress so identity construction
	 * stays unit testable, so it spells out `sanitize_key()`'s output alphabet
	 * rather than calling it. This is where the copy is proved not to have
	 * drifted — the unit suite cannot make this assertion, because the real
	 * function is not loaded there.
	 *
	 * @return void
	 */
	public function test_the_slug_validator_agrees_with_sanitize_key() {
		$candidates = array(
			'completed',
			'checkout-draft',
			'awaiting_stock',
			'stage2',
			'wc-completed',
			'WC-COMPLETED',
			'  completed  ',
			'',
			'   ',
			'wc-',
			'on hold',
			'on.hold',
			'a/b',
			'100%',
			"completed'",
			'terminé',
			'pending>processing',
		);

		foreach ( $candidates as $candidate ) {
			$normalized = \Extonify\WCEP\Domain\TriggerEvent::normalize_status( $candidate );
			$expected   = '' !== $normalized && sanitize_key( $normalized ) === $normalized;

			$this->assertSame(
				$expected,
				\Extonify\WCEP\Domain\TriggerEvent::is_valid_status_slug( $candidate ),
				'is_valid_status_slug() disagrees with sanitize_key() for ' . var_export( $candidate, true )
				. '. The hand-written alphabet has drifted from WordPress.'
			);
		}
	}

	/**
	 * Rows currently in the rules table.
	 *
	 * @return int
	 */
	private function rule_row_count(): int {
		global $wpdb;

		$table = $this->repo->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	// ---------------------------------------------------------------------
	// Raw-passenger safety (ADR-0011 §3).
	// ---------------------------------------------------------------------

	/**
	 * A hydrated row round-tripped straight back into `update()` writes exactly
	 * the original columns and NO passenger.
	 *
	 * `hydrate()` attaches each JSON column's raw string so the matcher can tell
	 * broken JSON from an empty document. Those keys are read-only: `sanitize()`
	 * builds its output from a fixed list of column names, so nothing in the
	 * input can introduce one. This asserts that rather than trusting it.
	 *
	 * @return void
	 */
	public function test_a_hydrated_row_round_trips_without_writing_a_passenger() {
		$id     = $this->track_rule( $this->repo->insert( $this->payload() ) );
		$before = $this->repo->find( $id );

		foreach ( RuleRepository::raw_passenger_keys() as $passenger ) {
			$this->assertArrayHasKey( $passenger, $before, 'The fixture is not exercising the passenger at all.' );
		}

		$statements = $this->capturing_queries(
			function () use ( $id, $before ) {
				$this->assertTrue( $this->repo->update( $id, $before ) );
			}
		);

		$writes = array_values(
			array_filter(
				$statements,
				static function ( string $sql ): bool {
					return 1 === preg_match( '/^\s*update\b/i', $sql );
				}
			)
		);
		$this->assertCount( 1, $writes, 'The round trip should have issued exactly one UPDATE.' );

		foreach ( RuleRepository::raw_passenger_keys() as $passenger ) {
			$this->assertStringNotContainsString(
				$passenger,
				$writes[0],
				"The passenger {$passenger} reached the write path."
			);
		}

		// The row is unchanged in every column, and a no-op save did not bump
		// the revision either.
		$after = $this->repo->find( $id );
		$this->assertSame( $before['revision'], $after['revision'] );
		foreach ( $before as $column => $value ) {
			if ( 'updated_at' === $column ) {
				continue;
			}
			$this->assertSame( $value, $after[ $column ], "Round-tripping the row changed {$column}." );
		}
	}

	/**
	 * The same guarantee on the INSERT path: a hydrated row handed to insert()
	 * creates an ordinary rule and writes no passenger column.
	 *
	 * @return void
	 */
	public function test_a_hydrated_row_can_be_inserted_without_a_passenger() {
		$source = $this->repo->find( $this->track_rule( $this->repo->insert( $this->payload() ) ) );

		$statements = $this->capturing_queries(
			function () use ( $source ) {
				$this->track_rule( $this->repo->insert( $source ) );
			}
		);

		$inserts = array_values(
			array_filter(
				$statements,
				static function ( string $sql ): bool {
					return 1 === preg_match( '/^\s*insert\b/i', $sql );
				}
			)
		);
		$this->assertNotEmpty( $inserts );

		foreach ( RuleRepository::raw_passenger_keys() as $passenger ) {
			$this->assertStringNotContainsString( $passenger, $inserts[0] );
		}
	}

	/**
	 * A caller cannot smuggle a passenger key into a write by supplying one.
	 *
	 * @return void
	 */
	public function test_a_supplied_passenger_key_is_never_written() {
		$data = $this->payload();
		foreach ( RuleRepository::raw_passenger_keys() as $passenger ) {
			$data[ $passenger ] = '{"products":[999]}';
		}

		$statements = $this->capturing_queries(
			function () use ( $data ) {
				$this->track_rule( $this->repo->insert( $data ) );
			}
		);

		foreach ( $statements as $sql ) {
			foreach ( RuleRepository::raw_passenger_keys() as $passenger ) {
				$this->assertStringNotContainsString( $passenger, $sql );
			}
		}
	}

	/**
	 * Run a callback with every SQL statement recorded.
	 *
	 * @param callable $callback Work to observe.
	 * @return string[] Statements issued, in order.
	 */
	private function capturing_queries( callable $callback ): array {
		$captured = array();

		$recorder = static function ( $query ) use ( &$captured ) {
			$captured[] = (string) $query;
			return $query;
		};

		add_filter( 'query', $recorder );
		try {
			$callback();
		} finally {
			remove_filter( 'query', $recorder );
		}

		return $captured;
	}
}
