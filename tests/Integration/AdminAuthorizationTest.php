<?php
/**
 * GATE 28 — every admin entry point, its capability check and its nonce.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\Assets;
use Extonify\WCEP\Admin\ConfirmationToken;
use Extonify\WCEP\Admin\DeliveryActions;
use Extonify\WCEP\Admin\DeliveryConfirm;
use Extonify\WCEP\Admin\DeliveryHistory;
use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Admin\OrderPanel;
use Extonify\WCEP\Admin\RuleActions;
use Extonify\WCEP\Admin\RuleEditor;
use Extonify\WCEP\Admin\RuleList;
use Extonify\WCEP\Admin\TargetSearch;
use Extonify\WCEP\Plugin;

/**
 * The authorisation gate.
 *
 * ⚠ THE POINT OF THIS FILE IS THE ENUMERATION, NOT ANY ONE ASSERTION. An admin
 * surface fails by BREADTH — one handler out of nine, not one clever bug — so
 * "every handler is protected" is worth nothing beside a list naming each one.
 * self::entry_points() IS the gate-28 table, in code, and
 * self::test_the_entry_point_table_is_complete() fails the moment a write action
 * exists that the table does not name.
 *
 * ⚠ SEVERITY: a missing capability check here is TIER 1 — the consequence is
 * privilege escalation, not a bad email.
 */
final class AdminAuthorizationTest extends AdminTestCase {

	/**
	 * Gate lines printed at the end of the run.
	 *
	 * @var string[]
	 */
	private $gate = array();

	/**
	 * Print the gate-28 table.
	 *
	 * @after
	 * @return void
	 */
	protected function report_gate() {
		foreach ( $this->gate as $line ) {
			fwrite( STDERR, "\n[P9 gate 28] " . $line );
		}

		$this->gate = array();
	}

	/**
	 * THE GATE-28 TABLE. Every admin entry point, with the capability it checks and
	 * the nonce action it verifies.
	 *
	 * @return array<string,array{0:string,1:string,2:string}> Name => [kind, capability, nonce].
	 */
	public static function entry_points(): array {
		return array(
			'screen render: rules list'  => array( 'render', Menu::CAPABILITY, '— (read-only)' ),
			'screen render: add rule'    => array( 'render', Menu::CAPABILITY, '— (read-only)' ),
			'screen render: edit rule'   => array( 'render', Menu::CAPABILITY, '— (read-only)' ),
			'request dispatch: load'     => array( 'load', Menu::CAPABILITY, 'per action, below' ),
			'handler: save'              => array( 'handler', Menu::CAPABILITY, RuleActions::NONCE_SAVE ),
			'handler: delete'            => array( 'handler', Menu::CAPABILITY, 'extonify_wcep_delete_rule_{id}' ),
			'handler: duplicate'         => array( 'handler', Menu::CAPABILITY, 'extonify_wcep_duplicate_rule_{id}' ),
			'handler: activate'          => array( 'handler', Menu::CAPABILITY, 'extonify_wcep_activate_rule_{id}' ),
			'handler: deactivate'        => array( 'handler', Menu::CAPABILITY, 'extonify_wcep_deactivate_rule_{id}' ),
			'ajax: target search'        => array( 'ajax', Menu::CAPABILITY, TargetSearch::NONCE_ACTION ),
			'asset enqueue'              => array( 'assets', Menu::CAPABILITY . ' (page registration)', '— (no state change)' ),
			// --- ADR-0018: the delivery-history surfaces. Read-only, so no nonce ----
			'screen render: history'     => array( 'render', Menu::CAPABILITY, '— (read-only)' ),
			'request dispatch: history'  => array( 'load', Menu::CAPABILITY, '— (read-only)' ),
			// ⚠ THE PANEL REFUSES BY RENDERING NOTHING, NOT WITH A 403, and the table
			// records that rather than smoothing it over. It is a fragment of
			// WooCommerce's own order screen: a `wp_die()` there would take down the
			// whole order editor for a user holding `edit_shop_orders` without
			// `manage_woocommerce` (ADR-0018 §8). `OrderPanelTest` asserts the security
			// property directly — no delivery data reaches them — rather than inferring
			// it from a status code this surface should not be issuing.
			'panel render: order screen' => array( 'panel', Menu::CAPABILITY . ' (renders nothing)', '— (read-only)' ),
			// --- ADR-0019: the four actions that mail a customer -------------------
			// ⚠ EACH ALSO CONSUMES A SINGLE-USE TOKEN (gate 37), which the nonce column
			// cannot express and which is a SECOND barrier rather than a substitute for
			// one. `ManualDeliveryTest` owns that half.
			'screen render: confirm'     => array( 'render', Menu::CAPABILITY, '— (read-only)' ),
			'handler: wcep_resend'       => array( 'handler', Menu::CAPABILITY, 'extonify_wcep_wcep_resend_{id}_{rule}' ),
			'handler: wcep_send_now'     => array( 'handler', Menu::CAPABILITY, 'extonify_wcep_wcep_send_now_{id}_{rule}' ),
			'handler: wcep_cancel'       => array( 'handler', Menu::CAPABILITY, 'extonify_wcep_wcep_cancel_{id}_{rule}' ),
			'handler: wcep_send_manual'  => array( 'handler', Menu::CAPABILITY, 'extonify_wcep_wcep_send_manual_{order}_{rule}' ),
		);
	}

