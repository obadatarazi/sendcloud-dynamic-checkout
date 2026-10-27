<?php
/**
 * Plugin Name:       Sendcloud Dynamic Checkout for WooCommerce
 * Plugin URI:        https://sendcloud.dev/api/v3/shipping-options/return-a-list-of-available-shipping-options
 * Description:       Shows real-time Sendcloud shipping rates on the WooCommerce checkout page using the Shipping Options API v3.
 * Version:           3.0.2
 * Author:            Obada Al-Tarazi
 * License:           GPL-2.0+
 * WC requires at least: 5.0
 * WC tested up to:   9.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SCDC_PLUGIN_FILE' ) ) {
    define( 'SCDC_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'SCDC_PLUGIN_DIR' ) ) {
    define( 'SCDC_PLUGIN_DIR', plugin_dir_path( SCDC_PLUGIN_FILE ) );
}
if ( ! defined( 'SCDC_PLUGIN_URL' ) ) {
    define( 'SCDC_PLUGIN_URL', plugin_dir_url( SCDC_PLUGIN_FILE ) );
}

function scdc_load_textdomain() {
    load_plugin_textdomain( 'scdc', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'scdc_load_textdomain' );

function scdc_default_settings(): array {
    $default_country_raw = (string) get_option( 'woocommerce_default_country', 'NL' );
    $default_country = strtoupper( trim( explode( ':', $default_country_raw )[0] ?? 'NL' ) );
    return [
        'title'                   => 'Shipping',
        'use_sendcloud_rates'     => 'yes',
        'api_key'                 => '',
        'api_secret'              => '',
        'from_country'            => 'NL',
        'from_postal_code'        => '',
        'default_length'          => '',
        'default_width'           => '',
        'default_height'          => '',
        'markup_type'             => 'none',
        'markup_value'            => '0',
        'free_shipping_enabled'   => 'no',
        'free_shipping_threshold' => '0',
        'free_shipping_label'     => 'FREE',
        'show_carrier_name'       => 'yes',
        'show_description'        => 'no',
        'enable_caching'          => 'yes',
        'hide_other_methods'      => 'no',
        'checkout_only_shipping'  => 'no',
        'simple_checkout_mode'    => 'yes',
        'show_type_pickup_point'  => 'yes',
        'show_type_home_delivery' => 'yes',
        'show_type_mailbox'       => 'yes',
        'enable_store_pickup'     => 'yes',
        'store_pickup_label'      => 'Pickup from Store',
        'store_pickup_address_line' => (string) get_option( 'woocommerce_store_address', '' ),
        'store_pickup_postcode'   => (string) get_option( 'woocommerce_store_postcode', '' ),
        'store_pickup_city'       => (string) get_option( 'woocommerce_store_city', '' ),
        'store_pickup_country'    => '' !== $default_country ? $default_country : 'NL',
        'store_pickup_opening_hours' => 'Mon-Fri 09:00-18:00',
        'store_profiles'            => [],
        'active_store_profile_id'   => '',
        'webhook_secret'          => '',
        'enabled_carrier_codes'   => [],
        'checkout_display_mode'   => 'carrier_grouped_dropdown',
    ];
}

function scdc_generate_store_profile_id(): string {
    return 'store_' . wp_generate_password( 8, false, false );
}

function scdc_get_woocommerce_base_store_profile(): array {
    $default_country_raw = (string) get_option( 'woocommerce_default_country', 'NL' );
    $default_country = strtoupper( trim( explode( ':', $default_country_raw )[0] ?? 'NL' ) );
    return [
        'id'                  => 'wc_base',
        'label'               => __( 'WooCommerce Base Store', 'scdc' ),
        'country'             => '' !== $default_country ? $default_country : 'NL',
        'postal_code'         => (string) get_option( 'woocommerce_store_postcode', '' ),
        'pickup_address_line' => (string) get_option( 'woocommerce_store_address', '' ),
        'pickup_postcode'     => (string) get_option( 'woocommerce_store_postcode', '' ),
        'pickup_city'         => (string) get_option( 'woocommerce_store_city', '' ),
        'pickup_country'      => '' !== $default_country ? $default_country : 'NL',
        'pickup_opening_hours'=> 'Mon-Fri 09:00-18:00',
    ];
}

function scdc_normalize_store_profile( array $profile, string $fallback_id = '' ): array {
    $id = sanitize_key( (string) ( $profile['id'] ?? $fallback_id ) );
    if ( '' === $id ) {
        $id = scdc_generate_store_profile_id();
    }
    $country = strtoupper( trim( sanitize_text_field( (string) ( $profile['country'] ?? '' ) ) ) );
    if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
        $country = scdc_get_woocommerce_base_store_profile()['country'];
    }
    $pickup_country = strtoupper( trim( sanitize_text_field( (string) ( $profile['pickup_country'] ?? $country ) ) ) );
    if ( ! preg_match( '/^[A-Z]{2}$/', $pickup_country ) ) {
        $pickup_country = $country;
    }

    return [
        'id'                  => $id,
        'label'               => sanitize_text_field( (string) ( $profile['label'] ?? '' ) ),
        'country'             => $country,
        'postal_code'         => sanitize_text_field( (string) ( $profile['postal_code'] ?? '' ) ),
        'pickup_address_line' => sanitize_text_field( (string) ( $profile['pickup_address_line'] ?? '' ) ),
        'pickup_postcode'     => sanitize_text_field( (string) ( $profile['pickup_postcode'] ?? '' ) ),
        'pickup_city'         => sanitize_text_field( (string) ( $profile['pickup_city'] ?? '' ) ),
        'pickup_country'      => $pickup_country,
        'pickup_opening_hours'=> sanitize_textarea_field( (string) ( $profile['pickup_opening_hours'] ?? '' ) ),
    ];
}

function scdc_normalize_store_profiles( $profiles ): array {
    if ( ! is_array( $profiles ) ) {
        return [];
    }

    $normalized = [];
    foreach ( $profiles as $profile_key => $profile_raw ) {
        if ( ! is_array( $profile_raw ) ) {
            continue;
        }
        if ( isset( $profile_raw['_remove'] ) && '1' === (string) $profile_raw['_remove'] ) {
            continue;
        }
        $profile = scdc_normalize_store_profile( $profile_raw, (string) $profile_key );
        $normalized[ $profile['id'] ] = $profile;
    }

    return $normalized;
}

function scdc_legacy_profile_from_settings( array $settings ): array {
    $base = scdc_get_woocommerce_base_store_profile();
    return scdc_normalize_store_profile(
        [
            'id'                  => 'legacy_primary',
            'label'               => __( 'Primary Store', 'scdc' ),
            'country'             => (string) ( $settings['from_country'] ?? $base['country'] ),
            'postal_code'         => (string) ( $settings['from_postal_code'] ?? $base['postal_code'] ),
            'pickup_address_line' => (string) ( $settings['store_pickup_address_line'] ?? $base['pickup_address_line'] ),
            'pickup_postcode'     => (string) ( $settings['store_pickup_postcode'] ?? $base['pickup_postcode'] ),
            'pickup_city'         => (string) ( $settings['store_pickup_city'] ?? $base['pickup_city'] ),
            'pickup_country'      => (string) ( $settings['store_pickup_country'] ?? $base['pickup_country'] ),
            'pickup_opening_hours'=> (string) ( $settings['store_pickup_opening_hours'] ?? $base['pickup_opening_hours'] ),
        ]
    );
}

function scdc_ensure_store_profiles_settings( array $settings ): array {
    $profiles = scdc_normalize_store_profiles( $settings['store_profiles'] ?? [] );
    if ( empty( $profiles ) ) {
        $legacy = scdc_legacy_profile_from_settings( $settings );
        $profiles[ $legacy['id'] ] = $legacy;
    }

    $active = sanitize_key( (string) ( $settings['active_store_profile_id'] ?? '' ) );
    if ( '' === $active || ! isset( $profiles[ $active ] ) ) {
        $active = (string) array_key_first( $profiles );
    }

    $settings['store_profiles'] = $profiles;
    $settings['active_store_profile_id'] = $active;
    return $settings;
}

function scdc_resolve_active_store_profile( array $settings ): array {
    $settings = scdc_ensure_store_profiles_settings( $settings );
    $profiles = $settings['store_profiles'];
    $active   = (string) $settings['active_store_profile_id'];
    $profile  = $profiles[ $active ] ?? reset( $profiles );
    if ( ! is_array( $profile ) ) {
        $profile = scdc_get_woocommerce_base_store_profile();
    }

    $base = scdc_get_woocommerce_base_store_profile();
    if ( '' === (string) ( $profile['country'] ?? '' ) ) {
        $profile['country'] = $base['country'];
    }
    if ( '' === (string) ( $profile['pickup_country'] ?? '' ) ) {
        $profile['pickup_country'] = $profile['country'];
    }
    if ( '' === (string) ( $profile['pickup_address_line'] ?? '' ) ) {
        $profile['pickup_address_line'] = $base['pickup_address_line'];
    }
    if ( '' === (string) ( $profile['pickup_postcode'] ?? '' ) ) {
        $profile['pickup_postcode'] = $base['pickup_postcode'];
    }
    if ( '' === (string) ( $profile['pickup_city'] ?? '' ) ) {
        $profile['pickup_city'] = $base['pickup_city'];
    }
    if ( '' === (string) ( $profile['pickup_opening_hours'] ?? '' ) ) {
        $profile['pickup_opening_hours'] = $base['pickup_opening_hours'];
    }

    return $profile;
}

function scdc_get_global_settings(): array {
    $saved = get_option( 'scdc_global_settings', [] );
    if ( ! is_array( $saved ) ) {
        $saved = [];
    }
    $settings = wp_parse_args( $saved, scdc_default_settings() );
    $settings = scdc_ensure_store_profiles_settings( $settings );
    return $settings;
}

function scdc_get_global_setting( string $key, $default = '' ) {
    $settings = scdc_get_global_settings();
    return $settings[ $key ] ?? $default;
}

function scdc_set_api_health( bool $ok, string $message = '' ): void {
    update_option(
        'scdc_api_health',
        [
            'ok'      => $ok ? 'yes' : 'no',
            'message' => sanitize_text_field( $message ),
            'time'    => time(),
        ]
    );
}

function scdc_get_api_health(): array {
    $data = get_option( 'scdc_api_health', [] );
    if ( ! is_array( $data ) ) {
        $data = [];
    }
    return [
        'ok'      => 'yes' === (string) ( $data['ok'] ?? '' ),
        'message' => sanitize_text_field( (string) ( $data['message'] ?? '' ) ),
        'time'    => absint( $data['time'] ?? 0 ),
    ];
}

function scdc_maybe_migrate_global_settings() {
    $existing = get_option( 'scdc_global_settings', null );
    if ( is_array( $existing ) && ! empty( $existing ) ) {
        return;
    }

    global $wpdb;
    $row = $wpdb->get_row(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce_sendcloud_dynamic_checkout_%_settings' LIMIT 1"
    );

    $settings = scdc_default_settings();
    if ( $row && ! empty( $row->option_name ) ) {
        $instance_settings = get_option( $row->option_name, [] );
        if ( is_array( $instance_settings ) ) {
            $settings = wp_parse_args( $instance_settings, $settings );
        }
    }

    $settings = scdc_ensure_store_profiles_settings( $settings );
    update_option( 'scdc_global_settings', $settings );
}

function scdc_register_admin_menu() {
    add_submenu_page(
        'woocommerce',
        __( 'Sendcloud Dynamic Checkout', 'scdc' ),
        __( 'Sendcloud Dynamic Checkout', 'scdc' ),
        'manage_woocommerce',
        'scdc-settings',
        'scdc_render_admin_page'
    );
}
add_action( 'admin_menu', 'scdc_register_admin_menu' );

function scdc_sanitize_settings( array $input ): array {
    $defaults = scdc_default_settings();
    $clean    = [];

    foreach ( $defaults as $key => $default ) {
        $value = $input[ $key ] ?? null;

        if ( in_array( $key, [ 'use_sendcloud_rates', 'free_shipping_enabled', 'show_carrier_name', 'show_description', 'enable_caching', 'hide_other_methods', 'checkout_only_shipping', 'simple_checkout_mode', 'show_type_pickup_point', 'show_type_home_delivery', 'show_type_mailbox', 'enable_store_pickup' ], true ) ) {
            $clean[ $key ] = ( isset( $input[ $key ] ) && 'yes' === $input[ $key ] ) ? 'yes' : 'no';
            continue;
        }

        if ( 'enabled_carrier_codes' === $key ) {
            $codes = $input['enabled_carrier_codes'] ?? [];
            if ( ! is_array( $codes ) ) {
                $codes = [];
            }
            $clean_codes = [];
            foreach ( $codes as $code ) {
                $safe = sanitize_key( (string) $code );
                if ( '' !== $safe ) {
                    $clean_codes[] = $safe;
                }
            }
            $clean[ $key ] = array_values( array_unique( $clean_codes ) );
            continue;
        }

        if ( 'store_profiles' === $key ) {
            $clean[ $key ] = scdc_normalize_store_profiles( $input['store_profiles'] ?? [] );
            continue;
        }

        if ( 'active_store_profile_id' === $key ) {
            $clean[ $key ] = sanitize_key( (string) ( $input['active_store_profile_id'] ?? '' ) );
            continue;
        }

        if ( in_array( $key, [ 'markup_value', 'free_shipping_threshold', 'default_length', 'default_width', 'default_height' ], true ) ) {
            $clean[ $key ] = is_numeric( $value ) ? (string) max( 0, (float) $value ) : (string) $default;
            continue;
        }

        if ( 'from_country' === $key ) {
            $country       = strtoupper( trim( sanitize_text_field( (string) $value ) ) );
            $clean[ $key ] = preg_match( '/^[A-Z]{2}$/', $country ) ? $country : 'NL';
            continue;
        }
        if ( 'store_pickup_country' === $key ) {
            $country       = strtoupper( trim( sanitize_text_field( (string) $value ) ) );
            $clean[ $key ] = preg_match( '/^[A-Z]{2}$/', $country ) ? $country : 'NL';
            continue;
        }

        if ( in_array( $key, [ 'markup_type' ], true ) ) {
            $allowed       = [ 'none', 'fixed', 'percentage' ];
            $clean[ $key ] = in_array( $value, $allowed, true ) ? $value : $default;
            continue;
        }

        if ( 'checkout_display_mode' === $key ) {
            $allowed       = [ 'carrier_grouped_dropdown', 'default_radios' ];
            $clean[ $key ] = in_array( (string) $value, $allowed, true ) ? (string) $value : $default;
            continue;
        }

        if ( 'webhook_secret' === $key ) {
            $secret = sanitize_text_field( (string) $value );
            $clean[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $secret, 0, 64 ) : substr( $secret, 0, 64 );
            continue;
        }

        $clean[ $key ] = sanitize_text_field( (string) $value );
    }

    return scdc_ensure_store_profiles_settings( $clean );
}

function scdc_render_admin_page() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }

    scdc_maybe_migrate_global_settings();
    $test_result = null;

    if ( isset( $_POST['scdc_save_settings'] ) && check_admin_referer( 'scdc_save_settings_nonce' ) ) {
        $raw_settings = $_POST['scdc_settings'] ?? [];
        $raw_settings = is_array( $raw_settings ) ? wp_unslash( $raw_settings ) : [];
        update_option( 'scdc_global_settings', scdc_sanitize_settings( $raw_settings ) );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Sendcloud Dynamic Checkout settings saved.', 'scdc' ) . '</p></div>';
    }

    if ( isset( $_POST['scdc_test_webhook'] ) && check_admin_referer( 'scdc_save_settings_nonce' ) ) {
        $test_result = scdc_send_test_webhook_request();
    }

    $settings = scdc_get_global_settings();
    $settings = scdc_ensure_store_profiles_settings( $settings );
    $store_profiles = is_array( $settings['store_profiles'] ?? null ) ? $settings['store_profiles'] : [];
    $active_store_profile_id = (string) ( $settings['active_store_profile_id'] ?? '' );
    $active_store_profile = scdc_resolve_active_store_profile( $settings );
    $webhook_url = rest_url( 'scdc/v1/webhook' );
    $carrier_result = scdc_fetch_available_carriers_for_admin( $settings );
    $carrier_options = $carrier_result['carriers'] ?? [];
    $carrier_error = $carrier_result['error'] ?? '';
    $selected_carriers = is_array( $settings['enabled_carrier_codes'] ?? null ) ? $settings['enabled_carrier_codes'] : [];
    $api_health = scdc_get_api_health();
    ?>
    <div class="wrap scdc-admin-page">
        <div class="scdc-page-hero">
            <div class="scdc-page-hero-content">
                <h1><?php esc_html_e( 'Sendcloud Dynamic Checkout', 'scdc' ); ?></h1>
                <p><?php esc_html_e( 'Premium shipping controls for real-time Sendcloud rates, webhook tools, and no-zone activation.', 'scdc' ); ?></p>
                <div class="scdc-hero-chips">
                    <span class="scdc-hero-chip <?php echo 'yes' === $settings['use_sendcloud_rates'] ? 'is-on' : 'is-off'; ?>">
                        <?php esc_html_e( 'API Mode', 'scdc' ); ?>: <?php echo 'yes' === $settings['use_sendcloud_rates'] ? esc_html__( 'On', 'scdc' ) : esc_html__( 'Off', 'scdc' ); ?>
                    </span>
                    <span class="scdc-hero-chip <?php echo 'yes' === $settings['enable_caching'] ? 'is-on' : 'is-off'; ?>">
                        <?php esc_html_e( 'Cache', 'scdc' ); ?>: <?php echo 'yes' === $settings['enable_caching'] ? esc_html__( 'On', 'scdc' ) : esc_html__( 'Off', 'scdc' ); ?>
                    </span>
                    <span class="scdc-hero-chip <?php echo 'yes' === $settings['free_shipping_enabled'] ? 'is-on' : 'is-off'; ?>">
                        <?php esc_html_e( 'Free Shipping', 'scdc' ); ?>: <?php echo 'yes' === $settings['free_shipping_enabled'] ? esc_html__( 'On', 'scdc' ) : esc_html__( 'Off', 'scdc' ); ?>
                    </span>
                    <span class="scdc-hero-chip <?php echo ! empty( $api_health['ok'] ) ? 'is-on' : 'is-off'; ?>">
                        <?php esc_html_e( 'API Health', 'scdc' ); ?>: <?php echo ! empty( $api_health['ok'] ) ? esc_html__( 'OK', 'scdc' ) : esc_html__( 'Issue', 'scdc' ); ?>
                    </span>
                </div>
                <?php if ( empty( $api_health['ok'] ) && '' !== (string) ( $api_health['message'] ?? '' ) ) : ?>
                    <p class="scdc-api-health-note"><?php echo esc_html( sprintf( __( 'Last API issue: %s', 'scdc' ), (string) $api_health['message'] ) ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <form method="post">
            <?php wp_nonce_field( 'scdc_save_settings_nonce' ); ?>
            <table class="form-table scdc-global-settings-table">
                <tr class="scdc-title-row"><th colspan="2"><?php esc_html_e( 'Core Checkout Controls', 'scdc' ); ?></th></tr>
                <tr><th scope="row"><?php esc_html_e( 'Method Title', 'scdc' ); ?></th><td><input type="text" name="scdc_settings[title]" value="<?php echo esc_attr( $settings['title'] ); ?>" class="regular-text" /><p class="description"><?php esc_html_e( 'Customer-facing title displayed at checkout.', 'scdc' ); ?></p></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Use Sendcloud Rates', 'scdc' ); ?></th><td><label><input id="scdc-global-use-sendcloud" type="checkbox" name="scdc_settings[use_sendcloud_rates]" value="yes" <?php checked( 'yes', $settings['use_sendcloud_rates'] ); ?> /> <?php esc_html_e( 'Enable Sendcloud API rates', 'scdc' ); ?></label></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Hide Other Shipping Methods', 'scdc' ); ?></th><td><label><input type="checkbox" name="scdc_settings[hide_other_methods]" value="yes" <?php checked( 'yes', $settings['hide_other_methods'] ); ?> /> <?php esc_html_e( 'Show only Sendcloud methods when available', 'scdc' ); ?></label></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Show Shipping In Checkout Only', 'scdc' ); ?></th><td><label><input type="checkbox" name="scdc_settings[checkout_only_shipping]" value="yes" <?php checked( 'yes', $settings['checkout_only_shipping'] ?? 'no' ); ?> /> <?php esc_html_e( 'If enabled, Sendcloud rates are shown on Checkout page only (hidden on Cart page).', 'scdc' ); ?></label></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Simple Checkout Mode', 'scdc' ); ?></th><td><label><input type="checkbox" name="scdc_settings[simple_checkout_mode]" value="yes" <?php checked( 'yes', $settings['simple_checkout_mode'] ?? 'yes' ); ?> /> <?php esc_html_e( 'Show only 2 options: cheapest Home Delivery + cheapest Service Point.', 'scdc' ); ?></label><p class="description"><?php esc_html_e( 'Recommended for stores like coffee/tea to keep checkout clean.', 'scdc' ); ?></p></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Carrier - Pickup Point', 'scdc' ); ?></th><td><label><input type="checkbox" name="scdc_settings[show_type_pickup_point]" value="yes" <?php checked( 'yes', $settings['show_type_pickup_point'] ?? 'yes' ); ?> /> <?php esc_html_e( 'Show Pickup Point methods', 'scdc' ); ?></label></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Carrier - Home Delivery', 'scdc' ); ?></th><td><label><input type="checkbox" name="scdc_settings[show_type_home_delivery]" value="yes" <?php checked( 'yes', $settings['show_type_home_delivery'] ?? 'yes' ); ?> /> <?php esc_html_e( 'Show Home Delivery methods', 'scdc' ); ?></label></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Carrier - Mailbox', 'scdc' ); ?></th><td><label><input type="checkbox" name="scdc_settings[show_type_mailbox]" value="yes" <?php checked( 'yes', $settings['show_type_mailbox'] ?? 'yes' ); ?> /> <?php esc_html_e( 'Show Mailbox methods', 'scdc' ); ?></label></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Enable Store Pickup', 'scdc' ); ?></th><td><label><input type="checkbox" name="scdc_settings[enable_store_pickup]" value="yes" <?php checked( 'yes', $settings['enable_store_pickup'] ?? 'yes' ); ?> /> <?php esc_html_e( 'Show pickup when customer country matches active store profile country', 'scdc' ); ?></label></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Enable Caching', 'scdc' ); ?></th><td><label><input type="checkbox" name="scdc_settings[enable_caching]" value="yes" <?php checked( 'yes', $settings['enable_caching'] ); ?> /> <?php esc_html_e( 'Cache API responses for faster checkout and fewer API calls', 'scdc' ); ?></label></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Checkout Display Mode', 'scdc' ); ?></th><td><select name="scdc_settings[checkout_display_mode]"><option value="carrier_grouped_dropdown" <?php selected( 'carrier_grouped_dropdown', $settings['checkout_display_mode'] ?? 'carrier_grouped_dropdown' ); ?>><?php esc_html_e( 'Carrier Grouped Dropdown', 'scdc' ); ?></option><option value="default_radios" <?php selected( 'default_radios', $settings['checkout_display_mode'] ?? '' ); ?>><?php esc_html_e( 'Default WooCommerce Radios', 'scdc' ); ?></option></select><p class="description"><?php esc_html_e( 'Grouped dropdown sorts methods by cheapest price and groups by carrier.', 'scdc' ); ?></p></td></tr>

                <tr class="scdc-title-row scdc-sendcloud-dependent"><th colspan="2"><?php esc_html_e( 'Sendcloud API and Origin', 'scdc' ); ?></th></tr>
                <tr class="scdc-sendcloud-dependent"><th scope="row"><?php esc_html_e( 'API Public Key', 'scdc' ); ?></th><td><input type="text" name="scdc_settings[api_key]" value="<?php echo esc_attr( $settings['api_key'] ); ?>" class="regular-text" /><p class="description"><?php esc_html_e( 'Found in Sendcloud account settings under API credentials.', 'scdc' ); ?></p></td></tr>
                <tr class="scdc-sendcloud-dependent"><th scope="row"><?php esc_html_e( 'API Secret Key', 'scdc' ); ?></th><td><input type="password" name="scdc_settings[api_secret]" value="<?php echo esc_attr( $settings['api_secret'] ); ?>" class="regular-text" /></td></tr>
                <tr class="scdc-sendcloud-dependent"><th scope="row"><?php esc_html_e( 'Sender Country', 'scdc' ); ?></th><td><input type="text" maxlength="2" value="<?php echo esc_attr( $active_store_profile['country'] ?? '' ); ?>" class="small-text" readonly /><p class="description"><?php esc_html_e( 'Read from active store profile (with WooCommerce base fallback).', 'scdc' ); ?></p></td></tr>
                <tr class="scdc-sendcloud-dependent"><th scope="row"><?php esc_html_e( 'Sender Postal Code', 'scdc' ); ?></th><td><input type="text" value="<?php echo esc_attr( $active_store_profile['postal_code'] ?? '' ); ?>" class="regular-text" readonly /></td></tr>
                <tr class="scdc-title-row scdc-sendcloud-dependent"><th colspan="2"><?php esc_html_e( 'Store Profiles', 'scdc' ); ?></th></tr>
                <tr class="scdc-sendcloud-dependent">
                    <th scope="row"><?php esc_html_e( 'Origin Profiles', 'scdc' ); ?></th>
                    <td>
                        <p class="description"><?php esc_html_e( 'Create one or more store profiles and select one active profile. Active profile drives sender country/postcode and pickup address defaults.', 'scdc' ); ?></p>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Active', 'scdc' ); ?></th>
                                    <th><?php esc_html_e( 'Label', 'scdc' ); ?></th>
                                    <th><?php esc_html_e( 'Country', 'scdc' ); ?></th>
                                    <th><?php esc_html_e( 'Postal Code', 'scdc' ); ?></th>
                                    <th><?php esc_html_e( 'Pickup Address', 'scdc' ); ?></th>
                                    <th><?php esc_html_e( 'Pickup City', 'scdc' ); ?></th>
                                    <th><?php esc_html_e( 'Pickup Country', 'scdc' ); ?></th>
                                    <th><?php esc_html_e( 'Opening Hours', 'scdc' ); ?></th>
                                    <th><?php esc_html_e( 'Remove', 'scdc' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $store_profiles as $profile_id => $profile ) : ?>
                                    <tr>
                                        <td><input type="radio" name="scdc_settings[active_store_profile_id]" value="<?php echo esc_attr( $profile_id ); ?>" <?php checked( $profile_id, $active_store_profile_id ); ?> /></td>
                                        <td><input type="text" name="scdc_settings[store_profiles][<?php echo esc_attr( $profile_id ); ?>][label]" value="<?php echo esc_attr( $profile['label'] ?? '' ); ?>" class="regular-text" /><input type="hidden" name="scdc_settings[store_profiles][<?php echo esc_attr( $profile_id ); ?>][id]" value="<?php echo esc_attr( $profile_id ); ?>" /></td>
                                        <td><input type="text" maxlength="2" name="scdc_settings[store_profiles][<?php echo esc_attr( $profile_id ); ?>][country]" value="<?php echo esc_attr( $profile['country'] ?? '' ); ?>" class="small-text" /></td>
                                        <td><input type="text" name="scdc_settings[store_profiles][<?php echo esc_attr( $profile_id ); ?>][postal_code]" value="<?php echo esc_attr( $profile['postal_code'] ?? '' ); ?>" class="regular-text" /></td>
                                        <td><input type="text" name="scdc_settings[store_profiles][<?php echo esc_attr( $profile_id ); ?>][pickup_address_line]" value="<?php echo esc_attr( $profile['pickup_address_line'] ?? '' ); ?>" class="regular-text" /><input type="text" name="scdc_settings[store_profiles][<?php echo esc_attr( $profile_id ); ?>][pickup_postcode]" value="<?php echo esc_attr( $profile['pickup_postcode'] ?? '' ); ?>" class="small-text" placeholder="<?php esc_attr_e( 'Pickup postcode', 'scdc' ); ?>" style="margin-top:6px;" /></td>
                                        <td><input type="text" name="scdc_settings[store_profiles][<?php echo esc_attr( $profile_id ); ?>][pickup_city]" value="<?php echo esc_attr( $profile['pickup_city'] ?? '' ); ?>" class="regular-text" /></td>
                                        <td><input type="text" maxlength="2" name="scdc_settings[store_profiles][<?php echo esc_attr( $profile_id ); ?>][pickup_country]" value="<?php echo esc_attr( $profile['pickup_country'] ?? '' ); ?>" class="small-text" /></td>
                                        <td><textarea name="scdc_settings[store_profiles][<?php echo esc_attr( $profile_id ); ?>][pickup_opening_hours]" rows="2" class="large-text"><?php echo esc_textarea( $profile['pickup_opening_hours'] ?? '' ); ?></textarea></td>
                                        <td><label><input type="checkbox" name="scdc_settings[store_profiles][<?php echo esc_attr( $profile_id ); ?>][_remove]" value="1" /> <?php esc_html_e( 'Delete', 'scdc' ); ?></label></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php $new_profile_id = scdc_generate_store_profile_id(); ?>
                                <tr>
                                    <td><input type="radio" name="scdc_settings[active_store_profile_id]" value="<?php echo esc_attr( $new_profile_id ); ?>" /></td>
                                    <td><input type="text" name="scdc_settings[store_profiles][<?php echo esc_attr( $new_profile_id ); ?>][label]" value="" class="regular-text" placeholder="<?php esc_attr_e( 'New store label', 'scdc' ); ?>" /><input type="hidden" name="scdc_settings[store_profiles][<?php echo esc_attr( $new_profile_id ); ?>][id]" value="<?php echo esc_attr( $new_profile_id ); ?>" /></td>
                                    <td><input type="text" maxlength="2" name="scdc_settings[store_profiles][<?php echo esc_attr( $new_profile_id ); ?>][country]" value="" class="small-text" /></td>
                                    <td><input type="text" name="scdc_settings[store_profiles][<?php echo esc_attr( $new_profile_id ); ?>][postal_code]" value="" class="regular-text" /></td>
                                    <td><input type="text" name="scdc_settings[store_profiles][<?php echo esc_attr( $new_profile_id ); ?>][pickup_address_line]" value="" class="regular-text" /><input type="text" name="scdc_settings[store_profiles][<?php echo esc_attr( $new_profile_id ); ?>][pickup_postcode]" value="" class="small-text" placeholder="<?php esc_attr_e( 'Pickup postcode', 'scdc' ); ?>" style="margin-top:6px;" /></td>
                                    <td><input type="text" name="scdc_settings[store_profiles][<?php echo esc_attr( $new_profile_id ); ?>][pickup_city]" value="" class="regular-text" /></td>
                                    <td><input type="text" maxlength="2" name="scdc_settings[store_profiles][<?php echo esc_attr( $new_profile_id ); ?>][pickup_country]" value="" class="small-text" /></td>
                                    <td><textarea name="scdc_settings[store_profiles][<?php echo esc_attr( $new_profile_id ); ?>][pickup_opening_hours]" rows="2" class="large-text"></textarea></td>
                                    <td><em><?php esc_html_e( 'New', 'scdc' ); ?></em></td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>
                <tr class="scdc-sendcloud-dependent">
                    <th scope="row"><?php esc_html_e( 'Allowed Carriers', 'scdc' ); ?></th>
                    <td>
                        <select id="scdc-carriers-select" name="scdc_settings[enabled_carrier_codes][]" multiple="multiple" size="8" class="regular-text" style="height:auto; min-height:180px;">
                            <?php foreach ( $carrier_options as $carrier_code => $carrier_name ) : ?>
                                <option value="<?php echo esc_attr( $carrier_code ); ?>" <?php selected( in_array( $carrier_code, $selected_carriers, true ) ); ?>>
                                    <?php echo esc_html( $carrier_name . ' (' . $carrier_code . ')' ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p style="margin-top:8px;">
                            <button type="button" class="button scdc-secondary-btn" id="scdc-refresh-carriers"><?php esc_html_e( 'Refresh Carriers (AJAX)', 'scdc' ); ?></button>
                            <span id="scdc-refresh-carriers-msg" style="margin-left:8px;"></span>
                        </p>
                        <?php if ( '' !== $carrier_error ) : ?>
                            <p class="description" style="color:#b91c1c;"><?php echo esc_html( $carrier_error ); ?></p>
                        <?php else : ?>
                            <p class="description"><?php esc_html_e( 'Select one or more carriers to display at checkout. Leave empty to allow all carriers.', 'scdc' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr class="scdc-title-row scdc-sendcloud-dependent"><th colspan="2"><?php esc_html_e( 'Pricing Rules', 'scdc' ); ?></th></tr>
                <tr class="scdc-sendcloud-dependent"><th scope="row"><?php esc_html_e( 'Markup Type', 'scdc' ); ?></th><td><select name="scdc_settings[markup_type]"><option value="none" <?php selected( 'none', $settings['markup_type'] ); ?>><?php esc_html_e( 'None', 'scdc' ); ?></option><option value="fixed" <?php selected( 'fixed', $settings['markup_type'] ); ?>><?php esc_html_e( 'Fixed', 'scdc' ); ?></option><option value="percentage" <?php selected( 'percentage', $settings['markup_type'] ); ?>><?php esc_html_e( 'Percentage', 'scdc' ); ?></option></select></td></tr>
                <tr class="scdc-sendcloud-dependent"><th scope="row"><?php esc_html_e( 'Markup Value', 'scdc' ); ?></th><td><input type="number" step="0.01" min="0" name="scdc_settings[markup_value]" value="<?php echo esc_attr( $settings['markup_value'] ); ?>" /></td></tr>
                <tr class="scdc-sendcloud-dependent"><th scope="row"><?php esc_html_e( 'Enable Free Shipping Threshold', 'scdc' ); ?></th><td><label><input id="scdc-global-free-enabled" type="checkbox" name="scdc_settings[free_shipping_enabled]" value="yes" <?php checked( 'yes', $settings['free_shipping_enabled'] ); ?> /> <?php esc_html_e( 'Enable', 'scdc' ); ?></label></td></tr>
                <tr class="scdc-sendcloud-dependent scdc-free-dependent"><th scope="row"><?php esc_html_e( 'Free Shipping Threshold', 'scdc' ); ?></th><td><input type="number" step="0.01" min="0" name="scdc_settings[free_shipping_threshold]" value="<?php echo esc_attr( $settings['free_shipping_threshold'] ); ?>" /></td></tr>
                <tr class="scdc-sendcloud-dependent scdc-free-dependent"><th scope="row"><?php esc_html_e( 'Free Shipping Label', 'scdc' ); ?></th><td><input type="text" name="scdc_settings[free_shipping_label]" value="<?php echo esc_attr( $settings['free_shipping_label'] ); ?>" class="regular-text" /></td></tr>

                <tr class="scdc-title-row"><th colspan="2"><?php esc_html_e( 'Store Pickup Details', 'scdc' ); ?></th></tr>
                <tr><th scope="row"><?php esc_html_e( 'Pickup Label', 'scdc' ); ?></th><td><input type="text" name="scdc_settings[store_pickup_label]" value="<?php echo esc_attr( $settings['store_pickup_label'] ?? 'Pickup from Store' ); ?>" class="regular-text" /></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Store Address', 'scdc' ); ?></th><td><input type="text" value="<?php echo esc_attr( $active_store_profile['pickup_address_line'] ?? '' ); ?>" class="regular-text" readonly /></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Store Postcode', 'scdc' ); ?></th><td><input type="text" value="<?php echo esc_attr( $active_store_profile['pickup_postcode'] ?? '' ); ?>" class="regular-text" readonly /></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Store City', 'scdc' ); ?></th><td><input type="text" value="<?php echo esc_attr( $active_store_profile['pickup_city'] ?? '' ); ?>" class="regular-text" readonly /></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Store Country Code', 'scdc' ); ?></th><td><input type="text" maxlength="2" value="<?php echo esc_attr( $active_store_profile['pickup_country'] ?? '' ); ?>" class="small-text" readonly /></td></tr>
                <tr><th scope="row"><?php esc_html_e( 'Opening Hours', 'scdc' ); ?></th><td><textarea rows="3" class="large-text" readonly><?php echo esc_textarea( $active_store_profile['pickup_opening_hours'] ?? '' ); ?></textarea><p class="description"><?php esc_html_e( 'Managed through the active store profile above.', 'scdc' ); ?></p></td></tr>

                <tr class="scdc-title-row scdc-sendcloud-dependent"><th colspan="2"><?php esc_html_e( 'Webhook Tools', 'scdc' ); ?></th></tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Webhook URL', 'scdc' ); ?></th>
                    <td>
                        <input id="scdc-webhook-url" type="text" readonly value="<?php echo esc_attr( $webhook_url ); ?>" class="regular-text" />
                        <button type="button" class="button scdc-secondary-btn" id="scdc-copy-webhook-url"><?php esc_html_e( 'Copy URL', 'scdc' ); ?></button>
                        <span id="scdc-copy-feedback" style="margin-left:8px;color:#15803d;font-weight:600;"></span>
                        <p class="description"><?php esc_html_e( 'Paste this URL in Sendcloud webhook settings.', 'scdc' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Webhook Secret', 'scdc' ); ?></th>
                    <td>
                        <input type="text" maxlength="64" name="scdc_settings[webhook_secret]" value="<?php echo esc_attr( $settings['webhook_secret'] ); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e( 'Optional: Send this same secret in webhook headers as x-scdc-webhook-secret.', 'scdc' ); ?></p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="submit" name="scdc_save_settings" class="button button-primary scdc-primary-btn"><?php esc_html_e( 'Save Settings', 'scdc' ); ?></button>
                <button type="submit" name="scdc_test_webhook" class="button scdc-secondary-btn"><?php esc_html_e( 'Send Test Webhook', 'scdc' ); ?></button>
            </p>
        </form>
        <?php if ( is_array( $test_result ) ) : ?>
            <div class="notice <?php echo ! empty( $test_result['ok'] ) ? 'notice-success' : 'notice-error'; ?>">
                <p>
                    <?php
                    if ( ! empty( $test_result['ok'] ) ) {
                        echo esc_html__( 'Test webhook sent successfully.', 'scdc' ) . ' ';
                    } else {
                        echo esc_html__( 'Test webhook failed.', 'scdc' ) . ' ';
                    }
                    echo esc_html( $test_result['message'] ?? '' );
                    ?>
                </p>
            </div>
        <?php endif; ?>
    </div>
    <script>
        (function() {
            var scdcAjax = {
                url: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
                nonce: <?php echo wp_json_encode( wp_create_nonce( 'scdc_admin_nonce' ) ); ?>
            };
            var copyButton = document.getElementById('scdc-copy-webhook-url');
            var urlInput = document.getElementById('scdc-webhook-url');
            var feedback = document.getElementById('scdc-copy-feedback');
            if (!copyButton || !urlInput || !feedback) {
                return;
            }
            function setRowVisibility(selector, visible) {
                document.querySelectorAll(selector).forEach(function(row) {
                    row.style.display = visible ? '' : 'none';
                    row.classList.toggle('scdc-hidden-row', !visible);
                });
            }

            function refreshGlobalSettingsUi() {
                var useSendcloud = document.getElementById('scdc-global-use-sendcloud');
                var freeEnabled = document.getElementById('scdc-global-free-enabled');
                var sendcloudOn = !!(useSendcloud && useSendcloud.checked);
                var freeOn = !!(freeEnabled && freeEnabled.checked);

                setRowVisibility('.scdc-sendcloud-dependent', sendcloudOn);
                setRowVisibility('.scdc-free-dependent', sendcloudOn && freeOn);
            }

            function copyUrl() {
                var value = urlInput.value || '';
                if (!value) {
                    return;
                }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(value).then(function() {
                        feedback.textContent = 'Copied';
                        setTimeout(function() { feedback.textContent = ''; }, 1800);
                    }).catch(function() {
                        feedback.textContent = 'Copy failed';
                    });
                    return;
                }
                urlInput.select();
                urlInput.setSelectionRange(0, 99999);
                try {
                    document.execCommand('copy');
                    feedback.textContent = 'Copied';
                } catch (e) {
                    feedback.textContent = 'Copy failed';
                }
                setTimeout(function() { feedback.textContent = ''; }, 1800);
            }

            copyButton.addEventListener('click', copyUrl);
            var useSendcloud = document.getElementById('scdc-global-use-sendcloud');
            var freeEnabled = document.getElementById('scdc-global-free-enabled');
            if (useSendcloud) {
                useSendcloud.addEventListener('change', refreshGlobalSettingsUi);
            }
            if (freeEnabled) {
                freeEnabled.addEventListener('change', refreshGlobalSettingsUi);
            }
            refreshGlobalSettingsUi();

            var refreshBtn = document.getElementById('scdc-refresh-carriers');
            var carriersSelect = document.getElementById('scdc-carriers-select');
            var refreshMsg = document.getElementById('scdc-refresh-carriers-msg');
            if (refreshBtn && carriersSelect && refreshMsg) {
                refreshBtn.addEventListener('click', function() {
                    refreshMsg.textContent = 'Loading...';
                    refreshMsg.style.color = '#1d4ed8';

                    var form = new FormData();
                    form.append('action', 'scdc_fetch_carriers');
                    form.append('nonce', scdcAjax.nonce);

                    fetch(scdcAjax.url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: form
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(payload) {
                        if (!payload || !payload.success) {
                            var message = (payload && payload.data && payload.data.message) ? payload.data.message : 'Failed to refresh carriers';
                            refreshMsg.textContent = message;
                            refreshMsg.style.color = '#b91c1c';
                            return;
                        }

                        var selected = Array.from(carriersSelect.selectedOptions).map(function(o) { return o.value; });
                        carriersSelect.innerHTML = '';
                        var carriers = payload.data && payload.data.carriers ? payload.data.carriers : {};
                        Object.keys(carriers).forEach(function(code) {
                            var opt = document.createElement('option');
                            opt.value = code;
                            opt.textContent = carriers[code] + ' (' + code + ')';
                            if (selected.indexOf(code) !== -1) {
                                opt.selected = true;
                            }
                            carriersSelect.appendChild(opt);
                        });
                        refreshMsg.textContent = 'Carriers refreshed';
                        refreshMsg.style.color = '#15803d';
                    })
                    .catch(function() {
                        refreshMsg.textContent = 'Network error while refreshing carriers';
                        refreshMsg.style.color = '#b91c1c';
                    });
                });
            }
        })();
    </script>
    <?php
}

function scdc_get_effective_settings( int $instance_id = 0 ): array {
    $settings = scdc_get_global_settings();

    if ( $instance_id > 0 ) {
        $instance_settings = get_option( 'woocommerce_sendcloud_dynamic_checkout_' . $instance_id . '_settings', [] );
        if ( is_array( $instance_settings ) ) {
            $settings = wp_parse_args( $instance_settings, $settings );
        }
    }

    return scdc_ensure_store_profiles_settings( wp_parse_args( $settings, scdc_default_settings() ) );
}

function scdc_get_store_pickup_address_text( array $settings ): string {
    $profile = scdc_resolve_active_store_profile( $settings );
    $address = trim( (string) ( $profile['pickup_address_line'] ?? '' ) );
    $postcode = trim( (string) ( $profile['pickup_postcode'] ?? '' ) );
    $city = trim( (string) ( $profile['pickup_city'] ?? '' ) );
    $country = strtoupper( trim( (string) ( $profile['pickup_country'] ?? '' ) ) );

    if ( '' === $address ) {
        $address = (string) get_option( 'woocommerce_store_address', '' );
    }
    if ( '' === $postcode ) {
        $postcode = (string) get_option( 'woocommerce_store_postcode', '' );
    }
    if ( '' === $city ) {
        $city = (string) get_option( 'woocommerce_store_city', '' );
    }
    if ( '' === $country ) {
        $default_country_raw = (string) get_option( 'woocommerce_default_country', 'NL' );
        $country = strtoupper( trim( explode( ':', $default_country_raw )[0] ?? 'NL' ) );
    }

    $parts = array_filter( [ $address, trim( $postcode . ' ' . $city ), $country ] );
    return implode( ', ', $parts );
}

function scdc_detect_delivery_group( array $option ): string {
    $candidates = [
        (string) ( $option['functionalities']['last_mile'] ?? '' ),
        (string) ( $option['delivery_method']['type'] ?? '' ),
        (string) ( $option['delivery_type'] ?? '' ),
        (string) ( $option['last_mile'] ?? '' ),
        (string) ( $option['name'] ?? '' ),
        (string) ( $option['code'] ?? '' ),
    ];

    foreach ( $candidates as $candidate ) {
        $value = strtolower( trim( $candidate ) );
        if ( '' === $value ) {
            continue;
        }
        if ( preg_match( '/(service[_ -]?point|pickup|pick[_ -]?up|parcelshop|parcel[_ -]?shop|locker|drop[_ -]?off|dropoff)/', $value ) ) {
            return 'pickup';
        }
        if ( preg_match( '/(mailbox|letter|brief)/', $value ) ) {
            return 'mailbox';
        }
        if ( preg_match( '/(home[_ -]?delivery|home delivery|to[_ -]?door|door)/', $value ) ) {
            return 'home';
        }
    }

    return 'home';
}

function scdc_normalize_country_code( string $country ): string {
    $country = strtoupper( trim( $country ) );
    if ( '' === $country ) {
        return '';
    }
    // Handle values like "NL:ZH" or other decorated formats safely.
    $country = explode( ':', $country )[0] ?? $country;
    return substr( preg_replace( '/[^A-Z]/', '', $country ), 0, 2 );
}

function scdc_get_destination_country_code( string $package_country = '' ): string {
    $candidate = scdc_normalize_country_code( $package_country );
    if ( '' === $candidate && function_exists( 'WC' ) && WC() && isset( WC()->customer ) ) {
        $shipping_country = (string) WC()->customer->get_shipping_country();
        $billing_country  = (string) WC()->customer->get_billing_country();
        $candidate = scdc_normalize_country_code( '' !== $shipping_country ? $shipping_country : $billing_country );
    }
    return $candidate;
}

function scdc_destination_matches_store_pickup_country( array $settings, string $package_country = '' ): bool {
    $destination_country = scdc_get_destination_country_code( $package_country );
    if ( '' === $destination_country ) {
        return false;
    }

    $profile = scdc_resolve_active_store_profile( $settings );
    $store_country = scdc_normalize_country_code( (string) ( $profile['pickup_country'] ?? $profile['country'] ?? '' ) );

    return '' !== $store_country && $destination_country === $store_country;
}

function scdc_add_business_days( DateTimeImmutable $date, int $days ): DateTimeImmutable {
    if ( $days <= 0 ) {
        return $date;
    }

    $cursor = $date;
    $remaining = $days;
    while ( $remaining > 0 ) {
        $cursor = $cursor->modify( '+1 day' );
        $weekday = (int) $cursor->format( 'N' );
        if ( $weekday < 6 ) {
            $remaining--;
        }
    }

    return $cursor;
}

function scdc_format_lead_time_eta( $lead_time ): string {
    if ( ! is_numeric( $lead_time ) ) {
        return '';
    }

    $hours = (int) ceil( (float) $lead_time );
    if ( $hours <= 0 ) {
        return '';
    }

    $min_days = max( 1, (int) floor( $hours / 24 ) );
    $max_days = max( $min_days, (int) ceil( $hours / 24 ) );

    $now = new DateTimeImmutable( 'now', wp_timezone() );
    $start = scdc_add_business_days( $now, $min_days );
    $end   = scdc_add_business_days( $now, $max_days );

    if ( $start->format( 'M Y' ) === $end->format( 'M Y' ) ) {
        return sprintf(
            __( 'Arrives %1$s-%2$s %3$s', 'scdc' ),
            $start->format( 'j' ),
            $end->format( 'j' ),
            $end->format( 'M' )
        );
    }

    return sprintf(
        __( 'Arrives %1$s - %2$s', 'scdc' ),
        $start->format( 'j M' ),
        $end->format( 'j M' )
    );
}

function scdc_fetch_available_carriers_for_admin( array $settings ): array {
    $api_key      = trim( (string) ( $settings['api_key'] ?? '' ) );
    $api_secret   = trim( (string) ( $settings['api_secret'] ?? '' ) );
    $profile      = scdc_resolve_active_store_profile( $settings );
    $from_country = strtoupper( trim( (string) ( $profile['country'] ?? 'NL' ) ) );
    $from_postal  = trim( (string) ( $profile['postal_code'] ?? '' ) );

    if ( '' === $api_key || '' === $api_secret ) {
        return [
            'carriers' => [],
            'error'    => __( 'Set API credentials first to load carriers dynamically.', 'scdc' ),
        ];
    }

    $body = [
        'from_country_code' => $from_country,
        'to_country_code'   => $from_country,
        'parcels'           => [
            [
                'weight' => [
                    'value' => '1.000',
                    'unit'  => 'kg',
                ],
            ],
        ],
        'calculate_quotes' => false,
    ];
    if ( '' !== $from_postal ) {
        $body['from_postal_code'] = $from_postal;
        $body['to_postal_code']   = $from_postal;
    }

    $response = wp_remote_post(
        'https://panel.sendcloud.sc/api/v3/shipping-options',
        [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
        ]
    );

    if ( is_wp_error( $response ) ) {
        return [
            'carriers' => [],
            'error'    => sprintf( __( 'Could not load carriers: %s', 'scdc' ), $response->get_error_message() ),
        ];
    }

    $status = (int) wp_remote_retrieve_response_code( $response );
    $data   = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( 200 !== $status || ! is_array( $data['data'] ?? null ) ) {
        return [
            'carriers' => [],
            'error'    => __( 'Carrier list not available from Sendcloud right now. Try again after saving valid credentials.', 'scdc' ),
        ];
    }

    $carriers = [];
    foreach ( $data['data'] as $option ) {
        $code = sanitize_key( (string) ( $option['carrier']['code'] ?? '' ) );
        $name = trim( (string) ( $option['carrier']['name'] ?? '' ) );
        if ( '' !== $code && '' !== $name ) {
            $carriers[ $code ] = $name;
        }
    }
    asort( $carriers );

    return [
        'carriers' => $carriers,
        'error'    => '',
    ];
}

require_once SCDC_PLUGIN_DIR . 'includes/scdc-webhooks.php';
require_once SCDC_PLUGIN_DIR . 'includes/scdc-ajax.php';

// ─────────────────────────────────────────────────────────────────────────────
// 1. Boot: register the shipping method once WooCommerce is loaded
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'woocommerce_shipping_init', 'scdc_init_shipping_method' );

function scdc_init_shipping_method() {
    if ( ! class_exists( 'WC_Shipping_Method' ) ) {
        return;
    }

    /**
     * Sendcloud_Dynamic_Checkout_Method
     *
     * Calls POST /api/v3/shipping-options and registers one WooCommerce
     * shipping rate per returned option.
     */
    class Sendcloud_Dynamic_Checkout_Method extends WC_Shipping_Method {

        // Transient TTL for API response cache (seconds)
        const CACHE_TTL = 120;

        public function __construct( $instance_id = 0 ) {
            $this->id                 = 'sendcloud_dynamic_checkout';
            $this->instance_id        = absint( $instance_id );
            $this->method_title       = __( 'Sendcloud Dynamic Checkout', 'scdc' );
            $this->method_description = __( 'Displays live shipping rates from the Sendcloud Shipping Options API v3.', 'scdc' );
            $this->supports           = [ 'shipping-zones', 'instance-settings' ];
            $this->title              = $this->get_option( 'title', 'Shipping' );

            $this->init();
        }

        // ── Settings ──────────────────────────────────────────────────────────

        public function init() {
            $this->init_form_fields();
            $this->init_settings();
            add_action(
                'woocommerce_update_options_shipping_' . $this->id,
                [ $this, 'process_admin_options' ]
            );
        }

        public function validate_from_country_field( $key, $value ) {
            $value = strtoupper( trim( sanitize_text_field( (string) $value ) ) );
            return preg_match( '/^[A-Z]{2}$/', $value ) ? $value : 'NL';
        }

        public function validate_from_postal_code_field( $key, $value ) {
            return strtoupper( trim( sanitize_text_field( (string) $value ) ) );
        }

        public function validate_markup_value_field( $key, $value ) {
            $value = is_numeric( $value ) ? (float) $value : 0.0;
            return (string) max( 0, $value );
        }

        public function validate_free_shipping_threshold_field( $key, $value ) {
            $value = is_numeric( $value ) ? (float) $value : 0.0;
            return (string) max( 0, $value );
        }

        public function validate_free_shipping_label_field( $key, $value ) {
            $value = sanitize_text_field( (string) $value );
            return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 24 ) : substr( $value, 0, 24 );
        }

        public function init_form_fields() {
            $this->instance_form_fields = [
                'section_mode' => [
                    'title'       => __( 'Checkout Mode', 'scdc' ),
                    'type'        => 'title',
                    'description' => __( 'Choose whether checkout uses Sendcloud live rates or your native WooCommerce shipping setup.', 'scdc' ),
                ],

                'title' => [
                    'title'   => __( 'Method Title', 'scdc' ),
                    'type'    => 'text',
                    'default' => __( 'Shipping', 'scdc' ),
                    'desc_tip' => true,
                    'description' => __( 'Label shown to the customer above the shipping options.', 'scdc' ),
                ],

                'use_sendcloud_rates' => [
                    'title'       => __( 'Use Sendcloud Rates', 'scdc' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Use Sendcloud API for real-time shipping rates', 'scdc' ),
                    'description' => __( 'When unchecked, WooCommerce shipping zones/methods are used instead.', 'scdc' ),
                    'default'     => 'yes',
                ],

                'hide_other_methods' => [
                    'title'       => __( 'Hide Other Shipping Methods', 'scdc' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Hide other WooCommerce shipping methods when Sendcloud rates are available', 'scdc' ),
                    'description' => __( 'Useful when you want checkout to only show Sendcloud rates.', 'scdc' ),
                    'default'     => 'no',
                ],

                'section_mode_end' => [
                    'type' => 'sectionend',
                ],

                // ── Sendcloud API credentials ──────────────────────────────
                'section_credentials' => [
                    'title'       => __( 'Sendcloud Credentials', 'scdc' ),
                    'type'        => 'title',
                    'description' => __( 'Use API keys from Sendcloud Settings > API.', 'scdc' ),
                ],

                'api_key' => [
                    'title'       => __( 'Sendcloud API Public Key', 'scdc' ),
                    'type'        => 'text',
                    'description' => __( 'Found under Sendcloud → Settings → API → Public key.', 'scdc' ),
                    'desc_tip'    => true,
                    'default'     => '',
                ],

                'api_secret' => [
                    'title'       => __( 'Sendcloud API Secret Key', 'scdc' ),
                    'type'        => 'password',
                    'description' => __( 'Found under Sendcloud → Settings → API → Secret key.', 'scdc' ),
                    'desc_tip'    => true,
                    'default'     => '',
                ],

                'section_credentials_end' => [
                    'type' => 'sectionend',
                ],

                // ── Sender details ─────────────────────────────────────────
                'section_sender' => [
                    'title'       => __( 'Sender Address Inputs', 'scdc' ),
                    'type'        => 'title',
                    'description' => __( 'These values improve quote precision for zonal and remote-area pricing.', 'scdc' ),
                ],

                'from_country' => [
                    'title'       => __( 'Sender Country Code', 'scdc' ),
                    'type'        => 'text',
                    'description' => __( 'ISO 3166-1 alpha-2 country code of your warehouse / sender address (e.g. NL).', 'scdc' ),
                    'desc_tip'    => true,
                    'default'     => 'NL',
                    'custom_attributes' => [
                        'maxlength'   => '2',
                        'placeholder' => 'NL',
                        'style'       => 'text-transform:uppercase;',
                    ],
                ],

                'from_postal_code' => [
                    'title'       => __( 'Sender Postal Code', 'scdc' ),
                    'type'        => 'text',
                    'description' => __( 'Recommended for accurate zonal and remote-area quotes.', 'scdc' ),
                    'desc_tip'    => true,
                    'default'     => '',
                    'custom_attributes' => [
                        'maxlength'   => '15',
                        'placeholder' => '1012AB',
                    ],
                ],

                'section_sender_end' => [
                    'type' => 'sectionend',
                ],

                // ── Optional: default parcel dimensions (cm) ───────────────
                'section_parcel' => [
                    'title'       => __( 'Fallback Parcel Dimensions', 'scdc' ),
                    'type'        => 'title',
                    'description' => __( 'Used only when product dimensions are not available.', 'scdc' ),
                ],

                'default_length' => [
                    'title'       => __( 'Default Parcel Length (cm)', 'scdc' ),
                    'type'        => 'number',
                    'description' => __( 'Used when products do not have individual dimensions set.', 'scdc' ),
                    'desc_tip'    => true,
                    'default'     => '',
                ],

                'default_width' => [
                    'title'       => __( 'Default Parcel Width (cm)', 'scdc' ),
                    'type'        => 'number',
                    'default'     => '',
                ],

                'default_height' => [
                    'title'       => __( 'Default Parcel Height (cm)', 'scdc' ),
                    'type'        => 'number',
                    'default'     => '',
                ],

                'section_parcel_end' => [
                    'type' => 'sectionend',
                ],

                // ── Pricing / display ──────────────────────────────────────
                'section_pricing' => [
                    'title'       => __( 'Pricing and Display', 'scdc' ),
                    'type'        => 'title',
                    'description' => __( 'Adjust rate presentation, surcharge rules, and free-shipping behavior.', 'scdc' ),
                ],

                'markup_type' => [
                    'title'       => __( 'Price Adjustment Type', 'scdc' ),
                    'type'        => 'select',
                    'description' => __( 'Optionally add a surcharge to the Sendcloud rate shown to the customer.', 'scdc' ),
                    'desc_tip'    => true,
                    'default'     => 'none',
                    'options'     => [
                        'none'       => __( 'None – show exact Sendcloud rate', 'scdc' ),
                        'fixed'      => __( 'Fixed amount (€ / currency)', 'scdc' ),
                        'percentage' => __( 'Percentage (%)', 'scdc' ),
                    ],
                ],

                'markup_value' => [
                    'title'       => __( 'Price Adjustment Value', 'scdc' ),
                    'type'        => 'number',
                    'description' => __( 'Enter the fixed amount or percentage to add. Leave blank or 0 for none.', 'scdc' ),
                    'desc_tip'    => true,
                    'default'     => '0',
                    'custom_attributes' => [ 'min' => '0', 'step' => '0.01' ],
                ],

                'free_shipping_enabled' => [
                    'title'   => __( 'Enable Free Shipping Threshold', 'scdc' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Set Sendcloud rates to free when subtotal reaches threshold', 'scdc' ),
                    'description' => __( 'Tip: set a minimum amount greater than 0 to avoid always-free shipping.', 'scdc' ),
                    'default' => 'no',
                ],

                'free_shipping_threshold' => [
                    'title'             => __( 'Free Shipping Minimum Amount', 'scdc' ),
                    'type'              => 'number',
                    'description'       => __( 'Cart subtotal needed for free shipping (in store currency).', 'scdc' ),
                    'desc_tip'          => true,
                    'default'           => '0',
                    'custom_attributes' => [ 'min' => '0', 'step' => '0.01' ],
                ],

                'free_shipping_label' => [
                    'title'       => __( 'Free Shipping Label', 'scdc' ),
                    'type'        => 'text',
                    'description' => __( 'Text appended to rate label when free shipping is applied.', 'scdc' ),
                    'desc_tip'    => true,
                    'default'     => __( 'FREE', 'scdc' ),
                    'custom_attributes' => [
                        'maxlength'   => '24',
                        'placeholder' => __( 'FREE', 'scdc' ),
                    ],
                ],

                'show_carrier_name' => [
                    'title'   => __( 'Show Carrier Name', 'scdc' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Append carrier name to the rate label (e.g. "Standard Delivery – DHL")', 'scdc' ),
                    'default' => 'yes',
                ],

                'show_description' => [
                    'title'   => __( 'Show Description', 'scdc' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Show the delivery option description below the rate label', 'scdc' ),
                    'default' => 'no',
                ],

                'enable_caching' => [
                    'title'   => __( 'Enable Response Caching', 'scdc' ),
                    'type'    => 'checkbox',
                    'label'   => sprintf(
                        /* translators: %d: cache TTL in seconds */
                        __( 'Cache API responses for %d seconds to reduce API calls (recommended)', 'scdc' ),
                        self::CACHE_TTL
                    ),
                    'default' => 'yes',
                ],

                'section_pricing_end' => [
                    'type' => 'sectionend',
                ],
            ];
        }

        // ── Core: calculate_shipping ──────────────────────────────────────────

        /**
         * Called by WooCommerce when it needs to know available rates.
         *
         * @param array $package  WooCommerce shipping package (contents, destination, …).
         */
        public function calculate_shipping( $package = [] ) {
            $settings = scdc_get_effective_settings( $this->instance_id );

            // Respect merchant setting: if disabled, let WooCommerce methods/zones handle rates.
            if ( 'yes' !== ( $settings['use_sendcloud_rates'] ?? 'yes' ) ) {
                return;
            }

            // Optional mode: only show Sendcloud shipping on checkout, not cart.
            if ( 'yes' === ( $settings['checkout_only_shipping'] ?? 'no' ) && ! scdc_is_checkout_request_context() ) {
                return;
            }

            // 1. Read saved settings
            $api_key          = trim( (string) ( $settings['api_key'] ?? '' ) );
            $api_secret       = trim( (string) ( $settings['api_secret'] ?? '' ) );
            $active_store_profile = scdc_resolve_active_store_profile( $settings );
            $from_country     = strtoupper( trim( (string) ( $active_store_profile['country'] ?? 'NL' ) ) );
            $from_postal_code = trim( (string) ( $active_store_profile['postal_code'] ?? '' ) );

            $has_api_credentials = ( '' !== $api_key && '' !== $api_secret );

            // 2. Build cart context from the WooCommerce package
            $to_country     = scdc_normalize_country_code( (string) ( $package['destination']['country'] ?? '' ) );
            $to_postal_code = $package['destination']['postcode'] ?? '';
            $destination_country = scdc_get_destination_country_code( $to_country );
            $destination_matches_store_pickup_country = scdc_destination_matches_store_pickup_country( $settings, $to_country );

            // During checkout updates, package country can be temporarily empty.
            // If customer country resolves through WC customer, keep availability reliable.
            if ( '' === $to_country && '' !== $destination_country ) {
                $to_country = $destination_country;
            }

            $weight_kg          = $this->get_cart_weight_kg( $package );
            $total_order_value  = $this->get_cart_subtotal( $package );
            $cart_subtotal      = $this->get_cart_subtotal_float( $package );

            // 3. Optional parcel dimensions
            $parcel_length = floatval( $settings['default_length'] ?? '' ) ?: null;
            $parcel_width  = floatval( $settings['default_width'] ?? '' ) ?: null;
            $parcel_height = floatval( $settings['default_height'] ?? '' ) ?: null;

            // 4. Build a cache key from all inputs that affect the API response
            $cache_key = 'scdc_' . md5( implode( '|', [
                $weight_kg,
                $total_order_value,
                $from_country,
                $from_postal_code,
                $to_country,
                $to_postal_code,
                (string) $parcel_length,
                (string) $parcel_width,
                (string) $parcel_height,
            ] ) );

            // 5. Try cache / API only when destination country is known.
            // If unknown, we can still show NL store pickup using customer fallback.
            $delivery_options = [];
            if ( '' !== $to_country && $has_api_credentials ) {
                $delivery_options = null;
                $caching_enabled  = 'yes' === ( $settings['enable_caching'] ?? 'yes' );

                if ( $caching_enabled ) {
                    $cached = get_transient( $cache_key );
                    if ( false !== $cached ) {
                        $delivery_options = $cached;
                    }
                }

                // 6. Call the Sendcloud API if no cached result
                if ( null === $delivery_options ) {
                    $delivery_options = $this->fetch_delivery_options(
                        $api_key,
                        $api_secret,
                        $weight_kg,
                        $total_order_value,
                        $from_country,
                        $from_postal_code,
                        $to_country,
                        $to_postal_code,
                        $parcel_length,
                        $parcel_width,
                        $parcel_height
                    );

                    if ( is_wp_error( $delivery_options ) ) {
                        $delivery_options = [];
                    }

                    if ( $caching_enabled && ! empty( $delivery_options ) ) {
                        set_transient( $cache_key, $delivery_options, self::CACHE_TTL );
                    }
                }
            }

            // 7. Register one WooCommerce shipping rate per delivery option
            $markup_type  = (string) ( $settings['markup_type'] ?? 'none' );
            $markup_value = floatval( $settings['markup_value'] ?? 0 );
            $show_carrier = 'yes' === ( $settings['show_carrier_name'] ?? 'yes' );
            $show_desc    = 'yes' === ( $settings['show_description'] ?? 'no' );
            $free_threshold = floatval( $settings['free_shipping_threshold'] ?? 0 );
            $is_free      = 'yes' === ( $settings['free_shipping_enabled'] ?? 'no' )
                && $free_threshold > 0
                && $cart_subtotal >= $free_threshold;
            $free_label   = trim( (string) ( $settings['free_shipping_label'] ?? __( 'FREE', 'scdc' ) ) );
            $enabled_carriers = is_array( $settings['enabled_carrier_codes'] ?? null ) ? $settings['enabled_carrier_codes'] : [];

            if ( ! empty( $enabled_carriers ) ) {
                $delivery_options = array_values(
                    array_filter(
                        $delivery_options,
                        static function ( $option ) use ( $enabled_carriers ) {
                            $carrier_code = sanitize_key( (string) ( $option['carrier']['code'] ?? '' ) );
                            return in_array( $carrier_code, $enabled_carriers, true );
                        }
                    )
                );
            }

            $show_pickup = 'yes' === ( $settings['show_type_pickup_point'] ?? 'yes' );
            $show_home   = 'yes' === ( $settings['show_type_home_delivery'] ?? 'yes' );
            $show_mailbox = 'yes' === ( $settings['show_type_mailbox'] ?? 'yes' );

            $delivery_options = array_values(
                array_filter(
                    $delivery_options,
                    static function ( $option ) use ( $show_pickup, $show_home, $show_mailbox ) {
                        $group = scdc_detect_delivery_group( is_array( $option ) ? $option : [] );

                        if ( 'pickup' === $group ) {
                            return $show_pickup;
                        }
                        if ( 'mailbox' === $group ) {
                            return $show_mailbox;
                        }
                        // Default remaining options to home delivery group.
                        return $show_home;
                    }
                )
            );

            usort(
                $delivery_options,
                static function ( $a, $b ) {
                    $price_a = floatval( $a['quotes'][0]['price']['total']['value'] ?? PHP_FLOAT_MAX );
                    $price_b = floatval( $b['quotes'][0]['price']['total']['value'] ?? PHP_FLOAT_MAX );
                    if ( $price_a === $price_b ) {
                        $carrier_a = strtolower( (string) ( $a['carrier']['name'] ?? '' ) );
                        $carrier_b = strtolower( (string) ( $b['carrier']['name'] ?? '' ) );
                        if ( $carrier_a === $carrier_b ) {
                            return strcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
                        }
                        return strcasecmp( $carrier_a, $carrier_b );
                    }
                    return $price_a <=> $price_b;
                }
            );

            // Simple mode for cleaner storefronts: keep only cheapest home + cheapest service-point option.
            if ( 'yes' === ( $settings['simple_checkout_mode'] ?? 'yes' ) ) {
                // If merchant selected carriers explicitly, keep the cheapest option per selected carrier.
                // This preserves a simple checkout while ensuring chosen carriers (e.g. DHL, UPS) are visible.
                if ( ! empty( $enabled_carriers ) ) {
                    $cheapest_per_carrier = [];
                    foreach ( $delivery_options as $option ) {
                        $carrier_code = sanitize_key( (string) ( $option['carrier']['code'] ?? '' ) );
                        if ( '' === $carrier_code ) {
                            continue;
                        }
                        if ( ! isset( $cheapest_per_carrier[ $carrier_code ] ) ) {
                            $cheapest_per_carrier[ $carrier_code ] = $option;
                        }
                    }
                    if ( ! empty( $cheapest_per_carrier ) ) {
                        $delivery_options = array_values( $cheapest_per_carrier );
                    }
                } else {
                $cheapest_home = null;
                $cheapest_service_point = null;

                foreach ( $delivery_options as $option ) {
                    $group = scdc_detect_delivery_group( is_array( $option ) ? $option : [] );
                    $is_service_point = 'pickup' === $group;
                    $is_home = in_array( $group, [ 'home', 'mailbox' ], true );

                    if ( $is_service_point && null === $cheapest_service_point ) {
                        $cheapest_service_point = $option;
                    }

                    if ( $is_home && null === $cheapest_home ) {
                        $cheapest_home = $option;
                    }

                    if ( null !== $cheapest_home && null !== $cheapest_service_point ) {
                        break;
                    }
                }

                $curated = [];
                if ( null !== $cheapest_home ) {
                    $curated[] = $cheapest_home;
                }
                if ( null !== $cheapest_service_point ) {
                    $home_code = (string) ( $cheapest_home['code'] ?? '' );
                    $sp_code   = (string) ( $cheapest_service_point['code'] ?? '' );
                    if ( $sp_code !== $home_code ) {
                        $curated[] = $cheapest_service_point;
                    }
                }

                // If no home/service-point split found, keep first two cheapest options.
                if ( empty( $curated ) ) {
                    $curated = array_slice( $delivery_options, 0, 2 );
                }
                $delivery_options = $curated;
                }
            }

            // Country-matched free in-store pickup option from active store profile.
            $store_pickup_enabled = 'yes' === ( $settings['enable_store_pickup'] ?? 'yes' );
            if ( $store_pickup_enabled && $destination_matches_store_pickup_country ) {
                $pickup_label = trim( (string) ( $settings['store_pickup_label'] ?? __( 'Pickup from Store', 'scdc' ) ) );
                if ( '' === $pickup_label ) {
                    $pickup_label = __( 'Pickup from Store', 'scdc' );
                }
                $pickup_address = scdc_get_store_pickup_address_text( $settings );
                $pickup_hours   = trim( (string) ( $active_store_profile['pickup_opening_hours'] ?? '' ) );
                $this->add_rate(
                    [
                        'id'        => $this->get_rate_id( 'scdc_store_pickup' ),
                        'label'     => $pickup_label,
                        'cost'      => 0.0,
                        'taxes'     => '',
                        'meta_data' => [
                            '_scdc_pickup_from_store'     => 'yes',
                            '_scdc_pickup_store_address'  => $pickup_address,
                            '_scdc_pickup_opening_hours'  => $pickup_hours,
                            '_scdc_pickup_label'          => $pickup_label,
                        ],
                    ]
                );
            }

            foreach ( $delivery_options as $option ) {
                if ( empty( $option['quotes'] ) || ! is_array( $option['quotes'] ) ) {
                    continue;
                }
                $quote = $option['quotes'][0] ?? [];
                $eta_label = scdc_format_lead_time_eta( $quote['lead_time'] ?? null );

                $base_cost = floatval( $quote['price']['total']['value'] ?? 0 );
                $cost      = $this->apply_markup( $base_cost, $markup_type, $markup_value );

                // Build a human-readable label
                $label = $option['name'] ?? __( 'Shipping', 'scdc' );
                if ( 'yes' === ( $settings['simple_checkout_mode'] ?? 'yes' ) ) {
                    $carrier_name = trim( (string) ( $option['carrier']['name'] ?? __( 'Carrier', 'scdc' ) ) );
                    $group        = scdc_detect_delivery_group( is_array( $option ) ? $option : [] );
                    $delivery_type = __( 'Delivery', 'scdc' );
                    if ( 'pickup' === $group ) {
                        $delivery_type = __( 'Service Point', 'scdc' );
                    } elseif ( 'mailbox' === $group ) {
                        $delivery_type = __( 'Mailbox', 'scdc' );
                    } elseif ( 'home' === $group ) {
                        $delivery_type = __( 'Home Delivery', 'scdc' );
                    }
                    $label = $carrier_name . ' - ' . $delivery_type;
                    if ( '' !== $eta_label ) {
                        $label .= ' (' . $eta_label . ')';
                    }
                } else {
                    if ( $show_carrier && ! empty( $option['carrier']['name'] ) ) {
                        $label .= ' – ' . $option['carrier']['name'];
                    }
                    // Keep show_description setting for future descriptive texts without duplicating ETA.
                    if ( $show_desc && ! empty( $option['description'] ) ) {
                        $label .= ' (' . sanitize_text_field( (string) $option['description'] ) . ')';
                    }
                    if ( '' !== $eta_label ) {
                        $label .= ' (' . $eta_label . ')';
                    }
                }
                if ( $is_free ) {
                    $cost = 0.0;
                    if ( '' !== $free_label ) {
                        $label .= ' - ' . $free_label;
                    }
                }

                $this->add_rate( [
                    'id'        => $this->get_rate_id( 'scdc_' . sanitize_key( $option['code'] ?? wp_unique_id() ) ),
                    'label'     => $label,
                    'cost'      => $cost,
                    'taxes'     => '', // let WooCommerce calculate taxes normally
                    'meta_data' => [
                        // Store the shipping_option_code so you can use it when
                        // creating a Sendcloud shipment after the order is placed.
                        '_sendcloud_shipping_option_code' => $option['code'] ?? '',
                        '_sendcloud_carrier_code'         => $option['carrier']['code'] ?? '',
                        '_sendcloud_carrier_name'         => $option['carrier']['name'] ?? '',
                        '_sendcloud_delivery_method_type' => $option['product']['code'] ?? '',
                    ],
                ] );
            }
        }

        // ── Helpers ───────────────────────────────────────────────────────────

        /**
         * POST /api/v3/shipping-options
         *
         * @return array|WP_Error  Array of shipping option objects on success.
         */
        private function fetch_delivery_options(
            string $api_key,
            string $api_secret,
            float  $weight_kg,
            string $total_order_value,
            string $from_country,
            string $from_postal_code,
            string $to_country,
            string $to_postal_code,
            ?float $parcel_length,
            ?float $parcel_width,
            ?float $parcel_height
        ) {
            $url = 'https://panel.sendcloud.sc/api/v3/shipping-options';
            $body = [
                'from_country_code' => $from_country,
                'to_country_code'   => $to_country,
                'to_postal_code'    => $to_postal_code ?: null,
                'parcels'           => [
                    [
                        'weight' => [
                            'value' => number_format( max( 0.001, $weight_kg ), 3, '.', '' ),
                            'unit'  => 'kg',
                        ],
                    ],
                ],
                'calculate_quotes' => true,
            ];

            if ( ! empty( $from_postal_code ) ) {
                $body['from_postal_code'] = $from_postal_code;
            }
            if ( $parcel_length && $parcel_width && $parcel_height ) {
                $body['parcels'][0]['dimensions'] = [
                    'length' => (string) $parcel_length,
                    'width'  => (string) $parcel_width,
                    'height' => (string) $parcel_height,
                    'unit'   => 'cm',
                ];
            }
            if ( '' === $body['to_postal_code'] ) {
                unset( $body['to_postal_code'] );
            }

            // Basic Auth: base64( public_key:secret_key )
            $auth_header = 'Basic ' . base64_encode( $api_key . ':' . $api_secret );

            $response = wp_remote_post( $url, [
                'timeout' => 10,
                'headers' => [
                    'Authorization' => $auth_header,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode( $body ),
            ] );

            if ( is_wp_error( $response ) ) {
                $this->log( 'API request failed: ' . $response->get_error_message() );
                scdc_set_api_health( false, $response->get_error_message() );
                return $response;
            }

            $status_code = wp_remote_retrieve_response_code( $response );
            $body        = wp_remote_retrieve_body( $response );
            $data        = json_decode( $body, true );

            if ( 200 !== (int) $status_code ) {
                $this->log( sprintf(
                    'API returned HTTP %d. Body: %s',
                    $status_code,
                    $body
                ) );
                scdc_set_api_health( false, "HTTP $status_code" );
                return new WP_Error( 'sendcloud_api_error', "HTTP $status_code from Sendcloud API" );
            }

            scdc_set_api_health( true, '' );
            return is_array( $data['data'] ?? null ) ? $data['data'] : [];
        }

        /**
         * Sum total cart weight in kilograms.
         */
        private function get_cart_weight_kg( array $package ): float {
            $total_weight = 0.0;
            foreach ( $package['contents'] as $item ) {
                $product = $item['data'];
                if ( $product && $product->has_weight() ) {
                    $total_weight += floatval( $product->get_weight() ) * $item['quantity'];
                }
            }

            // Convert from WooCommerce store weight unit to kilograms.
            $wc_unit = get_option( 'woocommerce_weight_unit', 'kg' );
            $kg      = wc_get_weight( $total_weight, 'kg', $wc_unit );

            return max( 0.001, (float) $kg );
        }

        /**
         * Get cart subtotal (products only, excl. shipping) as a formatted string.
         */
        private function get_cart_subtotal( array $package ): string {
            return number_format( $this->get_cart_subtotal_float( $package ), 2, '.', '' );
        }

        /**
         * Get cart subtotal (products only, excl. shipping) as a float.
         */
        private function get_cart_subtotal_float( array $package ): float {
            $subtotal = 0.0;
            foreach ( $package['contents'] as $item ) {
                $subtotal += floatval( $item['line_subtotal'] );
            }
            return (float) $subtotal;
        }

        /**
         * Apply optional price markup.
         */
        private function apply_markup( float $base, string $type, float $value ): float {
            if ( $value <= 0 || 'none' === $type ) {
                return $base;
            }
            if ( 'fixed' === $type ) {
                return $base + $value;
            }
            if ( 'percentage' === $type ) {
                return $base + ( $base * $value / 100 );
            }
            return $base;
        }

        /**
         * Write a message to WooCommerce's shipping log (WC_LOG_DIR/sendcloud-dynamic-checkout-*.log).
         */
        private function log( string $message ) {
            $logger = wc_get_logger();
            $logger->debug( $message, [ 'source' => 'sendcloud-dynamic-checkout' ] );
        }
    }
}

// Register the method with WooCommerce
add_filter( 'woocommerce_shipping_methods', 'scdc_register_shipping_method' );

function scdc_register_shipping_method( array $methods ): array {
    $methods['sendcloud_dynamic_checkout'] = 'Sendcloud_Dynamic_Checkout_Method';
    return $methods;
}

add_filter( 'woocommerce_package_rates', 'scdc_maybe_hide_other_shipping_methods', 100, 2 );

function scdc_maybe_hide_other_shipping_methods( array $rates, array $package ): array {
    $global_settings = scdc_get_effective_settings( 0 );
    if (
        'yes' === ( $global_settings['checkout_only_shipping'] ?? 'no' )
        && ! scdc_is_checkout_request_context()
    ) {
        // Keep non-Sendcloud methods on cart page, hide Sendcloud there.
        foreach ( $rates as $rate_id => $rate ) {
            if ( is_callable( [ $rate, 'get_method_id' ] ) && 'sendcloud_dynamic_checkout' === $rate->get_method_id() ) {
                unset( $rates[ $rate_id ] );
            }
        }
        return $rates;
    }

    $sendcloud_rates = [];
    foreach ( $rates as $rate_id => $rate ) {
        if ( is_callable( [ $rate, 'get_method_id' ] ) && 'sendcloud_dynamic_checkout' === $rate->get_method_id() ) {
            $sendcloud_rates[ $rate_id ] = $rate;
        }
    }

    // Global fallback: if no zone-based Sendcloud method returned rates, inject global rates.
    if ( empty( $sendcloud_rates ) && class_exists( 'Sendcloud_Dynamic_Checkout_Method' ) ) {
        $global_settings = scdc_get_effective_settings( 0 );
        if ( 'yes' === ( $global_settings['use_sendcloud_rates'] ?? 'yes' ) ) {
            $fallback_method = new Sendcloud_Dynamic_Checkout_Method( 0 );
            $fallback_method->calculate_shipping( $package );

            if ( ! empty( $fallback_method->rates ) && is_array( $fallback_method->rates ) ) {
                foreach ( $fallback_method->rates as $generated_rate ) {
                    if ( is_object( $generated_rate ) && isset( $generated_rate->id ) ) {
                        $rates[ $generated_rate->id ]          = $generated_rate;
                        $sendcloud_rates[ $generated_rate->id ] = $generated_rate;
                    }
                }
            }
        }
    }

    if ( empty( $sendcloud_rates ) ) {
        return $rates;
    }

    $first_rate   = reset( $sendcloud_rates );
    $instance_id  = is_callable( [ $first_rate, 'get_instance_id' ] ) ? absint( $first_rate->get_instance_id() ) : 0;
    $settings     = scdc_get_effective_settings( $instance_id );
    $hide_others  = 'yes' === ( $settings['hide_other_methods'] ?? 'no' );
    $use_sendcloud = 'yes' === ( $settings['use_sendcloud_rates'] ?? 'yes' );

    if ( $hide_others && $use_sendcloud ) {
        return $sendcloud_rates;
    }

    return $rates;
}

add_filter( 'woocommerce_cart_shipping_method_full_label', 'scdc_append_carrier_marker_to_rate_label', 20, 2 );
function scdc_append_carrier_marker_to_rate_label( string $label, $method ): string {
    if ( ! is_object( $method ) || ! method_exists( $method, 'get_method_id' ) ) {
        return $label;
    }
    if ( 'sendcloud_dynamic_checkout' !== $method->get_method_id() ) {
        return $label;
    }
    if ( false !== strpos( $label, 'scdc-carrier-name' ) ) {
        return $label;
    }

    if ( method_exists( $method, 'get_meta' ) && 'yes' === (string) $method->get_meta( '_scdc_pickup_from_store' ) ) {
        $pickup_address = (string) $method->get_meta( '_scdc_pickup_store_address' );
        $pickup_hours   = (string) $method->get_meta( '_scdc_pickup_opening_hours' );
        $label .= ' <span class="scdc-pickup-details" style="display:none;" data-scdc-pickup="yes" data-scdc-address="' . esc_attr( $pickup_address ) . '" data-scdc-hours="' . esc_attr( $pickup_hours ) . '"></span>';
        return $label;
    }

    $carrier_name = '';
    if ( method_exists( $method, 'get_meta' ) ) {
        $carrier_name = trim( (string) $method->get_meta( '_sendcloud_carrier_name' ) );
    }
    if ( '' === $carrier_name ) {
        return $label;
    }

    $label .= ' <span class="scdc-carrier-name" style="display:none;" data-scdc-carrier="' . esc_attr( $carrier_name ) . '"></span>';
    return $label;
}

add_action( 'wp_footer', 'scdc_render_checkout_dropdown_script', 99 );
function scdc_render_checkout_dropdown_script() {
    return;
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
        return;
    }
    if ( 'carrier_grouped_dropdown' !== scdc_get_global_setting( 'checkout_display_mode', 'carrier_grouped_dropdown' ) ) {
        return;
    }
    ?>
    <style id="scdc-checkout-dropdown-css">
        .scdc-dropdown-row {
            margin: 10px 0 12px;
        }
        .scdc-dropdown-row label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            line-height: 1.35;
        }
        .scdc-shipping-select {
            width: 100%;
            max-width: 100%;
            min-height: 44px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #fff;
            padding: 10px 12px;
            font-size: 14px;
            line-height: 1.3;
            box-sizing: border-box;
        }
        .scdc-shipping-select:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 1px #2563eb;
        }
        @media (max-width: 768px) {
            .scdc-dropdown-row label {
                font-size: 13px;
            }
            .scdc-shipping-select {
                min-height: 46px;
                font-size: 16px; /* avoids iOS zoom */
                padding: 10px 12px;
            }
        }
    </style>
    <script id="scdc-checkout-dropdown-js">
        (function($) {
            function normalizeLabel(text) {
                return (text || '').replace(/\s+/g, ' ').trim();
            }

            function carrierFromRateItem($li) {
                var marker = $li.find('.scdc-carrier-name').first().data('scdc-carrier');
                if (marker) {
                    return String(marker);
                }
                var labelText = normalizeLabel($li.find('label').first().text());
                var parts = labelText.split('–');
                if (parts.length > 1) {
                    var right = parts[parts.length - 1].replace(/\(.*?\)/g, '').trim();
                    if (right) {
                        return right;
                    }
                }
                return 'Other';
            }

            function buildGroupedDropdown($methodList) {
                var $sendcloudLis = $methodList.find('> li').filter(function() {
                    var value = $(this).find('input[name^="shipping_method"]').val() || '';
                    return value.indexOf('sendcloud_dynamic_checkout') !== -1;
                });

                $methodList.find('> li.scdc-dropdown-row').remove();
                $methodList.find('> li').show().removeClass('scdc-hidden-by-dropdown');

                if ($sendcloudLis.length <= 1) {
                    return;
                }

                var selectedValue = $sendcloudLis.find('input[name^="shipping_method"]:checked').val() || $sendcloudLis.first().find('input[name^="shipping_method"]').val();
                var grouped = {};
                var orderedGroups = [];

                $sendcloudLis.each(function() {
                    var $li = $(this);
                    var $input = $li.find('input[name^="shipping_method"]').first();
                    var value = $input.val();
                    var labelText = normalizeLabel($li.find('label').first().text());
                    var carrier = carrierFromRateItem($li);
                    if (!grouped[carrier]) {
                        grouped[carrier] = [];
                        orderedGroups.push(carrier);
                    }
                    grouped[carrier].push({ value: value, label: labelText });
                });

                var $select = $('<select class="scdc-shipping-select"></select>');
                orderedGroups.forEach(function(carrier) {
                    var $group = $('<optgroup></optgroup>').attr('label', carrier);
                    grouped[carrier].forEach(function(item) {
                        var $opt = $('<option></option>').attr('value', item.value).text(item.label);
                        if (item.value === selectedValue) {
                            $opt.prop('selected', true);
                        }
                        $group.append($opt);
                    });
                    $select.append($group);
                });

                var $row = $('<li class="scdc-dropdown-row"><label>Shipping method</label></li>');
                $row.append($select);
                $sendcloudLis.first().before($row);
                $sendcloudLis.hide().addClass('scdc-hidden-by-dropdown');

                $select.on('change', function() {
                    var chosen = $(this).val();
                    $sendcloudLis.each(function() {
                        var $li = $(this);
                        var $input = $li.find('input[name^="shipping_method"]').first();
                        $input.prop('checked', $input.val() === chosen);
                    });
                    $sendcloudLis.find('input[name^="shipping_method"]:checked').trigger('change');
                });
            }

            function initGroupedDropdown() {
                $('ul#shipping_method, ul.shipping_method, ul.woocommerce-shipping-methods').each(function() {
                    buildGroupedDropdown($(this));
                });
            }

            $(document.body).on('updated_checkout updated_shipping_method', initGroupedDropdown);
            $(document).ready(initGroupedDropdown);
        })(jQuery);
    </script>
    <?php
}

function scdc_activate_plugin() {
    scdc_maybe_migrate_global_settings();
    $settings = scdc_get_global_settings();
    $settings = scdc_ensure_store_profiles_settings( wp_parse_args( $settings, scdc_default_settings() ) );
    update_option( 'scdc_global_settings', $settings );
}
register_activation_hook( __FILE__, 'scdc_activate_plugin' );

function scdc_admin_missing_credentials_notice() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    if ( ! isset( $_GET['page'] ) || 'wc-settings' !== sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
        return;
    }

    $settings = scdc_get_global_settings();
    if ( 'yes' !== ( $settings['use_sendcloud_rates'] ?? 'yes' ) ) {
        return;
    }

    if ( ! empty( $settings['api_key'] ) && ! empty( $settings['api_secret'] ) ) {
        return;
    }

    $url = admin_url( 'admin.php?page=scdc-settings' );
    echo '<div class="notice notice-warning"><p>';
    echo esc_html__( 'Sendcloud Dynamic Checkout is enabled but API credentials are missing. ', 'scdc' );
    echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Configure Sendcloud Dynamic Checkout', 'scdc' ) . '</a>';
    echo '</p></div>';
}
add_action( 'admin_notices', 'scdc_admin_missing_credentials_notice' );

