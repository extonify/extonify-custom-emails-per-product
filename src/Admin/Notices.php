<?php
/**
 * Admin notices and refusal messages (ADR-0017 §3).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the screens say to a merchant, in one place.
 *
 * ⚠ EVERY MESSAGE IS ONE COMPLETE TRANSLATABLE SENTENCE (gate 33). None is built by
 * concatenating a field name onto a fragment: a translator handed
 * `"Delay" . " is invalid."` cannot reorder it, cannot decline the noun and cannot
 * see which of the two halves they are translating. Where a field name appears it is
 * a `%s` inside a whole sentence, with a translator comment saying so.
 *
 * ⚠ AND A REFUSAL SAYS WHICH FIELD AND WHY (ADR-0017 §3.3). "Could not save" throws
 * away the specific meaning the storage layer had — the Prompt 7A backlog records
 * exactly that — so every `RuleRepository::REFUSED_*` code has its own sentence.
 */
final class Notices {

	/**
	 * The query argument a post/redirect/get outcome travels in.
	 */
	const ARG = 'message';

	/**
	 * Render the notice this request's `message` argument asks for.
	 *
	 * @return void
	 */
	public static function render_request_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects which fixed, plugin-authored sentence to display; it is matched against a closed map and never echoed as itself.
		$code = isset( $_GET[ self::ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::ARG ] ) ) : '';

		if ( '' === $code ) {
			return;
		}

		$messages = self::request_messages();

		if ( ! isset( $messages[ $code ] ) ) {
			// An unrecognised code is shown as nothing at all. Echoing it back would
			// reflect a request value into the page for no benefit whatsoever.
			return;
		}

		self::render( $messages[ $code ][0], $messages[ $code ][1] );
	}

	/**
	 * One notice.
	 *
	 * @param string $type    `success`, `warning` or `error`.
	 * @param string $message Translated, unescaped message.
	 * @return void
	 */
	public static function render( string $type, string $message ): void {
		$class = 'error' === $type ? 'notice-error' : ( 'warning' === $type ? 'notice-warning' : 'notice-success' );

		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * The outcomes a redirect can report, code => `[type, message]`.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function request_messages(): array {
		return array(
			'created'          => array( 'success', __( 'Rule created.', 'extonify-custom-emails-per-product' ) ),
			'saved'            => array( 'success', __( 'Rule saved.', 'extonify-custom-emails-per-product' ) ),
			'duplicated'       => array( 'success', __( 'Rule duplicated. The copy is inactive until you enable it.', 'extonify-custom-emails-per-product' ) ),
			'deleted'          => array( 'success', __( 'Rule deleted.', 'extonify-custom-emails-per-product' ) ),
			'activated'        => array( 'success', __( 'Rule enabled.', 'extonify-custom-emails-per-product' ) ),
			'deactivated'      => array( 'success', __( 'Rule disabled.', 'extonify-custom-emails-per-product' ) ),
			'missing'          => array( 'error', __( 'That rule no longer exists.', 'extonify-custom-emails-per-product' ) ),
			'delete_failed'    => array( 'error', __( 'The rule could not be deleted, so nothing was changed.', 'extonify-custom-emails-per-product' ) ),
			'duplicate_failed' => array( 'error', __( 'The rule could not be duplicated. Open it and check its settings — a setting this plugin no longer accepts stops the copy being created.', 'extonify-custom-emails-per-product' ) ),
			'toggle_failed'    => array( 'error', __( 'This rule could not be enabled or disabled because one of its settings is no longer accepted. Correct the setting below and save.', 'extonify-custom-emails-per-product' ) ),
		);
	}

	/**
	 * The sentence one refusal deserves.
	 *
	 * @param string $field Column or form field the refusal names; '' for a storage failure.
	 * @param string $code  A `RuleRepository::REFUSED_*` code, or an editor-level code.
	 * @return string Translated, unescaped.
	 */
	public static function refusal_message( string $field, string $code ): string {
		$label = self::field_label( $field );

		switch ( $code ) {
			case 'not_in_vocabulary':
				return sprintf(
					/* translators: %s: the name of the field that was refused, e.g. "Status". */
					__( '%s was sent with a value this plugin does not accept, so nothing was saved. Choose one of the options offered.', 'extonify-custom-emails-per-product' ),
					$label
				);

			case 'malformed':
				return sprintf(
					/* translators: %s: the name of the field that was refused. */
					__( '%s is not in a form this plugin can store, so nothing was saved. Correct it and save again.', 'extonify-custom-emails-per-product' ),
					$label
				);

			case 'insert_forbids':
				return sprintf(
					/* translators: %s: the name of the field that was refused, e.g. "Delay". */
					__( '%s cannot be used by a rule that adds its content to a WooCommerce email, so nothing was saved. Clear it, or send this rule as a separate email instead.', 'extonify-custom-emails-per-product' ),
					$label
				);

			case 'required_for_insert':
				return __( 'Choose which WooCommerce email this rule adds its content to. Nothing was saved.', 'extonify-custom-emails-per-product' );

			case 'unregistered':
				return __( 'The WooCommerce email this rule names is not one this store sends, so nothing was saved. Choose one from the list.', 'extonify-custom-emails-per-product' );

			case 'required':
				return __( 'Give this rule a name, so you can find it again in the list.', 'extonify-custom-emails-per-product' );

			case 'negative':
				return __( 'A delay cannot be negative. Use zero to send straight away.', 'extonify-custom-emails-per-product' );

			case 'unit':
				return __( 'Choose a unit for the delay.', 'extonify-custom-emails-per-product' );
		}

		return __( 'The rule could not be saved, and nothing was changed. Check the WooCommerce logs (source: extonify-wcep) for details.', 'extonify-custom-emails-per-product' );
	}

	/**
	 * A field's name, as the editor labels it.
	 *
	 * ⚠ THE SAME STRING THE FORM USES, from one map, so the message and the label a
	 * merchant is being sent to cannot call the same field two different things.
	 *
	 * @param string $field Column or form field name.
	 * @return string
	 */
	public static function field_label( string $field ): string {
		$labels = self::field_labels();

		return $labels[ $field ] ?? __( 'A setting on this rule', 'extonify-custom-emails-per-product' );
	}

	/**
	 * Field => label.
	 *
	 * @return array<string,string>
	 */
	public static function field_labels(): array {
		return array(
			'name'            => __( 'Name', 'extonify-custom-emails-per-product' ),
			'status'          => __( 'Status', 'extonify-custom-emails-per-product' ),
			'trigger_type'    => __( 'Trigger', 'extonify-custom-emails-per-product' ),
			'trigger_value'   => __( 'Trigger details', 'extonify-custom-emails-per-product' ),
			'delivery_mode'   => __( 'How this rule is delivered', 'extonify-custom-emails-per-product' ),
			'native_email_id' => __( 'WooCommerce email', 'extonify-custom-emails-per-product' ),
			'insert_position' => __( 'Position in the email', 'extonify-custom-emails-per-product' ),
			'delay_seconds'   => __( 'Delay', 'extonify-custom-emails-per-product' ),
			'consolidation'   => __( 'How many emails', 'extonify-custom-emails-per-product' ),
			'subject'         => __( 'Subject', 'extonify-custom-emails-per-product' ),
			'heading'         => __( 'Heading', 'extonify-custom-emails-per-product' ),
			'content'         => __( 'Body', 'extonify-custom-emails-per-product' ),
			'priority'        => __( 'Priority', 'extonify-custom-emails-per-product' ),
		);
	}
}
