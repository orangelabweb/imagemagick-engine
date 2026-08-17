<?php
/**
 * AJAX handlers.
 *
 * @package imagemagick-engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit();
}

// Test if a path is correct for IM binary
function ime_ajax_test_im_path() {
    $nonce = isset( $_REQUEST['ime_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ime_nonce'] ) ) : '';
    if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, 'ime-admin-nonce' ) ) {
        wp_die( 'Sorry, but you do not have permissions to perform this action.' );
    }

    $mode = sanitize_text_field( wp_unslash( $_REQUEST['mode'] ?? 'cli' ) );

    $is_gm      = ( $mode === 'graphicsmagick' );
    $path_key   = $is_gm ? 'gm_path' : 'cli_path';
    $input_path = sanitize_text_field( wp_unslash( $_REQUEST[ $path_key ] ?? '' ) );
    $check_path = @realpath( $input_path ) ?: $input_path;
    $r          = ime_im_cli_check_command( $check_path, $is_gm );
    $found = ! empty( $r );

    $open_basedir = false;
    if ( ! $found ) {
        $open_basedir_ini = ini_get( 'open_basedir' );
        if ( $open_basedir_ini ) {
            $covered    = false;
            $check_norm = rtrim( $check_path, '/\\' ) . DIRECTORY_SEPARATOR;
            foreach ( explode( PATH_SEPARATOR, $open_basedir_ini ) as $dir ) {
                if ( $dir === '' ) {
                    continue;
                }
                $dir_norm = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR;
                if ( strpos( $check_norm, $dir_norm ) === 0 ) {
                    $covered = true;
                    break;
                }
            }
            $open_basedir = ! $covered;
        }
    }

    $engine  = $is_gm ? 'GraphicsMagick' : 'ImageMagick';
    $version = $is_gm ? ime_get_option( 'graphicsmagick_version' ) : ime_get_option( 'imagemagick_version' );

    if ( $found ) {
        wp_send_json_success(
            [
                'found'   => true,
                'engine'  => $engine,
                'version' => (string) $version,
            ]
        );
    }

    wp_send_json_error(
        [
            'found'        => false,
            'engine'       => $engine,
            'open_basedir' => $open_basedir,
            'message'      => $open_basedir
                /* translators: %s: engine name, e.g. ImageMagick */
                ? sprintf( __( '%s not found. Your PHP open_basedir setting is restricting access to this path. Add the path to your open_basedir configuration.', 'imagemagick-engine' ), $engine )
                /* translators: %s: engine name, e.g. ImageMagick */
                : sprintf( __( '%s not found at this path.', 'imagemagick-engine' ), $engine ),
        ]
    );
}

// Get list of attachments to regenerate
function ime_ajax_regeneration_get_images() {
    global $wpdb;

    $nonce = isset( $_REQUEST['ime_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ime_nonce'] ) ) : '';
    if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, 'ime-admin-nonce' ) ) {
        wp_die( 'Sorry, but you do not have permissions to perform this action.' );
    }

    // Query for the IDs only to reduce memory usage
    $images = $wpdb->get_results( "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' AND post_mime_type != 'image/svg+xml'" );

    $ids = array();
    foreach ( $images as $image ) {
        $ids[] = (int) $image->ID;
    }

    wp_send_json( $ids );
}

