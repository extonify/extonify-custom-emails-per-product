<?php
/**
 * PROMPT 13A ITEM 3 — the notice a merchant reads says what the delivery did.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Admin\DeliveryActions;
use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Admin\Notices;
use Extonify\WCEP\Delivery\Consolidation;
use Extonify\WCEP\Delivery\ManualDelivery;
use Extonify\WCEP\Domain\TriggerEvent;
use Extonify\WCEP\Email\EmailIdentity;

defined( 'ABSPATH' ) || exit;

/**
 * GATE 18, AT THE ONE SCREEN A MERCHANT ACTUALLY READS.
 *
 * ⚠ THE DEFECT: `TestDelivery::send()`, `ManualDelivery::send_manual()` and the resend
 * path each called the orchestrator, DISCARDED the `RunOutcome` it returned, and
 * reported `ok` with a fixed code. So a failed mailer, a `woocommerce_email_enabled_{id}`
 * refusal, a partially failed fan-out and zero messages sent all produced the same
 * sentence: *"The email was sent."*
 *
 * The delivery history recorded the truth throughout — the tombstone said `failed`, the
 * attempt rows said why. That is exactly what makes this worth fixing rather than
 * shrugging at: the plugin KNEW, and told the merchant the opposite, on the screen they
 * were looking at when they clicked.
 *
 * ⚠ EVERY ASSERTION HERE IS MADE ON THE RENDERED NOTICE, not on the outcome array. The
 * notice is what the merchant sees; a `code` that is correct and a sentence that is not
 * is the same defect one layer along. self::notice_for() drives the real redirect
 * through `Notices::render_request_notice()` with the real query arguments.
 */
final class DeliveryOutcomeNoticeTest extends ManualDeliveryTestCase {

	/**
	 * Gate lines.
	 *
	 * @var string[]
	 */
	private $gate = array();

	/**
	 * Filters this test registered.
	 *
	 * @var array[]
	 */
	private $filters = array();

	/**
	 * Print them.
	 *
	 * @after
	 * @return void
	 */
	protected function report_gate() {
		foreach ( $this->filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}

		$this->filters = array();

		foreach ( $this->gate as $line ) {
			fwrite( STDERR, "\n[P13A item 3 / gate 18] " . $line );
		}

		$this->gate = array();
	}

	/**
	 * 3a. EVERY MESSAGE SENT → a SUCCESS notice. The positive control.
	 *
	 * ⚠ WITHOUT IT, EVERY ASSERTION BELOW IS SATISFIED BY AN ACTION THAT NEVER
	 * SUCCEEDS. "Never reports success" is trivially true of a handler that always
	 * reports failure, and that would be a worse plugin, not a better one.
	 *
	 * @return void
	 */
	public function test_a_send_that_worked_reports_a_success() {
		$this->become_manager();

		$fixture = $this->undelivered_order();

		$outcome = $this->submit( DeliveryActions::ACTION_MANUAL, array( 'order' => $fixture['order_id'], 'rule' => $fixture['rule'] ) );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'the manual send was refused.' );
		$this->assertMailCount( 1, 'the manual send did not send.' );

		$notice = $this->notice_for( $outcome );

		$this->assertSame( 'wcep_sent_manual', $notice['code'] );
		$this->assertNoticeType( $notice, 'success' );

