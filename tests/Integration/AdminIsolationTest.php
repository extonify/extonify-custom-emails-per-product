<?php
/**
 * GATE 32 — front-end isolation, asserted rather than implemented.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\Assets;
use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Admin\OrderPanel;
use Extonify\WCEP\Admin\RuleActions;
use Extonify\WCEP\Admin\RulePreviewScreen;
use Extonify\WCEP\Admin\TargetSearch;

/**
 * Nothing of `src/Admin/` reaches a front-end request.
 *
 * ⚠ THIS TEST IS ONLY HONEST BECAUSE `is_admin()` IS FALSE HERE. The suite boots
 * WordPress through `wp-load.php` with no `WP_ADMIN`, and `AdminTestCase` requires
 * `wp-admin/includes/admin.php` for the FUNCTION LIBRARY alone — deliberately
 * without setting `WP_ADMIN`, so the request stays a front-end request while
 * `WP_List_Table` and `add_submenu_page()` are callable. Every other admin test
 * needs the library; this one needs the request to still be a front-end one, and
 * both hold in the same process.
 *
 * ⚠ SEVERITY: an admin surface reachable from the storefront is TIER 1 — a
 * privilege boundary crossed. A `wp_ajax_nopriv_` twin of the target search would
 * be a product-enumeration endpoint open to the whole internet, with a capability
 * check as the only thing behind it.
 */
final class AdminIsolationTest extends AdminTestCase {

	/**
	 * Gate lines.
	 *
	 * @var string[]
	 */
	private $gate = array();

	/**
	 * The current screen another test left behind, if any.
	 *
	 * @var \WP_Screen|null
	 */
	private $saved_screen = null;

	/**
	 * Put this process back to a front-end request for the duration of the test.
	 *
	 * ⚠ `is_admin()` READS `$GLOBALS['current_screen']` FIRST, before it looks at
	 * `WP_ADMIN` — so any earlier test that called `set_current_screen()` to build a
	 * `WP_List_Table` has turned every later test in the process into an admin
	 * request. That is a harness artefact of sharing one process, not plugin
	 * behaviour, and this class is the one place where the difference is the whole
	 * point. The screen is therefore removed here and put back afterwards, so the
	 * assertions below run against the request `wp-load.php` actually made.
	 *
	 * @before
	 * @return void
	 */
	protected function set_up_front_end_request() {
		$this->saved_screen = $GLOBALS['current_screen'] ?? null;

		unset( $GLOBALS['current_screen'] );
	}

	/**
	 * Restore the screen and print the gate lines.
	 *
	 * @after
	 * @return void
	 */
	protected function report_gate() {
		if ( null !== $this->saved_screen ) {
			$GLOBALS['current_screen'] = $this->saved_screen;
		}

		$this->saved_screen = null;

		foreach ( $this->gate as $line ) {
			fwrite( STDERR, "\n[P9 gate 32] " . $line );
		}

		$this->gate = array();
	}

	/**
	 * The admin-surface state as it stood the instant the plugin finished loading,
	 * on the front-end request `wp-load.php` made — recorded by `tests/bootstrap.php`
	 * before any test could register a menu, a screen or an AJAX handler of its own.
	 *
	 * @return array
	 */
	private function at_plugin_load(): array {
		$state = $GLOBALS['extonify_wcep_front_end_state'] ?? null;

		$this->assertIsArray(
			$state,
			'⚠ tests/bootstrap.php no longer records the front-end state, so gate 32 has nothing to assert on.'
		);

		return $state;
	}

