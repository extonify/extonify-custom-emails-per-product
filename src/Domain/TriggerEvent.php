<?php
/**
 * The event a rule set is evaluated against (ADR-0004, ADR-0011).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Trigger type, trigger value, and the ADR-0004 trigger identity.
 *
 * Immutable and free of WordPress, so identity construction is unit testable.
 * The identity STRING is built to ADR-0004's grammar and validated with
 * `DeliveryIdentity::is_valid_trigger_identity()`, so the matcher and the
 * tombstone can never disagree about what an identity looks like.
 *
 * STATUS SLUG NORMALISATION LIVES HERE AND NOWHERE ELSE (ADR-0011 §1): trim,
 * collapse whitespace, ASCII-range lowercase, then strip ONE leading `wc-`.
 * Every fetch, comparison and identity string uses the normalised form.
 */
final class TriggerEvent {

	/**
	 * Order entered a status.
	 */
	const TYPE_STATUS = 'status';

	/**
	 * Order moved from one status to another.
	 */
	const TYPE_TRANSITION = 'transition';

	/**
	 * A full or partial refund was recorded.
	 */
	const TYPE_REFUND = 'refund';

	/**
	 * Recognised trigger types (matches RuleRepository::TRIGGER_TYPES).
	 */
	const TYPES = array( self::TYPE_STATUS, self::TYPE_TRANSITION, self::TYPE_REFUND );

	/**
	 * The block-checkout draft status.
	 *
	 * Excluded EXPLICITLY, not by omission. Verified on WC 10.9.4:
	 * `Blocks\Domain\Services\DraftOrders` registers `wc-checkout-draft` through
	 * the `wc_order_statuses` filter, so `wc_get_order_statuses()` DOES return
	 * it and status discovery alone would happily make draft orders send
	 * customer email (ADR-0011 §1).
	 */
	const CHECKOUT_DRAFT = 'checkout-draft';

	/**
	 * The `wc-` prefix WooCommerce stores order statuses with.
	 */
	const STATUS_PREFIX = 'wc-';

	/**
	 * Trigger type, one of self::TYPES.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Status the order moved FROM (transition triggers only).
	 *
	 * @var string
	 */
	private $from;

	/**
	 * Status the order moved TO. Empty for refunds.
	 *
	 * @var string
	 */
	private $to;

	/**
	 * Refund id (refund triggers only).
	 *
	 * @var int
	 */
	private $refund_id;

	/**
	 * Use the named constructors.
	 *
	 * @param string $type      Trigger type.
	 * @param string $from      Normalised source status.
	 * @param string $to        Normalised destination status.
	 * @param int    $refund_id Refund id.
	 */
	private function __construct( string $type, string $from, string $to, int $refund_id ) {
		$this->type      = $type;
		$this->from      = $from;
		$this->to        = $to;
		$this->refund_id = $refund_id;
	}

	/**
	 * An order entering a status.
	 *
	 * @param string $to Destination status, with or without the `wc-` prefix.
	 * @return TriggerEvent
	 */
	public static function status( string $to ): TriggerEvent {
		return new self( self::TYPE_STATUS, '', self::normalize_status( $to ), 0 );
	}

	/**
	 * An order moving between two statuses.
	 *
	 * @param string $from Source status, with or without the `wc-` prefix.
	 * @param string $to   Destination status, with or without the prefix.
	 * @return TriggerEvent
	 */
	public static function transition( string $from, string $to ): TriggerEvent {
		return new self( self::TYPE_TRANSITION, self::normalize_status( $from ), self::normalize_status( $to ), 0 );
	}

	/**
	 * A refund on an order.
	 *
	 * @param int $refund_id WooCommerce refund object id.
	 * @return TriggerEvent
	 */
	public static function refund( int $refund_id ): TriggerEvent {
		return new self( self::TYPE_REFUND, '', '', $refund_id );
	}

	/**
	 * Normalise an order status slug (ADR-0011 §1).
	 *
	 * Trims, collapses internal whitespace and ASCII-lowercases through
	 * `DeliveryIdentity::normalize()` — the same locale- and mbstring-free
	 * folding the identity hash uses, so matching and hashing agree by
	 * construction — then strips ONE leading `wc-`.
	 *
	 * @param string $status Raw status slug.
	 * @return string
	 */
	public static function normalize_status( string $status ): string {
		$status = DeliveryIdentity::normalize( $status );

		if ( 0 === strpos( $status, self::STATUS_PREFIX ) ) {
			$status = substr( $status, strlen( self::STATUS_PREFIX ) );
		}

		return $status;
	}

