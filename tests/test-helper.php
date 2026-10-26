<?php
/**
 * Class HelperTest
 *
 * @package Conexa\DappWoo\tests\
 */

use Conexa\DappWoo\Includes\Helper\Helper;
use Conexa\DappWoo\Gateway\RequestDepositAction;

require_once 'config.php';

/**
 * Test Helper.
 */
class HelperTest extends WP_UnitTestCase {

	/**
	 * Test Helper asset Method.
	 */
	public function test_get_asset_url() {
		$url = Helper::get_assets_folder_url();
		$this->assertTrue( ! empty( $url ) );
	}

	/**
	 * Test Helper get Order by itemmeta Method.
	 */
	public function test_get_order_by_itemmeta_value() {
		$paymentIntention = '9879-98585-98585-64654';
		$email            = 'customer@example.org';
		$customer         = wc_create_new_customer( $email, $email, 'password' );
		wp_set_current_user( $customer );

		$order = \WC_Helper_Order::create_order();
		$order->set_customer_id( $customer );
		$order->set_billing_email( $email );
		$order->update_status( 'wc-pending' );

		$order->update_meta_data( \DappWoo::META_ORDER_PAYMENT_ID, $paymentIntention );

		$id  = Helper::find_order_by_itemmeta_value( \DappWoo::META_ORDER_PAYMENT_ID, $paymentIntention );
		$id2 = Helper::find_order_by_itemmeta_value( \DappWoo::META_ORDER_PAYMENT_ID, $paymentIntention . 'NOTEQUAL' );
		$this->assertEquals( $id, $order->get_id() );
		$this->assertNotEquals( $id2, $order->get_id() );

		// Delete order.
		\WC_Helper_Order::delete_order( $order->get_id() );

		// Delete customer.
		wp_delete_user( $email );
	}

	/**
	 * Test Helper Logger Method.
	 */
	public function test_logger() {

		update_option( TEST_DAPP_OPTION, TEST_DAPP_SETTINGS );

		$code         = uniqid();
		$info_code    = $code . '_INFO';
		$error_code   = $code . '_ERROR';
		$warning_code = $code . '_WARNING';
		$debug_code   = $code . '_DEBUG';
		$log_code     = $code . '_LOG';

		$logger = Helper::init();
		$this->assertEquals( get_class( $logger ), 'WC_Logger' );

		Helper::log_info( $info_code );
		Helper::log_error( $error_code );
		Helper::log_warning( $warning_code );
		Helper::log_debug( $debug_code );
		Helper::log( $log_code );

		$log_info    = false;
		$log_error   = false;
		$log_warning = false;
		$log_debug   = false;
		$log_log     = false;

		$path     = get_home_path() . 'wp-content/uploads/wc-logs/*.log';
		$fileList = glob( $path );

		foreach ( $fileList as $filename ) {
			if ( is_file( $filename ) ) {
				if ( strpos( file_get_contents( $filename ), $info_code ) !== false ) {
					$log_info = true;
				}
				if ( strpos( file_get_contents( $filename ), $error_code ) !== false ) {
					$log_error = true;
				}
				if ( strpos( file_get_contents( $filename ), $warning_code ) !== false ) {
					$log_warning = true;
				}
				if ( strpos( file_get_contents( $filename ), $debug_code ) !== false ) {
					$log_debug = true;
				}
				if ( strpos( file_get_contents( $filename ), $log_code ) !== false ) {
					$log_log = true;
				}
				unlink( $filename );
			}
		}

		$this->assertTrue( $log_info );
		$this->assertTrue( $log_error );
		$this->assertTrue( $log_warning );
		$this->assertTrue( $log_debug );
		$this->assertTrue( $log_log );

	}

	/**
	 * Test Helper Logger WC Generation
	 */
	public function test_logger_gen() {
		Helper::clean();
		$logger = Helper::init();
		$this->assertEquals( get_class( $logger ), 'WC_Logger' );
	}

	/**
	 * Test Helper settings Method.
	 */
	public function test_get_settings() {
		update_option( TEST_DAPP_OPTION, TEST_DAPP_SETTINGS );

		$settings = Helper::get_options();

		$this->assertEquals( TEST_DAPP_SETTINGS['enabled'], $settings['enabled'] );
		$this->assertEquals( TEST_DAPP_SETTINGS['enabled'], Helper::get_option( 'enabled' ) );
		$this->assertEquals( TEST_DAPP_SETTINGS['wc_dappwoo_api_token'], $settings['api_token'] );
		$this->assertEquals( TEST_DAPP_SETTINGS['wc_dappwoo_api_token'], Helper::get_option( 'api_token' ) );
		$this->assertEquals( TEST_DAPP_SETTINGS['wc_dappwoo_log_enabled'], $settings['debug'] );
		$this->assertEquals( TEST_DAPP_SETTINGS['wc_dappwoo_log_enabled'], Helper::get_option( 'debug' ) );

	}

	/**
	 * Test Helper Currency - Get
	 */
	public function test_get_currency() {
		update_option( 'woocommerce_currency', 'MXN' );
		$curr = Helper::get_currency();
		$this->assertEquals( $curr, 'MXN' );
	}

	/**
	 * Test Helper Validate Credentials
	 */
	public function test_validate_all_html() {
		update_option( TEST_DAPP_OPTION, TEST_DAPP_SETTINGS );
		update_option( 'woocommerce_currency', 'MXN' );

		global $current_section;
		$current_section = 'wc_dappwoo';
		$html            = Helper::validate_all_html();
		$this->assertContains( Helper::VALIDATION_OK_ICON, $html );
	}

	/**
	 * Test handle_payment( $order_id );
	 */
	public function test_handle_payment() {
		update_option( TEST_DAPP_OPTION, TEST_DAPP_SETTINGS );
		update_option( 'woocommerce_currency', 'MXN' );

		$email    = 'customer@example.org';
		$customer = wc_create_new_customer( $email, $email, 'password' );
		wp_set_current_user( $customer );

		$order = \WC_Helper_Order::create_order();
		$order->set_customer_id( $customer );
		$order->set_billing_email( $email );
		$order->update_status( 'wc-pending' );

		$ret = RequestDepositAction::run( $order->get_id(), 'CHECKOUT_TEST' );

		$this->assertTrue( Helper::handle_payment( $order->get_id(), 'CHECKOUT_TEST' ) );
		$this->assertFalse( Helper::handle_payment( $order->get_id(), '' ) );

		// Delete order.
		\WC_Helper_Order::delete_order( $order->get_id() );

		// Delete customer.
		wp_delete_user( $email );

	}
}
