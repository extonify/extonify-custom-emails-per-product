<?php
/**
 * Purgeable delivery-detail repository (ADR-0004, ADR-0005, ADR-0009).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Repository;

use Extonify\WCEP\Domain\Json;
use Extonify\WCEP\Domain\Recipient;
use Extonify\WCEP\Domain\Text;
use Extonify\WCEP\Install\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * The purgeable attempt/detail store.
 *
 * This is where the personal data lives. It is subject to retention purging
 * and privacy erasure; the durable tombstone in DeliveryRepository is not, and
 * nothing in this class may touch it.
 *
 * ONE ROW PER RESOLVED RECIPIENT (ADR-0009, amended Prompt 2a). A delivery to
 * a customer plus one CC and one BCC writes three rows sharing `delivery_id`
 * and `attempt`. The privacy exporter and eraser match on `recipient`, so that
 * column holds exactly one normalised address — never a display name and never
 * a comma-joined list.
 */
class DeliveryDetailRepository {

	/**
	 * Attempt types (ADR-0005).
	 */
	const TYPES = array( 'auto', 'manual', 'resend', 'test', 'debug' );

	/**
	 * Attempt states (ADR-0005).
	 */
	const STATES = array( 'scheduled', 'sent', 'failed', 'cancelled', 'skipped', 'suppressed', self::UNRESOLVED, self::ABANDONED );

	/**
	 * Content was rendered into a native email whose send outcome was never
	 * reported (ADR-0013 §6).
	 *
	 * DISTINCT FROM `failed` ON PURPOSE. Nothing is known to have failed: the
	 * content went into the message, and `woocommerce_email_sent` never fired to
	 * say what happened next — because the send threw (ADR-0012 §11e), or the
	 * render never sent at all. Recording it as `failed` would assert something
	 * untrue; dropping it would leave a silent gap.
	 *
	 * Schema v1 is unreleased and `state` is a PHP-enforced varchar allowlist, so
	 * this is a constant change and NOT a migration — the same reasoning
	 * ADR-0012 §2 used when adding `skipped`.
	 */
	const UNRESOLVED = 'unresolved';

	/**
	 * Content was rendered into a native email that WAS NEVER SENT (ADR-0013 §6a).
	 *
	 * DISTINCT FROM `unresolved`, WHICH IS ITSELF DISTINCT FROM `failed`. Three
	 * different facts, and collapsing any pair of them states something untrue:
	 *
	 *   - `failed`     — a send happened and WooCommerce reported it did not work;
	 *   - `unresolved` — a send happened and NOTHING was reported back;
	 *   - `abandoned`  — no send happened at all. A render was produced and thrown
	 *     away, which is what a third party calling `get_content()` for its own
	 *     purposes does.
	 *
	 * An abandoned render is NOT a delivery attempt, so it never becomes the
	 * tombstone's `final_status` while a genuine attempt exists — see
	 * self::record_attempt()'s `$defer_to_genuine_attempt`.
	 *
	 * Schema v1 is unreleased and `state` is a PHP-enforced varchar allowlist, so
	 * this is a constant change and NOT a migration — the same reasoning ADR-0012 §2
	 * used for `skipped` and ADR-0013 §6 for `unresolved`.
	 */
	const ABANDONED = 'abandoned';

	/**
	 * States that record a REAL send attempt and its outcome (ADR-0013 §6a).
	 *
	 * The tombstone's `final_status` belongs to the latest of these. Anything else —
	 * `abandoned` above all — describes something that happened around a delivery
	 * rather than a delivery.
	 */
	const GENUINE_ATTEMPT_STATES = array( 'sent', 'failed', self::UNRESOLVED );

