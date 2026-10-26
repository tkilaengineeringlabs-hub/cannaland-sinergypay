<?php
/**
 * Class ApiConnectorTest
 *
 * @package Conexa\DappWoo\tests\
 */

/**
 * DAPP API Class
 */
class MockApiConnector extends \Conexa\DappWoo\Includes\Api\ApiConnector implements \Conexa\DappWoo\Includes\Api\ApiInterface {

	/**
	 * Mock Api Connector Constructor
	 *
	 * @param array $settings Settings to be used to create Mock Api.
	 */
	public function __construct( array $settings = array() ) {
		$this->base_url = $settings['base_url'];
	}

	/**
	 * Get base url
	 *
	 * @return string
	 */
	public function get_base_url() {
		return $this->base_url;
	}
}

require_once 'config.php';

/**
 * Class ApiConnectorTest
 *
 * @return string
 */
class ApiConnectorTest extends WP_UnitTestCase {

	const BASEURL = ECHO_URL;
	/**
	 * Test Post Method.
	 */
	public function test_post() {
		$METHOD       = '/POST';
		$data_to_send = array( 'data' => 'test' );
		$header       = array(
			'content-type' => 'application/json',
			'test'         => 'test',
		);

		$api = new MockApiConnector( array( 'base_url' => self::BASEURL ) );
		$res = $api->post(
			$METHOD,
			$data_to_send,
			$header
		);

		$this->assertEquals( $header['test'], $res['headers']['test'] );
		$this->assertEquals( $header['content-type'], $res['headers']['content-type'] );
		$this->assertEquals( serialize( $data_to_send ), serialize( $res['data'] ) );
		$this->assertContains( strtoupper( self::BASEURL . $METHOD ), strtoupper( $res['url'] ) );

	}

	/**
	 * Test Get Method.
	 */
	public function test_get() {
		$METHOD       = '/GET';
		$data_to_send = array( 'test' => 'test' );
		$header       = array(
			'content-type' => 'application/json',
			'test'         => 'test',
		);

		$api = new MockApiConnector( array( 'base_url' => self::BASEURL ) );
		$res = $api->get(
			$METHOD,
			$data_to_send,
			$header
		);

		$this->assertEquals( $header['test'], $res['headers']['test'] );
		$this->assertEquals( $header['content-type'], $res['headers']['content-type'] );
		$this->assertEquals( serialize( $data_to_send ), serialize( $res['args'] ) );
		$this->assertContains( strtoupper( self::BASEURL . $METHOD ), strtoupper( $res['url'] ) );

	}

	/**
	 * Test Put Method.
	 */
	public function test_put() {
		$METHOD       = '/PUT';
		$data_to_send = array( 'data' => 'test' );
		$header       = array(
			'content-type' => 'application/json',
			'test'         => 'test',
		);

		$api = new MockApiConnector( array( 'base_url' => self::BASEURL ) );
		$res = $api->put(
			$METHOD,
			$data_to_send,
			$header
		);

		$this->assertEquals( $header['test'], $res['headers']['test'] );
		$this->assertEquals( $header['content-type'], $res['headers']['content-type'] );
		$this->assertEquals( serialize( $data_to_send ), serialize( $res['data'] ) );
		$this->assertContains( strtoupper( self::BASEURL . $METHOD ), strtoupper( $res['url'] ) );

	}

	/**
	 * Test Delete Method.
	 */
	public function test_delete() {
		$METHOD       = '/DELETE';
		$data_to_send = array( 'data' => 'test' );
		$header       = array(
			'content-type' => 'application/json',
			'test'         => 'test',
		);

		$api = new MockApiConnector( array( 'base_url' => self::BASEURL ) );
		$res = $api->delete(
			$METHOD,
			$data_to_send,
			$header
		);

		$this->assertEquals( $header['test'], $res['headers']['test'] );
		$this->assertEquals( $header['content-type'], $res['headers']['content-type'] );
		$this->assertEquals( serialize( $data_to_send ), serialize( $res['data'] ) );
		$this->assertContains( strtoupper( self::BASEURL . $METHOD ), strtoupper( $res['url'] ) );

	}
}
