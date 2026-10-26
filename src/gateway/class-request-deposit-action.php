<?php
/**
 * Class Request Deposit Action
 *
 * @package  Conexa\DappWoo\Gateway\RequestDepositAction
 */

namespace Conexa\DappWoo\Gateway;

use Conexa\DappWoo\Includes\Helper\Helper;
use Conexa\DappWoo\Sdk\DappWooSdk;
use Conexa\DappWoo\Gateway\WC_DappWoo;
/**
 * Orders Base Action Class
 */
abstract class RequestDepositAction {

	/**
	 * Run Action
	 *
	 * @param int $order_id ID for WC Order.
	 *
	 * @return string
	 */
	public static function run( $order_id ) {

		$order = wc_get_order( $order_id );
		$dapp_id = $order->get_meta( \DappWoo::META_ORDER_PAYMENT_ID );

        $options  = Helper::get_options( \DappWoo::GATEWAY_ID );
        $sdk      = new DappWooSdk( $options['api_token'] );

		if ( ! empty( $dapp_id ) ) {
            $response = $sdk->get_deposit( $order_id );
            if ( isset( $response['data'] ) && 'Ok' === $response['msg'] ) {
                $order->add_order_note(
                    sprintf(
                        /* translators: %s: System Flag */
                        esc_html__( 'Dapp qr_str obtained. ID %s', 'dappwoo' ),
                        $response['data']['id']
                    )
                );
            }
		} else {
            $response = $sdk->request_deposit( $order_id );
            if ( isset( $response['data'] ) && 'Ok' === $response['msg'] ) {
                $order->add_order_note(
                    sprintf(
                        /* translators: %s: System Flag */
                        esc_html__( 'Dapp qr_str created. ID %s', 'dappwoo' ),
                        $response['data']['id']
                    )
                );
                $order->update_meta_data(
                    \DappWoo::META_ORDER_PAYMENT_ID,
                    $response['data']['id']
                );
                $order->save();
            }
        }
        $qrStr = $response['data']['qr_str'];
        // UNICAMENTE REMPLAZAR EN SANDBOX
        if(!$options['is_production']) {
            $qrStr = str_replace('dapp', 'sandbox.dapp', $qrStr);
        }
        return $qrStr ?? '';
	}

	/**
	 * Validates Post parameters for Ajax Request
	 *
	 * @return bool/string
	 */
	public static function validate_ajax_request() {
		$errorCd = '';
		if ( ! isset( $_POST['order_id'] ) ) {
			$errorCd = 'missing order_id';
		} else {
			if ( empty( $_POST['order_id'] ) ) {
				$errorCd = 'order_id';
			}

			$order_id = filter_var( wp_unslash( $_POST['order_id'] ), FILTER_SANITIZE_NUMBER_INT );
			$order    = wc_get_order( $order_id );
			if ( ! $order ) {
				$errorCd = 'not order';
			}

			$payment_method = $order->get_payment_method();
			if ( empty( $payment_method ) ) {
				$errorCd = 'not payment method';
			}

			if ( \DappWoo::GATEWAY_ID !== $payment_method ) {
				$errorCd = 'not dapp';
			}
		}

		if ( ! empty( $errorCd ) ) {
			return $errorCd;
		}

		return true;
	}

	/**
	 * Ajax Callback
	 */
	public static function ajax_callback_wp() {

		$ret_validate = static::validate_ajax_request();
		if ( true !== $ret_validate ) {
			if ( defined( 'TEST_DAPP_RUNNING' ) && TEST_DAPP_RUNNING ) {
				return false;
			} else {
				wp_send_json_error( $ret_validate );
			}
		}
		//phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['order_id'] ) ) {
			$order_id = filter_var( wp_unslash( $_POST['order_id'] ), FILTER_SANITIZE_NUMBER_INT );
		}

		$ret = static::run( $order_id );
		if ( $ret ) {
			if ( defined( 'TEST_DAPP_RUNNING' ) && TEST_DAPP_RUNNING ) {
				return true;
			} else {
				wp_send_json_success( $ret );
			}
		} else {
			if ( defined( 'TEST_DAPP_RUNNING' ) && TEST_DAPP_RUNNING ) {
				return false;
			} else {
				$res = __( 'Sorry, this order is invalid and cannot be paid. Contact customer service.', 'dappwoo' );
				wp_send_json_error( $res );
			}
		}
		return false;
	}









}
