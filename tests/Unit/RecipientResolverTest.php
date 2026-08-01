<?php
/**
 * The recipients document: parse, validate, resolve (ADR-0012 §4).
 *
 * @package Extonify\WCEP\Tests
 */

namespace Extonify\WCEP\Tests\Unit;

use Extonify\WCEP\Delivery\RecipientResolver;
use Extonify\WCEP\Domain\RecipientsDocument;

/**
 * Resolution is a pure function of the document plus two order facts, so every
 * branch is provable here — with no order, no mail server and no framework.
 */
final class RecipientResolverTest extends UnitTestCase {

	/**
	 * The two addresses the tokens resolve to.
	 *
	 * @param array $overrides Context fields to replace.
	 * @return array
	 */
	private function context( array $overrides = array() ): array {
		return array_merge(
			array(
				RecipientResolver::TOKEN_CUSTOMER => 'customer@example.test',
				RecipientResolver::TOKEN_ADMIN    => 'admin@example.test',
			),
			$overrides
		);
	}

	/**
	 * Resolve a document against the default context.
	 *
	 * @param mixed $document Recipients document.
	 * @param array $context  Context overrides.
	 * @return \Extonify\WCEP\Delivery\ResolvedRecipients
	 */
	private function resolve( $document, array $context = array() ) {
		return RecipientResolver::resolve( $document, $this->context( $context ) );
	}

	// ---------------------------------------------------------------------
	// The valid shapes.
	// ---------------------------------------------------------------------

	/**
	 * Each channel resolves its tokens and literals onto the right type.
	 *
	 * @return void
	 */
	public function test_every_channel_resolves() {
		$resolved = $this->resolve(
			'{"to":["customer"],"cc":["admin"],"bcc":["warehouse@example.test"]}'
		);

		$this->assertTrue( $resolved->is_valid() );
		$this->assertTrue( $resolved->is_deliverable() );
		$this->assertSame( array( 'customer@example.test' ), $resolved->addresses( 'to' ) );
		$this->assertSame( array( 'admin@example.test' ), $resolved->addresses( 'cc' ) );
		$this->assertSame( array( 'warehouse@example.test' ), $resolved->addresses( 'bcc' ) );
		$this->assertCount( 3, $resolved->entries(), 'One entry per resolved recipient (ADR-0012 §4).' );
		$this->assertSame( array(), $resolved->notes(), 'A clean document records nothing.' );
	}

	/**
	 * A decoded array is accepted as well as a raw JSON string.
	 *
	 * @return void
	 */
	public function test_a_decoded_array_is_accepted() {
		$resolved = $this->resolve( array( 'to' => array( 'customer', 'admin' ) ) );

		$this->assertSame(
			array( 'customer@example.test', 'admin@example.test' ),
			$resolved->addresses( 'to' )
		);
	}

	/**
	 * Addresses are normalised with the same ASCII fold the privacy eraser
	 * searches by, so a stored row is findable.
	 *
	 * @return void
	 */
	public function test_addresses_are_normalised() {
		$resolved = $this->resolve( '{"to":["  Alice@Example.TEST  "]}' );

		$this->assertSame( array( 'alice@example.test' ), $resolved->addresses( 'to' ) );
	}

	/**
	 * A header-form entry keeps only its bare address, because that is what the
	 * detail store can search on.
	 *
	 * @return void
	 */
	public function test_a_header_form_entry_reduces_to_its_address() {
		$resolved = $this->resolve( '{"to":["Alice Smith <Alice@Example.test>"]}' );

		$this->assertSame( array( 'alice@example.test' ), $resolved->addresses( 'to' ) );
	}

