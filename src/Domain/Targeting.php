<?php
/**
 * Rule targeting document: parse, validate, evaluate (ADR-0011).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The `rules.targeting` JSON document and the item-level decision it makes.
 *
 * Deliberately free of WordPress: it evaluates plain ITEM DESCRIPTORS produced
 * by the WooCommerce-facing resolver, so every targeting decision is unit
 * testable without a framework, a database or a product.
 *
 * Schema (ADR-0011 §3) — unknown keys are ignored at every level:
 *
 *     {
 *       "include": {
 *         "products":   [int],  "variations": [int],
 *         "categories": [int],  "tags":       [int],
 *         "types":      ["simple","variable","virtual","downloadable", ...]
 *       },
 *       "exclude":   { ...same shape... },
 *       "match_all": false
 *     }
 *
 * `match_all` LIVES AT THE ROOT AND NOWHERE ELSE (ADR-0011 §3). Reading it from
 * inside `include` as well, and inventing an `exclude.match_all`, put three
 * spellings of one flag into a schema that import, export and editor validation
 * all have to agree on — and `exclude.match_all` had no coherent meaning, since
 * a rule that excludes everything is simply an inactive rule. Both are now
 * ordinary unknown keys and are ignored.
 *
 * VALIDITY is structural, and STRICT — because the alternative is silent. A
 * malformed document that merely "declares nothing" reports
 * `no_targeting_match`, which ADR-0005 does NOT log, so a corrupted, truncated
 * or badly imported rule would stop working with no entry anywhere in the
 * delivery log. A document is therefore invalid when:
 *
 *   - the JSON text will not parse, or decodes to something other than an array;
 *   - the root is a non-empty LIST rather than an object;
 *   - `include`/`exclude` is present and is not an object (a scalar, or a
 *     non-empty list);
 *   - a known kind is present and is not an array;
 *   - `match_all` is present and is not a real boolean — `"yes"` and `1` are
 *     rejected rather than guessed at.
 *
 * Junk ENTRIES inside a well-formed kind are still dropped rather than fatal: a
 * wrong-shaped container means the author's intent is unknowable, whereas one
 * bad id in a list of twenty plainly is not. Unknown KEYS inside a correctly
 * shaped document are still ignored (ADR-0011 §3) — shape errors and unknown
 * keys are different things and stay different.
 *
 * `types` mixes product TYPE SLUGS with the boolean FLAGS `virtual` and
 * `downloadable`, deliberately (ADR-0011 §3). They are not the same kind of
 * thing — a product has exactly one type but may carry both flags — and the
 * listed core slugs are not a closed allowlist, so a custom product type
 * registered by another plugin works unchanged.
 */
final class Targeting {

	/**
	 * Item resolution states (ADR-0011 §4). Carried on the item descriptor
	 * under `resolution`; an absent key means self::RESOLVED.
	 *
	 * Both the variation and its parent product loaded, or the item is not a
	 * variation at all. Every fact is knowable.
	 */
	const RESOLVED = 'resolved';

	/**
	 * The line item names a variation that no longer loads, but its PARENT
	 * product does.
	 *
	 * Identity matching proceeds — product id, variation id, category ids and
	 * tag ids are all knowable, the ids from the line item and the terms from
	 * the parent, which is where WooCommerce keeps them anyway. But the
	 * VARIATION-SPECIFIC facts are UNKNOWN and are never inherited: a variation
	 * may be virtual or downloadable when its parent is not, and
	 * `WC_Product_Variation::get_type()` returns `variation`, never the parent's
	 * slug. Falling back to the parent would let a `types` rule match on facts
	 * that never applied to what the customer actually bought.
	 */
	const PARTIALLY_RESOLVED = 'partially_resolved';

	/**
	 * Neither the variation nor the parent loads. The item is skipped and
	 * recorded `product_unavailable`; no descriptor is produced, so this state
	 * never reaches evaluation.
	 */
	const UNRESOLVED = 'unresolved';

	/**
	 * Include/exclude kinds, most specific first.
	 */
	const KINDS = array( 'variations', 'products', 'categories', 'tags', 'types' );

	/**
	 * Kinds holding term or post ids.
	 */
	const ID_KINDS = array( 'variations', 'products', 'categories', 'tags' );

