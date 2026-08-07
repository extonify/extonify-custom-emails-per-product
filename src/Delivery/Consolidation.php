<?php
/**
 * Consolidation: how many messages one delivery decision produces (ADR-0016).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

use Extonify\WCEP\Domain\Targeting;
use Extonify\WCEP\Domain\Text;

defined( 'ABSPATH' ) || exit;

/**
 * The fan-out planner: one decision in, one or more MESSAGES out.
 *
 * ⚠ THIS CLASS DECIDES NOTHING ABOUT IDENTITY, AND THAT IS ADR-0016 §3. The
 * tombstone is the unit of DECISION — this rule fired for this order on this
 * trigger, once, ever — and consolidation expands that one decision into N
 * messages beneath it. `order_id | rule_id | mode | trigger_identity` is untouched,
 * so a re-fired trigger is suppressed by the same atomic claim whether the rule
 * sends one message or five. A planner that could influence the key would let a
 * rule edited from `none` to `per_product` re-send to every order it had already
 * served, which is exactly why ADR-0004 keeps `revision` out of the key.
 *
 * `plan()` IS PURE — array in, array out, no WordPress. The cap is read separately
 * in self::max_messages(), which is the one method that touches a filter, so the
 * planning logic is unit-testable against the real code rather than a shim. Same
 * split as `Domain\PlaceholderSyntax` against `Delivery\PlaceholderResolver`.
 */
final class Consolidation {

	/**
	 * One message per rule per trigger, every matched item combined.
	 *
	 * The default, and the behaviour that shipped before this ADR. The locked v1.0
	 * requirement — smart mixed-cart merging — is what this value already provides.
	 */
	const NONE = 'none';

	/**
	 * One message per distinct matched product identity (ADR-0016 §4).
	 */
	const PER_PRODUCT = 'per_product';

	/**
	 * THE CLOSED VOCABULARY (ADR-0016 §1).
	 *
	 * ⚠ EXHAUSTIVE, NOT ILLUSTRATIVE. `daily`, `weekly` and `per_order` are INVALID
	 * VALUES, not merely unimplemented ones: `RuleRepository` validates the RAW
	 * value against this list and refuses the whole write, so they cannot be stored
	 * at all. Under the previous shape validation they COULD be stored and the phase
	 * filter was the only thing keeping them from being delivered as `none` under
	 * another name — one missing filter turned a merchant's digest request into an
	 * email per order.
	 *
	 * ⚠ `per_order` — cross-rule merging — IS NOT A MEMBER AND MUST NOT BE ADDED
	 * HERE. It merges across DIFFERENT delivery identities with different subjects,
	 * recipients, priorities and revisions, so it has to answer whose subject the
	 * merged message carries, which tombstone owns it, and what a per-identity
	 * `final_status` means for a message with no single identity. None of those has
	 * an answer in ADR-0016. Adding it needs a SUPERSEDING ADR, not an extra
	 * element.
	 */
	const MODES = array( self::NONE, self::PER_PRODUCT );

	/**
	 * The default fan-out cap: ten messages from one delivery (ADR-0016 §7).
	 *
	 * ⚠ A CUSTOMER-FACING SAFETY LIMIT, NOT A PERFORMANCE ONE. A category-targeted
	 * `per_product` rule meeting a wholesale order with sixty matching line items
	 * would send that customer SIXTY EMAILS. Beyond ten the experience is
	 * unambiguously wrong however it was configured: no inbox reads sixty
	 * near-identical messages as helpful, most providers treat the burst as abusive,
	 * and the sender-reputation cost lands on the merchant rather than on whoever
	 * wrote the rule. Ten is generous for the intended use — a handful of matched
	 * products in a mixed cart — and small enough that the failure it prevents
	 * cannot happen quietly.
	 */
	const MAX_MESSAGES = 10;

	/**
	 * The filter a site owner uses to raise the cap deliberately.
	 */
	const MAX_MESSAGES_FILTER = 'extonify_wcep_consolidation_max_messages';

