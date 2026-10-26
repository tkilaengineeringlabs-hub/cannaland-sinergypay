<?php
namespace Conexa\DappWoo\Includes\Helper;
use Conexa\DappWoo\Sdk\DappWooSdk;
use Conexa\DappWoo\Gateway\RequestDepositAction;

trait HandlePaymentTrait {
    public static function handle_payment( $order_id, $test = '' ) {
        if ( ! $order_id || ! self::acquire_order_lock( $order_id ) ) { return false; }
        try {
            $order = wc_get_order( $order_id );
            if ( ! $order || \DappWoo::GATEWAY_ID !== $order->get_payment_method() ) { return false; }
            $code = $order->get_meta( \DappWoo::META_ORDER_PAYMENT_ID );
            if ( ! RequestDepositAction::valid_id( $code ) ) { return false; }
            // A previously recorded Dapp confirmation can be acknowledged without side effects.
            if ( $order->is_paid() ) {
                return RequestDepositAction::valid_id( $order->get_meta( \DappWoo::META_ORDER_PAYMENT_ID_INTERNAL ) );
            }
            $options = self::get_options();
            if ( empty( $options['api_token'] ) ) { return false; }
            $sdk = new DappWooSdk( $options['api_token'] );
            $response = $sdk->get_payment_data( $code );
            if ( is_wp_error( $response ) || ! is_array( $response ) || ! is_array( $response['data'] ?? null ) ) { return false; }
            // The notification schema is documented, but the authenticated lookup schema and
            // its settlement semantics are not verified. Never approve by data presence.
            // Implement a strict validator only after obtaining the lookup contract/fixtures.
            self::log( 'Payment confirmation blocked: API contract unverified.' );
            return false;
        } finally { self::release_order_lock( $order_id ); }
    }
}
