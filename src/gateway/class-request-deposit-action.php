<?php
namespace Conexa\DappWoo\Gateway;
use Conexa\DappWoo\Includes\Helper\Helper;
use Conexa\DappWoo\Sdk\DappWooSdk;

defined( 'ABSPATH' ) || exit;
abstract class RequestDepositAction {
    public static function valid_id( $id ) {
        return is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,200}$/D', $id );
    }
    public static function run( $order_id ) {
        if ( ! Helper::acquire_order_lock( $order_id ) ) { return ''; }
        try {
            $order = wc_get_order( $order_id );
            if ( ! $order || \DappWoo::GATEWAY_ID !== $order->get_payment_method() || ! $order->needs_payment() ) { return ''; }
            $id = $order->get_meta( \DappWoo::META_ORDER_PAYMENT_ID );
            $options = Helper::get_options();
            if ( empty( $options['api_token'] ) ) { return ''; }
            $sdk = new DappWooSdk( $options['api_token'] );
            if ( '' !== $id ) {
                if ( ! self::valid_id( $id ) ) { return ''; }
                $response = $sdk->get_deposit( $order_id );
            } else {
                if ( $order->get_meta( '_DAPP_CREATION_PENDING' ) ) { return ''; }
                // Persist before HTTP: an ambiguous result must never cause an automatic second POST.
                $order->update_meta_data( '_DAPP_CREATION_PENDING', 'yes' );
                $order->save();
                $response = $sdk->request_deposit( $order_id );
            }
            if ( is_wp_error( $response ) || ! is_array( $response ) || 'Ok' !== ( $response['msg'] ?? null ) ||
                ! is_array( $response['data'] ?? null ) || ! self::valid_id( $response['data']['id'] ?? null ) ||
                ( '' !== $id && $id !== $response['data']['id'] ) ) { return ''; }
            $data = $response['data'];
            // Save the code even if the redirect is missing, so retries cannot create another code.
            if ( '' === $id ) {
                $order->update_meta_data( \DappWoo::META_ORDER_PAYMENT_ID, $data['id'] );
                $order->delete_meta_data( '_DAPP_CREATION_PENDING' );
                $order->save();
            }
            if ( ! is_string( $data['qr_str'] ?? null ) ) { return ''; }
            $url = wp_parse_url( $data['qr_str'] );
            if ( ! is_array( $url ) || 'https' !== ( $url['scheme'] ?? '' ) || isset( $url['user'] ) || isset( $url['pass'] ) || isset( $url['port'] ) ||
                ! in_array( $url['host'] ?? '', $options['is_production'] ? array( 'dapp.mx' ) : array( 'dapp.mx', 'sandbox.dapp.mx' ), true ) ||
                ( $url['path'] ?? '' ) !== '/c/' . $data['id'] ) { return ''; }
            if ( ! $options['is_production'] && 'dapp.mx' === $url['host'] ) {
                return 'https://sandbox.dapp.mx' . substr( $data['qr_str'], strlen( 'https://dapp.mx' ) );
            }
            return $data['qr_str'];
        } finally { Helper::release_order_lock( $order_id ); }
    }
    public static function validate_ajax_request() {
        if ( ! is_string( $_POST['nonce'] ?? null ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), \DappWoo::GATEWAY_ID ) ) { return 'Invalid request.'; }
        $id = $_POST['order_id'] ?? null;
        if ( ! is_scalar( $id ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $id ) || false === filter_var( $id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) ) { return 'Invalid order.'; }
        $order = wc_get_order( (int) $id );
        if ( ! $order || \DappWoo::GATEWAY_ID !== $order->get_payment_method() || ! $order->needs_payment() ) { return 'Invalid order.'; }
        $owner = $order->get_customer_id();
        $key = $_POST['order_key'] ?? null;
        $valid_key = is_string( $key ) && '' !== $order->get_order_key() && hash_equals( $order->get_order_key(), sanitize_text_field( wp_unslash( $key ) ) );
        if ( $owner ? ( (int) get_current_user_id() !== (int) $owner ) : ! $valid_key ) { return 'Invalid order access.'; }
        return true;
    }
    public static function ajax_callback_wp() {
        if ( true !== self::validate_ajax_request() ) { wp_send_json_error( 'Invalid payment request.', 403 ); return; }
        $url = self::run( (int) $_POST['order_id'] );
        if ( $url ) { wp_send_json_success( $url ); return; }
        wp_send_json_error( 'Unable to obtain payment link. Contact customer service before retrying.', 409 );
    }
}
