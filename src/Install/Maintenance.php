<?php
/**
 * The daily maintenance action, and the arming that guarantees it exists
 * (ADR-0015 §8.3, §8.3c).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Install;

use Extonify\WCEP\Delivery\DeferredEvaluation;
use Extonify\WCEP\Delivery\ScheduledDelivery;

defined( 'ABSPATH' ) || exit;

/**
 * One recurring action, whose only job today is recovering deliveries that
 * nothing else can reach.
 *
 * ⚠ THIS DID NOT EXIST BEFORE PROMPT 7A, AND THAT IS WORTH RECORDING RATHER THAN
 * GLOSSING. `Deactivator::RECURRING_HOOKS` has named `extonify_wcep_retention_purge`
 * since the foundation, but nothing ever scheduled it and nothing ever handled it
 * — it was a reserved name and a `docs/p2-backlog.md` item. ADR-0015 §8.3 needs a
 * daily sweep to exist, so one is created here rather than borrowing a hook whose
 * name promises retention work this does not do. Retention joins it when the
 * settings UI lands; the name says "maintenance" so that addition is not a rename.
 *
 * WHY A SWEEP IS NEEDED AT ALL. Two states are unreachable by every other
 * mechanism in the plugin:
 *
 *   - `executing` — the lease means no second worker may touch the row, so a
 *     worker that dies holding one strands its delivery permanently;
 *   - `scheduled` with no live job — the thing that would have run it is gone.
 *
 * Both are silently lost deliveries, which is the failure the whole state machine
 * exists to end. The sweep is the only thing standing between them and forever.
 *
 * ⚠ WHICH IS WHY THE SWEEP HAS TO ARM ITSELF (§8.3c, added Prompt 7C). Until then it
 * was armed from activation and `admin_init` only — and **an automatic plugin update
 * runs neither**, while a REST- or CLI-driven store may run the second rarely. "The
 * only thing standing between them and forever" was, on those stores, not there at
 * all. See self::register() and self::ensure_armed().
 */
final class Maintenance {

	/**
	 * Action Scheduler hook.
	 */
	const HOOK = 'extonify_wcep_maintenance';

	/**
	 * Action Scheduler group, shared with every other action this plugin owns so
	 * a store owner sees and purges its scheduled work in one place.
	 */
	const GROUP = DeferredEvaluation::GROUP;

	/**
	 * How often the sweep runs, in seconds. Daily.
	 *
	 * The recovery it performs is not time-critical by construction: the §8.3
	 * lease window is an hour, so a delivery is already accepted as stranded for
	 * that long before the sweep is even entitled to touch it. Running more often
	 * would add load without changing an outcome.
	 */
	const INTERVAL_SECONDS = 86400;

	/**
	 * When this site last VERIFIED that the recurring action exists (ADR-0015 §8.3c).
	 *
	 * A unix timestamp, and **autoloaded on purpose** — the one option in this plugin
	 * that is. It is read on `action_scheduler_init`, which fires on every request
	 * that loads Action Scheduler, so a non-autoloaded option would add a query to
	 * every storefront page while an autoloaded integer rides along in a blob
	 * WordPress has already fetched. Written at most once per
	 * self::VERIFY_INTERVAL_SECONDS.
	 */
	const OPTION_VERIFIED = 'extonify_wcep_maintenance_verified';

	/**
	 * How long a verification is trusted before the scheduler is asked again.
	 *
	 * ⚠ IT IS THE LEASE WINDOW, AND MATCHING IT IS THE ARGUMENT. This bounds how long
	 * a manually deleted maintenance action can stay deleted; a stranded delivery is
	 * already accepted as stranded for `LEASE_WINDOW_SECONDS` before the sweep is
	 * entitled to touch it, so re-verifying faster than that would buy nothing and
	 * would cost a scheduler query on requests that have no reason to pay one.
	 */
	const VERIFY_INTERVAL_SECONDS = ScheduledDelivery::LEASE_WINDOW_SECONDS;

	/**
	 * Whether this request has already verified the action.
	 *
	 * ⚠ REQUEST-SCOPED, AND IT MUST BE. `ensure_armed()` is called from every path
	 * that queues a delayed delivery, and one order can queue many — so without this
	 * a bulk status change would repeat the option read once per delivery. It is a
	 * cache of a fact that only gets MORE true within a request: nothing this plugin
	 * does unschedules the maintenance action mid-request except deactivation, which
	 * ends the request's ability to schedule anything anyway.
	 *
	 * @var bool
	 */
	private static $verified_this_request = false;

