<?php
namespace Cannaland\SinergyPay\Cards;
defined( 'ABSPATH' ) || exit;

/** No PAN/CVV collection, tokenization, SDK or provider requests until the contract and PCI scope are defined. */
final class PaymentService {
    public function is_ready(): bool { return false; }
    public function prepare( int $order_id ): \WP_Error {
        return new \WP_Error( 'csp_cards_pending', __( 'Tarjetas aún no está disponible: falta el contrato de SinergyPay y la definición de requisitos PCI.', 'cannaland-sinergypay' ) );
    }
}
