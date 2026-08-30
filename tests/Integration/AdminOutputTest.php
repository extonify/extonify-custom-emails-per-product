<?php
/**
 * GATES 31 and 33 — output escaping, labels, descriptions and translatability.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Admin\RuleActions;
use Extonify\WCEP\Admin\RuleEditor;
use Extonify\WCEP\Admin\RuleFormInput;
use Extonify\WCEP\Admin\RuleList;
use Extonify\WCEP\Plugin;

/**
 * Hostile values render inert, every control is labelled, and every string is
 * translatable.
 *
 * ⚠ SEVERITY: an unescaped output on an admin screen is TIER 1. The consequence is
 * STORED XSS against a `manage_woocommerce` user — a privilege boundary crossed —
 * not a badly formatted email.
 *
 * ⚠ THE HOSTILE VALUE IS ONE DISTINCTIVE MARKER, NOT "A `<script>` TAG". `wp_editor()`
 * legitimately emits `<script>` of its own, so asserting "no script tags" would fail
 * on WordPress's markup and prove nothing about ours. Every assertion is made on
 * `wcepXSS`, which appears nowhere else in the page.
 */
final class AdminOutputTest extends AdminTestCase {

	/**
	 * The payload every hostile field carries.
	 *
	 * Four attacks in one string: a script element, an attribute breakout with a
	 * double quote, an attribute breakout with a single quote, a bare ampersand, and
	 * a percent-encoded `<script>` that must never be decoded into one.
	 */
	const HOSTILE = '<script>wcepXSS()</script>" onmouseover="wcepXSS()\' onfocus=\'wcepXSS() & %3Cscript%3EwcepXSS()%3C/script%3E';

	/**
	 * Gate lines.
	 *
	 * @var string[]
	 */
	private $gate = array();

	/**
	 * Term ids this test created.
	 *
	 * @var int[]
	 */
	private $term_ids = array();

	/**
	 * Print the gate lines and prove the terms are gone.
	 *
	 * @after
	 * @return void
	 */
	protected function report_gate() {
		foreach ( $this->term_ids as $term_id ) {
			wp_delete_term( $term_id, 'product_cat' );

			$this->assertFalse(
				get_term( $term_id, 'product_cat' ) instanceof \WP_Term,
				'A test term survived teardown: #' . $term_id
			);
		}

		$this->term_ids = array();

		foreach ( $this->gate as $line ) {
			fwrite( STDERR, "\n[P9 gate 31] " . $line );
		}

		$this->gate = array();
	}

	/**
	 * THE GATE-31 TABLE. Every rendered value, its output context, and the escaping
	 * function that guards it.
	 *
	 * @return array[] Each `{value, context, escaper, screen}`.
	 */
	public static function output_table(): array {
		return array(
			// --- list ---------------------------------------------------------
			array( 'rule name (row title)', 'HTML text', 'esc_html', 'list' ),
			array( 'rule name (delete confirmation)', 'HTML attribute', 'esc_attr', 'list' ),
			array( 'edit / row-action URLs', 'URL attribute', 'esc_url', 'list' ),
			array( 'status label', 'HTML text', 'esc_html', 'list' ),
			array( 'status CSS class', 'HTML attribute', 'esc_attr( sanitize_html_class() )', 'list' ),
			array( 'trigger + order-status names', 'HTML text', 'esc_html', 'list' ),
			array( 'WooCommerce email title', 'HTML text', 'esc_html', 'list' ),
			array( 'target names (product, variation, term)', 'HTML text', 'esc_html', 'list' ),
			array( 'target type labels', 'HTML text', 'esc_html', 'list' ),
			array( '"+N more" and kind labels', 'HTML text', 'esc_html', 'list' ),
			array( 'delivery-mode label', 'HTML text', 'esc_html', 'list' ),
			array( 'delay', 'HTML text', 'esc_html', 'list' ),
			array( 'consolidation label', 'HTML text', 'esc_html', 'list' ),
			array( 'priority and any default column', 'HTML text', 'esc_html', 'list' ),
			array( 'filter select name / id / value', 'HTML attribute', 'esc_attr', 'list' ),
			array( 'filter select labels', 'HTML text', 'esc_html', 'list' ),
			array( 'empty-state copy and its link', 'HTML text / URL', 'esc_html / esc_url', 'list' ),
			array( 'request notice', 'HTML text', 'esc_html', 'both' ),
			// --- editor -------------------------------------------------------
			array( 'name / subject / heading values', 'HTML attribute', 'esc_attr', 'editor' ),
			array( 'body', 'RCDATA in wp_editor()', 'wp_kses_post at storage; format_for_editor, added by us', 'editor' ),
			array( 'recipient entries', 'textarea content', 'esc_textarea', 'editor' ),
			array( 'every input name / id / value', 'HTML attribute', 'esc_attr', 'editor' ),
			array( 'every select option value', 'HTML attribute', 'esc_attr', 'editor' ),
			array( 'every select option label', 'HTML text', 'esc_html', 'editor' ),
			array( 'stored value outside the vocabulary', 'HTML text + attribute', 'esc_html / esc_attr', 'editor' ),
			array( 'target chip labels', 'HTML text', 'esc_html', 'editor' ),
			array( 'chip input value / id / for', 'HTML attribute', 'esc_attr', 'editor' ),
			array( 'form action URL', 'URL attribute', 'esc_url', 'editor' ),
			array( 'refusal messages', 'HTML text', 'esc_html', 'editor' ),
			array( 'warning messages + their ids', 'HTML text + attribute', 'esc_html / esc_attr', 'editor' ),
			array( 'placeholder tokens', 'HTML text + attribute', 'esc_html / esc_attr', 'editor' ),
			array( 'field labels and descriptions', 'HTML text', 'esc_html', 'editor' ),
			array( 'search box placeholder', 'HTML attribute', 'esc_attr__', 'editor' ),
		);
	}