	/**
	 * THE DISPLAY NAME IS DROPPED, AND THE DROP IS RECORDED (ADR-0012 §4a).
	 *
	 * Preserving it would mean composing `"Smith, Alice" <a@b.test>` into a
	 * comma-joined recipient list, and `wp_mail()` splits that list on commas
	 * with no regard for quoting — so one display name containing a comma tears
	 * the whole list apart. The address is the deliverable; the name is
	 * cosmetic. What must NOT happen is losing it silently.
	 *
	 * @return void
	 */
	public function test_a_dropped_display_name_is_recorded() {
		$resolved = $this->resolve( '{"to":["Alice Smith <alice@example.test>"],"cc":["bob@example.test"]}' );

		$this->assertSame( array( 'alice@example.test' ), $resolved->addresses( 'to' ) );

		$reason = $resolved->reason();
		$this->assertStringContainsString( 'display name', $reason, 'The discarded display name left no trace.' );
		$this->assertStringContainsString( 'to', $reason );

		// A bare address carries no such note — the notes stay meaningful.
		$this->assertCount(
			1,
			array_filter(
				$resolved->notes(),
				static function ( string $note ): bool {
					return false !== strpos( $note, 'display name' );
				}
			),
			'A bare address was reported as having had a display name dropped.'
		);
	}

	/**
	 * A quoted display name containing a comma is exactly the case the contract
	 * exists for: one recipient, one bare address, no torn list.
	 *
	 * @return void
	 */
	public function test_a_comma_bearing_display_name_still_yields_one_recipient() {
		$resolved = $this->resolve( '{"to":["\"Smith, Alice\" <alice@example.test>","bob@example.test"]}' );

		$this->assertSame(
			array( 'alice@example.test', 'bob@example.test' ),
			$resolved->addresses( 'to' )
		);

		foreach ( $resolved->addresses( 'to' ) as $address ) {
			$this->assertStringNotContainsString( ',', $address );
			$this->assertStringNotContainsString( '<', $address );
		}
	}

	/**
	 * An empty document is VALID and simply resolves nobody — a rule with no
	 * recipients configured yet, not a corrupt one.
	 *
	 * @dataProvider empty_document_provider
	 *
	 * @param mixed $document Empty document.
	 * @return void
	 */
	public function test_an_empty_document_is_valid_but_undeliverable( $document ) {
		$resolved = $this->resolve( $document );

		$this->assertTrue( $resolved->is_valid() );
		$this->assertFalse( $resolved->is_deliverable() );
		$this->assertSame( array(), $resolved->entries() );
	}

	/**
	 * Every spelling of "no recipients yet".
	 *
	 * @return array
	 */
	public function empty_document_provider(): array {
		return array(
			'null'           => array( null ),
			'empty string'   => array( '' ),
			// A PHP array, not raw text: the `{}`-versus-`[]` distinction does
			// not exist here, and a constructed empty document is legitimate.
			'empty array'    => array( array() ),
			'empty json obj' => array( '{}' ),
			'empty channels' => array( '{"to":[],"cc":[],"bcc":[]}' ),
		);
	}

	// ---------------------------------------------------------------------
	// Shape errors.
	// ---------------------------------------------------------------------

	/**
	 * A malformed document resolves nobody and records why.
	 *
	 * @dataProvider malformed_provider
	 *
	 * @param mixed  $document Malformed document.
	 * @param string $label    What is wrong with it.
	 * @return void
	 */
	public function test_a_malformed_document_is_invalid( $document, string $label ) {
		$resolved = $this->resolve( $document );

		$this->assertFalse( $resolved->is_valid(), $label );
		$this->assertFalse( $resolved->is_deliverable(), $label );
		$this->assertSame( array(), $resolved->entries(), $label );
		$this->assertNotSame( '', $resolved->reason(), $label . ' left no reason for the log.' );
	}

	/**
	 * Malformed JSON and shape errors.
	 *
	 * @return array<string,array{0:mixed,1:string}>
	 */
	public function malformed_provider(): array {
		return array(
			'truncated json'  => array( '{"to":["customer"', 'truncated JSON' ),
			'not json'        => array( 'to: customer', 'not JSON at all' ),
			'json scalar'     => array( '"customer"', 'a JSON scalar' ),
			'json number'     => array( '42', 'a JSON number' ),
			'stored int'      => array( 42, 'a stored integer' ),
			'stored bool'     => array( true, 'a stored boolean' ),
			'list root'       => array( '["customer","admin"]', 'a list-shaped root' ),
			'empty list root' => array( '[]', 'an EMPTY list-shaped root' ),
			'channel object'  => array( '{"to":{"0":"customer"}}', 'a channel written as a JSON object' ),
			'named object'    => array( '{"to":{"a":"customer"}}', 'a channel written as a named object' ),
			'empty obj chan'  => array( '{"to":{}}', 'an empty object channel' ),
			'list root array' => array( array( 'customer' ), 'a list-shaped decoded root' ),
			'channel scalar'  => array( '{"to":"customer"}', 'a channel that is not a list' ),
			'channel bool'    => array( '{"to":true}', 'a channel that is a boolean' ),
			'cc scalar'       => array( '{"to":["customer"],"cc":7}', 'a cc channel that is not a list' ),
		);
	}

