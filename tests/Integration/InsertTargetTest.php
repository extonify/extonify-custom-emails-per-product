<?php
/**
 * ADR-0013 §2a — an insert target must be able to FIRE, not merely be registered.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\FieldOptions;
use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Admin\RuleEditor;
use Extonify\WCEP\Admin\RuleFormInput;
use Extonify\WCEP\Admin\Warnings;
use Extonify\WCEP\Email\EmailIdentity;
use Extonify\WCEP\Email\NativeEmailTargets;
use Extonify\WCEP\Plugin;
use Extonify\WCEP\Repository\RuleRepository;

/**
 * Every offered insert target can actually be reached, and the ones that cannot are
 * refused, warned about and marked.
 *
 * ⚠ SEVERITY: TIER 1, AND THE QUIETEST KIND. Insert mode's only entry point is
 * `woocommerce_email_order_details` (`Render\RenderEvents`, priorities 5 and 15). An
 * email whose template never fires it opens NO FRAME — so no evaluation runs, no slot
 * opens, no delivery row is written, and there is nothing in the history to look at.
 * The editor offered five such targets, including this plugin's own email. A merchant
 * could choose one, write content, save a rule that validated cleanly and read as
 * active, and never receive an email or an explanation.
 *
 * ⚠ THE 920-TEST SUITE MISSED IT BECAUSE NOTHING ENUMERATED THE OFFERED SET. Every
 * insert test picked `customer_processing_order` — a target that works — so the suite
 * proved insert mode works without ever asking whether everything the editor offers
 * does. `test_every_offered_target_can_be_reached_or_is_warned_about()` is written
 * against the WHOLE SET for that reason: it is the assertion whose absence let this
 * through, and it is the shape that caught Part K's rule-name defect too.
 */
final class InsertTargetTest extends InsertModeTestCase {

	/*
	 * ⚠ THE DELIVERY HARNESS **AND** THE ADMIN ONE. This file asserts a screen and a
	 * send in the same subject: the rules list must MARK an unreachable rule, and a
	 * reachable one must still reach the real WooCommerce email. `AdminHarness` is a
	 * trait for exactly this reason — `ManualDeliveryTestCase` combines the two the
	 * same way, because PHP has one parent and both halves are needed.
	 */
	use AdminHarness;

	/**
	 * The action the shared block template mentions. Spelled out rather than read from
	 * `NativeEmailTargets::HOOK`, so a rename there cannot make this test vacuous.
	 */
	const HOOK_IN_SHARED_TEMPLATE = 'woocommerce_email_order_details';

	/**
	 * Gate lines.
	 *
	 * @var string[]
	 */
	private $gate = array();

	/**
	 * Fixture template directory this test created, for teardown.
	 *
	 * @var string
	 */
	private $fixture_dir = '';

	/**
	 * Print them and clear the classifier's per-request memo.
	 *
	 * @after
	 * @return void
	 */
	protected function report_target_gate() {
		NativeEmailTargets::flush();

		if ( '' !== $this->fixture_dir && is_dir( $this->fixture_dir ) ) {
			foreach ( (array) glob( $this->fixture_dir . 'emails/*.php' ) as $file ) {
				unlink( $file );
			}

			rmdir( $this->fixture_dir . 'emails' );
			rmdir( $this->fixture_dir );

			$this->assertDirectoryDoesNotExist( $this->fixture_dir, 'a fixture template directory survived teardown.' );

			$this->fixture_dir = '';
		}

		foreach ( $this->gate as $line ) {
			fwrite( STDERR, "\n[13C-M ADR-0013 §2a] " . $line );
		}

		$this->gate = array();
	}

	// -----------------------------------------------------------------------
	// 1. The whole offered set, enumerated
	// -----------------------------------------------------------------------

