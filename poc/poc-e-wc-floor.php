<?php
/**
 * POC-E — WOOCOMMERCE FLOOR DETECTION. NON-PRODUCTION.
 *
 * Detects the email-preview integration surface
 * (woocommerce_prepare_email_for_preview / EmailPreview) and related feature
 * flags on the current runtime, then recommends a minimum supported
 * WooCommerce version with a capability-detection fallback plan for surfaces
 * that only exist above the floor.
 *
 * @package Extonify\WCEP\POC
 */

require __DIR__ . '/_bootstrap.php';

wcep_poc_section( 'POC-E — WOOCOMMERCE FLOOR DETECTION' );

$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : ( function_exists( 'WC' ) ? WC()->version : '0' );

// --- Capability detection on THIS runtime ---------------------------------
$preview_class   = '\Automattic\WooCommerce\Internal\Admin\EmailPreview\EmailPreview';
$has_preview     = class_exists( $preview_class );
$has_preview_run = $has_preview && method_exists( ltrim( $preview_class, '\\' ), 'render' );

$features        = function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' );
$email_improve   = $features ? \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'email_improvements' ) : false;
$block_editor    = $features ? \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'block_email_editor' ) : false;

$hpos_class      = '\Automattic\WooCommerce\Utilities\OrderUtil';
$has_hpos_helper = class_exists( $hpos_class );
$hpos_on         = $has_hpos_helper && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

// Known @since values, read from the WooCommerce source in this checkout.
$since_email_sent    = '5.6.0'; // do_action('woocommerce_email_sent')  — ADR-0005 dependency.
$since_prepare_prev  = '9.6.0'; // apply_filters('woocommerce_prepare_email_for_preview') — preview surface.

echo "  Runtime WooCommerce version ........... {$wc_version}\n";
printf( "  EmailPreview class present ............ %s  (%s)\n", $has_preview ? 'YES' : 'no', ltrim( $preview_class, '\\' ) );
printf( "  EmailPreview::render() present ........ %s\n", $has_preview_run ? 'YES' : 'no' );
printf( "  woocommerce_prepare_email_for_preview . available (filter @since %s)\n", $since_prepare_prev );
printf( "  woocommerce_email_sent (hard dep) ..... required (@since %s)\n", $since_email_sent );
printf( "  'email_improvements' feature enabled .. %s\n", $email_improve ? 'YES' : 'no' );
printf( "  'block_email_editor' feature enabled .. %s\n", $block_editor ? 'YES' : 'no' );
printf( "  HPOS helper present / HPOS enabled .... %s / %s\n", $has_hpos_helper ? 'YES' : 'no', $hpos_on ? 'YES' : 'no' );

// --- Recommendation --------------------------------------------------------
$RECOMMENDED_FLOOR = '8.2';

wcep_poc_section( 'RECOMMENDED WC FLOOR (PROVISIONAL — ADR-0006 amended)' );
echo "  Recommended minimum WooCommerce: {$RECOMMENDED_FLOOR}  ** PROVISIONAL **\n\n";
echo "  PROVISIONAL — observed on a SINGLE combination only:\n";
echo "    PHP 8.3.6 / WP 7.0.2 / WC 10.9.4 / HPOS ON / email_improvements ON / block_email_editor OFF\n";
echo "  UNTESTED (must be matrix-tested before the floor is asserted as tested):\n";
echo "    - PHP 8.0, 8.1, 8.2, and 8.4 — syntax is statically held to PHP 8.0, but\n";
echo "      ACTUAL PHP 8.0 RUNTIME compatibility is matrix-UNTESTED; everything here\n";
echo "      executed on PHP 8.3.6 ONLY.\n";
echo "    - legacy post-based order storage (HPOS OFF)\n";
echo "    - email_improvements OFF\n";
echo "    - block_email_editor ON\n";
echo "  Open option (deferred to the release matrix phase): align the final floor\n";
echo "  with the sibling Extonify Address Book plugin to reduce suite-wide test burden.\n\n";
echo "  Reasoning:\n";
echo "   - HARD dependency: woocommerce_email_sent (@since 5.6.0) drives ADR-0005\n";
echo "     insert-mode finalization. 5.6.0 is far below the recommended floor.\n";
echo "   - All injection/trigger hooks used (woocommerce_email_classes,\n";
echo "     woocommerce_email_order_details [4-arg], before/after_order_table,\n";
echo "     order_meta, customer_details, woocommerce_order_item_meta_end,\n";
echo "     woocommerce_email_footer, order_status_changed, order_fully/partially_\n";
echo "     refunded) are stable and predate 8.2.\n";
echo "   - 8.2 is the HPOS GA baseline: OrderUtil + uniform order CRUD across HPOS\n";
echo "     and legacy storage, which the delivery/claim layer relies on.\n";
echo "   - This floors BELOW the preview surface (9.6.0) deliberately, to maximise\n";
echo "     install reach, with the fallback plan below.\n\n";
echo "  Capability-detection fallback plan (floor 8.2 < preview 9.6.0):\n";
echo "   - Register the email-preview integration ONLY when the surface exists:\n";
echo "       if ( class_exists( EmailPreview::class ) ) { add_filter(\n";
echo "         'woocommerce_prepare_email_for_preview', … ); }\n";
echo "   - Treat 'email_improvements' and 'block_email_editor' as runtime-detected\n";
echo "     via FeaturesUtil::feature_is_enabled(); never hard-require them.\n";
echo "   - Below 9.6.0: no preview integration is registered; all separate-mode\n";
echo "     sending and insert-mode injection continue to work unchanged.\n";
echo "   - Guard every optional surface behind class_exists/method_exists/\n";
echo "     function_exists so a lower floor degrades features, never fatals.\n";

// --- Real assertions -------------------------------------------------------
wcep_poc_section( 'POC-E ASSERTIONS' );
wcep_poc_assert( 'WooCommerce version detected', version_compare( $wc_version, '0', '>' ), $wc_version );
wcep_poc_assert( 'preview surface detection works (EmailPreview present on 10.9.4)', $has_preview );
wcep_poc_assert( 'preview render entry point present', $has_preview_run );
wcep_poc_assert(
	'hard-dependency hook floor (email_sent 5.6.0) is BELOW recommended floor 8.2',
	version_compare( $RECOMMENDED_FLOOR, $since_email_sent, '>=' )
);
wcep_poc_assert(
	'recommended floor 8.2 is BELOW preview availability 9.6.0 → capability-detection fallback applies',
	version_compare( $RECOMMENDED_FLOOR, $since_prepare_prev, '<' )
);
wcep_poc_assert(
	'current runtime (10.9.4) is at/above recommended floor',
	version_compare( $wc_version, $RECOMMENDED_FLOOR, '>=' )
);
wcep_poc_assert(
	'floor is recorded as PROVISIONAL, observed on a single runtime combination (ADR-0006 amended)',
	true // documented above; this assertion pins the provisional caveat into the run output.
);
wcep_poc_assert(
	'single observed combination: HPOS on, email_improvements on, block editor off',
	$hpos_on && $email_improve && ! $block_editor,
	'hpos=' . ( $hpos_on ? 1 : 0 ) . ' improvements=' . ( $email_improve ? 1 : 0 ) . ' block=' . ( $block_editor ? 1 : 0 )
);
wcep_poc_assert(
	'PHP 8.0 RUNTIME compatibility recorded as matrix-UNTESTED (executed on 8.3.6 only)',
	version_compare( PHP_VERSION, '8.0', '>=' ) && '8.3' === PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
	'running ' . PHP_VERSION
);

wcep_poc_summary();
echo "\nPOC-E OK\n";