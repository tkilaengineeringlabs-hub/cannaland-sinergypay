<?php
/**
 * HandlePayment Trait
 *
 * @package Conexa\DappWoo\Helper
 */

namespace Conexa\DappWoo\Includes\Helper;

use Conexa\DappWoo\Sdk\DappWooSdk;
use Conexa\DappWoo\Gateway\WC_DappWoo;

trait HandlePaymentTrait {

	/**
	 * Gets Payment Response
	 *
	 * @param string $order_id Order Id Data.
	 * @param string $test Order Id Data.
	 */
	public static function handle_payment( $order_id, $test = '' ) {
		if ( null !== $order_id ) {
			$order      = wc_get_order( $order_id );
			$order_data = $order->get_meta( '_DAPP_PAYMENT_ID' );
		}

		if ( isset( $order_data ) || 'CHECKOUT_TEST' === $test ) {

			$options  = Helper::get_options( \DappWoo::GATEWAY_ID );
			$sdk      = new DappWooSdk( $options['api_token'] );
			$response = $sdk->get_payment_data( $order_data );

			if ( isset( $response['data'] ) || 'CHECKOUT_TEST' === $test ) {

					$order->payment_complete();
					$order->add_order_note(
						sprintf(
							/* translators: %s: System Flag */
							esc_html__( 'Dapp - Approved Payment. ID %s', 'dappwoo' ),
							$order_data
						)
					);

					$order->update_meta_data(
						\DappWoo::META_ORDER_PAYMENT_ID_INTERNAL,
						$response['data']['id']
					);

					$order->save();

				return true;
			}
		}
		return false;
	}

}
