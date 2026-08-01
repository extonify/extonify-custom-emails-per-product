<?php
/**
 * The `rules.recipients` document: parse, validate, resolve (ADR-0012 §4).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

use Extonify\WCEP\Domain\DeliveryIdentity;
use Extonify\WCEP\Domain\PlaceholderSyntax;
use Extonify\WCEP\Domain\Recipient;
use Extonify\WCEP\Domain\RecipientsDocument;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a rule's recipients document plus two order facts into an ordered,
 * de-duplicated list of addresses with their channels.
 *
 * A PURE FUNCTION of `(document, context)`. The two facts that need WordPress —
 * the customer's billing address and the store admin address — are passed IN by
 * the orchestrator rather than looked up here, so the whole of this parsing,
 * validation, de-duplication and header-injection defence is unit testable with
 * no framework, no order and no mail server.
 *
 * Schema (ADR-0012 §4) — unknown keys are ignored:
 *
 *     {
 *       "to":  ["customer" | "admin" | "an@address.test", ...],
 *       "cc":  [...],
 *       "bcc": [...]
 *     }
 *
 * NOTHING IS DROPPED SILENTLY. An invalid address, an unresolvable token and a
 * stripped line break each add a note, and the note reaches the delivery log —
 * a recipient that vanishes without explanation is precisely the silent failure
 * the decision log exists to eliminate.
 *
 * LITERAL RECIPIENTS RESOLVE TO BARE ADDRESSES (ADR-0012 §4a). Header form is
 * UNDERSTOOD — `Alice <alice@example.test>` is accepted and yields
 * `alice@example.test` — but the display name is not carried into the outbound
 * header, and `recipient_header` is left null for automatic deliveries. That is
 * a decision, not an omission:
 *
 *   - `wp_mail()` splits its `$to` on commas with no regard for quoting, so a
 *     display name containing a comma (`"Smith, Alice" <a@b.test>`) would be
 *     torn into two unusable recipients the moment two entries share a channel;
 *   - the address is the deliverable and the display name is cosmetic, so the
 *     trade is a cosmetic loss against a delivery failure.
 *
 * The drop is NOTED, so the log says the name was discarded rather than leaving
 * a merchant to wonder why it never appeared.
 */
final class RecipientResolver {

	/**
	 * Channels, in precedence order. `to` wins a de-duplication contest, then
	 * `cc`: one person receives one copy, on the most direct channel they
	 * qualified for.
	 */
	const CHANNELS = array( 'to', 'cc', 'bcc' );

	/**
	 * The order's billing email address.
	 */
	const TOKEN_CUSTOMER = 'customer';

	/**
	 * The store's admin address.
	 */
	const TOKEN_ADMIN = 'admin';

	/**
	 * The store's email identity, for `{store_email}`.
	 */
	const TOKEN_STORE = 'store';

	/**
	 * THE ONLY PLACEHOLDERS A RECIPIENT FIELD MAY CONTAIN (ADR-0014 §7), mapped
	 * to the context key each resolves from.
	 *
	 * ⚠ DELIBERATELY NARROWER THAN THE BODY. A recipient field becomes part of a
	 * mail header, and a free-text value in one is a header-injection vector even
	 * after CR/LF stripping — stripping is defence in depth, not a licence to put
	 * arbitrary customer input there. And there is no legitimate use: a merchant
	 * who wants to mail the customer writes `{customer_email}`, and nobody wants
	 * to mail an address assembled out of a billing first name.
	 *
	 * Everything these resolve to still passes `HeaderGuard::strip()`,
	 * `Recipient::normalize()`'s `is_email()` validation and de-duplication. A
	 * placeholder is a SOURCE of an address here, never a bypass around the
	 * checks an address faces.
	 *
	 * ⚠ AND ANY PLACEHOLDER OUTSIDE THIS SET REFUSES THE ENTIRE ENTRY (§7a) —
	 * see self::substitute(). "Resolve it to empty and validate what is left"
	 * delivered `{customer_first_name}alice@example.test` to `alice@example.test`.
	 */
	const SAFE_PLACEHOLDERS = array(
		'customer_email' => self::TOKEN_CUSTOMER,
		'store_email'    => self::TOKEN_STORE,
	);

