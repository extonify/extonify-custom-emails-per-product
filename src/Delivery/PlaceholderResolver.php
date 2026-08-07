<?php
/**
 * The WooCommerce-facing entry point to placeholder resolution (ADR-0014 §8, §9).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

use Extonify\WCEP\Domain\PlaceholderSyntax;
use Extonify\WCEP\Domain\Text;
use Extonify\WCEP\Matching\ItemResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Hands out one `PlaceholderValues` per delivery, over the request's shared
 * product cache.
 *
 * ⚠ THIS OBJECT HOLDS NO PER-DELIVERY STATE, AND THAT IS ADR-0012 §11. It is a
 * per-REQUEST collaborator — one in the orchestrator, one in the insert phase —
 * and both are shared across every delivery in the request. A memo or a note list
 * living here would be state an inner delivery overwrites before the outer one
 * has finished reading it, which is the defect shape Prompts 4b, 4c and 4d spent
 * three passes removing from the email object. So the memo and the notes live on
 * the per-delivery object instead, where they are created and discarded with the
 * delivery that owns them.
 *
 * WHY IT EXISTS AT ALL, GIVEN THAT. It owns the ONE thing that must be shared:
 * the `ItemResolver` product cache the matcher has already filled for this
 * request. That is what keeps the ADR-0014 §8 cost contract — **no
 * per-placeholder and no per-occurrence query growth; one bounded, named cost per
 * distinct DATA CLASS a body reads** — rather than "resolution is free", which the
 * gate disproved twice. `PlaceholderValues` enumerates every class and its
 * measured cost. The sharing is explicit rather than a static reaching sideways.
 */
final class PlaceholderResolver {

	/**
	 * The shared product cache (ADR-0012 §11b).
	 *
	 * @var ItemResolver
	 */
	private $items;

	/**
	 * Constructor.
	 *
	 * @param ItemResolver|null $items Shared product cache; built on demand,
	 *                                 which is the standalone case only.
	 */
	public function __construct( ?ItemResolver $items = null ) {
		$this->items = null !== $items ? $items : new ItemResolver();
	}

	/**
	 * Open a value set for ONE delivery.
	 *
	 * ⚠ ONE SET PER LINE ITEM AT THE PER-ITEM POSITION (ADR-0014 §5a), never one
	 * set reused across items. The set memoises by `name:parameter`, so a shared
	 * set would answer the second item with the first item's memoised value —
	 * which is the defect §5a exists to fix, reintroduced through the cache.
	 *
	 * @param \WC_Order $order           The live order — never a snapshot, never a
	 *                                   stale copy (ADR-0014 §8).
	 * @param array[]   $matched_items   Matched item records, in line-item order.
	 * @param int       $current_item_id Line item being rendered at the per-item
	 *                                   position; 0 everywhere else.
	 * @return PlaceholderValues
	 */
	public function for_delivery( \WC_Order $order, array $matched_items = array(), int $current_item_id = 0 ): PlaceholderValues {
		return new PlaceholderValues( $order, $matched_items, $this->items, $current_item_id );
	}

	/**
	 * Render one template in one context, for callers with nothing to memoise.
	 *
	 * @param \WC_Order $order         Live order.
	 * @param array[]   $matched_items Matched item records.
	 * @param string    $template      Template.
	 * @param string    $context       One of the `PlaceholderSyntax::CONTEXT_*`.
	 * @return string
	 */
	public function render( \WC_Order $order, array $matched_items, string $template, string $context ): string {
		return $this->for_delivery( $order, $matched_items )->render( $template, $context );
	}

	/**
	 * Render one rule body BOTH ways, from one value set (ADR-0014 §9).
	 *
	 * ⚠ THE PLAIN BODY FLATTENS THE TEMPLATE FIRST, THEN SUBSTITUTES, AND THE
	 * OTHER ORDER IS WRONG. Substituting into the HTML template and flattening
	 * afterwards would run `wp_strip_all_tags()` over the VALUES, so a customer
	 * named `<script>x</script>` would lose their name entirely and any `&` in a
	 * value would be mangled by the entity handling. Flatten, then substitute, and
	 * the value reaches the text body as text.
	 *
	 * BOTH FORMATS, ALWAYS, and that is required rather than wasteful: WooCommerce's
	 * `multipart` email type builds the plain alternative at `phpmailer_init`, long
	 * after the orchestrator has returned. The second format adds NO query cost,
	 * because both formats draw on the same memoised values — the ADR-0014 §8
	 * no-per-occurrence half of the contract, applied across formats.
	 *
	 * @param PlaceholderValues $values   The delivery's value set.
	 * @param string            $template Stored rule body.
	 * @return array{html:string,plain:string}
	 */
	public static function render_body( PlaceholderValues $values, string $template ): array {
		return array(
			'html'  => $values->render( $template, PlaceholderSyntax::CONTEXT_HTML ),
			'plain' => $values->render( Text::to_plain_text( $template ), PlaceholderSyntax::CONTEXT_PLAIN ),
		);
	}

