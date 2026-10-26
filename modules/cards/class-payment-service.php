<?php
namespace Cannaland\Payments\Cards;
defined( 'ABSPATH' ) || exit;

/** No PAN/CVV collection, tokenization, SDK or provider requests until the contract and PCI scope are defined. */
final class PaymentService {
    public function is_ready(): bool { return false; }
    public function prepare( int $order_id ): \WP_Error {
        return new \WP_Error( 'cannaland_payments_cards_pending', __( 'Tarjeta aún no está disponible. Selecciona otro método de pago.', 'cannaland-payments' ) );
    }
}
