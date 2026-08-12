<?php
/**
 * The confirmation screen every sending action goes through (ADR-0019 §6, gate 36).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

use Extonify\WCEP\Delivery\ManualDelivery;
use Extonify\WCEP\Repository\DeliveryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * What is about to happen, to whom, and what it will not do.
 *
 * ⚠ THIS SCREEN SENDS NOTHING. It is reached by GET, renders a POST form, and issues
 * the single-use token that form carries. Gate 36 asserts that no GET request
 * anywhere in this plugin sends an email, and this class is the reason the sending
 * actions never need one.
 *
 * ⚠ THE RECIPIENTS SHOWN ARE RESOLVED, NOT THE RULE'S DEFINITION. A merchant shown
 * `{customer_email}` has not been told who is about to be emailed, and the whole
 * point of a confirmation is that they can see it before they commit.
 *
 * ⚠ EACH ACTION NAMES ITS OWN CONSEQUENCE, and two of them are irreversible facts a
 * merchant cannot infer: cancelling permanently consumes the delivery identity
 * (ADR-0015 §1a), and a manual send does NOT suppress the automatic one
 * (ADR-0019 §2), so the customer may receive the message twice.
 */
final class DeliveryConfirm {

	/**
	 * Render the confirmation screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		Menu::require_capability();

		$action = self::requested_action();

		if ( ! DeliveryActions::is_write_action( $action ) ) {
			self::render_refusal( ManualDelivery::REFUSED_DELIVERY_MISSING );
			return;
		}

		$context = DeliveryActions::confirmation(
			$action,
			self::requested_int( DeliveryActions::FIELD_DELIVERY ),
			self::requested_int( DeliveryActions::FIELD_ORDER ),
			self::requested_int( DeliveryActions::FIELD_RULE )
		);

		if ( '' !== (string) $context['refusal'] ) {
			self::render_refusal( (string) $context['refusal'] );
			return;
		}

		if ( '' === (string) $context['token'] ) {
			// A confirmation whose token could not be issued must not offer a button:
			// the POST would be refused as a replay, which would read as a bug.
			self::render_refusal( ManualDelivery::REFUSED_WRITE_FAILED );
			return;
		}

		self::render_form( $context );
	}

	/**
	 * The confirmation form.
	 *
	 * @param array $context Result of `DeliveryActions::confirmation()`.
	 * @return void
	 */
	private static function render_form( array $context ): void {
		$action   = (string) $context['action'];
		$rule     = (array) $context['rule_row'];
		$order_id = (int) $context['order'];

		echo '<div class="wrap extonify-wcep extonify-wcep-confirm">';

		echo '<h1>' . esc_html( self::heading( $action ) ) . '</h1>';

		echo '<p>' . esc_html( self::summary( $action ) ) . '</p>';

		echo '<table class="widefat striped extonify-wcep-confirm-facts"><tbody>';

		self::fact( __( 'Rule', 'extonify-custom-emails-per-product' ), (string) ( $rule['name'] ?? '' ) );
		/* translators: %d: WooCommerce order id. */
		self::fact( __( 'Order', 'extonify-custom-emails-per-product' ), sprintf( __( 'Order #%d', 'extonify-custom-emails-per-product' ), $order_id ) );
		self::recipients_row( (array) $context['recipients'] );

		echo '</tbody></table>';

		foreach ( self::warnings( $action, $rule, (array) $context['tombstone'] ) as $warning ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $warning ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( Menu::history_url() ) . '">';

		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( DeliveryActions::FIELD_DELIVERY ) . '" value="' . esc_attr( (string) (int) $context['delivery'] ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( DeliveryActions::FIELD_ORDER ) . '" value="' . esc_attr( (string) $order_id ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( DeliveryActions::FIELD_RULE ) . '" value="' . esc_attr( (string) (int) $context['rule'] ) . '" />';

		// ⚠ THE SINGLE-USE TOKEN (ADR-0019 §5). It is what makes a reload, a
		// back-and-resubmit or a double-click produce exactly one email.
		echo '<input type="hidden" name="' . esc_attr( DeliveryActions::FIELD_TOKEN ) . '" value="' . esc_attr( (string) $context['token'] ) . '" />';

		wp_nonce_field(
			DeliveryActions::nonce_action(
				$action,
				DeliveryActions::ACTION_MANUAL === $action ? $order_id : (int) $context['delivery'],
				(int) $context['rule']
			),
			DeliveryActions::FIELD_NONCE
		);

		echo '<p class="submit">';
		echo '<button type="submit" class="button button-primary">' . esc_html( self::button( $action ) ) . '</button> ';
		echo '<a class="button" href="' . esc_url( Menu::history_url( array( DeliveriesListTable::ARG_ORDER => $order_id ) ) ) . '">'
			. esc_html__( 'Cancel', 'extonify-custom-emails-per-product' ) . '</a>';
		echo '</p>';

		echo '</form>';

		echo '</div>';
	}

	/**
	 * One fact row.
	 *
	 * @param string $label Row label.
	 * @param string $value Row value.
	 * @return void
	 */
	private static function fact( string $label, string $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>'
			. ( '' !== trim( $value )
				? esc_html( $value )
				: '<span class="extonify-wcep-muted">' . esc_html__( 'Not named', 'extonify-custom-emails-per-product' ) . '</span>' )
			. '</td></tr>';
	}

	/**
	 * The resolved recipients row.
	 *
	 * @param array<string,string[]> $recipients Channel => addresses.
	 * @return void
	 */
	private static function recipients_row( array $recipients ): void {
		echo '<tr><th scope="row">' . esc_html__( 'Will be sent to', 'extonify-custom-emails-per-product' ) . '</th><td>';

		if ( array() === $recipients ) {
			// ⚠ SHOWN, NOT HIDDEN. A rule that resolves to nobody sends nothing, and a
			// merchant staring at a Send button deserves to know that before clicking.
			echo '<span class="extonify-wcep-muted">'
				. esc_html__( 'Nobody — this rule resolves to no deliverable address for this order, so nothing would be sent.', 'extonify-custom-emails-per-product' )
				. '</span>';
		} else {
			$labels = FieldOptions::recipient_types();
			$lines  = array();

			foreach ( $recipients as $channel => $addresses ) {
				$lines[] = esc_html(
					sprintf(
						/* translators: 1: recipient kind, e.g. "To", 2: a comma-separated list of email addresses. */
						__( '%1$s: %2$s', 'extonify-custom-emails-per-product' ),
						$labels[ $channel ] ?? $channel,
						implode( ', ', (array) $addresses )
					)
				);
			}

			echo implode( '<br />', $lines ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every line is esc_html()'d as it is built directly above.
		}

		echo '</td></tr>';
	}

	/**
	 * The warnings one action must state before it is confirmed (ADR-0019 §6).
	 *
	 * @param string $action    The action.
	 * @param array  $rule      Rule row.
	 * @param array  $tombstone Tombstone row, if any.
	 * @return string[]
	 */
	private static function warnings( string $action, array $rule, array $tombstone ): array {
		$warnings = array();

		if ( DeliveryActions::ACTION_MANUAL === $action ) {
			// ⚠ THE §2 NOTE. This is the one consequence a merchant cannot infer, and
			// the reason the alternative design was rejected: letting a manual send
			// consume the automatic identity would silently disable the rule for this
			// order, with nothing on any screen to say so.
			$warnings[] = __( 'This is a one-off send. It does not replace or switch off the rule\'s automatic delivery, so if the rule\'s trigger fires later the customer will receive this email again.', 'extonify-custom-emails-per-product' );
		}

		if ( DeliveryActions::ACTION_RESEND === $action ) {
			$warnings[] = __( 'The email will be rendered from this rule as it is now, and from the order as it is now — not as they were when the delivery first ran.', 'extonify-custom-emails-per-product' );
		}

		if ( DeliveryActions::ACTION_CANCEL === $action ) {
			// ⚠ IRREVERSIBLE (ADR-0015 §1a), and said before the click rather than after.
			$warnings[] = __( 'Cancelling is permanent. This rule will never send again for this order and this trigger, even if the same trigger fires a second time.', 'extonify-custom-emails-per-product' );
		}

		if ( DeliveryActions::ACTION_SEND_NOW === $action ) {
			$warnings[] = __( 'The remaining delay is skipped and the queued job is removed. The delivery still runs the checks it would have run later, so if the rule or the order has changed it may be cancelled instead of sent.', 'extonify-custom-emails-per-product' );
		}

		if ( 'active' !== (string) ( $rule['status'] ?? '' ) ) {
			$warnings[] = __( 'This rule is currently disabled. Sending it here does not enable it; it stays disabled for every other order.', 'extonify-custom-emails-per-product' );
		}

		if ( array() !== $tombstone && DeliveryActions::is_write_action( $action ) && DeliveryActions::is_manual( $tombstone ) ) {
			$warnings[] = __( 'This delivery was itself sent by hand rather than by the rule\'s trigger.', 'extonify-custom-emails-per-product' );
		}

		return $warnings;
	}

	/**
	 * The screen heading for one action.
	 *
	 * @param string $action The action.
	 * @return string
	 */
	private static function heading( string $action ): string {
		$headings = array(
			DeliveryActions::ACTION_RESEND   => __( 'Resend this email?', 'extonify-custom-emails-per-product' ),
			DeliveryActions::ACTION_SEND_NOW => __( 'Send this email now?', 'extonify-custom-emails-per-product' ),
			DeliveryActions::ACTION_CANCEL   => __( 'Cancel this scheduled email?', 'extonify-custom-emails-per-product' ),
			DeliveryActions::ACTION_MANUAL   => __( 'Send this email for this order?', 'extonify-custom-emails-per-product' ),
		);

		return $headings[ $action ] ?? __( 'Confirm', 'extonify-custom-emails-per-product' );
	}

	/**
	 * The one-sentence summary for one action.
	 *
	 * @param string $action The action.
	 * @return string
	 */
	private static function summary( string $action ): string {
		$summaries = array(
			DeliveryActions::ACTION_RESEND   => __( 'This sends the rule\'s email to the customer again. Check who it is going to before you confirm.', 'extonify-custom-emails-per-product' ),
			DeliveryActions::ACTION_SEND_NOW => __( 'This sends the scheduled email straight away instead of waiting for its delay.', 'extonify-custom-emails-per-product' ),
			DeliveryActions::ACTION_CANCEL   => __( 'This stops the scheduled email from being sent. Nothing is emailed to the customer.', 'extonify-custom-emails-per-product' ),
			DeliveryActions::ACTION_MANUAL   => __( 'This sends the rule\'s email for this order even though the rule has not fired for it.', 'extonify-custom-emails-per-product' ),
		);

		return $summaries[ $action ] ?? '';
	}

	/**
	 * The confirm button's label.
	 *
	 * @param string $action The action.
	 * @return string
	 */
	private static function button( string $action ): string {
		$labels = array(
			DeliveryActions::ACTION_RESEND   => __( 'Resend the email', 'extonify-custom-emails-per-product' ),
			DeliveryActions::ACTION_SEND_NOW => __( 'Send it now', 'extonify-custom-emails-per-product' ),
			DeliveryActions::ACTION_CANCEL   => __( 'Cancel the delivery', 'extonify-custom-emails-per-product' ),
			DeliveryActions::ACTION_MANUAL   => __( 'Send the email', 'extonify-custom-emails-per-product' ),
		);

		return $labels[ $action ] ?? __( 'Confirm', 'extonify-custom-emails-per-product' );
	}

	/**
	 * Render a refusal instead of a form.
	 *
	 * @param string $code Refusal code.
	 * @return void
	 */
	private static function render_refusal( string $code ): void {
		$messages = Notices::delivery_messages();
		$key      = 'wcep_refused_' . $code;

		echo '<div class="wrap extonify-wcep extonify-wcep-confirm">';
		echo '<h1>' . esc_html__( 'This cannot be done', 'extonify-custom-emails-per-product' ) . '</h1>';

		Notices::render(
			'error',
			isset( $messages[ $key ] )
				? $messages[ $key ][1]
				: __( 'This action is not available for this delivery.', 'extonify-custom-emails-per-product' )
		);

		echo '<p><a class="button" href="' . esc_url( Menu::history_url() ) . '">'
			. esc_html__( 'Back to the delivery history', 'extonify-custom-emails-per-product' ) . '</a></p>';

		echo '</div>';
	}

	/**
	 * The action this request asks to confirm.
	 *
	 * @return string
	 */
	public static function requested_action(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selecting which read-only confirmation screen to draw; this screen sends nothing, and the POST it renders carries its own nonce.
		return isset( $_GET['wcep_action'] ) ? sanitize_key( wp_unslash( $_GET['wcep_action'] ) ) : '';
	}

	/**
	 * One integer request argument.
	 *
	 * @param string $key Query argument.
	 * @return int
	 */
	private static function requested_int( string $key ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reading which delivery to DESCRIBE; the cast to int IS the sanitisation for an id, and the state-changing POST this screen renders verifies its own nonce.
		return isset( $_GET[ $key ] ) ? max( 0, (int) wp_unslash( $_GET[ $key ] ) ) : 0;
	}

	/**
	 * A link to this screen.
	 *
	 * ⚠ A GET LINK TO A CONFIRMATION, NEVER TO A SEND (gate 36). This is the only URL
	 * shape the history screen and the order panel ever produce for these actions.
	 *
	 * @param string $action      The action.
	 * @param int    $delivery_id Delivery id.
	 * @param int    $order_id    Order id.
	 * @param int    $rule_id     Rule id.
	 * @return string
	 */
	public static function url( string $action, int $delivery_id, int $order_id = 0, int $rule_id = 0 ): string {
		return Menu::history_url(
			array(
				'wcep_action'                   => $action,
				DeliveryActions::FIELD_DELIVERY => $delivery_id,
				DeliveryActions::FIELD_ORDER    => $order_id,
				DeliveryActions::FIELD_RULE     => $rule_id,
			)
		);
	}

	/**
	 * Whether a delivery may be resent, from its recorded status alone.
	 *
	 * @param array $tombstone Tombstone row.
	 * @return bool
	 */
	public static function can_resend( array $tombstone ): bool {
		return ! in_array( (string) ( $tombstone['final_status'] ?? '' ), DeliveryRepository::IN_FLIGHT_STATUSES, true );
	}

	/**
	 * Whether a delivery is scheduled, and so may be sent now or cancelled.
	 *
	 * @param array $tombstone Tombstone row.
	 * @return bool
	 */
	public static function is_scheduled( array $tombstone ): bool {
		return DeliveryRepository::SCHEDULED === (string) ( $tombstone['final_status'] ?? '' );
	}
}
