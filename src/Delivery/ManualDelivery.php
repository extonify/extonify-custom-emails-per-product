<?php
/**
 * The four manual delivery actions (ADR-0019).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

use Extonify\WCEP\Domain\DeliveryIdentity;
use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Plugin;
use Extonify\WCEP\Repository\DeliveryRepository;
use Extonify\WCEP\Repository\RuleRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Resend, send now, cancel and send-manually — the decisions, not the screens.
 *
 * ⚠ EVERY ENTRY POINT REFUSES BEFORE IT WRITES (ADR-0019 §4). The refusal codes are a
 * closed vocabulary and each has its own sentence, because "could not send" throws
 * away the only thing the merchant needs to act on. Nothing here partially executes:
 * a refusal sends no mail and writes no row.
 *
 * ⚠ AND NOTHING HERE IMPLEMENTS A SEND. Every path ends in `Orchestrator::send_manual()`
 * or `ScheduledDelivery::run()`, which is what keeps the manual and automatic paths
 * from diverging (ADR-0019 §7, gate 39). This class decides WHETHER, never HOW.
 */
final class ManualDelivery {

	/**
	 * The four actions.
	 */
	const ACTION_RESEND   = 'resend';
	const ACTION_SEND_NOW = 'send_now';
	const ACTION_CANCEL   = 'cancel';
	const ACTION_MANUAL   = 'send_manual';

	/**
	 * Refusal codes (ADR-0019 §4). A closed vocabulary, each with its own sentence.
	 */
	const REFUSED_SCHEMA           = 'schema_unavailable';
	const REFUSED_GLOBALLY_OFF     = 'globally_disabled';
	const REFUSED_ORDER_MISSING    = 'order_missing';
	const REFUSED_RULE_DELETED     = 'rule_deleted';
	const REFUSED_RULE_INSERT_MODE = 'rule_insert_mode';
	const REFUSED_RULE_VOCABULARY  = 'rule_vocabulary';
	const REFUSED_NOT_TERMINAL     = 'not_terminal';
	const REFUSED_NOT_SCHEDULED    = 'not_scheduled';
	const REFUSED_ALREADY_EXISTS   = 'already_delivered';
	const REFUSED_DELIVERY_MISSING = 'delivery_missing';
	const REFUSED_NO_ITEMS         = 'no_matching_items';
	const REFUSED_LOST_RACE        = 'lost_race';
	const REFUSED_WRITE_FAILED     = 'write_failed';
	const REFUSED_EMAIL_MISSING    = 'email_unavailable';

	/**
	 * Outcome codes.
	 */
	const OK      = 'ok';
	const REFUSED = 'refused';

	/**
	 * Resend a delivery that already reached a terminal state (ADR-0019 §3).
	 *
	 * ⚠ IT RENDERS FROM THE **CURRENT** RULE. The common reason to resend is that the
	 * merchant corrected something, and a resend that faithfully reproduced the
	 * original mistake would be useless. `rule_revision_sent` records which revision
	 * produced each attempt, so the history stays honest about what was actually sent
	 * when. A DELETED rule is refused rather than approximated: ADR-0015 §2 releases
	 * the snapshot at the terminal state, deliberately, so there is genuinely nothing
	 * left to render.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return array{outcome:string, code:string, delivery_id:int}
	 */
	public static function resend( int $delivery_id ): array {
		$context = self::context_for_delivery( $delivery_id );

		if ( self::OK !== $context['outcome'] ) {
			return $context;
		}

		$tombstone = $context['tombstone'];

		if ( in_array( (string) $tombstone['final_status'], DeliveryRepository::IN_FLIGHT_STATUSES, true ) ) {
			// R4. Still in flight: resending would race the actor that owns it.
			return self::refuse( self::REFUSED_NOT_TERMINAL, $delivery_id );
		}

		return self::execute( $context, $delivery_id, (string) $tombstone['trigger_identity'] );
	}