	/**
	 * GATE 31a. A HOSTILE RULE RENDERS INERT ON THE LIST — WITH THE HOSTILE NAME
	 *           WRITTEN **STRAIGHT INTO THE TABLE**, past the repository.
	 *
	 * ⚠ STORAGE SANITISATION IS A DIFFERENT BARRIER FROM OUTPUT ESCAPING, AND THIS
	 * GATE IS ABOUT THE SECOND ONE. `RuleRepository::sanitize()` runs the name
	 * through `sanitize_text_field()`, which DELETES the `<script>` element outright
	 * — so a fixture inserted through the repository leaves nothing for `esc_html()`
	 * to escape, and a list column that forgot to escape entirely would still pass.
	 * The row is therefore written with `$wpdb->update()` directly, exactly as
	 * `ConsolidationTest::force_raw_consolidation()` does, which is also the state
	 * an import, a direct SQL edit, or a future write path that skips `sanitize()`
	 * would leave behind. Both barriers must hold on their own.
	 *
	 * @return void
	 */
	public function test_hostile_values_render_inert_on_the_list() {
		$this->become_manager();
		$this->use_our_screen();

		$rule_id = $this->make_hostile_rule();

		// Past the repository, so the `<script>` element really is in the column.
		$this->force_raw_name( $rule_id, self::HOSTILE );

		$this->request(
			array(
				'page' => Menu::PAGE,
				's'    => 'wcepXSS',
			)
		);

		$markup = $this->capture(
			static function () {
				RuleList::prepare();
				RuleList::render();
			}
		);

		$this->assertStringContainsString( (string) $rule_id, $markup, 'the hostile rule was not on the page.' );

		$this->assertInert( $markup, 'the rules list' );

		// The escaped forms ARE present, so the value was rendered rather than dropped
		// — the row title through `esc_html()`, the delete confirmation through
		// `esc_attr()`, which also encodes the quotes.
		$this->assertStringContainsString(
			'&lt;script&gt;wcepXSS()&lt;/script&gt;',
			$markup,
			'⚠ the raw script element never reached the page, so this test proved nothing about escaping.'
		);

		$this->assertStringContainsString(
			'&lt;script&gt;wcepXSS()&lt;/script&gt;&quot; onmouseover=',
			$markup,
			'⚠ the delete confirmation did not carry the escaped name in an attribute.'
		);

		$this->gate[] = 'list: the hostile name written DIRECT TO THE TABLE past sanitize() renders escaped '
			. 'in both the row title and the delete-confirmation attribute; no executable script, attribute '
			. 'breakout or decoded percent-sequence anywhere in the markup';
	}

	/**
	 * GATE 31b. THE SAME VALUES RENDER INERT IN THE EDITOR, loaded from storage.
	 *
	 * @return void
	 */
	public function test_hostile_values_render_inert_in_the_editor() {
		$this->become_manager();
		$this->use_our_screen();

		$rule_id = $this->make_hostile_rule();

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

		$this->assertInert( $markup, 'the rule editor' );

		$this->assertBodyIsTextareaText( $markup );

		$this->gate[] = 'editor (from storage): hostile name, subject, heading and body render inert; the body '
			. 'is a TEXT NODE inside <textarea id=' . RuleEditor::EDITOR_ID . '>, proven by the parser';
	}

	/**
	 * Assert the body value's enclosing element really is the editor `<textarea>`,
	 * according to a parser rather than according to a substring search.
	 *
	 * ⚠ THIS IS THE HALF `assertInert()` CANNOT MAKE. `assertInert()` proves the
	 * RCDATA holds no `<`; this proves the value is IN that RCDATA in the first
	 * place — that `DOMDocument`, applying the same HTML rules a browser applies,
	 * puts the marker in a text node whose parent is `<textarea>` and never in an
	 * element or an attribute of its own.
	 *
	 * @param string $markup Rendered editor markup.
	 * @return void
	 */
	private function assertBodyIsTextareaText( string $markup ): void {
		$dom  = self::parse( $markup );
		$path = new \DOMXPath( $dom );

		$editor = $path->query( '//textarea[@id="' . RuleEditor::EDITOR_ID . '"]' )->item( 0 );

		$this->assertInstanceOf(
			\DOMElement::class,
			$editor,
			'the editor textarea is not on the page, so nothing was proven about it.'
		);

		$children = array();

		foreach ( $editor->childNodes as $child ) {
			$children[] = $child->nodeName;
		}

		$this->assertSame(
			array( '#text' ),
			$children,
			'⚠ TIER 1: the editor textarea holds ELEMENTS, not text — the value left RCDATA.'
		);

		$this->assertStringContainsString(
			'onmouseover="wcepXSS',
			$editor->textContent,
			'⚠ the hostile body is not in the textarea, so the context under test is the wrong one.'
		);
	}