	/**
	 * Kind => the Specificity kind name it produces on a match.
	 */
	const KIND_SPECIFICITY = array(
		'variations' => 'variation',
		'products'   => 'product',
		'categories' => 'category',
		'tags'       => 'tag',
		'types'      => 'type',
	);

	/**
	 * Entries in `types` that name a boolean product flag rather than a type
	 * slug. `WC_Product::get_type()` can never return either of these.
	 */
	const FLAG_TYPES = array( 'virtual', 'downloadable' );

	/**
	 * Whether the document parsed and its structure is usable.
	 *
	 * @var bool
	 */
	private $valid;

	/**
	 * Normalised include set: kind => list of ids or type strings.
	 *
	 * @var array
	 */
	private $include;

	/**
	 * Normalised exclude set, same shape as $include.
	 *
	 * @var array
	 */
	private $exclude;

	/**
	 * Whether the root-level `match_all` is set.
	 *
	 * @var bool
	 */
	private $match_all;

	/**
	 * Use the named constructors.
	 *
	 * @param bool  $valid       Structural validity.
	 * @param array $include_set Normalised include set.
	 * @param array $exclude_set Normalised exclude set.
	 * @param bool  $match_all   Root-level match_all.
	 */
	private function __construct( bool $valid, array $include_set, array $exclude_set, bool $match_all ) {
		$this->valid     = $valid;
		$this->include   = $include_set;
		$this->exclude   = $exclude_set;
		$this->match_all = $match_all;
	}

	/**
	 * Build from a stored targeting value.
	 *
	 * Accepts either the RAW column string (so syntactically malformed JSON is
	 * distinguishable from an empty document) or an already-decoded array (what
	 * `RuleRepository::hydrate()` returns).
	 *
	 * THE TWO PATHS ARE NOT IDENTICAL, DELIBERATELY. Only the raw string can
	 * tell a JSON object from a JSON array — see self::from_json() — so only the
	 * raw path enforces `{}` versus `[]`. See the class docblock.
	 *
	 * @param mixed $value Raw JSON string, decoded array, or null.
	 * @return Targeting
	 */
	public static function from_value( $value ): Targeting {
		if ( null === $value ) {
			return self::empty_document();
		}

		if ( is_string( $value ) ) {
			$trimmed = trim( $value );
			if ( '' === $trimmed ) {
				return self::empty_document();
			}
			return self::from_json( $trimmed );
		}

		if ( is_array( $value ) ) {
			return self::from_array( $value );
		}

		// A scalar that is not a string — a stored number or boolean — is not a
		// targeting document under any reading.
		return self::invalid();
	}

	/**
	 * Build from the RAW column string, where `{}` and `[]` are distinguishable.
	 *
	 * `json_decode( $raw, true )` renders `{}` and `[]` as the same empty PHP
	 * array — a limitation of the ASSOCIATIVE decode mode, not of JSON. Decoding
	 * WITHOUT it keeps objects as `stdClass` and arrays as arrays, so the
	 * distinction survives, and strict validation can make it:
	 *
	 *     []                -> targeting_invalid   (root must be an object)
	 *     {"include": []}   -> targeting_invalid   (a side must be an object)
	 *     {"exclude": []}   -> targeting_invalid
	 *     {}                -> valid, inert
	 *     {"include": {}}   -> valid, inert
	 *
	 * Worth the extra decode mode because the whole point of strict validation
	 * is that a corrupted or truncated rule reports the LOGGABLE
	 * `targeting_invalid` rather than the silent `no_targeting_match`
	 * (ADR-0011 §3).
	 *
	 * Known KINDS are checked here too, before conversion: a JSON object where a
	 * list is required — `{"products":{"0":1}}` — collapses into an ordinary PHP
	 * array once converted and would otherwise be accepted, turning an object's
	 * values into ids.
	 *
	 * @param string $json Trimmed raw column string.
	 * @return Targeting
	 */
	private static function from_json( string $json ): Targeting {
		$document = json_decode( $json, false, Json::MAX_DEPTH );

		if ( JSON_ERROR_NONE !== json_last_error() || ! $document instanceof \stdClass ) {
			return self::invalid();
		}

		foreach ( array( 'include', 'exclude' ) as $side ) {
			if ( ! property_exists( $document, $side ) ) {
				continue;
			}

			$declared = $document->{$side};
			if ( ! ( $declared instanceof \stdClass ) ) {
				return self::invalid();
			}

			foreach ( self::KINDS as $kind ) {
				if ( property_exists( $declared, $kind ) && ! is_array( $declared->{$kind} ) ) {
					return self::invalid();
				}
			}
		}

		/*
		 * from_array() re-checks the root, and for one degenerate input it is
		 * stricter than the check above: a JSON OBJECT whose keys happen to be
		 * exactly "0".."n-1" converts to a PHP list and is rejected. Such a
		 * document is corrupt under any reading, and rejecting it reports the
		 * loggable `targeting_invalid` rather than passing it off as a document
		 * of unknown keys — the fail-loud direction, which is the point.
		 */
		return self::from_array( (array) self::objects_to_arrays( $document ) );
	}