	/**
	 * GATE 28j. EVERY DELIVERY HANDLER REFUSES WITHOUT THE CAPABILITY — with a VALID
	 *           nonce and a VALID token, so the capability is what refused.
	 *
	 * ⚠ THE VALID NONCE AND TOKEN MATTER. A handler tested without them refuses for
	 * the wrong reason and would go on refusing if the capability check were deleted.
	 *
	 * @dataProvider delivery_action_provider
	 *
	 * @param string $action The delivery action.
	 * @return void
	 */
	public function test_every_delivery_handler_refuses_without_the_capability( string $action ) {
		$this->become_manager();

		// Minted and issued while privileged, so both are genuinely valid.
		$post = array(
			'action'                        => $action,
			DeliveryActions::FIELD_DELIVERY => 1,
			DeliveryActions::FIELD_ORDER    => 1,
			DeliveryActions::FIELD_RULE     => 1,
			DeliveryActions::FIELD_TOKEN    => ConfirmationToken::issue(),
			DeliveryActions::FIELD_NONCE    => wp_create_nonce( DeliveryActions::nonce_action( $action, 1, 1 ) ),
		);

		$_SERVER['REQUEST_METHOD'] = 'POST';

		foreach ( array( 'logged out', 'subscriber' ) as $who ) {
			if ( 'logged out' === $who ) {
				$this->become_logged_out();
			} else {
				$this->become_subscriber();
			}

			$result = DeliveryActions::handle( $action, $post );

			$this->assertSame(
				RuleActions::OUTCOME_DENIED,
				$result['outcome'] ?? '',
				'⚠ TIER 1: delivery handler "' . $action . '" did not refuse a ' . $who . ' user holding a valid nonce and token.'
			);
		}

		unset( $_SERVER['REQUEST_METHOD'] );

		$this->gate[] = 'capability: handler: ' . $action . ' refuses both a logged-out and an unprivileged user '
			. 'EVEN WITH A VALID NONCE AND A VALID SINGLE-USE TOKEN';
	}

	/**
	 * GATE 28k. THE CONFIRMATION SCREEN REFUSES WITHOUT THE CAPABILITY.
	 *
	 * @return void
	 */
	public function test_the_confirmation_screen_refuses_without_the_capability() {
		foreach ( array( 'logged out', 'subscriber' ) as $who ) {
			if ( 'logged out' === $who ) {
				$this->become_logged_out();
			} else {
				$this->become_subscriber();
			}

			$this->request(
				array(
					'page'        => Menu::HISTORY_PAGE,
					'wcep_action' => DeliveryActions::ACTION_RESEND,
					'delivery'    => 1,
				)
			);

			$refusal = $this->assertRefuses(
				static function () {
					DeliveryConfirm::render();
				},
				'⚠ TIER 1: the send-confirmation screen rendered for a ' . $who . ' user.'
			);

			$this->assertSame( 403, $refusal->status() );
		}

		$this->gate[] = 'capability: screen render: confirm refuses both a logged-out and an unprivileged user (403)';
	}