add_action( 'admin_head', 'scdc_render_admin_settings_css' );
add_action( 'admin_footer', 'scdc_render_admin_settings_js' );

function scdc_is_wc_shipping_settings_screen(): bool {
    if ( ! is_admin() ) {
        return false;
    }

    $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
    $tab  = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';

    if ( 'scdc-settings' === $page ) {
        return true;
    }

    return 'wc-settings' === $page && 'shipping' === $tab;
}

function scdc_is_global_settings_screen(): bool {
    if ( ! is_admin() ) {
        return false;
    }
    $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
    return 'scdc-settings' === $page;
}

function scdc_is_checkout_request_context(): bool {
    if ( is_admin() && ! wp_doing_ajax() ) {
        return false;
    }

    // Hard-stop for cart screen: checkout-only mode must never show Sendcloud rates here.
    if ( function_exists( 'is_cart' ) && is_cart() ) {
        return false;
    }

    if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
        return true;
    }

    $wc_ajax = isset( $_REQUEST['wc-ajax'] ) ? sanitize_text_field( (string) wp_unslash( $_REQUEST['wc-ajax'] ) ) : '';
    if ( in_array( $wc_ajax, [ 'update_order_review', 'checkout' ], true ) ) {
        return true;
    }

    // For non-WC AJAX flows, allow only explicit checkout routes.
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
    if ( '' !== $uri && false !== stripos( $uri, 'checkout' ) && false === stripos( $uri, 'cart' ) ) {
        return true;
    }

    return false;
}

