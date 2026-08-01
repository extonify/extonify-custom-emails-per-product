<?php
/**
 * Placeholder grammar, single-pass substitution and per-context escaping
 * (ADR-0014 §1, §2, §3, §6).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Extonify\WCEP\Domain\PlaceholderSyntax;

/**
 * THE SECURITY BOUNDARY BETWEEN CUSTOMER-CONTROLLED TEXT AND A CUSTOMER'S INBOX.
 *
 * These run with no WordPress at all, against the REAL class rather than a
 * shimmed approximation of it — which matters more here than anywhere else in
 * the plugin. `PlaceholderSyntax` is framework-free precisely so that this suite
 * can exercise the escaping and the single-pass rule as they actually ship.
 */
final class PlaceholderSyntaxTest extends UnitTestCase {

	/**
	 * A resolver that answers every token with a canned value, and records what
	 * it was asked for.
	 *
	 * @param array $values Name (or `name:param`) => value.
	 * @param array $asked  Receives every token asked for, in order.
	 * @return callable
	 */
	private function lookup( array $values, array &$asked = array() ): callable {
		return static function ( string $name, ?string $param ) use ( $values, &$asked ): string {
			$key     = null === $param ? $name : $name . ':' . $param;
			$asked[] = $key;

			return (string) ( $values[ $key ] ?? '' );
		};
	}

	/**
	 * Every form the grammar accepts, and every form it does not.
	 *
	 * @dataProvider grammar_provider
	 *
	 * @param string $template Template.
	 * @param array  $expected Tokens the grammar should find, as labels.
	 * @param string $why      What the case proves.
	 * @return void
	 */
	public function test_the_grammar_matches_exactly_what_it_should( string $template, array $expected, string $why ) {
		$labels = array();

		foreach ( PlaceholderSyntax::tokens( $template ) as $token ) {
			$labels[] = $token['label'];
		}

		$this->assertSame( $expected, $labels, $why );
	}

	/**
	 * Accepted and rejected token shapes (ADR-0014 §1).
	 *
	 * @return array<string,array{0:string,1:array,2:string}>
	 */
	public function grammar_provider(): array {
		return array(
			'plain token'          => array( 'Hi {customer_first_name}.', array( '{customer_first_name}' ), 'the ordinary case' ),
			'parameterised'        => array( '{order_custom_field:total_paid_note}', array( '{order_custom_field:total_paid_note}' ), 'the parameterised case' ),
			'two tokens'           => array( '{a_one} and {b_two}', array( '{a_one}', '{b_two}' ), 'two distinct tokens' ),
			'repeat is one entry'  => array( '{a_one} {a_one} {a_one}', array( '{a_one}' ), 'a repeated token is one distinct token' ),
			'upper case folds'     => array( '{CUSTOMER_EMAIL}', array( '{customer_email}' ), 'names fold to lower case, ASCII range only' ),
			'mixed case folds'     => array( '{Customer_Email}', array( '{customer_email}' ), 'mixed case folds too' ),
			'meta key keeps case'  => array( '{order_custom_field:MyKey}', array( '{order_custom_field:MyKey}' ), '⚠ meta keys are case-SENSITIVE and must not be folded' ),
			'bare brace'           => array( 'Cost: 5{ each', array(), 'a bare { is not a token' ),
			'empty braces'         => array( 'Nothing {} here', array(), 'empty braces are not a token' ),
			'digits first'         => array( '{123}', array(), 'a name must start with a letter' ),
			'spaced'               => array( '{ customer_email }', array(), 'whitespace is not part of the grammar' ),
			'hyphenated name'      => array( '{customer-email}', array(), 'a hyphen is not part of a name' ),
			'split over lines'     => array( "{customer_\nemail}", array(), '⚠ a token may not span a line break' ),
			'param over lines'     => array( "{order_custom_field:a\nb}", array(), '⚠ nor may a parameter' ),
			'nested braces'        => array( '{{customer_email}}', array( '{customer_email}' ), 'the INNER token matches; the outer braces stay literal' ),
			'unclosed then closed' => array( '{a{b_two}', array( '{b_two}' ), 'a brace cannot be swallowed by a parameter' ),
			/*
			 * ⚠ REPLACES THE CASE THAT ASSERTED "an empty parameter is no parameter"
			 * (ADR-0014 §1e). That test ENCODED THE COERCION: the old
			 * `'' !== $matches[2]` check folded `{customer_email:}` into
			 * `{customer_email}`, and the §7 recipient safe set authorises the exact
			 * NAME and refuses anything parameterised — so the coercion handed the
			 * safe set a token it had never authorised. `''` and `null` are now
			 * different answers and the label proves it.
			 */
			'empty param kept'     => array( '{order_custom_field:}', array( '{order_custom_field:}' ), '⚠ an empty parameter is NOT no parameter' ),
			'empty param distinct' => array( '{order_custom_field:} {order_custom_field}', array( '{order_custom_field:}', '{order_custom_field}' ), '⚠ the two spellings are two distinct tokens' ),
		);
	}

