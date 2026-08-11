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
		if ( ! self::is_our_screen( (string) $hook_suffix ) ) {
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
	 * Whether this is the screen this plugin registered.
	 *
	 * Strict equality against the hook suffix `add_submenu_page()` returned, and
	 * `false` whenever this plugin has registered no menu at all.
	 *
	 * @param string $hook_suffix The screen WordPress is rendering.
	 * @return bool
	 */
	public static function is_our_screen( string $hook_suffix ): bool {
		$ours = Menu::hook();

		return '' !== $ours && $hook_suffix === $ours;
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
