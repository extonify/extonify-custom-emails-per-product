<?php
/**
 * Merchant-facing warnings on the rule editor (ADR-0017 §5).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

use Extonify\WCEP\Delivery\Consolidation;
use Extonify\WCEP\Delivery\PlaceholderValues;
use Extonify\WCEP\Domain\PlaceholderSyntax;
use Extonify\WCEP\Domain\Targeting;
use Extonify\WCEP\Email\NativeEmailTargets;

defined( 'ABSPATH' ) || exit;

/**
 * Facts a merchant cannot discover from the form, surfaced beside the form.
 *
 * ⚠ WARNINGS, NEVER REFUSALS, AND THE DISTINCTION IS THE WHOLE DESIGN. Every
 * condition here describes a configuration that is legitimate and merely
 * SURPRISING: a singular placeholder on a multi-product rule is an authoring choice
 * (ADR-0014 §5 says so in as many words), a `per_product` rule meeting a large
 * order is exactly what the merchant asked for, and an empty subject is a valid
 * template. Refusing any of them would be this plugin overruling a decision that is
 * not its to make. Saying nothing would leave the merchant to discover it from a
 * customer.
 *
 * ⚠ COMPUTED FROM THE SUBMITTED FORM, NOT FROM THE STORED ROW, so the warnings
 * describe what is on screen — including on a re-render after a refused write,
 * where the stored row and the form deliberately disagree.
 *
 * ⚠ AND EVERY ONE OF THEM IS SCOPED BY DELIVERY MODE. The scope table is on
 * `for_form()`; the reason it has to exist at all is that an insert rule's subject,
 * heading, recipients and trigger are never read (ADR-0013 §2), so a warning about
 * one of them is not a surprising fact — it is a false one.
 */
final class Warnings {

	/**
	 * The singular product placeholders whose plural forms exist (ADR-0014 §5).
	 *
	 * ⚠ READ FROM `PlaceholderValues`, NOT LISTED HERE. The set that binds to the
	 * FIRST matched item is a property of resolution, and a second copy of it would
	 * warn about the wrong placeholders the next time one is added.
	 */
	const SINGULAR_PLACEHOLDERS = PlaceholderValues::SINGULAR_ITEM_PLACEHOLDERS;

	/**
	 * The plural forms suggested in W1's place.
	 */
	const PLURAL_PLACEHOLDERS = array( 'product_names', 'matched_product_list' );

