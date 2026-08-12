<?php
/**
 * The delivery-history list table (ADR-0018 §1, §5, §7).
 *
 * @package Extonify\WCEP
 */

namespace Extonify\WCEP\Admin;

use Extonify\WCEP\Plugin;
use Extonify\WCEP\Repository\DeliveryRepository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Every delivery in the store, newest first.
 *
 * ⚠ `WP_List_Table` IS `@access private` AND ADR-0017 §7 ACCEPTED THAT EXPLICITLY.
 * The reasoning is unchanged here and is not re-litigated: only the
 * documented-by-convention surface is used, and a hand-rolled table would
 * re-implement pagination and URL state that nobody else's install exercises.
 *
 * ⚠ NO BULK ACTIONS AND NO ROW ACTIONS IN THIS PROMPT (ADR-0018 §9). This surface
 * is read-only: manual send, resend and cancel are Prompt 11. Where they will go
 * there is EMPTY SPACE, not a disabled control — ADR-0001 forbids anything that
 * reads as a locked feature in a free plugin.
 *
 * ⚠ EVERY CELL IS ESCAPED BY `Admin\DeliveryPresenter`, WHICH IS ALSO WHAT THE
 * ORDER PANEL USES. There is one copy of every history string and one copy of the
 * purged/redacted wording, because the failure mode of two copies is that one of
 * them drifts into saying something untrue (ADR-0018 §1).
 */
final class DeliveriesListTable extends \WP_List_Table {

	/**
	 * Filter query arguments.
	 *
	 * Prefixed, because this table shares its screen's query string with WordPress's
	 * own paging and search arguments and an unprefixed `order` would collide with
	 * `WP_List_Table`'s sort direction.
	 */
	const ARG_ORDER  = 'wcep_order';
	const ARG_RULE   = 'wcep_rule';
	const ARG_STATUS = 'wcep_status';
	const ARG_MODE   = 'wcep_mode';
	const ARG_FROM   = 'wcep_from';
	const ARG_TO     = 'wcep_to';

	/**
	 * How many rules the rule filter offers before it stops being a usable control.
	 *
	 * The dropdown is ONE statement whatever this is; the bound is about the merchant,
	 * not the database.
	 */
	const RULE_OPTIONS_LIMIT = 200;

	/**
	 * Attempt rows for this page, keyed by delivery id.
	 *
	 * @var array<int,array[]>
	 */
	private $details = array();

	/**
	 * Rule id => name, for this page.
	 *
	 * @var array<int,string>
	 */
	private $rules = array();