	/**
	 * Send a scheduled delivery ahead of its delay (ADR-0019 §7).
	 *
	 * ⚠ IT DOES NOT RE-IMPLEMENT EXECUTION. The queued job is removed and
	 * `ScheduledDelivery::run()` is called, which takes the lease itself, re-validates
	 * exactly as the scheduler would, sends inside the same containment boundary and
	 * writes the same terminal state.
	 *
	 * ⚠ UNSCHEDULING FIRST IS SAFE **BECAUSE THE LEASE IS THE GUARD**. A job that
	 * survives a failed unschedule fires later, fails to take the lease from a terminal
	 * row, and exits without sending. The reverse order would be no safer and would put
	 * a window between the transition and the unschedule.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return array{outcome:string, code:string, delivery_id:int, status:string}
	 */
	public static function send_now( int $delivery_id ): array {
		$context = self::context_for_delivery( $delivery_id );

		if ( self::OK !== $context['outcome'] ) {
			return $context;
		}

		$tombstone = $context['tombstone'];

		if ( DeliveryRepository::SCHEDULED !== (string) $tombstone['final_status'] ) {
			// R5. Another actor already owns it, or it is already finished.
			return self::refuse( self::REFUSED_NOT_SCHEDULED, $delivery_id );
		}

		$order_id = (int) $tombstone['order_id'];

		ScheduledDelivery::unschedule( $delivery_id, $order_id );

		ScheduledDelivery::run( $delivery_id, $order_id );

		/*
		 * ⚠ THE OUTCOME IS READ BACK, NEVER ASSUMED. `run()` returns void and contains
		 * every failure by design, so the only truthful way to report to the merchant is
		 * to ask the tombstone what happened. A row still `scheduled` or `executing` means
		 * the lease was not granted — another worker owns it, or it was re-queued — and
		 * that is reported as a lost race rather than as a send.
		 */
		$after  = Plugin::instance()->deliveries()->find_by_id( $delivery_id );
		$status = null === $after ? '' : (string) $after['final_status'];

		if ( '' === $status || in_array( $status, DeliveryRepository::IN_FLIGHT_STATUSES, true ) ) {
			return array(
				'outcome'     => self::REFUSED,
				'code'        => self::REFUSED_LOST_RACE,
				'delivery_id' => $delivery_id,
				'status'      => $status,
			);
		}

		return array(
			'outcome'     => self::OK,
			'code'        => $status,
			'delivery_id' => $delivery_id,
			'status'      => $status,
		);
	}

	/**
	 * Cancel a scheduled delivery (ADR-0019 §1, ADR-0015 §8.1).
	 *
	 * ⚠ TRANSITION FIRST, UNSCHEDULE SECOND, AND ONLY IF THE TRANSITION CHANGED A ROW
	 * — the Prompt 7B Group A ordering. Unscheduling first would remove the job of a
	 * delivery this call then failed to cancel, leaving it `scheduled` with nothing to
	 * run it. A `query_failed` result is neither success nor a lost race: the job stays
	 * queued, the row is untouched, and success is not reported.
	 *
	 * ⚠ CANCELLING PERMANENTLY CONSUMES THE IDENTITY (ADR-0015 §1a), so the rule cannot
	 * fire again for this order and trigger. The confirmation says so before the
	 * merchant commits.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return array{outcome:string, code:string, delivery_id:int}
	 */
	public static function cancel( int $delivery_id ): array {
		if ( ! Migrator::is_operational() ) {
			return self::refuse( self::REFUSED_SCHEMA, $delivery_id );
		}

		$tombstone = Plugin::instance()->deliveries()->find_by_id( $delivery_id );

		if ( null === $tombstone ) {
			return self::refuse( self::REFUSED_DELIVERY_MISSING, $delivery_id );
		}

		if ( DeliveryRepository::SCHEDULED !== (string) $tombstone['final_status'] ) {
			return self::refuse( self::REFUSED_NOT_SCHEDULED, $delivery_id );
		}

		$result = ScheduledDelivery::cancel(
			$delivery_id,
			ScheduledDelivery::REASON_MERCHANT_CANCELLED,
			DeliveryRepository::SCHEDULED,
			'manual'
		);

		$write = $result['transition'] ?? null;

		if ( ! ( $write instanceof \Extonify\WCEP\Domain\WriteResult ) || ! $write->won() ) {
			/*
			 * ⚠ THE JOB IS DELIBERATELY LEFT QUEUED. This call did not cancel anything —
			 * either somebody else got there first, or the write failed and NOBODY owns
			 * this delivery. Removing the job in the second case would strand a
			 * `scheduled` row with nothing able to run it, which is the exact shape
			 * ADR-0015 §8.3's sweep exists to recover from and which this must not create.
			 */
			return self::refuse(
				( $write instanceof \Extonify\WCEP\Domain\WriteResult && $write->is_shortfall() )
					? self::REFUSED_WRITE_FAILED
					: self::REFUSED_LOST_RACE,
				$delivery_id
			);
		}

		ScheduledDelivery::unschedule( $delivery_id, (int) $tombstone['order_id'] );

		return array(
			'outcome'     => self::OK,
			'code'        => self::ACTION_CANCEL,
			'delivery_id' => $delivery_id,
		);
	}

