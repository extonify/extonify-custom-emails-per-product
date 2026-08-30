<?php
/**
 * The screen tabs that replace a second submenu row (ADR-0018 §1a).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * One tab strip, shared by the rules screen and the delivery-history screen.
 *
 * ⚠ WHY TABS AND NOT TWO SUBMENU ROWS. WooCommerce's extension guidance puts an
 * extension's screens INSIDE the WooCommerce menu, and gives an extension one row
 * there rather than a block of them. Two sibling rows — `Custom Product Emails` and
 * `Custom Email History` — spent two slots in a menu the store owner also shares with
 * every other extension, to reach two views of one subject. One row plus native tabs
 * is the shape WordPress core uses for exactly this (Settings, Tools, Site Health),
 * and it is what the guidance asks for.
 *
 * ⚠ THE HISTORY PAGE IS STILL REGISTERED, AND NO ROW IS EVER DRAWN FOR IT. It is
 * parented to the RULES page rather than to `woocommerce` — see
 * `Menu::add_history_page()`. WordPress renders a submenu only for slugs present in the
 * top-level `$menu`, and `extonify-wcep-rules` is itself a submenu, so
 * `$submenu['extonify-wcep-rules']` is registered and fully resolvable while never
 * being walked by the menu renderer. The page, its `load-` handler and its capability
 * check are untouched — a row that is not drawn is a navigation decision and MUST NOT
 * become an authorisation one. `admin.php?page=extonify-wcep-history` still resolves,
 * still renders and still refuses a user without the capability, and gate 28 asserts
 * that it refuses exactly as it did when the row was drawn.
 *
 * ⚠ `remove_submenu_page()` WAS TRIED FIRST AND IS WRONG — kept here because the
 * reasoning, not the approach, is what a later reader needs. It DELETES the row from
 * `$submenu`, and `$submenu` is exactly where `get_admin_page_parent()` looks a plugin
 * page's parent up: with the row gone WordPress computed a hookname
 * `add_submenu_page()` had never registered and `admin.php` refused an administrator —
 * 403, then "Cannot load extonify-wcep-history." Prompt 13C Part F caught that by
 * fetching the page over HTTP, after every unit test had passed. Re-parenting keeps
 * WordPress's own routing intact instead of re-implementing it.
 */
final class Tabs {

	/**
	 * Render the tab strip for whichever screen is current.
	 *
	 * ⚠ NATIVE MARKUP, NOT A CUSTOM WIDGET. `nav-tab-wrapper` and `nav-tab` are core
	 * classes, so the strip inherits core's focus outline, colour scheme and
	 * high-contrast handling rather than reimplementing them badly. The tabs are plain
	 * anchors, so they are keyboard-operable by being links — there is no JavaScript
	 * and nothing to trap focus.
	 *
	 * ⚠ `aria-current="page"` MARKS THE CURRENT TAB, not `aria-selected`. These are
	 * links that navigate, not tabs in an ARIA tablist: there is no tabpanel and no
	 * scripted switching, so `role="tab"` would promise interaction behaviour that does
	 * not exist. `aria-current` is what core uses for exactly this pattern.
	 *
	 * @param string $current The page slug that is showing.
	 * @return void
	 */
	public static function render( string $current ): void {
		$tabs = array(
			Menu::PAGE         => __( 'Rules', 'extonify-custom-emails-per-product' ),
			Menu::HISTORY_PAGE => __( 'Delivery History', 'extonify-custom-emails-per-product' ),
		);

		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="'
			. esc_attr__( 'Product Emails screens', 'extonify-custom-emails-per-product' ) . '">';

		foreach ( $tabs as $slug => $label ) {
			$is_current = ( $slug === $current );
			$url        = add_query_arg( array( 'page' => $slug ), admin_url( 'admin.php' ) );

			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . ( $is_current ? ' nav-tab-active' : '' ) . '"'
				. ( $is_current ? ' aria-current="page"' : '' ) . '>'
				. esc_html( $label )
				. '</a>';
		}

		echo '</nav>';
	}
}
