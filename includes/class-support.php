<?php
namespace Cannaland\Payments;
defined( 'ABSPATH' ) || exit;

final class Support {
    const GATEWAY = 'cannaland_spei';
    const OPTION = 'woocommerce_cannaland_spei_settings';
    const PREFIX = '_cannaland_payments_';

    public static function settings(): array {
        $settings = get_option( self::OPTION, array() );
        return is_array( $settings ) ? $settings : array();
    }
    public static function environment(): string {
        return (string) ( self::settings()['environment'] ?? 'sandbox' );
    }
    public static function valid_environment( string $env ): bool {
        return in_array( $env, array( 'sandbox', 'production' ), true );
    }
    public static function meta( $order, string $key ) {
        return $order->get_meta( self::PREFIX . $key, true );
    }
    public static function put( $order, string $key, $value ): void {
        $order->update_meta_data( self::PREFIX . $key, $value );
    }
    public static function id( $value ): bool {
        return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,200}$/D', $value );
    }
    public static function clabe( $value ): bool {
        return is_string( $value ) && 1 === preg_match( '/^[0-9]{18}$/D', $value );
    }
    // Decimal comparison without binary floating point, rounding, locale conversion or exponent notation.
    public static function money( $value ): ?string {
        if ( ! is_string( $value ) || ! preg_match( '/^([0-9]{1,10})(?:\.([0-9]{1,2}))?$/D', $value, $m ) ) { return null; }
        return ( ltrim( $m[1], '0' ) ?: '0' ) . '.' . str_pad( $m[2] ?? '', 2, '0' );
    }
    // Creation responses may contain JSON numbers. Never use this for signed webhook text.
    public static function response_money( $value ): ?string {
        if ( is_int( $value ) ) { return self::money( (string) $value ); }
        if ( is_float( $value ) ) {
            if ( ! is_finite( $value ) || $value < 0 || $value > 9999999999.99 ) { return null; }
            $decimal = number_format( $value, 2, '.', '' );
            // Accept only a value that round-trips unchanged: do not round fractional cents.
            if ( (float) $decimal !== $value ) { return null; }
            return self::money( $decimal );
        }
        return self::money( $value );
    }
    public static function lock( int $id ): bool {
        // Atomic unique option_name insert across workers; no transient/read-then-write lock.
        return add_option( 'cannaland_payments_order_lock_' . $id, time(), '', false );
    }
    public static function unlock( int $id ): void {
        delete_option( 'cannaland_payments_order_lock_' . $id );
    }
    public static function find_reference( string $reference ) {
        $condition = array( 'key' => self::PREFIX . 'reference', 'value' => $reference, 'compare' => '=' );
        $hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) &&
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $legacy = static function ( $query, $vars ) use ( $condition ) {
            if ( isset( $vars['cannaland_payments_reference_lookup'] ) ) { $query['meta_query'][] = $condition; }
            return $query;
        };
        $args = array( 'limit' => 2, 'return' => 'ids', 'type' => 'shop_order', 'payment_method' => self::GATEWAY );
        if ( $hpos ) { $args['meta_query'] = array( $condition ); }
        else {
            $args['cannaland_payments_reference_lookup'] = true;
            add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $legacy, 10, 2 );
        }
        try { $ids = wc_get_orders( $args ); }
        finally { if ( ! $hpos ) { remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $legacy, 10 ); } }
        return count( $ids ) === 1 ? wc_get_order( (int) reset( $ids ) ) : false;
    }
    public static function log( string $event, int $status = 0 ): void {
        if ( 'yes' !== ( self::settings()['logging'] ?? 'no' ) || ! function_exists( 'wc_get_logger' ) ) { return; }
        if ( ! in_array( $event, array( 'api_response', 'api_network', 'webhook_internal', 'checkout_internal' ), true ) ) { return; }
        wc_get_logger()->info( $event . ( $status ? ' HTTP ' . $status : '' ), array( 'source' => 'cannaland-payments' ) );
    }
    public static function error( string $code = 'cannaland_payments_unavailable' ): \WP_Error {
        return new \WP_Error( $code, __( 'No fue posible preparar la transferencia. Contacta a la tienda antes de volver a intentarlo.', 'cannaland-payments' ) );
    }
}