	/**
	 * GATE 32a. THE PREMISE. This really is a front-end request.
	 *
	 * ⚠ WITHOUT THIS EVERY OTHER ASSERTION BELOW IS UNFALSIFIABLE. A test that
	 * asserts "no admin asset on the front end" while quietly running as an admin
	 * request passes for the wrong reason and would go on passing after the
	 * `is_admin()` branch was deleted.
	 *
	 * @return void
	 */
	public function test_the_request_under_test_is_a_front_end_request() {
		$loaded = $this->at_plugin_load();

		// What the plugin actually loaded into: a front-end request, recorded before
		// any test existed to disturb it.
		$this->assertFalse( $loaded['is_admin'], '⚠ the plugin loaded into an ADMIN request; gate 32 is untestable.' );
		$this->assertFalse( $loaded['wp_admin'], '⚠ WP_ADMIN was set when the plugin loaded.' );
		$this->assertFalse( $loaded['current_screen'], '⚠ a screen was already set when the plugin loaded.' );

		// And what this test is running in, once `set_up_front_end_request()` has
		// taken back the screen a sibling test left behind.
		$this->assertFalse( is_admin(), '⚠ the harness turned this into an admin request; gate 32 cannot be tested here.' );
		$this->assertFalse( defined( 'WP_ADMIN' ) && WP_ADMIN, '⚠ WP_ADMIN is set, so this is not a front-end request.' );
		$this->assertFalse( wp_doing_ajax(), '⚠ this is an AJAX request, not a storefront one.' );

		// The admin function library IS loaded — that is the harness concern the
		// class docblock describes, and it is not the same thing as being in the
		// admin. Asserting it keeps the two apart in the record.
		$this->assertTrue(
			function_exists( 'add_submenu_page' ),
			'the admin function library is not loaded, so a missing function could masquerade as isolation.'
		);

		$this->gate[] = 'premise: is_admin() false, WP_ADMIN unset, no screen, not AJAX — both AT PLUGIN LOAD '
			. '(recorded by the bootstrap before any test ran) and in this test — while wp-admin/includes/admin.php '
			. 'is loaded, so nothing below passes merely because a function was undefined';
	}

	/**
	 * GATE 32b. NO ADMIN SCRIPT AND NO ADMIN STYLE IS ENQUEUED — and the assertion
	 *           is proven capable of failing by enqueueing them on purpose.
	 *
	 * @return void
	 */
	public function test_no_admin_asset_is_registered_or_enqueued() {
		$loaded = $this->at_plugin_load();

		$this->assertFalse( $loaded['scripts_touched'], '⚠ TIER 1: the admin script was registered at plugin load.' );
		$this->assertFalse( $loaded['styles_touched'], '⚠ TIER 1: the admin stylesheet was registered at plugin load.' );

		foreach ( array( 'registered', 'enqueued', 'to_do', 'done' ) as $state ) {
			$this->assertFalse(
				wp_script_is( Assets::HANDLE, $state ),
				'⚠ TIER 1: the admin script is "' . $state . '" on a front-end request.'
			);

			$this->assertFalse(
				wp_style_is( Assets::HANDLE, $state ),
				'⚠ TIER 1: the admin stylesheet is "' . $state . '" on a front-end request.'
			);
		}

		// Not merely absent from the queue — absent from the registry that
		// `wp_scripts()` and `wp_styles()` actually hold.
		$this->assertArrayNotHasKey( Assets::HANDLE, wp_scripts()->registered );
		$this->assertArrayNotHasKey( Assets::HANDLE, wp_styles()->registered );
		$this->assertNotContains( Assets::HANDLE, wp_scripts()->queue );
		$this->assertNotContains( Assets::HANDLE, wp_styles()->queue );

		// ⚠ THE POSITIVE CONTROL. Enqueue deliberately, on this plugin's own hook, and
		// prove the checks above go red — otherwise "not enqueued" could just mean the
		// handle was never spelled the way the test spells it.
		$this->with_registered_menu(
			function ( string $hook ) {
				Assets::enqueue( $hook );

				$this->assertTrue( wp_script_is( Assets::HANDLE, 'enqueued' ), 'the positive control did not enqueue.' );
				$this->assertTrue( wp_style_is( Assets::HANDLE, 'enqueued' ), 'the positive control did not enqueue.' );
			}
		);

		wp_dequeue_script( Assets::HANDLE );
		wp_dequeue_style( Assets::HANDLE );
		wp_deregister_script( Assets::HANDLE );
		wp_deregister_style( Assets::HANDLE );

		$this->assertFalse( wp_script_is( Assets::HANDLE, 'registered' ), 'the positive control leaked past teardown.' );
		$this->assertFalse( wp_style_is( Assets::HANDLE, 'registered' ), 'the positive control leaked past teardown.' );

		$this->gate[] = 'assets: handle "' . Assets::HANDLE . '" is neither registered nor enqueued in wp_scripts() '
			. 'or wp_styles() — not at plugin load, and not now — and enqueues on this plugin\'s own hook, so the '
			. 'check can fail';
	}