function scdc_enqueue_admin_assets() {
    if ( ! scdc_is_wc_shipping_settings_screen() && ! scdc_is_global_settings_screen() ) {
        return;
    }

    wp_enqueue_script( 'selectWoo' );
    wp_enqueue_style( 'select2' );

    wp_enqueue_style(
        'scdc-admin',
        SCDC_PLUGIN_URL . 'assets/admin.css',
        [],
        file_exists( SCDC_PLUGIN_DIR . 'assets/admin.css' ) ? (string) filemtime( SCDC_PLUGIN_DIR . 'assets/admin.css' ) : '1.0.0'
    );

    wp_enqueue_script(
        'scdc-admin',
        SCDC_PLUGIN_URL . 'assets/admin.js',
        [ 'jquery' ],
        file_exists( SCDC_PLUGIN_DIR . 'assets/admin.js' ) ? (string) filemtime( SCDC_PLUGIN_DIR . 'assets/admin.js' ) : '1.0.0',
        true
    );

    wp_localize_script(
        'scdc-admin',
        'scdcAdmin',
        [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'scdc_admin_nonce' ),
            'isGlobalPage'   => scdc_is_global_settings_screen(),
            'isShippingPage' => scdc_is_wc_shipping_settings_screen(),
        ]
    );
}
add_action( 'admin_enqueue_scripts', 'scdc_enqueue_admin_assets' );

