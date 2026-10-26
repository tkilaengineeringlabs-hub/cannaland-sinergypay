<?php
namespace Cannaland\SinergyPay\Cash;
defined( 'ABSPATH' ) || exit;

final class Gateway extends \Cannaland\SinergyPay\PendingGateway {
    protected function method_id(): string { return \Cannaland\SinergyPay\Methods::CASH; }
    protected function pending_message(): string {
        return __( 'Pagos en efectivo: integración pendiente de documentación/API oficial. No procesa pagos.', 'cannaland-sinergypay' );
    }
    protected function service(): PaymentService { return new PaymentService(); }
}