	/**
	 * Every delivery action.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function delivery_action_provider(): array {
		$cases = array();

		foreach ( DeliveryActions::write_actions() as $action ) {
			$cases[ $action ] = array( $action );
		}

		return $cases;
	}

	/**
	 * GATE 28l. THE DELIVERY ENTRY-POINT TABLE IS COMPLETE.
	 *
	 * ⚠ THE MECHANISM, NOT THE DOCUMENTATION. A fifth sending action added to
	 * `DeliveryActions::write_actions()` without a table row — and therefore without a
	 * capability test and a nonce test — fails here rather than shipping unnoticed.
	 *
	 * @return void
	 */
	public function test_the_delivery_entry_point_table_is_complete() {
		$named = array();

		foreach ( array_keys( self::entry_points() ) as $label ) {
			if ( 0 === strpos( $label, 'handler: wcep_' ) ) {
				$named[] = substr( $label, strlen( 'handler: ' ) );
			}
		}

		sort( $named );

		$actual = DeliveryActions::write_actions();
		sort( $actual );

		$this->assertSame(
			$actual,
			$named,
			'⚠ a sending action exists that the gate-28 table does not name. Add it, with its capability and nonce tests.'
		);

		$this->gate[] = 'entry-point table: ' . count( $actual ) . ' sending actions, all named';
	}

	/**
	 * GATE 28a. THE TABLE IS COMPLETE: every state-changing action this plugin
	 *           registers appears in it.
	 *
	 * ⚠ THIS IS THE MECHANISM, NOT THE DOCUMENTATION. A new row action added to
	 * `RuleActions::write_actions()` without a table entry — and therefore without a
	 * capability test and a nonce test — fails here rather than shipping unnoticed.
	 *
	 * @return void
	 */
	public function test_the_entry_point_table_is_complete() {
		$named = array();

		foreach ( array_keys( self::entry_points() ) as $label ) {
			// ⚠ THE RULE HANDLERS ONLY. ADR-0019 added four `wcep_`-prefixed SENDING
			// handlers to the same table; they have their own completeness test against
			// `DeliveryActions::write_actions()`, and folding the two lists together
			// would let a missing row in either be masked by a spare row in the other.
			if ( 0 === strpos( $label, 'handler: ' ) && 0 !== strpos( $label, 'handler: wcep_' ) ) {
				$named[] = substr( $label, strlen( 'handler: ' ) );
			}
		}

		sort( $named );

		$actual = RuleActions::write_actions();
		sort( $actual );

		$this->assertSame(
			$actual,
			$named,
			'⚠ a state-changing action exists that the gate-28 table does not name. Add it, with its capability and nonce tests.'
		);

		$this->gate[] = 'entry-point table: ' . count( self::entry_points() ) . ' entry points, '
			. count( $actual ) . " state-changing\n"
			. "             ┌───────────────────────────┬──────────┬──────────────────────┬──────────────────────────────────────┐\n"
			. "             │ entry point               │ kind     │ capability           │ nonce action                         │\n"
			. "             ├───────────────────────────┼──────────┼──────────────────────┼──────────────────────────────────────┤\n"
			. self::table_rows()
			. "             └───────────────────────────┴──────────┴──────────────────────┴──────────────────────────────────────┘\n";
	}

