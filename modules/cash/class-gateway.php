<?php
namespace Cannaland\Payments\Cash;
defined( 'ABSPATH' ) || exit;

final class Gateway extends \Cannaland\Payments\PendingGateway {
    protected function method_id(): string { return \Cannaland\Payments\Methods::CASH; }
    protected function pending_message(): string {
        return __( 'Pago en efectivo: integración pendiente de documentación/API oficial. No procesa pagos.', 'cannaland-payments' );
    }
    protected function service(): PaymentService { return new PaymentService(); }
}
