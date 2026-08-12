<?php
/**
 * Rule definition repository (ADR-0009).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Repository;

use Extonify\WCEP\Delivery\Consolidation;
use Extonify\WCEP\Delivery\ScheduledCancellation;
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
	 * Refusal codes (ADR-0017 §3). WHY a column was refused, so the admin editor can
	 * say something a merchant can act on rather than "could not save".
	 *
	 * They name the KIND of refusal, never the message: the wording is presentation
	 * and belongs to whatever surface is showing it.
	 *
	 * The value is not one of the column's permitted values.
	 */
	const REFUSED_NOT_IN_VOCABULARY = 'not_in_vocabulary';

	/**
	 * The value is not the right SHAPE for the column at all.
	 */
	const REFUSED_MALFORMED = 'malformed';

	/**
	 * The value is well-formed but insert mode forbids it (ADR-0013 §2, ADR-0016 §2).
	 */
	const REFUSED_INSERT_FORBIDS = 'insert_forbids';

	/**
	 * An insert rule left `native_email_id` empty, so it targets no email at all.
	 */
	const REFUSED_REQUIRED_FOR_INSERT = 'required_for_insert';

	/**
	 * The named WooCommerce email is not one this store has.
	 */
	const REFUSED_UNREGISTERED = 'unregistered';

	/**
	 * Columns the list screen may sort by, mapped to themselves.
	 *
	 * ⚠ AN ALLOWLIST BECAUSE `ORDER BY` CANNOT BE BOUND (ADR-0017 §8). `$wpdb->prepare()`
	 * binds VALUES; an identifier has to be interpolated, so a request-supplied column
	 * name reaching that clause is SQL injection with a capability check in front of
	 * it. Anything not a key of this map takes the default, and the direction is
	 * matched against exactly two literals.
	 */
	const ORDERABLE_COLUMNS = array(
		'id'            => 'id',
		'name'          => 'name',
		'status'        => 'status',
		'priority'      => 'priority',
		'trigger_type'  => 'trigger_type',
		'delivery_mode' => 'delivery_mode',
		'consolidation' => 'consolidation',
		'delay_seconds' => 'delay_seconds',
		'updated_at'    => 'updated_at',
		'created_at'    => 'created_at',
	);

	/**
	 * Columns self::query() and self::count() filter on by exact match.
	 */
	const FILTERABLE_COLUMNS = array( 'status', 'trigger_type', 'delivery_mode', 'consolidation' );

	/**
	 * Longest storable `native_email_id` — the `varchar(100)` column's own width.
	 *
	 * ⚠ MUST MATCH `Migrator`'s SCHEMA, AND A TEST ASSERTS THAT IT DOES against
	 * `information_schema`. A 300-character well-formed id used to pass validation
	 * and be TRUNCATED by MySQL into a different, shorter string — which is the same
	 * silent-coercion failure as a repaired id, arriving from the database instead
	 * of from `sanitize_key()`: the rule then targets an email nobody named, and
	 * `find_active_for_native_email()` can never match it because the read is
	 * refused before it queries.
	 */
	const MAX_NATIVE_EMAIL_ID_LENGTH = 100;

	/**
	 * Longest storable `consolidation` — the `varchar(20)` column's own width.
	 *
	 * ⚠ NO LONGER THE ONLY CONTRACT ON THE COLUMN. Since ADR-0016 §1 the value must
	 * also be a member of `Consolidation::MODES`; this bound is kept as the first test
	 * so the column width stays impossible to exceed even if that vocabulary is grown
	 * carelessly, and so the length assertion against `information_schema` still has
	 * something to compare.
	 */
	const MAX_CONSOLIDATION_LENGTH = 20;

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
	 * How many ids to bind per statement in a batched lookup.
	 *
	 * The same bound the delivery repositories use, for the same reason: one
	 * unbounded `IN (…)` list can exceed `max_allowed_packet`.
	 */
	const ID_CHUNK_SIZE = 200;

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

		if ( ! self::write_is_valid( $data, array() ) ) {
			return 0;
		}

		$mode    = self::effective_mode( $data, array() );
		$trigger = $this->resolve_trigger_for_mode( $data, array(), 'status', '' );
		if ( null === $trigger ) {
			return 0;
		}

		$now = current_time( 'mysql', true );
		$row = $this->sanitize( $data, null, $trigger, $mode );

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
		if ( ! self::write_is_valid( $data, $existing ) ) {
			return false;
		}

		$mode    = self::effective_mode( $data, $existing );
		$trigger = $this->resolve_trigger_for_mode( $data, $existing, (string) $existing['trigger_type'], (string) $existing['trigger_value'] );
		if ( null === $trigger ) {
			return false;
		}

		$row               = $this->sanitize( $data, array_keys( $data ), $trigger, $mode );
		$row['updated_at'] = current_time( 'mysql', true );

		if ( $this->content_changed( $existing, $row ) ) {
			$row['revision'] = (int) $existing['revision'] + 1;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- primary-key update of the plugin-owned rules table; $wpdb->update() prepares every value.
		$updated = $wpdb->update( $this->table(), $row, array( 'id' => $rule_id ), $this->formats( $row ), array( '%d' ) );

		if ( false === $updated ) {
			return false;
		}

		/**
		 * Fires after a rule row is written (ADR-0015 §5).
		 *
		 * The storage boundary announces the change and knows nothing about what
		 * anyone does with it; `Delivery\ScheduledCancellation` subscribes and
		 * cancels the rule's pending delayed deliveries when the write took it out
		 * of the delayed phase.
		 *
		 * @since 1.0.0
		 *
		 * @param int $rule_id The rule that was written.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant IS the prefixed name (`extonify_wcep_rule_updated`); it is referenced rather than repeated so the hook this repository fires and the hook the subscriber listens on cannot drift apart.
		do_action( ScheduledCancellation::ACTION_UPDATED, $rule_id );

		return true;
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
	 * NAMES ONLY, FOR A WHOLE PAGE OF RULE IDS, IN ONE STATEMENT (ADR-0018 §7).
	 *
	 * ⚠ THIS EXISTS SO THE HISTORY SCREEN CANNOT BE WRITTEN AS `find()` IN A LOOP,
	 * which is one statement per row — and one full row, decoding two JSON documents,
	 * to render a single label. The delivery history joins tombstones to rules by id,
	 * and a page of twenty deliveries needs twenty names and nothing else.
	 *
	 * ⚠ AN ID WITH NO ROW IS SIMPLY ABSENT FROM THE RESULT, and the caller must read
	 * that as "this rule was deleted" rather than as "the lookup failed". ADR-0004
	 * keeps a tombstone when its rule goes, so a delivery naming a rule that no longer
	 * exists is the ordinary state of an old row.
	 *
	 * Chunked on the same bound as every other id batch in the repositories, so one
	 * enormous `IN (…)` list can never exceed `max_allowed_packet`.
	 *
	 * @param int[] $rule_ids Rule ids.
	 * @return array<int,string> rule_id => name, for the ids that still exist.
	 */
	public function names_for_ids( array $rule_ids ): array {
		global $wpdb;

		$ids = array();

		foreach ( $rule_ids as $id ) {
			$id = (int) $id;

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		if ( array() === $ids ) {
			return array();
		}

		$table = $this->table();
		$out   = array();

		foreach ( array_chunk( array_values( $ids ), self::ID_CHUNK_SIZE ) as $chunk ) {
			// Placeholders are generated from the COUNT of ids, and every id is an
			// int cast above — no value reaches the SQL unprepared.
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- batched label read of the plugin-owned rules table for one admin page render.
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$table} is a plugin-derived identifier; {$placeholders} is a generated run of %d tokens counted from $chunk, whose members are all int-cast above.
				$wpdb->prepare( "SELECT id, name FROM {$table} WHERE id IN ( {$placeholders} )", $chunk ),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$out[ (int) $row['id'] ] = (string) $row['name'];
			}
		}

		return $out;
	}

	/**
	 * EVERY RULE'S ID AND NAME, FOR A PICKER — and nothing else (ADR-0018 §5).
	 *
	 * ⚠ `query()` WOULD RETURN THE WHOLE ROW, AND THAT IS THE POINT OF THIS METHOD.
	 * A rule row carries `content` — the email body, which is merchant-authored HTML
	 * and routinely kilobytes — plus `targeting` and `recipients`, two JSON documents
	 * `hydrate()` decodes on the way out. Building a dropdown of names from that reads
	 * and decodes megabytes on a store with a few hundred rules, to render a few
	 * hundred short strings. "Nothing is loaded that is not shown" is the rule the
	 * delivery history is built on; a filter control is not exempt from it.
	 *
	 * Ordered by name, because this feeds a control a human reads.
	 *
	 * @param int $limit Maximum rules to return.
	 * @return array<int,string> rule_id => name.
	 */
	public function names_all( int $limit ): array {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- two-column read of the plugin-owned rules table for one admin filter control.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier; the ORDER BY names a hardcoded column and the limit is bound.
			$wpdb->prepare( "SELECT id, name FROM {$table} ORDER BY name ASC, id ASC LIMIT %d", max( 1, $limit ) ),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['id'] ] = (string) $row['name'];
		}

		return $out;
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
	 * Active INSERT rules targeting one WooCommerce email (ADR-0013 §2).
	 *
	 * `native_email_id` IS THE SINGLE SOURCE OF TRUTH for which email an insert
	 * rule belongs to. `trigger_type` and `trigger_value` are not consulted —
	 * they are stored EMPTY for insert rules precisely so there is no second
	 * place for this question to be answered differently.
	 *
	 * `delay_seconds = 0` AND `consolidation = 'none'` are part of the WHERE clause
	 * rather than a later filter, for the same reason ADR-0012 §9 filters before
	 * evaluation: a rule that does not belong to this phase must be left entirely
	 * untouched, not fetched and then discarded somewhere a future edit could
	 * forget. Filtering in the fetch also means such a rule can never HALT a
	 * supported one through `stop_processing`, because it never enters the ordered
	 * evaluation at all.
	 *
	 * ⚠ BOTH LITERALS ARE NOW INSERT MODE'S OWN REQUIREMENTS (ADR-0013 §2,
	 * ADR-0016 §2) — the read side refusing exactly what the write side refuses —
	 * rather than reads of the unimplemented-behaviour list. `delay_seconds` made that
	 * move in Prompt 7 and `consolidation` makes it here: `UNIMPLEMENTED_BEHAVIOUR_DEFAULTS`
	 * is empty now (ADR-0016 §9), so reading a value out of it would be reading a
	 * constant that has come to mean something else.
	 *
	 * ⚠ `consolidation` was missing from this clause until Prompt 5C. Prompt 5B gave
	 * the column validated storage with no vocabulary, so `daily` became storable — and
	 * a `daily` rule was then inserted into every matching email immediately, which is
	 * `none`'s behaviour under another name (ADR-0013 §8a).
	 *
	 * @param string $native_email_id WooCommerce email id currently rendering.
	 * @return array[] Rows in ADR-0011 fetch order.
	 */
	public function find_active_for_native_email( string $native_email_id ): array {
		global $wpdb;
		$table = $this->table();

		/*
		 * THE READ REFUSES EXACTLY WHAT THE WRITE REFUSES, and neither side
		 * sanitises first — the same contract `normalize_trigger()` shares between
		 * the two trigger paths, for the same reason. Repairing here would let
		 * `Customer Processing Order` match rows stored as
		 * `customer_processing_order`, which no rule author asked for. An id the
		 * write path cannot store can match nothing, so asking the database would
		 * be a query spent proving that.
		 */
		if ( ! self::is_well_formed_native_email_id( $native_email_id ) ) {
			return array();
		}

		/*
		 * ⚠ NEITHER LITERAL BELOW IS AN UNIMPLEMENTED-BEHAVIOUR DEFAULT ANY MORE — both
		 * are INSERT MODE'S OWN REQUIREMENTS (ADR-0015 §7, ADR-0016 §2, §9).
		 *
		 * `delay_seconds` used to be read out of `UNIMPLEMENTED_BEHAVIOUR_DEFAULTS`,
		 * which was true only while no phase implemented delay; Prompt 7 implements it
		 * and Prompt 8 implements consolidation, so that constant is now EMPTY and
		 * reading either value out of it would be reading a constant that has come to
		 * mean something else. ADR-0013 §2 refuses a delayed insert rule and ADR-0016 §2
		 * refuses a consolidated one, both at the write boundary; this is the read side
		 * refusing exactly what the write side refuses. Stated literally so the two
		 * cannot drift apart.
		 */

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- indexed read of the plugin-owned rules table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input; an identifier cannot be bound by prepare().
				"SELECT * FROM {$table}
				WHERE status = %s AND delivery_mode = %s AND native_email_id = %s
					AND delay_seconds = %d AND consolidation = %s
				ORDER BY priority ASC, id ASC",
				'active',
				'insert',
				$native_email_id,
				// The literal 0 is INSERT MODE'S OWN phase requirement — see above.
				0,
				Consolidation::NONE
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Whether a write may be stored at all (ADR-0009 write boundary, ADR-0013 §2).
	 *
	 * THE VALIDATED HALF OF THE BOUNDARY, AND IT REFUSES RATHER THAN REPAIRS. Every
	 * column checked here is either an ENUMERATION or an IDENTIFIER, and for those
	 * two kinds repair is not a kindness — it silently produces a DIFFERENT VALID
	 * VALUE, which is worse than an inert rule because the rule then behaves in a
	 * way nobody chose and nothing explains. `delivery_mode = 'unknown'` became a
	 * `separate` rule that emailed customers directly when the author meant to
	 * insert; `status = 'wide-open'` became `inactive`; `trigger_type = 'statuz'`
	 * became a working status trigger. Same shape, five columns, one rule.
	 *
	 * A refused write writes NOTHING and leaves any existing row exactly as it was.
	 *
	 * | Column | Refused unless |
	 * |---|---|
	 * | `status` | exactly `active` or `inactive` |
	 * | `delivery_mode` | exactly `insert` or `separate` |
	 * | `native_email_id` | `[a-z0-9_-]+`, at most MAX_NATIVE_EMAIL_ID_LENGTH (empty allowed on a non-insert rule) |
	 * | `consolidation` | exactly `none` or `per_product` — `Consolidation::MODES` (ADR-0016 §1) |
	 * | `trigger_type` / `trigger_value` | see self::normalize_trigger() |
	 *
	 * The remaining insert-mode rules are ADR-0013 §2's and ADR-0016 §2's:
	 *
	 *   - an insert rule with an EMPTY `native_email_id` targets no email, can
	 *     never fire, and can never say why;
	 *   - an insert rule with a NON-ZERO `delay_seconds` is incoherent: there is
	 *     no way to insert content into an email that is already sending, seven
	 *     days from now. Coercing it to zero would leave the merchant with a rule
	 *     whose editor says seven days and whose behaviour says none;
	 *   - an insert rule with a NON-`none` `consolidation` is incoherent for the
	 *     same reason in a different direction: `per_product` chooses HOW MANY
	 *     messages to send, and insert mode does not choose that — WooCommerce
	 *     already did, once, before any rule was consulted.
	 *
	 * The mode itself is read from the write when supplied and from the stored
	 * row otherwise, so a partial update that only changes `delay_seconds` is
	 * still judged against the rule's real mode.
	 *
	 * @param array $data     Raw input; its KEYS are the presence check.
	 * @param array $existing Stored row, or empty for an insert.
	 * @return bool
	 */
	private static function write_is_valid( array $data, array $existing ): bool {
		return array() === self::invalid_column( $data, $existing );
	}

	/**
	 * WHY a write would be refused, as a `{field, code}` pair (ADR-0017 §3).
	 *
	 * ⚠ THE REFUSAL CONTRACT HAS EXACTLY ONE IMPLEMENTATION, AND THIS IS IT.
	 * self::write_is_valid() is now DERIVED from this — `array() === explain_refusal()`
	 * — rather than computed beside it, so the boolean and the explanation cannot
	 * disagree about which writes are storable. That matters because the admin editor
	 * has to tell a merchant WHICH FIELD was refused and why (ADR-0017 §3), and the
	 * obvious alternative — an admin-side diagnosis mirroring these checks — is a
	 * second copy of a six-column contract, free to drift the next time either side is
	 * edited, and free to name the WRONG field to a merchant who then edits the wrong
	 * thing.
	 *
	 * ⚠ IT IS AN EXPLANATION, NEVER A GATE. The repository's own return value is the
	 * sole source of truth for whether a write happened (ADR-0017 §3.1); this says why
	 * one did not. A refusal it cannot account for — a genuine `$wpdb` failure — still
	 * returns `array()` here while `insert()` returns `0`, and the caller must treat
	 * the return value, not this, as the outcome.
	 *
	 * Covers the six validated columns AND the trigger resolution that
	 * `insert()`/`update()` refuse separately, in the order those two apply them, so
	 * the field named is the first one that actually stops the write.
	 *
	 * @param array $data     Raw input, exactly as it would be passed to insert()/update().
	 * @param array $existing Stored row, or empty for an insert.
	 * @return array Empty when the write is storable; otherwise `array{field:string, code:string}`.
	 */
	public static function explain_refusal( array $data, array $existing = array() ): array {
		$column = self::invalid_column( $data, $existing );

		if ( array() !== $column ) {
			return $column;
		}

		return self::invalid_trigger( $data, $existing );
	}

	/**
	 * The validated-column half of the refusal contract.
	 *
	 * The body of what self::write_is_valid() used to be, returning the offending
	 * column instead of `false`. Same checks, same order, same predicates.
	 *
	 * @param array $data     Raw input; its KEYS are the presence check.
	 * @param array $existing Stored row, or empty for an insert.
	 * @return array Empty, or `array{field:string, code:string}`.
	 */
	private static function invalid_column( array $data, array $existing ): array {
		/*
		 * THE ENUMERATIONS FIRST, ON THEIR RAW VALUES, AND BEFORE ANYTHING READS
		 * THEM. `delivery_mode` in particular decides which of the branches below
		 * applies, so validating it later would mean judging an insert-mode write by
		 * a mode that had already been coerced.
		 */
		if ( array_key_exists( 'status', $data ) && ! in_array( self::raw_value( $data, 'status' ), self::STATUSES, true ) ) {
			return self::refusal( 'status', self::REFUSED_NOT_IN_VOCABULARY );
		}

		if ( array_key_exists( 'delivery_mode', $data ) && ! in_array( self::raw_value( $data, 'delivery_mode' ), self::DELIVERY_MODES, true ) ) {
			return self::refusal( 'delivery_mode', self::REFUSED_NOT_IN_VOCABULARY );
		}

		/*
		 * ⚠ `consolidation` IS NOW AN EXHAUSTIVE ENUMERATION (ADR-0016 §1), validated
		 * on the RAW value exactly as `status` and `delivery_mode` are.
		 *
		 * It used to be validated by SHAPE and not by vocabulary, on the reasoning that
		 * consolidation behaviour was out of scope and enumerating the values would be
		 * designing that behaviour in the storage layer. Prompt 8 designs it, so
		 * `daily`, `weekly` and `per_order` become INVALID VALUES rather than merely
		 * unimplemented ones — and that is a real strengthening. Under shape validation
		 * `daily` was storable and the PHASE FILTER was the only thing stopping it from
		 * being delivered as `none` under another name; one missing filter turned a
		 * merchant's digest request into an email per order. A value that cannot exist
		 * needs no filter to remember it.
		 *
		 * THE SHAPE CHECK STAYS AS THE FIRST TEST, not as the only one. A member of the
		 * vocabulary necessarily passes it, so it costs nothing — and it keeps the
		 * `varchar(20)` column's own width impossible to exceed even if the enumeration
		 * is ever grown carelessly. `NONE`, `" none "`, `none!`, `PER_PRODUCT`,
		 * `per_product!` and a 30-character value are all refused rather than quietly
		 * rewritten or truncated by MySQL.
		 */

		/*
		 * ⚠ THE **EFFECTIVE** VALUE, ON EVERY WRITE — NOT ONLY WHEN THE FIELD IS
		 * SUPPLIED (ADR-0016 §1a). This used to be guarded by
		 * `array_key_exists( 'consolidation', $data )`, so an unrelated partial update to
		 * a LEGACY INVALID ROW — one holding `daily`, which was legitimately storable
		 * from Prompt 5B to Prompt 8 — sailed through and re-saved the row, bumping its
		 * revision and leaving it exactly as undeliverable as before while looking to the
		 * merchant like a successful save.
		 *
		 * Reading the effective value follows `self::effective_mode()`'s precedent
		 * (Prompt 5A item 3a) for the same reason: a partial update must be judged
		 * against the row it RESULTS IN, not against the keys the caller happened to
		 * name.
		 *
		 * SO A LEGACY INVALID ROW IS CORRECTABLE, AND ONLY CORRECTABLE: supplying `none`
		 * or `per_product` explicitly is accepted, and any other write is refused with
		 * the row left byte-identical. The plugin will not guess which behaviour the
		 * merchant meant, and it will not deliver until they say — ADR-0009's
		 * refuse-never-repair contract applied to data that predates the contract.
		 */
		$consolidation = self::effective_consolidation( $data, $existing );

		if ( ! self::is_well_formed_key( $consolidation, self::MAX_CONSOLIDATION_LENGTH ) ) {
			return self::refusal( 'consolidation', self::REFUSED_MALFORMED );
		}

		if ( ! Consolidation::is_valid( $consolidation ) ) {
			return self::refusal( 'consolidation', self::REFUSED_NOT_IN_VOCABULARY );
		}

		/*
		 * SHAPE IS CHECKED ON EVERY WRITE THAT SUPPLIES THE COLUMN, NOT ONLY ON
		 * INSERT-MODE ONES. Otherwise a malformed id could be stored, silently
		 * repaired, on a `separate` rule and then become an INSERT rule's target
		 * in one later `update( $id, [ 'delivery_mode' => 'insert' ] )` — the same
		 * coercion arriving by a second route.
		 */
		if ( array_key_exists( 'native_email_id', $data ) ) {
			$raw = self::raw_value( $data, 'native_email_id' );

			if ( '' !== $raw && ! self::is_well_formed_native_email_id( $raw ) ) {
				return self::refusal( 'native_email_id', self::REFUSED_MALFORMED );
			}
		}

		$mode = self::effective_mode( $data, $existing );

		if ( 'insert' !== $mode ) {
			return array();
		}

		/*
		 * THE ORIGINAL VALUE, NOT A SANITISED ONE. `sanitize_key()` ran first
		 * here, so `customer_processing_order!` was REPAIRED into a valid,
		 * registered id and stored — the rule then targeted an email nobody
		 * chose, which is worse than an inert rule for exactly the reason
		 * ADR-0011 §2 gives for trigger values: junk input became a DIFFERENT
		 * VALID TARGET. Same coercion, new column; refused, not repaired.
		 */
		$native = array_key_exists( 'native_email_id', $data )
			? self::raw_value( $data, 'native_email_id' )
			: (string) ( $existing['native_email_id'] ?? '' );

		if ( ! self::is_well_formed_native_email_id( $native ) ) {
			// Empty and malformed are different things to a merchant: one is a field
			// they have not filled in, the other is a value they cannot use.
			return self::refusal(
				'native_email_id',
				'' === $native ? self::REFUSED_REQUIRED_FOR_INSERT : self::REFUSED_MALFORMED
			);
		}

		$delay = array_key_exists( 'delay_seconds', $data )
			? (int) $data['delay_seconds']
			: (int) ( $existing['delay_seconds'] ?? 0 );

		if ( 0 !== $delay ) {
			return self::refusal( 'delay_seconds', self::REFUSED_INSERT_FORBIDS );
		}

		/*
		 * ⚠ AN INSERT RULE MAY NOT CONSOLIDATE (ADR-0016 §2), and the refusal is the
		 * `delay_seconds` precedent applied to a second column rather than a new
		 * mechanism.
		 *
		 * `per_product` means "one email per product". INSERT MODE CONTRIBUTES CONTENT
		 * TO AN EMAIL WOOCOMMERCE IS SENDING, so the number of messages is not this
		 * plugin's to choose — WooCommerce decided it, once, before any rule was
		 * consulted. An insert rule asking for `per_product` is asking for something
		 * that does not exist.
		 *
		 * READ FROM THE EFFECTIVE VALUE, so converting a stored `separate` +
		 * `per_product` rule to `insert` in one `update()` is REFUSED rather than
		 * having its consolidation quietly reset. A merchant whose editor says "one
		 * email per product" and whose behaviour says otherwise is exactly the silent
		 * coercion ADR-0009's write boundary exists to prevent.
		 */
		if ( Consolidation::NONE !== self::effective_consolidation( $data, $existing ) ) {
			return self::refusal( 'consolidation', self::REFUSED_INSERT_FORBIDS );
		}

		if ( ! self::native_email_is_registered( $native ) ) {
			return self::refusal( 'native_email_id', self::REFUSED_UNREGISTERED );
		}

		return array();
	}

	/**
	 * The trigger half of the refusal contract.
	 *
	 * ⚠ REPRODUCES self::resolve_trigger_for_mode()'s DECISION, NOT ITS RESULT, and
	 * says which HALF is at fault. `insert()` and `update()` refuse a null trigger
	 * with the same `0`/`false` they use for an invalid column, so a merchant whose
	 * `trigger_value` reads `pending>` would otherwise be told only that the rule
	 * could not be saved.
	 *
	 * The defaults match what `insert()` passes for a fresh row — type `status`,
	 * value empty — so an insert and an update are judged identically.
	 *
	 * @param array $data     Raw input.
	 * @param array $existing Stored row, or empty for an insert.
	 * @return array Empty, or `array{field:string, code:string}`.
	 */
	private static function invalid_trigger( array $data, array $existing ): array {
		// ADR-0013 §2: an insert rule stores empty trigger fields and skips trigger
		// validation entirely, so there is nothing here that can refuse it.
		if ( 'insert' === self::effective_mode( $data, $existing ) ) {
			return array();
		}

		$type = array_key_exists( 'trigger_type', $data )
			? (string) self::raw_value( $data, 'trigger_type' )
			: (string) ( $existing['trigger_type'] ?? TriggerEvent::TYPE_STATUS );

		$value = array_key_exists( 'trigger_value', $data )
			? (string) self::raw_value( $data, 'trigger_value' )
			: (string) ( $existing['trigger_value'] ?? '' );

		if ( ! in_array( $type, self::TRIGGER_TYPES, true ) ) {
			return self::refusal( 'trigger_type', self::REFUSED_NOT_IN_VOCABULARY );
		}

		if ( null === self::normalize_trigger_value( $type, $value ) ) {
			return self::refusal( 'trigger_value', self::REFUSED_MALFORMED );
		}

		return array();
	}

	/**
	 * One refusal, as a `{field, code}` pair.
	 *
	 * @param string $field Column name.
	 * @param string $code  One of the self::REFUSED_* codes.
	 * @return array
	 */
	private static function refusal( string $field, string $code ): array {
		return array(
			'field' => $field,
			'code'  => $code,
		);
	}

	/**
	 * Whether a value is EXACTLY a well-formed WooCommerce email id.
	 *
	 * The accepted alphabet is `sanitize_key()`'s own — lowercase ASCII letters,
	 * digits, underscore and hyphen — but it is APPLIED AS A TEST rather than as
	 * a transformation. That is the whole difference between this and what it
	 * replaced: `CUSTOMER_PROCESSING_ORDER`, `" customer_processing_order "`,
	 * `customer_processing_order!` and `<b>customer_processing_order</b>` are all
	 * REFUSED here and were all silently repaired into a working target before.
	 *
	 * THE LENGTH IS PART OF WELL-FORMEDNESS, and it is checked HERE so the write and
	 * the read share one answer — `find_active_for_native_email()` calls this too, so
	 * an id the write refuses is an id the read refuses to look for.
	 *
	 * Nothing downstream relies on this for safety — `$wpdb` prepares every value
	 * and the id is compared again at render time (ADR-0013 §4). It exists so a
	 * rule always targets the email its author actually named.
	 *
	 * @param string $value Raw value, exactly as supplied.
	 * @return bool
	 */
	private static function is_well_formed_native_email_id( string $value ): bool {
		return self::is_well_formed_key( $value, self::MAX_NATIVE_EMAIL_ID_LENGTH );
	}

	/**
	 * Whether a raw value is already a well-formed key of at most `$max` bytes.
	 *
	 * THE `sanitize_key()` ALPHABET, APPLIED AS A TEST. A value that would survive
	 * `sanitize_key()` unchanged passes; anything that sanitisation would have had to
	 * REPAIR is refused. The length is measured in BYTES with `strlen()`, because
	 * that is what a MySQL `varchar` column counts when it decides whether to
	 * truncate.
	 *
	 * @param string $value Raw value, exactly as supplied.
	 * @param int    $max   Column width in bytes.
	 * @return bool
	 */
	private static function is_well_formed_key( string $value, int $max ): bool {
		if ( '' === $value || strlen( $value ) > $max ) {
			return false;
		}

		return 1 === preg_match( '/^[a-z0-9_\-]+$/', $value );
	}

	/**
	 * One raw field, as a string, without ever throwing.
	 *
	 * NON-SCALAR INPUT BECOMES THE EMPTY STRING, which every validated column then
	 * refuses. Casting an array to string would raise a notice and — with the test
	 * suite converting notices to exceptions — turn a rejected write into a fatal
	 * one; and `(string) $object` would call `__toString()` on caller-supplied code.
	 *
	 * @param array  $data Raw input.
	 * @param string $key  Column name.
	 * @return string
	 */
	private static function raw_value( array $data, string $key ): string {
		$value = $data[ $key ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Whether a native email id matches one WooCommerce actually has.
	 *
	 * CAPABILITY-DETECTED, AND ITS ABSENCE IS NOT A REJECTION. The mailer is
	 * frequently unavailable when rules are written — a CLI importer, a
	 * migration, an activation hook all run long before `WC()->mailer()` — and
	 * refusing a valid rule because WooCommerce had not booted yet would be a
	 * worse failure than accepting an id that is checked again at render time,
	 * where it is compared against the email actually rendering (ADR-0013 §4).
	 *
	 * @param string $native_email_id Well-formed email id.
	 * @return bool
	 */
	private static function native_email_is_registered( string $native_email_id ): bool {
		if ( ! function_exists( 'WC' ) || ! is_object( WC()->mailer() ) ) {
			return true;
		}

		$emails = WC()->mailer()->get_emails();

		if ( ! is_array( $emails ) || array() === $emails ) {
			return true;
		}

		foreach ( $emails as $email ) {
			if ( is_object( $email ) && isset( $email->id ) && (string) $email->id === $native_email_id ) {
				return true;
			}
		}

		return false;
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
	 * A filtered, ordered, paged page of rules — THE LIST SCREEN'S ONLY READ
	 * (ADR-0017 §8).
	 *
	 * ⚠ FILTERING AND PAGING HAPPEN IN SQL, NEVER IN PHP. Loading every rule to slice
	 * it afterwards is unbounded in the one dimension a store grows over time, and it
	 * is the shape that works perfectly on the developer's twelve rules.
	 *
	 * ⚠ THE ORDER-BY IDENTIFIER IS ALLOWLISTED AND THE DIRECTION IS ONE OF TWO
	 * LITERALS. `prepare()` binds values, not identifiers, so this is the one clause
	 * where a request-supplied string could reach SQL uninterpolated. Every filter
	 * VALUE is still bound, exactly as everywhere else in this class.
	 *
	 * `id` is appended as the final sort key so paging is STABLE: without it, two rules
	 * sharing a priority may swap places between page 1 and page 2 and one of them is
	 * never shown.
	 *
	 * @param array $args {
	 *     Filter, ordering and paging arguments.
	 *
	 *     @type string $status        Exact-match filter, or '' for all.
	 *     @type string $trigger_type  Exact-match filter, or '' for all.
	 *     @type string $delivery_mode Exact-match filter, or '' for all.
	 *     @type string $consolidation Exact-match filter, or '' for all.
	 *     @type string $search        Substring of the rule name, or ''.
	 *     @type string $orderby       A key of self::ORDERABLE_COLUMNS.
	 *     @type string $order         `ASC` or `DESC`.
	 *     @type int    $limit         Page size.
	 *     @type int    $offset        Page offset.
	 * }
	 * @return array[] Rules with JSON columns decoded.
	 */
	public function query( array $args = array() ): array {
		global $wpdb;
		$table = $this->table();

		$where = $this->where_clause( $args );

		$orderby = isset( self::ORDERABLE_COLUMNS[ (string) ( $args['orderby'] ?? '' ) ] )
			? self::ORDERABLE_COLUMNS[ (string) $args['orderby'] ]
			: 'priority';

		$order = 'DESC' === strtoupper( (string) ( $args['order'] ?? '' ) ) ? 'DESC' : 'ASC';

		$limit  = max( 1, (int) ( $args['limit'] ?? 20 ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$sql = "SELECT * FROM {$table} {$where['sql']} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";

		$params = array_merge( $where['params'], array( $limit, $offset ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- paged listing read of the plugin-owned rules table.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table}, {$orderby} and {$order} are plugin-derived identifiers from self::ORDERABLE_COLUMNS and a two-literal direction; every VALUE is bound through $params.
			$wpdb->prepare( $sql, $params ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * How many rules match the same filters self::query() would apply.
	 *
	 * ⚠ SHARES ONE WHERE-CLAUSE BUILDER WITH self::query(), so the count and the page
	 * can never disagree about what they are counting — a pager that says 40 while the
	 * pages hold 37 is a bug nobody sees until the last page renders empty.
	 *
	 * @param array $args Same filter keys as self::query(); paging and ordering ignored.
	 * @return int
	 */
	public function count( array $args = array() ): int {
		global $wpdb;
		$table = $this->table();

		$where = $this->where_clause( $args );

		// A no-filter count carries no values at all, and prepare() with an empty
		// argument list is a deprecation rather than a no-op — so the unfiltered
		// clause, which is a constant plus a plugin-derived identifier, is issued
		// directly and the filtered one is always bound.
		$sql = "SELECT COUNT(*) FROM {$table} {$where['sql']}";

		if ( array() !== $where['params'] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- {$table} is a plugin-derived identifier; every value is bound through $params.
			$sql = $wpdb->prepare( $sql, $where['params'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- count for the pager over the plugin-owned rules table; see above.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * The shared WHERE clause for self::query() and self::count().
	 *
	 * Every filter is an EXACT match on a column the storage layer already
	 * validates, so an unrecognised filter value simply matches nothing — there is
	 * no repair, and no filter value ever reaches SQL uninterpolated.
	 *
	 * @param array $args Filter arguments.
	 * @return array{sql:string, params:array}
	 */
	private function where_clause( array $args ): array {
		global $wpdb;

		$clauses = array();
		$params  = array();

		foreach ( self::FILTERABLE_COLUMNS as $column ) {
			$value = (string) ( $args[ $column ] ?? '' );

			if ( '' === $value ) {
				continue;
			}

			$clauses[] = $column . ' = %s';
			$params[]  = $value;
		}

		$search = trim( (string) ( $args['search'] ?? '' ) );

		if ( '' !== $search ) {
			// esc_like() first, then bind: without it a merchant searching for `100%`
			// matches every rule, which reads as the filter being broken.
			$clauses[] = 'name LIKE %s';
			$params[]  = '%' . $wpdb->esc_like( $search ) . '%';
		}

		return array(
			'sql'    => array() === $clauses ? '' : 'WHERE ' . implode( ' AND ', $clauses ),
			'params' => $params,
		);
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

		if ( ! is_int( $deleted ) || $deleted <= 0 ) {
			return false;
		}

		/**
		 * Fires after a rule row is deleted (ADR-0015 §5).
		 *
		 * ⚠ AFTER the delete, deliberately: a subscriber that re-reads the rule
		 * must find it GONE, or it would decide the rule is still schedulable and
		 * leave the queued mail in place.
		 *
		 * @since 1.0.0
		 *
		 * @param int $rule_id The rule that was deleted.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant IS the prefixed name (`extonify_wcep_rule_deleted`); it is referenced rather than repeated so the hook this repository fires and the hook the subscriber listens on cannot drift apart.
		do_action( ScheduledCancellation::ACTION_DELETED, $rule_id );

		return true;
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
	 * @param string        $mode    The delivery mode the write RESULTS IN, from
	 *                               self::effective_mode().
	 * @return array Column => value, ready for $wpdb.
	 */
	private function sanitize( array $data, ?array $only, array $trigger, string $mode ): array {
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

		/*
		 * THE VALIDATED COLUMNS ARE WRITTEN VERBATIM, AND THAT IS THE POINT (ADR-0009
		 * write boundary). `status`, `delivery_mode`, `native_email_id`,
		 * `consolidation` and both trigger halves reached here only by passing
		 * self::write_is_valid() / self::normalize_trigger() on their RAW values, so
		 * there is nothing left to coerce. A second, weaker coercion here is exactly
		 * how the boundary drifted before: this line used to read
		 * `in_array( $status, STATUSES ) ? $status : 'inactive'`, which turned every
		 * unrecognised status into a silently deactivated rule.
		 *
		 * An ABSENT column still gets its documented default — that is a default, not
		 * a repair.
		 */
		$put( 'name', sanitize_text_field( (string) ( $data['name'] ?? '' ) ) );
		$put( 'status', array_key_exists( 'status', $data ) ? self::raw_value( $data, 'status' ) : 'inactive' );
		$put( 'priority', (int) ( $data['priority'] ?? 10 ) );
		$put( 'trigger_type', $trigger['type'] );
		$put( 'trigger_value', $trigger['value'] );
		$put( 'delivery_mode', $mode );
		$put( 'native_email_id', self::raw_value( $data, 'native_email_id' ) );
		// ⚠ THE ONE DOCUMENTED EXCEPTION: `insert_position` REPAIRS (ADR-0009). A
		// position is presentation with a sane default — an unrecognised one falls
		// back to `after_order_table` in `Injector::normalize_position()` rather than
		// refusing the rule or emitting nothing. Recorded as a decision, not left as
		// the sweep's unexamined survivor.
		$put( 'insert_position', sanitize_key( (string) ( $data['insert_position'] ?? '' ) ) );
		$put( 'targeting', self::encode_json_column( 'targeting', (array) ( $data['targeting'] ?? array() ) ) );
		$put( 'recipients', self::encode_json_column( 'recipients', (array) ( $data['recipients'] ?? array() ) ) );
		$put( 'subject', sanitize_text_field( (string) ( $data['subject'] ?? '' ) ) );
		$put( 'heading', sanitize_text_field( (string) ( $data['heading'] ?? '' ) ) );
		// Rule content is merchant-authored HTML email body: wp_kses_post keeps
		// safe markup and strips scripts. Escaped again on output by the caller.
		$put( 'content', wp_kses_post( (string) ( $data['content'] ?? '' ) ) );
		$put( 'delay_seconds', max( 0, (int) ( $data['delay_seconds'] ?? 0 ) ) );
		$put( 'consolidation', array_key_exists( 'consolidation', $data ) ? self::raw_value( $data, 'consolidation' ) : Consolidation::NONE );
		$put( 'stop_processing', ! empty( $data['stop_processing'] ) ? 1 : 0 );

		/*
		 * INSERT MODE FORCES ITS OWN COLUMNS, WHATEVER THE CALLER SUPPLIED, AND
		 * THIS BYPASSES `$only` DELIBERATELY (ADR-0013 §2).
		 *
		 * A partial update writes only the keys the caller named, so converting a
		 * stored `status`/`completed` rule with
		 * `update( $id, [ 'delivery_mode' => 'insert', 'native_email_id' => ... ] )`
		 * left BOTH trigger columns behind. The row then claimed a trigger the
		 * engine does not consult and the rule read as a promise nothing keeps —
		 * the exact single-source violation ADR-0013 §2 exists to rule out. The
		 * mode being written is the RESULTING mode, so a partial update that only
		 * changes `delay_seconds` is still judged against the rule's real mode.
		 *
		 * `delay_seconds` and `consolidation` are here for completeness rather than
		 * repair: a non-zero delay and a non-`none` consolidation on an insert rule are
		 * both REFUSED upstream by self::write_is_valid() (ADR-0013 §2, ADR-0016 §2),
		 * so these only ever write the values that were already true.
		 */
		if ( 'insert' === $mode ) {
			$out['trigger_type']  = $trigger['type'];
			$out['trigger_value'] = $trigger['value'];
			$out['delay_seconds'] = 0;
			$out['consolidation'] = Consolidation::NONE;
		}

		return $out;
	}

	/**
	 * The `consolidation` a write RESULTS IN — from the input when it says so, from
	 * the stored row otherwise (ADR-0016 §1a).
	 *
	 * ⚠ THE COMPANION TO self::effective_mode(), AND IT EXISTS FOR THE SAME REASON A
	 * PARTIAL UPDATE MUST BE JUDGED BY ITS RESULT. Without it, an unrelated edit to a
	 * legacy row holding `daily` was accepted — the row stayed undeliverable while the
	 * merchant's save appeared to succeed.
	 *
	 * THE RAW VALUE, NEVER A SANITISED ONE: an enumeration has no correct repair, and
	 * self::write_is_valid() refuses anything outside `Consolidation::MODES` before
	 * anything reads this, so by the time a value is stored it is already one of two
	 * literals.
	 *
	 * The default is `none` on both sides — the column's own documented default, so an
	 * insert that never mentions consolidation is valid rather than refused.
	 *
	 * @param array $data     Raw input.
	 * @param array $existing Stored row, or empty for an insert.
	 * @return string
	 */
	private static function effective_consolidation( array $data, array $existing ): string {
		if ( array_key_exists( 'consolidation', $data ) ) {
			return self::raw_value( $data, 'consolidation' );
		}

		return (string) ( $existing['consolidation'] ?? Consolidation::NONE );
	}

	/**
	 * The delivery mode a write RESULTS IN, from the input when it says and from
	 * the stored row otherwise.
	 *
	 * ONE ANSWER, USED EVERYWHERE. Validation, trigger resolution and
	 * sanitisation each used to work this out for themselves, and `sanitize()`
	 * got it wrong for partial updates — it defaulted to `separate` whenever the
	 * caller had not mentioned the mode, so a stored insert rule being edited was
	 * sanitised as a separate one.
	 *
	 * @param array $data     Raw input.
	 * @param array $existing Stored row, or empty for a fresh insert.
	 * @return string
	 */
	private static function effective_mode( array $data, array $existing ): string {
		if ( array_key_exists( 'delivery_mode', $data ) ) {
			/*
			 * THE RAW VALUE, NOT A SANITISED ONE. `sanitize_key()` used to run here,
			 * which meant `INSERT` and `" insert "` both became `insert` — the caller's
			 * intent guessed rather than honoured. self::write_is_valid() checks this
			 * column against the allowlist EXACTLY and refuses anything else before
			 * this is ever reached, so by here the value is already one of two
			 * literals. Sanitising it again could only re-open the coercion.
			 */
			return self::raw_value( $data, 'delivery_mode' );
		}

		return (string) ( $existing['delivery_mode'] ?? 'separate' );
	}

	/**
	 * Work out the trigger type and the NORMALISED trigger value a write should
	 * store, filling either half from the existing row when the caller omitted
	 * it (ADR-0011 §2).
	 *
	 * @param array  $data           Raw input.
	 * @param array  $existing       Stored row, or empty for a fresh insert.
	 * @param string $existing_type  Trigger type already stored ('status' for a
	 *                               fresh insert).
	 * @param string $existing_value Trigger value already stored.
	 * @return array|null `{type, value}`, or null when the trigger is malformed
	 *                    and must not be stored.
	 */
	private function resolve_trigger_for_mode( array $data, array $existing, string $existing_type, string $existing_value ): ?array {
		if ( 'insert' === self::effective_mode( $data, $existing ) ) {
			/*
			 * AN INSERT RULE STORES EMPTY TRIGGER FIELDS AND SKIPS TRIGGER
			 * VALIDATION ENTIRELY (ADR-0013 §2).
			 *
			 * This follows the refund precedent one step further: ADR-0011 §2
			 * already forces `trigger_value` empty for refund rules because the
			 * value is meaningless there. For an insert rule BOTH halves are
			 * meaningless — `native_email_id` decides which email it belongs to —
			 * so storing a plausible-looking `status:completed` would read as a
			 * promise the engine does not keep.
			 *
			 * Emptiness is also what makes phase isolation structural rather
			 * than conventional: `find_active_for_trigger()` refuses an empty
			 * type before it queries, so no insert rule can ever be returned to
			 * the separate path, whatever that path later does with its results.
			 */
			return array(
				'type'  => '',
				'value' => '',
			);
		}

		return $this->resolve_trigger( $data, $existing_type, $existing_value );
	}

	/**
	 * Work out the trigger a NON-insert write should store.
	 *
	 * @param array  $data           Raw input.
	 * @param string $existing_type  Trigger type already stored.
	 * @param string $existing_value Trigger value already stored.
	 * @return array|null `{type, value}`, or null when malformed.
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
