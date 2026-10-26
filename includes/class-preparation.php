<?php
namespace Cannaland\SinergyPay;
defined( 'ABSPATH' ) || exit;

final class Preparation {
    public function prepare( int $id ) {
        if ( ! Support::lock( $id ) ) { return Support::error( 'csp_busy' ); }
        try { return $this->locked( $id ); }
        catch ( \Throwable $e ) {
            Support::log( 'checkout_internal' );
            try {
                $order = wc_get_order( $id );
                if ( $order ) {
                    foreach ( array( 'order', 'clabe' ) as $phase ) {
                        if ( 'sending' === Support::meta( $order, $phase . '_state' ) ) { $this->failed( $order, $phase, 'ambiguous' ); }
                    }
                }
            } catch ( \Throwable $ignored ) { /* Persisted sending state remains fail-closed. */ }
            // A persisted sending state blocks automatic retries after any interrupted write/request.
            return Support::error();
        } finally { Support::unlock( $id ); }
    }
    private function locked( int $id ) {
        $order = wc_get_order( $id );
        if ( ! $order || Support::GATEWAY !== $order->get_payment_method() || $order->is_paid() ||
            ! in_array( $order->get_status(), array( 'pending', 'on-hold', 'failed' ), true ) || 'MXN' !== $order->get_currency() ) {
            return Support::error( 'csp_order' );
        }
        $settings = Support::settings();
        $env = Support::environment();
        $amount = Support::money( (string) $order->get_total() );
        $expiration = filter_var( $settings['expiration_minutes'] ?? '8640', FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
        if ( ! Support::valid_environment( $env ) || ! is_string( $settings['api_key'] ?? null ) || '' === trim( $settings['api_key'] ) || false === $expiration || null === $amount || '0.00' === $amount ) {
            if ( ! Support::meta( $order, 'order_state' ) && ! Support::meta( $order, 'remote_order_id' ) ) {
                $this->state( $order, 'order', 'local_error' );
            }
            return Support::error( 'csp_configuration' );
        }
        if ( ! Support::meta( $order, 'reference' ) ) {
            Support::put( $order, 'reference', 'csp-' . $id . '-' . wp_generate_uuid4() );
            Support::put( $order, 'environment', $env );
            Support::put( $order, 'amount', $amount );
            Support::put( $order, 'currency', 'MXN' );
            Support::put( $order, 'description', 'Cannaland order #' . $id );
            $order->save();
        }
        if ( $env !== Support::meta( $order, 'environment' ) || $amount !== Support::meta( $order, 'amount' ) ||
            $order->get_currency() !== Support::meta( $order, 'currency' ) ) { return Support::error( 'csp_changed_order' ); }
        foreach ( array( 'order', 'clabe' ) as $phase ) {
            if ( 'sending' === Support::meta( $order, $phase . '_state' ) ) {
                $this->failed( $order, $phase, 'ambiguous' );
                return Support::error( 'csp_review' );
            }
        }
        if ( 'ready' === Support::meta( $order, 'preparation' ) && Support::clabe( Support::meta( $order, 'clabe' ) ) ) { return $this->waiting( $order ); }
        $api = new Api( $env, $settings['api_key'] );
        if ( ! Support::meta( $order, 'remote_order_id' ) ) {
            if ( ! $this->can_send( $order, 'order' ) ) { return Support::error( 'csp_review' ); }
            $this->state( $order, 'order', 'sending' );
            $result = $api->create_order( array( 'amount' => $amount, 'description' => Support::meta( $order, 'description' ),
                'reference' => Support::meta( $order, 'reference' ), 'expiration_minutes' => $expiration ) );
            if ( 'success' !== $result['kind'] ) { $this->failed( $order, 'order', $result['kind'] ); return Support::error(); }
            $data = $result['data'];
            if ( ! Support::id( $data['id'] ?? null ) ) { $this->failed( $order, 'order', 'ambiguous' ); return Support::error(); }
            // Save remote ID before interpreting remaining fields; never create a second remote order.
            Support::put( $order, 'remote_order_id', $data['id'] );
            $order->save();
            if ( ! is_string( $data['amount'] ?? null ) || Support::money( $data['amount'] ) !== $amount ||
                ( $data['currency'] ?? null ) !== 'MXN' || ( $data['description'] ?? null ) !== Support::meta( $order, 'description' ) ||
                ! is_string( $data['expiration_date'] ?? null ) || false === strtotime( $data['expiration_date'] ) ) {
                $this->failed( $order, 'order', 'ambiguous' ); return Support::error();
            }
            Support::put( $order, 'expiration_date', $data['expiration_date'] );
            if ( isset( $data['reference_num'] ) && is_string( $data['reference_num'] ) && strlen( $data['reference_num'] ) <= 200 ) {
                Support::put( $order, 'reference_num', $data['reference_num'] );
            }
            $this->state( $order, 'order', 'succeeded' );
        }
        if ( 'succeeded' !== Support::meta( $order, 'order_state' ) || ! Support::id( Support::meta( $order, 'remote_order_id' ) ) ) {
            return Support::error( 'csp_review' );
        }
        if ( ! $this->can_send( $order, 'clabe' ) ) { return Support::error( 'csp_review' ); }
        $this->state( $order, 'clabe', 'sending' );
        $result = $api->create_clabe( Support::meta( $order, 'remote_order_id' ) );
        if ( 'success' !== $result['kind'] ) { $this->failed( $order, 'clabe', $result['kind'] ); return Support::error(); }
        if ( ! Support::clabe( $result['data']['clabe'] ?? null ) ) {
            $this->failed( $order, 'clabe', 'ambiguous' ); return Support::error( 'csp_invalid_clabe' );
        }
        Support::put( $order, 'clabe', $result['data']['clabe'] );
        Support::put( $order, 'clabe_state', 'succeeded' );
        Support::put( $order, 'preparation', 'ready' );
        $order->save();
        return $this->waiting( $order );
    }
    private function waiting( $order ) {
        if ( 'on-hold' !== $order->get_status() ) {
            $order->update_status( 'on-hold', __( 'SinergyPay: en espera de transferencia SPEI.', 'cannaland-sinergypay' ) );
        }
        return $order;
    }
    private function can_send( $order, string $phase ): bool {
        $state = Support::meta( $order, $phase . '_state' );
        if ( 'sending' === $state ) { $this->failed( $order, $phase, 'ambiguous' ); return false; }
        return '' === $state || 'local_error' === $state;
    }
    private function state( $order, string $phase, string $state ): void {
        Support::put( $order, $phase . '_state', $state );
        $order->save();
    }
    private function failed( $order, string $phase, string $kind ): void {
        $this->state( $order, $phase, $kind );
        if ( 'ambiguous' === $kind ) {
            $order->add_order_note( __( 'SinergyPay: resultado incierto al preparar SPEI. Requiere conciliación administrativa; no repetir la operación hasta revisarla con el proveedor.', 'cannaland-sinergypay' ) );
        } elseif ( 'rejected' === $kind ) {
            $order->add_order_note( __( 'SinergyPay: solicitud rechazada. Revisar la configuración y el rechazo con el proveedor antes de autorizar otro intento.', 'cannaland-sinergypay' ) );
        }
    }
}
