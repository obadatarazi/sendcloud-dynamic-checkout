<?php
defined( 'ABSPATH' ) || exit;

function scdc_register_webhook_route() {
    register_rest_route(
        'scdc/v1',
        '/webhook',
        [
            'methods'             => 'POST',
            'callback'            => 'scdc_handle_webhook',
            'permission_callback' => '__return_true',
        ]
    );
}
add_action( 'rest_api_init', 'scdc_register_webhook_route' );

function scdc_handle_webhook( WP_REST_Request $request ) {
    $settings = scdc_get_global_settings();
    $expected_secret = trim( (string) ( $settings['webhook_secret'] ?? '' ) );
    $provided_header = trim( (string) $request->get_header( 'x-scdc-webhook-secret' ) );
    $provided_query  = trim( (string) $request->get_param( 'secret' ) );

    if ( '' !== $expected_secret && ! hash_equals( $expected_secret, '' !== $provided_header ? $provided_header : $provided_query ) ) {
        return new WP_REST_Response(
            [ 'ok' => false, 'message' => 'Invalid webhook secret' ],
            401
        );
    }

    $payload = $request->get_json_params();
    if ( ! is_array( $payload ) ) {
        $payload = [ 'raw_body' => $request->get_body() ];
    }

    if ( function_exists( 'wc_get_logger' ) ) {
        $logger = wc_get_logger();
        $logger->info(
            'Webhook received: ' . wp_json_encode( $payload ),
            [ 'source' => 'sendcloud-dynamic-checkout-webhook' ]
        );
    }

    do_action( 'scdc_webhook_received', $payload, $request );

    return new WP_REST_Response(
        [ 'ok' => true, 'message' => 'Webhook received' ],
        200
    );
}

function scdc_send_test_webhook_request(): array {
    $webhook_url = rest_url( 'scdc/v1/webhook' );
    $settings    = scdc_get_global_settings();
    $secret      = trim( (string) ( $settings['webhook_secret'] ?? '' ) );

    $payload = [
        'event'     => 'scdc.test_webhook',
        'timestamp' => gmdate( 'c' ),
        'site'      => home_url(),
        'message'   => 'This is a test webhook from Sendcloud Dynamic Checkout plugin settings.',
    ];

    $headers = [ 'Content-Type' => 'application/json' ];
    if ( '' !== $secret ) {
        $headers['x-scdc-webhook-secret'] = $secret;
    }

    $response = wp_remote_post(
        $webhook_url,
        [
            'timeout' => 15,
            'headers' => $headers,
            'body'    => wp_json_encode( $payload ),
        ]
    );

    if ( is_wp_error( $response ) ) {
        return [
            'ok'      => false,
            'message' => $response->get_error_message(),
        ];
    }

    $status = (int) wp_remote_retrieve_response_code( $response );
    $body   = wp_remote_retrieve_body( $response );

    return [
        'ok'      => $status >= 200 && $status < 300,
        'message' => sprintf( 'HTTP %d. %s', $status, wp_strip_all_tags( (string) $body ) ),
    ];
}
