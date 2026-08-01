<?php
/**
 * Placeholder grammar, single-pass substitution and per-context escaping
 * (ADR-0014 §1, §2, §3).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Domain;

use Extonify\WCEP\Delivery\HeaderGuard;

defined( 'ABSPATH' ) || exit;

/**
 * The syntax half of placeholder resolution: what a token looks like, how a
 * template is substituted, and how a value is escaped for where it is going.
 *
 * FRAMEWORK-FREE ON PURPOSE. Everything here is PHP built-ins plus
 * `Delivery\HeaderGuard`, which is itself framework-free — so the unit suite
 * exercises the REAL code rather than a shimmed approximation of it. That
 * matters more here than anywhere else in the plugin: this class is the
 * boundary between customer-controlled text and a message a customer receives,
 * and a security boundary tested through a simplified stand-in is a security
 * boundary that has not been tested.
 *
 * ⚠ SUBSTITUTION IS SINGLE-PASS, AND THAT IS A SECURITY INVARIANT (ADR-0014 §2).
 * A resolved value is NEVER re-scanned for placeholders. Values come from
 * customer-controlled fields — a billing first name is whatever the customer
 * typed at checkout — so a recursive or two-pass resolver would let a customer
 * who names themselves
 *
 *     {order_custom_field:_stripe_source_id}
 *
 * have that token RESOLVED AND MAILED BACK TO THEM. Single-pass makes it a
 * literal string in the output, which is harmless.
 *
 * The invariant is STRUCTURAL rather than careful: self::render() is one
 * `preg_replace_callback()` over the template, and PCRE does not re-scan
 * replacement text. Any change that resolves in a loop, or that feeds a resolved
 * value back through this class, reintroduces the vulnerability.
 *
 * ⚠ `esc_html()` IS DELIBERATELY NOT USED. WordPress's `esc_html()` ends with
 * `apply_filters( 'esc_html', $safe_text, $text )`, so a third-party callback can
 * hand back the UNESCAPED input. An escaping step somebody else can switch off is
 * not a boundary. `htmlspecialchars()` is called directly and cannot be hooked.
 */
final class PlaceholderSyntax {

	/**
	 * Output contexts. Each escapes the VALUE differently; see self::escape().
	 */
	const CONTEXT_HTML   = 'html';
	const CONTEXT_PLAIN  = 'plain';
	const CONTEXT_HEADER = 'header';

	/**
	 * The token grammar (ADR-0014 §1).
	 *
	 * `{name}` or `{namespace:key}`, and every part of it answers a case:
	 *
	 *   - the NAME must start with a letter and continue with letters, digits
	 *     and underscores, so `{}`, `{123}` and a bare `{` are not tokens;
	 *   - ⚠ NEITHER PART MAY CONTAIN CR OR LF, so a placeholder broken across a
	 *     line break is inert rather than matching across two lines of content;
	 *   - the parameter excludes braces, so `{a{b}` cannot swallow a brace and
	 *     `{{customer_email}}` matches the INNER token only — leaving literal
	 *     braces around the value, which is harmless, rather than producing a
	 *     token from a value, which is the recursion §2 forbids;
	 *   - whitespace is not part of the grammar, so `{ customer_email }` is left
	 *     LITERAL. Guessing what a merchant meant is how a typo becomes a
	 *     resolved value;
	 *   - an EMPTY parameter still matches (`{order_custom_field:}`), because the
	 *     alternative is delivering those characters to a customer. ⚠ It does NOT
	 *     reduce to "no parameter" — see self::param_of(), where that coercion was a
	 *     hole in the §7 recipient safe set. It is carried as `''`, and every
	 *     consumer refuses it on its own terms and RECORDS the refusal — §1a's
	 *     outcome rather than §1a's failure mode.
	 *
	 * ⚠ THE PARAMETER IS UNBOUNDED IN THE GRAMMAR, AND THE VALIDATOR DECIDES
	 * (ADR-0014 §1b). It used to be capped at `{0,255}` to match
	 * self::META_KEY_PATTERN — which meant a 256-character parameter was NOT A
	 * TOKEN, self::is_valid_meta_key() never ran, and the entire raw placeholder was
	 * DELIVERED TO THE CUSTOMER VERBATIM. The validator sat behind a gate that only
	 * opened for keys it was going to accept anyway. Recognition and validation are
	 * now separate concerns: the grammar says what a token looks like, and
	 * `PlaceholderValues::meta_value()` decides whether it may be read.
	 *
	 * NO `/u` MODIFIER, DELIBERATELY. Every class here is ASCII, and a UTF-8
	 * multibyte sequence never contains an ASCII byte, so byte-wise matching
	 * gives the same answer — while `/u` would make `preg_replace_callback()`
	 * return NULL for a template carrying invalid UTF-8, silently emptying a
	 * merchant's email body.
	 */
	const TOKEN_PATTERN = '/\{([A-Za-z][A-Za-z0-9_]*)(?::([^{}\r\n]*))?\}/';

