<?php
/**
 * The rule write handlers: save, delete, duplicate, status toggle (ADR-0017 §3).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

use Extonify\WCEP\Plugin;
use Extonify\WCEP\Repository\RuleRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Every state-changing admin request, behind one capability check and one
 * action-specific nonce each.
 *
 * ⚠ THE HANDLERS RETURN AN OUTCOME; THEY DO NOT REDIRECT AND THEY DO NOT `exit`.
 * `Admin\Menu::dispatch()` turns an outcome into a redirect or a `wp_die()`. That is
 * not indirection for its own sake: a handler that calls `exit` cannot be asserted
 * on, so "this handler refuses a wrong-action nonce" would be a claim rather than a
 * test, and gate 28 requires the test.
 *
 * ⚠ AND THE REPOSITORY'S RETURN VALUE IS THE OUTCOME (ADR-0017 §3.1). Not the
 * absence of an exception, not this layer's opinion of the input. `insert()`
 * returning `0` and `update()` returning `false` are failures, and nothing here
 * softens either into a success notice.
 */
final class RuleActions {

	/**
	 * The form POST.
	 */
	const ACTION_SAVE = 'save';

	/**
	 * Row actions, each a nonce-carrying GET.
	 */
	const ACTION_DELETE     = 'delete';
	const ACTION_DUPLICATE  = 'duplicate';
	const ACTION_ACTIVATE   = 'activate';
	const ACTION_DEACTIVATE = 'deactivate';

	/**
	 * The nonce action the editor form carries.
	 */
	const NONCE_SAVE = 'extonify_wcep_save_rule';

	/**
	 * The form's nonce field name.
	 */
	const NONCE_FIELD = 'extonify_wcep_nonce';

	/**
	 * Outcomes `Admin\Menu::dispatch()` understands.
	 */
	const OUTCOME_DENIED   = 'denied';
	const OUTCOME_REDIRECT = 'redirect';
	const OUTCOME_REFUSED  = 'refused';

	/**
	 * The suffix appended to a duplicated rule's name.
	 *
	 * @return string
	 */
	public static function copy_suffix(): string {
		return __( '(copy)', 'extonify-custom-emails-per-product' );
	}

	/**
	 * Actions that change state, and therefore need a nonce.
	 *
	 * @param string $action Requested action.
	 * @return bool
	 */
	public static function is_write_action( string $action ): bool {
		return in_array( $action, self::write_actions(), true );
	}

	/**
	 * Every state-changing action, enumerated.
	 *
	 * ⚠ THE GATE-28 TABLE IS DERIVED FROM THIS LIST, and the test that walks it walks
	 * this constant — so an action added without a capability test, a nonce test and a
	 * table row fails the gate rather than shipping unnoticed.
	 *
	 * @return string[]
	 */
	public static function write_actions(): array {
		return array(
			self::ACTION_SAVE,
			self::ACTION_DELETE,
			self::ACTION_DUPLICATE,
			self::ACTION_ACTIVATE,
			self::ACTION_DEACTIVATE,
		);
	}

	/**
	 * The nonce action one row action uses for one rule.
	 *
	 * ⚠ ACTION-SPECIFIC **AND** RULE-SPECIFIC. A single shared nonce would let a
	 * "disable rule 4" link, once leaked into a referrer or a screenshot, be replayed
	 * as "delete rule 9" — the nonce would verify, because it would be the same nonce.
	 *
	 * @param string $action  Row action.
	 * @param int    $rule_id Rule id.
	 * @return string
	 */
	public static function row_nonce_action( string $action, int $rule_id ): string {
		return 'extonify_wcep_' . $action . '_rule_' . $rule_id;
	}

	/**
	 * Dispatch one state-changing request.
	 *
	 * @param string $action  Requested action, already sanitised.
	 * @param array  $post    Raw `$_POST`.
	 * @param array  $get     Raw `$_GET`.
	 * @return array Outcome.
	 */
	public static function handle( string $action, array $post, array $get ): array {
		// ⚠ THE CAPABILITY FIRST, ON EVERY HANDLER, TRUSTING NOTHING UPSTREAM.
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			return self::denied( __( 'You are not allowed to manage custom product emails.', 'extonify-custom-emails-per-product' ) );
		}

		if ( self::ACTION_SAVE === $action ) {
			return self::save( $post );
		}

		if ( ! self::is_write_action( $action ) ) {
			return self::denied( __( 'Unknown action.', 'extonify-custom-emails-per-product' ) );
		}