	/**
	 * EVERY OFFERED TARGET EITHER PROVABLY RENDERS ORDER DETAILS OR IS WARNED ABOUT.
	 *
	 * ⚠ THE PROOF IS RE-DERIVED, NOT TAKEN FROM THE CLASSIFIER. Asserting
	 * `status_for() === RENDERS` would only prove the classifier agrees with itself.
	 * For every offered target this locates the template WooCommerce will actually
	 * render — through `wc_locate_template()`, so a theme override counts — and reads
	 * the hook out of the file. For an `unknown` target it renders the editor and
	 * requires the warning.
	 *
	 * @return void
	 */
	public function test_every_offered_target_can_be_reached_or_is_warned_about() {
		$offered = FieldOptions::native_emails();

		$this->assertNotSame( array(), $offered, 'the editor offers no insert targets at all.' );

		$proven  = array();
		$warned  = array();
		$broken  = array();

		foreach ( array_keys( $offered ) as $id ) {
			$status = NativeEmailTargets::status_for( $id );

			if ( NativeEmailTargets::NEVER === $status ) {
				$broken[] = $id . ' (offered but classified never)';
				continue;
			}

			if ( NativeEmailTargets::RENDERS === $status ) {
				if ( $this->template_of( $id ) ) {
					$proven[] = $id;
				} else {
					$broken[] = $id . ' (claims renders, but no template of its own fires the hook)';
				}
				continue;
			}

			// UNKNOWN — offered on purpose, and the merchant must be told.
			if ( in_array( 'insert_target_unverified', $this->warning_ids_for_target( $id ), true ) ) {
				$warned[] = $id;
			} else {
				$broken[] = $id . ' (unclassifiable and NOT warned about)';
			}
		}

		$this->assertSame(
			array(),
			$broken,
			'⚠ TIER 1: the editor offers insert targets that cannot fire and says nothing: ' . implode( ', ', $broken )
		);

		$this->assertGreaterThan( 5, count( $proven ) + count( $warned ), 'too few targets were checked.' );

		$this->gate[] = 'offered set enumerated: ' . count( $offered ) . ' of '
			. count( FieldOptions::native_email_titles() ) . ' registered — ' . count( $proven )
			. ' proven to fire ' . NativeEmailTargets::HOOK . ' from their own located template, '
			. count( $warned ) . ' unclassifiable and warned about, 0 silent';
	}

	/**
	 * AND EVERY EXCLUDED TARGET IS EXCLUDED FOR A REASON THAT HOLDS.
	 *
	 * The complement of the test above: nothing is hidden that would have worked.
	 *
	 * @return void
	 */
	public function test_every_excluded_target_provably_cannot_fire() {
		$excluded = array_diff(
			array_keys( FieldOptions::native_email_titles() ),
			array_keys( FieldOptions::native_emails() )
		);

		$this->assertNotSame( array(), $excluded, 'nothing was excluded, so the exclusion is not being exercised.' );

		$wrongly = array();

		foreach ( $excluded as $id ) {
			// This plugin's own email is excluded by identity, ahead of detection, and
			// `Custom_Email` renders through no template at all.
			if ( EmailIdentity::EMAIL_ID === $id ) {
				continue;
			}

			if ( $this->template_of( $id ) ) {
				$wrongly[] = $id;
			}
		}

		$this->assertSame(
			array(),
			$wrongly,
			'⚠ a target that DOES fire the hook was hidden from the editor: ' . implode( ', ', $wrongly )
		);

		$this->gate[] = 'excluded set: ' . implode( ', ', $excluded ) . ' — each verified to fire no '
			. NativeEmailTargets::HOOK . ' (this plugin\'s own email by identity)';
	}

	/**
	 * THIS PLUGIN'S OWN EMAIL IS NEVER AN INSERT TARGET.
	 *
	 * @return void
	 */
	public function test_the_plugins_own_email_is_not_an_offered_target() {
		$this->assertArrayNotHasKey(
			EmailIdentity::EMAIL_ID,
			FieldOptions::native_emails(),
			'⚠ the editor offers this plugin\'s own email as an insert target — a self-referential rule that renders no order details.'
		);

		$this->assertArrayHasKey(
			EmailIdentity::EMAIL_ID,
			FieldOptions::native_email_titles(),
			'the display map lost this plugin\'s own email, so a stored rule naming it would show a raw id.'
		);

		$this->assertTrue(
			NativeEmailTargets::cannot_render( EmailIdentity::EMAIL_ID ),
			'this plugin\'s own email is not classified as unreachable.'
		);

		$this->gate[] = 'self-reference: ' . EmailIdentity::EMAIL_ID . ' is excluded by identity, and still NAMEABLE for display';
	}

	// -----------------------------------------------------------------------
	// 1c. The block email editor — the configuration Part M never varied
	// -----------------------------------------------------------------------

