<?php
namespace Cannaland\Payments;
defined( 'ABSPATH' ) || exit;

/** Read-only presentation of existing SPEI metadata. Never calls the provider or saves orders. */
final class SpeiPresentation {
    // Presentation label only: never sent to the provider or stored as bank account data.
    public const BENEFICIARY = 'Cannaland';
    private array $email_rendered = array();

    public static function register(): void {
        static $instance;
        if ( $instance ) { return; }
        $instance = new self();
        add_action( 'wp_enqueue_scripts', array( $instance, 'assets' ) );
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
        $fields = array(
            __( 'Beneficiario', 'cannaland-payments' ) => self::BENEFICIARY,
            __( 'CLABE', 'cannaland-payments' ) => Support::meta( $order, 'clabe' ),
            __( 'Referencia', 'cannaland-payments' ) => (string) Support::meta( $order, 'reference' ),
            __( 'Importe', 'cannaland-payments' ) => str_replace( "\xc2\xa0", ' ', $price ),
        );
        $expiration = self::expiration( Support::meta( $order, 'expiration_date' ) );
        if ( '' !== $expiration ) { $fields[ __( 'Válida hasta', 'cannaland-payments' ) ] = $expiration; }
        return $fields;
    }
    public function assets(): void {
        if ( ! ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) &&
            ! ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-order' ) ) ) { return; }
        wp_enqueue_style( 'cannaland-payments-spei', plugins_url( 'assets/css/spei-instructions.css', CANNALAND_PAYMENTS_FILE ), array(), CANNALAND_PAYMENTS_VERSION );
        wp_enqueue_script( 'cannaland-payments-spei', plugins_url( 'assets/js/spei-instructions.js', CANNALAND_PAYMENTS_FILE ), array(), CANNALAND_PAYMENTS_VERSION, true );
    }
    public static function render( $order, bool $plain_text = false, bool $email = false ): void {
        $fields = self::fields( $order );
        $provider_reference = Support::meta( $order, 'reference_num' );
        if ( $plain_text ) {
            echo "\n" . __( 'Transferencia SPEI', 'cannaland-payments' ) . "\n";
            if ( $provider_reference ) { $fields[ __( 'Referencia del proveedor', 'cannaland-payments' ) ] = $provider_reference; }
            foreach ( $fields as $label => $value ) {
                echo $label . ': ' . str_replace( array( "\r", "\n" ), ' ', wp_strip_all_tags( (string) $value ) ) . "\n";
            }
            echo "\n";
            return;
        }
        if ( $email ) {
            // Email-safe markup: no scripts, clipboard controls, frontend styles or data attributes.
            echo '<h2>' . esc_html__( 'Transferencia SPEI', 'cannaland-payments' ) . '</h2><table cellspacing="0" cellpadding="10" style="width:100%;border-collapse:collapse;text-align:left"><tbody>';
            if ( $provider_reference ) { $fields[ __( 'Referencia del proveedor', 'cannaland-payments' ) ] = $provider_reference; }
            foreach ( $fields as $label => $value ) {
                echo '<tr><th scope="row" style="vertical-align:top">' . esc_html( $label ) . '</th><td style="overflow-wrap:anywhere;word-break:break-word">' . esc_html( $value ) . '</td></tr>';
            }
            echo '</tbody></table>';
            return;
        }
        echo '<section class="cannaland-payments-spei" data-cannaland-spei aria-label="' . esc_attr__( 'Datos para transferencia SPEI', 'cannaland-payments' ) . '">';
        echo '<header class="cannaland-payments-spei__header"><h2>' . esc_html__( 'Datos para transferencia SPEI', 'cannaland-payments' ) . '</h2><p>' . esc_html__( 'Realiza tu transferencia utilizando los siguientes datos:', 'cannaland-payments' ) . '</p></header>';
        echo '<dl class="cannaland-payments-spei__fields">';
        $keys = array( 'beneficiary', 'clabe', 'reference', 'amount', 'expiration' );
        $index = 0;
        foreach ( $fields as $label => $value ) {
            $key = $keys[$index++];
            echo '<div class="cannaland-payments-spei__row cannaland-payments-spei__row--' . esc_attr( $key ) . '"><dt data-spei-label="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</dt><dd>';
            echo '<span class="cannaland-payments-spei__value" data-spei-value="' . esc_attr( $key ) . '">' . esc_html( $value ) . '</span>';
            if ( 'reference' === $key && $provider_reference ) {
                echo '<small class="cannaland-payments-spei__secondary">' . esc_html__( 'Referencia del proveedor', 'cannaland-payments' ) . ': ' . esc_html( $provider_reference ) . '</small>';
            }
            echo '</dd>';
            if ( 'expiration' !== $key ) {
                echo '<dd class="cannaland-payments-spei__action">';
                self::copy_button( $key, sprintf( __( 'Copiar %s', 'cannaland-payments' ), $label ) );
                echo '</dd>';
            }
            echo '</div>';
        }
        echo '</dl><footer class="cannaland-payments-spei__footer">';
        self::copy_button( 'all', __( 'Copiar todos los datos SPEI', 'cannaland-payments' ) );
        echo '<span class="cannaland-payments-spei__status" role="status" aria-live="polite" aria-atomic="true"></span></footer>';
        echo '<p class="cannaland-payments-spei__notice">' . esc_html__( 'Transfiere el importe exacto. Tu pedido se confirmará automáticamente cuando se reciba la confirmación válida de pago.', 'cannaland-payments' ) . '</p></section>';
    }
    private static function copy_button( string $key, string $label ): void {
        echo '<button type="button" class="cannaland-payments-spei__copy" hidden data-spei-copy="' . esc_attr( $key ) . '" aria-label="' . esc_attr( $label ) . '" data-copy-label="' . esc_attr__( 'all' === $key ? 'Copiar datos' : 'Copiar', 'cannaland-payments' ) . '" data-copied-label="' . esc_attr__( 'Copiado ✓', 'cannaland-payments' ) . '" data-copy-error="' . esc_attr__( 'No se pudo copiar. Selecciona el valor y cópialo manualmente.', 'cannaland-payments' ) . '">' . esc_html__( 'all' === $key ? 'Copiar datos' : 'Copiar', 'cannaland-payments' ) . '</button>';
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
