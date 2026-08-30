<?php
/**
 * ADR-0017 §5a — the editor never implies that an insert rule has an envelope.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\FieldOptions;
use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Admin\RuleActions;
use Extonify\WCEP\Admin\RuleEditor;
use Extonify\WCEP\Admin\RuleFormInput;
use Extonify\WCEP\Admin\Warnings;
use Extonify\WCEP\Delivery\Consolidation;
use Extonify\WCEP\Domain\RecipientsDocument;
use Extonify\WCEP\Plugin;

/**
 * An insert rule contributes BODY CONTENT ONLY, and the editor must say so.
 *
 * ⚠ SEVERITY: TIER 1, AND NOT BECAUSE ANYTHING IS DELIVERED WRONGLY. The delivery
 * code is correct — WooCommerce owns the recipient, the subject, the heading and the
 * send time of an inserted message (ADR-0013 §2), and
 * `RuleRepository::find_active_for_native_email()` selects on neither recipients nor
 * trigger. What was wrong was the DESCRIPTION. The editor rendered "Who receives it"
 * as a live choice with no mode guard and told the merchant *"A rule with no 'To'
 * recipient sends nothing"*, so a merchant who set **To: admin** on an insert rule
 * could reasonably conclude the content went to the administrator alone — when it is
 * injected into WooCommerce's email to the CUSTOMER. A false belief about who reads
 * what the merchant wrote is this project's Tier 1 shape in its more serious form.
 *
 * ⚠ AND THE FIX MUST NOT COST THE MERCHANT THEIR DATA. Insert mode neither reads nor
 * rewrites the recipients document, so it has to survive every save byte-for-byte.
 * `test_switching_to_insert_and_saving_again_preserves_the_recipients_and_subject()`
 * submits what the RENDERED FORM would actually submit — a disabled control
 * contributes nothing to a POST, which is the whole reason `delay_row()` carries a
 * hidden mirror — and asserts the STORED ROW rather than the re-rendered form.
 */
final class InsertModeEditorTest extends AdminTestCase {

	/**
	 * A WooCommerce email id the repository accepts for an insert rule.
	 */
	const NATIVE_EMAIL = 'customer_processing_order';

	/**
	 * Sentences that assert the customer is the recipient, as a CATEGORY.
	 *
	 * ⚠ THE CATEGORICAL FORM, NOT THE WORD "CUSTOMER". A separate rule addressed to
	 * `customer` really does go to the customer and the editor may say so; the
	 * recipient-token help legitimately reads *Write "customer" for the billing
	 * address*. What may not survive is a claim that holds for EVERY rule, because a
	 * whole class of ordinary rules — insert rules on `new_order` and its siblings —
	 * makes it false.
	 */
	const CATEGORICAL_RECIPIENT_CLAIMS = array(
		'the customer already receives',
		'the customer receives',
		'the customer will receive',
		'the customer may receive',
		'the customer sees',
		'the customer should see',
		'never goes to the customer',
		'emailed to the customer',
		'to the customer again',
		'normally the customer',
	);

	/**
	 * Gate lines.
	 *
	 * @var string[]
	 */
	private $gate = array();

	/**
	 * Print them.
	 *
	 * @after
	 * @return void
	 */
	protected function report_gate() {
		foreach ( $this->gate as $line ) {
			fwrite( STDERR, "\n[13C-K] " . $line );
		}

		$this->gate = array();
	}

	// -----------------------------------------------------------------------
	// 1. The recipients are not an active choice for an insert rule
	// -----------------------------------------------------------------------