	/**
	 * `{}` and `[]` are DISTINGUISHED at the raw boundary, exactly as
	 * `Targeting` does it (ADR-0012 §4).
	 *
	 * An object-shaped channel used to resolve its VALUES as recipients — so a
	 * malformed document quietly addressed the email to whatever they held. That
	 * is why the distinction matters here and not only in targeting.
	 *
	 * @dataProvider raw_shape_provider
	 *
	 * @param string $json  Raw column string.
	 * @param bool   $valid Expected validity.
	 * @return void
	 */
	public function test_object_and_list_shapes_are_distinguished_at_the_raw_boundary( string $json, bool $valid ) {
		$resolved = $this->resolve( $json );

		$this->assertSame( $valid, $resolved->is_valid(), $json );
		$this->assertFalse( $resolved->is_deliverable(), $json . ' should resolve nobody either way.' );
	}

	/**
	 * The empty shapes, object versus list, on the root and on a channel.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function raw_shape_provider(): array {
		return array(
			'root object'     => array( '{}', true ),
			'root list'       => array( '[]', false ),
			'empty to list'   => array( '{"to":[]}', true ),
			'empty to object' => array( '{"to":{}}', false ),
			'to as object'    => array( '{"to":{"0":"customer"}}', false ),
			'cc as object'    => array( '{"to":[],"cc":{"0":"admin"}}', false ),
		);
	}

	/**
	 * The encoder writes what the reader accepts: a programmatic empty document
	 * stores as `{}`, not the `[]` PHP renders for an empty array.
	 *
	 * Without this, every rule with no recipients configured yet would report
	 * its document unusable rather than simply having none.
	 *
	 * @return void
	 */
	public function test_the_encoder_round_trips_through_the_strict_reader() {
		$cases = array(
			'{}'                  => array(),
			'{"to":[]}'           => array( 'to' => array() ),
			'{"to":["customer"]}' => array( 'to' => array( 'customer' ) ),
		);

		foreach ( $cases as $expected => $document ) {
			$json = RecipientsDocument::encode( $document );

			$this->assertSame( $expected, $json );
			$this->assertTrue( $this->resolve( $json )->is_valid(), $json . ' did not survive its own encoder.' );
		}

		// Garbage is NOT repaired on the way in: a list-shaped root is written
		// as a list and reads back invalid.
		$this->assertSame( '["customer"]', RecipientsDocument::encode( array( 'customer' ) ) );
		$this->assertFalse( $this->resolve( '["customer"]' )->is_valid() );
	}

	/**
	 * Token folding is ASCII-RANGE, never `strtolower()` (ADR-0012 §4).
	 *
	 * `strtolower()` is locale-sensitive before PHP 8.2 — under a Turkish locale
	 * it maps `I` to a dotless i, so `"CUSTOMER"` would stop resolving on that
	 * host and start being treated as a literal address. Every other identifier
	 * in this plugin folds ASCII-only for the same reason.
	 *
	 * @return void
	 */
	public function test_tokens_fold_in_the_ascii_range_only() {
		foreach ( array( 'customer', 'CUSTOMER', 'Customer', 'cUsToMeR' ) as $token ) {
			$this->assertSame(
				array( 'customer@example.test' ),
				$this->resolve( array( 'to' => array( $token ) ) )->addresses( 'to' ),
				$token . ' did not resolve as the customer token.'
			);
		}

		// A non-ASCII near-miss is NOT the token: it is treated as a literal
		// address, and dropped for not being one.
		$resolved = $this->resolve( array( 'to' => array( "cu\xC4\xB1tomer" ) ) );
		$this->assertSame( array(), $resolved->addresses( 'to' ) );
		$this->assertNotSame( '', $resolved->reason() );
	}

