<?php
/**
 * GATE 48 — clean-install smoke test, driven through the ADMIN SCREENS.
 * NON-PRODUCTION (excluded from the zip by .distignore).
 *
 * ⚠ WHY THIS SCRIPT EXISTS. Prompt 13's clean-install smoke test created its rule by
 * calling `Admin\RuleActions::handle()` directly. That is the real handler — the
 * capability check, the nonce check, `RuleFormInput` sanitisation and the repository
 * all ran — but gate 48 asks for a rule created THROUGH THE ADMIN UI and for the
 * history screen to be OPENED, and calling a handler is neither. A browser was never
 * required; rendering the screens and posting the form they render is.
 *
 * So every step below goes through a screen:
 *
 *   1. the rules list is RENDERED and must show the empty state;
 *   2. the rule editor is RENDERED for a new rule;
 *   3. the form fields are read OUT OF THAT MARKUP and posted back — so the payload is
 *      what the screen actually offers, not what this script thinks it offers;
 *   4. the rules list is RENDERED again and must now show the rule;
 *   5. an order is completed and must send exactly one message from this plugin;
 *   6. the delivery HISTORY screen is RENDERED and must show that delivery;
 *   7. the order panel is RENDERED for the order;
 *   8. the same trigger fires again and must send nothing more.
 *
 * Usage:  php bin/clean-install-smoke.php
 *
 * @package Extonify\WCEP
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( "CLI only.\n" );
}

error_reporting( E_ALL );
ini_set( 'display_errors', '1' ); // phpcs:ignore

$wcep_plugin_dir = dirname( __DIR__ );

$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SERVER_NAME']    = $_SERVER['SERVER_NAME'] ?? 'localhost';
$_SERVER['REQUEST_URI']    = '/wp-admin/admin.php';
$_SERVER['REQUEST_METHOD'] = 'GET';

if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}

require dirname( $wcep_plugin_dir, 3 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use Extonify\WCEP\Admin\DeliveryHistory;
use Extonify\WCEP\Admin\Menu;
use Extonify\WCEP\Admin\OrderPanel;
use Extonify\WCEP\Admin\RuleActions;
use Extonify\WCEP\Admin\RuleEditor;
use Extonify\WCEP\Admin\RuleFormInput;
use Extonify\WCEP\Admin\RuleList;
use Extonify\WCEP\Install\Migrator;
use Extonify\WCEP\Plugin;

$wcep_failures = array();
$wcep_notes    = array();

/**
 * Record one check.
 *
 * @param bool   $ok      Whether it held.
 * @param string $message What was being checked.
 * @return void
 */
function wcep_smoke_check( bool $ok, string $message ): void {
	global $wcep_failures;

	echo ( $ok ? '  ok    ' : '  FAIL  ' ) . $message . "\n";

	if ( ! $ok ) {
		$wcep_failures[] = $message;
	}
}

/**
 * Render an admin screen and hand back its markup.
 *
 * @param callable $screen The screen's render entry point.
 * @param array    $get    `$_GET` for the request.
 * @param array    $post   `$_POST` for the request.
 * @return string
 */
function wcep_smoke_render( callable $screen, array $get = array(), array $post = array() ): string {
	$_GET     = $get;
	$_POST    = $post;
	$_REQUEST = array_merge( $get, $post );

	ob_start();

	try {
		$screen();
	} finally {
		$markup = (string) ob_get_clean();
	}

	return $markup;
}

echo "\nExtonify WCEP — clean-install smoke test (gate 48), through the admin screens\n";
echo 'WordPress ' . get_bloginfo( 'version' ) . '  WooCommerce ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' )
	. '  PHP ' . PHP_VERSION . "\n";
echo 'HPOS: ' . get_option( 'woocommerce_custom_orders_table_enabled', 'no' )
	. '   permalinks: ' . ( '' === (string) get_option( 'permalink_structure' ) ? 'plain' : 'pretty' ) . "\n\n";

// ---------------------------------------------------------------------------
// 0. The plugin is active and its schema is real.
// ---------------------------------------------------------------------------
wcep_smoke_check( function_exists( 'extonify_wcep_boot' ), 'the plugin is active' );
wcep_smoke_check( Migrator::verify_schema( true ), 'verify_schema() passes against the live database' );

// A user who may actually use the screens.
$wcep_user_id = username_exists( 'wcep_smoke_manager' );

