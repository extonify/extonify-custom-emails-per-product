<?php
/**
 * Versioned, idempotent schema migrations (ADR-0009).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Install;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the plugin schema.
 *
 * Failures degrade the plugin to no-op mode via the db-error option and are
 * surfaced as an admin notice — never a fatal.
 */
class Migrator {

	const TARGET_DB_VERSION = 1;
	const OPTION_DB_VERSION = 'extonify_wcep_db_version';
	const OPTION_DB_ERROR   = 'extonify_wcep_db_error';

	/**
	 * Short-lived migration lock. Non-autoloaded; holds a unique owner token
	 * and an expiry so concurrent activation/upgrade requests run the
	 * migration exactly once and a stale lock self-recovers.
	 */
	const OPTION_LOCK = 'extonify_wcep_migration_lock';
	const LOCK_TTL    = 60;

	/**
	 * The stored db-error option and the admin notice carry ONLY this stable
	 * code and a generic message — never SQL, table, engine or host strings.
	 * Full detail goes to the WooCommerce logger (source: extonify-wcep).
	 */
	const DB_ERROR_CODE = 'extonify_wcep_db_prepare_failed';

	/**
	 * Unprefixed table names owned by this plugin, in creation order.
	 */
	const TABLES = array( 'rules', 'deliveries', 'delivery_details' );

	/**
	 * What the live database MUST look like at TARGET_DB_VERSION.
	 *
	 * This drives verify_schema(), which is the whole point: dbDelta() reports
	 * success even when it silently declines to create an index, so the schema
	 * has to be read back out of the database rather than inferred from the
	 * DDL we handed it.
	 *
	 * Each index is specified EXACTLY — name, ordered columns, uniqueness — not
	 * merely by name. Verifying by name alone would accept an index called
	 * `retention` sitting on the wrong columns (silently costing the retention
	 * purge its index) or a composite UNIQUE over
	 * (identity_hash, order_id) that still permits duplicate identities.
	 *
	 * `types` pins the column definitions the design actually depends on.
	 */
	const SCHEMA = array(
		'rules'            => array(
			'columns' => array(
				'id',
				'name',
				'status',
				'priority',
				'revision',
				'trigger_type',
				'trigger_value',
				'delivery_mode',
				'native_email_id',
				'insert_position',
				'targeting',
				'recipients',
				'subject',
				'heading',
				'content',
				'delay_seconds',
				'consolidation',
				'stop_processing',
				'created_at',
				'updated_at',
			),
			'indexes' => array(
				'PRIMARY'         => array(
					'columns' => array( 'id' ),
					'unique'  => true,
				),
				'status_priority' => array(
					'columns' => array( 'status', 'priority' ),
					'unique'  => false,
				),
				'trigger_lookup'  => array(
					'columns' => array( 'trigger_type', 'trigger_value' ),
					'unique'  => false,
				),
				// ADR-0013 §2: insert rules are found by the WooCommerce email
				// they target, never by a trigger. Schema v1 is unreleased, so
				// this is an in-place amendment of v1 and NOT migration 2.
				'insert_lookup'   => array(
					'columns' => array( 'delivery_mode', 'native_email_id', 'status' ),
					'unique'  => false,
				),
			),
			'types'   => array(),
		),
		'deliveries'       => array(
			'columns' => array(
				'id',
				'identity_hash',
				'order_id',
				'rule_id',
				'mode',
				'trigger_identity',
				'first_claimed_at',
				'last_seen_at',
				'final_status',
				'suppressed_count',
				'rule_revision_sent',
			),
			'indexes' => array(
				'PRIMARY'       => array(
					'columns' => array( 'id' ),
					'unique'  => true,
				),
				// THE constraint the entire duplicate-prevention design rests
				// on. It must be UNIQUE and it must cover identity_hash ALONE:
				// a composite unique index over (identity_hash, order_id) would
				// still permit two rows with the same identity, which is exactly
				// the duplicate send this table exists to prevent.
				'identity_hash' => array(
					'columns' => array( 'identity_hash' ),
					'unique'  => true,
				),
				'order_id'      => array(
					'columns' => array( 'order_id' ),
					'unique'  => false,
				),
			),
			'types'   => array( 'identity_hash' => 'char(64)' ),
		),
		'delivery_details' => array(
			'columns' => array(
				'id',
				'delivery_id',
				'parent_attempt_id',
				'attempt',
				'type',
				'state',
				'reason',
				'recipient',
				'recipient_type',
				'recipient_header',
				'subject',
				'snapshot',
				'failure_message',
				'is_debug',
				'created_at',
			),
			'indexes' => array(
				'PRIMARY'     => array(
					'columns' => array( 'id' ),
					'unique'  => true,
				),
				'delivery_id' => array(
					'columns' => array( 'delivery_id' ),
					'unique'  => false,
				),
				// Column ORDER matters: the purge filters is_debug and state by
				// equality and created_at by range, so created_at must come last
				// or the index cannot serve the range.
				'retention'   => array(
					'columns' => array( 'is_debug', 'state', 'created_at' ),
					'unique'  => false,
				),
				'recipient'   => array(
					'columns' => array( 'recipient' ),
					'unique'  => false,
				),
			),
			'types'   => array( 'recipient' => 'varchar(191)' ),
		),
	);

