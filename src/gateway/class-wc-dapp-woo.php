<?php
/**
 * Class WC_DappWoo
 *
 * @package  Conexa\DappWoo\Gateway\WC_DappWoo
 */

namespace Conexa\DappWoo\Gateway;

use Conexa\DappWoo\Includes\Helper\Helper;
use Conexa\DappWoo\Sdk\DappWooSdk;

defined( 'ABSPATH' ) || class_exists( '\WC_Payment_Gateway' ) || exit();

/**
 * Main Class DappWoo Payment.
 */
class WC_DappWoo extends \WC_Payment_Gateway {

    public $instructions;
    public $payment_options;
    public $log_enabled;
    public $api_token;
    public bool $is_production;
    public DappWooSdk $sdk;
	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		$this->id                 = \DappWoo::GATEWAY_ID;
		$this->has_fields         = false;
		$this->method_title       = __( 'Dapp', 'dappwoo' );
		$this->method_description = __( 'La revolución de los pagos, hoy.', 'dappwoo' );
		$this->title              = __( 'Paga con dapp®', 'dappwoo' );
		$this->description        = __( 'Recibe pagos digitales fácil, rápido y seguro ¡El futuro de los pagos móviles hoy!', 'dappwoo' );

		// Define user set variables.
		$this->instructions = $this->get_option(
			$this->description,
			$this->method_description
		);

		$this->icon = Helper::get_assets_folder_url() . '/img/logotype_dapp_primary.png';

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();
		// $this->supports[] = 'refunds';

		$this->payment_options = $this->get_option( 'wc_dappwoo_payment_options' );
		$this->log_enabled     = $this->get_option( 'wc_dappwoo_log_enabled' );
		$this->api_token       = $this->get_option( 'wc_dappwoo_api_token' );
        $this->is_production   = $this->get_option( 'wc_dappwoo_is_production' ) == 'production';
		$this->sdk             = new DappWooSdk( $this->api_token, $this->is_production );

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array(
				$this,
				'process_admin_options',
			)
		);
		add_action(
			'woocommerce_thankyou_' . $this->id,
			array(
				$this,
				'thankyou_page',
			)
		);

		global $current_section;
		if ( \DappWoo::GATEWAY_ID === $current_section ) {

			$this->enqueue_settings_js();

		}

	}

	/**
	 * Process the payment and return the result
	 *
	 * @param int $order_id ID of Woo Order.
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$payment_nonce = wp_create_nonce( \DappWoo::GATEWAY_ID );
		// Return thankyou redirect.
		return array(
			'result'   => 'success',
			'redirect' => add_query_arg( 'dapp_nonce', $payment_nonce, add_query_arg( 'dapp_cta', true, $order->get_checkout_payment_url( true ) ) ),
		);
	}

	/**
	 * Set if Dapp must be available or not
	 *
	 * @param Array $available_gateways Array of Available Gateways.
	 *
	 * @return Array
	 */
	public static function available_payment_method( $available_gateways ) {
		if ( ! WC()->customer ) {
			return $available_gateways;
		}

		if ( 'MXN' !== Helper::get_currency() && isset( $available_gateways[ \DappWoo::GATEWAY_ID ] ) ) {
			unset( $available_gateways[ \DappWoo::GATEWAY_ID ] );
		}

		return $available_gateways;
	}

	/**
	 * Output for the order received page.
	 *
	 * @param string $order_id Order Id.
	 */
	public function thankyou_page( $order_id ) {
		// Nothing to add, but required to avoid Warnings.

		Helper::handle_payment( $order_id, '' );
	}

	/**
	 * Initialize Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = include 'settings.php';
	}

	/**
	 * Process the payment and return the result
	 *
	 * @param int $order_id of Woo Order.
	 * @param int $amount of Woo Order.
	 * @param int $reason of Woo Order.
	 *
	 * @return array
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {

		$order       = wc_get_order( $order_id );
		$order_total = $order->get_total();
		$meta        = $order->get_meta( '_DAPP_INTERNAL_DEPOSIT_ID' );

		if ( empty( $order_id ) ) {
			return false;
		}

		if ( $amount !== $order_total ) {
			return new \WP_Error( 'wcdapp-refound-rsp', __( 'El monto debe ser igual al total de la orden.', 'dappwoo' ) );
		}

		$response = $this->sdk->request_refund(
			$order_id,
			array(
				'amount'     => $amount,
				'reason'     => $reason,
				'cashout_id' => $meta,
			)
		);

		$cashout_id = isset( $response['data']['id'] ) ? $response['data']['id'] : '';

		if ( empty( $cashout_id ) ) {
			return new \WP_Error( 'wcdapp-refound-rsp', __( 'Dapp Cashout failed.', 'dappwoo' ) );
		}
		$order->add_order_note( sprintf( __( 'Dapp - Cashout requested. ID %s', 'dappwoo' ), $cashout_id ) );

		$order->save();

		return true;

	}

	/**
	 * Enqueue_settings_js
	 */
	private function enqueue_settings_js() {
		wc_enqueue_js(
			'var url = "' . esc_url( Helper::get_assets_folder_url() . '/img/logotype_dapp_primary.png' ) . '";
			jQuery(document).ready(
				function( $ ) {
					$(".logotype_dapp").remove();
					var image = new Image();
					image.src = url;
					image.classList.add("logotype_dapp");
					image.style.width = "80px";
					image.style.height = "auto";
					image.style.position = "relative";
					image.style.bottom = "-8px";
					image.style.border-right = "10px solid transparent";
					$("h2", $(".wrap.woocommerce")).first().prepend(image);
				});'
		);
	}

}
