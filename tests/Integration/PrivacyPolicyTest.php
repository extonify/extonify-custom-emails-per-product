<?php
/**
 * ADR-0010 privacy subject resolution policy — every cell of the matrix.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Privacy\Eraser;
use Extonify\WCEP\Privacy\Exporter;
use Extonify\WCEP\Privacy\SubjectData;
use Extonify\WCEP\Repository\DeliveryDetailRepository;
use Extonify\WCEP\Repository\DeliveryRepository;

/**
 * The policy is ADR-0010. Every cell of both matrices has an assertion here.
 *
 * The case that drove this pass: a subject may OWN one order and merely be
 * COPIED on another, so classification has to happen per tombstone. Resolving
 * once per request meant a CC-only recipient received the order owner's
 * unattributed rows — the same disclosure failure as before, in the other
 * direction.
 */
final class PrivacyPolicyTest extends IntegrationTestCase {

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
	 * Build repositories.
	 *
	 * @before
	 * @return void
	 */
	protected function set_up_repos() {
		$this->deliveries = new DeliveryRepository();
		$this->details    = new DeliveryDetailRepository();
	}

	/**
	 * A unique lowercase address.
	 *
	 * @param string $label Role label.
	 * @param string $suffix Shared suffix.
	 * @return string
	 */
	private function address( string $label, string $suffix ): string {
		return "wcep-{$label}-{$suffix}@example.test";
	}

	/**
	 * A real order owned by an address, plus a tombstone against it.
	 *
	 * @param string $owner_email Billing email.
	 * @param int    $rule_id     Rule id.
	 * @return array{order_id:int,delivery_id:int}
	 */
	private function order_with_delivery( string $owner_email, int $rule_id ): array {
		$product = $this->make_product( 'WCEP Policy Fixture' );
		$order   = wc_create_order();
		$order->add_product( wc_get_product( $product ), 1 );
		$order->set_billing_email( $owner_email );
		$order->calculate_totals();
		$order->save();
		$this->order_ids[] = (int) $order->get_id();

		$claim = $this->deliveries->claim( (int) $order->get_id(), $rule_id, 'separate', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );

		return array(
			'order_id'    => (int) $order->get_id(),
			'delivery_id' => $claim['delivery_id'],
		);
	}

	/**
	 * The canonical fixture: order owned by customer A, one delivery with
	 * TO: A, CC: manager, BCC: supplier, plus a NULL-recipient row naming A.
	 *
	 * @return array
	 */
	private function seed_shared_delivery(): array {
		$suffix   = strtolower( wp_generate_password( 10, false, false ) );
		$customer = $this->address( 'customer', $suffix );
		$manager  = $this->address( 'manager', $suffix );
		$supplier = $this->address( 'supplier', $suffix );

		$seed = $this->order_with_delivery( $customer, 300 );

		$rows = array();
		foreach ( array(
			'to'  => $customer,
			'cc'  => $manager,
			'bcc' => $supplier,
		) as $type => $address ) {
			$id = $this->details->insert(
				$seed['delivery_id'],
				array(
					'state'           => 'failed',
					'recipient'       => $address,
					'recipient_type'  => $type,
					'subject'         => "Order update for {$customer}",
					'reason'          => "attempted for {$customer}",
					'failure_message' => "550 5.1.1 <{$address}>: rejected",
					'snapshot'        => array( 'customer' => $customer ),
				)
			);
			$this->track_detail( $id );
			$rows[ $type ] = $id;
		}

		// The unattributed row: no recipient, and its text names customer A.
		$unattributed = $this->details->insert(
			$seed['delivery_id'],
			array(
				'state'           => 'cancelled',
				'failure_message' => "no recipient resolved for {$customer} (Alpha Customer)",
			)
		);
		$this->track_detail( $unattributed );
		$rows['unattributed'] = $unattributed;

		return array(
			'customer'    => $customer,
			'manager'     => $manager,
			'supplier'    => $supplier,
			'delivery_id' => $seed['delivery_id'],
			'order_id'    => $seed['order_id'],
			'rows'        => $rows,
		);
	}

	/**
	 * Every export item across all pages.
	 *
	 * @param string $email Subject address.
	 * @return array[]
	 */
	private function export_all( string $email ): array {
		$exporter = new Exporter();
		$items    = array();
		$page     = 1;
		$guard    = 0;
		do {
			$export = $exporter->export( $email, $page );
			foreach ( $export['data'] as $item ) {
				$items[] = $item;
			}
			++$page;
			++$guard;
		} while ( empty( $export['done'] ) && $guard < 50 );

		return $items;
	}