	/**
	 * Every warning that applies to one submitted form.
	 *
	 * ⚠ EVERY WARNING IS SCOPED TO THE MODES IT CAN ACTUALLY DESCRIBE, AND THAT WAS
	 * A TIER 1 GAP. An insert rule contributes BODY CONTENT ONLY: WooCommerce owns
	 * the recipient, the subject, the heading and the send time (ADR-0013 §2), and
	 * `RuleRepository::find_active_for_native_email()` never selects on recipients or
	 * trigger. W4 was guarded from the day it was written and the other three were
	 * not, so a merchant editing an insert rule was warned about an empty SUBJECT
	 * WooCommerce never reads, about singular placeholders in a HEADING that never
	 * renders, and — on the one save the repository was about to refuse outright —
	 * about a per-product cap insert mode cannot reach. A warning that cannot apply
	 * is not merely noise: it is the interface asserting that the field it names is
	 * part of what this rule sends.
	 *
	 * | # | id | fires when | separate | insert |
	 * |---|---|---|---|---|
	 * | W1 | `singular_placeholder_multi_match` | a singular product placeholder in a field this mode RENDERS, and the targeting can match several products | subject + heading + body | **body only** |
	 * | W2 | `per_product_cap` | `consolidation = per_product` | yes | **never** — ADR-0016 §2 makes the pair unstorable, so the save is refused rather than capped |
	 * | W3 | `empty_content_field` | a field this mode RENDERS is empty | subject + heading + body | **body only** |
	 * | W4 | `insert_mode_constraints` | `delivery_mode = insert` | **never** | yes |
	 * | W5 | `insert_target_cannot_render` | the chosen WooCommerce email VERIFIABLY never renders order details | **never** | yes |
	 * | W6 | `insert_target_unverified` | this plugin could not determine whether the chosen email renders order details | **never** | yes |
	 *
	 * ⚠ W5 AND W6 ARE THE TWO HALVES OF ONE QUESTION, AND BOTH MUST EXIST (ADR-0013
	 * §2a). W5 speaks for a rule the editor would no longer let anybody create — an
	 * import, a WP-CLI write or a rule whose target stopped rendering order details
	 * when a theme override changed. W6 speaks for a target this plugin cannot
	 * classify, which is OFFERED on purpose rather than hidden: hiding a target that
	 * might work is the same silent failure in the other direction, and a merchant can
	 * test a warned target in a minute while an absent one is unexplainable.
	 *
	 * @param RuleFormInput $form The form as submitted.
	 * @return array[] Each `{id, message}`; `id` is stable and testable, `message` is
	 *                 translated and unescaped.
	 */
	public static function for_form( RuleFormInput $form ): array {
		$warnings = array();

		// The ONE branch every warning below is scoped by. Read once, from the
		// submitted form, exactly as every other condition here is.
		$is_insert = self::is_insert( $form );

		$singular = self::singular_placeholders_used( $form, $is_insert );

		if ( array() !== $singular && self::can_match_several_products( $form ) ) {
			$warnings[] = array(
				'id'      => 'singular_placeholder_multi_match',
				'message' => sprintf(
					/* translators: 1: comma-separated list of placeholders used, e.g. "{product_name}", 2: comma-separated list of suggested placeholders. */
					__( 'This rule can match more than one product, but its content uses %1$s. Those resolve against the first matched product only. To name every matched product, use %2$s.', 'extonify-custom-emails-per-product' ),
					implode( ', ', $singular ),
					implode( ', ', array_map( array( PlaceholderSyntax::class, 'label' ), self::PLURAL_PLACEHOLDERS ) )
				),
			);
		}

		/*
		 * W2 IS SEPARATE-MODE ONLY, AND NOT BECAUSE THE CAP WOULD BE WRONG — because
		 * the combination cannot exist. ADR-0016 §2 refuses `insert` + `per_product` at
		 * the write boundary and `find_active_for_native_email()` refuses it again on
		 * the read side, so the only moment this pair is ever on screen is the instant
		 * before a refusal. Describing the fallback then tells a merchant how a rule
		 * behaves that is about to be rejected.
		 */
		if ( ! $is_insert && Consolidation::PER_PRODUCT === (string) $form->get( 'consolidation' ) ) {
			$warnings[] = array(
				'id'      => 'per_product_cap',
				'message' => sprintf(
					/* translators: %d: the maximum number of emails one rule sends for one order. */
					_n(
						'One email per matched product sends at most %d email for a single order. Past that limit one email is sent instead: it carries the full content for the first matched product and only names the rest. The limit applies to this rule on its own, not to the order — several rules set this way multiply.',
						'One email per matched product sends at most %d emails for a single order. Past that limit one email is sent instead: it carries the full content for the first matched products and only names the rest. The limit applies to this rule on its own, not to the order — several rules set this way multiply.',
						Consolidation::max_messages(),
						'extonify-custom-emails-per-product'
					),
					Consolidation::max_messages()
				),
			);
		}

		$empty = self::empty_content_fields( $form, $is_insert );

		if ( array() !== $empty ) {
			$warnings[] = array(
				'id'      => 'empty_content_field',
				'message' => sprintf(
					/* translators: %s: comma-separated list of field labels left empty. */
					__( 'Left empty, %s renders as nothing at all — which looks exactly like a placeholder that resolved to nothing. Fill it in if something should appear there.', 'extonify-custom-emails-per-product' ),
					implode( ', ', $empty )
				),
			);
		}

		if ( $is_insert ) {
			$target = (string) $form->get( 'native_email_id' );
			$status = '' === $target ? '' : NativeEmailTargets::status_for( $target );

			if ( NativeEmailTargets::NEVER === $status ) {
				$warnings[] = array(
					'id'      => 'insert_target_cannot_render',
					'message' => sprintf(
						/* translators: %s: the name of the chosen WooCommerce email. */
						__( 'This rule adds its content to %s, but that email has no order details section — which is the only place this plugin can add anything. Nothing will be added and nothing will be sent, and no delivery will be recorded to tell you so. Choose a WooCommerce email that shows the order, or send this rule as a separate email.', 'extonify-custom-emails-per-product' ),
						self::target_name( $target )
					),
				);
			}

			if ( NativeEmailTargets::UNKNOWN === $status ) {
				$warnings[] = array(
					'id'      => 'insert_target_unverified',
					'message' => sprintf(
						/* translators: %s: the name of the chosen WooCommerce email. */
						__( 'This plugin could not confirm that %s shows the order details, which is the only place it can add content. The rule is saved and offered anyway — send yourself a test order to check it arrives. If nothing appears, that email does not show order details and this rule cannot use it.', 'extonify-custom-emails-per-product' ),
						self::target_name( $target )
					),
				);
			}

			$warnings[] = array(
				'id'      => 'insert_mode_constraints',
				'message' => __( 'An inserted rule adds its content to an email WooCommerce is already sending. WooCommerce decides who receives that email, what its subject says and when it goes out — so the recipients, the delay and the choice of how many emails to send are switched off below.', 'extonify-custom-emails-per-product' ),
			);
		}

		return $warnings;
	}