	/**
	 * How many deliveries match the current filters.
	 *
	 * @var int
	 */
	private $total = 0;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'extonify_wcep_delivery',
				'plural'   => 'extonify_wcep_deliveries',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns, in order.
	 *
	 * ⚠ `identity_hash` IS ABSENT AND STAYS ABSENT (ADR-0018 §2). It is an internal
	 * correlation key, it means nothing to a merchant, and ADR-0010 already excluded
	 * it from the privacy exporter for the same reason.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'order'    => __( 'Order', 'extonify-custom-emails-per-product' ),
			'rule'     => __( 'Rule', 'extonify-custom-emails-per-product' ),
			'status'   => __( 'Status', 'extonify-custom-emails-per-product' ),
			'when'     => __( 'When', 'extonify-custom-emails-per-product' ),
			'attempts' => __( 'Attempts', 'extonify-custom-emails-per-product' ),
			'actions'  => __( 'Actions', 'extonify-custom-emails-per-product' ),
		);
	}

	/**
	 * The actions cell (ADR-0019 §6).
	 *
	 * @param array $item Tombstone row.
	 * @return string
	 */
	public function column_actions( $item ) {
		return DeliveryPresenter::actions_cell( (array) $item );
	}

	/**
	 * No sortable columns (ADR-0018 §5).
	 *
	 * The ordering is fixed at newest-first with a primary-key tie-break, which is
	 * what makes paging deterministic. Offering a sort would put a request-supplied
	 * identifier into an `ORDER BY` that `prepare()` cannot bind, for a control this
	 * screen does not need.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array();
	}

	/**
	 * No bulk actions (ADR-0018 §9).
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array();
	}

	/**
	 * The empty state.
	 *
	 * ⚠ "NOTHING MATCHES THESE FILTERS" AND "NOTHING HAS EVER BEEN SENT" ARE DIFFERENT
	 * FACTS (ADR-0018 §4c). A merchant who has just filtered to a rule that never fired
	 * is not looking at a broken plugin, and one who has never had a delivery is not
	 * looking at a filter problem.
	 *
	 * @return void
	 */
	public function no_items() {
		if ( self::is_filtered() ) {
			esc_html_e( 'No deliveries match these filters.', 'extonify-custom-emails-per-product' );
			return;
		}

		esc_html_e( 'No custom product emails have been delivered yet. Deliveries appear here as soon as a rule sends one.', 'extonify-custom-emails-per-product' );
	}

	/**
	 * Fetch the page (ADR-0018 §7).
	 *
	 * ⚠ FOUR STATEMENTS, CONSTANT IN THE PAGE SIZE: the pager count, the page of
	 * tombstones, EVERY attempt row for those tombstones in one batch, and EVERY rule
	 * name for those rules in one batch. Resolving either inside a column callback
	 * would be one statement per row — twenty per page today and a number that grows
	 * the moment somebody raises the page size, which is exactly the shape that works
	 * perfectly on a developer's twelve deliveries.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$args = self::query_args( $this->get_pagenum() );

		$deliveries  = DeliveryPresenter::deliveries();
		$this->total = $deliveries->count_matching( $args );
		$this->items = $deliveries->query( $args );

		$ids   = array();
		$rules = array();

		foreach ( $this->items as $delivery ) {
			$ids[]   = (int) $delivery['id'];
			$rules[] = (int) $delivery['rule_id'];
		}

		$this->details = Plugin::instance()->delivery_details()->find_for_deliveries( $ids );
		$this->rules   = DeliveryPresenter::rule_names( $rules );

		$this->set_pagination_args(
			array(
				'total_items' => $this->total,
				'per_page'    => DeliveryRepository::HISTORY_PER_PAGE,
				'total_pages' => (int) ceil( $this->total / DeliveryRepository::HISTORY_PER_PAGE ),
			)
		);
	}

	/**
	 * The repository arguments this request asks for.
	 *
	 * ⚠ EVERY ONE IS SANITISED AND NONE IS TRUSTED. The ids are cast to `int`, the
	 * vocabularies are bound as values against columns the storage layer already
	 * validates — so an unrecognised value matches nothing rather than being repaired
	 * into something that matches everything — and the dates are converted from the
	 * merchant's local day to the UTC bounds the columns actually hold.
	 *
	 * @param int $page 1-based page number.
	 * @return array
	 */
	public static function query_args( int $page ): array {
		$raw = self::raw_filters();

		$args = array(
			'order_id'     => max( 0, (int) $raw[ self::ARG_ORDER ] ),
			'rule_id'      => max( 0, (int) $raw[ self::ARG_RULE ] ),
			'final_status' => $raw[ self::ARG_STATUS ],
			'mode'         => $raw[ self::ARG_MODE ],
			'date_from'    => DeliveryPresenter::date_bound_to_utc( $raw[ self::ARG_FROM ], false ),
			'date_to'      => DeliveryPresenter::date_bound_to_utc( $raw[ self::ARG_TO ], true ),
		);

		$args['limit']  = DeliveryRepository::HISTORY_PER_PAGE;
		$args['offset'] = ( max( 1, $page ) - 1 ) * DeliveryRepository::HISTORY_PER_PAGE;

		return $args;
	}

	/**
	 * The filter values exactly as the request carries them, sanitised.
	 *
	 * @return array<string,string>
	 */
	public static function raw_filters(): array {
		$out = array();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only, idempotent listing filter carried in the URL; there is no state change here to protect, and a nonce would break bookmarking and paging links.
		foreach ( self::filter_args() as $arg ) {
			$out[ $arg ] = isset( $_GET[ $arg ] ) ? sanitize_text_field( wp_unslash( $_GET[ $arg ] ) ) : '';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $out;
	}

	/**
	 * Every filter query argument.
	 *
	 * @return string[]
	 */
	public static function filter_args(): array {
		return array( self::ARG_ORDER, self::ARG_RULE, self::ARG_STATUS, self::ARG_MODE, self::ARG_FROM, self::ARG_TO );
	}

	/**
	 * Whether the merchant narrowed the list.
	 *
	 * @return bool
	 */
	public static function is_filtered(): bool {
		foreach ( self::raw_filters() as $value ) {
			if ( '' !== $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The filter controls above the table.
	 *
	 * ⚠ EVERY CONTROL HAS A REAL `<label>` (gate 33). A row of six unlabelled inputs
	 * is six announcements of "edit text" to anybody not looking at the screen, and a
	 * `title` attribute is not a label.
	 *
	 * @param string $which `top` or `bottom`.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$raw = self::raw_filters();

		echo '<div class="alignleft actions extonify-wcep-delivery-filters">';

		self::number_field( self::ARG_ORDER, __( 'Order id', 'extonify-custom-emails-per-product' ), $raw[ self::ARG_ORDER ] );

		self::select_field(
			self::ARG_RULE,
			__( 'Filter by rule', 'extonify-custom-emails-per-product' ),
			__( 'All rules', 'extonify-custom-emails-per-product' ),
			self::rule_options(),
			$raw[ self::ARG_RULE ]
		);

		self::select_field(
			self::ARG_STATUS,
			__( 'Filter by status', 'extonify-custom-emails-per-product' ),
			__( 'All statuses', 'extonify-custom-emails-per-product' ),
			FieldOptions::delivery_statuses(),
			$raw[ self::ARG_STATUS ]
		);

		self::select_field(
			self::ARG_MODE,
			__( 'Filter by delivery mode', 'extonify-custom-emails-per-product' ),
			__( 'All modes', 'extonify-custom-emails-per-product' ),
			FieldOptions::delivery_modes(),
			$raw[ self::ARG_MODE ]
		);

		self::date_field( self::ARG_FROM, __( 'Delivered on or after', 'extonify-custom-emails-per-product' ), $raw[ self::ARG_FROM ] );
		self::date_field( self::ARG_TO, __( 'Delivered on or before', 'extonify-custom-emails-per-product' ), $raw[ self::ARG_TO ] );

		submit_button( __( 'Filter', 'extonify-custom-emails-per-product' ), '', 'filter_action', false );

		echo '</div>';
	}

	/**
	 * The rules a merchant can filter by, in ONE statement reading TWO columns.
	 *
	 * ⚠ `names_all()`, NOT `query()`. A full rule row carries the email body and two
	 * JSON documents that `hydrate()` decodes on the way out — read and thrown away
	 * here, once per history page view, to render a list of short names. ADR-0018 §5's
	 * "nothing is loaded that is not shown" governs this control as much as it governs
	 * the table.
	 *
	 * @return array<string,string> Rule id => name.
	 */
	private static function rule_options(): array {
		$options = array();

		foreach ( Plugin::instance()->rules()->names_all( self::RULE_OPTIONS_LIMIT ) as $id => $name ) {
			$name = trim( $name );

			$options[ (string) $id ] = '' !== $name
				? $name
				/* translators: %d: rule id. */
				: sprintf( __( 'Rule #%d (no name)', 'extonify-custom-emails-per-product' ), $id );
		}

		return $options;
	}

	/**
	 * One labelled select.
	 *
	 * @param string               $name     Query argument.
	 * @param string               $label    The control's accessible name.
	 * @param string               $all      The "no filter" option's label.
	 * @param array<string,string> $options  Value => label.
	 * @param string               $selected Current value.
	 * @return void
	 */
	private static function select_field( string $name, string $label, string $all, array $options, string $selected ): void {
		echo '<label class="screen-reader-text" for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '">';
		echo '<option value="">' . esc_html( $all ) . '</option>';

		foreach ( $options as $value => $text ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $selected, (string) $value, false ) . '>'
				. esc_html( (string) $text ) . '</option>';
		}

		echo '</select>';
	}

	/**
	 * One labelled number input.
	 *
	 * @param string $name  Query argument.
	 * @param string $label The control's accessible name.
	 * @param string $value Current value.
	 * @return void
	 */
	private static function number_field( string $name, string $label, string $value ): void {
		echo '<label class="screen-reader-text" for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
		echo '<input type="number" min="1" step="1" name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '"'
			. ' value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $label ) . '" class="small-text" />';
	}

	/**
	 * One labelled date input.
	 *
	 * @param string $name  Query argument.
	 * @param string $label The control's accessible name.
	 * @param string $value Current value.
	 * @return void
	 */
	private static function date_field( string $name, string $label, string $value ): void {
		echo '<label class="screen-reader-text" for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
		echo '<input type="date" name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '"'
			. ' value="' . esc_attr( $value ) . '" />';
	}

	/**
	 * The order cell.
	 *
	 * @param array $item Tombstone row.
	 * @return string
	 */
	public function column_order( $item ) {
		return DeliveryPresenter::order_cell( (int) ( $item['order_id'] ?? 0 ) );
	}

	/**
	 * The rule cell, with the trigger and mode beneath it.
	 *
	 * @param array $item Tombstone row.
	 * @return string
	 */
	public function column_rule( $item ) {
		return DeliveryPresenter::rule_cell( (int) ( $item['rule_id'] ?? 0 ), $this->rules )
			. '<br /><span class="description">'
			. DeliveryPresenter::trigger_cell( (string) ( $item['trigger_identity'] ?? '' ) )
			. ' &middot; '
			. DeliveryPresenter::mode_cell( (string) ( $item['mode'] ?? '' ) )
			. '</span>';
	}

	/**
	 * The status cell.
	 *
	 * @param array $item Tombstone row.
	 * @return string
	 */
	public function column_status( $item ) {
		return DeliveryPresenter::status_cell( (string) ( $item['final_status'] ?? '' ) );
	}

	/**
	 * The timing cell.
	 *
	 * @param array $item Tombstone row.
	 * @return string
	 */
	public function column_when( $item ) {
		return DeliveryPresenter::when_cell( (array) $item );
	}

	/**
	 * The attempts cell.
	 *
	 * @param array $item Tombstone row.
	 * @return string
	 */
	public function column_attempts( $item ) {
		return DeliveryPresenter::attempts_cell(
			(array) ( $this->details[ (int) ( $item['id'] ?? 0 ) ] ?? array() )
		);
	}

	/**
	 * Any column with no handler of its own.
	 *
	 * @param array  $item        Tombstone row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}
}
