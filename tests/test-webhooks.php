<?php
/**
 * Class Webhook
 *
 * @package Conexa\DappWoo\tests\
 */

use Conexa\DappWoo\Orders\Webhooks;
use Conexa\DappWoo\Gateway\RequestDepositAction;
use Conexa\DappWoo\Sdk\ClipSdk;

require_once 'config.php';

/**
 * Test Webhooks
 */
class WebhookTest extends WP_UnitTestCase {

	/**
	 * Test WebhooksTest .
	 */
	public function test_webhook_listener_ok() {
		update_option( TEST_DAPP_OPTION, TEST_DAPP_SETTINGS );
		update_option( 'woocommerce_currency', 'MXN' );

		$email    = 'customer@example.org';
		$customer = wc_create_new_customer( $email, $email, 'password' );
		wp_set_current_user( $customer );

		$order = \WC_Helper_Order::create_order();
		$order->set_customer_id( $customer );
		$order->set_billing_email( $email );
		$order->update_status( 'wc-pending' );

		$ret  = RequestDepositAction::run( $order->get_id() );
		$code = $order->get_meta_data( \DappWoo::META_ORDER_PAYMENT_ID );

		$json = '{ "code": "' . $code[0]->value . '"}';

		$ret2 = Webhooks::listener( $json, 'CHECKOUT_TEST' );
		$this->assertTrue( $ret2 );

		$lastComment = wc_get_order_notes( array( 'order_id' => $order->get_id() ) )[0];

		$this->assertContains( 'Dapp', $lastComment->content );

		// Delete order.
		\WC_Helper_Order::delete_order( $order->get_id() );

		// Delete customer.
		wp_delete_user( $email );

	}

	/**
	 * Test Webhooks
	 */
	public function test_process_webhook_ok() {
		update_option( TEST_DAPP_OPTION, TEST_DAPP_SETTINGS );
		update_option( 'woocommerce_currency', 'MXN' );

		$price    = 100;
		$email    = 'customer@example.org';
		$customer = wc_create_new_customer( $email, $email, 'password' );
		wp_set_current_user( $customer );

		$order = \WC_Helper_Order::create_order();
		$order->set_customer_id( $customer );
		$order->set_billing_email( $email );
		$order->set_total( $price );
		$order->update_status( 'wc-pending' );

		$ret  = RequestDepositAction::run( $order->get_id() );
		$code = $order->get_meta_data( \DappWoo::META_ORDER_PAYMENT_ID );

		$json = '{ "code": "' . $code[0]->value . '"}';

		$ret2 = Webhooks::process_webhook( $json, 'CHECKOUT_TEST' );
		$this->assertTrue( $ret2 );

		$ret3 = Webhooks::process_webhook( $json, '' );
		$this->assertFalse( $ret3 );

		$lastComment = wc_get_order_notes( array( 'order_id' => $order->get_id() ) )[0];
		$this->assertContains( 'Dapp', $lastComment->content );

		// Delete order.
		\WC_Helper_Order::delete_order( $order->get_id() );

		// Delete customer.
		wp_delete_user( $email );

	}

	/**
	 * Test validate_input
	 */
	public function test_validate_input_nok() {

		$json1 = '{ "code": "1234"}';
		$json1 = json_decode( $json1, true );

		$ret1 = Webhooks::validate_input( $json1 );
		$this->assertFalse( $ret1 );

		$json2 = '{ "code": ""}';
		$json2 = json_decode( $json2, true );

		$ret2 = Webhooks::validate_input( $json2 );
		$this->assertFalse( $ret2 );

	}

	/**
	 * Test process_webhook
	 */
	public function test_process_webhook_nok() {

		$json = '{ "test": ""}';

		$ret = Webhooks::process_webhook( $json, '' );
		$this->assertFalse( $ret );

	}

	/**
	 * Test get_order_id
	 */
	public function test_get_order_id_nok() {

		$json = '{ "test": ""}';
		$json = json_decode( $json, true );

		$ret = Webhooks::get_order_id( $json );
		$this->assertFalse( $ret );

	}

}
