<?php
/**
 * Class SettingsTrait
 *
 * @package  Conexa\DappWoo\Helper\SettingsTrait
 */

namespace Conexa\DappWoo\Includes\Helper;

/**
 * Settings Trait
 */
trait SettingsTrait {

	/**
	 * Gets a plugin option
	 *
	 * @param string  $key Key value searching for.
	 * @param boolean $default A dafault value in case Key is not founded.
	 *
	 * @return mixed
	 */
	public static function get_option( string $key, $default = false ) {
		return isset( self::get_options()[ $key ] ) &&
			! empty( self::get_options()[ $key ] )
			? self::get_options()[ $key ]
			: $default;
	}

	/**
	 * Get options
	 *
	 * @param string $gateway Gateway Name.
	 *
	 * @return array
	 */
	public static function get_options( $gateway = 'wc_dappwoo' ) {
		$option = get_option( 'woocommerce_' . $gateway . '_settings' );
		return array(
			'enabled'     => isset( $option['enabled'] ) ? $option['enabled'] : 'no',
			'title'       => isset( $option['title'] ) ? $option['title'] : __( 'Paga con dapp®', 'dappwoo' ),
			'description' => isset( $option['description'] ) ? $option['description'] : __( 'La revolución de los pagos, hoy.', 'dappwoo' ),

			'api_token'   => isset( $option['wc_dappwoo_api_token'] )
				? $option['wc_dappwoo_api_token']
				: '',
			'debug'       => isset( $option['wc_dappwoo_log_enabled'] )
				? $option['wc_dappwoo_log_enabled']
				: 'no',
            'is_production' => isset( $option['wc_dappwoo_is_production']) ? $option['wc_dappwoo_is_production'] == 'production' : false
		);
	}

	/**
	 * Set options
	 *
	 * @param string $key Key value searching for.
	 * @param string $value A value to be setted.
	 * @param string $gateway Gateway Name.
	 */
	public static function set_option( string $key, string $value, string $gateway = 'wc_dappwoo' ) {
		$option                         = get_option( 'woocommerce_' . $gateway . '_settings' );
		$option[ 'wc_dappwoo_' . $key ] = $value;
		update_option( 'woocommerce_' . $gateway . '_settings', $option );
	}

}
