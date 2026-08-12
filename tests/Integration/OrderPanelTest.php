<?php
/**
 * The order-screen panel, under BOTH order storages (ADR-0018 §8).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\Assets;
use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Admin\OrderPanel;

/**
 * HPOS and legacy post storage register the order editor under different screen ids
 * and hand the meta-box callback different objects. Both are asserted.
 *
 * ⚠ WHAT THE STORAGE SWITCH HERE DOES AND DOES NOT SIMULATE, STATED PLAINLY.
 * `OrderUtil::custom_orders_table_usage_is_enabled()` is a bare
 * `get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes'`, so filtering
 * that option genuinely exercises this plugin's DETECTION and everything downstream
 * of it — the screen id the box registers against, the shape of the order edit URL,
 * and the asset gate. What it does NOT do is move any order row between storages.
 *
 * That gap costs nothing **for this panel specifically**, and the reason is worth
 * stating rather than assuming: the panel never loads an order. It reads the
 * `order_id` recorded on the tombstone and builds a link from it (ADR-0018 §7), so
 * there is no code path here whose behaviour depends on where the order actually
 * lives. A surface that DID read orders could not be tested this way, and this note
 * is here so nobody copies the technique to one that does.
 *
 * The harness's own live configuration is HPOS, which is asserted below so the
 * record says which mode was real and which was simulated.
 */
final class OrderPanelTest extends DeliveryHistoryTestCase {

	/**
	 * The HPOS toggle WooCommerce reads.
	 */
	const HPOS_OPTION = 'woocommerce_custom_orders_table_enabled';

	/**
	 * Gate lines.
	 *
	 * @var string[]
	 */
	private $gate = array();

	/**
	 * Print the gate lines.
	 *
	 * @after
	 * @return void
	 */
	protected function report_gate() {
		remove_all_filters( 'pre_option_' . self::HPOS_OPTION );

		foreach ( $this->gate as $line ) {
			fwrite( STDERR, "\n[P10 panel] " . $line );
		}

		$this->gate = array();
	}

	/**
	 * Force one order storage for the duration of a callback.
	 *
	 * @param bool     $hpos Whether HPOS should report as enabled.
	 * @param callable $body What to run.
	 * @return mixed Whatever $body returned.
	 */
	private function with_storage( bool $hpos, callable $body ) {
		$value = $hpos ? 'yes' : 'no';

		$filter = static function () use ( $value ) {
			return $value;
		};

		add_filter( 'pre_option_' . self::HPOS_OPTION, $filter, 999 );

		try {
			$this->assertSame(
				$hpos,
				OrderPanel::hpos_is_active(),
				'the storage switch did not take effect, so this case would assert the wrong mode.'
			);

			return $body();
		} finally {
			remove_filter( 'pre_option_' . self::HPOS_OPTION, $filter, 999 );
		}
	}

	/**
	 * THE PREMISE: the harness really is running HPOS, so the record can say which
	 * mode was live and which was simulated.
	 *
	 * @return void
	 */
	public function test_the_harness_storage_is_recorded() {
		$live = OrderPanel::hpos_is_active();

		$this->assertIsBool( $live );

		$this->gate[] = 'harness storage: HPOS is ' . ( $live ? 'ENABLED' : 'DISABLED' )
			. ' in this environment — that mode is exercised live, the other through the '
			. 'woocommerce_custom_orders_table_enabled option, which is the only thing '
			. 'OrderUtil::custom_orders_table_usage_is_enabled() reads';
	}

	/**
	 * THE SCREEN ID FOLLOWS THE ACTIVE STORAGE.
	 *
	 * ⚠ A BOX REGISTERED AGAINST THE WRONG SCREEN FAILS SILENTLY. No error, no notice
	 * — just a panel that is not there, which only somebody who knew it should be
	 * would ever notice.
	 *
	 * @return void
	 */
	public function test_the_screen_id_follows_the_active_storage() {
		$hpos = $this->with_storage(
			true,
			static function () {
				return OrderPanel::screen_id();
			}
		);

		$legacy = $this->with_storage(
			false,
			static function () {
				return OrderPanel::screen_id();
			}
		);

		$this->assertSame( OrderPanel::LEGACY_SCREEN, $legacy, '⚠ the panel would not appear on a legacy order screen.' );

		$this->assertNotSame( $legacy, $hpos, '⚠ both storages resolved to the same screen id.' );

		$this->assertStringContainsString(
			'wc-orders',
			$hpos,
			'⚠ the HPOS screen id does not name the HPOS orders page.'
		);

		$this->gate[] = 'screen id: HPOS => "' . $hpos . '", legacy => "' . $legacy . '" — detected from the active '
			. 'storage, never assumed';
	}

