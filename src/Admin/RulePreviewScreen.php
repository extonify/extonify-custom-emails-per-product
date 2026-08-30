<?php
/**
 * The preview screen, and the test send beside it (ADR-0020 §2, §3, §5).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

use Extonify\WCEP\Delivery\RulePreview;
use Extonify\WCEP\Delivery\TestDelivery;
use Extonify\WCEP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * What this rule would produce for a real order, and a way to mail it to yourself.
 *
 * ⚠ THIS SCREEN WRITES NOTHING AND SENDS NOTHING. It is a GET, and the only thing on it
 * that can send is a form that POSTs to `Admin\DeliveryActions` carrying an
 * action-specific nonce and a single-use token — the ADR-0019 §5 gate, unchanged.
 *
 * ⚠ IT RENDERS MERCHANT-AUTHORED HTML ON PURPOSE, WHICH NO OTHER SCREEN HERE DOES. The
 * rendered message is a COMPLETE HTML DOCUMENT — WooCommerce's own wrapper — so it
 * cannot be injected into this page's DOM at all. It goes into a `<iframe srcdoc>`,
 * `esc_attr()`-escaped into the attribute, with an EMPTY `sandbox`, which means the
 * browser reconstructs it with no scripts, no forms, no same-origin access and no
 * navigation. The body reaching that point has already been through `wp_kses_post()`
 * twice — once at the storage boundary (ADR-0012 §6, the same filter the editor
 * applies) and once in `Custom_Email::get_content_html()` — and every placeholder VALUE
 * was escaped for its own output context at substitution time by ADR-0014 §3. The
 * sandbox is the third of those three, not the only one.
 *
 * ⚠ EVERYTHING ELSE ON THE PAGE IS ESCAPED NORMALLY: the subject and the plain body with
 * `esc_html()`, the ids as integers, the address with `esc_attr()`.
 */
final class RulePreviewScreen {

	/**
	 * The `order` request argument this screen reads.
	 */
	const ARG_ORDER = 'order';

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		Menu::require_capability();

		$rule_id  = Menu::requested_rule_id();
		$rule     = $rule_id > 0 ? Plugin::instance()->rules()->find( $rule_id ) : null;
		$order_id = self::requested_order_id();

		echo '<div class="wrap extonify-wcep extonify-wcep-preview">';

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Preview', 'extonify-custom-emails-per-product' ) . '</h1>';

		if ( null !== $rule ) {
			$edit = Menu::url(
				array(
					'action' => Menu::ACTION_EDIT,
					'rule'   => $rule_id,
				)
			);

			echo '<a href="' . esc_url( $edit ) . '" class="page-title-action">'
				. esc_html__( 'Edit this rule', 'extonify-custom-emails-per-product' ) . '</a>';
		}

		echo '<a href="' . esc_url( Menu::url() ) . '" class="page-title-action">'
			. esc_html__( 'Back to all rules', 'extonify-custom-emails-per-product' ) . '</a>';

		echo '<hr class="wp-header-end" />';

		Notices::render_request_notice();

		if ( null === $rule ) {
			Notices::render( 'error', __( 'That rule no longer exists.', 'extonify-custom-emails-per-product' ) );
			echo '</div>';
			return;
		}

		echo '<h2 class="extonify-wcep-preview-rule">' . esc_html( (string) ( $rule['name'] ?? '' ) ) . '</h2>';

		$preview = RulePreview::render( $rule_id, $order_id );

		self::render_order_form( $rule_id, (int) $preview['order_id'], $order_id );

		if ( RulePreview::OK !== $preview['outcome'] ) {
			self::render_refusal( (string) $preview['code'] );
			echo '</div>';
			return;
		}

		self::render_context_notes( $rule, $preview );
		self::render_preview( $preview );
		self::render_test_form( $rule_id, (int) $preview['order_id'] );

