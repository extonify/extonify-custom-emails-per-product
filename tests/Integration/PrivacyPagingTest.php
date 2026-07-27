<?php
/**
 * Tombstone discovery and privacy pagination (Prompt 2c Items 2 and 3).
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
 * Two defects covered here.
 *
 * ITEM 2 — tombstones were resolved only through the subject's ORDERS, so a row
 * matched directly by recipient whose order is missing left its owning
 * tombstone undiscovered: the export omitted a record that is genuinely
 * retained, and the eraser could report `items_retained => false` while the
 * tombstone demonstrably survived.
 *
 * ITEM 3 — WordPress's privacy exporter is paginated and expects
 * `done => false` until finished. Returning everything in one call times out on
 * a large history and produces no export at all.
 */
final class PrivacyPagingTest extends IntegrationTestCase {

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
	 * ITEM 2. A directly-matched row whose order does not exist still leads to
	 * its tombstone: exported, erased, and reported as retained.
	 *
	 * @return void
	 */
	public function test_tombstone_is_discovered_through_a_direct_recipient_match() {
		$email = 'wcep-orphan-' . strtolower( wp_generate_password( 10, false, false ) ) . '@example.test';

		// A tombstone against an order id that is NOT a real WooCommerce order,
		// so the order path contributes nothing at all.
		$claim = $this->deliveries->claim( $this->fake_order_id(), 210, 'insert', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );
		$this->track_detail(
			$this->details->insert(
				$claim['delivery_id'],
				array(
					'state'     => 'sent',
					'recipient' => $email,
					'subject'   => 'orphan-path-marker',
				)
			)
		);

		$subject = new SubjectData();
		$this->assertSame( array(), $subject->order_ids( $email ), 'Fixture is wrong: there should be no matching order.' );

		// The tombstone is nevertheless discovered, via the matched detail row.
		$tombstones = $subject->tombstones( $email );
		$this->assertCount( 1, $tombstones, 'The owning tombstone was not discovered from a direct recipient match.' );
		$this->assertSame( $claim['delivery_id'], (int) $tombstones[0]['id'] );

		// It appears in the export...
		$export   = ( new Exporter() )->export( $email );
		$identity = null;
		foreach ( $export['data'] as $item ) {
			if ( 'extonify-wcep-delivery-identities' === $item['group_id'] ) {
				$identity = $item;
			}
		}
		$this->assertNotNull( $identity, 'A retained tombstone was omitted from the export.' );

		// ...and the eraser reports it as retained rather than claiming
		// nothing was kept.
		$result = ( new Eraser() )->erase( $email );
		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'], 'A surviving tombstone was reported as not retained.' );
		$this->assertNotNull( $this->deliveries->find_by_hash( $claim['identity_hash'] ) );
	}

	/**
	 * ITEM 3. With more rows than one batch, the exporter pages: the first call
	 * reports `done => false` and successive pages complete the set without
	 * omitting or duplicating anything.
	 *
	 * @return void
	 */
	public function test_exporter_pages_without_omitting_or_duplicating() {
		$email = 'wcep-paged-' . strtolower( wp_generate_password( 10, false, false ) ) . '@example.test';
		$total = Exporter::PAGE_SIZE + 7;

		$claim = $this->deliveries->claim( $this->fake_order_id(), 211, 'separate', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );

		$expected = array();
		for ( $i = 0; $i < $total; $i++ ) {
			$id = $this->details->insert(
				$claim['delivery_id'],
				array(
					'state'     => 'sent',
					'recipient' => $email,
					'subject'   => 'row-' . $i,
				)
			);
			$this->track_detail( $id );
			$expected[] = 'extonify-wcep-delivery-' . $id;
		}

		$exporter = new Exporter();

		$first = $exporter->export( $email, 1 );
		$this->assertFalse( $first['done'], 'The exporter finished in one call despite having more rows than one page.' );

		// A page carries a full batch of attempt rows PLUS any tombstone
		// records due on that page (ADR-0010: identities are interleaved, not
		// dumped on the final page), so count the attempt group specifically.
		$attempts = array_filter(
			$first['data'],
			function ( $item ) {
				return 'extonify-wcep-deliveries' === $item['group_id'];
			}
		);
		$this->assertCount( Exporter::PAGE_SIZE, $attempts, 'The first page was not a full batch of attempt rows.' );

		$seen  = array();
		$page  = 1;
		$guard = 0;
		do {
			$export = $exporter->export( $email, $page );
			foreach ( $export['data'] as $item ) {
				if ( 'extonify-wcep-deliveries' === $item['group_id'] ) {
					$seen[] = $item['item_id'];
				}
			}
			++$page;
			++$guard;
		} while ( empty( $export['done'] ) && $guard < 20 );

		$this->assertTrue( (bool) $export['done'], 'The exporter never reported completion.' );
		$this->assertCount( $total, $seen, 'Paging omitted or duplicated attempt rows.' );
		$this->assertSame( count( $seen ), count( array_unique( $seen ) ), 'Paging returned a duplicate row.' );

		sort( $expected );
		$sorted_seen = $seen;
		sort( $sorted_seen );
		$this->assertSame( $expected, $sorted_seen );
	}

