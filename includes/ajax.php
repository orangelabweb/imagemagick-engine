<?php
/**
 * AJAX handlers.
 *
 * @package imagemagick-engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit();
}

/**
 * Reject the request unless the caller may manage options and the nonce is valid.
 *
 * Sends a JSON error and exits on failure.
 */
function ime_ajax_require_admin() {
    $nonce = isset( $_REQUEST['ime_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ime_nonce'] ) ) : '';

    if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, 'ime-admin-nonce' ) ) {
        wp_send_json_error(
            [ 'message' => __( 'You do not have permission to perform this action.', 'imagemagick-engine' ) ],
            403
        );
    }
}

// Test if a path is correct for IM binary
function ime_ajax_test_im_path() {
    ime_ajax_require_admin();

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

/**
 * Regenerate one attachment's sub-sizes with the configured engine.
 *
 * @param int      $id         Attachment ID.
 * @param string[] $size_names Size slugs to generate.
 * @param bool     $force      Regenerate sizes already produced by this plugin.
 * @return true|WP_Error
 */
function ime_process_attachment( $id, $size_names, $force ) {
    global $ime_image_sizes, $ime_image_file;

    $size_names = apply_filters( 'intermediate_image_sizes', $size_names );

    $additional_sizes = wp_get_additional_image_sizes();
    $sizes            = [];

    foreach ( $size_names as $s ) {
        $sizes[ $s ] = [
            'width'  => isset( $additional_sizes[ $s ]['width'] )
                ? intval( $additional_sizes[ $s ]['width'] )
                : get_option( "{$s}_size_w" ),
            'height' => isset( $additional_sizes[ $s ]['height'] )
                ? intval( $additional_sizes[ $s ]['height'] )
                : get_option( "{$s}_size_h" ),
            'crop'   => isset( $additional_sizes[ $s ]['crop'] )
                ? intval( $additional_sizes[ $s ]['crop'] )
                : get_option( "{$s}_crop" ),
        ];
    }

    remove_filter( 'intermediate_image_sizes_advanced', 'ime_filter_image_sizes', 99 );
    $sizes = apply_filters( 'intermediate_image_sizes_advanced', $sizes );

    $ime_image_file = function_exists( 'wp_get_original_image_path' )
        ? wp_get_original_image_path( $id )
        : get_attached_file( $id );

    if ( false === $ime_image_file || ! file_exists( $ime_image_file ) ) {
        return new WP_Error( 'ime_missing_file', __( 'The source file is missing.', 'imagemagick-engine' ) );
    }

    $metadata = wp_get_attachment_metadata( $id );

    // Do not re-encode images this plugin already produced, unless forced.
    if ( ! $force && isset( $metadata['image-converter'] ) && is_array( $metadata['image-converter'] ) ) {
        foreach ( $sizes as $s => $ignore ) {
            if ( isset( $metadata['image-converter'][ $s ] ) && 'IME' === $metadata['image-converter'][ $s ] ) {
                unset( $sizes[ $s ] );
            }
        }
        if ( count( $sizes ) < 1 ) {
            return true;
        }
    }

    $ime_image_sizes = $sizes;

    set_time_limit( 60 );

    $new_meta = ime_filter_attachment_metadata( $metadata, $id );
    if ( is_wp_error( $new_meta ) ) {
        return $new_meta;
    }
    wp_update_attachment_metadata( $id, $new_meta );

    /*
     * Resized files are normally overwritten in place. If the size
     * definitions changed, the new files get different names, so the old
     * ones must be deleted explicitly.
     */
    if ( empty( $metadata['sizes'] ) ) {
        return true;
    }

    $dir = trailingslashit( dirname( $ime_image_file ) );

    foreach ( $metadata['sizes'] as $size => $sizeinfo ) {
        $old_file = $sizeinfo['file'];
        $exists   = false;

        foreach ( $new_meta['sizes'] as $ignore => $new_sizeinfo ) {
            if ( $old_file === $new_sizeinfo['file'] ) {
                $exists = true;
                break;
            }
        }

        if ( ! $exists ) {
            wp_delete_file( $dir . $old_file );
        }
    }

    return true;
}

/** Regenerate a single attachment from the media screens. */
function ime_ajax_process_image() {
    ime_ajax_require_admin();

    if ( ! ime_mode_valid() ) {
        wp_send_json_error( [ 'message' => __( 'No valid image engine is configured.', 'imagemagick-engine' ) ] );
    }

    $id = isset( $_REQUEST['id'] ) ? intval( $_REQUEST['id'] ) : 0;
    if ( $id <= 0 ) {
        wp_send_json_error( [ 'message' => __( 'Invalid attachment.', 'imagemagick-engine' ) ] );
    }

    $raw_sizes = sanitize_text_field( wp_unslash( $_REQUEST['sizes'] ?? '' ) );
    $sizes     = array_values( array_filter( array_map( 'sanitize_key', explode( '|', $raw_sizes ) ) ) );

    if ( empty( $sizes ) ) {
        wp_send_json_error( [ 'message' => __( 'Select at least one image size.', 'imagemagick-engine' ) ] );
    }

    $result = ime_process_attachment( $id, $sizes, ! empty( $_REQUEST['force'] ) );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }

    wp_send_json_success( [ 'message' => __( 'Resized using ImageMagick Engine', 'imagemagick-engine' ) ] );
}

