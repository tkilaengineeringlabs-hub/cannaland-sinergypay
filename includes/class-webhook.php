<?php
namespace Cannaland\Payments;
defined( 'ABSPATH' ) || exit;

final class Webhook {
    private Notification $notification;
    public function __construct( ?Notification $notification = null ) {
        $this->notification = $notification ?? new Notification( dirname( CANNALAND_PAYMENTS_FILE ) . '/resources/keys' );
    }
    public function register(): void {
        register_rest_route( 'cannaland-payments/v1', '/payment-notification', array(
            'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( $this, 'receive' ),
        ) );
    }
    public function receive( $request ): \WP_REST_Response {
        return new \WP_REST_Response( null, $this->process( $request->get_body() ) );
    }
    public function process( string $raw ): int {
        try {
            $env = Support::environment();
            $event = $this->notification->verify( $raw, $env );
            if ( is_wp_error( $event ) ) {
                return 'cannaland_payments_crypto' === $event->get_error_code() ? 500 : ( 'cannaland_payments_signature' === $event->get_error_code() ? 401 : 400 );
            }
            $signed = $event['signed'];
            if ( ! Support::id( $signed['id'] ) || ! preg_match( '/^csp-[1-9][0-9]*-[a-f0-9-]{36}$/D', $signed['reference'] ) ||
                null === Support::money( $signed['amount'] ) || '' === $signed['date'] || false === strtotime( $signed['date'] ) ) { return 400; }
            // Check the documented SPEI notification shape only after verification. Nested
            // payment details are unsigned consistency data, never our transaction identity.
            $payload = $event['payload'];
            if ( ! is_array( $payload->payments ?? null ) || ! $payload->payments ) { return 400; }
            foreach ( $payload->payments as $payment ) {
                if ( ! $payment instanceof \stdClass || ! Support::id( $payment->id ?? null ) ||
                    ! ( $payment->Product ?? null ) instanceof \stdClass ||
                    ! ( $payment->Product->id ?? null ) instanceof JsonNumber || '22' !== $payment->Product->id->text ) { return 400; }
            }
            // Only authenticated reference is used for lookup; unsigned code and payments never select an order.
            $order = Support::find_reference( $signed['reference'] );
            if ( ! $order ) { return 409; }
            $id = (int) $order->get_id();
            if ( ! Support::lock( $id ) ) { return 500; }
            try { return $this->complete( $id, $env, $event ); }
            finally { Support::unlock( $id ); }
        } catch ( \Throwable $e ) {
            Support::log( 'webhook_internal' );
            return 500;
        }
    }
    private function complete( int $id, string $env, array $event ): int {
        $order = wc_get_order( $id );
        if ( ! $order ) { return 409; }
        $s = $event['signed'];
        $p = $event['payload'];
        if ( Support::GATEWAY !== $order->get_payment_method() || Support::meta( $order, 'reference' ) !== $s['reference'] ||
            Support::meta( $order, 'environment' ) !== $env || 'ready' !== Support::meta( $order, 'preparation' ) ||
            ! Support::id( Support::meta( $order, 'remote_order_id' ) ) || ! is_string( $p->code ?? null ) || $p->code !== Support::meta( $order, 'remote_order_id' ) ||
            $s['description'] !== Support::meta( $order, 'description' ) || 'MXN' !== $s['currency'] || $s['currency'] !== $order->get_currency() ||
            $s['currency'] !== Support::meta( $order, 'currency' ) || Support::money( $s['amount'] ) !== Support::meta( $order, 'amount' ) ||
            Support::money( (string) $order->get_total() ) !== Support::meta( $order, 'amount' ) ) { return 409; }
        // Ticket root id is signed; payments[].id and Product are not authenticated by this signature.
        $ticket = $s['id'];
        $recorded = Support::meta( $order, 'transaction_id' );
        if ( $order->is_paid() ) {
            if ( $recorded !== $ticket || $order->get_transaction_id() !== $ticket ) { return 409; }
            if ( 'paid' !== Support::meta( $order, 'confirmation_state' ) ) {
                Support::put( $order, 'confirmation_state', 'paid' );
                $order->save();
            }
            return 200;
        }
        if ( ! in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) ) { return 409; }
        if ( '' !== $recorded || Support::meta( $order, 'confirmation_state' ) ) {
            // A previous call may have run hooks before crashing. Do not invoke it a second time.
            return 500;
        }
        $claim = 'cannaland_payments_ticket_' . hash( 'sha256', $env . '|' . $ticket );
        if ( ! add_option( $claim, $s['reference'], '', false ) ) {
            $claimed = get_option( $claim, false );
            if ( false === $claimed ) { return 500; }
            if ( $claimed !== $s['reference'] ) { return 409; }
        }
        Support::put( $order, 'transaction_id', $ticket );
        Support::put( $order, 'confirmation_state', 'applying' );
        $order->save();
        try {
            $order->payment_complete( $ticket );
            $order = wc_get_order( $id );
            if ( ! $order || ! $order->is_paid() || $order->get_transaction_id() !== $ticket ) {
                if ( $order ) { $order->add_order_note( __( 'Cannaland Payments: confirmación interrumpida; requiere conciliación administrativa.', 'cannaland-payments' ) ); }
                return 500;
            }
            Support::put( $order, 'confirmation_state', 'paid' );
            $order->save();
            $order->add_order_note( __( 'Cannaland Payments: pago SPEI confirmado mediante notificación con firma válida.', 'cannaland-payments' ) );
            return 200;
        } catch ( \Throwable $e ) {
            $order->add_order_note( __( 'Cannaland Payments: confirmación interrumpida; requiere conciliación administrativa.', 'cannaland-payments' ) );
            return 500;
        }
    }
}