	/**
	 * Register the handler, and arm the action from a path that always runs.
	 *
	 * ⚠ REGISTRATION IS NO LONGER HOOKS-ONLY IN EFFECT, AND THAT IS THE POINT OF
	 * PROMPT 7C's SECOND INVARIANT (ADR-0015 §8.3c). Before this, `ensure_scheduled()`
	 * was reachable from exactly two places — activation and `admin_init` — and **an
	 * automatic plugin update runs neither**. A store driven by REST or WP-CLI can go
	 * weeks without an admin request, so it could queue delayed deliveries into a
	 * queue whose only recovery mechanism did not exist. Every guarantee in §8.3 and
	 * §8.4 rests on this action running.
	 *
	 * `action_scheduler_init` is the correct hook because it fires on EVERY request
	 * that loads Action Scheduler — front end, REST, cron and CLI alike — and because
	 * it is the point Action Scheduler itself declares the procedural API safe to use
	 * (`ActionScheduler::init()`, bundled 3.9.3). `action_scheduler_ensure_recurring_actions`
	 * is Action Scheduler's own daily invitation to re-register recurring work, so it
	 * is honoured too; it is a second belt rather than the braces, because AS only
	 * schedules the action that fires it from an admin request — the same dependency
	 * this section exists to remove.
	 *
	 * The cost is stated at self::ensure_armed(): one autoloaded option read per
	 * request, and one scheduler query per VERIFY_INTERVAL_SECONDS.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( 'action_scheduler_init', array( self::class, 'ensure_armed' ) );
		add_action( 'action_scheduler_ensure_recurring_actions', array( self::class, 'ensure_armed' ) );
	}

	/**
	 * Verify — cheaply — that the recurring action exists, and arm it if it does not
	 * (ADR-0015 §8.3c).
	 *
	 * **THE COST, STATED.** A request that never queues a delivery pays one autoloaded
	 * option read, which is zero extra queries: `wp_load_alloptions()` has already
	 * fetched it. A request that does queue one pays the same read once, no matter how
	 * many deliveries it queues, because of the static. Once per
	 * VERIFY_INTERVAL_SECONDS a request additionally pays one
	 * `as_next_scheduled_action()` — one indexed read of the Action Scheduler table —
	 * plus one option write. Nothing here scales with the queue, the store or the
	 * order.
	 *
	 * ⚠ THE STAMP IS WRITTEN ONLY WHEN THE ANSWER IS "ARMED". A failed arm must not
	 * buy an hour of silence for a queue that has no recovery mechanism, so a negative
	 * result leaves the stamp alone and the next call asks again.
	 *
	 * @return bool Whether the recurring action is armed now.
	 */
	public static function ensure_armed(): bool {
		if ( self::$verified_this_request ) {
			return true;
		}

		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'get_option' ) ) {
			return false;
		}

		$verified = (int) get_option( self::OPTION_VERIFIED, 0 );

		if ( $verified > 0 && ( time() - $verified ) < self::VERIFY_INTERVAL_SECONDS ) {
			self::$verified_this_request = true;

			return true;
		}

		$armed = false !== as_next_scheduled_action( self::HOOK, array(), self::GROUP ) || self::ensure_scheduled();

		if ( ! $armed ) {
			return false;
		}

		self::$verified_this_request = true;

		if ( function_exists( 'update_option' ) ) {
			// ⚠ AUTOLOADED — see self::OPTION_VERIFIED. This is read on every request
			// that loads Action Scheduler; a query per page view to save a few bytes in
			// a blob WordPress fetches anyway would be the wrong trade.
			update_option( self::OPTION_VERIFIED, time(), true );
		}

		return true;
	}

	/**
	 * Forget this request's verification.
	 *
	 * For lifecycle code that has just unscheduled the action, and for tests. Leaving
	 * a `true` behind would let the rest of the request believe in an action that has
	 * been removed.
	 *
	 * @return void
	 */
	public static function forget_verification(): void {
		self::$verified_this_request = false;
	}

	/**
	 * Queue the recurring action if it is not already queued.
	 *
	 * ⚠ CALLED FROM ACTIVATION, FROM `admin_init` AND — SINCE PROMPT 7C — FROM THE
	 * RUNTIME PATH THAT CREATES DELAYED WORK, VIA self::ensure_armed(). The first two
	 * were not enough and the gap was not theoretical: an install that upgrades to a
	 * new version never runs the activation hook, and a store driven by REST or WP-CLI
	 * may never run `admin_init` either, so a plugin could queue delayed deliveries
	 * into a queue whose only recovery mechanism did not exist (ADR-0015 §8.3c).
	 *
	 * Idempotent: `as_next_scheduled_action()` is an args-exact read, and the
	 * action carries no arguments.
	 *
	 * @return bool True when this call queued the action.
	 */
	public static function ensure_scheduled(): bool {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return false;
		}

		if ( false !== as_next_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			return false;
		}

		// Verified INITIALISED, not merely loaded: the functions load before the
		// data store does, and scheduling against an uninitialised store returns 0
		// (ADR-0015 §6).
		if ( ! class_exists( '\ActionScheduler' ) || ! \ActionScheduler::is_initialized() ) {
			return false;
		}

		// The first run is a full interval away rather than immediate: nothing can
		// be stale yet on a site that has only just armed this.
		$action_id = (int) as_schedule_recurring_action(
			time() + self::INTERVAL_SECONDS,
			self::INTERVAL_SECONDS,
			self::HOOK,
			array(),
			self::GROUP
		);

		return $action_id > 0;
	}

	/**
	 * Run the sweep.
	 *
	 * ⚠ CONTAINED, LIKE EVERY OTHER ENTRY POINT THIS PLUGIN OWNS. A throw would be
	 * caught by Action Scheduler and the action marked failed — but a RECURRING
	 * action that fails is one Action Scheduler stops rescheduling, which would
	 * silently remove the only mechanism that recovers a stranded delivery. The
	 * boundary is here so a bad row costs one sweep rather than all of them.
	 *
	 * @return array The sweep's counts, for tests and future reporting.
	 */
	public static function run(): array {
		try {
			return ScheduledDelivery::sweep();
		} catch ( \Throwable $error ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'the daily maintenance sweep threw and recovered nothing this pass: '
						. get_class( $error ) . ': ' . $error->getMessage(),
					array( 'source' => 'extonify-wcep' )
				);
			}

			return ScheduledDelivery::empty_sweep();
		}
	}
}
