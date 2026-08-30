<?php
/**
 * The delivery-history screen (ADR-0018 §1).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Every delivery in the store, with the retention note that explains an absent one.
 *
 * ⚠ THE TABLE IS BUILT AT `load-{$hook}`, NOT AT RENDER, for the reason
 * `Admin\RuleList` gives: `WP_List_Table` reads the current screen in its
 * constructor, so building it during output registers column headers and pagination
 * too late for the screen to know about them.
 *
 * ⚠ READ-ONLY (ADR-0018 §9, gate 34). This screen issues `SELECT`s and nothing else.
 */
final class DeliveryHistory {

	/**
	 * The table, built once per request.
	 *
	 * @var DeliveriesListTable|null
	 */
	private static $table = null;

	/**
	 * Build the table and fetch its page.
	 *
	 * @return void
	 */
	public static function prepare(): void {
		Menu::require_capability();

		self::$table = new DeliveriesListTable();
		self::$table->prepare_items();
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		Menu::require_capability();

		if ( null === self::$table ) {
			self::prepare();
		}

		echo '<div class="wrap extonify-wcep extonify-wcep-history">';

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Delivery History', 'extonify-custom-emails-per-product' ) . '</h1>';

		echo '<hr class="wp-header-end" />';

		/*
		 * ⚠ THE "Manage rules" BUTTON WENT WITH THE SUBMENU ROW (ADR-0018 §1a). It
		 * existed because this screen was a sibling row with no other way back to the
		 * rules list. The `Rules` tab below is that way back, and is where a merchant
		 * now looks for it; keeping both would give one destination two controls a few
		 * pixels apart.
		 */
		Tabs::render( Menu::HISTORY_PAGE );

		// The outcome of a manual action that redirected back here (ADR-0019 §6).
		Notices::render_request_notice();

		echo '<p>' . esc_html__( 'Every custom product email this store has delivered, newest first. Sending, resending and cancelling are done from a delivery\'s own confirmation screen.', 'extonify-custom-emails-per-product' ) . '</p>';

		echo '<form method="get">';

		// ⚠ THE PAGE ARGUMENT IS CARRIED FORWARD, or filtering navigates away from the
		// screen it is filtering.
		echo '<input type="hidden" name="page" value="' . esc_attr( Menu::HISTORY_PAGE ) . '" />';

		self::$table->display();

		echo '</form>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- retention_note() returns markup already escaped at each of its own output points; see Admin\DeliveryPresenter.
		echo DeliveryPresenter::retention_note();

		echo '</div>';
	}

	/**
	 * Forget the built table.
	 *
	 * For tests, which render this screen many times in one process and must not
	 * inherit a page another test prepared.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$table = null;
	}
}