	/**
	 * GATE 31c. AND ON THE **REFUSED RE-RENDER**, where the value comes straight
	 *           back out of `$_POST` rather than out of the database.
	 *
	 * ⚠ THE PATH MOST LIKELY TO BE MISSED. Everything else renders a value that has
	 * been through `RuleRepository::sanitize()`; this one renders exactly what the
	 * merchant — or an attacker with a `manage_woocommerce` session — just typed,
	 * having never touched the storage boundary. ADR-0017 §3.2 REQUIRES that the
	 * input comes back, so the escaping has to hold for it.
	 *
	 * @return void
	 */
	public function test_hostile_values_render_inert_on_a_refused_re_render() {
		$this->become_manager();
		$this->use_our_screen();

		// A refusal the repository will produce, so the form comes back.
		$post = $this->valid_post(
			array(
				'name'    => self::HOSTILE,
				'subject' => self::HOSTILE,
				'heading' => self::HOSTILE,
				'content' => '<p>' . self::HOSTILE . '</p>',
				'status'  => 'wide-open',
			),
			0
		);

		$result = RuleActions::handle( RuleActions::ACTION_SAVE, $post, array() );

		$this->assertSame( RuleActions::OUTCOME_REFUSED, $result['outcome'] ?? '' );

		$markup = $this->capture(
			static function () use ( $result ) {
				RuleEditor::render( $result );
			}
		);

		$this->assertInert( $markup, 'the refused re-render' );

		$this->gate[] = 'editor (refused re-render, straight from $_POST): hostile input renders inert';
	}

	/**
	 * GATE 31c-bis. THE BODY IS ESCAPED FOR RCDATA ON **BOTH** EDITOR PATHS, and by
	 *               this plugin rather than by a core implementation detail.
	 *
	 * ⚠ THE TEXT-ONLY PATH IS THE ONE THAT WAS UNCOVERED, AND IT IS THE ONE THIS
	 * SUITE RUNS. `_WP_Editors::editor()` adds `format_for_editor` only when TinyMCE
	 * is active, and `user_can_richedit()` is FALSE for every merchant who ticked
	 * "Disable the visual editor when writing" — and false under the CLI SAPI, which
	 * is why the assertion above sees it. On that path core's only remaining
	 * containment was its private `preg_replace( '%</textarea%i', … )`.
	 *
	 * ⚠ AND THE VISUAL PATH MUST NOT DOUBLE-ESCAPE. `add_filter()` keys on callback
	 * name and priority, so core adding the same function at the same priority
	 * replaces our entry instead of appending a second — asserted here rather than
	 * argued, because a double escape would show the merchant `&lt;p&gt;` and would
	 * corrupt the body on the next save.
	 *
	 * @return void
	 */
	public function test_the_body_is_escaped_for_rcdata_on_both_editor_paths() {
		$this->become_manager();
		$this->use_our_screen();

		$rule_id = Plugin::instance()->rules()->insert(
			array(
				'name'          => 'WCEP RCDATA',
				'status'        => 'inactive',
				'trigger_type'  => 'status',
				'trigger_value' => 'completed',
				'delivery_mode' => 'separate',
				'targeting'     => array( 'match_all' => true ),
				// `wp_kses_post()` keeps BOTH of these: the `<p>` because it is
				// allowed, and the stray `</textarea>` because kses does not strip a
				// closing tag it has no opening tag for.
				'content'       => '</textarea><p>wcepXSS()</p>',
			)
		);

		$this->assertGreaterThan( 0, $rule_id );
		$this->track_rule( $rule_id );

		$this->assertStringContainsString(
			'</textarea>',
			(string) Plugin::instance()->rules()->find( $rule_id )['content'],
			'⚠ the fixture never reached the column, so this test proves nothing.'
		);

		$this->request(
			array(
				'page'   => Menu::PAGE,
				'action' => Menu::ACTION_EDIT,
				'rule'   => $rule_id,
			)
		);

		foreach ( array( false, true ) as $rich ) {
			$filter = $rich ? '__return_true' : '__return_false';

			add_filter( 'user_can_richedit', $filter, 999 );

			$markup = $this->capture(
				static function () {
					RuleEditor::render();
				}
			);

			remove_filter( 'user_can_richedit', $filter, 999 );

			$path = $rich ? 'visual (TinyMCE)' : 'text-only (quicktags)';

			$this->assertInert( $markup, 'the editor body, ' . $path . ' path' );

			$content = self::editor_content( $markup );

			// Escaped exactly ONCE: `<` is an entity, and the entity itself was not
			// escaped again into `&amp;lt;`.
			$this->assertStringContainsString( '&lt;/textarea&gt;', $content, 'the ' . $path . ' path did not escape.' );
			$this->assertStringContainsString( '&lt;p&gt;wcepXSS()&lt;/p&gt;', $content, 'the ' . $path . ' path did not escape.' );
			$this->assertStringNotContainsString( '&amp;lt;', $content, '⚠ the ' . $path . ' path escaped the body TWICE.' );
		}

		$this->gate[] = 'editor body: escaped for RCDATA on BOTH the text-only and visual paths — once, never '
			. 'twice — so containment no longer rests on _WP_Editors\' own private </textarea replacement';
	}

	/**
	 * The editor textarea's rendered content, exactly as it sits in the markup.
	 *
	 * @param string $markup Rendered editor markup.
	 * @return string
	 */
	private static function editor_content( string $markup ): string {
		$opens = 'id="' . RuleEditor::EDITOR_ID . '">';
		$at    = strpos( $markup, $opens );

		if ( false === $at ) {
			return '';
		}

		$from  = $at + strlen( $opens );
		$until = stripos( $markup, '</textarea', $from );

		return false === $until ? substr( $markup, $from ) : substr( $markup, $from, $until - $from );
	}