	/**
	 * An invalid address is dropped, the rest survive, and the drop is
	 * RECORDED.
	 *
	 * @dataProvider invalid_address_provider
	 *
	 * @param string $address Invalid entry.
	 * @return void
	 */
	public function test_invalid_addresses_are_dropped_and_recorded( string $address ) {
		$resolved = $this->resolve(
			array(
				'to' => array( 'customer', $address ),
			)
		);

		$this->assertTrue( $resolved->is_valid(), 'One bad entry is not a shape error.' );
		$this->assertSame( array( 'customer@example.test' ), $resolved->addresses( 'to' ) );
		$this->assertNotSame( '', $resolved->reason(), 'A dropped address must leave a trace.' );
	}

	/**
	 * Entries that are not usable addresses.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function invalid_address_provider(): array {
		return array(
			'no at sign'  => array( 'not-an-address' ),
			'no domain'   => array( 'alice@' ),
			'no local'    => array( '@example.test' ),
			'spaces'      => array( 'alice smith@example.test' ),
			'comma list'  => array( 'a@example.test, b@example.test' ),
			'semicolons'  => array( 'a@example.test; b@example.test' ),
			'stray angle' => array( 'alice@example.test>' ),
		);
	}

	/**
	 * A non-string entry is dropped and recorded.
	 *
	 * @return void
	 */
	public function test_non_string_entries_are_dropped_and_recorded() {
		$resolved = $this->resolve(
			array(
				'to' => array( 'customer', 42, null, array( 'a@example.test' ), true ),
			)
		);

		$this->assertSame( array( 'customer@example.test' ), $resolved->addresses( 'to' ) );
		$this->assertCount( 4, $resolved->notes(), 'Each dropped entry records a note.' );
	}

	/**
	 * A token whose context address is missing resolves to nothing, and says so
	 * — a guest order with no billing email must not silently send to nobody.
	 *
	 * @return void
	 */
	public function test_an_unresolvable_token_is_recorded() {
		$resolved = $this->resolve(
			'{"to":["customer"]}',
			array( RecipientResolver::TOKEN_CUSTOMER => '' )
		);

		$this->assertTrue( $resolved->is_valid() );
		$this->assertFalse( $resolved->is_deliverable() );
		$this->assertNotSame( '', $resolved->reason() );
	}

	// ---------------------------------------------------------------------
	// De-duplication.
	// ---------------------------------------------------------------------

	/**
	 * One person receives ONE copy, on the most direct channel they qualified
	 * for: `to` beats `cc` beats `bcc`.
	 *
	 * @return void
	 */
	public function test_deduplication_across_channels_with_to_winning() {
		$resolved = $this->resolve(
			'{"to":["customer"],"cc":["customer","admin"],"bcc":["admin","customer","third@example.test"]}'
		);

		$this->assertSame( array( 'customer@example.test' ), $resolved->addresses( 'to' ) );
		$this->assertSame( array( 'admin@example.test' ), $resolved->addresses( 'cc' ), 'admin should win cc over bcc.' );
		$this->assertSame( array( 'third@example.test' ), $resolved->addresses( 'bcc' ) );
		$this->assertCount( 3, $resolved->entries(), 'Three people, three rows.' );
	}

	/**
	 * De-duplication is case- and form-insensitive, because normalisation runs
	 * first.
	 *
	 * @return void
	 */
	public function test_deduplication_sees_through_case_and_header_form() {
		$resolved = $this->resolve(
			'{"to":["Alice@Example.test","alice@example.test","Alice Smith <ALICE@EXAMPLE.TEST>"]}'
		);

		$this->assertSame( array( 'alice@example.test' ), $resolved->addresses( 'to' ) );
	}

	/**
	 * A duplicate is NOT recorded as a note: one copy per person is the
	 * intended behaviour, not a degradation.
	 *
	 * @return void
	 */
	public function test_deduplication_is_not_reported_as_a_problem() {
		$resolved = $this->resolve( '{"to":["customer","customer"]}' );

		$this->assertSame( array( 'customer@example.test' ), $resolved->addresses( 'to' ) );
		$this->assertSame( array(), $resolved->notes() );
	}

