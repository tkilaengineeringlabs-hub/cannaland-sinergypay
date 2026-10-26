<?php
namespace Cannaland\SinergyPay;
defined( 'ABSPATH' ) || exit;

/** JSON numeric lexeme, intentionally never decoded through a PHP float. */
final class JsonNumber {
    public string $text;
    public function __construct( string $text ) { $this->text = $text; }
}

/** Bounded JSON reader: preserves numeric spelling and rejects duplicate object keys. */
final class NotificationJson {
    private string $raw;
    private int $offset = 0;
    public function decode( string $raw ): \stdClass {
        if ( '' === $raw || strlen( $raw ) > 65536 ) { throw new \InvalidArgumentException( 'Invalid payload.' ); }
        $this->raw = $raw;
        $this->offset = 0;
        $value = $this->value( 0 );
        $this->space();
        if ( $this->offset !== strlen( $raw ) || ! $value instanceof \stdClass ) { throw new \InvalidArgumentException( 'Invalid payload.' ); }
        return $value;
    }
    private function space(): void {
        $this->offset += strspn( $this->raw, " \t\r\n", $this->offset );
    }
    private function value( int $depth ) {
        if ( $depth > 16 ) { throw new \InvalidArgumentException( 'Invalid payload.' ); }
        $this->space();
        $char = $this->raw[$this->offset] ?? '';
        if ( '"' === $char ) { return $this->string(); }
        if ( '{' === $char || '[' === $char ) {
            $object = '{' === $char;
            $end = $object ? '}' : ']';
            ++$this->offset;
            $this->space();
            $result = $object ? new \stdClass() : array();
            $seen = array();
            if ( ( $this->raw[$this->offset] ?? '' ) === $end ) { ++$this->offset; return $result; }
            while ( true ) {
                $this->space();
                if ( $object ) {
                    if ( ( $this->raw[$this->offset] ?? '' ) !== '"' ) { throw new \InvalidArgumentException( 'Invalid payload.' ); }
                    $key = $this->string();
                    if ( array_key_exists( $key, $seen ) ) { throw new \InvalidArgumentException( 'Invalid payload.' ); }
                    $seen[$key] = true;
                    $this->space();
                    if ( ( $this->raw[$this->offset++] ?? '' ) !== ':' ) { throw new \InvalidArgumentException( 'Invalid payload.' ); }
                    $result->{$key} = $this->value( $depth + 1 );
                } else { $result[] = $this->value( $depth + 1 ); }
                $this->space();
                $next = $this->raw[$this->offset++] ?? '';
                if ( $next === $end ) { return $result; }
                if ( ',' !== $next ) { throw new \InvalidArgumentException( 'Invalid payload.' ); }
            }
        }
        foreach ( array( 'true' => true, 'false' => false, 'null' => null ) as $token => $value ) {
            if ( substr( $this->raw, $this->offset, strlen( $token ) ) === $token ) { $this->offset += strlen( $token ); return $value; }
        }
        if ( preg_match( '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', substr( $this->raw, $this->offset ), $m ) ) {
            $this->offset += strlen( $m[0] );
            return new JsonNumber( $m[0] );
        }
        throw new \InvalidArgumentException( 'Invalid payload.' );
    }
    private function string(): string {
        $start = $this->offset++;
        $length = strlen( $this->raw );
        while ( $this->offset < $length ) {
            $char = $this->raw[$this->offset++];
            if ( '\\' === $char ) { ++$this->offset; continue; }
            if ( '"' === $char ) {
                $value = json_decode( substr( $this->raw, $start, $this->offset - $start ), true, 2, JSON_THROW_ON_ERROR );
                if ( ! is_string( $value ) ) { break; }
                return $value;
            }
        }
        throw new \InvalidArgumentException( 'Invalid payload.' );
    }
}

final class Notification {
    private string $key_directory;
    public function __construct( string $key_directory ) { $this->key_directory = $key_directory; }
    public function verify( string $raw, string $environment ) {
        try { $data = ( new NotificationJson() )->decode( $raw ); }
        catch ( \Throwable $e ) { return new \WP_Error( 'csp_payload', 'Invalid notification.' ); }
        if ( ! Support::valid_environment( $environment ) || ! isset( $data->security ) || ! $data->security instanceof \stdClass ) {
            return new \WP_Error( 'csp_signature', 'Invalid notification.' );
        }
        $security = $data->security;
        if ( ! ( $security->version ?? null ) instanceof JsonNumber || '1' !== $security->version->text ||
            ! is_string( $security->key ?? null ) || strlen( $security->key ) > 200 ||
            ! is_string( $security->signature ?? null ) || strlen( $security->signature ) > 2048 ) {
            return new \WP_Error( 'csp_signature', 'Invalid notification.' );
        }
        $fields = array();
        foreach ( array( 'id', 'currency', 'amount', 'description', 'reference', 'date' ) as $name ) {
            if ( ! property_exists( $data, $name ) ) { return new \WP_Error( 'csp_payload', 'Invalid notification.' ); }
            $value = $data->{$name};
            if ( 'amount' === $name && $value instanceof JsonNumber ) { $value = $value->text; }
            if ( null !== $value && ! is_string( $value ) ) { return new \WP_Error( 'csp_payload', 'Invalid notification.' ); }
            $fields[$name] = $value ?? '';
            // Delimiter-containing values are ambiguous under the documented unescaped format.
            if ( str_contains( $fields[$name], '|' ) ) { return new \WP_Error( 'csp_payload', 'Invalid notification.' ); }
        }
        $signature = base64_decode( $security->signature, true );
        if ( false === $signature || '' === $signature || base64_encode( $signature ) !== $security->signature ) {
            return new \WP_Error( 'csp_signature', 'Invalid notification.' );
        }
        // security.key is metadata only. Never used as a path, URL or trust selector.
        $file = $this->key_directory . ( 'production' === $environment ? '/sinergy_public_prod.pem' : '/sinergy_public_sb.pem' );
        if ( ! function_exists( 'openssl_verify' ) || ! is_readable( $file ) ) { return new \WP_Error( 'csp_crypto', 'Notification unavailable.' ); }
        $key = openssl_pkey_get_public( file_get_contents( $file ) );
        if ( false === $key ) { return new \WP_Error( 'csp_crypto', 'Notification unavailable.' ); }
        // OpenSSL RSA SHA512 uses RSASSA-PKCS1-v1_5, not RSA-PSS.
        $verified = openssl_verify( implode( '|', $fields ), $signature, $key, OPENSSL_ALGO_SHA512 );
        if ( 1 !== $verified ) { return new \WP_Error( 'csp_signature', 'Invalid notification.' ); }
        return array( 'signed' => $fields, 'payload' => $data );
    }
}