	/**
	 * GATE 28b. EVERY SCREEN RENDER REFUSES A USER WITHOUT THE CAPABILITY.
	 *
	 * @dataProvider screen_provider
	 *
	 * @param string $label  Entry-point name.
	 * @param array  $get    The request.
	 * @param string $method Which renderer.
	 * @return void
	 */
	public function test_a_screen_render_refuses_without_the_capability( string $label, array $get, string $method ) {
		if ( 'history' === $method || 'history_load' === $method ) {
			set_current_screen( 'woocommerce_page_' . Menu::HISTORY_PAGE );
		} else {
			$this->use_our_screen();
		}

		foreach ( array( 'logged out', 'subscriber' ) as $who ) {
			if ( 'logged out' === $who ) {
				$this->become_logged_out();
			} else {
				$this->become_subscriber();
			}

			$this->request( $get );

			$refusal = $this->assertRefuses(
				static function () use ( $method ) {
					if ( 'list' === $method ) {
						RuleList::render();
						return;
					}

					if ( 'editor' === $method ) {
						RuleEditor::render();
						return;
					}

					if ( 'history' === $method ) {
						// ⚠ THE DISPATCHER AND THE SCREEN ARE ASSERTED SEPARATELY. The
						// capability passed to `add_submenu_page()` decides whether
						// WordPress draws the LINK; `admin.php?page=…` is reachable by
						// URL whether it drew one or not.
						Menu::render_history();
						return;
					}

					if ( 'history_direct' === $method ) {
						DeliveryHistory::render();
						return;
					}

					if ( 'history_load' === $method ) {
						Menu::load_history();
						return;
					}

					Menu::render();
				},
				'⚠ ' . $label . ' rendered for a ' . $who . ' user: privilege boundary crossed.'
			);

			$this->assertSame( 403, $refusal->status(), $label . ' refused with the wrong status.' );
		}

		$this->gate[] = 'capability: ' . $label . ' refuses both a logged-out and an unprivileged user (403)';
	}

	/**
	 * The three screen renders.
	 *
	 * @return array<string,array{0:string,1:array,2:string}>
	 */
	public static function screen_provider(): array {
		return array(
			'rules list (dispatcher)' => array( 'screen render: rules list', array( 'page' => Menu::PAGE ), 'menu' ),
			'rules list (direct)'     => array( 'screen render: rules list', array( 'page' => Menu::PAGE ), 'list' ),
			'add rule (dispatcher)'   => array(
				'screen render: add rule',
				array(
					'page'   => Menu::PAGE,
					'action' => Menu::ACTION_NEW,
				),
				'menu',
			),
			'edit rule (dispatcher)'  => array(
				'screen render: edit rule',
				array(
					'page'   => Menu::PAGE,
					'action' => Menu::ACTION_EDIT,
					'rule'   => 1,
				),
				'menu',
			),
			'edit rule (direct)'      => array(
				'screen render: edit rule',
				array(
					'page'   => Menu::PAGE,
					'action' => Menu::ACTION_EDIT,
					'rule'   => 1,
				),
				'editor',
			),
			'history (dispatcher)'    => array(
				'screen render: history',
				array( 'page' => Menu::HISTORY_PAGE ),
				'history',
			),
			'history (direct)'        => array(
				'screen render: history',
				array( 'page' => Menu::HISTORY_PAGE ),
				'history_direct',
			),
			'history (load)'          => array(
				'request dispatch: history',
				array( 'page' => Menu::HISTORY_PAGE ),
				'history_load',
			),
		);
	}

	/**
	 * GATE 28c. THE REQUEST DISPATCHER REFUSES WITHOUT THE CAPABILITY, before it
	 *           looks at anything else.
	 *
	 * @return void
	 */
	public function test_the_dispatcher_refuses_without_the_capability() {
		$this->become_subscriber();
		$this->request( array( 'page' => Menu::PAGE ) );

		$refusal = $this->assertRefuses(
			static function () {
				Menu::load();
			},
			'⚠ Menu::load() ran for an unprivileged user.'
		);

		$this->assertSame( 403, $refusal->status() );

		$this->gate[] = 'capability: request dispatch: load refuses an unprivileged user (403)';
	}

