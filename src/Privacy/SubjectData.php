<?php
/**
 * Locating and classifying a data subject's rows for export and erasure.
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Privacy;

use Extonify\WCEP\Domain\Recipient;
use Extonify\WCEP\Repository\DeliveryDetailRepository;
use Extonify\WCEP\Repository\DeliveryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves everything this plugin holds about one email address, and decides
 * how much of each row actually belongs to that person.
 *
 * The policy is ADR-0010. This class implements it; the ADR explains why every
 * rule is the way it is. In brief:
 *
 *  - tombstones are classified PER TOMBSTONE, never once per request, because a
 *    subject can own order X and merely be copied on order Y in one request;
 *  - an ORDER_LINKED tombstone expands to every sibling row, subject to the
 *    third-party carve-out;
 *  - a DIRECT_LINKED tombstone expands ONLY to the subject's own rows — no
 *    siblings, and NO unattributed rows, because someone merely copied on a
 *    message has no claim on the order owner's data.
 *
 * Resolution returns rows already paired with their tombstone class, so
 * downstream code cannot lose the distinction and silently over-collect.
 */
class SubjectData {

	/**
	 * Tombstone classes (ADR-0010).
	 */
	const ORDER_LINKED  = 'order_linked';
	const DIRECT_LINKED = 'direct_linked';

	/**
	 * Row ownership classes (ADR-0010).
	 */
	const OWN          = 'own';
	const UNATTRIBUTED = 'unattributed';
	const THIRD_PARTY  = 'third_party';

	/**
	 * Fields identifying WHO a row was addressed to. On a third-party row these
	 * belong to someone else.
	 */
	const CONTACT_FIELDS = array( 'recipient', 'recipient_header', 'recipient_type' );

	/**
	 * Fields describing WHAT was sent or what went wrong. These may hold the
	 * subject's data whoever the row was addressed to.
	 */
	const CONTENT_FIELDS = array( 'subject', 'snapshot', 'reason', 'failure_message' );

	/**
	 * Orders fetched per query when resolving a subject's orders.
	 *
	 * This chunks the QUERY. It does not bound the invocation: every matching
	 * order id is still collected before anything is paged. See the documented
	 * bound in ADR-0010.
	 */
	const ORDER_QUERY_CHUNK = 100;

	/**
	 * Tombstone repository.
	 *
	 * @var DeliveryRepository
	 */
	private $deliveries;

	/**
	 * Detail repository.
	 *
	 * @var DeliveryDetailRepository
	 */
	private $details;

	/**
	 * Constructor.
	 *
	 * @param DeliveryRepository|null       $deliveries Tombstone repository.
	 * @param DeliveryDetailRepository|null $details    Detail repository.
	 */
	public function __construct( ?DeliveryRepository $deliveries = null, ?DeliveryDetailRepository $details = null ) {
		$this->deliveries = $deliveries ? $deliveries : new DeliveryRepository();
		$this->details    = $details ? $details : new DeliveryDetailRepository();
	}

	/**
	 * Normalise an address the same way storage does.
	 *
	 * @param string $email Raw address.
	 * @return string|null
	 */
	public function normalize( string $email ): ?string {
		return Recipient::normalize( $email );
	}

