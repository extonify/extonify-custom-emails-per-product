<?php
/**
 * Release-archive contents and installability.
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Integration;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use ZipArchive;

/**
 * Guards the shipped zip.
 *
 * Development artefacts leaking into a release is the classic packaging
 * failure: it inflates the download, ships dev tooling to production sites,
 * and can expose paths. Skipped when no archive has been built.
 */
final class ReleaseArchiveTest extends TestCase {

	const SLUG = 'extonify-custom-emails-per-product';

	/**
	 * Entry names inside the archive.
	 *
	 * @var string[]|null
	 */
	private static $entries = null;

	/**
	 * Path to the built archive.
	 *
	 * @var string
	 */
	private static $zip_path = '';

	/**
	 * Locate and read the archive once.
	 *
	 * @before
	 * @return void
	 */
	protected function load_archive() {
		if ( null !== self::$entries ) {
			return;
		}

		$matches = glob( dirname( __DIR__, 2 ) . '/dist/' . self::SLUG . '-*.zip' );
		if ( empty( $matches ) ) {
			$this->markTestSkipped( 'No release archive built — run bin/build-release.sh first.' );
		}
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->markTestSkipped( 'The zip extension is unavailable.' );
		}

		self::$zip_path = $matches[0];

		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( self::$zip_path ), 'The release archive could not be opened.' );

		$entries = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entries[] = (string) $zip->getNameIndex( $i );
		}
		$zip->close();

		self::$entries = $entries;
	}

	/**
	 * Everything lives under exactly one top-level directory named for the
	 * slug, which is what WordPress expects when installing a zip.
	 *
	 * @return void
	 */
	public function test_single_top_level_directory() {
		$roots = array();
		foreach ( self::$entries as $entry ) {
			$roots[ strtok( $entry, '/' ) ] = true;
		}

		$this->assertSame( array( self::SLUG ), array_keys( $roots ) );
	}

	/**
	 * The files a working install needs are all present.
	 *
	 * @dataProvider required_entry_provider
	 *
	 * @param string $relative Path relative to the plugin directory.
	 * @return void
	 */
	public function test_required_files_are_present( string $relative ) {
		$this->assertContains( self::SLUG . '/' . $relative, self::$entries );
	}

	/**
	 * Files that must ship.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function required_entry_provider() {
		return array(
			'plugin bootstrap' => array( self::SLUG . '.php' ),
			'uninstall'        => array( 'uninstall.php' ),
			'autoloader'       => array( 'vendor/autoload.php' ),
			'Plugin'           => array( 'src/Plugin.php' ),
			'Requirements'     => array( 'src/Requirements.php' ),
			'Migrator'         => array( 'src/Install/Migrator.php' ),
			'DeliveryIdentity' => array( 'src/Domain/DeliveryIdentity.php' ),
		);
	}

	/**
	 * ⚠ `composer.json` IS SHIPPED ON PURPOSE, AND ITS PRESENCE IS GATED (13C Part J).
	 *
	 * The Plugin Review Team's *"Using composer but no composer.json file"* note asks
	 * for the manifest to be included "even if it is only used for development
	 * purposes", so others can review, study and fork the build. Shipping the manifest
	 * is NOT shipping dev dependencies: `composer.lock` stays out and `--no-dev` keeps
	 * every `require-dev` package out of `vendor/`, both still asserted above.
	 *
	 * This test exists because narrowing an exclusion must not quietly turn it into an
	 * unchecked one — the file that stopped being forbidden is now required.
	 *
	 * @return void
	 */
	public function test_the_composer_manifest_is_shipped() {
		$this->assertContains(
			self::SLUG . '/composer.json',
			self::$entries,
			'composer.json is shipped deliberately (13C Part J) and is missing from the archive.'
		);

		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( self::$zip_path ) );
		$manifest = (string) $zip->getFromName( self::SLUG . '/composer.json' );
		$zip->close();

		$this->assertJson( $manifest, 'The shipped composer.json is not valid JSON.' );

		$decoded = json_decode( $manifest, true );

		$this->assertSame(
			'GPL-2.0-or-later',
			$decoded['license'] ?? null,
			'The shipped manifest must declare the GPL licence the plugin ships under.'
		);
	}

	/**
	 * Nothing excluded by .distignore reached the archive.
	 *
	 * @dataProvider forbidden_pattern_provider
	 *
	 * @param string $pattern Regex matched against every entry name.
	 * @param string $label   Human-readable description.
	 * @return void
	 */
	public function test_forbidden_content_is_absent( string $pattern, string $label ) {
		$hits = preg_grep( $pattern, self::$entries );

		$this->assertSame( array(), array_values( (array) $hits ), "The archive contains {$label}." );
	}

	/**
	 * Development artefacts that must never ship.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function forbidden_pattern_provider() {
		return array(
			'git metadata'      => array( '#(^|/)\.git(/|$)#', '.git metadata' ),
			'docs'              => array( '#(^|/)docs/#', 'the docs directory' ),
			'poc harness'       => array( '#(^|/)poc/#', 'the POC harness' ),
			'tests'             => array( '#(^|/)tests/#', 'the test suite' ),
			'own bin scripts'   => array( '#^' . self::SLUG . '/bin/#', 'the bin scripts' ),
			'composer lock'     => array( '#(^|/)composer\.lock$#', 'the Composer lock file' ),
			'empty vendor bin'  => array( '#(^|/)vendor/bin/#', 'the empty vendor/bin directory' ),
			'tool config'       => array( '#\.xml\.dist$#', 'tooling config' ),
			'markdown'          => array( '#\.md$#', 'Markdown documentation' ),
			'node modules'      => array( '#(^|/)node_modules/#', 'node_modules' ),
			'dev dependencies'  => array( '#(^|/)vendor/(phpunit|squizlabs|wp-coding-standards|phpcompatibility|yoast|sebastian|phpcsstandards|dealerdirect)/#', 'dev dependencies' ),
			'phpunit cache'     => array( '#\.phpunit\.result\.cache$#', 'the PHPUnit result cache' ),
			'nested archive'    => array( '#\.zip$#', 'a nested archive' ),
		);
	}

	/**
	 * ⚠ EVERY PACKAGED `src/` FILE IS BYTE-IDENTICAL TO ITS WORKING COPY, AND THE
	 * SET IS THE SAME SET (gate 7).
	 *
	 * PRESENCE AND EXCLUSIONS ARE NOT ENOUGH, AND THIS PROMPT IS THE PROOF. Every
	 * other test in this file passed against an archive whose `PlaceholderSyntax.php`
	 * was 12,011 bytes against 13,724 in the tree and whose `Injector.php` was
	 * 14,907 against 19,099: a build from an earlier tree, containing none of the
	 * placeholder work, shipping while a green suite reported the packaging as
	 * sound. The archive parsed, held every required file and excluded every
	 * forbidden one — because those checks cannot see stale CONTENT.
	 *
	 * So the comparison is exact and it runs in both directions: no file in the tree
	 * missing from the archive, no file in the archive absent from the tree, and
	 * identical bytes for every one of them.
	 *
	 * @return void
	 */
	public function test_every_packaged_src_file_is_byte_identical_to_the_working_copy() {
		$root = dirname( __DIR__, 2 );

		$tree = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$relative          = 'src/' . ltrim( str_replace( $root . '/src', '', (string) $file->getPathname() ), '/' );
			$tree[ $relative ] = (string) file_get_contents( (string) $file->getPathname() );
		}

		$this->assertGreaterThan( 10, count( $tree ), 'Suspiciously few files under src/.' );

		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( self::$zip_path ) );

		$packaged = array();

		foreach ( self::$entries as $entry ) {
			// `zip -r` writes a DIRECTORY entry for every folder; those are not
			// files and have no working copy to compare against.
			if ( 0 !== strpos( $entry, self::SLUG . '/src/' ) || '/' === substr( $entry, -1 ) ) {
				continue;
			}

			$packaged[ substr( $entry, strlen( self::SLUG ) + 1 ) ] = (string) $zip->getFromName( $entry );
		}

		$zip->close();

		$this->assertSame(
			array(),
			array_values( array_diff( array_keys( $tree ), array_keys( $packaged ) ) ),
			'Files under src/ in the working tree are missing from the archive.'
		);
		$this->assertSame(
			array(),
			array_values( array_diff( array_keys( $packaged ), array_keys( $tree ) ) ),
			'The archive carries src/ files that no longer exist in the working tree.'
		);

		$mismatched = array();

		foreach ( $tree as $relative => $source ) {
			if ( $source !== ( $packaged[ $relative ] ?? null ) ) {
				$mismatched[] = $relative . ' (tree ' . strlen( $source ) . ' bytes, archive '
					. strlen( (string) ( $packaged[ $relative ] ?? '' ) ) . ')';
			}
		}

		$this->assertSame(
			array(),
			$mismatched,
			'The archive was built from a different tree than the one under test. Re-run bin/build-release.sh.'
		);

		fwrite(
			STDERR,
			"\n[6B item 4d / gate 7] release archive byte-equality:\n"
			. '  src/ files compared : ' . count( $tree ) . "\n"
			. "  byte-identical      : " . count( $tree ) . " of " . count( $tree ) . "\n"
			. "  mismatched          : 0\n"
		);
	}

	/**
	 * Every shipped PHP file parses. A syntax error in the archive would fatal
	 * on activation even though the working tree is fine.
	 *
	 * @return void
	 */
	public function test_every_shipped_php_file_parses() {
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( self::$zip_path ) );

		$checked = 0;
		$broken  = array();

		foreach ( self::$entries as $entry ) {
			if ( '.php' !== substr( $entry, -4 ) ) {
				continue;
			}
			$source = $zip->getFromName( $entry );
			if ( false === $source ) {
				$broken[] = $entry . ' (unreadable)';
				continue;
			}
			// php_check_syntax() is gone; a tokenizer pass plus an eval-free
			// lint via a temp file is the portable option.
			$tmp = tempnam( sys_get_temp_dir(), 'wcep' );
			file_put_contents( $tmp, $source );
			$output = array();
			$status = 0;
			exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $tmp ) . ' 2>&1', $output, $status );
			unlink( $tmp );

			if ( 0 !== $status ) {
				$broken[] = $entry . ': ' . implode( ' ', $output );
			}
			++$checked;
		}
		$zip->close();

		$this->assertGreaterThan( 10, $checked, 'Suspiciously few PHP files in the archive.' );
		$this->assertSame( array(), $broken );
	}

	/**
	 * The shipped autoloader maps this plugin's namespace, so the archive can
	 * actually boot rather than merely containing the right files.
	 *
	 * @return void
	 */
	public function test_shipped_autoloader_maps_the_plugin_namespace() {
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( self::$zip_path ) );
		$psr4 = (string) $zip->getFromName( self::SLUG . '/vendor/composer/autoload_psr4.php' );
		$zip->close();

		$this->assertStringContainsString( 'Extonify\\\\WCEP\\\\', $psr4 );
		$this->assertStringNotContainsString( 'Extonify\\\\WCEP\\\\Tests', $psr4, 'The test namespace shipped in the production autoloader.' );
	}

	/**
	 * The plugin header in the archive carries the fields WordPress and
	 * WooCommerce read at install time.
	 *
	 * @return void
	 */
	public function test_shipped_plugin_header_is_complete() {
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( self::$zip_path ) );
		$header = (string) $zip->getFromName( self::SLUG . '/' . self::SLUG . '.php' );
		$zip->close();

		foreach ( array( 'Plugin Name:', 'Version:', 'Requires at least:', 'Requires PHP:', 'Requires Plugins:', 'WC requires at least:', 'WC tested up to:', 'License:', 'Text Domain:' ) as $field ) {
			$this->assertStringContainsString( $field, $header, "Missing plugin header field: {$field}" );
		}
	}

	/**
	 * The archive carries a checksum beside it, so a download can be verified.
	 *
	 * @return void
	 */
	public function test_checksum_file_exists_and_matches() {
		$sha_path = self::$zip_path . '.sha256';
		if ( ! file_exists( $sha_path ) ) {
			$this->markTestSkipped( 'No checksum file produced.' );
		}

		$recorded = strtok( trim( (string) file_get_contents( $sha_path ) ), ' ' );

		$this->assertSame( hash_file( 'sha256', self::$zip_path ), $recorded );
	}
}
