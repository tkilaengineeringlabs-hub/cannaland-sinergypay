<?php
/**
 * Hooks Files
 *
 * @package  Conexa\DappWoo\Hooks
 */

defined( 'ABSPATH' ) || exit;

// --- Payment Method
add_filter( 'woocommerce_payment_gateways', array( 'DappWoo', 'add_payment_method' ) );

add_filter(
	'woocommerce_available_payment_gateways',
	array(
		'\Conexa\DappWoo\Gateway\WC_DappWoo',
		'available_payment_method',
	)
);

// --- Settings
add_filter( 'plugin_action_links_' . plugin_basename( \DappWoo::MAIN_FILE ), array( 'DappWoo', 'create_settings_link' ) );

// --- Frontend buttons
add_action(
	'woocommerce_receipt_' . \DappWoo::GATEWAY_ID,
	array( '\Conexa\DappWoo\Gateway\PostCheckout', 'render' ),
	90
);

// --- Order Ajax Actions
add_action(
	'wp_ajax_dapp_request_deposit_action',
	array(
		'\Conexa\DappWoo\Gateway\RequestDepositAction',
		'ajax_callback_wp',
	)
);

add_action(
	'wp_ajax_nopriv_dapp_request_deposit_action',
	array(
		'\Conexa\DappWoo\Gateway\RequestDepositAction',
		'ajax_callback_wp',
	)
);

add_action( 'wp_enqueue_scripts', array( 'DappWoo', 'register_front_scripts' ) );


// --- Webhook
add_action(
	'woocommerce_api_wc-dapp',
	array(
		'\Conexa\DappWoo\Orders\Webhooks',
		'listener',
	)
);
