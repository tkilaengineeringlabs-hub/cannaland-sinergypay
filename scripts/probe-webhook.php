<?php
/** Safe internal WordPress REST dispatch. Run with: wp eval-file scripts/probe-webhook.php */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    fwrite( STDERR, "Use WP-CLI eval-file in a WordPress installation.\n" );
    exit( 1 );
}
if ( ! class_exists( 'WP_REST_Request' ) || ! function_exists( 'rest_do_request' ) ) {
    fwrite( STDERR, "WordPress REST must be loaded. Do not use --skip-wordpress.\n" );
    exit( 1 );
}
// Intentionally contains no order, reference, amount, signature or security object.
// It cannot authenticate or reach order lookup/completion. No test bypass is installed.
$request = new WP_REST_Request( 'POST', '/cannaland-payments/v1/payment-notification' );
$request->set_header( 'Content-Type', 'application/json' );
$request->set_body( '{"probe":"cannaland-payments-reachability"}' );
$response = rest_do_request( $request );
echo 'HTTP ' . $response->get_status() . "\n";
// A plugin-owned rejection has null data. Never print an unexpected response payload.
echo 'Response data: ' . ( null === $response->get_data() ? 'null' : '[non-null response omitted]' ) . "\n";
echo "Expected: HTTP 401, null data; webhook_received then invalid_signature/security_missing.\n";
echo "This internal dispatch does not test DNS/TLS/WAF, external delivery or a valid payment.\n";
