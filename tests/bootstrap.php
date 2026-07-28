<?php
/**
 * PHPUnit bootstrap.
 *
 * Two suites with different needs:
 *
 *  - `unit` runs pure logic and must NOT boot WordPress. Its test cases define
 *    the handful of WordPress functions they touch as no-op shims, so the suite
 *    proves the logic is genuinely framework-free.
 *  - `integration` boots the live WordPress + WooCommerce installation the same
 *    way poc/_bootstrap.php does, against the real database. Every test creates
 *    and destroys its own fixtures and verifies teardown.
 *
 * Which mode applies is decided by the suite being run: WordPress is loaded
 * only when the integration directory is part of the run.
 *
 * @package Extonify\WCEP\Tests
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/**
 * Decide whether this run needs the WordPress runtime.
 *
 * Defaults to true (the full `composer test` run includes integration), and is
 * false only when the command line restricts the run to the unit suite.
 *
 * @return bool
 */
function extonify_wcep_tests_needs_wordpress() {
	$argv = isset( $_SERVER['argv'] ) ? (array) $_SERVER['argv'] : array();

	foreach ( $argv as $i => $arg ) {
		if ( '--testsuite' === $arg ) {
			$next = isset( $argv[ $i + 1 ] ) ? (string) $argv[ $i + 1 ] : '';
			return 'unit' !== $next;
		}
		if ( 0 === strpos( (string) $arg, '--testsuite=' ) ) {
			return 'unit' !== substr( (string) $arg, 12 );
		}
	}

	return true;
}

if ( ! extonify_wcep_tests_needs_wordpress() ) {
	require_once __DIR__ . '/Unit/shims.php';
	require_once __DIR__ . '/Unit/UnitTestCase.php';
	return;
}

// ---------------------------------------------------------------------------
// Integration runtime: the live local WordPress + WooCommerce install.
// ---------------------------------------------------------------------------
$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SERVER_NAME']    = $_SERVER['SERVER_NAME'] ?? 'localhost';
$_SERVER['REQUEST_URI']    = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}

require dirname( __DIR__, 4 ) . '/wp-load.php';

if ( ! class_exists( 'WooCommerce' ) ) {
	fwrite( STDERR, "WooCommerce must be active for the integration suite.\n" );
	exit( 1 );
}
if ( ! function_exists( 'wc_create_order' ) ) {
	fwrite( STDERR, "wc_create_order() unavailable — WooCommerce not fully loaded.\n" );
	exit( 1 );
}
if ( ! function_exists( 'extonify_wcep_boot' ) ) {
	fwrite( STDERR, "The production plugin must be active for the integration suite.\n" );
	exit( 1 );
}

// ---------------------------------------------------------------------------
// SWITCH ONTO THE DEDICATED TEST DATABASE.
//
// WordPress boots against DB_NAME from wp-config.php, which is the working
// development database. The integration suite must not drop tables there, so
// when EXTONIFY_WCEP_TEST_DB names a DIFFERENT database the connection is moved
// onto it before anything else happens. wpdb::select() switches the active
// database on the open connection; the object cache is then flushed so no
// option or post read from the development database survives the switch.
//
// Create the target with `php bin/create-test-db.php` — see docs/testing.md.
// ---------------------------------------------------------------------------
$extonify_wcep_target_db = (string) getenv( 'EXTONIFY_WCEP_TEST_DB' );

if ( '' !== $extonify_wcep_target_db && $extonify_wcep_target_db !== DB_NAME ) {
	$extonify_wcep_exists = (int) $wpdb->get_var(
		$wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s', $extonify_wcep_target_db )
	);
	if ( $extonify_wcep_exists < 1 ) {
		fwrite( STDERR, "\nThe test database \"{$extonify_wcep_target_db}\" does not exist or is empty.\n" );
		fwrite( STDERR, "Create it first:  php bin/create-test-db.php\n\n" );
		exit( 1 );
	}

	$wpdb->select( $extonify_wcep_target_db );
	wp_cache_flush();

	// Prove the switch actually took effect before any test can write.
	$extonify_wcep_effective = (string) $wpdb->get_var( 'SELECT DATABASE()' );
	if ( $extonify_wcep_effective !== $extonify_wcep_target_db ) {
		fwrite( STDERR, "\nFailed to switch to \"{$extonify_wcep_target_db}\" (still on \"{$extonify_wcep_effective}\").\n\n" );
		exit( 1 );
	}
}

/**
 * The database the suite is actually running against, after any switch.
 *
 * @return string
 */
function extonify_wcep_effective_db() {
	global $wpdb;
	return (string) $wpdb->get_var( 'SELECT DATABASE()' );
}

// ---------------------------------------------------------------------------
// DESTRUCTIVE-TEST GUARD — suite level, so no individual test can forget it.
//
// The integration suite performs real DDL against the live plugin tables:
// SchemaVerificationTest drops tables, columns and indexes in order to prove
// that verify_schema() actually notices. That is harmless while the tables are
// empty and becomes DATA LOSS the moment real rules and delivery history exist.
//
// `composer test` chains this suite, so the guard has to fail closed and it has
// to live here rather than in each test. There is deliberately NO interactive
// confirmation fallback: an automated run must refuse, not prompt.
// ---------------------------------------------------------------------------

