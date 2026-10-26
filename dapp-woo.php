<?php
/**
 * Plugin Name: Dapp para WooCommerce
 * Description: Pasarela de pago para WooCommerce
 * Version: 1.3.0
 * Requires PHP: 7.0
 * Author: Dapp
 * Author URI: https://dapp.mx
 * Text Domain: dappwoo
 * Domain Path: /i18n/languages
 * WC requires at least: 5.4.1
 * WC tested up to: 5.4
 */

defined( 'ABSPATH' ) || exit;

use Conexa\DappWoo\Includes\Helper\Helper;

add_action( 'plugins_loaded', array( 'DappWoo', 'init' ) );
add_action( 'activated_plugin', array( 'DappWoo', 'activation' ) );
add_action( 'deactivated_plugin', array( 'DappWoo', 'deactivation' ) );

if ( ! class_exists( 'DappWoo' ) ) {
	/**
	 * Plugin's base Class
	 */
	class DappWoo {

		const VERSION                        = '1.3.0';
		const PLUGIN_NAME                    = 'dappwoo';
		const MAIN_FILE                      = __FILE__;
		const MAIN_DIR                       = __DIR__;
		const GATEWAY_ID                     = 'wc_dappwoo';
		const META_ORDER_PAYMENT_ID          = '_DAPP_PAYMENT_ID';
		const META_ORDER_PAYMENT_ID_INTERNAL = '_DAPP_INTERNAL_DEPOSIT_ID';

		/**
		 * Inits our plugin
		 *
		 * @return void
		 */
		public static function init() {
			if ( ! self::check_system() ) {
				return false;
			}

			spl_autoload_register(
				function ( $class ) {
					// Plugin base Namespace.

					if ( strpos( $class, 'DappWoo' ) === false ) {
						return;
					}

					if ( strpos( $class, 'Includes' ) === false ) {
						$boiler = 'src';
					} else {
						$boiler = 'includes';
					}

					$class     = str_replace( '\\', '/', $class );
					$parts     = explode( '/', $class );
					$classname = array_pop( $parts );

					$filename = $classname;
					$filename = str_replace( 'WooCommerce', 'Woocommerce', $filename );
					$filename = str_replace( 'WC_', 'Wc', $filename );
					$filename = str_replace( 'WC', 'Wc', $filename );
					$filename = preg_replace( '/([A-Z])/', '-$1', $filename );
					$filename = 'class' . $filename;
					$filename = strtolower( $filename );
					$folder   = strtolower( array_pop( $parts ) );

					if ( 'class-dapp-woo' === $filename ) {
						return;
					}

					require_once plugin_dir_path( __FILE__ ) . $boiler . '/' . $folder . '/' . $filename . '.php';
				}
			);

			include_once __DIR__ . '/src/Hooks.php';
			self::load_textdomain();
			Helper::init();
			return true;
		}

		/**
		 * Checks system requirements
		 *
		 * @return bool
		 */
		public static function check_system() {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$system = self::check_components();

			if ( $system['flag'] ) {
				deactivate_plugins( plugin_basename( __FILE__ ) );
				echo '<div class="notice notice-error is-dismissible">'
				. '<p>' .
					sprintf(
						/* translators: %s: System Flag */
						esc_html__(
							'<strong>%1$s</strong> Requiere al menos %2$s versión %3$s o superior.',
							'ocasa'
						),
						esc_html( self::PLUGIN_NAME ),
						esc_html( $system['flag'] ),
						esc_html( $system['version'] )
					) .
					'</p>'
				. '</div>';
				return false;
			}

			if ( ! class_exists( 'WooCommerce' ) ) {
				deactivate_plugins( plugin_basename( __FILE__ ) );
				echo '<div class="notice notice-error is-dismissible">'
				. '<p>' .
					sprintf(
						/* translators: %s: System Flag */
						esc_html__(
							'WooCommerce debe estar activo antes de usar <strong>%s</strong>',
							'ocasa'
						),
						esc_html( self::PLUGIN_NAME )
					) .
					'</p>'
				. '</div>';
				return false;
			}
			return true;
		}
		/**
		 * Check the components required for the plugin to work (PHP, WordPress and WooCommerce)
		 *
		 * @return array
		 */
		private static function check_components() {
			global $wp_version;
			$flag    = false;
			$version = false;

			if ( version_compare( PHP_VERSION, '7.0', '<' ) ) {
				$flag    = 'PHP';
				$version = '7.0';
			} elseif ( version_compare( $wp_version, '5.4', '<' ) ) {
				$flag    = 'WordPress';
				$version = '5.4';
			} elseif (
			! defined( 'WC_VERSION' ) ||
			version_compare( WC_VERSION, '3.8.0', '<' )
			) {
				$flag    = 'WooCommerce';
				$version = '3.8.0';
			}
			return array(
				'flag'    => $flag,
				'version' => $version,
			);
		}

		/**
		 * Loads the plugin text domain
		 *
		 * @return void
		 */
		public static function load_textdomain() {
			load_plugin_textdomain( 'dappwoo', false, basename( dirname( __FILE__ ) ) . '/i18n/languages' );
		}

		/**
		 * Create a link to the settings page, in the plugins page
		 *
		 * @param array $links Links for plugin.
		 * @return array
		 */
		public static function create_settings_link( array $links ) {
			$link =
				'<a href="' .
				esc_url(
					get_admin_url( null, 'admin.php?page=wc-settings&tab=checkout&section=wc_dappwoo' )
				) .
				'">' .
				__( 'Ajustes', 'dappwoo' ) .
				'</a>';
			array_unshift( $links, $link );
			return $links;
		}

		/**
		 * Activation Plugin Actions
		 *
		 * @param string $plugin Plugin Name.
		 * @return bool
		 */
		public static function activation( $plugin ) {

			if ( plugin_basename( self::MAIN_FILE ) === $plugin ) {
				wp_safe_redirect(
					admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_dappwoo' )
				);
				exit();
			}
			return false;
		}

		/**
		 * Deactivation Plugin Actions
		 *
		 * @param int $plugin comment about this variable.
		 * @return void
		 */
		public static function deactivation( $plugin ) {
			Helper::set_option( 'api_token', '' );
			return false;
		}

		/**
		 * Adds our payment method to WooCommerce
		 *
		 * @param array $gateways Gateways setted on Woo.
		 * @return array
		 */
		public static function add_payment_method( $gateways ) {
			$gateways[] = '\Conexa\DappWoo\Gateway\WC_DappWoo';
			return $gateways;
		}

		/**
		 * Registers all scripts to be loaded laters
		 *
		 * @return void
		 */
		public static function register_front_scripts() {
			wp_register_script(
				'dapp-gateway',
				Helper::get_assets_folder_url() . '/js/gateway.js',
				array( 'jquery' ),
				\DappWoo::VERSION,
				true
			);
		}

	}

	// --- HPOS WooCommerce Compatibility
	add_action( 'before_woocommerce_init', function() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	} );
}