	/**
	 * AN INSERT RULE OFFERS NO ACTIVE RECIPIENT CONTROL, AND A SEPARATE RULE STILL
	 * DOES.
	 *
	 * ⚠ AND NEITHER IS `disabled`. A disabled control is absent from the submission,
	 * so disabling these would empty the recipients document of every insert rule on
	 * its next save — the assertion below is the one that keeps the fix from becoming
	 * the defect it replaces.
	 *
	 * @return void
	 */
	public function test_an_insert_rule_offers_no_active_recipient_control() {
		$this->become_manager();
		$this->use_our_screen();

		$separate = $this->editor_dom( $this->make_rule( 'separate' ) );
		$insert   = $this->editor_dom( $this->make_rule( 'insert' ) );

		$checked = 0;

		foreach ( RecipientsDocument::CHANNELS as $channel ) {
			$id = 'extonify-wcep-recipients-' . $channel;

			$live  = $this->element( $separate, '//textarea[@id="' . $id . '"]' );
			$inert = $this->element( $insert, '//textarea[@id="' . $id . '"]' );

			$this->assertFalse(
				$live->hasAttribute( 'readonly' ),
				'⚠ a SEPARATE rule cannot edit its own ' . $channel . ' recipients.'
			);

			$this->assertTrue(
				$inert->hasAttribute( 'readonly' ),
				'⚠ an INSERT rule presents ' . $channel . ' as a live choice — it has no envelope of its own.'
			);

			// The preservation half: read-only submits, disabled does not.
			foreach ( array( $live, $inert ) as $control ) {
				$this->assertFalse(
					$control->hasAttribute( 'disabled' ),
					'⚠ a disabled recipients box submits NOTHING and would empty the stored document.'
				);
			}

			++$checked;
		}

		// The false claim itself: the "To" advice is meaningless for a rule that
		// sends nothing either way, so it must name the mode it belongs to.
		$advice = $this->element( $insert, '//p[@id="extonify-wcep-recipients-to-description"]' );

		$this->assertStringContainsString(
			'separate email',
			$advice->textContent,
			'⚠ the "To" advice still reads as if it applied to every rule.'
		);

		// And the section says what actually happens instead.
		$note = $this->element( $insert, '//p[@id="extonify-wcep-recipients-note"]' );

		$this->assertStringContainsString(
			'WooCommerce is already sending',
			$note->textContent,
			'⚠ nothing on the screen names where an inserted rule\'s content actually goes.'
		);

		$this->gate[] = 'recipients: ' . $checked . ' channels — editable on a separate rule, read-only on an '
			. 'insert rule, and never disabled (a disabled box submits nothing)';
	}

	// -----------------------------------------------------------------------
	// 1b. An insert rule may target an ADMIN email, and the copy must allow it
	// -----------------------------------------------------------------------

	/**
	 * THE EDITOR DESCRIBES AN INSERT RULE WITHOUT CLAIMING THE CUSTOMER RECEIVES IT.
	 *
	 * ⚠ THE ROOT INACCURACY PART L FIXED WAS AT THE SOURCE, NOT IN THE WORDING.
	 * `FieldOptions::native_emails()` iterates `WC()->mailer()->get_emails()` and
	 * excludes NOTHING, so `new_order`, `cancelled_order`, `failed_order` and
	 * `admin_payment_gateway_enabled` are all valid insert targets on a stock store.
	 * Copy reading *"an email the customer already receives"* was therefore false for
	 * a whole class of perfectly ordinary rules — not loosely worded, wrong.
	 *
	 * ⚠ THIS IS A SWEEP, NOT A STRING ASSERTION, AND THAT IS THE POINT. Asserting one
	 * corrected sentence exists would pass while a second screen kept the old claim.
	 * The whole rendered editor is searched for the CATEGORICAL form — "the customer
	 * receives", "the customer sees", "never goes to the customer" — so a future edit
	 * that reintroduces the assumption anywhere on this screen fails here, whichever
	 * file it lands in. Naming the customer where the customer genuinely IS the
	 * recipient stays legal, which is why the patterns are categorical claims rather
	 * than the word "customer".
	 *
	 * @return void
	 */
	public function test_an_insert_rule_on_an_admin_email_is_described_without_claiming_the_customer_receives_it() {
		$this->become_manager();
		$this->use_our_screen();

		$admin_email = $this->an_admin_addressed_native_email();

		$rule_id = $this->make_rule( 'insert', $admin_email );
		$markup  = $this->editor_markup( $rule_id );

		// 1. The screen names the email it targets, by that email's OWN title — which
		//    is what makes the general copy able to stay recipient-neutral.
		$title = (string) ( FieldOptions::native_emails()[ $admin_email ] ?? '' );

		$this->assertNotSame( '', $title, 'WooCommerce offers no title for ' . $admin_email . '.' );

		$this->assertStringContainsString(
			$title,
			$markup,
			'⚠ the editor does not show which WooCommerce email this rule targets.'
		);

		// 2. And nothing on it asserts the customer as the recipient.
		$found = array();

		foreach ( self::CATEGORICAL_RECIPIENT_CLAIMS as $claim ) {
			if ( false !== stripos( $markup, $claim ) ) {
				$found[] = $claim;
			}
		}

		$this->assertSame(
			array(),
			$found,
			'⚠ the editor still claims the customer is the recipient, on a rule whose target is an ADMIN email: '
			. implode( ' | ', $found )
		);

		$this->gate[] = 'admin insert target: ' . $admin_email . ' ("' . $title . '") is describable — the screen names '
			. 'the email, and 0 of ' . count( self::CATEGORICAL_RECIPIENT_CLAIMS ) . ' categorical customer-recipient '
			. 'claims survive anywhere in the rendered editor';
	}

