<?php
/**
 * Plugin Name: EGH Appilix Manual Notifications
 * Description: Admin tool for manually sending Appilix notifications by device and study level.
 * Version: 1.0.0
 * Author: Codex
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'EGH_AMN_OPTION_KEY', 'egh_amn_settings' );

function egh_amn_get_settings() {
    $defaults = array(
        'device_meta_key' => 'student_device',
        'android_values'  => 'android',
        'iphone_values'   => 'ios',
        'level_meta_key'  => 'egh_studying_language',
        'levels'          => 'en,de,es,ko',
        'cefr_meta_key'   => 'egh_student_levels',
        'cefr_levels'     => 'A1,A2,B1,B2,C1,C2',
    );

    $settings = get_option( EGH_AMN_OPTION_KEY, array() );

    if ( ! is_array( $settings ) ) {
        $settings = array();
    }

    return wp_parse_args( $settings, $defaults );
}

function egh_amn_get_form_state_meta_key() {
    return 'egh_amn_last_form_state';
}

function egh_amn_get_form_state() {
    $defaults = array(
        'notification_title' => '',
        'notification_body'  => '',
        'open_link_url'      => '',
        'notification_image' => '',
        'device_target'      => 'all',
        'level_target'       => 'all',
        'cefr_target'        => 'all',
    );

    $user_id = get_current_user_id();

    if ( ! $user_id ) {
        return $defaults;
    }

    $state = get_user_meta( $user_id, egh_amn_get_form_state_meta_key(), true );

    if ( ! is_array( $state ) ) {
        $state = array();
    }

    return wp_parse_args( $state, $defaults );
}

function egh_amn_save_form_state( $state ) {
    $user_id = get_current_user_id();

    if ( ! $user_id ) {
        return;
    }

    update_user_meta( $user_id, egh_amn_get_form_state_meta_key(), $state );
}

function egh_amn_normalize_value( $value ) {
    $value = is_scalar( $value ) ? (string) $value : '';
    $value = strtolower( trim( wp_strip_all_tags( $value ) ) );
    $value = str_replace( array( '-', '_', ' ' ), '', $value );

    return $value;
}

function egh_amn_csv_to_normalized_array( $value ) {
    $parts = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );

    return array_values(
        array_unique(
            array_filter(
                array_map( 'egh_amn_normalize_value', $parts )
            )
        )
    );
}

function egh_amn_get_level_options( $settings ) {
    if ( class_exists( 'EGH_Language_Mapping' ) && method_exists( 'EGH_Language_Mapping', 'get_studied_language_options' ) ) {
        return EGH_Language_Mapping::get_studied_language_options();
    }

    $levels = array_filter( array_map( 'trim', explode( ',', (string) $settings['levels'] ) ) );
    $options = array();

    foreach ( array_values( array_unique( $levels ) ) as $level ) {
        $options[ $level ] = strtoupper( $level );
    }

    return $options;
}

function egh_amn_get_cefr_options( $settings ) {
    $levels = array_filter( array_map( 'trim', explode( ',', (string) $settings['cefr_levels'] ) ) );
    $options = array();

    foreach ( array_values( array_unique( $levels ) ) as $level ) {
        $options[ $level ] = strtoupper( $level );
    }

    return $options;
}

function egh_amn_classify_device( $user_id, $settings ) {
    $meta_key = trim( $settings['device_meta_key'] );

    if ( '' === $meta_key ) {
        return '';
    }

    $raw_value = get_user_meta( $user_id, $meta_key, true );

    if ( is_array( $raw_value ) || is_object( $raw_value ) ) {
        $raw_value = wp_json_encode( $raw_value );
    }

    $normalized      = egh_amn_normalize_value( $raw_value );
    $android_values  = egh_amn_csv_to_normalized_array( $settings['android_values'] );
    $iphone_values   = egh_amn_csv_to_normalized_array( $settings['iphone_values'] );

    if ( in_array( $normalized, $android_values, true ) ) {
        return 'android';
    }

    if ( in_array( $normalized, $iphone_values, true ) ) {
        return 'iphone';
    }

    return '';
}

function egh_amn_get_study_level( $user_id, $settings ) {
    $meta_key = trim( $settings['level_meta_key'] );

    if ( '' === $meta_key ) {
        return '';
    }

    $level = get_user_meta( $user_id, $meta_key, true );

    if ( is_array( $level ) || is_object( $level ) ) {
        $level = wp_json_encode( $level );
    }

    return trim( (string) $level );
}

function egh_amn_get_cefr_level( $user_id, $settings ) {
    $meta_key = trim( $settings['cefr_meta_key'] );

    if ( '' === $meta_key ) {
        return '';
    }

    $level = get_user_meta( $user_id, $meta_key, true );

    if ( is_array( $level ) ) {
        $level = reset( $level );
    } elseif ( is_object( $level ) ) {
        $level = wp_json_encode( $level );
    }

    return strtoupper( trim( (string) $level ) );
}

function egh_amn_get_matching_users( $device_target = 'all', $level_target = 'all', $cefr_target = 'all', $settings = null ) {
    if ( null === $settings ) {
        $settings = egh_amn_get_settings();
    }

    $users = get_users(
        array(
            'fields'  => array( 'ID', 'user_email', 'display_name', 'user_login' ),
            'orderby' => 'display_name',
            'order'   => 'ASC',
        )
    );

    $matches = array();

    foreach ( $users as $user ) {
        if ( empty( $user->user_email ) ) {
            continue;
        }

        $device = egh_amn_classify_device( $user->ID, $settings );
        $level  = egh_amn_get_study_level( $user->ID, $settings );
        $cefr   = egh_amn_get_cefr_level( $user->ID, $settings );

        if ( 'all' !== $device_target && $device !== $device_target ) {
            continue;
        }

        if ( 'all' !== $level_target && strtolower( $level ) !== strtolower( $level_target ) ) {
            continue;
        }

        if ( 'all' !== $cefr_target && strtoupper( $cefr ) !== strtoupper( $cefr_target ) ) {
            continue;
        }

        $matches[] = array(
            'ID'           => (int) $user->ID,
            'email'        => $user->user_email,
            'display_name' => $user->display_name,
            'user_login'   => $user->user_login,
            'device'       => $device,
            'level'        => $level,
            'cefr'         => $cefr,
        );
    }

    return $matches;
}

function egh_amn_send_appilix_notification( $user_email, $notification_title, $notification_body, $open_link_url, $notification_image = '' ) {
    if ( ! defined( 'APPILIX_APP_KEY' ) || ! defined( 'APPILIX_API_KEY' ) ) {
        return new WP_Error( 'egh_amn_missing_config', 'Appilix constants are missing.' );
    }

    $body = array(
        'app_key'            => APPILIX_APP_KEY,
        'api_key'            => APPILIX_API_KEY,
        'notification_title' => $notification_title,
        'notification_body'  => $notification_body,
        'open_link_url'      => $open_link_url,
    );

    if ( ! empty( $user_email ) ) {
        $body['user_identity'] = $user_email;
    }

    if ( ! empty( $notification_image ) ) {
        $body['notification_image'] = $notification_image;
    }

    $response = wp_remote_post(
        'https://appilix.com/api/push-notification',
        array(
            'body'    => $body,
            'timeout' => 10,
        )
    );

    return is_wp_error( $response ) ? $response : true;
}

function egh_amn_log_send_result( $user_email, $title, $body, $result ) {
    $log_file = plugin_dir_path( __FILE__ ) . 'notifications.log';

    $log_message = sprintf(
        "[%s] User: %s, Title: %s, Body: %s, Response: %s\n",
        current_time( 'mysql' ),
        $user_email,
        $title,
        $body,
        is_wp_error( $result ) ? $result->get_error_message() : 'Success'
    );

    file_put_contents( $log_file, $log_message, FILE_APPEND );
}

function egh_amn_is_broadcast_request( $device_target, $level_target, $cefr_target ) {
    return 'all' === $device_target && 'all' === $level_target && 'all' === $cefr_target;
}

function egh_amn_register_admin_menu() {
    add_menu_page(
        'Manual Appilix Notifications',
        'Manual Notifications',
        'manage_options',
        'egh-appilix-manual-notifications',
        'egh_amn_render_admin_page',
        'dashicons-megaphone',
        101
    );
}
add_action( 'admin_menu', 'egh_amn_register_admin_menu' );

function egh_amn_handle_settings_save() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'egh-appilix-manual-notifications' ) );
    }

    check_admin_referer( 'egh_amn_save_settings' );

    $settings = array(
        'device_meta_key' => sanitize_text_field( wp_unslash( $_POST['device_meta_key'] ?? '' ) ),
        'android_values'  => sanitize_text_field( wp_unslash( $_POST['android_values'] ?? '' ) ),
        'iphone_values'   => sanitize_text_field( wp_unslash( $_POST['iphone_values'] ?? '' ) ),
        'level_meta_key'  => sanitize_text_field( wp_unslash( $_POST['level_meta_key'] ?? '' ) ),
        'levels'          => sanitize_text_field( wp_unslash( $_POST['levels'] ?? '' ) ),
        'cefr_meta_key'   => sanitize_text_field( wp_unslash( $_POST['cefr_meta_key'] ?? '' ) ),
        'cefr_levels'     => sanitize_text_field( wp_unslash( $_POST['cefr_levels'] ?? '' ) ),
    );

    update_option( EGH_AMN_OPTION_KEY, $settings );

    wp_safe_redirect(
        add_query_arg(
            array(
                'page'            => 'egh-appilix-manual-notifications',
                'settings-updated' => '1',
            ),
            admin_url( 'admin.php' )
        )
    );
    exit;
}
add_action( 'admin_post_egh_amn_save_settings', 'egh_amn_handle_settings_save' );

function egh_amn_handle_send_notification() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'egh-appilix-manual-notifications' ) );
    }

    check_admin_referer( 'egh_amn_send_notification' );

    $title         = sanitize_text_field( wp_unslash( $_POST['notification_title'] ?? '' ) );
    $body          = sanitize_textarea_field( wp_unslash( $_POST['notification_body'] ?? '' ) );
    $open_link_url = esc_url_raw( wp_unslash( $_POST['open_link_url'] ?? '' ) );
    $image_url     = esc_url_raw( wp_unslash( $_POST['notification_image'] ?? '' ) );
    $device_target = sanitize_key( wp_unslash( $_POST['device_target'] ?? 'all' ) );
    $level_target  = sanitize_text_field( wp_unslash( $_POST['level_target'] ?? 'all' ) );
    $cefr_target   = sanitize_text_field( wp_unslash( $_POST['cefr_target'] ?? 'all' ) );

    egh_amn_save_form_state(
        array(
            'notification_title' => $title,
            'notification_body'  => $body,
            'open_link_url'      => $open_link_url,
            'notification_image' => $image_url,
            'device_target'      => $device_target,
            'level_target'       => $level_target,
            'cefr_target'        => $cefr_target,
        )
    );

    if ( '' === $title || '' === $body ) {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'   => 'egh-appilix-manual-notifications',
                    'status' => 'missing-message',
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    $settings = egh_amn_get_settings();
    $is_broadcast = egh_amn_is_broadcast_request( $device_target, $level_target, $cefr_target );
    $users        = $is_broadcast ? array() : egh_amn_get_matching_users( $device_target, $level_target, $cefr_target, $settings );

    $sent_count   = 0;
    $failed_count = 0;

    if ( $is_broadcast ) {
        $result = egh_amn_send_appilix_notification( '', $title, $body, $open_link_url, '' );
        egh_amn_log_send_result( 'BROADCAST', $title, $body, $result );

        if ( is_wp_error( $result ) ) {
            $failed_count = 1;
        } else {
            $sent_count = 1;
        }
    } else {
        foreach ( $users as $user ) {
            $user_image = '';

            if ( ! empty( $image_url ) && 'android' === $user['device'] ) {
                $user_image = $image_url;
            }

            $result = egh_amn_send_appilix_notification( $user['email'], $title, $body, $open_link_url, $user_image );
            egh_amn_log_send_result( $user['email'], $title, $body, $result );

            if ( is_wp_error( $result ) ) {
                $failed_count++;
                continue;
            }

            $sent_count++;
        }
    }

    wp_safe_redirect(
        add_query_arg(
            array(
                'page'   => 'egh-appilix-manual-notifications',
                'status' => 'sent',
                'sent'   => $sent_count,
                'failed' => $failed_count,
            ),
            admin_url( 'admin.php' )
        )
    );
    exit;
}
add_action( 'admin_post_egh_amn_send_notification', 'egh_amn_handle_send_notification' );

function egh_amn_render_notice() {
    $status = sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) );

    if ( 'sent' === $status ) {
        $sent   = absint( $_GET['sent'] ?? 0 );
        $failed = absint( $_GET['failed'] ?? 0 );
        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html( sprintf( 'Notifications processed. Sent: %d. Failed: %d.', $sent, $failed ) )
        );
    } elseif ( 'missing-message' === $status ) {
        echo '<div class="notice notice-error is-dismissible"><p>Please enter both a notification title and body.</p></div>';
    } elseif ( isset( $_GET['settings-updated'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Manual notification settings saved.</p></div>';
    }
}

function egh_amn_render_users_table( $users, $title, $language_options = array() ) {
    ?>
    <h2><?php echo esc_html( $title ); ?></h2>
    <p><strong><?php echo esc_html( count( $users ) ); ?></strong> users matched.</p>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>User</th>
                <th>Email</th>
                <th>Device</th>
                <th>Studying Language</th>
                <th>CEFR Level</th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $users ) ) : ?>
                <tr>
                    <td colspan="5">No users matched the current filter.</td>
                </tr>
            <?php else : ?>
                <?php foreach ( $users as $user ) : ?>
                    <tr>
                        <td><?php echo esc_html( $user['display_name'] ?: $user['user_login'] ); ?></td>
                        <td><?php echo esc_html( $user['email'] ); ?></td>
                        <td><?php echo esc_html( $user['device'] ?: 'Unknown' ); ?></td>
                        <td><?php echo esc_html( ( $user['level'] && isset( $language_options[ $user['level'] ] ) ) ? $language_options[ $user['level'] ] : ( $user['level'] ?: 'Unknown' ) ); ?></td>
                        <td><?php echo esc_html( $user['cefr'] ?: 'Unknown' ); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
}

function egh_amn_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $settings      = egh_amn_get_settings();
    $level_options = egh_amn_get_level_options( $settings );
    $cefr_options  = egh_amn_get_cefr_options( $settings );
    $form_state    = egh_amn_get_form_state();
    $view          = sanitize_key( wp_unslash( $_GET['view'] ?? '' ) );

    $preview_device = sanitize_key( wp_unslash( $_GET['preview_device'] ?? 'all' ) );
    $preview_level  = sanitize_text_field( wp_unslash( $_GET['preview_level'] ?? 'all' ) );
    $preview_cefr   = sanitize_text_field( wp_unslash( $_GET['preview_cefr'] ?? 'all' ) );
    $preview_users  = egh_amn_get_matching_users( $preview_device, $preview_level, $preview_cefr, $settings );
    $android_users  = egh_amn_get_matching_users( 'android', 'all', 'all', $settings );
    $iphone_users   = egh_amn_get_matching_users( 'iphone', 'all', 'all', $settings );

    ?>
    <div class="wrap">
        <h1>Manual Appilix Notifications</h1>
        <?php egh_amn_render_notice(); ?>

        <p>Use this page to filter users by device, studying language, and CEFR level, preview recipients, and send manual Appilix notifications.</p>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin:20px 0;">
            <div style="background:#fff;border:1px solid #dcdcde;padding:16px;">
                <h2 style="margin-top:0;">Android Users</h2>
                <p><strong><?php echo esc_html( count( $android_users ) ); ?></strong> matched the current Android mapping.</p>
                <p><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'egh-appilix-manual-notifications', 'view' => 'android-list' ), admin_url( 'admin.php' ) ) ); ?>">List Android Users</a></p>
            </div>
            <div style="background:#fff;border:1px solid #dcdcde;padding:16px;">
                <h2 style="margin-top:0;">iPhone Users</h2>
                <p><strong><?php echo esc_html( count( $iphone_users ) ); ?></strong> matched the current iPhone mapping.</p>
                <p><a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'egh-appilix-manual-notifications', 'view' => 'iphone-list' ), admin_url( 'admin.php' ) ) ); ?>">List iPhone Users</a></p>
            </div>
        </div>

        <hr />

        <h2>Metadata Settings</h2>
        <p>The filters are preconfigured for your app using `student_device`, `egh_studying_language`, and `egh_student_levels`. You can still adjust these settings here later if the app schema changes.</p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'egh_amn_save_settings' ); ?>
            <input type="hidden" name="action" value="egh_amn_save_settings" />
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="device_meta_key">Device Meta Key</label></th>
                    <td><input name="device_meta_key" id="device_meta_key" type="text" class="regular-text" value="<?php echo esc_attr( $settings['device_meta_key'] ); ?>" placeholder="student_device" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="android_values">Android Values</label></th>
                    <td><input name="android_values" id="android_values" type="text" class="regular-text" value="<?php echo esc_attr( $settings['android_values'] ); ?>" placeholder="android" />
                    <p class="description">Comma-separated values that should count as Android.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="iphone_values">iPhone Values</label></th>
                    <td><input name="iphone_values" id="iphone_values" type="text" class="regular-text" value="<?php echo esc_attr( $settings['iphone_values'] ); ?>" placeholder="ios" />
                    <p class="description">Comma-separated values that should count as iPhone/iOS.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="level_meta_key">Studying Language Meta Key</label></th>
                    <td><input name="level_meta_key" id="level_meta_key" type="text" class="regular-text" value="<?php echo esc_attr( $settings['level_meta_key'] ); ?>" placeholder="egh_studying_language" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="levels">Available Studying Languages</label></th>
                    <td><input name="levels" id="levels" type="text" class="regular-text" value="<?php echo esc_attr( $settings['levels'] ); ?>" placeholder="en,de,es,ko" />
                    <p class="description">Comma-separated list used in the send form.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="cefr_meta_key">CEFR Level Meta Key</label></th>
                    <td><input name="cefr_meta_key" id="cefr_meta_key" type="text" class="regular-text" value="<?php echo esc_attr( $settings['cefr_meta_key'] ); ?>" placeholder="egh_student_levels" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="cefr_levels">Available CEFR Levels</label></th>
                    <td><input name="cefr_levels" id="cefr_levels" type="text" class="regular-text" value="<?php echo esc_attr( $settings['cefr_levels'] ); ?>" placeholder="A1,A2,B1,B2,C1,C2" />
                    <p class="description">Comma-separated list used in the send form.</p></td>
                </tr>
            </table>
            <?php submit_button( 'Save Metadata Settings' ); ?>
        </form>

        <hr />

        <h2>Send Notification</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'egh_amn_send_notification' ); ?>
            <input type="hidden" name="action" value="egh_amn_send_notification" />
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="notification_title">Title</label></th>
                    <td><input name="notification_title" id="notification_title" type="text" class="regular-text" value="<?php echo esc_attr( $form_state['notification_title'] ); ?>" required /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="notification_body">Body</label></th>
                    <td><textarea name="notification_body" id="notification_body" rows="5" class="large-text" required><?php echo esc_textarea( $form_state['notification_body'] ); ?></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><label for="open_link_url">Open Link URL</label></th>
                    <td><input name="open_link_url" id="open_link_url" type="url" class="regular-text" value="<?php echo esc_attr( $form_state['open_link_url'] ); ?>" placeholder="https://app.english-grammar-homework.com/" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="notification_image">Notification Image URL</label></th>
                    <td><input name="notification_image" id="notification_image" type="url" class="regular-text" value="<?php echo esc_attr( $form_state['notification_image'] ); ?>" placeholder="https://example.com/image.jpg" />
                    <p class="description">Sent only to Android users. iPhone users will receive the same notification without the image.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="device_target">Device</label></th>
                    <td>
                        <select name="device_target" id="device_target">
                            <option value="all" <?php selected( $form_state['device_target'], 'all' ); ?>>All devices</option>
                            <option value="android" <?php selected( $form_state['device_target'], 'android' ); ?>>Android only</option>
                            <option value="iphone" <?php selected( $form_state['device_target'], 'iphone' ); ?>>iPhone only</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="level_target">Studying Language</label></th>
                    <td>
                        <select name="level_target" id="level_target">
                            <option value="all" <?php selected( $form_state['level_target'], 'all' ); ?>>All studying languages</option>
                            <?php foreach ( $level_options as $level_value => $level_label ) : ?>
                                <option value="<?php echo esc_attr( $level_value ); ?>" <?php selected( $form_state['level_target'], $level_value ); ?>><?php echo esc_html( $level_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="cefr_target">CEFR Level</label></th>
                    <td>
                        <select name="cefr_target" id="cefr_target">
                            <option value="all" <?php selected( $form_state['cefr_target'], 'all' ); ?>>All CEFR levels</option>
                            <?php foreach ( $cefr_options as $cefr_value => $cefr_label ) : ?>
                                <option value="<?php echo esc_attr( $cefr_value ); ?>" <?php selected( $form_state['cefr_target'], $cefr_value ); ?>><?php echo esc_html( $cefr_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Send Notification' ); ?>
        </form>

        <hr />

        <h2>Preview Recipients</h2>
        <form method="get" action="">
            <input type="hidden" name="page" value="egh-appilix-manual-notifications" />
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="preview_device">Device</label></th>
                    <td>
                        <select name="preview_device" id="preview_device">
                            <option value="all" <?php selected( $preview_device, 'all' ); ?>>All devices</option>
                            <option value="android" <?php selected( $preview_device, 'android' ); ?>>Android only</option>
                            <option value="iphone" <?php selected( $preview_device, 'iphone' ); ?>>iPhone only</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="preview_level">Studying Language</label></th>
                    <td>
                        <select name="preview_level" id="preview_level">
                            <option value="all" <?php selected( $preview_level, 'all' ); ?>>All studying languages</option>
                            <?php foreach ( $level_options as $level_value => $level_label ) : ?>
                                <option value="<?php echo esc_attr( $level_value ); ?>" <?php selected( $preview_level, $level_value ); ?>><?php echo esc_html( $level_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="preview_cefr">CEFR Level</label></th>
                    <td>
                        <select name="preview_cefr" id="preview_cefr">
                            <option value="all" <?php selected( $preview_cefr, 'all' ); ?>>All CEFR levels</option>
                            <?php foreach ( $cefr_options as $cefr_value => $cefr_label ) : ?>
                                <option value="<?php echo esc_attr( $cefr_value ); ?>" <?php selected( $preview_cefr, $cefr_value ); ?>><?php echo esc_html( $cefr_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Preview Matching Users', 'secondary', '', false ); ?>
        </form>

        <?php egh_amn_render_users_table( $preview_users, 'Preview Results', $level_options ); ?>

        <?php if ( 'android-list' === $view ) : ?>
            <div style="margin-top:32px;">
                <?php egh_amn_render_users_table( $android_users, 'All Android Users', $level_options ); ?>
            </div>
        <?php elseif ( 'iphone-list' === $view ) : ?>
            <div style="margin-top:32px;">
                <?php egh_amn_render_users_table( $iphone_users, 'All iPhone Users', $level_options ); ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
