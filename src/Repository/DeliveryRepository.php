<?php
/**
 * Durable delivery tombstone repository (ADR-0004, ADR-0009).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Repository;

use Extonify\WCEP\Domain\DeliveryIdentity;
use Extonify\WCEP\Domain\DeliverySnapshot;
use Extonify\WCEP\Domain\WriteResult;
use Extonify\WCEP\Install\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * The durable delivery identity store.
 *
 * The tombstone contains no direct contact or message-content fields, but
 * remains linked to an order and is retained to prevent unintended duplicate
 * automatic deliveries.
 *
 * ⚠ ONE QUALIFICATION, AND IT IS THE REASON THE PRIVACY WORDING CHANGED IN
 * PROMPT 7A (ADR-0015 §2a). A delivery that is still PENDING carries a
 * `snapshot`: the store's own unrendered templates and recipient DEFINITIONS,
 * which may include a literal address a merchant typed into the rule. That is
 * merchant configuration rather than the data subject's data — it is already in
 * the rules table in the clear — and it is RELEASED the moment the delivery
 * reaches a terminal state, so the sentence above holds for every row that is not
 * currently owed. It does not hold for one that is, and the exporter, the eraser
 * and the public privacy notice all say so.
 *
 * Rows here survive retention purges and privacy erasure: purging an identity
 * would silently re-arm an already-delivered order, which is the exact failure
 * ADR-0004 exists to prevent. The only thing that removes a tombstone is
 * permanent deletion of the order it belongs to.
 */
class DeliveryRepository {

	/**
	 * Claim outcomes.
	 */
	const CLAIMED    = 'claimed';
	const SUPPRESSED = 'suppressed';
	const FAILED     = 'failed';

	/**
	 * The armed state of a delayed delivery (ADR-0015 §1).
	 *
	 * Between `claimed` and its terminal state: the identity is consumed, the
	 * snapshot is stored, and a job is queued to execute it.
	 */
	const SCHEDULED = 'scheduled';

	/**
	 * THE LEASE (ADR-0015 §8.2).
	 *
	 * Between `scheduled` and its terminal state: one worker has taken exclusive
	 * ownership of this delivery and is running it right now.
	 *
	 * ⚠ IT EXISTS TO STOP A SECOND EMAIL, AND THE CASE NEEDS ONLY A PHP TIMEOUT.
	 * A worker that dies AFTER `wp_mail()` returned but BEFORE the status write
	 * leaves the action re-claimable. Without this state the next worker reads
	 * `scheduled`, re-validates successfully, and sends the customer a duplicate.
	 * `run()` begins by taking this lease and returns without sending when it
	 * cannot — that is the whole mechanism.
	 *
	 * NOT TERMINAL, and therefore NOT in self::SNAPSHOT_RELEASING_STATUSES: a
	 * delivery under lease is still pending work, and one whose lease is later
	 * swept still has to know what it was going to send.
	 */
	const EXECUTING = 'executing';

	/**
	 * Terminal statuses a tombstone may record.
	 *
	 * An allowlist, not a sanitised free string: `final_status` drives support
	 * tooling and future reporting, and an arbitrary value would sit in the
	 * column forever with nothing able to interpret it.
	 *
	 * `skipped` was added in Prompt 4 (ADR-0012 §2): a rule that matched the
	 * include set and was then excluded, whose targeting is invalid, or that was
	 * disabled, DOES consume its identity and needs a terminal status saying so.
	 * The column is a `varchar` policed by this list in PHP, and schema v1 is
	 * unreleased, so this is a constant change and NOT a migration.
	 *
	 * `abandoned` was added in Prompt 5B (ADR-0013 §6a): content was rendered into a
	 * native email that was never sent. It is written ONLY when the tombstone holds
	 * no real send attempt — a tombstone whose latest genuine attempt succeeded keeps
	 * `sent`, because a third party throwing a later render away does not undo a
	 * delivery that happened.
	 */
	const FINAL_STATUSES = array( 'claimed', 'scheduled', self::EXECUTING, 'sent', 'failed', 'cancelled', 'skipped', 'unresolved', DeliveryDetailRepository::ABANDONED );

	/**
	 * THE ONLY MOVES A DELAYED DELIVERY MAY MAKE (ADR-0015 §8.1).
	 *
	 * Keyed by source state; the value is every state that source may reach. A
	 * pair absent from here is refused by self::transition() and logged, so an
	 * impermissible move is a recorded error rather than a silent write.
	 *
	 * ⚠ `executing => cancelled` AND `executing => skipped` ARE LOAD-BEARING, AND
	 * THE SHORTER FOUR-EDGE VERSION OF THIS TABLE LOOKS COMPLETE WITHOUT THEM.
	 * ADR-0015 §4 requires `cancelled` with a distinct reason for all six
	 * re-validation outcomes, and those checks run AFTER the lease is taken —
	 * without this edge they would have to record `failed`, which would be untrue
	 * (nothing failed; the merchant disabled the rule). ADR-0012 §2's claiming
	 * skips — no deliverable recipient, the per-delivery
	 * `woocommerce_email_enabled_{id}` filter refusing — are likewise discovered
	 * inside the send, under the lease.
	 *
	 * There is no `executing => scheduled` edge and there must never be one: a
	 * delivery that has been walked backwards out of its lease is a delivery two
	 * workers can pick up.
	 */
	const TRANSITIONS = array(
		self::SCHEDULED => array( self::EXECUTING, 'cancelled' ),
		self::EXECUTING => array( 'sent', 'failed', 'unresolved', 'cancelled', 'skipped' ),
	);

	/**
	 * How many ids to bind per statement when deleting in bulk.
	 */
	const DELETE_CHUNK_SIZE = 200;

	/**
	 * How many rows one LIFECYCLE pass reads at a time.
	 *
	 * Deactivation and uninstall walk a table whose whole design is to grow for the
	 * lifetime of the store, so they page rather than selecting everything into
	 * memory at once. They page to EXHAUSTION — a shutdown that finalised only the
	 * first N deliveries would leave the rest stranded — which is what separates
	 * them from the daily sweep, whose per-run budget is capped by
	 * `ScheduledDelivery::SWEEP_MAX_PAGES` (ADR-0015 §8.3a) because it is entitled
	 * to finish tomorrow.
	 */
	const SWEEP_CHUNK_SIZE = 200;

	/**
	 * Fully-prefixed table name.
	 *
	 * @return string
	 */
	public function table(): string {
		return Migrator::table( 'deliveries' );
	}