	/**
	 * Recursively convert decoded `stdClass` nodes to arrays.
	 *
	 * Used only after self::from_json() has made every shape decision that needs
	 * the object/array distinction, so flattening it away afterwards costs
	 * nothing. Depth is already bounded by the decoder's `Json::MAX_DEPTH`.
	 *
	 * @param mixed $value Decoded node.
	 * @return mixed
	 */
	private static function objects_to_arrays( $value ) {
		if ( $value instanceof \stdClass ) {
			$value = get_object_vars( $value );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::objects_to_arrays( $item );
		}

		return $value;
	}

	/**
	 * Build from a decoded document.
	 *
	 * `array()` is a VALID empty document here: a programmatically constructed
	 * document that declares nothing is legitimate, and at this boundary the
	 * `{}`-versus-`[]` distinction genuinely no longer exists. Enforcing it is
	 * self::from_json()'s job, where the information is still present.
	 *
	 * @param array $document Decoded targeting document.
	 * @return Targeting
	 */
	public static function from_array( array $document ): Targeting {
		// A root-level LIST is not a targeting document. Before this check
		// `[1,2]` found no `include` key and became a perfectly valid document
		// that matched nothing — reported `no_targeting_match`, which ADR-0005
		// does not log, so the rule stopped working invisibly.
		if ( ! self::is_object_shaped( $document ) ) {
			return self::invalid();
		}

		// A real boolean, or nothing. `"yes"` and `1` are the shapes a broken
		// importer writes, and guessing at them means guessing whether a rule
		// targets the entire catalogue.
		if ( array_key_exists( 'match_all', $document ) && ! is_bool( $document['match_all'] ) ) {
			return self::invalid();
		}

		$include_raw = $document['include'] ?? array();
		$exclude_raw = $document['exclude'] ?? array();

		foreach ( array( $include_raw, $exclude_raw ) as $side ) {
			if ( ! is_array( $side ) || ! self::is_object_shaped( $side ) ) {
				return self::invalid();
			}
		}

		$include = self::normalize_side( $include_raw );
		$exclude = self::normalize_side( $exclude_raw );

		if ( null === $include || null === $exclude ) {
			return self::invalid();
		}

		// ROOT ONLY (ADR-0011 §3). `include.match_all` and `exclude.match_all`
		// are ordinary unknown keys and are ignored.
		return new self( true, $include, $exclude, ! empty( $document['match_all'] ) );
	}

	/**
	 * Encode a targeting document for the `rules.targeting` column.
	 *
	 * THE COUNTERPART TO self::from_json(), AND REQUIRED BY IT. PHP renders
	 * `array()` as `[]`, so `Json::encode()` stored an empty targeting document
	 * as `[]` — which strict reading now, correctly, calls `targeting_invalid`.
	 * Every brand-new rule with no targeting yet would have reported itself
	 * permanently broken, contradicting ADR-0011 §3: such a rule is VALID and
	 * simply inert. The writer therefore has to be as precise about the
	 * object/array distinction as the reader is.
	 *
	 * Only the AMBIGUITY is fixed. Object-shaped nodes in object positions — the
	 * root, `include`, `exclude` — are cast to objects so they cannot come back
	 * as lists; kind entries stay arrays, because they are lists; and a root or
	 * side that genuinely IS a list is left alone, so garbage still reads back
	 * as `targeting_invalid` rather than being quietly repaired on the way in.
	 *
	 * @param array $document Targeting document.
	 * @return string JSON text for storage.
	 */
	public static function encode( array $document ): string {
		foreach ( array( 'include', 'exclude' ) as $side ) {
			if ( array_key_exists( $side, $document ) && is_array( $document[ $side ] ) && self::is_object_shaped( $document[ $side ] ) ) {
				$document[ $side ] = (object) $document[ $side ];
			}
		}

		return Json::encode_value( self::is_object_shaped( $document ) ? (object) $document : $document, '{}' );
	}