	/**
	 * Run the eraser to completion; returns the number of invocations.
	 *
	 * @param string $email Subject address.
	 * @return int
	 */
	private function erase_all( string $email ): int {
		$eraser = new Eraser();
		$calls  = 0;
		do {
			$result = $eraser->erase( $email, $calls + 1 );
			++$calls;
		} while ( empty( $result['done'] ) && $calls < 50 );

		return $calls;
	}

	/**
	 * Read one row by id.
	 *
	 * @param int $delivery_id Owning tombstone.
	 * @param int $row_id      Detail row id.
	 * @return array
	 */
	private function row( int $delivery_id, int $row_id ): array {
		foreach ( $this->details->find_for_delivery( $delivery_id ) as $row ) {
			if ( (int) $row['id'] === $row_id ) {
				return $row;
			}
		}
		$this->fail( "Row {$row_id} not found." );
	}

	// -----------------------------------------------------------------------
	// DIRECT_LINKED — the manager, who is only CC'd.
	// -----------------------------------------------------------------------

	/**
	 * MANAGER EXPORT. A CC-only subject receives their own row and nothing
	 * else — not the customer's row, not the supplier's, and NOT the
	 * unattributed row, which belongs to the order owner.
	 *
	 * @return void
	 */
	public function test_direct_linked_export_is_limited_to_own_rows() {
		$f = $this->seed_shared_delivery();

		// Precondition: the manager owns no order, so the tombstone can only be
		// DIRECT_LINKED.
		$subject = new SubjectData();
		$this->assertSame( array(), $subject->order_ids( $f['manager'] ) );
		$tombstones = $subject->tombstones( $f['manager'] );
		$this->assertCount( 1, $tombstones );
		$this->assertSame( SubjectData::DIRECT_LINKED, $tombstones[0]['wcep_class'] );

		$items = $this->export_all( $f['manager'] );

		$attempt_ids = array();
		foreach ( $items as $item ) {
			if ( 'extonify-wcep-deliveries' === $item['group_id'] ) {
				$attempt_ids[] = $item['item_id'];
			}
		}

		$this->assertSame(
			array( 'extonify-wcep-delivery-' . $f['rows']['cc'] ),
			$attempt_ids,
			'A DIRECT_LINKED subject received rows other than their own.'
		);

		$blob = (string) wp_json_encode( $items );
		$this->assertStringNotContainsString( $f['customer'], $blob, "The order owner's address leaked to a CC recipient." );
		$this->assertStringNotContainsString( $f['supplier'], $blob, "A BCC recipient's address leaked to a CC recipient." );
		$this->assertStringNotContainsString( 'Alpha Customer', $blob, "The order owner's NAME leaked to a CC recipient." );
		$this->assertStringNotContainsString( 'no recipient resolved', $blob, 'The unattributed row leaked to a CC recipient.' );

		// Their own row IS there, in full.
		$this->assertStringContainsString( $f['manager'], $blob );
	}

	/**
	 * MANAGER ERASURE. Clears the manager's row and leaves everyone else's
	 * completely untouched — including the unattributed row.
	 *
	 * @return void
	 */
	public function test_direct_linked_erasure_touches_only_own_rows() {
		$f = $this->seed_shared_delivery();

		$before_to           = $this->row( $f['delivery_id'], $f['rows']['to'] );
		$before_bcc          = $this->row( $f['delivery_id'], $f['rows']['bcc'] );
		$before_unattributed = $this->row( $f['delivery_id'], $f['rows']['unattributed'] );

		$this->erase_all( $f['manager'] );

		// The manager's own row is cleared.
		$cc = $this->row( $f['delivery_id'], $f['rows']['cc'] );
		$this->assertNull( $cc['recipient'] );
		$this->assertNull( $cc['subject'] );
		$this->assertNull( $cc['failure_message'] );

		// Everyone else's row is byte-identical to before.
		$after_to           = $this->row( $f['delivery_id'], $f['rows']['to'] );
		$after_bcc          = $this->row( $f['delivery_id'], $f['rows']['bcc'] );
		$after_unattributed = $this->row( $f['delivery_id'], $f['rows']['unattributed'] );

		$this->assertSame( $before_to, $after_to, "A CC recipient's erasure altered the order owner's row." );
		$this->assertSame( $before_bcc, $after_bcc, "A CC recipient's erasure altered a BCC recipient's row." );
		$this->assertSame( $before_unattributed, $after_unattributed, "A CC recipient's erasure altered the unattributed row." );
	}

	// -----------------------------------------------------------------------
	// ORDER_LINKED — the customer, who owns the order.
	// -----------------------------------------------------------------------

