<?php
/**
 * Settings.php
 *
 * @package  Conexa\DappWoo\Gateway\
 */

namespace Conexa\DappWoo\Gateway;

use Conexa\DappWoo\Includes\Helper\Helper;

return apply_filters(
	'wc_dappwoo_form_fields',
	array(
		'enabled'                        => array(
			'title'   => __( 'Activar/desactivar', 'dappwoo' ),
			'type'    => 'checkbox',
			'label'   => __( ' Habilitar pasarela de pago de Dapp', 'dappwoo' ),
			'default' => 'yes',
		),

		'wc_dappwoo_api_token'           => array(
			'title' => __( 'Api Key de cliente', 'dappwoo' ),
			'type'  => 'password',
		),

        'wc_dappwoo_is_production' => array(
            'title'   => __( 'Tipo de entorno', 'dappwoo' ),
            'type'    => 'select',
            'options' => array(
                'production' => __('Production'),
                'sandbox' => __('Sandbox')
            ),
            'default' => 'production',
        ),

		'wc_dappwoo_validations_section' => array(
			'title'       => __( 'Validación', 'dappwoo' ),
			'type'        => 'title',
			'description' => Helper::validate_all_html(),
		),

		'wc_dappwoo_log_enabled'         => array(
			'title'       => __( 'Activar/desactivar', 'dappwoo' ),
			'type'        => 'checkbox',
			'label'       => __( 'Activar registros', 'dappwoo' ),
			'description' => sprintf(
				__(
					'Puede habilitar la depuración del plugin para rastrear la comunicación entre el complemento y la API de Dapp. Podrá ver el registro desde el menú <a href="%s">WooCommerce > Estado > Registros</a>.',
					'dappwoo'
				),
				esc_url( get_admin_url( null, 'admin.php?page=wc-status&tab=logs' ) )
			),
			'default'     => 'yes',
		),


		'wc_dappwoo_webhook'             => array(
			'title'       => __( 'Webhook', 'dappwoo' ),
			'type'        => 'title',
			'id'          => 'wc-dappwoo-webhook',
			'description' => sprintf(
				__(
					'Url para comunicarse vía webhook <strong>[ %s ]</strong>.',
					'dappwoo'
				),
				esc_url( get_site_url( null, '/wc-api/wc-dapp' ) )
			),
		),



	)
);


