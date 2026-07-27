<?php
/**
 * WordPress privacy framework: personal-data eraser.
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Privacy;

use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Repository\DeliveryDetailRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Erases this plugin's personal data for an email address, implementing
 * ADR-0010.
 *
 * HARD RULE (ADR-0004/ADR-0005): tombstones are NEVER erased. The tombstone
 * contains no direct contact or message-content fields, but remains linked to
 * an order and is retained to prevent unintended duplicate automatic
 * deliveries — deleting one would silently re-arm an already-delivered order.
 * Because a tombstone IS retained, this eraser reports `items_retained` truly.
 *
 * SCOPE is decided per tombstone, not per request: see SubjectData. A third
 * party's contact details survive an erasure requested by someone else, and a
 * row the subject has no claim on is not touched at all.
 *
 * PAGINATION — OFFSET PAGING IS PROHIBITED HERE, and this is the whole reason
 * the eraser and the exporter page differently.
 *
 * Erasure nulls `recipient`, which is one of the two columns resolution matches
 * on. The resolved set therefore SHRINKS beneath a cursor: page 2 at offset 25
 * would skip rows that moved into positions 0-24 when page 1's rows stopped
 * matching, and `done` would report true with rows silently unerased.
 *
 * So every invocation re-resolves the currently ELIGIBLE rows and processes up
 * to PAGE_SIZE from the FRONT, never at an offset. Eligibility means the row is
 * in scope for this subject AND at least one field this subject's policy would
 * clear is still non-null. That makes erasure idempotent (a second run clears
 * nothing) and convergent (each pass strictly shrinks the eligible set).
 */
class Eraser {

	/**
	 * Rows processed per invocation. Matches the exporter so both halves of a
	 * request behave predictably.
	 */
	const PAGE_SIZE = 25;

	/**
	 * Detail repository.
	 *
	 * @var DeliveryDetailRepository
	 */
	private $details;

	/**
	 * Subject locator and classifier.
	 *
	 * @var SubjectData
	 */
	private $subject;

	/**
	 * Constructor.
	 *
	 * @param DeliveryDetailRepository|null $details Detail repository.
	 * @param SubjectData|null              $subject Subject locator.
	 */
	public function __construct( ?DeliveryDetailRepository $details = null, ?SubjectData $subject = null ) {
		$this->details = $details ? $details : new DeliveryDetailRepository();
		$this->subject = $subject ? $subject : new SubjectData();
	}