	/**
	 * GATE 28d. EVERY HANDLER REFUSES WITHOUT THE CAPABILITY — with a VALID nonce,
	 *           so the capability is what did the refusing.
	 *
	 * ⚠ THE VALID NONCE MATTERS. A handler tested with no nonce refuses for the
	 * wrong reason and would still refuse if the capability check were deleted.
	 *
	 * @dataProvider write_action_provider
	 *
	 * @param string $action The state-changing action.
	 * @return void
	 */
	public function test_every_handler_refuses_without_the_capability( string $action ) {
		$this->become_manager();
		$rule_id = $this->make_rule();

		// The nonce is minted while privileged, so it is genuinely valid.
		$get  = $this->row_get( $action, $rule_id );
		$post = $this->valid_post( array(), $rule_id );

		$before = $this->raw_rule( $rule_id );

		foreach ( array( 'logged out', 'subscriber' ) as $who ) {
			if ( 'logged out' === $who ) {
				$this->become_logged_out();
			} else {
				$this->become_subscriber();
			}

			$result = RuleActions::handle( $action, $post, $get );

			$this->assertSame(
				RuleActions::OUTCOME_DENIED,
				$result['outcome'] ?? '',
				'⚠ handler "' . $action . '" did not refuse a ' . $who . ' user holding a valid nonce.'
			);
		}

		$this->become_manager();

		$this->assertSame(
			$before,
			$this->raw_rule( $rule_id ),
			'⚠ handler "' . $action . '" changed the stored row while refusing.'
		);

		$this->gate[] = 'capability: handler: ' . $action . ' refuses both a logged-out and an unprivileged user '
			. 'EVEN WITH A VALID NONCE; stored row byte-identical';
	}

	/**
	 * GATE 28e. EVERY HANDLER REFUSES A MISSING, WRONG-ACTION AND EXPIRED NONCE —
	 *           while the user IS privileged, so the nonce is what did the refusing.
	 *
	 * @dataProvider write_action_provider
	 *
	 * @param string $action The state-changing action.
	 * @return void
	 */
	public function test_every_handler_refuses_a_bad_nonce( string $action ) {
		$this->become_manager();

		$rule_id = $this->make_rule();
		$other   = $this->make_rule();
		$before  = $this->raw_rule( $rule_id );

		$cases = array(
			'missing'      => $this->nonce_case( $action, $rule_id, '' ),
			'empty string' => $this->nonce_case( $action, $rule_id, '   ' ),
			'garbage'      => $this->nonce_case( $action, $rule_id, 'deadbeef' ),
			// ⚠ A NONCE FOR ANOTHER ACTION ON THE SAME RULE.
			'wrong action' => $this->nonce_case(
				$action,
				$rule_id,
				wp_create_nonce(
					RuleActions::ACTION_SAVE === $action
						? RuleActions::row_nonce_action( RuleActions::ACTION_DELETE, $rule_id )
						: RuleActions::NONCE_SAVE
				)
			),
			// ⚠ A NONCE FOR THE SAME ACTION ON ANOTHER RULE — which is why the row
			// actions' nonces are rule-specific as well as action-specific.
			'wrong rule'   => $this->nonce_case(
				$action,
				$rule_id,
				wp_create_nonce( RuleActions::row_nonce_action( $action, $other ) )
			),
		);

		foreach ( $cases as $label => $case ) {
			$result = RuleActions::handle( $action, $case['post'], $case['get'] );

			$this->assertSame(
				RuleActions::OUTCOME_DENIED,
				$result['outcome'] ?? '',
				'⚠ handler "' . $action . '" accepted a ' . $label . ' nonce.'
			);
		}

		// EXPIRED: minted under one nonce tick, verified under another. That is what
		// an expired nonce IS — `wp_verify_nonce()` accepts only the current tick and
		// the one before it, and `wp_nonce_tick()` is derived from `nonce_life`.
		$expired = $this->nonce_case( $action, $rule_id, wp_create_nonce( $this->nonce_action_for( $action, $rule_id ) ) );

		add_filter( 'nonce_life', static fn() => 2, 999 );

		$result = RuleActions::handle( $action, $expired['post'], $expired['get'] );

		remove_all_filters( 'nonce_life' );

		$this->assertSame(
			RuleActions::OUTCOME_DENIED,
			$result['outcome'] ?? '',
			'⚠ handler "' . $action . '" accepted an expired nonce.'
		);

		$this->assertSame(
			$before,
			$this->raw_rule( $rule_id ),
			'⚠ handler "' . $action . '" changed the stored row while refusing a bad nonce.'
		);

		$this->gate[] = 'nonce: handler: ' . $action . ' refuses missing, blank, garbage, wrong-action, '
			. 'wrong-rule and expired nonces; stored row byte-identical';
	}