	/**
	 * Resolve a stored recipients value.
	 *
	 * @param mixed $value   Raw JSON string, decoded array, or null.
	 * @param array $context {
	 *     Addresses the tokens resolve to. A missing or empty entry means the
	 *     token cannot be resolved and is recorded as such.
	 *
	 *     @type string $customer Order billing email.
	 *     @type string $admin    Store admin email.
	 * }
	 * @return ResolvedRecipients
	 */
	public static function resolve( $value, array $context ): ResolvedRecipients {
		// SHAPE IS `Domain\RecipientsDocument`'s JOB, and it enforces `{}`
		// versus `[]` at the raw boundary — an object-shaped channel used to
		// resolve its VALUES as recipients, quietly addressing an email to
		// whatever they held.
		$document = RecipientsDocument::from_value( $value );

		if ( null === $document ) {
			return ResolvedRecipients::invalid( 'recipients document is malformed or is not an object keyed by channel' );
		}

		$entries = array();
		$notes   = array();
		$seen    = array();

		foreach ( self::CHANNELS as $channel ) {
			if ( ! array_key_exists( $channel, $document ) ) {
				continue;
			}

			foreach ( (array) $document[ $channel ] as $raw ) {
				self::add_entry( $raw, $channel, $context, $entries, $notes, $seen );
			}
		}

		return ResolvedRecipients::create( $entries, $notes );
	}

	/**
	 * Resolve one declared entry onto one channel.
	 *
	 * @param mixed    $raw      Declared entry: a token or a literal address.
	 * @param string   $channel  Channel name.
	 * @param array    $context  Token context.
	 * @param array[]  $entries  Accumulated entries, by reference.
	 * @param string[] $notes    Accumulated notes, by reference.
	 * @param array    $seen     Addresses already taken, by reference.
	 * @return void
	 */
	private static function add_entry( $raw, string $channel, array $context, array &$entries, array &$notes, array &$seen ): void {
		if ( ! is_string( $raw ) ) {
			$notes[] = 'dropped a non-string ' . $channel . ' entry';
			return;
		}

		$source = trim( $raw );
		if ( '' === $source ) {
			$notes[] = 'dropped an empty ' . $channel . ' entry';
			return;
		}

		// STRIP BEFORE ANYTHING ELSE LOOKS AT IT (ADR-0012 §4). A break in a
		// literal address, or in the display name of a header-form entry, must
		// never reach header composition — and the attempt is recorded, because
		// a silently sanitised injection is still something support must see.
		if ( HeaderGuard::has_break( $source ) ) {
			$notes[] = 'stripped a line break from a ' . $channel . ' entry';
			$source  = HeaderGuard::strip( $source );
			if ( '' === $source ) {
				return;
			}
		}

		// THE RESTRICTED PLACEHOLDER PASS (ADR-0014 §7), before the token pass
		// and before validation — so whatever a placeholder produces still faces
		// every check a literal address faces.
		$source = self::substitute( $source, $channel, $context, $notes );

		if ( null === $source || '' === $source ) {
			// `null` — the entry contained a disallowed placeholder and is refused
			// AS A WHOLE (ADR-0014 §7a). `''` — every placeholder in it resolved to
			// nothing. Both are recorded by self::substitute(); dropping either
			// silently would leave the merchant with a recipient that vanished for
			// no stated reason.
			return;
		}

		$resolved = self::resolve_token( $source, $context );

		if ( null === $resolved ) {
			$notes[] = 'dropped an unresolvable ' . $channel . ' entry';
			return;
		}

		// `Recipient::normalize()` is the SAME normaliser the detail store and
		// the privacy eraser use — it validates with is_email(), refuses a
		// comma-joined list, and folds ASCII case only, so the address stored
		// here is the address `WHERE recipient = %s` will later find.
		$address = Recipient::normalize( $resolved );

		if ( null === $address ) {
			$notes[] = 'dropped an invalid ' . $channel . ' address';
			return;
		}

		if ( isset( $seen[ $address ] ) ) {
			// Not a note: one person receiving one copy is the intended
			// behaviour, not a degradation.
			return;
		}

		$seen[ $address ] = true;

		// ADR-0012 §4a: the bare address is what is sent and what is stored.
		// Recorded rather than assumed, so "where did my display name go" has an
		// answer in the delivery log.
		if ( Recipient::has_display_name( $source ) ) {
			$notes[] = 'sent a ' . $channel . ' entry to its bare address; the display name is not carried into the header';
		}

		$entries[] = array(
			'address' => $address,
			'type'    => $channel,
			'source'  => $source,
		);
	}