/**
 * Read the current regeneration queue.
 *
 * Deletes and reports absent any queue older than IME_REGEN_TTL.
 *
 * @return array|null
 */
function ime_regen_queue_get() {
    $queue = get_option( IME_REGEN_OPTION );

    if ( ! is_array( $queue ) || ! isset( $queue['started'] ) ) {
        return null;
    }

    if ( ( time() - (int) $queue['started'] ) > IME_REGEN_TTL ) {
        ime_regen_queue_clear();
        return null;
    }

    return $queue;
}

/**
 * Write the regeneration queue.
 *
 * @param array $queue Queue state.
 */
function ime_regen_queue_save( $queue ) {
    update_option( IME_REGEN_OPTION, $queue, false );
}

/** Remove the regeneration queue. */
function ime_regen_queue_clear() {
    delete_option( IME_REGEN_OPTION );
}

/**
 * Count attachments eligible for regeneration.
 *
 * @return int
 */
function ime_regen_count_images() {
    global $wpdb;

    return (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM $wpdb->posts
         WHERE post_type = 'attachment'
           AND post_mime_type LIKE 'image/%'
           AND post_mime_type != 'image/svg+xml'"
    );
}

/**
 * Fetch the next page of attachment IDs.
 *
 * Ordering by ID is required for correctness: without a stable sort,
 * OFFSET silently skips rows between batches.
 *
 * @param int $offset Rows already processed.
 * @param int $limit  Rows to fetch.
 * @return int[]
 */
function ime_regen_next_ids( $offset, $limit ) {
    global $wpdb;

    $rows = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID FROM $wpdb->posts
             WHERE post_type = 'attachment'
               AND post_mime_type LIKE 'image/%%'
               AND post_mime_type != 'image/svg+xml'
             ORDER BY ID ASC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        )
    );

    return array_map( 'intval', (array) $rows );
}

/**
 * Choose the next batch size from how long the last batch took.
 *
 * A single image can take 0.2s or 30s depending on its dimensions, so a
 * fixed batch size times out on shared hosting.
 *
 * @param int   $current Batch size just used.
 * @param float $elapsed Seconds the batch took.
 * @return int
 */
function ime_regen_next_batch_size( $current, $elapsed ) {
    if ( $elapsed < 5.0 ) {
        $next = $current * 2;
    } elseif ( $elapsed > 15.0 ) {
        $next = (int) floor( $current / 2 );
    } else {
        $next = $current;
    }

    return max( IME_REGEN_BATCH_MIN, min( IME_REGEN_BATCH_MAX, $next ) );
}

