<?php
/**
 * The vocabularies every editor control offers (ADR-0017 §4).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

use Extonify\WCEP\Delivery\Consolidation;
use Extonify\WCEP\Domain\Recipient;
use Extonify\WCEP\Domain\Targeting;
use Extonify\WCEP\Domain\TriggerEvent;
use Extonify\WCEP\Matching\OrderStatuses;
use Extonify\WCEP\Render\Injector;
use Extonify\WCEP\Repository\DeliveryDetailRepository;
use Extonify\WCEP\Repository\DeliveryRepository;
use Extonify\WCEP\Repository\RuleRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Every option list the editor renders, read from the frozen constants.
 *
 * ⚠ NOT ONE VOCABULARY IS SPELLED OUT HERE, AND THAT IS THE POINT (ADR-0017 §4).
 * A hard-coded duplicate of `STATUSES`, `DELIVERY_MODES` or `Consolidation::MODES`
 * in the UI is a defect waiting for the next enumeration change: it renders a
 * control for a value the repository refuses, or hides one the repository accepts,
 * and either way the merchant is arguing with a screen rather than with a rule.
 * Every `values()` here reads the constant the storage layer validates against, and
 * the suite asserts the rendered option set EQUALS that constant — `AdminOutputTest`
 * for the editor's vocabularies, `DeliveryHistoryTest` for the delivery ones.
 * (This docblock previously named an `AdminVocabularyTest` that has never existed;
 * corrected in Prompt 10 rather than left pointing at a file nobody can open.)
 *
 * LABELS ARE THIS LAYER'S OWN, deliberately. A vocabulary is data; its human name is
 * presentation, it is translatable, and it has no business in a domain constant. A
 * value with no label falls back to the raw value rather than disappearing — so
 * growing an enumeration without touching this file yields an ugly option, never a
 * missing one.
 */
final class FieldOptions {

	/**
	 * Rule statuses => label.
	 *
	 * @return array<string,string>
	 */
	public static function statuses(): array {
		return self::label( RuleRepository::STATUSES, self::status_labels() );
	}

	/**
	 * Delivery modes => label.
	 *
	 * @return array<string,string>
	 */
	public static function delivery_modes(): array {
		return self::label(
			RuleRepository::DELIVERY_MODES,
			array(
				'separate' => __( 'Send as a separate email', 'extonify-custom-emails-per-product' ),
				'insert'   => __( 'Insert into a WooCommerce email', 'extonify-custom-emails-per-product' ),
			)
		);
	}

	/**
	 * Trigger types => label.
	 *
	 * @return array<string,string>
	 */
	public static function trigger_types(): array {
		return self::label(
			RuleRepository::TRIGGER_TYPES,
			array(
				TriggerEvent::TYPE_STATUS     => __( 'Order reaches a status', 'extonify-custom-emails-per-product' ),
				TriggerEvent::TYPE_TRANSITION => __( 'Order moves between two statuses', 'extonify-custom-emails-per-product' ),
				TriggerEvent::TYPE_REFUND     => __( 'Order is refunded', 'extonify-custom-emails-per-product' ),
			)
		);
	}

	/**
	 * Consolidation modes => label.
	 *
	 * @return array<string,string>
	 */
	public static function consolidation_modes(): array {
		return self::label(
			Consolidation::MODES,
			array(
				Consolidation::NONE        => __( 'One email for the whole order', 'extonify-custom-emails-per-product' ),
				Consolidation::PER_PRODUCT => __( 'One email per matched product', 'extonify-custom-emails-per-product' ),
			)
		);
	}

	/**
	 * Insert positions => label.
	 *
	 * @return array<string,string>
	 */
	public static function insert_positions(): array {
		return self::label(
			array_keys( Injector::POSITIONS ),
			array(
				'before_order_table' => __( 'Before the order table', 'extonify-custom-emails-per-product' ),
				'after_order_table'  => __( 'After the order table', 'extonify-custom-emails-per-product' ),
				'order_meta'         => __( 'With the order details', 'extonify-custom-emails-per-product' ),
				'customer_details'   => __( 'With the customer details', 'extonify-custom-emails-per-product' ),
				'item_meta'          => __( 'Beside each matched product', 'extonify-custom-emails-per-product' ),
			)
		);
	}

	/**
	 * Order statuses this store actually has, slug => human name.
	 *
	 * READ FROM WooCommerce AND NORMALISED THE WAY STORAGE NORMALISES, through
	 * `Matching\OrderStatuses::all()`. The block-checkout draft status is excluded
	 * there, explicitly rather than by omission (ADR-0011 §1) — offering it would
	 * offer a trigger that can never fire.
	 *
	 * @return array<string,string>
	 */
	public static function order_statuses(): array {
		$names = array();

		if ( function_exists( 'wc_get_order_statuses' ) ) {
			foreach ( (array) wc_get_order_statuses() as $raw => $name ) {
				$names[ TriggerEvent::normalize_status( (string) $raw ) ] = (string) $name;
			}
		}

		$out = array();

		foreach ( OrderStatuses::all() as $slug ) {
			$out[ $slug ] = $names[ $slug ] ?? $slug;
		}

		return $out;
	}

