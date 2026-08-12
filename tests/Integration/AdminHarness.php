<?php
/**
 * The generic admin test harness: users, request superglobals, captured output and
 * a `wp_die()` that throws.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\Menu;

defined( 'ABSPATH' ) || exit;

/**
 * ⚠ A TRAIT BECAUSE TWO BASE CLASSES NEED IT AND PHP HAS ONE PARENT.
 * `AdminTestCase extends IntegrationTestCase` and
 * `DeliveryTestCase extends MatchingTestCase` are sibling branches, and Prompt 11's
 * tests need BOTH — an admin request (users, nonces, `wp_die()`) and the delivery
 * harness (mail capture, live email settings), because the thing under test is an
 * admin click that sends a customer an email. Copying the harness into a second base
 * class would give the suite two definitions of "become a manager" to drift apart.
 *
 * ⚠ `wp_die()` MUST NOT `die()` HERE. Half of gate 28 is asserting that a screen or a
 * handler REFUSES, and every refusal in this plugin's admin goes through `wp_die()` —
 * whose default handler ends the PHP process. A test that proves a refusal by
 * terminating the suite proves nothing and reports nothing, so the three `wp_die`
 * handler filters are replaced with one that throws.
 */
trait AdminHarness {

	/**
	 * User ids created by this test.
	 *
	 * @var int[]
	 */
	protected $user_ids = array();

	/**
	 * The `manage_woocommerce` user, created lazily.
	 *
	 * @var int
	 */
	protected $admin_id = 0;

	/**
	 * A user WITHOUT `manage_woocommerce`, created lazily.
	 *
	 * @var int
	 */
	protected $subscriber_id = 0;

	/**
	 * Superglobals as they were before this test.
	 *
	 * @var array
	 */
	private $saved_request = array();

	/**
	 * Install the throwing `wp_die()` handlers and snapshot the request.
	 *
	 * @before
	 * @return void
	 */
	protected function set_up_admin_harness() {
		/*
		 * ⚠ THE ADMIN FUNCTION LIBRARY, LOADED EXPLICITLY. The suite boots WordPress
		 * through `wp-load.php` with no `WP_ADMIN`, so `add_submenu_page()`,
		 * `set_current_screen()`, `submit_button()` and `WP_List_Table` are simply not
		 * defined — and a test that skipped because a function was missing would look
		 * exactly like a test that passed. Requiring the library is a test-harness
		 * concern only: `is_admin()` stays FALSE, which is what lets
		 * `AdminIsolationTest` assert the front-end guarantee against a genuinely
		 * front-end request in this same process.
		 */
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/admin.php';
		}

		$handler = static function () {
			return static function ( $message, $title = '', $args = array() ) {
				throw new AdminDieException(
					is_wp_error( $message ) ? $message->get_error_message() : (string) $message,
					(int) ( is_array( $args ) ? ( $args['response'] ?? 0 ) : 0 )
				);
			};
		};

		foreach ( array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler' ) as $filter ) {
			add_filter( $filter, $handler, 999 );
		}

		$this->saved_request = array(
			'GET'     => $_GET,
			'POST'    => $_POST,
			'REQUEST' => $_REQUEST,
			'SERVER'  => $_SERVER['REQUEST_METHOD'] ?? null,
			'user'    => get_current_user_id(),
		);

		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * Restore everything this test changed, and prove the users are gone.
	 *
	 * @after
	 * @return void
	 */
	protected function tear_down_admin_harness() {
		remove_all_filters( 'wp_die_handler' );
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_json_handler' );
		remove_all_filters( 'wp_doing_ajax' );

		$_GET     = $this->saved_request['GET'] ?? array();
		$_POST    = $this->saved_request['POST'] ?? array();
		$_REQUEST = $this->saved_request['REQUEST'] ?? array();

		if ( null === ( $this->saved_request['SERVER'] ?? null ) ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $this->saved_request['SERVER'];
		}

		wp_set_current_user( (int) ( $this->saved_request['user'] ?? 0 ) );

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );

			$this->assertFalse(
				get_userdata( $user_id ),
				'A test user survived teardown: #' . $user_id
			);
		}

		$this->user_ids      = array();
		$this->admin_id      = 0;
		$this->subscriber_id = 0;
	}

	/**
	 * Become a user who may manage custom product emails.
	 *
	 * @return int User id.
	 */
	protected function become_manager(): int {
		if ( 0 === $this->admin_id ) {
			$this->admin_id = $this->make_user( 'administrator', array( Menu::CAPABILITY ) );
		}

		wp_set_current_user( $this->admin_id );

		$this->assertTrue(
			current_user_can( Menu::CAPABILITY ),
			'The privileged fixture does not hold ' . Menu::CAPABILITY . '.'
		);

		return $this->admin_id;
	}

	/**
	 * Become a logged-in user who may NOT.
	 *
	 * @return int User id.
	 */
	protected function become_subscriber(): int {
		if ( 0 === $this->subscriber_id ) {
			$this->subscriber_id = $this->make_user( 'subscriber', array() );
		}

		wp_set_current_user( $this->subscriber_id );

		$this->assertFalse(
			current_user_can( Menu::CAPABILITY ),
			'⚠ the unprivileged fixture holds ' . Menu::CAPABILITY . ', so this test could not fail.'
		);

		return $this->subscriber_id;
	}

	/**
	 * Become nobody at all.
	 *
	 * @return void
	 */
	protected function become_logged_out(): void {
		wp_set_current_user( 0 );

		$this->assertFalse( current_user_can( Menu::CAPABILITY ) );
	}

	/**
	 * Create a tracked user.
	 *
	 * @param string   $role         Role slug.
	 * @param string[] $capabilities Extra capabilities to grant explicitly.
	 * @return int
	 */
	protected function make_user( string $role, array $capabilities ): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'wcep_' . $role . '_' . wp_generate_password( 8, false, false ),
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => 'wcep_' . wp_generate_password( 10, false, false ) . '@example.test',
				'role'       => $role,
			)
		);

		$this->assertIsInt( $user_id, 'Could not create the test user.' );

		$this->user_ids[] = (int) $user_id;

		$user = get_userdata( (int) $user_id );

		foreach ( $capabilities as $capability ) {
			$user->add_cap( $capability );
		}

		return (int) $user_id;
	}

	/**
	 * Populate `$_GET`, `$_POST` and `$_REQUEST` for one simulated request.
	 *
	 * @param array $get  GET arguments.
	 * @param array $post POST arguments.
	 * @return void
	 */
	protected function request( array $get = array(), array $post = array() ): void {
		$_GET     = $get;
		$_POST    = $post;
		$_REQUEST = array_merge( $get, $post );
	}

	/**
	 * Declare this simulated request's HTTP method.
	 *
	 * ⚠ NEEDED BY GATE 36. `DeliveryActions::handle()` refuses anything that is not a
	 * POST, so a test that never sets the method would have every send refused for the
	 * wrong reason — and the GET-refusal test would pass without proving anything.
	 *
	 * @param string $method `GET` or `POST`.
	 * @return void
	 */
	protected function use_method( string $method ): void {
		$_SERVER['REQUEST_METHOD'] = $method;
	}

	/**
	 * Run a renderer and return everything it echoed.
	 *
	 * @param callable $render The renderer.
	 * @return string
	 */
	protected function capture( callable $render ): string {
		ob_start();

		try {
			$render();
		} finally {
			$markup = (string) ob_get_clean();
		}

		return $markup;
	}

	/**
	 * Assert a callable refuses through `wp_die()`, and return the refusal.
	 *
	 * @param callable $run     The entry point.
	 * @param string   $message Assertion message.
	 * @return AdminDieException
	 */
	protected function assertRefuses( callable $run, string $message ): AdminDieException {
		ob_start();

		try {
			$run();
			ob_end_clean();

			$this->fail( $message );
		} catch ( AdminDieException $refusal ) {
			ob_end_clean();

			return $refusal;
		}
	}
}