	/**
	 * WITH THE BLOCK EMAIL EDITOR ON, A SHARED TEMPLATE PROVES NOTHING.
	 *
	 * ⚠ THIS IS PART M'S TIER 1 DEFECT RE-OPENED THROUGH A DIFFERENT DOOR, AND THE
	 * ENUMERATION ABOVE COULD NOT SEE IT BECAUSE IT NEVER VARIED THIS FLAG.
	 * `WC_Email::__construct()` sets `block_email_editor_enabled` from a GLOBAL feature
	 * flag, so with the feature on EVERY email carries WooCommerce's shared
	 * `emails/block/general-block-email.php` as a candidate. That file contains the
	 * hook, but fires it behind
	 * `isset( $order ) && ! in_array( $email->id, $emails_without_order_details, true )`
	 * — a list that always holds `customer_reset_password`, `customer_new_account` and
	 * `customer_verify_email`. A substring test over one file serving eighteen emails
	 * returned `RENDERS` for the very emails it excludes.
	 *
	 * The rule now is that a shared file yields at most `UNKNOWN`: offered, and warned
	 * about. This asserts the whole offered set under BOTH settings of the flag.
	 *
	 * @return void
	 */
	public function test_the_shared_block_template_never_proves_a_target() {
		$before = $this->block_editor_flags();

		/*
		 * ⚠ THE BLOCK EMAIL EDITOR DOES NOT EXIST BELOW WooCommerce 9.9, AND THE FLOOR
		 * RUNS 9.6.0. `class-wc-email.php` there contains ZERO occurrences of
		 * `block_email_editor_enabled` and ships no `templates/emails/block/` directory.
		 * The first version of this test asserted the flag's PRESENCE as a precondition
		 * and failed the floor corner for it — a test defect, not a plugin one: the
		 * classifier itself was correct on 9.6.0, offering 10 of 13 with 0 unknown and
		 * 0 silent.
		 *
		 * ⚠ AND THE ANSWER IS NOT A SKIP. A skipped test proves nothing about the floor,
		 * and "the platform has no block editor" is itself a state worth asserting: with
		 * no shared candidate there must be nothing unclassifiable, which is the same
		 * guarantee this test makes on 11.0.1 by a different route.
		 */
		if ( array() === $before ) {
			$only = NativeEmailTargets::classify();

			$this->assertNotContains(
				NativeEmailTargets::UNKNOWN,
				$only,
				'⚠ on a platform with no block email editor nothing should be unclassifiable.'
			);

			$this->gate[] = 'block editor: this WooCommerce has none (no email carries the flag) — '
				. $this->tally( $only ) . ', nothing shared, nothing unclassifiable';

			return;
		}

		// --- OFF: the shipped default, and Part M's numbers must not move ----------
		$this->set_block_editor( false );

		$off = NativeEmailTargets::classify();

		$this->assertNotContains(
			NativeEmailTargets::UNKNOWN,
			$off,
			'⚠ with the block editor OFF nothing should be unclassifiable on a stock store.'
		);

		// --- ON: every candidate gains the shared template -------------------------
		$this->set_block_editor( true );

		$on = NativeEmailTargets::classify();

		$regressed = array();
		$softened  = array();

		foreach ( $off as $id => $was ) {
			$now = $on[ $id ] ?? '';

			// ⚠ THE ASSERTION THAT MATTERS. A target that verifiably cannot fire must
			// never become RENDERS just because a file it shares with seventeen others
			// mentions the hook.
			if ( NativeEmailTargets::NEVER === $was && NativeEmailTargets::RENDERS === $now ) {
				$regressed[] = $id;
			}

			if ( NativeEmailTargets::NEVER === $was && NativeEmailTargets::UNKNOWN === $now ) {
				$softened[] = $id;
			}
		}

		$this->assertSame(
			array(),
			$regressed,
			'⚠ TIER 1: the shared block template promoted an unreachable target to RENDERS: ' . implode( ', ', $regressed )
		);

		// And everything now offered is still either proven or warned about.
		$silent = array();

		foreach ( array_keys( FieldOptions::native_emails() ) as $id ) {
			if ( NativeEmailTargets::RENDERS === NativeEmailTargets::status_for( $id ) ) {
				if ( ! $this->template_of( $id ) ) {
					$silent[] = $id . ' (claims renders with no email-specific proof)';
				}
				continue;
			}

			if ( ! in_array( 'insert_target_unverified', $this->warning_ids_for_target( $id ), true ) ) {
				$silent[] = $id . ' (offered, unproven and unwarned)';
			}
		}

		$this->assertSame(
			array(),
			$silent,
			'⚠ TIER 1: with the block editor ON the editor offers targets it cannot prove and does not warn about: '
			. implode( ', ', $silent )
		);

		$this->restore_block_editor( $before );

		$this->gate[] = 'block editor: OFF -> ' . $this->tally( $off ) . ' | ON -> ' . $this->tally( $on )
			. ' — ' . count( $softened ) . ' unreachable targets softened to UNKNOWN (offered + warned), 0 promoted to RENDERS';
	}