	/**
	 * GATE 31d. A HOSTILE **PRODUCT TITLE** — a value this plugin never wrote —
	 *           renders inert in the target summary and in the editor chips.
	 *
	 * ⚠ NOT EVERY VALUE ON THE SCREEN IS THIS PLUGIN'S. The targeting summary shows
	 * product and term names owned by WooCommerce and by whoever typed them, and a
	 * plugin that escapes only its own columns has escaped the easy half.
	 *
	 * @return void
	 */
	public function test_a_hostile_product_title_renders_inert() {
		$this->become_manager();
		$this->use_our_screen();

		$product_id = $this->make_product( self::HOSTILE );
		$term_id    = $this->make_hostile_term();

		$rule_id = Plugin::instance()->rules()->insert(
			array(
				'name'          => 'WCEP Hostile Targets',
				'status'        => 'inactive',
				'trigger_type'  => 'status',
				'trigger_value' => 'completed',
				'delivery_mode' => 'separate',
				'targeting'     => array(
					'include' => array(
						'products'   => array( $product_id ),
						'categories' => array( $term_id ),
					),
				),
			)
		);

		$this->assertGreaterThan( 0, $rule_id );
		$this->track_rule( $rule_id );

		$this->request(
			array(
				'page' => Menu::PAGE,
				's'    => 'WCEP Hostile Targets',
			)
		);

		$list = $this->capture(
			static function () {
				RuleList::prepare();
				RuleList::render();
			}
		);

		$this->assertInert( $list, 'the target summary' );

		$this->request(
			array(
				'page'   => Menu::PAGE,
				'action' => Menu::ACTION_EDIT,
				'rule'   => $rule_id,
			)
		);

		$editor = $this->capture(
			static function () {
				RuleEditor::render();
			}
		);

		$this->assertInert( $editor, 'the editor chips' );

		$this->gate[] = 'list + editor: a hostile PRODUCT TITLE and TERM NAME — values this plugin '
			. 'never wrote — render inert too';
	}

	/**
	 * GATE 31e. THE OUTPUT TABLE IS PRINTED, so the gate is auditable rather than
	 *           asserted.
	 *
	 * @return void
	 */
	public function test_the_output_table_is_printed() {
		$rows = self::output_table();

		$this->assertGreaterThan( 30, count( $rows ), 'the output table is suspiciously short.' );

		$table = "\n             ┌──────────────────────────────────────────┬─────────────────────────┬───────────────────────────────────────────────────┬────────┐\n"
			. "             │ rendered value                           │ context                 │ escaping function                                 │ screen │\n"
			. "             ├──────────────────────────────────────────┼─────────────────────────┼───────────────────────────────────────────────────┼────────┤\n";

		foreach ( $rows as $row ) {
			$table .= '             │ ' . str_pad( $row[0], 40 ) . ' │ ' . str_pad( $row[1], 23 ) . ' │ '
				. str_pad( $row[2], 49 ) . ' │ ' . str_pad( $row[3], 6 ) . " │\n";
		}

		$table .= "             └──────────────────────────────────────────┴─────────────────────────┴───────────────────────────────────────────────────┴────────┘\n";

		$this->gate[] = 'output table: ' . count( $rows ) . " enumerated outputs\n" . $table;
	}

	// -----------------------------------------------------------------------
	// GATE 33 — accessibility and i18n
	// -----------------------------------------------------------------------

	/**
	 * GATE 33a. EVERY CONTROL THIS PLUGIN RENDERS HAS A REAL `<label for>`.
	 *
	 * ⚠ SCOPED TO THIS PLUGIN'S OWN MARKUP. `wp_editor()` emits TinyMCE's toolbar,
	 * which this plugin neither wrote nor controls; asserting over it would fail on
	 * WordPress's markup and say nothing about ours. Every element whose id or name
	 * belongs to this plugin is checked, and nothing else.
	 *
	 * @return void
	 */
	public function test_every_control_is_labelled() {
		$this->become_manager();
		$this->use_our_screen();

		$markup = $this->capture(
			static function () {
				RuleEditor::render();
			}
		);

		$dom = self::parse( $markup );

		$labels = array();

		foreach ( $dom->getElementsByTagName( 'label' ) as $label ) {
			$for = $label->getAttribute( 'for' );

			if ( '' !== $for ) {
				$labels[ $for ] = true;
			}
		}

		$checked   = 0;
		$unlabelled = array();

		foreach ( array( 'input', 'select', 'textarea' ) as $tag ) {
			foreach ( $dom->getElementsByTagName( $tag ) as $control ) {
				$name = $control->getAttribute( 'name' );
				$id   = $control->getAttribute( 'id' );

				$ours = 0 === strpos( $name, RuleFormInput::FIELD )
					|| 0 === strpos( $id, 'extonify-wcep' );

				if ( ! $ours ) {
					continue;
				}

				// A hidden input carries no interaction and needs no label — it is not
				// reachable, focusable or announced.
				if ( 'input' === $tag && 'hidden' === $control->getAttribute( 'type' ) ) {
					continue;
				}

				++$checked;

				if ( '' !== $id && isset( $labels[ $id ] ) ) {
					continue;
				}

				$unlabelled[] = $tag . '[name=' . $name . '][id=' . $id . ']';
			}
		}

		$this->assertSame(
			array(),
			$unlabelled,
			'⚠ controls with no <label for>: ' . implode( ', ', $unlabelled )
		);

		$this->assertGreaterThan( 20, $checked, 'too few controls were checked — the scope filter is wrong.' );

		fwrite( STDERR, "\n[P9 gate 33] labels: " . $checked . " of this plugin's own controls, every one with a real <label for>" );
	}

