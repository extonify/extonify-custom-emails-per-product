<?php
/**
 * The exception a test-mode `wp_die()` throws.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * A refusal, caught rather than fatal.
 *
 * WordPress's default `wp_die()` handler calls `die()`. Half of gate 28 is asserting
 * that an entry point refuses, and a test that proves a refusal by ending the PHP
 * process proves nothing and reports nothing — so `AdminTestCase` swaps the handler
 * for one that throws this, carrying the HTTP status the caller asked for.
 */
final class AdminDieException extends \RuntimeException {

	/**
	 * The HTTP status the refusal carried.
	 *
	 * @var int
	 */
	private $status;

	/**
	 * Constructor.
	 *
	 * @param string $message Refusal message.
	 * @param int    $status  HTTP status.
	 */
	public function __construct( string $message, int $status ) {
		parent::__construct( $message );

		$this->status = $status;
	}

	/**
	 * The HTTP status.
	 *
	 * @return int
	 */
	public function status(): int {
		return $this->status;
	}
}