	/**
	 * A valid document that declares nothing, and therefore matches nothing.
	 *
	 * @return Targeting
	 */
	public static function empty_document(): Targeting {
		return new self( true, array(), array(), false );
	}

	/**
	 * A structurally malformed document. Matches nothing and reports
	 * `targeting_invalid`.
	 *
	 * @return Targeting
	 */
	public static function invalid(): Targeting {
		return new self( false, array(), array(), false );
	}

	/**
	 * Whether an array can stand in for a JSON OBJECT.
	 *
	 * An empty array is accepted: at THIS boundary the value has already lost
	 * whether it was written `{}` or `[]`, and a programmatic empty document is
	 * legitimate. The raw-string boundary makes that call instead, while the
	 * information still exists — see self::from_json(). A NON-EMPTY list is
	 * rejected either way: it can only have come from JSON array syntax where
	 * the schema requires an object.
	 *
	 * `array_is_list()` is PHP 8.1; the floor is 8.0 (ADR-0006).
	 *
	 * @param array $value Decoded value.
	 * @return bool
	 */
	private static function is_object_shaped( array $value ): bool {
		if ( array() === $value ) {
			return true;
		}
		return array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}

	/**
	 * Whether the document is structurally usable.
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return $this->valid;
	}

	/**
	 * Whether the root-level `match_all` is set.
	 *
	 * @return bool
	 */
	public function matches_all(): bool {
		return $this->match_all;
	}

	/**
	 * Whether the document can never match anything, whatever the order holds.
	 *
	 * @return bool
	 */
	public function matches_nothing(): bool {
		return ! $this->valid || ( ! $this->match_all && array() === $this->include );
	}

	/**
	 * The declared include set, kind => entries. Exposed for the admin screens
	 * and tests; evaluation uses the internal copy.
	 *
	 * @return array
	 */
	public function includes(): array {
		return $this->include;
	}

	/**
	 * The declared exclude set, kind => entries.
	 *
	 * @return array
	 */
	public function excludes(): array {
		return $this->exclude;
	}

	/**
	 * The rule's declared specificity: the highest ladder level present in the
	 * DECLARED include set (ADR-0011 §5).
	 *
	 * Computed from the declaration, never from a match, so rule ordering is
	 * identical on every order and costs nothing to recompute.
	 *
	 * @return int Ladder level, or Specificity::NONE when nothing is declared.
	 */
	public function declared_specificity(): int {
		if ( ! $this->valid ) {
			return Specificity::NONE;
		}

		$highest = Specificity::NONE;
		foreach ( self::KINDS as $kind ) {
			if ( empty( $this->include[ $kind ] ) ) {
				continue;
			}
			$highest = Specificity::highest( $highest, Specificity::kind_level( self::KIND_SPECIFICITY[ $kind ] ) );
		}

		if ( $this->match_all ) {
			$highest = Specificity::highest( $highest, Specificity::MATCH_ALL );
		}

		return $highest;
	}