	/**
	 * GATE 33b. EVERY DESCRIPTION IS TIED TO ITS FIELD WITH `aria-describedby`.
	 *
	 * ⚠ A DESCRIPTION SITTING BENEATH A CONTROL IS NOT ASSOCIATED WITH IT. To a
	 * screen-reader user it is loose text somewhere on the page, read at a moment
	 * that has nothing to do with the field it explains.
	 *
	 * ⚠ A DESCRIPTION MAY DESCRIBE A **GROUP** INSTEAD OF ONE CONTROL, and then the
	 * group is what must point at it. The trigger note explains every trigger field
	 * at once; repeating it on four controls would read the same paragraph out four
	 * times as the user tabs through them. So a `role="group"` reference counts —
	 * but only when the group carries an accessible NAME as well, because a
	 * description attached to an anonymous container is announced with nothing to
	 * attach it to.
	 *
	 * @return void
	 */
	public function test_every_description_is_associated_with_its_field() {
		$this->become_manager();
		$this->use_our_screen();

		$markup = $this->capture(
			static function () {
				RuleEditor::render();
			}
		);

		$dom = self::parse( $markup );

		$described = array();

		foreach ( array( 'input', 'select', 'textarea' ) as $tag ) {
			foreach ( $dom->getElementsByTagName( $tag ) as $control ) {
				foreach ( preg_split( '/\s+/', trim( $control->getAttribute( 'aria-describedby' ) ) ) as $ref ) {
					if ( '' !== $ref ) {
						$described[ $ref ] = true;
					}
				}
			}
		}

		$groups = 0;
		$path   = new \DOMXPath( $dom );

		foreach ( $path->query( '//*[@role="group"]' ) as $group ) {
			$labelled = $group->getAttribute( 'aria-labelledby' );

			$this->assertNotSame(
				'',
				$labelled,
				'⚠ a role=group carries a description but no accessible name.'
			);

			$this->assertInstanceOf(
				\DOMElement::class,
				$path->query( '//*[@id="' . $labelled . '"]' )->item( 0 ),
				'⚠ a group is labelled by an element that is not on the page: ' . $labelled
			);

			foreach ( preg_split( '/\s+/', trim( $group->getAttribute( 'aria-describedby' ) ) ) as $ref ) {
				if ( '' !== $ref ) {
					$described[ $ref ] = true;
					++$groups;
				}
			}
		}

		$orphans = array();
		$checked = 0;

		foreach ( $dom->getElementsByTagName( 'p' ) as $paragraph ) {
			$id = $paragraph->getAttribute( 'id' );

			if ( 0 !== strpos( $id, 'extonify-wcep' ) ) {
				continue;
			}

			++$checked;

			if ( ! isset( $described[ $id ] ) ) {
				$orphans[] = $id;
			}
		}

		$this->assertSame(
			array(),
			$orphans,
			'⚠ descriptions no control points at with aria-describedby: ' . implode( ', ', $orphans )
		);

		$this->assertGreaterThan( 5, $checked, 'too few descriptions were checked.' );

		fwrite(
			STDERR,
			"\n[P9 gate 33] descriptions: " . $checked . ' associated by aria-describedby, 0 orphaned ('
			. $groups . ' of them on a NAMED role=group, for copy that describes a whole section)'
		);
	}

	/**
	 * GATE 33c. EVERY ICON-ONLY OR OTHERWISE UNNAMED CONTROL CARRIES SCREEN-READER
	 *           TEXT, and the search comboboxes carry their ARIA relationships.
	 *
	 * @return void
	 */
	public function test_screen_reader_text_and_combobox_wiring() {
		$this->become_manager();
		$this->use_our_screen();

		$markup = $this->capture(
			static function () {
				RuleEditor::render();
			}
		);

		$dom = self::parse( $markup );

		$ids = array();

		foreach ( $dom->getElementsByTagName( 'ul' ) as $list ) {
			$id = $list->getAttribute( 'id' );

			if ( '' !== $id ) {
				$ids[ $id ] = $list->getAttribute( 'role' );
			}
		}

		$comboboxes = 0;

		foreach ( $dom->getElementsByTagName( 'input' ) as $control ) {
			if ( 'search' !== $control->getAttribute( 'type' ) ) {
				continue;
			}

			++$comboboxes;

			$controls = $control->getAttribute( 'aria-controls' );

			$this->assertSame( 'combobox', $control->getAttribute( 'role' ) );
			$this->assertSame( 'list', $control->getAttribute( 'aria-autocomplete' ) );
			$this->assertNotSame( '', $control->getAttribute( 'aria-expanded' ) );
			$this->assertArrayHasKey( $controls, $ids, 'a combobox points at a results list that does not exist.' );
			$this->assertSame( 'listbox', $ids[ $controls ], 'the results list is not a listbox.' );
		}

		$this->assertSame( 8, $comboboxes, 'expected one search box per id kind per side (4 kinds x 2 sides).' );

		// The delete confirmation is a data attribute the script binds, never an
		// inline `onclick` — so the message stays translatable and the page needs no
		// inline handler.
		$this->assertStringNotContainsString( 'onclick=', $markup );

		fwrite( STDERR, "\n[P9 gate 33] combobox wiring: " . $comboboxes . ' search boxes, each role=combobox with '
			. 'aria-controls pointing at a role=listbox' );
	}