	/**
	 * GATE 32c. `Assets::is_our_screen()` IS FALSE FOR THE FRONT END AND FOR EVERY
	 *           UNRELATED ADMIN HOOK — including the near-misses a `strpos()` test
	 *           would wrongly accept.
	 *
	 * @return void
	 */
	public function test_is_our_screen_is_false_off_our_screen() {
		// With no menu registered there is no screen of ours at all, and the empty
		// hook of a front-end request must not compare equal to the empty stored one.
		$this->assertSame( '', Menu::hook(), 'the menu is registered on a front-end request.' );
		$this->assertFalse( Assets::is_our_screen( '' ), '⚠ TIER 1: an empty hook matched an unregistered screen.' );

		$foreign = array(
			'',
			'index.php',
			'plugins.php',
			'options-general.php',
			'edit.php',
			'woocommerce_page_wc-settings',
			'woocommerce_page_wc-orders',
			// The near-misses. Every one of these CONTAINS the page slug, so every
			// one of them would be accepted by `false !== strpos( $hook, … )`.
			Menu::PAGE,
			'toplevel_page_' . Menu::PAGE,
			'woocommerce_page_' . Menu::PAGE . '-extra',
			'other_page_' . Menu::PAGE,
			'admin_page_' . Menu::PAGE,
			// ⚠ AND THE SAME NEAR-MISSES FOR THE HISTORY SLUG (ADR-0018 §10). The
			// screen set grew from one hook to two, which is precisely the moment a
			// `strpos()` starts to look like a tidy simplification.
			Menu::HISTORY_PAGE,
			'toplevel_page_' . Menu::HISTORY_PAGE,
			'woocommerce_page_' . Menu::HISTORY_PAGE . '-extra',
			'other_page_' . Menu::HISTORY_PAGE,
		);

		$this->with_registered_menu(
			function ( string $hook ) use ( $foreign ) {
				$this->assertTrue( Assets::is_our_screen( $hook ), 'the positive control failed: our own hook is not ours.' );

				// BOTH of this plugin's screens are ours, and the set is exactly two.
				$ours = Menu::hooks();

				$this->assertCount( 2, $ours, 'this plugin no longer owns exactly the rules and history screens.' );

				foreach ( $ours as $mine ) {
					$this->assertTrue( Assets::is_our_screen( $mine ), 'a registered screen of ours was not recognised.' );
				}

				foreach ( $foreign as $other ) {
					if ( in_array( $other, $ours, true ) ) {
						continue;
					}

					$this->assertFalse(
						Assets::is_our_screen( $other ),
						'⚠ TIER 1: the asset gate claimed "' . $other . '" is this plugin\'s screen.'
					);

					// And nothing was enqueued as a side effect of being asked.
					Assets::enqueue( $other );

					$this->assertFalse(
						wp_script_is( Assets::HANDLE, 'enqueued' ),
						'⚠ TIER 1: the admin script enqueued on "' . $other . '".'
					);
				}
			}
		);

		$this->gate[] = 'screen gate: is_our_screen() is false for the front end and for ' . count( $foreign )
			. ' foreign hooks, 5 of which CONTAIN the page slug and would pass a strpos() test; '
			. 'enqueue() on each of them adds nothing';
	}

