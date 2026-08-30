<?php
/**
 * The rule editor screen.
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

use Extonify\WCEP\Delivery\Consolidation;
use Extonify\WCEP\Domain\RecipientsDocument;
use Extonify\WCEP\Domain\Targeting;
use Extonify\WCEP\Domain\TriggerEvent;
use Extonify\WCEP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The add/edit form.
 *
 * ⚠ EVERY VALUE IS ESCAPED AT ITS POINT OF OUTPUT, FOR ITS OWN CONTEXT (gate 31).
 * A rule's stored name, subject and heading are merchant-authored free text —
 * `sanitize_text_field()` at the storage boundary is a sanitiser, not an escaper —
 * and the body is `wp_kses_post()`-filtered HTML that must reach `wp_editor()` as
 * markup and every other context escaped.
 *
 * ⚠ EVERY INPUT HAS A REAL `<label for>`, AND EVERY DESCRIPTION IS TIED TO ITS FIELD
 * WITH `aria-describedby` (gate 33). A description sitting visually beneath a control
 * is not associated with it: to anyone using a screen reader it is loose text
 * somewhere on the page, read at a moment that has nothing to do with the field.
 *
 * ⚠ A REFUSED SAVE RE-RENDERS FROM THE SUBMITTED FORM, NOT FROM STORAGE
 * (ADR-0017 §3.2). The stored row and the form deliberately disagree at that point:
 * the row is what it always was, and the form is what the merchant typed.
 */
final class RuleEditor {

	/**
	 * The `wp_editor()` instance id.
	 *
	 * ⚠ LOWER-CASE LETTERS ONLY. TinyMCE derives DOM ids and JavaScript identifiers
	 * from it, and WordPress documents underscores and hyphens as unsupported.
	 */
	const EDITOR_ID = 'extonifywcepcontent';

	/**
	 * Render the screen.
	 *
	 * @param array $pending Outcome from a refused save, or empty.
	 * @return void
	 */
	public static function render( array $pending = array() ): void {
		Menu::require_capability();

		$errors = array();

		if ( isset( $pending['form'] ) && $pending['form'] instanceof RuleFormInput ) {
			$form    = $pending['form'];
			$rule_id = (int) ( $pending['rule_id'] ?? 0 );
			$errors  = (array) ( $pending['errors'] ?? array() );
		} else {
			$rule_id = Menu::requested_rule_id();
			$rule    = $rule_id > 0 ? Plugin::instance()->rules()->find( $rule_id ) : null;

			if ( $rule_id > 0 && null === $rule ) {
				echo '<div class="wrap extonify-wcep">';
				echo '<h1>' . esc_html__( 'Email Rules', 'extonify-custom-emails-per-product' ) . '</h1>';
				Notices::render( 'error', __( 'That rule no longer exists.', 'extonify-custom-emails-per-product' ) );
				echo '<p><a href="' . esc_url( Menu::url() ) . '">' . esc_html__( 'Back to all rules', 'extonify-custom-emails-per-product' ) . '</a></p>';
				echo '</div>';
				return;
			}

			$form = null === $rule ? RuleFormInput::blank() : RuleFormInput::from_rule( $rule );
		}

		echo '<div class="wrap extonify-wcep extonify-wcep-editor">';

		echo '<h1 class="wp-heading-inline">' . ( $rule_id > 0
			? esc_html__( 'Edit Email Rule', 'extonify-custom-emails-per-product' )
			: esc_html__( 'Add Email Rule', 'extonify-custom-emails-per-product' ) ) . '</h1>';

		echo '<a href="' . esc_url( Menu::url() ) . '" class="page-title-action">'
			. esc_html__( 'Back to all rules', 'extonify-custom-emails-per-product' ) . '</a>';

		echo '<hr class="wp-header-end" />';

		// ⚠ ON A REFUSAL THERE IS NO SUCCESS NOTICE, EVER (ADR-0017 §3.4). The request
		// notice is rendered only when the save handler did not refuse.
		if ( array() === $errors ) {
			Notices::render_request_notice();
		} else {
			self::render_errors( $errors );
		}

		self::render_warnings( $form );

		echo '<form method="post" action="' . esc_url( Menu::url() ) . '" class="extonify-wcep-form">';

		echo '<input type="hidden" name="action" value="' . esc_attr( RuleActions::ACTION_SAVE ) . '" />';
		echo '<input type="hidden" name="rule" value="' . esc_attr( (string) $rule_id ) . '" />';

		wp_nonce_field( RuleActions::NONCE_SAVE, RuleActions::NONCE_FIELD );

		self::section_identity( $form, $errors );
		self::section_trigger( $form, $errors );
		self::section_targeting( $form );
		self::section_delivery( $form, $errors );
		self::section_recipients( $form );
		self::section_content( $form );
		self::section_ordering( $form );

		submit_button(
			$rule_id > 0
			? __( 'Save rule', 'extonify-custom-emails-per-product' )
			: __( 'Create rule', 'extonify-custom-emails-per-product' )
		);

		echo '</form>';

		echo '</div>';
	}

	/**
	 * The refusal notices, one per named field.
	 *
	 * @param array<string,string> $errors Field => code.
	 * @return void
	 */
	private static function render_errors( array $errors ): void {
		echo '<div class="notice notice-error"><p><strong>'
			. esc_html__( 'This rule was not saved, and nothing was changed.', 'extonify-custom-emails-per-product' )
			. '</strong></p><ul class="extonify-wcep-errors">';

		foreach ( $errors as $field => $code ) {
			echo '<li>' . esc_html( Notices::refusal_message( (string) $field, (string) $code ) ) . '</li>';
		}

		echo '</ul></div>';
	}

	/**
	 * The ADR-0017 §5 warnings.
	 *
	 * @param RuleFormInput $form The form being rendered.
	 * @return void
	 */
	private static function render_warnings( RuleFormInput $form ): void {
		foreach ( Warnings::for_form( $form ) as $warning ) {
			echo '<div class="notice notice-warning extonify-wcep-warning" data-extonify-wcep-warning="'
				. esc_attr( (string) $warning['id'] ) . '"><p>'
				. esc_html( (string) $warning['message'] ) . '</p></div>';
		}
	}

	// -----------------------------------------------------------------------
	// Sections
	// -----------------------------------------------------------------------

