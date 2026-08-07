<?php
/**
 * The delayed-delivery snapshot: what a scheduled delivery will send
 * (ADR-0007, ADR-0015 §2, §2a).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A rule state frozen at scheduling time, and NOTHING resolved from an order.
 *
 * ⚠ THE ONE INVARIANT THIS CLASS EXISTS TO HOLD (ADR-0015 §2a): **no rendered
 * personal data.** Templates, recipient DEFINITIONS and line-item ids — never a
 * name, an address, a total or an email address. That is what lets the snapshot
 * live on the DURABLE tombstone, which is never purged and never erased, without
 * making that row a place personal data hides from a subject-access request.
 *
 * Everything here is framework-free: array in, array out, plus `Domain\Json` for
 * the column. It is unit-tested against the real code rather than a shim, which
 * matters because this is the boundary between a rule as it was and a message a
 * customer will receive hours later.
 *
 * WHAT IS AND IS NOT AUTHORITATIVE AT EXECUTION (ADR-0015 §3):
 *
 *     content      -> SNAPSHOT   (subject, heading, body, as authored then)
 *     recipients   -> SNAPSHOT definitions, RESOLVED fresh
 *     placeholders -> LIVE ORDER (ADR-0014 §8 — always send-time)
 *     permission   -> LIVE RULE  (ADR-0011 §7a — re-fetched, never trusted here)
 *
 * The snapshot supplies CONTENT. It never supplies permission: a rule that has
 * been disabled since scheduling is disabled, whatever this row remembers.
 */
final class DeliverySnapshot {

	/**
	 * Snapshot format version.
	 *
	 * Stored so a reader can refuse a shape it does not understand rather than
	 * half-reading it. A queued delivery written by one version and executed
	 * after an upgrade is the ordinary case, not the exotic one — the delay is
	 * exactly the window in which an upgrade happens.
	 *
	 * ⚠ **2 SINCE ADR-0016 §8**, because the shape really did change: v2 carries
	 * `consolidation`, which `ScheduledDelivery::still_in_phase()` compares against the
	 * live rule. Bumping is the honest use of this field rather than a formality — a v1
	 * document has no such key, so `read()`'s presence check would have refused it as a
	 * HALF-WRITTEN ROW without ever saying the format had changed. Under the bump an
	 * old queued row refuses as *a version this build does not understand*,
	 * `snapshot_unreadable`, cancelled with a reason (ADR-0015 §4). Schema v1 is
	 * unreleased, so no merchant has a queued v1 delivery to lose.
	 */
	const VERSION = 2;

	/**
	 * The only delivery mode a snapshot can describe.
	 *
	 * ADR-0013 §2 refuses a non-zero delay on an insert rule at the repository
	 * boundary, so `insert + delay > 0` cannot be stored at all and a snapshot
	 * claiming it describes a delivery this plugin cannot perform. Held as a
	 * literal rather than imported from `Delivery\DeliveryLogger::MODE_SEPARATE`
	 * because this class is framework-free by design and `Domain` does not depend
	 * on `Delivery`; a unit test asserts the two agree.
	 */
	const MODE = 'separate';

	/**
	 * Every key a stored snapshot carries.
	 *
	 * ⚠ AN ALLOWLIST, APPLIED ON BOTH SIDES. Writing walks it so a caller cannot
	 * smuggle a resolved address in by adding a key; reading walks it so a stored
	 * row cannot introduce one. The invariant in the class docblock is only worth
	 * as much as the mechanism that enforces it, and this is the mechanism.
	 */
	const FIELDS = array(
		'version',
		'revision',
		'subject',
		'heading',
		'content',
		'recipients',
		'matched_items',
		'mode',
		'consolidation',
		'trigger_identity',
		'delay_seconds',
		'scheduled_for',
	);

	/**
	 * The consolidation a v1 document could only have meant.
	 *
	 * Held as a literal for the reason self::MODE is: this class is framework-free by
	 * design and `Domain` does not depend on `Delivery`. A unit test asserts it agrees
	 * with `Delivery\Consolidation::NONE`.
	 */
	const CONSOLIDATION_NONE = 'none';

	/**
	 * Every consolidation a snapshot may describe (ADR-0016 §1).
	 *
	 * The same closed vocabulary as `Delivery\Consolidation::MODES`, asserted equal by
	 * a unit test rather than imported, for the reason above.
	 */
	const CONSOLIDATIONS = array( self::CONSOLIDATION_NONE, 'per_product' );