	/**
	 * GATE 33e. THE PLACEHOLDER REFERENCE STILL FOLLOWS THE CONTENT FIELDS IN
	 *           DOCUMENT ORDER.
	 *
	 * ⚠ THE TWO-COLUMN LAYOUT IS CSS, AND THIS IS THE ASSERTION THAT KEEPS IT CSS.
	 * Part G puts the reference in a right-hand column beside the fields. The
	 * tempting way to do that is to move the markup; doing so would put the
	 * reference BEFORE the fields in the document and hand a keyboard user thirty
	 * placeholder buttons to tab through on the way to the subject line. Grid
	 * columns reorder the picture, not the document — and only an assertion on the
	 * document keeps a later "just swap the divs" from undoing it silently.
	 *
	 * @return void
	 */
	public function test_the_placeholder_reference_follows_the_content_fields() {
		$this->become_manager();
		$this->use_our_screen();

		$markup = $this->capture(
			static function () {
				RuleEditor::render();
			}
		);

		$path = new \DOMXPath( self::parse( $markup ) );

		// An XPath node list is in DOCUMENT order, which is the property under test.
		$nodes = $path->query(
			'//*[@data-extonify-wcep-insertable or @data-extonify-wcep-placeholders]'
		);

		$order = array();

		foreach ( $nodes as $node ) {
			$order[] = $node->hasAttribute( 'data-extonify-wcep-placeholders' )
				? 'the placeholder reference'
				: $node->getAttribute( 'name' );
		}

		$this->assertSame(
			array(
				RuleFormInput::FIELD . '[subject]',
				RuleFormInput::FIELD . '[heading]',
				RuleFormInput::FIELD . '[content]',
				'the placeholder reference',
			),
			$order,
			'⚠ tab order no longer runs fields → reference.'
		);

		fwrite(
			STDERR,
			"\n[P9 gate 33] DOM order: subject → heading → body → placeholder reference; the two-column "
			. 'layout is CSS grid, so tab order is unchanged'
		);
	}

	/**
	 * ⚠ TIER 1 REGRESSION GUARD. THE INSERTABLE SURFACE IS PINNED AS A COMPLETE
	 *   MAP — EVERY FIELD THAT TAKES A PLACEHOLDER, AND EVERY FIELD THAT DOES NOT.
	 *
	 * This is the assertion whose absence allowed the Part G defect, and it failed
	 * in BOTH directions when it was first written.
	 *
	 * ⚠ MISSING. Until Part G `data-extonify-wcep-insertable` was emitted at one
	 * site, `RuleEditor::text_row()`, so the subject and the heading had it and the
	 * `wp_editor()` body did not. Focusing the body therefore never updated
	 * `assets/admin.js`'s "last focused field", and a placeholder clicked while the
	 * merchant was writing the body was inserted into the HEADING — silently, with
	 * the token sitting in a field the merchant was not looking at.
	 *
	 * ⚠ AND SPURIOUS. The same single site gave the marker to the rule NAME, whose
	 * own description reads "Only you see this": a private label no email ever
	 * renders, placed above the fold, where a token could land unseen for exactly
	 * the same reason. The attribute is now a per-field argument, so it is a
	 * decision rather than a side effect of which helper drew the row.
	 *
	 * ⚠ ASSERTED AS A MAP, NOT AS THREE `assertContains()` CALLS. The failure mode
	 * is an ADDED field, not a changed one. `assertSame()` over every text-entry
	 * field this plugin renders means a new one cannot appear without someone
	 * writing down, here, whether a placeholder belongs in it.
	 *
	 * ⚠ WHAT THIS TEST CANNOT PROVE. The insertion itself is JavaScript and no PHP
	 * assertion reaches it — see `docs/testing.md` § *Placeholder insertion — the
	 * seven manual cases*, which carries the browser verification instead. What PHP
	 * can prove is that the marker the script keys on is on every surface that
	 * should have it and on nothing else, which is precisely the half that was
	 * wrong.
	 *
	 * @return void
	 */
	public function test_every_placeholder_target_is_marked_and_nothing_else_is() {
		$this->become_manager();
		$this->use_our_screen();

		// ⚠ BOTH EDITOR PATHS. The marker reaches the body through `the_editor`, and
		// `wp_editor()` builds its markup differently depending on `user_can_richedit()`
		// — false for every merchant who ticked "Disable the visual editor when
		// writing", and on that path the textarea is the ONLY editing surface. A marker
		// present on one path only would leave those merchants with the defect this
		// test exists to prevent.
		foreach ( array( true, false ) as $rich ) {
			$filter = $rich ? '__return_true' : '__return_false';

			add_filter( 'user_can_richedit', $filter, 999 );

			try {
				$this->assertInsertableMap( $rich ? 'visual (TinyMCE)' : 'text-only (quicktags)' );
			} finally {
				remove_filter( 'user_can_richedit', $filter, 999 );
			}
		}

		fwrite(
			STDERR,
			"\n[P13G] insertable surfaces: 9 text-entry fields scanned on BOTH editor paths, exactly 3 "
			. 'marked (subject, heading, body) — the body through the `the_editor` filter, since '
			. 'wp_editor() takes no attribute arguments'
		);
	}