	/**
	 * Name and status.
	 *
	 * @param RuleFormInput        $form   The form.
	 * @param array<string,string> $errors Field => code.
	 * @return void
	 */
	private static function section_identity( RuleFormInput $form, array $errors ): void {
		self::open_section( 'identity', __( 'Name and status', 'extonify-custom-emails-per-product' ) );

		self::text_row(
			'name',
			__( 'Name', 'extonify-custom-emails-per-product' ),
			(string) $form->get( 'name' ),
			__( 'Only you see this. It is how the rule appears in the list.', 'extonify-custom-emails-per-product' ),
			isset( $errors['name'] )
		);

		self::select_row(
			'status',
			__( 'Status', 'extonify-custom-emails-per-product' ),
			FieldOptions::statuses(),
			(string) $form->get( 'status' ),
			__( 'An inactive rule is never evaluated and sends nothing.', 'extonify-custom-emails-per-product' ),
			isset( $errors['status'] )
		);

		self::close_section();
	}

	/**
	 * Trigger.
	 *
	 * @param RuleFormInput        $form   The form.
	 * @param array<string,string> $errors Field => code.
	 * @return void
	 */
	private static function section_trigger( RuleFormInput $form, array $errors ): void {
		// The note below explains the WHOLE section, not one field, so the section is
		// the named group it describes (see `open_section()`).
		self::open_section(
			'trigger',
			__( 'When it sends', 'extonify-custom-emails-per-product' ),
			'extonify-wcep-trigger-note'
		);

		echo '<p class="extonify-wcep-note" id="extonify-wcep-trigger-note">'
			. esc_html__( 'A rule that adds its content to a WooCommerce email has no trigger of its own — WooCommerce decides when that email sends. These settings apply to a rule sent as a separate email.', 'extonify-custom-emails-per-product' )
			. '</p>';

		self::select_row(
			'trigger_type',
			__( 'Trigger', 'extonify-custom-emails-per-product' ),
			FieldOptions::trigger_types(),
			(string) $form->get( 'trigger_type' ),
			'',
			isset( $errors['trigger_type'] )
		);

		$statuses = FieldOptions::order_statuses();
		$invalid  = isset( $errors['trigger_value'] );

		self::select_row(
			'trigger_status',
			__( 'Order status', 'extonify-custom-emails-per-product' ),
			$statuses,
			(string) $form->get( 'trigger_status' ),
			__( 'The rule is considered each time an order reaches this status.', 'extonify-custom-emails-per-product' ),
			$invalid,
			array( 'data-extonify-wcep-trigger' => TriggerEvent::TYPE_STATUS )
		);

		self::select_row(
			'trigger_from',
			__( 'Moves from', 'extonify-custom-emails-per-product' ),
			$statuses,
			(string) $form->get( 'trigger_from' ),
			'',
			$invalid,
			array( 'data-extonify-wcep-trigger' => TriggerEvent::TYPE_TRANSITION )
		);

		self::select_row(
			'trigger_to',
			__( 'Moves to', 'extonify-custom-emails-per-product' ),
			$statuses,
			(string) $form->get( 'trigger_to' ),
			__( 'The rule is considered only on this exact move, not on every arrival at the second status.', 'extonify-custom-emails-per-product' ),
			$invalid,
			array( 'data-extonify-wcep-trigger' => TriggerEvent::TYPE_TRANSITION )
		);

		self::close_section();
	}

	/**
	 * Targeting.
	 *
	 * @param RuleFormInput $form The form.
	 * @return void
	 */
	private static function section_targeting( RuleFormInput $form ): void {
		self::open_section( 'targeting', __( 'Which products', 'extonify-custom-emails-per-product' ) );

		self::checkbox_row(
			'match_all',
			__( 'Every product in the order', 'extonify-custom-emails-per-product' ),
			(bool) $form->get( 'match_all' ),
			__( 'Anything you exclude below still wins: an excluded product is never matched, whatever else says otherwise.', 'extonify-custom-emails-per-product' )
		);

		// One batch for the whole editor (ADR-0017 §9), so a rule naming two hundred
		// products still costs two queries rather than two hundred.
		$needed = array();

		foreach ( Targeting::KINDS as $kind ) {
			$needed[ $kind ] = array_merge( $form->targets( 'include', $kind ), $form->targets( 'exclude', $kind ) );
		}

		$labels = TargetLabels::resolve( $needed );
		$kinds  = FieldOptions::targeting_kinds();

		foreach ( RuleFormInput::sides() as $side ) {
			echo '<div class="extonify-wcep-side extonify-wcep-side--' . esc_attr( $side ) . '">';

			echo '<h3>' . ( 'include' === $side
				? esc_html__( 'Include', 'extonify-custom-emails-per-product' )
				: esc_html__( 'Exclude', 'extonify-custom-emails-per-product' ) ) . '</h3>';

			foreach ( Targeting::KINDS as $kind ) {
				if ( 'types' === $kind ) {
					self::type_picker( $side, (string) ( $kinds[ $kind ] ?? $kind ), $form->targets( $side, $kind ) );
					continue;
				}

				self::id_picker( $side, $kind, (string) ( $kinds[ $kind ] ?? $kind ), $form->targets( $side, $kind ), $labels[ $kind ] ?? array() );
			}

			echo '</div>';
		}

		self::close_section();
	}

	/**
	 * Delivery.
	 *
	 * @param RuleFormInput        $form   The form.
	 * @param array<string,string> $errors Field => code.
	 * @return void
	 */
	private static function section_delivery( RuleFormInput $form, array $errors ): void {
		$is_insert = 'insert' === (string) $form->get( 'delivery_mode' );

		self::open_section( 'delivery', __( 'How it is delivered', 'extonify-custom-emails-per-product' ) );

		self::select_row(
			'delivery_mode',
			Notices::field_label( 'delivery_mode' ),
			FieldOptions::delivery_modes(),
			(string) $form->get( 'delivery_mode' ),
			'',
			isset( $errors['delivery_mode'] )
		);

		$emails = FieldOptions::native_emails();

		self::select_row(
			'native_email_id',
			Notices::field_label( 'native_email_id' ),
			$emails,
			(string) $form->get( 'native_email_id' ),
			__( 'Which WooCommerce email this rule adds its content to.', 'extonify-custom-emails-per-product' ),
			isset( $errors['native_email_id'] ),
			array( 'data-extonify-wcep-mode' => 'insert' ),
			array( '' => __( '— Choose an email —', 'extonify-custom-emails-per-product' ) )
		);

		self::select_row(
			'insert_position',
			Notices::field_label( 'insert_position' ),
			FieldOptions::insert_positions(),
			(string) $form->get( 'insert_position' ),
			__( 'Where in that email the content appears.', 'extonify-custom-emails-per-product' ),
			false,
			array( 'data-extonify-wcep-mode' => 'insert' )
		);

		self::delay_row( $form, $is_insert, isset( $errors['delay_seconds'] ) );
		self::consolidation_row( $form, $is_insert, isset( $errors['consolidation'] ) );

		self::close_section();
	}

