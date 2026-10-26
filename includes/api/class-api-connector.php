<?php
/**
 * Class Woo Api
 *
 * @package  Conexa\Woo\ApiWoo;
 */

namespace Conexa\DappWoo\Includes\Api;

use Conexa\DappWoo\Includes\Helper\Helper;

/**
 * Abstract API Class
 */
abstract class ApiConnector {

	/**
	 * Executes API Request
	 *
	 * @param string $method HTTP Method Request.
	 * @param string $url URL Target Request.
	 * @param array  $body Data to send.
	 * @param array  $headers HTTP Headers for Requests.
	 * @return string
	 */
	protected function exec(
		string $method,
		string $url,
		array $body,
		array $headers
	) {

        $args = array( 'method' => $method, 'headers' => $headers, 'timeout' => 20, 'redirection' => 0 );
        if ( 'GET' !== strtoupper( $method ) ) {
            $args['body'] = wp_json_encode( $body, JSON_UNESCAPED_UNICODE );
            if ( false === $args['body'] ) {
                return new \WP_Error( 'dapp_json_request', 'Invalid request data.' );
            }
        }
        $request = wp_safe_remote_request( $url, $args );
        if ( is_wp_error( $request ) ) {
            Helper::log( 'API network failure.' );
            return new \WP_Error( 'dapp_network', 'Payment service unavailable.' );
        }
        $status = (int) wp_remote_retrieve_response_code( $request );
        Helper::log( 'API HTTP status: ' . $status );
        if ( $status < 200 || $status >= 300 ) {
            return new \WP_Error( 'dapp_http', 'Unexpected payment service HTTP status.', array( 'status' => $status ) );
        }
        $raw = wp_remote_retrieve_body( $request );
        if ( 204 === $status && '' === $raw ) {
            return array();
        }
        $response = json_decode( $raw, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $response ) ) {
            return new \WP_Error( 'dapp_json_response', 'Invalid payment service response.' );
        }
        return $response;

	}

	/**
	 * Executes Post Request
	 *
	 * @param string $endpoint URL Target Request.
	 * @param array  $body Data to send.
	 * @param array  $headers HTTP Headers for Requests.
	 * @return string
	 */
	public function post( string $endpoint, array $body = array(), array $headers = array() ) {
		$url = $this->get_base_url() . $endpoint;
		return $this->exec( 'POST', $url, $body, $headers );
	}

	/**
	 * Executes Get Request
	 *
	 * @param string $endpoint URL Target Request.
	 * @param array  $body Data to send.
	 * @param array  $headers HTTP Headers for Requests.
	 * @return string
	 */
	public function get( string $endpoint, array $body = array(), array $headers = array() ) {
		$url = $this->get_base_url() . $endpoint;
		if ( ! empty( $body ) ) {
			$url .= '?' . http_build_query( $body );
		}
		return $this->exec( 'GET', $url, array(), $headers );
	}

	/**
	 * Executes Put Request
	 *
	 * @param string $endpoint URL Target Request.
	 * @param array  $body Data to send.
	 * @param array  $headers HTTP Headers for Requests.
	 * @return string
	 */
	public function put( string $endpoint, array $body = array(), array $headers = array() ) {
		$url = $this->get_base_url() . $endpoint;
		return $this->exec( 'PUT', $url, $body, $headers );
	}

	/**
	 * Executes Delete Request
	 *
	 * @param string $endpoint URL Target Request.
	 * @param array  $body Data to send.
	 * @param array  $headers HTTP Headers for Requests.
	 * @return string
	 */
	public function delete( string $endpoint, array $body = array(), array $headers = array() ) {
		$url = $this->get_base_url() . $endpoint;
		return $this->exec( 'DELETE', $url, $body, $headers );
	}
	/**
	 *  Get Base Url
	 *
	 * @return String
	 */
	abstract public function get_base_url();
}