	// -----------------------------------------------------------------------
	// 2. The subject is scoped exactly as the heading already was
	// -----------------------------------------------------------------------

	/**
	 * THE SUBJECT AND THE HEADING ARE A MATCHED PAIR AND MUST CARRY THE SAME SCOPE.
	 *
	 * ⚠ THE TELL THAT HID THIS DEFECT FOR TEN PROMPTS: the heading's description was
	 * written accurately — *"Ignored when the content is added to a WooCommerce
	 * email"* — and the subject's said *"The subject line the customer sees"*, which
	 * is flatly false for an insert rule, whose customer sees WooCommerce's subject.
	 * One of a matched pair being right is exactly what makes the other read as
	 * deliberate.
	 *
	 * @return void
	 */
	public function test_the_subject_carries_the_same_mode_scope_as_the_heading() {
		$this->become_manager();
		$this->use_our_screen();

		$dom = $this->editor_dom( $this->make_rule( 'insert' ) );

		$subject = $this->element( $dom, '//p[@id="extonify-wcep-subject-description"]' )->textContent;
		$heading = $this->element( $dom, '//p[@id="extonify-wcep-heading-description"]' )->textContent;

		$scope = 'Ignored when the content is added to a WooCommerce email';

		foreach ( array( 'subject' => $subject, 'heading' => $heading ) as $field => $text ) {
			$this->assertStringContainsString(
				$scope,
				$text,
				'⚠ the ' . $field . ' description does not say which mode it applies to.'
			);

			$this->assertStringContainsString(
				'separate email',
				$text,
				'⚠ the ' . $field . ' description does not name the mode it DOES apply to.'
			);
		}

		$this->assertStringNotContainsString(
			'The subject line the customer sees',
			$subject,
			'⚠ the subject still claims the customer reads it — WooCommerce\'s subject is used in insert mode.'
		);

		$this->gate[] = 'content scope: subject and heading both carry "' . $scope . '"';
	}

	// -----------------------------------------------------------------------
	// 3. Every warning, asserted against BOTH modes
	// -----------------------------------------------------------------------

	/**
	 * EVERY WARNING IS SCOPED TO THE MODES IT CAN ACTUALLY DESCRIBE (ADR-0017 §5).
	 *
	 * ⚠ THE TABLE IS THE TEST. `Warnings` had ONE mode guard — W4, guarded from the
	 * day it was written — and the other three fired on rules they cannot apply to.
	 * Enumerating each condition against BOTH modes is what turns "this was decided
	 * once" into "this is decided for every warning, and a new one cannot skip it".
	 *
	 * @dataProvider warning_scope_provider
	 *
	 * @param string   $case      What is being configured.
	 * @param array    $overrides Form overrides on top of the quiet baseline.
	 * @param string[] $separate  Warning ids expected in separate mode, in order.
	 * @param string[] $insert    Warning ids expected in insert mode, in order.
	 * @return void
	 */
	public function test_every_warning_is_scoped_by_delivery_mode( string $case, array $overrides, array $separate, array $insert ) {
		$this->become_manager();

		$this->assertSame(
			$separate,
			$this->warning_ids( 'separate', $overrides ),
			'⚠ separate mode, "' . $case . '": wrong warnings.'
		);

		$this->assertSame(
			$insert,
			$this->warning_ids( 'insert', $overrides ),
			'⚠ insert mode, "' . $case . '": a warning fired that cannot apply, or one that must did not.'
		);

		$this->gate[] = sprintf(
			'warning scope | %-38s | separate: %-46s | insert: %s',
			$case,
			array() === $separate ? '—' : implode( ' + ', $separate ),
			array() === $insert ? '—' : implode( ' + ', $insert )
		);
	}