	/**
	 * Recipients.
	 *
	 * ⚠ AN INSERT RULE HAS NO ENVELOPE, AND THIS SECTION USED TO IMPLY IT HAD ONE.
	 * That was a TIER 1 defect of the interface, not of the delivery code: an insert
	 * rule contributes BODY CONTENT ONLY — WooCommerce owns the recipient, the
	 * subject, the heading and the send time (ADR-0013 §2), and
	 * `RuleRepository::find_active_for_native_email()` never selects on recipients.
	 * The section nonetheless rendered "Who receives it" as a live choice with no
	 * mode guard, and told the merchant *"A rule with no 'To' recipient sends
	 * nothing"* — advice that is meaningless for a rule which sends nothing either
	 * way. A merchant who set **To: admin** on an insert rule could reasonably
	 * conclude the extra content went to the administrator alone, when it is in fact
	 * injected into WooCommerce's email to the CUSTOMER. Nothing on the screen
	 * corrected that belief, which makes it the worse form of this project's own
	 * Tier 1 shape: a false belief about who reads what the merchant wrote.
	 *
	 * ⚠ READ-ONLY, NOT DISABLED, AND THAT IS THE WHOLE PRESERVATION MECHANISM.
	 * `delay_row()` and `consolidation_row()` disable their controls and submit a
	 * hidden mirror, because insert mode FORCES those two columns to `0` and `none` —
	 * a mirror can carry a constant. Recipients are different in kind: insert mode
	 * neither reads nor rewrites them, so the stored document must arrive back
	 * BYTE-FOR-BYTE. A hidden mirror could only carry the value the page was rendered
	 * with, so a merchant who edited the recipients and THEN switched to insert would
	 * have had their edit silently replaced by the stale copy — the very defect this
	 * section is being fixed for, reintroduced by the fix. A read-only `<textarea>`
	 * submits its own live value in every path, with the script on or off, and needs
	 * no second copy of the merchant's data to keep in step.
	 *
	 * @param RuleFormInput $form The form.
	 * @return void
	 */
	private static function section_recipients( RuleFormInput $form ): void {
		$is_insert = 'insert' === (string) $form->get( 'delivery_mode' );

		// Two paragraphs, both named as the section's description (gate 33): the mode
		// scope is the one a merchant must read, and the syntax is the one they come
		// back to. A single `aria-describedby` IDREF LIST associates both with the
		// group, so neither is loose text and neither is read out three times.
		self::open_section(
			'recipients',
			__( 'Who receives it', 'extonify-custom-emails-per-product' ),
			'extonify-wcep-recipients-note extonify-wcep-recipients-syntax'
		);

		echo '<p class="extonify-wcep-note" id="extonify-wcep-recipients-note">'
			. esc_html__( 'These recipients apply to a rule sent as a separate email. A rule that adds its content to a WooCommerce email has no recipients of its own: the content goes into the email WooCommerce is already sending, to whoever that email is addressed to: the customer for a customer email, the store\'s administrators for an admin one. For such a rule these boxes are read-only, and whatever they hold is kept in case it is switched back.', 'extonify-custom-emails-per-product' )
			. '</p>';

		echo '<p class="extonify-wcep-note" id="extonify-wcep-recipients-syntax">'
			. esc_html__( 'One recipient per line. Write "customer" for the billing address on the order, "admin" for the site administrator, or any email address. The placeholders {customer_email} and {store_email} also work here — no others do, because a recipient becomes part of the email header.', 'extonify-custom-emails-per-product' )
			. '</p>';

		$labels = FieldOptions::recipient_channels();

		foreach ( RecipientsDocument::CHANNELS as $channel ) {
			self::textarea_row(
				RuleFormInput::FIELD . '[recipients][' . $channel . ']',
				(string) ( $labels[ $channel ] ?? $channel ),
				implode( "\n", $form->recipients( $channel ) ),
				'to' === $channel
					? __( 'A rule sent as a separate email with no "To" recipient sends nothing, and says so in its delivery record.', 'extonify-custom-emails-per-product' )
					: '',
				'extonify-wcep-recipients-' . $channel,
				$is_insert
			);
		}

		self::close_section();
	}

