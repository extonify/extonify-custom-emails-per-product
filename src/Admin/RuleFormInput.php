<?php
/**
 * The rule editor's input boundary: `$_POST` in, field values out.
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

use Extonify\WCEP\Delivery\Consolidation;
use Extonify\WCEP\Domain\RecipientsDocument;
use Extonify\WCEP\Domain\Targeting;
use Extonify\WCEP\Domain\TriggerEvent;
use Extonify\WCEP\Repository\RuleRepository;

defined( 'ABSPATH' ) || exit;

/**
 * One submitted (or one stored) rule, as field values the editor can re-render and
 * the repository can be handed.
 *
 * ⚠ THE ONE PLACE `$_POST` IS READ FOR A RULE, and it reads it exactly once, into a
 * fixed list of field names. Nothing in `$_POST` can introduce a field here — the
 * same discipline `RuleRepository::sanitize()` uses for columns, one layer out.
 *
 * ⚠ AND THE SANITISATION IS DELIBERATELY DIFFERENT FOR THE VALIDATED COLUMNS, WHICH
 * LOOKS LIKE A GAP AND IS THE OPPOSITE OF ONE.
 *
 * `status`, `delivery_mode`, `consolidation`, `trigger_type`, `trigger_value` and
 * `native_email_id` are carried through as their EXACT SUBMITTED BYTES, unslashed
 * and forced to a string, and nothing more. Running `sanitize_key()` over them here
 * would REPAIR them — `STATUS!` into `status`, `customer_processing_order!` into a
 * registered email id, `INSERT` into `insert` — and a repaired value is not a
 * rejected one: it is a DIFFERENT VALID VALUE the merchant never chose, stored and
 * delivered. That is precisely the silent coercion ADR-0009's refuse-never-repair
 * boundary exists to prevent, and sanitising here would reintroduce it one layer
 * above the boundary that removed it.
 *
 * Nothing downstream relies on sanitising them for safety: `$wpdb->prepare()` binds
 * every value, every rendered value is escaped at its point of output for its own
 * context (gate 31), and the repository refuses anything outside the vocabulary
 * before a row is written. The type coercion — non-scalar becomes the empty string,
 * which every validated column then refuses — is the sanitisation, and it is applied
 * to every one of them.
 *
 * The columns that are NOT enumerations are sanitised the ordinary way: text fields
 * through `sanitize_text_field()`, the body through `wp_kses_post()`, ids through
 * `Domain\Targeting`'s own parsers (ADR-0017 §2), integers through `(int)`.
 */
final class RuleFormInput {

	/**
	 * The `$_POST` key the whole form lives under.
	 */
	const FIELD = 'extonify_wcep_rule';

	/**
	 * Field values, keyed by field name.
	 *
	 * @var array
	 */
	private $fields;

	/**
	 * Editor-level validation errors: field => message key.
	 *
	 * ⚠ THESE ARE THE EDITOR'S OWN, NOT THE REPOSITORY'S. The repository's refusals
	 * arrive as `RuleRepository::explain_refusal()` output AFTER a write is attempted
	 * (ADR-0017 §3); these are the things the storage layer has no opinion about and
	 * a merchant still needs telling.
	 *
	 * @var array<string,string>
	 */
	private $errors;

	/**
	 * Use the named constructors.
	 *
	 * @param array                $fields Field values.
	 * @param array<string,string> $errors Editor-level errors.
	 */
	private function __construct( array $fields, array $errors ) {
		$this->fields = $fields;
		$this->errors = $errors;
	}

	/**
	 * A blank form for a brand-new rule.
	 *
	 * @return RuleFormInput
	 */
	public static function blank(): RuleFormInput {
		return new self( self::defaults(), array() );
	}