	/**
	 * The scope table: one row per condition, both modes on the row.
	 *
	 * The baseline is deliberately QUIET — every content field filled, one product
	 * placeholder-free body, `consolidation = none` — so each row's expectation is
	 * caused by its own override and nothing else. `match_all` is set throughout,
	 * which is what makes W1's "can match more than one product" true.
	 *
	 * @return array[]
	 */
	public static function warning_scope_provider(): array {
		$w1 = 'singular_placeholder_multi_match';
		$w2 = 'per_product_cap';
		$w3 = 'empty_content_field';
		$w4 = 'insert_mode_constraints';

		return array(
			'nothing to say' => array(
				'a fully filled rule',
				array(),
				array(),
				array( $w4 ),
			),
			'W1 in the subject' => array(
				'{product_name} in the subject',
				array( 'subject' => 'Your {product_name} has shipped' ),
				array( $w1 ),
				array( $w4 ),
			),
			'W1 in the heading' => array(
				'{product_name} in the heading',
				array( 'heading' => 'About your {product_name}' ),
				array( $w1 ),
				array( $w4 ),
			),
			'W1 in the body' => array(
				'{product_name} in the body',
				array( 'content' => '<p>About your {product_name}.</p>' ),
				array( $w1 ),
				array( $w1, $w4 ),
			),
			'W2' => array(
				'one email per matched product',
				array( 'consolidation' => Consolidation::PER_PRODUCT ),
				array( $w2 ),
				array( $w4 ),
			),
			'W3 subject' => array(
				'an empty subject',
				array( 'subject' => '' ),
				array( $w3 ),
				array( $w4 ),
			),
			'W3 heading' => array(
				'an empty heading',
				array( 'heading' => '' ),
				array( $w3 ),
				array( $w4 ),
			),
			'W3 body' => array(
				'an empty body',
				array( 'content' => '' ),
				array( $w3 ),
				array( $w3, $w4 ),
			),
		);
	}

	// -----------------------------------------------------------------------
	// 4. Switching mode does not cost the merchant their recipients or subject
	// -----------------------------------------------------------------------

