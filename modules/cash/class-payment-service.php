<?php
namespace Cannaland\SinergyPay\Cash;
defined( 'ABSPATH' ) || exit;

/** No voucher/reference creation, expiry or payment confirmation contract is assumed. */
final class PaymentService {
    public function is_ready(): bool { return false; }
    public function prepare( int $order_id ): \WP_Error {
        return new \WP_Error( 'csp_cash_pending', __( 'Pagos en efectivo aún no está disponible: falta la documentación oficial de SinergyPay.', 'cannaland-sinergypay' ) );
    }
}
