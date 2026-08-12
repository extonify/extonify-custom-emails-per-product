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
		) + self::delivery_messages();
	}

	/**
	 * What each manual delivery action reports (ADR-0019 §4, gate 38).
	 *
	 * ⚠ EVERY REFUSAL HAS ITS OWN SENTENCE, AND THAT IS THE GATE. "The email could not
	 * be sent" tells a merchant nothing they can act on — they cannot tell a deleted
	 * rule from an insert-mode one from a delivery somebody else is already sending,
	 * and those need three different responses. Gate 38 fails on a refusal code that
	 * reaches this map without one.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function delivery_messages(): array {
		return array(
			// --- successes ---------------------------------------------------
			'wcep_resent'                     => array( 'success', __( 'The email was resent. It was rendered from this rule as it is now, not as it was when the delivery first ran.', 'extonify-custom-emails-per-product' ) ),
			'wcep_sent_manual'                => array( 'success', __( 'The email was sent. This was a one-off send and it does not replace the rule\'s automatic delivery, so the customer may receive it again if the rule\'s trigger fires later.', 'extonify-custom-emails-per-product' ) ),
			'wcep_sent_now'                   => array( 'success', __( 'The email was sent immediately and the scheduled job was removed.', 'extonify-custom-emails-per-product' ) ),
			'wcep_cancelled'                  => array( 'success', __( 'The scheduled delivery was cancelled and its job removed. This rule will not send again for this order and trigger.', 'extonify-custom-emails-per-product' ) ),

			// ⚠ SEND NOW RE-VALIDATES, SO "SENT" IS NOT THE ONLY SUCCESSFUL OUTCOME
			// (ADR-0019 §7). Reporting "sent" for a delivery that was cancelled during
			// re-validation would be untrue about an email that never went out.
			'wcep_send_now_cancelled'         => array( 'warning', __( 'Nothing was sent. When the delivery ran it no longer passed the checks it makes before sending — most often because the rule or the order changed after it was scheduled. Open the delivery to see the recorded reason.', 'extonify-custom-emails-per-product' ) ),
			'wcep_send_now_other'             => array( 'warning', __( 'The delivery ran but did not send. Open it to see the recorded outcome.', 'extonify-custom-emails-per-product' ) ),

			// --- refusals, one sentence each ----------------------------------
			'wcep_refused_replayed'           => array( 'warning', __( 'Nothing was sent, because this confirmation had already been used. Each confirmation runs once; open the delivery and confirm again if you meant to send a second email.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_rule_deleted'       => array( 'error', __( 'This rule has been deleted, so there is no message left to send. A completed delivery does not keep a copy of what it sent.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_rule_insert_mode'   => array( 'error', __( 'This rule adds its content to a WooCommerce email rather than sending one of its own, so there is no message to send on its own. Resend the WooCommerce email instead.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_rule_vocabulary'    => array( 'error', __( 'This rule has a setting this plugin no longer accepts, so it cannot be delivered. Open the rule, correct the setting and save it first.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_not_terminal'       => array( 'error', __( 'This delivery has not finished yet, so it cannot be resent. Wait for it to finish, or cancel it.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_not_scheduled'      => array( 'error', __( 'This delivery is no longer scheduled, so it cannot be sent early or cancelled. Something else has already run or cancelled it.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_already_delivered'  => array( 'warning', __( 'Nothing was sent, because this exact send had already been recorded. Reload the delivery history to see it.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_delivery_missing'   => array( 'error', __( 'That delivery no longer exists.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_order_missing'      => array( 'error', __( 'That order no longer exists, so there is nothing to send an email about.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_no_matching_items'  => array( 'error', __( 'Nothing on this order matches what this rule targets, so there is nothing to send about. Check the rule\'s products, categories and tags.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_lost_race'          => array( 'warning', __( 'Nothing was changed, because something else was already running this delivery. Reload the delivery history to see what it did.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_write_failed'       => array( 'error', __( 'Nothing was sent and nothing was changed, because the delivery record could not be written. Check the WooCommerce logs (source: extonify-wcep) and try again.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_email_unavailable'  => array( 'error', __( 'Nothing was sent, because custom product emails are switched off in WooCommerce settings, or WooCommerce has not registered this plugin\'s email. Nothing was recorded against this order.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_schema_unavailable' => array( 'error', __( 'Nothing was sent, because this plugin\'s database tables are unavailable. Deactivate and reactivate the plugin, then try again.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_denied'             => array( 'error', __( 'You are not allowed to send custom product emails.', 'extonify-custom-emails-per-product' ) ),
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