	/**
	 * Whether a raw status value is a usable order-status SLUG.
	 *
	 * A VALIDATOR, NOT A SECOND NORMALISER: it normalises through
	 * self::normalize_status() and then asks whether the result is a slug at
	 * all. Storage uses it to refuse a `trigger_value` that would be
	 * permanently dead — an empty or whitespace-only status can never be
	 * produced by a real event, so such a rule never fires and never says why
	 * (ADR-0011 §2).
	 *
	 * THE ALPHABET IS `sanitize_key()`'s OUTPUT ALPHABET, spelled out rather
	 * than called, because this class is deliberately free of WordPress so
	 * identity construction stays unit testable. The two are asserted to agree
	 * against the real function in the integration suite, so the copy cannot
	 * drift unnoticed.
	 *
	 * Deliberately STRICTER than `DeliveryIdentity::is_valid_trigger_identity()`,
	 * which permits spaces and dots in the value part: that grammar exists to
	 * reject invented identity FORMATS, while this rejects values that are not
	 * order statuses.
	 *
	 * @param string $status Raw status value.
	 * @return bool
	 */
	public static function is_valid_status_slug( string $status ): bool {
		$normalized = self::normalize_status( $status );

		// `\z`, not `$`: `$` also matches immediately before a trailing newline,
		// so `"completed\n"` would pass. Normalisation trims, so that cannot
		// arrive today — this makes the predicate independent of that.
		return '' !== $normalized && 1 === preg_match( '/^[a-z0-9_-]+\z/', $normalized );
	}

	/**
	 * Trigger type.
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * The value stored in `rules.trigger_value` for rules in this family
	 * (ADR-0011 §2): the destination slug for a status trigger, `from>to` for a
	 * transition, and the empty string for a refund.
	 *
	 * @return string
	 */
	public function value(): string {
		if ( self::TYPE_TRANSITION === $this->type ) {
			return $this->from . '>' . $this->to;
		}
		if ( self::TYPE_STATUS === $this->type ) {
			return $this->to;
		}
		return '';
	}

	/**
	 * The ADR-0004 trigger identity.
	 *
	 * @return string
	 */
	public function identity(): string {
		if ( self::TYPE_REFUND === $this->type ) {
			return self::TYPE_REFUND . ':' . $this->refund_id;
		}
		return $this->type . ':' . $this->value();
	}

	/**
	 * Status the order moved from. Empty unless this is a transition.
	 *
	 * @return string
	 */
	public function from_status(): string {
		return $this->from;
	}

	/**
	 * The destination status — the one that GOVERNS whether this event fires
	 * at all (ADR-0011 §1). Empty for a refund.
	 *
	 * @return string
	 */
	public function destination_status(): string {
		return $this->to;
	}

	/**
	 * Refund id, or 0.
	 *
	 * @return int
	 */
	public function refund_id(): int {
		return $this->refund_id;
	}

	/**
	 * Whether this event is status-shaped (status or transition).
	 *
	 * @return bool
	 */
	public function is_status_event(): bool {
		return self::TYPE_STATUS === $this->type || self::TYPE_TRANSITION === $this->type;
	}

	/**
	 * Whether the identity satisfies ADR-0004's grammar and fits the column.
	 *
	 * An identity that a later call cannot reproduce must never reach the
	 * tombstone: duplicate prevention would silently stop working for that
	 * delivery (ADR-0009 §Claim input validation).
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		if ( ! in_array( $this->type, self::TYPES, true ) ) {
			return false;
		}

		/*
		 * STRICTER THAN THE RAW GRAMMAR, DELIBERATELY. ADR-0004's identity
		 * pattern is permissive about the value part — `refund:0` and
		 * `transition:pending>` both satisfy it — because it exists to reject
		 * invented FORMATS, not to know what each type means. This class does
		 * know, so it refuses identities that are grammatically fine and
		 * semantically empty before they can reach the tombstone.
		 */
		if ( self::TYPE_REFUND === $this->type && $this->refund_id < 1 ) {
			return false;
		}
		if ( self::TYPE_STATUS === $this->type && '' === $this->to ) {
			return false;
		}
		if ( self::TYPE_TRANSITION === $this->type && ( '' === $this->from || '' === $this->to ) ) {
			return false;
		}

		return DeliveryIdentity::is_valid_trigger_identity( $this->identity() );
	}

	/**
	 * Whether the DESTINATION is the block-checkout draft status.
	 *
	 * `status:checkout-draft` and `transition:{any}>checkout-draft` never fire.
	 * `transition:checkout-draft>pending` DOES: the order has left the draft
	 * state, which is a genuine lifecycle event and the only way to target the
	 * moment a block-checkout order becomes real (ADR-0011 §1).
	 *
	 * @return bool
	 */
	public function is_draft_destination(): bool {
		return self::CHECKOUT_DRAFT === $this->to;
	}

	/**
	 * Whether this event may fire at all, ignoring status discovery (which
	 * needs WooCommerce and is applied by `Matching\OrderStatuses`).
	 *
	 * @return bool
	 */
	public function is_triggerable(): bool {
		return $this->is_valid() && ! $this->is_draft_destination();
	}
}