	/**
	 * Render ONE body that covers every unit — the cap fallback (ADR-0016 §7).
	 *
	 * ⚠ THIS EXISTS BECAUSE "one combined message" DID NOT CONTAIN THE PRODUCTS. A
	 * capped message binds `item_id = 0`, which is ADR-0014 §5's first-matched-item
	 * binding, so `Care guide for {product_name}` rendered ONCE named PRODUCT ONE and
	 * products 2–60 appeared nowhere the customer could see. The body is therefore
	 * rendered once per unit — up to the bound below — and concatenated.
	 *
	 * ⚠ EACH SECTION IS LABELLED, AND THE LABEL IS LOAD-BEARING. Repetition alone still
	 * names nothing for an EMPTY body or for a body with no placeholder in it
	 * (`Hand wash only.`), both of which are ordinary templates for a single-product
	 * rule. The label is that unit's own `PlaceholderValues::unit_label()`, read through
	 * its OWN value set — the line item's stored name, plus the attributes that name
	 * does not already carry when the unit is a live variation — and it is escaped for
	 * its destination by the same `PlaceholderSyntax::escape()` every placeholder value
	 * goes through.
	 *
	 * ⚠ AND THE LABEL MUST IDENTIFY, NOT MERELY NAME (ADR-0016 §7a). `{product_name}`
	 * alone was not enough: WooCommerce omits a variation's attributes from its
	 * generated title whenever the variation has three or more attributes, or two or
	 * more with a hyphenated attribute key — so two sibling variations that ADR-0016 §4
	 * defines as distinct units both rendered as the bare parent name, and for a unit
	 * past the section bound that identical line is the WHOLE of what the customer
	 * receives about it.
	 *
	 * ⚠ ONLY THE FIRST `content` SECTIONS CARRY THE BODY, AND THAT IS THE FIX FOR A
	 * QUADRATIC (ADR-0016 §7a). Prompt 8A rendered the body in EVERY section, and the
	 * body may contain a full-set plural — `{product_names}`,
	 * `{matched_product_list}` — which resolves to ALL N matched products. N sections ×
	 * N products is N² list entries in ONE message: for a sixty-line wholesale order,
	 * 3,600 entries and roughly 100KB before the merchant's own content, past the point
	 * at which mail clients clip and the customer silently loses the tail. The planner
	 * decides how many sections carry the body (`Consolidation::rendered_sections()`);
	 * every remaining unit is still LABELLED, so no matched product is absent from the
	 * body whatever the template contains, and the output is **O(C·N + N)** — linear in
	 * the unit count FOR A FIXED CONFIGURED CAP. The qualification is load-bearing:
	 * `Consolidation::max_messages()` passes the order to its filter, so a site that
	 * deliberately returns a cap derived from the order's size restores the quadratic
	 * term. That is a considered act on a safety limit, not a defect, but "linear in N"
	 * is only true of a cap that does not itself depend on N.
	 *
	 * ⚠ ONE VALUE SET PER SECTION, for ADR-0014 §5a's reason: the set memoises by
	 * `name:parameter`, so a shared one would answer section 2 with section 1's
	 * memoised `{product_name}` and every section would name the same product — the
	 * exact defect this method exists to fix, reintroduced through the cache.
	 *
	 * ⚠ AND NO SECTION'S SET IS RETAINED PAST ITS SECTION. Each one memoises whatever
	 * the body read, including a full-set plural string that is itself O(units), so
	 * keeping N of them would be quadratic RETAINED MEMORY beside the quadratic output.
	 * The notes are folded out as each section completes and the set is released, so at
	 * most ONE section's set is live at any moment however many units there are.
	 *
	 * ⚠ THE PLAIN TEMPLATE IS FLATTENED ONCE, OUTSIDE THE LOOP, and that ordering is
	 * ADR-0014 §9's: flatten the TEMPLATE then substitute, never the reverse, or
	 * `wp_strip_all_tags()` runs over the VALUES and a customer named
	 * `<script>x</script>` loses their name entirely.
	 *
	 * @param \WC_Order     $order         Live order.
	 * @param array[]       $matched_items Every matched item record — the FULL set, so
	 *                                     the plural placeholders keep meaning the whole
	 *                                     set in every section that renders the body
	 *                                     (ADR-0016 §6).
	 * @param array[]       $sections      Section descriptors from the plan:
	 *                                     `{unit, item_id, content}`.
	 * @param string        $template      Stored rule body.
	 * @param callable|null $observe       Called as `( PlaceholderValues $live,
	 *                                     string[] $notes_so_far )` BEFORE each section
	 *                                     renders, so a containment boundary sees what
	 *                                     resolution had reached when a section throws.
	 * @return array{html:string,plain:string,notes:string[]}
	 */
	public function render_sectioned_body( \WC_Order $order, array $matched_items, array $sections, string $template, ?callable $observe = null ): array {
		$plain_template = Text::to_plain_text( $template );

		$html  = array();
		$plain = array();
		$notes = array();

		foreach ( $sections as $section ) {
			$set = $this->for_delivery( $order, $matched_items, (int) ( $section['item_id'] ?? 0 ) );

			/*
			 * HANDED OVER BEFORE THE SECTION RENDERS, NOT AFTER (ADR-0014 §1c). The set
			 * is the live one, so a throw part way through this section reports the notes
			 * it had reached, and `$notes` already carries every completed section's.
			 * Reporting only on completion is what made a throw in section 5 discard
			 * sections 1–4's diagnostics.
			 */
			if ( null !== $observe ) {
				$observe( $set, PlaceholderValues::folded_notes( $notes ) );
			}

			/*
			 * THE LABEL IS A VALUE, so it is resolved and escaped exactly like one —
			 * but it is the UNIT's label, not `{product_name}` (ADR-0016 §7a). For a
			 * unit past the bound the label is the whole of what the customer receives
			 * about it, and an order item's name does not distinguish sibling
			 * variations: WooCommerce omits the attributes from a variation's title
			 * whenever it has three or more of them, or two or more with a hyphenated
			 * attribute key. `unit_label()` appends exactly what the name does not
			 * already say, and nothing at all for a simple product.
			 */
			$label = $set->unit_label();

			// ⚠ ABSENT MEANS TRUE, so a caller that predates the bound — or a hand-built
			// section in a test — renders the body rather than silently losing it.
			$carries_body = false !== ( $section['content'] ?? true );

			$html[]  = self::html_section( $label, $carries_body ? $set->render( $template, PlaceholderSyntax::CONTEXT_HTML ) : '' );
			$plain[] = self::plain_section( $label, $carries_body ? $set->render( $plain_template, PlaceholderSyntax::CONTEXT_PLAIN ) : '' );

			// FOLDED OUT AND THE SET RELEASED. A token that is unknown is unknown in
			// every section, so the fold de-duplicates; the cap is the same one a
			// single delivery is held to.
			$notes = PlaceholderValues::fold_notes( $notes, $set->notes() );

			unset( $set );
		}

		return array(
			// A BLANK LINE BETWEEN SECTIONS IN BOTH FORMATS. In HTML it separates two
			// merchant-authored blocks that WooCommerce's template will lay out; in
			// plain text it is the only separation available at all.
			'html'  => implode( "\n", $html ),
			'plain' => trim( implode( "\n\n", $plain ) ),
			// The merged notes rather than the sets that produced them — see above.
			'notes' => PlaceholderValues::folded_notes( $notes ),
		);
	}

