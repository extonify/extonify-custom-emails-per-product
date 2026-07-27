<?php
/**
 * Revision coverage across every behaviour-changing field (Prompt 2a Item 7).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Repository\RuleRepository;

/**
 * `revision` feeds the ADR-0007 scheduling snapshot and the audit trail: it is
 * how anyone later works out WHICH version of a rule produced a delivery.
 *
 * The original inclusion list silently exempted `trigger_type`,
 * `trigger_value`, `delay_seconds`, `consolidation` and `stop_processing` — so
 * changing a delay from one day to seven left the revision unchanged and the
 * audit trail could not tell the two behaviours apart. The rule is now an
 * EXCLUSION list, and this data provider walks every column to prove it.
 */
final class RuleRevisionTest extends IntegrationTestCase {

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
	 * A baseline rule with every column populated.
	 *
	 * @return array
	 */
	private function payload(): array {
		return array(
			'name'            => 'Baseline rule',
			'status'          => 'active',
			'priority'        => 10,
			'trigger_type'    => 'status',
			'trigger_value'   => 'completed',
			'delivery_mode'   => 'insert',
			'native_email_id' => 'customer_completed_order',
			'insert_position' => 'after_order_table',
			'targeting'       => array( 'products' => array( 1 ) ),
			'recipients'      => array( 'customer' ),
			'subject'         => 'Baseline subject',
			'heading'         => 'Baseline heading',
			'content'         => '<p>Baseline content.</p>',
			'delay_seconds'   => 86400,
			'consolidation'   => 'none',
			'stop_processing' => 0,
		);
	}

	/**
	 * Every behaviour-changing column bumps the revision when it changes.
	 *
	 * @dataProvider revision_bearing_provider
	 *
	 * @param string $field Column name.
	 * @param mixed  $value New value, different from the baseline.
	 * @return void
	 */
	public function test_revision_bearing_field_bumps( string $field, $value ) {
		$id = $this->track_rule( $this->repo->insert( $this->payload() ) );
		$this->assertSame( 1, $this->repo->find( $id )['revision'] );

		$this->assertTrue( $this->repo->update( $id, array( $field => $value ) ) );

		$this->assertSame(
			2,
			$this->repo->find( $id )['revision'],
			"Changing {$field} did not bump the revision — the scheduling snapshot and audit trail cannot distinguish the behaviour."
		);
	}

	/**
	 * Every column that changes delivered behaviour.
	 *
	 * The five marked NEW are the ones the original inclusion list missed.
	 *
	 * @return array<string,array{0:string,1:mixed}>
	 */
	public static function revision_bearing_provider() {
		return array(
			'subject'               => array( 'subject', 'A different subject' ),
			'heading'               => array( 'heading', 'A different heading' ),
			'content'               => array( 'content', '<p>Different content.</p>' ),
			'recipients'            => array( 'recipients', array( 'customer', 'admin' ) ),
			'targeting'             => array( 'targeting', array( 'products' => array( 2, 3 ) ) ),
			'delivery_mode'         => array( 'delivery_mode', 'separate' ),
			'insert_position'       => array( 'insert_position', 'before_order_table' ),
			'native_email_id'       => array( 'native_email_id', 'customer_processing_order' ),
			// NEW in Prompt 2a — silently exempt before.
			'trigger_type (NEW)'    => array( 'trigger_type', 'refund' ),
			'trigger_value (NEW)'   => array( 'trigger_value', 'processing' ),
			'delay_seconds (NEW)'   => array( 'delay_seconds', 604800 ),
			'consolidation (NEW)'   => array( 'consolidation', 'daily' ),
			'stop_processing (NEW)' => array( 'stop_processing', 1 ),
		);
	}

	/**
	 * Purely administrative columns do NOT bump the revision: renaming a rule,
	 * toggling it, or reordering it changes no delivered behaviour.
	 *
	 * @dataProvider administrative_provider
	 *
	 * @param string $field Column name.
	 * @param mixed  $value New value.
	 * @return void
	 */
	public function test_administrative_field_does_not_bump( string $field, $value ) {
		$id = $this->track_rule( $this->repo->insert( $this->payload() ) );

		$this->assertTrue( $this->repo->update( $id, array( $field => $value ) ) );

		$rule = $this->repo->find( $id );
		$this->assertSame( 1, $rule['revision'], "Changing the administrative field {$field} bumped the revision." );
		$this->assertSame( $value, $rule[ $field ], "The administrative change to {$field} was not stored." );
	}

	/**
	 * Columns that must stay exempt.
	 *
	 * @return array<string,array{0:string,1:mixed}>
	 */
	public static function administrative_provider() {
		return array(
			'name'     => array( 'name', 'Renamed rule' ),
			'status'   => array( 'status', 'inactive' ),
			'priority' => array( 'priority', 99 ),
		);
	}

