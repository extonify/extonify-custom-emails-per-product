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
	 * How many messages a partly-successful delivery sent, and how many it planned.
	 *
	 * ⚠ TWO INTEGERS AND NOTHING ELSE. A merchant told "some of these were sent" needs
	 * to know how many, and the recorded REASON is free text this plugin does not own —
	 * it can hold a mailer's error string. Carrying that through a query argument would
	 * reflect arbitrary content into the page; carrying two counts cannot, because
	 * `absint()` is total. The reason itself lives on the delivery record, escaped, on
	 * the screen the sentence sends the merchant to.
	 */
	const ARG_SENT  = 'wcep_sent';
	const ARG_TOTAL = 'wcep_total';

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

		$counted = self::counted_message( $code );

		self::render( $messages[ $code ][0], null !== $counted ? $counted : $messages[ $code ][1] );
	}

	/**
	 * The counted variant of one message, when this request carries usable counts.
	 *
	 * ⚠ AN ENRICHMENT, NEVER THE ONLY SENTENCE. `self::request_messages()` stays a
	 * COMPLETE closed map — gate 38 walks it, `DeliveryConfirm` reads it, and a code
	 * whose only sentence lived here would be a code with no sentence for either. So
	 * every code answered here also has a count-free sentence in the map, and this
	 * returns null the moment the counts are missing or do not describe a partial
	 * outcome.
	 *
	 * @param string $code Notice code.
	 * @return string|null Translated, unescaped; null to use the map's sentence.
	 */
	private static function counted_message( string $code ): ?string {
		if ( 'wcep_partly_sent' !== $code ) {
			return null;
		}

		$sent  = self::requested_count( self::ARG_SENT );
		$total = self::requested_count( self::ARG_TOTAL );

		// "3 of 2 sent" and "0 of 5 sent" are not partial outcomes; a request that
		// claims one is not describing anything this plugin produced.
		if ( $sent <= 0 || $total <= $sent ) {
			return null;
		}

		return sprintf(
			/* translators: 1: how many messages were sent, 2: how many were attempted. */
			__(
				'Only some of this rule\'s emails were sent: %1$d of %2$d went out. This rule sends one email per matched product, and the rest failed or were skipped. Open the delivery in the history to see what happened to each one.',
				'extonify-custom-emails-per-product'
			),
			$sent,
			$total
		);
	}

	/**
	 * One non-negative integer request argument.
	 *
	 * @param string $key Query argument.
	 * @return int
	 */
	private static function requested_count( string $key ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading two counts for a notice sentence on a read-only screen; absint() IS the sanitisation, and the state-changing POST that produced this redirect verified its own nonce and token.
		return isset( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : 0;
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
		) + self::delivery_messages() + self::preview_messages();
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
			'wcep_resent'                       => array( 'success', __( 'The email was resent. It was rendered from this rule as it is now, not as it was when the delivery first ran.', 'extonify-custom-emails-per-product' ) ),
			'wcep_sent_manual'                  => array( 'success', __( 'The email was sent. This was a one-off send and it does not replace the rule\'s automatic delivery, so it may be sent again, to the rule\'s own recipients, if the rule\'s trigger fires later.', 'extonify-custom-emails-per-product' ) ),
			'wcep_sent_now'                     => array( 'success', __( 'The email was sent immediately and the scheduled job was removed.', 'extonify-custom-emails-per-product' ) ),
			'wcep_cancelled'                    => array( 'success', __( 'The scheduled delivery was cancelled and its job removed. This rule will not send again for this order and trigger.', 'extonify-custom-emails-per-product' ) ),

			// ⚠ SEND NOW RE-VALIDATES, SO "SENT" IS NOT THE ONLY SUCCESSFUL OUTCOME
			// (ADR-0019 §7). Reporting "sent" for a delivery that was cancelled during
			// re-validation would be untrue about an email that never went out.
			'wcep_send_now_cancelled'           => array( 'warning', __( 'Nothing was sent. When the delivery ran it no longer passed the checks it makes before sending — most often because the rule or the order changed after it was scheduled. Open the delivery to see the recorded reason.', 'extonify-custom-emails-per-product' ) ),
			'wcep_send_now_other'               => array( 'warning', __( 'The delivery ran but did not send. Open it to see the recorded outcome.', 'extonify-custom-emails-per-product' ) ),

			/*
			 * --- ⚠ WHAT A SEND ACTUALLY DID (Prompt 13A item 3, gate 18) ------------
			 *
			 * A sending action reports the outcome its `RunOutcome` recorded, and only
			 * `sent` may render as a success. These four cover every other answer, and
			 * every one of them is reachable: the mailer failing, another plugin's
			 * `woocommerce_email_enabled_{id}` declining THIS delivery, a per-product
			 * fan-out where some messages went out and some did not, and a run that
			 * recorded nothing at all.
			 *
			 * ⚠ EACH SENDS THE MERCHANT TO THE DELIVERY RECORD, deliberately. That is
			 * where the recorded reason lives — a mailer's own error string, which is
			 * free text this plugin does not own and therefore does not put in a URL.
			 */
			'wcep_partly_sent'                  => array( 'warning', __( 'Only some of this rule\'s emails were sent. This rule sends one email per matched product, and the rest failed or were skipped. Open the delivery in the history to see what happened to each one.', 'extonify-custom-emails-per-product' ) ),
			'wcep_not_sent_failed'              => array( 'error', __( 'Nothing was sent. The delivery ran and the message did not go out — the mailer reported a failure, or the send raised an error. Open the delivery in the history for the recorded reason, and check the WooCommerce logs (source: extonify-wcep).', 'extonify-custom-emails-per-product' ) ),
			'wcep_not_sent_skipped'             => array( 'warning', __( 'Nothing was sent, because this delivery was deliberately skipped rather than attempted. Either another plugin declined it, or the rule resolved to no address this store can send to. Open the delivery in the history for the recorded reason.', 'extonify-custom-emails-per-product' ) ),
			'wcep_not_sent_unknown'             => array( 'error', __( 'Nothing was sent, and this delivery recorded no outcome at all. Open the delivery in the history, and check the WooCommerce logs (source: extonify-wcep).', 'extonify-custom-emails-per-product' ) ),

			// --- refusals, one sentence each ----------------------------------
			'wcep_refused_replayed'             => array( 'warning', __( 'Nothing was sent, because this confirmation had already been used. Each confirmation runs once; open the delivery and confirm again if you meant to send a second email.', 'extonify-custom-emails-per-product' ) ),

			/*
			 * ⚠ TOLD APART FROM `replayed` DELIBERATELY (Prompt 13A item 4, gate 38).
			 * "You already used this" and "what you approved is no longer what would
			 * happen" send a merchant to two different places, and the second is the
			 * one that matters: it means the recipients, the rule or the order moved
			 * between the confirmation screen being drawn and the button being pressed.
			 */
			'wcep_refused_confirmation_changed' => array( 'warning', __( 'Nothing was sent. What this confirmation was for is no longer what would happen — the rule, the order or the addresses it would reach changed after the confirmation was opened. Open it again and check who it goes to before confirming.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_snapshot_unreadable'  => array( 'error', __( 'Nothing was sent. The stored copy of what this scheduled delivery would send cannot be read, so there is no way to show you who it would reach. Cancel it and send the rule by hand instead.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_rule_deleted'         => array( 'error', __( 'This rule has been deleted, so there is no message left to send. A completed delivery does not keep a copy of what it sent.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_rule_insert_mode'     => array( 'error', __( 'This rule adds its content to a WooCommerce email rather than sending one of its own, so there is no message to send on its own. Resend the WooCommerce email instead.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_rule_vocabulary'      => array( 'error', __( 'This rule has a setting this plugin no longer accepts, so it cannot be delivered. Open the rule, correct the setting and save it first.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_not_terminal'         => array( 'error', __( 'This delivery has not finished yet, so it cannot be resent. Wait for it to finish, or cancel it.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_not_scheduled'        => array( 'error', __( 'This delivery is no longer scheduled, so it cannot be sent early or cancelled. Something else has already run or cancelled it.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_already_delivered'    => array( 'warning', __( 'Nothing was sent, because this exact send had already been recorded. Reload the delivery history to see it.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_delivery_missing'     => array( 'error', __( 'That delivery no longer exists.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_order_missing'        => array( 'error', __( 'That order no longer exists, so there is nothing to send an email about.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_no_matching_items'    => array( 'error', __( 'Nothing on this order matches what this rule targets, so there is nothing to send about. Check the rule\'s products, categories and tags.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_lost_race'            => array( 'warning', __( 'Nothing was changed, because something else was already running this delivery. Reload the delivery history to see what it did.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_write_failed'         => array( 'error', __( 'Nothing was sent and nothing was changed, because the delivery record could not be written. Check the WooCommerce logs (source: extonify-wcep) and try again.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_email_unavailable'    => array( 'error', __( 'Nothing was sent, because custom product emails are switched off in WooCommerce settings, or WooCommerce has not registered this plugin\'s email. Nothing was recorded against this order.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_schema_unavailable'   => array( 'error', __( 'Nothing was sent, because this plugin\'s database tables are unavailable. Deactivate and reactivate the plugin, then try again.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_denied'               => array( 'error', __( 'You are not allowed to send custom product emails.', 'extonify-custom-emails-per-product' ) ),

			// --- ADR-0020: the test send ---------------------------------------
			'wcep_sent_test'                    => array( 'success', __( 'The test email was sent to the address you chose. Its subject is marked as a test, and the rule\'s automatic delivery is untouched — it still sends normally when its trigger fires.', 'extonify-custom-emails-per-product' ) ),
			'wcep_refused_test_address_invalid' => array( 'error', __( 'Nothing was sent, because that is not an email address this store can send to. Type a valid address, or leave the field empty to send the test to yourself.', 'extonify-custom-emails-per-product' ) ),
		);
	}

	/**
	 * What the preview screen reports when it cannot render (ADR-0020).
	 *
	 * ⚠ ONE SENTENCE PER REFUSAL, exactly as gate 38 requires of the sending actions,
	 * and for the same reason: "this rule could not be previewed" leaves a merchant with
	 * nothing to do next. A preview refusing because the store has no orders and one
	 * refusing because the rule has an unusable setting need two different responses.
	 *
	 * ⚠ KEPT SEPARATE FROM self::delivery_messages() BECAUSE THE SUBJECTS DIFFER. Those
	 * are outcomes of an action that may have mailed a customer; these are outcomes of a
	 * render that mailed nobody, and merging them would put "nothing was sent" sentences
	 * in front of something that never intended to send.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function preview_messages(): array {
		return array(
			'wcep_preview_refused_schema_unavailable'   => array( 'error', __( 'This rule cannot be previewed, because this plugin\'s database tables are unavailable. Deactivate and reactivate the plugin, then try again.', 'extonify-custom-emails-per-product' ) ),
			'wcep_preview_refused_rule_deleted'         => array( 'error', __( 'That rule no longer exists, so there is nothing to preview.', 'extonify-custom-emails-per-product' ) ),
			'wcep_preview_refused_rule_vocabulary'      => array( 'error', __( 'This rule has a setting this plugin no longer accepts, so it could not be delivered and cannot be previewed. Open the rule, correct the setting and save it first.', 'extonify-custom-emails-per-product' ) ),
			'wcep_preview_refused_order_missing'        => array( 'error', __( 'That order does not exist. Type an order number from this store, or clear the field to use the most recent matching order.', 'extonify-custom-emails-per-product' ) ),
			'wcep_preview_refused_no_orders'            => array( 'warning', __( 'This store has no orders yet, and a preview is rendered against a real one so that you see real customer and product values. Place a test order, then preview this rule against it.', 'extonify-custom-emails-per-product' ) ),
			'wcep_preview_refused_native_email_missing' => array( 'error', __( 'The WooCommerce email this rule adds its content to is not one this store sends, so there is nothing to render it inside. Open the rule and choose an email from the list.', 'extonify-custom-emails-per-product' ) ),
			'wcep_preview_refused_email_unavailable'    => array( 'error', __( 'WooCommerce has not registered this plugin\'s email, so the store\'s email wrapper cannot be rendered around the preview. Check that WooCommerce is active and try again.', 'extonify-custom-emails-per-product' ) ),
			'wcep_preview_refused_render_failed'        => array( 'error', __( 'This rule could not be rendered. Something in the email templates or in another plugin raised an error part way through. Check the WooCommerce logs (source: extonify-wcep) for the details.', 'extonify-custom-emails-per-product' ) ),
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

			case 'no_order_details':
				return __( 'That WooCommerce email has no order details section, so there is nowhere in it for this rule\'s content to go and it would never appear. Nothing was saved. Choose an email that shows the order, or send this rule as a separate email instead.', 'extonify-custom-emails-per-product' );

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
