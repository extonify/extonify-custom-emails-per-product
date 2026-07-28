<?php
/**
 * An order whose contents change within one request (ADR-0008, ADR-0012 §11b).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\DeferredEvaluation;
use Extonify\WCEP\Domain\TriggerEvent;
use Extonify\WCEP\Matching\ItemResolver;

/**
 * ONE resolver serves the whole request, so anything it caches is a claim that
 * the fact cannot change before the request ends.
 *
 * It cached RESOLVED ORDER CONTENTS, keyed by order id, and returned them on a
 * hit. That claim is false for the one lifecycle this plugin exists to support:
 * `wc_create_order( array( 'status' => 'processing' ) )`, the REST API and CSV
 * importers all fire the transition BEFORE attaching items, so an order is
 * routinely empty at one event and full at the next — in the same request.
 *
 * The consequence was that ADR-0008 was defeated by a cache. The order deferred
 * because it had no items, the items arrived, and the next event read
 * `item_count = 0` from the cache and deferred all over again instead of
 * matching. Items replaced or removed mid-request were stale the same way.
 *
 * These tests drive REAL status transitions and let the plugin's own registered
 * `Events` handle them — which is the sharpest possible version of the case,
 * because `Events` holds ONE orchestrator, one matcher and one resolver for the
 * entire PHP process.
 */
final class OrderMutationTest extends DeliveryTestCase {

	/**
	 * Filters registered by a test, removed on teardown.
	 *
	 * @var array[]
	 */
	private $hooks = array();

	/**
	 * Scheduled deferrals to cancel on teardown.
	 *
	 * @var array[]
	 */
	private $scheduled = array();

	/**
	 * Remove filters and cancel any deferral a test caused.
	 *
	 * @after
	 * @return void
	 */
	protected function tear_down_order_mutation() {
		foreach ( $this->hooks as $hook ) {
			list( $tag, $callback, $priority ) = $hook;
			remove_filter( $tag, $callback, $priority );
		}

		$this->hooks = array();

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( $this->scheduled as $args ) {
				as_unschedule_all_actions( DeferredEvaluation::HOOK, $args, DeferredEvaluation::GROUP );
			}
		}

