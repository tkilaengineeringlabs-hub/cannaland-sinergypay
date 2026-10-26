<?php
namespace Cannaland\Payments\Cash;
defined( 'ABSPATH' ) || exit;

/** No voucher/reference creation, expiry or payment confirmation contract is assumed. */
final class PaymentService {
    public function is_ready(): bool { return false; }
    public function prepare( int $order_id ): \WP_Error {
        return new \WP_Error( 'cannaland_payments_cash_pending', __( 'Pago en efectivo aún no está disponible. Selecciona otro método de pago.', 'cannaland-payments' ) );
    }
}