	/**
	 * GATE 32d. NO ADMIN SCREEN, HANDLER OR AJAX ENDPOINT IS REACHABLE — none of
	 *           them is even hooked, because the whole branch is behind `is_admin()`.
	 *
	 * ⚠ `wp_ajax_nopriv_` IS THE ONE THAT WOULD MATTER MOST, so it is asserted
	 * separately and by name rather than folded into a loop.
	 *
	 * ⚠ ASSERTED ON THE BOOTSTRAP'S RECORDING, NOT ON LIVE `has_action()`. By the
	 * time this class runs, sibling tests have deliberately registered the very
	 * hooks under test in order to exercise them — so live globals answer about the
	 * suite, not about the plugin. `tests/bootstrap.php` took the reading at the one
	 * moment it meant something. The nopriv twin is the exception and is ALSO
	 * asserted live, because nothing in this plugin or this suite ever registers it,
	 * so its absence is a standing property rather than a moment in time.
	 *
	 * @return void
	 */
	public function test_no_admin_entry_point_is_hooked() {
		$loaded = $this->at_plugin_load();

		$this->assertSame( '', $loaded['menu_hook'], '⚠ TIER 1: the menu registered a hook on a front-end request.' );
		$this->assertSame( '', $loaded['history_hook'], '⚠ TIER 1: the history screen registered a hook on a front-end request.' );

		foreach ( array( 'admin_menu', 'admin_enqueue_scripts', 'wp_ajax', 'add_meta_boxes' ) as $hook ) {
			$this->assertFalse(
				$loaded[ $hook ],
				'⚠ TIER 1: "' . $hook . '" was hooked when the plugin loaded on a front-end request.'
			);
		}

		// THE NOPRIV TWIN MUST NOT EXIST AT ALL — not on the front end, not in the
		// admin, not anywhere. `TargetSearch::register()` registers only the
		// privileged variant, and a `nopriv` twin would open a product-enumeration
		// endpoint to logged-out visitors.
		$this->assertFalse( $loaded['wp_ajax_nopriv'], '⚠ TIER 1: a wp_ajax_nopriv_ twin existed at plugin load.' );

		$this->assertFalse(
			has_action( 'wp_ajax_nopriv_' . TargetSearch::ACTION ),
			'⚠ TIER 1: the target search has a wp_ajax_nopriv_ twin.'
		);

		// And registering it — which only ever happens inside `is_admin()` — still
		// does not create one. The positive control is undone afterwards, so this
		// test leaves the process exactly as it found it.
		$was_hooked = has_action( 'wp_ajax_' . TargetSearch::ACTION, array( TargetSearch::class, 'handle' ) );

		TargetSearch::register();

		$this->assertNotFalse(
			has_action( 'wp_ajax_' . TargetSearch::ACTION, array( TargetSearch::class, 'handle' ) ),
			'the positive control failed: register() did not hook the privileged endpoint.'
		);

		$this->assertFalse(
			has_action( 'wp_ajax_nopriv_' . TargetSearch::ACTION ),
			'⚠ TIER 1: register() created a wp_ajax_nopriv_ twin.'
		);

		if ( false === $was_hooked ) {
			remove_action( 'wp_ajax_' . TargetSearch::ACTION, array( TargetSearch::class, 'handle' ) );
		}

		$this->gate[] = 'entry points: at plugin load on a front-end request, Menu::hook() was "", and admin_menu, '
			. 'admin_enqueue_scripts and wp_ajax_' . TargetSearch::ACTION . ' were all unhooked; wp_ajax_nopriv_'
			. TargetSearch::ACTION . ' does not exist then, now, or after register() runs';
	}

