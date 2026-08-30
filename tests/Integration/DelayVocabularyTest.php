<?php
/**
 * PROMPT 13A ITEM 1 — every storable delay survives the editor unchanged.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\FieldOptions;
use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Admin\RuleActions;
use Extonify\WCEP\Admin\RuleEditor;
use Extonify\WCEP\Admin\RuleFormInput;
use Extonify\WCEP\Delivery\Consolidation;
use Extonify\WCEP\Repository\DeliveryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * THE DELAY UNIT VOCABULARY MUST COVER EVERY VALUE THE COLUMN CAN HOLD.
 *
 * ⚠ THE DEFECT THIS FILE EXISTS FOR WAS SILENT AND COST A CUSTOMER AN EMAIL.
 * `delay_seconds` is an arbitrary non-negative integer;
 * `RuleFormInput::from_rule()` shows it in the largest unit that divides it exactly
 * and falls back to `seconds` when none does. While `FieldOptions::delay_units()`
 * offered only minutes, hours and days, that fallback named a unit the `<select>`
 * did not contain — so **the browser selected the first option instead**, and the
 * next save of that rule, for any reason at all, stored 90 seconds as 90 MINUTES.
 *
 * ADR-0015 §4 then re-validates a queued delivery's delay against its snapshot,
 * finds it changed, and CANCELS the delivery; ADR-0015 §1a makes that identity
 * permanently consumed. The customer's email is gone and cannot be re-sent
 * automatically.
 *
 * ⚠ THE ROUND TRIP IS DRIVEN THROUGH THE RENDERED MARKUP, NOT THROUGH
 * `RuleFormInput` DIRECTLY, AND THAT IS THE WHOLE POINT. `from_rule()` and
 * `delay_seconds()` were both already correct — `$units[$unit] ?? 1` yields a
 * multiplier of 1 for `seconds`. The break lived in the gap between the value the
 * form held and the option the browser could submit, and only a test that submits
 * what a browser would submit can see it. self::browser_submits() applies the HTML
 * rule exactly: the selected option if there is one, otherwise the first.
 */
final class DelayVocabularyTest extends ScheduledDeliveryTestCase {