	/**
	 * Evaluate one item descriptor against this document.
	 *
	 * The descriptor is a plain array produced by `Matching\ItemResolver`:
	 * `item_id`, `product_id`, `variation_id`, `resolution`, `type`,
	 * `parent_type`, `virtual`, `downloadable`, `category_ids`, `tag_ids`.
	 *
	 * Exclusion always wins, at every specificity level (ADR-0011 §4): an
	 * excluded item can never be rescued by a more specific include.
	 *
	 * @param array $item Item descriptor.
	 * @return array {
	 *     @type bool   $included Matched the include set.
	 *     @type bool   $excluded Matched the exclude set.
	 *     @type bool   $matched  Included and not excluded.
	 *     @type string $kind     Include kind that produced the strongest
	 *                            match, '' when not included.
	 *     @type int    $level    Ladder level of that kind, Specificity::NONE
	 *                            when not included.
	 * }
	 */
	public function evaluate( array $item ): array {
		$result = array(
			'included' => false,
			'excluded' => false,
			'matched'  => false,
			'kind'     => '',
			'level'    => Specificity::NONE,
		);

		if ( ! $this->valid ) {
			return $result;
		}

		$hit = $this->strongest_hit( $this->include, $item );

		if ( null !== $hit ) {
			$result['included'] = true;
			$result['kind']     = $hit['kind'];
			$result['level']    = $hit['level'];
		} elseif ( $this->match_all ) {
			// match_all is the floor, not a ceiling: an item that also satisfies
			// a declared include kind keeps the higher level it earned above.
			$result['included'] = true;
			$result['kind']     = 'match_all';
			$result['level']    = Specificity::MATCH_ALL;
		}

		$result['excluded'] = null !== $this->strongest_hit( $this->exclude, $item );
		$result['matched']  = $result['included'] && ! $result['excluded'];

		return $result;
	}

	/**
	 * The strongest kind in one side of the document that the item satisfies.
	 *
	 * The KINDS constant is ordered most specific first, so the first hit is
	 * the strongest and the remaining kinds need not be tested.
	 *
	 * @param array $side One normalised side (include or exclude).
	 * @param array $item Item descriptor.
	 * @return array|null `{kind, level}`, or null when the item satisfies none.
	 */
	private function strongest_hit( array $side, array $item ): ?array {
		foreach ( self::KINDS as $kind ) {
			if ( empty( $side[ $kind ] ) || ! $this->kind_matches( $kind, $side[ $kind ], $item ) ) {
				continue;
			}
			$name = self::KIND_SPECIFICITY[ $kind ];
			return array(
				'kind'  => $name,
				'level' => Specificity::kind_level( $name ),
			);
		}

		return null;
	}

	/**
	 * Whether one kind's entries match the item.
	 *
	 * @param string $kind    Kind name.
	 * @param array  $entries Normalised entries for that kind.
	 * @param array  $item    Item descriptor.
	 * @return bool
	 */
	private function kind_matches( string $kind, array $entries, array $item ): bool {
		switch ( $kind ) {
			case 'variations':
				$variation_id = (int) ( $item['variation_id'] ?? 0 );
				return $variation_id > 0 && in_array( $variation_id, $entries, true );

			case 'products':
				$product_id = (int) ( $item['product_id'] ?? 0 );
				return $product_id > 0 && in_array( $product_id, $entries, true );

			case 'categories':
				return array() !== array_intersect( $entries, self::int_list( $item['category_ids'] ?? array() ) );

			case 'tags':
				return array() !== array_intersect( $entries, self::int_list( $item['tag_ids'] ?? array() ) );

			case 'types':
				return $this->type_matches( $entries, $item );
		}

		return false;
	}

