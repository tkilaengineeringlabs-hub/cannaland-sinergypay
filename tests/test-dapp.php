<?php
/**
 * Class DappTest
 *
 * @package Conexa\DappWoo\tests\
 */

require_once 'config.php';

/**
 * Test ClipTest.
 */
class DappTest extends WP_UnitTestCase {

	/**
	 * Test ClipTest .
	 */
	public function test_checksystem() {
		$ret = DappWoo::check_system();
		$this->assertTrue( $ret );
	}

	/**
	 * Test Settings Link
	 */
	public function test_setting_link() {
		$link_string = '<a href="http://example.org/wp-admin/admin.php?page=wc-settings&#038;tab=checkout&#038;section=wc_dappwoo">';
		$arr         = array();
		$arr         = DappWoo::create_settings_link( $arr );
		$this->assertContains( strtoupper( $link_string ), strtoupper( $arr[0] ) );
	}

	/**
	 * Test Register Scripts
	 */
	public function test_register_scripts() {
		$clip_gateway_script_name = 'dapp-gateway';

		DappWoo::register_front_scripts();
		wp_enqueue_script( $clip_gateway_script_name );

		$modal_flag    = false;
		$modal_gateway = false;

		global $wp_scripts;

		foreach ( $wp_scripts->queue as $handle ) {
			if ( $handle === $clip_gateway_script_name ) {
				$modal_gateway = true;
				$modal_flag    = true;
			}
		}

		$this->assertTrue( $modal_flag );
		$this->assertTrue( $modal_gateway );
	}

	/**
	 * Test init
	 */
	public function test_dapp_init() {
		$this->assertTrue( DappWoo::init() );
	}

	/**
	 * Test activation_nok
	 */
	public function test_activation_ok() {

		$this->assertNotNull( DappWoo::activation( 'dappwoo' ) );
	}

	/**
	 * Test activation_nok
	 */
	public function test_activation_nok() {
		$this->assertFalse( DappWoo::activation( 'none' ) );
	}

	/**
	 * Test activation_nok
	 */
	public function test_deactivation_ok() {
		$this->assertFalse( DappWoo::deactivation( 'dappwoo' ) );
	}

	/**
	 * Test activation_nok
	 */
	public function test_add_payment_method_ok() {
		$this->assertNotNull( DappWoo::add_payment_method( array() ) );

	}
}