function scdc_enqueue_checkout_assets() {
    if ( ! function_exists( 'is_checkout' ) || ( ! is_checkout() && ! is_cart() ) ) {
        return;
    }
    if ( 'carrier_grouped_dropdown' !== scdc_get_global_setting( 'checkout_display_mode', 'carrier_grouped_dropdown' ) ) {
        return;
    }

    wp_enqueue_style(
        'scdc-checkout',
        SCDC_PLUGIN_URL . 'assets/checkout.css',
        [],
        file_exists( SCDC_PLUGIN_DIR . 'assets/checkout.css' ) ? (string) filemtime( SCDC_PLUGIN_DIR . 'assets/checkout.css' ) : '1.0.0'
    );

    wp_enqueue_script(
        'scdc-checkout',
        SCDC_PLUGIN_URL . 'assets/checkout.js',
        [ 'jquery' ],
        file_exists( SCDC_PLUGIN_DIR . 'assets/checkout.js' ) ? (string) filemtime( SCDC_PLUGIN_DIR . 'assets/checkout.js' ) : '1.0.0',
        true
    );

    $settings = scdc_get_global_settings();
    $active_store_profile = scdc_resolve_active_store_profile( $settings );
    $api_health = scdc_get_api_health();
    $api_warning = '';
    if ( empty( $api_health['ok'] ) && ! empty( $api_health['time'] ) && ( time() - (int) $api_health['time'] ) < HOUR_IN_SECONDS ) {
        $api_warning = __( 'Live carrier rates are temporarily unavailable. Fallback methods may be shown.', 'scdc' );
    }
    wp_localize_script(
        'scdc-checkout',
        'scdcCheckout',
        [
            'pickupAddress' => scdc_get_store_pickup_address_text( $settings ),
            'pickupHours'   => trim( (string) ( $active_store_profile['pickup_opening_hours'] ?? '' ) ),
            'pickupLabel'   => trim( (string) ( $settings['store_pickup_label'] ?? __( 'Pickup from Store', 'scdc' ) ) ),
            'apiWarning'    => $api_warning,
        ]
    );
}
add_action( 'wp_enqueue_scripts', 'scdc_enqueue_checkout_assets' );