	/**
	 * GATE 28f. THE AJAX ENDPOINT REFUSES WITHOUT THE CAPABILITY AND WITHOUT THE
	 *           NONCE, and is registered for logged-in users only.
	 *
	 * @return void
	 */
	public function test_the_ajax_endpoint_refuses_without_capability_or_nonce() {
		$this->become_manager();

		$valid = wp_create_nonce( TargetSearch::NONCE_ACTION );

		/*
		 * ⚠ `$_REQUEST` AS WELL AS `$_GET`, WHICH IS NOT A DETAIL.
		 * `check_ajax_referer()` reads the nonce out of `$_REQUEST` — PHP populates
		 * that from the query string on a real request — so a test that set only
		 * `$_GET` would make every case a MISSING-nonce case, and the wrong-action and
		 * valid-nonce cases would both pass for the wrong reason.
		 */
		$call = function ( array $query ) {
			$this->request( $query );

			return $this->ajax(
				static function () {
					TargetSearch::handle();
				}
			);
		};

		// 1. Unprivileged, VALID nonce — so the capability is what refused.
		$this->become_subscriber();

		$this->assertRefusedJson(
			$call(
				array(
					'kind'  => 'products',
					'term'  => 'x',
					'nonce' => $valid,
				)
			),
			'⚠ the search endpoint answered an unprivileged user holding a valid nonce.'
		);

		// 2. Privileged, no nonce.
		$this->become_manager();

		$this->assertRefusedJson(
			$call(
				array(
					'kind' => 'products',
					'term' => 'x',
				)
			),
			'⚠ the search endpoint answered without a nonce.'
		);

		// 3. Privileged, a nonce for a DIFFERENT action.
		$this->assertRefusedJson(
			$call(
				array(
					'kind'  => 'products',
					'term'  => 'x',
					'nonce' => wp_create_nonce( RuleActions::NONCE_SAVE ),
				)
			),
			'⚠ the search endpoint accepted a nonce minted for another action.'
		);

		// 4. Privileged, valid nonce, UNKNOWN kind — refused rather than repaired
		//    into `products`, which is what `sanitize_key()` alone would have done.
		$this->assertRefusedJson(
			$call(
				array(
					'kind'  => 'PRODUCTS!',
					'term'  => 'x',
					'nonce' => $valid,
				)
			),
			'⚠ the search endpoint accepted a kind outside Targeting::ID_KINDS.'
		);

		// 5. POSITIVE CONTROL: privileged, valid nonce, known kind — it answers.
		$answer = $call(
			array(
				'kind'  => 'products',
				'term'  => 'WCEP',
				'nonce' => $valid,
			)
		);

		$this->assertTrue(
			$answer['payload']['success'] ?? false,
			'⚠ the search endpoint refuses every request, so its refusal tests prove nothing.'
		);
		$this->assertIsArray( $answer['payload']['data']['results'] ?? null );

		// 6. AND IT IS NOT REGISTERED FOR LOGGED-OUT VISITORS AT ALL.
		remove_all_actions( 'wp_ajax_' . TargetSearch::ACTION );
		remove_all_actions( 'wp_ajax_nopriv_' . TargetSearch::ACTION );

		TargetSearch::register();

		$this->assertNotFalse(
			has_action( 'wp_ajax_' . TargetSearch::ACTION, array( TargetSearch::class, 'handle' ) ),
			'the privileged AJAX action was not registered.'
		);

		$this->assertFalse(
			has_action( 'wp_ajax_nopriv_' . TargetSearch::ACTION, array( TargetSearch::class, 'handle' ) ),
			'⚠ the search endpoint is exposed to logged-out visitors.'
		);

		// ⚠ AND IT IS UNREGISTERED AGAIN. This test registered a GLOBAL hook to
		// observe it; leaving it behind would hand every later test in this process
		// an AJAX endpoint the plugin never wired on a front-end request — which is
		// precisely the state `AdminIsolationTest` exists to rule out.
		remove_all_actions( 'wp_ajax_' . TargetSearch::ACTION );
		remove_all_actions( 'wp_ajax_nopriv_' . TargetSearch::ACTION );

		$this->gate[] = 'ajax: target search refuses unprivileged, missing-nonce, wrong-action-nonce (403) '
			. 'and unknown kind (400); no wp_ajax_nopriv_ twin';
	}

