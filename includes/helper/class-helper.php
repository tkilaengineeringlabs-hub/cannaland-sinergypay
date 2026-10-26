<?php
/**
 * Class DappWoo Helper
 *
 * @package  Conexa\DappWoo\Helper;
 */

namespace Conexa\DappWoo\Includes\Helper;

/**
 * Helper Main Class
 */
class Helper {
	use AssetsTrait;
	use DebugTrait;
	use LoggerTrait;
	use ValidationsTrait;
	use SettingsTrait;
	use HandlePaymentTrait;
	use DatabaseTrait;
	use CountryCurrencyTrait;

	const VALIDATION_OK_ICON    = '<span class="dashicons dashicons-saved" style="color:green;"></span>';
	const VALIDATION_ERROR_ICON = '<span class="dashicons dashicons-no-alt" style="color:red;"></span>';
}
