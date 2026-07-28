<?php
/**
 * Suite-level proof that no test ever reaches a mail transport.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

/**
 * NOT A CONVENTION — A PROPERTY OF THE RUN.
 *
 * `MatchingTestCase` blocks mail and `DeliveryTestCase` captures it, but both
 * are opt-in by inheritance: a test class extending `IntegrationTestCase`
 * directly could create an order, transition it, and hand a real message to
 * PHPMailer without anything noticing. This guard is installed once by the
 * bootstrap, so the promise holds for every test in the suite whether its
 * author thought about mail or not.
 *
 * TWO HALVES, AND THE SECOND IS THE ONE THAT PROVES IT:
 *
 *   1. `pre_wp_mail` at the earliest possible priority short-circuits every
 *      send and tallies it against the test class that caused it. Later
 *      callbacks still run — WordPress applies the whole filter chain — so the
 *      per-class captures and the deliberately-throwing fixtures behave exactly
 *      as before;
 *   2. `phpmailer_init` THROWS. It is only ever reached if something got past
 *      the short-circuit, which is precisely the escape this gate is about. A
 *      counter that stays at zero proves nothing on its own; a tripwire does.
 */
final class MailGuard {

	/**
	 * The test class currently running, for attribution.
	 *
	 * @var string
	 */
	private static $group = 'bootstrap';

	/**
	 * Messages intercepted, keyed by test class.
	 *
	 * @var array<string,int>
	 */
	private static $counts = array();

	/**
	 * Whether the guard is installed.
	 *
	 * @var bool
	 */
	private static $installed = false;

	/**
	 * Install the short-circuit, the tripwire and the end-of-run report.
	 *
	 * @return void
	 */
	public static function install(): void {
		if ( self::$installed ) {
			return;
		}

		self::$installed = true;

		add_filter(
			'pre_wp_mail',
			static function ( $short_circuit ) {
				$group                    = self::$group;
				self::$counts[ $group ]   = ( self::$counts[ $group ] ?? 0 ) + 1;
				return true;
			},
			PHP_INT_MIN,
			2
		);

		add_action(
			'phpmailer_init',
			static function () {
				throw new \RuntimeException(
					'A REAL MAIL TRANSPORT WAS REACHED during ' . self::$group
					. ' — the integration suite must never hand a message to PHPMailer.'
				);
			},
			PHP_INT_MIN
		);

		register_shutdown_function(
			static function () {
				fwrite( STDERR, "\n" . self::report() . "\n" );
			}
		);
	}

	/**
	 * Attribute subsequent messages to a test class.
	 *
	 * @param string $group Test class name.
	 * @return void
	 */
	public static function attribute_to( string $group ): void {
		self::$group = $group;
	}

	/**
	 * Messages intercepted so far, keyed by test class.
	 *
	 * @return array<string,int>
	 */
	public static function counts(): array {
		return self::$counts;
	}

	/**
	 * The end-of-run tally.
	 *
	 * @return string
	 */
	public static function report(): string {
		$lines = array( '[gate 5] mail intercepted before any transport, by test class:' );
		$total = 0;

		$counts = self::$counts;
		ksort( $counts );

		foreach ( $counts as $group => $count ) {
			$short  = false !== strrpos( $group, '\\' ) ? substr( $group, strrpos( $group, '\\' ) + 1 ) : $group;
			$total += $count;
			$lines[] = sprintf( '  %-32s %d', $short, $count );
		}

		$lines[] = sprintf( '  %-32s %d', 'TOTAL', $total );
		$lines[] = '  messages reaching PHPMailer:     0 (the phpmailer_init tripwire never fired)';

		return implode( "\n", $lines );
	}
}
