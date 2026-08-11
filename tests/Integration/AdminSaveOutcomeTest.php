<?php
/**
 * GATE 30 — no refused write reports success, and every refusal names its field.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Admin\Notices;
use Extonify\WCEP\Admin\RuleActions;
use Extonify\WCEP\Admin\RuleEditor;
use Extonify\WCEP\Delivery\Consolidation;
use Extonify\WCEP\Plugin;
use Extonify\WCEP\Repository\RuleRepository;

/**
 * The save-outcome gate.
 *
 * ⚠ A GENERIC "COULD NOT SAVE" DISCARDS A SPECIFIC MEANING THE STORAGE LAYER HAD.
 * The Prompt 7A backlog records exactly that, and ADR-0017 §3 is the answer: the
 * repository's return value is the outcome, `explain_refusal()` says which field and
 * why, the form comes back with the merchant's own input, and there is no redirect
 * and no success notice.
 *
 * ⚠ AND THE STORED ROW MUST BE BYTE-IDENTICAL, compared as raw columns rather than
 * as a hydrated row: a hydrated comparison decodes the JSON columns and could not
 * see a re-encoded document, and `revision` and `updated_at` are precisely the
 * columns a wrongly-accepted write would move.
 */
final class AdminSaveOutcomeTest extends AdminTestCase {

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
			fwrite( STDERR, "\n[P9 gate 30] " . $line );
		}

		$this->gate = array();
	}

	/**
	 * GATE 30a. EACH VALIDATED COLUMN, REFUSED ON AN **UPDATE**: the form comes back
	 *           intact, the field is named, no success notice appears, and the stored
	 *           row is byte-identical.
	 *
	 * @dataProvider refusal_provider
	 *
	 * @param string $field     The column that must be named.
	 * @param string $code      The refusal code that must be reported.
	 * @param array  $overrides Form overrides that produce the refusal.
	 * @param string $visible   A submitted value that must survive into the re-render.
	 * @return void
	 */
	public function test_a_refused_update_keeps_the_input_names_the_field_and_changes_nothing( string $field, string $code, array $overrides, string $visible ) {
		$this->become_manager();
		$this->use_our_screen();

		$rule_id = $this->make_rule();
		$before  = $this->raw_rule( $rule_id );

		$this->assertNotSame( array(), $before, 'the fixture was not stored.' );

		$post = $this->valid_post(
			array_merge( array( 'name' => $visible ), $overrides ),
			$rule_id
		);

		$this->request( array( 'page' => Menu::PAGE ), $post );

		$result = RuleActions::handle( RuleActions::ACTION_SAVE, $post, array() );

		// 1. THE OUTCOME IS A REFUSAL — not a redirect, which is what a success is.
		$this->assertSame(
			RuleActions::OUTCOME_REFUSED,
			$result['outcome'] ?? '',
			'⚠ a write the repository refused was reported as a success for ' . $field . '.'
		);

		// 2. THE SPECIFIC FIELD IS NAMED, with the reason.
		$this->assertArrayHasKey(
			$field,
			(array) ( $result['errors'] ?? array() ),
			'⚠ the refusal did not name ' . $field . ': ' . wp_json_encode( $result['errors'] ?? array() )
		);

		$this->assertSame( $code, $result['errors'][ $field ], 'the refusal reported the wrong reason for ' . $field . '.' );

		// 3. THE STORED ROW IS BYTE-IDENTICAL — including revision and updated_at.
		$this->assertSame(
			$before,
			$this->raw_rule( $rule_id ),
			'⚠ a refused write changed the stored row for ' . $field . '.'
		);

		// 4. THE RE-RENDER KEEPS THE MERCHANT'S INPUT, names the field, and shows NO
		//    success notice — asserted on the markup, because that is what they see.
		$markup = $this->capture(
			static function () use ( $result ) {
				RuleEditor::render( $result );
			}
		);

		$this->assertStringContainsString(
			esc_attr( $visible ),
			$markup,
			'⚠ the refused form lost the merchant\'s input for ' . $field . '.'
		);

		$this->assertStringContainsString(
			esc_html( Notices::refusal_message( $field, $code ) ),
			$markup,
			'⚠ the refused form did not show the reason for ' . $field . '.'
		);

		$this->assertStringContainsString( 'notice-error', $markup );
		$this->assertStringNotContainsString( 'notice-success', $markup, '⚠ a refused save showed a success notice.' );

		foreach ( array_keys( Notices::request_messages() ) as $code_name ) {
			if ( in_array( $code_name, array( 'created', 'saved', 'duplicated', 'activated', 'deactivated', 'deleted' ), true ) ) {
				$this->assertStringNotContainsString(
					esc_html( Notices::request_messages()[ $code_name ][1] ),
					$markup,
					'⚠ a refused save showed the "' . $code_name . '" success message.'
				);
			}
		}

		$this->gate[] = 'refused ' . str_pad( $field, 16 ) . ' (' . str_pad( $code, 20 )
			. ') → form intact, field named, no success notice, row byte-identical';
	}

	/**
	 * GATE 30b. THE SAME REFUSALS ON AN **INSERT** create no row at all.
	 *
	 * @dataProvider refusal_provider
	 *
	 * @param string $field     The column that must be named.
	 * @param string $code      The refusal code.
	 * @param array  $overrides Form overrides that produce the refusal.
	 * @param string $visible   A submitted value that must survive.
	 * @return void
	 */
	public function test_a_refused_insert_creates_nothing( string $field, string $code, array $overrides, string $visible ) {
		$this->become_manager();

		$rules  = Plugin::instance()->rules();
		$before = $rules->count();

		$post = $this->valid_post( array_merge( array( 'name' => $visible ), $overrides ), 0 );

		$result = RuleActions::handle( RuleActions::ACTION_SAVE, $post, array() );

		$this->assertSame( RuleActions::OUTCOME_REFUSED, $result['outcome'] ?? '' );
		$this->assertArrayHasKey( $field, (array) ( $result['errors'] ?? array() ) );
		$this->assertSame( $code, $result['errors'][ $field ] );

		$this->assertSame(
			$before,
			$rules->count(),
			'⚠ a refused insert created a row for ' . $field . '.'
		);
	}

	/**
	 * One refusal per validated column, plus insert mode's two.
	 *
	 * @return array<string,array{0:string,1:string,2:array,3:string}>
	 */
	public static function refusal_provider(): array {
		return array(
			'status outside the vocabulary'        => array(
				'status',
				RuleRepository::REFUSED_NOT_IN_VOCABULARY,
				array( 'status' => 'wide-open' ),
				'WCEP Refused Status',
			),
			'delivery_mode outside the vocabulary' => array(
				'delivery_mode',
				RuleRepository::REFUSED_NOT_IN_VOCABULARY,
				array( 'delivery_mode' => 'unknown' ),
				'WCEP Refused Mode',
			),
			'consolidation outside the vocabulary' => array(
				'consolidation',
				RuleRepository::REFUSED_NOT_IN_VOCABULARY,
				array( 'consolidation' => 'daily' ),
				'WCEP Refused Daily',
			),
			'consolidation malformed'              => array(
				'consolidation',
				RuleRepository::REFUSED_MALFORMED,
				array( 'consolidation' => 'PER_PRODUCT' ),
				'WCEP Refused Caps',
			),
			'native_email_id malformed'            => array(
				'native_email_id',
				RuleRepository::REFUSED_MALFORMED,
				array( 'native_email_id' => 'customer_processing_order!' ),
				'WCEP Refused Email',
			),
			'native_email_id empty on insert'      => array(
				'native_email_id',
				RuleRepository::REFUSED_REQUIRED_FOR_INSERT,
				array(
					'delivery_mode'   => 'insert',
					'native_email_id' => '',
				),
				'WCEP Refused No Email',
			),
			'native_email_id unregistered'         => array(
				'native_email_id',
				RuleRepository::REFUSED_UNREGISTERED,
				array(
					'delivery_mode'   => 'insert',
					'native_email_id' => 'wcep_no_such_email',
				),
				'WCEP Refused Unknown Email',
			),
			'trigger_type outside the vocabulary'  => array(
				'trigger_type',
				RuleRepository::REFUSED_NOT_IN_VOCABULARY,
				array( 'trigger_type' => 'statuz' ),
				'WCEP Refused Trigger',
			),
			'trigger_value empty status'           => array(
				'trigger_value',
				RuleRepository::REFUSED_MALFORMED,
				array( 'trigger_status' => '' ),
				'WCEP Refused Empty Status',
			),
			'trigger_value half a transition'      => array(
				'trigger_value',
				RuleRepository::REFUSED_MALFORMED,
				array(
					'trigger_type' => 'transition',
					'trigger_from' => 'pending',
					'trigger_to'   => '',
				),
				'WCEP Refused Half Transition',
			),
			'trigger_value not a slug'             => array(
				'trigger_value',
				RuleRepository::REFUSED_MALFORMED,
				array( 'trigger_status' => '<b>completed</b>' ),
				'WCEP Refused Markup Status',
			),
			'insert forbids a delay'               => array(
				'delay_seconds',
				RuleRepository::REFUSED_INSERT_FORBIDS,
				array(
					'delivery_mode'   => 'insert',
					'native_email_id' => 'customer_completed_order',
					'delay_value'     => 2,
					'delay_unit'      => 'hours',
				),
				'WCEP Refused Insert Delay',
			),
			'insert forbids consolidation'         => array(
				'consolidation',
				RuleRepository::REFUSED_INSERT_FORBIDS,
				array(
					'delivery_mode'   => 'insert',
					'native_email_id' => 'customer_completed_order',
					'consolidation'   => Consolidation::PER_PRODUCT,
				),
				'WCEP Refused Insert Fanout',
			),
		);
	}

	/**
	 * GATE 30c. THE EXPLANATION AND THE OUTCOME AGREE, over a corpus.
	 *
	 * ⚠ THIS IS WHAT MAKES `explain_refusal()` TRUSTWORTHY. It is derived from
	 * `write_is_valid()`'s own body (ADR-0017 §3), so drift is impossible by
	 * construction — and this asserts the property anyway, because "impossible by
	 * construction" is a claim about code that a later edit can quietly falsify.
	 *
	 * `explain_refusal() === array()` **iff** the write is actually stored.
	 *
	 * @return void
	 */
	public function test_the_explanation_and_the_outcome_agree() {
		$this->become_manager();

		$rules   = Plugin::instance()->rules();
		$checked = 0;

		foreach ( self::corpus() as $label => $data ) {
			$explained = RuleRepository::explain_refusal( $data );
			$rule_id   = $rules->insert( $data );

			if ( $rule_id > 0 ) {
				$this->track_rule( $rule_id );
			}

			$this->assertSame(
				array() === $explained,
				$rule_id > 0,
				'⚠ explain_refusal() and insert() disagree about "' . $label . '": explanation '
					. wp_json_encode( $explained ) . ', insert returned ' . $rule_id
			);

			// And on UPDATE, judged against a stored row.
			$existing = $rule_id > 0 ? $rules->find( $rule_id ) : null;

			if ( null !== $existing ) {
				foreach ( self::partial_updates() as $update_label => $update ) {
					$explained_update = RuleRepository::explain_refusal( $update, $existing );
					$updated          = $rules->update( $rule_id, $update );

					$this->assertSame(
						array() === $explained_update,
						$updated,
						'⚠ explain_refusal() and update() disagree about "' . $update_label . '" on "' . $label . '".'
					);

					++$checked;

					// Re-read: an accepted update changes what the next one is judged against.
					$existing = $rules->find( $rule_id );
				}
			}

			++$checked;
		}

		$this->gate[] = 'explanation/outcome agreement: ' . $checked
			. ' writes — explain_refusal() empty IFF the row was stored';
	}

	/**
	 * Writes spanning accepted and refused, for the agreement check.
	 *
	 * @return array<string,array>
	 */
	private static function corpus(): array {
		$base = array(
			'name'          => 'WCEP Corpus',
			'status'        => 'inactive',
			'trigger_type'  => 'status',
			'trigger_value' => 'completed',
			'delivery_mode' => 'separate',
			'targeting'     => array( 'match_all' => true ),
			'recipients'    => array( 'to' => array( 'customer' ) ),
		);

		$cases = array(
			'valid separate'         => $base,
			'valid refund'           => array_merge( $base, array( 'trigger_type' => 'refund' ) ),
			'valid transition'       => array_merge(
				$base,
				array(
					'trigger_type'  => 'transition',
					'trigger_value' => 'pending>processing',
				)
			),
			'valid insert'           => array_merge(
				$base,
				array(
					'delivery_mode'   => 'insert',
					'native_email_id' => 'customer_completed_order',
				)
			),
			'valid per_product'      => array_merge( $base, array( 'consolidation' => Consolidation::PER_PRODUCT ) ),
			'valid delayed'          => array_merge( $base, array( 'delay_seconds' => 3600 ) ),
			'bad status'            => array_merge( $base, array( 'status' => 'wide-open' ) ),
			'bad status case'       => array_merge( $base, array( 'status' => 'ACTIVE' ) ),
			'bad mode'              => array_merge( $base, array( 'delivery_mode' => 'unknown' ) ),
			'bad mode case'         => array_merge( $base, array( 'delivery_mode' => 'INSERT' ) ),
			'bad consolidation'     => array_merge( $base, array( 'consolidation' => 'daily' ) ),
			'bad consolidation fmt' => array_merge( $base, array( 'consolidation' => ' none ' ) ),
			'bad consolidation len' => array_merge( $base, array( 'consolidation' => str_repeat( 'a', 30 ) ) ),
			'bad trigger type'      => array_merge( $base, array( 'trigger_type' => 'statuz' ) ),
			'bad trigger empty'     => array_merge( $base, array( 'trigger_value' => '' ) ),
			'bad trigger markup'    => array_merge( $base, array( 'trigger_value' => '<b>completed</b>' ) ),
			'bad transition'        => array_merge(
				$base,
				array(
					'trigger_type'  => 'transition',
					'trigger_value' => 'pending>',
				)
			),
			'bad transition two'    => array_merge(
				$base,
				array(
					'trigger_type'  => 'transition',
					'trigger_value' => 'a>b>c',
				)
			),
			'bad native id'         => array_merge( $base, array( 'native_email_id' => 'customer!' ) ),
			'insert no email'       => array_merge( $base, array( 'delivery_mode' => 'insert' ) ),
			'insert unregistered'   => array_merge(
				$base,
				array(
					'delivery_mode'   => 'insert',
					'native_email_id' => 'wcep_no_such_email',
				)
			),
			'insert with delay'     => array_merge(
				$base,
				array(
					'delivery_mode'   => 'insert',
					'native_email_id' => 'customer_completed_order',
					'delay_seconds'   => 60,
				)
			),
			'insert with fan-out'   => array_merge(
				$base,
				array(
					'delivery_mode'   => 'insert',
					'native_email_id' => 'customer_completed_order',
					'consolidation'   => Consolidation::PER_PRODUCT,
				)
			),
		);

		return $cases;
	}

	/**
	 * Partial updates applied to every accepted row.
	 *
	 * @return array<string,array>
	 */
	private static function partial_updates(): array {
		return array(
			'rename'              => array( 'name' => 'WCEP Corpus renamed' ),
			'activate'            => array( 'status' => 'active' ),
			'bad status'          => array( 'status' => 'nope' ),
			'bad consolidation'   => array( 'consolidation' => 'weekly' ),
			'good consolidation'  => array( 'consolidation' => Consolidation::NONE ),
			'bad mode'            => array( 'delivery_mode' => 'nope' ),
			'bad native id'       => array( 'native_email_id' => 'Customer Processing' ),
			'switch to insert'    => array( 'delivery_mode' => 'insert' ),
			'priority only'       => array( 'priority' => 5 ),
			'bad trigger type'    => array( 'trigger_type' => 'statuz' ),
			'refund trigger'      => array( 'trigger_type' => 'refund' ),
		);
	}

	/**
	 * GATE 30d. THE EDITOR'S **OWN** ERRORS also refuse without reaching storage.
	 *
	 * A missing name and a negative delay are things the repository has no opinion
	 * about — `sanitize()` would clamp `-2 hours` to zero and store a rule whose
	 * editor says one thing and whose behaviour says another.
	 *
	 * @return void
	 */
	public function test_editor_level_errors_refuse_before_storage() {
		$this->become_manager();

		$rules  = Plugin::instance()->rules();
		$before = $rules->count();

		foreach ( array(
			'no name'      => array(
				'field'     => 'name',
				'code'      => 'required',
				'overrides' => array( 'name' => '   ' ),
			),
			'negative delay' => array(
				'field'     => 'delay_seconds',
				'code'      => 'negative',
				'overrides' => array( 'delay_value' => -2 ),
			),
			'unknown unit' => array(
				'field'     => 'delay_seconds',
				'code'      => 'unit',
				'overrides' => array( 'delay_unit' => 'fortnights' ),
			),
		) as $label => $case ) {
			$result = RuleActions::handle(
				RuleActions::ACTION_SAVE,
				$this->valid_post( $case['overrides'], 0 ),
				array()
			);

			$this->assertSame( RuleActions::OUTCOME_REFUSED, $result['outcome'] ?? '', $label );
			$this->assertSame( $case['code'], $result['errors'][ $case['field'] ] ?? '', $label );
		}

		$this->assertSame( $before, $rules->count(), '⚠ an editor-level refusal still created a row.' );

		$this->gate[] = 'editor-level refusals: a blank name, a negative delay and an unknown unit '
			. 'refuse without reaching storage';
	}

	/**
	 * GATE 30e. A VALID SAVE **DOES** SUCCEED, redirects, and stores what was typed.
	 *
	 * ⚠ THE POSITIVE CONTROL. Without it every assertion above is satisfied by an
	 * editor that refuses everything.
	 *
	 * @return void
	 */
	public function test_a_valid_save_succeeds_and_stores_the_input() {
		$this->become_manager();

		$result = RuleActions::handle(
			RuleActions::ACTION_SAVE,
			$this->valid_post(
				array(
					'name'    => 'WCEP Accepted',
					'status'  => 'active',
					'subject' => 'Thanks for buying {product_name}',
				),
				0
			),
			array()
		);

		$this->assertSame( RuleActions::OUTCOME_REDIRECT, $result['outcome'] ?? '' );
		$this->assertStringContainsString( 'message=created', (string) $result['url'] );

		preg_match( '/rule=(\d+)/', (string) $result['url'], $found );

		$rule_id = (int) ( $found[1] ?? 0 );

		$this->assertGreaterThan( 0, $rule_id );

		$this->track_rule( $rule_id );

		$stored = Plugin::instance()->rules()->find( $rule_id );

		$this->assertSame( 'WCEP Accepted', $stored['name'] );
		$this->assertSame( 'active', $stored['status'] );
		$this->assertSame( 'Thanks for buying {product_name}', $stored['subject'] );
		$this->assertSame( 'completed', $stored['trigger_value'] );
		$this->assertTrue( (bool) ( $stored['targeting']['match_all'] ?? false ) );
		$this->assertSame( array( 'customer' ), (array) ( $stored['recipients']['to'] ?? array() ) );

		$this->gate[] = 'positive control: a valid save redirects with message=created and stores what was typed';
	}

	/**
	 * A tracked rule to edit.
	 *
	 * @return int
	 */
	private function make_rule(): int {
		$rule_id = Plugin::instance()->rules()->insert(
			array(
				'name'          => 'WCEP Save Fixture',
				'status'        => 'active',
				'trigger_type'  => 'status',
				'trigger_value' => 'completed',
				'delivery_mode' => 'separate',
				'targeting'     => array( 'include' => array( 'products' => array( 12 ) ) ),
				'recipients'    => array( 'to' => array( 'customer' ) ),
				'subject'       => 'Subject',
				'content'       => '<p>Body</p>',
			)
		);

		$this->assertGreaterThan( 0, $rule_id, 'Could not create the rule fixture.' );

		return $this->track_rule( $rule_id );
	}
}