	/**
	 * Resolve the SAFE placeholders in one recipient entry (ADR-0014 §7, §7a).
	 *
	 * SINGLE-PASS, through the same engine the body uses, so a resolved address
	 * is never re-scanned — a customer whose billing email somehow contained
	 * `{store_email}` cannot reach the store's address through it.
	 *
	 * ⚠ A DISALLOWED PLACEHOLDER REFUSES THE **WHOLE ENTRY**, NOT JUST ITSELF
	 * (ADR-0014 §7a). Resolving it to empty and validating the REMAINDER was a hole:
	 *
	 *     {customer_first_name}alice@example.test  ->  alice@example.test  ->  SENT
	 *
	 * The refused placeholder disappeared and what was left happened to be a valid
	 * address, so the entry delivered — to an address §7 never authorised, assembled
	 * out of a field §7 exists to keep out of headers. §7 says a recipient field
	 * takes two placeholders and nothing else; the only way to mean that is to
	 * discard the entry the moment a third one appears, whatever survives it.
	 *
	 * Not left literal either: a literal `{customer_first_name}` would fail
	 * `is_email()` and be dropped as "an invalid address", which is true but
	 * useless — the merchant needs to know the plugin refused a placeholder, not
	 * that "Alice" is not an email address.
	 *
	 * @param string   $source  Entry, already line-break stripped.
	 * @param string   $channel Channel name, for the note.
	 * @param array    $context Token context.
	 * @param string[] $notes   Accumulated notes, by reference.
	 * @return string|null Substituted entry, or NULL when the entry is refused
	 *                     whole.
	 */
	private static function substitute( string $source, string $channel, array $context, array &$notes ): ?string {
		if ( false === strpos( $source, '{' ) ) {
			return $source;
		}

		$refused = false;

		$resolved = PlaceholderSyntax::render(
			$source,
			static function ( string $name, ?string $param ) use ( $channel, $context, &$notes, &$refused ): string {
				$label = PlaceholderSyntax::label( $name, $param );

				if ( null !== $param || ! isset( self::SAFE_PLACEHOLDERS[ $name ] ) ) {
					$refused = true;
					$notes[] = 'refused a disallowed placeholder in a ' . $channel . ' entry: ' . $label
						. '; the whole entry was dropped';
					return '';
				}

				$address = trim( (string) ( $context[ self::SAFE_PLACEHOLDERS[ $name ] ] ?? '' ) );

				if ( '' === $address ) {
					$notes[] = 'resolved ' . $label . ' in a ' . $channel . ' entry to nothing';
				}

				// Header-context escaping, exactly as a subject gets: a value
				// reaching header composition is stripped whatever route it took.
				return PlaceholderSyntax::escape( $address, PlaceholderSyntax::CONTEXT_HEADER );
			}
		);

		return $refused ? null : $resolved;
	}

	/**
	 * Turn a declared entry into a raw address.
	 *
	 * @param string $source  Declared entry.
	 * @param array  $context Token context.
	 * @return string|null Raw address, or null when the token resolves to
	 *                     nothing.
	 */
	private static function resolve_token( string $source, array $context ): ?string {
		// ASCII-RANGE FOLD, never strtolower(). `strtolower()` is
		// locale-sensitive before PHP 8.2 — under a Turkish locale it maps `I`
		// to a dotless `ı`, so a document written `"CUSTOMER"` would stop
		// resolving on that host and start being treated as a literal address.
		// `DeliveryIdentity`, `Recipient` and `Targeting` all fold this way for
		// the same reason; the tokens are no exception.
		$token = strtr( $source, DeliveryIdentity::ASCII_UPPER, DeliveryIdentity::ASCII_LOWER );

		if ( self::TOKEN_CUSTOMER === $token || self::TOKEN_ADMIN === $token ) {
			$address = trim( (string) ( $context[ $token ] ?? '' ) );
			return '' === $address ? null : $address;
		}

		return $source;
	}
}