function scdc_render_admin_settings_css() {
    return;
    if ( ! scdc_is_wc_shipping_settings_screen() ) {
        return;
    }
    ?>
    <style id="scdc-modern-settings-css">
        .scdc-modern-settings {
            border-collapse: separate;
            border-spacing: 0 10px;
        }
        .scdc-admin-page .notice {
            border-radius: 10px;
        }
        .scdc-page-hero {
            background: linear-gradient(135deg, #0ea5e9 0%, #2563eb 45%, #1d4ed8 100%);
            color: #eff6ff;
            border-radius: 14px;
            margin: 8px 0 16px;
            padding: 22px;
            box-shadow: 0 14px 32px rgba(37, 99, 235, 0.22);
        }
        .scdc-page-hero h1 {
            color: #ffffff;
            margin: 0 0 8px;
            font-size: 28px;
            line-height: 1.2;
        }
        .scdc-page-hero p {
            margin: 0;
            color: #dbeafe;
            font-size: 14px;
        }
        .scdc-hero-chips {
            margin-top: 14px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .scdc-hero-chip {
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.3px;
            border: 1px solid rgba(255, 255, 255, 0.28);
            background: rgba(255, 255, 255, 0.12);
            color: #eff6ff;
        }
        .scdc-hero-chip.is-off {
            background: rgba(15, 23, 42, 0.35);
        }
        .scdc-global-settings-table tr th,
        .scdc-global-settings-table tr td,
        .scdc-modern-settings tr th,
        .scdc-modern-settings tr td {
            background: #fff;
            border-top: 1px solid #dbeafe;
            border-bottom: 1px solid #dbeafe;
            padding: 14px 16px !important;
        }
        .scdc-global-settings-table tr th,
        .scdc-modern-settings tr th {
            border-left: 1px solid #dbeafe;
            border-top-left-radius: 10px;
            border-bottom-left-radius: 10px;
            width: 270px;
        }
        .scdc-global-settings-table tr td,
        .scdc-modern-settings tr td {
            border-right: 1px solid #dbeafe;
            border-top-right-radius: 10px;
            border-bottom-right-radius: 10px;
        }
        .scdc-global-settings-table .scdc-title-row th {
            background: linear-gradient(180deg, #eff6ff, #dbeafe);
            color: #1d4ed8;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
            border-color: #bfdbfe;
            border-radius: 10px;
        }
        .scdc-modern-settings .forminp-title th,
        .scdc-modern-settings .forminp-title td {
            background: #eff6ff;
            border-color: #bfdbfe;
        }
        .scdc-modern-settings .forminp-title th h3 {
            margin: 0;
            font-size: 14px;
            letter-spacing: 0.2px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .scdc-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #334155;
            font-size: 10px;
            font-weight: 700;
            line-height: 1;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            padding: 4px 8px;
        }
        .scdc-title-meta {
            margin-top: 6px;
            color: #64748b;
            font-size: 12px;
            line-height: 1.35;
        }
        .scdc-modern-settings input[type="text"],
        .scdc-modern-settings input[type="password"],
        .scdc-modern-settings input[type="number"],
        .scdc-modern-settings select,
        .scdc-global-settings-table input[type="text"],
        .scdc-global-settings-table input[type="password"],
        .scdc-global-settings-table input[type="number"],
        .scdc-global-settings-table select {
            border-radius: 8px;
            border-color: #bfdbfe;
            min-height: 36px;
        }
        .scdc-global-settings-table input:focus,
        .scdc-global-settings-table select:focus,
        .scdc-modern-settings input:focus,
        .scdc-modern-settings select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 1px #2563eb;
        }
        .scdc-modern-settings .description {
            color: #4b5563;
        }
        .scdc-modern-settings .scdc-is-disabled {
            opacity: 0.5;
        }
        .scdc-status-card {
            background: linear-gradient(135deg, #1d4ed8, #0ea5e9);
            color: #eff6ff;
            border-radius: 12px;
            padding: 16px 18px;
            margin: 0 0 14px;
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.22);
        }
        .scdc-status-card h4 {
            margin: 0 0 10px;
            font-size: 14px;
            color: #f8fafc;
        }
        .scdc-status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 8px;
        }
        .scdc-pill {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.2px;
            margin-left: 6px;
        }
        .scdc-pill-on {
            background: #bbf7d0;
            color: #14532d;
        }
        .scdc-pill-off {
            background: #e2e8f0;
            color: #334155;
        }
        .scdc-status-item {
            background: rgba(255, 255, 255, 0.13);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 12px;
        }
        .scdc-status-item strong {
            display: block;
            color: #f8fafc;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }
        .scdc-warning-note {
            margin-top: 10px;
            font-size: 12px;
            color: #fef3c7;
        }
        .scdc-primary-btn {
            background: linear-gradient(135deg, #2563eb, #1d4ed8) !important;
            border-color: #1d4ed8 !important;
            color: #fff !important;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.25);
        }
        .scdc-primary-btn:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af) !important;
        }
        .scdc-secondary-btn {
            border-color: #bfdbfe !important;
            color: #1d4ed8 !important;
            background: #eff6ff !important;
        }
        .scdc-secondary-btn:hover {
            border-color: #93c5fd !important;
            background: #dbeafe !important;
            color: #1e3a8a !important;
        }
        .scdc-hidden-row {
            opacity: 0;
            transition: opacity .2s ease;
        }
    </style>
    <?php
}

function scdc_render_admin_settings_js() {
    return;
    if ( ! scdc_is_wc_shipping_settings_screen() ) {
        return;
    }
    ?>
    <script id="scdc-modern-settings-js">
        (function() {
            function closestRow(el) {
                return el ? el.closest('tr') : null;
            }

            function setRowVisibility(row, isVisible) {
                if (!row) {
                    return;
                }
                row.style.display = isVisible ? '' : 'none';
                row.classList.toggle('scdc-is-disabled', !isVisible);
            }

            function fieldBySuffix(suffix) {
                return document.querySelector('input[id$="' + suffix + '"], select[id$="' + suffix + '"]');
            }

            function fieldValue(suffix, fallback) {
                var field = fieldBySuffix(suffix);
                if (!field) {
                    return fallback || '';
                }
                if (field.type === 'checkbox') {
                    return field.checked ? 'yes' : 'no';
                }
                return field.value || fallback || '';
            }

            function boolPill(isOn) {
                return '<span class="scdc-pill ' + (isOn ? 'scdc-pill-on' : 'scdc-pill-off') + '">' + (isOn ? 'ON' : 'OFF') + '</span>';
            }

            function mountStatusCard(table) {
                var card = document.createElement('div');
                card.className = 'scdc-status-card';
                card.innerHTML = [
                    '<h4>Sendcloud Dynamic Checkout Control Center</h4>',
                    '<div class="scdc-status-grid" id="scdc-status-grid"></div>',
                    '<div class="scdc-warning-note" id="scdc-warning-note" style="display:none;"></div>'
                ].join('');
                table.parentNode.insertBefore(card, table);
                return card;
            }

            function enhanceSectionTitles(table) {
                var titleRows = table.querySelectorAll('.forminp-title');
                var sectionMeta = {
                    'checkout mode': { badge: 'Mode', hint: 'Pick the source of rates shown to shoppers.' },
                    'sendcloud credentials': { badge: 'Auth', hint: 'Credentials are required only when Sendcloud mode is active.' },
                    'sender address inputs': { badge: 'Source', hint: 'Used by carriers to calculate zonal and remote pricing.' },
                    'fallback parcel dimensions': { badge: 'Parcel', hint: 'Applied only when products do not have dimensions.' },
                    'pricing and display': { badge: 'Rules', hint: 'Configure markup, free-shipping threshold, and label output.' }
                };

                titleRows.forEach(function(row) {
                    var heading = row.querySelector('h3');
                    var cell = row.querySelector('th');
                    if (!heading || !cell) {
                        return;
                    }

                    var key = (heading.textContent || '').trim().toLowerCase();
                    var meta = sectionMeta[key];
                    if (!meta) {
                        return;
                    }

                    if (!heading.querySelector('.scdc-badge')) {
                        var badge = document.createElement('span');
                        badge.className = 'scdc-badge';
                        badge.textContent = meta.badge;
                        heading.appendChild(badge);
                    }

                    if (!cell.querySelector('.scdc-title-meta')) {
                        var helper = document.createElement('div');
                        helper.className = 'scdc-title-meta';
                        helper.textContent = meta.hint;
                        cell.appendChild(helper);
                    }
                });
            }

            function renderStatusCard(card) {
                var sendcloudEnabled = fieldValue('_use_sendcloud_rates') === 'yes';
                var hideOthers = fieldValue('_hide_other_methods') === 'yes';
                var freeEnabled = fieldValue('_free_shipping_enabled') === 'yes';
                var thresholdRaw = fieldValue('_free_shipping_threshold', '0');
                var threshold = parseFloat(thresholdRaw || '0');
                var markupType = fieldValue('_markup_type', 'none');
                var markupValue = fieldValue('_markup_value', '0');

                var grid = card.querySelector('#scdc-status-grid');
                if (grid) {
                    grid.innerHTML = [
                        '<div class="scdc-status-item"><strong>Rate Source</strong>' + (sendcloudEnabled ? 'Sendcloud API' : 'WooCommerce Zones') + boolPill(sendcloudEnabled) + '</div>',
                        '<div class="scdc-status-item"><strong>Method Visibility</strong>' + (hideOthers ? 'Only Sendcloud shown' : 'All methods shown') + boolPill(hideOthers) + '</div>',
                        '<div class="scdc-status-item"><strong>Free Shipping</strong>' + (freeEnabled ? 'Threshold enabled' : 'Disabled') + boolPill(freeEnabled) + '</div>',
                        '<div class="scdc-status-item"><strong>Threshold</strong>' + (isNaN(threshold) ? '0.00' : threshold.toFixed(2)) + '</div>',
                        '<div class="scdc-status-item"><strong>Price Adjustment</strong>' + markupType + ' (' + markupValue + ')</div>'
                    ].join('');
                }

                var warning = card.querySelector('#scdc-warning-note');
                if (!warning) {
                    return;
                }

                if (sendcloudEnabled && freeEnabled && (isNaN(threshold) || threshold <= 0)) {
                    warning.style.display = 'block';
                    warning.textContent = 'Free shipping is enabled with a threshold of 0. This can make all Sendcloud rates free.';
                } else {
                    warning.style.display = 'none';
                    warning.textContent = '';
                }
            }

            function initSCDCSettingsUI() {
                var useSendcloud = document.querySelector('input[id$="_use_sendcloud_rates"]');
                if (!useSendcloud) {
                    return;
                }

                var table = useSendcloud.closest('table');
                if (table) {
                    table.classList.add('scdc-modern-settings');
                    enhanceSectionTitles(table);
                }
                var statusCard = table ? mountStatusCard(table) : null;

                var freeShipping = document.querySelector('input[id$="_free_shipping_enabled"]');
                var dependentSendcloudSelectors = [
                    'input[id$="_api_key"]',
                    'input[id$="_api_secret"]',
                    'input[id$="_from_country"]',
                    'input[id$="_from_postal_code"]',
                    'input[id$="_default_length"]',
                    'input[id$="_default_width"]',
                    'input[id$="_default_height"]',
                    'select[id$="_markup_type"]',
                    'input[id$="_markup_value"]',
                    'input[id$="_free_shipping_enabled"]',
                    'input[id$="_show_carrier_name"]',
                    'input[id$="_show_description"]',
                    'input[id$="_enable_caching"]',
                    'input[id$="_hide_other_methods"]'
                ];
                var freeShippingSelectors = [
                    'input[id$="_free_shipping_threshold"]',
                    'input[id$="_free_shipping_label"]'
                ];

                function refreshVisibility() {
                    var sendcloudEnabled = !!useSendcloud.checked;
                    dependentSendcloudSelectors.forEach(function(selector) {
                        setRowVisibility(closestRow(document.querySelector(selector)), sendcloudEnabled);
                    });

                    var freeEnabled = freeShipping && freeShipping.checked && sendcloudEnabled;
                    freeShippingSelectors.forEach(function(selector) {
                        setRowVisibility(closestRow(document.querySelector(selector)), freeEnabled);
                    });

                    if (statusCard) {
                        renderStatusCard(statusCard);
                    }
                }

                useSendcloud.addEventListener('change', refreshVisibility);
                if (freeShipping) {
                    freeShipping.addEventListener('change', refreshVisibility);
                }
                ['_hide_other_methods','_free_shipping_threshold','_markup_type','_markup_value'].forEach(function(suffix) {
                    var field = fieldBySuffix(suffix);
                    if (field) {
                        field.addEventListener('change', refreshVisibility);
                        field.addEventListener('input', refreshVisibility);
                    }
                });
                refreshVisibility();
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initSCDCSettingsUI);
            } else {
                initSCDCSettingsUI();
            }
        })();
    </script>
    <?php
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. Save the selected shipping_option_code to the WooCommerce order meta
//    so you can use it later when creating the Sendcloud shipment.
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'woocommerce_checkout_order_created', 'scdc_save_shipping_option_to_order' );

