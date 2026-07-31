<?php
/**
 * The production render-depth bound (ADR-0013 §5c).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Extonify\WCEP\Render\RenderContext;
use Extonify\WCEP\Render\RenderEvents;

/**
 * A third party that re-enters a WooCommerce email render hook must not be able
 * to make THIS PLUGIN amplify its recursion.
 *
 * WHAT THE BOUND IS FOR, AND IT IS A DENIAL-OF-SERVICE FIX. Every frame this
 * plugin accepts costs a frame record, a render record, a ledger slot, two
 * open-token entries and — the expensive one — one rule evaluation, which is a
 * database query. Measured before the bound existed: 41 nested renders were
 * accepted and produced 41 evaluations. The plugin never originates a recursion,
 * so the amplification is the only part of this it controls; these tests pin the
 * amplification to `MAX_RENDER_DEPTH` and prove that everything past it costs
 * NOTHING, emits nothing, and leaves the real render untouched.
 *
 * ⚠ WHAT IS DELIBERATELY NOT CLAIMED. The recursion itself is not stopped and
 * cannot be: it loops through the third party's own closure and WordPress's
 * dispatcher, and never re-enters this plugin. See ADR-0013 §5c.
 */
class RenderDepthTest extends InsertModeTestCase {

	/**
	 * Gate 14 / gate 6. PAST THE BOUND, A RENDER COSTS EXACTLY ZERO QUERIES.
	 *
	 * Drives `RenderEvents::on_render_start()` directly, which is precisely what
	 * WordPress does when a template fires `woocommerce_email_order_details`, and
	 * measures the query cost OF THAT CALL at each level. Driving a full nested
	 * template render instead would measure WooCommerce's own template queries
	 * along with ours and prove nothing about which of them stopped.
	 *
	 * @return void
	 */
	public function test_a_render_past_the_depth_bound_costs_no_queries_and_creates_nothing() {
		global $wpdb;

		$product_id = $this->make_simple_product( 'WCEP Depth Bound' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$this->make_insert_rule( $product_id, array( 'content' => '<p>DEPTH BLOCK.</p>' ) );

		$email = $this->native_email();
		$limit = RenderContext::MAX_RENDER_DEPTH;
		$depth = 20;

		$this->assertGreaterThan( $limit, $depth, 'The probe must go past the bound to prove anything.' );

		$costs = array();

		for ( $level = 1; $level <= $depth; $level++ ) {
			$before = $wpdb->num_queries;

			RenderEvents::on_render_start( $order, false, false, $email );

			$costs[ $level ] = $wpdb->num_queries - $before;
		}

		$context = RenderEvents::context();
		$ledger  = RenderEvents::ledger();

		// --- Accepted frames evaluate; refused frames do not exist to us. -----
		$this->assertCount( $depth, $this->frames_of( $context ), 'A refused render was not left poppable.' );
		$this->assertCount( $limit, $context->renders(), 'A refused render created a render record.' );
		$this->assertCount( $limit, $ledger->slots(), 'A refused render opened a ledger slot.' );
		$this->assertSame( $depth - $limit, $context->refused_renders() );

		/*
		 * --- ZERO QUERIES BEYOND THE LIMIT. Not "fewer" — zero. ----------------
		 *
		 * ⚠ WITH ONE ACCOUNTED-FOR EXCEPTION, AND IT IS THE PROOF OF THE
		 * "LOG ONCE PER REQUEST" RULE RATHER THAN A LEAK. The FIRST refusal writes a
		 * single diagnostic through `wc_get_logger()`, and WooCommerce's default log
		 * handler is backed by the database, so that one call costs queries. Every
		 * refusal after it costs exactly zero — which is what makes the amplification
		 * bounded no matter how deep the recursion goes.
		 */
		$first_refusal = $costs[ $limit + 1 ];
		$beyond        = 0;

		for ( $level = $limit + 2; $level <= $depth; $level++ ) {
			$beyond += $costs[ $level ];
			$this->assertSame( 0, $costs[ $level ], "Level {$level} ran a query past the depth bound." );
		}

		$this->assertSame( 0, $beyond, 'Repeated refusals cost queries.' );
		$this->assertGreaterThan( 0, $costs[1], 'The first render did not evaluate at all — the probe proves nothing.' );

		// And the log really is once per request: a second storm of refusals, after
		// the first one has logged, costs nothing whatsoever.
		$before_second = $wpdb->num_queries;

		for ( $level = 0; $level < 25; $level++ ) {
			RenderEvents::on_render_start( $order, false, false, $email );
		}

		$this->assertSame(
			0,
			$wpdb->num_queries - $before_second,
			'25 further refused renders cost queries — the once-per-request log is per level.'
		);
		$this->assertSame( $depth - $limit + 25, $context->refused_renders() );

		for ( $level = 0; $level < 25; $level++ ) {
			RenderEvents::on_render_end( $order, false, false, $email );
		}

		// --- And the refused frames unwind cleanly, restoring the real one. ---
		for ( $level = 1; $level <= $depth; $level++ ) {
			RenderEvents::on_render_end( $order, false, false, $email );
		}

		$this->assertSame( array(), $this->frames_of( $context ), 'The refused markers did not pop.' );
		$this->assertNull( $context->current() );
		$this->assertFalse( $context->in_email() );

		fwrite(
			STDERR,
			sprintf(
				"\n[5B item 2 / gate 14] depth bound %d, probed to %d:\n"
				. "  accepted frames %d  render records %d  ledger slots %d  refused %d\n"
				. "  query cost level 1 = %d, level %d = %d (last accepted)\n"
				. "  level %d = %d (first refusal; includes the once-per-request WC log write), levels %d-%d = %d, next 25 refusals = 0\n",
				$limit,
				$depth,
				$depth,
				count( $context->renders() ),
				count( $ledger->slots() ),
				$depth - $limit,
				$costs[1],
				$limit,
				$costs[ $limit ],
				$limit + 1,
				$first_refusal,
				$limit + 2,
				$depth,
				$beyond
			)
		);

		// Housekeeping: those 16 slots carried no rules, so the sweep drops them
		// silently (ADR-0013 §6) and writes nothing.
		$this->run_shutdown_sweep();
		$this->assertSame( array(), $this->tombstones_for( $order_id ) );
	}

	/**
	 * Gate 14. NOTHING IS EMITTED INTO A REFUSED RENDER — not even when it shares
	 * the outer render's order, email id and audience.
	 *
	 * This is why the refusal pushes a marker instead of returning. With nothing
	 * pushed, the OUTER frame would still be top of stack, the shared item hook
	 * would still report an active render, and the outer render's content would be
	 * emitted into the refused inner one.
	 *
	 * @return void
	 */
	public function test_a_refused_render_receives_no_emission_even_with_the_outer_identity() {
		$product_id = $this->make_simple_product( 'WCEP Refused Emission' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$this->make_insert_rule(
			$product_id,
			array(
				'insert_position' => 'order_meta',
				'content'         => '<p>REFUSED EMISSION BLOCK.</p>',
			)
		);

		$email = $this->native_email();

		// Fill the stack to the bound with real renders, then one past it.
		for ( $level = 0; $level < RenderContext::MAX_RENDER_DEPTH; $level++ ) {
			RenderEvents::on_render_start( $order, false, false, $email );
		}

		$accepted = RenderEvents::context()->renders();
		$this->assertCount( RenderContext::MAX_RENDER_DEPTH, $accepted );

		$innermost = end( $accepted );
		$this->assertNotEmpty( $innermost['rules'], 'The fixture rule never matched, so the test cannot prove refusal.' );

		RenderEvents::on_render_start( $order, false, false, $email );

		$marker = RenderEvents::context()->current();
		$this->assertNotNull( $marker );
		$this->assertTrue( ! empty( $marker['refused'] ), 'The frame on top of the stack is not the refused marker.' );

		// SAME order, SAME email object, SAME audience as the enclosing render.
		ob_start();

		try {
			do_action( 'woocommerce_email_order_meta', $order, false, false, $email );
			do_action( 'woocommerce_order_item_meta_end', 0, null, $order, false );
		} finally {
			$emitted = (string) ob_get_clean();
		}

		$this->assertStringNotContainsString(
			'REFUSED EMISSION BLOCK.',
			$emitted,
			'Content was emitted into a refused render.'
		);
		$this->assertSame( '', trim( $emitted ), 'A refused render produced output.' );

		// --- The enclosing render is untouched and emits normally again. -------
		RenderEvents::on_render_end( $order, false, false, $email );

		ob_start();

		try {
			do_action( 'woocommerce_email_order_meta', $order, false, false, $email );
		} finally {
			$after = (string) ob_get_clean();
		}

		$this->assertStringContainsString(
			'REFUSED EMISSION BLOCK.',
			$after,
			'The enclosing render stopped emitting after a refused one closed.'
		);

		fwrite(
			STDERR,
			"\n[5B item 2 / gate 14] refused render, identical order/email/audience: emitted "
			. strlen( trim( $emitted ) ) . " bytes; the enclosing render then emitted normally\n"
		);

		// Unwind the rest.
		for ( $level = 0; $level < RenderContext::MAX_RENDER_DEPTH; $level++ ) {
			RenderEvents::on_render_end( $order, false, false, $email );
		}

		$this->run_shutdown_sweep();
		$this->assertNotSame( array(), $this->tombstones_for( $order_id ), 'The enclosing render recorded nothing at all.' );
	}

	/**
	 * Gate 14. A REAL third-party recursion: the outer send is unaffected and
	 * still finalises with its content.
	 *
	 * The nesting here goes through WordPress's dispatcher exactly as a
	 * third-party plugin's would — a callback on
	 * `woocommerce_email_before_order_table` firing
	 * `woocommerce_email_order_details` again — so the whole chain runs: push,
	 * template, pop.
	 *
	 * @return void
	 */
	public function test_the_outer_send_survives_a_real_nested_render_storm() {
		$product_id = $this->make_simple_product( 'WCEP Depth Storm' );
		$order      = $this->make_order_with( array( $product_id ) );
		$order_id   = (int) $order->get_id();

		$rule_id = $this->make_insert_rule( $product_id, array( 'content' => '<p>STORM BLOCK.</p>' ) );

		$depth = 0;
		$peak  = 0;

		$this->hook(
			'woocommerce_email_before_order_table',
			function ( $order_arg = null, $sent_to_admin = false, $plain_text = false, $email_arg = null ) use ( &$depth, &$peak, $order ) {
				// A BOUNDED reproduction of an unbounded bug: 20 levels is enough to
				// pass the plugin's bound of 16 without asking PHP to survive an
				// actually infinite recursion.
				if ( $depth >= 19 ) {
					return;
				}

				++$depth;
				$peak = max( $peak, RenderEvents::context()->depth() );

				do_action( 'woocommerce_email_order_details', $order, false, false, $email_arg );
			},
			1,
			4
		);

		$mail = $this->send_native( $order_id );

		$this->assertSame( 19, $depth, 'The nested render storm did not run.' );
		$this->assertGreaterThan( RenderContext::MAX_RENDER_DEPTH, $peak, 'The storm never reached the bound.' );

		$context = RenderEvents::context();
		$this->assertGreaterThan( 0, $context->refused_renders(), 'Nothing was refused, so the bound never engaged.' );

		// --- THE OUTER SEND IS UNAFFECTED. ------------------------------------
		$this->assertMailCount( 1, 'The nested renders produced extra messages.' );
		$this->assertBody( $mail, 'STORM BLOCK.', true, 'The outer render lost its insertion to the storm.' );

		$finalizations = $ledger_finalizations = RenderEvents::ledger()->finalizations();
		$this->assertCount( 1, $finalizations, 'The outer send finalised nothing, or more than once.' );
		$this->assertSame( $order_id, (int) $finalizations[0]['order_id'] );

		$tombstone = $this->insert_tombstone( $order_id, $rule_id );
		$this->assertNotNull( $tombstone, 'The outer send recorded no delivery.' );
		$this->assertSame( 'sent', $tombstone['final_status'] );

		fwrite(
			STDERR,
			sprintf(
				"\n[5B item 2 / gate 14] real nested storm: peak depth %d, refused %d, accepted records %d;"
				. " outer send still finalised %s -> order %d, final_status=%s\n",
				$peak,
				$context->refused_renders(),
				count( $context->renders() ),
				(string) $ledger_finalizations[0]['resolved'],
				(int) $ledger_finalizations[0]['order_id'],
				$tombstone['final_status']
			)
		);

		$this->run_shutdown_sweep();
	}

	/**
	 * Gate 14. THE DIAGNOSTIC ARRAYS ARE CAPPED, and the shortfall is counted.
	 *
	 * @return void
	 */
	public function test_the_diagnostic_arrays_cannot_grow_without_bound() {
		$product_id = $this->make_simple_product( 'WCEP Diagnostic Cap' );

		$order    = $this->make_order_with( array( $product_id ) );
		$order_id = (int) $order->get_id();

		$other    = $this->make_order_with( array( $product_id ) );
		$other_id = (int) $other->get_id();

		$this->make_insert_rule( $product_id, array( 'content' => '<p>CAP BLOCK.</p>' ) );

		$email = $this->native_email();
		$cap   = RenderContext::MAX_DIAGNOSTIC_ENTRIES;
		$fired = $cap + 25;

		// One open render, then a foreign-argument emission per iteration: each one
		// resolves to no exact scope and is recorded.
		RenderEvents::on_render_start( $order, false, false, $email );

		ob_start();

		try {
			for ( $i = 0; $i < $fired; $i++ ) {
				do_action( 'woocommerce_email_order_meta', $other, true, false, $email );
			}
		} finally {
			ob_get_clean();
		}

		$context = RenderEvents::context();

		$this->assertCount( $cap, $context->unresolved_emissions(), 'The diagnostic array grew past its cap.' );
		$this->assertSame(
			$fired - $cap,
			$context->diagnostics_dropped()['unresolved_emissions'],
			'Dropped diagnostic entries were not counted.'
		);

		// A valid token presented for the wrong render: the `mismatches` array,
		// capped the same way.
		$token = $context->current()['token'];

		for ( $i = 0; $i < $fired; $i++ ) {
			$this->assertFalse( $context->pop( $email, $other_id, $token ), 'A foreign-order pop removed a frame.' );
		}

		$this->assertCount( $cap, $context->mismatches(), 'The mismatch array grew past its cap.' );
		$this->assertSame( $fired - $cap, $context->diagnostics_dropped()['mismatches'] );

		fwrite(
			STDERR,
			sprintf(
				"\n[5B item 2 / gate 14] diagnostics capped at %d: %d unresolved emissions fired -> %d kept, %d counted as dropped;"
				. " %d mismatches fired -> %d kept, %d dropped\n",
				$cap,
				$fired,
				count( $context->unresolved_emissions() ),
				$context->diagnostics_dropped()['unresolved_emissions'],
				$fired,
				count( $context->mismatches() ),
				$context->diagnostics_dropped()['mismatches']
			)
		);

		RenderEvents::on_render_end( $order, false, false, $email );
		$this->run_shutdown_sweep();

		// Every emission was refused, so the slot carried nothing and the ADR-0013 §6
		// noise floor drops it: a render that inserted nothing is not a delivery.
		$this->assertSame(
			array(),
			$this->tombstones_for( $order_id ),
			'A render whose every emission was refused still produced a delivery record.'
		);
	}

	/**
	 * The frame stack, which is private and has no production reader.
	 *
	 * Read reflectively rather than exposed: the frame stack is not something any
	 * caller should be able to take a copy of, and a test seam that returned it
	 * would be an invitation to do exactly that.
	 *
	 * @param RenderContext $context Context.
	 * @return array[]
	 */
	private function frames_of( RenderContext $context ): array {
		$property = new \ReflectionProperty( RenderContext::class, 'frames' );
		$property->setAccessible( true );

		return (array) $property->getValue( $context );
	}
}
