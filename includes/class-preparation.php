<?php
namespace Cannaland\Payments;
defined( 'ABSPATH' ) || exit;

final class Preparation {
    private int $order_id = 0;
    private string $stage = 'order_lock';
    private array $diagnostic = array();
    public function prepare( int $id ) {
        $this->order_id = $id;
        $this->stage = 'order_lock';
        $this->diagnostic = array();
        try {
            if ( ! Support::lock( $id ) ) { return $this->error( 'cannaland_payments_busy', 'lock_unavailable', 'blocked' ); }
        } catch ( \Throwable $e ) {
            $this->exception( $e );
            return Support::error();
        }
        try { return $this->locked( $id ); }
        catch ( \Throwable $e ) {
            $this->exception( $e );
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
        $this->stage = 'order_validation';
        $order = wc_get_order( $id );
        if ( ! $order ) { return $this->error( 'cannaland_payments_order', 'order_not_found' ); }
        if ( Support::GATEWAY !== $order->get_payment_method() ) { return $this->error( 'cannaland_payments_order', 'wrong_gateway' ); }
        if ( $order->is_paid() ) { return $this->error( 'cannaland_payments_order', 'already_paid', 'blocked' ); }
        if ( ! in_array( $order->get_status(), array( 'pending', 'on-hold', 'failed' ), true ) ) { return $this->error( 'cannaland_payments_order', 'invalid_order_status' ); }
        if ( 'MXN' !== $order->get_currency() ) { return $this->error( 'cannaland_payments_order', 'unsupported_currency' ); }
        $this->stage = 'configuration_validation';
        $settings = Support::settings();
        $env = Support::environment();
        $amount = Support::money( (string) $order->get_total() );
        $expiration = filter_var( $settings['expiration_minutes'] ?? '8640', FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
        if ( ! Support::valid_environment( $env ) || ! is_string( $settings['api_key'] ?? null ) || '' === trim( $settings['api_key'] ) || false === $expiration || null === $amount || '0.00' === $amount ) {
            if ( ! Support::meta( $order, 'order_state' ) && ! Support::meta( $order, 'remote_order_id' ) ) {
                $this->state( $order, 'order', 'local_error' );
            }
            $reason = ! Support::valid_environment( $env ) ? 'invalid_environment' :
                ( ! is_string( $settings['api_key'] ?? null ) || '' === trim( $settings['api_key'] ) ? 'missing_api_key' :
                ( false === $expiration ? 'invalid_expiration' : 'invalid_order_amount' ) );
            return $this->error( 'cannaland_payments_configuration', $reason );
        }
        $this->stage = 'reference_persistence';
        if ( ! Support::meta( $order, 'reference' ) ) {
            Support::put( $order, 'reference', 'csp-' . $id . '-' . wp_generate_uuid4() );
            Support::put( $order, 'environment', $env );
            Support::put( $order, 'amount', $amount );
            Support::put( $order, 'currency', 'MXN' );
            Support::put( $order, 'description', 'Cannaland order #' . $id );
            $order->save();
        }
        Diagnostics::event( 'reference_prepared', $id, $this->stage, array( 'reason' => 'prepared', 'result' => 'success' ) );
        $this->stage = 'snapshot_validation';
        if ( $env !== Support::meta( $order, 'environment' ) || $amount !== Support::meta( $order, 'amount' ) ||
            $order->get_currency() !== Support::meta( $order, 'currency' ) ) { return $this->error( 'cannaland_payments_changed_order', 'snapshot_changed', 'blocked' ); }
        foreach ( array( 'order', 'clabe' ) as $phase ) {
            if ( 'sending' === Support::meta( $order, $phase . '_state' ) ) {
                $this->stage = $phase . '_state_validation';
                $this->diagnostic = array( 'endpoint' => 'order' === $phase ? '/orders/' : '/orders/{order_id}/clabe', 'stored_state' => 'sending' );
                $this->report( 'interrupted_sending', 'ambiguous' );
                $this->failed( $order, $phase, 'ambiguous' );
                return Support::error( 'cannaland_payments_review' );
            }
        }
        if ( 'ready' === Support::meta( $order, 'preparation' ) && Support::clabe( Support::meta( $order, 'clabe' ) ) ) { Diagnostics::event( 'preparation_reused', $id, 'preparation', array( 'reason' => 'reused', 'result' => 'reused' ) ); return $this->waiting( $order ); }
        $api = new SinergyPayClient( $env, $settings['api_key'] );
        if ( ! Support::meta( $order, 'remote_order_id' ) ) {
            $this->stage = 'order_state_validation';
            $this->diagnostic = array( 'endpoint' => '/orders/' );
            if ( ! $this->can_send( $order, 'order' ) ) { return Support::error( 'cannaland_payments_review' ); }
            $this->state( $order, 'order', 'sending' );
            $this->stage = 'create_order';
            $this->diagnostic = array( 'endpoint' => '/orders/' );
            $result = $api->create_order( array( 'amount' => $amount, 'description' => Support::meta( $order, 'description' ),
                'reference' => Support::meta( $order, 'reference' ), 'expiration_minutes' => $expiration ), $id );
            $this->diagnostic = $result['diagnostic'] ?? $this->diagnostic;
            if ( 'success' !== $result['kind'] ) { $this->report( $result['diagnostic']['reason'] ?? 'invalid_response_data', $result['kind'] ); $this->failed( $order, 'order', $result['kind'] ); return Support::error(); }
            $this->stage = 'order_response_validation';
            $data = $result['data'];
            if ( ! Support::id( $data['id'] ?? null ) ) { $this->report( 'invalid_remote_id', 'ambiguous' ); $this->failed( $order, 'order', 'ambiguous' ); return Support::error(); }
            // Save remote ID before interpreting remaining fields; never create a second remote order.
            $this->stage = 'remote_order_persistence';
            Support::put( $order, 'remote_order_id', $data['id'] );
            $order->save();
            Diagnostics::event( 'remote_order_saved', $id, $this->stage, array_merge( $this->diagnostic, array( 'reason' => 'saved', 'result' => 'success' ) ) );
            $this->stage = 'order_response_validation';
            if ( ! is_string( $data['amount'] ?? null ) || Support::money( $data['amount'] ) !== $amount ||
                ( $data['currency'] ?? null ) !== 'MXN' || ( $data['description'] ?? null ) !== Support::meta( $order, 'description' ) ||
                ! is_string( $data['expiration_date'] ?? null ) || false === strtotime( $data['expiration_date'] ) ) {
                $reason = ! is_string( $data['amount'] ?? null ) ? 'amount_type_invalid' :
                    ( Support::money( $data['amount'] ) !== $amount ? 'amount_mismatch' :
                    ( ( $data['currency'] ?? null ) !== 'MXN' ? 'currency_mismatch' :
                    ( ( $data['description'] ?? null ) !== Support::meta( $order, 'description' ) ? 'description_mismatch' : 'expiration_invalid' ) ) );
                $this->report( $reason, 'ambiguous' );
                $this->failed( $order, 'order', 'ambiguous' ); return Support::error();
            }
            $this->stage = 'order_details_persistence';
            Support::put( $order, 'expiration_date', $data['expiration_date'] );
            if ( isset( $data['reference_num'] ) && is_string( $data['reference_num'] ) && strlen( $data['reference_num'] ) <= 200 ) {
                Support::put( $order, 'reference_num', $data['reference_num'] );
            }
            $this->state( $order, 'order', 'succeeded' );
        }
        $this->stage = 'order_state_validation';
        if ( 'succeeded' !== Support::meta( $order, 'order_state' ) || ! Support::id( Support::meta( $order, 'remote_order_id' ) ) ) {
            $this->diagnostic = array( 'endpoint' => '/orders/', 'stored_state' => Support::meta( $order, 'order_state' ) ?: 'missing' );
            return $this->error( 'cannaland_payments_review', 'remote_order_not_ready', 'blocked' );
        }
        $this->stage = 'clabe_state_validation';
        $this->diagnostic = array( 'endpoint' => '/orders/{order_id}/clabe' );
        if ( ! $this->can_send( $order, 'clabe' ) ) { return Support::error( 'cannaland_payments_review' ); }
        $this->state( $order, 'clabe', 'sending' );
        $this->stage = 'create_clabe';
        $this->diagnostic = array( 'endpoint' => '/orders/{order_id}/clabe' );
        $result = $api->create_clabe( Support::meta( $order, 'remote_order_id' ), $id );
        $this->diagnostic = $result['diagnostic'] ?? $this->diagnostic;
        if ( 'success' !== $result['kind'] ) { $this->report( $result['diagnostic']['reason'] ?? 'invalid_response_data', $result['kind'] ); $this->failed( $order, 'clabe', $result['kind'] ); return Support::error(); }
        $this->stage = 'clabe_response_validation';
        if ( ! Support::clabe( $result['data']['clabe'] ?? null ) ) {
            $clabe = $result['data']['clabe'] ?? null;
            $reason = ! is_string( $clabe ) ? 'invalid_clabe_type' : ( strlen( $clabe ) !== 18 ? 'invalid_clabe_length' : 'invalid_clabe_characters' );
            $this->report( $reason, 'ambiguous' );
            $this->failed( $order, 'clabe', 'ambiguous' ); return Support::error( 'cannaland_payments_invalid_clabe' );
        }
        $this->stage = 'clabe_persistence';
        Support::put( $order, 'clabe', $result['data']['clabe'] );
        Support::put( $order, 'clabe_state', 'succeeded' );
        Support::put( $order, 'preparation', 'ready' );
        $order->save();
        Diagnostics::event( 'clabe_saved', $id, $this->stage, array_merge( $this->diagnostic, array( 'reason' => 'saved', 'result' => 'success' ) ) );
        return $this->waiting( $order );
    }
    private function waiting( $order ) {
        $this->stage = 'on_hold_transition';
        $this->diagnostic = array();
        if ( 'on-hold' !== $order->get_status() ) {
            $order->update_status( 'on-hold', __( 'Cannaland Payments: en espera de transferencia SPEI.', 'cannaland-payments' ) );
        }
        Diagnostics::event( 'order_on_hold', $this->order_id, $this->stage, array( 'reason' => 'success', 'result' => 'success' ) );
        return $order;
    }
    private function can_send( $order, string $phase ): bool {
        $state = Support::meta( $order, $phase . '_state' );
        if ( 'sending' === $state ) { $this->failed( $order, $phase, 'ambiguous' ); return false; }
        $allowed = '' === $state || 'local_error' === $state;
        if ( ! $allowed ) { $this->diagnostic['stored_state'] = $state ?: 'missing'; $this->report( 'phase_blocked', 'blocked' ); }
        return $allowed;
    }
    private function state( $order, string $phase, string $state ): void {
        $previous = $this->stage;
        $this->stage = $phase . '_state_persistence';
        Support::put( $order, $phase . '_state', $state );
        $order->save();
        $this->stage = $previous;
    }
    private function failed( $order, string $phase, string $kind ): void {
        $this->state( $order, $phase, $kind );
        if ( 'ambiguous' === $kind ) {
            $order->add_order_note( __( 'Cannaland Payments: resultado incierto al preparar SPEI. Requiere conciliación administrativa; no repetir la operación hasta revisarla con el proveedor.', 'cannaland-payments' ) );
        } elseif ( 'rejected' === $kind ) {
            $order->add_order_note( __( 'Cannaland Payments: solicitud rechazada. Revisar la configuración y el rechazo con el proveedor antes de autorizar otro intento.', 'cannaland-payments' ) );
        }
    }
    private function report( string $reason, string $kind ): void {
        Diagnostics::event( 'preparation_failed', $this->order_id, $this->stage,
            array_merge( $this->diagnostic, array( 'reason' => $reason, 'result' => $kind ) ) );
    }
    private function error( string $code, string $reason, string $kind = 'local_error' ): \WP_Error {
        Diagnostics::event( 'preparation_failed', $this->order_id, $this->stage,
            array_merge( $this->diagnostic, array( 'reason' => $reason, 'result' => $kind, 'wp_error_code' => $code ) ) );
        return Support::error( $code );
    }
    private function exception( \Throwable $e ): void {
        $local = in_array( $this->stage, array( 'order_lock', 'order_validation', 'configuration_validation', 'reference_persistence', 'snapshot_validation' ), true ) ||
            ( in_array( $this->stage, array( 'order_state_persistence', 'clabe_state_persistence' ), true ) && ! isset( $this->diagnostic['result'] ) );
        Diagnostics::event( 'preparation_exception', $this->order_id, $this->stage,
            array_merge( $this->diagnostic, array( 'reason' => 'exception', 'result' => $local ? 'local_error' : 'ambiguous', 'exception' => $e ) ) );
    }

}