	/**
	 * THE SHARED FILE IS FOUND BY OBSERVATION, NOT BY PATH.
	 *
	 * @return void
	 */
	public function test_the_shared_template_is_detected_by_fan_out() {
		$before = $this->block_editor_flags();

		// Below WooCommerce 9.9 there is no general block template, so nothing is shared
		// — and that ABSENCE is the correct outcome, asserted rather than skipped.
		if ( array() === $before ) {
			$this->assertSame(
				array(),
				$this->shared_template_files(),
				'⚠ a template is shared on a platform that has no general block template.'
			);

			$this->gate[] = 'fan-out: this WooCommerce registers no shared email template at all — '
				. 'the rule has nothing to exclude and excludes nothing';

			return;
		}

		$this->set_block_editor( true );

		$fanout = array();

		foreach ( (array) WC()->mailer()->get_emails() as $email ) {
			if ( ! is_object( $email ) || ! isset( $email->id ) || ! isset( $email->template_block_content ) ) {
				continue;
			}

			$base = isset( $email->template_base ) ? (string) $email->template_base : '';
			$file = wc_locate_template( (string) $email->template_block_content, '', $base );

			if ( is_string( $file ) && is_readable( $file ) ) {
				$fanout[ $file ][ (string) $email->id ] = true;
			}
		}

		$shared = array();

		foreach ( $fanout as $file => $ids ) {
			if ( count( $ids ) > 1 ) {
				$shared[ $file ] = count( $ids );
			}
		}

		$this->assertNotSame(
			array(),
			$shared,
			'no template is shared with the block editor ON, so the fan-out rule is not being exercised.'
		);

		foreach ( $shared as $file => $count ) {
			$this->assertStringContainsString(
				self::HOOK_IN_SHARED_TEMPLATE,
				(string) file_get_contents( $file ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				'the shared template no longer mentions the hook, so this test proves nothing.'
			);

			$this->gate[] = 'fan-out: ' . basename( $file ) . ' serves ' . $count
				. ' distinct email ids and mentions ' . self::HOOK_IN_SHARED_TEMPLATE
				. ' — it can prove nothing about any one of them';
		}

		$this->restore_block_editor( $before );
	}

	// -----------------------------------------------------------------------
	// 1d. NEVER requires that nothing further can be included
	// -----------------------------------------------------------------------

	/**
	 * A TEMPLATE THAT INCLUDES A HOOK-FIRING PARTIAL IS NOT `NEVER`.
	 *
	 * ⚠ THE ORIGINAL DEFECT INVERTED, AND WORSE IN ONE RESPECT. WooCommerce's own order
	 * emails are built around `emails/email-order-details.php`, so a template whose own
	 * text lacks the hook but which includes a partial firing it is a WORKING target.
	 * Part M classified it `NEVER`, which is not offered and — unlike `UNKNOWN` —
	 * says nothing.
	 *
	 * ⚠ AND THE THIRD CASE IS WHY THIS DID NOT SIMPLY WIDEN EVERYTHING TO `UNKNOWN`.
	 * A template with no hook, no includes and nothing dynamic really is unreachable,
	 * and must stay `NEVER` — otherwise the five targets Part M closed are re-offered.
	 * All three outcomes are asserted here against real fixture templates resolved
	 * through `wc_locate_template()` exactly as a real one would be.
	 *
	 * @return void
	 */
	public function test_never_requires_that_nothing_further_can_be_included() {
		$dir = $this->fixture_templates();

		$email = $this->one_registered_email( 'customer_new_account' );

		$restore = array(
			'template_html'  => $email->template_html,
			'template_plain' => $email->template_plain,
			'template_base'  => $email->template_base ?? '',
		);

		$cases = array(
			'includes a partial that fires the hook'       => array( 'emails/wcep-includes-hook.php', NativeEmailTargets::UNKNOWN ),
			'includes a name it cannot resolve'            => array( 'emails/wcep-dynamic-include.php', NativeEmailTargets::UNKNOWN ),
			'has no hook, no includes and nothing dynamic' => array( 'emails/wcep-inert.php', NativeEmailTargets::NEVER ),
		);

		try {
			foreach ( $cases as $label => $case ) {
				list( $template, $expected ) = $case;

				$email->template_html  = $template;
				$email->template_plain = '';
				$email->template_base  = $dir;

				NativeEmailTargets::flush();

				$this->assertSame(
					$expected,
					NativeEmailTargets::status_for( (string) $email->id ),
					'⚠ a template that ' . $label . ' was classified wrongly.'
				);

				$this->gate[] = 'include depth: a template that ' . $label . ' => ' . strtoupper( $expected );
			}
		} finally {
			$email->template_html  = $restore['template_html'];
			$email->template_plain = $restore['template_plain'];
			$email->template_base  = $restore['template_base'];

			NativeEmailTargets::flush();
		}
	}

	// -----------------------------------------------------------------------
	// 1e. The filter may not overrule a fact the plugin owns
	// -----------------------------------------------------------------------

	/**
	 * NO FILTER CAN RE-ENABLE THIS PLUGIN'S OWN EMAIL AS AN INSERT TARGET.
	 *
	 * ⚠ PART M ROUTED THE SELF-REFERENCE RETURN THROUGH `filtered()`, CONTRADICTING THE
	 * DOCBLOCK DIRECTLY ABOVE IT — which stated that `Custom_Email.php` contains zero
	 * occurrences of the hook and that "no store configuration can change either fact".
	 * A filter corrects what detection cannot see; it does not overrule a fact this
	 * plugin owns about its own code.
	 *
	 * @return void
	 */
	public function test_no_filter_can_re_enable_the_plugins_own_email() {
		$force = static function () {
			return NativeEmailTargets::RENDERS;
		};

		add_filter( 'extonify_wcep_insert_target_status', $force, 10 );
		NativeEmailTargets::flush();

		try {
			$this->assertSame(
				NativeEmailTargets::NEVER,
				NativeEmailTargets::status_for( EmailIdentity::EMAIL_ID ),
				'⚠ a filter re-enabled this plugin\'s own email as an insert target.'
			);

			$this->assertArrayNotHasKey(
				EmailIdentity::EMAIL_ID,
				FieldOptions::native_emails(),
				'⚠ a filter put this plugin\'s own email back in the editor\'s target list.'
			);

			// The escape hatch still works for everything else, or it would be useless.
			$this->assertSame(
				NativeEmailTargets::RENDERS,
				NativeEmailTargets::status_for( $this->an_admin_addressed_or_any_other_email() ),
				'the filter no longer reaches a third-party email, so the escape hatch is gone.'
			);
		} finally {
			remove_filter( 'extonify_wcep_insert_target_status', $force, 10 );
			NativeEmailTargets::flush();
		}

		$this->gate[] = 'filter scope: ' . EmailIdentity::EMAIL_ID . ' is returned before the filter runs and '
			. 'cannot be re-enabled; every other id remains filterable';
	}

	// -----------------------------------------------------------------------
	// 2. A rule that cannot fire does not present as working
	// -----------------------------------------------------------------------

	/**
	 * A STORED RULE WITH AN UNREACHABLE TARGET IS REFUSED, WARNED ABOUT AND MARKED.
	 *
	 * ⚠ WRITTEN PAST THE REPOSITORY, BECAUSE THAT IS THE ONLY WAY IT CAN EXIST NOW.
	 * The editor no longer offers such a target and `write_is_valid()` refuses it, so
	 * the row is created with `$wpdb->update()` directly — exactly the state an import,
	 * a WP-CLI write, a direct SQL edit or a theme change would leave behind.
	 *
	 * @return void
	 */
	public function test_a_rule_with_an_unreachable_target_does_not_present_as_working() {
		$unreachable = $this->an_unreachable_email();

		// 1. The write boundary refuses it outright.
		$refused = Plugin::instance()->rules()->insert(
			array(
				'name'            => 'WCEP unreachable target',
				'status'          => 'active',
				'delivery_mode'   => 'insert',
				'native_email_id' => $unreachable,
				'insert_position' => 'after_order_table',
				'targeting'       => array( 'include' => array( 'products' => array( 12 ) ) ),
				'content'         => '<p>Body</p>',
			)
		);

		$this->assertSame( 0, $refused, '⚠ the repository stored an insert rule against an email that can never fire.' );

		$refusal = Plugin::instance()->rules()->explain_refusal(
			array(
				'name'            => 'x',
				'status'          => 'active',
				'delivery_mode'   => 'insert',
				'native_email_id' => $unreachable,
			)
		);

		$this->assertSame( 'native_email_id', $refusal['field'] ?? '', 'the refusal does not name the field.' );
		$this->assertSame(
			RuleRepository::REFUSED_NO_ORDER_DETAILS,
			$refusal['code'] ?? '',
			'the refusal does not give the reason.'
		);

		// And the merchant-facing sentence for that code exists and says what is wrong.
		$this->assertStringContainsString(
			'no order details section',
			\Extonify\WCEP\Admin\Notices::refusal_message( 'native_email_id', RuleRepository::REFUSED_NO_ORDER_DETAILS ),
			'the refusal code has no explanation a merchant can read.'
		);

		// 2. A row that got in anyway is warned about in the editor…
		$rule_id = $this->force_unreachable_rule( $unreachable );

		$this->assertContains(
			'insert_target_cannot_render',
			$this->warning_ids_for_target( $unreachable ),
			'⚠ the editor says nothing about a rule whose target can never fire.'
		);

		// …and marked on the list, which is the only screen it appears on until opened.
		$this->become_manager();
		set_current_screen( 'woocommerce_page_' . Menu::PAGE );

		/*
		 * ⚠ `prepare()` BEFORE `render()`, BECAUSE THAT IS WHAT A REAL REQUEST DOES.
		 * `RuleList` holds the table in a STATIC, built on the first render of the
		 * process. A test that only calls `render()` therefore displays whichever rows
		 * some earlier test class prepared — this assertion passed alone and failed
		 * beside `AdminOutputTest` for exactly that reason. `Menu` prepares at
		 * `load-{page}` and renders at output time; doing both here is the faithful
		 * sequence, not a workaround for one.
		 */
		$markup = $this->capture(
			static function () {
				\Extonify\WCEP\Admin\RuleList::prepare();
				\Extonify\WCEP\Admin\RuleList::render();
			}
		);

		$this->assertStringContainsString(
			'extonify-wcep-rule-broken',
			$markup,
			'⚠ the rules list shows an unreachable rule exactly like a working one.'
		);

		$this->assertStringContainsString(
			'has no order details section',
			$markup,
			'the list marker does not say what is wrong.'
		);

		Plugin::instance()->rules()->delete( $rule_id );

		$this->gate[] = 'unreachable target ' . $unreachable . ': write REFUSED (' . RuleRepository::REFUSED_NO_ORDER_DETAILS
			. '), editor warns, rules list marks it — no path presents it as working';
	}

	// -----------------------------------------------------------------------
	// 3. The regression guard, and the escape hatch
	// -----------------------------------------------------------------------

	/**
	 * A VALID INSERT RULE STILL DELIVERS — the whole point of the filtering is that it
	 * removes only what could never have worked.
	 *
	 * Driven through the real `WC_Email::trigger()`, so the real template, the real
	 * hook order and the real send path all run.
	 *
	 * @return void
	 */
	public function test_a_valid_insert_rule_against_a_reachable_target_still_delivers() {
		$this->assertArrayHasKey(
			'customer_processing_order',
			FieldOptions::native_emails(),
			'⚠ the filtering removed a target that has always worked.'
		);

		$product_id = $this->make_simple_product( 'WCEP Insert Target Regression' );
		$order_id   = (int) $this->make_order_with( array( $product_id ) )->get_id();

		$this->make_insert_rule(
			$product_id,
			array(
				'native_email_id' => 'customer_processing_order',
				'content'         => '<p>WCEPINSERTTARGETREGRESSION</p>',
			)
		);

		$mail = $this->send_native( $order_id );

		$this->assertIsArray( $mail, 'the native email was not sent at all.' );

		$this->assertStringContainsString(
			'WCEPINSERTTARGETREGRESSION',
			(string) $mail['message'],
			'⚠ REGRESSION: a valid insert rule no longer reaches the WooCommerce email.'
		);

		$this->gate[] = 'regression: an insert rule on customer_processing_order still reaches the real email';
	}

	/**
	 * A THIRD-PARTY ORDER EMAIL CAN BE DECLARED REACHABLE AND BECOMES SELECTABLE.
	 *
	 * ⚠ THE ESCAPE HATCH IS THE REASON A BLOCKLIST WAS REJECTED. Detection reads
	 * templates; an email that fires the hook some way this cannot see reads `unknown`,
	 * and its author can say otherwise. The filtered value is validated against the
	 * three constants, so a plugin returning nonsense cannot make a target's status
	 * unknowable.
	 *
	 * @return void
	 */
	public function test_a_filter_can_add_a_target_back_and_cannot_return_nonsense() {
		$excluded = $this->an_unreachable_email();

		$declare = static function ( $status, $id ) use ( $excluded ) {
			return $id === $excluded ? NativeEmailTargets::RENDERS : $status;
		};

		add_filter( 'extonify_wcep_insert_target_status', $declare, 10, 2 );
		NativeEmailTargets::flush();

		$this->assertArrayHasKey(
			$excluded,
			FieldOptions::native_emails(),
			'⚠ a site cannot declare a third-party order email reachable.'
		);

		remove_filter( 'extonify_wcep_insert_target_status', $declare, 10 );

		$nonsense = static function () {
			return 'probably';
		};

		add_filter( 'extonify_wcep_insert_target_status', $nonsense, 10 );
		NativeEmailTargets::flush();

		$this->assertContains(
			NativeEmailTargets::status_for( $excluded ),
			NativeEmailTargets::STATUSES,
			'⚠ a filter returning a value outside the vocabulary was taken at face value.'
		);

		$this->assertSame(
			NativeEmailTargets::NEVER,
			NativeEmailTargets::status_for( $excluded ),
			'a filter returning nonsense changed the answer instead of being discarded.'
		);

		remove_filter( 'extonify_wcep_insert_target_status', $nonsense, 10 );
		NativeEmailTargets::flush();

		$this->gate[] = 'filter: a third-party order email can be declared ' . NativeEmailTargets::RENDERS
			. ' and becomes selectable; a value outside the vocabulary is discarded, not guessed at';
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * The current `block_email_editor_enabled` flag of every registered email.
	 *
	 * @return array<string,bool>
	 */
	private function block_editor_flags(): array {
		$out = array();

		foreach ( (array) WC()->mailer()->get_emails() as $key => $email ) {
			if ( is_object( $email ) && property_exists( $email, 'block_email_editor_enabled' ) ) {
				$out[ (string) $key ] = (bool) $email->block_email_editor_enabled;
			}
		}

		return $out;
	}

	/**
	 * Set the block flag on every registered email.
	 *
	 * ⚠ THE PROPERTY, NOT THE FEATURE OPTION. `WC_Email::__construct()` reads
	 * `FeaturesUtil::feature_is_enabled( 'block_email_editor' )` ONCE, so flipping the
	 * option changes nothing until the mailer is rebuilt — and rebuilding it mid-suite
	 * would swap out the very objects other tests hold. The detector reads this property
	 * and nothing else, so setting it exercises exactly the code under test with no
	 * dependence on WooCommerce's feature plumbing.
	 *
	 * @param bool $on Whether the block email editor is enabled.
	 * @return void
	 */
	private function set_block_editor( bool $on ): void {
		foreach ( (array) WC()->mailer()->get_emails() as $email ) {
			if ( is_object( $email ) && property_exists( $email, 'block_email_editor_enabled' ) ) {
				$email->block_email_editor_enabled = $on;
			}
		}

		NativeEmailTargets::flush();
	}

	/**
	 * Put the flags back exactly as they were found.
	 *
	 * @param array<string,bool> $flags From self::block_editor_flags().
	 * @return void
	 */
	private function restore_block_editor( array $flags ): void {
		foreach ( (array) WC()->mailer()->get_emails() as $key => $email ) {
			if ( is_object( $email ) && array_key_exists( (string) $key, $flags ) ) {
				$email->block_email_editor_enabled = $flags[ (string) $key ];
			}
		}

		NativeEmailTargets::flush();
	}

	/**
	 * A one-line tally of a classification map.
	 *
	 * @param array<string,string> $map id => status.
	 * @return string
	 */
	private function tally( array $map ): string {
		$counts = array_fill_keys( NativeEmailTargets::STATUSES, 0 );

		foreach ( $map as $status ) {
			++$counts[ $status ];
		}

		return sprintf(
			'renders=%d unknown=%d never=%d',
			$counts[ NativeEmailTargets::RENDERS ],
			$counts[ NativeEmailTargets::UNKNOWN ],
			$counts[ NativeEmailTargets::NEVER ]
		);
	}

	/**
	 * Absolute template files that serve more than one email id, as things stand.
	 *
	 * @return array<string,int>
	 */
	private function shared_template_files(): array {
		$seen = array();

		foreach ( (array) WC()->mailer()->get_emails() as $email ) {
			if ( ! is_object( $email ) || ! isset( $email->id ) ) {
				continue;
			}

			foreach ( array( 'template_html', 'template_plain', 'template_block_content' ) as $property ) {
				if ( ! isset( $email->$property ) || ! is_string( $email->$property ) || '' === $email->$property ) {
					continue;
				}

				$base = isset( $email->template_base ) ? (string) $email->template_base : '';
				$file = wc_locate_template( $email->$property, '', $base );

				if ( is_string( $file ) && is_readable( $file ) ) {
					$seen[ $file ][ (string) $email->id ] = true;
				}
			}
		}

		$out = array();

		foreach ( $seen as $file => $ids ) {
			if ( count( $ids ) > 1 ) {
				$out[ $file ] = count( $ids );
			}
		}

		return $out;
	}

	/**
	 * One registered email object, by id.
	 *
	 * @param string $id WooCommerce email id.
	 * @return object
	 */
	private function one_registered_email( string $id ) {
		foreach ( (array) WC()->mailer()->get_emails() as $email ) {
			if ( is_object( $email ) && isset( $email->id ) && (string) $email->id === $id ) {
				return $email;
			}
		}

		$this->fail( 'this store does not register ' . $id . '.' );
	}

	/**
	 * Any registered id other than this plugin's own, for the filter-scope test.
	 *
	 * @return string
	 */
	private function an_admin_addressed_or_any_other_email(): string {
		foreach ( array_keys( FieldOptions::native_email_titles() ) as $id ) {
			if ( EmailIdentity::EMAIL_ID !== $id ) {
				return $id;
			}
		}

		$this->fail( 'this store registers no email other than the plugin\'s own.' );
	}

	/**
	 * Three fixture templates, written where `wc_locate_template()` will find them.
	 *
	 * ⚠ RESOLVED THE REAL WAY. `wc_locate_template( $name, '', $base )` falls back to
	 * `$base . $name`, so pointing an email's `template_base` at this directory makes
	 * the detector locate these exactly as it locates WooCommerce's own — no stubbing of
	 * the resolver, and the theme-override branch still runs first and finds nothing.
	 *
	 * @return string The base directory, with a trailing slash.
	 */
	private function fixture_templates(): string {
		if ( '' !== $this->fixture_dir ) {
			return $this->fixture_dir;
		}

		$base = trailingslashit( get_temp_dir() ) . 'wcep-target-fixtures-' . wp_generate_password( 8, false ) . '/';

		$this->assertTrue( wp_mkdir_p( $base . 'emails' ), 'could not create the fixture template directory.' );

		$hook = self::HOOK_IN_SHARED_TEMPLATE;

		$files = array(
			// Fires the hook itself — the partial the next one includes.
			'emails/wcep-partial-with-hook.php' => "<?php do_action( '{$hook}', \$order, false, false, \$email );",
			// No hook of its own, but includes the partial above, by literal name.
			'emails/wcep-includes-hook.php'     => "<?php wc_get_template(\n\t'emails/wcep-partial-with-hook.php',\n\tarray()\n);",
			// No hook, and an include this cannot resolve to a name.
			'emails/wcep-dynamic-include.php'   => "<?php \$name = 'emails/whatever.php'; wc_get_template( \$name, array() );",
			// No hook, no includes, nothing dynamic.
			'emails/wcep-inert.php'             => "<?php echo '<p>nothing to see</p>';",
		);

		foreach ( $files as $name => $body ) {
			$this->assertNotFalse(
				file_put_contents( $base . $name, $body ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
				'could not write the fixture template ' . $name . '.'
			);
		}

		$this->fixture_dir = $base;

		return $base;
	}

	/**
	 * Whether any template this email renders through contains the hook — re-derived
	 * here rather than read from the classifier.
	 *
	 * @param string $id WooCommerce email id.
	 * @return bool
	 */
	private function template_of( string $id ): bool {
		foreach ( (array) WC()->mailer()->get_emails() as $email ) {
			if ( ! is_object( $email ) || ! isset( $email->id ) || (string) $email->id !== $id ) {
				continue;
			}

			foreach ( array( 'template_html', 'template_plain' ) as $property ) {
				if ( ! isset( $email->$property ) || ! is_string( $email->$property ) || '' === $email->$property ) {
					continue;
				}

				$base = isset( $email->template_base ) ? (string) $email->template_base : '';
				$file = wc_locate_template( $email->$property, '', $base );

				if ( is_string( $file ) && is_readable( $file )
					&& false !== strpos( (string) file_get_contents( $file ), NativeEmailTargets::HOOK ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * One registered email this store cannot insert into.
	 *
	 * ⚠ READ FROM THE CLASSIFICATION, NOT PINNED TO A LITERAL. WooCommerce renames
	 * email ids across versions, and a test pinned to `customer_new_account` would skip
	 * silently past the case it exists to cover on a store that calls it something
	 * else. This plugin's own email is skipped so the test exercises the DETECTION
	 * rather than the identity shortcut.
	 *
	 * @return string
	 */
	private function an_unreachable_email(): string {
		foreach ( NativeEmailTargets::classify() as $id => $status ) {
			if ( NativeEmailTargets::NEVER === $status && EmailIdentity::EMAIL_ID !== $id ) {
				return $id;
			}
		}

		$this->fail( '⚠ this store registers no non-order WooCommerce email, so the case cannot be covered.' );
	}

	/**
	 * The warning ids the editor produces for an insert rule on one target.
	 *
	 * @param string $target `native_email_id`.
	 * @return string[]
	 */
	private function warning_ids_for_target( string $target ): array {
		$fields = array(
			'name'            => 'WCEP target fixture',
			'status'          => 'inactive',
			'trigger_type'    => 'status',
			'trigger_status'  => 'completed',
			'delivery_mode'   => 'insert',
			'native_email_id' => $target,
			'insert_position' => 'after_order_table',
			'delay_value'     => 0,
			'delay_unit'      => 'minutes',
			'consolidation'   => 'none',
			'match_all'       => '1',
			'subject'         => 'Subject',
			'heading'         => 'Heading',
			'content'         => '<p>Body</p>',
			'priority'        => 10,
			'recipients'      => array( 'to' => 'customer' ),
		);

		return array_map(
			static function ( $warning ) {
				return (string) $warning['id'];
			},
			Warnings::for_form( RuleFormInput::from_post( array( RuleFormInput::FIELD => $fields ) ) )
		);
	}

	/**
	 * Write a rule with an unreachable target PAST the repository's validation.
	 *
	 * @param string $unreachable A `never` email id.
	 * @return int
	 */
	private function force_unreachable_rule( string $unreachable ): int {
		global $wpdb;

		$rule_id = Plugin::instance()->rules()->insert(
			array(
				'name'            => 'WCEP forced unreachable',
				'status'          => 'active',
				'delivery_mode'   => 'insert',
				'native_email_id' => 'customer_processing_order',
				'insert_position' => 'after_order_table',
				'targeting'       => array( 'include' => array( 'products' => array( 12 ) ) ),
				'content'         => '<p>Body</p>',
			)
		);

		$this->assertGreaterThan( 0, $rule_id, 'the seed rule was not stored.' );

		$table = \Extonify\WCEP\Install\Migrator::table( 'rules' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only write that deliberately bypasses the write boundary, exactly as ConsolidationTest::force_raw_consolidation() does.
		$wpdb->update( $table, array( 'native_email_id' => $unreachable ), array( 'id' => $rule_id ), array( '%s' ), array( '%d' ) );

		return $this->track_rule( $rule_id );
	}
}
