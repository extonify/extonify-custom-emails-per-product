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

	use AdminHarness;

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