	/**
	 * The WooCommerce emails an insert rule may target, id => title.
	 *
	 * ⚠ CAPABILITY-DETECTED, AND AN EMPTY LIST IS NOT AN ERROR — the same posture
	 * `RuleRepository::native_email_is_registered()` takes, and for the same reason:
	 * the mailer is not always available, and refusing to render a control because
	 * WooCommerce had not booted yet would be a worse failure than an empty select
	 * whose stored value is preserved.
	 *
	 * @return array<string,string>
	 */
	public static function native_emails(): array {
		if ( ! function_exists( 'WC' ) || ! is_object( WC()->mailer() ) ) {
			return array();
		}

		$out = array();

		foreach ( (array) WC()->mailer()->get_emails() as $email ) {
			if ( ! is_object( $email ) || ! isset( $email->id ) ) {
				continue;
			}

			$id = (string) $email->id;

			if ( '' === $id ) {
				continue;
			}

			$title = is_callable( array( $email, 'get_title' ) ) ? (string) $email->get_title() : $id;

			$out[ $id ] = '' !== $title ? $title : $id;
		}

		ksort( $out );

		return $out;
	}

	/**
	 * The `types` targeting vocabulary, slug => label.
	 *
	 * ⚠ TYPE SLUGS AND BOOLEAN FLAGS IN ONE LIST, DELIBERATELY (ADR-0011 §3). They
	 * are not the same kind of thing — a product has exactly one type but may carry
	 * both flags — and the core slugs are NOT a closed allowlist, so a custom product
	 * type registered by another plugin appears here unchanged.
	 *
	 * @return array<string,string>
	 */
	public static function product_types(): array {
		$out = array();

		if ( function_exists( 'wc_get_product_types' ) ) {
			foreach ( (array) wc_get_product_types() as $slug => $label ) {
				$slug = (string) $slug;

				if ( '' !== $slug ) {
					$out[ $slug ] = (string) $label;
				}
			}
		}

		foreach ( Targeting::FLAG_TYPES as $flag ) {
			$out[ $flag ] = 'virtual' === $flag
				? __( 'Virtual (any type)', 'extonify-custom-emails-per-product' )
				: __( 'Downloadable (any type)', 'extonify-custom-emails-per-product' );
		}

		return $out;
	}

	/**
	 * Targeting kinds => label, in `Targeting::KINDS` order.
	 *
	 * @return array<string,string>
	 */
	public static function targeting_kinds(): array {
		return self::label(
			Targeting::KINDS,
			array(
				'variations' => __( 'Variations', 'extonify-custom-emails-per-product' ),
				'products'   => __( 'Products', 'extonify-custom-emails-per-product' ),
				'categories' => __( 'Categories', 'extonify-custom-emails-per-product' ),
				'tags'       => __( 'Tags', 'extonify-custom-emails-per-product' ),
				'types'      => __( 'Product types', 'extonify-custom-emails-per-product' ),
			)
		);
	}

	/**
	 * Recipient channels => label.
	 *
	 * @return array<string,string>
	 */
	public static function recipient_channels(): array {
		return array(
			'to'  => __( 'To', 'extonify-custom-emails-per-product' ),
			'cc'  => __( 'Cc', 'extonify-custom-emails-per-product' ),
			'bcc' => __( 'Bcc', 'extonify-custom-emails-per-product' ),
		);
	}