	/**
	 * Every revision-bearing column named by the provider is a real column,
	 * and together with the exclusion list they account for the whole rule
	 * row. A column added later without a decision therefore shows up here.
	 *
	 * @return void
	 */
	public function test_provider_and_exclusion_list_cover_every_column() {
		$id   = $this->track_rule( $this->repo->insert( $this->payload() ) );
		$rule = $this->repo->find( $id );

		$covered = array();
		foreach ( self::revision_bearing_provider() as $case ) {
			$covered[] = $case[0];
		}
		foreach ( self::administrative_provider() as $case ) {
			$covered[] = $case[0];
		}
		$covered = array_merge( $covered, RuleRepository::NON_REVISION_FIELDS );

		/*
		 * The raw JSON passengers are NOT columns. `hydrate()` carries each JSON
		 * column's original string alongside the decoded array so the matching
		 * engine can tell broken JSON from an empty document (ADR-0011 §3); they
		 * are never written and never sanitised, so they carry no revision
		 * decision to make.
		 *
		 * THE EXEMPTION IS EXACT, NEVER A WILDCARD. Dropping anything matching
		 * `*_raw` would exempt a genuinely new COLUMN that happened to end that
		 * way — the same hole this whole test exists to close. Only the
		 * enumerated keys are exempt, and each is proved below not to be a real
		 * column before it is allowed to excuse anything.
		 */
		$passengers = RuleRepository::raw_passenger_keys();
		$columns    = $this->rule_table_columns();

		foreach ( $passengers as $passenger ) {
			$this->assertNotContains(
				$passenger,
				$columns,
				"{$passenger} is a REAL column in the rules table, so exempting it would hide it from revision coverage."
			);
			$this->assertContains( $passenger, array_keys( $rule ), "{$passenger} is declared a passenger but hydrate() does not produce it." );
		}

		$covered = array_merge( $covered, $passengers );

		$uncovered = array_diff( array_keys( $rule ), $covered );

		$this->assertSame(
			array(),
			array_values( $uncovered ),
			'A rule column is neither exercised by this test nor listed as exempt: ' . implode( ', ', $uncovered )
		);

		// Every real column is accounted for from the database's side too, so a
		// column that hydrate() somehow dropped cannot escape either.
		$unaccounted = array_diff( $columns, $covered );
		$this->assertSame(
			array(),
			array_values( $unaccounted ),
			'A rules table column is not covered by this test: ' . implode( ', ', $unaccounted )
		);
	}

	/**
	 * The rules table's real column names, read from the schema rather than
	 * from a hydrated row.
	 *
	 * @return string[]
	 */
	private function rule_table_columns(): array {
		global $wpdb;

		$table = ( new RuleRepository() )->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );

		$this->assertNotEmpty( $columns, 'Could not read the rules table columns.' );

		return array_map( 'strval', (array) $columns );
	}

	/**
	 * Re-saving identical values does not bump the revision, so a no-op save
	 * cannot invalidate a scheduling snapshot.
	 *
	 * @return void
	 */
	public function test_identical_values_do_not_bump() {
		$payload = $this->payload();
		$id      = $this->track_rule( $this->repo->insert( $payload ) );

		$this->repo->update(
			$id,
			array(
				'subject'         => $payload['subject'],
				'delay_seconds'   => $payload['delay_seconds'],
				'targeting'       => $payload['targeting'],
				'stop_processing' => $payload['stop_processing'],
			)
		);

		$this->assertSame( 1, $this->repo->find( $id )['revision'] );
	}

	/**
	 * Several behaviour-changing fields in one update bump the revision once,
	 * not once per field.
	 *
	 * @return void
	 */
	public function test_multiple_changes_bump_once() {
		$id = $this->track_rule( $this->repo->insert( $this->payload() ) );

		$this->repo->update(
			$id,
			array(
				'subject'       => 'New subject',
				'delay_seconds' => 3600,
				'consolidation' => 'weekly',
			)
		);

		$this->assertSame( 2, $this->repo->find( $id )['revision'] );
	}

	/**
	 * The revision keeps climbing across successive edits.
	 *
	 * @return void
	 */
	public function test_revision_increments_across_successive_edits() {
		$id = $this->track_rule( $this->repo->insert( $this->payload() ) );

		$this->repo->update( $id, array( 'delay_seconds' => 100 ) );
		$this->repo->update( $id, array( 'delay_seconds' => 200 ) );
		$this->repo->update( $id, array( 'delay_seconds' => 300 ) );

		$this->assertSame( 4, $this->repo->find( $id )['revision'] );
	}
}
