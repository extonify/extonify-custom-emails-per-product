<?php
/**
 * Base class for the admin-surface tests (ADR-0017).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Admin\RuleActions;
use Extonify\WCEP\Admin\RuleFormInput;
use Extonify\WCEP\Delivery\Consolidation;

defined( 'ABSPATH' ) || exit;

/**
 * Users, request superglobals, captured output, and a `wp_die()` that throws.
 *
 * ⚠ `wp_die()` MUST NOT `die()` HERE. Half of gate 28 is asserting that a screen or
 * a handler REFUSES, and every refusal in this plugin's admin goes through
 * `wp_die()` — whose default handler ends the PHP process. A test that proves a
 * refusal by terminating the suite proves nothing and reports nothing, so the three
 * `wp_die` handler filters are replaced with one that throws.
 *
 * ⚠ AND EVERY RENDER IS CAPTURED. `phpunit.xml.dist` sets
 * `beStrictAboutOutputDuringTests`, so a screen that echoes outside an output buffer
 * fails the run — which is the behaviour wanted, because it is also how gate 32's
 * "nothing outputs during plugin load" is enforced for free.
 */
abstract class AdminTestCase extends IntegrationTestCase {

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

	// -----------------------------------------------------------------------
	// Users
	// -----------------------------------------------------------------------

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

	// -----------------------------------------------------------------------
	// Requests
	// -----------------------------------------------------------------------

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
	 * A complete, valid editor submission.
	 *
	 * ⚠ THE NONCE IS REAL. Every save test that is not ABOUT the nonce carries a
	 * genuine one, so a test asserting a refusal cannot pass because it forgot it.
	 *
	 * @param array $overrides Field overrides, merged over the defaults.
	 * @param int   $rule_id   Rule being edited, or 0 for a new rule.
	 * @return array A `$_POST` array.
	 */
	protected function valid_post( array $overrides = array(), int $rule_id = 0 ): array {
		$fields = array_merge(
			array(
				'name'            => 'WCEP Admin Rule',
				'status'          => 'inactive',
				'trigger_type'    => 'status',
				'trigger_status'  => 'completed',
				'delivery_mode'   => 'separate',
				'native_email_id' => '',
				'insert_position' => 'after_order_table',
				'delay_value'     => 0,
				'delay_unit'      => 'minutes',
				'consolidation'   => Consolidation::NONE,
				'match_all'       => '1',
				'subject'         => 'Subject',
				'heading'         => 'Heading',
				'content'         => '<p>Body</p>',
				'priority'        => 10,
				'recipients'      => array( 'to' => 'customer' ),
			),
			$overrides
		);

		return array(
			'action'                 => RuleActions::ACTION_SAVE,
			'rule'                   => $rule_id,
			RuleActions::NONCE_FIELD => wp_create_nonce( RuleActions::NONCE_SAVE ),
			RuleFormInput::FIELD     => $fields,
		);
	}

	/**
	 * A nonce-carrying row-action GET.
	 *
	 * @param string $action  Row action.
	 * @param int    $rule_id Rule id.
	 * @return array
	 */
	protected function row_get( string $action, int $rule_id ): array {
		return array(
			'page'     => Menu::PAGE,
			'action'   => $action,
			'rule'     => $rule_id,
			'_wpnonce' => wp_create_nonce( RuleActions::row_nonce_action( $action, $rule_id ) ),
		);
	}

	// -----------------------------------------------------------------------
	// Output and refusals
	// -----------------------------------------------------------------------

	/**
	 * Pretend WordPress is rendering this plugin's screen.
	 *
	 * `WP_List_Table` reads the current screen in its constructor and registers
	 * column filters against its id, so a table built with no screen registers them
	 * against whatever the last request left behind.
	 *
	 * @return string The screen id.
	 */
	protected function use_our_screen(): string {
		$screen_id = 'woocommerce_page_' . Menu::PAGE;

		set_current_screen( $screen_id );

		return $screen_id;
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
	 * Run one AJAX request and return its status and decoded payload.
	 *
	 * ⚠ THE HTTP STATUS IS NOT OBSERVABLE HERE, AND THE TESTS SAY SO RATHER THAN
	 * PRETENDING OTHERWISE. `wp_send_json()` sets the response code only
	 * `if ( ! headers_sent() )`, and under the CLI SAPI `headers_sent()` is already
	 * true the moment PHPUnit prints its first progress dot — so `status_header()` is
	 * never reached in this process, whatever the endpoint asked for. The `status`
	 * key is therefore best-effort (0 when unobservable) and every assertion that
	 * matters is made on the RESPONSE BODY, which is what the picker actually reads
	 * and what a browser would act on.
	 *
	 * @param callable $run The endpoint call.
	 * @return array{status:int, payload:array}
	 */
	protected function ajax( callable $run ): array {
		$status = 0;

		$capture = static function ( $header, $code ) use ( &$status ) {
			$status = (int) $code;

			return $header;
		};

		add_filter( 'wp_doing_ajax', '__return_true', 999 );
		add_filter( 'status_header', $capture, 999, 2 );

		ob_start();

		try {
			$run();
		} catch ( AdminDieException $expected ) {
			unset( $expected );
		} finally {
			$body = (string) ob_get_clean();

			remove_filter( 'status_header', $capture, 999 );
			remove_filter( 'wp_doing_ajax', '__return_true', 999 );
		}

		$payload = json_decode( $body, true );

		return array(
			'status'  => $status,
			'payload' => is_array( $payload ) ? $payload : array(),
		);
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

	/**
	 * Every column of one stored rule, exactly as the database holds it.
	 *
	 * Used to prove a refused write left the row BYTE-IDENTICAL (gate 30) — the
	 * hydrated form would compare decoded JSON and could not see a re-encoded
	 * document, and `updated_at` and `revision` are exactly the columns a
	 * wrongly-accepted write would move.
	 *
	 * @param int $rule_id Rule id.
	 * @return array
	 */
	protected function raw_rule( int $rule_id ): array {
		global $wpdb;

		$table = \Extonify\WCEP\Install\Migrator::table( 'rules' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test-only primary-key read of the plugin-owned table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $rule_id ), ARRAY_A );

		return is_array( $row ) ? $row : array();
	}
}
