<?php
/**
 * ADR-0018 §1a — one submenu row under WooCommerce, two tabs behind it.
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Admin\Tabs;

/**
 * The navigation WooCommerce's extension guidance asks for, asserted.
 *
 * ⚠ THE POINT OF THIS FILE IS THE PAIR OF FACTS THAT MUST HOLD TOGETHER: no row is
 * DRAWN for the history screen, and the history PAGE is still registered and reachable.
 * A change that hid the row by unregistering the page would satisfy the first and
 * silently break every saved link to it; a change that "restored" the page by giving it
 * a row again would satisfy the second and put the clutter back. Each test below pins
 * one half.
 */
class AdminNavigationTest extends AdminTestCase {

	/**
	 * Build the admin menu the way WordPress does, as an administrator.
	 *
	 * @return array{menu:array,submenu:array}
	 */
	private function build_menu(): array {
		global $menu, $submenu;

		$menu    = array();
		$submenu = array();

		$this->become_manager();

		// WooCommerce's own parent, so `add_submenu_page()` has something to attach to.
		add_menu_page( 'WooCommerce', 'WooCommerce', Menu::CAPABILITY, Menu::PARENT, '__return_null' );

		Menu::add_page();

		return array(
			'menu'    => (array) $menu,
			'submenu' => (array) $submenu,
		);
	}

	/**
	 * GATE 33e / ADR-0018 §1a. EXACTLY ONE VISIBLE ROW UNDER WOOCOMMERCE.
	 *
	 * @return void
	 */
	public function test_exactly_one_visible_submenu_row_under_woocommerce() {
		$built = $this->build_menu();
		$rows  = (array) ( $built['submenu'][ Menu::PARENT ] ?? array() );

		$ours = array();

		foreach ( $rows as $row ) {
			$slug = (string) ( $row[2] ?? '' );

			if ( 0 === strpos( $slug, 'extonify-wcep' ) ) {
				$ours[ $slug ] = (string) ( $row[0] ?? '' );
			}
		}

		$this->assertCount(
			1,
			$ours,
			'⚠ this plugin must occupy exactly ONE row under the WooCommerce menu. Found: ' . implode( ', ', array_keys( $ours ) )
		);

		$this->assertArrayHasKey( Menu::PAGE, $ours );
		$this->assertArrayNotHasKey(
			Menu::HISTORY_PAGE,
			$ours,
			'⚠ the delivery-history row is back in the menu; it is meant to be a tab.'
		);

		fwrite( STDERR, "\n[P13F ADR-0018 §1a] menu: 1 row under WooCommerce — \"" . $ours[ Menu::PAGE ] . '"' );
	}

	/**
	 * ADR-0018 §1a. NO ROW IS DRAWN; THE PAGE IS STILL REGISTERED.
	 *
	 * ⚠ THIS IS THE HALF THAT PROTECTS SAVED LINKS. Hiding the row must not become
	 * `unregister`: the hook suffix has to survive, because the `load-{$hook}`
	 * handler — which is where the capability check and the write dispatch live — is
	 * attached to it.
	 *
	 * ⚠ AND HIDING IT IS DONE BY RE-PARENTING, NOT BY DELETION. `Menu::add_history_page()`
	 * parents the page to the rules page — itself a submenu, so WordPress registers the
	 * entry and never walks it when drawing the menu. `remove_submenu_page()` was the
	 * first attempt and it DELETED the registration this test asserts survives; what
	 * that cost is in `test_wordpress_routing_resolves_the_hidden_history_page()`.
	 *
	 * @return void
	 */
	public function test_the_history_page_is_still_registered_with_no_row_drawn() {
		$this->build_menu();

		$this->assertNotSame( '', Menu::history_hook(), '⚠ the history page lost its hook suffix; its load- handler is now dead.' );
		$this->assertContains( Menu::history_hook(), Menu::hooks() );
		$this->assertContains( Menu::hook(), Menu::hooks() );
	}

