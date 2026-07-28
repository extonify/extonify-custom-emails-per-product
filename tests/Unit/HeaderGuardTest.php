<?php
/**
 * Mail-header injection defence (ADR-0012 §4).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Extonify\WCEP\Delivery\HeaderGuard;

/**
 * A header ends at a CRLF, so a value carrying one can append headers of its
 * own. Every form that becomes a break once something decodes it is treated as
 * a break here.
 */
final class HeaderGuardTest extends UnitTestCase {

	/**
	 * Every break form is detected and removed.
	 *
	 * @dataProvider injection_provider
	 *
	 * @param string $value Hostile value.
	 * @param string $label What it is.
	 * @return void
	 */
	public function test_line_breaks_are_detected_and_stripped( string $value, string $label ) {
		$this->assertTrue( HeaderGuard::has_break( $value ), $label . ' was not detected.' );

		$clean = HeaderGuard::strip( $value );

		$this->assertStringNotContainsString( "\r", $clean, $label );
		$this->assertStringNotContainsString( "\n", $clean, $label );
		$this->assertFalse( HeaderGuard::has_break( $clean ), $label . ' survived a strip.' );

		// And the smuggled header name never sits at the start of a line, which
		// is the only position a mail transport reads a header from.
		$this->assertSame( 0, preg_match( '/^(bcc|cc|to|from|subject|content-type):/im', $clean ), $label );
	}

	/**
	 * Raw, percent-encoded and entity-encoded injection attempts.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function injection_provider(): array {
		return array(
			'raw CRLF'             => array( "alice@example.test\r\nBcc: attacker@evil.test", 'raw CRLF' ),
			'raw LF'               => array( "alice@example.test\nBcc: attacker@evil.test", 'raw LF' ),
			'raw CR'               => array( "alice@example.test\rBcc: attacker@evil.test", 'raw CR' ),
			'percent lower'        => array( 'alice@example.test%0d%0aBcc: attacker@evil.test', 'percent-encoded lowercase' ),
			'percent upper'        => array( 'alice@example.test%0D%0ABcc: attacker@evil.test', 'percent-encoded uppercase' ),
			'percent LF only'      => array( 'alice@example.test%0aBcc: attacker@evil.test', 'percent-encoded LF' ),
			'double percent'       => array( 'alice@example.test%250d%250aBcc: attacker@evil.test', 'double percent-encoded' ),
			'decimal entity'       => array( 'alice@example.test&#13;&#10;Bcc: attacker@evil.test', 'decimal entities' ),
			'padded entity'        => array( 'alice@example.test&#013;&#010;Bcc: attacker@evil.test', 'zero-padded entities' ),
			'hex entity'           => array( 'alice@example.test&#x0d;&#x0a;Bcc: attacker@evil.test', 'hex entities' ),
			'display name raw'     => array( "Alice\r\nBcc: attacker@evil.test <alice@example.test>", 'display name, raw CRLF' ),
			'display name encoded' => array( 'Alice%0aBcc: attacker@evil.test <alice@example.test>', 'display name, encoded' ),
			'subject raw'          => array( "Your order\r\nBcc: attacker@evil.test", 'subject, raw CRLF' ),
			'subject encoded'      => array( 'Your order%0d%0aBcc: attacker@evil.test', 'subject, encoded' ),
			'tab'                  => array( "alice@example.test\tx", 'tab' ),
			'null byte'            => array( "alice@example.test\0x", 'NUL' ),
			'vertical tab'         => array( "alice@example.test\x0Bx", 'vertical tab' ),
		);
	}

	/**
	 * A NESTED encoding cannot survive by reassembling itself after one pass.
	 *
	 * `%%0a0a` becomes `%0a` if a single pass removes the inner `%0a` — so the
	 * strip repeats until the value stops changing.
	 *
	 * @return void
	 */
	public function test_nested_encodings_do_not_reassemble() {
		foreach ( array( '%%0a0a', '%%0d0d', '&#&#10;10;', '%25%0a0a' ) as $nested ) {
			$clean = HeaderGuard::strip( 'alice@example.test' . $nested . 'Bcc: attacker@evil.test' );

			$this->assertFalse( HeaderGuard::has_break( $clean ), $nested . ' reassembled into a break.' );
			$this->assertSame( 0, preg_match( '/%0[adAD]|&#/', $clean ), $nested . ' left an encoded break behind.' );
		}
	}

	/**
	 * An ordinary value is returned unchanged apart from trimming, so the guard
	 * is not quietly mangling legitimate input.
	 *
	 * @dataProvider benign_provider
	 *
	 * @param string $value    Benign value.
	 * @param string $expected Expected output.
	 * @return void
	 */
	public function test_benign_values_survive( string $value, string $expected ) {
		$this->assertSame( $expected, HeaderGuard::strip( $value ) );
		$this->assertFalse( HeaderGuard::has_break( $expected ), 'A clean value must be stable under the guard.' );
	}

	/**
	 * Values that must pass through intact.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function benign_provider(): array {
		return array(
			'bare address'    => array( 'alice@example.test', 'alice@example.test' ),
			'header form'     => array( 'Alice Smith <alice@example.test>', 'Alice Smith <alice@example.test>' ),
			'quoted comma'    => array( '"Smith, Alice" <alice@example.test>', '"Smith, Alice" <alice@example.test>' ),
			'subject'         => array( 'Your order #1234 is on its way', 'Your order #1234 is on its way' ),
			'accented name'   => array( 'Renée Dupont <renee@example.test>', 'Renée Dupont <renee@example.test>' ),
			'percent, no CR'  => array( '50%25 off', '50%25 off' ),
			'ampersand'       => array( 'Tea & Coffee', 'Tea & Coffee' ),
			'padded'          => array( '  alice@example.test  ', 'alice@example.test' ),
			'entity, not CRLF' => array( '&#65;lice', '&#65;lice' ),
		);
	}

	/**
	 * A value that is nothing BUT breaks strips to the empty string rather than
	 * to something that looks like an address.
	 *
	 * @return void
	 */
	public function test_a_value_of_only_breaks_becomes_empty() {
		foreach ( array( "\r\n", '%0d%0a', '&#13;&#10;', "\t\r\n " ) as $value ) {
			$this->assertSame( '', HeaderGuard::strip( $value ), var_export( $value, true ) );
		}
	}
}
