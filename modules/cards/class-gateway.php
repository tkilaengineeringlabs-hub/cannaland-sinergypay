<?php
namespace Cannaland\SinergyPay\Cards;
defined( 'ABSPATH' ) || exit;

final class Gateway extends \Cannaland\SinergyPay\PendingGateway {
    protected function method_id(): string { return \Cannaland\SinergyPay\Methods::CARDS; }
    protected function pending_message(): string {
        return __( 'Tarjetas: integración pendiente de documentación/API oficial y definición PCI. No procesa pagos.', 'cannaland-sinergypay' );
    }
    protected function service(): PaymentService { return new PaymentService(); }
}