	/**
	 * Content.
	 *
	 * @param RuleFormInput $form The form.
	 * @return void
	 */
	private static function section_content( RuleFormInput $form ): void {
		self::open_section( 'content', __( 'What it says', 'extonify-custom-emails-per-product' ) );

		/*
		 * ⚠ THE REFERENCE IS PLACED **AFTER** THE FIELDS AND STAYS THERE (gate 33).
		 * `assets/admin.css` puts it in the right-hand column of a two-column grid on
		 * a wide screen, which is a LAYOUT change and not a DOM one: the document
		 * order is still subject → heading → body → placeholders, so tab order runs
		 * fields → reference, which is the order a merchant works in. Moving the
		 * markup to get the visual order would have swapped the tab order with it.
		 *
		 * The wrapper carries no id, no label and no ARIA attribute, so no gate-33
		 * association passes through it.
		 */
		echo '<div class="extonify-wcep-content-layout">';
		echo '<div class="extonify-wcep-content-fields">';

		/*
		 * ⚠ THE SUBJECT AND THE HEADING ARE A MATCHED PAIR AND MUST READ AS ONE. The
		 * heading's description has carried its mode scope since it was written; the
		 * subject's said *"The subject line the customer sees"* — flatly false for an
		 * insert rule, whose customer sees WOOCOMMERCE'S subject. One of a pair being
		 * accurate is how the other survived every earlier read of this file.
		 *
		 * Both stay EDITABLE in insert mode, unlike the recipients above, and the line
		 * between them is deliberate: the recipients section presents an ENVELOPE the
		 * rule does not have, which is the false belief worth removing, while these two
		 * are content fields whose scope is now stated on them. A merchant switching
		 * back to a separate email finds what they wrote still there.
		 */
		self::text_row(
			'subject',
			Notices::field_label( 'subject' ),
			(string) $form->get( 'subject' ),
			__( 'Used as the subject of a separate email. Ignored when the content is added to a WooCommerce email — WooCommerce\'s own subject is used.', 'extonify-custom-emails-per-product' ),
			false,
			true
		);

		self::text_row(
			'heading',
			Notices::field_label( 'heading' ),
			(string) $form->get( 'heading' ),
			__( 'Shown at the top of a separate email. Ignored when the content is added to a WooCommerce email.', 'extonify-custom-emails-per-product' ),
			false,
			true
		);

		echo '<div class="extonify-wcep-row extonify-wcep-row--editor">';
		echo '<div class="extonify-wcep-label"><label for="' . esc_attr( self::EDITOR_ID ) . '">'
			. esc_html( Notices::field_label( 'content' ) ) . '</label></div>';
		echo '<div class="extonify-wcep-field">';

		/*
		 * ⚠ THE BODY IS THE ONE FIELD THAT IS **NOT** ESCAPED ON OUTPUT, AND THAT IS
		 * CORRECT (gate 31). `wp_editor()` takes MARKUP: escaping it would show a
		 * merchant their own `<p>` tags as text and would corrupt the body on the next
		 * save. What makes it safe is the storage boundary — `RuleRepository::sanitize()`
		 * and `RuleFormInput` both pass it through `wp_kses_post()`, which strips
		 * scripts and event handlers — plus the `the_editor_content` escaping arranged
		 * below, which keeps it inside the textarea's RCDATA. Every OTHER surface that
		 * shows this value escapes it for its own context.
		 *
		 * ⚠ TWO ATTRIBUTES ARE ADDED THROUGH `the_editor` BECAUSE THERE IS NO OTHER
		 * WAY IN. `wp_editor()` builds its own `<textarea>` and takes no attribute
		 * arguments — only `editor_class`, which cannot express either of these.
		 * `the_editor` is the documented filter over exactly that markup; the
		 * injection is anchored to THIS editor's id, so another `wp_editor()` on the
		 * same screen is untouched, and the filter is removed immediately after.
		 *
		 *   `aria-describedby` (gate 33) — without it the description below is loose
		 *   text a screen-reader user meets at a moment unrelated to the field it
		 *   explains.
		 *
		 *   `data-extonify-wcep-insertable` — the ONE marker `assets/admin.js` reads
		 *   to decide where a clicked placeholder lands. ⚠ THE BODY CARRIED NO MARKER
		 *   UNTIL PART G, AND THAT WAS A TIER 1 DEFECT: focusing the body never
		 *   updated the script's "last focused field", so a placeholder clicked while
		 *   the merchant was writing the body was silently inserted into the HEADING.
		 *   Every field a placeholder may be inserted into carries this attribute,
		 *   every field that must not accept one does not, and the script branches on
		 *   nothing else.
		 *   `AdminOutputTest::test_every_placeholder_target_is_marked_and_nothing_else_is()`
		 *   pins that as a complete map, on both editor paths, so a new field cannot
		 *   appear without a deliberate answer either way.
		 *
		 * ⚠ BOTH GO **BEFORE** THE `id`, WHICH MUST STAY LAST. `_WP_Editors` emits the
		 * id as the final attribute and `AdminOutputTest::editor_content()` locates the
		 * body by `id="…">`; appending after it would make that helper silently return
		 * the empty string and take the RCDATA escaping assertions down with it.
		 */
		$augment = static function ( $markup ) {
			return str_replace(
				' id="' . self::EDITOR_ID . '"',
				' aria-describedby="extonify-wcep-content-description"'
					. ' data-extonify-wcep-insertable="1" id="' . self::EDITOR_ID . '"',
				(string) $markup
			);
		};

		add_filter( 'the_editor', $augment );

		/*
		 * ⚠ THE BODY IS ESCAPED FOR THE TEXTAREA BY THIS PLUGIN, NOT BY LUCK.
		 * `_WP_Editors::editor()` adds `format_for_editor` to `the_editor_content`
		 * ONLY when TinyMCE is active — and TinyMCE is inactive whenever
		 * `user_can_richedit()` is false, which is every merchant who ticked
		 * "Disable the visual editor when writing", and every non-browser request.
		 * On that path core hands the body to the `<textarea>` UNESCAPED and the only
		 * thing left between it and the end of RCDATA is core's own internal
		 * `preg_replace( '%</textarea%i', … )` — an implementation detail of a class
		 * marked `@access private`, not an API this plugin may lean on.
		 *
		 * Adding the filter here covers that path and cannot double-escape the other
		 * one: `add_filter()` is keyed on callback name and priority, so when core
		 * adds the SAME function at the SAME priority it replaces this entry rather
		 * than appending a second, and core's own `remove_filter()` then takes it
		 * away. The content is escaped exactly once either way.
		 *
		 * It also fixes a quiet round-trip loss on that path: without it a stored
		 * `&lt;` is RCDATA-decoded by the browser into `<` and comes back as a real
		 * tag on the next save.
		 */
		add_filter( 'the_editor_content', 'format_for_editor', 10, 2 );

		wp_editor(
			(string) $form->get( 'content' ),
			self::EDITOR_ID,
			array(
				'textarea_name' => RuleFormInput::FIELD . '[content]',
				'textarea_rows' => 14,
				'media_buttons' => true,
				'teeny'         => false,
				'editor_class'  => 'extonify-wcep-content',
			)
		);

		remove_filter( 'the_editor_content', 'format_for_editor', 10 );
		remove_filter( 'the_editor', $augment );

		echo '<p class="description" id="extonify-wcep-content-description">'
			. esc_html__( 'The message body. Use the Placeholders panel to include order and product details.', 'extonify-custom-emails-per-product' )
			. '</p>';

		echo '</div></div>';

		echo '</div>'; // .extonify-wcep-content-fields — the left column.

		self::placeholder_reference();

		echo '</div>'; // .extonify-wcep-content-layout.

		self::close_section();
	}