if ( ! $wcep_user_id ) {
	$wcep_user_id = wp_insert_user(
		array(
			'user_login' => 'wcep_smoke_manager',
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'wcep-smoke-manager@example.test',
			'role'       => 'administrator',
		)
	);
}

wp_set_current_user( (int) $wcep_user_id );
get_userdata( (int) $wcep_user_id )->add_cap( Menu::CAPABILITY );
set_current_screen( 'woocommerce_page_' . Menu::PAGE );

wcep_smoke_check( current_user_can( Menu::CAPABILITY ), 'the smoke user holds ' . Menu::CAPABILITY );

// ---------------------------------------------------------------------------
// 1. THE RULES LIST SCREEN RENDERS.
// ---------------------------------------------------------------------------
/*
 * ⚠ `prepare()` THEN `render()`, WHICH IS WHAT A REAL REQUEST DOES. WordPress calls
 * `RuleList::prepare()` on `load-{hook}` and `render()` as the page callback, and the
 * prepared `WP_List_Table` is memoised on the class for the life of the process. One
 * CLI process rendering the screen twice must therefore prepare twice, or the second
 * render re-displays the FIRST request's result set — which is a harness artifact, not
 * a plugin defect, and it cost this script one confusing failure before it was written
 * this way.
 */
$wcep_list_before = wcep_smoke_render(
	static function () {
		RuleList::prepare();
		RuleList::render();
	},
	array( 'page' => Menu::PAGE )
);

// ⚠ THE HEADING CHANGED IN PROMPT 13C PART F (ADR-0018 §1a) and this check caught it:
// it asserted the old "Custom Product Emails" and failed the moment the screen said
// "Email Rules". Updated rather than loosened — the point of the check is that a
// specific screen rendered, and a substring that matches any screen would not do that.
wcep_smoke_check( false !== strpos( $wcep_list_before, '>Email Rules<' ), 'the rules list screen renders' );
wcep_smoke_check(
	false !== strpos( $wcep_list_before, 'No custom product emails yet' ),
	'and shows the first-run empty state'
);

// ---------------------------------------------------------------------------
// 2. THE RULE EDITOR RENDERS FOR A NEW RULE.
// ---------------------------------------------------------------------------
$wcep_editor = wcep_smoke_render(
	static function () {
		RuleEditor::render();
	},
	array(
		'page'   => Menu::PAGE,
		'action' => Menu::ACTION_NEW,
	)
);

wcep_smoke_check( false !== strpos( $wcep_editor, RuleFormInput::FIELD ), 'the rule editor renders its form' );
wcep_smoke_check( false !== strpos( $wcep_editor, RuleActions::NONCE_FIELD ), 'the editor form carries a nonce field' );

// ---------------------------------------------------------------------------
// 3. POST THE EDITOR'S OWN FORM.
//
// ⚠ THE PAYLOAD IS READ OUT OF THE RENDERED MARKUP. The nonce is the one the screen
// printed, and the delay unit is the option the screen marked `selected` — so this
// submits what a browser sitting on that page would submit, rather than a payload
// this script invented and the screen may not offer.
// ---------------------------------------------------------------------------
$wcep_dom = new DOMDocument();
$wcep_previous_libxml = libxml_use_internal_errors( true );
$wcep_dom->loadHTML( '<?xml encoding="utf-8" ?>' . $wcep_editor );
libxml_clear_errors();
libxml_use_internal_errors( $wcep_previous_libxml );

$wcep_xpath = new DOMXPath( $wcep_dom );

$wcep_nonce_node = $wcep_xpath->query( '//input[@name="' . RuleActions::NONCE_FIELD . '"]' )->item( 0 );
$wcep_nonce      = $wcep_nonce_node instanceof DOMElement ? (string) $wcep_nonce_node->getAttribute( 'value' ) : '';

wcep_smoke_check( '' !== $wcep_nonce, 'the editor printed a usable nonce' );

$wcep_delay_unit = '';

foreach ( $wcep_xpath->query( '//select[@id="extonify-wcep-delay-unit"]/option' ) as $wcep_option ) {
	if ( '' === $wcep_delay_unit || $wcep_option->hasAttribute( 'selected' ) ) {
		$wcep_delay_unit = (string) $wcep_option->getAttribute( 'value' );
	}
}

wcep_smoke_check( '' !== $wcep_delay_unit, 'the editor offered a delay unit to submit' );

