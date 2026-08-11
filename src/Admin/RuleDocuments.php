<?php
/**
 * THE EDITOR–VALIDATOR CONTRACT: form fields in, storable documents out
 * (ADR-0017 §2).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

use Extonify\WCEP\Delivery\HeaderGuard;
use Extonify\WCEP\Domain\RecipientsDocument;
use Extonify\WCEP\Domain\Targeting;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the two JSON documents a rule stores, from already-parsed field values.
 *
 * ⚠ THIS CLASS IS THE WHOLE OF ADR-0017 §2, AND ITS JOB IS TO BE INCAPABLE RATHER
 * THAN CAREFUL.
 *
 * `Domain\Targeting` and `Domain\RecipientsDocument` validate their stored strings
 * strictly, and a document failing that validation is `targeting_invalid` — a
 * LOGGABLE outcome (which is the point of the strictness) but an INVISIBLE one to a
 * merchant who has just clicked Save. The rule saves, reports success, and never
 * fires. So the editor may not merely avoid emitting such a document; it must be
 * structurally unable to.
 *
 * FIVE PROPERTIES, AND EACH IS A PROPERTY OF THE CODE RATHER THAN OF A REQUEST:
 *
 *   1. **The browser never sends a document.** It sends field values — checkbox sets
 *      of ids, checkbox sets of type slugs, one `match_all` checkbox, and three lists
 *      of recipient entries. There is no request shape in which a JSON string reaches
 *      the storage layer, because nothing here parses one.
 *   2. **This class is FREE OF WordPress AND FREE OF `$_POST`.** It is a pure
 *      function of its argument, so the space of documents it can emit is
 *      enumerable, and gate 29 enumerates ALL 2048 of the targeting shapes and all 8
 *      of the recipients shapes rather than sampling them.
 *   3. **The id and type parsers ARE THE VALIDATOR'S OWN** — `Targeting::int_list()`
 *      and `Targeting::type_list()`, made public in Prompt 9 for exactly this. Not a
 *      matching pair that a test keeps honest: the same function, so there is nothing
 *      to drift.
 *   4. **Empty is OMITTED, never emitted as an empty container.** An empty kind is
 *      dropped, a side with no kinds is dropped, an empty channel is dropped — so no
 *      list-shaped node can ever appear where the schema requires an object.
 *   5. **`match_all` is always a real PHP `bool`.** `Targeting::from_array()` refuses
 *      `"yes"` and `1` rather than guessing whether a rule targets the whole
 *      catalogue, so the one flag that could is written by `! empty()` and by nothing
 *      else.
 *
 * `Targeting::encode()` and `RecipientsDocument::encode()` remain the only writers of
 * their columns, and they are what turn an object-shaped root into `{}` rather than
 * the `[]` PHP renders for an empty array.
 */
final class RuleDocuments {

	/**
	 * The two sides of a targeting document.
	 */
	const SIDES = array( 'include', 'exclude' );

	/**
	 * Build a `rules.targeting` document from parsed form input.
	 *
	 * The emitted structure is exactly: a root object; `include` and/or `exclude`
	 * present only when they hold at least one kind, each an object; each kind a
	 * non-empty list; `match_all` a boolean. Nothing else is reachable.
	 *
	 * @param array $input {
	 *     Raw field values, exactly as read from the form.
	 *
	 *     @type array $include   Kind => raw list of entries.
	 *     @type array $exclude   Kind => raw list of entries.
	 *     @type mixed $match_all Truthy when the rule targets everything.
	 * }
	 * @return array A document `Targeting::encode()` will store and
	 *               `Targeting::from_value()` will accept.
	 */
	public static function targeting( array $input ): array {
		$document = array();

		foreach ( self::SIDES as $side ) {
			$raw = isset( $input[ $side ] ) && is_array( $input[ $side ] ) ? $input[ $side ] : array();
			$set = array();

			foreach ( Targeting::KINDS as $kind ) {
				$entries = in_array( $kind, Targeting::ID_KINDS, true )
					? Targeting::int_list( $raw[ $kind ] ?? array() )
					: Targeting::type_list( $raw[ $kind ] ?? array() );

				// AN EMPTY KIND IS OMITTED. `"products": []` is a legal document, but
				// omitting it keeps the emitted shape-space small enough to enumerate
				// exhaustively, which is what gate 29 is.
				if ( array() !== $entries ) {
					$set[ $kind ] = $entries;
				}
			}

			// A SIDE WITH NO KINDS IS OMITTED, for the same reason.
			if ( array() !== $set ) {
				$document[ $side ] = $set;
			}
		}

		/*
		 * ⚠ A REAL BOOLEAN, ALWAYS, AND WRITTEN NOWHERE ELSE. `Targeting::from_array()`
		 * refuses `"yes"` and `1` rather than guessing at them, because guessing means
		 * guessing whether a rule targets the entire catalogue. `! empty()` is the only
		 * expression in this class that produces this value.
		 */
		$document['match_all'] = ! empty( $input['match_all'] );

		return $document;
	}

	/**
	 * Build a `rules.recipients` document from parsed form input.
	 *
	 * @param array $input Channel => raw list of entries.
	 * @return array A document `RecipientsDocument::encode()` will store and
	 *               `RecipientsDocument::from_value()` will accept.
	 */
	public static function recipients( array $input ): array {
		$document = array();

		foreach ( RecipientsDocument::CHANNELS as $channel ) {
			$entries = self::entry_list( $input[ $channel ] ?? array() );

			if ( array() !== $entries ) {
				$document[ $channel ] = $entries;
			}
		}

		return $document;
	}

	/**
	 * Filter a raw list to unique, non-empty, single-line recipient entries.
	 *
	 * ⚠ WHAT THIS IS AND IS NOT. It is a SHAPE filter: the stored document must be a
	 * list of strings, so non-strings and blanks are dropped and duplicates collapse.
	 * It is NOT address validation and it is NOT the header-injection defence — those
	 * are `Delivery\RecipientResolver`'s, at send time, against the live order, where
	 * `HeaderGuard::strip()`, `Recipient::normalize()`'s `is_email()` and ADR-0014 §7a's
	 * whole-entry refusal all still apply to every entry stored here.
	 *
	 * Line breaks are nonetheless stripped ON THE WAY IN, through the same
	 * `HeaderGuard` the send path uses. A stored entry containing a CR or an LF has no
	 * legitimate reading, and keeping one would mean the editor renders back something
	 * the sender will later refuse — a rule that looks saved and cannot deliver.
	 *
	 * @param mixed $values Raw list.
	 * @return string[]
	 */
	public static function entry_list( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$out = array();

		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}

			$entry = trim( HeaderGuard::strip( $value ) );

			if ( '' === $entry || in_array( $entry, $out, true ) ) {
				continue;
			}

			$out[] = $entry;
		}

		return $out;
	}
}
