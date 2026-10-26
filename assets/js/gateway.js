/**
 * Generate Dapp Payment Link
 *
 * @package  Conexa\DappWoo\Assets\Js
 */

'use strict'
jQuery( document ).ready(
	function () {
		(function ($, settings) {

			var showSpinner = function () {
				var htmlString =
				"<div class='" +
				settings.spinner_id +
				"'><div class='" +
				settings.spinner_id +
				"-center'><span class='spinner is-active'><img src='" +
				settings.spinner_url +
				"'></span></div></div>"
				htmlString     =
				htmlString +
				'<style> .' +
				settings.spinner_id +
				'{z-index:99999;width: 100%;height: 100%;position: fixed;top: 0;left: 0;opacity: 0.4;background-color:#ccc;text-align: center; } .' +
				settings.spinner_id +
				'-center{position: absolute;top: 50%; left: 50%; transform: translate(-50%, -50%); } .' +
				settings.spinner_id +
				' .spinner{ vertical-align: middle; }' +
				settings.spinner_id +
				' * img{z-index:99999;}</style>'

				$( 'body' ).prepend( htmlString )
			}

			var removeSpinner = function () {
				jQuery( '.' + settings.spinner_id ).remove()
			}

			var cta = function () {
				showSpinner()

				var dataToSend = {
					action: settings.action,
					order_id: settings.order_id,
					nonce: settings.ajax_nonce,
                    order_key: settings.order_key,
				}

				$.post(
					settings.ajax_url,
					dataToSend,
					function (response) {

						if (response.success) {

							console.log( 'dapp-cta' )

							window.location.href = response.data;
							removeSpinner()
						} else {
							console.log( 'onFailurePost' )


							$( "#dapp-cta" ).addClass( "disabled" );
							$( "#alert_text" ).addClass( "woocommerce-info" );
							$( '.woocommerce-info' ).text( response.data );
							removeSpinner()
						}
					}
				).fail(function () { removeSpinner(); $("#alert_text").text("Payment service unavailable. Contact customer service before retrying."); })

			}
			$( '#dapp-cta' ).click(
				function () {
					cta()
				}
			)

			if (settings.dapp_cta_flag) {
				cta()
			}
		})( jQuery, wc_dapp_settings )
	}
)
