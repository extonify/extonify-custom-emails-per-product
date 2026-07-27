<?php
/**
 * Base class for the pure-logic unit suite.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Unit tests run WITHOUT WordPress. The classes under test must therefore be
 * free of WordPress calls, or use only the tiny shims defined below.
 *
 * ABSPATH is defined because every production file guards on it; that guard is
 * a security measure against direct web access, not a framework dependency.
 */
abstract class UnitTestCase extends TestCase {

	/**
	 * ABSPATH is defined by tests/Unit/shims.php at BOOTSTRAP time, not here.
	 *
	 * It cannot be a @beforeClass hook: PHPUnit evaluates static data providers
	 * while building the suite, and a provider that references a class constant
	 * autoloads that production file before any hook has run. The file's
	 * `defined( 'ABSPATH' ) || exit;` guard would then exit the process with
	 * status 0 and no output — a green-looking run that executed nothing.
	 */
}