	/**
	 * ORDER_LINKED classification, and the full ownership matrix on export.
	 *
	 * @return void
	 */
	public function test_order_linked_export_applies_the_ownership_matrix() {
		$f       = $this->seed_shared_delivery();
		$subject = new SubjectData();

		$tombstones = $subject->tombstones( $f['customer'] );
		$this->assertCount( 1, $tombstones );
		$this->assertSame( SubjectData::ORDER_LINKED, $tombstones[0]['wcep_class'] );

		$items = $this->export_all( $f['customer'] );
		$by_id = array();
		foreach ( $items as $item ) {
			$by_id[ $item['item_id'] ] = wp_list_pluck( $item['data'], 'value', 'name' );
		}

		// OWN — all personal fields.
		$own = $by_id[ 'extonify-wcep-delivery-' . $f['rows']['to'] ];
		$this->assertSame( $f['customer'], $own['Recipient'] );
		$this->assertSame( 'to', $own['Recipient type'] );
		$this->assertArrayHasKey( 'Failure message', $own );

		// UNATTRIBUTED — all personal fields.
		$unattributed = $by_id[ 'extonify-wcep-delivery-' . $f['rows']['unattributed'] ];
		$this->assertStringContainsString( 'Alpha Customer', $unattributed['Failure message'] );

		// THIRD_PARTY — content only, contact omitted, address redacted.
		foreach ( array(
			'cc'  => $f['manager'],
			'bcc' => $f['supplier'],
		) as $type => $other ) {
			$third = $by_id[ 'extonify-wcep-delivery-' . $f['rows'][ $type ] ];
			$this->assertArrayNotHasKey( 'Recipient', $third );
			$this->assertArrayNotHasKey( 'Recipient type', $third );
			$this->assertArrayNotHasKey( 'Recipient (as addressed)', $third );
			$this->assertStringContainsString( $f['customer'], $third['Subject'] );
			$this->assertStringNotContainsString( $other, $third['Failure message'], 'A third party address survived redaction.' );
		}
	}

	/**
	 * ORDER_LINKED erasure: own and unattributed cleared fully, third-party
	 * content cleared, third-party contact preserved.
	 *
	 * @return void
	 */
	public function test_order_linked_erasure_applies_the_ownership_matrix() {
		$f = $this->seed_shared_delivery();
		$this->erase_all( $f['customer'] );

		$own = $this->row( $f['delivery_id'], $f['rows']['to'] );
		$this->assertNull( $own['recipient'] );
		$this->assertNull( $own['failure_message'] );

		$unattributed = $this->row( $f['delivery_id'], $f['rows']['unattributed'] );
		$this->assertNull( $unattributed['failure_message'] );

		foreach ( array(
			'cc'  => $f['manager'],
			'bcc' => $f['supplier'],
		) as $type => $other ) {
			$third = $this->row( $f['delivery_id'], $f['rows'][ $type ] );

			// Contact preserved.
			$this->assertSame( $other, $third['recipient'], "A third party's recipient was erased." );
			$this->assertSame( $type, $third['recipient_type'], "A third party's recipient type was erased." );

			// Content cleared.
			$this->assertNull( $third['subject'] );
			$this->assertNull( $third['reason'] );
			$this->assertNull( $third['failure_message'] );
			$this->assertSame( array(), $third['snapshot'] );
		}
	}

	// -----------------------------------------------------------------------
	// DUAL ROLE — owns order X, CC'd on order Y, in one request.
	// -----------------------------------------------------------------------