	/**
	 * Assert the insertable map for whichever editor path is currently active.
	 *
	 * @param string $path_name Human name of the path, for the failure message.
	 * @return void
	 */
	private function assertInsertableMap( string $path_name ): void {
		$markup = $this->capture(
			static function () {
				RuleEditor::render();
			}
		);

		$path = new \DOMXPath( self::parse( $markup ) );
		$map  = array();

		foreach ( array( 'input', 'textarea' ) as $tag ) {
			foreach ( $path->query( '//' . $tag ) as $control ) {
				$name = $control->getAttribute( 'name' );

				// Scoped to this plugin's own value-bearing fields. `wp_editor()`
				// emits quicktag buttons and a media button of its own, and the
				// targeting chips are checkboxes — none of them is somewhere a
				// placeholder can go, and the chip set is not fixed.
				if ( 0 !== strpos( $name, RuleFormInput::FIELD ) ) {
					continue;
				}

				$type = 'input' === $tag ? $control->getAttribute( 'type' ) : 'textarea';

				if ( in_array( $type, array( 'hidden', 'checkbox', 'radio' ), true ) ) {
					continue;
				}

				$map[ $name ] = $control->hasAttribute( 'data-extonify-wcep-insertable' );
			}
		}

		// Keyed comparison, not an ordered one: DOM order is the OTHER test's claim,
		// and this one must keep holding when a field moves between sections.
		ksort( $map );

		$this->assertSame(
			array(
				// One of the three the placeholder reference's own copy names.
				RuleFormInput::FIELD . '[content]'         => true,
				// A delay is a number; a placeholder is not a number.
				RuleFormInput::FIELD . '[delay_value]'     => false,
				RuleFormInput::FIELD . '[heading]'         => true,
				// A private label — its own description reads "Only you see this".
				RuleFormInput::FIELD . '[name]'            => false,
				RuleFormInput::FIELD . '[priority]'        => false,
				// Recipients accept {customer_email} and {store_email} only, typed
				// rather than clicked: the reference offers ~30 tokens and all but
				// those two would produce an invalid header.
				RuleFormInput::FIELD . '[recipients][bcc]' => false,
				RuleFormInput::FIELD . '[recipients][cc]'  => false,
				RuleFormInput::FIELD . '[recipients][to]'  => false,
				RuleFormInput::FIELD . '[subject]'         => true,
			),
			$map,
			'⚠ TIER 1 on the ' . $path_name . ' path: the set of fields a clicked placeholder can '
			. 'land in is not the set that should accept one. A field with the marker the merchant '
			. 'cannot see, and a content field without one, both put a token somewhere they did not '
			. 'intend.'
		);
	}

	/**
	 * GATE 33d. EVERY USER-FACING STRING IN `src/Admin/` IS TRANSLATABLE WITH THIS
	 *           PLUGIN'S TEXT DOMAIN, and no sentence is built by concatenation.
	 *
	 * ⚠ A CONCATENATED SENTENCE CANNOT BE TRANSLATED. A translator handed
	 * `__( 'Delay' ) . __( ' is invalid.' )` cannot reorder the halves, cannot
	 * decline the noun and cannot see which of the two they are working on.
	 *
	 * @return void
	 */
	public function test_every_admin_string_is_translatable_and_whole() {
		$files = glob( dirname( __DIR__, 2 ) . '/src/Admin/*.php' );

		$this->assertNotEmpty( $files );

		$calls       = 0;
		$wrong       = array();
		$concatenated = array();

		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file );
			$name   = basename( $file );

			preg_match_all(
				'/\b(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e|_n|_x|_nx)\s*\(/',
				$source,
				$found
			);

			$calls += count( $found[0] );

			// Every gettext call must name THIS domain. A call naming another domain
			// silently falls back to English on a translated site.
			preg_match_all( "/'([a-z0-9-]+)'\s*\)/", $source, $domains );

			foreach ( (array) $domains[1] as $domain ) {
				if ( 'extonify-custom-emails-per-product' === $domain ) {
					continue;
				}

				// Only flag strings that look like a text domain argument.
				if ( 1 === preg_match( '/^(default|woocommerce)$/', $domain ) ) {
					$wrong[] = $name . ': ' . $domain;
				}
			}

