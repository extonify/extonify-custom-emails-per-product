<?php
/**
 * A custom HTML order-email template that fires NO footer hook of any kind.
 *
 * Swapped in over `emails/customer-processing-order.php` through the
 * `wc_get_template` filter, which is exactly how a plugin or theme replaces a
 * WooCommerce email template in the wild.
 *
 * It fires the three actions every WooCommerce order-email template fires —
 * order details, order meta, customer details — and then simply stops. Neither
 * `woocommerce_email_footer` nor `woocommerce_pos_email_footer` is fired, so
 * nothing hook-based can ever retire this render's open footer token: only the
 * token-exact purge at finalization can (ADR-0013 §5h).
 *
 * @package Extonify\WCEP\Tests
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

echo '<p>NO FOOTER TEMPLATE.</p>';

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );
