<?php
/**
 * Class DappWooApiTest
 *
 * @package Conexa\DappWoo\tests\
 */

use Conexa\DappWoo\Api\DappWooApi;

/**
 * ClipApiTest.
 */
class DappWooApiTest extends WP_UnitTestCase {

	/**
	 * Test DappWooApi Connector Method.
	 */
	public function test_clip_api() {
		$settings = array(
			'api_token' => 'token',
			'debug'     => 'yes',
		);
		$api      = new DappWooApi( $settings );
		$this->assertEquals( $api->api_token, $settings['api_token'] );
		$this->assertEquals( $api->debug, $settings['debug'] );
		$this->assertEquals( $api->get_base_url(), DappWooApi::API_BASE_URL );
	}

}
