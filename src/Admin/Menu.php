<?php
/**
 * Admin screen registration and request dispatch (ADR-0017 §1).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The one submenu page, and the dispatcher behind it.
 *
 * ⚠ EVERY ENTRY POINT CHECKS THE CAPABILITY FOR ITSELF (ADR-0017 §1). The capability
 * passed to `add_submenu_page()` decides whether WordPress DRAWS THE LINK, and a
 * drawn link is not an authorisation: `admin.php?page=…` is reachable by URL whether
 * the menu rendered it or not, and `admin-ajax.php` never consults the menu at all.
 * `load()`, `render()` and every handler in `Admin\RuleActions` and
 * `Admin\TargetSearch` therefore ask again, and gate 28 enumerates each one with the
 * test that asserts it.
 *
 * ⚠ THE EDITOR IS THE SAME PAGE WITH AN `action` PARAMETER, not a second registered
 * page. One registration, one capability surface, one dispatcher.
 *
 * ⚠ HANDLERS RUN AT `load-{$hook}`, BEFORE ANY OUTPUT, so a successful write can
 * redirect (post/redirect/get) and a refused one can hand the render an intact form.
 * A refusal NEVER redirects: that is the shape that loses the merchant's input and
 * replaces a specific reason with a generic notice, and ADR-0017 §3 prohibits it.
 */
final class Menu {

	/**
	 * The page slug, and the `page` query argument.
	 */
	const PAGE = 'extonify-wcep-rules';

	/**
	 * The delivery-history page slug (ADR-0018 §1).
	 *
	 * ⚠ A SECOND REGISTERED PAGE, WHICH §1 OF ADR-0017 DECLINED FOR THE EDITOR AND
	 * ADR-0018 §1 GRANTS HERE. The editor is the same subject matter as the list,
	 * reached from it, so an `action` parameter was enough. History is a peer
	 * destination with its own subject — deliveries, not rules — and a merchant asking
	 * "did this customer get their email" is not editing anything. It costs one
	 * submenu row under a menu this plugin already appears in, not a top-level slot.
	 */
	const HISTORY_PAGE = 'extonify-wcep-history';

	/**
	 * The capability every screen and every handler requires.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * The parent menu this hangs under.
	 */
	const PARENT = 'woocommerce';

	/**
	 * Recognised `action` values. Anything else renders the list.
	 */
	const ACTION_NEW  = 'new';
	const ACTION_EDIT = 'edit';

	/**
	 * The preview screen (ADR-0020 §2, §5).
	 *
	 * ⚠ AN `action` ON THIS PAGE RATHER THAN A THIRD REGISTERED PAGE, for the reason §1
	 * of ADR-0017 gives for the editor: preview is the same subject matter as the rule
	 * list, reached from it, about one rule. It is also where ADR-0020 §5 says preview
	 * belongs — "where a merchant is already looking at a rule".
	 *
	 * ⚠ AND IT IS NOT A WRITE ACTION. It renders and it is a GET, because it writes
	 * nothing and sends nothing; the test send that sits beside it on the same screen is
	 * a POST through `DeliveryActions` and carries the whole ADR-0019 §5 gate.
	 */
	const ACTION_PREVIEW = 'preview';

	/**
	 * The hook suffix `add_submenu_page()` returned, or ''.
	 *
	 * ⚠ THE ASSET GATE IS EQUALITY AGAINST THIS, NEVER A `strpos()` ON THE PAGE NAME
	 * (ADR-0017 §6, gate 32). A substring test matches any screen whose hook happens
	 * to contain the slug, which is how admin assets end up on other plugins' pages.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * The hook suffix the HISTORY page registered, or ''.
	 *
	 * @var string
	 */
	private static $history_hook = '';

	/**
	 * The outcome `load()` produced, read by `render()`.
	 *
	 * @var array
	 */
	private static $pending = array();