	/**
	 * GATE 28g. A PRIVILEGED USER WITH A VALID NONCE IS **ACCEPTED** — so every
	 *           refusal above is a refusal rather than a handler that never works.
	 *
	 * @dataProvider write_action_provider
	 *
	 * @param string $action The state-changing action.
	 * @return void
	 */
	public function test_a_privileged_request_with_a_valid_nonce_is_accepted( string $action ) {
		$this->become_manager();

		$rule_id = $this->make_rule();

		$result = RuleActions::handle(
			$action,
			$this->valid_post( array(), $rule_id ),
			$this->row_get( $action, $rule_id )
		);

		$this->assertSame(
			RuleActions::OUTCOME_REDIRECT,
			$result['outcome'] ?? '',
			'⚠ handler "' . $action . '" refused a privileged request with a valid nonce, so its refusal tests prove nothing.'
		);

		$this->gate[] = 'positive control: handler: ' . $action . ' accepts a privileged request with a valid nonce';
	}

	/**
	 * GATE 28h. THE MENU IS NOT REGISTERED AT ALL for a user without the capability
	 *           — and the asset enqueue therefore has no screen to fire on.
	 *
	 * @return void
	 */
	public function test_the_page_is_not_registered_for_an_unprivileged_user() {
		global $admin_page_hooks, $_registered_pages, $_parent_pages;

		$saved = array( $admin_page_hooks, $_registered_pages, $_parent_pages );

		$this->become_subscriber();

		Menu::add_page();

		$this->assertSame( '', Menu::hook(), '⚠ the screen registered a hook for an unprivileged user.' );
		$this->assertSame( '', Menu::history_hook(), '⚠ the history screen registered a hook for an unprivileged user.' );
		$this->assertSame( array(), Menu::hooks(), '⚠ an unprivileged user owns a screen this plugin would load assets on.' );
		$this->assertFalse( Assets::is_our_screen( 'woocommerce_page_' . Menu::PAGE ) );
		$this->assertFalse( Assets::is_our_screen( 'woocommerce_page_' . Menu::HISTORY_PAGE ) );

		list( $admin_page_hooks, $_registered_pages, $_parent_pages ) = $saved;

		$this->gate[] = 'asset enqueue: neither the rules page nor the history page registers a hook for an '
			. 'unprivileged user, so no asset can load';
	}

	/**
	 * GATE 28i. THE ORDER PANEL IS NEITHER REGISTERED NOR RENDERED FOR A USER WITHOUT
	 *           THE CAPABILITY (ADR-0018 §8).
	 *
	 * ⚠ IT REFUSES BY RENDERING NOTHING RATHER THAN WITH A 403, so the assertion is on
	 * the DATA rather than on a status code. `wp_die()` inside a meta box would take
	 * down WooCommerce's whole order editor for a user who legitimately holds
	 * `edit_shop_orders` — a worse outcome than the one it would be preventing.
	 * `OrderPanelTest` covers this in depth; this is the gate-28 row.
	 *
	 * @return void
	 */
	public function test_the_order_panel_refuses_without_the_capability() {
		$this->become_subscriber();

		$markup = '';

		ob_start();

		try {
			OrderPanel::render( (object) array( 'ID' => 4242 ) );
		} finally {
			$markup = (string) ob_get_clean();
		}

		$this->assertStringContainsString(
			'You are not allowed to view custom product email deliveries.',
			$markup,
			'⚠ the order panel did not refuse an unprivileged user.'
		);

		$this->assertStringNotContainsString(
			'<table',
			$markup,
			'⚠ TIER 1: the order panel rendered delivery data for an unprivileged user.'
		);

		$this->gate[] = 'capability: panel render: order screen refuses an unprivileged user by rendering a sentence '
			. 'and no delivery table (deliberately not a 403 — see the entry-point table)';
	}