	/**
	 * The same subject is classified per tombstone: full ownership under the
	 * order they own, direct-only under the one they are merely copied on.
	 *
	 * @return void
	 */
	public function test_dual_role_subject_is_classified_per_tombstone() {
		$suffix  = strtolower( wp_generate_password( 10, false, false ) );
		$subject = $this->address( 'dual', $suffix );
		$other   = $this->address( 'otherowner', $suffix );

		// Order X — owned by the subject, with an unattributed sibling row.
		$x     = $this->order_with_delivery( $subject, 301 );
		$x_own = $this->details->insert(
			$x['delivery_id'],
			array(
				'state'     => 'sent',
				'recipient' => $subject,
				'subject'   => 'x-own-row',
			)
		);
		$this->track_detail( $x_own );
		$x_unattributed = $this->details->insert(
			$x['delivery_id'],
			array(
				'state'  => 'cancelled',
				'reason' => 'x-unattributed-row',
			)
		);
		$this->track_detail( $x_unattributed );

		// Order Y — owned by someone else; the subject is only CC'd, and there
		// is an unattributed sibling row that belongs to the other owner.
		$y    = $this->order_with_delivery( $other, 302 );
		$y_cc = $this->details->insert(
			$y['delivery_id'],
			array(
				'state'          => 'sent',
				'recipient'      => $subject,
				'recipient_type' => 'cc',
				'subject'        => 'y-cc-row',
			)
		);
		$this->track_detail( $y_cc );
		$y_unattributed = $this->details->insert(
			$y['delivery_id'],
			array(
				'state'  => 'cancelled',
				'reason' => 'y-unattributed-row',
			)
		);
		$this->track_detail( $y_unattributed );

		// Per-tombstone classification.
		$locator = new SubjectData();
		$classes = array();
		foreach ( $locator->tombstones( $subject ) as $tombstone ) {
			$classes[ (int) $tombstone['id'] ] = $tombstone['wcep_class'];
		}
		$this->assertSame( SubjectData::ORDER_LINKED, $classes[ $x['delivery_id'] ], 'The owned order was not ORDER_LINKED.' );
		$this->assertSame( SubjectData::DIRECT_LINKED, $classes[ $y['delivery_id'] ], 'The copied-on order was not DIRECT_LINKED.' );

		// Scoped expansion follows the class.
		$scoped = $locator->scoped_detail_ids( $subject );
		$this->assertArrayHasKey( $x_own, $scoped );
		$this->assertArrayHasKey( $x_unattributed, $scoped, 'The owned order\'s unattributed sibling was excluded.' );
		$this->assertArrayHasKey( $y_cc, $scoped );
		$this->assertArrayNotHasKey( $y_unattributed, $scoped, 'The other owner\'s unattributed row was included.' );

		// And the export agrees.
		$blob = (string) wp_json_encode( $this->export_all( $subject ) );
		$this->assertStringContainsString( 'x-own-row', $blob );
		$this->assertStringContainsString( 'x-unattributed-row', $blob );
		$this->assertStringContainsString( 'y-cc-row', $blob );
		$this->assertStringNotContainsString( 'y-unattributed-row', $blob, 'The other owner\'s unattributed row leaked.' );

		// Erasure respects the same boundary.
		$this->erase_all( $subject );
		$this->assertNull( $this->row( $x['delivery_id'], $x_unattributed )['reason'] );
		$this->assertSame(
			'y-unattributed-row',
			$this->row( $y['delivery_id'], $y_unattributed )['reason'],
			'Erasure by a CC recipient cleared the order owner\'s unattributed row.'
		);
	}

	// -----------------------------------------------------------------------
	// MUTATION-SAFE ERASURE.
	// -----------------------------------------------------------------------

	/**
	 * THE CASE OFFSET PAGING SILENTLY FAILS.
	 *
	 * 30 rows, each under its OWN tombstone, none of whose orders exist, all
	 * sharing one direct recipient. Erasure nulls `recipient`, which is the only
	 * thing making them resolvable — so with offset paging page 2 would seek
	 * past rows that had shifted forward, and `done` would arrive with rows
	 * unerased.
	 *
	 * @return void
	 */
	public function test_erasure_is_mutation_safe_across_pages() {
		$email = $this->address( 'mutating', strtolower( wp_generate_password( 10, false, false ) ) );
		$total = 30;

		$this->assertGreaterThan( Eraser::PAGE_SIZE, $total, 'The fixture must exceed one page to be meaningful.' );

		$rows = array();
		for ( $i = 0; $i < $total; $i++ ) {
			$claim = $this->deliveries->claim( $this->fake_order_id() + $i, 310, 'separate', 'status:mut-' . $i );
			$this->track_delivery( $claim['delivery_id'] );

			$id = $this->details->insert(
				$claim['delivery_id'],
				array(
					'state'     => 'sent',
					'recipient' => $email,
					'subject'   => 'mutating-row-' . $i,
				)
			);
			$this->track_detail( $id );
			$rows[] = array( $claim['delivery_id'], $id );
		}

		$eraser = new Eraser();
		$calls  = 0;
		do {
			$result = $eraser->erase( $email, $calls + 1 );
			++$calls;

			if ( empty( $result['done'] ) ) {
				$this->assertLessThan( 50, $calls, 'Erasure did not converge.' );
			}
		} while ( empty( $result['done'] ) && $calls < 50 );

		$this->assertTrue( (bool) $result['done'] );
		$this->assertGreaterThan( 1, $calls, 'The fixture should have needed more than one invocation.' );

		// EVERY row is cleared. This is what offset paging gets wrong.
		foreach ( $rows as list( $delivery_id, $row_id ) ) {
			$row = $this->row( $delivery_id, $row_id );
			$this->assertNull( $row['recipient'], "Row {$row_id} was skipped by paged erasure." );
			$this->assertNull( $row['subject'], "Row {$row_id} was skipped by paged erasure." );
		}
	}

