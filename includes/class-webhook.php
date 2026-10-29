<?php
namespace Cannaland\Payments;
defined( 'ABSPATH' ) || exit;

final class Webhook {
    private Notification $notification;
    public function __construct( ?Notification $notification = null ) {
        $this->notification = $notification ?? new Notification( dirname( CANNALAND_PAYMENTS_FILE ) . '/resources/keys' );
    }
    public function register(): void {
        add_filter( 'rest_post_dispatch', array( $this, 'dispatch_diagnostic' ), 10, 3 );
        register_rest_route( 'cannaland-payments/v1', '/payment-notification', array(
            'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( $this, 'receive' ),
        ) );
    }
    public function dispatch_diagnostic( $response, $server, $request ) {
        // WordPress/authentication plugins can reject a request before receive(), e.g. rest_invalid_json.
        // Observe only our exact POST route. Never override status, body, permissions or headers.
        try {
            if ( '/cannaland-payments/v1/payment-notification' === $request->get_route() && 'POST' === $request->get_method() &&
                is_object( $response ) && method_exists( $response, 'get_status' ) && $response->get_status() >= 400 ) {
                $data = $response->get_data();
                $invalid_json = is_array( $data ) && 'rest_invalid_json' === ( $data['code'] ?? null );
                Diagnostics::event( $invalid_json ? 'invalid_json' : 'webhook_dispatch_rejected', 0, 'webhook_dispatch', array(
                    'endpoint' => '/wp-json/cannaland-payments/v1/payment-notification', 'http_status' => (int) $response->get_status(),
                    'reason' => $invalid_json ? 'invalid_json' : 'dispatch_rejected', 'result' => 'rejected',
                ) );
            }
        } catch ( \Throwable $ignored ) { /* Telemetry never alters REST behavior. */ }
        return $response;
    }
    public function receive( $request ): \WP_REST_Response {
        // First handler action: never read/log headers, signatures or raw body for telemetry.
        Diagnostics::event( 'webhook_received', 0, 'webhook_entry', array(
            'endpoint' => '/wp-json/cannaland-payments/v1/payment-notification', 'result' => 'in_progress',
            'reason' => 'started', 'signature_verified' => false,
        ) );
        try { return new \WP_REST_Response( null, $this->process( $request->get_body() ) ); }
        catch ( \Throwable $e ) { return new \WP_REST_Response( null, $this->outcome( 'webhook_exception', 500, 'webhook_body', 0, 'exception', false, array( 'exception' => $e ) ) ); }
    }
    private function outcome( string $event, int $status, string $stage, int $id = 0, string $reason = 'invalid_signed_fields', bool $verified = false, array $details = array() ): int {
        Diagnostics::event( $event, $id, $stage, array_merge( $details, array(
            'endpoint' => '/wp-json/cannaland-payments/v1/payment-notification', 'http_status' => $status,
            'reason' => $reason, 'signature_verified' => $verified,
            'result' => $status >= 500 ? 'ambiguous' : ( $status >= 400 ? 'rejected' : ( 'duplicate_webhook' === $event ? 'reused' : 'success' ) ),
        ) ) );
        return $status;
    }
    private function verification_rejected( \WP_Error $error, string $raw ): int {
        $code = $error->get_error_code();
        $status = 'cannaland_payments_crypto' === $code ? 500 : ( 'cannaland_payments_signature' === $code ? 401 : 400 );
        // Diagnostic parsing only. It never authenticates values, looks up orders or changes the verdict.
        try { $payload = ( new NotificationJson() )->decode( $raw ); }
        catch ( \Throwable $e ) { return $this->outcome( 'invalid_json', $status, 'webhook_verification', 0, 'invalid_json' ); }
        $details = Diagnostics::notification_shape( $payload );
        if ( 500 === $status ) { return $this->outcome( 'webhook_rejected', $status, 'webhook_verification', 0, 'crypto_unavailable', false, $details ); }
        if ( 401 === $status ) {
            $reason = 'invalid_signature';
            if ( ! ( $payload->security ?? null ) instanceof \stdClass ) { $reason = 'security_missing'; }
            elseif ( ! ( $payload->security->version ?? null ) instanceof JsonNumber || '1' !== $payload->security->version->text ) { $reason = 'unsupported_security_version'; }
            elseif ( ! is_string( $payload->security->key ?? null ) || ! is_string( $payload->security->signature ?? null ) ) { $reason = 'invalid_security_structure'; }
            return $this->outcome( 'invalid_signature', $status, 'webhook_verification', 0, $reason, false, $details );
        }
        if ( ! property_exists( $payload, 'reference' ) || null === $payload->reference || '' === $payload->reference ) {
            return $this->outcome( 'reference_missing', $status, 'webhook_verification', 0, 'reference_missing', false, $details );
        }
        $reason = 'invalid_signed_fields';
        foreach ( array( 'id', 'currency', 'amount', 'description', 'date' ) as $key ) {
            if ( ! property_exists( $payload, $key ) ) { $reason = 'missing_signed_fields'; break; }
        }
        return $this->outcome( 'webhook_rejected', $status, 'webhook_verification', 0, $reason, false, $details );
    }
    public function process( string $raw ): int {
        $id = 0;
        $verified = false;
        $stage = 'webhook_verification';
        try {
            $env = Support::environment();
            $event = $this->notification->verify( $raw, $env );
            if ( is_wp_error( $event ) ) { return $this->verification_rejected( $event, $raw ); }
            $verified = true;
            $signed = $event['signed'];
            $stage = 'webhook_shape';
            if ( '' === $signed['reference'] ) { return $this->outcome( 'reference_missing', 400, $stage, 0, 'reference_missing', true ); }
            if ( ! preg_match( '/^csp-[1-9][0-9]*-[a-f0-9-]{36}$/D', $signed['reference'] ) ) { return $this->outcome( 'webhook_rejected', 400, $stage, 0, 'reference_format_invalid', true ); }
            if ( ! Support::id( $signed['id'] ) || null === Support::money( $signed['amount'] ) || '' === $signed['date'] || false === strtotime( $signed['date'] ) ) {
                return $this->outcome( 'webhook_rejected', 400, $stage, 0, 'invalid_signed_fields', true, Diagnostics::notification_shape( $event['payload'] ) );
            }
            // Check the documented SPEI notification shape only after verification. Nested
            // payment details are unsigned consistency data, never our transaction identity.
            $payload = $event['payload'];
            if ( ! is_array( $payload->payments ?? null ) || ! $payload->payments ) { return $this->outcome( 'webhook_rejected', 400, $stage, 0, 'payments_shape_invalid', true, Diagnostics::notification_shape( $payload ) ); }
            foreach ( $payload->payments as $payment_index => $payment ) {
                if ( ! $payment instanceof \stdClass || ! Support::id( $payment->id ?? null ) ||
                    ! ( $payment->Product ?? null ) instanceof \stdClass ||
                    ! ( $payment->Product->id ?? null ) instanceof JsonNumber || '22' !== $payment->Product->id->text ) {
                    return $this->outcome( 'webhook_rejected', 400, $stage, 0, 'payment_product_invalid', true, Diagnostics::payment_product_shape( $payment, (int) $payment_index ) );
                }
            }
            // Only authenticated reference is used for lookup; unsigned code and payments never select an order.
            $stage = 'webhook_lookup';
            $order = Support::find_reference( $signed['reference'] );
            if ( ! $order ) { return $this->outcome( 'order_not_found', 409, $stage, 0, 'order_not_found', true ); }
            $id = (int) $order->get_id();
            $stage = 'webhook_lock';
            if ( ! Support::lock( $id ) ) { return $this->outcome( 'webhook_rejected', 500, $stage, $id, 'lock_unavailable', true ); }
            try { $stage = 'webhook_confirmation'; return $this->complete( $id, $env, $event ); }
            finally { Support::unlock( $id ); }
        } catch ( \Throwable $e ) {
            Support::log( 'webhook_internal' );
            return $this->outcome( 'webhook_exception', 500, $stage, $id, 'exception', $verified, array( 'exception' => $e ) );
        }
    }
    private function complete( int $id, string $env, array $event ): int {
        $order = wc_get_order( $id );
        if ( ! $order ) { return $this->outcome( 'order_not_found', 409, 'webhook_lookup', $id, 'order_not_found', true ); }
        $s = $event['signed'];
        $p = $event['payload'];
        $reason = '';
        if ( Support::GATEWAY !== $order->get_payment_method() ) { $reason = 'wrong_gateway'; }
        elseif ( Support::meta( $order, 'reference' ) !== $s['reference'] ) { $reason = 'reference_mismatch'; }
        elseif ( Support::meta( $order, 'environment' ) !== $env ) { $reason = 'environment_mismatch'; }
        elseif ( 'ready' !== Support::meta( $order, 'preparation' ) ) { $reason = 'preparation_not_ready'; }
        elseif ( ! Support::id( Support::meta( $order, 'remote_order_id' ) ) || ! is_string( $p->code ?? null ) || $p->code !== Support::meta( $order, 'remote_order_id' ) ) { $reason = 'remote_order_mismatch'; }
        elseif ( $s['description'] !== Support::meta( $order, 'description' ) ) { $reason = 'description_mismatch'; }
        elseif ( 'MXN' !== $s['currency'] || $s['currency'] !== $order->get_currency() || $s['currency'] !== Support::meta( $order, 'currency' ) ) { $reason = 'currency_mismatch'; }
        elseif ( Support::money( $s['amount'] ) !== Support::meta( $order, 'amount' ) || Support::money( (string) $order->get_total() ) !== Support::meta( $order, 'amount' ) ) { $reason = 'amount_mismatch'; }
        if ( '' !== $reason ) { return $this->outcome( 'amount_mismatch' === $reason ? 'amount_mismatch' : 'webhook_rejected', 409, 'webhook_consistency', $id, $reason, true, Diagnostics::notification_shape( $p ) ); }
        // Ticket root id is signed; payments[].id and Product are not authenticated by this signature.
        $ticket = $s['id'];
        $recorded = Support::meta( $order, 'transaction_id' );
        if ( $order->is_paid() ) {
            if ( $recorded !== $ticket || $order->get_transaction_id() !== $ticket ) { return $this->outcome( 'webhook_rejected', 409, 'webhook_confirmation', $id, 'transaction_conflict', true ); }
            if ( 'paid' !== Support::meta( $order, 'confirmation_state' ) ) {
                Support::put( $order, 'confirmation_state', 'paid' );
                $order->save();
            }
            return $this->outcome( 'duplicate_webhook', 200, 'webhook_confirmation', $id, 'duplicate', true );
        }
        if ( ! in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) ) { return $this->outcome( 'webhook_rejected', 409, 'webhook_confirmation', $id, 'invalid_order_status', true ); }
        if ( '' !== $recorded || Support::meta( $order, 'confirmation_state' ) ) {
            // A previous call may have run hooks before crashing. Do not invoke it a second time.
            return $this->outcome( 'webhook_rejected', 500, 'webhook_confirmation', $id, 'confirmation_interrupted', true );
        }
        $claim = 'cannaland_payments_ticket_' . hash( 'sha256', $env . '|' . $ticket );
        if ( ! add_option( $claim, $s['reference'], '', false ) ) {
            $claimed = get_option( $claim, false );
            if ( false === $claimed ) { return $this->outcome( 'webhook_rejected', 500, 'webhook_confirmation', $id, 'claim_unavailable', true ); }
            if ( $claimed !== $s['reference'] ) { return $this->outcome( 'webhook_rejected', 409, 'webhook_confirmation', $id, 'claim_conflict', true ); }
        }
        Support::put( $order, 'transaction_id', $ticket );
        Support::put( $order, 'confirmation_state', 'applying' );
        $order->save();
        try {
            $order->payment_complete( $ticket );
            $order = wc_get_order( $id );
            if ( ! $order || ! $order->is_paid() || $order->get_transaction_id() !== $ticket ) {
                if ( $order ) { $order->add_order_note( __( 'Cannaland Payments: confirmación interrumpida; requiere conciliación administrativa.', 'cannaland-payments' ) ); }
                return $this->outcome( 'webhook_rejected', 500, 'webhook_confirmation', $id, 'confirmation_interrupted', true );
            }
            Support::put( $order, 'confirmation_state', 'paid' );
            $order->save();
            $order->add_order_note( __( 'Cannaland Payments: pago SPEI confirmado mediante notificación con firma válida.', 'cannaland-payments' ) );
            return $this->outcome( 'payment_confirmed', 200, 'webhook_confirmation', $id, 'confirmed', true );
        } catch ( \Throwable $e ) {
            $order->add_order_note( __( 'Cannaland Payments: confirmación interrumpida; requiere conciliación administrativa.', 'cannaland-payments' ) );
            return $this->outcome( 'webhook_exception', 500, 'webhook_confirmation', $id, 'exception', true, array( 'exception' => $e ) );
        }
    }
}
