<?php
defined( 'ABSPATH' ) || exit;

function scdc_ajax_fetch_carriers() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied', 'scdc' ) ], 403 );
    }

    check_ajax_referer( 'scdc_admin_nonce', 'nonce' );

    $settings = scdc_get_global_settings();
    $result   = scdc_fetch_available_carriers_for_admin( $settings );

    if ( ! empty( $result['error'] ) ) {
        wp_send_json_error(
            [
                'message' => (string) $result['error'],
                'carriers' => [],
            ],
            400
        );
    }

    wp_send_json_success(
        [
            'carriers' => $result['carriers'] ?? [],
        ]
    );
}
add_action( 'wp_ajax_scdc_fetch_carriers', 'scdc_ajax_fetch_carriers' );