	/**
	 * Accepted shape for a meta key (ADR-0014 §6.2).
	 *
	 * APPLIED AS A TEST, NEVER AS A TRANSFORMATION. `sanitize_key()` would turn
	 * `total paid note` into the DIFFERENT, VALID key `totalpaidnote` and read
	 * whatever that holds — junk becoming a different valid target, which is the
	 * exact coercion ADR-0011 §2 and ADR-0013 §2 removed from the trigger and
	 * `native_email_id` boundaries.
	 *
	 * A LEADING UNDERSCORE IS STRUCTURALLY VALID HERE and is refused by POLICY
	 * instead — see self::is_protected_meta_key(). Keeping the two apart is what
	 * lets a site owner's filter permit one protected key without also having to
	 * re-open the shape check.
	 *
	 * ⚠ THE 255-CHARACTER CAP LIVES HERE AND ONLY HERE (ADR-0014 §1b). The grammar
	 * deliberately does not enforce it: a length limit in the TOKEN pattern meant an
	 * overlength key was not recognised as a token at all and was delivered to the
	 * customer as raw template text. WordPress's `meta_key` column is
	 * `varchar(255)`, so a longer key cannot name anything that exists — it is
	 * refused, recorded, and resolves empty like any other malformed key.
	 */
	const META_KEY_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.\-]{0,254}$/';

	/**
	 * Substitute every token in a template, ONCE (ADR-0014 §2).
	 *
	 * @param string   $template Merchant-authored template.
	 * @param callable $lookup   `fn( string $name, ?string $param ): string` —
	 *                           returns the FINAL replacement text, already
	 *                           escaped for its destination. Whatever it returns
	 *                           is inserted verbatim and is never examined again.
	 * @return string
	 */
	public static function render( string $template, callable $lookup ): string {
		if ( '' === $template || false === strpos( $template, '{' ) ) {
			// Nothing to do, and the common case: most rule bodies carry no
			// placeholders at all.
			return $template;
		}

		$rendered = preg_replace_callback(
			self::TOKEN_PATTERN,
			static function ( array $matches ) use ( $lookup ): string {
				return (string) $lookup( self::normalize_name( (string) $matches[1] ), self::param_of( $matches ) );
			},
			$template
		);

		// A PCRE failure must never empty a merchant's email. `null` means the
		// engine gave up (backtrack limit, or invalid input); the template is
		// then sent unsubstituted, which is the pre-Prompt-6 behaviour rather
		// than a blank message.
		return null === $rendered ? $template : $rendered;
	}

	/**
	 * Every distinct token in a template, in first-appearance order.
	 *
	 * For diagnostics and tests. `render()` does NOT call this — it substitutes
	 * in one pass — so this cannot become a second scan of anything.
	 *
	 * @param string $template Template.
	 * @return array[] Each entry `{name, param, label}`.
	 */
	public static function tokens( string $template ): array {
		$found = array();
		$seen  = array();

		if ( ! preg_match_all( self::TOKEN_PATTERN, $template, $matches, PREG_SET_ORDER ) ) {
			return $found;
		}

		foreach ( $matches as $match ) {
			$name  = self::normalize_name( (string) $match[1] );
			$param = self::param_of( $match );
			$label = self::label( $name, $param );

			if ( isset( $seen[ $label ] ) ) {
				continue;
			}

			$seen[ $label ] = true;
			$found[]        = array(
				'name'  => $name,
				'param' => $param,
				'label' => $label,
			);
		}

		return $found;
	}

	/**
	 * The parameter one match carries: a string, or NULL for no parameter at all
	 * (ADR-0014 §1e).
	 *
	 * ⚠ `''` AND `null` ARE DIFFERENT ANSWERS, AND CONFLATING THEM WAS A HOLE. This
	 * used to be
	 *
	 *     isset( $matches[2] ) && '' !== $matches[2] ? (string) $matches[2] : null
	 *
	 * so `{customer_email:}` was COERCED into `{customer_email}` and resolved to the
	 * customer's address. The §7 recipient safe set authorises the exact NAME and
	 * refuses anything parameterised, so that coercion handed the safe set a token
	 * it had never authorised — the same "junk repaired into a valid target" shape
	 * ADR-0011 §2, ADR-0013 §2 and ADR-0014 §6.2 each removed from their own
	 * boundaries. An explicitly empty parameter is now preserved, and each consumer
	 * refuses it on its own terms: the meta resolver as a malformed empty key, a
	 * non-parameterised name as unknown, a recipient field as a disallowed
	 * placeholder that drops the whole entry.
	 *
	 * `isset()` ALONE IS THE CORRECT DISCRIMINATOR, and it is a property of the
	 * pattern rather than a convention. Group 2 is the LAST group, and PCRE omits
	 * trailing groups that did not participate — verified against the shipped
	 * pattern: `{customer_email}` yields a two-element match, `{customer_email:}`
	 * yields three with `''` in the third.
	 *
	 * @param array $matches One `preg_*` match set.
	 * @return string|null
	 */
	private static function param_of( array $matches ): ?string {
		return isset( $matches[2] ) ? (string) $matches[2] : null;
	}

	/**
	 * Fold a placeholder NAME to lower case, ASCII range only.
	 *
	 * NEVER `strtolower()`, for the reason `DeliveryIdentity`, `Recipient`,
	 * `Targeting` and `RecipientResolver` all avoid it: it is locale-sensitive
	 * before PHP 8.2, and under a Turkish locale it maps `I` to a dotless `ı` —
	 * so `{CUSTOMER_EMAIL}` would resolve on one host and not on another.
	 *
	 * ⚠ META KEYS ARE NOT FOLDED. WordPress meta keys are case-sensitive in the
	 * database, so folding one would look up a key that was never stored.
	 *
	 * @param string $name Raw placeholder name.
	 * @return string
	 */
	public static function normalize_name( string $name ): string {
		return strtr( $name, DeliveryIdentity::ASCII_UPPER, DeliveryIdentity::ASCII_LOWER );
	}

	/**
	 * The `{name}` / `{name:param}` spelling of a token, for the delivery log.
	 *
	 * @param string      $name  Normalised name.
	 * @param string|null $param Parameter, or null.
	 * @return string
	 */
	public static function label( string $name, ?string $param = null ): string {
		return null === $param ? '{' . $name . '}' : '{' . $name . ':' . $param . '}';
	}

	/**
	 * Escape one resolved VALUE for the destination it is going to
	 * (ADR-0014 §3).
	 *
	 * THE TEMPLATE IS THE MERCHANT'S AND HAS ALREADY PASSED `wp_kses_post` AT
	 * STORAGE; THE VALUE IS NOT AND HAS NOT. So escaping happens here, at
	 * substitution, per context — and the same placeholder therefore renders
	 * differently in HTML and in plain text BY DESIGN.
	 *
	 * ⚠ NEWLINE-TO-`<br />` HAPPENS AFTER ESCAPING, NEVER BEFORE. The `<br />`
	 * is markup this plugin emits from a `\n` it can see; the value cannot
	 * contribute markup of its own at any point. That is what lets
	 * `{billing_address}` render as a readable multi-line address in HTML while
	 * staying a plain `\n`-separated string in text.
	 *
	 * @param string $value   Resolved value — always TEXT (ADR-0014 §3: no
	 *                        placeholder ever yields markup).
	 * @param string $context One of the self::CONTEXT_* constants.
	 * @return string
	 */
	public static function escape( string $value, string $context ): string {
		if ( self::CONTEXT_HEADER === $context ) {
			/*
			 * Subject and heading: CR and LF in every spelling, plus every other
			 * control character (ADR-0012 §4).
			 *
			 * A RAW newline becomes a SPACE first, purely so a multi-line value
			 * reads as a sentence: `HeaderGuard` deletes it outright, which turned
			 * a two-line address into "12 High StreetLondon". Cosmetic only — the
			 * break is gone either way, and the encoded spellings are still
			 * REMOVED rather than spaced, because a value spelling its break as
			 * `%0d%0a` is not a merchant formatting an address.
			 */
			return HeaderGuard::strip( str_replace( array( "\r\n", "\r", "\n" ), ' ', $value ) );
		}

		$value = self::normalize_line_endings( $value );

		if ( self::CONTEXT_PLAIN === $context ) {
			// Text must read as text: there is no markup to protect against in a
			// `text/plain` body, and escaping there could only corrupt it
			// (ADR-0013 §4b, which this generalises to every value).
			return $value;
		}

		return str_replace(
			"\n",
			"<br />\n",
			htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' )
		);
	}

	/**
	 * Whether a raw meta key is well-formed (ADR-0014 §6.2).
	 *
	 * @param string $raw Raw key, exactly as the template supplied it.
	 * @return bool
	 */
	public static function is_valid_meta_key( string $raw ): bool {
		return 1 === preg_match( self::META_KEY_PATTERN, $raw );
	}

	/**
	 * Whether a raw meta key is WordPress-protected, i.e. underscore-prefixed
	 * (ADR-0014 §6.1).
	 *
	 * WordPress's own convention hides `_`-prefixed meta from the editor, and
	 * WooCommerce stores exactly what that convention exists for:
	 * `_stripe_source_id`, `_customer_ip_address`, `_customer_user_agent`,
	 * `_billing_address_index`. Any of them in a customer-facing email is a data
	 * breach.
	 *
	 * @param string $raw Raw key.
	 * @return bool
	 */
	public static function is_protected_meta_key( string $raw ): bool {
		return '' !== $raw && '_' === $raw[0];
	}

	/**
	 * Whether a meta value may be substituted at all (ADR-0014 §6.3).
	 *
	 * **STRING, INTEGER OR FLOAT. Nothing else.** Stated as those three types
	 * rather than as "scalars", because PHP's definition of scalar includes `bool`
	 * and this refuses it — so "scalars only" described the code incorrectly for as
	 * long as it was written that way.
	 *
	 * An array or object resolves EMPTY rather than being serialised:
	 * `a:2:{i:0;s:5:"…"}` in a customer email is meaningless at best, and rendering
	 * an object graph can carry anything the graph references.
	 *
	 * ⚠ AND A BOOLEAN RESOLVES EMPTY TOO, WHICH IS A DECISION RATHER THAN AN
	 * OVERSIGHT. `(string) true` is `"1"` and `(string) false` is `""` — so a
	 * merchant writing `{order_custom_field:is_gift}` would get `1` for yes and a
	 * blank for no, two renderings that read as "1" and "nothing was stored". There
	 * is no wording this plugin could choose that would be right for every store's
	 * flag, so it declines to guess, and the refusal is recorded.
	 *
	 * @param mixed $value Raw meta value.
	 * @return bool
	 */
	public static function is_printable_meta_value( $value ): bool {
		return is_string( $value ) || is_int( $value ) || is_float( $value );
	}

	/**
	 * Normalise CRLF and CR to LF.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function normalize_line_endings( string $value ): string {
		return (string) preg_replace( '/\r\n|\r/', "\n", $value );
	}
}
