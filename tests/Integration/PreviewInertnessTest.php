<?php
/**
 * GATES 40 and 41 — a preview writes nothing, and cannot poison a later real send.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Delivery\RulePreview;
use Extonify\WCEP\Domain\TriggerEvent;
use Extonify\WCEP\Render\RenderContext;
use Extonify\WCEP\Render\RenderEvents;

/**
 * The two properties that make preview safe, asserted rather than assumed.
 *
 * ⚠ SEVERITY: both gates here are TIER 1. A preview that writes a delivery record
 * fabricates history for an email nobody received; a preview that suppresses a later
 * real record loses a delivery silently, which is worse — the customer got the email and
 * the merchant has no evidence of it.
 */
final class PreviewInertnessTest extends PreviewTestCase {

	/**
	 * Gate lines printed at the end of the run.
	 *
	 * @var string[]
	 */
	private $gate = array();

	/**
	 * Print the gate lines.
	 *
	 * @after
	 * @return void
	 */
	protected function report_gate() {
		foreach ( $this->gate as $line ) {
			fwrite( STDERR, "\n[P12 gates 40-41] " . $line );
		}

		$this->gate = array();
	}

	/**
	 * GATE 40a. A SEPARATE-MODE PREVIEW WRITES NOTHING, SCHEDULES NOTHING AND SENDS
	 *           NOTHING.
	 *
	 * @return void
	 */
	public function test_a_separate_mode_preview_writes_nothing() {
		$fixture = $this->previewable();

		$before = $this->delivery_checksums();
		$jobs   = $this->scheduled_job_count();

		$preview = null;

		$statements = $this->record_statements(
			function () use ( $fixture, &$preview ) {
				$preview = RulePreview::render( $fixture['rule'], $fixture['order_id'] );
			}
		);

		$this->assertSame(
			RulePreview::OK,
			$preview['outcome'],
			'the separate-mode preview refused: ' . $preview['code']
		);

		// The premise: it really did render something, so "nothing was written" is not
		// passing because nothing happened.
		$this->assertNotSame( '', $preview['html'], 'the preview produced no HTML, so this test proves nothing.' );
		$this->assertNotSame( '', $preview['plain'], 'the preview produced no plain text.' );

		$this->assertPreviewWroteNothing( $before, $this->delivery_writes( $statements ), $jobs, 'a separate-mode rule' );

		$this->gate[] = 'gate 40 (separate): a preview that produced ' . strlen( (string) $preview['html'] ) . ' bytes of HTML and '
			. strlen( (string) $preview['plain'] ) . ' bytes of plain text wrote 0 rows to either delivery table '
			. '(content checksums identical), issued 0 writes, scheduled 0 actions and sent 0 messages';
	}

	/**
	 * GATE 40b. AN INSERT-MODE PREVIEW WRITES NOTHING — and leaves no ledger residue
	 *           either, which is the half that only insert mode can fail.
	 *
	 * @return void
	 */
	public function test_an_insert_mode_preview_writes_nothing_and_leaves_no_ledger_residue() {
		$fixture = $this->previewable_insert();

		$before = $this->delivery_checksums();
		$jobs   = $this->scheduled_job_count();

		$preview = null;

		$statements = $this->record_statements(
			function () use ( $fixture, &$preview ) {
				$preview = RulePreview::render( $fixture['rule'], $fixture['order_id'] );
			}
		);

		$this->assertSame(
			RulePreview::OK,
			$preview['outcome'],
			'the insert-mode preview refused: ' . $preview['code']
		);

		// ⚠ THE PREMISE, AND IT IS THE WHOLE TEST. If the plugin's content did not
		// actually reach the native email, then "no slot was created" would be true for
		// the boring reason that nothing was injected.
		$this->assertStringContainsString(
			'INSERTED PREVIEW BLOCK.',
			(string) $preview['html'],
			'⚠ the insert-mode preview did not inject the rule\'s content, so nothing below proves anything.'
		);

		$this->assertPreviewWroteNothing( $before, $this->delivery_writes( $statements ), $jobs, 'an insert-mode rule' );

		// GATE 40c: no ledger residue. A preview creates a FRAME and NO SLOT
		// (ADR-0013 §5), so after it there is nothing at all left to reconcile.
		$this->assertNoLedgerResidue( 'an insert-mode rule' );

		// And the shutdown sweep — the thing that files abandoned renders as
		// `unresolved` — finds nothing to report either.
		RenderEvents::on_shutdown();

		$this->assertSame(
			$before,
			$this->delivery_checksums(),
			'⚠ TIER 1: the shutdown sweep recorded something for a preview.'
		);

		$this->gate[] = 'gate 40 (insert): a preview that DID inject the rule\'s block into the native email wrote 0 rows, '
			. '0 ledger slots, 0 reservations, 0 candidates, 0 render records and 0 open tokens; the shutdown sweep '
			. 'then reported nothing unresolved';
	}