	use AdminHarness;

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
			fwrite( STDERR, "\n[P13A item 1] " . $line );
		}

		$this->gate = array();
	}

	/**
	 * Every value the column can hold, including the ones no larger unit divides.
	 *
	 * @return array<string,array{0:int}>
	 */
	public function delay_provider(): array {
		return array(
			'30 seconds — half a minute'   => array( 30 ),
			'59 seconds — prime, sub-minute' => array( 59 ),
			'61 seconds — over a minute, indivisible' => array( 61 ),
			'90 seconds — a minute and a half' => array( 90 ),
			'120 seconds — exactly two minutes' => array( 120 ),
			'3600 seconds — exactly one hour' => array( 3600 ),
			'604800 seconds — exactly seven days' => array( 604800 ),
		);
	}

	/**
	 * 1a. A LOAD-EDIT-SAVE CYCLE LEAVES `delay_seconds` BYTE-IDENTICAL.
	 *
	 * The edit is the most innocent one there is — the rule's NAME — because the
	 * defect's whole character is that it is triggered by a save the merchant made
	 * for an unrelated reason.
	 *
	 * @dataProvider delay_provider
	 *
	 * @param int $seconds Stored delay.
	 * @return void
	 */
	public function test_a_stored_delay_survives_a_load_edit_save_cycle( int $seconds ) {
		$this->become_manager();
		$this->use_editor_screen();

		$rule_id = $this->make_form_shaped_rule( array( 'delay_seconds' => $seconds ) );

		$this->assertSame(
			$seconds,
			(int) $this->rules->find( $rule_id )['delay_seconds'],
			'the fixture did not store the delay, so nothing below would be proven.'
		);

		$submitted = $this->browser_submits( $rule_id );

		/*
		 * ⚠ THE PROXIMATE DEFECT, ASSERTED DIRECTLY. A `<select>` with NO selected
		 * option is one whose current value the vocabulary does not contain — the
		 * browser then submits the first option, and the rewrite follows. Asserting the
		 * submitted unit is merely "a known unit" would NOT catch this, because
		 * `minutes` is a known unit and `minutes` is exactly what the browser fell back
		 * to.
		 */
		$this->assertTrue(
			$submitted['had_selection'],
			'⚠ the editor rendered no selected delay unit for ' . $seconds . ' seconds, so a browser would '
				. 'submit the first option instead of the stored one.'
		);

		$this->assertArrayHasKey(
			$submitted['delay_unit'],
			FieldOptions::delay_units(),
			'⚠ the editor rendered a delay unit it does not offer for ' . $seconds . ' seconds.'
		);

		$post = $this->delay_post(
			$rule_id,
			array(
				'name'        => 'WCEP Delay Fixture RENAMED',
				'delay_value' => $submitted['delay_value'],
				'delay_unit'  => $submitted['delay_unit'],
			)
		);

		$this->request( array( 'page' => Menu::PAGE ), $post );

		$result = RuleActions::handle( RuleActions::ACTION_SAVE, $post, array() );

		$this->assertSame(
			RuleActions::OUTCOME_REDIRECT,
			$result['outcome'] ?? '',
			'the name-only save was not accepted: ' . wp_json_encode( $result['errors'] ?? array() )
		);

		$stored = $this->rules->find( $rule_id );

		// The unrelated edit landed …
		$this->assertSame( 'WCEP Delay Fixture RENAMED', (string) $stored['name'] );

		// … and the delay did not move one second.
		$this->assertSame(
			$seconds,
			(int) $stored['delay_seconds'],
			'⚠ a name-only save rewrote the delay: ' . $seconds . ' became ' . (int) $stored['delay_seconds'] . '.'
		);

		$this->gate[] = 'round trip: ' . $seconds . 's renders as ' . $submitted['delay_value'] . ' '
			. $submitted['delay_unit'] . ' and a name-only save stores ' . (int) $stored['delay_seconds'] . 's';
	}

	/**
	 * 1b. A QUEUED SUB-MINUTE DELIVERY SURVIVES AN UNRELATED EDIT TO ITS RULE.
	 *
	 * ⚠ THIS IS THE CONSEQUENCE, NOT A SECOND SPELLING OF 1a. The round trip above
	 * proves the stored integer is unchanged; this proves what that integer was
	 * protecting. ADR-0015 §4's phase check compares the LIVE rule's delay against
	 * the SNAPSHOT taken at scheduling time, so a rewritten delay does not merely
	 * mis-time the delivery — it cancels it, and ADR-0015 §1a consumes the identity
	 * so the rule can never fire for that order and trigger again.
	 *
	 * @return void
	 */
	public function test_a_queued_sub_minute_delivery_survives_a_name_only_edit() {
		$this->become_manager();
		$this->use_editor_screen();

		$product  = $this->make_simple_product( 'WCEP Delay Product ' . wp_generate_password( 6, false ) );
		$order    = $this->delayed_order( array( $product ) );
		$order_id = (int) $order->get_id();

		$fields = array(
			'name'           => 'WCEP Ninety Second Rule',
			'trigger_status' => 'processing',
			'match_all'      => '',
			'targeting'      => array( 'include' => array( 'products' => array( $product ) ) ),
			'content'        => '<p>DELAYED BLOCK.</p>',
		);

		$rule_id = $this->make_form_shaped_rule( array( 'delay_seconds' => 90 ), $fields );

		$this->orchestrator()->handle_status_change( $order_id, 'pending', 'processing' );

		$tombstone = $this->scheduled_tombstone( $order_id, $rule_id );

		$this->assertNotNull( $tombstone, 'the 90-second rule did not schedule.' );
		$this->assertSame( DeliveryRepository::SCHEDULED, (string) $tombstone['final_status'] );

		$delivery_id = (int) $tombstone['id'];

		// THE UNRELATED EDIT, submitted exactly as the editor renders it.
		$submitted = $this->browser_submits( $rule_id );

		$post = $this->delay_post(
			$rule_id,
			array_merge(
				$fields,
				array(
					'name'        => 'WCEP Ninety Second Rule RENAMED',
					'delay_value' => $submitted['delay_value'],
					'delay_unit'  => $submitted['delay_unit'],
				)
			)
		);

		$this->request( array( 'page' => Menu::PAGE ), $post );

		$result = RuleActions::handle( RuleActions::ACTION_SAVE, $post, array() );

		$this->assertSame(
			RuleActions::OUTCOME_REDIRECT,
			$result['outcome'] ?? '',
			'the name-only save was refused: ' . wp_json_encode( $result['errors'] ?? array() )
		);

		$this->assertSame( 90, (int) $this->rules->find( $rule_id )['delay_seconds'], 'the delay moved.' );

		// EAGER CANCELLATION DID NOT FIRE …
		$this->assertSame(
			DeliveryRepository::SCHEDULED,
			(string) $this->deliveries->find_by_id( $delivery_id )['final_status'],
			'⚠ a name-only edit cancelled a queued 90-second delivery.'
		);
		$this->assertTrue( $this->has_job( $delivery_id, $order_id ), 'the queued job was removed by a name-only edit.' );

		// … and neither did EXECUTION-TIME re-validation: the delivery still sends.
		$this->run_job( $delivery_id, $order_id );

		$this->assertSame(
			'sent',
			(string) $this->deliveries->find_by_id( $delivery_id )['final_status'],
			'⚠ the queued 90-second delivery was cancelled at execution instead of sent.'
		);
		$this->assertMailCount( 1, 'the 90-second delivery did not send exactly one message.' );

		$this->gate[] = 'a queued 90s delivery survives a name-only edit: still scheduled, job intact, '
			. 'and it sends when the job runs';
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Pretend WordPress is rendering this plugin's screen.
	 *
	 * The same two lines `AdminTestCase::use_our_screen()` runs, repeated here
	 * because this file needs the SCHEDULED base class and PHP has one parent.
	 *
	 * @return void
	 */
	private function use_editor_screen(): void {
		set_current_screen( 'woocommerce_page_' . Menu::PAGE );
	}

	/**
	 * What a browser would submit for the delay, read off the rendered editor.
	 *
	 * ⚠ IT APPLIES THE HTML RULE, NOT A CONVENIENT ONE. A `<select>` with no
	 * `selected` option submits its FIRST option — that is the behaviour the defect
	 * rode in on, so the helper reproduces it rather than reading the form object.
	 *
	 * @param int $rule_id Rule to open in the editor.
	 * @return array{delay_value:int, delay_unit:string, had_selection:bool}
	 */
	private function browser_submits( int $rule_id ): array {
		$this->request(
			array(
				'page'   => Menu::PAGE,
				'action' => Menu::ACTION_EDIT,
				'rule'   => $rule_id,
			)
		);

		$markup = $this->capture(
			static function () {
				RuleEditor::render();
			}
		);

		$dom  = new \DOMDocument();
		$path = null;

		$previous = libxml_use_internal_errors( true );

		try {
			$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $markup );
			libxml_clear_errors();
		} finally {
			libxml_use_internal_errors( $previous );
		}

		$path = new \DOMXPath( $dom );

		$number = $path->query( '//input[@id="extonify-wcep-delay-value"]' )->item( 0 );

		$this->assertInstanceOf( \DOMElement::class, $number, 'the delay number input is not on the editor.' );

		$options = $path->query( '//select[@id="extonify-wcep-delay-unit"]/option' );

		$this->assertGreaterThan( 0, $options->length, 'the delay unit select has no options.' );

		$first    = '';
		$selected = '';

		foreach ( $options as $index => $option ) {
			$value = (string) $option->getAttribute( 'value' );

			if ( 0 === $index ) {
				$first = $value;
			}

			if ( $option->hasAttribute( 'selected' ) ) {
				$selected = $value;
			}
		}

		return array(
			'delay_value'   => (int) $number->getAttribute( 'value' ),
			'delay_unit'    => '' !== $selected ? $selected : $first,
			'had_selection' => '' !== $selected,
		);
	}

	/**
	 * The field values one complete editor submission carries.
	 *
	 * ⚠ ONE DEFINITION, USED BOTH TO BUILD THE FIXTURE AND TO SUBMIT THE EDIT, so
	 * "the merchant only changed the name" is true of the DIFF and not merely of the
	 * intention. A fixture built from different columns would make every save look
	 * like a multi-field edit, and the delay's survival would prove less than it
	 * appears to.
	 *
	 * @param array $overrides Field overrides.
	 * @return array
	 */
	private function form_fields( array $overrides = array() ): array {
		return array_merge(
			array(
				'name'            => 'WCEP Delay Fixture',
				'status'          => 'active',
				'trigger_type'    => 'status',
				'trigger_status'  => 'completed',
				'delivery_mode'   => 'separate',
				'native_email_id' => '',
				'insert_position' => 'after_order_table',
				'delay_value'     => 0,
				'delay_unit'      => 'minutes',
				'consolidation'   => Consolidation::NONE,
				'match_all'       => '1',
				'subject'         => 'Subject',
				'heading'         => 'Heading',
				'content'         => '<p>Body</p>',
				'priority'        => 10,
				'recipients'      => array( 'to' => 'customer' ),
			),
			$overrides
		);
	}

	/**
	 * A stored rule whose every column is exactly what self::form_fields() saves.
	 *
	 * ⚠ BUILT THROUGH `RuleFormInput` ITSELF rather than by hand. The row is then
	 * byte-identical to one the editor would have written, which is what makes the
	 * later re-submission a genuine name-only edit.
	 *
	 * @param array $columns Column overrides applied after the form conversion.
	 * @param array $fields  Field overrides applied BEFORE it.
	 * @return int Rule id.
	 */
	private function make_form_shaped_rule( array $columns = array(), array $fields = array() ): int {
		$write = RuleFormInput::from_post(
			array( RuleFormInput::FIELD => $this->form_fields( $fields ) )
		)->to_write();

		$rule_id = $this->rules->insert( array_merge( $write, $columns ) );

		$this->assertGreaterThan( 0, $rule_id, 'could not create the delay fixture.' );

		return $this->track_rule( $rule_id );
	}

	/**
	 * A complete, valid editor submission for one existing rule.
	 *
	 * Kept here rather than reused from `AdminTestCase`, because this file needs the
	 * SCHEDULED harness and PHP has one parent.
	 *
	 * @param int   $rule_id   Rule being edited.
	 * @param array $overrides Field overrides.
	 * @return array A `$_POST` array.
	 */
	private function delay_post( int $rule_id, array $overrides ): array {
		return array(
			'action'                 => RuleActions::ACTION_SAVE,
			'rule'                   => $rule_id,
			RuleActions::NONCE_FIELD => wp_create_nonce( RuleActions::NONCE_SAVE ),
			RuleFormInput::FIELD     => $this->form_fields( $overrides ),
		);
	}
}