	/**
	 * Build a snapshot from the rule row the matcher decided on.
	 *
	 * ⚠ THE ROW MUST BE THE ONE THE MATCHER USED, not a re-read (ADR-0012 §10).
	 * A second fetch could return a row edited between the decision and the
	 * scheduling, so the delivery would be queued with content the matcher never
	 * evaluated.
	 *
	 * @param array  $rule             Rule row.
	 * @param array  $matched_items    Matched item records from the decision.
	 * @param string $trigger_identity ADR-0004 trigger identity.
	 * @param int    $scheduled_for    UTC timestamp the job is queued for.
	 * @return array
	 */
	public static function create( array $rule, array $matched_items, string $trigger_identity, int $scheduled_for ): array {
		return array(
			'version'          => self::VERSION,
			'revision'         => (int) ( $rule['revision'] ?? 0 ),
			'subject'          => (string) ( $rule['subject'] ?? '' ),
			'heading'          => (string) ( $rule['heading'] ?? '' ),
			'content'          => (string) ( $rule['content'] ?? '' ),
			// The DEFINITIONS exactly as stored — tokens and literals. Resolution
			// happens at execution, against the order as it is then.
			'recipients'       => self::recipients_of( $rule ),
			'matched_items'    => self::item_ids( $matched_items ),
			'mode'             => (string) ( $rule['delivery_mode'] ?? '' ),

			/*
			 * ⚠ STORED SO THE PHASE CHECK HAS SOMETHING TO COMPARE (ADR-0016 §8). A
			 * merchant who switches a delayed rule between `none` and `per_product`
			 * during the delay has changed HOW MANY MESSAGES the queued delivery would
			 * send, which is the same class of change as re-timing it — so
			 * `ScheduledDelivery::still_in_phase()` requires the live value to equal
			 * this one, and a mismatch is `rule_left_phase`.
			 *
			 * It is a RULE SETTING, like `mode` and `delay_seconds`, so it does not
			 * touch the §2a invariant: no rendered personal data on the durable,
			 * never-erased row.
			 */
			'consolidation'    => (string) ( $rule['consolidation'] ?? self::CONSOLIDATION_NONE ),
			'trigger_identity' => $trigger_identity,
			'delay_seconds'    => (int) ( $rule['delay_seconds'] ?? 0 ),
			'scheduled_for'    => $scheduled_for,
		);
	}

	/**
	 * The recipients document from a rule row, decoded shape preserved.
	 *
	 * `RuleRepository` hydrates `recipients` as an array and carries the raw
	 * string beside it. The DECODED form is taken here deliberately: this is a
	 * re-encode into a different column, not a validity decision, and
	 * `RecipientResolver` will apply the same strictness at execution that it
	 * applies to an immediate delivery.
	 *
	 * @param array $rule Rule row.
	 * @return array
	 */
	private static function recipients_of( array $rule ): array {
		$recipients = $rule['recipients'] ?? array();

		return is_array( $recipients ) ? $recipients : array();
	}