	/**
	 * GATE 41a. AN INTERRUPTED PREVIEW DOES NOT STOP THE NEXT REAL SEND RECORDING —
	 *           in the SAME request.
	 *
	 * ⚠ THE THROW IS PUT INSIDE THE RENDER, BETWEEN PUSH AND POP, which is the state
	 * ADR-0013 §5 is about: a preview frame that was never closed. Throwing before the
	 * render began would leave nothing behind and the test would pass vacuously.
	 *
	 * @return void
	 */
	public function test_an_interrupted_preview_does_not_poison_the_next_real_send() {
		$insert = $this->previewable_insert();

		// A callback that throws part way through the native email's own render, i.e.
		// after `on_render_start()` has pushed the preview frame.
		$this->hook(
			'woocommerce_email_order_details',
			static function () {
				throw new \RuntimeException( 'a third party blew up inside the preview render' );
			},
			7,
			4
		);

		$preview = RulePreview::render( $insert['rule'], $insert['order_id'] );

		$this->assertSame(
			RulePreview::REFUSED,
			$preview['outcome'],
			'the interrupted preview did not refuse, so it did not actually throw.'
		);

		$this->assertSame(
			RulePreview::REFUSED_RENDER_FAILED,
			$preview['code'],
			'the interrupted preview refused for the wrong reason.'
		);

		// The throw was CONTAINED: it did not escape into the admin request.
		$this->assertTrue( true, 'the throw did not escape RulePreview.' );

		// ⚠ AND OUR OWN `finally` TOOK CORE'S SIGNAL BACK OFF, which is the entire
		// difference between this plugin's preview and core's.
		$this->assertFalse(
			RenderContext::core_preview_signal(),
			'⚠ TIER 1: an interrupted preview LEAKED woocommerce_is_email_preview, exactly as core does.'
		);

		// Now perform a REAL delivery, in this same request, and assert it records.
		$this->captured_mail = array();

		$real = $this->previewable();

		$this->orchestrator()->run( $real['order'], TriggerEvent::status( 'completed' ) );

		$tombstone = $this->tombstone( $real['order_id'], $real['rule'], 'status:completed' );

		$this->assertIsArray(
			$tombstone,
			'⚠ TIER 1: after an interrupted preview, a real delivery in the same request wrote NO tombstone.'
		);

		$this->track_delivery( (int) $tombstone['id'] );

		$this->assertSame(
			'sent',
			(string) $tombstone['final_status'],
			'⚠ TIER 1: after an interrupted preview, the real delivery did not record as sent.'
		);

		$this->assertNotSame(
			array(),
			$this->detail_rows( (int) $tombstone['id'] ),
			'⚠ TIER 1: after an interrupted preview, the real delivery wrote no attempt row.'
		);

		$this->assertMailCount( 1, 'the real delivery did not send exactly one message.' );

		$this->gate[] = 'gate 41 (interrupted preview): a preview thrown mid-render refuses with render_failed, does NOT '
			. 'leak woocommerce_is_email_preview, and a REAL delivery in the SAME request still writes its tombstone, '
			. 'its attempt row and its message';
	}