	/**
	 * Fallback reason code: more matched units than the cap allows.
	 */
	const FALLBACK_CAP_EXCEEDED = 'cap_exceeded';

	/**
	 * Whether a RAW value is a member of the vocabulary (ADR-0016 §1).
	 *
	 * Tested on the raw value, exactly as `status` and `delivery_mode` are
	 * (ADR-0009): an enumeration has no correct repair, because repairing one
	 * produces a DIFFERENT VALID VALUE that behaves in a way nobody chose.
	 *
	 * @param string $value Raw value, exactly as supplied.
	 * @return bool
	 */
	public static function is_valid( string $value ): bool {
		return in_array( $value, self::MODES, true );
	}

	/**
	 * Whether a rule ROW may be delivered at all — THE READ BOUNDARY (ADR-0016 §1a).
	 *
	 * ⚠ THIS IS NOT `Orchestrator::behaviour_is_implemented()`, AND MERGING THE TWO
	 * WOULD EVENTUALLY SWITCH THIS OFF. They answer different questions with different
	 * lifetimes:
	 *
	 *   - that one asks *has anybody built this yet* — a value the column legitimately
	 *     holds, filtered by an enumeration that SHRINKS as features land and is now
	 *     EMPTY (§9);
	 *   - this one asks *is this data corrupt* — a value the column must never hold,
	 *     and a vocabulary always has an outside, so this check is PERMANENT.
	 *
	 * Folding this into the enumeration would have looked like tidying and would have
	 * meant that emptying the enumeration silently disabled the corruption check. That
	 * is precisely how a rule holding `daily` came to be delivered.
	 *
	 * ⚠ AND "corrupt" DOES NOT MEAN "direct SQL". `daily`, `weekly` and `per_order`
	 * were LEGITIMATELY STORABLE from Prompt 5B to Prompt 8, so any rule created — or
	 * created by a test — while that vocabulary was open still holds one in an ordinary
	 * database. This is the upgrade path, not an exotic case.
	 *
	 * @param array $rule Rule row.
	 * @return bool
	 */
	public static function has_valid_value( array $rule ): bool {
		return self::is_valid( self::requested( $rule ) );
	}

