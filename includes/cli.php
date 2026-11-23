<?php
/**
 * WP-CLI command: wp ime regenerate.
 *
 * @package imagemagick-engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit();
}

/**
 * Sizes for a run: the requested ones, or every size the plugin handles.
 *
 * @param string|null $requested Comma-separated size names.
 * @return string[]|WP_Error
 */
function ime_cli_resolve_sizes( $requested ) {
    $available = array_keys( ime_available_image_sizes() );

    if ( null === $requested ) {
        $handled = array_filter( (array) ime_get_option( 'handle_sizes' ), static function ( $mode ) {
            return $mode && 'skip' !== $mode;
        } );

        return array_values( array_intersect( array_keys( $handled ), $available ) );
    }

    $sizes   = array_values( array_filter( array_map( 'trim', explode( ',', $requested ) ) ) );
    $unknown = array_diff( $sizes, $available );

    if ( ! empty( $unknown ) ) {
        /* translators: %s: comma-separated list of image size names */
        return new WP_Error( 'ime_unknown_size', sprintf( __( 'Unknown image size: %s', 'imagemagick-engine' ), implode( ', ', $unknown ) ) );
    }

    return $sizes;
}

/**
 * Check that the given IDs are images this plugin can regenerate.
 *
 * @param string[] $args IDs from the command line.
 * @return int[]|WP_Error
 */
function ime_cli_validate_ids( $args ) {
    $ids = array_map( 'intval', $args );

    foreach ( $ids as $id ) {
        $mime = get_post_type( $id ) === 'attachment' ? (string) get_post_mime_type( $id ) : '';

        if ( 0 !== strpos( $mime, 'image/' ) || 'image/svg+xml' === $mime ) {
            /* translators: %d: attachment ID */
            return new WP_Error( 'ime_not_an_image', sprintf( __( 'ID %d is not an image attachment.', 'imagemagick-engine' ), $id ) );
        }
    }

    return $ids;
}

/**
 * Walk every image attachment without loading all IDs at once.
 *
 * @param int $page_size IDs fetched per query.
 * @return Generator<int>
 */
function ime_cli_all_image_ids( $page_size = 200 ) {
    $offset = 0;

    do {
        $ids = ime_regen_next_ids( $offset, $page_size );

        foreach ( $ids as $id ) {
            yield $id;
        }

        $offset += $page_size;
    } while ( count( $ids ) === $page_size );
}

/**
 * Regenerate images, carrying on past failures.
 *
 * @param iterable<int>                       $ids      Attachment IDs.
 * @param string[]                            $sizes    Size names.
 * @param bool                                $force    Regenerate sizes already handled by this plugin.
 * @param callable(int, true|WP_Error): void  $progress Called after each image.
 * @return array{done: int, failed: array<int, string>}
 */
function ime_cli_regenerate( $ids, $sizes, $force, $progress = null ) {
    $done   = 0;
    $failed = [];

    foreach ( $ids as $id ) {
        $result = ime_process_attachment( $id, $sizes, $force );

        if ( is_wp_error( $result ) ) {
            $failed[ $id ] = $result->get_error_message();
        } else {
            $done++;
        }

        if ( $progress ) {
            $progress( $id, $result );
        }

        // A long run would otherwise grow the object cache until memory runs out.
        if ( ( $done + count( $failed ) ) % 50 === 0 ) {
            wp_cache_flush_runtime();
        }
    }

    return [
        'done'   => $done,
        'failed' => $failed,
    ];
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    /**
     * Regenerate image sizes with the configured engine.
     *
     * ## OPTIONS
     *
     * [<id>...]
     * : Attachment IDs. Defaults to every image.
     *
     * [--sizes=<sizes>]
     * : Comma-separated size names. Defaults to the sizes the plugin handles.
     *
     * [--force]
     * : Also regenerate sizes this plugin has already produced.
     *
     * [--dry-run]
     * : Show what would be regenerated without changing anything.
     *
     * ## EXAMPLES
     *
     *     wp ime regenerate --force
     *     wp ime regenerate 12 34 --sizes=thumbnail,medium --force
     *
     * @when after_wp_load
     *
     * @param string[]             $args       Attachment IDs.
     * @param array<string, mixed> $assoc_args Flags.
     */
    $ime_cli_command = static function ( $args, $assoc_args ) {
        if ( ! ime_mode_valid() ) {
            WP_CLI::error( __( 'No valid image engine is configured.', 'imagemagick-engine' ) );
        }

        $sizes = ime_cli_resolve_sizes( $assoc_args['sizes'] ?? null );
        if ( is_wp_error( $sizes ) ) {
            WP_CLI::error( $sizes->get_error_message() );
        }
        if ( empty( $sizes ) ) {
            WP_CLI::error( __( 'Select at least one image size.', 'imagemagick-engine' ) );
        }

        if ( empty( $args ) ) {
            $ids   = ime_cli_all_image_ids();
            $total = ime_regen_count_images();
        } else {
            $ids = ime_cli_validate_ids( $args );
            if ( is_wp_error( $ids ) ) {
                WP_CLI::error( $ids->get_error_message() );
            }
            $total = count( $ids );
        }

        if ( $total < 1 ) {
            WP_CLI::warning( __( 'There are no images to regenerate.', 'imagemagick-engine' ) );
            return;
        }

        if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ) ) {
            WP_CLI::success( sprintf( 'Would regenerate %d image(s), sizes: %s', $total, implode( ', ', $sizes ) ) );
            return;
        }

        $bar    = WP_CLI\Utils\make_progress_bar( 'Regenerating', $total );
        $result = ime_cli_regenerate(
            $ids,
            $sizes,
            (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false ),
            static function () use ( $bar ) {
                $bar->tick();
            }
        );
        $bar->finish();

        foreach ( $result['failed'] as $id => $message ) {
            WP_CLI::warning( sprintf( 'ID %d: %s', $id, $message ) );
        }

        if ( ! empty( $result['failed'] ) ) {
            WP_CLI::error( sprintf( 'Regenerated %d image(s), %d failed.', $result['done'], count( $result['failed'] ) ) );
        }

        WP_CLI::success( sprintf( 'Regenerated %d image(s).', $result['done'] ) );
    };

    WP_CLI::add_command( 'ime regenerate', $ime_cli_command );
}
