<?php
/**
 * Fixtures shared by the matching-engine integration tests.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Matching\ItemResolver;
use Extonify\WCEP\Matching\RuleMatcher;
use Extonify\WCEP\Repository\RuleRepository;

/**
 * Real products, variations, terms, orders and rules through WooCommerce CRUD.
 *
 * Terms get their own tracked teardown; everything else rides on
 * IntegrationTestCase's verify-after-delete discipline.
 */
abstract class MatchingTestCase extends IntegrationTestCase {

	/**
	 * Terms created by this test: `[ taxonomy, term_id ]` pairs.
	 *
	 * @var array[]
	 */
	protected $term_ids = array();

	/**
	 * Refund ids created by this test.
	 *
	 * @var int[]
	 */
	protected $refund_ids = array();

	/**
	 * Rule storage.
	 *
	 * @var RuleRepository|null
	 */
	protected $rules = null;

	/**
	 * Mail the runtime attempted to send while this test ran.
	 *
	 * @var array[]
	 */
	protected $mail_attempts = array();

	/**
	 * The `pre_wp_mail` short-circuit, kept so it can be removed again.
	 *
	 * @var callable|null
	 */
	private $mail_blocker = null;

	/**
	 * Build the rule repository and block outgoing mail.
	 *
	 * Fixtures like `wc_create_refund()` make WooCommerce send a real email,
	 * which on a box without sendmail writes shell noise into the run. Blocking
	 * it keeps the output clean and gives the purity test a counter to assert
	 * against: the matching engine must not cause a single send.
	 *
	 * @before
	 * @return void
	 */
	protected function set_up_matching() {
		$this->rules         = new RuleRepository();
		$this->mail_attempts = array();

		$this->mail_blocker = function ( $short_circuit, $atts ) {
			$this->mail_attempts[] = $atts;
			return true;
		};

		add_filter( 'pre_wp_mail', $this->mail_blocker, 10, 2 );
	}

	/**
	 * Restore mail delivery.
	 *
	 * @after
	 * @return void
	 */
	protected function tear_down_mail_blocker() {
		if ( null !== $this->mail_blocker ) {
			remove_filter( 'pre_wp_mail', $this->mail_blocker, 10 );
			$this->mail_blocker = null;
		}
	}

	/**
	 * Remove terms and refunds, then verify they are gone.
	 *
	 * @after
	 * @return void
	 */
	protected function tear_down_matching_fixtures() {
		foreach ( $this->refund_ids as $refund_id ) {
			$refund = wc_get_order( $refund_id );
			if ( $refund ) {
				$refund->delete( true );
			}
		}

		$leaks = array();
		foreach ( $this->term_ids as $tracked ) {
			list( $taxonomy, $term_id ) = $tracked;
			wp_delete_term( $term_id, $taxonomy );
			if ( get_term( $term_id, $taxonomy ) instanceof \WP_Term ) {
				$leaks[] = $taxonomy . ':' . $term_id;
			}
		}

		$this->term_ids   = array();
		$this->refund_ids = array();

		$this->assertSame( array(), $leaks, 'Term teardown left rows behind: ' . implode( ', ', $leaks ) );
	}

	/**
	 * A matcher wired to a fresh item resolver, so no test inherits another's
	 * product cache.
	 *
	 * @return RuleMatcher
	 */
	protected function matcher(): RuleMatcher {
		return new RuleMatcher( $this->rules, new ItemResolver() );
	}

	/**
	 * Create a product term and track it for teardown.
	 *
	 * @param string $taxonomy `product_cat` or `product_tag`.
	 * @param string $name     Term name.
	 * @return int Term id.
	 */
	protected function make_term( string $taxonomy, string $name ): int {
		$term = wp_insert_term( $name . ' ' . wp_generate_password( 6, false, false ), $taxonomy );
		$this->assertIsArray( $term, 'Could not create the ' . $taxonomy . ' fixture.' );

		$term_id          = (int) $term['term_id'];
		$this->term_ids[] = array( $taxonomy, $term_id );

		return $term_id;
	}