	/**
	 * GATE 32e. AND AN UNAUTHENTICATED FRONT-END VISITOR WHO GUESSES THE URL IS
	 *           REFUSED BY THE CODE ITSELF, not only by the missing registration.
	 *
	 * ⚠ TWO INDEPENDENT REASONS, DELIBERATELY. "It is not registered" is a wiring
	 * fact that a future refactor can undo silently; "it refuses without the
	 * capability" is a property of the entry point. Gate 28 owns the second in
	 * depth — this asserts the pair holds together for a LOGGED-OUT storefront
	 * visitor, which is the front-end case.
	 *
	 * @return void
	 */
	public function test_a_logged_out_visitor_is_refused_by_the_entry_points_themselves() {
		$this->become_logged_out();

		$this->assertRefuses(
			static function () {
				Menu::render();
			},
			'⚠ TIER 1: the rules screen rendered for a logged-out visitor.'
		);

		$this->assertRefuses(
			static function () {
				Menu::load();
			},
			'⚠ TIER 1: the request dispatcher ran for a logged-out visitor.'
		);

		// ADR-0020: the preview screen renders a real customer's order data, so it
		// refuses on its own as well — through the dispatcher AND directly, because
		// `Menu::render()` routing to it is a wiring fact a refactor can undo silently
		// while the renderer's own check is a property of the entry point.
		$this->request(
			array(
				'page'   => Menu::PAGE,
				'action' => Menu::ACTION_PREVIEW,
				'rule'   => 1,
			)
		);

		$this->assertRefuses(
			static function () {
				Menu::render();
			},
			'⚠ TIER 1: the preview screen rendered for a logged-out visitor.'
		);

		$this->assertRefuses(
			static function () {
				RulePreviewScreen::render();
			},
			'⚠ TIER 1: the preview renderer ran for a logged-out visitor.'
		);

		$this->request( array() );

		// ADR-0018: the two history entry points refuse on their own too.
		$this->assertRefuses(
			static function () {
				Menu::render_history();
			},
			'⚠ TIER 1: the delivery history rendered for a logged-out visitor.'
		);

		$this->assertRefuses(
			static function () {
				Menu::load_history();
			},
			'⚠ TIER 1: the history dispatcher ran for a logged-out visitor.'
		);

		// ⚠ THE PANEL REFUSES BY RENDERING NOTHING, NOT BY DYING (ADR-0018 §8), so the
		// assertion is on the DATA rather than on a `wp_die()` this surface must not
		// issue — a fatal inside a meta box would take down WooCommerce's order screen.
		$panel = $this->capture(
			static function () {
				OrderPanel::render( (object) array( 'ID' => 4242 ) );
			}
		);

		$this->assertStringNotContainsString(
			'<table',
			$panel,
			'⚠ TIER 1: the order panel rendered delivery data for a logged-out visitor.'
		);

		$this->assertStringContainsString(
			'You are not allowed to view custom product email deliveries.',
			$panel,
			'⚠ the order panel did not refuse a logged-out visitor.'
		);

		$answer = $this->ajax(
			static function () {
				TargetSearch::handle();
			}
		);

		$this->assertFalse(
			$answer['payload']['success'] ?? true,
			'⚠ TIER 1: the target search answered a logged-out visitor.'
		);

		$this->assertArrayNotHasKey(
			'results',
			(array) ( $answer['payload']['data'] ?? array() ),
			'⚠ TIER 1: the target search leaked results to a logged-out visitor.'
		);

		$refused = RuleActions::handle( RuleActions::ACTION_SAVE, $this->valid_post(), array() );

		$this->assertNotSame(
			RuleActions::OUTCOME_REDIRECT,
			$refused['outcome'] ?? '',
			'⚠ TIER 1: a logged-out visitor completed a save.'
		);

		$this->gate[] = 'logged out: the rules screen, the dispatcher, the history screen and dispatcher, the PREVIEW '
			. 'screen and its renderer, the AJAX endpoint and the save handler each refuse a logged-out visitor on '
			. 'their own, independently of the menu never being registered';
	}

	/**
	 * GATE 32f. NOTHING IS OUTPUT DURING PLUGIN LOAD OR REGISTRATION.
	 *
	 * ⚠ ASSERTED TWICE, BECAUSE `beStrictAboutOutputDuringTests` ONLY COVERS HALF.
	 * The PHPUnit setting fails a test that prints, which catches output from code
	 * this suite calls — it says nothing about a stray closing tag in a file the
	 * suite happens never to reach. So: (1) the registration entry points are run
	 * inside an output buffer and must produce zero bytes, and (2) every production
	 * file is token-scanned for the two things that can emit bytes at load time —
	 * inline HTML, and a closing `?>` whose trailing newline is output.
	 *
	 * @return void
	 */
	public function test_nothing_is_output_during_load_or_registration() {
		$this->assertTrue(
			$this->strict_about_output(),
			'⚠ phpunit.xml.dist no longer sets beStrictAboutOutputDuringTests, so half of this gate is gone.'
		);

		$printed = $this->capture(
			function () {
				$this->with_registered_menu(
					static function ( string $hook ) {
						Assets::enqueue( 'index.php' );
						Assets::enqueue( '' );
						Assets::enqueue( $hook );
					}
				);

				TargetSearch::register();
			}
		);

		remove_action( 'wp_ajax_' . TargetSearch::ACTION, array( TargetSearch::class, 'handle' ) );
		wp_dequeue_script( Assets::HANDLE );
		wp_dequeue_style( Assets::HANDLE );
		wp_deregister_script( Assets::HANDLE );
		wp_deregister_style( Assets::HANDLE );

		$this->assertSame( '', $printed, '⚠ registration printed ' . strlen( $printed ) . ' bytes.' );

		$root  = dirname( __DIR__, 2 );
		$files = array( $root . '/extonify-custom-emails-per-product.php', $root . '/uninstall.php' );

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src' ) ) as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		$this->assertGreaterThan( 60, count( $files ), 'the production file scan found suspiciously few files.' );

		$emitters = array();

		foreach ( $files as $file ) {
			foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) {
				if ( ! is_array( $token ) ) {
					continue;
				}

				if ( T_INLINE_HTML === $token[0] || T_CLOSE_TAG === $token[0] ) {
					$emitters[] = str_replace( $root . '/', '', $file ) . ':' . $token[2];
				}
			}
		}