	/**
	 * Send a rule manually for an order it has never fired on (ADR-0019 §2).
	 *
	 * ⚠ THE TOKEN IS THE IDENTITY'S UNIQUIFIER, AND THAT IS THE SINGLE-EXECUTION
	 * MECHANISM. A replayed submission carries the same token, hashes to the same
	 * `identity_hash`, and `claim()` answers SUPPRESSED off the UNIQUE index — the same
	 * single statement that has prevented duplicate automatic sends since ADR-0004. A
	 * merchant who deliberately confirms again gets a fresh token and a genuinely
	 * separate delivery, which is correct: they asked twice.
	 *
	 * ⚠ THE GLOBAL SWITCH AND THE SCHEMA ARE CHECKED BEFORE THE CLAIM, exactly as
	 * `Orchestrator::deliver()` does (ADR-0012 §5), so a refused action leaves no
	 * tombstone behind and the rule can still fire normally later.
	 *
	 * @param int    $order_id Order id.
	 * @param int    $rule_id  Rule id.
	 * @param string $token    The consumed confirmation token.
	 * @return array{outcome:string, code:string, delivery_id:int}
	 */
	public static function send_manual( int $order_id, int $rule_id, string $token ): array {
		if ( ! Migrator::is_operational() ) {
			return self::refuse( self::REFUSED_SCHEMA, 0 );
		}

		$rule = Plugin::instance()->rules()->find( $rule_id );

		$refusal = self::rule_refusal( $rule );

		if ( null !== $refusal ) {
			return self::refuse( $refusal, 0 );
		}

		$order = self::load_order( $order_id );

		if ( null === $order ) {
			return self::refuse( self::REFUSED_ORDER_MISSING, 0 );
		}

		$orchestrator = new Orchestrator();

		if ( ! $orchestrator->manual_send_is_available() ) {
			// R6/R9, before anything is claimed.
			return self::refuse( self::REFUSED_EMAIL_MISSING, 0 );
		}

		$items = self::matched_items( $order, (array) $rule );

		if ( array() === $items ) {
			// Nothing on this order matches the rule's targeting, so there is no
			// message to compose. Refused rather than sent empty.
			return self::refuse( self::REFUSED_NO_ITEMS, 0 );
		}

		$identity = DeliveryIdentity::manual( $token );

		$claim = Plugin::instance()->deliveries()->claim(
			$order_id,
			$rule_id,
			DeliveryLogger::MODE,
			$identity,
			(int) ( $rule['revision'] ?? 0 )
		);

		if ( DeliveryRepository::CLAIMED !== $claim['result'] ) {
			/*
			 * SUPPRESSED means this exact confirmation has already been executed — a
			 * replay. FAILED means the claim statement itself did not work. Neither may
			 * send, and both are reported honestly rather than as a success.
			 */
			return self::refuse(
				DeliveryRepository::SUPPRESSED === $claim['result'] ? self::REFUSED_ALREADY_EXISTS : self::REFUSED_WRITE_FAILED,
				(int) $claim['delivery_id']
			);
		}

		$delivery_id = (int) $claim['delivery_id'];

		$orchestrator->send_manual( $order, (array) $rule, $items, $delivery_id, $identity, 'manual' );

		return array(
			'outcome'     => self::OK,
			'code'        => self::ACTION_MANUAL,
			'delivery_id' => $delivery_id,
		);
	}

	/**
	 * Everything a resend or a send-now needs, or the refusal that stops it.
	 *
	 * @param int $delivery_id Tombstone id.
	 * @return array
	 */
	private static function context_for_delivery( int $delivery_id ): array {
		if ( ! Migrator::is_operational() ) {
			return self::refuse( self::REFUSED_SCHEMA, $delivery_id );
		}

		$tombstone = Plugin::instance()->deliveries()->find_by_id( $delivery_id );

		if ( null === $tombstone ) {
			return self::refuse( self::REFUSED_DELIVERY_MISSING, $delivery_id );
		}

		$rule = Plugin::instance()->rules()->find( (int) $tombstone['rule_id'] );

		$refusal = self::rule_refusal( $rule );

		if ( null !== $refusal ) {
			return self::refuse( $refusal, $delivery_id );
		}

		$order = self::load_order( (int) $tombstone['order_id'] );

		if ( null === $order ) {
			return self::refuse( self::REFUSED_ORDER_MISSING, $delivery_id );
		}

		return array(
			'outcome'     => self::OK,
			'code'        => '',
			'delivery_id' => $delivery_id,
			'tombstone'   => $tombstone,
			'rule'        => (array) $rule,
			'order'       => $order,
		);
	}