	/**
	 * Read one submitted form.
	 *
	 * ⚠ `wp_unslash()` BEFORE ANY SANITISER, ALWAYS. WordPress adds slashes to every
	 * superglobal, so sanitising first measures a string nobody submitted and stores
	 * one nobody typed — `O\'Brien` survives as `O\\'Brien`, and a value the
	 * repository judges byte-for-byte is judged on the wrong bytes.
	 *
	 * @param array $post Raw `$_POST`, still slashed.
	 * @return RuleFormInput
	 */
	public static function from_post( array $post ): RuleFormInput {
		$raw = isset( $post[ self::FIELD ] ) && is_array( $post[ self::FIELD ] )
			? wp_unslash( $post[ self::FIELD ] )
			: array();

		$fields = self::defaults();

		// --- Identity and status -------------------------------------------------
		$fields['name']   = sanitize_text_field( self::scalar( $raw, 'name' ) );
		$fields['status'] = self::scalar( $raw, 'status' );

		// --- Trigger -------------------------------------------------------------
		$fields['trigger_type']   = self::scalar( $raw, 'trigger_type' );
		$fields['trigger_status'] = self::scalar( $raw, 'trigger_status' );
		$fields['trigger_from']   = self::scalar( $raw, 'trigger_from' );
		$fields['trigger_to']     = self::scalar( $raw, 'trigger_to' );

		// --- Delivery ------------------------------------------------------------
		$fields['delivery_mode']   = self::scalar( $raw, 'delivery_mode' );
		$fields['native_email_id'] = self::scalar( $raw, 'native_email_id' );
		$fields['insert_position'] = sanitize_key( self::scalar( $raw, 'insert_position' ) );
		$fields['delay_value']     = (int) self::scalar( $raw, 'delay_value' );
		$fields['delay_unit']      = sanitize_key( self::scalar( $raw, 'delay_unit' ) );
		$fields['consolidation']   = self::scalar( $raw, 'consolidation' );

		// --- Targeting -----------------------------------------------------------
		$fields['targeting'] = self::targeting_fields( $raw );
		$fields['match_all'] = ! empty( $raw['match_all'] );

		// --- Recipients ----------------------------------------------------------
		$fields['recipients'] = self::recipient_fields( $raw );

		// --- Content -------------------------------------------------------------
		$fields['subject'] = sanitize_text_field( self::scalar( $raw, 'subject' ) );
		$fields['heading'] = sanitize_text_field( self::scalar( $raw, 'heading' ) );
		// Merchant-authored HTML email body: wp_kses_post keeps safe markup and
		// strips scripts. The repository applies it again at the storage boundary,
		// and every render escapes for its own context.
		$fields['content'] = wp_kses_post( self::scalar( $raw, 'content' ) );

		// --- Ordering ------------------------------------------------------------
		$fields['priority']        = (int) self::scalar( $raw, 'priority' );
		$fields['stop_processing'] = ! empty( $raw['stop_processing'] );

		return new self( $fields, self::validate( $fields ) );
	}