	/**
	 * One HTML section: the escaped label, then the merchant's rendered block.
	 *
	 * The label is emitted as a `<strong>` paragraph — the least markup that reads as a
	 * heading inside WooCommerce's own email template without imposing a size or a
	 * colour on the merchant's design. An EMPTY label emits nothing at all rather than
	 * an empty paragraph.
	 *
	 * @param string $label Unescaped product name.
	 * @param string $block Already-rendered, already-escaped body for this unit.
	 * @return string
	 */
	private static function html_section( string $label, string $block ): string {
		$escaped = PlaceholderSyntax::escape( $label, PlaceholderSyntax::CONTEXT_HTML );

		if ( '' === $escaped ) {
			return $block;
		}

		return '<p><strong>' . $escaped . '</strong></p>' . ( '' === $block ? '' : "\n" . $block );
	}

	/**
	 * One plain-text section: the label on its own line, then the merchant's block.
	 *
	 * @param string $label Unescaped product name.
	 * @param string $block Already-rendered body for this unit.
	 * @return string
	 */
	private static function plain_section( string $label, string $block ): string {
		$escaped = PlaceholderSyntax::escape( $label, PlaceholderSyntax::CONTEXT_PLAIN );

		if ( '' === $escaped ) {
			return $block;
		}

		return $escaped . ( '' === trim( $block ) ? '' : "\n" . $block );
	}

	/**
	 * The shared product cache, so a caller can prove it is the same one.
	 *
	 * @return ItemResolver
	 */
	public function item_resolver(): ItemResolver {
		return $this->items;
	}
}