	/**
	 * The tombstone group is emitted exactly once, on the final page, so a
	 * paged export cannot duplicate it.
	 *
	 * @return void
	 */
	public function test_identities_are_emitted_once_on_the_final_page() {
		$email = 'wcep-ident-' . strtolower( wp_generate_password( 10, false, false ) ) . '@example.test';

		$claim = $this->deliveries->claim( $this->fake_order_id(), 212, 'separate', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );
		for ( $i = 0; $i < Exporter::PAGE_SIZE + 3; $i++ ) {
			$this->track_detail(
				$this->details->insert(
					$claim['delivery_id'],
					array(
						'state'     => 'sent',
						'recipient' => $email,
					)
				)
			);
		}

		$exporter   = new Exporter();
		$identities = 0;
		$page       = 1;
		$guard      = 0;
		do {
			$export = $exporter->export( $email, $page );
			foreach ( $export['data'] as $item ) {
				if ( 'extonify-wcep-delivery-identities' === $item['group_id'] ) {
					++$identities;
				}
			}
			++$page;
			++$guard;
		} while ( empty( $export['done'] ) && $guard < 20 );

		$this->assertSame( 1, $identities, 'The delivery identity was emitted on more than one page, or not at all.' );
	}

	/**
	 * The eraser is likewise batched, and completes across pages.
	 *
	 * @return void
	 */
	public function test_eraser_pages_and_completes() {
		$email = 'wcep-erasepage-' . strtolower( wp_generate_password( 10, false, false ) ) . '@example.test';
		$total = Eraser::PAGE_SIZE + 5;

		$claim = $this->deliveries->claim( $this->fake_order_id(), 213, 'separate', $this->unique_identity() );
		$this->track_delivery( $claim['delivery_id'] );
		for ( $i = 0; $i < $total; $i++ ) {
			$this->track_detail(
				$this->details->insert(
					$claim['delivery_id'],
					array(
						'state'     => 'sent',
						'recipient' => $email,
						'subject'   => 'to-be-erased',
					)
				)
			);
		}

		$eraser = new Eraser();

		$first = $eraser->erase( $email, 1 );
		$this->assertFalse( $first['done'], 'The eraser finished in one call despite having more rows than one page.' );
		$this->assertTrue( $first['items_removed'] );

		$page  = 2;
		$guard = 0;
		do {
			$result = $eraser->erase( $email, $page );
			++$page;
			++$guard;
		} while ( empty( $result['done'] ) && $guard < 20 );

		$this->assertTrue( (bool) $result['done'] );

		// Every row is now cleared — nothing was skipped by the paging.
		foreach ( $this->details->find_for_delivery( $claim['delivery_id'] ) as $row ) {
			$this->assertNull( $row['recipient'], 'Paged erasure skipped a row.' );
			$this->assertNull( $row['subject'], 'Paged erasure skipped a row.' );
		}
	}

	/**
	 * A single page still completes in one call, so the common case is not
	 * made slower or chattier by the paging.
	 *
	 * @return void
	 */
	public function test_small_result_completes_in_one_call() {
		$email = 'wcep-small-' . strtolower( wp_generate_password( 10, false, false ) ) . '@example.test';

		$claim = $this->deliveries->claim( $this->fake_order_id(), 214, 'separate', $this->unique_identity() );
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

		$export = ( new Exporter() )->export( $email, 1 );
		$this->assertTrue( $export['done'] );

		$erase = ( new Eraser() )->erase( $email, 1 );
		$this->assertTrue( $erase['done'] );
		$this->assertTrue( $erase['items_removed'] );
	}
}