	/**
	 * Priority and stop-processing.
	 *
	 * @param RuleFormInput $form The form.
	 * @return void
	 */
	private static function section_ordering( RuleFormInput $form ): void {
		self::open_section( 'ordering', __( 'Order of evaluation', 'extonify-custom-emails-per-product' ) );

		echo '<div class="extonify-wcep-row">';
		echo '<div class="extonify-wcep-label"><label for="extonify-wcep-priority">'
			. esc_html( Notices::field_label( 'priority' ) ) . '</label></div>';
		echo '<div class="extonify-wcep-field">';
		echo '<input type="number" step="1" id="extonify-wcep-priority" class="small-text"'
			. ' name="' . esc_attr( RuleFormInput::FIELD ) . '[priority]"'
			. ' value="' . esc_attr( (string) (int) $form->get( 'priority', 10 ) ) . '"'
			. ' aria-describedby="extonify-wcep-priority-description" />';
		echo '<p class="description" id="extonify-wcep-priority-description">'
			. esc_html__( 'Lower numbers are considered first.', 'extonify-custom-emails-per-product' )
			. '</p>';
		echo '</div></div>';

		self::checkbox_row(
			'stop_processing',
			__( 'Stop after this rule', 'extonify-custom-emails-per-product' ),
			(bool) $form->get( 'stop_processing' ),
			__( 'When this rule matches an order, no lower-priority rule is considered for it.', 'extonify-custom-emails-per-product' )
		);

		self::close_section();
	}

	// -----------------------------------------------------------------------
	// Controls
	// -----------------------------------------------------------------------

	/**
	 * The delay control, with its insert-mode mirror (ADR-0017 §5, W4).
	 *
	 * ⚠ THE HIDDEN MIRROR IS WHY A DISABLED CONTROL DOES NOT LOSE THE SAVE. A disabled
	 * input submits NOTHING, so switching a stored delayed rule to insert mode would
	 * leave the repository reading the delay it already had and refusing the write.
	 * The mirror carries the explicit `0` while the real control is disabled, and the
	 * script swaps which of the two is enabled. Rendered from the STORED mode, so the
	 * pair is already correct with JavaScript switched off.
	 *
	 * ⚠ AND THE MIRROR IS A COURTESY, NOT THE BOUNDARY. A forged post carrying
	 * `insert` and a non-zero delay is still refused by the repository, naming
	 * `delay_seconds` (ADR-0013 §2) — see `AdminInsertConstraintsTest`.
	 *
	 * @param RuleFormInput $form      The form.
	 * @param bool          $is_insert Whether the current mode is insert.
	 * @param bool          $invalid   Whether this field was refused.
	 * @return void
	 */
	private static function delay_row( RuleFormInput $form, bool $is_insert, bool $invalid ): void {
		$field = RuleFormInput::FIELD;
		$units = FieldOptions::delay_unit_labels();
		$unit  = (string) $form->get( 'delay_unit', 'minutes' );

		echo '<div class="extonify-wcep-row' . ( $invalid ? ' extonify-wcep-row--invalid' : '' ) . '">';
		echo '<div class="extonify-wcep-label"><label for="extonify-wcep-delay-value">'
			. esc_html( Notices::field_label( 'delay_seconds' ) ) . '</label></div>';
		echo '<div class="extonify-wcep-field">';

		// The mirrors: enabled ONLY while the real controls are disabled.
		echo '<input type="hidden" name="' . esc_attr( $field ) . '[delay_value]" value="0"'
			. ' data-extonify-wcep-insert-mirror="1"' . ( $is_insert ? '' : ' disabled="disabled"' ) . ' />';
		echo '<input type="hidden" name="' . esc_attr( $field ) . '[delay_unit]" value="minutes"'
			. ' data-extonify-wcep-insert-mirror="1"' . ( $is_insert ? '' : ' disabled="disabled"' ) . ' />';

		echo '<input type="number" min="0" step="1" id="extonify-wcep-delay-value" class="small-text"'
			. ' name="' . esc_attr( $field ) . '[delay_value]"'
			. ' value="' . esc_attr( (string) (int) $form->get( 'delay_value', 0 ) ) . '"'
			. ' data-extonify-wcep-insert-locked="1"' . ( $is_insert ? ' disabled="disabled"' : '' )
			. ' aria-describedby="extonify-wcep-delay-description" />';

		echo ' <label class="screen-reader-text" for="extonify-wcep-delay-unit">'
			. esc_html__( 'Delay unit', 'extonify-custom-emails-per-product' ) . '</label>';
		echo '<select id="extonify-wcep-delay-unit" name="' . esc_attr( $field ) . '[delay_unit]"'
			. ' data-extonify-wcep-insert-locked="1"' . ( $is_insert ? ' disabled="disabled"' : '' )
			. ' aria-describedby="extonify-wcep-delay-description">';

		foreach ( $units as $value => $label ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $unit, (string) $value, false ) . '>'
				. esc_html( (string) $label ) . '</option>';
		}

		echo '</select>';

		echo '<p class="description" id="extonify-wcep-delay-description">'
			. esc_html__( 'Zero sends straight away. A rule that adds its content to a WooCommerce email cannot be delayed, so this is switched off for it.', 'extonify-custom-emails-per-product' )
			. '</p>';