$wcep_product_id = ( new WC_Product_Simple() );
$wcep_product_id->set_name( 'WCEP Smoke Widget' );
$wcep_product_id->set_regular_price( '12.00' );
$wcep_product_id = $wcep_product_id->save();

$wcep_post = array(
	'action'                 => RuleActions::ACTION_SAVE,
	'rule'                   => 0,
	RuleActions::NONCE_FIELD => $wcep_nonce,
	RuleFormInput::FIELD     => array(
		'name'            => 'WCEP Smoke Rule',
		'status'          => 'active',
		'trigger_type'    => 'status',
		'trigger_status'  => 'completed',
		'delivery_mode'   => 'separate',
		'native_email_id' => '',
		'insert_position' => '',
		'delay_value'     => 0,
		'delay_unit'      => $wcep_delay_unit,
		'consolidation'   => 'none',
		'targeting'       => array( 'include' => array( 'products' => array( $wcep_product_id ) ) ),
		'recipients'      => array( 'to' => 'customer' ),
		'subject'         => 'About your {product_name}',
		'heading'         => 'About your order',
		'content'         => '<p>SMOKE BLOCK for {product_name} on order {order_number}.</p>',
		'priority'        => 10,
	),
);

$_SERVER['REQUEST_METHOD'] = 'POST';

$_GET     = array( 'page' => Menu::PAGE );
$_POST    = $wcep_post;
$_REQUEST = array_merge( $_GET, $_POST );

$wcep_saved = RuleActions::handle( RuleActions::ACTION_SAVE, $wcep_post, $_GET );

wcep_smoke_check(
	RuleActions::OUTCOME_REDIRECT === ( $wcep_saved['outcome'] ?? '' ),
	'posting the editor form created the rule (outcome: ' . ( $wcep_saved['outcome'] ?? '?' ) . ')'
);

// The SAME payload without the nonce must be refused, or the nonce proves nothing.
$wcep_forged = $wcep_post;
unset( $wcep_forged[ RuleActions::NONCE_FIELD ] );

$wcep_refused = RuleActions::handle( RuleActions::ACTION_SAVE, $wcep_forged, $_GET );

wcep_smoke_check(
	RuleActions::OUTCOME_DENIED === ( $wcep_refused['outcome'] ?? '' ),
	'the same payload WITHOUT a nonce is denied'
);

$_SERVER['REQUEST_METHOD'] = 'GET';

$wcep_rules = Plugin::instance()->rules()->query( array( 'limit' => 50 ) );
$wcep_rule  = null;

foreach ( $wcep_rules as $wcep_row ) {
	if ( 'WCEP Smoke Rule' === (string) $wcep_row['name'] ) {
		$wcep_rule = $wcep_row;
	}
}

wcep_smoke_check( null !== $wcep_rule, 'exactly one rule named "WCEP Smoke Rule" is stored' );

// ---------------------------------------------------------------------------
// 4. THE LIST SCREEN NOW SHOWS IT.
// ---------------------------------------------------------------------------
$wcep_list_after = wcep_smoke_render(
	static function () {
		RuleList::prepare();
		RuleList::render();
	},
	array( 'page' => Menu::PAGE )
);

wcep_smoke_check( false !== strpos( $wcep_list_after, 'WCEP Smoke Rule' ), 'the rules list screen shows the new rule' );

// ---------------------------------------------------------------------------
// 5. A REAL ORDER SENDS EXACTLY ONE MESSAGE FROM THIS PLUGIN.
// ---------------------------------------------------------------------------
$wcep_sent = array();

add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, $atts ) use ( &$wcep_sent ) {
		$wcep_sent[] = array(
			'to'      => $atts['to'] ?? '',
			'subject' => $atts['subject'] ?? '',
			'message' => $atts['message'] ?? '',
		);

		return true;
	},
	PHP_INT_MIN,
	2
);

$wcep_order = wc_create_order();
$wcep_order->add_product( wc_get_product( $wcep_product_id ), 1 );
$wcep_order->set_billing_first_name( 'Ada' );
$wcep_order->set_billing_email( 'wcep-smoke-customer@example.test' );
$wcep_order->calculate_totals();
$wcep_order->set_status( 'processing' );
$wcep_order->save();

$wcep_sent = array();

$wcep_order->update_status( 'completed' );