		return self::row_action( $action, $get );
	}

	/**
	 * Save one rule.
	 *
	 * @param array $post Raw `$_POST`.
	 * @return array Outcome.
	 */
	private static function save( array $post ): array {
		$nonce = isset( $post[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $post[ self::NONCE_FIELD ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_SAVE ) ) {
			return self::denied( __( 'This page has expired. Reload it and try again.', 'extonify-custom-emails-per-product' ) );
		}

		$rule_id = isset( $post['rule'] ) ? max( 0, (int) wp_unslash( $post['rule'] ) ) : 0;
		$form    = RuleFormInput::from_post( $post );
		$rules   = Plugin::instance()->rules();

		$existing = $rule_id > 0 ? $rules->find( $rule_id ) : null;

		if ( $rule_id > 0 && null === $existing ) {
			return self::denied( __( 'That rule no longer exists.', 'extonify-custom-emails-per-product' ) );
		}

		// The editor's OWN checks — a missing name, a negative delay — which the
		// storage layer has no opinion about. They never reach the repository.
		$errors = $form->errors();

		if ( array() !== $errors ) {
			return self::refused( $form, $rule_id, $errors );
		}

		$data = $form->to_write();

		/*
		 * ⚠ THE REPOSITORY'S RETURN VALUE IS THE OUTCOME (ADR-0017 §3.1), and
		 * `explain_refusal()` is asked only AFTER it has refused. Asking first and
		 * skipping the write would make this layer the authority, which is exactly the
		 * duplication ADR-0017 §4 forbids — and it would be wrong the moment the two
		 * disagreed, in whichever direction.
		 */
		if ( null === $existing ) {
			$written = $rules->insert( $data );
			$ok      = $written > 0;
			$rule_id = $ok ? $written : 0;
		} else {
			$ok = $rules->update( $rule_id, $data );
		}

		if ( ! $ok ) {
			return self::refused(
				$form,
				$rule_id,
				self::refusal_errors( $data, is_array( $existing ) ? $existing : array() )
			);
		}

		return self::redirect(
			Menu::url(
				array(
					'action'  => Menu::ACTION_EDIT,
					'rule'    => $rule_id,
					'message' => null === $existing ? 'created' : 'saved',
				)
			)
		);
	}

	/**
	 * Turn a repository refusal into a field-addressed error map.
	 *
	 * ⚠ A REFUSAL THE REPOSITORY CANNOT ACCOUNT FOR IS STILL A REFUSAL (ADR-0017 §3).
	 * A genuine `$wpdb` failure leaves `explain_refusal()` empty; the outcome does not
	 * soften, only the explanation degrades to a generic one — and there is still no
	 * success notice and still no redirect.
	 *
	 * @param array $data     The write that was refused.
	 * @param array $existing The stored row, or empty for an insert.
	 * @return array<string,string> Field => refusal code.
	 */
	private static function refusal_errors( array $data, array $existing ): array {
		$refusal = RuleRepository::explain_refusal( $data, $existing );

		if ( array() === $refusal ) {
			return array( '' => 'storage' );
		}

		return array( (string) $refusal['field'] => (string) $refusal['code'] );
	}

	/**
	 * Delete, duplicate, activate or deactivate one rule.
	 *
	 * @param string $action Row action.
	 * @param array  $get    Raw `$_GET`.
	 * @return array Outcome.
	 */
	private static function row_action( string $action, array $get ): array {
		$rule_id = isset( $get['rule'] ) ? max( 0, (int) wp_unslash( $get['rule'] ) ) : 0;
		$nonce   = isset( $get['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $get['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::row_nonce_action( $action, $rule_id ) ) ) {
			return self::denied( __( 'This link has expired. Reload the list and try again.', 'extonify-custom-emails-per-product' ) );
		}

		$rules = Plugin::instance()->rules();
		$rule  = $rule_id > 0 ? $rules->find( $rule_id ) : null;

		if ( null === $rule ) {
			return self::redirect( Menu::url( array( 'message' => 'missing' ) ) );
		}

		if ( self::ACTION_DELETE === $action ) {
			/*
			 * ⚠ THE UNSCHEDULE HAPPENS INSIDE `delete()`, NOT HERE (ADR-0017 §10).
			 * `Delivery\ScheduledCancellation` subscribes to the repository's
			 * `extonify_wcep_rule_deleted` action, which fires AFTER the row is gone so a
			 * subscriber re-reading the rule finds it missing and cancels rather than
			 * deciding it is still schedulable. A second cancellation path in the admin
			 * layer would be a second thing to keep correct.
			 */
			$ok = $rules->delete( $rule_id );

			return self::redirect( Menu::url( array( 'message' => $ok ? 'deleted' : 'delete_failed' ) ) );
		}

		if ( self::ACTION_DUPLICATE === $action ) {
			return self::duplicate( $rule );
		}

		$status = self::ACTION_ACTIVATE === $action ? 'active' : 'inactive';

		$ok = $rules->update( $rule_id, array( 'status' => $status ) );

		if ( ! $ok ) {
			/*
			 * ⚠ A STATUS TOGGLE CAN BE REFUSED, AND IT IS NOT A CONTRADICTION.
			 * `update()` re-validates the row it RESULTS IN (ADR-0016 §1a), so a legacy
			 * row holding an invalid `consolidation` refuses every write until the
			 * merchant corrects it. Sending them to the editor is the only place they
			 * can, and it is where the specific reason is shown.
			 */
			return self::redirect(
				Menu::url(
					array(
						'action'  => Menu::ACTION_EDIT,
						'rule'    => $rule_id,
						'message' => 'toggle_failed',
					)
				)
			);
		}

		return self::redirect( Menu::url( array( 'message' => 'active' === $status ? 'activated' : 'deactivated' ) ) );
	}

	/**
	 * Copy one rule as a new, INACTIVE rule (ADR-0017 §11).
	 *
	 * ⚠ INACTIVE IS NOT A COURTESY. An active duplicate of an active rule immediately
	 * doubles that rule's mail for every order the original matches, before the
	 * merchant has seen the copy — let alone edited the one field they duplicated it
	 * to change.
	 *
	 * ⚠ THE COPY CARRIES NO DELIVERY HISTORY, and gets that property for free rather
	 * than by clearing anything: history is keyed by rule id (ADR-0004), and the copy
	 * has a new id no delivery has ever named.
	 *
	 * @param array $rule Hydrated source rule.
	 * @return array Outcome.
	 */
	private static function duplicate( array $rule ): array {
		$rules = Plugin::instance()->rules();

		$data = array(
			'name'            => trim( (string) ( $rule['name'] ?? '' ) . ' ' . self::copy_suffix() ),
			'status'          => 'inactive',
			'priority'        => (int) ( $rule['priority'] ?? 10 ),
			'trigger_type'    => (string) ( $rule['trigger_type'] ?? 'status' ),
			'trigger_value'   => (string) ( $rule['trigger_value'] ?? '' ),
			'delivery_mode'   => (string) ( $rule['delivery_mode'] ?? 'separate' ),
			'native_email_id' => (string) ( $rule['native_email_id'] ?? '' ),
			'insert_position' => (string) ( $rule['insert_position'] ?? '' ),
			'targeting'       => (array) ( $rule['targeting'] ?? array() ),
			'recipients'      => (array) ( $rule['recipients'] ?? array() ),
			'subject'         => (string) ( $rule['subject'] ?? '' ),
			'heading'         => (string) ( $rule['heading'] ?? '' ),
			'content'         => (string) ( $rule['content'] ?? '' ),
			'delay_seconds'   => (int) ( $rule['delay_seconds'] ?? 0 ),
			'consolidation'   => (string) ( $rule['consolidation'] ?? 'none' ),
			'stop_processing' => ! empty( $rule['stop_processing'] ) ? 1 : 0,
		);

		/*
		 * ⚠ AN INSERT RULE IS COPIED WITH ITS TRIGGER COLUMNS AS STORED — EMPTY
		 * (ADR-0013 §2) — and the repository forces them empty again on the way in, so
		 * the copy cannot acquire a trigger the engine never consults.
		 */
		$new_id = $rules->insert( $data );

		if ( $new_id <= 0 ) {
			// A legacy row the current vocabulary refuses cannot be copied, and saying
			// "duplicated" would leave the merchant looking for a rule that is not there.
			return self::redirect( Menu::url( array( 'message' => 'duplicate_failed' ) ) );
		}

		return self::redirect(
			Menu::url(
				array(
					'action'  => Menu::ACTION_EDIT,
					'rule'    => $new_id,
					'message' => 'duplicated',
				)
			)
		);
	}

	/**
	 * A refused request.
	 *
	 * @param string $message Translated, unescaped reason.
	 * @return array
	 */
	private static function denied( string $message ): array {
		return array(
			'outcome' => self::OUTCOME_DENIED,
			'message' => $message,
		);
	}

	/**
	 * A successful request, to be followed by a redirect.
	 *
	 * @param string $url Destination.
	 * @return array
	 */
	private static function redirect( string $url ): array {
		return array(
			'outcome' => self::OUTCOME_REDIRECT,
			'url'     => $url,
		);
	}

	/**
	 * A refused write: the form comes back intact, with the field named.
	 *
	 * @param RuleFormInput        $form    The submitted form.
	 * @param int                  $rule_id Rule id, or 0 for a new rule.
	 * @param array<string,string> $errors  Field => code.
	 * @return array
	 */
	private static function refused( RuleFormInput $form, int $rule_id, array $errors ): array {
		return array(
			'outcome' => self::OUTCOME_REFUSED,
			'form'    => $form,
			'rule_id' => $rule_id,
			'errors'  => $errors,
		);
	}
}
