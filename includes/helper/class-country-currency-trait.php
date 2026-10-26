<?php
/**
 * Class CountryCurrencyTrait
 *
 * @package  Conexa\DappWoo\Helper\CountryCurrencyTrait
 */

namespace Conexa\DappWoo\Includes\Helper;

/**
 * Database Trait
 */
trait CountryCurrencyTrait {

	/**
	 * Return the current transaction currency
	 * - Supports WOOCS currency switcher
	 */
	public static function get_currency() {
		if ( class_exists( 'WOOCS' ) ) {
			global $WOOCS;
			$currency = strtoupper( $WOOCS->storage->get_val( 'woocs_current_currency' ) );
		} else {
			$currency = get_woocommerce_currency();
		}
		return $currency;
	}

}