	/**
	 * The customer and admin tokens colliding on one address yields one
	 * recipient, on the `to` channel.
	 *
	 * @return void
	 */
	public function test_customer_and_admin_may_be_the_same_person() {
		$resolved = $this->resolve(
			'{"to":["customer"],"cc":["admin"]}',
			array( RecipientResolver::TOKEN_ADMIN => 'customer@example.test' )
		);

		$this->assertSame( array( 'customer@example.test' ), $resolved->addresses( 'to' ) );
		$this->assertSame( array(), $resolved->addresses( 'cc' ) );
	}

	// ---------------------------------------------------------------------
	// Header injection (ADR-0012 §4).
	// ---------------------------------------------------------------------

	/**
	 * A line break in a recipient entry never survives resolution, in any form,
	 * and the attempt is RECORDED.
	 *
	 * @dataProvider hostile_entry_provider
	 *
	 * @param string $entry Hostile entry.
	 * @param string $label What it is.
	 * @return void
	 */
	public function test_line_breaks_never_reach_a_resolved_recipient( string $entry, string $label ) {
		$resolved = $this->resolve(
			array(
				'to' => array( 'customer', $entry ),
			)
		);

		foreach ( $resolved->entries() as $item ) {
			$this->assertStringNotContainsString( "\r", $item['address'], $label );
			$this->assertStringNotContainsString( "\n", $item['address'], $label );
			$this->assertStringNotContainsString( 'attacker@evil.test', $item['address'], $label );
		}

		$this->assertNotContains( 'attacker@evil.test', $resolved->addresses( 'bcc' ), $label );
		$this->assertNotSame( '', $resolved->reason(), $label . ' left no trace in the log.' );
	}

	/**
	 * Injection attempts in a recipient entry, raw and encoded.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function hostile_entry_provider(): array {
		return array(
			'raw CRLF'       => array( "victim@example.test\r\nBcc: attacker@evil.test", 'raw CRLF' ),
			'raw LF'         => array( "victim@example.test\nBcc: attacker@evil.test", 'raw LF' ),
			'percent lower'  => array( 'victim@example.test%0d%0aBcc: attacker@evil.test', 'percent-encoded' ),
			'percent upper'  => array( 'victim@example.test%0D%0ABcc: attacker@evil.test', 'percent-encoded uppercase' ),
			'entity decimal' => array( 'victim@example.test&#13;&#10;Bcc: attacker@evil.test', 'decimal entities' ),
			'entity hex'     => array( 'victim@example.test&#x0d;&#x0a;Bcc: attacker@evil.test', 'hex entities' ),
			'display name'   => array( "Alice\r\nBcc: attacker@evil.test <victim@example.test>", 'hostile display name' ),
		);
	}

	/**
	 * A hostile DISPLAY NAME is the realistic attack — it comes straight from a
	 * billing field the customer controls. The address still resolves; the
	 * injected header does not.
	 *
	 * @return void
	 */
	public function test_a_hostile_display_name_still_yields_a_clean_address() {
		$resolved = $this->resolve(
			array(
				'to' => array( "Alice\r\nBcc: attacker@evil.test <alice@example.test>" ),
			)
		);

		$this->assertSame( array( 'alice@example.test' ), $resolved->addresses( 'to' ) );
		$this->assertStringContainsString( 'stripped a line break', $resolved->reason() );
	}

	/**
	 * An entry that is nothing but a line break is dropped rather than becoming
	 * an empty recipient.
	 *
	 * @return void
	 */
	public function test_an_entry_of_only_breaks_is_dropped() {
		$resolved = $this->resolve(
			array(
				'to' => array( 'customer', "\r\n", '%0d%0a' ),
			)
		);

		$this->assertSame( array( 'customer@example.test' ), $resolved->addresses( 'to' ) );
	}

	// ---------------------------------------------------------------------
	// ADR-0014 §7 / §7a — the restricted placeholder set.
	// ---------------------------------------------------------------------

	/**
	 * The two SAFE placeholders resolve, and everything they produce still faces
	 * every check a literal address faces (ADR-0014 §7).
	 *
	 * @return void
	 */
	public function test_the_safe_recipient_placeholders_resolve() {
		$resolved = $this->resolve(
			array(
				'to'  => array( '{customer_email}' ),
				'bcc' => array( '{store_email}' ),
			),
			array( RecipientResolver::TOKEN_STORE => 'shop@example.test' )
		);

		$this->assertSame( array( 'customer@example.test' ), $resolved->addresses( 'to' ) );
		$this->assertSame( array( 'shop@example.test' ), $resolved->addresses( 'bcc' ) );
		$this->assertSame( array(), $resolved->notes() );
	}