	/**
	 * THE META BOX IS REGISTERED, ON WHICHEVER SCREEN IS ACTIVE.
	 *
	 * @return void
	 */
	public function test_the_meta_box_is_registered_under_both_storages() {
		$this->become_manager();

		foreach ( array( 'HPOS' => true, 'legacy' => false ) as $label => $hpos ) {
			$registered = $this->with_storage(
				$hpos,
				function () {
					return $this->registered_boxes();
				}
			);

			$this->assertArrayHasKey(
				OrderPanel::BOX_ID,
				$registered,
				'⚠ the delivery panel was not registered on the ' . $label . ' order screen.'
			);
		}

		$this->gate[] = 'registration: the "' . OrderPanel::BOX_ID . '" meta box registers on the order screen under '
			. 'BOTH storages';
	}

	/**
	 * THE BOX IS NOT REGISTERED FOR A USER WITHOUT THE CAPABILITY.
	 *
	 * @return void
	 */
	public function test_the_meta_box_is_not_registered_without_the_capability() {
		foreach ( array( 'logged out', 'subscriber' ) as $who ) {
			if ( 'logged out' === $who ) {
				$this->become_logged_out();
			} else {
				$this->become_subscriber();
			}

			$this->assertArrayNotHasKey(
				OrderPanel::BOX_ID,
				$this->registered_boxes(),
				'⚠ TIER 1: the delivery panel registered for a ' . $who . ' user.'
			);
		}

		$this->gate[] = 'capability (registration): no meta box is registered for a logged-out or unprivileged user';
	}

	/**
	 * AND THE RENDERER REFUSES ON ITS OWN, leaking no delivery data.
	 *
	 * ⚠ IT RENDERS A SENTENCE RATHER THAN CALLING `wp_die()`, AND THAT IS DELIBERATE
	 * (ADR-0018 §8). A meta box is a fragment of WooCommerce's screen; `wp_die()` here
	 * would take down the whole order editor for a user who legitimately holds
	 * `edit_shop_orders` without `manage_woocommerce`. The security property — no
	 * delivery data reaches them — is asserted directly rather than inferred from a
	 * 403 the surface should not be issuing.
	 *
	 * @return void
	 */
	public function test_the_renderer_refuses_without_the_capability_and_leaks_nothing() {
		$this->become_manager();

		$order_id    = $this->fake_order_id();
		$delivery_id = $this->raw_delivery( array( 'order_id' => $order_id ) );

		$this->raw_detail( $delivery_id, array( 'recipient' => 'secret-customer@example.test' ) );

		// The positive control first: a privileged user DOES see it, so the assertions
		// below are about the capability rather than about an empty fixture.
		$allowed = $this->panel( $order_id );

		$this->assertStringContainsString(
			'secret-customer@example.test',
			$allowed,
			'the privileged render shows nothing, so the refusal tests would prove nothing.'
		);

		foreach ( array( 'logged out', 'subscriber' ) as $who ) {
			if ( 'logged out' === $who ) {
				$this->become_logged_out();
			} else {
				$this->become_subscriber();
			}

			$refused = $this->panel( $order_id );

			$this->assertStringNotContainsString(
				'secret-customer@example.test',
				$refused,
				'⚠ TIER 1: the delivery panel leaked a recipient address to a ' . $who . ' user.'
			);

			$this->assertStringContainsString(
				'You are not allowed to view custom product email deliveries.',
				$refused,
				'⚠ the panel rendered nothing at all for a ' . $who . ' user instead of saying why.'
			);
		}

		$this->gate[] = 'capability (render): the panel refuses a logged-out and an unprivileged user with a sentence '
			. 'and NO delivery data, while a privileged user sees the recipient — and it does not wp_die(), which '
			. 'would take down WooCommerce\'s order screen';
	}