	/**
	 * ⚠ THE EMPTY PARAMETER REACHES THE LOOKUP AS `''`, NOT AS `null`
	 * (ADR-0014 §1e).
	 *
	 * The grammar test above proves the LABEL is distinct; this proves the value
	 * handed to the resolver is, which is what every consumer branches on.
	 *
	 * @return void
	 */
	public function test_an_empty_parameter_reaches_the_lookup_as_an_empty_string() {
		$seen = array();

		PlaceholderSyntax::render(
			'{customer_email:} {customer_email} {order_custom_field:k}',
			static function ( string $name, ?string $param ) use ( &$seen ): string {
				$seen[] = $name . '=' . ( null === $param ? 'NULL' : '"' . $param . '"' );

				return '';
			}
		);

		$this->assertSame(
			array( 'customer_email=""', 'customer_email=NULL', 'order_custom_field="k"' ),
			$seen,
			'an explicitly empty parameter was coerced into "no parameter"'
		);
	}

	/**
	 * Whatever the lookup returns is inserted verbatim.
	 *
	 * @return void
	 */
	public function test_substitution_inserts_the_lookup_result() {
		$out = PlaceholderSyntax::render(
			'Hi {customer_first_name}, order {order_number}.',
			$this->lookup(
				array(
					'customer_first_name' => 'Alice',
					'order_number'        => '1234',
				)
			)
		);

		$this->assertSame( 'Hi Alice, order 1234.', $out );
	}

	/**
	 * A template with no braces is returned untouched and asks nothing.
	 *
	 * @return void
	 */
	public function test_a_template_without_tokens_resolves_nothing() {
		$asked = array();

		$this->assertSame(
			'No placeholders here.',
			PlaceholderSyntax::render( 'No placeholders here.', $this->lookup( array(), $asked ) )
		);
		$this->assertSame( array(), $asked );
	}

	/**
	 * ⚠ SECURITY: a VALUE containing a placeholder renders LITERALLY
	 * (ADR-0014 §2).
	 *
	 * A billing first name is whatever the customer typed at checkout. If
	 * resolution were recursive or two-pass, a customer could name themselves
	 * after a placeholder and have it resolved for them.
	 *
	 * @dataProvider hostile_value_provider
	 *
	 * @param string $value    The customer-controlled value.
	 * @param string $why      What the case proves.
	 * @return void
	 */
	public function test_a_resolved_value_is_never_rescanned( string $value, string $why ) {
		$asked = array();

		$out = PlaceholderSyntax::render(
			'Hi {customer_first_name}.',
			$this->lookup( array( 'customer_first_name' => $value ), $asked )
		);

		$this->assertSame( 'Hi ' . $value . '.', $out, $why );
		$this->assertSame(
			array( 'customer_first_name' ),
			$asked,
			'the resolver was asked for something the TEMPLATE never contained: ' . $why
		);
	}

	/**
	 * Values a customer can set in a billing field.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function hostile_value_provider(): array {
		return array(
			'a private meta token' => array(
				'{order_custom_field:_stripe_source_id}',
				'a payment token would have been resolved and mailed to the customer',
			),
			'another placeholder'  => array(
				'{customer_email}',
				'a second pass would resolve a placeholder the merchant never wrote',
			),
			'the same placeholder' => array(
				'{customer_first_name}',
				'a recursive resolver would loop here',
			),
			'two tokens'           => array(
				'{store_email} and {order_custom_field:_customer_ip_address}',
				'neither token in a value may be resolved',
			),
		);
	}

	/**
	 * The self-referential case cannot loop, and completes.
	 *
	 * @return void
	 */
	public function test_a_self_referential_value_terminates() {
		$calls = 0;

		$out = PlaceholderSyntax::render(
			'{loop_me}',
			static function ( string $name, ?string $param ) use ( &$calls ): string {
				++$calls;

				return '{loop_me}';
			}
		);

		$this->assertSame( '{loop_me}', $out );
		$this->assertSame( 1, $calls, 'the resolver ran more than once for one token' );
	}

