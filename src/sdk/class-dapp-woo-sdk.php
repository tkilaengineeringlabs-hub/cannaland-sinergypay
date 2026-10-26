<?php
/**
 * Class DappSdk
 *
 * @package  Ecomerciar\Dapp\Helper\DappSdk
 */

namespace Conexa\DappWoo\Sdk;

use Conexa\DappWoo\Includes\Helper\Helper;
use Conexa\DappWoo\Api\DappWooApi;

/**
 * Main Class DappWoo Sdk.
 */
class DappWooSdk {

	/**
	 * Defines DappWoo API Token
	 *
	 * @var string $api_token API Token;
	 */
	private string $api_token;
	private $debug;

	const JSON = 'application/json';
    public DappWooApi $api;

	/**
	 * Constructor.
	 *
	 * @param string  $api_token DappWoo API Token.
	 * @param boolean $debug Debug Switch.
	 */
	public function __construct(
		string $api_token,
		bool $debug = false
	) {
		$api_token       = base64_encode( ':' . $api_token );
		$this->api_token = 'Basic ' . $api_token;
		$this->api       = new DappWooApi(
			array(
				'api_token' => $api_token,
				'debug'     => $debug,
			)
		);
		$this->debug     = $debug;
	}

	/**
	 * Validate_receipt
	 *
	 * @return bool
	 */
	public function validate_receipt() {

		try {
			$res = $this->api->get(
				'/dapp-codes/wallets',
				array(),
				array(
					'Authorization' => $this->api_token,
				)
			);

		} catch ( \Exception $e ) {
			Helper::log_error( __FUNCTION__ . ': ' . $e->getMessage() );
			return array();
		}
		if ( ! empty( $this->handle_response( $res, __FUNCTION__ )['data'] ) ) {
            $webHookData = $this->get_webhook_data();
			if ( empty( $webHookData['data'] ) ) {
				$this->post_webhook_data();
			} else {
                $url = get_site_url( null, '/wc-api/wc-dapp' );
                $exist = false;
                foreach($webHookData['data'] as $value){
                    if($value['url'] == $url){
                        $exist = true;
                        break;
                    }
                }
                if(!$exist){
                    $this->post_webhook_data();
                }
            }

			return true;
		}
		Helper::set_option( 'api_token', '' );
		return false;
	}

	/**
	 * Create deposit
	 *
	 * @param int $order_id ID for WC Order.
	 *
	 * @return array
	 */
	public function request_deposit( $order_id ) {
		$order = wc_get_order( $order_id );

		$data_to_send = array(
			'amount'       => floatval( $order->get_total() ),
			'description'  => __( 'Shop at ', 'dappwoo' ) . get_bloginfo( 'name' ) . ' #' . $order_id,
			'reference'    => 'WC' . $order_id . '-' . wp_generate_uuid4(),
			'success_page' => $order->get_checkout_order_received_url(),
		);

		try {
			$res = $this->api->post(
				'/dapp-codes/',
				$data_to_send,
				array(
					'User-Agent'    => 'MiIntegracion1.0',
					'content-type'  => self::JSON,
					'Authorization' => $this->api_token,
				)
			);
		} catch ( \Exception $e ) {
			Helper::log_error( __FUNCTION__ . ': ' . $e->getMessage() );
			return false;
		}

		return $this->handle_response( $res, __FUNCTION__ );
	}

    /**
	 * Get deposit
	 *
	 * @param int $order_id ID for WC Order.
	 *
	 * @return array
	 */
	public function get_deposit( $order_id ) {
		$order = wc_get_order( $order_id );
		$dapp_id = $order->get_meta( \DappWoo::META_ORDER_PAYMENT_ID );

		try {
			$res = $this->api->get(
				'/dapp-codes/' . $dapp_id,
				array(),
				array(
					'User-Agent'    => 'MiIntegracion1.0',
					'content-type'  => self::JSON,
					'Authorization' => $this->api_token,
				)
			);
		} catch ( \Exception $e ) {
			Helper::log_error( __FUNCTION__ . ': ' . $e->getMessage() );
			return false;
		}

		return $this->handle_response( $res, __FUNCTION__ );
	}

	/**
	 * Create request_refund
	 *
	 * @param int   $order_id ID for WC Order.
	 * @param array $options ID for WC Order.
	 *
	 * @return array
	 */
	public function request_refund( $order_id, $options = array(
		'amount'     => 0,
		'reason'     => '',
		'cashout_id' => '',
	) ) {

		try {
			$res = $this->api->post(
				'/payments/' . $options['cashout_id'] . '/refund/full',
				array(),
				array(
					'User-Agent'    => 'MiIntegracion1.0',
					'content-type'  => self::JSON,
					'Authorization' => $this->api_token,
				)
			);
		} catch ( \Exception $e ) {
			Helper::log_error( __FUNCTION__ . ': ' . $e->getMessage() );
			return array();
		}

		return $this->handle_response( $res, __FUNCTION__ );
	}

	/**
	 * Get payment data
	 *
	 * @param string $payment_id Dapp Payment Intention.
	 *
	 * @return array
	 */
	public function get_payment_data( $payment_id ) {
		try {
			$res = $this->api->get(
				'/dapp-codes/' . $payment_id . '/payment',
				array(),
				array(
					'User-Agent'    => 'MiIntegracion1.0',
					'content-type'  => self::JSON,
					'Authorization' => $this->api_token,
				)
			);
		} catch ( \Exception $e ) {
			Helper::log_error( __FUNCTION__ . ': ' . $e->getMessage() );
			return array();
		}
		return $this->handle_response( $res, __FUNCTION__ );
	}

	/**
	 * Handle Response
	 *
	 * @param array  $response Response data.
	 * @param string $function_name Function function is calling from.
	 *
	 * @return array
	 */
	protected function handle_response( $response = array(), string $function_name = '' ) {
		return $response;
	}

	/**
	 * Create get_webhook_data
	 *
	 * @return array
	 */
	protected function get_webhook_data() {
		try {
			$res = $this->api->get(
				'/webhooks',
				array(),
				array(
					'User-Agent'    => 'MiIntegracion1.0',
					'content-type'  => self::JSON,
					'Authorization' => $this->api_token,
				)
			);
		} catch ( \Exception $e ) {
			Helper::log_error( __FUNCTION__ . ': ' . $e->getMessage() );
			return array();
		}
		return $this->handle_response( $res, __FUNCTION__ );
	}

	/**
	 * Create post_webhook_data
	 *
	 * @return array
	 */
	protected function post_webhook_data() {

		$data_to_send = array(
			'url' => get_site_url( null, '/wc-api/wc-dapp' ),
		);

		try {
			$res = $this->api->post(
				'/webhooks',
				$data_to_send,
				array(
					'User-Agent'    => 'MiIntegracion1.0',
					'content-type'  => self::JSON,
					'Authorization' => $this->api_token,
				)
			);
		} catch ( \Exception $e ) {
			Helper::log_error( __FUNCTION__ . ': ' . $e->getMessage() );
			return array();
		}
		return $this->handle_response( $res, __FUNCTION__ );
	}
}
