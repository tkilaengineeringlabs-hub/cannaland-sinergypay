<?php
/**
 * Plugin Name: Cannaland Payments
 * Author: Tkila Intelligence Labs
 * Description: Pasarela de pagos integrada para Cannaland.
 * Version: 0.3.8
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 * Text Domain: cannaland-payments
 */
defined( 'ABSPATH' ) || exit;
define( 'CANNALAND_PAYMENTS_FILE', __FILE__ );
define( 'CANNALAND_PAYMENTS_VERSION', '0.3.8' );
require_once __DIR__ . '/includes/class-support.php';
require_once __DIR__ . '/includes/class-diagnostics.php';
require_once __DIR__ . '/includes/class-methods.php';
require_once __DIR__ . '/includes/class-sinergypay-client.php';
require_once __DIR__ . '/includes/class-preparation.php';
require_once __DIR__ . '/includes/class-notification.php';
require_once __DIR__ . '/includes/class-webhook.php';

add_action( 'before_woocommerce_init', static function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );
add_action( 'plugins_loaded', static function () {
    if ( ! class_exists( 'WC_Payment_Gateway' ) || ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '8.2', '<' ) ) {
        add_action( 'admin_notices', static function () {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Cannaland Payments requiere WooCommerce 8.2 o posterior.', 'cannaland-payments' ) . '</p></div>';
        } );
        return;
    }
    require_once __DIR__ . '/includes/class-spei-presentation.php';
    \Cannaland\Payments\SpeiPresentation::register();
    require_once __DIR__ . '/includes/class-gateway.php';
    require_once __DIR__ . '/includes/class-pending-gateway.php';
    foreach ( array( 'cards', 'cash' ) as $module ) {
        require_once __DIR__ . '/modules/' . $module . '/class-payment-service.php';
        require_once __DIR__ . '/modules/' . $module . '/class-gateway.php';
    }
    add_filter( 'woocommerce_payment_gateways', static function ( $gateways ) {
        foreach ( \Cannaland\Payments\Methods::all() as $method ) { $gateways[] = $method['gateway']; }
        return $gateways;
    } );
    add_action( 'rest_api_init', static function () {
        ( new \Cannaland\Payments\Webhook() )->register();
    } );
} );
add_action( 'woocommerce_blocks_loaded', static function () {
    if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        require_once __DIR__ . '/includes/class-blocks.php';
        add_action( 'woocommerce_blocks_payment_method_type_registration', static function ( $registry ) {
            foreach ( array_keys( \Cannaland\Payments\Methods::all() ) as $id ) {
                $registry->register( new \Cannaland\Payments\Blocks( $id ) );
            }
        } );
    }
} );
