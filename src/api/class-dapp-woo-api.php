<?php
/**
 * Class DappWoo Api Class
 *
 * @package  Conexa\DappWoo\Api;
 */

namespace Conexa\DappWoo\Api;

use Conexa\DappWoo\Includes\Helper\Helper;

defined( 'ABSPATH' ) || exit();
/**
 * DappWoo API Class
 */
class DappWooApi extends \Conexa\DappWoo\Includes\Api\ApiConnector implements \Conexa\DappWoo\Includes\Api\ApiInterface {

	const API_BASE_URL_SANDBOX = 'https://sandbox.dapp.mx/v2';
	const API_BASE_URL = 'https://api.dapp.mx/v2';
    public string $api_token;
    public mixed $debug;

	/**
	 * Class Constructor
	 *
	 * @param array $settings DappWoo Settings Object.
	 */
	public function __construct( array $settings = array() ) {
		$this->api_token = is_string( $settings['api_token'] ?? null ) ? $settings['api_token'] : '';
		$this->debug     = $settings['debug'] ?? false;
	}

	/**
	 *  Get Base Url
	 *
	 * @return String
	 */
	public function get_base_url() {
        $options  = Helper::get_options( \DappWoo::GATEWAY_ID );
        if($options['is_production']){
            return $this::API_BASE_URL;
        }
		return $this::API_BASE_URL_SANDBOX;
	}
}
