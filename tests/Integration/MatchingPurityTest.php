<?php
/**
 * The engine writes nothing, and its cost does not scale with rules
 * (ADR-0011 §9).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Domain\TriggerEvent;
use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Matching\ItemResolver;
use Extonify\WCEP\Matching\RuleMatcher;

/**
 * Purity is the engine's central promise: the delivery phase decides what to
 * record, so the matcher deciding anything for it would make claims,
 * log rows and idempotency untestable in isolation.
 *
 * Row counts alone are weak evidence — a write followed by a delete would pass
 * — so every statement the request issues is captured and inspected.
 */
final class MatchingPurityTest extends MatchingTestCase {

	/**
	 * Write statements captured during the window under test.
	 *
	 * @var string[]
	 */
	private $captured = array();

	/**
	 * Tables no evaluation may write to.
	 *
	 * The plugin's own three, plus every table WooCommerce keeps an order in
	 * under both HPOS and legacy post storage.
	 *
	 * @return string[]
	 */
	private function forbidden_tables(): array {
		global $wpdb;

		return array(
			Migrator::table( 'rules' ),
			Migrator::table( 'deliveries' ),
			Migrator::table( 'delivery_details' ),
			$wpdb->prefix . 'wc_orders',
			$wpdb->prefix . 'wc_orders_meta',
			$wpdb->prefix . 'wc_order_addresses',
			$wpdb->prefix . 'wc_order_operational_data',
			$wpdb->prefix . 'woocommerce_order_items',
			$wpdb->prefix . 'woocommerce_order_itemmeta',
		);
	}

	/**
	 * Run a callback with every write statement recorded.
	 *
	 * @param callable $callback Work to observe.
	 * @return mixed The callback's return value.
	 */
	private function capturing_writes( callable $callback ) {
		$this->captured = array();

		$recorder = function ( $query ) {
			if ( preg_match( '/^\s*(insert|update|delete|replace|truncate|alter|drop|create)\b/i', (string) $query ) ) {
				$this->captured[] = (string) $query;
			}
			return $query;
		};

		add_filter( 'query', $recorder );
		try {
			return $callback();
		} finally {
			remove_filter( 'query', $recorder );
		}
	}