// Process single attachment ID
function ime_ajax_process_image() {
    global $ime_image_sizes, $ime_image_file;

    $nonce = isset( $_REQUEST['ime_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ime_nonce'] ) ) : '';
    if ( ! current_user_can( 'manage_options' ) || ! ime_mode_valid() || ! wp_verify_nonce( $nonce, 'ime-admin-nonce' ) ) {
        wp_die( '-1' );
    }

    if ( ! isset( $_REQUEST['id'] ) ) {
        wp_die( '-1' );
    }

    $id = intval( $_REQUEST['id'] );
    if ( $id <= 0 ) {
        wp_die( '-1' );
    }

    $temp_sizes = sanitize_text_field( wp_unslash( $_REQUEST['sizes'] ?? '' ) );
    if ( empty( $temp_sizes ) ) {
        wp_die( '-1' );
    }
    $temp_sizes = explode( '|', $temp_sizes );
    if ( count( $temp_sizes ) < 1 ) {
        wp_die( '-1' );
    }

    $temp_sizes = apply_filters( 'intermediate_image_sizes', $temp_sizes );

    $additional_sizes = wp_get_additional_image_sizes();

    foreach ( $temp_sizes as $s ) {
        $sizes[ $s ] = [
            'width'  => '',
            'height' => '',
            'crop'   => false,
        ];
        if ( isset( $additional_sizes[ $s ]['width'] ) ) {
            $sizes[ $s ]['width'] = intval( $additional_sizes[ $s ]['width'] ); // For theme-added sizes
        } else {
            $sizes[ $s ]['width'] = get_option( "{$s}_size_w" ); // For default sizes set in options
        }
        if ( isset( $additional_sizes[ $s ]['height'] ) ) {
            $sizes[ $s ]['height'] = intval( $additional_sizes[ $s ]['height'] ); // For theme-added sizes
        } else {
            $sizes[ $s ]['height'] = get_option( "{$s}_size_h" ); // For default sizes set in options
        }
        if ( isset( $additional_sizes[ $s ]['crop'] ) ) {
            $sizes[ $s ]['crop'] = intval( $additional_sizes[ $s ]['crop'] ); // For theme-added sizes
        } else {
            $sizes[ $s ]['crop'] = get_option( "{$s}_crop" ); // For default sizes set in options
        }
    }

    remove_filter( 'intermediate_image_sizes_advanced', 'ime_filter_image_sizes', 99, 1 );
    $sizes = apply_filters( 'intermediate_image_sizes_advanced', $sizes );

    $force = isset( $_REQUEST['force'] ) && ! ! $_REQUEST['force'];

    $ime_image_file = function_exists('wp_get_original_image_path') ? wp_get_original_image_path( $id ) : get_attached_file( $id );

    if ( false === $ime_image_file || ! file_exists( $ime_image_file ) ) {
        wp_die( '-1' );
    }

    $metadata = wp_get_attachment_metadata( $id );

    // Do not re-encode IME images unless forced
    if ( ! $force && isset( $metadata['image-converter'] ) && is_array( $metadata['image-converter'] ) ) {
        $converter = $metadata['image-converter'];

        foreach ( $sizes as $s => $ignore ) {
            if ( isset( $converter[ $s ] ) && $converter[ $s ] == 'IME' ) {
                unset( $sizes[ $s ] );
            }
        }
        if ( count( $sizes ) < 1 ) {
            wp_die( 1 );
        }
    }

    $ime_image_sizes = $sizes;

    set_time_limit( 60 );

    $new_meta = ime_filter_attachment_metadata( $metadata, $id );
    if ( is_wp_error( $new_meta ) ) {
        wp_die( '-1' );
    }
    wp_update_attachment_metadata( $id, $new_meta );

    /*
     * Normally the old file gets overwritten by the new one when
     * regenerating resized images.
     *
     * However, if the specifications of image sizes were changed this
     * will result in different resized file names.
     *
     * Make sure they get deleted.
     */

    // No old sizes, nothing to check
    if ( ! isset( $metadata['sizes'] ) || empty( $metadata['sizes'] ) ) {
        wp_die( '1' );
    }

    $dir = trailingslashit( dirname( $ime_image_file ) );

    foreach ( $metadata['sizes'] as $size => $sizeinfo ) {
        $old_file = $sizeinfo['file'];

        // Does file exist in new meta?
        $exists = false;
        foreach ( $new_meta['sizes'] as $ignore => $new_sizeinfo ) {
            if ( $old_file != $new_sizeinfo['file'] ) {
                continue;
            }
            $exists = true;
            break;
        }
        if ( $exists ) {
            continue;
        }

        // Old file did not exist in new meta. Delete it!
        @ unlink( $dir . $old_file );
    }

    wp_die( '1' );
}
