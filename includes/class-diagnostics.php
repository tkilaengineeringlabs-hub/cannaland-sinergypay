<?php
namespace Cannaland\Payments;
defined( 'ABSPATH' ) || exit;

/** Explicit allowlisted telemetry. External text/keys/values are never logged wholesale. */
final class Diagnostics {
    const SOURCE = 'cannaland-payments';
    private const EVENTS = array(
        'webhook_received', 'webhook_dispatch_rejected', 'invalid_json', 'invalid_signature', 'reference_missing', 'order_not_found',
        'amount_mismatch', 'duplicate_webhook', 'payment_confirmed', 'webhook_rejected', 'webhook_exception',
        'process_payment_started', 'process_payment_failed', 'process_payment_exception', 'process_payment_success',
        'preparation_failed', 'preparation_exception', 'reference_prepared', 'preparation_reused',
        'create_order_request', 'create_order_response', 'remote_order_saved',
        'create_clabe_request', 'create_clabe_response', 'clabe_saved', 'order_on_hold',
        'instructions_started', 'instructions_skipped', 'instructions_displayed', 'instructions_exception',
    );
    private const STAGES = array(
        'webhook_dispatch', 'webhook_entry', 'webhook_body', 'webhook_verification', 'webhook_shape', 'webhook_lookup', 'webhook_lock', 'webhook_consistency', 'webhook_confirmation',
        'process_payment', 'order_lock', 'order_validation', 'configuration_validation', 'reference_persistence',
        'snapshot_validation', 'order_state_validation', 'order_sending_persistence', 'create_order',
        'order_response_validation', 'remote_order_persistence', 'order_details_persistence',
        'clabe_state_validation', 'clabe_sending_persistence', 'create_clabe', 'clabe_response_validation',
        'clabe_persistence', 'order_state_persistence', 'clabe_state_persistence', 'on_hold_transition', 'preparation', 'cart_clear', 'redirect', 'instructions',
    );
    private const REASONS = array(
        'dispatch_rejected', 'invalid_signature', 'reference_missing', 'reference_format_invalid', 'invalid_signed_fields', 'missing_signed_fields',
        'invalid_security_structure', 'unsupported_security_version', 'security_missing', 'crypto_unavailable',
        'payments_shape_invalid', 'payment_product_invalid', 'order_inconsistent', 'remote_order_mismatch',
        'reference_mismatch', 'environment_mismatch', 'preparation_not_ready', 'transaction_conflict',
        'confirmation_interrupted', 'claim_unavailable', 'claim_conflict', 'confirmed', 'duplicate',
        'started', 'saved', 'success', 'reused', 'prepared', 'displayed', 'not_ready', 'lock_unavailable',
        'order_not_found', 'wrong_gateway', 'already_paid', 'invalid_order_status', 'unsupported_currency',
        'invalid_environment', 'missing_api_key', 'invalid_api_key_format', 'invalid_expiration',
        'invalid_order_amount', 'snapshot_changed', 'interrupted_sending', 'phase_blocked', 'remote_order_not_ready',
        'request_json_encoding_failed', 'invalid_request_amount', 'invalid_remote_id', 'network_error',
        'provider_success', 'provider_rejected', 'http_status_unexpected', 'invalid_json', 'invalid_envelope',
        'success_criteria_not_met', 'invalid_response_data', 'unexpected_transport_type',
        'amount_type_invalid', 'amount_mismatch', 'currency_mismatch', 'description_mismatch',
        'expiration_invalid', 'invalid_clabe_type', 'invalid_clabe_length', 'invalid_clabe_characters',
        'preparation_error', 'exception',
    );
    private const TYPES = array( 'json_number', 'null', 'string', 'integer', 'double', 'boolean', 'array', 'object', 'resource', 'missing' );
    private const KINDS = array( 'not_sent', 'in_progress', 'success', 'local_error', 'rejected', 'ambiguous', 'blocked', 'reused' );
    private const KEYS = array( 'rc', 'msg', 'data', 'error', 'errors', 'code', 'message', 'status',
        'id', 'creation_date', 'expiration_date', 'description', 'amount', 'currency', 'reference_num', 'clabe', 'reference', 'date', 'payments', 'security' );