	/**
	 * Create a simple product with optional taxonomy and flags.
	 *
	 * @param string $name Product name.
	 * @param array  $args {
	 *     Optional product properties.
	 *
	 *     @type int[] $category_ids Category term ids.
	 *     @type int[] $tag_ids      Tag term ids.
	 *     @type bool  $virtual      Virtual flag.
	 *     @type bool  $downloadable Downloadable flag.
	 * }
	 * @return int Product id.
	 */
	protected function make_simple_product( string $name = 'WCEP Product', array $args = array() ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '10.00' );
		$product->set_price( '10.00' );
		$product->set_status( 'publish' );

		if ( ! empty( $args['category_ids'] ) ) {
			$product->set_category_ids( array_map( 'intval', $args['category_ids'] ) );
		}
		if ( ! empty( $args['tag_ids'] ) ) {
			$product->set_tag_ids( array_map( 'intval', $args['tag_ids'] ) );
		}
		if ( ! empty( $args['virtual'] ) ) {
			$product->set_virtual( true );
		}
		if ( ! empty( $args['downloadable'] ) ) {
			$product->set_downloadable( true );
		}

		$id = (int) $product->save();
		$this->assertGreaterThan( 0, $id, 'Could not create the product fixture.' );

		$this->product_ids[] = $id;
		return $id;
	}

	/**
	 * Create a variable product with one custom attribute and N variations.
	 *
	 * @param string   $name    Product name.
	 * @param string[] $options Attribute values, one variation each.
	 * @param array    $args    Same optional properties as make_simple_product().
	 * @return array {
	 *     @type int   $parent     Parent product id.
	 *     @type int[] $variations Variation ids, in $options order.
	 * }
	 */
	protected function make_variable_product( string $name, array $options, array $args = array() ): array {
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( $options );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = new \WC_Product_Variable();
		$parent->set_name( $name );
		$parent->set_status( 'publish' );
		$parent->set_attributes( array( $attribute ) );

		if ( ! empty( $args['category_ids'] ) ) {
			$parent->set_category_ids( array_map( 'intval', $args['category_ids'] ) );
		}
		if ( ! empty( $args['tag_ids'] ) ) {
			$parent->set_tag_ids( array_map( 'intval', $args['tag_ids'] ) );
		}

		$parent_id = (int) $parent->save();
		$this->assertGreaterThan( 0, $parent_id, 'Could not create the variable product fixture.' );
		$this->product_ids[] = $parent_id;

		$variations = array();
		foreach ( $options as $option ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( array( 'size' => $option ) );
			$variation->set_regular_price( '12.00' );
			$variation->set_status( 'publish' );

			if ( ! empty( $args['virtual'] ) ) {
				$variation->set_virtual( true );
			}
			if ( ! empty( $args['downloadable'] ) ) {
				$variation->set_downloadable( true );
			}

			$variation_id = (int) $variation->save();
			$this->assertGreaterThan( 0, $variation_id, 'Could not create the variation fixture.' );

			$this->product_ids[] = $variation_id;
			$variations[]        = $variation_id;
		}

		return array(
			'parent'     => $parent_id,
			'variations' => $variations,
		);
	}

	/**
	 * Create an order from a list of `[ product-or-variation id, quantity ]`
	 * lines, through WooCommerce CRUD.
	 *
	 * @param array[] $lines Each entry `array( id, qty )`; qty defaults to 1.
	 * @return \WC_Order
	 */
	protected function make_order_with( array $lines ): \WC_Order {
		$order = wc_create_order();

		foreach ( $lines as $line ) {
			$product_id = (int) ( is_array( $line ) ? $line[0] : $line );
			$quantity   = (int) ( is_array( $line ) && isset( $line[1] ) ? $line[1] : 1 );

			$product = wc_get_product( $product_id );
			$this->assertInstanceOf( \WC_Product::class, $product, 'Order fixture referenced a missing product.' );

			$order->add_product( $product, $quantity );
		}

		$order->set_billing_email( 'wcep-matching@example.test' );
		$order->calculate_totals();
		$order->save();

		$this->order_ids[] = (int) $order->get_id();

		return $order;
	}

	/**
	 * Insert a rule, tracked for teardown.
	 *
	 * Defaults produce an ACTIVE, separate-mode `status:completed` rule with no
	 * targeting; override whatever the test is about.
	 *
	 * @param array $overrides Rule fields.
	 * @return int Rule id.
	 */
	protected function make_rule( array $overrides = array() ): int {
		$rule_id = $this->rules->insert(
			array_merge(
				array(
					'name'          => 'WCEP matching fixture',
					'status'        => 'active',
					'priority'      => 10,
					'trigger_type'  => 'status',
					'trigger_value' => 'completed',
					'delivery_mode' => 'separate',
					'subject'       => 'Fixture',
					'content'       => 'Fixture body',
				),
				$overrides
			)
		);

		$this->assertGreaterThan( 0, $rule_id, 'Could not create the rule fixture.' );

		return $this->track_rule( $rule_id );
	}

	/**
	 * Store a raw string in a rule's `targeting` column, bypassing the
	 * repository's encoder — the only way to simulate a column corrupted by a
	 * bad import or direct database access.
	 *
	 * @param int    $rule_id Rule id.
	 * @param string $json    Raw column value.
	 * @return void
	 */
	protected function force_raw_targeting( int $rule_id, string $json ) {
		global $wpdb;

		$updated = $wpdb->update(
			\Extonify\WCEP\Install\Migrator::table( 'rules' ),
			array( 'targeting' => $json ),
			array( 'id' => $rule_id ),
			array( '%s' ),
			array( '%d' )
		);

		$this->assertNotFalse( $updated, 'Could not write the raw targeting fixture.' );
	}

	/**
	 * Track a refund for teardown.
	 *
	 * @param int $refund_id Refund id.
	 * @return int
	 */
	protected function track_refund( int $refund_id ): int {
		$this->refund_ids[] = $refund_id;
		return $refund_id;
	}

	/**
	 * The decisions belonging to THIS test's rules, in evaluation order.
	 *
	 * The matcher fetches every active rule for the trigger, so a rule left in
	 * the target database by something else would otherwise show up in an
	 * exact-sequence assertion. Filtering to the test's own fixtures keeps
	 * those assertions meaningful without assuming an empty table.
	 *
	 * @param \Extonify\WCEP\Domain\EvaluationResult $result Result to read.
	 * @return \Extonify\WCEP\Domain\MatchDecision[]
	 */
	protected function own_decisions( $result ): array {
		$own = array();
		foreach ( $result->decisions() as $decision ) {
			if ( in_array( $decision->rule_id(), $this->rule_ids, true ) ) {
				$own[] = $decision;
			}
		}
		return $own;
	}

	/**
	 * This test's rule ids, in evaluation order.
	 *
	 * @param \Extonify\WCEP\Domain\EvaluationResult $result Result to read.
	 * @return int[]
	 */
	protected function decision_order( $result ): array {
		return array_map(
			static function ( $decision ) {
				return $decision->rule_id();
			},
			$this->own_decisions( $result )
		);
	}

	/**
	 * This test's reason codes, keyed by rule id, in evaluation order.
	 *
	 * @param \Extonify\WCEP\Domain\EvaluationResult $result Result to read.
	 * @return array<int,string>
	 */
	protected function own_reasons( $result ): array {
		$reasons = array();
		foreach ( $this->own_decisions( $result ) as $decision ) {
			$reasons[ $decision->rule_id() ] = $decision->reason();
		}
		return $reasons;
	}
}