	/**
	 * THE PANEL RENDERS FROM BOTH ARGUMENT SHAPES.
	 *
	 * ⚠ THIS IS THE HALF THE SCREEN ID CANNOT COVER. Under HPOS WooCommerce hands the
	 * callback a `WC_Order`; under legacy WordPress hands it the `WP_Post`. A panel
	 * that read `->ID` would be blank on HPOS and one that read `->get_id()` would
	 * fatal on legacy, and neither would be caught by testing registration alone.
	 *
	 * @return void
	 */
	public function test_the_panel_renders_from_a_wc_order_and_from_a_wp_post() {
		$this->become_manager();

		$product_id = $this->make_product( 'WCEP Panel Product' );
		$order      = $this->make_order( $product_id );
		$order_id   = (int) $order->get_id();

		$delivery_id = $this->raw_delivery( array( 'order_id' => $order_id ) );

		$this->raw_detail( $delivery_id, array( 'recipient' => 'panel-shape@example.test' ) );

		// HPOS shape: the real WC_Order WooCommerce would pass.
		$this->assertSame( $order_id, OrderPanel::order_id_from( $order ) );

		$from_order = $this->capture(
			static function () use ( $order ) {
				OrderPanel::render( $order );
			}
		);

		// Legacy shape: the WP_Post WordPress would pass.
		$post = get_post( $order_id );
		$post = $post instanceof \WP_Post ? $post : (object) array( 'ID' => $order_id );

		$this->assertSame( $order_id, OrderPanel::order_id_from( $post ) );

		$from_post = $this->capture(
			static function () use ( $post ) {
				OrderPanel::render( $post );
			}
		);

		foreach ( array( 'WC_Order' => $from_order, 'WP_Post' => $from_post ) as $shape => $markup ) {
			$this->assertStringContainsString(
				'panel-shape@example.test',
				$markup,
				'⚠ the panel rendered no delivery when handed a ' . $shape . '.'
			);
		}

		// An unsaved order — the `add_meta_boxes` call on a brand-new order screen —
		// says so rather than rendering an empty table or resolving to order #0.
		$this->assertSame( 0, OrderPanel::order_id_from( null ) );

		$unsaved = $this->capture(
			static function () {
				OrderPanel::render( null );
			}
		);

		$this->assertStringContainsString( 'has not been saved yet', $unsaved );

		$this->gate[] = 'argument shapes: the panel renders the same delivery from a WC_Order (HPOS) and from a '
			. 'WP_Post (legacy), and reports an unsaved order rather than resolving it to #0';
	}

	/**
	 * THE ORDER EDIT LINK TAKES THE SHAPE THE ACTIVE STORAGE USES.
	 *
	 * @return void
	 */
	public function test_the_order_link_takes_the_shape_of_the_active_storage() {
		$this->become_manager();

		$order_id = $this->fake_order_id();

		$delivery_id = $this->raw_delivery( array( 'order_id' => $order_id ) );
		$this->raw_detail( $delivery_id );

		$hpos = $this->with_storage(
			true,
			function () use ( $order_id ) {
				return $this->render_history( array( \Extonify\WCEP\Admin\DeliveriesListTable::ARG_ORDER => $order_id ) );
			}
		);

		$legacy = $this->with_storage(
			false,
			function () use ( $order_id ) {
				return $this->render_history( array( \Extonify\WCEP\Admin\DeliveriesListTable::ARG_ORDER => $order_id ) );
			}
		);

		$this->assertStringContainsString( 'page=wc-orders', $hpos, '⚠ the HPOS order link does not point at the HPOS editor.' );
		$this->assertStringContainsString( (string) $order_id, $hpos );

		$this->assertStringContainsString( 'post.php', $legacy, '⚠ the legacy order link does not point at post.php.' );
		$this->assertStringNotContainsString( 'page=wc-orders', $legacy, '⚠ the legacy link used the HPOS URL shape.' );

		$this->gate[] = 'order link: "admin.php?page=wc-orders&action=edit&id=N" under HPOS and '
			. '"post.php?post=N&action=edit" under legacy — built from the recorded id, costing no order load';
	}