function scdc_save_shipping_option_to_order( WC_Order $order ) {
    foreach ( $order->get_shipping_methods() as $shipping_item ) {
        $pickup = $shipping_item->get_meta( '_scdc_pickup_from_store' );
        if ( 'yes' === $pickup ) {
            $order->update_meta_data( '_scdc_pickup_from_store', 'yes' );
            $order->update_meta_data( '_scdc_pickup_store_address', (string) $shipping_item->get_meta( '_scdc_pickup_store_address' ) );
            $order->update_meta_data( '_scdc_pickup_opening_hours', (string) $shipping_item->get_meta( '_scdc_pickup_opening_hours' ) );
            $order->save();
            break;
        }

        $code = $shipping_item->get_meta( '_sendcloud_shipping_option_code' );
        if ( $code ) {
            $order->update_meta_data( '_sendcloud_shipping_option_code', $code );
            $order->update_meta_data( '_sendcloud_carrier_code',         $shipping_item->get_meta( '_sendcloud_carrier_code' ) );
            $order->save();
            break;
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. Show the selected Sendcloud shipping option in the WooCommerce order admin
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'woocommerce_admin_order_data_after_shipping_address', 'scdc_display_option_code_in_admin' );

function scdc_display_option_code_in_admin( WC_Order $order ) {
    if ( 'yes' === (string) $order->get_meta( '_scdc_pickup_from_store' ) ) {
        echo '<p><strong>' . esc_html__( 'Pickup from Store', 'scdc' ) . ':</strong> ' . esc_html__( 'Yes', 'scdc' ) . '</p>';
        $pickup_address = (string) $order->get_meta( '_scdc_pickup_store_address' );
        $pickup_hours   = (string) $order->get_meta( '_scdc_pickup_opening_hours' );
        if ( '' !== $pickup_address ) {
            echo '<p><strong>' . esc_html__( 'Store Address', 'scdc' ) . ':</strong> ' . esc_html( $pickup_address ) . '</p>';
        }
        if ( '' !== $pickup_hours ) {
            echo '<p><strong>' . esc_html__( 'Opening Hours', 'scdc' ) . ':</strong> ' . nl2br( esc_html( $pickup_hours ) ) . '</p>';
        }
        return;
    }

    $code    = $order->get_meta( '_sendcloud_shipping_option_code' );
    $carrier = $order->get_meta( '_sendcloud_carrier_code' );

    if ( $code ) {
        echo '<p><strong>' . esc_html__( 'Sendcloud Shipping Option', 'scdc' ) . ':</strong> '
           . esc_html( $code );
        if ( $carrier ) {
            echo ' <em>(' . esc_html( $carrier ) . ')</em>';
        }
        echo '</p>';
    }
}

function scdc_get_order_sendcloud_payload_meta( WC_Order $order ): array {
    if ( 'yes' === (string) $order->get_meta( '_scdc_pickup_from_store' ) ) {
        return [
            'pickup_from_store' => true,
            'pickup_store_address' => (string) $order->get_meta( '_scdc_pickup_store_address' ),
            'pickup_opening_hours' => (string) $order->get_meta( '_scdc_pickup_opening_hours' ),
        ];
    }

    return [
        'pickup_from_store' => false,
        'shipping_option_code' => (string) $order->get_meta( '_sendcloud_shipping_option_code' ),
        'carrier_code' => (string) $order->get_meta( '_sendcloud_carrier_code' ),
    ];
}

add_filter(
    'scdc_sendcloud_shipment_payload_meta',
    static function ( $meta, $order ) {
        if ( $order instanceof WC_Order ) {
            return scdc_get_order_sendcloud_payload_meta( $order );
        }
        return $meta;
    },
    10,
    2
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Clear cached rates when cart changes (weight / destination / value)
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'woocommerce_cart_updated',                'scdc_flush_rate_cache' );
add_action( 'woocommerce_after_checkout_form',         'scdc_flush_rate_cache' );
add_action( 'woocommerce_checkout_update_order_review', 'scdc_flush_rate_cache' );

function scdc_flush_rate_cache() {
    // WooCommerce already busts its own shipping transients; this ensures
    // our Sendcloud-specific transients are also cleared.
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_scdc_%'
            OR option_name LIKE '_transient_timeout_scdc_%'"
    );
}