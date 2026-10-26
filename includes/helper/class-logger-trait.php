<?php
/**
 * Class LoggerTrait
 *
 * @package  Conexa\DappWoo\Helper\LoggerTrait
 */

namespace Conexa\DappWoo\Includes\Helper;

/**
 * Logger Trait
 */
trait LoggerTrait {
	/**
	 * Define WC Logger Object
	 *
	 * @var logger $logger
	 */
	private static $logger;
	/**
	 * Define Source Domain for Logger
	 *
	 * @var source $source comment about this variable.
	 */
	private static $source = \DappWoo::PLUGIN_NAME;

	/**
	 * Clean Logger (for testing purpouses)
	 */
	public static function clean() {
		self::$logger = null;
	}

	/**
	 * Inits our logger singleton
	 *
	 * @return logger
	 */
	public static function init() {
		if ( function_exists( 'wc_get_logger' ) && ! isset( self::$logger ) ) {
			self::$logger = wc_get_logger();
		}
		return self::$logger;
	}

	/**
	 * Logs an info message
	 *
	 * @param mixed $msg Message.
	 * @return void
	 */
    public static function log_info( $msg ) { self::write_log( 'info', $msg ); }
    public static function log_error( $msg ) { self::write_log( 'error', $msg ); }
    public static function log_warning( $msg ) { self::write_log( 'warning', $msg ); }
    public static function log_debug( $msg ) { self::write_log( 'debug', $msg ); }

    private static function write_log( $level, $msg ) {
        if ( 'yes' !== self::get_option( 'debug', 'no' ) ) { return; }
        // Only fixed diagnostic events are allowed, never caller/API content.
        if ( ! is_string( $msg ) || ! preg_match( '/^(API network failure\.|API HTTP status: [0-9]{3}|Webhook received|Payment confirmation blocked: API contract unverified\.)$/D', $msg ) ) { return; }
        $logger = self::init();
        if ( $logger ) { $logger->log( $level, $msg, array( 'source' => self::$source ) ); }
    }
}