    public static function event( string $event, int $order_id, string $stage, array $details = array() ): void {
        try {
            if ( 'yes' !== ( Support::settings()['logging'] ?? 'no' ) || ! function_exists( 'wc_get_logger' ) ||
                ! in_array( $event, self::EVENTS, true ) || ! in_array( $stage, self::STAGES, true ) ) { return; }
            $context = array( 'source' => self::SOURCE, 'diagnostics' => 'spei-v1', 'stage' => $stage,
                'order_id' => max( 0, $order_id ), 'endpoint' => 'local', 'response_type' => 'not_applicable' );
            $endpoint = $details['endpoint'] ?? 'local';
            if ( in_array( $endpoint, array( 'local', '/orders/', '/orders/{order_id}/clabe', '/wp-json/cannaland-payments/v1/payment-notification' ), true ) ) { $context['endpoint'] = $endpoint; }
            foreach ( array( 'reason' => self::REASONS, 'result' => self::KINDS,
                'stored_state' => array( 'sending', 'succeeded', 'ambiguous', 'rejected', 'local_error', 'paid', 'ready', 'applying', 'missing' ), 'value_type' => self::TYPES,
                'response_type' => array( 'not_applicable', 'wp_error', 'http_response', 'json_object', 'json_array', 'json_null', 'json_string', 'json_number', 'json_boolean', 'invalid_json', 'empty_body', 'unexpected_transport_type' ) ) as $key => $allowed ) {
                if ( in_array( $details[$key] ?? null, $allowed, true ) ) { $context[$key] = $details[$key]; }
            }
            foreach ( array( 'http_status' => array( 100, 599 ), 'rc' => array( -999999, 999999 ), 'curl_code' => array( 0, 999 ) ) as $key => $bounds ) {
                if ( isset( $details[$key] ) && is_int( $details[$key] ) && $details[$key] >= $bounds[0] && $details[$key] <= $bounds[1] ) { $context[$key] = $details[$key]; }
            }
            if ( is_bool( $details['signature_verified'] ?? null ) ) { $context['signature_verified'] = $details['signature_verified']; }
            if ( isset( $details['wp_error_code'] ) ) { $context['wp_error_code'] = self::error_code( $details['wp_error_code'] ); }
            if ( isset( $details['provider_message'] ) ) {
                $context['provider_message'] = in_array( $details['provider_message'], array( 'Ok', 'OK', 'ok', 'Success', 'Unauthorized', 'Forbidden', 'Invalid API key', 'Rejected', 'Error' ), true ) ? $details['provider_message'] : '[omitted_untrusted_message]';
            }
            foreach ( array( 'response_keys', 'data_keys' ) as $field ) {
                if ( is_array( $details[$field] ?? null ) ) {
                    $context[$field] = array_values( array_intersect( self::KEYS, array_filter( $details[$field], 'is_string' ) ) );
                    if ( count( $details[$field] ) !== count( $context[$field] ) ) { $context[$field][] = '[other_keys_omitted]'; }
                }
            }
            if ( is_array( $details['field_types'] ?? null ) ) {
                foreach ( self::KEYS as $key ) {
                    if ( in_array( $details['field_types'][$key] ?? null, self::TYPES, true ) ) { $context['field_types'][$key] = $details['field_types'][$key]; }
                }
            }
            if ( isset( $details['exception'] ) && $details['exception'] instanceof \Throwable ) {
                $class = get_class( $details['exception'] );
                $context['exception_class'] = strlen( $class ) <= 160 && preg_match( '/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $class ) ? $class : 'anonymous_or_unrecognized_throwable';
                $message = $details['exception']->getMessage();
                // Category only: exception text may contain credentials, SQL or request bodies.
                $context['exception_message'] = $details['exception'] instanceof \TypeError ? 'Type mismatch.' : '[omitted_untrusted_message]';
                foreach ( array( 'Call to undefined function ' => 'Undefined function.', 'Call to undefined method ' => 'Undefined method.', 'Too few arguments ' => 'Too few arguments.' ) as $prefix => $safe ) {
                    if ( str_starts_with( $message, $prefix ) ) { $context['exception_message'] = $safe; break; }
                }
            }
            $context['certainty'] = array( 'success' => 'confirmed', 'local_error' => 'definitive_local', 'rejected' => 'definitive_rejection', 'ambiguous' => 'ambiguous', 'not_sent' => 'not_sent', 'in_progress' => 'pending', 'reused' => 'confirmed', 'blocked' => 'not_attempted' )[$context['result'] ?? ''] ?? 'not_applicable';
            if ( 'rejected' === ( $context['stored_state'] ?? null ) ) { $context['certainty'] = 'definitive_rejection'; }
            if ( in_array( $context['stored_state'] ?? null, array( 'ambiguous', 'sending' ), true ) ) { $context['certainty'] = 'ambiguous'; }
            $failure = str_ends_with( $event, '_failed' ) || str_ends_with( $event, '_exception' ) ||
                in_array( $context['result'] ?? null, array( 'local_error', 'rejected', 'ambiguous', 'blocked' ), true );
            $logger = wc_get_logger();
            if ( $failure ) { $logger->error( $event, $context ); }
            else { $logger->info( $event, $context ); }
        } catch ( \Throwable $ignored ) {
            // Diagnostics must never change payment semantics, retry a POST or throw to checkout.
        }
    }
    public static function notification_shape( \stdClass $payload ): array {
        $details = array( 'response_keys' => array_keys( get_object_vars( $payload ) ), 'field_types' => array() );
        foreach ( array( 'id', 'currency', 'amount', 'description', 'reference', 'date', 'reference_num', 'payments', 'security', 'code', 'clabe' ) as $key ) {
            $value = $payload->{$key} ?? null;
            $details['field_types'][$key] = ! property_exists( $payload, $key ) ? 'missing' : ( $value instanceof JsonNumber ? 'json_number' : self::type( $value ) );
        }
        return $details;
    }
    private static function error_code( $code ): string {
        $known = array( 'http_request_failed', 'http_request_not_executed', 'http_request_rejected', 'http_no_url', 'http_no_protocol', 'http_404',
            'timeout', 'network', 'cannaland_payments_unavailable', 'cannaland_payments_busy', 'cannaland_payments_order',
            'cannaland_payments_configuration', 'cannaland_payments_changed_order', 'cannaland_payments_review', 'cannaland_payments_invalid_clabe' );
        return in_array( $code, $known, true ) ? $code : '[omitted_unrecognized_code]';
    }
    public static function wp_error( \WP_Error $error ): array {
        $details = array( 'wp_error_code' => $error->get_error_code() );
        if ( preg_match( '/\bcURL error ([0-9]{1,3})\b/', $error->get_error_message(), $m ) ) { $details['curl_code'] = (int) $m[1]; }
        return $details;
    }
    private static function type( $value ): string {
        return null === $value ? 'null' : gettype( $value );
    }
    public static function shape( $data ): array {
        $details = array( 'response_keys' => is_array( $data ) ? array_keys( $data ) : array(), 'field_types' => array() );
        if ( is_array( $data ) ) {
            foreach ( array( 'rc', 'msg', 'data' ) as $key ) { $details['field_types'][$key] = array_key_exists( $key, $data ) ? self::type( $data[$key] ) : 'missing'; }
            if ( array_key_exists( 'rc', $data ) ) { $details['rc'] = $data['rc']; }
            if ( isset( $data['msg'] ) ) { $details['provider_message'] = $data['msg']; }
            if ( is_array( $data['data'] ?? null ) ) {
                $details['data_keys'] = array_keys( $data['data'] );
                foreach ( array( 'id', 'amount', 'currency', 'description', 'expiration_date', 'clabe' ) as $key ) {
                    $details['field_types'][$key] = array_key_exists( $key, $data['data'] ) ? self::type( $data['data'][$key] ) : 'missing';
                }
            }
        }
        return $details;
    }
}