	/**
	 * The consolidation a rule row asks for, as stored.
	 *
	 * RETURNS THE RAW VALUE, INCLUDING AN UNRECOGNISED ONE. Coercing here would
	 * hide the §1a case from the planner, which is the one place that can record
	 * it. An absent column is the documented default.
	 *
	 * @param array $rule Rule row.
	 * @return string
	 */
	public static function requested( array $rule ): string {
		$value = $rule['consolidation'] ?? self::NONE;

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * The fan-out cap for one delivery (ADR-0016 §7).
	 *
	 * ⚠ ONLY AN INTEGER DECIDES, AND A CAST WOULD BE A FAIL-OPEN (ADR-0014 §6.5's
	 * general rule, third application). The consent this filter grants is permission
	 * to send a customer MORE EMAIL than the plugin's own default allows, so reading
	 * a non-integer — above all a `WP_Error`, which is what a callback returns when
	 * it FAILED to decide — as a raised cap would be the same fail-open with a larger
	 * blast radius than a meta key. An extension point that cannot decide is not
	 * consent; the default stands.
	 *
	 * FLOORED AT 1. A filter returning `0` or a negative number would otherwise mean
	 * "send nothing", and a delivery that sends nothing because of an arithmetic
	 * accident is a lost email.
	 *
	 * @param array          $rule  Rule row being delivered.
	 * @param \WC_Order|null $order The order, for a filter that wants to decide per
	 *                              order.
	 * @return int At least 1.
	 */
	public static function max_messages( array $rule = array(), ?\WC_Order $order = null ): int {
		/**
		 * Raise the number of messages one `per_product` delivery may send.
		 *
		 * Beyond the cap the delivery falls back to ONE message (ADR-0016 §7) rather
		 * than truncating or sending the lot. That message carries the merchant's own
		 * content rendered once per matched product **for the first `$cap` products**,
		 * and every remaining product is named by a labelled section carrying no body
		 * (§7a). So this value sets two things at once, deliberately: how many messages
		 * a fan-out may send, and how many products' content one fallback message may
		 * repeat.
		 *
		 * ⚠ AND RAISING IT RAISES THE FALLBACK MESSAGE'S SIZE. The body is
		 * `O(cap × (template + plurals × products))`, so a cap derived from the order's
		 * own size — which this filter is given the order to make possible — brings back
		 * the quadratic growth §7a removed. It is a safety limit; move it knowing that.
		 *
		 * @since 1.0.0
		 *
		 * ⚠ RETURN AN INTEGER. Anything else — including a `WP_Error` — leaves the
		 * default in place; it is never read as permission to send more email.
		 *
		 * @param int            $cap   The default cap, self::MAX_MESSAGES.
		 * @param array          $rule  The rule row being delivered.
		 * @param \WC_Order|null $order The order being delivered for.
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant IS the prefixed name (`extonify_wcep_consolidation_max_messages`); it is referenced rather than repeated so the hook a site owner writes and the hook this plugin fires cannot drift apart.
		$cap = apply_filters( self::MAX_MESSAGES_FILTER, self::MAX_MESSAGES, $rule, $order );

		if ( ! is_int( $cap ) ) {
			return self::MAX_MESSAGES;
		}

		return max( 1, $cap );
	}

	/**
	 * Plan one delivery's messages. PURE.
	 *
	 * ALWAYS RETURNS AT LEAST ONE MESSAGE. There is no input for which a matched
	 * decision plans zero messages: a plan of nothing would be a silently lost
	 * email, which is the outcome every branch below exists to avoid.
	 *
	 * @param array   $rule          Rule row.
	 * @param array[] $matched_items Matched item records, in line-item order.
	 * @param int     $cap           Fan-out cap from self::max_messages().
	 * @return array {
	 *     @type string   $requested Raw stored consolidation.
	 *     @type string   $mode      Effective mode: self::NONE or self::PER_PRODUCT.
	 *     @type int      $units     Distinct matched-unit count; 0 when not computed.
	 *     @type int      $cap       Cap in force.
	 *     @type bool     $capped    Whether the cap forced the fallback.
	 *     @type string[] $notes     Diagnostics for the delivery's `reason` column.
	 *     @type array[]  $messages  One or more message descriptors.
	 * }
	 */
	public static function plan( array $rule, array $matched_items, int $cap ): array {
		$requested = self::requested( $rule );
		$cap       = max( 1, $cap );
		$notes     = array();

		if ( ! self::is_valid( $requested ) ) {
			/*
			 * ⚠ ONE MESSAGE, AND SAY SO (ADR-0016 §1a). The enumeration is enforced at
			 * the WRITE boundary, so a value reaching here came from direct SQL, a
			 * hand-edited row or a restore from a foreign schema — and this is defence
			 * in depth, NOT a repair: nothing is written back.
			 *
			 * Fanning out on an unknown value would guess at a count. Leaving the rule
			 * inert would send NOTHING, and a rule that is configured, active and
			 * matching but invisible is the failure ADR-0009 cites when explaining why
			 * `insert_position` repairs. One message, explained in the log, is the only
			 * total answer that neither guesses a count nor returns an empty plan a
			 * caller might read as "nothing to send".
			 */
			$notes[] = Text::note_value(
				'unrecognised consolidation "' . $requested . '" stored on this rule; sent as one combined message'
			);

			return self::combined( $requested, $matched_items, 0, $cap, false, $notes );
		}

		if ( self::NONE === $requested ) {
			return self::combined( $requested, $matched_items, 0, $cap, false, $notes );
		}

		$units = self::units( $matched_items );
		$count = count( $units );

		if ( 0 === $count ) {
			// A MATCHED decision with no matched items. Separate mode does not produce
			// one — only insert mode's non-targeted case does, and ADR-0016 §2 refuses
			// consolidation there — so this is unreachable rather than expected. It
			// still gets a message: nothing about "no items" makes sending nothing the
			// right answer for a rule that matched.
			return self::combined( $requested, $matched_items, 0, $cap, false, $notes );
		}

		if ( $count > $cap ) {
			/*
			 * ⚠ FALL BACK TO ONE MESSAGE CARRYING EVERY UNIT'S CONTENT, RECORDED WITH
			 * THE COUNT (ADR-0016 §7). Truncating to the first `$cap` products would
			 * silently lose the rest — the merchant asked for every matched product to
			 * be mentioned, and a rule mentioning ten of sixty is wrong in a way nothing
			 * in the message reveals. Sending all sixty is the failure the cap exists to
			 * prevent.
			 *
			 * ⚠ AND "one combined message" WAS NOT ENOUGH ON ITS OWN — that was the
			 * Prompt 8 defect. A combined message bound to the first matched item renders
			 * `Care guide for {product_name}` naming PRODUCT ONE, and products 2–60
			 * appear nowhere the customer can see. So the message carries SECTIONS: the
			 * merchant's own body rendered once per unit, each labelled with that unit's
			 * product name. See self::combined() for why the label is a correctness
			 * requirement and not decoration.
			 *
			 * ⚠ AND THE SECTIONS THAT CARRY THE BODY ARE THEMSELVES BOUNDED (§7a). Prompt
			 * 8A repeated the body once per unit with no bound at all, so a full-set
			 * plural inside it resolved completely in EVERY section — N sections × N
			 * products is N² list entries in one message. self::rendered_sections() is
			 * that bound; the units past it are LABELLED, so none of them is lost.
			 */
			$notes[] = Text::note_value(
				'consolidation ' . self::PER_PRODUCT . ' fell back to one message covering every matched product: '
					. $count . ' matched products exceeds the cap of ' . $cap
					. '; the body is rendered for the first ' . self::rendered_sections( $count, $cap )
					. ' and the remaining ' . ( $count - self::rendered_sections( $count, $cap ) ) . ' are named'
			);

			return self::combined( $requested, $matched_items, $count, $cap, true, $notes, $units );
		}

		$messages = array();
		$index    = 0;

		foreach ( $units as $unit ) {
			++$index;

			$messages[] = array(
				'index'        => $index,
				'count'        => $count,
				'unit'         => $unit['key'],
				'product_id'   => $unit['product_id'],
				'variation_id' => $unit['variation_id'],
				// THE UNIT'S REPRESENTATIVE LINE ITEM (ADR-0016 §6): its FIRST matched
				// item in line-item order. All seven singular item placeholders bind to
				// this one item, so they describe the same line rather than a mixture.
				'item_id'      => $unit['item_id'],
				'items'        => $unit['items'],
				// ALWAYS EMPTY ON A REAL FAN-OUT (ADR-0016 §7): this message IS one
				// unit, so there is nothing to section. Declared rather than omitted so
				// every message carries the same keys and no reader has to guess.
				'sections'     => array(),
			);
		}

		return array(
			'requested' => $requested,
			'mode'      => self::PER_PRODUCT,
			'units'     => $count,
			'cap'       => $cap,
			'capped'    => false,
			'notes'     => $notes,
			'messages'  => $messages,
		);
	}

	/**
	 * A plan of exactly one message carrying every matched item.
	 *
	 * `none`'s behaviour, and the cap fallback's, and §1a's. One code path for all
	 * three so the combined message cannot come out differently depending on why it
	 * was chosen.
	 *
	 * ⚠ `$sections` IS WHAT MAKES THE CAP FALLBACK CONTAIN EVERY PRODUCT (ADR-0016 §7).
	 * It is EMPTY for `none`, so an ordinary delivery renders exactly as it did before
	 * this ADR existed — one body, one value set, `item_id = 0`, the ADR-0014 §5
	 * first-matched-item binding untouched. It is populated ONLY by the cap fallback,
	 * where EVERY unit gets a section labelled with its product name and the first
	 * `cap` of them also carry the merchant's body (§7a).
	 *
	 * ⚠ EVERY UNIT GETS A SECTION AND ONLY THE BODY IS BOUNDED, and the two halves are
	 * separate on purpose: the LABEL is what satisfies "no matched product absent from
	 * the customer-visible body", so bounding the section COUNT would drop products,
	 * while bounding what a section CARRIES costs content and loses nobody.
	 *
	 * WHY THE LABEL IS A CORRECTNESS REQUIREMENT AND NOT DECORATION. Repetition alone
	 * fails for two ORDINARY templates:
	 *
	 *   | template                        | pure repetition | product named? |
	 *   |---------------------------------|-----------------|----------------|
	 *   | `Care guide for {product_name}.` | 60 correct blocks | YES          |
	 *   | *(empty body)*                   | 60 empty blocks   | **NO**       |
	 *   | `Hand wash only.` (no token)     | 60 identical blocks | **NO**     |
	 *
	 * The third row decides it: a template with no placeholder is entirely reasonable
	 * for a single-product rule, and repeating it sixty times names nothing. The label
	 * is the unit's own `{product_name}` — the LINE ITEM's stored name, which survives
	 * product deletion — so it is data the rule is already about rather than
	 * plugin-authored prose, and it is escaped for its destination by the same
	 * `PlaceholderSyntax::escape()` call every placeholder value goes through.
	 *
	 * @param string  $requested     Raw stored consolidation.
	 * @param array[] $matched_items Every matched item record.
	 * @param int     $units         Distinct unit count, when it was computed.
	 * @param int     $cap           Cap in force.
	 * @param bool    $capped        Whether the cap forced this.
	 * @param array   $notes         Diagnostics accumulated so far.
	 * @param array[] $sections      One entry per unit, for the cap fallback only.
	 * @return array
	 */
	private static function combined( string $requested, array $matched_items, int $units, int $cap, bool $capped, array $notes, array $sections = array() ): array {
		return array(
			'requested' => $requested,
			'mode'      => self::NONE,
			'units'     => $units,
			'cap'       => $cap,
			'capped'    => $capped,
			'notes'     => $notes,
			'messages'  => array(
				array(
					'index'        => 1,
					'count'        => 1,
					// EMPTY, not a unit key: this message is about the whole matched
					// set, so naming one product would be a lie the log would carry.
					'unit'         => '',
					'product_id'   => 0,
					'variation_id' => 0,

					/*
					 * ⚠ ZERO IS THE ADR-0014 §5 BINDING, and for `none` it is unchanged:
					 * the singular item placeholders resolve against the FIRST matched
					 * item, exactly as they do for every delivery that predates this ADR.
					 *
					 * The cap fallback ALSO leaves it zero, because the SUBJECT and the
					 * HEADING bind through it and they can carry only one value — so they
					 * bind to the first unit, which is precisely what `none` does. The
					 * fallback therefore introduces no new header semantics at all; the
					 * per-unit completeness lives entirely in `sections`.
					 */
					'item_id'      => 0,
					'items'        => array_values( $matched_items ),
					'sections'     => self::sections_for( $sections, $cap ),
				),
			),
		);
	}

	/**
	 * How many of a capped message's sections carry the merchant's BODY
	 * (ADR-0016 §7a).
	 *
	 * ⚠ THE CAP IN FORCE, AND IT IS THE SAME NUMBER FOR THE SAME REASON. The fallback
	 * moved the repetition out of N MESSAGES and into ONE BODY; Prompt 8A moved it
	 * without moving the limit with it, so the body repeated the merchant's block once
	 * per unit and any full-set plural inside it — `{product_names}`,
	 * `{matched_product_list}` — resolved completely in every one of them. N sections ×
	 * N products is N² list entries in a single message, which for a sixty-line
	 * wholesale order is past the size at which mail clients clip and the customer
	 * silently loses the tail.
	 *
	 * So the invariant is: **the fallback never renders the merchant's body more times
	 * than the fan-out it replaced was permitted to send it.** Every remaining unit is
	 * still LABELLED, so requirement 2 — no matched product absent from the
	 * customer-visible body — holds for every unit and every template, which is the
	 * guarantee, and the per-unit body beyond the cap is what pays for it.
	 *
	 * @param int $units Distinct matched-unit count.
	 * @param int $cap   Cap in force.
	 * @return int
	 */
	public static function rendered_sections( int $units, int $cap ): int {
		return min( max( 0, $units ), max( 1, $cap ) );
	}

	/**
	 * The per-unit section descriptors a capped message renders (ADR-0016 §7).
	 *
	 * Deliberately a projection rather than the unit records themselves: a section
	 * needs only what BINDS a value set, what LABELS it, and whether it carries the
	 * merchant's body — and handing the renderer the whole unit would invite it to read
	 * facts the label has no business carrying.
	 *
	 * ⚠ `content` IS DECIDED HERE, IN THE PURE PLANNER, NOT IN THE RENDERER (ADR-0016
	 * §7a). It is the same decision the message count is — how many times this delivery
	 * may repeat the merchant's block — so it belongs beside it, where it is decided
	 * once, testable without WordPress, and visible in the plan a reader inspects.
	 *
	 * @param array[] $units Units from self::units(), or empty for `none`.
	 * @param int     $cap   Cap in force.
	 * @return array[] One `{unit, item_id, content}` per section, in line-item order.
	 */
	private static function sections_for( array $units, int $cap ): array {
		$sections = array();
		$rendered = self::rendered_sections( count( $units ), $cap );
		$index    = 0;

		foreach ( $units as $unit ) {
			++$index;

			$sections[] = array(
				'unit'    => (string) $unit['key'],
				'item_id' => (int) $unit['item_id'],
				// TRUE for the first `$rendered`; the rest are LABEL ONLY, which still
				// names their product (ADR-0016 §7a).
				'content' => $index <= $rendered,
			);
		}

		return $sections;
	}

	/**
	 * The distinct matched product identities, in first-seen line-item order
	 * (ADR-0016 §4).
	 *
	 * | Matched item | Unit |
	 * |---|---|
	 * | simple product | `product:{product_id}` |
	 * | variation, resolved | `variation:{variation_id}` |
	 * | variation, `partially_resolved` | `product:{product_id}` — the PARENT |
	 * | neither id present | `item:{item_id}` |
	 *
	 * TWO LINE ITEMS OF ONE PRODUCT COLLAPSE, AND SO DOES QUANTITY. ADR-0011 §3
	 * makes quantity and duplicate line items irrelevant to matching, and they stay
	 * irrelevant here: a rule that matched once cannot become five emails because a
	 * customer bought five.
	 *
	 * ⚠ A PARTIALLY RESOLVED VARIATION FALLS BACK TO ITS PARENT. ADR-0011 §4's third
	 * resolution state exists because a deleted variation's OWN facts are no longer
	 * knowable while its identity still is. A unit is what a message is ABOUT, and a
	 * message about a variation nobody can identify has nothing to say that
	 * distinguishes it from its parent. Two such variations of one parent therefore
	 * collapse into one message — neither can be told from the other — while one
	 * alongside a LIVE sibling produces two units, which looks asymmetric until you
	 * notice that only one of them is identifiable.
	 *
	 * ⚠ `resolution` IS READ FROM THE RECORD, NEVER RE-DERIVED, and its absence means
	 * resolved. The scheduled path builds records from
	 * `WC_Order_Item_Product::get_variation_id()`, which returns 0 for a deleted
	 * variation on WC 10.9.4 — the behaviour ADR-0011 §4 flags, where `set_props()`
	 * swallows the setter's exception while `_variation_id` survives in item meta. So
	 * that path reaches the parent unit by a DIFFERENT ROUTE and lands on the same
	 * answer, and neither path depends on the other's quirk.
	 *
	 * @param array[] $matched_items Matched item records, in line-item order.
	 * @return array[] One entry per unit: `{key, product_id, variation_id, item_id, items}`.
	 */
	private static function units( array $matched_items ): array {
		$units = array();

		foreach ( $matched_items as $matched ) {
			if ( ! is_array( $matched ) ) {
				continue;
			}

			$item_id      = (int) ( $matched['item_id'] ?? 0 );
			$product_id   = (int) ( $matched['product_id'] ?? 0 );
			$variation_id = (int) ( $matched['variation_id'] ?? 0 );
			$partial      = Targeting::PARTIALLY_RESOLVED === (string) ( $matched['resolution'] ?? '' );

			if ( $variation_id > 0 && ! $partial ) {
				$key = 'variation:' . $variation_id;
			} elseif ( $product_id > 0 ) {
				// The parent, for a simple product AND for a variation whose own facts
				// are gone. `variation_id` is reported 0 because the unit really is the
				// parent — the `key` is what records which of the two cases produced it.
				$key          = 'product:' . $product_id;
				$variation_id = 0;
			} else {
				// DEFENSIVE, and deliberately per line item: with neither id there is
				// nothing to group by, and grouping unrelated items under one message
				// would attribute one product's content to another.
				$key = 'item:' . $item_id;
			}

			if ( ! isset( $units[ $key ] ) ) {
				$units[ $key ] = array(
					'key'          => $key,
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'item_id'      => $item_id,
					'items'        => array(),
				);
			}

			$units[ $key ]['items'][] = $matched;
		}

		return array_values( $units );
	}

	/**
	 * The `snapshot` payload recording one message's place in its fan-out.
	 *
	 * ⚠ THE FAN-OUT AXIS IS HERE, NOT IN THE `attempt` COLUMN (ADR-0016 §5). That
	 * column means "attempt N at this delivery" — ADR-0004's manual resend chain —
	 * and the N messages of a fan-out are not retries of one another; they are ONE
	 * attempt at one decision expressed as N messages. Putting the message index
	 * there would be two orthogonal facts in one column, which is the failure shape
	 * this project has now hit three times (`not_rendered` overwriting the message
	 * outcome, a boolean merging a lost race with a failed write, a truthy filter
	 * return read as consent).
	 *
	 * ⚠ AND IT IS EMPTY FOR A PLAIN `none` RULE. A rule that never asked for
	 * consolidation gets exactly the rows it got before this ADR — no extra key, no
	 * change to what its snapshot carries.
	 *
	 * @param array $plan    Plan from self::plan().
	 * @param array $message One of its messages.
	 * @return array Snapshot fragment, or empty.
	 */
	public static function snapshot_for( array $plan, array $message ): array {
		if ( self::NONE === (string) ( $plan['requested'] ?? self::NONE ) ) {
			return array();
		}

		$payload = array(
			'requested' => (string) $plan['requested'],
			'mode'      => (string) $plan['mode'],
			'index'     => (int) $message['index'],
			'count'     => (int) $message['count'],
			'unit'      => (string) $message['unit'],
			'cap'       => (int) $plan['cap'],
		);

		if ( (int) $message['product_id'] > 0 ) {
			$payload['product_id'] = (int) $message['product_id'];
		}

		if ( (int) $message['variation_id'] > 0 ) {
			$payload['variation_id'] = (int) $message['variation_id'];
		}

		if ( (bool) $plan['capped'] ) {
			$payload['units']    = (int) $plan['units'];
			$payload['fallback'] = self::FALLBACK_CAP_EXCEEDED;
			// HOW MANY UNITS GOT THE MERCHANT'S BODY (ADR-0016 §7a). The remaining
			// `units - rendered` are named and nothing more, so support tooling can
			// answer "how much of this rule did that customer actually see" without
			// re-deriving it from a cap that may since have been filtered differently.
			$payload['rendered'] = self::rendered_sections( (int) $plan['units'], (int) $plan['cap'] );
		}

		return array( 'consolidation' => $payload );
	}

	/**
	 * The plan's diagnostics as one sentence, for a `reason` column.
	 *
	 * @param array $plan Plan from self::plan().
	 * @return string
	 */
	public static function note_line( array $plan ): string {
		return implode( '; ', (array) ( $plan['notes'] ?? array() ) );
	}

	/**
	 * Whether a plan sends more than one message.
	 *
	 * @param array $plan Plan from self::plan().
	 * @return bool
	 */
	public static function is_fan_out( array $plan ): bool {
		return count( (array) ( $plan['messages'] ?? array() ) ) > 1;
	}
}
