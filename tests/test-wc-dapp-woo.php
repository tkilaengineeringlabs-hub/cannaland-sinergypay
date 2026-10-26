<?php
/**
 * Class WC_DappWooTest
 *
 * @package Conexa\DappWoo\tests\
 */

use Conexa\DappWoo\Gateway\WC_DappWoo;
use Conexa\DappWoo\Gateway\RequestDepositAction;
use Conexa\DappWoo\Includes\Helper\Helper;

require_once 'config.php';

/**
 * Test WC_Clip Construction Method.
 */
class WC_DappWooTest extends WP_UnitTestCase {

	/**
	 * Test WC_Clip Construction Method.
	 */
	public function test_dapp_gateway() {
		update_option( TEST_DAPP_OPTION, TEST_DAPP_SETTINGS );

		$dapp = new WC_DappWoo();
		$this->assertTrue( is_subclass_of( $dapp, 'WC_Payment_Gateway' ) );
		$this->assertEquals( $dapp->id, \DappWoo::GATEWAY_ID );
		$this->assertEquals( $dapp->enabled, TEST_DAPP_SETTINGS['enabled'] );

		$this->assertEquals( $dapp->api_token, TEST_DAPP_SETTINGS['wc_dappwoo_api_token'] );
		$this->assertEquals( $dapp->get_option( 'wc_dappwoo_api_token' ), TEST_DAPP_SETTINGS['wc_dappwoo_api_token'] );

		$this->assertEquals( $dapp->log_enabled, TEST_DAPP_SETTINGS['wc_dappwoo_log_enabled'] );
		$this->assertEquals( $dapp->get_option( 'wc_dappwoo_log_enabled' ), TEST_DAPP_SETTINGS['wc_dappwoo_log_enabled'] );

		$this->assertEquals( $dapp->payment_options, TEST_DAPP_SETTINGS['wc_dappwoo_payment_options'] );
		$this->assertEquals( $dapp->get_option( 'wc_dappwoo_payment_options' ), TEST_DAPP_SETTINGS['wc_dappwoo_payment_options'] );

		$this->assertTrue( has_action( 'woocommerce_update_options_payment_gateways_' . $dapp->id ) );
		$this->assertTrue( has_action( 'woocommerce_thankyou_' . $dapp->id ) );

	}


	/**
	 * Test WC_Clip Available Payment Method.
	 */
	public function test_clip_available_payment_no_filter() {
		update_option( TEST_DAPP_OPTION, TEST_DAPP_SETTINGS );
		update_option( 'woocommerce_currency', 'MXN' );
		$gateways[ \DappWoo::GATEWAY_ID ] = '';
		WC()->customer                    = null;
		$gatewaysAvailables               = WC_DappWoo::available_payment_method( $gateways );
		$this->assertTrue( isset( $gatewaysAvailables[ \DappWoo::GATEWAY_ID ] ) );
	}

	/**
	 * Test WC_Clip Available Payment Method.
	 */
	public function test_clip_available_payment_ok() {
		update_option( TEST_DAPP_OPTION, TEST_DAPP_SETTINGS );
		update_option( 'woocommerce_currency', 'MXN' );
		$email    = 'customer@example.org';
		$customer = wc_create_new_customer( $email, $email, 'password' );
		wp_set_current_user( $customer );
		WC()->customer = new WC_Customer( $customer );
		WC()->customer->set_billing_country( 'MX' );
		$gateways[ \DappWoo::GATEWAY_ID ] = '';
		$gatewaysAvailables               = WC_DappWoo::available_payment_method( $gateways );
		$this->assertTrue( isset( $gatewaysAvailables[ \DappWoo::GATEWAY_ID ] ) );
	}

	/**
	 * Test WC_Clip Available Payment Method.
	 */
	public function test_clip_available_payment_nok() {
		update_option( TEST_DAPP_OPTION, TEST_DAPP_SETTINGS );
		update_option( 'woocommerce_currency', 'ARS' );
		WC()->customer->set_billing_country( 'AR' );
		$email    = 'customer@example.org';
		$customer = wc_create_new_customer( $email, $email, 'password' );
		wp_set_current_user( $customer );
		WC()->customer                    = new WC_Customer( $customer );
		$gateways[ \DappWoo::GATEWAY_ID ] = '';
		$gatewaysAvailables               = WC_DappWoo::available_payment_method( $gateways );
		$this->assertFalse( isset( $gatewaysAvailables[ \DappWoo::GATEWAY_ID ] ) );
	}

	/**
	 * Test process payment Webhook Method.
	 */
	public function test_process_payment() {

		$email    = 'customer@example.org';
		$customer = wc_create_new_customer( $email, $email, 'password' );
		wp_set_current_user( $customer );

		$order = \WC_Helper_Order::create_order();
		$order->set_customer_id( $customer );
		$order->set_billing_email( $email );
		$order->update_status( 'wc-pending' );

		update_option( TEST_DAPP_OPTION, TEST_DAPP_SETTINGS );
		$dapp = new WC_DappWoo();
		$ret  = $dapp->process_payment( $order->get_id() );

		$this->assertContains( $order->get_checkout_payment_url( true ), $ret['redirect'] );

		// Delete order.
		\WC_Helper_Order::delete_order( $order->get_id() );

		// Delete customer.
		wp_delete_user( $email );

	}