	/**
	 * Atomically claim a delivery identity (ADR-0004(b)).
	 *
	 * ONE statement decides the outcome, and the outcome is read purely from
	 * the affected-row count — never from error text:
	 *
	 *   - 1 row affected  => CLAIMED     (a fresh INSERT; proceed to send)
	 *   - 2 rows affected => SUPPRESSED  (ON DUPLICATE KEY UPDATE fired)
	 *   - anything else   => FAILED      (fail closed: record failed, DO NOT SEND)
	 *
	 * `LAST_INSERT_ID(id)` makes the existing row's id available on the
	 * duplicate branch without a second query. A `false` return from $wpdb
	 * (query error) and any unrecognised affected-row count both land in the
	 * FAILED branch: the caller must not send.
	 *
	 * @param int    $order_id           WooCommerce order id.
	 * @param int    $rule_id            Rule id.
	 * @param string $mode               Delivery mode: 'insert' or 'separate'.
	 * @param string $trigger_identity   Trigger identity (ADR-0004).
	 * @param int    $rule_revision_sent Rule revision, audit only — never keyed.
	 * @return array{result:string,delivery_id:int,identity_hash:string}
	 */
	public function claim( int $order_id, int $rule_id, string $mode, string $trigger_identity, int $rule_revision_sent = 0 ): array {
		global $wpdb;

		// VALIDATE BEFORE HASHING OR WRITING (Prompt 2a Item 6). A tombstone
		// written under a meaningless identity can never be matched again, so
		// duplicate prevention silently stops working for that delivery — the
		// worst possible failure mode for this table. Reject instead.
		$invalid = $this->validate_identity_input( $order_id, $rule_id, $mode, $trigger_identity, $rule_revision_sent );
		if ( null !== $invalid ) {
			$this->log_error( 'refused to claim an invalid delivery identity: ' . $invalid );
			return array(
				'result'        => self::FAILED,
				'delivery_id'   => 0,
				'identity_hash' => '',
			);
		}

		$hash  = DeliveryIdentity::hash( $order_id, $rule_id, $mode, $trigger_identity );
		$now   = current_time( 'mysql', true );
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic claim against the plugin-owned tombstone table; the UNIQUE constraint IS the race protection and a cached read would defeat it.
		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is built from $wpdb->prefix plus a hardcoded literal in Migrator::table(); it is never user input and an identifier cannot be bound by prepare().
				"INSERT INTO {$table}
					(identity_hash, order_id, rule_id, mode, trigger_identity, first_claimed_at, last_seen_at, final_status, suppressed_count, rule_revision_sent)
				VALUES (%s, %d, %d, %s, %s, %s, %s, %s, 0, %d)
				ON DUPLICATE KEY UPDATE
					suppressed_count = suppressed_count + 1,
					last_seen_at     = VALUES(last_seen_at),
					id               = LAST_INSERT_ID(id)",
				$hash,
				$order_id,
				$rule_id,
				DeliveryIdentity::normalize( $mode ),
				DeliveryIdentity::normalize( $trigger_identity ),
				$now,
				$now,
				self::CLAIMED,
				$rule_revision_sent
			)
		);

		if ( 1 === $affected ) {
			return array(
				'result'        => self::CLAIMED,
				'delivery_id'   => (int) $wpdb->insert_id,
				'identity_hash' => $hash,
			);
		}

		if ( 2 === $affected ) {
			return array(
				'result'        => self::SUPPRESSED,
				'delivery_id'   => (int) $wpdb->insert_id,
				'identity_hash' => $hash,
			);
		}

		// Fail closed. Includes false (query error) and every unrecognised
		// count. Never infer success, never inspect the error string.
		$this->log_error( 'claim failed for order #' . $order_id . ' rule #' . $rule_id . ' (unexpected affected-rows result)' );
		return array(
			'result'        => self::FAILED,
			'delivery_id'   => 0,
			'identity_hash' => $hash,
		);
	}

	/**
	 * Validate the identity inputs a claim would be keyed on.
	 *
	 * Every rule here exists because the value would otherwise produce a row
	 * that cannot be matched again: a zero or negative order/rule id, an
	 * unrecognised mode, an empty or over-long trigger identity (MySQL would
	 * truncate it), or a negative revision (which behaves differently by SQL
	 * mode on an unsigned column).
	 *
	 * @param int    $order_id           Order id.
	 * @param int    $rule_id            Rule id.
	 * @param string $mode               Delivery mode.
	 * @param string $trigger_identity   Trigger identity.
	 * @param int    $rule_revision_sent Rule revision.
	 * @return string|null Reason the input is invalid, or null when it is fine.
	 */
	protected function validate_identity_input( int $order_id, int $rule_id, string $mode, string $trigger_identity, int $rule_revision_sent ): ?string {
		if ( $order_id <= 0 ) {
			return 'order_id must be a positive integer';
		}
		if ( $rule_id <= 0 ) {
			return 'rule_id must be a positive integer';
		}
		if ( ! DeliveryIdentity::is_valid_mode( $mode ) ) {
			return 'unrecognised delivery mode';
		}
		if ( ! DeliveryIdentity::is_valid_trigger_identity( $trigger_identity ) ) {
			return 'trigger_identity is empty, over-long, or not an ADR-0004 form';
		}
		if ( $rule_revision_sent < 0 ) {
			return 'rule_revision_sent must not be negative';
		}
		return null;
	}

	/**
	 * Fetch one tombstone by its identity components.
	 *
	 * @param int    $order_id         Order id.
	 * @param int    $rule_id          Rule id.
	 * @param string $mode             Delivery mode.
	 * @param string $trigger_identity Trigger identity.
	 * @return array|null Row as an associative array, or null when absent.
	 */
	public function find( int $order_id, int $rule_id, string $mode, string $trigger_identity ): ?array {
		return $this->find_by_hash( DeliveryIdentity::hash( $order_id, $rule_id, $mode, $trigger_identity ) );
	}

	/**
	 * Fetch one tombstone by identity hash.
	 *
	 * @param string $hash 64-character sha256 hex digest.
	 * @return array|null
	 */
	public function find_by_hash( string $hash ): ?array {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single indexed read of the plugin-owned tombstone table; caching a claim state would risk a duplicate send.
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE identity_hash = %s", $hash ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * All tombstones for one order.
	 *
	 * @param int $order_id Order id.
	 * @return array[] Rows as associative arrays.
	 */
	public function find_for_order( int $order_id ): array {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- indexed read of the plugin-owned tombstone table.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC", $order_id ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * All tombstones for a set of orders.
	 *
	 * Used by the privacy exporter and eraser to reach a data subject's rows
	 * through the ORDER linkage rather than only through the stored recipient
	 * string — see Privacy\SubjectData for why that matters.
	 *
	 * @param int[] $order_ids Order ids.
	 * @return array[] Rows as associative arrays.
	 */
	public function find_for_orders( array $order_ids ): array {
		global $wpdb;

		$ids = array();
		foreach ( $order_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		if ( empty( $ids ) ) {
			return array();
		}

		$table = $this->table();
		$out   = array();

		foreach ( array_chunk( array_unique( $ids ), self::DELETE_CHUNK_SIZE ) as $chunk ) {
			// Placeholders are generated from the COUNT of ids, and every id is
			// an int cast above — no value reaches the SQL unprepared.
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy request over the plugin-owned tombstone table; results must be live.
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$table} is a plugin-derived identifier; {$placeholders} is a generated run of %d tokens counted from $chunk, whose members are all int-cast above.
				$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id IN ( {$placeholders} ) ORDER BY id ASC", $chunk ),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * Tombstones by primary key.
	 *
	 * @param int[] $ids Tombstone ids.
	 * @return array[] Rows as associative arrays, ordered by id.
	 */
	public function find_by_ids( array $ids ): array {
		global $wpdb;

		$clean = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$clean[] = $id;
			}
		}
		if ( empty( $clean ) ) {
			return array();
		}
		$clean = array_values( array_unique( $clean ) );

		$table = $this->table();
		$out   = array();

		foreach ( array_chunk( $clean, self::DELETE_CHUNK_SIZE ) as $chunk ) {
			// Placeholders are generated from the COUNT of ids, and every id is
			// an int cast above — no value reaches the SQL unprepared.
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy request over the plugin-owned tombstone table; results must be live.
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$table} is a plugin-derived identifier; {$placeholders} is a generated run of %d tokens counted from $chunk, whose members are all int-cast above.
				$wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ( {$placeholders} ) ORDER BY id ASC", $chunk ),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * Statuses at which a delayed delivery's snapshot is RELEASED (ADR-0015 §2).
	 *
	 * ⚠ THE SNAPSHOT IS PENDING WORK, NOT HISTORY. Tombstones are never purged, so
	 * a snapshot left on one accumulates a rule body per delivery for the lifetime
	 * of the store — a resource growing without bound in normal operation. It is
	 * needed only between scheduling and execution, so every terminal state clears
	 * it and durable storage tracks QUEUE DEPTH rather than delivery history.
	 *
	 * `scheduled` is pointedly absent: that is the state the snapshot exists for.
	 * `claimed` is absent too — an immediate delivery passes through it and never
	 * had a snapshot, and a scheduled one must not be walked backwards into it.
	 */
	const SNAPSHOT_RELEASING_STATUSES = array( 'sent', 'failed', 'cancelled', 'skipped', 'unresolved', DeliveryDetailRepository::ABANDONED );

	/**
	 * THE GUARDED TRANSITION: move a delivery from one state to another, but only
	 * from the state the caller believes it is in (ADR-0015 §8.1).
	 *
	 * ONE STATEMENT DECIDES, and the decision is read purely from the affected-row
	 * count — exactly as `claim()` does, and for exactly the same reason:
	 *
	 *   - 1 row changed  => CHANGED:      THIS caller won the transition and OWNS the outcome
	 *   - 0 rows changed => LOST_RACE:    somebody else got there first; the row is not `$from`
	 *   - false          => QUERY_FAILED: nothing happened at all; nobody owns it
	 *   - not attempted  => REFUSED:      an unusable id, or a pair outside the table
	 *
	 * **Whoever wins owns the outcome; the loser exits without acting.** That
	 * sentence is the whole concurrency design of the scheduled path, and it is why
	 * this return value may never be discarded.
	 *
	 * ⚠ AND IT IS WHY THE RETURN VALUE IS NOT A BOOLEAN (ADR-0015 §8.1a, Prompt 7B).
	 * "Somebody else won" and "the UPDATE failed" are DIFFERENT FACTS REQUIRING
	 * OPPOSITE ACTIONS, and `false` merged them: a lost race means the delivery has
	 * an owner and exiting is correct, while a failed query means it has none and
	 * exiting strands it in the state it was already in. Three call sites acted on
	 * the ambiguity — the lease read a failed UPDATE as a lost race and let the
	 * action be consumed with the row still `scheduled`; the eager cancellation
	 * unscheduled the job anyway; the arm left the tombstone `claimed` with no job.
	 * `Domain\WriteResult` carries the distinction so no caller can re-merge them.
	 *
	 * ⚠ THE ROW COUNT IS TRUSTWORTHY HERE, AND THAT IS NOT AN ACCIDENT. WordPress
	 * does not set `CLIENT_FOUND_ROWS`, so MySQL reports rows CHANGED rather than
	 * matched — and every permitted transition writes a DIFFERENT `final_status`,
	 * so a matching row always changes and a no-op update can never be mistaken for
	 * a win. `WHERE id = %d` is the primary key, so the count is never above 1.
	 * (`set_final_status()` needs its zero-rows-is-not-failure fallback precisely
	 * because it CAN be asked to write a status the row already holds. This cannot.)
	 *
	 * @param int    $delivery_id        Tombstone id.
	 * @param string $from               State the caller believes the row is in.
	 * @param string $to                 State to move it to.
	 * @param int    $rule_revision_sent Rule revision, audit only — never keyed.
	 * @return WriteResult CHANGED only when THIS call changed exactly one row.
	 */
	public function transition( int $delivery_id, string $from, string $to, int $rule_revision_sent = 0 ): WriteResult {
		global $wpdb;

		$from      = DeliveryIdentity::normalize( $from );
		$to        = DeliveryIdentity::normalize( $to );
		$attempted = $from . ' -> ' . $to;

		if ( $delivery_id <= 0 ) {
			$this->log_error( 'refused a state transition for an unusable delivery id' );
			return WriteResult::refused( $attempted );
		}

		if ( ! self::transition_is_permitted( $from, $to ) ) {
			// AN ALLOWLIST, NOT A SANITISED PAIR. A move nobody reviewed is a move
			// nothing can interpret afterwards, and the pair most worth refusing —
			// `executing` back to `scheduled` — is the one that would let two
			// workers pick up the same delivery.
			$this->log_error(
				'refused an impermissible delivery transition for #' . $delivery_id . ': ' . $attempted
			);
			return WriteResult::refused( $attempted );
		}

		$now         = current_time( 'mysql', true );
		$assignments = array( 'final_status = %s', 'last_seen_at = %s' );
		$values      = array( $to, $now );

		if ( self::EXECUTING === $to ) {
			// ADR-0015 §8.2: the lease clock, in a column nothing else writes.
			$assignments[] = 'lease_taken_at = %s';
			$values[]      = $now;
		} else {
			// Every exit from the lease clears it, so a terminal row can never be
			// mistaken for one whose worker is still running.
			$assignments[] = 'lease_taken_at = NULL';
		}

		if ( in_array( $to, self::SNAPSHOT_RELEASING_STATUSES, true ) ) {
			// ADR-0015 §2: released in the one statement that already knows the
			// delivery has finished.
			$assignments[] = 'snapshot = NULL';
		}

		if ( $rule_revision_sent > 0 ) {
			$assignments[] = 'rule_revision_sent = %d';
			$values[]      = $rule_revision_sent;
		}

		$values[] = $delivery_id;
		$values[] = $from;

		$table = $this->table();
		$set   = implode( ', ', $assignments );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- guarded primary-key update of the plugin-owned tombstone table; the final_status predicate IS the concurrency guard and a cached read would defeat it.
		$updated = $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the replacements arrive as one $values array, which the sniff cannot count through; it is built beside the assignments above so the two are always the same length.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier; {$set} is assembled from the hardcoded literal fragments directly above, every one of which binds its value through the $values array. No caller input reaches the SQL text.
				"UPDATE {$table} SET {$set} WHERE id = %d AND final_status = %s",
				$values
			)
		);

		if ( false === $updated ) {
			$this->log_error(
				'delivery #' . $delivery_id . ': the ' . $attempted . ' transition failed with a query error, so '
					. 'NOTHING was written and no other actor owns this delivery'
			);
			return WriteResult::failed( $attempted );
		}

		return 1 === (int) $updated ? WriteResult::changed( $attempted ) : WriteResult::lost( $attempted );
	}

	/**
	 * Whether one state may move to another (ADR-0015 §8.1).
	 *
	 * @param string $from Source state.
	 * @param string $to   Target state.
	 * @return bool
	 */
	public static function transition_is_permitted( string $from, string $to ): bool {
		$allowed = self::TRANSITIONS[ $from ] ?? array();

		return in_array( $to, $allowed, true );
	}

	/**
	 * The statuses from which a delivery can still move.
	 *
	 * The complement of "terminal", derived from self::TRANSITIONS rather than
	 * listed a second time — a state with somewhere to go is exactly a state with
	 * an entry there. `claimed` joins them because an immediate delivery passes
	 * through it on its way to an outcome.
	 */
	const IN_FLIGHT_STATUSES = array( 'claimed', self::SCHEDULED, self::EXECUTING );

	/**
	 * Whether a delivery has already reached a terminal state.
	 *
	 * ⚠ USED TO CONFIRM THAT A DELIVERY THIS CALLER DID NOT FINALISE NEVERTHELESS
	 * HAS AN OUTCOME. A lost race is somebody else's success and is not a shortfall
	 * worth alarming a merchant about — but only a READ can say whether the winner
	 * actually recorded something, and `WriteResult::LOST_RACE` alone cannot.
	 *
	 * ⚠ FALSE ALSO WHEN THE ROW IS MISSING OR THE READ FAILED, which is the safe
	 * direction: it means "do not treat this delivery as finished". A caller that
	 * needs to tell those two apart asks self::inspect() instead.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return bool False when the row is missing or still in flight.
	 */
	public function is_terminal( int $delivery_id ): bool {
		$row = $this->find_by_id( $delivery_id );

		if ( null === $row ) {
			return false;
		}

		return ! in_array( (string) $row['final_status'], self::IN_FLIGHT_STATUSES, true );
	}

	/**
	 * Record the terminal outcome of a delivery.
	 *
	 * ⚠ THE IMMEDIATE PATH ONLY (ADR-0015 §8.1). It is an UNCONDITIONAL
	 * primary-key update, which is correct for a tombstone that goes
	 * `claimed -> terminal` inside one request with no second actor — and wrong for
	 * a delayed one, where a cancellation could overwrite `sent` and a send could
	 * overwrite `cancelled`. **No scheduled path may call this**; they call
	 * self::transition() and declare the state they are leaving.
	 *
	 * @param int    $delivery_id        Tombstone id.
	 * @param string $final_status       One of claimed|scheduled|sent|failed|cancelled.
	 * @param int    $rule_revision_sent Rule revision, audit only.
	 * @return bool True when a row was updated.
	 */
	public function set_final_status( int $delivery_id, string $final_status, int $rule_revision_sent = 0 ): bool {
		global $wpdb;

		$status = DeliveryIdentity::normalize( $final_status );
		if ( $delivery_id <= 0 || ! in_array( $status, self::FINAL_STATUSES, true ) ) {
			// Allowlist, not a sanitised free string: an unrecognised status
			// would sit in the column forever with nothing able to read it.
			$this->log_error( 'refused an unrecognised final_status for delivery #' . $delivery_id );
			return false;
		}

		$data = array(
			'final_status' => $status,
			'last_seen_at' => current_time( 'mysql', true ),
		);
		$fmt  = array( '%s', '%s' );

		if ( in_array( $status, self::SNAPSHOT_RELEASING_STATUSES, true ) ) {
			// ADR-0015 §2: released here, in the one statement that already knows
			// the delivery has finished, so no separate sweep has to find them.
			$data['snapshot'] = null;
			$fmt[]            = '%s';
		}

		if ( $rule_revision_sent > 0 ) {
			$data['rule_revision_sent'] = $rule_revision_sent;
			$fmt[]                      = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- primary-key update of the plugin-owned tombstone table; $wpdb->update() prepares every value.
		$updated = $wpdb->update( $this->table(), $data, array( 'id' => $delivery_id ), $fmt, array( '%d' ) );

		if ( false === $updated ) {
			return false; // A real query error.
		}
		if ( $updated > 0 ) {
			return true;
		}

		// ZERO AFFECTED ROWS IS NOT A FAILURE. MySQL reports 0 when the row is
		// byte-identical to what was written, and that happens routinely here:
		// last_seen_at has one-second resolution, so re-recording the SAME
		// status within the same second changes nothing. Treating that as
		// failure would make a caller think a status write was lost. Confirm
		// the row exists and already holds the value instead.
		$current = $this->find_by_id( $delivery_id );
		return null !== $current && $status === (string) $current['final_status'];
	}

	/**
	 * Arm a claimed delivery as SCHEDULED, storing its snapshot (ADR-0015 §1, §2).
	 *
	 * ⚠ A SEPARATE STATEMENT FROM `claim()`, DELIBERATELY. The claim is ONE atomic
	 * statement whose affected-row count decides the outcome, and it is the single
	 * mechanism preventing a duplicate send. Widening it to carry a longtext
	 * payload would put the plugin's most load-bearing query at risk for a column
	 * that is not part of the decision — so the claim decides, and this arms.
	 *
	 * WRITTEN ONLY OVER A `claimed` ROW. A row already `scheduled`, `sent` or
	 * `cancelled` is not re-armed: the caller reached here after a CLAIMED result,
	 * so anything else means another request got there first, and overwriting its
	 * snapshot would hand one delivery another's content. The predicate does that
	 * check inside the UPDATE rather than around it, so there is no read-then-write
	 * window.
	 *
	 * ⚠ ITS RESULT IS STRUCTURED FOR THE SAME REASON self::transition()'s IS
	 * (ADR-0015 §8.1a). A `false` return meant either "another request armed this
	 * first" or "the UPDATE failed", and the only call site treated both as an inert
	 * skip — so an ordinary database failure, with nothing thrown, left the identity
	 * claimed, no job queued, no detail row, and no sweep able to find the row,
	 * silently suppressing every later trigger for that delivery.
	 *
	 * @param int   $delivery_id Tombstone id, from a CLAIMED claim.
	 * @param array $snapshot    Snapshot from `Domain\DeliverySnapshot::create()`.
	 * @return WriteResult CHANGED when this call armed the row.
	 */
	public function arm_scheduled( int $delivery_id, array $snapshot ): WriteResult {
		global $wpdb;

		if ( $delivery_id <= 0 || array() === $snapshot ) {
			$this->log_error( 'refused to arm delivery #' . $delivery_id . ': unusable id or empty snapshot' );
			return WriteResult::refused( 'arm ' . self::CLAIMED . ' -> ' . self::SCHEDULED );
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- guarded primary-key update of the plugin-owned tombstone table; the final_status predicate IS the concurrency guard and a cached read would defeat it.
		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input.
				"UPDATE {$table}
					SET snapshot = %s, final_status = %s, last_seen_at = %s
				WHERE id = %d AND final_status = %s",
				DeliverySnapshot::encode( $snapshot ),
				self::SCHEDULED,
				current_time( 'mysql', true ),
				$delivery_id,
				self::CLAIMED
			)
		);

		$attempted = 'arm ' . self::CLAIMED . ' -> ' . self::SCHEDULED;

		if ( false === $updated ) {
			$this->log_error(
				'arming delivery #' . $delivery_id . ' as scheduled failed with a query error, so the identity is '
					. 'consumed with nothing behind it'
			);
			return WriteResult::failed( $attempted );
		}

		return $updated > 0 ? WriteResult::changed( $attempted ) : WriteResult::lost( $attempted );
	}

	/**
	 * READ A TOMBSTONE'S STATE, AND SAY WHETHER THE ANSWER IS KNOWN
	 * (ADR-0015 §8.1a).
	 *
	 * ⚠ `find_by_id()` RETURNS NULL FOR TWO DIFFERENT FACTS — the row is gone, or
	 * the READ failed — and the lease branch acted on the first while the second was
	 * live. "The order was permanently deleted, so there is nothing to record onto"
	 * and "this plugin cannot currently reach its own table" call for opposite
	 * handling, and a caller recovering from a failed write is exactly the caller
	 * most likely to meet a failing read.
	 *
	 * `$wpdb->last_error` is the only thing that separates them: `wpdb::query()`
	 * clears it through `flush()` before every statement, so a non-empty value after
	 * this read belongs to this read.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return array{known:bool,exists:bool,status:string,order_id:int} `known` false
	 *         means the READ failed and nothing here may be relied on.
	 */
	public function inspect( int $delivery_id ): array {
		global $wpdb;

		if ( $delivery_id <= 0 ) {
			// No row can exist for an unusable id, and no query is needed to know it.
			return array(
				'known'    => true,
				'exists'   => false,
				'status'   => '',
				'order_id' => 0,
			);
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- primary-key read of the plugin-owned tombstone table; a cached read would report a state another actor has already moved on from, which is the one thing this method exists to answer accurately.
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input.
			$wpdb->prepare( "SELECT id, order_id, final_status FROM {$table} WHERE id = %d", $delivery_id ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			$failed = '' !== (string) $wpdb->last_error;

			if ( $failed ) {
				$this->log_error( 'could not read the state of delivery #' . $delivery_id . ': ' . (string) $wpdb->last_error );
			}

			return array(
				'known'    => ! $failed,
				'exists'   => false,
				'status'   => '',
				'order_id' => 0,
			);
		}

		return array(
			'known'    => true,
			'exists'   => true,
			'status'   => (string) $row['final_status'],
			'order_id' => (int) $row['order_id'],
		);
	}

	/**
	 * The stored snapshot of one delivery, or null when it has none.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return array|null
	 */
	public function snapshot_of( int $delivery_id ): ?array {
		$row = $this->find_by_id( $delivery_id );

		if ( null === $row ) {
			return null;
		}

		return DeliverySnapshot::read( (string) ( $row['snapshot'] ?? '' ) );
	}

	/**
	 * Every PENDING scheduled delivery for one rule (ADR-0015 §5).
	 *
	 * Served by the `scheduled_lookup (rule_id, final_status)` index. Used by
	 * eager cancellation when a merchant disables or deletes a rule — an admin
	 * action, so the cost is paid once and never in a delivery path.
	 *
	 * @param int $rule_id Rule id.
	 * @return array[] Tombstone rows still `scheduled`.
	 */
	public function find_scheduled_for_rule( int $rule_id ): array {
		global $wpdb;

		if ( $rule_id <= 0 ) {
			return array();
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- indexed read of the plugin-owned tombstone table; a cached read would let a just-scheduled delivery escape eager cancellation.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE rule_id = %d AND final_status = %s ORDER BY id ASC", $rule_id, self::SCHEDULED ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * `executing` rows whose lease was taken before a cutoff (ADR-0015 §8.3).
	 *
	 * These are deliveries whose worker never came back — a PHP timeout, a fatal,
	 * a host restart. The sweep records them `unresolved`, never `failed`: nobody
	 * knows whether the mail went out, and asserting that it did not is the input a
	 * resend feature would read as "retry this".
	 *
	 * ⚠ `COALESCE` RATHER THAN A BARE COLUMN TEST. Only self::transition() ever
	 * writes `executing`, and it always stamps the lease — but a row that somehow
	 * reached the state without one would otherwise be INVISIBLE to the only
	 * mechanism that can free it, which is the exact stranding this sweep exists to
	 * end. `last_seen_at` is the honest fallback and is never newer.
	 *
	 * Cost: the `lease_sweep` index narrows to `final_status = 'executing'`, whose
	 * cardinality is the number of deliveries in flight — not the table.
	 *
	 * ⚠ CURSOR-PAGED, LIKE THE ORPHAN HALF, THOUGH FOR A NARROWER REASON. Every row
	 * this returns is transitioned OUT of `executing` by the sweep, so the candidate
	 * set drains itself and this half cannot starve the way the orphan half could.
	 * The cursor is what keeps a row the sweep FAILED to recover — a poisoned row, a
	 * failing write — from being re-read at the head of every page for the rest of
	 * the run and hiding every row behind it.
	 *
	 * @param string $cutoff_utc `Y-m-d H:i:s` UTC; leases at or before this are stale.
	 * @param int    $after_id   Return rows with an id strictly greater than this.
	 * @param int    $limit      Maximum rows to return.
	 * @return array[] Tombstone rows, ascending by id.
	 */
	public function find_stale_leases( string $cutoff_utc, int $after_id, int $limit ): array {
		global $wpdb;

		$limit = max( 1, $limit );
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- indexed maintenance read of the plugin-owned tombstone table; a cached read would re-sweep rows a previous pass already recovered.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input.
				"SELECT * FROM {$table}
					WHERE final_status = %s AND COALESCE(lease_taken_at, last_seen_at) <= %s AND id > %d
					ORDER BY id ASC LIMIT %d",
				self::EXECUTING,
				$cutoff_utc,
				max( 0, $after_id ),
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * `scheduled` rows untouched since a cutoff (ADR-0015 §8.3).
	 *
	 * The candidates for the orphan half of the sweep. ⚠ THE AGE FILTER IS THE
	 * SAFETY, NOT AN OPTIMISATION: a delivery armed microseconds ago has not queued
	 * its job yet, and a sweep that read it would find no job and "recover" a
	 * delivery that was never lost. `last_seen_at` is written by `arm_scheduled()`,
	 * so it is the moment the row entered this state.
	 *
	 * Being `scheduled` says nothing about whether the job is DUE — a delivery
	 * queued for next week is pending, and `as_has_scheduled_action()` reports it
	 * as such. Only "no job at all" means orphaned, and that question is asked per
	 * row by the caller, against the queue.
	 *
	 * ⚠ CURSOR-PAGED, AND THE MISSING CURSOR WAS A STARVATION DEFECT THAT NEEDED NO
	 * FAILURE AT ALL (Prompt 7B, Group B). A HEALTHY row — one whose job exists —
	 * stays a candidate: it is still `scheduled` and still older than the cutoff.
	 * `ORDER BY id ASC LIMIT 100` with no cursor therefore returned the SAME first
	 * hundred rows every day, so a store with more than a hundred pending
	 * long-delayed deliveries never examined row 101 and an orphan behind them was
	 * never reached. Only moderate volume and a long delay were required — which is
	 * the feature this ADR exists to ship.
	 *
	 * ⚠ AND BOUNDED ABOVE BY A CYCLE HIGH-WATER MARK, WHICH IS WHAT MAKES THE CURSOR
	 * FAIR (ADR-0015 §8.3b, Prompt 7C). A cursor alone only guarantees progress
	 * THROUGH the candidate set; it guarantees nothing about REACHING the back of it,
	 * because under sustained inflow the set grows in front of the cursor faster than
	 * the cursor advances and the wrap that would revisit a low id never happens. The
	 * caller freezes `$max_id` (and `$cutoff_utc`) once per cycle, so rows arriving
	 * during a cycle join the NEXT one and the current candidate set can only shrink.
	 *
	 * ⚠ `$max_id` IS REQUIRED, NOT DEFAULTED. A default would be an unbounded page,
	 * which is exactly the shape that starved — and a starving sweep produces no
	 * error, no exception and no log line, so nothing but the type signature can stop
	 * a future caller reintroducing it.
	 *
	 * @param string $cutoff_utc `Y-m-d H:i:s` UTC; rows last touched at or before this.
	 * @param int    $after_id   Return rows with an id strictly greater than this.
	 * @param int    $limit      Maximum rows to return.
	 * @param int    $max_id     Cycle high-water mark; rows above it belong to the
	 *                           next cycle. Zero or less means no cycle is open and
	 *                           there is nothing to read.
	 * @return array[] Tombstone rows, ascending by id.
	 */
	public function find_stale_scheduled( string $cutoff_utc, int $after_id, int $limit, int $max_id ): array {
		global $wpdb;

		if ( $max_id <= 0 ) {
			return array();
		}

		$limit = max( 1, $limit );
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- indexed maintenance read of the plugin-owned tombstone table; a cached read would act on a delivery another pass has already finalised.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input.
				"SELECT * FROM {$table}
					WHERE final_status = %s AND last_seen_at <= %s AND id > %d AND id <= %d
					ORDER BY id ASC LIMIT %d",
				self::SCHEDULED,
				$cutoff_utc,
				max( 0, $after_id ),
				$max_id,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The highest id among the orphan half's candidates RIGHT NOW (ADR-0015 §8.3b).
	 *
	 * The cycle high-water mark. Read once when a sweep cycle opens and then frozen
	 * for the whole cycle, so that `find_stale_scheduled()` describes a candidate set
	 * that cannot grow while the sweep is working through it.
	 *
	 * ⚠ IT MEASURES THE CANDIDATE SET, NOT THE TABLE. `MAX(id)` over the whole table
	 * would work for fairness — nothing above it can be eligible — but it would put
	 * the mark past a long run of terminal rows, and a cycle is only complete once
	 * the cursor has walked to the mark. Bounding the mark by the candidates keeps a
	 * cycle as short as the work actually justifies.
	 *
	 * Returns 0 when there are no candidates, which the caller reads as "the cycle is
	 * complete before it started" — the ordinary state of a store with nothing aged
	 * and pending.
	 *
	 * ⚠ A FAILED READ IS INDISTINGUISHABLE FROM AN EMPTY SET HERE, AND THAT IS SAFE
	 * IN THIS ONE DIRECTION ONLY. `get_var()` returns null for both, and both mean
	 * "sweep nothing this run" — the sweep is a recovery mechanism, so doing nothing
	 * costs a day's delay, never a delivery. This is the opposite of §8.1a's rule,
	 * which governs WRITES: there, "somebody else won" and "nothing happened" have
	 * different consequences, so they may never share a return value.
	 *
	 * @param string $cutoff_utc `Y-m-d H:i:s` UTC; rows last touched at or before this.
	 * @return int Highest candidate id, or 0.
	 */
	public function max_stale_scheduled_id( string $cutoff_utc ): int {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- indexed maintenance read of the plugin-owned tombstone table; a cached read would freeze a cycle against a stale view of the queue.
		$max = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input.
				"SELECT MAX(id) FROM {$table} WHERE final_status = %s AND last_seen_at <= %s",
				self::SCHEDULED,
				$cutoff_utc
			)
		);

		return null === $max ? 0 : max( 0, (int) $max );
	}

	/**
	 * The two states a DELAYED delivery can be pending in (ADR-0015 §8.8).
	 *
	 * Deliberately not `IN_FLIGHT_STATUSES`: that set includes `claimed`, which is
	 * the immediate path's transient state and belongs to a request that is still
	 * running, not to the scheduled state machine.
	 */
	const PENDING_SCHEDULED_STATUSES = array( self::SCHEDULED, self::EXECUTING );

	/**
	 * Every PENDING delayed delivery in the store, one page at a time
	 * (ADR-0015 §8.6, §8.8).
	 *
	 * For deactivation and uninstall, which must finalise the lot. Paged by id
	 * rather than selected whole: this table's whole design is to grow for the
	 * lifetime of the store, and a lifecycle hook that loads all of it into memory
	 * is a lifecycle hook that fails on the sites that most need it to work.
	 *
	 * ⚠ BOTH PENDING STATES, AND `executing` WAS MISSING UNTIL PROMPT 7B. The lease
	 * exists so that no second actor touches a running delivery, and during normal
	 * running that is exactly right — but a shutdown is not normal running. A
	 * merchant who deactivated mid-flight left a row `executing` with its snapshot
	 * retained, and deactivation ALSO removes the maintenance action, so the
	 * stale-lease sweep that would have recovered it an hour later was gone too.
	 * Nothing could ever move that row again. What each state becomes is the
	 * caller's decision (§8.8); this method only has to stop hiding one of them.
	 *
	 * ⚠ THE CALLER MUST PAGE BY THE LAST ID IT SAW, NOT BY OFFSET. Each pass
	 * finalises the rows it read, so they leave this result set — an `OFFSET` would
	 * then skip exactly as many unprocessed rows as it had already handled.
	 *
	 * @param int $after_id Return rows with an id strictly greater than this.
	 * @param int $limit    Maximum rows to return.
	 * @return array[] Tombstone rows, ascending by id.
	 */
	public function find_pending_after( int $after_id, int $limit ): array {
		global $wpdb;

		$limit = max( 1, $limit );
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lifecycle read of the plugin-owned tombstone table; a cached read would let a just-scheduled delivery escape deactivation.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input.
				"SELECT * FROM {$table}
					WHERE final_status IN ( %s, %s ) AND id > %d
					ORDER BY id ASC LIMIT %d",
				self::SCHEDULED,
				self::EXECUTING,
				max( 0, $after_id ),
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many delayed deliveries are still pending, in either state.
	 *
	 * ⚠ THE DEACTIVATION PRECONDITION (ADR-0015 §8.8). The hook-wide unschedule is
	 * a sweep with no tombstone in its hand: it removes every job this plugin owns
	 * whether or not the row behind it was finalised. Running it while a pending row
	 * survives produces exactly the stranded state this ADR exists to eliminate, so
	 * the caller asks this first and leaves the jobs alone when the answer is not
	 * zero.
	 *
	 * @return int -1 when the count could not be read, which is NOT zero.
	 */
	public function count_pending(): int {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lifecycle aggregate over the plugin-owned tombstone table; a cached read would authorise a hook-wide unschedule against a stale answer.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input.
				"SELECT COUNT(*) FROM {$table} WHERE final_status IN ( %s, %s )",
				self::SCHEDULED,
				self::EXECUTING
			)
		);

		// ⚠ NULL IS NOT ZERO. A failed count must never read as "nothing is
		// pending", which is the answer that authorises removing every job.
		return null === $count ? -1 : (int) $count;
	}

	/**
	 * How many tombstones currently hold one status.
	 *
	 * Used by the lifecycle assertions (ADR-0015 §8.6) and by future reporting.
	 *
	 * @param string $status One of self::FINAL_STATUSES.
	 * @return int
	 */
	public function count_with_status( string $status ): int {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregate over the plugin-owned tombstone table.
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input.
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE final_status = %s", DeliveryIdentity::normalize( $status ) )
		);
	}

	/**
	 * Columns the history listing may filter on by exact match (ADR-0018 §5).
	 *
	 * An allowlist of IDENTIFIERS, keyed by the argument name the caller uses, so
	 * the SQL text is assembled only from members of this constant. Every VALUE is
	 * still bound.
	 */
	const HISTORY_INT_FILTERS = array(
		'order_id' => 'order_id',
		'rule_id'  => 'rule_id',
	);

	/**
	 * String columns the history listing may filter on by exact match.
	 *
	 * ⚠ NO REPAIR AND NO FALLBACK. A value outside the stored vocabulary simply
	 * matches nothing, exactly as `RuleRepository::where_clause()` behaves — the
	 * alternative is a filter that silently widens itself when a merchant hand-edits
	 * the URL, which is the shape that reads as the filter being broken.
	 */
	const HISTORY_STRING_FILTERS = array(
		'final_status' => 'final_status',
		'mode'         => 'mode',
	);

	/**
	 * Date-range filters, argument name => comparison operator.
	 *
	 * Both bounds are inclusive, and both are compared against the SAME column the
	 * ordering uses, so one index serves the filter and the sort.
	 */
	const HISTORY_DATE_FILTERS = array(
		'date_from' => '>=',
		'date_to'   => '<=',
	);

	/**
	 * Rows the history screen reads at a time.
	 *
	 * Well below self::DELETE_CHUNK_SIZE, which is what keeps the per-page detail
	 * and rule-name batches to ONE statement each (ADR-0018 §7).
	 */
	const HISTORY_PER_PAGE = 20;

	/**
	 * ONE PAGE OF DELIVERY HISTORY: filtered, ordered and paged IN SQL
	 * (ADR-0018 §5).
	 *
	 * ⚠ NOTHING IS LOADED THAT IS NOT SHOWN. Reading the table and slicing it in PHP
	 * is unbounded in the one dimension this table is DESIGNED to grow in — it is
	 * never purged, by construction — and it is the defect the Prompt 7C sweep
	 * corrected elsewhere. Every filter, the ordering and the page window are in the
	 * statement.
	 *
	 * ⚠ ORDERED `first_claimed_at DESC, id DESC`, AND BOTH HALVES ARE LOAD-BEARING.
	 * Newest first is what a merchant wants; the tie-break on the primary key is what
	 * makes paging DETERMINISTIC. Without it two deliveries claimed in the same second
	 * have no defined order, so MySQL is free to return them differently for page 1
	 * and page 2 — and a row that swaps across the boundary is either shown twice or
	 * never shown at all.
	 *
	 * ⚠ `first_claimed_at` AND NOT `last_seen_at`. `claim()`'s
	 * `ON DUPLICATE KEY UPDATE` bumps `last_seen_at` every time the same trigger fires
	 * again, so ordering by it would shuffle a year-old delivery to the top of the
	 * history the moment a merchant re-saved the order.
	 *
	 * ⚠ THE ORDER-BY CARRIES NO CALLER INPUT AT ALL. `prepare()` binds values, not
	 * identifiers, so the one clause where a request string could reach SQL
	 * uninterpolated is simply not parameterised here — the listing has no sortable
	 * columns and therefore no allowlist to get wrong.
	 *
	 * Served by the `history_recent (first_claimed_at, id)` index (ADR-0018 §6).
	 *
	 * @param array $args {
	 *     Filter and paging arguments. Every one is optional.
	 *
	 *     @type int    $order_id     Exact-match filter; 0 or less for all.
	 *     @type int    $rule_id      Exact-match filter; 0 or less for all.
	 *     @type string $final_status Exact-match filter, or '' for all.
	 *     @type string $mode         Exact-match filter, or '' for all.
	 *     @type string $date_from    `Y-m-d H:i:s` UTC lower bound, inclusive.
	 *     @type string $date_to      `Y-m-d H:i:s` UTC upper bound, inclusive.
	 *     @type int    $limit        Page size.
	 *     @type int    $offset       Page offset.
	 * }
	 * @return array[] Tombstone rows, newest first.
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$table = $this->table();
		$where = $this->history_where( $args );

		$limit  = max( 1, (int) ( $args['limit'] ?? self::HISTORY_PER_PAGE ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$sql = "SELECT * FROM {$table} {$where['sql']} ORDER BY first_claimed_at DESC, id DESC LIMIT %d OFFSET %d";

		$params = array_merge( $where['params'], array( $limit, $offset ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- paged listing read of the plugin-owned tombstone table; a cached page would show a merchant a delivery state another request has already moved on from, which is the one thing this screen exists to report accurately.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier and {$where['sql']} is assembled ONLY from the HISTORY_*_FILTERS class constants plus hardcoded date predicates; every VALUE is bound through $params.
			$wpdb->prepare( $sql, $params ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many tombstones match the same filters self::query() would apply.
	 *
	 * ⚠ SHARES ONE WHERE-CLAUSE BUILDER WITH self::query(), so the pager and the page
	 * can never disagree about what they are counting — a pager reporting 40 while the
	 * pages hold 37 is a defect nobody sees until the last page renders empty.
	 *
	 * ⚠ NAMED `count_matching()` RATHER THAN OVERLOADING `count()`. The existing
	 * no-argument `count()` means "every tombstone in the store" and is what the
	 * lifecycle assertions and the uninstall smoke test read; giving it an optional
	 * filter argument would make an unfiltered call and a call whose filters happened
	 * to be empty indistinguishable at the call site.
	 *
	 * @param array $args Same filter keys as self::query(); paging is ignored.
	 * @return int
	 */
	public function count_matching( array $args = array() ): int {
		global $wpdb;

		$table = $this->table();
		$where = $this->history_where( $args );

		$sql = "SELECT COUNT(*) FROM {$table} {$where['sql']}";

		if ( array() !== $where['params'] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- {$table} is a plugin-derived identifier and {$where['sql']} is built from the filter allowlists above; every value is bound.
			$sql = $wpdb->prepare( $sql, $where['params'] );
		}

		// An unfiltered count carries no values at all, and prepare() with an empty
		// argument list is a deprecation rather than a no-op — so that form is issued
		// directly and the filtered one is always bound.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- pager count over the plugin-owned tombstone table; see above.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * The shared WHERE clause for self::query() and self::count_matching().
	 *
	 * @param array $args Filter arguments.
	 * @return array{sql:string, params:array}
	 */
	private function history_where( array $args ): array {
		$clauses = array();
		$params  = array();

		foreach ( self::HISTORY_INT_FILTERS as $key => $column ) {
			$value = (int) ( $args[ $key ] ?? 0 );

			if ( $value <= 0 ) {
				continue;
			}

			$clauses[] = $column . ' = %d';
			$params[]  = $value;
		}

		foreach ( self::HISTORY_STRING_FILTERS as $key => $column ) {
			$value = (string) ( $args[ $key ] ?? '' );

			if ( '' === $value ) {
				continue;
			}

			$clauses[] = $column . ' = %s';
			$params[]  = $value;
		}

		// ⚠ THE RANGE IS ON `first_claimed_at`, THE SAME COLUMN THE ORDERING USES, so
		// one index serves both. Both bounds are inclusive and both are UTC, because
		// that is how `current_time( 'mysql', true )` wrote the column; converting a
		// merchant's local date into this form is the admin layer's job, done once,
		// rather than a timezone assumption buried in SQL.
		foreach ( self::HISTORY_DATE_FILTERS as $key => $operator ) {
			$value = trim( (string) ( $args[ $key ] ?? '' ) );

			if ( '' === $value ) {
				continue;
			}

			$clauses[] = 'first_claimed_at ' . $operator . ' %s';
			$params[]  = $value;
		}

		return array(
			'sql'    => array() === $clauses ? '' : 'WHERE ' . implode( ' AND ', $clauses ),
			'params' => $params,
		);
	}

	/**
	 * Fetch one tombstone by primary key.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return array|null
	 */
	public function find_by_id( int $delivery_id ): ?array {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- primary-key read of the plugin-owned tombstone table; caching a claim state would risk a duplicate send.
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $delivery_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Delete every tombstone AND detail row for a permanently deleted order
	 * (ADR-0004: the tombstone's bound is the ORDER lifetime).
	 *
	 * FAIL CLOSED. There is no real foreign key — `dbDelta()` cannot declare
	 * one, so ADR-0009 makes referential integrity repository discipline. If
	 * the child delete failed and the parent delete went ahead anyway, the
	 * detail rows would be orphaned permanently and unreachable by anything,
	 * including the privacy eraser. So the children are deleted first, the
	 * result is CHECKED, and the parents are left alone on failure.
	 *
	 * Wrapped in a transaction where the storage engine supports one, so a
	 * mid-way failure rolls back rather than leaving half the rows.
	 *
	 * @param int $order_id Order id.
	 * @return array{success:bool,details_deleted:int,tombstones_deleted:int}
	 */
	public function delete_for_order( int $order_id ): array {
		global $wpdb;

		$result = array(
			'success'            => true,
			'details_deleted'    => 0,
			'tombstones_deleted' => 0,
		);

		// The transaction opens BEFORE the ids are resolved, and the tombstones
		// are selected FOR UPDATE, so a concurrent detail insert contends on the
		// same parent rows instead of slipping in between the child delete and
		// the parent delete (Prompt 2c Item 4).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control around plugin-owned tables.
		$wpdb->query( 'START TRANSACTION' );

		$table = $this->table();

		// get_col() returns an empty array both for "no rows" and for a FAILED
		// query — and this query can genuinely fail, because acquiring the row
		// lock can hit innodb_lock_wait_timeout while a concurrent insert holds
		// it. Reporting success there would mean "cleanup did nothing, and said
		// it worked". Clear the error first so the check below is about THIS
		// statement.
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lifecycle cleanup of the plugin-owned tombstone table; the row lock is the point of this read.
		$locked = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input.
			$wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = %d ORDER BY id ASC FOR UPDATE", $order_id )
		);

		if ( '' !== (string) $wpdb->last_error ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control around plugin-owned tables.
			$wpdb->query( 'ROLLBACK' );
			$this->log_error( 'order cleanup for order #' . $order_id . ' could not lock its tombstones; nothing was deleted' );
			$result['success'] = false;
			return $result;
		}

		$ids = array();
		foreach ( (array) $locked as $locked_id ) {
			$locked_id = (int) $locked_id;
			if ( $locked_id > 0 ) {
				$ids[] = $locked_id;
			}
		}
		if ( empty( $ids ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control around plugin-owned tables.
			$wpdb->query( 'COMMIT' );
			return $result; // Nothing to do is a success.
		}

		$details = $this->details()->delete_for_deliveries( $ids );
		if ( false === $details ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control around plugin-owned tables.
			$wpdb->query( 'ROLLBACK' );
			$this->log_error( 'order cleanup aborted for order #' . $order_id . ': detail delete failed, tombstones left intact' );
			$result['success'] = false;
			return $result;
		}
		$result['details_deleted'] = (int) $details;

		$tombstones = 0;
		foreach ( array_chunk( array_unique( $ids ), self::DELETE_CHUNK_SIZE ) as $chunk ) {
			// Placeholders are generated from the COUNT of ids, and every id is
			// an int cast above — no value reaches the SQL unprepared.
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lifecycle cleanup of the plugin-owned tombstone table.
			$deleted = $wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$table} is a plugin-derived identifier; {$placeholders} is a generated run of %d tokens counted from $chunk, whose members are all int-cast above, so every value is bound by prepare(). The sniff cannot see placeholders that arrive via a variable.
				$wpdb->prepare( "DELETE FROM {$table} WHERE id IN ( {$placeholders} )", $chunk )
			);

			if ( false === $deleted ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control around plugin-owned tables.
				$wpdb->query( 'ROLLBACK' );
				$this->log_error( 'order cleanup aborted for order #' . $order_id . ': tombstone delete failed' );
				return array(
					'success'            => false,
					'details_deleted'    => 0,
					'tombstones_deleted' => 0,
				);
			}
			$tombstones += (int) $deleted;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control around plugin-owned tables.
		$wpdb->query( 'COMMIT' );

		$result['tombstones_deleted'] = $tombstones;
		return $result;
	}

	/**
	 * The child detail repository.
	 *
	 * A seam, not indirection for its own sake: `delete_for_order()` is
	 * fail-closed and must abort when the CHILD delete fails, and a test
	 * cannot prove that branch without being able to make the child fail.
	 *
	 * @return DeliveryDetailRepository
	 */
	protected function details(): DeliveryDetailRepository {
		return new DeliveryDetailRepository();
	}

	/**
	 * Total tombstone count. Used by tests and future admin reporting.
	 *
	 * @return int
	 */
	public function count(): int {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregate over the plugin-owned tombstone table.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input; the statement takes no parameters.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Log through WooCommerce's logger when available.
	 *
	 * @param string $message Error detail.
	 * @return void
	 */
	protected function log_error( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'extonify-wcep' ) );
		}
	}
}
