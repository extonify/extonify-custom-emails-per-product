<?php
/**
 * The delivery panel on the WooCommerce order screen (ADR-0018 §1, §8).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * One meta box, on whichever order screen this store actually uses.
 *
 * ⚠ THE SCREEN IS DETECTED, NEVER ASSUMED (ADR-0018 §8). HPOS and legacy post
 * storage register the order editor under different screen ids —
 * `woocommerce_page_wc-orders` and `shop_order` — and this plugin declares HPOS
 * compatibility in its header. A panel that registered against one of them would be
 * a visible contradiction of that declaration on every store using the other, and it
 * would fail SILENTLY: no error, no notice, just a missing box that only somebody
 * who knew it should be there would miss.
 *
 * ⚠ AND THE CALLBACK IS HANDED A DIFFERENT OBJECT BY EACH. Under HPOS WooCommerce
 * passes a `WC_Order`; under legacy WordPress passes the `WP_Post`. Both shapes are
 * resolved to an id here, and nothing else is read off the argument — so the panel
 * does not care which storage produced it.
 *
 * ⚠ A MISSING CAPABILITY MAKES THIS RENDER NOTHING; IT DOES NOT `wp_die()`. Every
 * other entry point in this plugin refuses with a 403, and that is right for a page
 * the merchant navigated to. A meta box is a fragment of somebody ELSE's screen: a
 * `wp_die()` here would take down the whole WooCommerce order editor for a user who
 * legitimately holds `edit_shop_orders` without `manage_woocommerce`. So the box is
 * not registered for them at all, and the renderer re-checks and emits one sentence
 * and no delivery data if it is somehow reached anyway. Gate 28 records the kind of
 * refusal alongside the capability, rather than pretending it is a 403.
 *
 * ⚠ READ-ONLY, LIKE THE HISTORY SCREEN (ADR-0018 §9, gate 34). This renders
 * `SELECT`s and nothing else, and leaves EMPTY SPACE where Prompt 11 will put send,
 * resend and cancel — not a disabled button, which is what a paid tier looks like
 * from the merchant's side and which ADR-0001 forbids.
 */
final class OrderPanel {

	/**
	 * Meta-box id.
	 */
	const BOX_ID = 'extonify-wcep-deliveries';

	/**
	 * The legacy post-storage screen id.
	 */
	const LEGACY_SCREEN = 'shop_order';

	/**
	 * The HPOS order-screen id, used when WooCommerce's own helper is unavailable.
	 */
	const HPOS_SCREEN = 'woocommerce_page_wc-orders';

	/**
	 * How many of an order's deliveries the panel shows.
	 *
	 * An order accumulates one tombstone per (rule, mode, trigger) it has ever fired,
	 * which is small for a real order and unbounded only in principle — so the panel
	 * is capped rather than paged, and says so when it truncates. Paging a meta box
	 * would need its own request handling on somebody else's screen.
	 */
	const MAX_ROWS = 50;

	/**
	 * Register the meta box. Hooks only — zero queries, zero output.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add' ), 10, 0 );
	}

	/**
	 * Add the box to the active order screen.
	 *
	 * @return void
	 */
	public static function add(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			// Not registered at all, so there is no box to reach — the same posture
			// `Menu::add_page()` takes when `add_submenu_page()` returns nothing.
			return;
		}

		if ( ! Migrator::is_operational() ) {
			// Degraded mode: the tables are missing or a migration failed. The plugin
			// no-ops rather than putting an empty panel on a merchant's order screen.
			return;
		}

