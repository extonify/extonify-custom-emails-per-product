<?php
/**
 * Which WooCommerce emails an insert rule can actually reach (ADR-0013 §2a).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies every registered WooCommerce email by whether insert mode can reach it.
 *
 * ⚠ INSERT MODE HAS EXACTLY ONE ENTRY POINT, AND AN EMAIL THAT NEVER FIRES IT IS
 * UNREACHABLE. `Render\RenderEvents` binds `woocommerce_email_order_details` at
 * priorities 5 and 15; that pair is what pushes the frame, runs the evaluation and
 * opens the slot. An email whose template never fires that action opens no frame, so
 * nothing is evaluated, no slot exists, no delivery record is written and **nothing
 * appears in the history to explain the silence**.
 *
 * ⚠ THAT WAS A TIER 1 DEFECT AND THE DROPDOWN WAS THE WHOLE OF IT. Until Prompt 13C
 * Part M, `Admin\FieldOptions::native_emails()` iterated `WC()->mailer()->get_emails()`
 * and excluded NOTHING, so the editor offered `customer_new_account`,
 * `customer_reset_password`, `admin_payment_gateway_enabled` — and this plugin's own
 * `extonify_wcep_custom` — as insert targets. A merchant could pick one, write
 * content, save a rule that validated cleanly and read as active, and never receive a
 * single email or a single line of explanation. Merchant content silently never
 * delivered, with no screen saying so.
 *
 * ⚠ A HARD-CODED LIST OF THE FIVE KNOWN-BAD IDS WOULD HAVE BEEN THE WRONG FIX, and
 * this class exists because of that. A store may register order emails of its own
 * through `woocommerce_email_classes`; a fixed blocklist would keep offering an
 * unknown third-party non-order email while wrongly hiding a legitimate third-party
 * order one. What is detected here is the PROPERTY that actually matters — does the
 * template this email renders through fire the hook — not a list of names.
 *
 * ⚠ AND IT FAILS TOWARD OFFERING. Three outcomes, never two: `RENDERS` when an
 * EMAIL-SPECIFIC template was read and fires the hook, `NEVER` when templates were
 * read, nothing further can be included, and none fires it, and `UNKNOWN` for
 * everything in between. **`UNKNOWN` is offered**, with the editor warning that this
 * plugin could not confirm it — because hiding a target that might work is the same
 * silent failure in the other direction, and a merchant can test a warned target in a
 * minute while an absent one is unexplainable.
 *
 * ⚠ TWO RULES GOVERN THE EVIDENCE, AND PROMPT 13C PART N ADDED BOTH AFTER PART M GOT
 * THEM WRONG IN OPPOSITE DIRECTIONS.
 *
 * **1. A TEMPLATE THAT IS NOT SPECIFIC TO THIS EMAIL CANNOT PROVE ANYTHING ABOUT IT.**
 * With the block email editor on, every email's `template_block_content` resolves to
 * WooCommerce's SHARED `emails/block/general-block-email.php` — verified on the
 * bundled 11.0.1: one file, eighteen ids. It contains the hook, but fires it behind
 * `isset( $order ) && ! in_array( $email->id, $emails_without_order_details, true )`,
 * where that list always contains `customer_reset_password`, `customer_new_account`
 * and `customer_verify_email` and is itself filterable. A substring test over that
 * file therefore returned `RENDERS` for the very emails it excludes — Part M's Tier 1
 * defect re-opened through a different door. A shared file now yields at most
 * `UNKNOWN`.
 *
 * **2. `NEVER` REQUIRES THAT NOTHING FURTHER CAN BE INCLUDED.** A template whose own
 * text lacks the hook may still `wc_get_template()` a partial that fires it —
 * WooCommerce's own order emails are built that way around
 * `emails/email-order-details.php`. Hiding such a target is the original defect
 * inverted, and worse in one respect: `UNKNOWN` warns and `NEVER` is silent. Includes
 * are followed **one level**, and an include this cannot resolve to a literal name
 * forces `UNKNOWN`.
 */
final class NativeEmailTargets {

	/**
	 * The action insert mode binds. Named once, here, because the classification is
	 * only meaningful against the hook `RenderEvents` actually uses.
	 */
	const HOOK = 'woocommerce_email_order_details';

	/**
	 * A template was located and it fires the hook.
	 */
	const RENDERS = 'renders';

