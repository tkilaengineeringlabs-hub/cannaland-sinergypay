<?php
namespace Conexa\DappWoo\Includes\Helper;

trait DatabaseTrait {
    public static function find_order_by_itemmeta_value( string $meta_key, string $meta_value ) {
        $condition = array( 'key' => $meta_key, 'value' => $meta_value, 'compare' => '=' );
        $legacy = function ( $query, $vars ) use ( $condition ) {
            if ( isset( $vars['dapp_lookup'] ) ) { $query['meta_query'][] = $condition; }
            return $query;
        };
        $hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) &&
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $args = array( 'limit' => 2, 'return' => 'ids', 'type' => 'shop_order', 'payment_method' => \DappWoo::GATEWAY_ID );
        if ( $hpos ) { $args['meta_query'] = array( $condition ); }
        else { $args['dapp_lookup'] = true; add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $legacy, 10, 2 ); }
        try { $ids = wc_get_orders( $args ); }
        finally { if ( ! $hpos ) { remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $legacy, 10 ); } }
        return count( $ids ) === 1 ? (int) reset( $ids ) : false;
    }

    // add_option uses the unique option_name index across PHP workers. No unsafe TTL takeover.
    public static function acquire_order_lock( $order_id ) {
        return add_option( 'dappwoo_order_lock_' . (int) $order_id, time(), '', false );
    }
    public static function release_order_lock( $order_id ) {
        delete_option( 'dappwoo_order_lock_' . (int) $order_id );
    }
}
