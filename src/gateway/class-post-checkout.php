<?php
/**
 * Class PostCheckout
 *
 * @package  Conexa\DappWoo\Gateway\PostCheckout
 */

namespace Conexa\DappWoo\Gateway;

use Conexa\DappWoo\Includes\Helper\Helper;
use Conexa\DappWoo\Sdk\DappWooSdk;
use \WC_Payment_Gateway;
use Conexa\DappWoo\Gateway\WC_DappWoo;

defined( 'ABSPATH' ) || exit();
/**
 * Post Checkout Page Controller
 */
class PostCheckout {

	/**
	 * Run Action
	 *
	 * @param int $order_id ID for WC Order.
	 *
	 * @return bool
	 */
	public static function render( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || \DappWoo::GATEWAY_ID !== $order->get_payment_method() ) {
			return false;
		}

		?>
		<div id="alert_text"></div>
		<span class="cta-dapp-post-checkout" style="font-size:110%">
		<?php echo esc_html__( 'Haga clic en el botón para completar la compra', 'dappwoo' ); ?>
		<span>
		<div class="checkout-top-div-container post-checkout">
		<a id="dapp-cta" class="button btn btn-primary">
			<span>
				<?php echo esc_html__( 'Paga con dapp® ', 'dappwoo' ); ?> 
			</span>
			<img src="<?php echo esc_url( Helper::get_assets_folder_url() ); ?>/img/logotype_dapp_primary.png" alt="<?php echo esc_attr__( 'Dapp', 'dappwoo' ); ?>">
		</a>
		<a id="select-gateway" class="button btn btn-secondary" href="<?php echo esc_url( $order->get_checkout_payment_url( false ) ); ?>">
			<span>
				<?php echo esc_html__( 'Seleccione otro método de pago', 'dappwoo' ); ?> 
			</span>			
		</a>
		</div>		
		<style>			
			#dapp-cta{
				min-width: 225px;
				background: #bdbdbd;
				border-radius: 5px;
				color: white;		
				text-align:center;			
			}
			#dapp-cta *{
				display: inline-block;
			}
			#dapp-cta img{
				max-height: 30px;				
				vertical-align:middle;
				margin: -5px;
				margin-left: 6px;
			}

			.dapp-container-spinner img{
				animation: rotation 1s infinite linear;
			}
			@keyframes rotation {
				from {
					transform: rotate(0deg);
				}
				to {
					transform: rotate(359deg);
				}
			}
			#dapp-container{
				z-index: 9999 !important;
			}
		</style>
		<?php
			$dapp_cta_flag = isset( $_GET['dapp_cta'] ) ? 'true' : 'false';
		if ( ! isset( $_GET['dapp_nonce'] ) ) {
			$dapp_cta_flag = 'false';
		} else {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['dapp_nonce'] ) ), \DappWoo::GATEWAY_ID ) ) {
				$dapp_cta_flag = 'false';
			}
		}
		?>
		<script type="text/javascript">
			var wc_dapp_settings = {
				action: "dapp_request_deposit_action",
				ajax_url : "<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>",
				order_id : "<?php echo esc_html( $order_id ); ?>",
				order_key : <?php echo wp_json_encode( $order->get_order_key(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>,
                spinner_id : 'dapp-container-spinner',
				spinner_url : '<?php echo esc_url( Helper::get_assets_folder_url() ); ?>/img/loading-spinner.png',
				ajax_nonce : "<?php echo esc_attr( wp_create_nonce( \DappWoo::GATEWAY_ID ) ); ?>", 
				//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				dapp_cta_flag: <?php echo esc_html( $dapp_cta_flag ); ?>, 
			}
		</script>
		<?php

		wp_enqueue_script( 'dapp-gateway' );

		return true;
	}
}
