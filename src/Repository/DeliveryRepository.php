<?php
/**
 * Durable delivery tombstone repository (ADR-0004, ADR-0009).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Repository;

use Extonify\WCEP\Domain\DeliveryIdentity;
use Extonify\WCEP\Install\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * The durable delivery identity store.
 *
 * The tombstone contains no direct contact or message-content fields, but
 * remains linked to an order and is retained to prevent unintended duplicate
 * automatic deliveries.
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
	 * Terminal statuses a tombstone may record.
	 *
	 * An allowlist, not a sanitised free string: `final_status` drives support
	 * tooling and future reporting, and an arbitrary value would sit in the
	 * column forever with nothing able to interpret it.
	 */
	const FINAL_STATUSES = array( 'claimed', 'scheduled', 'sent', 'failed', 'cancelled' );

	/**
	 * How many ids to bind per statement when deleting in bulk.
	 */
	const DELETE_CHUNK_SIZE = 200;

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
	 * Record the terminal outcome of a delivery.
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