	/**
	 * GATE 41b. A LEAKED CORE SIGNAL IS DEMOTED, AND BOTH DELIVERY PATHS RECOVER.
	 *
	 * ⚠ THIS SIMULATES SOMEBODY ELSE'S INTERRUPTED PREVIEW, WHICH IS WHAT ADR-0013 §5 IS
	 * ACTUALLY ABOUT. This plugin's own preview cleans up in a `finally`, so it cannot
	 * produce this state; core's `EmailPreview::render_preview_email()` can and does,
	 * because it has no `try`/`finally` (verified WC 11.0.1). The leak is therefore
	 * staged exactly as core leaves it — the filter still attached — and the stale
	 * preview frame is produced by a GENUINELY interrupted render rather than by hand,
	 * so the state under test is one the runtime can really reach.
	 *
	 * ⚠ AND IT ASSERTS BOTH GUARDS. `RenderContext` (insert mode) and
	 * `Orchestrator::is_rendering_preview()` (ADR-0012 §8's coarse guard, separate mode)
	 * read the same signal, and until this prompt only the first one demoted it — so a
	 * leaked signal silently made every separate-mode delivery in the request inert.
	 * ⚠ THAT DEFECT WAS FOUND BY THIS TEST; see ADR-0020 §1b.
	 *
	 * @return void
	 */
	public function test_a_leaked_core_signal_is_demoted_and_the_real_send_still_records() {
		$context = RenderEvents::context();

		if ( ! RenderContext::core_signal_available() ) {
			$this->markTestSkipped( 'This WooCommerce exposes no woocommerce_is_email_preview signal to leak.' );
		}

		$insert = $this->previewable_insert();

		// 1. SOMEBODY ELSE'S PREVIEW LEAKS THE SIGNAL and never takes it off again.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce-owned hook, deliberately left attached to reproduce core's own defect.
		$this->hook( 'woocommerce_is_email_preview', '__return_true', 10 );

		$this->assertTrue(
			RenderContext::core_preview_signal(),
			'the leak was not staged, so this test would prove nothing.'
		);

		// 2. AND IT IS INTERRUPTED MID-RENDER, leaving a stale PREVIEW frame — which is
		// what the leak plus an abandoned frame together prove.
		$this->stage_interrupted_third_party_preview( $insert['order_id'] );

		$this->assertSame( 1, $context->depth(), 'the interrupted render left no stale frame, so nothing can be demoted.' );
		$this->assertTrue( (bool) $context->current()['is_preview'], 'the stale frame is not a preview frame.' );

		// 3. A REAL INSERT-MODE SEND NOW. Its `push()` reconciles, PROVES the leak, and
		// classifies itself as a real render — so it opens a slot and records.
		$this->captured_mail = array();

		$sent = $this->send_native_email( $insert['order_id'] );

		$this->assertNotNull( $sent, '⚠ TIER 1: with the signal leaked, the native email produced no message at all.' );

		$this->assertTrue(
			$context->signal_leaked(),
			'⚠ TIER 1: the leaked signal was NOT demoted, so every later render would classify itself as a preview.'
		);

		$tombstone = $this->insert_tombstone_for( $insert['order_id'], $insert['rule'] );

		$this->assertIsArray(
			$tombstone,
			'⚠ TIER 1: with core\'s preview signal leaked, a real INSERT delivery wrote no record — the exact defect '
			. 'ADR-0013 §5\'s demotion exists to prevent.'
		);

		$this->assertSame( 'sent', (string) $tombstone['final_status'], '⚠ TIER 1: the real insert delivery did not record as sent.' );

		// 4. AND THE SEPARATE-MODE COARSE GUARD RECOVERS TOO (ADR-0020 §1b). Before this
		// prompt `Orchestrator::is_rendering_preview()` read the raw signal, so with the
		// leak still attached `run()` returned null and the delivery vanished silently.
		$this->assertTrue( RenderContext::core_preview_signal(), 'the leak healed itself, so the coarse guard is untested.' );

		$this->assertTrue(
			\Extonify\WCEP\Delivery\Orchestrator::is_operational(),
			'⚠ TIER 1: the separate-mode guard still believes a signal that has been proven leaked, so every '
			. 'separate-mode delivery in this request would be silently skipped.'
		);

		$this->captured_mail = array();

		$real = $this->previewable();

		$this->orchestrator()->run( $real['order'], TriggerEvent::status( 'completed' ) );

		$separate = $this->tombstone( $real['order_id'], $real['rule'], 'status:completed' );

		$this->assertIsArray(
			$separate,
			'⚠ TIER 1: with the signal leaked, a real SEPARATE-mode delivery wrote no tombstone — silently lost.'
		);

		$this->track_delivery( (int) $separate['id'] );

		$this->assertSame( 'sent', (string) $separate['final_status'], '⚠ TIER 1: the separate delivery did not record as sent.' );
		$this->assertMailCount( 1, '⚠ TIER 1: the separate delivery sent no message.' );

		$this->gate[] = 'gate 41 (leaked signal): with woocommerce_is_email_preview stuck true AND a stale preview frame '
			. 'from a genuinely interrupted render, reconciliation DEMOTES the signal; the real insert delivery then '
			. 'records `sent`, and the separate-mode coarse guard stands down too so its delivery records and sends. '
			. '⚠ the separate-mode half FAILED before this prompt (ADR-0020 §1b)';
	}

