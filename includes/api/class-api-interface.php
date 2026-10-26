<?php
/**
 * Class Woo Api
 *
 * @package  Conexa\Woo\ApiWoo;
 */

namespace Conexa\DappWoo\Includes\Api;

defined( 'ABSPATH' ) || exit();
/**
 * API Interface Class
 */
interface ApiInterface {

	/**
	 * Executes Get Request
	 *
	 * @param string $endpoint URL Target Request.
	 * @param array  $body Data to send.
	 * @param array  $headers HTTP Headers for Requests.
	 * @return string
	 */
	public function get(
		string $endpoint,
		array $body = array(),
		array $headers = array()
	);

	/**
	 * Executes Post Request
	 *
	 * @param string $endpoint URL Target Request.
	 * @param array  $body Data to send.
	 * @param array  $headers HTTP Headers for Requests.
	 * @return string
	 */
	public function post(
		string $endpoint,
		array $body = array(),
		array $headers = array()
	);

	/**
	 * Executes Put Request
	 *
	 * @param string $endpoint URL Target Request.
	 * @param array  $body Data to send.
	 * @param array  $headers HTTP Headers for Requests.
	 * @return string
	 */
	public function put(
		string $endpoint,
		array $body = array(),
		array $headers = array()
	);

	/**
	 * Executes Delete Request
	 *
	 * @param string $endpoint URL Target Request.
	 * @param array  $body Data to send.
	 * @param array  $headers HTTP Headers for Requests.
	 * @return string
	 */
	public function delete(
		string $endpoint,
		array $body = array(),
		array $headers = array()
	);
}
