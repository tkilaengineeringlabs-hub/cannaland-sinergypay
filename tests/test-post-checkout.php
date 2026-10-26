<?php
/**
 * Class PostCheckoutTest
 *
 * @package Conexa\DappWoo\tests\
 */

use Conexa\DappWoo\Includes\Helper\Helper;
use Conexa\DappWoo\Gateway\PostCheckout;

require_once 'config.php';

/**
 * Test PostCheckoutTest.
 */
class PostCheckoutTest extends WP_UnitTestCase {

	/**
	 * Test PostCheckoutTest .
	 */
	public function test_post_checkout() {

		update_option( TEST_DAPP_OPTION, TEST_DAPP_SETTINGS );

		$email    = 'customer@example.org';
		$customer = wc_create_new_customer( $email, $email, 'password' );
		wp_set_current_user( $customer );

		$order = \WC_Helper_Order::create_order();
		$order->set_customer_id( $customer );
		$order->set_billing_email( $email );
		$order->update_status( 'wc-pending' );

		$ret = PostCheckout::render( $order->get_id() );
		$this->assertFalse( $ret );

		$payment_gateways = WC()->payment_gateways->payment_gateways();
		$order->set_payment_method( $payment_gateways[ \DappWoo::GATEWAY_ID ] );
		$order->save();

		$ret = PostCheckout::render( $order->get_id() );
		$this->assertTrue( $ret );

		// Delete order.
		\WC_Helper_Order::delete_order( $order->get_id() );

		// Delete customer.
		wp_delete_user( $email );
	}
}