	/**
	 * Delivery statuses => label (ADR-0018 §2).
	 *
	 * Read from `DeliveryRepository::FINAL_STATUSES`, the same constant
	 * `set_final_status()` validates against, for the reason the class docblock
	 * gives: a hard-coded duplicate renders a filter for a status the plugin never
	 * writes, or hides one it does.
	 *
	 * ⚠ THREE OF THESE LABELS ARE THE WHOLE POINT OF THE VOCABULARY AND MUST NOT BE
	 * COLLAPSED. `failed` means WooCommerce reported the send did not work;
	 * `unresolved` means a send happened and NOTHING was reported back; `abandoned`
	 * means no send happened at all (ADR-0013 §6a). A merchant reading "Failed" for
	 * the second is being told something untrue about an email that may well have
	 * arrived, and a resend feature would read it as "retry this".
	 *
	 * @return array<string,string>
	 */
	public static function delivery_statuses(): array {
		return self::label(
			DeliveryRepository::FINAL_STATUSES,
			array(
				'claimed'                            => __( 'In progress', 'extonify-custom-emails-per-product' ),
				DeliveryRepository::SCHEDULED        => __( 'Scheduled', 'extonify-custom-emails-per-product' ),
				DeliveryRepository::EXECUTING        => __( 'Sending now', 'extonify-custom-emails-per-product' ),
				'sent'                               => __( 'Sent', 'extonify-custom-emails-per-product' ),
				'failed'                             => __( 'Failed', 'extonify-custom-emails-per-product' ),
				'cancelled'                          => __( 'Cancelled', 'extonify-custom-emails-per-product' ),
				'skipped'                            => __( 'Skipped', 'extonify-custom-emails-per-product' ),
				DeliveryDetailRepository::UNRESOLVED => __( 'Outcome unknown', 'extonify-custom-emails-per-product' ),
				DeliveryDetailRepository::ABANDONED  => __( 'Not sent', 'extonify-custom-emails-per-product' ),
			)
		);
	}

	/**
	 * Attempt states => label.
	 *
	 * @return array<string,string>
	 */
	public static function attempt_states(): array {
		return self::label(
			DeliveryDetailRepository::STATES,
			array(
				'scheduled'                          => __( 'Scheduled', 'extonify-custom-emails-per-product' ),
				'sent'                               => __( 'Sent', 'extonify-custom-emails-per-product' ),
				'failed'                             => __( 'Failed', 'extonify-custom-emails-per-product' ),
				'cancelled'                          => __( 'Cancelled', 'extonify-custom-emails-per-product' ),
				'skipped'                            => __( 'Skipped', 'extonify-custom-emails-per-product' ),
				'suppressed'                         => __( 'Suppressed as a duplicate', 'extonify-custom-emails-per-product' ),
				DeliveryDetailRepository::UNRESOLVED => __( 'Outcome unknown', 'extonify-custom-emails-per-product' ),
				DeliveryDetailRepository::ABANDONED  => __( 'Not sent', 'extonify-custom-emails-per-product' ),
			)
		);
	}

	/**
	 * Attempt types => label.
	 *
	 * @return array<string,string>
	 */
	public static function attempt_types(): array {
		return self::label(
			DeliveryDetailRepository::TYPES,
			array(
				'auto'   => __( 'Automatic', 'extonify-custom-emails-per-product' ),
				'manual' => __( 'Sent by hand', 'extonify-custom-emails-per-product' ),
				'resend' => __( 'Resend', 'extonify-custom-emails-per-product' ),
				'test'   => __( 'Test', 'extonify-custom-emails-per-product' ),
				'debug'  => __( 'Debug', 'extonify-custom-emails-per-product' ),
			)
		);
	}

	/**
	 * Recipient types => label. The stored per-row form of self::recipient_channels().
	 *
	 * @return array<string,string>
	 */
	public static function recipient_types(): array {
		return self::label( Recipient::TYPES, self::recipient_channels() );
	}

	/**
	 * Delay units => seconds in one unit.
	 *
	 * @return array<string,int>
	 */
	public static function delay_units(): array {
		return array(
			'minutes' => MINUTE_IN_SECONDS,
			'hours'   => HOUR_IN_SECONDS,
			'days'    => DAY_IN_SECONDS,
		);
	}

	/**
	 * Delay units => label.
	 *
	 * @return array<string,string>
	 */
	public static function delay_unit_labels(): array {
		return array(
			'minutes' => __( 'minutes', 'extonify-custom-emails-per-product' ),
			'hours'   => __( 'hours', 'extonify-custom-emails-per-product' ),
			'days'    => __( 'days', 'extonify-custom-emails-per-product' ),
		);
	}

	/**
	 * Statuses with their labels, kept apart so the list filter and the editor
	 * cannot name the same status differently.
	 *
	 * @return array<string,string>
	 */
	private static function status_labels(): array {
		return array(
			'active'   => __( 'Active', 'extonify-custom-emails-per-product' ),
			'inactive' => __( 'Inactive', 'extonify-custom-emails-per-product' ),
		);
	}

	/**
	 * Attach labels to a frozen value list, preserving its order.
	 *
	 * A value with no label falls back to itself, so growing an enumeration without
	 * touching this file produces an unlabelled option rather than a missing one.
	 *
	 * @param string[]             $values Values, from the frozen constant.
	 * @param array<string,string> $labels Value => translated label.
	 * @return array<string,string>
	 */
	private static function label( array $values, array $labels ): array {
		$out = array();

		foreach ( $values as $value ) {
			$value         = (string) $value;
			$out[ $value ] = $labels[ $value ] ?? $value;
		}

		return $out;
	}
}
