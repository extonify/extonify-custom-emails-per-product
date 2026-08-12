<?php
/**
 * Admin asset loading (ADR-0017 §6, gate 32).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

use Extonify\WCEP\Domain\Targeting;

defined( 'ABSPATH' ) || exit;

/**
 * One stylesheet and one script, on this plugin's own screen and nowhere else.
 *
 * ⚠ THE SCREEN TEST IS `$hook_suffix === Menu::hook()` — STRICT EQUALITY AGAINST THE
 * VALUE `add_submenu_page()` RETURNED, and nothing else (gate 32). It is not a
 * substring test: `false !== strpos( $hook, 'extonify-wcep-rules' )` is the idiom
 * that puts a plugin's admin CSS on other plugins' screens, because any hook
 * containing the slug matches — and it silently starts matching more screens the
 * moment somebody registers a similarly named page. `is_our_screen()` also refuses
 * when `Menu::hook()` is empty: a menu that was never registered owns no screen, so
 * an empty hook suffix must not compare equal to an empty stored hook.
 *
 * ⚠ AND NOTHING HERE CAN REACH THE FRONT END. `admin_enqueue_scripts` does not fire
 * on a front-end request at all, and the whole of `Admin\` is registered inside
 * `Plugin::init()`'s `is_admin()` branch. Two independent reasons, because one of
 * them is a WordPress behaviour and the other is this plugin's own.
 *
 * ⚠ NO BUILD STEP (ADR-0017 §6). Hand-written CSS and hand-written ES5-compatible
 * JavaScript, versioned by the plugin version so an update busts the cache. An
 * un-reproducible build artefact in the release archive is worse than more
 * hand-written JavaScript: the archive gate would be asserting the checksum of
 * something no reviewer can regenerate from the tag.
 */
final class Assets {

	/**
	 * Script and style handle.
	 */
	const HANDLE = 'extonify-wcep-admin';

	/**
	 * Enqueue on this plugin's screen only.
	 *
	 * @param string $hook_suffix The screen WordPress is rendering.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ): void {
		$hook_suffix = (string) $hook_suffix;

		// ⚠ THE ORDER SCREEN GETS THE STYLESHEET AND NOTHING ELSE (ADR-0018 §10). The
		// script exists for the targeting picker and the delete confirmation, neither of
		// which is on somebody else's screen — and the localized payload carries a nonce
		// for an endpoint the panel never calls. Shipping either onto the WooCommerce
		// order editor would be this plugin putting its JavaScript on a page it does not
		// own, which is the same failure as putting its CSS there.
		if ( self::is_order_screen() && ! self::is_our_screen( $hook_suffix ) ) {
			wp_enqueue_style(
				self::HANDLE,
				EXTONIFY_WCEP_URL . 'assets/admin.css',
				array(),
				EXTONIFY_WCEP_VERSION
			);

			return;
		}

		if ( ! self::is_our_screen( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			EXTONIFY_WCEP_URL . 'assets/admin.css',
			array(),
			EXTONIFY_WCEP_VERSION
		);

		wp_enqueue_script(
			self::HANDLE,
			EXTONIFY_WCEP_URL . 'assets/admin.js',
			array(),
			EXTONIFY_WCEP_VERSION,
			true
		);

		wp_localize_script( self::HANDLE, 'extonifyWcepAdmin', self::data() );
	}

	/**
	 * Whether this is one of the screens this plugin registered.
	 *
	 * ⚠ STRICT EQUALITY AGAINST AN ENUMERATED SET, NOT A `strpos()`. The set grew from
	 * one hook to two when ADR-0018 added the history page, and that growth is exactly
	 * the moment a substring test looks tempting — it would accept every hook
	 * CONTAINING either slug, including `woocommerce_page_extonify-wcep-rules-extra`,
	 * and it would silently start matching more screens the day somebody registered a
	 * similarly named page.
	 *
	 * ⚠ AND AN UNREGISTERED PAGE CANNOT MATCH. `Menu::hooks()` filters out the empty
	 * hooks, so the `''` hook suffix of a front-end request never compares equal to the
	 * `''` of a menu that was never registered.
	 *
	 * @param string $hook_suffix The screen WordPress is rendering.
	 * @return bool
	 */
	public static function is_our_screen( string $hook_suffix ): bool {
		if ( '' === $hook_suffix ) {
			return false;
		}

		return in_array( $hook_suffix, Menu::hooks(), true );
	}

	/**
	 * Whether WordPress is rendering the WooCommerce order editor (ADR-0018 §8).
	 *
	 * ⚠ THE HOOK SUFFIX CANNOT ANSWER THIS UNDER LEGACY STORAGE. There the order
	 * editor is `post.php`, whose hook suffix is shared with every other post type on
	 * the site — so the question is asked of the SCREEN, whose id distinguishes them,
	 * and compared by strict equality against the id the active storage reports.
	 *
	 * ⚠ FALSE ON A FRONT-END REQUEST BY CONSTRUCTION. `get_current_screen()` is not
	 * even defined outside the admin, and returns null before `admin_init` — both of
	 * which this returns `false` for rather than guessing.
	 *
	 * @return bool
	 */
	public static function is_order_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen instanceof \WP_Screen ) {
			return false;
		}

		return OrderPanel::screen_id() === (string) $screen->id;
	}

	/**
	 * Everything the script needs, passed through `wp_localize_script()`.
	 *
	 * ⚠ THE NONCE IS ISSUED HERE AND NOWHERE ELSE, and it is the same action
	 * `Admin\TargetSearch` verifies. A search request without it is refused with a
	 * 403 whatever the user's capability.
	 *
	 * @return array
	 */
	public static function data(): array {
		return array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'action'   => TargetSearch::ACTION,
			'nonce'    => wp_create_nonce( TargetSearch::NONCE_ACTION ),
			'kinds'    => array_values( Targeting::ID_KINDS ),
			'editorId' => RuleEditor::EDITOR_ID,
			'minChars' => 2,
			'i18n'     => array(
				'searching'    => __( 'Searching…', 'extonify-custom-emails-per-product' ),
				'noResults'    => __( 'Nothing found.', 'extonify-custom-emails-per-product' ),
				'failed'       => __( 'The search could not be completed. Try again.', 'extonify-custom-emails-per-product' ),
				'alreadyAdded' => __( 'Already added.', 'extonify-custom-emails-per-product' ),
				'added'        => __( 'Added.', 'extonify-custom-emails-per-product' ),
				'removed'      => __( 'Removed.', 'extonify-custom-emails-per-product' ),
			),
		);
	}
}