	/**
	 * Whether any `types` entry matches the item, by type slug or by flag.
	 *
	 * The item's own type slug and — for a variation — its PARENT's type slug
	 * are both considered, because `WC_Product_Variation::get_type()` returns
	 * `variation` and never `variable`, so `types: ["variable"]` would
	 * otherwise match no line item anybody ever actually buys (ADR-0011 §3).
	 *
	 * @param array $entries Normalised type entries.
	 * @param array $item    Item descriptor.
	 * @return bool
	 */
	private function type_matches( array $entries, array $item ): bool {
		/*
		 * A PARTIALLY RESOLVED item knows no type slug and no flags (ADR-0011
		 * §4). Falling back to the parent's would be affirmatively wrong, not
		 * merely degraded: a variation may be virtual or downloadable when its
		 * parent is not, and get_type() never returns the parent's slug. Unknown
		 * is not false, and it is certainly not the parent's value, so a `types`
		 * entry cannot match — the `variation_unavailable` item note is what
		 * explains the absence in the delivery log.
		 */
		if ( self::PARTIALLY_RESOLVED === (string) ( $item['resolution'] ?? self::RESOLVED ) ) {
			return false;
		}

		$slugs = array();
		foreach ( array( $item['type'] ?? '', $item['parent_type'] ?? '' ) as $slug ) {
			$slug = DeliveryIdentity::normalize( (string) $slug );
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		foreach ( $entries as $entry ) {
			if ( in_array( $entry, $slugs, true ) ) {
				return true;
			}
			if ( 'virtual' === $entry && ! empty( $item['virtual'] ) ) {
				return true;
			}
			if ( 'downloadable' === $entry && ! empty( $item['downloadable'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate and normalise one side of the document.
	 *
	 * @param array $side Raw `include` or `exclude` value.
	 * @return array|null Normalised kind => entries, or null when a known kind
	 *                    is present but is not an array (structurally invalid).
	 */
	private static function normalize_side( array $side ): ?array {
		$out = array();

		foreach ( self::KINDS as $kind ) {
			if ( ! array_key_exists( $kind, $side ) ) {
				continue;
			}
			if ( ! is_array( $side[ $kind ] ) ) {
				return null;
			}

			$entries = in_array( $kind, self::ID_KINDS, true )
				? self::int_list( $side[ $kind ] )
				: self::type_list( $side[ $kind ] );

			if ( array() !== $entries ) {
				$out[ $kind ] = $entries;
			}
		}

		return $out;
	}

	/**
	 * Filter a raw list to unique positive integers, DROPPING junk entries.
	 *
	 * @param mixed $values Raw list.
	 * @return int[]
	 */
	private static function int_list( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$out = array();
		foreach ( $values as $value ) {
			$id = self::positive_int( $value );
			if ( null !== $id && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * Read one entry as a post or term id, or reject it.
	 *
	 * `(int)` IS NOT A VALIDATOR. Casting turns `"1abc"` into 1, `"12x"` into
	 * 12, `true` into 1 and `1.9` into 1 — so a rule could target a product
	 * nobody ever selected, which is worse than the rule not working at all.
	 * ADR-0011 §3 says junk entries are DISCARDED; only a real positive integer,
	 * or a string that is nothing but decimal digits, is one.
	 *
	 * Rejected: booleans, floats, `null`, arrays, objects, mixed alphanumeric
	 * strings, signed strings, decimal strings, zero and negatives. A rejected
	 * entry leaves the document valid — it is dropped, never converted.
	 *
	 * @param mixed $value Raw entry.
	 * @return int|null The id, or null when the entry is not one.
	 */
	private static function positive_int( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		// ctype_digit() is false for '', for any sign, separator or space, and
		// for every non-string — exactly the boundary wanted here.
		if ( is_string( $value ) && ctype_digit( $value ) && self::fits_in_int( $value ) ) {
			$id = (int) $value;
			return $id > 0 ? $id : null;
		}

		return null;
	}

	/**
	 * Whether a digit string can be cast to `int` without changing value.
	 *
	 * CASTING AN OVERSIZED DIGIT STRING CLAMPS, IT DOES NOT FAIL:
	 * `(int) "999999999999999999999999999999"` is `PHP_INT_MAX`. One supplied id
	 * would silently become a DIFFERENT id — the same coercion shape as the
	 * `(int)` cast this parser exists to replace, so it is refused for the same
	 * reason.
	 *
	 * Compared as text rather than numerically, because any numeric comparison
	 * would itself have to survive the overflow being tested for. Leading zeros
	 * still normalise (`"0100"` -> `100`), which is the existing contract.
	 *
	 * @param string $digits A string already known to be all decimal digits.
	 * @return bool
	 */
	private static function fits_in_int( string $digits ): bool {
		$significant = ltrim( $digits, '0' );

		if ( '' === $significant ) {
			// All zeros. In range, and rejected later for not being positive.
			return true;
		}

		$max = (string) PHP_INT_MAX;

		if ( strlen( $significant ) !== strlen( $max ) ) {
			return strlen( $significant ) < strlen( $max );
		}

		return strcmp( $significant, $max ) <= 0;
	}

	/**
	 * Normalise a raw `types` list to unique, ASCII-lowercased strings.
	 *
	 * @param mixed $values Raw list.
	 * @return string[]
	 */
	private static function type_list( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$out = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$slug = DeliveryIdentity::normalize( $value );
			if ( '' !== $slug && ! in_array( $slug, $out, true ) ) {
				$out[] = $slug;
			}
		}

		return $out;
	}
}
