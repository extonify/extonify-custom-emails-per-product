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
	 * The shared product cache, so a caller can prove it is the same one.
	 *
	 * @return ItemResolver
	 */
	public function item_resolver(): ItemResolver {
		return $this->items;
	}
}