		echo '</div>';
	}

	/**
	 * The order picker.
	 *
	 * ⚠ A GET FORM, BECAUSE CHOOSING AN ORDER TO PREVIEW CHANGES NOTHING. It carries no
	 * nonce for the same reason the history screen's filters carry none: there is no
	 * state to protect, and a nonce on a read would only expire and refuse a merchant
	 * who left the tab open.
	 *
	 * @param int $rule_id  Rule being previewed.
	 * @param int $used     The order the preview actually used.
	 * @param int $asked_for The order the merchant asked for, or 0.
	 * @return void
	 */
	private static function render_order_form( int $rule_id, int $used, int $asked_for ): void {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="extonify-wcep-preview-order">';

		echo '<input type="hidden" name="page" value="' . esc_attr( Menu::PAGE ) . '" />';
		echo '<input type="hidden" name="action" value="' . esc_attr( Menu::ACTION_PREVIEW ) . '" />';
		echo '<input type="hidden" name="rule" value="' . esc_attr( (string) $rule_id ) . '" />';

		echo '<label for="extonify-wcep-preview-order">'
			. esc_html__( 'Preview against order', 'extonify-custom-emails-per-product' ) . '</label> ';

		// ⚠ EMPTY RATHER THAN "0" WHEN THERE IS NO ORDER TO SHOW. `min="1"` would make a
		// literal zero an invalid value the browser refuses to submit, so a merchant on
		// the "this store has no orders yet" refusal could not type one in.
		$chosen = $asked_for > 0 ? $asked_for : $used;

		echo '<input type="number" min="1" step="1" id="extonify-wcep-preview-order" name="' . esc_attr( self::ARG_ORDER ) . '"'
			. ' value="' . esc_attr( $chosen > 0 ? (string) $chosen : '' ) . '"'
			. ' aria-describedby="extonify-wcep-preview-order-help" /> ';

		submit_button( __( 'Preview', 'extonify-custom-emails-per-product' ), 'secondary', '', false );

		echo '<p class="description" id="extonify-wcep-preview-order-help">'
			. esc_html(
				sprintf(
					/* translators: %d: how many recent orders are searched for a match. */
					__( 'Leave this alone to use the most recent of the last %d orders that this rule matches. Type any order number to preview against that order instead.', 'extonify-custom-emails-per-product' ),
					RulePreview::MATCH_SCAN_LIMIT
				)
			)
			. '</p>';

		echo '</form>';
	}

	/**
	 * Everything the merchant needs to read the preview correctly.
	 *
	 * @param array $rule    Rule row.
	 * @param array $preview Result of `RulePreview::render()`.
	 * @return void
	 */
	private static function render_context_notes( array $rule, array $preview ): void {
		echo '<table class="widefat striped extonify-wcep-preview-facts"><tbody>';

		self::fact(
			__( 'Order', 'extonify-custom-emails-per-product' ),
			/* translators: %d: WooCommerce order id. */
			sprintf( __( 'Order #%d', 'extonify-custom-emails-per-product' ), (int) $preview['order_id'] )
		);

		self::fact(
			__( 'Delivered as', 'extonify-custom-emails-per-product' ),
			'insert' === (string) $preview['mode']
				? sprintf(
					/* translators: %s: the WooCommerce email this rule adds its content to. */
					__( 'Content added to the WooCommerce email "%s"', 'extonify-custom-emails-per-product' ),
					self::native_title( (string) $preview['native'] )
				)
				: __( 'A separate email of its own', 'extonify-custom-emails-per-product' )
		);

		if ( (int) $preview['messages'] > 1 ) {
			/*
			 * ⚠ WORDED TO AGREE WITH `readme.txt` (Prompt 13B, item 4), which says a
			 * per-product rule "shows one representative message and states how many
			 * would be sent in total". Both halves have to be on the screen: the count
			 * alone would let a merchant read the single document below as THE email,
			 * and "the first one" alone says which message without saying that the
			 * others exist. `Orchestrator::compose_preview()` renders `messages[0]`, so
			 * naming it as the first is accurate as well as representative.
			 */
			self::fact(
				__( 'Messages', 'extonify-custom-emails-per-product' ),
				sprintf(
					/* translators: %d: how many messages this delivery would send. */
					_n(
						'This rule would send %d separate email for this order. One representative message — the first — is shown below.',
						'This rule would send %d separate emails for this order. One representative message — the first — is shown below.',
						(int) $preview['messages'],
						'extonify-custom-emails-per-product'
					),
					(int) $preview['messages']
				)
			);
		}

		echo '</tbody></table>';

		if ( ! $preview['matched'] ) {
			/*
			 * ⚠ SAID OUT LOUD, NOT INFERRED FROM BLANKS (ADR-0020 §2a). A preview whose
			 * `{product_name}` silently rendered empty would look like a broken rule
			 * rather than an order the rule does not target — and the merchant would go
			 * and "fix" a template that was never wrong.
			 */
			Notices::render(
				'warning',
				__( 'This rule\'s targeting does not match anything on this order, so every placeholder about matched products is empty below. The rule would not send for this order at all. Choose an order that contains a targeted product to see the real content.', 'extonify-custom-emails-per-product' )
			);
		}

		if ( 'active' !== (string) ( $rule['status'] ?? '' ) ) {
			Notices::render(
				'warning',
				__( 'This rule is currently disabled, so it does not send automatically. The preview below shows what it would produce if it were enabled.', 'extonify-custom-emails-per-product' )
			);
		}

		if ( ! self::globally_enabled() ) {
			// ADR-0020 §3b: a preview claims nothing, so it is available while the
			// feature is off — which is exactly when a merchant wants to check a rule.
			Notices::render(
				'warning',
				__( 'Custom product emails are currently switched off in WooCommerce settings. Nothing would be sent, and a test send from this screen will be refused. The preview is still accurate.', 'extonify-custom-emails-per-product' )
			);
		}

		if ( '' !== (string) $preview['notes'] ) {
			Notices::render(
				'warning',
				sprintf(
					/* translators: %s: one or more notes about placeholders that could not be resolved. */
					__( 'While rendering this preview: %s', 'extonify-custom-emails-per-product' ),
					(string) $preview['notes']
				)
			);
		}
	}

	/**
	 * The rendered message, in both formats (ADR-0020 §3).
	 *
	 * @param array $preview Result of `RulePreview::render()`.
	 * @return void
	 */
	private static function render_preview( array $preview ): void {
		if ( 'insert' !== (string) $preview['mode'] ) {
			echo '<h3>' . esc_html__( 'Subject', 'extonify-custom-emails-per-product' ) . '</h3>';
			echo '<p class="extonify-wcep-preview-subject"><code>' . esc_html( (string) $preview['subject'] ) . '</code></p>';
		}

		echo '<h3>' . esc_html__( 'HTML', 'extonify-custom-emails-per-product' ) . '</h3>';

		/*
		 * ⚠ A SANDBOXED `srcdoc` IFRAME, AND EVERY WORD OF THAT IS LOAD-BEARING. The
		 * value is a COMPLETE HTML DOCUMENT, so it cannot go into this page's DOM;
		 * `esc_attr()` is the escaping applied at output, and the EMPTY `sandbox`
		 * withholds scripts, forms, same-origin access and navigation from whatever the
		 * browser reconstructs — so even a construct that survived two passes of
		 * `wp_kses_post()` could not act.
		 */
		echo '<iframe class="extonify-wcep-preview-frame" sandbox="" title="'
			. esc_attr__( 'A preview of the HTML email', 'extonify-custom-emails-per-product' ) . '"'
			. ' srcdoc="' . esc_attr( (string) $preview['html'] ) . '"></iframe>';

		echo '<h3>' . esc_html__( 'Plain text', 'extonify-custom-emails-per-product' ) . '</h3>';

		echo '<pre class="extonify-wcep-preview-plain">' . esc_html( (string) $preview['plain'] ) . '</pre>';
	}

	/**
	 * The test-send form (ADR-0020 §4, §5).
	 *
	 * ⚠ IT POSTS, IT CARRIES AN ACTION- AND SUBJECT-SPECIFIC NONCE, AND IT LEADS TO A
	 * CONFIRMATION RATHER THAN TO A SEND. The GET link goes to `DeliveryConfirm`, which
	 * issues the single-use token; gate 36 asserts that no GET anywhere in this plugin
	 * sends anything, and this screen is no exception to it.
	 *
	 * @param int $rule_id  Rule being previewed.
	 * @param int $order_id Order the preview used.
	 * @return void
	 */
	private static function render_test_form( int $rule_id, int $order_id ): void {
		echo '<h3>' . esc_html__( 'Send a test email', 'extonify-custom-emails-per-product' ) . '</h3>';

		echo '<p class="description" id="extonify-wcep-test-help">'
			. esc_html__( 'This sends a real email, through this store\'s own mail settings, to the address you type below. The rule\'s own recipients are not used. Its subject is marked as a test, and it does not stop the rule sending normally.', 'extonify-custom-emails-per-product' )
			. '</p>';

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="extonify-wcep-preview-test">';

		echo '<input type="hidden" name="page" value="' . esc_attr( Menu::HISTORY_PAGE ) . '" />';
		echo '<input type="hidden" name="wcep_action" value="' . esc_attr( DeliveryActions::ACTION_TEST ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( DeliveryActions::FIELD_ORDER ) . '" value="' . esc_attr( (string) $order_id ) . '" />';
		echo '<input type="hidden" name="' . esc_attr( DeliveryActions::FIELD_RULE ) . '" value="' . esc_attr( (string) $rule_id ) . '" />';

		echo '<label for="extonify-wcep-test-address">'
			. esc_html__( 'Send the test to', 'extonify-custom-emails-per-product' ) . '</label> ';

		echo '<input type="email" id="extonify-wcep-test-address" name="' . esc_attr( DeliveryActions::FIELD_ADDRESS ) . '"'
			. ' value="' . esc_attr( TestDelivery::current_user_email() ) . '"'
			. ' aria-describedby="extonify-wcep-test-help" /> ';

		submit_button( __( 'Send a test…', 'extonify-custom-emails-per-product' ), 'secondary', '', false );

		echo '</form>';
	}

	/**
	 * One fact row.
	 *
	 * @param string $label Row label.
	 * @param string $value Row value.
	 * @return void
	 */
	private static function fact( string $label, string $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	/**
	 * A refusal instead of a preview.
	 *
	 * @param string $code Refusal code.
	 * @return void
	 */
	private static function render_refusal( string $code ): void {
		$messages = Notices::preview_messages();
		$key      = 'wcep_preview_refused_' . $code;

		Notices::render(
			'error',
			isset( $messages[ $key ] )
				? $messages[ $key ][1]
				: __( 'This rule could not be previewed.', 'extonify-custom-emails-per-product' )
		);
	}

	/**
	 * A native email's human title, falling back to its id.
	 *
	 * @param string $native_email_id WooCommerce email id.
	 * @return string
	 */
	private static function native_title( string $native_email_id ): string {
		$titles = FieldOptions::native_emails();

		return (string) ( $titles[ $native_email_id ] ?? $native_email_id );
	}

	/**
	 * Whether custom product emails are switched on at all.
	 *
	 * @return bool
	 */
	private static function globally_enabled(): bool {
		return ( new \Extonify\WCEP\Delivery\Orchestrator() )->manual_send_is_available();
	}

	/**
	 * The order this request asked to preview against, or 0.
	 *
	 * @return int
	 */
	public static function requested_order_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reading which order to RENDER a preview of; the cast to int IS the sanitisation for an id, this screen writes nothing and sends nothing, and the test-send POST it links to verifies its own nonce and token.
		return isset( $_GET[ self::ARG_ORDER ] ) ? max( 0, (int) wp_unslash( $_GET[ self::ARG_ORDER ] ) ) : 0;
	}

	/**
	 * A link to this screen for one rule.
	 *
	 * @param int $rule_id  Rule id.
	 * @param int $order_id Order id, or 0 for the default.
	 * @return string
	 */
	public static function url( int $rule_id, int $order_id = 0 ): string {
		$args = array(
			'action' => Menu::ACTION_PREVIEW,
			'rule'   => $rule_id,
		);

		if ( $order_id > 0 ) {
			$args[ self::ARG_ORDER ] = $order_id;
		}

		return Menu::url( $args );
	}
}