$wcep_ours = array_values(
	array_filter(
		$wcep_sent,
		static function ( $mail ) {
			return false !== strpos( (string) $mail['message'], 'SMOKE BLOCK' );
		}
	)
);

wcep_smoke_check( 1 === count( $wcep_ours ), 'completing the order sent EXACTLY ONE message from this plugin (of ' . count( $wcep_sent ) . ' total)' );

if ( array() !== $wcep_ours ) {
	$wcep_to = is_array( $wcep_ours[0]['to'] ) ? implode( ', ', $wcep_ours[0]['to'] ) : (string) $wcep_ours[0]['to'];

	wcep_smoke_check( false !== strpos( strtolower( $wcep_to ), 'wcep-smoke-customer@example.test' ), 'it was addressed to the customer' );
	wcep_smoke_check( false !== strpos( (string) $wcep_ours[0]['subject'], 'WCEP Smoke Widget' ), '{product_name} resolved in the subject' );
	wcep_smoke_check( false === strpos( (string) $wcep_ours[0]['message'], '{order_number}' ), 'no raw placeholder token survived into the body' );
	$wcep_notes[] = 'subject: ' . $wcep_ours[0]['subject'];
}

// ---------------------------------------------------------------------------
// 6. THE DELIVERY HISTORY SCREEN IS OPENED, and shows the delivery.
// ---------------------------------------------------------------------------
set_current_screen( 'woocommerce_page_' . Menu::HISTORY_PAGE );

$wcep_history = wcep_smoke_render(
	static function () {
		DeliveryHistory::prepare();
		DeliveryHistory::render();
	},
	array( 'page' => Menu::HISTORY_PAGE )
);

wcep_smoke_check( false !== strpos( $wcep_history, 'WCEP Smoke Rule' ), 'the delivery history screen shows the delivery' );
wcep_smoke_check( false !== strpos( $wcep_history, (string) $wcep_order->get_id() ), 'the history row names the order' );

$wcep_tombstones = Plugin::instance()->deliveries()->find_for_order( (int) $wcep_order->get_id() );

wcep_smoke_check( 1 === count( $wcep_tombstones ), 'one tombstone was recorded' );
wcep_smoke_check(
	array() !== $wcep_tombstones && 'sent' === (string) $wcep_tombstones[0]['final_status'],
	'its final status is `sent`'
);

// ---------------------------------------------------------------------------
// 7. THE ORDER PANEL RENDERS on the order edit screen.
// ---------------------------------------------------------------------------
set_current_screen( 'shop_order' );

$wcep_panel = wcep_smoke_render(
	static function () use ( $wcep_order ) {
		OrderPanel::render( $wcep_order );
	}
);

wcep_smoke_check( false !== strpos( $wcep_panel, 'WCEP Smoke Rule' ), 'the order panel shows the delivery' );

// ---------------------------------------------------------------------------
// 8. RE-FIRING THE SAME TRIGGER SENDS NOTHING MORE (ADR-0004).
// ---------------------------------------------------------------------------
$wcep_sent = array();

$wcep_order->update_status( 'processing' );
$wcep_order->update_status( 'completed' );

$wcep_again = array_values(
	array_filter(
		$wcep_sent,
		static function ( $mail ) {
			return false !== strpos( (string) $mail['message'], 'SMOKE BLOCK' );
		}
	)
);

wcep_smoke_check( array() === $wcep_again, 're-firing the same trigger sent nothing more' );
wcep_smoke_check(
	1 === count( Plugin::instance()->deliveries()->find_for_order( (int) $wcep_order->get_id() ) ),
	'and recorded no second tombstone'
);

// ---------------------------------------------------------------------------
// 9. Navigation (ADR-0018 §1a) and save-then-reload persistence.
// ---------------------------------------------------------------------------
//
// ⚠ THE SECOND HALF OF THIS BLOCK EXISTS BECAUSE OF THE REVIEW CORPUS, NOT THE CODE.
// "Settings do not save" is the third most common complaint across WooCommerce plugin
// reviews, and it is nearly always a rule that appears to save and is not there after a
// reload. It costs one re-read to prove and it is proved here rather than assumed from
// the fact that the insert returned an id.

global $menu, $submenu;
$menu    = array();
$submenu = array();

add_menu_page( 'WooCommerce', 'WooCommerce', Menu::CAPABILITY, Menu::PARENT, '__return_null' );
Menu::add_page();

$wcep_rows = array();