	/**
	 * A comparable projection of an order, read through CRUD only.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	private function order_projection( int $order_id ): array {
		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $order );

		$items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$items[ (int) $item_id ] = array(
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'quantity'     => (int) $item->get_quantity(),
				'total'        => (string) $item->get_total(),
			);
		}

		$modified = $order->get_date_modified();

		return array(
			'status'        => $order->get_status(),
			'total'         => (string) $order->get_total(),
			'customer_note' => $order->get_customer_note(),
			'billing_email' => $order->get_billing_email(),
			'date_modified' => $modified ? $modified->getTimestamp() : null,
			'items'         => $items,
		);
	}

	/**
	 * Row counts in the plugin's two delivery tables.
	 *
	 * @return array
	 */
	private function delivery_row_counts(): array {
		global $wpdb;

		$deliveries = Migrator::table( 'deliveries' );
		$details    = Migrator::table( 'delivery_details' );

		return array(
			'deliveries'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$deliveries}" ), // phpcs:ignore
			'delivery_details' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$details}" ), // phpcs:ignore
		);
	}

	/**
	 * 16. A full mixed-cart evaluation writes NOTHING.
	 *
	 * @return void
	 */
	public function test_evaluation_is_pure() {
		$cat_id = $this->make_term( 'product_cat', 'WCEP Purity Cat' );
		$tag_id = $this->make_term( 'product_tag', 'WCEP Purity Tag' );

		$simple      = $this->make_simple_product( 'WCEP Purity Simple', array( 'category_ids' => array( $cat_id ) ) );
		$virtual     = $this->make_simple_product( 'WCEP Purity Virtual', array( 'virtual' => true ) );
		$tagged      = $this->make_simple_product( 'WCEP Purity Tagged', array( 'tag_ids' => array( $tag_id ) ) );
		$doomed      = $this->make_simple_product( 'WCEP Purity Doomed' );
		$variable    = $this->make_variable_product( 'WCEP Purity Variable', array( 'Small', 'Large' ) );
		$variation_a = $variable['variations'][0];

		$order = $this->make_order_with(
			array(
				array( $simple, 3 ),
				$virtual,
				$tagged,
				$doomed,
				$variation_a,
			)
		);

		// A deleted product on the order, so the unresolvable path is exercised
		// too — that is where a careless implementation would try to repair
		// something.
		wc_get_product( $doomed )->delete( true );

		$this->make_rule( array( 'targeting' => array( 'include' => array( 'products' => array( $simple ) ) ) ) );
		$this->make_rule( array( 'targeting' => array( 'include' => array( 'categories' => array( $cat_id ) ) ) ) );
		$this->make_rule( array( 'targeting' => array( 'include' => array( 'tags' => array( $tag_id ) ) ) ) );
		$this->make_rule( array( 'targeting' => array( 'include' => array( 'types' => array( 'virtual' ) ) ) ) );
		$this->make_rule( array( 'targeting' => array( 'include' => array( 'variations' => array( $variation_a ) ) ) ) );
		$this->make_rule(
			array(
				'priority'  => 99,
				'targeting' => array(
					'match_all' => true,
					'exclude'   => array( 'products' => array( $simple ) ),
				),
			)
		);

		$order_id = (int) $order->get_id();

		// Warm every cache first, so the measurement below observes the
		// evaluation and not WooCommerce's own lazy loading.
		$this->matcher()->evaluate( wc_get_order( $order_id ), TriggerEvent::status( 'completed' ) );

		$counts_before     = $this->delivery_row_counts();
		$projection_before = $this->order_projection( $order_id );
		$mail_before       = count( $this->mail_attempts );

		$result = $this->capturing_writes(
			function () use ( $order_id ) {
				return $this->matcher()->evaluate( wc_get_order( $order_id ), TriggerEvent::status( 'completed' ) );
			}
		);

		// The evaluation really did do the work, so "nothing was written" is
		// not the trivial consequence of nothing having happened.
		$this->assertNotEmpty( $result->decisions() );
		$this->assertNotEmpty( $result->actionable() );
		$this->assertNotEmpty( $result->unavailable_item_ids() );

		$this->assertSame( $counts_before, $this->delivery_row_counts(), 'The engine wrote to a delivery table.' );
		$this->assertSame( $projection_before, $this->order_projection( $order_id ), 'The engine modified the order.' );
		$this->assertSame( $mail_before, count( $this->mail_attempts ), 'The engine attempted to send mail.' );

		foreach ( $this->captured as $statement ) {
			foreach ( $this->forbidden_tables() as $table ) {
				$this->assertStringNotContainsStringIgnoringCase(
					$table,
					$statement,
					'The engine issued a write against ' . $table . ': ' . $statement
				);
			}
		}
	}

	/**
	 * 16. The evaluation issues no write statement at all on a warm cache —
	 *     stricter than the table allowlist above, and reported when it fails
	 *     so a future WooCommerce lazy-write is diagnosable rather than
	 *     mysterious.
	 *
	 * @return void
	 */
	public function test_evaluation_issues_no_write_statements() {
		$product_id = $this->make_simple_product( 'WCEP Pure Simple' );
		$order      = $this->make_order_with( array( $product_id ) );
		$this->make_rule( array( 'targeting' => array( 'include' => array( 'products' => array( $product_id ) ) ) ) );

		$order_id = (int) $order->get_id();
		$this->matcher()->evaluate( wc_get_order( $order_id ), TriggerEvent::status( 'completed' ) );

		$matcher = $this->matcher();
		$this->capturing_writes(
			function () use ( $matcher, $order_id ) {
				return $matcher->evaluate( wc_get_order( $order_id ), TriggerEvent::status( 'completed' ) );
			}
		);

		$this->assertSame( array(), $this->captured, "The engine issued write statements:\n" . implode( "\n", $this->captured ) );
	}

	/**
	 * Gate 6. Evaluating a 10-item order against 20 rules performs a bounded
	 * number of queries that does NOT scale with rules × items.
	 *
	 * @return void
	 */
	public function test_query_count_does_not_scale_with_rules() {
		global $wpdb;

		$products = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$products[] = $this->make_simple_product( 'WCEP Perf ' . $i );
		}

		$order    = $this->make_order_with( $products );
		$order_id = (int) $order->get_id();

		$rules_20 = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$rules_20[] = $this->make_rule(
				array(
					'priority'  => 10 + $i,
					'targeting' => array( 'include' => array( 'products' => array( $products[ $i % 10 ] ) ) ),
				)
			);
		}

		/*
		 * COLD measures the honest per-request cost: the object cache is
		 * flushed, so every product really is read from the database. WARM is
		 * what a second evaluation in the same request costs. Both matter —
		 * the first is the number a store actually pays, the second proves the
		 * request cache exists.
		 */
		$measure = function ( bool $cold ) use ( $order_id, $wpdb ): array {
			if ( $cold ) {
				wp_cache_flush();
			}

			$matcher = new RuleMatcher( $this->rules, new ItemResolver() );

			// Loading the order is the caller's cost, not the engine's.
			$order = wc_get_order( $order_id );

			$before = $wpdb->num_queries;
			$result = $matcher->evaluate( $order, TriggerEvent::status( 'completed' ) );

			return array( $wpdb->num_queries - $before, $result );
		};

		list( $cold_20, $result_20 ) = $measure( true );
		list( $warm_20 )             = $measure( false );

		$this->assertCount( 20, $this->own_decisions( $result_20 ), 'All 20 rules should have been evaluated.' );

		// Double the rules; the query count must not move.
		for ( $i = 0; $i < 20; $i++ ) {
			$this->make_rule(
				array(
					'priority'  => 40 + $i,
					'targeting' => array( 'include' => array( 'products' => array( $products[ $i % 10 ] ) ) ),
				)
			);
		}

		list( $cold_40, $result_40 ) = $measure( true );
		list( $warm_40 )             = $measure( false );

		$this->assertCount( 40, $this->own_decisions( $result_40 ) );

		$this->assertSame(
			$cold_20,
			$cold_40,
			"Query count scaled with rule count: {$cold_20} for 20 rules, {$cold_40} for 40."
		);
		$this->assertSame( $warm_20, $warm_40 );

		// A warm second evaluation costs exactly the rule fetch: every product,
		// category and tag is already resolved for the request.
		$this->assertSame( 1, $warm_20, 'A warm evaluation should cost one query: the rule fetch.' );

		// The cold cost is bounded well below rules × items (20 × 10 = 200),
		// which is what a naive per-rule-per-item implementation would cost.
		$this->assertLessThan( 200, $cold_20 );
		$this->assertLessThanOrEqual(
			60,
			$cold_20,
			"Evaluating a 10-item order cold took {$cold_20} queries; the bound is one rule fetch plus a constant per DISTINCT product."
		);

		fwrite(
			STDERR,
			"\n[gate 6] 10-item order, 10 distinct products:"
			. " cold {$cold_20} queries against 20 rules / {$cold_40} against 40;"
			. " warm {$warm_20} / {$warm_40}.\n"
		);
	}

	/**
	 * Gate 6. The cost tracks DISTINCT PRODUCTS, and a repeated product is
	 * free — the request cache doing its job.
	 *
	 * @return void
	 */
	public function test_query_count_tracks_distinct_products() {
		global $wpdb;

		$product_id = $this->make_simple_product( 'WCEP Repeat' );
		$this->make_rule( array( 'targeting' => array( 'include' => array( 'products' => array( $product_id ) ) ) ) );

		$one   = $this->make_order_with( array( $product_id ) );
		$lines = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$lines[] = $product_id;
		}
		$ten = $this->make_order_with( $lines );

		$measure = function ( int $order_id ) use ( $wpdb ): int {
			$matcher = new RuleMatcher( $this->rules, new ItemResolver() );
			$order   = wc_get_order( $order_id );

			$before = $wpdb->num_queries;
			$matcher->evaluate( $order, TriggerEvent::status( 'completed' ) );

			return $wpdb->num_queries - $before;
		};

		$measure( (int) $one->get_id() );
		$measure( (int) $ten->get_id() );

		$this->assertSame(
			$measure( (int) $one->get_id() ),
			$measure( (int) $ten->get_id() ),
			'Ten line items of ONE product cost more than one: the product cache is not working.'
		);
	}

	/**
	 * Gate 6. Both trigger families of one status change share a single item
	 * resolution pass.
	 *
	 * @return void
	 */
	public function test_both_families_share_one_resolution_pass() {
		global $wpdb;

		$product_id = $this->make_simple_product( 'WCEP Shared Resolution' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$this->make_rule( array( 'targeting' => array( 'match_all' => true ) ) );
		$this->make_rule(
			array(
				'trigger_type'  => 'transition',
				'trigger_value' => 'pending>completed',
				'targeting'     => array( 'match_all' => true ),
			)
		);

		$matcher = $this->matcher();
		$matcher->evaluate( wc_get_order( $order_id ), TriggerEvent::status( 'completed' ) );

		// The resolver already holds this order, so the SECOND family costs
		// exactly its own rule fetch and nothing else.
		$before = $wpdb->num_queries;
		$matcher->evaluate( wc_get_order( $order_id ), TriggerEvent::transition( 'pending', 'completed' ) );

		$this->assertSame( 1, $wpdb->num_queries - $before, 'The second trigger family re-resolved the order items.' );
	}
}