/** Begin a regeneration run. */
function ime_ajax_regen_start() {
    ime_ajax_require_admin();

    if ( ! ime_mode_valid() ) {
        wp_send_json_error( [ 'message' => __( 'No valid image engine is configured.', 'imagemagick-engine' ) ] );
    }

    $raw_sizes = sanitize_text_field( wp_unslash( $_REQUEST['sizes'] ?? '' ) );
    $sizes     = array_values( array_filter( array_map( 'sanitize_key', explode( '|', $raw_sizes ) ) ) );
    $sizes     = array_values( array_intersect( $sizes, array_keys( ime_available_image_sizes() ) ) );

    if ( empty( $sizes ) ) {
        wp_send_json_error( [ 'message' => __( 'Select at least one image size.', 'imagemagick-engine' ) ] );
    }

    $total = ime_regen_count_images();
    if ( $total < 1 ) {
        wp_send_json_error( [ 'message' => __( 'There are no images to regenerate.', 'imagemagick-engine' ) ] );
    }

    $queue = [
        'id'      => wp_generate_uuid4(),
        'sizes'   => $sizes,
        'force'   => ! empty( $_REQUEST['force'] ),
        'offset'  => 0,
        'total'   => $total,
        'failed'  => [],
        'failed_count' => 0,
        'batch'   => IME_REGEN_BATCH_START,
        'started' => time(),
    ];

    ime_regen_queue_save( $queue );

    wp_send_json_success(
        [
            'run_id' => $queue['id'],
            'total'  => $total,
            'done'   => 0,
            'batch'  => $queue['batch'],
        ]
    );
}

/** Process the next batch. */
function ime_ajax_regen_batch() {
    ime_ajax_require_admin();

    $queue = ime_regen_queue_get();
    if ( null === $queue ) {
        wp_send_json_error(
            [
                'message' => __( 'No regeneration is in progress.', 'imagemagick-engine' ),
                'code'    => 'no_queue',
            ]
        );
    }

    $ids = ime_regen_next_ids( $queue['offset'], $queue['batch'] );

    if ( empty( $ids ) ) {
        ime_regen_queue_clear();
        wp_send_json_success(
            [
                'done'         => $queue['offset'],
                'total'        => $queue['total'],
                'failed'       => $queue['failed'],
                'failed_count' => $queue['failed_count'],
                'batch'        => $queue['batch'],
                'finished'     => true,
            ]
        );
    }

    $started = microtime( true );

    foreach ( $ids as $id ) {
        $result = ime_process_attachment( $id, $queue['sizes'], $queue['force'] );

        if ( is_wp_error( $result ) ) {
            ++$queue['failed_count'];

            if ( count( $queue['failed'] ) < IME_REGEN_FAILED_CAP ) {
                $queue['failed'][] = [
                    'id'    => $id,
                    'title' => get_the_title( $id ),
                    'error' => $result->get_error_message(),
                ];
            }
        }

        ++$queue['offset'];
    }

    $queue['batch'] = ime_regen_next_batch_size( $queue['batch'], microtime( true ) - $started );

    $finished = $queue['offset'] >= $queue['total'];

    if ( $finished ) {
        ime_regen_queue_clear();
    } else {
        $current = ime_regen_queue_get();

        if ( null === $current || $current['id'] !== $queue['id'] ) {
            // Cancelled (or replaced) while this batch was running.
            wp_send_json_success(
                [
                    'done'         => $queue['offset'],
                    'total'        => $queue['total'],
                    'failed'       => $queue['failed'],
                    'failed_count' => $queue['failed_count'],
                    'batch'        => $queue['batch'],
                    'finished'     => true,
                    'cancelled'    => true,
                ]
            );
        }

        ime_regen_queue_save( $queue );
    }

    wp_send_json_success(
        [
            'done'         => $queue['offset'],
            'total'        => $queue['total'],
            'failed'       => $queue['failed'],
            'failed_count' => $queue['failed_count'],
            'batch'        => $queue['batch'],
            'finished'     => $finished,
        ]
    );
}

/** Abandon the current run. */
function ime_ajax_regen_cancel() {
    ime_ajax_require_admin();
    ime_regen_queue_clear();
    wp_send_json_success( [ 'cancelled' => true ] );
}

/** Report whether a run is in progress, for resuming after a page load. */
function ime_ajax_regen_state() {
    ime_ajax_require_admin();

    $queue = ime_regen_queue_get();

    if ( null === $queue ) {
        wp_send_json_success( [ 'running' => false ] );
    }

    wp_send_json_success(
        [
            'running'      => true,
            'run_id'       => $queue['id'],
            'done'         => $queue['offset'],
            'total'        => $queue['total'],
            'failed'       => $queue['failed'],
            'failed_count' => $queue['failed_count'],
            'sizes'        => $queue['sizes'],
            'force'        => (bool) $queue['force'],
        ]
    );
}