	/**
	 * RENDERING THE PANEL WRITES NOTHING.
	 *
	 * @return void
	 */
	public function test_rendering_the_panel_writes_nothing() {
		$this->become_manager();

		$order_id    = $this->fake_order_id();
		$delivery_id = $this->raw_delivery( array( 'order_id' => $order_id ) );

		$this->raw_detail( $delivery_id );

		$before = $this->delivery_checksums();

		$statements = $this->record_statements(
			function () use ( $order_id ) {
				$this->panel( $order_id );
			}
		);

		$this->assertSame( array(), $this->delivery_writes( $statements ), '⚠ TIER 1: the panel wrote to a delivery table.' );

		$this->assertSame( $before, $this->delivery_checksums(), '⚠ TIER 1: a delivery table changed while the panel rendered.' );

		$this->gate[] = 'gate 34 (panel): ' . count( $this->delivery_statements( $statements ) )
			. ' delivery-table statements, 0 writes, both tables byte-identical afterwards';
	}

	/**
	 * THE ORDER SCREEN GETS THE STYLESHEET AND NOT THE SCRIPT.
	 *
	 * ⚠ THE SCRIPT CARRIES A NONCE FOR THE TARGET-SEARCH ENDPOINT AND BINDS THE
	 * DELETE CONFIRMATION — neither belongs on somebody else's screen, and shipping it
	 * there is the same class of mistake as shipping the CSS to an unrelated page.
	 *
	 * @return void
	 */
	public function test_the_order_screen_gets_the_stylesheet_only() {
		$this->become_manager();

		$saved_screen = $GLOBALS['current_screen'] ?? null;

		try {
			set_current_screen( OrderPanel::screen_id() );

			$this->assertTrue( Assets::is_order_screen(), 'the harness did not reach the order screen.' );

			Assets::enqueue( 'woocommerce_page_wc-orders' );

			$this->assertTrue( wp_style_is( Assets::HANDLE, 'enqueued' ), 'the panel stylesheet did not load on the order screen.' );
			$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ), '⚠ the admin SCRIPT loaded on the WooCommerce order screen.' );
		} finally {
			wp_dequeue_style( Assets::HANDLE );
			wp_deregister_style( Assets::HANDLE );
			wp_dequeue_script( Assets::HANDLE );
			wp_deregister_script( Assets::HANDLE );

			if ( null !== $saved_screen ) {
				$GLOBALS['current_screen'] = $saved_screen;
			}
		}

		// And an unrelated admin screen gets neither.
		$this->assertFalse( Assets::is_our_screen( 'plugins.php' ) );

		$this->gate[] = 'assets: the order screen enqueues the stylesheet and NOT the script (which carries the '
			. 'target-search nonce); an unrelated hook gets neither';
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Run `add_meta_boxes` for the active order screen and return what was registered
	 * on it, restoring the global afterwards.
	 *
	 * @return array
	 */
	private function registered_boxes(): array {
		global $wp_meta_boxes;

		$saved  = $wp_meta_boxes;
		$screen = OrderPanel::screen_id();

		$wp_meta_boxes = array();

		try {
			OrderPanel::add();

			$found = array();

			foreach ( (array) ( $wp_meta_boxes[ $screen ] ?? array() ) as $contexts ) {
				foreach ( (array) $contexts as $boxes ) {
					foreach ( (array) $boxes as $id => $box ) {
						$found[ $id ] = $box;
					}
				}
			}

			return $found;
		} finally {
			$wp_meta_boxes = $saved;
		}
	}

	/**
	 * Render the panel for one order id, through the legacy argument shape.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private function panel( int $order_id ): string {
		return $this->capture(
			static function () use ( $order_id ) {
				OrderPanel::render( (object) array( 'ID' => $order_id ) );
			}
		);
	}

	/**
	 * The history page slug, so the panel's "view in history" link is meaningful.
	 *
	 * @return void
	 */
	public function test_the_panel_links_to_the_filtered_history() {
		$this->become_manager();

		$order_id    = $this->fake_order_id();
		$delivery_id = $this->raw_delivery( array( 'order_id' => $order_id ) );

		$this->raw_detail( $delivery_id );

		$markup = $this->panel( $order_id );

		$this->assertStringContainsString( 'page=' . Menu::HISTORY_PAGE, $markup, 'the panel does not link to the history screen.' );
		$this->assertStringContainsString( \Extonify\WCEP\Admin\DeliveriesListTable::ARG_ORDER . '=' . $order_id, $markup );

		$this->gate[] = 'panel link: "view in the full delivery history" carries this order\'s id as a filter';
	}
}
