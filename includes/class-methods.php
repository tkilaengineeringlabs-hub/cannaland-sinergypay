<?php
namespace Cannaland\SinergyPay;
defined( 'ABSPATH' ) || exit;

/** Local checkout registration metadata, not a provider API contract. */
final class Methods {
    const CARDS = 'cannaland_sinergypay_cards';
    const CASH = 'cannaland_sinergypay_cash';

    public static function all(): array {
        return array(
            self::CARDS => array( 'gateway' => Cards\Gateway::class, 'title' => __( 'Tarjetas', 'cannaland-sinergypay' ),
                'description' => __( 'Integración de tarjetas pendiente de documentación y definición PCI.', 'cannaland-sinergypay' ),
                'script' => 'modules/cards/blocks.js', 'handle' => 'cannaland-sinergypay-cards-blocks' ),
            Support::GATEWAY => array( 'gateway' => Gateway::class, 'title' => __( 'Transferencia SPEI', 'cannaland-sinergypay' ),
                'description' => __( 'Paga mediante transferencia SPEI.', 'cannaland-sinergypay' ),
                'script' => 'assets/js/blocks.js', 'handle' => 'cannaland-sinergypay-blocks' ),
            self::CASH => array( 'gateway' => Cash\Gateway::class, 'title' => __( 'Pagos en efectivo', 'cannaland-sinergypay' ),
                'description' => __( 'Integración de efectivo pendiente de documentación oficial.', 'cannaland-sinergypay' ),
                'script' => 'modules/cash/blocks.js', 'handle' => 'cannaland-sinergypay-cash-blocks' ),
        );
    }
    public static function get( string $id ): array {
        $methods = self::all();
        if ( ! isset( $methods[$id] ) ) { throw new \InvalidArgumentException( 'Unknown payment method.' ); }
        return $methods[$id];
    }
}
