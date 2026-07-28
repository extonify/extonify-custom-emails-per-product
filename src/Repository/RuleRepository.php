<?php
/**
 * Rule definition repository (ADR-0009).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Repository;

use Extonify\WCEP\Domain\Json;
use Extonify\WCEP\Domain\RecipientsDocument;
use Extonify\WCEP\Domain\Targeting;
use Extonify\WCEP\Domain\TriggerEvent;
use Extonify\WCEP\Install\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for rule definitions.
 *
 * This class stores and retrieves rules. It does NOT decide whether a rule
 * matches an order — that is `Matching\RuleMatcher`.
 *
 * IT DOES NORMALISE THE TRIGGER, AND THAT BELONGS HERE (ADR-0011 §2).
 * `find_active_for_trigger()` matches `trigger_value` with `= %s`, so a rule
 * stored as `wc-completed`, `WC-COMPLETED` or `wc-pending>wc-processing` is
 * never returned: it never fires and never explains why — no decision, no
 * reason code, nothing in the delivery log. ADR-0011 originally placed that
 * obligation on the rule editor, which is the wrong boundary: the repository is
 * the only one every path crosses — importers, WP-CLI, migrations, a future REST
 * endpoint, and the editor itself. Normalisation runs on `insert()`, on
 * `update()` and on the read side, all through the single normaliser in
 * `Domain\TriggerEvent`.
 */
class RuleRepository {

	/**
	 * Rule statuses.
	 */
	const STATUSES = array( 'active', 'inactive' );

	/**
	 * Trigger types (ADR-0004).
	 */
	const TRIGGER_TYPES = array( 'status', 'transition', 'refund' );

	/**
	 * Delivery modes (ADR-0005).
	 */
	const DELIVERY_MODES = array( 'insert', 'separate' );

	/**
	 * Columns holding JSON documents.
	 */
	const JSON_COLUMNS = array( 'targeting', 'recipients' );

	/**
	 * Suffix under which a hydrated row keeps each JSON column's RAW string.
	 *
	 * `Json::decode()` returns an empty array for a column that is empty AND
	 * for one holding syntactically broken JSON, which is the right contract
	 * for a reader that just wants an array. The matching engine needs the two
	 * apart: an empty targeting document is a valid, inert rule
	 * (`no_targeting_match`, deliberately NOT logged by ADR-0005), while broken
	 * JSON is `targeting_invalid` and IS logged, because a rule that can never
	 * fire is something support has to be able to see (ADR-0011 §7).
	 *
	 * The raw value is read-only passenger data: `sanitize()` builds its output
	 * from a fixed list of column names and never emits these keys, so a
	 * hydrated row handed straight back to `insert()` or `update()` cannot write
	 * one. See self::raw_passenger_keys().
	 *
	 * ALIASES `Domain\Json::RAW_SUFFIX`, which is where the convention now
	 * lives, so the writer here and the reader in `Domain\PreparedRule` cannot
	 * name the passenger differently.
	 */
	const RAW_SUFFIX = Json::RAW_SUFFIX;

	/**
	 * Fields whose change does NOT bump `revision`.
	 *
	 * Deliberately an EXCLUSION list. An inclusion list silently exempted
	 * behaviour-changing columns — `trigger_type`, `trigger_value`,
	 * `delay_seconds`, `consolidation` and `stop_processing` were all missing,
	 * so changing a delay from one day to seven left the revision unchanged and
	 * the ADR-0007 scheduling snapshot and audit trail could not tell which
	 * behaviour actually produced a delivery. Inverting it makes every future
	 * column revision-bearing by default; exempting one becomes a deliberate,
	 * reviewable edit here.
	 *
	 * Only purely administrative metadata is exempt: renaming a rule, toggling
	 * it on or off, and reordering it change no delivered behaviour. The
	 * timestamps are bookkeeping.
	 *
	 * Per ADR-0004, `revision` is never part of the delivery identity.
	 */
	const NON_REVISION_FIELDS = array(
		'name',
		'status',
		'priority',
		'created_at',
		'updated_at',
		'revision',
		'id',
	);

	/**
	 * Fully-prefixed table name.
	 *
	 * @return string
	 */
	public function table(): string {
		return Migrator::table( 'rules' );
	}