	/**
	 * Re-open a stored rule as form values.
	 *
	 * @param array $rule Hydrated rule row.
	 * @return RuleFormInput
	 */
	public static function from_rule( array $rule ): RuleFormInput {
		$fields = self::defaults();

		$fields['name']            = (string) ( $rule['name'] ?? '' );
		$fields['status']          = (string) ( $rule['status'] ?? 'inactive' );
		$fields['trigger_type']    = (string) ( $rule['trigger_type'] ?? TriggerEvent::TYPE_STATUS );
		$fields['delivery_mode']   = (string) ( $rule['delivery_mode'] ?? 'separate' );
		$fields['native_email_id'] = (string) ( $rule['native_email_id'] ?? '' );
		$fields['insert_position'] = (string) ( $rule['insert_position'] ?? '' );
		$fields['consolidation']   = (string) ( $rule['consolidation'] ?? Consolidation::NONE );
		$fields['subject']         = (string) ( $rule['subject'] ?? '' );
		$fields['heading']         = (string) ( $rule['heading'] ?? '' );
		$fields['content']         = (string) ( $rule['content'] ?? '' );
		$fields['priority']        = (int) ( $rule['priority'] ?? 10 );
		$fields['stop_processing'] = ! empty( $rule['stop_processing'] );

		$value = (string) ( $rule['trigger_value'] ?? '' );

		if ( TriggerEvent::TYPE_TRANSITION === $fields['trigger_type'] ) {
			$sides                  = explode( '>', $value );
			$fields['trigger_from'] = (string) ( $sides[0] ?? '' );
			$fields['trigger_to']   = (string) ( $sides[1] ?? '' );
		} else {
			$fields['trigger_status'] = $value;
		}

		/*
		 * ⚠ THE DELAY IS SHOWN IN THE LARGEST UNIT THAT DIVIDES IT EXACTLY, so a rule
		 * stored as 604800 reads "7 days" rather than "604800 seconds". Exactness is
		 * what makes it lossless: a value no larger unit divides falls back to
		 * `seconds`, whose multiplier is 1, so `self::delay_seconds()` reconstructs
		 * the stored integer unchanged.
		 *
		 * ⚠ AND `seconds` MUST BE A UNIT `FieldOptions::delay_units()` OFFERS, which is
		 * the whole point of it being there. This fallback has always named `seconds`;
		 * while the vocabulary stopped at `minutes` the editor rendered a `<select>`
		 * with no matching option, the browser selected the first one, and the next
		 * save stored 90 seconds as 90 minutes — cancelling a queued delivery under
		 * ADR-0015 §4 and consuming its identity for good under §1a. See
		 * `FieldOptions::delay_units()`.
		 */
		$seconds = max( 0, (int) ( $rule['delay_seconds'] ?? 0 ) );

		$fields['delay_value'] = $seconds;
		$fields['delay_unit']  = 'seconds';

		foreach ( array_reverse( FieldOptions::delay_units(), true ) as $unit => $size ) {
			if ( $seconds > 0 && 0 === $seconds % $size ) {
				$fields['delay_value'] = (int) ( $seconds / $size );
				$fields['delay_unit']  = $unit;
				break;
			}
		}

		$targeting = Targeting::from_value( $rule[ 'targeting' . RuleRepository::RAW_SUFFIX ] ?? ( $rule['targeting'] ?? null ) );

		foreach ( self::sides() as $side ) {
			$set = 'include' === $side ? $targeting->includes() : $targeting->excludes();

			foreach ( Targeting::KINDS as $kind ) {
				$fields['targeting'][ $side ][ $kind ] = array_values( (array) ( $set[ $kind ] ?? array() ) );
			}
		}

		$fields['match_all'] = $targeting->matches_all();

		$recipients = RecipientsDocument::from_value( $rule[ 'recipients' . RuleRepository::RAW_SUFFIX ] ?? ( $rule['recipients'] ?? null ) );

		foreach ( RecipientsDocument::CHANNELS as $channel ) {
			$fields['recipients'][ $channel ] = RuleDocuments::entry_list(
				array_map( 'strval', array_filter( (array) ( ( $recipients[ $channel ] ?? array() ) ), 'is_scalar' ) )
			);
		}

		return new self( $fields, array() );
	}

	/**
	 * One field value.
	 *
	 * @param string $key      Field name.
	 * @param mixed  $fallback Value when the field is unset.
	 * @return mixed
	 */
	public function get( string $key, $fallback = '' ) {
		return $this->fields[ $key ] ?? $fallback;
	}

	/**
	 * One targeting kind's entries.
	 *
	 * @param string $side One of `include`, `exclude`.
	 * @param string $kind One of `Targeting::KINDS`.
	 * @return array
	 */
	public function targets( string $side, string $kind ): array {
		return (array) ( $this->fields['targeting'][ $side ][ $kind ] ?? array() );
	}

	/**
	 * One recipient channel's entries.
	 *
	 * @param string $channel One of `RecipientsDocument::CHANNELS`.
	 * @return string[]
	 */
	public function recipients( string $channel ): array {
		return (array) ( $this->fields['recipients'][ $channel ] ?? array() );
	}

	/**
	 * The delay in seconds, as the repository stores it.
	 *
	 * @return int
	 */
	public function delay_seconds(): int {
		$units = FieldOptions::delay_units();
		$unit  = (string) $this->get( 'delay_unit', 'minutes' );
		$size  = $units[ $unit ] ?? 1;

		return (int) $this->get( 'delay_value', 0 ) * $size;
	}

