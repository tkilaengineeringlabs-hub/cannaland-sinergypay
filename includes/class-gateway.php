<?php
namespace Cannaland\SinergyPay;
defined( 'ABSPATH' ) || exit;

final class Gateway extends \WC_Payment_Gateway {
    public function __construct() {
        $this->id = Support::GATEWAY;
        $this->has_fields = false;
        $this->supports = array( 'products' );
        $this->method_title = __( 'Transferencia SPEI', 'cannaland-sinergypay' );
        $this->method_description = __( 'Transferencias SPEI a una CLABE generada por SinergyPay. La confirmación se recibe por notificación firmada.', 'cannaland-sinergypay' );
        $this->init_form_fields();
        $this->init_settings();
        $this->enabled = $this->get_option( 'enabled', 'no' );
        $this->title = $this->get_option( 'title', __( 'Transferencia SPEI', 'cannaland-sinergypay' ) );
        $this->description = $this->get_option( 'description', __( 'Paga por transferencia SPEI. Recibirás una CLABE para este pedido.', 'cannaland-sinergypay' ) );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'instructions' ) );
        add_action( 'woocommerce_view_order', array( $this, 'instructions' ) );
    }
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array( 'title' => __( 'Activar', 'cannaland-sinergypay' ), 'type' => 'checkbox', 'label' => __( 'Habilitar transferencia SPEI', 'cannaland-sinergypay' ), 'default' => 'no' ),
            'title' => array( 'title' => __( 'Título', 'cannaland-sinergypay' ), 'type' => 'text', 'default' => __( 'Transferencia SPEI', 'cannaland-sinergypay' ) ),
            'description' => array( 'title' => __( 'Descripción', 'cannaland-sinergypay' ), 'type' => 'textarea', 'default' => __( 'Paga por transferencia SPEI. Recibirás una CLABE para este pedido.', 'cannaland-sinergypay' ) ),
            'environment' => array( 'title' => __( 'Entorno', 'cannaland-sinergypay' ), 'type' => 'select', 'options' => array( 'sandbox' => 'Sandbox', 'production' => 'Producción' ), 'default' => 'sandbox' ),
            'api_key' => array( 'title' => 'API key privada', 'type' => 'password', 'default' => '', 'description' => __( 'Usa la llave del entorno seleccionado. Se guarda en la configuración de WordPress; no se registra en logs.', 'cannaland-sinergypay' ) ),
            'expiration_minutes' => array( 'title' => __( 'Vencimiento en minutos', 'cannaland-sinergypay' ), 'type' => 'number', 'default' => '8640', 'custom_attributes' => array( 'min' => '1', 'step' => '1' ) ),
            'logging' => array( 'title' => __( 'Registro seguro', 'cannaland-sinergypay' ), 'type' => 'checkbox', 'label' => __( 'Registrar eventos técnicos mínimos sin datos de pago ni credenciales', 'cannaland-sinergypay' ), 'default' => 'no' ),
            'callback' => array( 'title' => 'Callback SinergyPay', 'type' => 'title', 'description' => esc_html__( 'Proporciona esta URL al equipo SinergyPay para configurar las notificaciones POST:', 'cannaland-sinergypay' ) . ' <code>' . esc_html( rest_url( 'cannaland-sinergypay/v1/payment-notification' ) ) . '</code>' ),
        );
    }
    public function validate_expiration_minutes_field( $key, $value ) {
        $number = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
        if ( false === $number ) { throw new \Exception( __( 'El vencimiento debe ser un número entero positivo.', 'cannaland-sinergypay' ) ); }
        return (string) $number;
    }
    public function is_available() {
        return parent::is_available() && 'MXN' === get_woocommerce_currency() && function_exists( 'openssl_verify' ) &&
            Support::valid_environment( Support::environment() ) && '' !== trim( (string) $this->get_option( 'api_key', '' ) );
    }
    public function process_payment( $order_id ) {
        $prepared = ( new Preparation() )->prepare( (int) $order_id );
        if ( is_wp_error( $prepared ) ) {
            wc_add_notice( $prepared->get_error_message(), 'error' );
            return array( 'result' => 'failure' );
        }
        if ( WC()->cart ) { WC()->cart->empty_cart(); }
        return array( 'result' => 'success', 'redirect' => $this->get_return_url( $prepared ) );
    }
    public function instructions( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || Support::GATEWAY !== $order->get_payment_method() || $order->is_paid() ||
            ! in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) ||
            'ready' !== Support::meta( $order, 'preparation' ) || ! Support::clabe( Support::meta( $order, 'clabe' ) ) ) { return; }
        echo '<section class="cannaland-sinergypay-instructions"><h2>' . esc_html__( 'Transferencia SPEI', 'cannaland-sinergypay' ) . '</h2><dl>';
        $fields = array(
            __( 'CLABE', 'cannaland-sinergypay' ) => Support::meta( $order, 'clabe' ),
            __( 'Importe', 'cannaland-sinergypay' ) => Support::meta( $order, 'amount' ) . ' ' . Support::meta( $order, 'currency' ),
            __( 'Referencia de operación', 'cannaland-sinergypay' ) => Support::meta( $order, 'reference' ),
        );
        if ( Support::meta( $order, 'reference_num' ) ) { $fields[ __( 'Referencia del proveedor', 'cannaland-sinergypay' ) ] = Support::meta( $order, 'reference_num' ); }
        if ( Support::meta( $order, 'expiration_date' ) ) { $fields[ __( 'Vencimiento (incluye zona horaria)', 'cannaland-sinergypay' ) ] = Support::meta( $order, 'expiration_date' ); }
        foreach ( $fields as $label => $value ) { echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>'; }
        echo '</dl><p>' . esc_html__( 'Transfiere el importe exacto. El pedido se confirmará automáticamente al recibirse la notificación válida de pago.', 'cannaland-sinergypay' ) . '</p></section>';
    }
}