	/**
	 * Register the screens. Hooks only — zero queries, zero output.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( Assets::class, 'enqueue' ) );

		TargetSearch::register();
		OrderPanel::register();
	}

	/**
	 * Add the WooCommerce submenu entry.
	 *
	 * @return void
	 */
	public static function add_page(): void {
		$hook = add_submenu_page(
			self::PARENT,
			__( 'Custom Product Emails', 'extonify-custom-emails-per-product' ),
			__( 'Custom Product Emails', 'extonify-custom-emails-per-product' ),
			self::CAPABILITY,
			self::PAGE,
			array( self::class, 'render' )
		);

		if ( ! is_string( $hook ) || '' === $hook ) {
			// The current user's capability was insufficient, so WordPress registered
			// nothing. Nothing further to hook: the page does not exist for them.
			return;
		}

		self::$hook = $hook;

		add_action( 'load-' . $hook, array( self::class, 'load' ) );

		self::add_history_page();
	}

	/**
	 * Add the delivery-history submenu entry (ADR-0018 §1).
	 *
	 * ⚠ ITS OWN `load-` HANDLER, AND ITS OWN CAPABILITY CHECK. The capability passed
	 * to `add_submenu_page()` decides whether WordPress DRAWS THE LINK; it is not an
	 * authorisation, because `admin.php?page=…` is reachable by URL whether the menu
	 * rendered it or not.
	 *
	 * @return void
	 */
	private static function add_history_page(): void {
		$hook = add_submenu_page(
			self::PARENT,
			__( 'Custom Email History', 'extonify-custom-emails-per-product' ),
			__( 'Custom Email History', 'extonify-custom-emails-per-product' ),
			self::CAPABILITY,
			self::HISTORY_PAGE,
			array( self::class, 'render_history' )
		);

		if ( ! is_string( $hook ) || '' === $hook ) {
			return;
		}

		self::$history_hook = $hook;

		add_action( 'load-' . $hook, array( self::class, 'load_history' ) );
	}

	/**
	 * The registered hook suffix, or '' when the page was not registered.
	 *
	 * @return string
	 */
	public static function hook(): string {
		return self::$hook;
	}

	/**
	 * The history page's hook suffix, or '' when it was not registered.
	 *
	 * @return string
	 */
	public static function history_hook(): string {
		return self::$history_hook;
	}

	/**
	 * Every hook suffix this plugin owns, with the empty ones removed.
	 *
	 * ⚠ THE ASSET GATE READS THIS AND COMPARES BY STRICT EQUALITY (gate 32). The
	 * empties are filtered out so an unregistered page's `''` can never compare equal
	 * to the empty hook suffix of a request that registered no menu at all.
	 *
	 * @return string[]
	 */
	public static function hooks(): array {
		return array_values( array_filter( array( self::$hook, self::$history_hook ) ) );
	}

