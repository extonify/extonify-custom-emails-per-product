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
	 * @param RuleFormInput $form The form as submitted.
	 * @return array[] Each `{id, message}`; `id` is stable and testable, `message` is
	 *                 translated and unescaped.
	 */
	public static function for_form( RuleFormInput $form ): array {
		$warnings = array();

		$singular = self::singular_placeholders_used( $form );

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

		if ( Consolidation::PER_PRODUCT === (string) $form->get( 'consolidation' ) ) {
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

		$empty = self::empty_content_fields( $form );

		if ( array() !== $empty ) {
			$warnings[] = array(
				'id'      => 'empty_content_field',
				'message' => sprintf(
					/* translators: %s: comma-separated list of field labels left empty. */
					__( 'Left empty, %s renders as nothing at all — which looks exactly like a placeholder that resolved to nothing. Fill it in if the customer should see something there.', 'extonify-custom-emails-per-product' ),
					implode( ', ', $empty )
				),
			);
		}

		if ( 'insert' === (string) $form->get( 'delivery_mode' ) ) {
			$warnings[] = array(
				'id'      => 'insert_mode_constraints',
				'message' => __( 'An inserted rule adds content to an email WooCommerce is already sending, so it cannot be delayed and cannot choose how many emails to send. Those two settings are switched off below.', 'extonify-custom-emails-per-product' ),
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
	 * The singular product placeholders this form's content actually uses.
	 *
	 * @param RuleFormInput $form The form as submitted.
	 * @return string[] Labels, e.g. `{product_name}`.
	 */
	private static function singular_placeholders_used( RuleFormInput $form ): array {
		$template = (string) $form->get( 'subject' ) . "\n"
			. (string) $form->get( 'heading' ) . "\n"
			. (string) $form->get( 'content' );

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
	 * Which of the three content fields were left empty, by label.
	 *
	 * @param RuleFormInput $form The form as submitted.
	 * @return string[]
	 */
	private static function empty_content_fields( RuleFormInput $form ): array {
		$fields = array(
			'subject' => __( 'the subject', 'extonify-custom-emails-per-product' ),
			'heading' => __( 'the heading', 'extonify-custom-emails-per-product' ),
			'content' => __( 'the body', 'extonify-custom-emails-per-product' ),
		);

		$empty = array();

		foreach ( $fields as $key => $label ) {
			if ( '' === trim( (string) $form->get( $key ) ) ) {
				$empty[] = $label;
			}
		}

		return $empty;
	}
}