/**
 * Reasons the destructive integration suite must not run.
 *
 * @return string[] Blockers; empty when the suite may proceed.
 */
function extonify_wcep_destructive_blockers() {
	$blockers = array();

	$opt_in = ( '1' === (string) getenv( 'EXTONIFY_WCEP_ALLOW_DESTRUCTIVE_TESTS' ) )
		|| ( defined( 'EXTONIFY_WCEP_ALLOW_DESTRUCTIVE_TESTS' ) && EXTONIFY_WCEP_ALLOW_DESTRUCTIVE_TESTS );
	if ( ! $opt_in ) {
		$blockers[] = 'EXTONIFY_WCEP_ALLOW_DESTRUCTIVE_TESTS is not set to 1';
	}

	$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
	if ( ! in_array( $environment, array( 'local', 'development' ), true ) ) {
		$blockers[] = 'WP_ENVIRONMENT_TYPE is "' . $environment . '" (must be local or development)';
	}

	// The EFFECTIVE database — the one queries will actually hit after any
	// switch above — not the wp-config constant. The guard exists to protect
	// whatever is about to be written to, so that is what it must measure.
	$db_name  = extonify_wcep_effective_db();
	$declared = (string) getenv( 'EXTONIFY_WCEP_TEST_DB' );
	if ( '' === $declared && defined( 'EXTONIFY_WCEP_TEST_DB' ) ) {
		$declared = (string) EXTONIFY_WCEP_TEST_DB;
	}
	$looks_like_test     = (bool) preg_match( '/(_test|_tests)$/i', $db_name );
	$explicitly_declared = ( '' !== $declared && hash_equals( $db_name, $declared ) );
	if ( ! $looks_like_test && ! $explicitly_declared ) {
		$blockers[] = 'DB_NAME "' . $db_name . '" is not a test database'
			. ' (name it *_test, or declare it with EXTONIFY_WCEP_TEST_DB=' . $db_name . ')';
	}

	return $blockers;
}

$extonify_wcep_blockers = extonify_wcep_destructive_blockers();

// Always show which database is about to be touched, refusal or not.
fwrite( STDOUT, 'Extonify WCEP integration suite — target database: ' . extonify_wcep_effective_db() . "\n" );

if ( ! empty( $extonify_wcep_blockers ) ) {
	fwrite( STDERR, "\nREFUSING TO RUN THE INTEGRATION SUITE — it performs destructive DDL on the plugin tables.\n" );
	foreach ( $extonify_wcep_blockers as $extonify_wcep_blocker ) {
		fwrite( STDERR, '  - ' . $extonify_wcep_blocker . "\n" );
	}
	fwrite( STDERR, "\nTo run it deliberately (see docs/testing.md):\n" );
	fwrite(
		STDERR,
		"  EXTONIFY_WCEP_ALLOW_DESTRUCTIVE_TESTS=1 WP_ENVIRONMENT_TYPE=development \\\n"
		. "    EXTONIFY_WCEP_TEST_DB=extonify_wcep_test composer test:integration\n\n"
	);
	exit( 1 );
}

/**
 * Set only once the suite-level guard has passed. Tests performing DDL assert
 * on this before their first destructive statement, so a future refactor of the
 * base class cannot silently re-expose the danger.
 */
define( 'EXTONIFY_WCEP_DESTRUCTIVE_TESTS_ALLOWED', true );

// ---------------------------------------------------------------------------
// NO REAL MAIL, SUITE-WIDE.
//
// Installed here rather than in a base class so it covers every test, including
// ones whose author never thought about mail. See `Integration\MailGuard`: the
// short-circuit tallies, and a `phpmailer_init` tripwire is what actually
// proves nothing escaped.
// ---------------------------------------------------------------------------
\Extonify\WCEP\Tests\Integration\MailGuard::install();

require_once __DIR__ . '/Unit/UnitTestCase.php';
require_once __DIR__ . '/Integration/IntegrationTestCase.php';

/*
 * Leave the install healthy however the run ends.
 *
 * These tests deliberately corrupt the schema, and a test that fails midway
 * can skip its own @after restore. Without this, one red run would leave the
 * development install broken and every later run would open with a misleading
 * "schema is missing" failure. Runs on shutdown, after PHPUnit has finished.
 */
register_shutdown_function(
	function () {
		if ( ! class_exists( '\Extonify\WCEP\Install\Migrator', false ) ) {
			return;
		}
		\Extonify\WCEP\Install\Migrator::flush_schema_cache();
		if ( true === \Extonify\WCEP\Install\Migrator::verify_schema() ) {
			return;
		}
		\Extonify\WCEP\Tests\Integration\IntegrationTestCase::force_rebuild_schema();
		fwrite( STDOUT, "\n[bootstrap] schema was left broken by the run; rebuilt it.\n" );
	}
);
