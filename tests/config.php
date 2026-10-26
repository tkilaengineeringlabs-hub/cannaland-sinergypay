<?php
/**
 * Config.php
 *
 * @package Conexa\DappWoo\tests\
 */

define( 'TEST_DAPP_RUNNING', true );
define( 'ECHO_URL', 'https://www.postman-echo.com' );
define( 'TEST_API_TOKEN', getenv( 'DAPP_TEST_API_TOKEN' ) ?: 'placeholder-not-a-credential' );

define(
	'TEST_DAPP_SETTINGS',
	array(
		'enabled'                    => 'yes',
		'wc_dappwoo_api_token'       => TEST_API_TOKEN,
		'wc_dappwoo_log_enabled'     => 'yes',
		'wc_dappwoo_payment_options' => '',
	)
);

define( 'TEST_DAPP_OPTION', 'woocommerce_wc_dappwoo_settings' );