	/**
	 * ADR-0018 §1a. BOTH SLUGS ARE UNCHANGED, SO SAVED LINKS STILL RESOLVE.
	 *
	 * @return void
	 */
	public function test_both_page_slugs_are_unchanged() {
		$this->assertSame( 'extonify-wcep-rules', Menu::PAGE );
		$this->assertSame( 'extonify-wcep-history', Menu::HISTORY_PAGE );
		$this->assertStringContainsString( 'page=extonify-wcep-rules', Menu::url() );
		$this->assertStringContainsString( 'page=extonify-wcep-history', Menu::history_url() );
	}

	/**
	 * ADR-0018 §1a. THE HIDDEN PAGE STILL REFUSES A USER WITHOUT THE CAPABILITY.
	 *
	 * ⚠ GATE 28 ALREADY ASSERTS THIS FOR THE HISTORY SCREEN, and it asserted it when
	 * the row was drawn. It is re-asserted here for the specific reason that the row is
	 * now hidden: the failure this guards against is somebody concluding that an
	 * unlinked page does not need a check.
	 *
	 * @return void
	 */
	public function test_the_hidden_history_page_still_refuses_without_the_capability() {
		$this->request( array( 'page' => Menu::HISTORY_PAGE ) );

		$this->become_subscriber();
		$this->assertRefuses( array( Menu::class, 'render_history' ), 'a subscriber reached the hidden history screen' );
		$this->assertRefuses( array( Menu::class, 'load_history' ), 'a subscriber reached the hidden history dispatcher' );

		$this->become_logged_out();
		$this->assertRefuses( array( Menu::class, 'render_history' ), 'a logged-out visitor reached the hidden history screen' );
	}

	/**
	 * ADR-0018 §1a. WORDPRESS'S OWN ROUTING RESOLVES THE HIDDEN PAGE.
	 *
	 * ⚠ THIS IS THE TEST THAT WAS MISSING, AND ITS ABSENCE SHIPPED A BROKEN PAGE. The
	 * first attempt at hiding the row used `remove_submenu_page()`; every unit test still
	 * passed, because they all call `Menu::render_history()` directly and never traverse
	 * `wp-admin/admin.php`. Fetching the page over HTTP returned **403, then 500 —
	 * "Cannot load extonify-wcep-history."** — to an administrator.
	 *
	 * The mechanism, in WordPress's own code: `get_admin_page_parent()` finds a plugin
	 * page's parent by SEARCHING `$submenu`. Delete the row and that search fails, so
	 * `get_plugin_page_hookname()` computes `admin_page_…` instead of the name
	 * `add_submenu_page()` registered, `get_plugin_page_hook()` finds no action under it,
	 * and `admin.php` gives up before this plugin's capability check ever runs.
	 *
	 * The fix keeps a REAL `$submenu` entry, parented to the rules page — a slug that is
	 * itself a submenu and therefore never walked by the menu renderer. So this test
	 * asserts the three WordPress-side facts that the row-removal approach broke, rather
	 * than asserting that our own renderer works.
	 *
	 * @return void
	 */
	public function test_wordpress_routing_resolves_the_hidden_history_page() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->build_menu();

		// Exactly what wp-admin/admin.php sets up before it decides. The two `nopriv`
		// registries are normally filled during `admin_menu`; under CLI they can be null,
		// and `user_can_access_admin_page()` calls `array_keys()` on one of them.
		$GLOBALS['pagenow']            = 'admin.php';
		$GLOBALS['plugin_page']        = Menu::HISTORY_PAGE;
		$GLOBALS['parent_file']        = '';
		$GLOBALS['typenow']            = '';
		$GLOBALS['_wp_menu_nopriv']    = (array) ( $GLOBALS['_wp_menu_nopriv'] ?? array() );
		$GLOBALS['_wp_submenu_nopriv'] = (array) ( $GLOBALS['_wp_submenu_nopriv'] ?? array() );

		// 1. The parent resolves — this is what row removal broke.
		$this->assertSame(
			Menu::PAGE,
			get_admin_page_parent(),
			'⚠ WordPress cannot find the history page\'s parent; admin.php will not route to it.'
		);

		// 2. The hookname WordPress computes is the one that was registered.
		$hookname = get_plugin_page_hookname( Menu::HISTORY_PAGE, get_admin_page_parent() );
		$this->assertSame( Menu::history_hook(), $hookname );

		// 3. An action is attached under it, so `admin.php` finds a page to run rather
		//    than falling through to "Cannot load …".
		$this->assertNotNull(
			get_plugin_page_hook( Menu::HISTORY_PAGE, get_admin_page_parent() ),
			'⚠ no action under the computed hookname — admin.php would die with "Cannot load".'
		);

		// 4. And WordPress permits a capable user, reading the capability out of the
		//    hidden submenu entry exactly as it would a visible one.
		$this->assertTrue( user_can_access_admin_page() );

		fwrite( STDERR, "\n[P13F ADR-0018 §1a] routing: admin.php resolves the hidden page as \"" . $hookname . '"' );
	}

	/**
	 * ADR-0018 §1a. THE TAB STRIP MARKS THE CURRENT PAGE, ON BOTH PAGES.
	 *
	 * @return void
	 */
	public function test_the_tabs_mark_the_current_page() {
		foreach ( array( Menu::PAGE, Menu::HISTORY_PAGE ) as $current ) {
			ob_start();
			Tabs::render( $current );
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'nav-tab-wrapper', $html, 'native tab markup is required.' );
			$this->assertStringContainsString( 'page=' . Menu::PAGE, $html );
			$this->assertStringContainsString( 'page=' . Menu::HISTORY_PAGE, $html );

			// Exactly one tab is current, and it is this one.
			$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ), '⚠ exactly one tab must be current.' );
			$this->assertSame( 1, substr_count( $html, 'nav-tab-active' ) );

			$active = (string) preg_replace( '/.*<a href="([^"]*)"[^>]*nav-tab nav-tab-active[^>]*>.*/s', '$1', $html );
			$this->assertStringContainsString( 'page=' . $current, htmlspecialchars_decode( $active ) );
		}
	}

	/**
	 * ADR-0018 §1a. THE PLUGINS-LIST ACTION SAYS `Settings` AND REACHES THE RULES SCREEN.
	 *
	 * @return void
	 */
	public function test_the_plugins_list_action_links_to_the_rules_screen() {
		$links = Menu::plugin_action_links( array( '<a href="#">Deactivate</a>' ) );

		$this->assertIsArray( $links );
		$this->assertCount( 2, $links );

		// Prepended, so a merchant reads it before Deactivate.
		$this->assertStringContainsString( '>Settings<', $links[0] );
		$this->assertStringContainsString( 'page=extonify-wcep-rules', htmlspecialchars_decode( $links[0] ) );

		// No branding in the interface: the row already prints the plugin's name.
		$this->assertStringNotContainsString( 'Extonify', $links[0] );

		// A filter must survive a non-array from another badly behaved plugin.
		$this->assertSame( 1, count( Menu::plugin_action_links( null ) ) );
	}

	/**
	 * ADR-0018 §1a. THE HEADINGS ARE THE ONES THE MERCHANT ASKED FOR.
	 *
	 * ⚠ ASSERTED ON THE RENDERED SCREEN, not on a constant, because a heading nobody
	 * echoes is not a heading.
	 *
	 * @return void
	 */
	public function test_the_screen_headings_are_correct() {
		$this->become_manager();
		$this->use_our_screen();

		ob_start();
		\Extonify\WCEP\Admin\RuleList::render();
		$rules = (string) ob_get_clean();

		$this->assertStringContainsString( '>Email Rules<', $rules );
		$this->assertStringContainsString( '>Add Email Rule<', $rules );
		$this->assertStringNotContainsString( 'Custom Product Emails', $rules );
		$this->assertStringContainsString( 'nav-tab-wrapper', $rules );

		fwrite( STDERR, "\n[P13F ADR-0018 §1a] headings: \"Email Rules\" + \"Add Email Rule\", tab strip present" );
	}
}
