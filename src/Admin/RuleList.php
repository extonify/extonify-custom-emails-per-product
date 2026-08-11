<?php
/**
 * The rules list screen.
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the list table, the page header and the admin notices row actions leave
 * behind.
 *
 * ⚠ THE TABLE IS BUILT AT `load-{$hook}`, NOT AT RENDER. `WP_List_Table` reads the
 * current screen in its constructor, and building it during output means column
 * headers and pagination are registered too late for the screen to know about them.
 */
final class RuleList {

	/**
	 * The table, built once per request.
	 *
	 * @var RulesListTable|null
	 */
	private static $table = null;

	/**
	 * Build the table and fetch its page.
	 *
	 * @return void
	 */
	public static function prepare(): void {
		Menu::require_capability();

		self::$table = new RulesListTable();
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

		echo '<div class="wrap extonify-wcep">';

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Custom Product Emails', 'extonify-custom-emails-per-product' ) . '</h1>';

		echo '<a href="' . esc_url( Menu::url( array( 'action' => Menu::ACTION_NEW ) ) ) . '" class="page-title-action">'
			. esc_html__( 'Add rule', 'extonify-custom-emails-per-product' ) . '</a>';

		echo '<hr class="wp-header-end" />';

		Notices::render_request_notice();

		echo '<form method="get">';

		// ⚠ THE PAGE ARGUMENT IS CARRIED FORWARD, or filtering navigates away from the
		// screen it is filtering.
		echo '<input type="hidden" name="page" value="' . esc_attr( Menu::PAGE ) . '" />';

		self::$table->search_box( __( 'Search rules', 'extonify-custom-emails-per-product' ), 'extonify-wcep-rule-search' );
		self::$table->display();

		echo '</form>';

		echo '</div>';
	}
}
