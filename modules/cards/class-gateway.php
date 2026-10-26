<?php
namespace Cannaland\Payments\Cards;
defined( 'ABSPATH' ) || exit;

final class Gateway extends \Cannaland\Payments\PendingGateway {
    protected function method_id(): string { return \Cannaland\Payments\Methods::CARDS; }
    protected function pending_message(): string {
        return __( 'Tarjeta: integración pendiente de documentación/API oficial y definición PCI. No procesa pagos.', 'cannaland-payments' );
    }
    protected function service(): PaymentService { return new PaymentService(); }
}