		$this->gate[] = 'all sent  => ' . $notice['code'] . ' (success)';
	}

	/**
	 * 3b. THE MAILER FAILED → an ERROR notice, never a success.
	 *
	 * @return void
	 */
	public function test_a_failed_mailer_never_reports_a_success() {
		$this->become_manager();

		$fixture = $this->undelivered_order();

		// ⚠ PRIORITY 11, AFTER THE SUITE'S CAPTURE AND BLOCKER. `pre_wp_mail` does not
		// short-circuit its own chain: WordPress runs every callback and acts on the
		// LAST return value. This is the same position `ConsolidationTest` uses to model
		// a transport failure, and it means the message IS handed over and rejected —
		// which is what a real mailer failure looks like.
		$this->filter( 'pre_wp_mail', '__return_false', 11 );

		$outcome = $this->submit( DeliveryActions::ACTION_MANUAL, array( 'order' => $fixture['order_id'], 'rule' => $fixture['rule'] ) );

		$this->assertSame( 'failed', $this->sole_status_for( $fixture['order_id'] ), 'the tombstone did not record a failure.' );

		$notice = $this->notice_for( $outcome );

		$this->assertSame( 'wcep_not_sent_failed', $notice['code'] );
		$this->assertNoticeType( $notice, 'error' );
		$this->assertNoticeIsNotSuccess( $notice, 'a failed mailer' );

		$this->gate[] = 'mailer failed => ' . $notice['code'] . ' (error); the tombstone says failed and so does the notice';
	}

	/**
	 * 3c. A DELIVERY-FILTER REFUSAL → a WARNING, and NOT the failure sentence either.
	 *
	 * ⚠ THE DISTINCTION IS ADR-0012 §5a's AND IT SURVIVES INTO THE NOTICE. A third party
	 * that looked at this order, this rule and these recipients and declined is not the
	 * mailer breaking. Telling the merchant to go and read the SMTP logs would send them
	 * to the wrong place entirely.
	 *
	 * @return void
	 */
	public function test_a_declined_delivery_reports_a_skip_not_a_success() {
		$this->become_manager();

		$fixture = $this->undelivered_order();

		$this->filter( 'woocommerce_email_enabled_' . EmailIdentity::EMAIL_ID, '__return_false', 10 );

		$outcome = $this->submit( DeliveryActions::ACTION_MANUAL, array( 'order' => $fixture['order_id'], 'rule' => $fixture['rule'] ) );

		$this->assertMailCount( 0, 'a declined delivery still sent.' );
		$this->assertSame( 'skipped', $this->sole_status_for( $fixture['order_id'] ) );

		$notice = $this->notice_for( $outcome );

		$this->assertSame( 'wcep_not_sent_skipped', $notice['code'] );
		$this->assertNoticeType( $notice, 'warning' );
		$this->assertNoticeIsNotSuccess( $notice, 'a delivery a filter declined' );

		$this->gate[] = 'filter declined => ' . $notice['code'] . ' (warning), told apart from the mailer-failure sentence';
	}

	/**
	 * 3d. A PARTIALLY FAILED FAN-OUT → a WARNING that NAMES HOW MANY.
	 *
	 * ⚠ THE SHAPE A SINGLE AGGREGATE CANNOT EXPRESS. `FanOutResult::run_action()`
	 * answers `failed` when any message fails, which is right for the tombstone and
	 * useless to a merchant: two of three customers DID receive their email, and a bare
	 * "nothing was sent" would send the merchant chasing three problems instead of one.
	 *
	 * @return void
	 */
	public function test_a_partly_failed_fan_out_names_how_many_went_out() {
		$this->become_manager();

		$products = array(
			$this->make_simple_product( 'WCEP Notice Alpha' ),
			$this->make_simple_product( 'WCEP Notice Bravo' ),
		);

		$rule_id = $this->make_rule(
			array(
				'name'          => 'WCEP outcome fan-out',
				'status'        => 'active',
				'delivery_mode' => 'separate',
				'trigger_type'  => 'status',
				'trigger_value' => 'completed',
				'consolidation' => Consolidation::PER_PRODUCT,
				'targeting'     => array( 'include' => array( 'products' => $products ) ),
				'recipients'    => array( 'to' => array( 'customer' ) ),
				'subject'       => 'About {product_name}',
				'content'       => '<p>About {product_name}.</p>',
			)
		);

		$order = $this->make_order_with( $products );
		$order->set_billing_email( 'wcep-fanout@example.test' );
		$order->save();

		$this->captured_mail = array();

		$breaker = static function ( $short_circuit, $atts ) {
			return false !== strpos( (string) ( $atts['subject'] ?? '' ), 'Bravo' ) ? false : $short_circuit;
		};

		$this->filter( 'pre_wp_mail', $breaker, 11, 2 );

		$outcome = $this->submit( DeliveryActions::ACTION_MANUAL, array( 'order' => (int) $order->get_id(), 'rule' => $rule_id ) );

		$this->assertMailCount( 2, 'the fan-out did not attempt both messages.' );
		$this->assertSame( 'failed', $this->sole_status_for( (int) $order->get_id() ), 'the tombstone did not aggregate to failed.' );

		$notice = $this->notice_for( $outcome );

		$this->assertSame( 'wcep_partly_sent', $notice['code'] );
		$this->assertNoticeType( $notice, 'warning' );
		$this->assertNoticeIsNotSuccess( $notice, 'a fan-out where one message failed' );

		// ⚠ THE COUNTS TRAVELLED AND THE SENTENCE USED THEM.
		$this->assertSame( 1, (int) ( $notice['args'][ Notices::ARG_SENT ] ?? 0 ), 'the sent count did not travel.' );
		$this->assertSame( 2, (int) ( $notice['args'][ Notices::ARG_TOTAL ] ?? 0 ), 'the total count did not travel.' );

		$this->assertStringContainsString( '1 of 2', $notice['html'], '⚠ the partial notice did not name how many went out.' );

		$this->gate[] = 'partial fan-out => ' . $notice['code'] . ' (warning) naming "1 of 2"; the tombstone says failed '
			. 'and the merchant is told which half worked';
	}

	/**
	 * 3e. A RESEND REPORTS ITS OWN OUTCOME TOO — the path is shared, and so is the bug
	 *     it used to have.
	 *
	 * @return void
	 */
	public function test_a_failed_resend_never_reports_a_success() {
		$this->become_manager();

		$fixture = $this->completed_delivery();

		$this->filter( 'pre_wp_mail', '__return_false', 11 );

		$outcome = $this->submit( DeliveryActions::ACTION_RESEND, array( 'delivery' => $fixture['delivery'] ) );

		$notice = $this->notice_for( $outcome );

		$this->assertSame( 'wcep_not_sent_failed', $notice['code'] );
		$this->assertNoticeIsNotSuccess( $notice, 'a failed resend' );

		$this->gate[] = 'resend, mailer failed => ' . $notice['code'] . ' (error)';
	}

	/**
	 * 3f. A TEST SEND REPORTS ITS OWN OUTCOME — the third path that used to hard-code
	 *     `ok`.
	 *
	 * @return void
	 */
	public function test_a_failed_test_send_never_reports_a_success() {
		$this->become_manager();

		$fixture = $this->undelivered_order();

		$this->filter( 'pre_wp_mail', '__return_false', 11 );

		$post = array(
			'action'                        => DeliveryActions::ACTION_TEST,
			DeliveryActions::FIELD_DELIVERY => 0,
			DeliveryActions::FIELD_ORDER    => $fixture['order_id'],
			DeliveryActions::FIELD_RULE     => $fixture['rule'],
			DeliveryActions::FIELD_ADDRESS  => 'wcep-outcome@example.test',
			DeliveryActions::FIELD_TOKEN    => $this->confirmation_token(
				DeliveryActions::ACTION_TEST,
				0,
				(int) $fixture['order_id'],
				(int) $fixture['rule'],
				'wcep-outcome@example.test'
			),
			DeliveryActions::FIELD_NONCE    => wp_create_nonce(
				DeliveryActions::nonce_action( DeliveryActions::ACTION_TEST, (int) $fixture['order_id'], (int) $fixture['rule'] )
			),
		);

		$this->use_method( 'POST' );
		$this->request( array( 'page' => Menu::HISTORY_PAGE ), $post );

		$outcome = DeliveryActions::handle( DeliveryActions::ACTION_TEST, $post );

		$this->assertSame( '', $this->refusal_of( $outcome ), 'the test send was refused: ' . $this->refusal_of( $outcome ) );

		$notice = $this->notice_for( $outcome );

		$this->assertSame( 'wcep_not_sent_failed', $notice['code'] );
		$this->assertNoticeIsNotSuccess( $notice, 'a failed test send' );

		$this->gate[] = 'test send, mailer failed => ' . $notice['code'] . ' (error), not "the test email was sent"';
	}

	/**
	 * 3g. A RUN THAT REPORTED NOTHING IS `none`, AND `none` IS NOT A SUCCESS.
	 *
	 * ⚠ ASSERTED AT THE SEAM RATHER THAN END TO END, AND THE REASON IS WORTH STATING.
	 * `Orchestrator::send_manual()` returns null only when orchestration is inert, and
	 * every caller checks `manual_send_is_available()` BEFORE claiming — so the null is
	 * unreachable from a merchant's click without a race inside a single request. It is
	 * still the value the method can return, and the mapping has to be right for the day
	 * it happens. Contriving the race would test the contrivance.
	 *
	 * @return void
	 */
	public function test_a_run_that_recorded_nothing_is_not_a_success() {
		$report = ManualDelivery::report_of( null );

		$this->assertSame( ManualDelivery::RESULT_NO_OUTCOME, $report['code'] );
		$this->assertSame( 0, $report['sent'] );
		$this->assertFalse( $report['recorded'] );

		$messages = Notices::request_messages();

		$this->assertArrayHasKey( 'wcep_not_sent_unknown', $messages, 'the no-outcome code has no sentence (gate 38).' );
		$this->assertSame( 'error', $messages['wcep_not_sent_unknown'][0], 'a run that recorded nothing renders as a success.' );

		// AND EVERY NON-SENT OUTCOME HAS A SENTENCE, so none of them can fall through to
		// a blank screen.
		foreach ( array( 'wcep_partly_sent', 'wcep_not_sent_failed', 'wcep_not_sent_skipped', 'wcep_not_sent_unknown' ) as $code ) {
			$this->assertArrayHasKey( $code, $messages, $code . ' has no sentence (gate 38).' );
			$this->assertNotSame( 'success', $messages[ $code ][0], '⚠ ' . $code . ' renders as a SUCCESS notice.' );
		}

		$this->gate[] = 'no outcome => none => wcep_not_sent_unknown (error); all four non-sent codes have their own '
			. 'sentence and not one of them is a success notice';
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Register a filter and remember it for teardown.
	 *
	 * @param string   $tag      Hook.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted arguments.
	 * @return void
	 */
	private function filter( string $tag, callable $callback, int $priority, int $args = 1 ): void {
		add_filter( $tag, $callback, $priority, $args );

		$this->filters[] = array( $tag, $callback, $priority );
	}

	/**
	 * The recorded status of the one tombstone an order has.
	 *
	 * ⚠ READ FROM THE ORDER, NOT FROM THE OUTCOME. A manual or test send reports
	 * `delivery_id = 0` to the admin layer — its tombstone is created inside the action
	 * and the redirect is keyed on the ORDER — so asserting on the outcome's id would
	 * silently assert on nothing.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	private function sole_status_for( int $order_id ): string {
		$rows = $this->tombstones_for( $order_id );

		$this->assertCount( 1, $rows, 'expected exactly one tombstone for order #' . $order_id . '.' );

		return (string) $rows[0]['final_status'];
	}

	/**
	 * The notice a handler outcome actually renders.
	 *
	 * ⚠ IT FOLLOWS THE REDIRECT RATHER THAN READING THE CODE. The merchant never sees
	 * the outcome array; they see whatever the screen draws when the browser lands on
	 * the URL, so that is what is asserted.
	 *
	 * @param array $outcome Handler outcome.
	 * @return array{code:string, args:array, html:string}
	 */
	private function notice_for( array $outcome ): array {
		$query = (string) wp_parse_url( (string) ( $outcome['url'] ?? '' ), PHP_URL_QUERY );

		$args = array();

		parse_str( $query, $args );

		$this->request( $args );

		$html = $this->capture(
			static function () {
				Notices::render_request_notice();
			}
		);

		return array(
			'code' => (string) ( $args[ Notices::ARG ] ?? '' ),
			'args' => $args,
			'html' => $html,
		);
	}

	/**
	 * Assert a rendered notice carries one WordPress notice class.
	 *
	 * @param array  $notice Result of self::notice_for().
	 * @param string $type   `success`, `warning` or `error`.
	 * @return void
	 */
	private function assertNoticeType( array $notice, string $type ): void {
		$this->assertStringContainsString(
			'notice-' . $type,
			$notice['html'],
			'the notice for ' . $notice['code'] . ' is not a ' . $type . ' notice: ' . $notice['html']
		);
	}

	/**
	 * Assert a rendered notice is not a success — the whole point of this file.
	 *
	 * @param array  $notice Result of self::notice_for().
	 * @param string $what   What produced it, for the failure message.
	 * @return void
	 */
	private function assertNoticeIsNotSuccess( array $notice, string $what ): void {
		$this->assertStringNotContainsString(
			'notice-success',
			$notice['html'],
			'⚠ ' . $what . ' rendered as a SUCCESS notice: ' . $notice['html']
		);

		$this->assertNotSame( '', $notice['html'], '⚠ ' . $what . ' rendered NO notice at all, which reads as "it worked".' );
	}
}
