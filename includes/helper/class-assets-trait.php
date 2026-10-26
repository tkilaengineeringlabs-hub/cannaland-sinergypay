<?php
/**
 * Class Woo Helper
 *
 * @package  Conexa\DappWoo\Helper;
 */

namespace Conexa\DappWoo\Includes\Helper;

trait AssetsTrait {

	/**
	 * Gets Assets Folder URL
	 *
	 * @return string
	 */
	public static function get_assets_folder_url() {
		return plugin_dir_url( \DappWoo::MAIN_FILE ) . 'assets';
	}

}