		$this->assertSame(
			array(),
			$emitters,
			'⚠ production files that can emit bytes at load time: ' . implode( ', ', $emitters )
		);

		$this->gate[] = 'load silence: registration printed 0 bytes, and ' . count( $files )
			. ' production files carry no inline HTML and no closing "?>" — plus beStrictAboutOutputDuringTests, '
			. 'which fails any test that prints';
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Register this plugin's menu for the duration of one callback, then put the
	 * admin menu globals — and `Menu::$hook` — back exactly as they were.
	 *
	 * ⚠ THE STATIC IS RESTORED BY REFLECTION ON PURPOSE. `Menu::$hook` has no
	 * setter, and leaving it set would make `Assets::is_our_screen()` answer `true`
	 * for whatever test runs next in this process — which is precisely the
	 * cross-test contamination gate 32 exists to rule out.
	 *
	 * @param callable $body Receives the registered hook suffix.
	 * @return void
	 */
	private function with_registered_menu( callable $body ): void {
		global $admin_page_hooks, $_registered_pages, $_parent_pages, $menu, $submenu;

		$saved = array( $admin_page_hooks, $_registered_pages, $_parent_pages, $menu, $submenu );

		// ⚠ BOTH STATICS, SINCE ADR-0018 ADDED THE HISTORY PAGE. Restoring only `$hook`
		// would leave `$history_hook` set, and `Assets::is_our_screen()` reads BOTH
		// through `Menu::hooks()` — so the next test in this process would be told the
		// history screen is ours on a front-end request, which is exactly the
		// cross-test contamination gate 32 exists to rule out.
		$properties = array();

		foreach ( array( 'hook', 'history_hook' ) as $name ) {
			$property = new \ReflectionProperty( Menu::class, $name );
			$property->setAccessible( true );

			$properties[ $name ] = array( $property, $property->getValue() );
		}

		$restore_user = get_current_user_id();
		$hook         = '';
		$history      = '';

		try {
			$this->become_manager();

			Menu::add_page();

			$hook    = Menu::hook();
			$history = Menu::history_hook();

			$this->assertNotSame( '', $hook, 'the menu did not register for a privileged user.' );
			$this->assertNotSame( '', $history, 'the history page did not register for a privileged user.' );

			$body( $hook );
		} finally {
			// `add_page()` also hangs a dispatcher on each `load-{$hook}`. Left behind
			// they would be admin handlers registered during a front-end request — the
			// very thing this class asserts never happens.
			if ( '' !== $hook ) {
				remove_action( 'load-' . $hook, array( Menu::class, 'load' ) );
			}

			if ( '' !== $history ) {
				remove_action( 'load-' . $history, array( Menu::class, 'load_history' ) );
			}

			foreach ( $properties as $entry ) {
				$entry[0]->setValue( null, $entry[1] );
			}

			list( $admin_page_hooks, $_registered_pages, $_parent_pages, $menu, $submenu ) = $saved;

			wp_set_current_user( $restore_user );
		}

		$this->assertSame( $properties['hook'][1], Menu::hook(), 'the registered hook leaked out of the helper.' );
		$this->assertSame( $properties['history_hook'][1], Menu::history_hook(), 'the history hook leaked out of the helper.' );
	}

	/**
	 * Whether the suite still fails a test that prints.
	 *
	 * @return bool
	 */
	private function strict_about_output(): bool {
		$config = (string) file_get_contents( dirname( __DIR__, 2 ) . '/phpunit.xml.dist' );

		return 1 === preg_match( '/beStrictAboutOutputDuringTests\s*=\s*"true"/', $config );
	}
}