	/**
	 * The same value, escaped for each destination (ADR-0014 §3).
	 *
	 * @dataProvider escaping_provider
	 *
	 * @param string $value   Raw value.
	 * @param string $html    Expected HTML rendering.
	 * @param string $plain   Expected plain rendering.
	 * @param string $header  Expected header rendering.
	 * @return void
	 */
	public function test_a_value_is_escaped_for_its_destination( string $value, string $html, string $plain, string $header ) {
		$this->assertSame( $html, PlaceholderSyntax::escape( $value, PlaceholderSyntax::CONTEXT_HTML ), 'HTML context' );
		$this->assertSame( $plain, PlaceholderSyntax::escape( $value, PlaceholderSyntax::CONTEXT_PLAIN ), 'plain context' );
		$this->assertSame( $header, PlaceholderSyntax::escape( $value, PlaceholderSyntax::CONTEXT_HEADER ), 'header context' );
	}

	/**
	 * Values, and what each destination must make of them.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string}>
	 */
	public function escaping_provider(): array {
		return array(
			'plain text'      => array( 'Alice', 'Alice', 'Alice', 'Alice' ),
			'script tag'      => array(
				'<script>alert(1)</script>',
				'&lt;script&gt;alert(1)&lt;/script&gt;',
				'<script>alert(1)</script>',
				'<script>alert(1)</script>',
			),
			'ampersand'       => array( 'A & B', 'A &amp; B', 'A & B', 'A & B' ),
			'entity text'     => array( '&amp;', '&amp;amp;', '&amp;', '&amp;' ),
			'quotes'          => array( 'He said "hi"', 'He said &quot;hi&quot;', 'He said "hi"', 'He said "hi"' ),
			'single quotes'   => array( "It's", 'It&#039;s', "It's", "It's" ),
			'attribute break' => array( 'x" onload="alert(1)', 'x&quot; onload=&quot;alert(1)', 'x" onload="alert(1)', 'x" onload="alert(1)' ),
			'raw CRLF'        => array(
				"Alice\r\nBcc: attacker@evil.test",
				"Alice<br />\nBcc: attacker@evil.test",
				"Alice\nBcc: attacker@evil.test",
				'Alice Bcc: attacker@evil.test',
			),
			'encoded CRLF'    => array(
				'Alice%0d%0aBcc: attacker@evil.test',
				'Alice%0d%0aBcc: attacker@evil.test',
				'Alice%0d%0aBcc: attacker@evil.test',
				'AliceBcc: attacker@evil.test',
			),
			'entity CRLF'     => array(
				'Alice&#13;&#10;Bcc: attacker@evil.test',
				'Alice&amp;#13;&amp;#10;Bcc: attacker@evil.test',
				'Alice&#13;&#10;Bcc: attacker@evil.test',
				'AliceBcc: attacker@evil.test',
			),
			'multi-line'      => array(
				"12 High Street\nLondon",
				"12 High Street<br />\nLondon",
				"12 High Street\nLondon",
				'12 High Street London',
			),
		);
	}