	/**
	 * ⚠ ANY PLACEHOLDER OUTSIDE THE SAFE SET REFUSES THE **WHOLE** ENTRY, WHATEVER
	 * SURVIVES SUBSTITUTION (ADR-0014 §7a).
	 *
	 * The implementation used to resolve the disallowed placeholder to empty and
	 * validate the REMAINDER — so `{customer_first_name}alice@example.test` became
	 * `alice@example.test` and delivered, to an address §7 never authorised,
	 * assembled out of exactly the customer-controlled field §7 exists to keep out
	 * of a mail header.
	 *
	 * @dataProvider refused_entry_provider
	 *
	 * @param string $entry  Declared entry.
	 * @param string $label  Placeholder that must be named in the note.
	 * @param string $leaked Fragment that must NOT become an address.
	 * @return void
	 */
	public function test_any_disallowed_placeholder_refuses_the_whole_entry( string $entry, string $label, string $leaked ) {
		$resolved = $this->resolve( array( 'to' => array( $entry ) ) );

		$this->assertSame( array(), $resolved->addresses( 'to' ), $entry . ' still produced a recipient' );
		$this->assertFalse( $resolved->is_deliverable(), $entry );

		$this->assertStringContainsString(
			'refused a disallowed placeholder in a to entry: ' . $label,
			$resolved->reason(),
			$entry . ': the refusal names no placeholder'
		);
		$this->assertStringContainsString( 'the whole entry was dropped', $resolved->reason(), $entry );

		if ( '' !== $leaked ) {
			$this->assertStringNotContainsString( $leaked, implode( ' ', $resolved->addresses( 'to' ) ), $entry );
		}
	}

	/**
	 * The three shapes the empty-and-revalidate implementation delivered.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function refused_entry_provider(): array {
		return array(
			'prefixed'            => array( '{customer_first_name}alice@example.test', '{customer_first_name}', 'alice@example.test' ),
			'interpolated'        => array( 'alice+{order_number}@example.test', '{order_number}', 'alice+' ),
			'parameterised'       => array( '{order_custom_field:email}', '{order_custom_field:email}', '' ),
			'alone'               => array( '{customer_first_name}', '{customer_first_name}', '' ),
			'safe plus unsafe'    => array( '{customer_email}{customer_phone}', '{customer_phone}', 'customer@example.test' ),
			'parameterised safe'  => array( '{customer_email:x}', '{customer_email:x}', '' ),
		);
	}

	/**
	 * A refused entry takes ITSELF down, not the channel (ADR-0014 §7a).
	 *
	 * Fail-closed must not become fail-everything: a merchant with one bad entry
	 * and one good one still mails the good one.
	 *
	 * @return void
	 */
	public function test_a_refused_entry_does_not_take_its_channel_with_it() {
		$resolved = $this->resolve(
			array(
				'to' => array( '{customer_first_name}alice@example.test', 'customer' ),
			)
		);

		$this->assertSame( array( 'customer@example.test' ), $resolved->addresses( 'to' ) );
		$this->assertTrue( $resolved->is_deliverable() );
		$this->assertStringContainsString( 'the whole entry was dropped', $resolved->reason() );
	}

	/**
	 * A SAFE placeholder that resolves to nothing is recorded, and the entry is
	 * dropped rather than becoming an empty recipient (ADR-0014 §7).
	 *
	 * @return void
	 */
	public function test_a_safe_placeholder_resolving_to_nothing_is_recorded() {
		$resolved = $this->resolve(
			array( 'to' => array( '{store_email}' ) ),
			array( RecipientResolver::TOKEN_STORE => '' )
		);

		$this->assertSame( array(), $resolved->addresses( 'to' ) );
		$this->assertStringContainsString( 'resolved {store_email} in a to entry to nothing', $resolved->reason() );
		$this->assertStringNotContainsString( 'the whole entry was dropped', $resolved->reason(), 'an empty SAFE placeholder is not a refusal' );
	}
}