	/**
	 * Fields that can hold personal data and must therefore be surfaced by the
	 * exporter and cleared by the eraser (Prompt 2a Item 3).
	 *
	 * `reason` and `failure_message` are here because they routinely carry the
	 * recipient address, customer names, order values and raw SMTP responses —
	 * leaving them behind made erasure incomplete.
	 *
	 * Privacy\SubjectData splits these into CONTACT and CONTENT fields: on a row
	 * addressed to a CC or BCC recipient the contact fields belong to that other
	 * person and must survive a request made by the customer.
	 */
	const PERSONAL_FIELDS = array( 'recipient', 'recipient_header', 'recipient_type', 'subject', 'snapshot', 'reason', 'failure_message' );

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
		return Migrator::table( 'delivery_details' );
	}

	/**
	 * Insert one attempt row for ONE recipient.
	 *
	 * Input is sanitised here, at the storage boundary. Output escaping is the
	 * caller's job, performed late at the point of rendering.
	 *
	 * A `recipient` that is not exactly one storable address is REJECTED (the
	 * row is not written and 0 is returned) rather than stored raw: a
	 * comma-joined list would be invisible to the privacy eraser's
	 * `WHERE recipient = %s` lookup. Resolving a delivery into one row per
	 * recipient belongs to the delivery engine. A header-form value
	 * (`Alice <a@b.test>`) is normalised instead — the bare address goes to
	 * `recipient`, the original to the never-searched `recipient_header`.
	 *
	 * @param int   $delivery_id Owning tombstone id.
	 * @param array $data        Attempt fields; unknown keys are ignored.
	 * @return int Inserted row id, or 0 on failure or rejected input.
	 */
	public function insert( int $delivery_id, array $data ): int {
		global $wpdb;

		// LOCK THE PARENT FOR THE DURATION (Prompt 2c Item 4).
		//
		// Order cleanup resolves tombstone ids, deletes the details, then
		// deletes the tombstones. Without a lock an insert landing between
		// those two deletes writes a detail row whose parent is about to
		// vanish — and there is no foreign key to stop it. Taking the parent
		// row FOR UPDATE makes both paths contend on the same row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control around plugin-owned tables.
		$wpdb->query( 'START TRANSACTION' );

		$prepared = $this->prepare_row( $delivery_id, $data );

		if ( null === $prepared['row'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control around plugin-owned tables.
			$wpdb->query( 'ROLLBACK' );
			$this->log_error( 'refused to write a delivery-detail row: ' . (string) $prepared['error'] );
			return 0;
		}

		$insert_id = $this->write_row( $prepared['row'] );

		if ( $insert_id <= 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control around plugin-owned tables.
			$wpdb->query( 'ROLLBACK' );
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control around plugin-owned tables.
		$wpdb->query( 'COMMIT' );

		return $insert_id;
	}

	/**
	 * Allocate an attempt number, write the attempt row, and record the tombstone's
	 * resulting status — ALL IN ONE TRANSACTION, holding the parent row lock
	 * (ADR-0009 amendment, Prompt 5B Item E).
	 *
	 * ⚠ WHY THIS EXISTS, AND BOTH DEFECTS ARE RACES THAT CORRUPT THE AUDIT.
	 *
	 * 1. **Two recorders could allocate the same attempt number.** The attempt
	 *    number used to be computed OUTSIDE this transaction, from the tombstone's
	 *    `suppressed_count`, and only then was the row inserted under the parent
	 *    lock. Two concurrent recorders — a merchant resending while a webhook fires
	 *    the same email — both read the same count and both wrote attempt 2. The
	 *    count is also the wrong source: it counts CLAIMS, not attempt rows, so a
	 *    claim that failed to write its row left the numbering permanently offset.
	 *    The number now comes from `MAX(attempt) + 1` over the attempt rows
	 *    themselves, computed after the `FOR UPDATE` lock is held, so a second
	 *    recorder blocks until the first has committed its row and then sees it.
	 *
	 * 2. **The tombstone could contradict its own highest attempt.** Request A
	 *    inserted attempt 2, request B inserted attempt 3 and wrote
	 *    `final_status = sent`, then A finished and wrote `final_status = failed` —
	 *    a tombstone reporting the outcome of an EARLIER attempt than the one it
	 *    holds. Allocating and writing the status inside the same lock makes that
	 *    ordering impossible: whoever allocates the higher attempt is, necessarily,
	 *    the one who writes last.
	 *
	 * A FAILED STATUS WRITE DOES NOT ROLL BACK THE ATTEMPT ROW. The row records
	 * something that really happened, and discarding it would lose the only evidence
	 * of a real send; the shortfall is REPORTED to the caller instead (ADR-0012 §3).
	 *
	 * @param int         $delivery_id              Owning tombstone id.
	 * @param array       $data                     Attempt fields; `attempt` is
	 *                                              ignored — it is allocated here.
	 * @param string|null $final_status             Status to record on the
	 *                                              tombstone, or null to leave it.
	 * @param int         $rule_revision_sent       Revision to record with it.
	 * @param bool        $defer_to_genuine_attempt When true the status is written
	 *                                              ONLY if no earlier attempt on
	 *                                              this tombstone recorded a real
	 *                                              send outcome — the ADR-0013 §6a
	 *                                              abandoned-render rule. Checked
	 *                                              inside the lock, or it would be a
	 *                                              third race.
	 * @param bool        $type_by_genuine_attempt  When true `type` is DERIVED here:
	 *                                              `resend` if this tombstone already
	 *                                              carries a real send attempt,
	 *                                              `auto` otherwise (ADR-0013 §6b).
	 *                                              The caller's `type` is ignored.
	 * @return array{id:int,attempt:int,type:string,status_written:bool,status_deferred:bool}
	 */
	public function record_attempt( int $delivery_id, array $data, ?string $final_status = null, int $rule_revision_sent = 0, bool $defer_to_genuine_attempt = false, bool $type_by_genuine_attempt = false ): array {
		global $wpdb;

		$result = array(
			'id'              => 0,
			'attempt'         => 0,
			'type'            => '',
			'status_written'  => false,
			'status_deferred' => false,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control around plugin-owned tables.
		$wpdb->query( 'START TRANSACTION' );

		// Takes the parent row FOR UPDATE. Everything below happens under that lock.
		$prepared = $this->prepare_row( $delivery_id, $data );

		if ( null === $prepared['row'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control around plugin-owned tables.
			$wpdb->query( 'ROLLBACK' );
			$this->log_error( 'refused to write a delivery-detail row: ' . (string) $prepared['error'] );
			return $result;
		}

		$row            = $prepared['row'];
		$attempt        = $this->next_attempt_locked( $delivery_id );
		$row['attempt'] = $attempt;

		// READ BEFORE WRITING: this row must not count itself as an earlier attempt.
		$has_genuine = $this->has_genuine_attempt( $delivery_id );

		if ( $type_by_genuine_attempt ) {
			/*
			 * THE TYPE COMES FROM DELIVERY HISTORY, NOT FROM CLAIM SUPPRESSION
			 * (ADR-0013 §6b). A first genuine send whose identity was already claimed
			 * by an ABANDONED render was being written `resend` — recording a
			 * merchant's FIRST delivery as a repeat of something that never went out.
			 * Both values are members of self::TYPES, so validation is not bypassed.
			 */
			$row['type'] = $has_genuine ? 'resend' : 'auto';
		}

		$result['type'] = (string) $row['type'];

		$insert_id = $this->write_row( $row );

		if ( $insert_id <= 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control around plugin-owned tables.
			$wpdb->query( 'ROLLBACK' );
			return $result;
		}

		$result['id']      = $insert_id;
		$result['attempt'] = $attempt;

		if ( null !== $final_status ) {
			if ( $defer_to_genuine_attempt && $has_genuine ) {
				$result['status_deferred'] = true;
			} else {
				$result['status_written'] = $this->deliveries()->set_final_status( $delivery_id, $final_status, $rule_revision_sent );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control around plugin-owned tables.
		$wpdb->query( 'COMMIT' );

		return $result;
	}

	/**
	 * The next attempt number for a tombstone, read under the parent lock.
	 *
	 * MUST BE CALLED INSIDE THE TRANSACTION THAT HOLDS THE PARENT `FOR UPDATE`
	 * LOCK. Called anywhere else it is a plain read and two callers can agree on the
	 * same number.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return int
	 */
	private function next_attempt_locked( int $delivery_id ): int {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregate over the plugin-owned detail table inside the allocating transaction; a cached read would defeat the allocation.
		$max = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input.
			$wpdb->prepare( "SELECT MAX(attempt) FROM {$table} WHERE delivery_id = %d", $delivery_id )
		);

		return max( 1, (int) $max + 1 );
	}

	/**
	 * Whether a tombstone already carries a REAL send attempt (ADR-0013 §6a).
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return bool
	 */
	private function has_genuine_attempt( int $delivery_id ): bool {
		global $wpdb;
		$table = $this->table();

		// Placeholders are generated from the COUNT of a class constant, and every
		// member of it is a hardcoded literal.
		$placeholders = implode( ', ', array_fill( 0, count( self::GENUINE_ATTEMPT_STATES ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- probe of the plugin-owned detail table inside the allocating transaction; the answer decides a status write and must be live.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$table} is a plugin-derived identifier; {$placeholders} is a generated run of %s tokens counted from the GENUINE_ATTEMPT_STATES constant, whose members are hardcoded literals.
				"SELECT id FROM {$table} WHERE delivery_id = %d AND state IN ( {$placeholders} ) LIMIT 1",
				array_merge( array( $delivery_id ), self::GENUINE_ATTEMPT_STATES )
			)
		);

		return null !== $found;
	}

	/**
	 * Write one prepared row. NO transaction control of its own — the caller owns
	 * it, because ⚠ MySQL treats a nested `START TRANSACTION` as an implicit COMMIT
	 * of the outer one, which would release the parent lock mid-operation.
	 *
	 * @param array $row Prepared row from self::prepare_row().
	 * @return int Inserted row id, or 0 on failure.
	 */
	private function write_row( array $row ): int {
		global $wpdb;

		// Positional, matching the FIXED key order self::prepare_row() builds.
		$formats = array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- insert into the plugin-owned detail table; $wpdb->insert() prepares every value.
		$ok = $wpdb->insert( $this->table(), $row, $formats );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Validate one attempt row and build it, or explain why it cannot be written.
	 *
	 * TAKES THE PARENT ROW `FOR UPDATE` as part of the integrity probe, so every
	 * caller must already be inside a transaction.
	 *
	 * @param int   $delivery_id Owning tombstone id.
	 * @param array $data        Raw input.
	 * @return array{row:?array,error:?string}
	 */
	private function prepare_row( int $delivery_id, array $data ): array {
		$type  = isset( $data['type'] ) ? sanitize_key( (string) $data['type'] ) : 'auto';
		$state = isset( $data['state'] ) ? sanitize_key( (string) $data['state'] ) : 'scheduled';

		// INTEGRITY GATE (Prompt 2b Item 4). There is no database foreign key —
		// ADR-0009 makes referential integrity repository discipline — so this
		// is the only thing standing between a typo and an orphan row that no
		// cleanup path and no privacy path can ever reach.
		$invalid = $this->validate_row( $delivery_id, $data, $type, $state );
		if ( null !== $invalid ) {
			return array(
				'row'   => null,
				'error' => $invalid,
			);
		}

		// Strict, like `type` and `state`: silently relabelling a BCC as a
		// direct recipient corrupts the delivery audit and misreports the
		// recipient type in a privacy export.
		$recipient_type = Recipient::normalize_type( (string) ( $data['recipient_type'] ?? 'to' ) );
		if ( null === $recipient_type ) {
			return array(
				'row'   => null,
				'error' => 'unrecognised recipient_type for delivery #' . $delivery_id,
			);
		}

		$recipient        = null;
		$recipient_header = null;
		if ( isset( $data['recipient'] ) && '' !== trim( (string) $data['recipient'] ) ) {
			$raw       = (string) $data['recipient'];
			$recipient = Recipient::normalize( $raw );
			if ( null === $recipient ) {
				return array(
					'row'   => null,
					'error' => 'unresolvable recipient for delivery #' . $delivery_id
						. ' — the delivery engine must resolve one row per recipient',
				);
			}
			$display_name = Recipient::display_name( $raw );
			if ( null !== $display_name ) {
				// Recomposed from the sanitised NAME plus the already-normalised
				// address. Running sanitize_text_field() over the whole header
				// would strip `<alice@example.test>` as an HTML tag and lose the
				// address this column exists to record.
				$recipient_header = Recipient::compose_header( sanitize_text_field( $display_name ), $recipient );
			}
		}

		return array(
			'row'   => array(
				'delivery_id'       => $delivery_id,
				// A non-positive parent id is NOT a parent. Storing the raw cast
				// would write 0 — a fake parent that validation never inspects,
				// because it only checks values greater than zero.
				'parent_attempt_id' => ( isset( $data['parent_attempt_id'] ) && (int) $data['parent_attempt_id'] > 0 )
					? (int) $data['parent_attempt_id']
					: null,
				'attempt'           => isset( $data['attempt'] ) ? max( 1, (int) $data['attempt'] ) : 1,
				// Validated above, not defaulted: an unrecognised state would be
				// silently rewritten to 'scheduled', and since the retention purge
				// branches on state = 'failed', a typo like 'faild' would quietly
				// give a failed delivery the 90-day window instead of 180.
				'type'              => $type,
				'state'             => $state,
				// Shape-only sanitisation: see Text::log_value(). A tag-stripping
				// sanitiser would delete `<alice@example.test>` from a diagnostic.
				'reason'            => isset( $data['reason'] ) ? Text::log_value( (string) $data['reason'] ) : '',
				'recipient'         => $recipient,
				'recipient_type'    => $recipient_type,
				'recipient_header'  => $recipient_header,
				'subject'           => isset( $data['subject'] ) ? sanitize_text_field( (string) $data['subject'] ) : null,
				'snapshot'          => isset( $data['snapshot'] ) ? Json::encode( (array) $data['snapshot'] ) : null,
				'failure_message'   => isset( $data['failure_message'] ) ? Text::log_value( (string) $data['failure_message'] ) : null,
				'is_debug'          => ! empty( $data['is_debug'] ) ? 1 : 0,
				// UTC, matching the tombstone and the gmdate() comparison in
				// purge_older_than(). Never a MySQL CURRENT_TIMESTAMP default: the
				// database server's system timezone is not necessarily UTC, and a
				// local-time default would mis-fire retention by that offset.
				'created_at'        => current_time( 'mysql', true ),
			),
			'error' => null,
		);
	}

	/**
	 * The parent tombstone repository.
	 *
	 * A seam for the same reason `DeliveryRepository::details()` is one, and the
	 * status write it provides deliberately issues no transaction control of its
	 * own, so it participates in self::record_attempt()'s transaction.
	 *
	 * @return DeliveryRepository
	 */
	protected function deliveries(): DeliveryRepository {
		return new DeliveryRepository();
	}

	/**
	 * Validate a detail row before it is written.
	 *
	 * @param int    $delivery_id Owning tombstone id.
	 * @param array  $data        Raw input.
	 * @param string $type        Sanitised attempt type.
	 * @param string $state       Sanitised attempt state.
	 * @return string|null Reason the row is invalid, or null when it is fine.
	 */
	protected function validate_row( int $delivery_id, array $data, string $type, string $state ): ?string {
		global $wpdb;

		if ( $delivery_id <= 0 ) {
			return 'delivery_id must be a positive integer';
		}
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return 'unrecognised attempt type "' . $type . '"';
		}
		if ( ! in_array( $state, self::STATES, true ) ) {
			return 'unrecognised attempt state "' . $state . '"';
		}

		$deliveries = Migrator::table( 'deliveries' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- referential-integrity probe against the plugin-owned tombstone table; a cached read could admit an orphan.
		$exists = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$deliveries} is a plugin-derived identifier, not user input.
			$wpdb->prepare( "SELECT id FROM {$deliveries} WHERE id = %d FOR UPDATE", $delivery_id )
		);
		if ( null === $exists ) {
			return 'delivery #' . $delivery_id . ' does not exist';
		}

		if ( isset( $data['parent_attempt_id'] ) && (int) $data['parent_attempt_id'] > 0 ) {
			$parent_id = (int) $data['parent_attempt_id'];
			$table     = $this->table();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- referential-integrity probe against the plugin-owned detail table.
			$parent_delivery = $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input.
				$wpdb->prepare( "SELECT delivery_id FROM {$table} WHERE id = %d", $parent_id )
			);
			if ( null === $parent_delivery ) {
				return 'parent attempt #' . $parent_id . ' does not exist';
			}
			// An attempt chain that crossed deliveries would make a resend look
			// like part of a different order's history.
			if ( (int) $parent_delivery !== $delivery_id ) {
				return 'parent attempt #' . $parent_id . ' belongs to delivery #' . (int) $parent_delivery . ', not #' . $delivery_id;
			}
		}

		return null;
	}

	/**
	 * All attempt rows for one tombstone, oldest first.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return array[] Rows with `snapshot` decoded.
	 */
	public function find_for_delivery( int $delivery_id ): array {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- indexed read of the plugin-owned detail table.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE delivery_id = %d ORDER BY id ASC", $delivery_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Attempt rows for one recipient address, across every tombstone. Used by
	 * the privacy exporter and eraser.
	 *
	 * The address is normalised the same way it was on write, so a lookup by
	 * `Alice@Example.test` finds a row stored as `alice@example.test`.
	 *
	 * @param string $email  Email address to match.
	 * @param int    $limit  Page size.
	 * @param int    $offset Page offset.
	 * @return array[] Rows with `snapshot` decoded.
	 */
	public function find_by_recipient( string $email, int $limit = 100, int $offset = 0 ): array {
		global $wpdb;

		$normalized = Recipient::normalize( $email );
		if ( null === $normalized ) {
			return array();
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy request over the plugin-owned detail table; results must be live, never cached.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input; an identifier cannot be bound by prepare().
				"SELECT * FROM {$table} WHERE recipient = %s ORDER BY id ASC LIMIT %d OFFSET %d",
				$normalized,
				max( 1, $limit ),
				max( 0, $offset )
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Detail-row IDS for a set of tombstones.
	 *
	 * The privacy path needs ids rather than whole rows so it can union the
	 * order-linked matches with the recipient-string matches before reading.
	 *
	 * @param int[] $delivery_ids Tombstone ids.
	 * @return int[]
	 */
	public function ids_for_deliveries( array $delivery_ids ): array {
		global $wpdb;

		$ids = $this->positive_ints( $delivery_ids );
		if ( empty( $ids ) ) {
			return array();
		}

		$table = $this->table();
		$out   = array();

		foreach ( array_chunk( $ids, self::DELETE_CHUNK_SIZE ) as $chunk ) {
			// Placeholders are generated from the COUNT of ids, and every id is
			// an int cast above — no value reaches the SQL unprepared.
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy request over the plugin-owned detail table; results must be live.
			$rows = $wpdb->get_col(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$table} is a plugin-derived identifier; {$placeholders} is a generated run of %d tokens counted from $chunk, whose members are all int-cast above.
				$wpdb->prepare( "SELECT id FROM {$table} WHERE delivery_id IN ( {$placeholders} )", $chunk )
			);
			foreach ( (array) $rows as $id ) {
				$out[] = (int) $id;
			}
		}

		return $out;
	}

	/**
	 * Detail-row IDS whose recipient matches an address.
	 *
	 * @param string $email Email address.
	 * @return int[]
	 */
	public function ids_for_recipient( string $email ): array {
		global $wpdb;

		$normalized = Recipient::normalize( $email );
		if ( null === $normalized ) {
			return array();
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy request over the plugin-owned detail table; results must be live.
		$rows = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input.
			$wpdb->prepare( "SELECT id FROM {$table} WHERE recipient = %s", $normalized )
		);

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Tombstone ids owning any row whose recipient matches an address.
	 *
	 * Lets the privacy layer discover a retained tombstone even when its order
	 * is missing, deleted, or was never a resolvable WooCommerce order.
	 *
	 * @param string $email Email address.
	 * @return int[]
	 */
	public function delivery_ids_for_recipient( string $email ): array {
		global $wpdb;

		$normalized = Recipient::normalize( $email );
		if ( null === $normalized ) {
			return array();
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy request over the plugin-owned detail table; results must be live.
		$rows = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input.
			$wpdb->prepare( "SELECT DISTINCT delivery_id FROM {$table} WHERE recipient = %s", $normalized )
		);

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Read a page of rows by primary key.
	 *
	 * @param int[] $ids    Detail row ids.
	 * @param int   $limit  Page size.
	 * @param int   $offset Page offset.
	 * @return array[] Rows with `snapshot` decoded.
	 */
	public function find_by_ids( array $ids, int $limit = 100, int $offset = 0 ): array {
		global $wpdb;

		$ids = $this->positive_ints( $ids );
		if ( empty( $ids ) ) {
			return array();
		}
		sort( $ids );

		// Page the ID LIST in PHP rather than with SQL LIMIT/OFFSET. The ids are
		// already resolved and sorted in memory, so slicing here is equivalent,
		// keeps the statement to a single bound placeholder run, and avoids an
		// OFFSET scan that grows with the page number.
		$page = array_slice( $ids, max( 0, $offset ), max( 1, $limit ) );
		if ( empty( $page ) ) {
			return array();
		}

		$table = $this->table();
		// Placeholders are generated from the COUNT of ids, and every id is an
		// int cast above — no value reaches the SQL unprepared.
		$placeholders = implode( ', ', array_fill( 0, count( $page ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy request over the plugin-owned detail table; results must be live.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$table} is a plugin-derived identifier; {$placeholders} is a generated run of %d tokens counted from $page, whose members are all int-cast above.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ( {$placeholders} ) ORDER BY id ASC", $page ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Privacy eraser: clear the NAMED personal fields on the given rows.
	 *
	 * The field list is scoped by the caller, because a row addressed to a CC
	 * or BCC recipient is only partly the requesting subject's: its contact
	 * fields belong to that other person and must survive (see
	 * Privacy\SubjectData).
	 *
	 * @param int[]         $ids    Detail row ids.
	 * @param string[]|null $fields Fields to clear; null means every personal field.
	 * @return int Number of rows anonymised.
	 */
	public function anonymize_ids( array $ids, ?array $fields = null ): int {
		global $wpdb;

		$ids = $this->positive_ints( $ids );
		if ( empty( $ids ) ) {
			return 0;
		}

		// Only ever columns this class declares personal — never a caller string.
		$fields = ( null === $fields ) ? self::PERSONAL_FIELDS : array_values( array_intersect( $fields, self::PERSONAL_FIELDS ) );
		if ( empty( $fields ) ) {
			return 0;
		}

		$assignments = array();
		foreach ( $fields as $field ) {
			$assignments[] = '`' . $field . '` = NULL';
		}
		$set     = implode( ', ', $assignments );
		$table   = $this->table();
		$cleared = 0;

		foreach ( array_chunk( $ids, self::DELETE_CHUNK_SIZE ) as $chunk ) {
			// Placeholders are generated from the COUNT of ids, and every id is
			// an int cast above — no value reaches the SQL unprepared.
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy erasure over the plugin-owned detail table.
			$updated = $wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$table} is a plugin-derived identifier and {$set} is built ONLY from names intersected against the PERSONAL_FIELDS class constant above; {$placeholders} is a generated run of %d tokens counted from $chunk, whose members are all int-cast above.
				$wpdb->prepare( "UPDATE {$table} SET {$set} WHERE id IN ( {$placeholders} )", $chunk )
			);
			$cleared += is_int( $updated ) ? $updated : 0;
		}

		return $cleared;
	}

	/**
	 * Cast, filter and de-duplicate a list of ids.
	 *
	 * @param array $ids Raw ids.
	 * @return int[]
	 */
	private function positive_ints( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Privacy eraser: clear EVERY field that can hold personal data.
	 *
	 * Tombstones are deliberately untouched (ADR-0004/0005) — erasing an
	 * identity would silently re-arm an already-delivered order. The row itself
	 * is kept so the attempt history stays coherent; only the personal columns
	 * are cleared, leaving state, type, attempt and timestamps intact.
	 *
	 * @param string $email Email address to erase.
	 * @return int Number of rows anonymised.
	 */
	public function anonymize_recipient( string $email ): int {
		global $wpdb;

		$normalized = Recipient::normalize( $email );
		if ( null === $normalized ) {
			return 0;
		}

		$data    = array();
		$formats = array();
		foreach ( self::PERSONAL_FIELDS as $field ) {
			$data[ $field ] = null;
			$formats[]      = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy erasure over the plugin-owned detail table.
		$updated = $wpdb->update(
			$this->table(),
			$data,
			array( 'recipient' => $normalized ),
			$formats,
			array( '%s' )
		);

		return is_int( $updated ) ? $updated : 0;
	}

	/**
	 * Retention purge (ADR-0005). Acts ONLY on this detail table — tombstones
	 * are never purged.
	 *
	 * Three distinct tiers, matching the documented ADR-0005 windows:
	 *   - debug rows           (is_debug = 1)                      shortest;
	 *   - FAILED deliveries    (is_debug = 0 AND state = 'failed')  longest,
	 *     because failure history is exactly what support needs most;
	 *   - everything else      (is_debug = 0 AND state != 'failed').
	 *
	 * Before this correction every non-debug row shared one window, so failed
	 * deliveries were destroyed at the normal 90 days instead of 180.
	 *
	 * All comparisons are UTC (gmdate), matching how created_at is written.
	 *
	 * All three windows are REQUIRED. They used to default to 0, which means
	 * "keep indefinitely", so a caller passing only the normal window silently
	 * disabled failed and debug cleanup — a data-retention decision made by
	 * omission. Every caller now states all three.
	 *
	 * @param int $normal_days Age in days for non-debug, non-failed rows.
	 * @param int $failed_days Age in days for non-debug failed rows.
	 * @param int $debug_days  Age in days for debug rows.
	 * @return int Number of rows removed.
	 */
	public function purge_older_than( int $normal_days, int $failed_days, int $debug_days ): int {
		global $wpdb;
		$table   = $this->table();
		$removed = 0;

		// Each predicate is served by the composite (is_debug, state,
		// created_at) index.
		$tiers = array(
			array( $debug_days, 'is_debug = 1 AND created_at < %s' ),
			array( $failed_days, "is_debug = 0 AND state = 'failed' AND created_at < %s" ),
			array( $normal_days, "is_debug = 0 AND state <> 'failed' AND created_at < %s" ),
		);

		foreach ( $tiers as $tier ) {
			list( $days, $predicate ) = $tier;
			if ( $days <= 0 ) {
				continue; // 0 or less means "keep indefinitely" for that tier.
			}
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- retention purge over the plugin-owned detail table.
			$deleted = $wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$table} is a plugin-derived identifier and {$predicate} is a hardcoded literal from the $tiers list above, whose %s token prepare() binds. The sniff cannot see a placeholder that arrives via a variable.
				$wpdb->prepare( "DELETE FROM {$table} WHERE {$predicate}", $cutoff )
			);

			$removed += is_int( $deleted ) ? $deleted : 0;
		}

		return $removed;
	}

	/**
	 * Delete every detail row belonging to the given tombstones.
	 *
	 * Called by DeliveryRepository::delete_for_order() BEFORE the tombstones
	 * themselves. Chunked, because an order could in principle accumulate an
	 * unbounded number of tombstones and one giant IN (...) list can exceed
	 * max_allowed_packet.
	 *
	 * @param int[] $delivery_ids Tombstone ids.
	 * @return int|false Rows removed, or false when any chunk failed.
	 */
	public function delete_for_deliveries( array $delivery_ids ) {
		global $wpdb;

		$ids = array();
		foreach ( $delivery_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		if ( empty( $ids ) ) {
			return 0;
		}

		$table   = $this->table();
		$removed = 0;

		foreach ( array_chunk( array_unique( $ids ), self::DELETE_CHUNK_SIZE ) as $chunk ) {
			// Placeholders are generated from the COUNT of ids, and every id is
			// an int cast above — no value reaches the SQL unprepared.
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lifecycle cleanup of the plugin-owned detail table.
			$deleted = $wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$table} is a plugin-derived identifier; {$placeholders} is a generated run of %d tokens counted from $chunk, whose members are all int-cast above, so every value is bound by prepare(). The sniff cannot see placeholders that arrive via a variable.
				$wpdb->prepare( "DELETE FROM {$table} WHERE delivery_id IN ( {$placeholders} )", $chunk )
			);

			if ( false === $deleted ) {
				// Fail closed: the caller must NOT go on to delete the parents,
				// or these rows become permanently orphaned and unreachable —
				// including by the privacy eraser.
				return false;
			}
			$removed += (int) $deleted;
		}

		return $removed;
	}

	/**
	 * Total detail-row count. Used by tests and future admin reporting.
	 *
	 * @return int
	 */
	public function count(): int {
		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregate over the plugin-owned detail table.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a plugin-derived identifier, not user input; the statement takes no parameters.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Decode JSON columns on read.
	 *
	 * @param array $row Raw database row.
	 * @return array
	 */
	private function hydrate( array $row ): array {
		$row['snapshot'] = Json::decode( $row['snapshot'] ?? null );
		return $row;
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