	/**
	 * Erasure is idempotent: a second run clears nothing, completes at once,
	 * and leaves preserved third-party contact fields intact.
	 *
	 * @return void
	 */
	public function test_erasure_is_idempotent() {
		$f = $this->seed_shared_delivery();

		$this->erase_all( $f['customer'] );

		$second = ( new Eraser() )->erase( $f['customer'], 1 );

		$this->assertTrue( $second['done'], 'A repeat erasure did not complete immediately.' );
		$this->assertFalse( $second['items_removed'], 'A repeat erasure claimed to remove something.' );

		// The third party's contact fields are still there, unharmed by either
		// pass — and their presence has not made the row eligible forever.
		$third = $this->row( $f['delivery_id'], $f['rows']['cc'] );
		$this->assertSame( $f['manager'], $third['recipient'] );
		$this->assertSame( 'cc', $third['recipient_type'] );
	}

	// -----------------------------------------------------------------------
	// BOUNDED TOMBSTONE EMISSION.
	// -----------------------------------------------------------------------

	/**
	 * A subject with more tombstones than one batch receives them across
	 * pages, none omitted and none duplicated.
	 *
	 * @return void
	 */
	public function test_tombstones_are_emitted_in_bounded_batches() {
		$email = $this->address( 'manytombs', strtolower( wp_generate_password( 10, false, false ) ) );
		$total = Exporter::IDENTITY_PAGE_SIZE + 6;

		$expected = array();
		for ( $i = 0; $i < $total; $i++ ) {
			$claim = $this->deliveries->claim( $this->fake_order_id() + $i, 320, 'separate', 'status:tomb-' . $i );
			$this->track_delivery( $claim['delivery_id'] );
			$this->track_detail(
				$this->details->insert(
					$claim['delivery_id'],
					array(
						'state'     => 'sent',
						'recipient' => $email,
					)
				)
			);
			$expected[] = 'extonify-wcep-identity-' . $claim['delivery_id'];
		}

		$exporter = new Exporter();
		$seen     = array();
		$pages    = 0;
		$page     = 1;
		do {
			$export = $exporter->export( $email, $page );
			$batch  = 0;
			foreach ( $export['data'] as $item ) {
				if ( 'extonify-wcep-delivery-identities' === $item['group_id'] ) {
					$seen[] = $item['item_id'];
					++$batch;
				}
			}
			$this->assertLessThanOrEqual(
				Exporter::IDENTITY_PAGE_SIZE,
				$batch,
				'A single page carried more tombstones than the batch size.'
			);
			++$page;
			++$pages;
		} while ( empty( $export['done'] ) && $pages < 50 );

		$this->assertGreaterThan( 1, $pages, 'The fixture should have needed more than one page.' );
		$this->assertCount( $total, $seen, 'Tombstones were omitted or duplicated across pages.' );
		$this->assertSame( count( $seen ), count( array_unique( $seen ) ), 'A tombstone was emitted twice.' );

		sort( $expected );
		sort( $seen );
		$this->assertSame( $expected, $seen );
	}

	/**
	 * Both tombstone classes export the same minimal record, and neither
	 * exports the internal hash.
	 *
	 * @return void
	 */
	public function test_both_tombstone_classes_export_the_same_minimal_record() {
		$f = $this->seed_shared_delivery();

		foreach ( array( $f['customer'], $f['manager'] ) as $who ) {
			$identity = null;
			foreach ( $this->export_all( $who ) as $item ) {
				if ( 'extonify-wcep-delivery-identities' === $item['group_id'] ) {
					$identity = wp_list_pluck( $item['data'], 'value', 'name' );
				}
			}

			$this->assertNotNull( $identity, "No delivery identity was exported for {$who}." );
			$this->assertSame( (string) $f['order_id'], $identity['Order'] );
			$this->assertArrayHasKey( 'Delivery mode', $identity );
			$this->assertArrayHasKey( 'Triggered by', $identity );
			$this->assertArrayHasKey( 'Outcome', $identity );
			$this->assertArrayHasKey( 'First recorded (UTC)', $identity );
			$this->assertArrayNotHasKey( 'identity_hash', $identity );
		}

		// And the tombstone survives erasure by either party.
		$hash = (string) $this->delivery_column( $f['delivery_id'], 'identity_hash' );
		$this->erase_all( $f['manager'] );
		$this->erase_all( $f['customer'] );
		$this->assertNotNull( $this->deliveries->find_by_hash( $hash ), 'A tombstone was erased.' );
	}
}
