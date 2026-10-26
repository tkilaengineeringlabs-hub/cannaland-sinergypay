<?php
namespace Cannaland\Payments;
defined( 'ABSPATH' ) || exit;

/** Local checkout registration metadata, not a provider API contract. */
final class Methods {
    const CARDS = 'cannaland_cards';
    const CASH = 'cannaland_cash';

    public static function all(): array {
        return array(
            self::CARDS => array( 'gateway' => Cards\Gateway::class, 'title' => __( 'Tarjeta', 'cannaland-payments' ),
                'description' => __( 'Integración de tarjetas pendiente de documentación y definición PCI.', 'cannaland-payments' ),
                'script' => 'modules/cards/blocks.js', 'handle' => 'cannaland-payments-cards-blocks' ),
            Support::GATEWAY => array( 'gateway' => Gateway::class, 'title' => __( 'Transferencia SPEI', 'cannaland-payments' ),
                'description' => __( 'Paga mediante transferencia SPEI.', 'cannaland-payments' ),
                'script' => 'assets/js/blocks.js', 'handle' => 'cannaland-payments-blocks' ),
            self::CASH => array( 'gateway' => Cash\Gateway::class, 'title' => __( 'Pago en efectivo', 'cannaland-payments' ),
                'description' => __( 'Integración de efectivo pendiente de documentación oficial.', 'cannaland-payments' ),
                'script' => 'modules/cash/blocks.js', 'handle' => 'cannaland-payments-cash-blocks' ),
        );
    }
    public static function get( string $id ): array {
        $methods = self::all();
        if ( ! isset( $methods[$id] ) ) { throw new \InvalidArgumentException( 'Unknown payment method.' ); }
        return $methods[$id];
    }
}