	/**
	 * Resolve, render and send one already-claimed delivery.
	 *
	 * @param array  $context  Result of self::context_for_delivery().
	 * @param int    $delivery_id Tombstone id.
	 * @param string $identity Trigger identity the tombstone carries.
	 * @return array
	 */
	private static function execute( array $context, int $delivery_id, string $identity ): array {
		$order = $context['order'];
		$rule  = $context['rule'];

		$orchestrator = new Orchestrator();

		if ( ! $orchestrator->manual_send_is_available() ) {
			return self::refuse( self::REFUSED_EMAIL_MISSING, $delivery_id );
		}

		$items = self::matched_items( $order, $rule );

		if ( array() === $items ) {
			return self::refuse( self::REFUSED_NO_ITEMS, $delivery_id );
		}

		// ⚠ TYPED `resend`, DECLARED BY THE ACTION (ADR-0019 §8). This path is only
		// reached for a tombstone that already reached a terminal state, so the delivery
		// genuinely has history behind it.
		$orchestrator->send_manual( $order, $rule, $items, $delivery_id, $identity, 'resend' );

		return array(
			'outcome'     => self::OK,
			'code'        => self::ACTION_RESEND,
			'delivery_id' => $delivery_id,
		);
	}

	/**
	 * The ADR-0019 §4 refusals that are facts about the RULE (R1, R2, R3).
	 *
	 * @param array|null $rule Rule row, or null when it is gone.
	 * @return string|null Refusal code, or null when the rule is usable.
	 */
	public static function rule_refusal( ?array $rule ): ?string {
		if ( null === $rule ) {
			// R1. ADR-0015 §2 releases the snapshot at the terminal state, so a
			// completed delivery has nothing to fall back on.
			return self::REFUSED_RULE_DELETED;
		}

		if ( 'insert' === (string) ( $rule['delivery_mode'] ?? '' ) ) {
			// R2. ADR-0013 §2: insert content is a fragment of somebody else's email.
			// It has no subject, no recipients and no envelope — there is no message.
			return self::REFUSED_RULE_INSERT_MODE;
		}

		if ( ! in_array( (string) ( $rule['delivery_mode'] ?? '' ), RuleRepository::DELIVERY_MODES, true ) ) {
			// R3, mode half.
			return self::REFUSED_RULE_VOCABULARY;
		}

		if ( ! Consolidation::is_valid( (string) ( $rule['consolidation'] ?? '' ) ) ) {
			// R3, consolidation half (ADR-0016 §1a): the rule is not deliverable, and
			// the automatic path refuses it too.
			return self::REFUSED_RULE_VOCABULARY;
		}

		return null;
	}

	/**
	 * The line items this rule's targeting matches on this order.
	 *
	 * ⚠ THROUGH THE SAME MATCHER AND THE SAME LOOP THE AUTOMATIC PATH USES (gate 39).
	 * A manual send that resolved items its own way could address a message to products
	 * the rule does not target — a divergence invisible until a customer receives an
	 * email about something they did not buy.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $rule  Rule row.
	 * @return array[] Matched item descriptors.
	 */
	public static function matched_items( \WC_Order $order, array $rule ): array {
		return ( new \Extonify\WCEP\Matching\RuleMatcher() )->match_rule_against_order( $order, $rule );
	}

	/**
	 * Load an order, or null.
	 *
	 * @param int $order_id Order id.
	 * @return \WC_Order|null
	 */
	private static function load_order( int $order_id ): ?\WC_Order {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * A refusal.
	 *
	 * @param string $code        Refusal code.
	 * @param int    $delivery_id Tombstone id, or 0.
	 * @return array
	 */
	private static function refuse( string $code, int $delivery_id ): array {
		return array(
			'outcome'     => self::REFUSED,
			'code'        => $code,
			'delivery_id' => $delivery_id,
		);
	}
}
