<?php
/**
 * WordPress privacy framework: personal-data exporter.
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Privacy;

use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Repository\DeliveryDetailRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Exports this plugin's data for an email address, implementing ADR-0010.
 *
 * Two groups are exported:
 *
 *  - the purgeable ATTEMPT rows, which hold the personal data;
 *  - the durable DELIVERY IDENTITIES (tombstones), which hold no direct contact
 *    or message-content fields but remain linked to an order. `identity_hash` is
 *    never exported — an internal key of no value to the subject.
 *
 * SCOPE is decided per tombstone, not per request: see SubjectData. A row the
 * subject has no claim on is not exported at all, and a third party's contact
 * details are omitted from — and redacted out of — the rows that are.
 *
 * PAGINATION: export mutates nothing, so the resolved id set is stable and a
 * plain offset is correct. Tombstones are emitted in bounded batches ALONGSIDE
 * the detail pages rather than all at once on the final page, so a subject with
 * many deliveries never receives one oversized response.
 */
class Exporter {

	/**
	 * Attempt rows returned per page.
	 *
	 * Conservative on purpose: each row can carry a large `snapshot`, and the
	 * privacy exporter runs inside an admin request with a normal PHP timeout.
	 */
	const PAGE_SIZE = 25;

	/**
	 * Tombstone records emitted per page, alongside the attempt rows.
	 */
	const IDENTITY_PAGE_SIZE = 25;

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
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function register( array $exporters ): array {
		$exporters['extonify-wcep-deliveries'] = array(
			'exporter_friendly_name' => __( 'Extonify Custom Emails Per Product', 'extonify-custom-emails-per-product' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Export one page of records for an address.
	 *
	 * @param string $email_address Email address being exported.
	 * @param int    $page          1-based page number.
	 * @return array{data:array,done:bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$page = max( 1, (int) $page );

		if ( ! Migrator::is_operational() ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$scoped     = $this->subject->scoped_detail_ids( $email_address );
		$tombstones = $this->subject->tombstones( $email_address );

		$export = array();

		// --- attempt rows for this page ------------------------------------
		$ids       = array_keys( $scoped );
		$row_page  = array_slice( $ids, ( $page - 1 ) * self::PAGE_SIZE, self::PAGE_SIZE );
		$rows_left = count( $ids ) > $page * self::PAGE_SIZE;

		if ( ! empty( $row_page ) ) {
			foreach ( $this->details->find_by_ids( $row_page, self::PAGE_SIZE, 0 ) as $row ) {
				$item = $this->attempt_item( $row, $email_address, $scoped[ (int) $row['id'] ] );
				if ( null !== $item ) {
					$export[] = $item;
				}
			}
		}

		// --- tombstone records for this page --------------------------------
		$identity_page   = array_slice( $tombstones, ( $page - 1 ) * self::IDENTITY_PAGE_SIZE, self::IDENTITY_PAGE_SIZE );
		$identities_left = count( $tombstones ) > $page * self::IDENTITY_PAGE_SIZE;

		foreach ( $identity_page as $tombstone ) {
			$export[] = $this->identity_item( $tombstone );
		}

		return array(
			'data' => $export,
			'done' => ! $rows_left && ! $identities_left,
		);
	}

	/**
	 * One attempt row as an export item, scoped to what the subject may see.
	 *
	 * @param array  $row             Detail row.
	 * @param string $email           Subject's address.
	 * @param string $tombstone_class Class of the tombstone the row came through.
	 * @return array|null Null when the row is out of scope entirely.
	 */
	private function attempt_item( array $row, string $email, string $tombstone_class ): ?array {
		$ownership = $this->subject->classify( $row, $email );
		$allowed   = $this->subject->fields_for( $ownership, $tombstone_class );

		if ( empty( $allowed ) ) {
			// Out of scope: not the subject's row, reached through a tombstone
			// that gives them no claim on it. Exported not at all.
			return null;
		}

		$is_third = ( SubjectData::THIRD_PARTY === $ownership );

		// Non-personal context: safe for every class, and without it a
		// content-only item would be unreadable.
		$fields = array(
			array(
				'name'  => __( 'Date (UTC)', 'extonify-custom-emails-per-product' ),
				'value' => (string) $row['created_at'],
			),
			array(
				'name'  => __( 'Status', 'extonify-custom-emails-per-product' ),
				'value' => (string) $row['state'],
			),
			array(
				'name'  => __( 'Type', 'extonify-custom-emails-per-product' ),
				'value' => (string) $row['type'],
			),
		);

		$labels = array(
			'recipient'        => __( 'Recipient', 'extonify-custom-emails-per-product' ),
			'recipient_type'   => __( 'Recipient type', 'extonify-custom-emails-per-product' ),
			'recipient_header' => __( 'Recipient (as addressed)', 'extonify-custom-emails-per-product' ),
			'subject'          => __( 'Subject', 'extonify-custom-emails-per-product' ),
			'reason'           => __( 'Reason', 'extonify-custom-emails-per-product' ),
			'failure_message'  => __( 'Failure message', 'extonify-custom-emails-per-product' ),
			'snapshot'         => __( 'Delivery snapshot', 'extonify-custom-emails-per-product' ),
		);

		foreach ( $labels as $field => $label ) {
			if ( ! in_array( $field, $allowed, true ) ) {
				// A third party's contact field: omitted ENTIRELY, not blanked,
				// so nothing about the other person appears in this export.
				continue;
			}
			$value = 'snapshot' === $field
				? ( empty( $row[ $field ] ) ? '' : (string) wp_json_encode( $row[ $field ] ) )
				: (string) $row[ $field ];

			if ( $is_third ) {
				// Omitting the contact COLUMNS is not enough on its own: a
				// content field routinely quotes the address it was addressed
				// to — an SMTP rejection reads
				// `550 5.1.1 <manager@example.test>: rejected`. Leaving that in
				// would disclose the third party through the back door.
				$value = $this->redact_third_party( $value, $row );
			}

			if ( SubjectData::DIRECT_LINKED === $tombstone_class ) {
				// A DIRECT_LINKED subject was merely copied on this delivery.
				// Their own row is theirs, but its CONTENT still quotes the
				// order owner — "Order update for alice@example.test". Under
				// ADR-0010 they have no claim on anyone else's identity here,
				// so every address that is not theirs is redacted.
				$value = $this->redact_foreign_addresses( $value, $email );
			}

			$fields[] = array(
				'name'  => $label,
				'value' => $value,
			);
		}

		if ( $is_third ) {
			$fields[] = array(
				'name'  => __( 'Note', 'extonify-custom-emails-per-product' ),
				'value' => __( 'This email was addressed to someone else (for example a copy sent to staff). Only the parts that may relate to you are shown; the other recipient\'s contact details are not included.', 'extonify-custom-emails-per-product' ),
			);
		}

		return array(
			'group_id'    => 'extonify-wcep-deliveries',
			'group_label' => __( 'Product email deliveries', 'extonify-custom-emails-per-product' ),
			'item_id'     => 'extonify-wcep-delivery-' . (int) $row['id'],
			'data'        => $this->drop_empty( $fields ),
		);
	}

	/**
	 * Remove a third party's own address from a content value.
	 *
	 * Only that row's recipient is redacted — the value is left otherwise
	 * intact, because everything else in it may be the requesting subject's
	 * data and they are entitled to see it.
	 *
	 * @param string $value Content value.
	 * @param array  $row   The third-party detail row.
	 * @return string
	 */
	private function redact_third_party( string $value, array $row ): string {
		if ( '' === $value ) {
			return $value;
		}

		$needles = array();
		foreach ( array( 'recipient', 'recipient_header' ) as $field ) {
			$candidate = isset( $row[ $field ] ) ? trim( (string) $row[ $field ] ) : '';
			if ( '' !== $candidate ) {
				$needles[] = $candidate;
			}
		}
		if ( empty( $needles ) ) {
			return $value;
		}

		// Longest first, so the header form is replaced before the bare address
		// it contains.
		usort(
			$needles,
			function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		// Case-insensitive: the stored recipient is normalised to lowercase but
		// a diagnostic may quote it in any case.
		return (string) str_ireplace( $needles, '[removed]', $value );
	}

	/**
	 * Redact every email address in a value except the subject's own.
	 *
	 * Used for DIRECT_LINKED rows, where the subject is entitled to their own
	 * record but to nobody else's identity. Generic by design: it does not need
	 * to know who the other parties are, only who the subject is.
	 *
	 * @param string $value Content value.
	 * @param string $email Subject's address.
	 * @return string
	 */
	private function redact_foreign_addresses( string $value, string $email ): string {
		if ( '' === $value || false === strpos( $value, '@' ) ) {
			return $value;
		}

		$subject_address = $this->subject->normalize( $email );
		if ( null === $subject_address ) {
			return $value;
		}

		return (string) preg_replace_callback(
			'/[^\s<>,;:"\']+@[^\s<>,;:"\']+\.[^\s<>,;:"\']+/',
			function ( $matches ) use ( $subject_address ) {
				$found = $this->subject->normalize( rtrim( $matches[0], '.>' ) );
				if ( null !== $found && hash_equals( $subject_address, $found ) ) {
					return $matches[0];
				}
				return '[removed]';
			},
			$value
		);
	}

	/**
	 * One tombstone as an export item.
	 *
	 * Both tombstone classes export the same minimal record: a DIRECT_LINKED
	 * subject demonstrably received that delivery, so its existence and outcome
	 * are their own data. `identity_hash` is intentionally absent.
	 *
	 * @param array $tombstone Tombstone row.
	 * @return array
	 */
	private function identity_item( array $tombstone ): array {
		$fields = array(
			array(
				'name'  => __( 'Order', 'extonify-custom-emails-per-product' ),
				'value' => (string) $tombstone['order_id'],
			),
			array(
				'name'  => __( 'Delivery mode', 'extonify-custom-emails-per-product' ),
				'value' => (string) $tombstone['mode'],
			),
			array(
				'name'  => __( 'Triggered by', 'extonify-custom-emails-per-product' ),
				'value' => (string) $tombstone['trigger_identity'],
			),
			array(
				'name'  => __( 'Outcome', 'extonify-custom-emails-per-product' ),
				'value' => (string) $tombstone['final_status'],
			),
			array(
				'name'  => __( 'First recorded (UTC)', 'extonify-custom-emails-per-product' ),
				'value' => (string) $tombstone['first_claimed_at'],
			),
			array(
				'name'  => __( 'Why this is kept', 'extonify-custom-emails-per-product' ),

				/*
				 * ⚠ THE SECOND SENTENCE EXISTS BECAUSE THE FIRST STOPPED BEING THE
				 * WHOLE TRUTH (ADR-0015 §2a). A delivery that is still PENDING carries
				 * a snapshot: the store's own unrendered templates and recipient
				 * DEFINITIONS, which may include a literal address the merchant typed
				 * into the rule. That is merchant configuration rather than the data
				 * subject's data — which is why it is not added to the export — but a
				 * privacy notice that says the row holds no message content while it
				 * holds unsent message templates is a notice that has drifted from
				 * the code, and a reader has no way to tell.
				 */
				'value' => __( 'This record contains no direct contact or message-content fields, but remains linked to an order and is retained to prevent unintended duplicate automatic deliveries. While a delayed email for this order is still waiting to be sent, the record also temporarily holds the store\'s own unsent template text and recipient settings for that message; this is released as soon as the email is sent or cancelled.', 'extonify-custom-emails-per-product' ),
			),
		);

		return array(
			'group_id'    => 'extonify-wcep-delivery-identities',
			'group_label' => __( 'Product email delivery records', 'extonify-custom-emails-per-product' ),
			'item_id'     => 'extonify-wcep-identity-' . (int) $tombstone['id'],
			'data'        => $this->drop_empty( $fields ),
		);
	}

	/**
	 * Drop empty values so an export reads cleanly. Nothing that holds a value
	 * is ever omitted.
	 *
	 * @param array $fields Export fields.
	 * @return array
	 */
	private function drop_empty( array $fields ): array {
		return array_values(
			array_filter(
				$fields,
				function ( $field ) {
					return '' !== $field['value'];
				}
			)
		);
	}
}
