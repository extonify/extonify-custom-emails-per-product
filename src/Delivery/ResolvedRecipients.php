<?php
/**
 * The outcome of resolving a rule's recipients document (ADR-0012 §4).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Delivery;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable: the addresses a delivery will use, and everything that was
 * dropped or altered on the way there.
 *
 * WHY THE NOTES ARE PART OF THE VALUE. A dropped invalid address and a stripped
 * header-injection attempt both change who receives the email, and both are
 * invisible in the result alone — the recipient list simply comes out shorter.
 * Carrying the notes alongside makes the delivery log able to say *why* an
 * address is missing, which is the same reason ADR-0011 made the matcher return
 * reason codes instead of a boolean.
 */
final class ResolvedRecipients {

	/**
	 * Whether the recipients document was structurally usable.
	 *
	 * @var bool
	 */
	private $valid;

	/**
	 * Resolved entries, in channel order: each `{address, type, source}`.
	 *
	 * @var array[]
	 */
	private $entries;

	/**
	 * Human-readable notes: dropped entries, stripped line breaks, unresolved
	 * tokens.
	 *
	 * @var string[]
	 */
	private $notes;

	/**
	 * Use the named constructors.
	 *
	 * @param bool     $valid   Structural validity.
	 * @param array[]  $entries Resolved entries.
	 * @param string[] $notes   Notes recorded during resolution.
	 */
	private function __construct( bool $valid, array $entries, array $notes ) {
		$this->valid   = $valid;
		$this->entries = array_values( $entries );
		$this->notes   = array_values( $notes );
	}

	/**
	 * A successful resolution, possibly with notes and possibly with nothing in
	 * it — an empty recipients document is valid and simply sends to nobody.
	 *
	 * @param array[]  $entries Resolved entries.
	 * @param string[] $notes   Notes.
	 * @return ResolvedRecipients
	 */
	public static function create( array $entries, array $notes = array() ): ResolvedRecipients {
		return new self( true, $entries, $notes );
	}

	/**
	 * A structurally malformed recipients document. Resolves nobody.
	 *
	 * @param string $reason Why the document is unusable.
	 * @return ResolvedRecipients
	 */
	public static function invalid( string $reason ): ResolvedRecipients {
		return new self( false, array(), array( $reason ) );
	}

	/**
	 * Whether the document was structurally usable.
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return $this->valid;
	}

	/**
	 * Every resolved entry, in channel order (`to`, then `cc`, then `bcc`).
	 *
	 * @return array[] Each `{address, type, source}`.
	 */
	public function entries(): array {
		return $this->entries;
	}

	/**
	 * Addresses on one channel, in order.
	 *
	 * @param string $type One of `to`, `cc`, `bcc`.
	 * @return string[]
	 */
	public function addresses( string $type ): array {
		$out = array();
		foreach ( $this->entries as $entry ) {
			if ( $type === $entry['type'] ) {
				$out[] = $entry['address'];
			}
		}
		return $out;
	}

	/**
	 * Whether there is at least one `to` recipient.
	 *
	 * `cc`/`bcc` alone is NOT a delivery (ADR-0012 §4): a message with no direct
	 * recipient is a message nobody was sent.
	 *
	 * @return bool
	 */
	public function is_deliverable(): bool {
		return array() !== $this->addresses( 'to' );
	}

	/**
	 * Notes recorded during resolution.
	 *
	 * @return string[]
	 */
	public function notes(): array {
		return $this->notes;
	}

	/**
	 * The notes as one storable reason string, or '' when there are none.
	 *
	 * @return string
	 */
	public function reason(): string {
		return implode( '; ', $this->notes );
	}
}
