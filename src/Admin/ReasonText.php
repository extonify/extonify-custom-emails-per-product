<?php
/**
 * Read-time translation for the cancellation reasons recorded on a delivery.
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

use Extonify\WCEP\Delivery\ScheduledDelivery;

defined( 'ABSPATH' ) || exit;

/**
 * The merchant-facing sentence for a stored cancellation reason CODE.
 *
 * ⚠ WHY THIS IS A SECOND COPY OF SENTENCES THAT ALREADY EXIST, AND NOT A REFACTOR OF
 * THE FIRST. `ScheduledDelivery::REASON_TEXT` holds the same sentences untranslated,
 * and it must keep holding them: that copy is written into the `reason` column of a
 * delivery detail row at the moment of cancellation, and a PERSISTED AUDIT RECORD MUST
 * NOT CARRY A TRANSLATION. Wrapping the stored copy in `__()` would freeze whichever
 * locale happened to be active on the request that cancelled the delivery — a cron
 * worker, an admin in another language, a REST call — into a row that outlives it, and
 * a history whose rows are each in a different language is worse than one that is
 * uniformly English.
 *
 * So the two copies do different jobs:
 *
 *   ScheduledDelivery::REASON_TEXT   what is WRITTEN, once, in English, forever
 *   Admin\ReasonText::for_code()     what is SHOWN, per request, in the reader's locale
 *
 * The bridge between them is the reason CODE, which
 * `DeliveryLogger::record_scheduled_cancellation()` already persists in the detail
 * row's `snapshot` — `snapshot.cancelled.reason_code`. No schema change was needed to
 * read it; `DeliveryDetailRepository::hydrate()` has always decoded that column.
 *
 * ⚠ `self::for_code()` RETURNS '' RATHER THAN GUESSING. A row written before the code
 * was recorded, or one whose snapshot was erased by the privacy eraser, has no code to
 * key on. The caller falls back to the stored English sentence, which is the honest
 * outcome: an untranslated true sentence beats a translated wrong one.
 */
class ReasonText {

	/**
	 * Every cancellation code this plugin records, in the reader's locale.
	 *
	 * ⚠ KEYED ON THE CONSTANT, NOT ON A LITERAL. A code renamed in
	 * `ScheduledDelivery` breaks this array at load time rather than silently
	 * falling through to the untranslated copy, and `ReasonTextTest` asserts the
	 * two sets are equal in both directions.
	 *
	 * @return array<string,string> code => translated sentence.
	 */
	public static function map(): array {
		return array(
			ScheduledDelivery::REASON_RULE_DELETED       => __( 'the rule was deleted during the delay, so there is nothing left to send', 'extonify-custom-emails-per-product' ),
			ScheduledDelivery::REASON_RULE_DISABLED      => __( 'the rule was disabled during the delay', 'extonify-custom-emails-per-product' ),
			ScheduledDelivery::REASON_RULE_LEFT_PHASE    => __( 'the rule no longer belongs to the delayed separate-mode phase (its mode, delay or consolidation changed during the delay)', 'extonify-custom-emails-per-product' ),
			ScheduledDelivery::REASON_ORDER_DELETED      => __( 'the order no longer exists, or was moved to the trash, during the delay', 'extonify-custom-emails-per-product' ),
			ScheduledDelivery::REASON_ORDER_STATE        => __( 'the order was cancelled, failed or fully refunded during the delay', 'extonify-custom-emails-per-product' ),
			ScheduledDelivery::REASON_ITEMS_REFUNDED     => __( 'every matched line item was removed or fully refunded during the delay', 'extonify-custom-emails-per-product' ),
			ScheduledDelivery::REASON_NO_SNAPSHOT        => __( 'the stored snapshot could not be read, so the message this delivery would send is unknown', 'extonify-custom-emails-per-product' ),
			ScheduledDelivery::REASON_CONSOLIDATION_INVALID => __( 'the rule\'s consolidation setting is not one this plugin recognises, so it was not delivered; set it to "none" or "per_product"', 'extonify-custom-emails-per-product' ),
			ScheduledDelivery::REASON_SCHEMA_UNAVAILABLE => __( 'this plugin\'s database tables were unavailable when the delayed delivery came due, and it could not be re-queued', 'extonify-custom-emails-per-product' ),
			ScheduledDelivery::REASON_ARGS_MISMATCH      => __( 'the queued job named a different order from the delivery record it points at, so neither was trusted', 'extonify-custom-emails-per-product' ),
			ScheduledDelivery::REASON_EMAIL_UNAVAILABLE  => __( 'the custom email class was not registered with WooCommerce when the delayed delivery came due', 'extonify-custom-emails-per-product' ),
			ScheduledDelivery::REASON_INOPERATIVE        => __( 'delivery was not operational when the delayed delivery came due', 'extonify-custom-emails-per-product' ),
			ScheduledDelivery::REASON_ARM_FAILED         => __( 'this delayed delivery could not be armed for sending, so nothing was queued', 'extonify-custom-emails-per-product' ),
			ScheduledDelivery::REASON_LEASE_UNWRITABLE   => __( 'the execution lease for this delayed delivery could not be written and it could not be re-queued, so no worker was able to take it', 'extonify-custom-emails-per-product' ),
			ScheduledDelivery::REASON_MERCHANT_CANCELLED => __( 'a store administrator cancelled this delivery before it was sent', 'extonify-custom-emails-per-product' ),
			ScheduledDelivery::REASON_LEASE_EXPIRED      => __( 'the worker running this delayed delivery never reported back, so whether the message was sent is not known', 'extonify-custom-emails-per-product' ),
			ScheduledDelivery::REASON_ORPHANED           => __( 'the queued job for this delayed delivery no longer exists and it could not be re-queued', 'extonify-custom-emails-per-product' ),
			ScheduledDelivery::REASON_PLUGIN_DEACTIVATED => __( 'the plugin was deactivated while this delayed delivery was still queued', 'extonify-custom-emails-per-product' ),
			ScheduledDelivery::REASON_SHUTDOWN_INTERRUPT => __( 'the plugin was shut down while a worker was running this delayed delivery, so whether the message was sent is not known', 'extonify-custom-emails-per-product' ),
		);
	}

	/**
	 * The translated sentence for one stored code, or '' when there is none.
	 *
	 * @param string $code A `ScheduledDelivery::REASON_*` value.
	 * @return string Translated sentence, or '' to fall back to the stored text.
	 */
	public static function for_code( string $code ): string {
		if ( '' === $code ) {
			return '';
		}

		$map = self::map();

		return isset( $map[ $code ] ) ? $map[ $code ] : '';
	}

	/**
	 * The cancellation code recorded on one detail row, or '' when absent.
	 *
	 * ⚠ THE SNAPSHOT IS A PERSONAL FIELD AND MAY BE GONE. `snapshot` sits in
	 * `DeliveryDetailRepository::PERSONAL_FIELDS`, so the privacy eraser removes it
	 * alongside `reason` itself. Both vanish together, so a row that has lost its code
	 * has also lost the sentence it would have keyed — there is nothing to translate and
	 * nothing is claimed.
	 *
	 * @param array $detail One hydrated detail row.
	 * @return string The stored code, or ''.
	 */
	public static function code_for( array $detail ): string {
		$snapshot = $detail['snapshot'] ?? null;

		if ( ! is_array( $snapshot ) ) {
			return '';
		}

		$cancelled = $snapshot['cancelled'] ?? null;

		if ( ! is_array( $cancelled ) ) {
			return '';
		}

		$code = $cancelled['reason_code'] ?? '';

		return is_string( $code ) ? $code : '';
	}
}