	/**
	 * Assert one AJAX answer is a refusal that leaked nothing.
	 *
	 * ⚠ ASSERTED ON THE BODY, NOT THE STATUS. Under the CLI SAPI `headers_sent()` is
	 * already true when `wp_send_json()` runs, so it never reaches `status_header()`
	 * and the HTTP code is unobservable in this process whatever the endpoint asked
	 * for — see `AdminTestCase::ajax()`. The body is what the picker reads and what a
	 * browser would act on, and it is where the leak would be if there were one.
	 *
	 * @param array  $answer  Result of self::ajax().
	 * @param string $message Assertion message.
	 * @return void
	 */
	private function assertRefusedJson( array $answer, string $message ): void {
		$this->assertFalse( $answer['payload']['success'] ?? true, $message );

		$this->assertArrayNotHasKey(
			'results',
			(array) ( $answer['payload']['data'] ?? array() ),
			$message . ' (results were returned alongside the refusal)'
		);

		$this->assertNotSame(
			'',
			(string) ( $answer['payload']['data']['message'] ?? '' ),
			'the refusal carried no message for the picker to show.'
		);
	}

	/**
	 * Every state-changing action.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function write_action_provider(): array {
		$cases = array();

		foreach ( RuleActions::write_actions() as $action ) {
			$cases[ $action ] = array( $action );
		}

		return $cases;
	}

	/**
	 * Build a request carrying one specific nonce.
	 *
	 * @param string $action  The action.
	 * @param int    $rule_id Rule id.
	 * @param string $nonce   The nonce to carry.
	 * @return array{get:array, post:array}
	 */
	private function nonce_case( string $action, int $rule_id, string $nonce ): array {
		$post = $this->valid_post( array(), $rule_id );
		$get  = $this->row_get( $action, $rule_id );

		$post[ RuleActions::NONCE_FIELD ] = $nonce;
		$get['_wpnonce']                  = $nonce;

		return array(
			'get'  => $get,
			'post' => $post,
		);
	}

	/**
	 * The nonce action one action uses.
	 *
	 * @param string $action  The action.
	 * @param int    $rule_id Rule id.
	 * @return string
	 */
	private function nonce_action_for( string $action, int $rule_id ): string {
		return RuleActions::ACTION_SAVE === $action
			? RuleActions::NONCE_SAVE
			: RuleActions::row_nonce_action( $action, $rule_id );
	}

	/**
	 * A tracked rule to act on.
	 *
	 * @return int
	 */
	private function make_rule(): int {
		$rule_id = Plugin::instance()->rules()->insert(
			array(
				'name'          => 'WCEP Auth Fixture',
				'status'        => 'active',
				'trigger_type'  => 'status',
				'trigger_value' => 'completed',
				'delivery_mode' => 'separate',
				'targeting'     => array( 'match_all' => true ),
				'recipients'    => array( 'to' => array( 'customer' ) ),
				'subject'       => 'Subject',
				'content'       => '<p>Body</p>',
			)
		);

		$this->assertGreaterThan( 0, $rule_id, 'Could not create the rule fixture.' );

		return $this->track_rule( $rule_id );
	}

	/**
	 * The gate-28 table, rendered.
	 *
	 * @return string
	 */
	private static function table_rows(): string {
		$rows = '';

		foreach ( self::entry_points() as $label => $spec ) {
			$rows .= '             │ ' . str_pad( $label, 25 ) . ' │ ' . str_pad( $spec[0], 8 ) . ' │ '
				. str_pad( $spec[1], 20 ) . ' │ ' . str_pad( $spec[2], 36 ) . " │\n";
		}

		return $rows;
	}
}