		echo '</div></div>';
	}

	/**
	 * The consolidation control, with its insert-mode mirror (ADR-0016 §2).
	 *
	 * @param RuleFormInput $form      The form.
	 * @param bool          $is_insert Whether the current mode is insert.
	 * @param bool          $invalid   Whether this field was refused.
	 * @return void
	 */
	private static function consolidation_row( RuleFormInput $form, bool $is_insert, bool $invalid ): void {
		$field   = RuleFormInput::FIELD;
		$current = (string) $form->get( 'consolidation' );

		echo '<div class="extonify-wcep-row' . ( $invalid ? ' extonify-wcep-row--invalid' : '' ) . '">';
		echo '<div class="extonify-wcep-label"><label for="extonify-wcep-consolidation">'
			. esc_html( Notices::field_label( 'consolidation' ) ) . '</label></div>';
		echo '<div class="extonify-wcep-field">';

		echo '<input type="hidden" name="' . esc_attr( $field ) . '[consolidation]"'
			. ' value="' . esc_attr( Consolidation::NONE ) . '"'
			. ' data-extonify-wcep-insert-mirror="1"' . ( $is_insert ? '' : ' disabled="disabled"' ) . ' />';

		echo '<select id="extonify-wcep-consolidation" name="' . esc_attr( $field ) . '[consolidation]"'
			. ' data-extonify-wcep-insert-locked="1"' . ( $is_insert ? ' disabled="disabled"' : '' )
			. ' aria-describedby="extonify-wcep-consolidation-description">';

		foreach ( FieldOptions::consolidation_modes() as $value => $label ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $current, (string) $value, false ) . '>'
				. esc_html( (string) $label ) . '</option>';
		}

		/*
		 * ⚠ A STORED VALUE OUTSIDE THE VOCABULARY IS SHOWN, DISABLED, RATHER THAN
		 * SILENTLY BECOMING `none` (ADR-0016 §1a). Such a row is legitimate history —
		 * `daily` was storable from Prompt 5B to Prompt 8 — and the rule is NOT
		 * DELIVERABLE until the merchant chooses. Rendering the select without it would
		 * show `none` selected, which is a value nobody chose and a rule that would
		 * start sending on the next save.
		 */
		if ( '' !== $current && ! Consolidation::is_valid( $current ) ) {
			echo '<option value="' . esc_attr( $current ) . '" selected="selected" disabled="disabled">'
				. esc_html(
					sprintf(
						/* translators: %s: the unrecognised value stored on the rule. */
						__( '%s — no longer supported, choose another', 'extonify-custom-emails-per-product' ),
						$current
					)
				) . '</option>';
		}

		echo '</select>';

		echo '<p class="description" id="extonify-wcep-consolidation-description">'
			. esc_html__( 'A rule that adds its content to a WooCommerce email cannot choose this — WooCommerce already decided how many emails to send.', 'extonify-custom-emails-per-product' )
			. '</p>';

		echo '</div></div>';
	}

	/**
	 * One id-kind picker: the current selection as removable checkboxes, plus a
	 * search box the script fills.
	 *
	 * ⚠ THE SELECTION IS REAL CHECKBOXES, NOT A WIDGET'S INTERNAL STATE. With
	 * JavaScript off the merchant can still see every target and still remove any of
	 * them; the search box is the only part that needs the script. Unchecking is what
	 * removes an entry, which is the behaviour a checkbox already promises.
	 *
	 * @param string $side   `include` or `exclude`.
	 * @param string $kind   One of `Targeting::ID_KINDS`.
	 * @param string $label  Kind label.
	 * @param array  $ids    Currently selected ids.
	 * @param array  $labels Resolved id => `{label, missing}`.
	 * @return void
	 */
	private static function id_picker( string $side, string $kind, string $label, array $ids, array $labels ): void {
		$base    = RuleFormInput::FIELD . '[targeting][' . $side . '][' . $kind . '][]';
		$dom     = 'extonify-wcep-' . $side . '-' . $kind;
		$search  = $dom . '-search';
		$results = $dom . '-results';

		echo '<fieldset class="extonify-wcep-picker" data-extonify-wcep-picker="1"'
			. ' data-kind="' . esc_attr( $kind ) . '" data-side="' . esc_attr( $side ) . '">';
		echo '<legend>' . esc_html( $label ) . '</legend>';

		echo '<ul class="extonify-wcep-chips" data-extonify-wcep-chips="1">';

		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$meta = $labels[ $id ] ?? array(
				'label'   => '#' . $id,
				'missing' => true,
			);

			self::chip( $base, $id, (string) $meta['label'], (bool) $meta['missing'] );
		}

		echo '</ul>';

		echo '<label class="screen-reader-text" for="' . esc_attr( $search ) . '">'
			. esc_html(
				sprintf(
					/* translators: %s: the kind being searched, e.g. "Products". */
					__( 'Search %s to add', 'extonify-custom-emails-per-product' ),
					$label
				)
			) . '</label>';

		echo '<input type="search" id="' . esc_attr( $search ) . '" class="extonify-wcep-search regular-text"'
			. ' autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list"'
			. ' aria-controls="' . esc_attr( $results ) . '"'
			. ' placeholder="' . esc_attr__( 'Type to search…', 'extonify-custom-emails-per-product' ) . '"'
			. ' data-extonify-wcep-search="1" data-name="' . esc_attr( $base ) . '" />';

		echo '<ul class="extonify-wcep-results" id="' . esc_attr( $results ) . '" role="listbox" hidden="hidden"></ul>';

		echo '</fieldset>';
	}

	/**
	 * One selected target, as a labelled checkbox.
	 *
	 * @param string $name    Input name.
	 * @param int    $id      Target id.
	 * @param string $label   Human label.
	 * @param bool   $missing Whether the product or term no longer exists.
	 * @return void
	 */
	private static function chip( string $name, int $id, string $label, bool $missing ): void {
		$dom = 'extonify-wcep-chip-' . md5( $name . '|' . $id );

		echo '<li class="extonify-wcep-chip' . ( $missing ? ' extonify-wcep-chip--missing' : '' ) . '">';
		echo '<input type="checkbox" id="' . esc_attr( $dom ) . '" name="' . esc_attr( $name ) . '"'
			. ' value="' . esc_attr( (string) $id ) . '" checked="checked" />';
		echo '<label for="' . esc_attr( $dom ) . '">' . esc_html( $label );

		if ( $missing ) {
			// ⚠ SHOWN, NOT DROPPED (ADR-0017 §9). Hiding it would make the editor lie
			// about the document it is going to save, and would leave the entry
			// unremovable because the merchant cannot see it.
			echo ' <span class="extonify-wcep-missing">'
				. esc_html__( '(no longer available)', 'extonify-custom-emails-per-product' ) . '</span>';
		}

		echo '</label></li>';
	}

	/**
	 * The product-type picker: a fixed checkbox list, no search needed.
	 *
	 * @param string $side  `include` or `exclude`.
	 * @param string $label Kind label.
	 * @param array  $chosen Currently selected slugs.
	 * @return void
	 */
	private static function type_picker( string $side, string $label, array $chosen ): void {
		$base = RuleFormInput::FIELD . '[targeting][' . $side . '][types][]';

		echo '<fieldset class="extonify-wcep-picker extonify-wcep-picker--types">';
		echo '<legend>' . esc_html( $label ) . '</legend>';
		echo '<ul class="extonify-wcep-types">';

		foreach ( FieldOptions::product_types() as $slug => $name ) {
			$slug = (string) $slug;
			$dom  = 'extonify-wcep-type-' . $side . '-' . sanitize_html_class( $slug );

			echo '<li><input type="checkbox" id="' . esc_attr( $dom ) . '" name="' . esc_attr( $base ) . '"'
				. ' value="' . esc_attr( $slug ) . '"' . checked( in_array( $slug, $chosen, true ), true, false ) . ' />';
			echo '<label for="' . esc_attr( $dom ) . '">' . esc_html( (string) $name ) . '</label></li>';
		}

		echo '</ul></fieldset>';
	}

	/**
	 * The click-to-insert placeholder reference (ADR-0014 §5).
	 *
	 * ⚠ EVERY ITEM IS A `<button>`, NOT A CLICKABLE `<span>` (gate 33). A button is
	 * reachable by keyboard, announced as a control and activated by Enter and Space
	 * without a single line of JavaScript being written for any of it.
	 *
	 * @return void
	 */
	private static function placeholder_reference(): void {
		echo '<div class="extonify-wcep-placeholders" data-extonify-wcep-placeholders="1">';
		echo '<h3>' . esc_html__( 'Placeholders', 'extonify-custom-emails-per-product' ) . '</h3>';
		echo '<p class="description">'
			. esc_html__( 'Put the cursor in a field, then choose a placeholder to insert it there. Placeholders work in the subject, the heading and the body.', 'extonify-custom-emails-per-product' )
			. '</p>';

		foreach ( PlaceholderReference::categories() as $category ) {
			echo '<div class="extonify-wcep-placeholder-group">';
			echo '<h4>' . esc_html( (string) $category['title'] ) . '</h4>';

			if ( '' !== (string) $category['note'] ) {
				echo '<p class="description">' . esc_html( (string) $category['note'] ) . '</p>';
			}

			echo '<ul>';

			foreach ( $category['items'] as $item ) {
				echo '<li>';
				echo '<button type="button" class="button button-small extonify-wcep-insert"'
					. ' data-extonify-wcep-token="' . esc_attr( (string) $item['token'] ) . '">'
					. esc_html( (string) $item['token'] ) . '</button>';

				if ( '' !== (string) $item['description'] ) {
					echo ' <span class="extonify-wcep-placeholder-note">' . esc_html( (string) $item['description'] ) . '</span>';
				}

				echo '</li>';
			}

			echo '</ul></div>';
		}

		echo '</div>';
	}

	// -----------------------------------------------------------------------
	// Row primitives — every one labelled, described and escaped
	// -----------------------------------------------------------------------

	/**
	 * Open a section.
	 *
	 * ⚠ A SECTION THAT CARRIES A DESCRIPTION BECOMES A NAMED GROUP (gate 33). Some
	 * copy on this screen explains the whole section rather than any one control —
	 * the trigger note is about every trigger field at once, and repeating it on
	 * four `aria-describedby` attributes would read it out four times as the user
	 * tabs. The accessible form of "this text explains these controls" is a group
	 * with an accessible NAME and a DESCRIPTION: `role="group"` +
	 * `aria-labelledby` pointing at this section's own `<h2>` + `aria-describedby`
	 * pointing at the note. The note is emitted by the caller AFTER this tag, which
	 * is fine — an IDREF resolves across the whole document, not backwards only.
	 *
	 * @param string $id             Section id.
	 * @param string $title          Section title.
	 * @param string $description_id Id of the paragraph describing the whole
	 *                               section, or '' when the section has none.
	 * @return void
	 */
	private static function open_section( string $id, string $title, string $description_id = '' ): void {
		$heading_id = 'extonify-wcep-section-' . $id . '-title';

		echo '<div class="extonify-wcep-section" id="extonify-wcep-section-' . esc_attr( $id ) . '"';

		if ( '' !== $description_id ) {
			echo ' role="group" aria-labelledby="' . esc_attr( $heading_id ) . '"'
				. ' aria-describedby="' . esc_attr( $description_id ) . '"';
		}

		echo '>';
		echo '<h2 id="' . esc_attr( $heading_id ) . '">' . esc_html( $title ) . '</h2>';
	}

	/**
	 * Close a section.
	 *
	 * @return void
	 */
	private static function close_section(): void {
		echo '</div>';
	}

	/**
	 * One text input row.
	 *
	 * ⚠ `$insertable` IS A DECISION, NOT A DEFAULT. It emits the one attribute
	 * `assets/admin.js` reads to choose where a clicked placeholder lands, so it
	 * must be set per FIELD and never per helper. Until Part G this helper emitted
	 * it unconditionally, which made the rule NAME — a private label nothing ever
	 * renders, whose own description says "Only you see this" — an insertion target
	 * a merchant could not see while the placeholder reference was on screen. The
	 * reference's copy names three fields; exactly those three carry the attribute.
	 *
	 * @param string $key         Field key.
	 * @param string $label       Label text.
	 * @param string $value       Current value.
	 * @param string $description Description, or ''.
	 * @param bool   $invalid     Whether this field was refused.
	 * @param bool   $insertable  Whether a placeholder may be inserted into it.
	 * @return void
	 */
	private static function text_row( string $key, string $label, string $value, string $description, bool $invalid, bool $insertable = false ): void {
		$dom  = 'extonify-wcep-' . $key;
		$desc = $dom . '-description';

		echo '<div class="extonify-wcep-row' . ( $invalid ? ' extonify-wcep-row--invalid' : '' ) . '">';
		echo '<div class="extonify-wcep-label"><label for="' . esc_attr( $dom ) . '">' . esc_html( $label ) . '</label></div>';
		echo '<div class="extonify-wcep-field">';
		echo '<input type="text" class="regular-text" id="' . esc_attr( $dom ) . '"'
			. ' name="' . esc_attr( RuleFormInput::FIELD ) . '[' . esc_attr( $key ) . ']"'
			. ' value="' . esc_attr( $value ) . '"'
			. ( $insertable ? ' data-extonify-wcep-insertable="1"' : '' )
			. ( '' !== $description ? ' aria-describedby="' . esc_attr( $desc ) . '"' : '' )
			. ( $invalid ? ' aria-invalid="true"' : '' ) . ' />';

		if ( '' !== $description ) {
			echo '<p class="description" id="' . esc_attr( $desc ) . '">' . esc_html( $description ) . '</p>';
		}

		echo '</div></div>';
	}

	/**
	 * One textarea row.
	 *
	 * ⚠ `$inert` EMITS `readonly`, NEVER `disabled`, AND THE DIFFERENCE IS THE STORED
	 * VALUE. A disabled control submits nothing, so disabling these would empty the
	 * recipients document of every rule a merchant switched to insert mode — the
	 * mirror pattern `delay_row()` uses exists for exactly that reason, and cannot be
	 * reused here because a mirror carries a constant and this field carries the
	 * merchant's own text. `readonly` keeps the field in the submission, which is what
	 * makes "switch to insert, save, switch back" lossless.
	 *
	 * The marker is emitted on every row this helper draws — all of which are
	 * recipient channels — so `assets/admin.js` can flip the state on a mode change
	 * without a per-channel list to fall out of step with `RecipientsDocument`.
	 *
	 * @param string $name        The complete input name, brackets included.
	 * @param string $label       Label text.
	 * @param string $value       Current value.
	 * @param string $description Description, or ''.
	 * @param string $dom         DOM id.
	 * @param bool   $inert       Whether the current mode makes this field read-only.
	 * @return void
	 */
	private static function textarea_row( string $name, string $label, string $value, string $description, string $dom, bool $inert = false ): void {
		$desc = $dom . '-description';

		echo '<div class="extonify-wcep-row">';
		echo '<div class="extonify-wcep-label"><label for="' . esc_attr( $dom ) . '">' . esc_html( $label ) . '</label></div>';
		echo '<div class="extonify-wcep-field">';
		echo '<textarea id="' . esc_attr( $dom ) . '" rows="3" class="large-text code"'
			. ' name="' . esc_attr( $name ) . '"'
			. ' data-extonify-wcep-insert-readonly="1"' . ( $inert ? ' readonly="readonly"' : '' )
			. ( '' !== $description ? ' aria-describedby="' . esc_attr( $desc ) . '"' : '' ) . '>'
			. esc_textarea( $value ) . '</textarea>';

		if ( '' !== $description ) {
			echo '<p class="description" id="' . esc_attr( $desc ) . '">' . esc_html( $description ) . '</p>';
		}

		echo '</div></div>';
	}

	/**
	 * One select row.
	 *
	 * @param string               $key         Field key.
	 * @param string               $label       Label text.
	 * @param array<string,string> $options     Value => label.
	 * @param string               $current     Current value.
	 * @param string               $description Description, or ''.
	 * @param bool                 $invalid     Whether this field was refused.
	 * @param array<string,string> $attributes  Extra attributes.
	 * @param array<string,string> $prefix      Options prepended before the vocabulary.
	 * @return void
	 */
	private static function select_row( string $key, string $label, array $options, string $current, string $description, bool $invalid, array $attributes = array(), array $prefix = array() ): void {
		$dom  = 'extonify-wcep-' . $key;
		$desc = $dom . '-description';

		$extra = '';

		foreach ( $attributes as $name => $value ) {
			$extra .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}

		echo '<div class="extonify-wcep-row' . ( $invalid ? ' extonify-wcep-row--invalid' : '' ) . '"' . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $extra is assembled above from esc_attr()-escaped names and values.
		echo '<div class="extonify-wcep-label"><label for="' . esc_attr( $dom ) . '">' . esc_html( $label ) . '</label></div>';
		echo '<div class="extonify-wcep-field">';
		echo '<select id="' . esc_attr( $dom ) . '" name="' . esc_attr( RuleFormInput::FIELD ) . '[' . esc_attr( $key ) . ']"'
			. ( '' !== $description ? ' aria-describedby="' . esc_attr( $desc ) . '"' : '' )
			. ( $invalid ? ' aria-invalid="true"' : '' ) . '>';

		foreach ( array_merge( $prefix, $options ) as $value => $text ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $current, (string) $value, false ) . '>'
				. esc_html( (string) $text ) . '</option>';
		}

		/*
		 * ⚠ A STORED VALUE THE VOCABULARY NO LONGER HOLDS IS SHOWN AND SELECTED, so the
		 * form never silently rewrites a rule to a value nobody chose. It is disabled,
		 * so the merchant must pick a real one before the rule will save.
		 */
		if ( '' !== $current && ! array_key_exists( $current, $options ) && ! array_key_exists( $current, $prefix ) ) {
			echo '<option value="' . esc_attr( $current ) . '" selected="selected" disabled="disabled">'
				. esc_html(
					sprintf(
						/* translators: %s: the unrecognised value stored on the rule. */
						__( '%s — not available, choose another', 'extonify-custom-emails-per-product' ),
						$current
					)
				) . '</option>';
		}

		echo '</select>';

		if ( '' !== $description ) {
			echo '<p class="description" id="' . esc_attr( $desc ) . '">' . esc_html( $description ) . '</p>';
		}

		echo '</div></div>';
	}

	/**
	 * One checkbox row.
	 *
	 * @param string $key         Field key.
	 * @param string $label       Label text.
	 * @param bool   $checked     Current value.
	 * @param string $description Description, or ''.
	 * @return void
	 */
	private static function checkbox_row( string $key, string $label, bool $checked, string $description ): void {
		$dom  = 'extonify-wcep-' . $key;
		$desc = $dom . '-description';

		echo '<div class="extonify-wcep-row">';
		echo '<div class="extonify-wcep-label"></div>';
		echo '<div class="extonify-wcep-field">';
		echo '<input type="checkbox" id="' . esc_attr( $dom ) . '" value="1"'
			. ' name="' . esc_attr( RuleFormInput::FIELD ) . '[' . esc_attr( $key ) . ']"'
			. checked( $checked, true, false )
			. ( '' !== $description ? ' aria-describedby="' . esc_attr( $desc ) . '"' : '' ) . ' />';
		echo '<label for="' . esc_attr( $dom ) . '">' . esc_html( $label ) . '</label>';

		if ( '' !== $description ) {
			echo '<p class="description" id="' . esc_attr( $desc ) . '">' . esc_html( $description ) . '</p>';
		}

		echo '</div></div>';
	}
}