	/**
	 * Request-memoized schema-verification result (plain static — never a
	 * persistent cache). Invalidated by flush_schema_cache().
	 *
	 * @var bool|null
	 */
	private static $schema_ok = null;

	/**
	 * Fully-prefixed name of one plugin table.
	 *
	 * @param string $name One of self::TABLES.
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'extonify_wcep_' . $name;
	}

	/**
	 * Every fully-prefixed plugin table name.
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		return array_map( array( self::class, 'table' ), self::TABLES );
	}

	/**
	 * Drop the memoized verification result.
	 *
	 * MUST be called by anything that changes the schema — migration, an
	 * uninstall drop, a test that drops a table — so a stale `true` cannot
	 * survive a drop within the same request.
	 *
	 * @return void
	 */
	public static function flush_schema_cache(): void {
		self::$schema_ok = null;
	}

	/**
	 * Whether a single table exists.
	 *
	 * @param string $table Fully-prefixed table name.
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		// esc_like: underscores in the table name are LIKE wildcards and must
		// match literally.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema existence probe; no cache layer exists for SHOW TABLES and the caller memoizes.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	/**
	 * Verify the LIVE database against self::SCHEMA.
	 *
	 * The dbDelta() helper fails silently — ADR-0009 cites exactly that as the
	 * reason the tombstone key is a fixed-length hash. "The tables exist" is
	 * therefore not evidence that the UNIQUE index protecting against duplicate
	 * sends exists. This reads the real schema back out of the database and
	 * names the first thing that is wrong.
	 *
	 * @param bool $include_version Also require the stored version to equal
	 *                              TARGET_DB_VERSION. The migration passes
	 *                              false, because it verifies the structure
	 *                              BEFORE it is allowed to stamp the version.
	 * @return true|WP_Error
	 */
	public static function verify_schema( bool $include_version = true ) {
		global $wpdb;

		foreach ( self::SCHEMA as $name => $expected ) {
			$table = self::table( $name );

			if ( ! self::table_exists( $table ) ) {
				return self::schema_error( 'missing table: ' . $table );
			}

			// --- columns, with the load-bearing types pinned -----------------
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema introspection of a plugin-owned table; {$table} is plugin-derived and SHOW COLUMNS takes no bindable parameters.
			$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );

			$present = array();
			foreach ( (array) $columns as $column ) {
				$present[ (string) $column['Field'] ] = strtolower( (string) $column['Type'] );
			}

			foreach ( $expected['columns'] as $column ) {
				if ( ! isset( $present[ $column ] ) ) {
					return self::schema_error( 'missing column: ' . $table . '.' . $column );
				}
			}
			foreach ( $expected['types'] as $column => $type ) {
				if ( isset( $present[ $column ] ) && $present[ $column ] !== $type ) {
					return self::schema_error(
						'wrong column type: ' . $table . '.' . $column . ' is ' . $present[ $column ] . ', expected ' . $type
					);
				}
			}

			// --- storage engine ------------------------------------------------
			// InnoDB is not a preference here. DeliveryRepository::delete_for_order()
			// wraps its two deletes in a transaction so a failed child delete
			// rolls back rather than orphaning detail rows — and START
			// TRANSACTION silently NO-OPS on MyISAM, which would turn that
			// guarantee into a comment.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection of a plugin-owned table.
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			if ( 0 !== strcasecmp( (string) $engine, 'InnoDB' ) ) {
				return self::schema_error(
					'wrong storage engine: ' . $table . ' is ' . ( '' === (string) $engine ? '(unknown)' : (string) $engine )
					. ', expected InnoDB (transactional cleanup depends on it)'
				);
			}

			// --- indexes: exact name, ordered columns and uniqueness -----------
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema introspection of a plugin-owned table; {$table} is plugin-derived and SHOW INDEX takes no bindable parameters.
			$rows = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );

			// Rebuild each index from Seq_in_index so column ORDER is preserved.
			$live = array();
			foreach ( (array) $rows as $row ) {
				$key      = (string) $row['Key_name'];
				$sequence = (int) $row['Seq_in_index'];

				$live[ $key ]['unique']               = ( '0' === (string) $row['Non_unique'] );
				$live[ $key ]['columns'][ $sequence ] = (string) $row['Column_name'];
			}
			foreach ( $live as $key => $definition ) {
				ksort( $live[ $key ]['columns'] );
				$live[ $key ]['columns'] = array_values( $live[ $key ]['columns'] );
			}

			// EXACT INDEX SET, not merely "the required ones are present". A
			// leftover index from a superseded schema is not harmless: dbDelta()
			// never drops one (Prompt 2a replaced two single-column indexes with
			// a composite and left the originals behind on the development
			// database), so without this an install silently diverges from what
			// a fresh install produces, and every later index change reasons
			// about a schema nobody actually has.
			$unexpected = array_diff( array_keys( $live ), array_keys( $expected['indexes'] ) );
			if ( ! empty( $unexpected ) ) {
				return self::schema_error(
					'unexpected index on ' . $table . ': ' . implode( ', ', $unexpected )
				);
			}

			foreach ( $expected['indexes'] as $index_name => $spec ) {
				if ( ! isset( $live[ $index_name ] ) ) {
					return self::schema_error( 'missing index: ' . $table . '.' . $index_name );
				}

				$actual = $live[ $index_name ];

				if ( $spec['unique'] !== $actual['unique'] ) {
					return self::schema_error(
						( $spec['unique'] ? 'missing UNIQUE on index ' : 'unexpected UNIQUE on index ' )
						. $table . '.' . $index_name
					);
				}

				// Exact ordered match — no reordering, no extra columns, no
				// missing ones.
				if ( $spec['columns'] !== $actual['columns'] ) {
					return self::schema_error(
						'wrong columns on index ' . $table . '.' . $index_name
						. ': (' . implode( ', ', $actual['columns'] ) . ')'
						. ', expected (' . implode( ', ', $spec['columns'] ) . ')'
					);
				}
			}
		}

		if ( $include_version ) {
			$installed = (int) get_option( self::OPTION_DB_VERSION, 0 );
			if ( self::TARGET_DB_VERSION !== $installed ) {
				return self::schema_error(
					'schema version is ' . $installed . ', expected ' . self::TARGET_DB_VERSION
				);
			}
		}

		return true;
	}

	/**
	 * Build a schema WP_Error carrying the specific missing object.
	 *
	 * The detail is for the logger and for tests; the user-facing notice stays
	 * generic (see Plugin::maybe_degraded_notice()).
	 *
	 * @param string $detail What is wrong.
	 * @return WP_Error
	 */
	private static function schema_error( string $detail ): WP_Error {
		return new WP_Error(
			'extonify_wcep_schema_invalid',
			__( 'The custom-email database is not in the expected state.', 'extonify-custom-emails-per-product' ),
			array( 'detail' => $detail )
		);
	}

	/**
	 * Operational check used by every consumer: the plugin behaves as inactive
	 * unless the live schema matches TARGET_DB_VERSION exactly.
	 *
	 * Version-aware on purpose. Tables that exist at an OLDER version are NOT
	 * operational: once a version 2 adds a column, runtime code compiled
	 * against v2 must not touch a v1 table just because the tables are there.
	 * Memoized per request.
	 *
	 * @param bool $force_recheck Bypass the request memo (used after migrating).
	 * @return bool
	 */
	public static function is_operational( bool $force_recheck = false ): bool {
		if ( $force_recheck ) {
			self::flush_schema_cache();
		}
		if ( null === self::$schema_ok ) {
			self::$schema_ok = ( true === self::verify_schema() );
		}
		return self::$schema_ok;
	}

	/**
	 * Run pending migrations under a short-lived lock.
	 *
	 * Idempotent; the version is bumped only after verified success; touches
	 * only this plugin's own tables. Never fatal: any Throwable degrades to
	 * no-op mode.
	 *
	 * @return true|WP_Error
	 */
	public static function migrate() {
		self::flush_schema_cache();

		// Fast path: already at target AND the live schema really matches.
		if ( true === self::verify_schema() ) {
			return true;
		}

		$token = self::acquire_lock();
		if ( null === $token ) {
			// Another request holds the lock and is migrating. Do nothing here;
			// degrade cleanly until the winner finishes (never fatal).
			return self::is_operational( true )
				? true
				: new WP_Error(
					'extonify_wcep_migration_locked',
					__( 'The custom-email database is being prepared. Please try again in a moment.', 'extonify-custom-emails-per-product' ),
					array( 'status' => 503 )
				);
		}

		try {
			return self::run_migrations();
		} catch ( \Throwable $e ) {
			// Never fatal: store only a stable code, log the exception class
			// (never the raw message), and degrade to no-op mode.
			self::record_failure( 'unexpected exception (' . get_class( $e ) . ')' );
			return self::generic_failure_error();
		} finally {
			self::release_lock( $token );
			self::flush_schema_cache();
		}
	}

	/**
	 * The actual migration steps.
	 *
	 * Re-verifies under the lock (recheck guard) so a request that lost a prior
	 * race sees the winner's work and no step runs twice.
	 *
	 * @return true|WP_Error
	 */
	private static function run_migrations() {
		self::flush_schema_cache();
		if ( true === self::verify_schema() ) {
			return true;
		}

		$result = static::migration_1_create_tables();
		if ( is_wp_error( $result ) ) {
			$data   = (array) $result->get_error_data();
			$detail = isset( $data['detail'] ) ? (string) $data['detail'] : '(no detail)';
			self::record_failure( $detail );
			return self::generic_failure_error();
		}

		// ONLY NOW is the version stamped: the structure has been read back out
		// of the live database and confirmed. A silently-skipped UNIQUE index
		// can no longer be recorded as a successful install.
		update_option( self::OPTION_DB_VERSION, self::TARGET_DB_VERSION, false );
		delete_option( self::OPTION_DB_ERROR );
		self::flush_schema_cache();
		return true;
	}

	/**
	 * Migration 1: create the three tables (ADR-0009), then prove it worked.
	 *
	 * @return true|WP_Error
	 */
	protected static function migration_1_create_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( static::schema_statements() as $sql ) {
			dbDelta( $sql );
		}
		self::flush_schema_cache();

		// Structure only: the version has deliberately NOT been stamped yet, so
		// the version arm of verify_schema() would fail on a fresh install.
		return self::verify_schema( false );
	}

	/**
	 * The dbDelta CREATE TABLE statements (ADR-0009).
	 *
	 * The dbDelta input formatting is load-bearing and mis-formatted input fails
	 * SILENTLY, so these follow its rules exactly: two spaces after
	 * `PRIMARY KEY`, `KEY` rather than `INDEX`, lowercase column types, one
	 * field per line, and a key name on every key.
	 *
	 * @return string[]
	 */
	protected static function schema_statements(): array {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$rules            = self::table( 'rules' );
		$deliveries       = self::table( 'deliveries' );
		$delivery_details = self::table( 'delivery_details' );

		$statements = array();

		$statements[] = "CREATE TABLE {$rules} (
	id bigint(20) unsigned NOT NULL auto_increment,
	name varchar(191) NOT NULL default '',
	status varchar(20) NOT NULL default 'inactive',
	priority int(11) NOT NULL default 10,
	revision int(10) unsigned NOT NULL default 1,
	trigger_type varchar(20) NOT NULL default 'status',
	trigger_value varchar(191) NOT NULL default '',
	delivery_mode varchar(20) NOT NULL default 'separate',
	native_email_id varchar(100) NOT NULL default '',
	insert_position varchar(60) NOT NULL default '',
	targeting longtext NULL,
	recipients longtext NULL,
	subject text NULL,
	heading text NULL,
	content longtext NULL,
	delay_seconds int(10) unsigned NOT NULL default 0,
	consolidation varchar(20) NOT NULL default 'none',
	stop_processing tinyint(1) NOT NULL default 0,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY status_priority (status, priority),
	KEY trigger_lookup (trigger_type, trigger_value),
	KEY insert_lookup (delivery_mode, native_email_id, status)
) {$charset_collate};";

		// ADR-0004 tombstone. Holds no direct contact or message-content fields
		// — only a minimal order-linked delivery identity. identity_hash is a
		// fixed-length sha256 hex digest so the UNIQUE index length is safe on
		// every MySQL/MariaDB configuration (ADR-0009).
		$statements[] = "CREATE TABLE {$deliveries} (
	id bigint(20) unsigned NOT NULL auto_increment,
	identity_hash char(64) NOT NULL default '',
	order_id bigint(20) unsigned NOT NULL default 0,
	rule_id bigint(20) unsigned NOT NULL default 0,
	mode varchar(20) NOT NULL default '',
	trigger_identity varchar(191) NOT NULL default '',
	first_claimed_at datetime NOT NULL default '0000-00-00 00:00:00',
	last_seen_at datetime NOT NULL default '0000-00-00 00:00:00',
	final_status varchar(20) NOT NULL default 'claimed',
	suppressed_count int(10) unsigned NOT NULL default 0,
	rule_revision_sent int(10) unsigned NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY identity_hash (identity_hash),
	KEY order_id (order_id)
) {$charset_collate};";

		// One row per RESOLVED recipient (ADR-0009, amended Prompt 2a): the
		// privacy exporter/eraser match on `recipient`, so it holds exactly one
		// normalised address and is indexed. `recipient_header` keeps the
		// original display-name form for the log and is never searched.
		//
		// `retention` is a COMPOSITE index: the purge filters on is_debug,
		// state and created_at together, and single-column indexes cannot
		// serve that.
		$statements[] = "CREATE TABLE {$delivery_details} (
	id bigint(20) unsigned NOT NULL auto_increment,
	delivery_id bigint(20) unsigned NOT NULL default 0,
	parent_attempt_id bigint(20) unsigned NULL,
	attempt int(10) unsigned NOT NULL default 1,
	type varchar(20) NOT NULL default 'auto',
	state varchar(20) NOT NULL default 'scheduled',
	reason text NULL,
	recipient varchar(191) NULL,
	recipient_type varchar(10) NULL,
	recipient_header text NULL,
	subject text NULL,
	snapshot longtext NULL,
	failure_message text NULL,
	is_debug tinyint(1) NOT NULL default 0,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY delivery_id (delivery_id),
	KEY retention (is_debug, state, created_at),
	KEY recipient (recipient)
) {$charset_collate};";

		return $statements;
	}

	/**
	 * Atomically acquire the migration lock. Returns a unique owner token on
	 * success, or null when another live holder owns it. A stale (expired)
	 * lock is recovered.
	 *
	 * Note that add_option() is the atomic primitive: wp_options.option_name
	 * is UNIQUE, so a second concurrent INSERT of the same row fails and
	 * returns false.
	 *
	 * @return string|null
	 */
	private static function acquire_lock(): ?string {
		$token   = uniqid( (string) getmypid(), true );
		$now     = time();
		$payload = array(
			'token'   => $token,
			'expires' => $now + self::LOCK_TTL,
		);

		if ( add_option( self::OPTION_LOCK, $payload, '', 'no' ) ) {
			return $token;
		}

		// Row exists — recover it only if the holder's lease has expired.
		$existing = get_option( self::OPTION_LOCK );
		if ( is_array( $existing ) && isset( $existing['expires'] ) && (int) $existing['expires'] < $now ) {
			delete_option( self::OPTION_LOCK );
			if ( add_option( self::OPTION_LOCK, $payload, '', 'no' ) ) {
				return $token;
			}
		}

		return null;
	}

	/**
	 * Release the migration lock, but only when we still own it (never delete
	 * a lock a later owner acquired after ours expired).
	 *
	 * @param string $token Owner token from acquire_lock().
	 * @return void
	 */
	private static function release_lock( string $token ): void {
		$existing = get_option( self::OPTION_LOCK );
		if ( is_array( $existing ) && isset( $existing['token'] ) && hash_equals( (string) $existing['token'], $token ) ) {
			delete_option( self::OPTION_LOCK );
		}
	}

	/**
	 * Record a migration failure: store ONLY the stable code in the
	 * (non-autoloaded) option that drives the admin notice; send the detail to
	 * the WooCommerce logger only.
	 *
	 * @param string $detail Internal detail for the logger (never surfaced).
	 * @return void
	 */
	private static function record_failure( string $detail ): void {
		update_option( self::OPTION_DB_ERROR, self::DB_ERROR_CODE, false );
		self::log_error( 'schema preparation failed: ' . $detail );
	}

	/**
	 * The generic, PII-free failure returned to callers.
	 *
	 * @return WP_Error
	 */
	private static function generic_failure_error(): WP_Error {
		return new WP_Error(
			self::DB_ERROR_CODE,
			__( 'The custom-email database could not be prepared. The plugin has safely disabled its features. See the WooCommerce logs for details.', 'extonify-custom-emails-per-product' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Upgrade check for plugin updates without reactivation. Admin-only by
	 * hook placement (admin_init) — never runs on frontend requests.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( ! self::is_operational() ) {
			self::migrate();
		}
	}

	/**
	 * Log through WooCommerce's logger when available.
	 *
	 * @param string $message Error detail.
	 * @return void
	 */
	private static function log_error( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'extonify-wcep' ) );
		}
	}
}