	/**
	 * The trigger value the composed form fields make (ADR-0011 §2).
	 *
	 * ⚠ COMPOSED, NEVER SANITISED. A transition is `{from}>{to}` and the repository
	 * refuses a malformed one — an empty side, no `>`, more than one — naming
	 * `trigger_value`. Repairing it here would store a trigger the merchant did not
	 * choose, which is the failure ADR-0011 §2 refuses for.
	 *
	 * @return string
	 */
	public function trigger_value(): string {
		$type = (string) $this->get( 'trigger_type' );

		if ( TriggerEvent::TYPE_TRANSITION === $type ) {
			return $this->get( 'trigger_from' ) . '>' . $this->get( 'trigger_to' );
		}

		if ( TriggerEvent::TYPE_REFUND === $type ) {
			// ADR-0011 §2: refunds are keyed by refund id, so the value is empty.
			return '';
		}

		return (string) $this->get( 'trigger_status' );
	}

	/**
	 * Editor-level errors, field => message key. Empty when the form is internally
	 * consistent — which says nothing about whether the repository will accept it.
	 *
	 * @return array<string,string>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * The `$data` array `RuleRepository::insert()`/`update()` takes.
	 *
	 * ⚠ EVERY VALIDATED COLUMN IS PASSED AS SUBMITTED. `delay_seconds` and
	 * `consolidation` in particular are NOT forced to `0`/`none` for an insert-mode
	 * rule, deliberately: ADR-0013 §2 and ADR-0016 §2 make that combination
	 * unstorable, the editor disables the controls so a merchant cannot reach it
	 * (ADR-0017 §5, W4), and a FORGED post that sets them anyway must be REFUSED with
	 * the field named rather than quietly corrected into a rule nobody asked for.
	 *
	 * @return array
	 */
	public function to_write(): array {
		return array(
			'name'            => (string) $this->get( 'name' ),
			'status'          => (string) $this->get( 'status' ),
			'priority'        => (int) $this->get( 'priority', 10 ),
			'trigger_type'    => (string) $this->get( 'trigger_type' ),
			'trigger_value'   => $this->trigger_value(),
			'delivery_mode'   => (string) $this->get( 'delivery_mode' ),
			'native_email_id' => (string) $this->get( 'native_email_id' ),
			'insert_position' => (string) $this->get( 'insert_position' ),
			'targeting'       => RuleDocuments::targeting(
				array(
					'include'   => $this->fields['targeting']['include'] ?? array(),
					'exclude'   => $this->fields['targeting']['exclude'] ?? array(),
					'match_all' => $this->get( 'match_all' ),
				)
			),
			'recipients'      => RuleDocuments::recipients( $this->fields['recipients'] ?? array() ),
			'subject'         => (string) $this->get( 'subject' ),
			'heading'         => (string) $this->get( 'heading' ),
			'content'         => (string) $this->get( 'content' ),
			'delay_seconds'   => $this->delay_seconds(),
			'consolidation'   => (string) $this->get( 'consolidation' ),
			'stop_processing' => $this->get( 'stop_processing' ) ? 1 : 0,
		);
	}

	/**
	 * The two targeting sides.
	 *
	 * @return string[]
	 */
	public static function sides(): array {
		return RuleDocuments::SIDES;
	}

	/**
	 * A blank field set.
	 *
	 * @return array
	 */
	private static function defaults(): array {
		$targeting = array();

		foreach ( self::sides() as $side ) {
			foreach ( Targeting::KINDS as $kind ) {
				$targeting[ $side ][ $kind ] = array();
			}
		}

		$recipients = array();

		foreach ( RecipientsDocument::CHANNELS as $channel ) {
			$recipients[ $channel ] = array();
		}

		return array(
			'name'            => '',
			'status'          => 'inactive',
			'trigger_type'    => TriggerEvent::TYPE_STATUS,
			'trigger_status'  => '',
			'trigger_from'    => '',
			'trigger_to'      => '',
			'delivery_mode'   => 'separate',
			'native_email_id' => '',
			'insert_position' => '',
			'delay_value'     => 0,
			'delay_unit'      => 'minutes',
			'consolidation'   => Consolidation::NONE,
			'targeting'       => $targeting,
			'match_all'       => false,
			'recipients'      => $recipients,
			'subject'         => '',
			'heading'         => '',
			'content'         => '',
			'priority'        => 10,
			'stop_processing' => false,
		);
	}

