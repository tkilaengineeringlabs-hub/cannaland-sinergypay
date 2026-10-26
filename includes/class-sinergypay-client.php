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
    public function create_order( array $body ): array { return $this->post( '/orders/', $body ); }
    public function create_clabe( string $id ): array {
        if ( ! Support::id( $id ) ) { return array( 'kind' => 'local_error' ); }
        return $this->post( '/orders/' . rawurlencode( $id ) . '/clabe', array() );
    }
    private function post( string $path, array $body ): array {
        if ( ! Support::valid_environment( $this->environment ) || '' === trim( $this->key ) || preg_match( '/[\r\n:]/', $this->key ) ) {
            return array( 'kind' => 'local_error' );
        }
        // Send amount as a JSON decimal number without ever converting it to float.
        if ( array_key_exists( 'amount', $body ) ) {
            $amount = Support::money( $body['amount'] );
            if ( null === $amount ) { return array( 'kind' => 'local_error' ); }
            unset( $body['amount'] );
            $rest = wp_json_encode( (object) $body );
            if ( false === $rest ) { return array( 'kind' => 'local_error' ); }
            $json = '{"amount":' . $amount . ( '{}' === $rest ? '}' : ',' . substr( $rest, 1 ) );
        } else { $json = wp_json_encode( (object) $body ); }
        if ( false === $json ) { return array( 'kind' => 'local_error' ); }
        $base = 'production' === $this->environment ? 'https://api.sinergypay.mx/v2' : 'https://sandbox.sinergypay.mx/v2';
        $response = wp_safe_remote_request( $base . $path, array(
            'method' => 'POST', 'timeout' => 20, 'redirection' => 0, 'limit_response_size' => 65536,
            'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $this->key . ':' ),
                'User-Agent' => 'CannalandPayments/' . CANNALAND_PAYMENTS_VERSION, 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
            'body' => $json,
        ) );
        if ( is_wp_error( $response ) ) {
            Support::log( 'api_network' );
            return array( 'kind' => 'ambiguous' );
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        Support::log( 'api_response', $status );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $envelope = JSON_ERROR_NONE === json_last_error() && is_array( $data ) &&
            isset( $data['rc'], $data['msg'] ) && is_int( $data['rc'] ) && is_string( $data['msg'] );
        if ( $status >= 200 && $status < 300 && $envelope && 0 === $data['rc'] && 'Ok' === $data['msg'] && is_array( $data['data'] ?? null ) ) {
            return array( 'kind' => 'success', 'data' => $data['data'] );
        }
        // Only explicit application rejection, not a proxy error, timeout or rate limit.
        if ( $envelope && 0 !== $data['rc'] && ( ( $status >= 200 && $status < 300 ) || in_array( $status, array( 400, 401, 403, 404, 405, 422 ), true ) ) ) {
            return array( 'kind' => 'rejected' );
        }
        return array( 'kind' => 'ambiguous' );
    }
}
