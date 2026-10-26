<?php
namespace Cannaland\Payments;
defined( 'ABSPATH' ) || exit;

final class SinergyPayClient {
    private string $environment;
    private string $key;
    public function __construct( string $environment, string $key ) {
        $this->environment = $environment;
        $this->key = $key;
    }
    public function create_order( array $body, int $order_id = 0 ): array { return $this->post( '/orders/', $body, $order_id, 'create_order' ); }
    public function create_clabe( string $id, int $order_id = 0 ): array {
        if ( ! Support::id( $id ) ) { return $this->result( 'create_clabe', $order_id, 'local_error', array( 'reason' => 'invalid_remote_id' ) ); }
        return $this->post( '/orders/' . rawurlencode( $id ) . '/clabe', array(), $order_id, 'create_clabe' );
    }
    private function post( string $path, array $body, int $order_id, string $operation ): array {
        $sent = false;
        $diagnostic = array();
        try {
            if ( ! Support::valid_environment( $this->environment ) ) {
                return $this->result( $operation, $order_id, 'local_error', array( 'reason' => 'invalid_environment' ) );
            }
            if ( '' === trim( $this->key ) ) {
                return $this->result( $operation, $order_id, 'local_error', array( 'reason' => 'missing_api_key' ) );
            }
            if ( preg_match( '/[\r\n:]/', $this->key ) ) {
                return $this->result( $operation, $order_id, 'local_error', array( 'reason' => 'invalid_api_key_format' ) );
            }
            // Send amount as a JSON decimal number without ever converting it to float.
            if ( array_key_exists( 'amount', $body ) ) {
                $amount = Support::money( $body['amount'] );
                if ( null === $amount ) { return $this->result( $operation, $order_id, 'local_error', array( 'reason' => 'invalid_request_amount' ) ); }
                unset( $body['amount'] );
                $rest = wp_json_encode( (object) $body );
                if ( false === $rest ) { return $this->result( $operation, $order_id, 'local_error', array( 'reason' => 'request_json_encoding_failed' ) ); }
                $json = '{"amount":' . $amount . ( '{}' === $rest ? '}' : ',' . substr( $rest, 1 ) );
            } else { $json = wp_json_encode( (object) $body ); }
            if ( false === $json ) { return $this->result( $operation, $order_id, 'local_error', array( 'reason' => 'request_json_encoding_failed' ) ); }
            $base = 'production' === $this->environment ? 'https://api.sinergypay.mx/v2' : 'https://sandbox.sinergypay.mx/v2';
            Diagnostics::event( $operation . '_request', $order_id, $operation, array( 'endpoint' => $this->endpoint( $operation ), 'reason' => 'started', 'result' => 'in_progress' ) );
            $sent = true;
            $response = wp_safe_remote_request( $base . $path, array(
                'method' => 'POST', 'timeout' => 20, 'redirection' => 0, 'limit_response_size' => 65536,
                'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $this->key . ':' ),
                    'User-Agent' => 'CannalandPayments/' . CANNALAND_PAYMENTS_VERSION, 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
                'body' => $json,
            ) );
            if ( is_wp_error( $response ) ) {
                return $this->result( $operation, $order_id, 'ambiguous', array_merge( Diagnostics::wp_error( $response ), array( 'reason' => 'network_error', 'response_type' => 'wp_error' ) ) );
            }
            if ( ! is_array( $response ) ) {
                return $this->result( $operation, $order_id, 'ambiguous', array( 'reason' => 'unexpected_transport_type', 'response_type' => 'unexpected_transport_type' ) );
            }
            $status = (int) wp_remote_retrieve_response_code( $response );
            $diagnostic = array( 'http_status' => $status, 'response_type' => 'http_response' );
            $raw = wp_remote_retrieve_body( $response );
            $data = json_decode( $raw, true );
            $json_error = json_last_error();
            $response_type = 'invalid_json';
            if ( '' === $raw ) { $response_type = 'empty_body'; }
            elseif ( JSON_ERROR_NONE === $json_error ) {
                if ( is_array( $data ) ) { $response_type = str_starts_with( ltrim( $raw ), '{' ) ? 'json_object' : 'json_array'; }
                else { $response_type = array( 'NULL' => 'json_null', 'string' => 'json_string', 'boolean' => 'json_boolean', 'integer' => 'json_number', 'double' => 'json_number' )[gettype( $data )] ?? 'invalid_json'; }
            }
            $diagnostic = array_merge( Diagnostics::shape( $data ), array( 'http_status' => $status, 'response_type' => $response_type ) );
            $envelope = JSON_ERROR_NONE === $json_error && is_array( $data ) &&
                isset( $data['rc'], $data['msg'] ) && is_int( $data['rc'] ) && is_string( $data['msg'] );
            if ( $status >= 200 && $status < 300 && $envelope && 0 === $data['rc'] && 'Ok' === $data['msg'] && is_array( $data['data'] ?? null ) ) {
                return $this->result( $operation, $order_id, 'success', array_merge( $diagnostic, array( 'reason' => 'provider_success' ) ), $data['data'] );
            }
            // Only explicit application rejection, not a proxy error, timeout or rate limit.
            if ( $envelope && 0 !== $data['rc'] && ( ( $status >= 200 && $status < 300 ) || in_array( $status, array( 400, 401, 403, 404, 405, 422 ), true ) ) ) {
                return $this->result( $operation, $order_id, 'rejected', array_merge( $diagnostic, array( 'reason' => 'provider_rejected' ) ) );
            }
            $reason = 'success_criteria_not_met';
            if ( $status < 200 || $status >= 300 ) { $reason = 'http_status_unexpected'; }
            elseif ( JSON_ERROR_NONE !== $json_error ) { $reason = 'invalid_json'; }
            elseif ( ! $envelope ) { $reason = 'invalid_envelope'; }
            elseif ( ! is_array( $data['data'] ?? null ) ) { $reason = 'invalid_response_data'; }
            return $this->result( $operation, $order_id, 'ambiguous', array_merge( $diagnostic, array( 'reason' => $reason ) ) );
        } catch ( \Throwable $e ) {
            Diagnostics::event( $operation . '_response', $order_id, $operation,
                array_merge( $diagnostic, array( 'endpoint' => $this->endpoint( $operation ), 'reason' => 'exception',
                    'result' => $sent ? 'ambiguous' : 'local_error', 'exception' => $e ) ) );
            throw $e;
        }
    }
    private function endpoint( string $operation ): string {
        return 'create_order' === $operation ? '/orders/' : '/orders/{order_id}/clabe';
    }
    private function result( string $operation, int $order_id, string $kind, array $diagnostic, ?array $data = null ): array {
        $diagnostic['endpoint'] = $this->endpoint( $operation );
        $diagnostic['result'] = $kind;
        Diagnostics::event( $operation . '_response', $order_id, $operation, $diagnostic );
        $result = array( 'kind' => $kind, 'diagnostic' => $diagnostic );
        if ( null !== $data ) { $result['data'] = $data; }
        return $result;
    }
}