	/**
	 * Register with the privacy framework.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function register( array $erasers ): array {
		$erasers['extonify-wcep-deliveries'] = array(
			'eraser_friendly_name' => __( 'Extonify Custom Emails Per Product', 'extonify-custom-emails-per-product' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Clear the subject's personal fields on one batch of eligible rows.
	 *
	 * The $page argument is deliberately unused for positioning: see the class
	 * docblock. It is accepted because the privacy framework supplies it, and
	 * it bounds the work per call in exactly the same way — one PAGE_SIZE batch
	 * per invocation — without ever seeking past rows that are still eligible.
	 *
	 * @param string $email_address Email address being erased.
	 * @param int    $page          1-based page number from the framework.
	 * @return array{items_removed:bool,items_retained:bool,messages:array,done:bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		unset( $page ); // Positioning comes from eligibility, never an offset.

		$result = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		if ( ! Migrator::is_operational() ) {
			return $result;
		}

		// Resolve BEFORE erasing: anonymisation nulls `recipient`, one of the
		// two ways a tombstone is discovered, so looking afterwards would
		// wrongly report that nothing was retained.
		$has_tombstones = ! empty( $this->subject->tombstones( $email_address ) );

		$eligible = $this->eligible_rows( $email_address );

		$batch            = array_slice( $eligible, 0, self::PAGE_SIZE );
		$remaining        = count( $eligible ) > self::PAGE_SIZE;
		$cleared          = 0;
		$third_party_seen = false;

		// Group the batch by the field set each row's policy clears, so each
		// group is a single statement.
		$by_fields = array();
		foreach ( $batch as $entry ) {
			$key                         = implode( ',', $entry['fields'] );
			$by_fields[ $key ]['fields'] = $entry['fields'];
			$by_fields[ $key ]['ids'][]  = $entry['id'];
			if ( SubjectData::THIRD_PARTY === $entry['ownership'] ) {
				$third_party_seen = true;
			}
		}

		foreach ( $by_fields as $group ) {
			$cleared += $this->details->anonymize_ids( $group['ids'], $group['fields'] );
		}

		if ( $cleared > 0 ) {
			$result['items_removed'] = true;
			$result['messages'][]    = __( 'Personal details of product email deliveries were removed: recipient address, subject, delivery snapshot, reason and any failure message.', 'extonify-custom-emails-per-product' );
		}
		if ( $third_party_seen ) {
			$result['messages'][] = __( 'Some of these emails were also addressed to other people, for example a copy sent to staff. Their contact details belong to them and have been left in place; only the message content that may relate to you was removed.', 'extonify-custom-emails-per-product' );
		}

		$result['done'] = ! $remaining;

		// Report the retention truthfully, on the final call.
		if ( $result['done'] && $has_tombstones ) {
			$result['items_retained'] = true;
			$result['messages'][]     = __( 'A minimal delivery record has been retained for each affected order. It contains no direct contact or message-content fields, but remains linked to an order and is retained to prevent unintended duplicate automatic deliveries.', 'extonify-custom-emails-per-product' );
		}

		return $result;
	}

	/**
	 * Rows currently eligible for erasure by this subject.
	 *
	 * A row qualifies when it is in scope AND at least one field this subject's
	 * policy would clear is still non-null. Already-cleared rows drop out,
	 * which is what makes the loop terminate: a third-party row whose content
	 * is cleared is finished even though its contact fields — which this
	 * subject may never clear — are still populated.
	 *
	 * @param string $email Subject address.
	 * @return array<int,array{id:int,fields:string[],ownership:string}>
	 */
	private function eligible_rows( string $email ): array {
		$scoped = $this->subject->scoped_detail_ids( $email );
		if ( empty( $scoped ) ) {
			return array();
		}

		$eligible = array();

		// Read in bounded chunks: the id set can be large, and only the first
		// PAGE_SIZE eligible rows are needed to make progress.
		foreach ( array_chunk( array_keys( $scoped ), DeliveryDetailRepository::DELETE_CHUNK_SIZE, true ) as $chunk ) {
			$rows = $this->details->find_by_ids( $chunk, count( $chunk ), 0 );

			foreach ( $rows as $row ) {
				$id        = (int) $row['id'];
				$ownership = $this->subject->classify( $row, $email );
				$fields    = $this->subject->fields_for( $ownership, $scoped[ $id ] );

				if ( empty( $fields ) ) {
					continue; // Out of scope entirely.
				}
				if ( ! $this->has_clearable_value( $row, $fields ) ) {
					continue; // Already cleared: ineligible, so the loop converges.
				}

				$eligible[] = array(
					'id'        => $id,
					'fields'    => $fields,
					'ownership' => $ownership,
				);
			}

			// Enough to fill this batch and know whether more remain.
			if ( count( $eligible ) > self::PAGE_SIZE ) {
				break;
			}
		}

		return $eligible;
	}

	/**
	 * Whether any of the named fields still holds a value on this row.
	 *
	 * @param array    $row    Detail row.
	 * @param string[] $fields Fields this subject's policy would clear.
	 * @return bool
	 */
	private function has_clearable_value( array $row, array $fields ): bool {
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $row ) ) {
				continue;
			}
			// `snapshot` is hydrated to an array; everything else is a string
			// or null. Both are "empty" once cleared.
			if ( ! empty( $row[ $field ] ) ) {
				return true;
			}
		}
		return false;
	}
}
