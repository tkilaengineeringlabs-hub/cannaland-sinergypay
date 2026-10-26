<?php
namespace Cannaland\SinergyPay;
defined( 'ABSPATH' ) || exit;

final class Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
    protected $name = Support::GATEWAY;
    public function initialize() { $this->settings = Support::settings(); }
    public function is_active() {
        $gateways = WC()->payment_gateways()->payment_gateways();
        return isset( $gateways[$this->name] ) && $gateways[$this->name]->is_available();
    }
    public function get_payment_method_script_handles() {
        wp_register_script( 'cannaland-sinergypay-blocks', plugins_url( 'assets/js/blocks.js', CANNALAND_SINERGYPAY_FILE ),
            array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ), CANNALAND_SINERGYPAY_VERSION, true );
        return array( 'cannaland-sinergypay-blocks' );
    }
    public function get_payment_method_data() {
        return array( 'title' => $this->settings['title'] ?? 'Cannaland SinergyPay', 'description' => wp_strip_all_tags( $this->settings['description'] ?? 'Paga mediante transferencia SPEI.' ), 'supports' => array( 'products' ) );
    }
}