	/**
	 * Line-item ids from matched item records.
	 *
	 * IDS ONLY, NOT THE RECORDS. A matched record carries product and variation
	 * ids that the live order can re-derive, and storing fewer facts is storing
	 * fewer facts that can go stale.
	 *
	 * @param array[] $matched_items Matched item records.
	 * @return int[]
	 */
	private static function item_ids( array $matched_items ): array {
		$ids = array();

		foreach ( $matched_items as $item ) {
			$id = (int) ( ( is_array( $item ) ? ( $item['item_id'] ?? 0 ) : $item ) );

			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Encode a snapshot for the `snapshot` column.
	 *
	 * @param array $snapshot Snapshot.
	 * @return string
	 */
	public static function encode( array $snapshot ): string {
		$clean = array();

		foreach ( self::FIELDS as $field ) {
			if ( array_key_exists( $field, $snapshot ) ) {
				$clean[ $field ] = $snapshot[ $field ];
			}
		}

		return Json::encode( $clean );
	}

	/**
	 * Read a stored snapshot, or null when it is unusable.
	 *
	 * ⚠ NULL RATHER THAN A PARTIAL SNAPSHOT, AND THAT IS FAIL-CLOSED. A delivery
	 * whose snapshot cannot be read must not send *something*: the content is the
	 * whole point of the snapshot, and a message assembled from defaults is a
	 * message the merchant never wrote. The caller cancels with a reason instead.
	 *
	 * ⚠ VERSION 1 IS VALIDATED STRICTLY, NOT COERCED (Prompt 7A). This method used
	 * to accept a v1 document with fields MISSING and default them — a `null`
	 * subject became `''`, an absent `recipients` became `array()`, a garbled
	 * `matched_items` became no items at all. A partially-written row would then
	 * have rendered and sent an email the merchant never authored, addressed to
	 * nobody, about nothing. Every field must now be present, of the right type,
	 * and satisfy its own invariant; anything else is `snapshot_unreadable` and the
	 * caller cancels with a reason. **`null` is fail-closed, and half a snapshot is
	 * not half a message — it is a different message.**
	 *
	 * @param mixed $stored Raw column value, or an already-decoded array.
	 * @return array|null
	 */
	public static function read( $stored ): ?array {
		$decoded = is_array( $stored ) ? $stored : Json::decode( (string) $stored );

		if ( array() === $decoded || ! isset( $decoded['version'] ) ) {
			return null;
		}

		if ( self::VERSION !== $decoded['version'] || ! is_int( $decoded['version'] ) ) {
			// A shape from another version of this plugin, or a version that is not
			// even an integer. Refusing is the only honest answer: half-reading it
			// would send a message assembled from fields that may mean something
			// else now.
			return null;
		}

		foreach ( self::FIELDS as $field ) {
			// PRESENCE FIRST, AND array_key_exists RATHER THAN isset: a field
			// explicitly stored as null is a field that was written wrong, not one
			// that is absent, and both must be refused rather than defaulted.
			if ( ! array_key_exists( $field, $decoded ) || null === $decoded[ $field ] ) {
				return null;
			}
		}

		if ( ! is_int( $decoded['revision'] ) || $decoded['revision'] < 0 ) {
			// Non-negative: `rule_revision_sent` is an unsigned column, and a
			// negative value behaves differently by SQL mode.
			return null;
		}

		foreach ( array( 'subject', 'heading', 'content', 'mode', 'trigger_identity' ) as $text_field ) {
			if ( ! is_string( $decoded[ $text_field ] ) ) {
				return null;
			}
		}

		if ( ! is_array( $decoded['recipients'] ) ) {
			return null;
		}

		$items = self::valid_item_ids( $decoded['matched_items'] );

		if ( null === $items ) {
			// Positive, unique integers. A zero or negative line-item id cannot
			// match anything on the order, and a duplicate would scope a
			// placeholder to the same item twice.
			return null;
		}

		if ( self::MODE !== $decoded['mode'] ) {
			// ⚠ EXACTLY `separate`. ADR-0013 §2 refuses a non-zero delay on an
			// insert rule at the repository boundary, so a snapshot claiming any
			// other mode describes a delivery this plugin cannot perform.
			return null;
		}

		if ( ! in_array( $decoded['consolidation'], self::CONSOLIDATIONS, true ) ) {
			/*
			 * ⚠ THE CLOSED VOCABULARY, REFUSED NOT DEFAULTED (ADR-0016 §1, §8). This is
			 * the value the phase check compares the live rule against, so defaulting it
			 * to `none` would make a `per_product` delivery whose column had been
			 * corrupted look as though the merchant had asked for one message — and it
			 * would pass the equality check against a live `none` rule, sending the wrong
			 * SHAPE of delivery rather than refusing. Half a snapshot is not half a
			 * message; it is a different message.
			 */
			return null;
		}

		if ( ! DeliveryIdentity::is_valid_trigger_identity( $decoded['trigger_identity'] ) ) {
			// ADR-0004: the identity the executing job claims and sends under. An
			// unrecognised form could never be matched back to its tombstone.
			return null;
		}

		if ( ! is_int( $decoded['delay_seconds'] ) || $decoded['delay_seconds'] <= 0 ) {
			// A delayed delivery with no delay is a contradiction — it would belong
			// to the immediate phase, which never writes a snapshot (ADR-0015 §7).
			return null;
		}

		if ( ! is_int( $decoded['scheduled_for'] ) || $decoded['scheduled_for'] <= 0 ) {
			// A positive UTC timestamp. Zero is what an unset field casts to, which
			// is exactly the value this validation exists to stop accepting.
			return null;
		}

		$snapshot = array();

		foreach ( self::FIELDS as $field ) {
			$snapshot[ $field ] = $decoded[ $field ];
		}

		$snapshot['matched_items'] = $items;

		return $snapshot;
	}

	/**
	 * Line-item ids from a stored `matched_items`, or null when the shape is bad.
	 *
	 * DISTINCT FROM self::item_ids(), WHICH IS A WRITE-SIDE NORMALISER. That one
	 * takes matcher records and extracts what it can, because the caller is this
	 * plugin and the shape is known. This one is reading a row that may have been
	 * written by a failed request, and its job is to REFUSE rather than to salvage:
	 * an empty result would send an email scoped to no products at all.
	 *
	 * @param mixed $stored Stored `matched_items` value.
	 * @return int[]|null Null when the value is not a list of positive unique ints.
	 */
	private static function valid_item_ids( $stored ): ?array {
		if ( ! is_array( $stored ) || array() === $stored ) {
			return null;
		}

		$ids = array();

		foreach ( $stored as $id ) {
			if ( ! is_int( $id ) || $id <= 0 || in_array( $id, $ids, true ) ) {
				return null;
			}

			$ids[] = $id;
		}

		return $ids;
	}

	/**
	 * A rule-row-shaped array carrying the snapshotted CONTENT.
	 *
	 * So the scheduled path can hand `Orchestrator::attempt()` the same shape an
	 * immediate delivery hands it, without that method needing to know which of
	 * the two produced it. ⚠ The `id` and `delivery_mode` come from the LIVE rule
	 * the caller re-fetched, never from here — this supplies content, not
	 * permission (ADR-0015 §3).
	 *
	 * ⚠ `consolidation` IS CARRIED, AND IT IS THE SAME VALUE EITHER WAY (ADR-0016 §8).
	 * `ScheduledDelivery::still_in_phase()` runs BEFORE this and requires the live rule
	 * to equal the snapshot, so there is exactly one place the question is decided and
	 * this row cannot disagree with the rule. Omitting it would be worse than either
	 * source: the delivery would fan out as `none` however the merchant configured it,
	 * silently sending one message where they asked for one per product.
	 *
	 * @param array $snapshot Snapshot from self::read().
	 * @param int   $rule_id  Live rule id.
	 * @return array
	 */
	public static function as_rule_row( array $snapshot, int $rule_id ): array {
		return array(
			'id'            => $rule_id,
			'revision'      => (int) ( $snapshot['revision'] ?? 0 ),
			'subject'       => (string) ( $snapshot['subject'] ?? '' ),
			'heading'       => (string) ( $snapshot['heading'] ?? '' ),
			'content'       => (string) ( $snapshot['content'] ?? '' ),
			'recipients'    => (array) ( $snapshot['recipients'] ?? array() ),
			'delivery_mode' => (string) ( $snapshot['mode'] ?? '' ),
			'consolidation' => (string) ( $snapshot['consolidation'] ?? self::CONSOLIDATION_NONE ),
		);
	}

	/**
	 * Field names in a snapshot that self::FIELDS does not allow.
	 *
	 * THE ALLOWLIST, AS AN ASSERTION RATHER THAN A PROMISE. ADR-0015 §2a's
	 * invariant — no rendered personal data on the durable, never-erased row — is
	 * worth exactly as much as the mechanism enforcing it, and this is how a test
	 * can see that mechanism working rather than take its word.
	 *
	 * ⚠ IT CHECKS THE KEYS, NOT THE VALUES. Nothing can inspect a string and tell
	 * a template from a rendered one; what it CAN do is prove that no field
	 * outside the reviewed list is present, which is the property the invariant
	 * actually rests on. Judging a new field's contents stays a human job, and
	 * this is what makes a new field impossible to add without one.
	 *
	 * @param array $snapshot Snapshot.
	 * @return string[] Keys outside self::FIELDS. Empty when the shape is clean.
	 */
	public static function unexpected_fields( array $snapshot ): array {
		$unexpected = array();

		foreach ( array_keys( $snapshot ) as $field ) {
			if ( ! in_array( (string) $field, self::FIELDS, true ) ) {
				$unexpected[] = (string) $field;
			}
		}

		return $unexpected;
	}
}
