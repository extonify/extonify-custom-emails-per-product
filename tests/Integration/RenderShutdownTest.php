<?php
/**
 * GATE 50 — the shutdown sweep builds nothing in a request that rendered nothing.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Render\RenderContext;
use Extonify\WCEP\Render\RenderEvents;
use Extonify\WCEP\Render\RenderLedger;

/**
 * `wp plugin uninstall <slug> --deactivate` used to exit 255 (Prompt 13C item 2, Tier 2).
 *
 * ⚠ WHAT ACTUALLY HAPPENED, AND IT IS A LIFECYCLE DEFECT RATHER THAN A RENDER ONE.
 * WP-CLI does deactivate → uninstall → **delete the plugin directory** in ONE process.
 * `RenderEvents::register()` put `on_shutdown()` on WordPress's `shutdown` action during
 * that process's own boot, so the hook fired after `src/` had been removed — and
 * `on_shutdown()` opened with `self::context()`, a LAZY constructor. The autoloader had
 * nothing left to include:
 *
 *     PHP Fatal error: Uncaught Error: Class "Extonify\WCEP\Render\RenderContext" not found
 *
 * The uninstall's data work had already completed correctly; the process simply died
 * afterwards with exit code 255, which is enough to break a deployment script.
 *
 * ⚠ THE FIX IS NOT "CHECK THE FILE EXISTS". It is that a request which never rendered
 * has, provably, nothing to reconcile — so the sweep must not BUILD the machinery it
 * would then find empty. This test asserts exactly that mechanism, because the fatal
 * itself needs a deleted directory and cannot be staged in-process. The end-to-end
 * evidence is `bin/wp-cli-uninstall-check.sh`, which runs the real command against a
 * throwaway install and asserts exit 0, no fatal, and the uninstall actually done.
 *
 * ⚠ AND THE OTHER DIRECTION IS ASSERTED TOO. A guard that made the sweep a no-op when
 * there IS something open would silently drop every abandoned render and every
 * unresolved send — the ADR-0013 §6 reporting this plugin exists to keep honest.
 */
final class RenderShutdownTest extends IntegrationTestCase {

	/**
	 * Gate lines.
	 *
	 * @var string[]
	 */
	private $gate = array();

	/**
	 * Leave the render singletons as this class found them.
	 *
	 * @after
	 * @return void
	 */
	protected function tear_down_render_shutdown() {
		RenderEvents::set_collaborators( null, null, null );

		foreach ( $this->gate as $line ) {
			fwrite( STDERR, "\n[P13C gate 50] " . $line );
		}

		$this->gate = array();
	}

	/**
	 * GATE 50. A REQUEST THAT RENDERED NOTHING CONSTRUCTS NOTHING AT SHUTDOWN.
	 *
	 * @return void
	 */
	public function test_the_shutdown_sweep_constructs_nothing_when_nothing_rendered() {
		RenderEvents::set_collaborators( null, null, null );

		$this->assertNull( $this->collaborator( 'context' ), 'the fixture did not start from a clean slate.' );
		$this->assertNull( $this->collaborator( 'ledger' ), 'the fixture did not start from a clean slate.' );

		RenderEvents::on_shutdown();

		$this->assertNull(
			$this->collaborator( 'context' ),
			'⚠ the shutdown sweep constructed a RenderContext in a request that never rendered. On the WP-CLI '
				. 'uninstall path the class file no longer exists at that moment, and this is the fatal.'
		);

		$this->assertNull(
			$this->collaborator( 'ledger' ),
			'⚠ the shutdown sweep constructed a RenderLedger in a request that never rendered.'
		);

		$this->gate[] = 'gate 50 (uninstall exit code): RenderEvents::on_shutdown() with no context and no ledger '
			. 'returned without constructing either — the lazy construction that made '
			. '`wp plugin uninstall --deactivate` exit 255 after the plugin directory was deleted';
	}

	/**
	 * GATE 50b. THE GUARD DOES NOT DISABLE THE SWEEP THAT DOES HAVE WORK.
	 *
	 * @return void
	 */
	public function test_the_shutdown_sweep_still_clears_a_ledger_that_holds_something() {
		$context = new RenderContext();
		$ledger  = new RenderLedger();
		$phase   = new RecordingPhaseSpy();

		RenderEvents::set_collaborators( $context, $ledger, $phase );

		$frame = $context->push( $this->rendering_email(), null, false, false );

		$ledger->open( $frame['token'], $this->rendering_email(), 0, 'customer_processing_order' );

		// ⚠ A RULE MUST BE REGISTERED OR THE SWEEP RIGHTLY IGNORES THE SLOT.
		// `take_open()` drops slots with no rules on purpose — nothing of this
		// plugin's went into that render, so there is nothing to report about it.
		$ledger->register( $frame['token'], 1, 1, 'after_order_table' );

		$this->assertNotSame( array(), $ledger->slots(), 'the fixture did not open a slot, so this proves nothing.' );

		RenderEvents::on_shutdown();

		$this->assertSame(
			array(),
			$ledger->slots(),
			'⚠ the shutdown sweep left an open slot on the request-shared ledger — `take_open()` is its only '
				. 'wholesale clearing path (gate 10), and the item 2 guard must not skip it.'
		);

		$this->assertSame( 0, $context->depth(), '⚠ the shutdown sweep left a render frame open.' );

		// ⚠ AND THE RENDER WAS REPORTED. A slot that never reached the send path is an
		// ABANDONED render (ADR-0013 §6a); clearing it silently would be the sweep
		// losing exactly the report it exists to produce.
		$this->assertSame(
			1,
			$phase->abandoned,
			'⚠ the shutdown sweep cleared the slot without recording the abandoned render.'
		);

		$this->assertSame( 0, $phase->unresolved, 'a render that never reached the send path was reported unresolved.' );

		$this->gate[] = 'gate 50b (the guard is not a kill switch): with one slot open the sweep still ran — 1 abandoned '
			. 'render recorded, 0 unresolved, ledger empty, frame stack at depth 0';
	}

	/**
	 * A stand-in for the email object a render is attributed to.
	 *
	 * Any object will do: the context keys frames on object identity, never on type.
	 *
	 * @return object
	 */
	private function rendering_email() {
		static $email = null;

		if ( null === $email ) {
			$email     = new \stdClass();
			$email->id = 'customer_processing_order';
		}

		return $email;
	}

	/**
	 * Read one of `RenderEvents`' private lazy collaborators without building it.
	 *
	 * ⚠ REFLECTION IS THE ASSERTION HERE, NOT A SHORTCUT. Every public accessor on that
	 * class CONSTRUCTS what it returns, so asking through one would create exactly the
	 * object this test exists to prove was never created.
	 *
	 * @param string $name Property name.
	 * @return mixed
	 */
	private function collaborator( string $name ) {
		$property = new \ReflectionProperty( RenderEvents::class, $name );

		$property->setAccessible( true );

		return $property->getValue();
	}
}
