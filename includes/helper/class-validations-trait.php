<?php
/**
 * Class ValidationsTrait
 *
 * @package  Conexa\DappWoo\Helper\ValidationsTrait
 */

namespace Conexa\DappWoo\Includes\Helper;

use Conexa\DappWoo\Sdk\DappWooSdk;
use Conexa\DappWoo\Gateway\WC_DappWoo;
/**
 * Validations Trait
 */
trait ValidationsTrait {

	/**
	 * Validate HTML
	 *
	 * @param bool   $bool Value to test.
	 * @param string $message_ok Message to show if $bool is true.
	 * @param string $message_error Message to show if $bool is false.
	 *
	 * @return Array
	 */
	public static function validate_html( $bool, $message_ok, $message_error ) {
		return $bool
			? self::VALIDATION_OK_ICON . $message_ok
			: self::VALIDATION_ERROR_ICON . $message_error;
	}

	/**
	 * Validate Credentials
	 *
	 * @return bool
	 */
	public static function validate_credentials() {

		$sdk = new DappWooSdk(
			self::get_option( 'api_token' ),
		);

		return $sdk->validate_receipt();
	}

	/**
	 * Validate Credentials HTML
	 *
	 * @return bool
	 */
	public static function validate_credentials_html() {
		return self::validate_html(
			self::validate_credentials(),
			__( 'Credenciales válidas.', 'dappwoo' ),
			__( 'Credenciales inválidas.', 'dappwoo' )
		);
	}

	/**
	 * Validations All Html (print erros)
	 *
	 * @return string
	 */
	public static function validate_all_html() {

		global $current_section;
		if ( 'wc_dappwoo' === $current_section ) {
			return '<p>' . self::validate_credentials_html() . '</p>';
		}
		return '';
	}
}