	/**
	 * Whether this form's targeting can match more than one product (ADR-0017 §5).
	 *
	 * ⚠ A PROPERTY OF THE DOCUMENT, NOT OF AN ORDER. Nothing here loads a product or
	 * asks how many are in a category: `match_all` targets the catalogue, a category,
	 * a tag or a type targets an open-ended set by definition, and naming two products
	 * or two variations is two products. Only a rule naming exactly one identity is
	 * unambiguous — and that is the one rule for which `{product_name}` needs no
	 * warning.
	 *
	 * @param RuleFormInput $form The form as submitted.
	 * @return bool
	 */
	public static function can_match_several_products( RuleFormInput $form ): bool {
		if ( $form->get( 'match_all' ) ) {
			return true;
		}

		$identities = 0;

		foreach ( Targeting::KINDS as $kind ) {
			$entries = $form->targets( 'include', $kind );

			if ( array() === $entries ) {
				continue;
			}

			// A category, a tag or a type names a SET whose size the rule does not
			// control — one today, forty next month.
			if ( ! in_array( $kind, array( 'products', 'variations' ), true ) ) {
				return true;
			}

			$identities += count( $entries );
		}

		return $identities > 1;
	}

	/**
	 * The human name of one WooCommerce email, falling back to its id.
	 *
	 * ⚠ READ FROM THE UNFILTERED DISPLAY MAP, NOT FROM THE OFFERED SET. W5 exists
	 * precisely for a target the offered set no longer contains, so looking the name up
	 * there would print a raw id in the one warning whose whole job is to tell a
	 * merchant which email is the problem.
	 *
	 * @param string $native_email_id WooCommerce email id.
	 * @return string
	 */
	private static function target_name( string $native_email_id ): string {
		$titles = FieldOptions::native_email_titles();

		return (string) ( $titles[ $native_email_id ] ?? $native_email_id );
	}

	/**
	 * Whether the submitted form is an insert rule.
	 *
	 * ⚠ THE RAW SUBMITTED VALUE, LIKE EVERY OTHER CONDITION HERE. Anything that is
	 * not exactly `insert` is judged as a separate rule, which is the safe direction:
	 * a malformed mode is refused by the repository moments later, and until then the
	 * merchant sees the fuller set of warnings rather than the narrower one.
	 *
	 * @param RuleFormInput $form The form as submitted.
	 * @return bool
	 */
	private static function is_insert( RuleFormInput $form ): bool {
		return 'insert' === (string) $form->get( 'delivery_mode' );
	}

	/**
	 * The three content fields, scoped to the ones this mode actually renders.
	 *
	 * ⚠ THE ONE PLACE W1 AND W3 AGREE ON WHAT "THE CONTENT" IS. Both used to name
	 * subject, heading and body unconditionally; in insert mode WooCommerce supplies
	 * its own subject and heading and only the BODY is this rule's, so both warnings
	 * read the scope from here rather than each keeping its own list to drift.
	 *
	 * @param bool $is_insert Whether the current mode is insert.
	 * @return array<string,string> Field key => label, in rendering order.
	 */
	private static function rendered_content_fields( bool $is_insert ): array {
		$body = array( 'content' => __( 'the body', 'extonify-custom-emails-per-product' ) );

		if ( $is_insert ) {
			return $body;
		}

		return array_merge(
			array(
				'subject' => __( 'the subject', 'extonify-custom-emails-per-product' ),
				'heading' => __( 'the heading', 'extonify-custom-emails-per-product' ),
			),
			$body
		);
	}

	/**
	 * The singular product placeholders this form's content actually uses.
	 *
	 * ⚠ SCANS ONLY THE FIELDS THIS MODE RENDERS. `{product_name}` in the subject of an
	 * INSERT rule resolves against nothing a customer ever reads — WooCommerce's own
	 * subject is used — so warning that it binds to the first matched product would
	 * be advice about a field that is not in play.
	 *
	 * @param RuleFormInput $form      The form as submitted.
	 * @param bool          $is_insert Whether the current mode is insert.
	 * @return string[] Labels, e.g. `{product_name}`.
	 */
	private static function singular_placeholders_used( RuleFormInput $form, bool $is_insert ): array {
		$parts = array();

		foreach ( array_keys( self::rendered_content_fields( $is_insert ) ) as $key ) {
			$parts[] = (string) $form->get( $key );
		}

		$template = implode( "\n", $parts );

		$used = array();

		foreach ( PlaceholderSyntax::tokens( $template ) as $token ) {
			if ( null !== $token['param'] || ! in_array( $token['name'], self::SINGULAR_PLACEHOLDERS, true ) ) {
				continue;
			}

			$used[] = $token['label'];
		}

		return $used;
	}

	/**
	 * Which of this mode's content fields were left empty, by label.
	 *
	 * ⚠ AN INSERT RULE'S EMPTY SUBJECT IS NOT AN EMPTY FIELD, it is a field that does
	 * not apply: the customer reads WooCommerce's subject. Warning about it told the
	 * merchant to fill in something no one would ever see, and implied the rule
	 * carried a subject line of its own.
	 *
	 * @param RuleFormInput $form      The form as submitted.
	 * @param bool          $is_insert Whether the current mode is insert.
	 * @return string[]
	 */
	private static function empty_content_fields( RuleFormInput $form, bool $is_insert ): array {
		$fields = self::rendered_content_fields( $is_insert );

		$empty = array();

		foreach ( $fields as $key => $label ) {
			if ( '' === trim( (string) $form->get( $key ) ) ) {
				$empty[] = $label;
			}
		}

		return $empty;
	}
}