	/**
	 * SWITCHING A RULE FROM SEPARATE TO INSERT AND SAVING PRESERVES ITS STORED
	 * RECIPIENTS AND SUBJECT — asserted on the ROW, not on the re-rendered form.
	 *
	 * ⚠ THE SECOND SAVE IS THE ONE THAT MATTERS. The first save is made from a form
	 * rendered in SEPARATE mode, where the boxes were live whatever mechanism is
	 * used. The second is made from a form rendered in INSERT mode, where they are
	 * inert — and that is precisely where `disabled` would have silently emptied the
	 * document, because a disabled control is absent from the POST.
	 *
	 * ⚠ THE POST IS HARVESTED FROM THE RENDERED MARKUP, NOT HAND-WRITTEN, for the
	 * reason `DelayVocabularyTest::browser_submits()` gives: a hand-written array
	 * asserts what the test author believes the form contains, and the defect being
	 * guarded against lives in the difference.
	 *
	 * @return void
	 */
	public function test_switching_to_insert_and_saving_again_preserves_the_recipients_and_subject() {
		$this->become_manager();
		$this->use_our_screen();

		$rule_id = $this->make_rule( 'separate' );

		$expected_recipients = array(
			'to'  => array( 'customer' ),
			'cc'  => array( 'warehouse@example.test' ),
			'bcc' => array( 'admin', 'audit@example.test' ),
		);

		// 1. The merchant switches the mode and saves.
		$this->save_rendered_form(
			$rule_id,
			array(
				'delivery_mode'   => 'insert',
				'native_email_id' => self::NATIVE_EMAIL,
			)
		);

		$row = $this->stored( $rule_id );

		$this->assertSame( 'insert', $row['delivery_mode'], 'the mode change was not saved.' );
		$this->assertSame( $expected_recipients, $row['recipients'], '⚠ switching to insert mode dropped the recipients.' );
		$this->assertSame( 'Your order', $row['subject'], '⚠ switching to insert mode dropped the subject.' );

		// 2. And an ordinary edit made from the INSERT-mode form — where the boxes
		//    are inert — leaves both alone.
		$this->save_rendered_form( $rule_id, array( 'name' => 'WCEP Insert Rule, renamed' ) );

		$row = $this->stored( $rule_id );

		$this->assertSame( 'WCEP Insert Rule, renamed', $row['name'], 'the second save did not land.' );
		$this->assertSame( 'insert', $row['delivery_mode'], 'the second save changed the mode.' );
		$this->assertSame(
			$expected_recipients,
			$row['recipients'],
			'⚠ saving an insert rule from its own editor emptied the recipients — the boxes are not submitting.'
		);
		$this->assertSame( 'Your order', $row['subject'], '⚠ saving an insert rule from its own editor emptied the subject.' );

		$this->gate[] = 'preservation: recipients (to/cc/bcc) and subject survive separate → insert and a further '
			. 'save from the insert-mode form — mechanism: read-only textareas, which submit; not disabled + mirror, '
			. 'which could only carry the value the page was rendered with';
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * The warning ids one configuration produces in one mode.
	 *
	 * @param string $mode      `separate` or `insert`.
	 * @param array  $overrides Overrides on the quiet baseline.
	 * @return string[]
	 */
	private function warning_ids( string $mode, array $overrides ): array {
		$fields = array_merge(
			array(
				'name'            => 'WCEP Warning Fixture',
				'status'          => 'inactive',
				'trigger_type'    => 'status',
				'trigger_status'  => 'completed',
				'delivery_mode'   => $mode,
				'native_email_id' => 'insert' === $mode ? self::NATIVE_EMAIL : '',
				'insert_position' => 'after_order_table',
				'delay_value'     => 0,
				'delay_unit'      => 'minutes',
				'consolidation'   => Consolidation::NONE,
				'match_all'       => '1',
				'subject'         => 'Your order',
				'heading'         => 'Thank you',
				'content'         => '<p>Body</p>',
				'priority'        => 10,
				'recipients'      => array( 'to' => 'customer' ),
			),
			$overrides
		);

		$form = RuleFormInput::from_post( array( RuleFormInput::FIELD => $fields ) );

		return array_map(
			static function ( $warning ) {
				return (string) $warning['id'];
			},
			Warnings::for_form( $form )
		);
	}

	/**
	 * A stored rule in one mode, with all three recipient channels filled.
	 *
	 * @param string $mode   `separate` or `insert`.
	 * @param string $target `native_email_id` for an insert rule; '' takes the default.
	 * @return int
	 */
	private function make_rule( string $mode, string $target = '' ): int {
		$rule_id = Plugin::instance()->rules()->insert(
			array(
				'name'            => 'WCEP Insert Rule',
				'status'          => 'active',
				'trigger_type'    => 'status',
				'trigger_value'   => 'completed',
				'delivery_mode'   => $mode,
				'native_email_id' => 'insert' === $mode ? ( '' !== $target ? $target : self::NATIVE_EMAIL ) : '',
				'insert_position' => 'after_order_table',
				'targeting'       => array( 'include' => array( 'products' => array( 12 ) ) ),
				'recipients'      => array(
					'to'  => array( 'customer' ),
					'cc'  => array( 'warehouse@example.test' ),
					'bcc' => array( 'admin', 'audit@example.test' ),
				),
				'subject'         => 'Your order',
				'heading'         => 'Thank you',
				'content'         => '<p>Body</p>',
			)
		);

		$this->assertGreaterThan( 0, $rule_id, 'Could not create the ' . $mode . ' rule fixture.' );

		return $this->track_rule( $rule_id );
	}

	/**
	 * A registered WooCommerce email that is addressed to the STORE, not the customer.
	 *
	 * ⚠ READ FROM THE LIVE MAILER, NOT HARD-CODED. WooCommerce has renamed these ids
	 * across versions — the admin new-order mail is `new_order` on the bundled 11.0.1,
	 * not `admin_new_order` — and a test pinned to a literal that this store does not
	 * register would skip past the very case it exists to cover. The candidates are
	 * tried in order and the first one the editor actually offers is used.
	 *
	 * @return string
	 */
	private function an_admin_addressed_native_email(): string {
		$offered = FieldOptions::native_emails();

		foreach ( array( 'new_order', 'cancelled_order', 'failed_order', 'admin_payment_gateway_enabled' ) as $id ) {
			if ( isset( $offered[ $id ] ) ) {
				return $id;
			}
		}

		$this->fail( '⚠ this store registers no admin-addressed WooCommerce email, so the case cannot be covered.' );
	}

	/**
	 * One hydrated stored rule.
	 *
	 * @param int $rule_id Rule id.
	 * @return array
	 */
	private function stored( int $rule_id ): array {
		$rule = Plugin::instance()->rules()->find( $rule_id );

		$this->assertIsArray( $rule, 'the rule is no longer stored.' );

		return $rule;
	}

	/**
	 * The rule editor's markup for one rule, parsed.
	 *
	 * @param int $rule_id Rule to open.
	 * @return \DOMDocument
	 */
	private function editor_dom( int $rule_id ): \DOMDocument {
		return self::parse( $this->editor_markup( $rule_id ) );
	}

	/**
	 * The rule editor's markup for one rule.
	 *
	 * @param int $rule_id Rule to open.
	 * @return string
	 */
	private function editor_markup( int $rule_id ): string {
		$this->request(
			array(
				'page'   => Menu::PAGE,
				'action' => Menu::ACTION_EDIT,
				'rule'   => $rule_id,
			)
		);

		return $this->capture(
			static function () {
				RuleEditor::render();
			}
		);
	}

	/**
	 * Submit the editor exactly as a browser would, with a few fields changed.
	 *
	 * ⚠ EVERY CONTROL IS READ OFF THE RENDERED FORM AND THE HTML RULES ARE APPLIED,
	 * not a convenient approximation: a `disabled` control is omitted (that is the
	 * whole point of the exercise), an unchecked box is omitted, a `<select>` with no
	 * `selected` option submits its FIRST option, and a `<textarea>` submits its
	 * text. The nonce and the hidden `action`/`rule` fields come from the form too,
	 * so nothing is supplied that the merchant's browser would not have sent.
	 *
	 * @param int   $rule_id Rule being edited.
	 * @param array $changes Field overrides inside `RuleFormInput::FIELD`.
	 * @return void
	 */
	private function save_rendered_form( int $rule_id, array $changes ): void {
		$dom  = self::parse( $this->editor_markup( $rule_id ) );
		$path = new \DOMXPath( $dom );
		$post = array();

		foreach ( array( 'input', 'select', 'textarea' ) as $tag ) {
			foreach ( $path->query( '//' . $tag ) as $control ) {
				$name = (string) $control->getAttribute( 'name' );

				if ( ! $this->is_ours( $name ) || $control->hasAttribute( 'disabled' ) ) {
					continue;
				}

				$value = $this->submitted_value( $tag, $control );

				if ( null === $value ) {
					continue;
				}

				self::assign( $post, $name, $value );
			}
		}

		$this->assertArrayHasKey( RuleFormInput::FIELD, $post, 'the rendered form submitted no fields at all.' );

		$post[ RuleFormInput::FIELD ] = array_merge( $post[ RuleFormInput::FIELD ], $changes );

		$this->request( array( 'page' => Menu::PAGE ), $post );

		$result = RuleActions::handle( RuleActions::ACTION_SAVE, $post, array() );

		$this->assertSame(
			RuleActions::OUTCOME_REDIRECT,
			$result['outcome'] ?? '',
			'⚠ the save was not accepted: ' . wp_json_encode( $result )
		);
	}

	/**
	 * Whether a control name belongs to this form rather than to WordPress.
	 *
	 * @param string $name The `name` attribute.
	 * @return bool
	 */
	private function is_ours( string $name ): bool {
		if ( '' === $name ) {
			return false;
		}

		return 0 === strpos( $name, RuleFormInput::FIELD )
			|| in_array( $name, array( 'action', 'rule', RuleActions::NONCE_FIELD, '_wp_http_referer' ), true );
	}

	/**
	 * What one control contributes to the submission, or null when it contributes
	 * nothing.
	 *
	 * @param string      $tag     Tag name.
	 * @param \DOMElement $control The control.
	 * @return string|null
	 */
	private function submitted_value( string $tag, \DOMElement $control ) {
		if ( 'textarea' === $tag ) {
			return $control->textContent;
		}

		if ( 'select' === $tag ) {
			$first    = null;
			$selected = null;

			foreach ( $control->getElementsByTagName( 'option' ) as $option ) {
				if ( $option->hasAttribute( 'disabled' ) ) {
					continue;
				}

				$value = (string) $option->getAttribute( 'value' );

				if ( null === $first ) {
					$first = $value;
				}

				if ( $option->hasAttribute( 'selected' ) ) {
					$selected = $value;
				}
			}

			return null !== $selected ? $selected : $first;
		}

		$type = strtolower( (string) $control->getAttribute( 'type' ) );

		if ( in_array( $type, array( 'checkbox', 'radio' ), true ) && ! $control->hasAttribute( 'checked' ) ) {
			return null;
		}

		if ( 'submit' === $type || 'button' === $type ) {
			return null;
		}

		return (string) $control->getAttribute( 'value' );
	}

	/**
	 * Write one `a[b][c][]`-shaped control name into a nested array, the way PHP
	 * builds `$_POST`.
	 *
	 * @param array  $target Array being built, by reference.
	 * @param string $name   The `name` attribute.
	 * @param string $value  Submitted value.
	 * @return void
	 */
	private static function assign( array &$target, string $name, string $value ): void {
		if ( ! preg_match( '/^([^\[\]]+)((?:\[[^\[\]]*\])*)$/', $name, $matches ) ) {
			return;
		}

		$keys = array( $matches[1] );

		if ( '' !== $matches[2] ) {
			preg_match_all( '/\[([^\[\]]*)\]/', $matches[2], $parts );

			foreach ( $parts[1] as $part ) {
				$keys[] = $part;
			}
		}

		$last = array_pop( $keys );
		$ref  = &$target;

		foreach ( $keys as $key ) {
			if ( ! isset( $ref[ $key ] ) || ! is_array( $ref[ $key ] ) ) {
				$ref[ $key ] = array();
			}

			$ref = &$ref[ $key ];
		}

		if ( '' === $last ) {
			$ref[] = $value;
			return;
		}

		$ref[ $last ] = $value;
	}

	/**
	 * The one element an XPath must match.
	 *
	 * @param \DOMDocument $dom   Parsed markup.
	 * @param string       $query XPath.
	 * @return \DOMElement
	 */
	private function element( \DOMDocument $dom, string $query ): \DOMElement {
		$node = ( new \DOMXPath( $dom ) )->query( $query )->item( 0 );

		$this->assertInstanceOf( \DOMElement::class, $node, '⚠ not on the editor: ' . $query );

		return $node;
	}

	/**
	 * Parse rendered markup without letting HTML5 attributes raise warnings.
	 *
	 * @param string $markup Rendered HTML.
	 * @return \DOMDocument
	 */
	private static function parse( string $markup ): \DOMDocument {
		$dom = new \DOMDocument();

		$previous = libxml_use_internal_errors( true );

		$dom->loadHTML( '<?xml encoding="utf-8" ?><body>' . $markup . '</body>' );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $dom;
	}
}