	/**
	 * A newline becomes a `<br />` only AFTER escaping, so a value can never
	 * contribute markup (ADR-0014 §3).
	 *
	 * @return void
	 */
	public function test_the_line_break_conversion_cannot_be_abused() {
		$value = "<br />\n<img src=x onerror=alert(1)>";
		$html  = PlaceholderSyntax::escape( $value, PlaceholderSyntax::CONTEXT_HTML );

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '&lt;br /&gt;', $html, "the value's own <br /> must be escaped" );
		$this->assertSame( 1, substr_count( $html, '<br />' ), 'exactly one <br /> — the one WE inserted for the real newline' );
	}

	/**
	 * A CRLF value in a header context leaves no line-leading header name.
	 *
	 * @return void
	 */
	public function test_a_header_value_can_never_open_a_new_header() {
		foreach ( array( "a\r\nBcc: x@y.test", 'a%0d%0aBcc: x@y.test', 'a&#13;&#10;Bcc: x@y.test' ) as $value ) {
			$escaped = PlaceholderSyntax::escape( $value, PlaceholderSyntax::CONTEXT_HEADER );

			$this->assertStringNotContainsString( "\r", $escaped );
			$this->assertStringNotContainsString( "\n", $escaped );
			$this->assertSame( 0, preg_match( '/^(bcc|cc|to|from|subject):/im', $escaped ) );
		}
	}

	/**
	 * Meta-key SHAPE is tested on the raw value, never repaired (ADR-0014 §6.2).
	 *
	 * @dataProvider meta_key_provider
	 *
	 * @param string $key       Raw key.
	 * @param bool   $valid     Whether the shape is accepted.
	 * @param bool   $protected Whether it is underscore-prefixed.
	 * @param string $why       What the case proves.
	 * @return void
	 */
	public function test_meta_key_policy( string $key, bool $valid, bool $protected, string $why ) {
		$this->assertSame( $valid, PlaceholderSyntax::is_valid_meta_key( $key ), 'shape: ' . $why );
		$this->assertSame( $protected, PlaceholderSyntax::is_protected_meta_key( $key ), 'protection: ' . $why );
	}

	/**
	 * Keys, their shape verdict and their protection verdict.
	 *
	 * @return array<string,array{0:string,1:bool,2:bool,3:string}>
	 */
	public function meta_key_provider(): array {
		return array(
			'public key'        => array( 'total_paid_note', true, false, 'the ordinary case' ),
			'dotted'            => array( 'vendor.field-2', true, false, 'dots and hyphens are part of the alphabet' ),
			'digits'            => array( '2024_note', true, false, 'a key may start with a digit' ),
			'protected'         => array( '_customer_ip_address', true, true, '⚠ shape-valid but protected: refused by POLICY, which the filter can lift' ),
			'stripe token'      => array( '_stripe_source_id', true, true, 'the case ADR-0014 §2 is written around' ),
			'spaces'            => array( 'total paid note', false, false, '⚠ sanitize_key() would REPAIR this into a different, valid key' ),
			'html'              => array( '<b>note</b>', false, false, 'markup is not a key' ),
			'newline'           => array( "note\nBcc:", false, false, 'a break is not a key' ),
			'empty'             => array( '', false, false, 'nothing is not a key' ),
			'brace'             => array( 'no{brace', false, false, 'braces cannot appear in a key' ),
			'percent'           => array( 'note%20', false, false, 'an encoded space is still not a key' ),
			'too long'          => array( str_repeat( 'a', 256 ), false, false, 'bounded length' ),
			'longest permitted' => array( str_repeat( 'a', 255 ), true, false, 'the boundary itself is accepted' ),
		);
	}

	/**
	 * STRING, INTEGER OR FLOAT — and nothing else (ADR-0014 §6.3).
	 *
	 * ⚠ NOT "SCALARS ONLY", WHICH IS WHAT THIS SAID AND WHAT THE ADR SAID. PHP
	 * counts `bool` as a scalar and this refuses it, so the two disagreed. The
	 * three accepted types are now named, and the boolean case carries its own
	 * reason: `(string) true` is `"1"` and `(string) false` is `""`.
	 *
	 * @return void
	 */
	public function test_only_string_int_and_float_meta_values_are_printable() {
		$this->assertTrue( PlaceholderSyntax::is_printable_meta_value( 'a string' ) );
		$this->assertTrue( PlaceholderSyntax::is_printable_meta_value( 0 ) );
		$this->assertTrue( PlaceholderSyntax::is_printable_meta_value( 1.5 ) );
		$this->assertTrue( PlaceholderSyntax::is_printable_meta_value( '' ) );

		$this->assertFalse( PlaceholderSyntax::is_printable_meta_value( array( 'a', 'b' ) ), 'an array must never be serialised into an email' );
		$this->assertFalse( PlaceholderSyntax::is_printable_meta_value( array() ) );
		$this->assertFalse( PlaceholderSyntax::is_printable_meta_value( new \stdClass() ), 'an object graph can reference anything' );
		$this->assertFalse( PlaceholderSyntax::is_printable_meta_value( null ) );

		$this->assertFalse( PlaceholderSyntax::is_printable_meta_value( true ), 'true would print as "1"' );
		$this->assertFalse( PlaceholderSyntax::is_printable_meta_value( false ), 'false would print as nothing at all' );

		// The property the ADR now states, asserted as a property rather than as a
		// list: `is_scalar()` is NOT the rule.
		$this->assertTrue( is_scalar( true ), 'if PHP ever stops calling a bool a scalar, this test is why the ADR says what it says' );
	}

	/**
	 * THE GRAMMAR RECOGNISES A PARAMETER OF ANY LENGTH; THE VALIDATOR DECIDES
	 * (ADR-0014 §1b).
	 *
	 * ⚠ THE CAP USED TO LIVE IN `TOKEN_PATTERN` TOO, AND THAT DELIVERED THE RAW
	 * PLACEHOLDER TO CUSTOMERS. A 256-character parameter was not a token, so
	 * `is_valid_meta_key()` never ran on it and the whole
	 * `{order_custom_field:…}` was emitted as template text. The end-to-end
	 * assertion lives in the integration suite, because THIS test passed
	 * throughout.
	 *
	 * @return void
	 */
	public function test_an_overlength_parameter_is_still_recognised_as_a_token() {
		foreach ( array( 255, 256, 1024 ) as $length ) {
			$key      = str_repeat( 'k', $length );
			$template = 'x{order_custom_field:' . $key . '}y';

			$tokens = PlaceholderSyntax::tokens( $template );

			$this->assertCount( 1, $tokens, $length . '-character parameter was not recognised as a token' );
			$this->assertSame( 'order_custom_field', $tokens[0]['name'] );
			$this->assertSame( $key, $tokens[0]['param'], $length . '-character parameter was truncated by the grammar' );

			// And it SUBSTITUTES, so nothing of the raw token survives into output.
			$rendered = PlaceholderSyntax::render(
				$template,
				static function ( string $name, ?string $param ): string {
					return '<' . $name . '>';
				}
			);

			$this->assertSame( 'x<order_custom_field>y', $rendered, $length . '-character parameter was left literal' );
		}

		// The VALIDATOR is where the cap lives, and it still refuses.
		$this->assertFalse( PlaceholderSyntax::is_valid_meta_key( str_repeat( 'k', 256 ) ) );
		$this->assertTrue( PlaceholderSyntax::is_valid_meta_key( str_repeat( 'k', 255 ) ) );
	}

	/**
	 * Braces and line breaks still bound the parameter, at any length
	 * (ADR-0014 §1, §1b).
	 *
	 * The cap was removed; the two exclusions that make the grammar safe were not.
	 *
	 * @return void
	 */
	public function test_an_unbounded_parameter_still_excludes_braces_and_breaks() {
		$long = str_repeat( 'k', 400 );

		$this->assertSame( array(), PlaceholderSyntax::tokens( '{order_custom_field:' . $long . "\n" . $long . '}' ), 'a token spanned a line break' );
		$this->assertSame( array(), PlaceholderSyntax::tokens( '{order_custom_field:' . $long . "\r" . $long . '}' ), 'a token spanned a carriage return' );

		/*
		 * A BRACE IS NEVER SWALLOWED BY THE PARAMETER, at any length. `{a{b}`
		 * matches the INNER token only (ADR-0014 §1), so the outer parameterised
		 * form does not match at all and `order_custom_field` is never asked for a
		 * 800-character key spanning a brace.
		 */
		$tokens = PlaceholderSyntax::tokens( '{order_custom_field:' . $long . '{' . $long . '}' );

		$this->assertCount( 1, $tokens );
		$this->assertSame( $long, $tokens[0]['name'], 'the match did not start at the INNER brace' );
		$this->assertNull( $tokens[0]['param'], 'the parameter swallowed a brace' );
	}

	/**
	 * The lookup is asked for the FOLDED name and the RAW parameter.
	 *
	 * @return void
	 */
	public function test_the_lookup_receives_a_folded_name_and_a_raw_parameter() {
		$asked = array();

		PlaceholderSyntax::render( '{ORDER_CUSTOM_FIELD:MyKey}', $this->lookup( array(), $asked ) );

		$this->assertSame( array( 'order_custom_field:MyKey' ), $asked );
	}

	/**
	 * A template carrying invalid UTF-8 is returned rather than emptied.
	 *
	 * `preg_replace_callback()` with `/u` returns null on malformed input, which
	 * would blank a merchant's email body. The pattern is deliberately ASCII, so
	 * this substitutes normally instead.
	 *
	 * @return void
	 */
	public function test_invalid_utf8_does_not_empty_the_template() {
		$template = "Hi {customer_first_name} \xC3\x28 end";

		$out = PlaceholderSyntax::render( $template, $this->lookup( array( 'customer_first_name' => 'Alice' ) ) );

		$this->assertStringContainsString( 'Alice', $out );
		$this->assertStringContainsString( 'end', $out );
	}
}