		$this->scheduled = array();
	}

	/**
	 * Switch WooCommerce's own transactional emails off, so the message counts
	 * measure this plugin rather than core.
	 *
	 * @return void
	 */
	private function silence_core_emails(): void {
		foreach ( array( 'new_order', 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order' ) as $id ) {
			add_filter( 'woocommerce_email_enabled_' . $id, '__return_false', 10, 3 );
			$this->hooks[] = array( 'woocommerce_email_enabled_' . $id, '__return_false', 10 );
		}
	}

	/**
	 * Track every deferral identity an order could have produced.
	 *
	 * @param int      $order_id Order id.
	 * @param string[] $statuses Destination statuses the test will move through.
	 * @return void
	 */
	private function track_deferrals( int $order_id, array $statuses ): void {
		foreach ( $statuses as $to ) {
			$this->scheduled[] = DeferredEvaluation::args( $order_id, TriggerEvent::status( $to ) );
			$this->scheduled[] = DeferredEvaluation::args( $order_id, TriggerEvent::transition( 'pending', $to ) );
		}
	}

	/**
	 * An order with NO line items, created the way importers and the REST API
	 * do it — status first, items later.
	 *
	 * @return \WC_Order
	 */
	private function make_empty_order(): \WC_Order {
		$order = wc_create_order();
		$order->set_billing_email( 'mutation@example.test' );
		$order->save();

		$this->order_ids[] = (int) $order->get_id();

		$this->assertCount( 0, $order->get_items(), 'The fixture order should have no items yet.' );

		return $order;
	}

	/**
	 * 1. ITEMS ATTACHED AFTER THE FIRST EVENT, IN THE SAME REQUEST.
	 *
	 * This is ADR-0008's motivating lifecycle end to end. The first event finds
	 * an empty order and defers; the items arrive; the second REAL status event
	 * must MATCH AND SEND. While the resolution was cached by order id it read
	 * the stale `item_count = 0` and deferred again, so the email never went.
	 *
	 * @return void
	 */
	public function test_items_attached_mid_request_are_seen_by_the_next_event() {
		$this->silence_core_emails();

		$product_id = $this->make_simple_product( 'WCEP Mutation Attach' );
		$order      = $this->make_empty_order();
		$order_id   = (int) $order->get_id();

		$this->track_deferrals( $order_id, array( 'processing', 'completed' ) );

		// A candidate on the FIRST status, so the empty order really does defer
		// (ADR-0012 §7a), and one on the second, which is what must send.
		$first_rule  = $this->make_sending_rule(
			$product_id,
			array(
				'name'          => 'mutation first',
				'trigger_value' => 'processing',
				'subject'       => 'Should not send: still empty',
			)
		);
		$second_rule = $this->make_sending_rule(
			$product_id,
			array(
				'name'          => 'mutation second',
				'trigger_value' => 'completed',
				'subject'       => 'Sent after the items arrived',
			)
		);

		// --- Event 1: real transition on an EMPTY order. ---------------------
		$order->update_status( 'processing', 'mutation fixture' );

		$this->assertMailCount( 0, 'An empty order sent an email.' );
		$this->assertNull(
			$this->tombstone( $order_id, $first_rule, 'status:processing' ),
			'A deferring event must claim nothing.'
		);

		// --- The items arrive, exactly as an importer would attach them. -----
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->calculate_totals();
		$order->save();

		$this->assertCount( 1, wc_get_order( $order_id )->get_items(), 'The fixture did not actually attach the item.' );

		// --- Event 2: real transition, SAME request, SAME shared resolver. ---
		$order->update_status( 'completed', 'mutation fixture' );

		$this->assertMailCount( 1, 'The second event did not send: the resolver replayed the empty order.' );
		$this->assertSame( 'Sent after the items arrived', $this->last_mail()['subject'] );

		$tombstone = $this->tombstone( $order_id, $second_rule, 'status:completed' );
		$this->assertNotNull( $tombstone, 'The second event never claimed — it saw a stale empty order.' );
		$this->track_delivery( (int) $tombstone['id'] );
		$this->assertSame( 'sent', $tombstone['final_status'] );

		// The second event had items, so it must NOT have queued a deferral.
		$this->assertFalse(
			DeferredEvaluation::is_scheduled( $order_id, TriggerEvent::status( 'completed' ) ),
			'An order WITH items scheduled a deferral.'
		);
	}

	/**
	 * 2. THE LINE ITEM IS REPLACED MID-REQUEST.
	 *
	 * Product A is swapped for product B between two events. The rule targeting
	 * B must match and the rule targeting A must not. Against the cached
	 * resolution the answer inverted: A still matched and B never did.
	 *
	 * @return void
	 */
	public function test_a_replaced_line_item_is_seen_by_the_next_event() {
		$this->silence_core_emails();

		$product_a = $this->make_simple_product( 'WCEP Mutation A' );
		$product_b = $this->make_simple_product( 'WCEP Mutation B' );

		$order    = $this->make_order_with( array( $product_a ) );
		$order_id = (int) $order->get_id();

		$this->track_deferrals( $order_id, array( 'processing', 'completed' ) );

		$rule_a = $this->make_sending_rule(
			$product_a,
			array(
				'name'          => 'targets A',
				'trigger_value' => 'completed',
				'subject'       => 'A subject',
			)
		);
		$rule_b = $this->make_sending_rule(
			$product_b,
			array(
				'name'          => 'targets B',
				'trigger_value' => 'completed',
				'subject'       => 'B subject',
			)
		);

		// --- Event 1 primes the resolver with product A. ---------------------
		// Nothing targets `processing`, so this sends nothing while still
		// resolving the order's contents.
		$order->update_status( 'processing', 'mutation fixture' );
		$this->assertMailCount( 0, 'Nothing targets the priming status.' );

		// --- Swap the line item. ---------------------------------------------
		foreach ( $order->get_items() as $item_id => $item ) {
			$order->remove_item( $item_id );
		}
		$order->add_product( wc_get_product( $product_b ), 1 );
		$order->calculate_totals();
		$order->save();

		$reloaded = wc_get_order( $order_id );
		$this->assertCount( 1, $reloaded->get_items(), 'The fixture left the wrong number of items.' );
		foreach ( $reloaded->get_items() as $item ) {
			$this->assertSame( $product_b, (int) $item->get_product_id(), 'The fixture did not actually replace the item.' );
		}

		// --- Event 2 must evaluate the CURRENT contents. ---------------------
		$order->update_status( 'completed', 'mutation fixture' );

		$this->assertMailCount( 1, 'Exactly one rule should have matched the replaced item.' );
		$this->assertSame(
			'B subject',
			$this->last_mail()['subject'],
			'The evaluation used the order contents as they were BEFORE the swap.'
		);

		$tombstone_b = $this->tombstone( $order_id, $rule_b, 'status:completed' );
		$this->assertNotNull( $tombstone_b, 'The rule targeting the CURRENT product never claimed.' );
		$this->track_delivery( (int) $tombstone_b['id'] );
		$this->assertSame( 'sent', $tombstone_b['final_status'] );

		$this->assertNull(
			$this->tombstone( $order_id, $rule_a, 'status:completed' ),
			'The rule targeting the REMOVED product still matched (ADR-0005 noise floor: it must write nothing).'
		);
	}

	/**
	 * The resolver has no order-keyed cache left to go stale.
	 *
	 * Asserted structurally as well as behaviourally, so re-introducing one is a
	 * deliberate act that fails here first (ADR-0012 §11b).
	 *
	 * @return void
	 */
	public function test_the_resolver_holds_no_order_keyed_cache() {
		$properties = array();

		foreach ( ( new \ReflectionClass( ItemResolver::class ) )->getProperties() as $property ) {
			$properties[] = $property->getName();
		}

		sort( $properties );

		$this->assertSame(
			array( 'facts', 'products' ),
			$properties,
			'ItemResolver gained a property; every cache must declare its invalidation (ADR-0012 §11b).'
		);
	}

	/**
	 * Two evaluations of the SAME unchanged order still agree, so removing the
	 * cache did not trade staleness for instability.
	 *
	 * @return void
	 */
	public function test_repeated_resolution_of_an_unchanged_order_is_stable() {
		$product_id = $this->make_simple_product( 'WCEP Mutation Stable' );
		$order      = $this->make_order_with( array( $product_id, $product_id ) );

		$resolver = new ItemResolver();

		$first  = $resolver->resolve_order( $order );
		$second = $resolver->resolve_order( $order );

		$this->assertSame( $first, $second, 'Two resolutions of one unchanged order disagreed.' );
		$this->assertSame( 2, $first['item_count'] );
	}

	/**
	 * Gate 6. THE PRICE OF REMOVING THE ORDER CACHE, measured directly.
	 *
	 * A second resolution of the same order used to be a cache hit. It now
	 * re-walks the line items — and costs nothing extra, because
	 * `WC_Order::get_items()` is memoised on the order object, the item meta is
	 * already in WordPress's cache, and every product fact is still cached by
	 * product id.
	 *
	 * @return void
	 */
	public function test_re_resolving_an_order_costs_no_extra_queries() {
		global $wpdb;

		$products = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$products[] = $this->make_simple_product( 'WCEP Mutation Cost ' . $i );
		}

		$order    = $this->make_order_with( $products );
		$resolver = new ItemResolver();

		// First pass pays for everything.
		$first_before = $wpdb->num_queries;
		$first        = $resolver->resolve_order( $order );
		$first_cost   = $wpdb->num_queries - $first_before;

		// Second pass is what the removed cache used to make free.
		$second_before = $wpdb->num_queries;
		$second        = $resolver->resolve_order( $order );
		$second_cost   = $wpdb->num_queries - $second_before;

		$this->assertSame( $first, $second );
		$this->assertSame( 10, $first['item_count'] );

		$this->assertSame(
			0,
			$second_cost,
			"Re-resolving an order cost {$second_cost} queries; the product caches should make it free."
		);

		fwrite(
			STDERR,
			"\n[gate 6] resolving a 10-item / 10-product order: first pass {$first_cost} queries,"
			. " second pass {$second_cost} (the removed order cache cost nothing).\n"
		);
	}

	/**
	 * Gate 6. Both trigger families of one status change, one sending rule,
	 * twenty candidates — the cost still does not scale with rules × items with
	 * the order cache gone.
	 *
	 * @return void
	 */
	public function test_both_families_still_cost_a_bounded_number_of_queries() {
		global $wpdb;

		$this->silence_core_emails();

		$products = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$products[] = $this->make_simple_product( 'WCEP Mutation Perf ' . $i );
		}

		// Targeted by nineteen rules and present on NO order, so those rules are
		// evaluated in full and none of them sends.
		$absent = $this->make_simple_product( 'WCEP Mutation Perf Absent' );

		$order    = $this->make_order_with( $products );
		$order_id = (int) $order->get_id();

		for ( $i = 0; $i < 20; $i++ ) {
			$this->make_rule(
				array(
					'priority'      => 10 + $i,
					'trigger_value' => 'completed',
					'recipients'    => array( 'to' => array( 'customer' ) ),
					'targeting'     => array(
						'include' => array(
							'products' => array( 0 === $i ? $products[0] : $absent ),
						),
					),
				)
			);
		}

		$orchestrator = $this->orchestrator();

		wp_cache_flush();

		$before = $wpdb->num_queries;
		$orchestrator->handle_status_change( $order_id, 'pending', 'completed' );
		$cost = $wpdb->num_queries - $before;

		$this->assertMailCount( 1, 'Exactly one rule should have sent.' );

		foreach ( $this->tombstones_for( $order_id ) as $row ) {
			$this->track_delivery( (int) $row['id'] );
		}

		$this->assertLessThan(
			200,
			$cost,
			"A status change on a 10-item order against 20 rules took {$cost} queries; rules x items would be 200."
		);

		fwrite(
			STDERR,
			"\n[gate 6] status change, BOTH families, 10-item order, 20 rules (1 sending),"
			. " order cache removed: {$cost} queries.\n"
		);
	}
}