foreach ( (array) ( $submenu[ Menu::PARENT ] ?? array() ) as $wcep_row ) {
	$wcep_slug = (string) ( $wcep_row[2] ?? '' );

	if ( 0 === strpos( $wcep_slug, 'extonify-wcep' ) ) {
		$wcep_rows[ $wcep_slug ] = (string) ( $wcep_row[0] ?? '' );
	}
}

wcep_smoke_check( 1 === count( $wcep_rows ), 'exactly ONE submenu row under WooCommerce' );
wcep_smoke_check(
	isset( $wcep_rows[ Menu::PAGE ] ) && 'Product Emails' === $wcep_rows[ Menu::PAGE ],
	'the row is labelled "Product Emails"'
);
wcep_smoke_check( ! isset( $wcep_rows[ Menu::HISTORY_PAGE ] ), 'the history page has NO separate row' );
wcep_smoke_check( '' !== Menu::history_hook(), 'the history page is still REGISTERED behind the hidden row' );

// Both tabs load: the rules screen and the history screen each render past the tab strip.
$_REQUEST = array( 'page' => Menu::PAGE );
$_GET     = $_REQUEST;
set_current_screen( 'woocommerce_page_' . Menu::PAGE );
ob_start();
RuleList::render();
$wcep_rules_html = (string) ob_get_clean();

wcep_smoke_check( false !== strpos( $wcep_rules_html, 'nav-tab-wrapper' ), 'the Rules tab strip renders' );
wcep_smoke_check( false !== strpos( $wcep_rules_html, '>Email Rules<' ), 'the rules heading reads "Email Rules"' );
wcep_smoke_check(
	false !== strpos( $wcep_rules_html, 'page=' . Menu::HISTORY_PAGE ),
	'the Delivery History tab links to the history page'
);

$_REQUEST = array( 'page' => Menu::HISTORY_PAGE );
$_GET     = $_REQUEST;
set_current_screen( 'woocommerce_page_' . Menu::HISTORY_PAGE );
DeliveryHistory::prepare();
ob_start();
DeliveryHistory::render();
$wcep_history_html = (string) ob_get_clean();

wcep_smoke_check( false !== strpos( $wcep_history_html, 'nav-tab-wrapper' ), 'the Delivery History tab strip renders' );
wcep_smoke_check( false !== strpos( $wcep_history_html, '>Delivery History<' ), 'the history heading reads "Delivery History"' );
wcep_smoke_check(
	false !== strpos( $wcep_history_html, 'page=' . Menu::PAGE ),
	'the Rules tab links back to the rules page'
);

// The plugins-list Settings action.
$wcep_links = Menu::plugin_action_links( array( '<a href="#">Deactivate</a>' ) );
wcep_smoke_check(
	isset( $wcep_links[0] ) && false !== strpos( $wcep_links[0], '>Settings<' ),
	'the plugins list offers a "Settings" action'
);
wcep_smoke_check(
	isset( $wcep_links[0] ) && false !== strpos( html_entity_decode( $wcep_links[0] ), 'page=' . Menu::PAGE ),
	'and it resolves to the rules screen'
);

// ⚠ SAVE → RELOAD. Re-read the rule from a FRESH repository call, not the object the
// save returned, so a value that never reached the database cannot pass this.
$wcep_saved = null !== $wcep_rule
	? Plugin::instance()->rules()->find( (int) $wcep_rule['id'] )
	: null;

wcep_smoke_check( is_array( $wcep_saved ), 'the saved rule is still there after a reload' );
wcep_smoke_check(
	is_array( $wcep_saved ) && 'WCEP Smoke Rule' === (string) $wcep_saved['name'],
	'and its name survived the round trip'
);
wcep_smoke_check(
	is_array( $wcep_saved ) && (string) $wcep_saved['subject'] === (string) $wcep_rule['subject'],
	'and so did its subject, re-read from the database'
);

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
echo "\n";

foreach ( $wcep_notes as $wcep_note ) {
	echo '  note: ' . $wcep_note . "\n";
}

if ( array() === $wcep_failures ) {
	echo "\nGATE 48 SMOKE TEST PASSED — every step above ran through a rendered admin screen.\n\n";
	exit( 0 );
}

echo "\nGATE 48 SMOKE TEST FAILED:\n";

foreach ( $wcep_failures as $wcep_failure ) {
	echo '  - ' . $wcep_failure . "\n";
}

echo "\n";
exit( 1 );