	/**
	 * The subject's order ids, via WooCommerce CRUD only.
	 *
	 * @param string $email Email address.
	 * @return int[]
	 */
	public function order_ids( string $email ): array {
		if ( '' === trim( $email ) || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$found = array();

		// Two CRUD lookups: the billing address on the order, and WooCommerce's
		// own customer resolution (which also catches orders linked to a
		// registered account whose billing address has since changed).
		foreach ( array( 'billing_email', 'customer' ) as $field ) {
			$page = 1;
			do {
				$ids = wc_get_orders(
					array(
						$field    => $email,
						'limit'   => self::ORDER_QUERY_CHUNK,
						'page'    => $page,
						'orderby' => 'ID',
						'order'   => 'ASC',
						'return'  => 'ids',
					)
				);

				$ids        = (array) $ids;
				$chunk_size = count( $ids );
				foreach ( $ids as $id ) {
					$id = (int) $id;
					if ( $id > 0 ) {
						$found[ $id ] = true;
					}
				}
				++$page;
			} while ( self::ORDER_QUERY_CHUNK === $chunk_size );
		}

		$out = array_map( 'intval', array_keys( $found ) );
		sort( $out );

		return $out;
	}

	/**
	 * The subject's tombstones, each TAGGED with its class (ADR-0010).
	 *
	 * Two sources, unioned and de-duplicated by id:
	 *
	 *   1. tombstones belonging to the subject's orders  => ORDER_LINKED;
	 *   2. tombstones owning a directly matched row      => DIRECT_LINKED.
	 *
	 * Source 2 matters when the order is missing, deleted, or was never a
	 * resolvable WooCommerce order: without it the export omits a record that is
	 * genuinely retained. A tombstone reachable BOTH ways is ORDER_LINKED — the
	 * stronger claim wins.
	 *
	 * @param string $email Email address.
	 * @return array[] Rows with an added `wcep_class` key, ordered by id.
	 */
	public function tombstones( string $email ): array {
		$by_id = array();

		$order_ids = $this->order_ids( $email );
		if ( ! empty( $order_ids ) ) {
			foreach ( $this->deliveries->find_for_orders( $order_ids ) as $row ) {
				$row['wcep_class']         = self::ORDER_LINKED;
				$by_id[ (int) $row['id'] ] = $row;
			}
		}

		// Only tombstones NOT already resolved through an order become
		// DIRECT_LINKED — the stronger claim wins.
		$direct = $this->details->delivery_ids_for_recipient( $email );
		$direct = array_values( array_diff( $direct, array_keys( $by_id ) ) );
		if ( ! empty( $direct ) ) {
			foreach ( $this->deliveries->find_by_ids( $direct ) as $row ) {
				$row['wcep_class']         = self::DIRECT_LINKED;
				$by_id[ (int) $row['id'] ] = $row;
			}
		}

		ksort( $by_id );

		return array_values( $by_id );
	}

	/**
	 * Every in-scope detail row for the subject, paired with the class of the
	 * tombstone it came from.
	 *
	 * This is the single place row expansion happens, so the ADR-0010 scoping
	 * rules cannot be bypassed:
	 *
	 *  - ORDER_LINKED  => every sibling row under the tombstone;
	 *  - DIRECT_LINKED => only rows whose recipient IS the subject.
	 *
	 * @param string $email Email address.
	 * @return array<int,string> Map of detail row id => tombstone class, ordered by id.
	 */
	public function scoped_detail_ids( string $email ): array {
		$scoped = array();

		$order_linked = array();
		foreach ( $this->tombstones( $email ) as $tombstone ) {
			if ( self::ORDER_LINKED === $tombstone['wcep_class'] ) {
				$order_linked[] = (int) $tombstone['id'];
			}
		}

		// ORDER_LINKED: every sibling row.
		if ( ! empty( $order_linked ) ) {
			foreach ( $this->details->ids_for_deliveries( $order_linked ) as $id ) {
				$scoped[ (int) $id ] = self::ORDER_LINKED;
			}
		}

		// DIRECT_LINKED: the subject's OWN rows only. A row already claimed by an
		// ORDER_LINKED tombstone keeps that stronger class.
		foreach ( $this->details->ids_for_recipient( $email ) as $id ) {
			$id = (int) $id;
			if ( ! isset( $scoped[ $id ] ) ) {
				$scoped[ $id ] = self::DIRECT_LINKED;
			}
		}

		ksort( $scoped );

		return $scoped;
	}

	/**
	 * Classify one row's ownership against the subject.
	 *
	 * @param array  $row   Detail row.
	 * @param string $email Subject's address.
	 * @return string One of self::OWN, self::UNATTRIBUTED, self::THIRD_PARTY.
	 */
	public function classify( array $row, string $email ): string {
		$recipient = isset( $row['recipient'] ) ? (string) $row['recipient'] : '';
		if ( '' === trim( $recipient ) ) {
			return self::UNATTRIBUTED;
		}

		// Normalise BOTH sides: the stored value was normalised on write, and
		// the request may arrive in any case.
		$subject_address = $this->normalize( $email );
		$row_address     = $this->normalize( $recipient );

		if ( null !== $subject_address && null !== $row_address && hash_equals( $subject_address, $row_address ) ) {
			return self::OWN;
		}

		return self::THIRD_PARTY;
	}

	/**
	 * The fields of a row this subject may see or have erased, given the class
	 * of the tombstone the row was reached through (ADR-0010).
	 *
	 * An EMPTY result means the row is OUT OF SCOPE ENTIRELY — neither exported
	 * nor erased. That happens for anything other than the subject's own rows
	 * beneath a DIRECT_LINKED tombstone.
	 *
	 * @param string $ownership       Row ownership class.
	 * @param string $tombstone_class Tombstone class.
	 * @return string[] Field names; empty when out of scope.
	 */
	public function fields_for( string $ownership, string $tombstone_class ): array {
		if ( self::DIRECT_LINKED === $tombstone_class ) {
			// Only the subject's own rows are in scope; a person merely copied
			// on a message has no claim on the order owner's data.
			return self::OWN === $ownership
				? array_merge( self::CONTACT_FIELDS, self::CONTENT_FIELDS )
				: array();
		}

		if ( self::THIRD_PARTY === $ownership ) {
			return self::CONTENT_FIELDS;
		}

		return array_merge( self::CONTACT_FIELDS, self::CONTENT_FIELDS );
	}
}