	/**
	 * Read the targeting half of a submitted form.
	 *
	 * ⚠ THE VALIDATOR'S OWN PARSERS, NOT A MATCHING PAIR (ADR-0017 §2). Ids go
	 * through `Targeting::int_list()` and types through `Targeting::type_list()` —
	 * the exact functions `Targeting` validates a stored document with — so junk is
	 * DROPPED rather than cast into a different id, and the editor cannot emit an
	 * entry its own validator would reject.
	 *
	 * @param array $raw Unslashed form array.
	 * @return array
	 */
	private static function targeting_fields( array $raw ): array {
		$out       = array();
		$submitted = isset( $raw['targeting'] ) && is_array( $raw['targeting'] ) ? $raw['targeting'] : array();

		foreach ( self::sides() as $side ) {
			$side_raw = isset( $submitted[ $side ] ) && is_array( $submitted[ $side ] ) ? $submitted[ $side ] : array();

			foreach ( Targeting::KINDS as $kind ) {
				$out[ $side ][ $kind ] = in_array( $kind, Targeting::ID_KINDS, true )
					? Targeting::int_list( $side_raw[ $kind ] ?? array() )
					: Targeting::type_list( $side_raw[ $kind ] ?? array() );
			}
		}

		return $out;
	}

	/**
	 * Read the recipients half of a submitted form.
	 *
	 * A channel arrives as one textarea, one entry per line — a merchant writing
	 * three addresses writes three lines, which is what every mail client has taught
	 * them to expect.
	 *
	 * @param array $raw Unslashed form array.
	 * @return array<string,string[]>
	 */
	private static function recipient_fields( array $raw ): array {
		$out       = array();
		$submitted = isset( $raw['recipients'] ) && is_array( $raw['recipients'] ) ? $raw['recipients'] : array();

		foreach ( RecipientsDocument::CHANNELS as $channel ) {
			$lines = preg_split( '/[\r\n]+/', (string) self::scalar( $submitted, $channel ) );

			$out[ $channel ] = RuleDocuments::entry_list( is_array( $lines ) ? $lines : array() );
		}

		return $out;
	}

	/**
	 * The editor's own consistency checks.
	 *
	 * ⚠ DELIBERATELY SHORT, AND NONE OF IT DUPLICATES THE REPOSITORY (ADR-0017 §4).
	 * Everything the storage layer judges is left to the storage layer, which is the
	 * authority and says WHY. What is here is what the storage layer has no opinion
	 * about:
	 *
	 *   - a rule with no name is unfindable in a list of forty;
	 *   - a NEGATIVE delay would be silently clamped to zero by
	 *     `RuleRepository::sanitize()`'s `max( 0, … )`, so the merchant's editor would
	 *     say "-2 hours" and the rule would send immediately. Refused here rather than
	 *     repaired there;
	 *   - an unknown delay UNIT would silently be treated as seconds, turning
	 *     "7 whatever" into seven seconds.
	 *
	 * @param array $fields Parsed field values.
	 * @return array<string,string>
	 */
	private static function validate( array $fields ): array {
		$errors = array();

		if ( '' === trim( (string) $fields['name'] ) ) {
			$errors['name'] = 'required';
		}

		if ( (int) $fields['delay_value'] < 0 ) {
			$errors['delay_seconds'] = 'negative';
		}

		$units = FieldOptions::delay_units();

		if ( ! isset( $units[ (string) $fields['delay_unit'] ] ) ) {
			$errors['delay_seconds'] = 'unit';
		}

		return $errors;
	}

	/**
	 * One raw field as a string, without ever throwing.
	 *
	 * NON-SCALAR INPUT BECOMES THE EMPTY STRING — the same contract
	 * `RuleRepository::raw_value()` holds, and for the same two reasons: casting an
	 * array to string raises a notice (which the suite converts to an exception, so a
	 * rejected write would become a fatal one), and `(string) $object` would call
	 * `__toString()` on caller-supplied code.
	 *
	 * @param array  $data Unslashed input.
	 * @param string $key  Field name.
	 * @return string
	 */
	private static function scalar( array $data, string $key ): string {
		$value = $data[ $key ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}
}
