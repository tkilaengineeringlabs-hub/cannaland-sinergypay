<?php
namespace Cannaland\Payments;
defined( 'ABSPATH' ) || exit;

/** Read-only presentation of existing SPEI metadata. Never calls the provider or saves orders. */
final class SpeiPresentation {
    private array $email_rendered = array();

    public static function register(): void {
        static $instance;
        if ( $instance ) { return; }
        $instance = new self();
        // A new order-details render is a new email (including legitimate resends).
        add_action( 'woocommerce_email_order_details', array( $instance, 'begin_email' ), 1, 4 );
        add_action( 'woocommerce_email_before_order_table', array( $instance, 'email' ), 10, 4 );
        add_action( 'woocommerce_admin_order_data_after_order_details', array( $instance, 'admin' ) );
    }
    public static function eligible( $order ): bool {
        return $order && Support::GATEWAY === $order->get_payment_method() && ! $order->is_paid() &&
            in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) &&
            'ready' === Support::meta( $order, 'preparation' ) && Support::clabe( Support::meta( $order, 'clabe' ) );
    }
    public static function expiration( $value ): string {
        if ( ! is_string( $value ) || '' === $value ) { return ''; }
        try {
            // The provider's ISO offset determines the instant; WP determines the displayed timezone.
            $date = new \DateTimeImmutable( $value, wp_timezone() );
            return (string) wp_date( get_option( 'date_format', 'F j, Y' ) . ', ' . get_option( 'time_format', 'g:i a' ), $date->getTimestamp(), wp_timezone() );
        } catch ( \Exception $e ) {
            return __( 'Fecha no disponible', 'cannaland-payments' );
        }
    }
    private static function fields( $order ): array {
        $amount = Support::money( Support::meta( $order, 'amount' ) );
        $currency = (string) Support::meta( $order, 'currency' );
        $price = null === $amount ? __( 'Importe no disponible', 'cannaland-payments' ) :
            html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' ) . ' ' . $currency;
        $reference = (string) Support::meta( $order, 'reference' );
        if ( Support::meta( $order, 'reference_num' ) ) {
            $reference .= ' (' . __( 'Referencia del proveedor', 'cannaland-payments' ) . ': ' . Support::meta( $order, 'reference_num' ) . ')';
        }
        $fields = array(
            __( 'CLABE', 'cannaland-payments' ) => Support::meta( $order, 'clabe' ),
            __( 'Importe', 'cannaland-payments' ) => str_replace( "\xc2\xa0", ' ', $price ),
            __( 'Referencia', 'cannaland-payments' ) => $reference,
        );
        $expiration = self::expiration( Support::meta( $order, 'expiration_date' ) );
        if ( '' !== $expiration ) { $fields[ __( 'Válida hasta', 'cannaland-payments' ) ] = $expiration; }
        return $fields;
    }
    public static function render( $order, bool $plain_text = false, bool $email = false ): void {
        $fields = self::fields( $order );
        if ( $plain_text ) {
            echo "\n" . __( 'Transferencia SPEI', 'cannaland-payments' ) . "\n";
            foreach ( $fields as $label => $value ) {
                // Provider strings cannot inject HTML or additional lines into plain-text email.
                echo $label . ': ' . str_replace( array( "\r", "\n" ), ' ', wp_strip_all_tags( (string) $value ) ) . "\n";
            }
            echo "\n";
            return;
        }
        echo '<section class="woocommerce-order-details cannaland-payments-instructions"><h2>' . esc_html__( 'Transferencia SPEI', 'cannaland-payments' ) . '</h2>';
        echo '<table class="woocommerce-table shop_table shop_table_responsive"><tbody>';
        foreach ( $fields as $label => $value ) {
            echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><span style="user-select:all">' . esc_html( $value ) . '</span></td></tr>';
        }
        echo '</tbody></table>';
        if ( ! $email ) {
            echo '<p>' . esc_html__( 'Transfiere el importe exacto. El pedido se confirmará automáticamente al recibirse la notificación válida de pago.', 'cannaland-payments' ) . '</p>';
        }
        echo '</section>';
    }
    public function begin_email( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
        $this->email_rendered = array();
    }
    public function email( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
        if ( $sent_to_admin || ! is_object( $email ) || ! $email->is_customer_email() || ! self::eligible( $order ) ) { return; }
        $key = spl_object_id( $email ) . ':' . $order->get_id() . ':' . (int) $plain_text;
        if ( isset( $this->email_rendered[$key] ) ) { return; }
        $this->email_rendered[$key] = true;
        self::render( $order, (bool) $plain_text, true );
    }
    public function admin( $order ): void {
        if ( ! $order || Support::GATEWAY !== $order->get_payment_method() || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) { return; }
        $states = array(
            '' => __( 'Sin iniciar', 'cannaland-payments' ), 'ready' => __( 'Instrucciones preparadas', 'cannaland-payments' ),
            'sending' => __( 'Envío iniciado', 'cannaland-payments' ), 'succeeded' => __( 'Completado', 'cannaland-payments' ),
            'ambiguous' => __( 'Requiere conciliación', 'cannaland-payments' ), 'rejected' => __( 'Rechazado', 'cannaland-payments' ),
            'local_error' => __( 'Error local', 'cannaland-payments' ), 'applying' => __( 'Confirmación en revisión', 'cannaland-payments' ),
            'paid' => __( 'Pago confirmado', 'cannaland-payments' ),
        );
        $fields = array();
        foreach ( array( 'preparation' => 'Preparación SPEI', 'order_state' => 'Creación de operación', 'clabe_state' => 'Generación de CLABE', 'confirmation_state' => 'Confirmación de pago' ) as $key => $label ) {
            $value = (string) Support::meta( $order, $key );
            $fields[ __( $label, 'cannaland-payments' ) ] = 'confirmation_state' === $key && '' === $value ?
                __( 'Pendiente de notificación', 'cannaland-payments' ) : ( $states[$value] ?? __( 'Requiere revisión', 'cannaland-payments' ) );
        }
        foreach ( array( 'clabe' => 'CLABE', 'reference' => 'Referencia', 'reference_num' => 'Referencia del proveedor', 'remote_order_id' => 'ID de operación SinergyPay' ) as $key => $label ) {
            $value = Support::meta( $order, $key );
            if ( '' !== $value ) { $fields[ __( $label, 'cannaland-payments' ) ] = $value; }
        }
        $expiration = self::expiration( Support::meta( $order, 'expiration_date' ) );
        if ( '' !== $expiration ) { $fields[ __( 'Válida hasta', 'cannaland-payments' ) ] = $expiration; }
        echo '<div class="cannaland-payments-admin"><h3>' . esc_html__( 'Transferencia SPEI', 'cannaland-payments' ) . '</h3><dl>';
        foreach ( $fields as $label => $value ) { echo '<dt><strong>' . esc_html( $label ) . '</strong></dt><dd>' . esc_html( $value ) . '</dd>'; }
        echo '</dl></div>';
    }
}
