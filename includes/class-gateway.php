<?php
namespace Cannaland\Payments;
defined( 'ABSPATH' ) || exit;

final class Gateway extends \WC_Payment_Gateway {
    public function __construct() {
        $this->id = Support::GATEWAY;
        $this->has_fields = false;
        $this->supports = array( 'products' );
        $this->method_title = __( 'Transferencia SPEI', 'cannaland-payments' );
        $this->method_description = __( 'Transferencias SPEI a una CLABE generada por SinergyPay. La confirmación se recibe por notificación firmada.', 'cannaland-payments' );
        $this->init_form_fields();
        $this->init_settings();
        $this->enabled = $this->get_option( 'enabled', 'no' );
        $this->title = $this->get_option( 'title', __( 'Transferencia SPEI', 'cannaland-payments' ) );
        $this->description = $this->get_option( 'description', __( 'Paga por transferencia SPEI. Recibirás una CLABE para este pedido.', 'cannaland-payments' ) );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'instructions' ) );
        add_action( 'woocommerce_view_order', array( $this, 'instructions' ) );
    }
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array( 'title' => __( 'Activar', 'cannaland-payments' ), 'type' => 'checkbox', 'label' => __( 'Habilitar transferencia SPEI', 'cannaland-payments' ), 'default' => 'no' ),
            'title' => array( 'title' => __( 'Título', 'cannaland-payments' ), 'type' => 'text', 'default' => __( 'Transferencia SPEI', 'cannaland-payments' ) ),
            'description' => array( 'title' => __( 'Descripción', 'cannaland-payments' ), 'type' => 'textarea', 'default' => __( 'Paga por transferencia SPEI. Recibirás una CLABE para este pedido.', 'cannaland-payments' ) ),
            'environment' => array( 'title' => __( 'Entorno', 'cannaland-payments' ), 'type' => 'select', 'options' => array( 'sandbox' => 'Sandbox', 'production' => 'Producción' ), 'default' => 'sandbox' ),
            'api_key' => array( 'title' => 'API key privada', 'type' => 'password', 'default' => '', 'description' => __( 'Usa la llave del entorno seleccionado. Se guarda en la configuración de WordPress; no se registra en logs.', 'cannaland-payments' ) ),
            'expiration_minutes' => array( 'title' => __( 'Vencimiento en minutos', 'cannaland-payments' ), 'type' => 'number', 'default' => '8640', 'custom_attributes' => array( 'min' => '1', 'step' => '1' ) ),
            'logging' => array( 'title' => __( 'Registro seguro', 'cannaland-payments' ), 'type' => 'checkbox', 'label' => __( 'Registrar eventos técnicos mínimos sin datos de pago ni credenciales', 'cannaland-payments' ), 'default' => 'no', 'description' => __( 'Fuente de logs: cannaland-payments. Para ver avances y errores, activa el logger de WooCommerce y usa nivel mínimo Info (o sin umbral) en Estado > Registros > Ajustes.', 'cannaland-payments' ) ),
            'callback' => array( 'title' => 'Callback SinergyPay', 'type' => 'title', 'description' => esc_html__( 'Proporciona esta URL al equipo SinergyPay para configurar las notificaciones POST:', 'cannaland-payments' ) . ' <code>' . esc_html( rest_url( 'cannaland-payments/v1/payment-notification' ) ) . '</code>' ),
        );
    }
    public function validate_expiration_minutes_field( $key, $value ) {
        $number = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
        if ( false === $number ) { throw new \Exception( __( 'El vencimiento debe ser un número entero positivo.', 'cannaland-payments' ) ); }
        return (string) $number;
    }
    public function is_available() {
        return parent::is_available() && 'MXN' === get_woocommerce_currency() && function_exists( 'openssl_verify' ) &&
            Support::valid_environment( Support::environment() ) && '' !== trim( (string) $this->get_option( 'api_key', '' ) );
    }
    public function process_payment( $order_id ) {
        $order_id = (int) $order_id;
        $stage = 'process_payment';
        Diagnostics::event( 'process_payment_started', $order_id, $stage, array( 'reason' => 'started', 'result' => 'not_sent' ) );
        try {
            $stage = 'preparation';
            $prepared = ( new Preparation() )->prepare( $order_id );
            if ( is_wp_error( $prepared ) ) {
                Diagnostics::event( 'process_payment_failed', $order_id, $stage,
                    array_merge( Diagnostics::wp_error( $prepared ), array( 'reason' => 'preparation_error', 'result' => 'blocked' ) ) );
                wc_add_notice( $prepared->get_error_message(), 'error' );
                return array( 'result' => 'failure' );
            }
            $stage = 'cart_clear';
            if ( WC()->cart ) { WC()->cart->empty_cart(); }
            $stage = 'redirect';
            $redirect = $this->get_return_url( $prepared );
            Diagnostics::event( 'process_payment_success', $order_id, $stage, array( 'reason' => 'success', 'result' => 'success' ) );
            return array( 'result' => 'success', 'redirect' => $redirect );
        } catch ( \Throwable $e ) {
            Diagnostics::event( 'process_payment_exception', $order_id, $stage, array( 'reason' => 'exception', 'result' => 'ambiguous', 'exception' => $e ) );
            wc_add_notice( Support::error()->get_error_message(), 'error' );
            return array( 'result' => 'failure' );
        }
    }
    public function instructions( $order_id ) {
        Diagnostics::event( 'instructions_started', (int) $order_id, 'instructions', array( 'reason' => 'started', 'result' => 'not_sent' ) );
        try {
            $order = wc_get_order( $order_id );
            if ( ! $order || Support::GATEWAY !== $order->get_payment_method() || $order->is_paid() ||
                ! in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) ||
                'ready' !== Support::meta( $order, 'preparation' ) || ! Support::clabe( Support::meta( $order, 'clabe' ) ) ) {
                Diagnostics::event( 'instructions_skipped', (int) $order_id, 'instructions', array( 'reason' => 'not_ready', 'result' => 'not_sent' ) );
                return;
            }
            echo '<section class="cannaland-payments-instructions"><h2>' . esc_html__( 'Transferencia SPEI', 'cannaland-payments' ) . '</h2><dl>';
            $fields = array(
                __( 'CLABE', 'cannaland-payments' ) => Support::meta( $order, 'clabe' ),
                __( 'Importe', 'cannaland-payments' ) => Support::meta( $order, 'amount' ) . ' ' . Support::meta( $order, 'currency' ),
                __( 'Referencia de operación', 'cannaland-payments' ) => Support::meta( $order, 'reference' ),
            );
            if ( Support::meta( $order, 'reference_num' ) ) { $fields[ __( 'Referencia del proveedor', 'cannaland-payments' ) ] = Support::meta( $order, 'reference_num' ); }
            if ( Support::meta( $order, 'expiration_date' ) ) { $fields[ __( 'Vencimiento (incluye zona horaria)', 'cannaland-payments' ) ] = Support::meta( $order, 'expiration_date' ); }
            foreach ( $fields as $label => $value ) { echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>'; }
            echo '</dl><p>' . esc_html__( 'Transfiere el importe exacto. El pedido se confirmará automáticamente al recibirse la notificación válida de pago.', 'cannaland-payments' ) . '</p></section>';
            Diagnostics::event( 'instructions_displayed', (int) $order_id, 'instructions', array( 'reason' => 'displayed', 'result' => 'success' ) );
        } catch ( \Throwable $e ) {
            Diagnostics::event( 'instructions_exception', (int) $order_id, 'instructions', array( 'reason' => 'exception', 'result' => 'local_error', 'exception' => $e ) );
            throw $e;
        }
    }
}
