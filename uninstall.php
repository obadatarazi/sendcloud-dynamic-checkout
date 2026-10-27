<?php
/**
 * Uninstall cleanup for Sendcloud Dynamic Checkout.
 *
 * @package scdc
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'scdc_global_settings' );

global $wpdb;
if ( isset( $wpdb ) && isset( $wpdb->options ) ) {
    $like = $wpdb->esc_like( '_transient_scdc_' ) . '%';
    $like_timeout = $wpdb->esc_like( '_transient_timeout_scdc_' ) . '%';

    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
}
