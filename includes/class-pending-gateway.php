<?php
namespace Cannaland\SinergyPay;
defined( 'ABSPATH' ) || exit;

/** Administrative scaffold only. Each module owns its future business service and checkout UI. */
abstract class PendingGateway extends \WC_Payment_Gateway {
    abstract protected function method_id(): string;
    abstract protected function pending_message(): string;
    abstract protected function service();

    public function __construct() {
        $this->id = $this->method_id();
        $definition = Methods::get( $this->id );
        $this->has_fields = false;
        $this->supports = array( 'products' );
        $this->method_title = $definition['title'];
        $this->method_description = $this->pending_message();
        $this->init_form_fields();
        $this->init_settings();
        $this->enabled = $this->get_option( 'enabled', 'no' );
        $this->title = $this->get_option( 'title', $definition['title'] );
        $this->description = $this->get_option( 'description', $definition['description'] );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
    }
    public function init_form_fields() {
        $definition = Methods::get( $this->id );
        $this->form_fields = array(
            'status' => array( 'title' => __( 'Integración pendiente', 'cannaland-sinergypay' ), 'type' => 'title',
                'description' => esc_html( $this->pending_message() ) . ' ' . esc_html__( 'No se ofrece al comprador hasta que exista una implementación operativa. Habilitar esta opción no activa cobros.', 'cannaland-sinergypay' ) ),
            'enabled' => array( 'title' => __( 'Habilitar', 'cannaland-sinergypay' ), 'type' => 'checkbox', 'default' => 'no',
                'label' => __( 'Guardar habilitación independiente para este método', 'cannaland-sinergypay' ) ),
            'title' => array( 'title' => __( 'Título', 'cannaland-sinergypay' ), 'type' => 'text', 'default' => $definition['title'] ),
            'description' => array( 'title' => __( 'Descripción', 'cannaland-sinergypay' ), 'type' => 'textarea', 'default' => $definition['description'] ),
        );
    }
    public function is_available() {
        return parent::is_available() && $this->service()->is_ready();
    }
    public function validate_fields() {
        wc_add_notice( $this->pending_message(), 'error' );
        return false;
    }
    public function process_payment( $order_id ) {
        // Defense in depth against direct checkout requests for an unavailable method.
        $error = $this->service()->prepare( (int) $order_id );
        wc_add_notice( $error->get_error_message(), 'error' );
        return array( 'result' => 'failure' );
    }
}