	/**
	 * Prepare the history screen, before any output.
	 *
	 * @return void
	 */
	public static function load_history(): void {
		self::require_capability();

		$action = self::requested_action();

		/*
		 * ⚠ THE SENDING ACTIONS RUN HERE, AT `load-{$hook}`, BEFORE ANY OUTPUT — the
		 * same place `Admin\Menu::load()` runs the rule handlers, and for the same
		 * reason: a completed action must redirect (post/redirect/get), and a redirect
		 * needs headers that have not been sent. It also means a reload after a send
		 * re-issues a GET rather than re-submitting the POST.
		 */
		if ( DeliveryActions::is_write_action( $action ) ) {
			self::dispatch( DeliveryActions::handle( $action, $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- DeliveryActions::handle() verifies the capability, the request method, an action-specific nonce and a single-use token before it reads anything; passing $_POST in is what makes that verification testable without a live request.
			return;
		}

		if ( '' !== DeliveryConfirm::requested_action() ) {
			// The confirmation screen is read-only and builds nothing here.
			return;
		}

		DeliveryHistory::prepare();
	}

	/**
	 * Render the history screen, or the confirmation screen it links to.
	 *
	 * @return void
	 */
	public static function render_history(): void {
		self::require_capability();

		if ( '' !== DeliveryConfirm::requested_action() ) {
			DeliveryConfirm::render();
			return;
		}

		DeliveryHistory::render();
	}

	/**
	 * A URL on the history screen.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public static function history_url( array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => self::HISTORY_PAGE ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Handle the request before anything is output.
	 *
	 * @return void
	 */
	public static function load(): void {
		self::require_capability();

		$action = self::requested_action();

		if ( RuleActions::is_write_action( $action ) ) {
			self::dispatch( RuleActions::handle( $action, $_POST, $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- RuleActions::handle() verifies an action-specific nonce and the capability before it reads either superglobal; passing them in is what makes that verification testable without a live request.
			return;
		}

		/*
		 * ⚠ THE TEST SEND IS NOT DISPATCHED HERE, DELIBERATELY. It is a
		 * `DeliveryActions` write action like the other four and is confirmed and
		 * submitted on the history screen, where `load_history()` already dispatches
		 * every member of `DeliveryActions::write_actions()`. A second dispatch site for
		 * the same handler would be a second entry point to protect, prove and keep in
		 * step for no gain — the only thing this screen needs is to be where the merchant
		 * LANDS afterwards, which `DeliveryActions::return_url()` handles.
		 */
		if ( self::ACTION_EDIT === $action || self::ACTION_PREVIEW === $action ) {
			// A screen-option-free page still wants the editor's own list-table
			// dependencies absent; nothing to prepare here beyond the capability. The
			// preview screen builds its own render at output time and writes nothing.
			return;
		}

		RuleList::prepare();
	}

	/**
	 * Render whichever screen the request asked for.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::require_capability();

		$action = self::requested_action();

		if ( self::ACTION_PREVIEW === $action && array() === self::$pending ) {
			RulePreviewScreen::render();
			return;
		}

		if ( self::ACTION_NEW === $action || self::ACTION_EDIT === $action || array() !== self::$pending ) {
			RuleEditor::render( self::$pending );
			return;
		}

		RuleList::render();
	}

	/**
	 * The `action` this request asked for.
	 *
	 * ⚠ READ FROM `$_REQUEST` AND `sanitize_key()`-ED BEFORE ANY COMPARISON. It only
	 * ever selects a branch — it is never stored, never echoed and never reaches a
	 * query — and an unrecognised value falls through to the list rather than being
	 * guessed at.
	 *
	 * @return string
	 */
	public static function requested_action(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which SCREEN to show; every state-changing branch verifies its own nonce before doing anything.
		return isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
	}

	/**
	 * The rule id this request asked for, or 0.
	 *
	 * @return int
	 */
	public static function requested_rule_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which rule to SHOW; every state-changing branch verifies its own nonce before doing anything.
		$raw = isset( $_REQUEST['rule'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['rule'] ) ) : '';

		return max( 0, (int) $raw );
	}

	/**
	 * A URL on this screen.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => self::PAGE ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * A nonce-carrying URL for one row action.
	 *
	 * @param string $action  One of `RuleActions`' row actions.
	 * @param int    $rule_id Rule id.
	 * @return string
	 */
	public static function row_action_url( string $action, int $rule_id ): string {
		return wp_nonce_url(
			self::url(
				array(
					'action' => $action,
					'rule'   => $rule_id,
				)
			),
			RuleActions::row_nonce_action( $action, $rule_id )
		);
	}

	/**
	 * Refuse anyone without the capability, on every screen and before anything else.
	 *
	 * @return void
	 */
	public static function require_capability(): void {
		if ( current_user_can( self::CAPABILITY ) ) {
			return;
		}

		wp_die(
			esc_html__( 'You are not allowed to manage custom product emails.', 'extonify-custom-emails-per-product' ),
			esc_html__( 'Custom Product Emails', 'extonify-custom-emails-per-product' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Act on one handler outcome.
	 *
	 * @param array $result Outcome from `RuleActions::handle()`.
	 * @return void
	 */
	private static function dispatch( array $result ): void {
		$outcome = (string) ( $result['outcome'] ?? '' );

		if ( RuleActions::OUTCOME_DENIED === $outcome ) {
			wp_die(
				esc_html( (string) ( $result['message'] ?? '' ) ),
				esc_html__( 'Custom Product Emails', 'extonify-custom-emails-per-product' ),
				array( 'response' => 403 )
			);
		}

		if ( RuleActions::OUTCOME_REDIRECT === $outcome ) {
			wp_safe_redirect( (string) ( $result['url'] ?? self::url() ) );
			exit;
		}

		// OUTCOME_REFUSED: no redirect, no success notice, and the merchant's own
		// input is handed to the renderer intact (ADR-0017 §3.2).
		self::$pending = $result;
	}
}