	/**
	 * The EXACT set of read-only passenger keys a hydrated row carries.
	 *
	 * Enumerated, never matched by prefix or suffix. A wildcard would exempt any
	 * future column that happened to end in `_raw` as well, which is exactly the
	 * hole `RuleRevisionTest`'s column-coverage guard exists to close.
	 *
	 * @return string[]
	 */
	public static function raw_passenger_keys(): array {
		$keys = array();
		foreach ( self::JSON_COLUMNS as $column ) {
			$keys[] = $column . self::RAW_SUFFIX;
		}
		return $keys;
	}

	/**
	 * Insert a rule.
	 *
	 * @param array $data Rule fields; unknown keys are ignored.
	 * @return int Inserted rule id, or 0 on failure — including a malformed
	 *             transition trigger, which is refused rather than stored.
	 */
	public function insert( array $data ): int {
		global $wpdb;

		$trigger = $this->resolve_trigger( $data, 'status', '' );
		if ( null === $trigger ) {
			return 0;
		}

		$now = current_time( 'mysql', true );
		$row = $this->sanitize( $data, null, $trigger );

		$row['revision']   = 1;
		$row['created_at'] = $now;
		$row['updated_at'] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- insert into the plugin-owned rules table; $wpdb->insert() prepares every value.
		$ok = $wpdb->insert( $this->table(), $row, $this->formats( $row ) );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a rule, bumping `revision` when a content-bearing field changed.
	 *
	 * @param int   $rule_id Rule id.
	 * @param array $data    Fields to change; unknown keys are ignored.
	 * @return bool True when the row was written. False when the rule does not
	 *              exist, or when the resulting transition trigger would be
	 *              malformed.
	 */
	public function update( int $rule_id, array $data ): bool {
		global $wpdb;

		$existing = $this->find( $rule_id );
		if ( null === $existing ) {
			return false;
		}

		/*
		 * A PARTIAL UPDATE OF `trigger_value` MUST KNOW THE EXISTING
		 * `trigger_type` — `completed` normalises one way for a status rule and
		 * is malformed for a transition one — so the stored row is what supplies
		 * whichever half the caller left out.
		 */
		$trigger = $this->resolve_trigger( $data, (string) $existing['trigger_type'], (string) $existing['trigger_value'] );
		if ( null === $trigger ) {
			return false;
		}

		$row               = $this->sanitize( $data, array_keys( $data ), $trigger );
		$row['updated_at'] = current_time( 'mysql', true );

		if ( $this->content_changed( $existing, $row ) ) {
			$row['revision'] = (int) $existing['revision'] + 1;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- primary-key update of the plugin-owned rules table; $wpdb->update() prepares every value.
		$updated = $wpdb->update( $this->table(), $row, array( 'id' => $rule_id ), $this->formats( $row ), array( '%d' ) );

		return false !== $updated;
	}

	/**
	 * Fetch one rule by id, JSON columns decoded.
	 *
	 * @param int $rule_id Rule id.
	 * @return array|null
	 */
	public function find( int $rule_id ): ?array {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- primary-key read of the plugin-owned rules table.
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $rule_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Active rules for a trigger, in priority order.
	 *
	 * Uses the (trigger_type, trigger_value) index to narrow candidates before
	 * any JSON is decoded. Returns storage rows only — deciding which of them
	 * applies to an order belongs to the matching engine.
	 *
	 * THE QUERY VALUE IS NORMALISED THE SAME WAY THE STORED ONE IS, from the
	 * same RAW contract. The writes store a normalised slug, so a read that did
	 * not normalise would reproduce the very silent miss this class now
	 * prevents, just from the other side —
	 * `find_active_for_trigger( 'status', 'wc-completed' )` would match nothing
	 * and say nothing. Neither side sanitises first, or the two would disagree
	 * about which inputs are acceptable.
	 *
	 * @param string $trigger_type  One of self::TRIGGER_TYPES.
	 * @param string $trigger_value Trigger value, e.g. a status slug.
	 * @return array[] Rules with JSON columns decoded.
	 */
	public function find_active_for_trigger( string $trigger_type, string $trigger_value ): array {
		global $wpdb;
		$table = $this->table();

		$trigger = self::normalize_trigger( $trigger_type, $trigger_value );

		// A trigger the write path refuses can never have been stored, so no row
		// can match it; asking the database would be a query spent proving that.
		if ( null === $trigger ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- indexed read of the plugin-owned rules table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input; an identifier cannot be bound by prepare().
				"SELECT * FROM {$table} WHERE status = %s AND trigger_type = %s AND trigger_value = %s ORDER BY priority ASC, id ASC",
				'active',
				$trigger['type'],
				$trigger['value']
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * All rules, newest first. Used by tests and the future admin list table.
	 *
	 * @param int $limit  Page size.
	 * @param int $offset Page offset.
	 * @return array[]
	 */
	public function all( int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- listing read of the plugin-owned rules table.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input.
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", max( 1, $limit ), max( 0, $offset ) ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Delete a rule.
	 *
	 * Tombstones referencing it are deliberately left alone: they are keyed by
	 * order and rule id and must survive, or deleting a rule would re-arm every
	 * order it had already served (ADR-0004).
	 *
	 * @param int $rule_id Rule id.
	 * @return bool
	 */
	public function delete( int $rule_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- primary-key delete of the plugin-owned rules table.
		$deleted = $wpdb->delete( $this->table(), array( 'id' => $rule_id ), array( '%d' ) );

		return is_int( $deleted ) && $deleted > 0;
	}

	/**
	 * Sanitise rule input at the storage boundary.
	 *
	 * BUILDS ITS OUTPUT FROM A FIXED LIST OF COLUMN NAMES. Nothing in `$data`
	 * can introduce a key here, which is what makes the hydrated row's raw JSON
	 * passengers (self::raw_passenger_keys()) unable to reach a write.
	 *
	 * @param array         $data    Raw input.
	 * @param string[]|null $only    Restrict to these keys (partial update).
	 * @param array         $trigger Normalised trigger from self::resolve_trigger().
	 * @return array Column => value, ready for $wpdb.
	 */
	private function sanitize( array $data, ?array $only, array $trigger ): array {
		$out = array();

		/*
		 * Changing the TYPE re-normalises the VALUE even when the caller did not
		 * mention it: `refund` forces the value empty, and a value normalised
		 * under the old type is meaningless — and unfindable — under the new one.
		 */
		if ( null !== $only && in_array( 'trigger_type', $only, true ) && ! in_array( 'trigger_value', $only, true ) ) {
			$only[] = 'trigger_value';
		}

		$put = function ( $key, $value ) use ( &$out, $only ) {
			if ( null === $only || in_array( $key, $only, true ) ) {
				$out[ $key ] = $value;
			}
		};

		$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : 'inactive';
		$mode   = isset( $data['delivery_mode'] ) ? sanitize_key( (string) $data['delivery_mode'] ) : 'separate';

		$put( 'name', sanitize_text_field( (string) ( $data['name'] ?? '' ) ) );
		$put( 'status', in_array( $status, self::STATUSES, true ) ? $status : 'inactive' );
		$put( 'priority', (int) ( $data['priority'] ?? 10 ) );
		$put( 'trigger_type', $trigger['type'] );
		$put( 'trigger_value', $trigger['value'] );
		$put( 'delivery_mode', in_array( $mode, self::DELIVERY_MODES, true ) ? $mode : 'separate' );
		$put( 'native_email_id', sanitize_key( (string) ( $data['native_email_id'] ?? '' ) ) );
		$put( 'insert_position', sanitize_key( (string) ( $data['insert_position'] ?? '' ) ) );
		$put( 'targeting', self::encode_json_column( 'targeting', (array) ( $data['targeting'] ?? array() ) ) );
		$put( 'recipients', self::encode_json_column( 'recipients', (array) ( $data['recipients'] ?? array() ) ) );
		$put( 'subject', sanitize_text_field( (string) ( $data['subject'] ?? '' ) ) );
		$put( 'heading', sanitize_text_field( (string) ( $data['heading'] ?? '' ) ) );
		// Rule content is merchant-authored HTML email body: wp_kses_post keeps
		// safe markup and strips scripts. Escaped again on output by the caller.
		$put( 'content', wp_kses_post( (string) ( $data['content'] ?? '' ) ) );
		$put( 'delay_seconds', max( 0, (int) ( $data['delay_seconds'] ?? 0 ) ) );
		$put( 'consolidation', sanitize_key( (string) ( $data['consolidation'] ?? 'none' ) ) );
		$put( 'stop_processing', ! empty( $data['stop_processing'] ) ? 1 : 0 );

		return $out;
	}

	/**
	 * Work out the trigger type and the NORMALISED trigger value a write should
	 * store, filling either half from the existing row when the caller omitted
	 * it (ADR-0011 §2).
	 *
	 * @param array  $data           Raw input.
	 * @param string $existing_type  Trigger type already stored ('status' for a
	 *                               fresh insert).
	 * @param string $existing_value Trigger value already stored.
	 * @return array|null `{type, value}`, or null when the trigger is malformed
	 *                    and must not be stored.
	 */
	private function resolve_trigger( array $data, string $existing_type, string $existing_value ): ?array {
		/*
		 * THE ORIGINAL VALUES, NOT SANITISED ONES. Sanitising before validating
		 * REPAIRS malformed input into validity and then stores it:
		 * `sanitize_text_field()` strips tags and percent-encoded sequences, so
		 * `"<b>completed</b>"` and `"completed%20"` both became a working
		 * `completed` trigger; `sanitize_key()` lowercased and stripped, so
		 * `"STATUS!"` became `status`. That is the identical silent-coercion
		 * shape removed from trigger types and from id parsing — surviving one
		 * layer up, inside the very function that removed it elsewhere.
		 *
		 * `TriggerEvent` performs exactly the transformations ADR-0011 §2
		 * permits — trim, collapse whitespace, ASCII case folding, one `wc-`
		 * prefix — and then validates the slug alphabet. Anything needing more
		 * than those is refused, not repaired. Nothing here relies on
		 * sanitisation for safety: the accepted alphabet is `[a-z0-9_-]`, the
		 * type is one of three literals, and `$wpdb` prepares every value.
		 */
		$type = array_key_exists( 'trigger_type', $data )
			? (string) $data['trigger_type']
			: $existing_type;

		$value = array_key_exists( 'trigger_value', $data )
			? (string) $data['trigger_value']
			: $existing_value;

		return self::normalize_trigger( $type, $value );
	}

	/**
	 * Validate and normalise a (type, value) trigger pair, or refuse it.
	 *
	 * SHARED BY THE WRITE AND READ PATHS so they cannot drift: what
	 * `insert()`/`update()` store and what `find_active_for_trigger()` looks for
	 * are produced by the same function, which is the only reason an exact-match
	 * fetch is safe at all.
	 *
	 * AN UNKNOWN TYPE IS REFUSED, NEVER COERCED. This used to fall back to
	 * `status`, so `trigger_type = 'statuz'` was silently stored as a working
	 * status rule — worse than an inert rule, because junk input became a
	 * DIFFERENT VALID TRIGGER and the rule fired on events its author never
	 * chose. That is the identical failure shape as the `(int)` cast removed
	 * from id parsing, and it is refused for the identical reason.
	 *
	 * BOTH ARGUMENTS ARE THE RAW VALUES. The type is compared to the allowed
	 * list EXACTLY — no `sanitize_key()` first, or `'STATUS!'` would be repaired
	 * into `status` and pass. It lands on a valid value by accident there, which
	 * is precisely why leaving it inconsistent with the value path would invite
	 * the value path to be "fixed" back later.
	 *
	 * @param string $type  Raw trigger type, exactly as supplied.
	 * @param string $value Raw trigger value, exactly as supplied.
	 * @return array|null `{type, value}`, or null when the pair must not be
	 *                    stored and can never match.
	 */
	private static function normalize_trigger( string $type, string $value ): ?array {
		if ( ! in_array( $type, self::TRIGGER_TYPES, true ) ) {
			return null;
		}

		$normalized = self::normalize_trigger_value( $type, $value );

		if ( null === $normalized ) {
			return null;
		}

		return array(
			'type'  => $type,
			'value' => $normalized,
		);
	}

	/**
	 * Normalise one trigger value to the stored contract (ADR-0011 §2).
	 *
	 * | Type | Stored value | Example |
	 * |---|---|---|
	 * | `status` | normalised destination slug | `wc-Completed` -> `completed` |
	 * | `transition` | `{from}>{to}`, both normalised | `wc-a>WC-B` -> `a>b` |
	 * | `refund` | empty string | anything -> `` |
	 *
	 * THE SLUG NORMALISER IS `TriggerEvent`'s AND THERE IS NO SECOND ONE: a
	 * private copy here would be free to drift, and the fetch compares this
	 * value against `TriggerEvent::value()` with `= %s`, so the two agreeing is
	 * the whole point. Slug SHAPE is checked with
	 * `TriggerEvent::is_valid_status_slug()`, for the same reason.
	 *
	 * REFUSED rather than stored, because each of these is a rule that can never
	 * match and never says so:
	 *
	 *   - an EMPTY or whitespace-only status — no real event ever produces one;
	 *   - a status that is not a slug, on either polarity or either side of a
	 *     transition, validated by SHAPE rather than merely trimmed and
	 *     lowercased;
	 *   - a malformed TRANSITION: no `>`, more than one `>`, or an empty side.
	 *
	 * There is no repair for any of them that is not a guess.
	 *
	 * @param string $type  Trigger type, already one of self::TRIGGER_TYPES.
	 * @param string $value Raw trigger value.
	 * @return string|null Normalised value, or null when it is malformed.
	 */
	private static function normalize_trigger_value( string $type, string $value ): ?string {
		if ( TriggerEvent::TYPE_REFUND === $type ) {
			// ADR-0011 §2: refunds are keyed by refund id, so every active
			// refund rule is a candidate for every refund.
			return '';
		}

		if ( TriggerEvent::TYPE_TRANSITION === $type ) {
			$sides = explode( '>', $value );
			if ( 2 !== count( $sides ) ) {
				return null;
			}

			foreach ( $sides as $side ) {
				if ( ! TriggerEvent::is_valid_status_slug( $side ) ) {
					return null;
				}
			}

			$event = TriggerEvent::transition( $sides[0], $sides[1] );

			return $event->is_valid() ? $event->value() : null;
		}

		if ( ! TriggerEvent::is_valid_status_slug( $value ) ) {
			return null;
		}

		return TriggerEvent::normalize_status( $value );
	}

	/**
	 * Encode one JSON column, honouring the shape that column's reader expects.
	 *
	 * BOTH JSON columns are OBJECT documents read back with strict object/array
	 * validation — `targeting` under ADR-0011 §3, `recipients` under ADR-0012 §4
	 * — so an empty one must be stored `{}` and not the `[]` PHP renders for an
	 * empty array. Routed through one function so `sanitize()` and
	 * `content_changed()` cannot encode the same column two different ways and
	 * bump the revision on a no-op save.
	 *
	 * @param string $column Column name.
	 * @param array  $value  Decoded value.
	 * @return string JSON text.
	 */
	private static function encode_json_column( string $column, array $value ): string {
		if ( 'targeting' === $column ) {
			return Targeting::encode( $value );
		}

		if ( 'recipients' === $column ) {
			return RecipientsDocument::encode( $value );
		}

		return Json::encode( $value );
	}

	/**
	 * $wpdb format specifiers matching a sanitised row.
	 *
	 * @param array $row Sanitised row.
	 * @return string[]
	 */
	private function formats( array $row ): array {
		$int_columns = array( 'priority', 'revision', 'delay_seconds', 'stop_processing' );
		$formats     = array();
		foreach ( array_keys( $row ) as $column ) {
			$formats[] = in_array( $column, $int_columns, true ) ? '%d' : '%s';
		}
		return $formats;
	}

	/**
	 * Decode JSON columns and normalise integer columns on read.
	 *
	 * Each JSON column's raw string is preserved alongside the decoded array
	 * under `{$column}` . self::RAW_SUFFIX — see that constant for why, and
	 * self::raw_passenger_keys() for the exact set of keys this adds.
	 *
	 * @param array $row Raw database row.
	 * @return array
	 */
	private function hydrate( array $row ): array {
		foreach ( self::JSON_COLUMNS as $column ) {
			$raw                               = $row[ $column ] ?? null;
			$row[ $column . self::RAW_SUFFIX ] = is_string( $raw ) ? $raw : null;
			$row[ $column ]                    = Json::decode( $raw );
		}
		foreach ( array( 'id', 'priority', 'revision', 'delay_seconds', 'stop_processing' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}
		return $row;
	}

	/**
	 * Whether an update touches any behaviour-changing field (ADR-0009).
	 *
	 * Everything is revision-bearing except self::NON_REVISION_FIELDS. JSON
	 * columns are compared as their encoded strings, so re-saving an identical
	 * structure does not bump the revision.
	 *
	 * @param array $existing Hydrated existing row.
	 * @param array $changes  Sanitised incoming columns.
	 * @return bool
	 */
	private function content_changed( array $existing, array $changes ): bool {
		foreach ( $changes as $field => $value ) {
			if ( in_array( $field, self::NON_REVISION_FIELDS, true ) ) {
				continue;
			}
			$before = $existing[ $field ] ?? '';
			if ( in_array( $field, self::JSON_COLUMNS, true ) ) {
				$before = self::encode_json_column( $field, (array) $before );
			}
			if ( (string) $before !== (string) $value ) {
				return true;
			}
		}
		return false;
	}
}