	/**
	 * Templates were located and none of them fires the hook.
	 */
	const NEVER = 'never';

	/**
	 * Nothing could be located, so the question is open.
	 */
	const UNKNOWN = 'unknown';

	/**
	 * The three outcomes, for validating a filtered value.
	 */
	const STATUSES = array( self::RENDERS, self::NEVER, self::UNKNOWN );

	/**
	 * Per-request memo of id => status.
	 *
	 * ⚠ PER REQUEST, NOT PERSISTED. The answer depends on the theme's template
	 * overrides and on which plugins are active, both of which change without this
	 * plugin being told. A stored answer would outlive the store that produced it —
	 * which is the failure this whole class exists to prevent, cached.
	 *
	 * @var array<string,string>|null
	 */
	private static $memo = null;

	/**
	 * Per-request memo of resolved template file => how many distinct email ids it
	 * serves. Built once per `classify()` because rule 1 above needs it.
	 *
	 * @var array<string,int>|null
	 */
	private static $fanout = null;

	/**
	 * Forget both memos. Tests that change the registered set — or an email's block
	 * flag — need this; nothing in production does, because neither can change inside
	 * one request.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$memo   = null;
		self::$fanout = null;
	}

	/**
	 * Every registered email id, mapped to its status.
	 *
	 * @return array<string,string> id => one of self::STATUSES.
	 */
	public static function classify(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		$registered = self::registered();

		self::$fanout = self::template_fanout( $registered );

		$out = array();

		foreach ( $registered as $email ) {
			$id = (string) $email->id;

			if ( '' === $id ) {
				continue;
			}

			$out[ $id ] = self::status_of( $email );
		}

		self::$memo = $out;

		return $out;
	}

	/**
	 * How many DISTINCT email ids each resolved template file serves.
	 *
	 * ⚠ SHARING IS OBSERVED, NOT ASSUMED FROM A PATH. The alternative was to treat
	 * anything under `emails/block/` as shared, which encodes one vendor's directory
	 * convention — the same objection that ruled out a blocklist of email ids in Part M,
	 * and it would miss a third-party plugin that points ten of its emails at one
	 * template of its own. Counting how many ids resolve to the same absolute file is
	 * the property itself, and on the bundled WooCommerce 11.0.1 it finds exactly one
	 * shared file: `emails/block/general-block-email.php`, serving all eighteen.
	 *
	 * ⚠ AN EMAIL'S OWN HTML AND PLAIN TEMPLATES DO NOT COUNT AS SHARING, because the
	 * map is keyed on distinct email IDS, not on how many times a file is named.
	 *
	 * @param object[] $registered Every registered email.
	 * @return array<string,int> Absolute file => distinct id count.
	 */
	private static function template_fanout( array $registered ): array {
		$seen = array();

		foreach ( $registered as $email ) {
			$id = (string) $email->id;

			if ( '' === $id ) {
				continue;
			}

			foreach ( self::candidates_for( $email ) as $candidate ) {
				$file = self::locate( $candidate['template'], $email );

				if ( '' !== $file ) {
					$seen[ $file ][ $id ] = true;
				}
			}
		}

		$out = array();

		foreach ( $seen as $file => $ids ) {
			$out[ $file ] = count( $ids );
		}

		return $out;
	}

	/**
	 * One id's status, or `UNKNOWN` when this store does not register it.
	 *
	 * ⚠ AN UNREGISTERED ID IS `UNKNOWN`, NOT `NEVER`, AND THE DIFFERENCE IS DELIBERATE.
	 * "This store does not send that email" is already refused by
	 * `RuleRepository::native_email_is_registered()` with its own message; answering
	 * `NEVER` here would produce a second, worse explanation for the same condition.
	 *
	 * @param string $native_email_id WooCommerce email id.
	 * @return string
	 */
	public static function status_for( string $native_email_id ): string {
		if ( '' === $native_email_id ) {
			return self::UNKNOWN;
		}

		$all = self::classify();

		return $all[ $native_email_id ] ?? self::UNKNOWN;
	}