	/**
	 * Leave the request in the state core's interrupted preview leaves it in: the signal
	 * still attached, and a stale PREVIEW frame nobody closed.
	 *
	 * ⚠ THE FRAME IS PRODUCED BY A REAL RENDER THAT THROWS, not by calling `push()` by
	 * hand. A hand-pushed frame proves only that the reconciler reads its own data
	 * structure; an abandoned frame from a genuine render is the state the runtime
	 * actually reaches, and it is the one ADR-0013 §5 describes.
	 *
	 * @param int $order_id Order to render.
	 * @return void
	 */
	private function stage_interrupted_third_party_preview( int $order_id ): void {
		$exploder = static function () {
			throw new \RuntimeException( 'a third party blew up inside somebody else\'s preview' );
		};

		add_action( 'woocommerce_email_order_details', $exploder, 7, 4 );

		$depth = ob_get_level();

		try {
			$this->send_native_email( $order_id );
		} catch ( \Throwable $expected ) {
			unset( $expected );
		} finally {
			remove_action( 'woocommerce_email_order_details', $exploder, 7 );

			// ⚠ `wc_get_template_html()` HAS NO try/finally (WC 11.0.1), so the throw
			// above leaks its output buffer. The HARNESS cleans up after the third party
			// here, exactly as `RulePreview::as_preview()` cleans up after itself.
			while ( ob_get_level() > $depth ) {
				ob_end_clean();
			}
		}
	}

	/**
	 * Send one native WooCommerce email for an order, through its own `trigger()`.
	 *
	 * @param int $order_id Order id.
	 * @return array|null The captured message.
	 */
	private function send_native_email( int $order_id ): ?array {
		$emails = WC()->mailer()->get_emails();

		$this->assertArrayHasKey( 'WC_Email_Customer_Processing_Order', $emails, 'the native email fixture is not registered.' );

		$emails['WC_Email_Customer_Processing_Order']->trigger( $order_id );

		return $this->last_mail();
	}

	/**
	 * The insert-mode tombstone for one rule on one order, or null.
	 *
	 * @param int $order_id Order id.
	 * @param int $rule_id  Rule id.
	 * @return array|null
	 */
	private function insert_tombstone_for( int $order_id, int $rule_id ): ?array {
		$row = $this->deliveries->find(
			$order_id,
			$rule_id,
			\Extonify\WCEP\Delivery\DeliveryLogger::MODE_INSERT,
			\Extonify\WCEP\Domain\DeliveryIdentity::native( 'customer_processing_order' )
		);

		if ( null !== $row ) {
			$this->track_delivery( (int) $row['id'] );
		}

		return $row;
	}

	/**
	 * GATE 41c. A PREVIEW'S OWN MARKER NEVER SURVIVES INTO A LATER REAL SEND.
	 *
	 * ⚠ THE SEPARATE-MODE CASE, WHICH IS THE ONE THAT CAN ACTUALLY FAIL. A separate-mode
	 * preview fires no `woocommerce_email_order_details`, so NOTHING consumes the pending
	 * marker the way a render would — and an unconsumed marker on the request-shared
	 * `Custom_Email` would classify the next REAL send of that object as a preview.
	 *
	 * @return void
	 */
	public function test_a_separate_preview_leaves_no_pending_marker_on_the_shared_email() {
		$fixture = $this->previewable();

		$preview = RulePreview::render( $fixture['rule'], $fixture['order_id'] );

		$this->assertSame( RulePreview::OK, $preview['outcome'], 'the preview refused: ' . $preview['code'] );

		$email = $this->live_email();

		$this->assertNotNull( $email, 'the live email object is missing.' );

		// The marker is gone: a render pushed for this very object is NOT a preview.
		$frame = RenderEvents::context()->push( $email, $fixture['order'], false, false );

		$this->assertFalse(
			(bool) $frame['is_preview'],
			'⚠ TIER 1: a separate-mode preview left its pending marker on the shared email object, so the next REAL '
			. 'send of that object would classify itself as a preview and write no record.'
		);

		RenderEvents::context()->cleanup( $email, $fixture['order'], (string) $frame['token'] );

		// And the real thing: a genuine delivery through that same shared object records.
		$this->captured_mail = array();

		$this->orchestrator()->run( $fixture['order'], TriggerEvent::status( 'completed' ) );

		$tombstone = $this->tombstone( $fixture['order_id'], $fixture['rule'], 'status:completed' );

		$this->assertIsArray( $tombstone, '⚠ TIER 1: a real delivery after a preview of the SAME rule wrote no tombstone.' );

		$this->track_delivery( (int) $tombstone['id'] );

		$this->assertSame( 'sent', (string) $tombstone['final_status'], '⚠ TIER 1: it did not record as sent.' );
		$this->assertMailCount( 1, 'the real delivery did not send.' );

		$this->gate[] = 'gate 41 (marker): a separate-mode preview — which fires no order-details hook and so has no '
			. 'render to consume its marker — gives the marker back, and a real delivery of the SAME rule through the '
			. 'SAME shared email object afterwards records normally';
	}
}
