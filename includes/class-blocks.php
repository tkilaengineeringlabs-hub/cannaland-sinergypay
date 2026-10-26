<?php
namespace Cannaland\Payments;
defined( 'ABSPATH' ) || exit;

/** One independent WooCommerce Blocks adapter instance per gateway. */
final class Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
    protected $name = Support::GATEWAY;
    public function __construct( string $id = Support::GATEWAY ) {
        Methods::get( $id );
        $this->name = $id;
    }
    public function initialize() {
        $settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
        $this->settings = is_array( $settings ) ? $settings : array();
    }
    public function is_active() {
        $gateways = WC()->payment_gateways()->payment_gateways();
        return isset( $gateways[$this->name] ) && $gateways[$this->name]->is_available();
    }
    public function get_payment_method_script_handles() {
        $definition = Methods::get( $this->name );
        wp_register_script( $definition['handle'], plugins_url( $definition['script'], CANNALAND_PAYMENTS_FILE ),
            array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ), CANNALAND_PAYMENTS_VERSION, true );
        return array( $definition['handle'] );
    }
    public function get_payment_method_data() {
        $definition = Methods::get( $this->name );
        return array( 'title' => $this->settings['title'] ?? $definition['title'],
            'description' => wp_strip_all_tags( $this->settings['description'] ?? $definition['description'] ),
            'available' => $this->is_active(), 'supports' => array( 'products' ) );
    }
}