	/**
	 * The ids an insert rule may be pointed at: everything except `NEVER`.
	 *
	 * @return string[]
	 */
	public static function selectable(): array {
		$out = array();

		foreach ( self::classify() as $id => $status ) {
			if ( self::NEVER !== $status ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * Whether this id is VERIFIABLY unable to carry an inserted rule.
	 *
	 * ⚠ CAPABILITY-DETECTED, EXACTLY AS ADR-0013 §2 REQUIRES OF THE REGISTRATION
	 * CHECK. When the mailer has not booted — a CLI importer, a migration — nothing
	 * can be classified, `classify()` returns an empty map, every id reads `UNKNOWN`
	 * and this returns FALSE. **The absence of an answer is never a rejection**, for
	 * the reason ADR-0013 §2 already gives: refusing a valid rule because the mailer
	 * had not initialised is a worse failure than accepting one that is checked again
	 * where it is actually used.
	 *
	 * @param string $native_email_id WooCommerce email id.
	 * @return bool
	 */
	public static function cannot_render( string $native_email_id ): bool {
		return self::NEVER === self::status_for( $native_email_id );
	}

	// -----------------------------------------------------------------------
	// Detection
	// -----------------------------------------------------------------------

	/**
	 * Classify one `WC_Email`.
	 *
	 * @param object $email A registered email object.
	 * @return string
	 */
	private static function status_of( $email ): string {
		$id = (string) $email->id;

		/*
		 * ⚠ THIS PLUGIN'S OWN EMAIL IS `NEVER`, BY IDENTITY, AHEAD OF ANY DETECTION AND
		 * **WITHOUT PASSING THROUGH THE FILTER** (Prompt 13C Part N). `Custom_Email` sets
		 * `template_html` and `template_plain` to '' and builds its body from the header
		 * and footer partials directly, so template detection would answer `UNKNOWN` and
		 * the editor would offer it with a warning. That is the wrong answer to a
		 * question this plugin can answer exactly: `Custom_Email.php` contains zero
		 * occurrences of self::HOOK, an insert rule pointed at it would be
		 * self-referential, and no store configuration can change either fact.
		 *
		 * ⚠ AND UNTIL PART N THE RETURN WENT THROUGH self::filtered(), WHICH CONTRADICTED
		 * THE SENTENCE ABOVE IT. A site could hand back `renders` and re-enable the one
		 * target this plugin knows cannot work. A filter may correct what DETECTION
		 * cannot see; it may not overrule a fact this plugin owns.
		 */
		if ( EmailIdentity::EMAIL_ID === $id ) {
			return self::NEVER;
		}

		$located   = 0;
		$uncertain = false;

		foreach ( self::candidates_for( $email ) as $candidate ) {
			$file = self::locate( $candidate['template'], $email );

			if ( '' === $file ) {
				// A template this email NAMES but that cannot be read is not evidence of
				// absence — something is there and we could not look at it.
				$uncertain = true;
				continue;
			}

			++$located;

			$body = self::read( $file );

			if ( null === $body ) {
				$uncertain = true;
				continue;
			}

			if ( false !== strpos( $body, self::HOOK ) ) {
				/*
				 * ⚠ RULE 1. A DIRECT HIT PROVES THE TARGET **ONLY** IF THE FILE BELONGS TO
				 * THIS EMAIL. `emails/block/general-block-email.php` serves every
				 * block-enabled email at once and fires the hook behind an id test that
				 * excludes several of them; reading the hook out of it says nothing about
				 * WHICH email reaches line 140.
				 */
				if ( ! self::is_shared( $file, $candidate['shared'] ) ) {
					return self::filtered( self::RENDERS, $id, $email );
				}

				$uncertain = true;
				continue;
			}

			/*
			 * ⚠ RULE 2. NO HOOK HERE IS NOT NO HOOK ANYWHERE. Follow what this template
			 * includes, one level. A partial that fires the hook makes the target
			 * REACHABLE but not PROVEN — the include may sit inside a condition this
			 * cannot evaluate — so it yields `UNKNOWN`, which offers the target and warns,
			 * rather than `RENDERS`, which would offer it silently.
			 */
			$includes = self::included_templates( $body );

			if ( $includes['unresolved'] ) {
				$uncertain = true;
			}

			foreach ( $includes['names'] as $name ) {
				$partial = self::locate( $name, $email );

				if ( '' === $partial ) {
					$uncertain = true;
					continue;
				}

				$inner = self::read( $partial );

				if ( null === $inner ) {
					$uncertain = true;
					continue;
				}

				if ( false !== strpos( $inner, self::HOOK ) ) {
					$uncertain = true;
				}
			}
		}

		if ( 0 === $located ) {
			return self::filtered( self::UNKNOWN, $id, $email );
		}

		return self::filtered( $uncertain ? self::UNKNOWN : self::NEVER, $id, $email );
	}

	/**
	 * Whether a resolved file is unable to speak for one email on its own.
	 *
	 * @param string $file                  Absolute path.
	 * @param bool   $shared_by_construction Whether the property it came from is a
	 *                                       general template by definition.
	 * @return bool
	 */
	private static function is_shared( string $file, bool $shared_by_construction ): bool {
		if ( $shared_by_construction ) {
			return true;
		}

		return isset( self::$fanout[ $file ] ) && self::$fanout[ $file ] > 1;
	}

	/**
	 * The templates one template body includes, one level down.
	 *
	 * ⚠ THE NAME MUST BE A LITERAL, AND A CALL WHOSE NAME IS NOT ONE SETS `unresolved`.
	 * `wc_get_template( $some_variable )` could include anything, so a template
	 * containing one cannot be called `NEVER` — that is exactly the "nothing further can
	 * be included" condition rule 2 requires. The pattern tolerates the MULTI-LINE call
	 * form, which is the only form the bundled email templates actually use: both call
	 * sites in `templates/emails/` put the name on the line after the opening bracket,
	 * and a single-line pattern finds neither.
	 *
	 * @param string $body Template source.
	 * @return array{names:string[], unresolved:bool}
	 */
	private static function included_templates( string $body ): array {
		$call    = '/\bwc_get_template(?:_html|_part)?\s*\(|\bget_template_part\s*\(/';
		$literal = '/\bwc_get_template(?:_html|_part)?\s*\(\s*([\'"])([^\'"]+)\1|\bget_template_part\s*\(\s*([\'"])([^\'"]+)\3/';

		$calls = preg_match_all( $call, $body );
		$found = preg_match_all( $literal, $body, $matches );

		$names = array();

		foreach ( array( 2, 4 ) as $group ) {
			foreach ( (array) ( $matches[ $group ] ?? array() ) as $name ) {
				if ( is_string( $name ) && '' !== $name ) {
					$names[] = $name;
				}
			}
		}

		return array(
			'names'      => array_values( array_unique( $names ) ),
			'unresolved' => is_int( $calls ) && is_int( $found ) && $calls > $found,
		);
	}

	/**
	 * Read one located template, or null.
	 *
	 * @param string $file Absolute path.
	 * @return string|null
	 */
	private static function read( string $file ): ?string {
		// ⚠ NO `@`, AND THEREFORE ONE SNIFF RATHER THAN TWO (gate 2). The read is
		// already guarded by `is_readable()` in self::locate(), so silencing it would
		// suppress nothing that can happen and would cost a second suppression on the
		// same line.
		$body = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a template file already resolved by wc_locate_template(); WP_Filesystem is not available at admin render time and this is a read of a known-readable local path.

		return is_string( $body ) ? $body : null;
	}

	/**
	 * Every template this email might render through, each tagged with whether it can
	 * speak for this email alone.
	 *
	 * ⚠ THE BLOCK TEMPLATE COUNTS, AND ONLY WHEN THE FEATURE IS ON. WooCommerce 9.9+
	 * carries `WC_Email::$template_block_content` (`emails/block/general-block-email.php`)
	 * and `get_content()` routes through it only when `block_email_editor_enabled` is
	 * true — a flag `WC_Email::__construct()` sets from
	 * `FeaturesUtil::feature_is_enabled( 'block_email_editor' )`, so it is GLOBAL rather
	 * than per-email: with the feature on, every email carries it. Including the
	 * template unconditionally would classify every email on every store as reachable;
	 * excluding it on a store that HAS the feature on would hide targets that work.
	 *
	 * ⚠ AND IT IS TAGGED `shared` BY CONSTRUCTION, NOT MERELY BY FAN-OUT COUNT. The
	 * counting rule in self::template_fanout() would already catch it on any real store,
	 * but a store registering exactly one email would give it a fan-out of one and let a
	 * substring hit in a general template prove that email. The property's own purpose —
	 * WooCommerce's docblock calls it the *general* block email, used for "content that
	 * can not be generated by personalization tags" — settles it without needing a
	 * quorum.
	 *
	 * @param object $email A registered email object.
	 * @return array<int,array{template:string, shared:bool}>
	 */
	private static function candidates_for( $email ): array {
		$out  = array();
		$seen = array();

		foreach ( array( 'template_html', 'template_plain' ) as $property ) {
			if ( isset( $email->$property ) && is_string( $email->$property ) && '' !== $email->$property
				&& ! isset( $seen[ $email->$property ] ) ) {
				$seen[ $email->$property ] = true;

				$out[] = array(
					'template' => $email->$property,
					'shared'   => false,
				);
			}
		}

		if ( ! empty( $email->block_email_editor_enabled )
			&& isset( $email->template_block_content )
			&& is_string( $email->template_block_content )
			&& '' !== $email->template_block_content ) {

			$out[] = array(
				'template' => $email->template_block_content,
				'shared'   => true,
			);
		}

		return $out;
	}

	/**
	 * Resolve one template name to a readable file, or ''.
	 *
	 * ⚠ THROUGH `wc_locate_template()`, NOT BY PATH ARITHMETIC, so the file inspected
	 * is the file WooCommerce will actually render: a theme override at
	 * `theme/woocommerce/emails/…` wins here exactly as it wins at send time. A theme
	 * that overrides an order email and REMOVES the hook is a real configuration, and
	 * this reports it correctly rather than trusting the bundled default.
	 *
	 * @param string $template Template name, e.g. `emails/customer-processing-order.php`.
	 * @param object $email    The email it belongs to.
	 * @return string Absolute path, or ''.
	 */
	private static function locate( string $template, $email ): string {
		if ( ! function_exists( 'wc_locate_template' ) ) {
			return '';
		}

		$base = isset( $email->template_base ) && is_string( $email->template_base ) ? $email->template_base : '';
		$file = wc_locate_template( $template, '', $base );

		return is_string( $file ) && '' !== $file && is_readable( $file ) ? $file : '';
	}

	/**
	 * Every registered email object, or an empty list when the mailer is unavailable.
	 *
	 * @return object[]
	 */
	private static function registered(): array {
		if ( ! function_exists( 'WC' ) || ! is_object( WC()->mailer() ) ) {
			return array();
		}

		$emails = WC()->mailer()->get_emails();

		if ( ! is_array( $emails ) ) {
			return array();
		}

		$out = array();

		foreach ( $emails as $email ) {
			if ( is_object( $email ) && isset( $email->id ) ) {
				$out[] = $email;
			}
		}

		return $out;
	}

	/**
	 * Let a site correct one classification.
	 *
	 * ⚠ THE ESCAPE HATCH THE DETECTION NEEDS, AND IT IS VALIDATED LIKE ANY OTHER
	 * ENUMERATION (ADR-0009). A third-party email that renders order details some way
	 * this cannot see — building its body in PHP, or firing the hook from inside a
	 * condition — reads `UNKNOWN`, and its author can declare it `RENDERS` here.
	 * A value outside self::STATUSES is DISCARDED rather than guessed at, because
	 * guessing means guessing whether a merchant's content is ever delivered.
	 *
	 * ⚠ ONE ID IS NOT FILTERABLE AND MUST NOT BECOME SO: this plugin's own
	 * `EmailIdentity::EMAIL_ID`. `status_of()` returns `NEVER` for it before reaching
	 * here, because `Custom_Email.php` contains zero occurrences of self::HOOK and an
	 * insert rule pointed at it would be self-referential — facts about this plugin's
	 * own code, not about a store's configuration. Until Prompt 13C Part N that return
	 * DID pass through this filter, so a site could hand back `renders` and re-enable
	 * the one target the docblock beside it called impossible. **A filter corrects what
	 * detection cannot see; it does not overrule a fact this plugin owns.**
	 *
	 * @param string $status One of self::STATUSES.
	 * @param string $id     WooCommerce email id.
	 * @param object $email  The email object.
	 * @return string
	 */
	private static function filtered( string $status, string $id, $email ): string {
		/**
		 * Filters whether one WooCommerce email can carry an inserted rule.
		 *
		 * @since 1.0.0
		 *
		 * @param string $status One of `renders`, `never` or `unknown`.
		 * @param string $id     The WooCommerce email id.
		 * @param object $email  The `WC_Email` instance.
		 */
		$value = apply_filters( 'extonify_wcep_insert_target_status', $status, $id, $email );

		return in_array( $value, self::STATUSES, true ) ? (string) $value : $status;
	}
}
