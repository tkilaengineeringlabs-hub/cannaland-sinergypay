<?php
/**
 * Class Webhooks
 *
 * @package  Conexa\DappWoo\Orders\Webhooks
 */

namespace Conexa\DappWoo\Orders;

use Conexa\DappWoo\Includes\Helper\Helper;
use Conexa\DappWoo\Sdk\DappWooSdk;
use Conexa\DappWoo\Gateway\WC_DappWoo;

defined( 'ABSPATH' ) || exit();

/**
 * WebHook's base Class
 */
class Webhooks {

	const OK    = 'HTTP/1.1 200 OK';
	const ERROR = 'HTTP/1.1 500 ERROR';

	/**
	 * Receives the webhook and check if it's valid to proceed
	 *
	 * @param string $data Webhook json Data for testing purpouses.
	 * @param string $test Webhook json Data for testing purpouses.
	 *
	 * @return bool
	 */
	public static function listener( string $data = null, $test = '' ) {

		// Takes raw data from the request.
		if ( is_null( $data ) || empty( $data ) ) {
			$json = file_get_contents( 'php://input' );
		} else {
			$json = $data;
		}

		Helper::log( 'Webhook received' );
		Helper::log(
			__FUNCTION__ .
				__( '- Webhook received from Dapp:', 'dappwoo' ) .
				$json
		);

		$process = self::process_webhook( $json, $test );

		if ( is_null( $data ) ) {
			// Real Webhook.
			if ( $process ) {
				if ( defined( 'TEST_DAPP_RUNNING' ) && TEST_DAPP_RUNNING ) {
					return true;
				} else {
					header( self::OK );
				}
			} else {
				if ( defined( 'TEST_DAPP_RUNNING' ) && TEST_DAPP_RUNNING ) {
					return false;
				} else {
					header( self::ERROR );
					wp_die(
						esc_html__( 'WooCommerce Dapp Webhook not valid.', 'dappwoo' ),
						'Dapp Webhook',
						array( 'response' => 500 )
					);
				}
			}
		} else {
			// For testing purpouse.
			return $process;
		}
	}


	/**
	 * Process Webhook
	 *
	 * @param json   $json Webhook data for.
	 * @param string $test Webhook json Data for testing purpouses.
	 *
	 * @return bool
	 */
	public static function process_webhook( $json, $test = '' ) {
		// Converts it into a PHP object.
		$data = json_decode( $json, true );

		if ( self::validate_input( $data ) ) {
			$order_id = self::get_order_id( $data );
			return Helper::handle_payment( $order_id, $test );
		}

		return false;
	}


	/**
	 * Get Order Id from Data Json
	 *
	 * @param array $data Webhook data.
	 *
	 * @return int
	 */
	public static function get_order_id( array $data ) {

		if ( isset( $data['code'] ) ) {
			$dapp_id = filter_var( $data['code'], FILTER_SANITIZE_STRING );
			return Helper::find_order_by_itemmeta_value(
				\DappWoo::META_ORDER_PAYMENT_ID,
				$dapp_id
			);
		}

		return false;
	}

	/**
	 * Validates the incoming webhook
	 *
	 * @param array $data Webhook data to be validated.
	 *
	 * @return bool
	 */
	public static function validate_input( array $data ) {
		$return = true;
		$data   = wp_unslash( $data );

		if ( ! isset( $data['code'] ) || empty( $data['code'] ) ) {
			Helper::log(
				__FUNCTION__ .
					__( '- Webhook received without code.', 'dappwoo' )
			);
			$return = false;
		}

		if ( $return ) {
			$order_id = self::get_order_id( $data );
			if ( empty( $order_id ) || is_null( $order_id ) || ! is_int( $order_id ) ) {
				Helper::log(
					__FUNCTION__ .
						__(
							'- Webhook received without order related.',
							'dappwoo'
						)
				);
				$return = false;
			}
		}
		return $return;
	}


}