	/**
	 * Test run Payment Intention  Method.
	 */
	public function test_run_payment_intention() {
		update_option( TEST_DAPP_OPTION, TEST_DAPP_SETTINGS );
		update_option( 'woocommerce_currency', 'MXN' );

		$email    = 'customer@example.org';
		$customer = wc_create_new_customer( $email, $email, 'password' );
		wp_set_current_user( $customer );

		$order = \WC_Helper_Order::create_order();
		$order->set_customer_id( $customer );
		$order->set_billing_email( $email );
		$order->update_status( 'wc-pending' );

		$ret = RequestDepositAction::run( $order->get_id() );

		$this->assertEquals( 'https://sandbox.dapp.mx/c/' . get_meta( $order->get_id(), \DappWoo::META_ORDER_PAYMENT_ID )[0], $ret );

		// Delete order.
		\WC_Helper_Order::delete_order( $order->get_id() );

		// Delete customer.
		wp_delete_user( $email );
	}

	/**
	 * Test validate ajax Payment Intention  Method.
	 */
	public function test_validate_ajax_payment_intention() {
		update_option( TEST_DAPP_OPTION, TEST_DAPP_SETTINGS );
		update_option( 'woocommerce_currency', 'MXN' );

		$email    = 'customer@example.org';
		$customer = wc_create_new_customer( $email, $email, 'password' );
		wp_set_current_user( $customer );

		$order = \WC_Helper_Order::create_order();
		$order->set_customer_id( $customer );
		$order->set_billing_email( $email );
		$order->update_status( 'wc-pending' );

		// Not Posting Anything.
		$ret = RequestDepositAction::validate_ajax_request();
		$this->assertFalse( true === $ret );

		$_POST['nonce']    = wp_create_nonce( \DappWoo::GATEWAY_ID );
		$_POST['order_id'] = $order->get_id();

		$payment_gateways = WC()->payment_gateways->payment_gateways();
		$order->set_payment_method( $payment_gateways[ \DappWoo::GATEWAY_ID ] );
		$order->save();

		// Not Posting Anything.
		$ret = RequestDepositAction::validate_ajax_request();
		$this->assertTrue( true === $ret );
		// Delete order.
		\WC_Helper_Order::delete_order( $order->get_id() );

		// Delete customer.
		wp_delete_user( $email );
	}


	/**
	 * Test validate ajax Payment Intention  Method.
	 */
	public function test_validate_ajax_request() {
		update_option( TEST_DAPP_OPTION, TEST_DAPP_SETTINGS );
		update_option( 'woocommerce_currency', 'MXN' );

		$email    = 'customer@example.org';
		$customer = wc_create_new_customer( $email, $email, 'password' );
		wp_set_current_user( $customer );

		$order = \WC_Helper_Order::create_order();
		$order->set_customer_id( $customer );
		$order->set_billing_email( $email );
		$order->update_status( 'wc-pending' );

		// Not Posting Anything.
		$ret = RequestDepositAction::validate_ajax_request();
		$this->assertFalse( true === $ret );

		$_POST['nonce']    = wp_create_nonce( \DappWoo::GATEWAY_ID );
		$_POST['order_id'] = $order->get_id();

		$payment_gateways = WC()->payment_gateways->payment_gateways();
		$order->set_payment_method( $payment_gateways[ \DappWoo::GATEWAY_ID ] );
		$order->save();

		// Not Posting Anything.
		$ret = RequestDepositAction::ajax_callback_wp();
		$this->assertTrue( true === $ret );
		// Delete order.
		\WC_Helper_Order::delete_order( $order->get_id() );

		// Delete customer.
		wp_delete_user( $email );
	}

	/**
	 * Test validate ajax Payment Intention  Method.
	 */
	public function test_validate_ajax_request_nok() {
		update_option( TEST_DAPP_OPTION, TEST_DAPP_SETTINGS );
		update_option( 'woocommerce_currency', 'MXN' );

		$email    = 'customer@example.org';
		$customer = wc_create_new_customer( $email, $email, 'password' );
		wp_set_current_user( $customer );

		$order = \WC_Helper_Order::create_order();
		$order->set_customer_id( $customer );
		$order->set_billing_email( $email );
		$order->update_status( 'wc-pending' );

		// Not Posting Anything.
		$ret = RequestDepositAction::validate_ajax_request();
		$this->assertFalse( true === $ret );

		$_POST['nonce']    = 'NONCE';
		$_POST['order_id'] = $order->get_id();

		$payment_gateways = WC()->payment_gateways->payment_gateways();
		$order->set_payment_method( $payment_gateways[ \DappWoo::GATEWAY_ID ] );
		$order->save();

		// Not Posting Anything.
		$ret = RequestDepositAction::ajax_callback_wp();
		$this->assertFalse( true === $ret );
		// Delete order.
		\WC_Helper_Order::delete_order( $order->get_id() );

		// Delete customer.
		wp_delete_user( $email );
	}


	/**
	 * Test process_refund
	 */
	public function test_process_refund() {
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

		$wc       = new WC_DappWoo();
		$response = $wc->process_refund( $order->get_id(), 50.00, '' );

		$this->assertNotNull( $response );

		// Delete order.
		\WC_Helper_Order::delete_order( $order->get_id() );

		// Delete customer.
		wp_delete_user( $email );

	}
}