			// Two gettext calls joined by `.` on one line is a concatenated sentence.
			foreach ( explode( "\n", $source ) as $number => $line ) {
				if ( 1 === preg_match( '/__\(\s*[\'"][^\'"]*[\'"][^)]*\)\s*\.\s*[\'"]?\s*\.?\s*(esc_html__|esc_attr__|__)\s*\(/', $line ) ) {
					$concatenated[] = $name . ':' . ( $number + 1 );
				}
			}
		}

		$this->assertSame( array(), $wrong, '⚠ gettext calls naming another text domain: ' . implode( ', ', $wrong ) );
		$this->assertSame( array(), $concatenated, '⚠ concatenated sentences: ' . implode( ', ', $concatenated ) );
		$this->assertGreaterThan( 100, $calls, 'suspiciously few translated strings in src/Admin/.' );

		fwrite(
			STDERR,
			"\n[P9 gate 33] i18n: " . $calls . ' gettext calls across ' . count( $files )
			. " admin files, all on the plugin text domain, none concatenated\n"
		);
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Assert nothing in this markup can execute.
	 *
	 * ⚠ THE FOUR CHECKS ARE MADE ON THE MARKUP **OUTSIDE** TEXTAREA CONTENT, AND
	 * NOTHING IS RELAXED FOR ANY CALLER. Inside `<textarea>` the HTML parser is in
	 * RCDATA state: `"`, `'` and `=` have no meaning there, so a bare
	 * `onmouseover="…"` is text and not an attribute — which is why WordPress's own
	 * `format_for_editor()` escapes editor content with `ENT_NOQUOTES` and leaves
	 * the quotes alone. Applying an ordinary-HTML rule to RCDATA would therefore
	 * assert a property that is not the safety property, so RCDATA regions get
	 * `assertRcdataCannotEscape()` instead — a DIFFERENT and, in its own terms,
	 * stricter check.
	 *
	 * ⚠ AND THE SPLIT CANNOT LAUNDER AN ESCAPE. The splitter ends a region at the
	 * first `</textarea`, exactly where a browser ends RCDATA. A value that managed
	 * to emit `</textarea>` would therefore CLOSE its own region and push its
	 * remainder into the ordinary-HTML half, where all four checks above run at full
	 * strength — so neither half can be satisfied vacuously.
	 *
	 * @param string $markup Rendered markup.
	 * @param string $where  What was rendered.
	 * @return void
	 */
	private function assertInert( string $markup, string $where ): void {
		list( $html, $rcdata ) = self::split_rcdata( $markup );

		$this->assertStringNotContainsString(
			'<script>wcepXSS',
			$html,
			'⚠ TIER 1: a script element survived into ' . $where . '.'
		);

		$this->assertStringNotContainsString(
			'onmouseover="wcepXSS',
			$html,
			'⚠ TIER 1: a double-quoted attribute breakout survived into ' . $where . '.'
		);

		$this->assertStringNotContainsString(
			"onfocus='wcepXSS",
			$html,
			'⚠ TIER 1: a single-quoted attribute breakout survived into ' . $where . '.'
		);

		// A percent-encoded sequence must render as text, never be decoded into
		// markup on the way to the page.
		$this->assertStringNotContainsString(
			'%3Cscript%3EwcepXSS',
			rawurldecode( $html ),
			'⚠ TIER 1: a percent-encoded script survived decoding into ' . $where . '.'
		);

		foreach ( $rcdata as $index => $content ) {
			$this->assertRcdataCannotEscape( $content, $where . ', textarea #' . ( $index + 1 ) );
		}
	}

	/**
	 * Assert one textarea's content cannot leave RCDATA.
	 *
	 * ⚠ THE ONLY WAY OUT OF RCDATA IS `</textarea`, AND THE ONLY WAY TO WRITE THAT
	 * IS A RAW `<`. So the property asserted here is that the region contains no raw
	 * `<` at all, in any case-form and in any spelling — which is what
	 * `htmlspecialchars( …, ENT_NOQUOTES )` guarantees and what makes leaving the
	 * quotes alone correct. It is strictly stronger than searching for `</textarea`:
	 * it also rejects `<`-anything, so a future escaper that special-cased only the
	 * closing tag would still fail here.
	 *
	 * @param string $content One textarea's rendered content.
	 * @param string $where   What was rendered.
	 * @return void
	 */
	private function assertRcdataCannotEscape( string $content, string $where ): void {
		$this->assertSame(
			0,
			preg_match( '#<\s*/\s*textarea#i', $content ),
			'⚠ TIER 1: a closing textarea tag survived into the RCDATA of ' . $where . '.'
		);

		$this->assertStringNotContainsString(
			'<',
			$content,
			'⚠ TIER 1: a raw "<" survived into the RCDATA of ' . $where
			. ' — the one character that can end it.'
		);
	}

	/**
	 * Split markup into everything outside textarea content, and each textarea's
	 * content as a browser would delimit it.
	 *
	 * @param string $markup Rendered markup.
	 * @return array{0:string, 1:string[]} Ordinary HTML, then the RCDATA regions.
	 */
	private static function split_rcdata( string $markup ): array {
		$html   = '';
		$rcdata = array();
		$offset = 0;

		while ( 1 === preg_match( '#<textarea\b[^>]*>#i', $markup, $open, PREG_OFFSET_CAPTURE, $offset ) ) {
			$starts = (int) $open[0][1] + strlen( (string) $open[0][0] );
			$html  .= substr( $markup, $offset, $starts - $offset );

			$ends = stripos( $markup, '</textarea', $starts );

			if ( false === $ends ) {
				$rcdata[] = substr( $markup, $starts );

				return array( $html, $rcdata );
			}

			$rcdata[] = substr( $markup, $starts, $ends - $starts );
			$offset   = $ends;
		}

		$html .= substr( $markup, $offset );

		return array( $html, $rcdata );
	}

	/**
	 * Parse rendered markup, tolerating WordPress's own HTML.
	 *
	 * @param string $markup Rendered markup.
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

	/**
	 * A rule whose every free-text column is hostile.
	 *
	 * @return int
	 */
	private function make_hostile_rule(): int {
		$rule_id = Plugin::instance()->rules()->insert(
			array(
				'name'          => self::HOSTILE,
				'status'        => 'active',
				'trigger_type'  => 'status',
				'trigger_value' => 'completed',
				'delivery_mode' => 'separate',
				'targeting'     => array( 'match_all' => true ),
				'recipients'    => array( 'to' => array( self::HOSTILE ) ),
				'subject'       => self::HOSTILE,
				'heading'       => self::HOSTILE,
				'content'       => '<p>' . self::HOSTILE . '</p>',
			)
		);

		$this->assertGreaterThan( 0, $rule_id, 'Could not create the hostile rule.' );

		return $this->track_rule( $rule_id );
	}

	/**
	 * Write a rule name STRAIGHT INTO THE TABLE, past `RuleRepository::sanitize()`.
	 *
	 * The precedent is `ConsolidationTest::force_raw_consolidation()`: a column value
	 * the writing path would never produce, so the READING path is tested on its own.
	 *
	 * @param int    $rule_id Rule id.
	 * @param string $value   The raw name to store.
	 * @return void
	 */
	private function force_raw_name( int $rule_id, string $value ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only raw write to the plugin-owned table, deliberately bypassing the repository.
		$updated = $wpdb->update(
			\Extonify\WCEP\Install\Migrator::table( 'rules' ),
			array( 'name' => $value ),
			array( 'id' => $rule_id ),
			array( '%s' ),
			array( '%d' )
		);

		$this->assertNotFalse( $updated, 'Could not write the raw name fixture.' );

		$this->assertSame(
			$value,
			(string) $this->raw_rule( $rule_id )['name'],
			'⚠ the raw hostile name did not land in the column, so this test would prove nothing.'
		);
	}

	/**
	 * A product category whose NAME is hostile.
	 *
	 * @return int
	 */
	private function make_hostile_term(): int {
		$term = wp_insert_term( self::HOSTILE . ' ' . uniqid(), 'product_cat' );

		$this->assertIsArray( $term, 'Could not create the hostile term.' );

		$this->term_ids[] = (int) $term['term_id'];

		return (int) $term['term_id'];
	}
}