		// ⚠ THE PRIORITY ARGUMENT IS OMITTED, NOT PASSED AS `'default'`. Spelling out a
		// parameter's own default adds nothing — and gate 33's text-domain scan reads
		// any `'lowercase-string' )` as a possible domain argument, so the literal reads
		// as a gettext call naming WordPress's `default` domain. Omitting it is both
		// tidier and unambiguous.
		add_meta_box(
			self::BOX_ID,
			__( 'Custom product emails', 'extonify-custom-emails-per-product' ),
			array( self::class, 'render' ),
			self::screen_id(),
			'normal'
		);
	}

	/**
	 * Whether HPOS is the active order storage.
	 *
	 * @return bool
	 */
	public static function hpos_is_active(): bool {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return false;
		}

		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * The screen id the active storage puts the order editor on.
	 *
	 * `wc_get_page_screen_id()` lives in WooCommerce's ADMIN function library, which
	 * is not loaded on every request — so its absence falls back to the literal it
	 * would have returned rather than to an empty string, which would register the box
	 * against no screen at all and produce exactly the silent disappearance this class
	 * exists to prevent.
	 *
	 * @return string
	 */
	public static function screen_id(): string {
		if ( ! self::hpos_is_active() ) {
			return self::LEGACY_SCREEN;
		}

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screen = (string) wc_get_page_screen_id( 'shop-order' );

			if ( '' !== $screen ) {
				return $screen;
			}
		}

		return self::HPOS_SCREEN;
	}

	/**
	 * The order id behind whichever object the screen handed us.
	 *
	 * @param mixed $subject `WC_Order` under HPOS, `WP_Post` under legacy.
	 * @return int
	 */
	public static function order_id_from( $subject ): int {
		if ( $subject instanceof \WC_Order ) {
			return (int) $subject->get_id();
		}

		if ( is_object( $subject ) && isset( $subject->ID ) ) {
			return (int) $subject->ID;
		}

		return 0;
	}

	/**
	 * Render the panel.
	 *
	 * @param mixed $subject `WC_Order` under HPOS, `WP_Post` under legacy.
	 * @return void
	 */
	public static function render( $subject = null ): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			echo '<p>' . esc_html__( 'You are not allowed to view custom product email deliveries.', 'extonify-custom-emails-per-product' ) . '</p>';
			return;
		}

		$order_id = self::order_id_from( $subject );

		if ( $order_id <= 0 ) {
			echo '<p>' . esc_html__( 'This order has not been saved yet, so it has no delivery history.', 'extonify-custom-emails-per-product' ) . '</p>';
			return;
		}

		$page = self::page_for_order( $order_id );

		echo '<div class="extonify-wcep-order-panel">';

		if ( array() === $page['deliveries'] ) {
			// ⚠ "NO DELIVERIES" AND "DELIVERIES WHOSE DETAILS AGED OUT" ARE DIFFERENT
			// FACTS (ADR-0018 §4c), and this is the ONLY branch that may say the first.
			// A tombstone with no detail rows never reaches here — it is a delivery, and
			// `DeliveryPresenter::attempts_cell()` explains its missing detail.
			echo '<p>' . esc_html__( 'No custom emails have been sent for this order.', 'extonify-custom-emails-per-product' ) . '</p>';

			self::render_manual_send( $order_id );

			echo '</div>';
			return;
		}

		self::render_table( $page );

		if ( $page['total'] > count( $page['deliveries'] ) ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: 1: how many deliveries are shown, 2: how many this order has in total. */
					_n(
						'Showing the %1$d most recent of %2$d delivery for this order.',
						'Showing the %1$d most recent of %2$d deliveries for this order.',
						(int) $page['total'],
						'extonify-custom-emails-per-product'
					),
					count( $page['deliveries'] ),
					$page['total']
				)
			) . '</p>';
		}

		echo '<p><a href="' . esc_url(
			Menu::history_url( array( DeliveriesListTable::ARG_ORDER => $order_id ) )
		) . '">' . esc_html__( 'View this order in the full delivery history', 'extonify-custom-emails-per-product' ) . '</a></p>';

		self::render_manual_send( $order_id );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- retention_note() returns markup already escaped at each of its own output points.
		echo DeliveryPresenter::retention_note();

		echo '</div>';
	}

	/**
	 * The manual-send control: pick a rule, go to its confirmation (ADR-0019 §6).
	 *
	 * ⚠ IT IS A GET FORM TO A CONFIRMATION SCREEN, NOT A SEND. Choosing a rule here
	 * sends nothing and needs no nonce; the confirmation screen it lands on shows the
	 * resolved recipients and renders the POST that does (gate 36).
	 *
	 * ⚠ EVERY RULE IS OFFERED, INCLUDING ONES THAT HAVE ALREADY DELIVERED FOR THIS
	 * ORDER. Hiding those was the first design and it contradicted ADR-0019 §2: two
	 * deliberate manual sends are two distinct identities BECAUSE the merchant asked
	 * twice, so a picker that removed a rule after its first manual send would make the
	 * second one unreachable through the UI while the handler still allowed it. The
	 * delivery's own `Resend` control remains the better route when one exists, and the
	 * confirmation screen says what a second send will do.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	private static function render_manual_send( int $order_id ): void {
		$options = array();

		foreach ( Plugin::instance()->rules()->names_all( self::MAX_RULE_OPTIONS ) as $rule_id => $name ) {
			$name = trim( $name );

			$options[ (int) $rule_id ] = '' !== $name
				? $name
				/* translators: %d: rule id. */
				: sprintf( __( 'Rule #%d (no name)', 'extonify-custom-emails-per-product' ), (int) $rule_id );
		}

		if ( array() === $options ) {
			return;
		}

		echo '<div class="extonify-wcep-manual-send">';

		echo '<h4>' . esc_html__( 'Send one of these emails for this order', 'extonify-custom-emails-per-product' ) . '</h4>';

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';

		echo '<input type="hidden" name="page" value="' . esc_attr( Menu::HISTORY_PAGE ) . '" />';
		echo '<input type="hidden" name="wcep_action" value="' . esc_attr( DeliveryActions::ACTION_MANUAL ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( DeliveryActions::FIELD_ORDER ) . '" value="' . esc_attr( (string) $order_id ) . '" />';

		echo '<label class="screen-reader-text" for="extonify-wcep-manual-rule">'
			. esc_html__( 'Choose which email to send', 'extonify-custom-emails-per-product' ) . '</label>';

		echo '<select name="' . esc_attr( DeliveryActions::FIELD_RULE ) . '" id="extonify-wcep-manual-rule">';

		foreach ( $options as $rule_id => $label ) {
			echo '<option value="' . esc_attr( (string) $rule_id ) . '">' . esc_html( $label ) . '</option>';
		}

		echo '</select> ';

		echo '<button type="submit" class="button">' . esc_html__( 'Choose…', 'extonify-custom-emails-per-product' ) . '</button>';

		echo '</form>';

		echo '<p class="description">'
			. esc_html__( 'You will be shown who the email would go to, and asked to confirm, before anything is sent.', 'extonify-custom-emails-per-product' )
			. '</p>';

		echo '</div>';
	}

	/**
	 * How many rules the manual-send picker offers.
	 */
	const MAX_RULE_OPTIONS = 200;

	/**
	 * One order's deliveries with their details, in the SAME fixed number of
	 * statements the history screen uses (ADR-0018 §7).
	 *
	 * @param int $order_id Order id.
	 * @return array{deliveries:array[], details:array<int,array[]>, rules:array<int,string>, total:int}
	 */
	private static function page_for_order( int $order_id ): array {
		$filters = array( 'order_id' => $order_id );

		$deliveries = DeliveryPresenter::deliveries()->query(
			array_merge( $filters, array( 'limit' => self::MAX_ROWS ) )
		);

		$ids   = array();
		$rules = array();

		foreach ( $deliveries as $delivery ) {
			$ids[]   = (int) $delivery['id'];
			$rules[] = (int) $delivery['rule_id'];
		}

		return array(
			'deliveries' => $deliveries,
			'details'    => Plugin::instance()->delivery_details()->find_for_deliveries( $ids ),
			'rules'      => DeliveryPresenter::rule_names( $rules ),
			'total'      => DeliveryPresenter::deliveries()->count_matching( $filters ),
		);
	}

	/**
	 * The panel's table.
	 *
	 * ⚠ A REAL TABLE WITH REAL HEADERS AND `scope="col"` (gate 33). A screen reader
	 * announcing "Sent" with no column name is telling somebody a word, not a fact.
	 *
	 * @param array $page Result of self::page_for_order().
	 * @return void
	 */
	private static function render_table( array $page ): void {
		echo '<table class="widefat striped extonify-wcep-order-deliveries">';

		echo '<caption class="screen-reader-text">'
			. esc_html__( 'Custom product emails sent for this order', 'extonify-custom-emails-per-product' )
			. '</caption>';

		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Rule', 'extonify-custom-emails-per-product' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'extonify-custom-emails-per-product' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'When', 'extonify-custom-emails-per-product' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Attempts', 'extonify-custom-emails-per-product' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Actions', 'extonify-custom-emails-per-product' ) . '</th>';
		echo '</tr></thead>';

		echo '<tbody>';

		foreach ( $page['deliveries'] as $delivery ) {
			$delivery_id = (int) $delivery['id'];

			echo '<tr>';

			echo '<td>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- DeliveryPresenter escapes every value at its own point of output; see its class docblock and gate 31.
			echo DeliveryPresenter::rule_cell( (int) $delivery['rule_id'], $page['rules'] );
			echo '<br /><span class="description">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the presenter.
			echo DeliveryPresenter::trigger_cell( (string) $delivery['trigger_identity'] );
			echo ' · ';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the presenter.
			echo DeliveryPresenter::mode_cell( (string) $delivery['mode'] );
			echo '</span></td>';

			echo '<td>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the presenter.
			echo DeliveryPresenter::status_cell( (string) $delivery['final_status'] );
			echo '</td>';

			echo '<td>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the presenter.
			echo DeliveryPresenter::when_cell( $delivery );
			echo '</td>';

			echo '<td>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the presenter.
			echo DeliveryPresenter::attempts_cell( (array) ( $page['details'][ $delivery_id ] ?? array() ) );
			echo '</td>';

			echo '<td>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the presenter; every link points at a CONFIRMATION screen, never at a send (ADR-0019 §6).
			echo DeliveryPresenter::actions_cell( $delivery );
			echo '</td>';

			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
