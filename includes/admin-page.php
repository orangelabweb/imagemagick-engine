<?php
/**
 * Admin page rendering.
 *
 * @package imagemagick-engine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit();
}

/* Add admin page */
function ime_admin_menu() {
    global $ime_page;

    $ime_page = add_options_page( 'ImageMagick Engine', 'ImageMagick Engine', 'manage_options', 'imagemagick-engine', 'ime_option_page' );

    $script_pages = [ $ime_page, 'media.php', 'media-new.php', 'media-upload.php', 'media-upload-popup', 'post.php', 'upload.php' ];
    foreach ( $script_pages as $page ) {
        add_action( 'admin_print_scripts-' . $page, 'ime_admin_print_scripts' );
        add_action( 'admin_print_styles-' . $page, 'ime_admin_print_styles' );
    }
}

/* Which admin tab should be active on page load? */
function ime_current_tab() {
    return ( isset( $_GET['tab'] ) && 'regenerate' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ) ? 'regenerate' : 'settings';
}

/* Enqueue admin page scripts */
function ime_admin_print_scripts() {
    global $ime_page;

    wp_enqueue_script( 'ime-admin' );
    wp_enqueue_script( 'ime-alpinejs' );

    $data = [
        'failed'             => '<strong>' . __( 'Failed to resize image!', 'imagemagick-engine' ) . '</strong>',
        'resized'            => __( 'Resized using ImageMagick Engine', 'imagemagick-engine' ),
        'ime_nonce'          => wp_create_nonce('ime-admin-nonce'),
        'ajaxurl'            => admin_url( 'admin-ajax.php' ),
        'initial_tab'        => ime_current_tab(),
        'request_failed'     => __( 'The request failed. Please try again.', 'imagemagick-engine' ),
        'path_found'         => __( 'Command found.', 'imagemagick-engine' ),
        'regen_running'      => __( 'Regenerating images', 'imagemagick-engine' ),
        'regen_paused'       => __( 'Regeneration in progress', 'imagemagick-engine' ),
        /* translators: %d: number of minutes */
        'regen_eta_fmt'      => __( 'about %d min remaining', 'imagemagick-engine' ),
        /* translators: %d: number of images */
        'regen_done_fmt'     => __( 'Finished. Processed %d images.', 'imagemagick-engine' ),
        /* translators: %d: number of images */
        'regen_failed_fmt'   => __( '%d failed', 'imagemagick-engine' ),
    ];

    // Engine detection (ime_mode_valid() for all four engines) is only read by
    // the imeSettings component, which exists only on this plugin's own page.
    // Running it on every media screen forks proc_open() under WP_DEBUG and
    // writes ime_options on every page load.
    if ( get_current_screen() && $ime_page === get_current_screen()->id ) {
        $engine_state    = ime_resolve_engine_state();
        $data['enabled'] = $engine_state['enabled'];
        $data['mode']    = (string) $engine_state['mode'];
    }

    wp_localize_script( 'ime-admin', 'ime_admin', $data );
}

/* Enqueue admin page style */
function ime_admin_print_styles() {
    wp_enqueue_style( 'ime-admin-style', plugins_url( '/css/ime-admin.css', dirname( __DIR__ ) . '/imagemagick-engine.php' ), [], constant( 'IME_VERSION' ) );
}

/* Add settings to plugin action links */
function ime_filter_plugin_actions( $links, $file ) {
    if ( $file == plugin_basename( dirname( __DIR__ ) . '/imagemagick-engine.php' ) ) {
        $settings_link = '<a href="options-general.php?page=imagemagick-engine">'
            . __( 'Settings', 'imagemagick-engine' ) . '</a>';
        array_unshift( $links, $settings_link ); // before other links
    }

    return $links;
}

/*
 * Add admin information if attachment is converted using plugin
 */
function ime_filter_media_meta( $content, $post ) {
    if ( ! ime_mode_valid() ) {
        return $content;
    }

    if ( ! wp_image_editor_supports( [ 'mime_type' => $post->post_mime_type ] ) ) {
        return $content;
    }

    $metadata = wp_get_attachment_metadata( $post->ID );

    $ime = false;
    if ( is_array( $metadata ) && array_key_exists( 'image-converter', $metadata ) ) {
        foreach ( $metadata['image-converter'] as $size => $converter ) {
            if ( $converter != 'IME' ) {
                continue;
            }

            $ime = true;
            break;
        }
    }

    if ( $ime ) {
        $initial_message = __( 'Resized using ImageMagick Engine', 'imagemagick-engine' );
        $resize          = __( 'Resize image', 'imagemagick-engine' );
        $force           = '1';
    } else {
        $initial_message = '';
        $resize          = __( 'Resize using ImageMagick Engine', 'imagemagick-engine' );
        $force           = '0';
    }

    $handle_sizes = ime_get_option( 'handle_sizes' );
    $sizes        = [];
    foreach ( $handle_sizes as $s => $h ) {
        if ( ! $h || 'skip' === $h ) {
            continue;
        }
        $sizes[] = $s;
    }

    $content .= '</p><p>';
    $content .= sprintf(
        '<span class="ime-media-regen" x-data="imeMediaRegen" data-post-id="%1$d" data-sizes="%2$s" data-force="%3$s" data-message="%4$s">'
            . '<button type="button" class="button ime-regen-button" x-on:click="regenerate" :disabled="busy">%5$s</button>'
            . '<span class="spinner" :class="spinnerClass"></span>'
            . '<span class="ime-media-message" x-text="message"></span>'
            . '</span>',
        absint( $post->ID ),
        esc_attr( implode( '|', $sizes ) ),
        esc_attr( $force ),
        esc_attr( $initial_message ),
        esc_html( $resize )
    );

    return $content;
}

// Define available modes
function ime_get_available_modes(): array {
    return array(
        'php'             => __( 'Imagick PHP module', 'imagemagick-engine' ),
        'gmagick'         => __( 'Gmagick PHP module', 'imagemagick-engine' ),
        'cli'             => __( 'ImageMagick command-line', 'imagemagick-engine' ),
        'graphicsmagick'  => __( 'GraphicsMagick command-line', 'imagemagick-engine' ),
    );
}

/**
 * Resolve the engine to use and whether the plugin is effectively enabled.
 *
 * Falls back to the first valid engine when the stored mode is missing or
 * unavailable, so the rendered form and the JavaScript state cannot disagree.
 *
 * @return array{mode: ?string, enabled: bool, valid: array<string, bool>}
 */
function ime_resolve_engine_state() {
    $valid = ime_get_available_modes();
    foreach ( $valid as $m => $ignore ) {
        $valid[ $m ] = ime_mode_valid( $m );
    }

    $mode = ime_get_option( 'mode' );
    if ( ! isset( $valid[ $mode ] ) || ! $valid[ $mode ] ) {
        $mode = null;
    }
    if ( is_null( $mode ) ) {
        foreach ( $valid as $m => $is_valid ) {
            if ( $is_valid ) {
                $mode = $m;
                break;
            }
        }
    }

    $enabled = (bool) ( ime_get_option( 'enabled' ) && $mode );

    return [
        'mode'    => $mode,
        'enabled' => $enabled,
        'valid'   => $valid,
    ];
}

/**
 * Render one engine choice as a selectable card.
 *
 * @param string $mode            Engine key, e.g. 'php'.
 * @param string $label           Human-readable engine name.
 * @param bool   $valid           Whether the engine is usable on this server.
 * @param string $detail          Version string or reason it is unavailable.
 * @param string $current_mode    Currently selected engine key.
 * @param string $path_field_html Optional markup rendered inside the card when selected.
 */
function ime_render_engine_card( $mode, $label, $valid, $detail, $current_mode, $path_field_html = '' ) {
    $id     = 'ime-engine-' . $mode;
    $desc   = $id . '-status';
    $icon   = $valid ? 'dashicons-yes-alt' : 'dashicons-dismiss';
    $state  = $valid
        ? __( 'Available', 'imagemagick-engine' )
        : __( 'Not available', 'imagemagick-engine' );
    $classes = 'ime-engine-card' . ( $valid ? '' : ' ime-engine-card--unavailable' );
    ?>
    <div class="<?php echo esc_attr( $classes ); ?>">
        <label for="<?php echo esc_attr( $id ); ?>">
            <input type="radio" name="mode" id="<?php echo esc_attr( $id ); ?>"
                value="<?php echo esc_attr( $mode ); ?>"
                x-model="mode"
                aria-describedby="<?php echo esc_attr( $desc ); ?>"
                <?php checked( $mode, $current_mode ); ?>
                <?php disabled( ! $valid ); ?> />
            <span class="ime-engine-card__label"><?php echo esc_html( $label ); ?></span>
        </label>
        <p class="ime-engine-card__status" id="<?php echo esc_attr( $desc ); ?>">
            <span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
            <span class="screen-reader-text"><?php echo esc_html( $state ); ?></span>
            <?php echo esc_html( $detail ); ?>
        </p>
        <?php
        if ( '' !== $path_field_html ) {
            echo $path_field_html; // Already escaped by the caller.
        }
        ?>
    </div>
    <?php
}

/**
 * Render the binary path input for a command-line engine.
 *
 * The pass/fail indicator lives on the card's status line, so this renders
 * only the input, its test button, and the test result.
 *
 * @param string $prefix 'cli' or 'gm'.
 * @param string $path   Current stored path.
 */
function ime_render_path_field( $prefix, $path ) {
    $field    = 'cli' === $prefix ? 'cli_path' : 'gm_path';
    $show     = 'cli' === $prefix ? 'isCli' : 'isGraphicsmagick';
    $testing  = 'cli' === $prefix ? 'cliPathTesting' : 'gmPathTesting';
    $error    = 'cli' === $prefix ? 'cliPathError' : 'gmPathError';
    $ok       = 'cli' === $prefix ? 'cliPathOk' : 'gmPathOk';
    $message  = 'cli' === $prefix ? 'cliPathMessage' : 'gmPathMessage';
    $test     = 'cli' === $prefix ? 'testCliPath' : 'testGmPath';
    $describe = $prefix . '-path-help';
    ?>
    <div class="ime-engine-card__path" x-show="<?php echo esc_attr( $show ); ?>" x-cloak>
        <label class="screen-reader-text" for="<?php echo esc_attr( $field ); ?>">
            <?php esc_html_e( 'Path to the binary', 'imagemagick-engine' ); ?>
        </label>
        <input type="text" id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>"
            class="regular-text code" value="<?php echo esc_attr( $path ); ?>"
            aria-describedby="<?php echo esc_attr( $describe ); ?>" />
        <button type="button" class="button button-secondary" x-on:click="<?php echo esc_attr( $test ); ?>">
            <?php esc_html_e( 'Test path', 'imagemagick-engine' ); ?>
        </button>
        <span class="spinner" x-show="<?php echo esc_attr( $testing ); ?>"></span>
        <p class="notice notice-error inline" x-show="<?php echo esc_attr( $error ); ?>" x-cloak
            x-text="<?php echo esc_attr( $message ); ?>"></p>
        <p class="notice notice-success inline" x-show="<?php echo esc_attr( $ok ); ?>" x-cloak
            x-text="<?php echo esc_attr( $message ); ?>"></p>
        <p class="description" id="<?php echo esc_attr( $describe ); ?>">
            <?php esc_html_e( 'Enter the path where the binary is installed on your server. This is usually /usr/bin or /usr/local/bin.', 'imagemagick-engine' ); ?>
        </p>
    </div>
    <?php
}

/* Plugin admin / status page */
function ime_option_page() {
    global $ime_available_quality_modes;

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Sorry, but you do not have permissions to change settings.' );
    }

    /* Make sure post was from this page */
    if ( count( $_POST ) > 0 ) {
        check_admin_referer( 'ime-options' );
    }

    $sizes = ime_available_image_sizes();

    /* Should we update settings? */
    if ( isset( $_POST['update_settings'] ) ) {
        $new_enabled = isset( $_POST['enabled'] ) && ! ! $_POST['enabled'];
        ime_set_option( 'enabled', $new_enabled );
        if ( isset( $_POST['mode'] ) ) {
            $posted_mode = sanitize_key( wp_unslash( $_POST['mode'] ) );
            if ( array_key_exists( $posted_mode, ime_get_available_modes() ) ) {
                ime_set_option( 'mode', $posted_mode );
            }
        }
        if ( isset( $_POST['cli_path'] ) ) {
            ime_set_option( 'cli_path', ime_try_realpath( sanitize_text_field( wp_unslash( $_POST['cli_path'] ) ) ) );
            delete_transient( 'ime_cli_valid' );
        }
        if ( isset( $_POST['gm_path'] ) ) {
            ime_set_option( 'gm_path', ime_try_realpath( sanitize_text_field( wp_unslash( $_POST['gm_path'] ) ) ) );
            delete_transient( 'ime_gm_valid' );
        }

        $new_quality = [
            'quality' => -1,
            'size'    => 70,
        ];
        if ( isset( $_POST['quality-quality'] ) ) {
            if ( is_numeric( $_POST['quality-quality'] ) ) {
                $new_quality['quality'] = min( 100, max( 0, intval( $_POST['quality-quality'] ) ) );
            } elseif ( empty( $_POST['quality-quality'] ) ) {
                $new_quality['quality'] = -1;
            }
        }
        if ( isset( $_POST['quality-size'] ) ) {
            if ( is_numeric( $_POST['quality-size'] ) ) {
                $new_quality['size'] = min( 100, max( 0, intval( $_POST['quality-size'] ) ) );
            } elseif ( empty( $_POST['quality-size'] ) ) {
                $new_quality['size'] = -1;
            }
        }
        ime_set_option( 'quality', $new_quality );

        $new_interlace = isset( $_POST['interlace'] ) && ! ! $_POST['interlace'];
        ime_set_option( 'interlace', $new_interlace );

        $new_keep_exif = isset( $_POST['keep_exif'] ) && ! ! $_POST['keep_exif'];
        ime_set_option( 'keep_exif', $new_keep_exif );

        // Only saved when the setting is shown, so older WordPress versions keep the stored value.
        if ( ime_client_side_processing_available() ) {
            $new_disable_csp = isset( $_POST['disable_client_side_processing'] ) && ! ! $_POST['disable_client_side_processing'];
            ime_set_option( 'disable_client_side_processing', $new_disable_csp );
        }

        $new_handle_sizes = [];
        foreach ( $sizes as $s => $name ) {
            $new_mode = isset( $_POST[ 'handle-mode-' . $s ] ) ? $_POST[ 'handle-mode-' . $s ] : 'skip';
            if ( in_array( $new_mode, $ime_available_quality_modes ) ) {
                $mode = $new_mode;
            } else {
                $mode = 'quality';
            }

            $new_handle_sizes[ $s ] = $mode;
        }
        ime_set_option( 'handle_sizes', $new_handle_sizes );

        ime_store_options();

        wp_admin_notice(
            __( 'Settings updated', 'imagemagick-engine' ),
            [
                'type'        => 'success',
                'dismissible' => true,
            ]
        );
    }

    $engine_state = ime_resolve_engine_state();
    $modes_valid  = $engine_state['valid'];
    $current_mode = $engine_state['mode'];
    $enabled      = $engine_state['enabled'];
    $any_valid    = in_array( true, $modes_valid, true );

    $cli_path = ime_get_option( 'cli_path' );
    if ( is_null( $cli_path ) ) {
        $cli_path = ime_im_cli_command();
    }
    $cli_path_ok = ime_im_cli_check_command( $cli_path );

    $gm_path = ime_get_option( 'gm_path' );
    if ( is_null( $gm_path ) ) {
        $gm_path = ime_im_cli_command( true );
    }
    $gm_path_ok = ime_im_cli_check_command( $gm_path, true );

    $quality = ime_get_option( 'quality' );
    if ( ! is_array( $quality ) ) {
        $n = [
            'quality' => -1,
            'size'    => 70,
        ];
        if ( is_numeric( $quality ) && $quality > 0 ) {
            $n['quality'] = $quality;
        }
        $quality = $n;
    }

    $interlace  = ime_get_option( 'interlace' );
    $keep_exif  = ime_get_option( 'keep_exif' );
    $disable_client_side_processing = ime_get_option( 'disable_client_side_processing' );

    $handle_sizes = ime_get_option( 'handle_sizes' );

    if ( ! $any_valid ) {
        wp_admin_notice(
            __( 'No valid ImageMagick mode found!', 'imagemagick-engine' ),
            [ 'type' => 'error' ]
        );
    } elseif ( ! $enabled ) {
        wp_admin_notice(
            __( 'ImageMagick Engine is not enabled.', 'imagemagick-engine' ),
            [ 'type' => 'warning' ]
        );
    }

    ?>
    <div class="wrap" x-data="imeSettings">
        <h1><?php esc_html_e( 'ImageMagick Engine', 'imagemagick-engine' ); ?></h1>

        <noscript>
            <div class="notice notice-error">
                <p><?php esc_html_e( 'This settings page requires JavaScript. Please enable JavaScript in your browser and reload the page to configure ImageMagick Engine.', 'imagemagick-engine' ); ?></p>
            </div>
        </noscript>

        <nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Secondary menu', 'imagemagick-engine' ); ?>">
            <button type="button" class="nav-tab" :class="settingsTabClass" x-on:click="selectSettingsTab">
                <?php esc_html_e( 'Settings', 'imagemagick-engine' ); ?>
            </button>
            <button type="button" class="nav-tab" :class="regenerateTabClass" x-on:click="selectRegenerateTab">
                <?php esc_html_e( 'Regenerate', 'imagemagick-engine' ); ?>
            </button>
        </nav>

        <div x-show="isTabSettings" x-cloak>
            <form action="options-general.php?page=imagemagick-engine" method="post" name="update_options">
                <?php wp_nonce_field( 'ime-options' ); ?>
                <div id="ime-settings" class="postbox" x-cloak>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th scope="row" valign="top"><?php _e( 'Enable', 'imagemagick-engine' ); ?>:</th>
                                <td>
                                    <input type="checkbox" id="enabled" name="enabled" x-model="enabled"
                                        <?php echo $any_valid ? '' : ' disabled=disabled '; ?>
                                    />
                                </td>
                            </tr>
                            <tbody x-show="enabled">
                                <tr>
                                    <th scope="row" valign="top"><?php _e( 'Image engine', 'imagemagick-engine' ); ?>:</th>
                                    <td>
                                        <fieldset class="ime-engine-grid">
                                            <legend class="screen-reader-text"><?php esc_html_e( 'Image engine', 'imagemagick-engine' ); ?></legend>
                                            <?php
                                            ime_render_engine_card(
                                                'php',
                                                __( 'Imagick PHP module', 'imagemagick-engine' ),
                                                $modes_valid['php'],
                                                $modes_valid['php']
                                                    ? __( 'Module loaded', 'imagemagick-engine' )
                                                    : __( 'Module not found', 'imagemagick-engine' ),
                                                $current_mode
                                            );

                                            ime_render_engine_card(
                                                'gmagick',
                                                __( 'Gmagick PHP module', 'imagemagick-engine' ),
                                                $modes_valid['gmagick'],
                                                $modes_valid['gmagick']
                                                    ? __( 'Module loaded', 'imagemagick-engine' )
                                                    : __( 'Module not found', 'imagemagick-engine' ),
                                                $current_mode
                                            );

                                            ob_start();
                                            ime_render_path_field( 'cli', $cli_path );
                                            $cli_field = ob_get_clean();

                                            ime_render_engine_card(
                                                'cli',
                                                __( 'ImageMagick command-line', 'imagemagick-engine' ),
                                                $modes_valid['cli'],
                                                $cli_path_ok
                                                    ? ime_get_option( 'imagemagick_version' )
                                                    : __( 'Command not found', 'imagemagick-engine' ),
                                                $current_mode,
                                                $cli_field
                                            );

                                            ob_start();
                                            ime_render_path_field( 'gm', $gm_path );
                                            $gm_field = ob_get_clean();

                                            ime_render_engine_card(
                                                'graphicsmagick',
                                                __( 'GraphicsMagick command-line', 'imagemagick-engine' ),
                                                $modes_valid['graphicsmagick'],
                                                $gm_path_ok
                                                    ? ime_get_option( 'graphicsmagick_version' )
                                                    : __( 'Command not found', 'imagemagick-engine' ),
                                                $current_mode,
                                                $gm_field
                                            );
                                            ?>
                                        </fieldset>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="quality-quality"><?php esc_html_e( 'Optimize for quality', 'imagemagick-engine' ); ?></label></th>
                                    <td>
                                        <input type="number" id="quality-quality" name="quality-quality" min="0" max="100" step="1"
                                            class="small-text" placeholder="<?php esc_attr_e( 'auto', 'imagemagick-engine' ); ?>"
                                            value="<?php echo esc_attr( ( isset( $quality['quality'] ) && $quality['quality'] > 0 ) ? $quality['quality'] : '' ); ?>"
                                            aria-describedby="ime-quality-help" />
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="quality-size"><?php esc_html_e( 'Optimize for size', 'imagemagick-engine' ); ?></label></th>
                                    <td>
                                        <input type="number" id="quality-size" name="quality-size" min="0" max="100" step="1"
                                            class="small-text" placeholder="<?php esc_attr_e( 'auto', 'imagemagick-engine' ); ?>"
                                            value="<?php echo esc_attr( ( isset( $quality['size'] ) && $quality['size'] > 0 ) ? $quality['size'] : '' ); ?>"
                                            aria-describedby="ime-quality-help" />
                                        <p class="description" id="ime-quality-help">
                                            <?php
                                            printf(
                                                /* translators: 1: computed quality value, 2: computed size value */
                                                esc_html__( 'Set to 0-100. A higher value means better image quality and a larger file. Leave empty to compute the value dynamically, which currently gives %1$d when optimizing for quality and %2$d when optimizing for size.', 'imagemagick-engine' ),
                                                absint( ime_get_quality( 'quality' ) ),
                                                absint( ime_get_quality( 'size' ) )
                                            );
                                            ?>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row" valign="top"><?php _e( 'Image interlace?', 'imagemagick-engine' ); ?>:</th>
                                    <td>
                                        <input type="checkbox" id="interlace" name="interlace" value="1"
                                            <?php checked( $interlace, true ); ?>
                                        />
                                        <p class="description"><?php _e( 'Adds interlace option to ImageMagick when images are processed.', 'imagemagick-engine' ); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row" valign="top"><?php _e( 'Preserve Exif data?', 'imagemagick-engine' ); ?>:</th>
                                    <td>
                                        <input type="checkbox" id="keep_exif" name="keep_exif" value="1"
                                            <?php checked( $keep_exif, true ); ?>
                                        />
                                        <p class="description"><?php _e( 'When optimizing for size, preserve Exif metadata (including GPS location) instead of stripping it. Other non-essential metadata (IPTC, XMP) is still removed.', 'imagemagick-engine' ); ?></p>
                                    </td>
                                </tr>
                                <?php if ( ime_client_side_processing_available() ) { ?>
                                <tr>
                                    <th scope="row" valign="top"><?php _e( 'Disable client-side media processing?', 'imagemagick-engine' ); ?>:</th>
                                    <td>
                                        <input type="checkbox" id="disable_client_side_processing" name="disable_client_side_processing" value="1"
                                            <?php checked( $disable_client_side_processing, true ); ?>
                                        />
                                        <p class="description"><?php _e( 'WordPress 7.1 and later lets the browser generate image sizes during upload, bypassing ImageMagick for those sizes. Keep this checked so every size is generated on the server with the settings above.', 'imagemagick-engine' ); ?></p>
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                            <tr>
                                <th colspan="2">
                                    <input class="button-primary" type="submit" name="update_settings" value="<?php _e( 'Save Changes', 'imagemagick-engine' ); ?>" />
                                </th>
                            </tr>
                        </table>

                        <div x-show="enabled" x-cloak>
                            <h2><?php esc_html_e( 'Image sizes', 'imagemagick-engine' ); ?></h2>
                            <p class="description"><?php esc_html_e( 'Choose how each image size is generated. Sizes set to None are left to WordPress.', 'imagemagick-engine' ); ?></p>

                            <table class="wp-list-table widefat striped ime-sizes-table">
                                <thead>
                                    <tr>
                                        <th scope="col"><?php esc_html_e( 'Image size', 'imagemagick-engine' ); ?></th>
                                        <th scope="col">
                                            <?php esc_html_e( 'Quality', 'imagemagick-engine' ); ?><br />
                                            <button type="button" class="button-link" x-on:click="setAllQuality"><?php esc_html_e( 'Select all', 'imagemagick-engine' ); ?></button>
                                        </th>
                                        <th scope="col">
                                            <?php esc_html_e( 'Size', 'imagemagick-engine' ); ?><br />
                                            <button type="button" class="button-link" x-on:click="setAllSize"><?php esc_html_e( 'Select all', 'imagemagick-engine' ); ?></button>
                                        </th>
                                        <th scope="col">
                                            <?php esc_html_e( 'None', 'imagemagick-engine' ); ?><br />
                                            <button type="button" class="button-link" x-on:click="setAllSkip"><?php esc_html_e( 'Select all', 'imagemagick-engine' ); ?></button>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                $mode_labels = [
                                    'quality' => __( 'Quality', 'imagemagick-engine' ),
                                    'size'    => __( 'Size', 'imagemagick-engine' ),
                                    'skip'    => __( 'None', 'imagemagick-engine' ),
                                ];
                                foreach ( $sizes as $s => $name ) {
                                    // Fixup for options stored before 1.5.0.
                                    if ( ! isset( $handle_sizes[ $s ] ) || ! $handle_sizes[ $s ] ) {
                                        $handle_sizes[ $s ] = 'skip';
                                    } elseif ( true === $handle_sizes[ $s ] ) {
                                        $handle_sizes[ $s ] = 'quality';
                                    }

                                    $group = 'handle-mode-' . $s;
                                    ?>
                                    <tr>
                                        <th scope="row"><?php echo esc_html( $name ); ?></th>
                                        <?php foreach ( [ 'quality', 'size', 'skip' ] as $value ) { ?>
                                            <td>
                                                <fieldset>
                                                    <legend class="screen-reader-text">
                                                        <?php
                                                        printf(
                                                            /* translators: %s: image size name */
                                                            esc_html__( 'Handling for %s', 'imagemagick-engine' ),
                                                            esc_html( $name )
                                                        );
                                                        ?>
                                                    </legend>
                                                    <label>
                                                        <input type="radio" name="<?php echo esc_attr( $group ); ?>"
                                                            class="ime-handle-mode ime-handle-mode--<?php echo esc_attr( $value ); ?>"
                                                            value="<?php echo esc_attr( $value ); ?>"
                                                            <?php checked( $value, $handle_sizes[ $s ] ); ?> />
                                                        <span class="screen-reader-text"><?php echo esc_html( $mode_labels[ $value ] ); ?></span>
                                                    </label>
                                                </fieldset>
                                            </td>
                                        <?php } ?>
                                    </tr>
                                    <?php
                                }
                                ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <div x-show="isTabRegenerate" x-cloak>
            <div x-data="imeRegen">
                <h2><?php esc_html_e( 'Regenerate images', 'imagemagick-engine' ); ?></h2>

                <?php if ( ! ime_active() ) { ?>
                    <div class="notice notice-warning inline">
                        <p><?php esc_html_e( 'ImageMagick Engine is not active, so resizing will use standard WordPress functions.', 'imagemagick-engine' ); ?></p>
                    </div>
                <?php } ?>

                <div x-show="isIdle" x-cloak>
                    <fieldset class="ime-regen-sizes">
                        <legend><?php esc_html_e( 'Sizes', 'imagemagick-engine' ); ?></legend>
                        <?php
                        foreach ( $sizes as $s => $name ) {
                            $checked = isset( $handle_sizes[ $s ] ) && 'skip' !== $handle_sizes[ $s ] && $handle_sizes[ $s ];
                            ?>
                            <label>
                                <input type="checkbox" class="ime-regen-size" value="<?php echo esc_attr( $s ); ?>"
                                    data-default="<?php echo $checked ? '1' : '0'; ?>"
                                    <?php checked( $checked ); ?> />
                                <?php echo esc_html( $name ); ?>
                            </label>
                        <?php } ?>
                        <p>
                            <button type="button" class="button-link" x-on:click="selectAllSizes"><?php esc_html_e( 'All', 'imagemagick-engine' ); ?></button> ·
                            <button type="button" class="button-link" x-on:click="selectNoSizes"><?php esc_html_e( 'None', 'imagemagick-engine' ); ?></button> ·
                            <button type="button" class="button-link" x-on:click="selectDefaultSizes"><?php esc_html_e( 'Match settings', 'imagemagick-engine' ); ?></button>
                        </p>
                    </fieldset>

                    <p>
                        <label>
                            <input type="checkbox" id="ime-regen-force" x-model="force" />
                            <?php esc_html_e( 'Also regenerate images already handled by ImageMagick Engine', 'imagemagick-engine' ); ?>
                        </label>
                    </p>

                    <p>
                        <button type="button" class="button button-primary" x-on:click="start" :disabled="starting"><?php esc_html_e( 'Start regeneration', 'imagemagick-engine' ); ?></button>
                    </p>
                    <p class="description"><?php esc_html_e( 'This can take a long time.', 'imagemagick-engine' ); ?></p>
                </div>

                <div x-show="isRunning" x-cloak>
                    <p><strong x-text="headingText"></strong></p>
                    <progress class="ime-progress" max="100" :value="percent"></progress>
                    <p class="ime-regen-status" aria-live="polite" x-text="statusText"></p>
                    <p>
                        <button type="button" class="button button-primary" x-show="isPaused" x-cloak x-on:click="resume"><?php esc_html_e( 'Resume', 'imagemagick-engine' ); ?></button>
                        <button type="button" class="button" x-on:click="cancel"><?php esc_html_e( 'Cancel', 'imagemagick-engine' ); ?></button>
                    </p>
                </div>

                <div x-show="isDone" x-cloak>
                    <div class="notice notice-success inline"><p x-text="doneText"></p></div>
                </div>

                <div class="notice notice-error inline" x-show="hasError" x-cloak>
                    <p x-text="errorMessage"></p>
                </div>

                <div x-show="hasFailures" x-cloak>
                    <div class="notice notice-warning inline">
                        <p x-text="failedText"></p>
                        <ul class="ime-regen-failures">
                            <template x-for="item in failed" :key="item.id">
                                <li><span x-text="item.title"></span> — <span x-text="item.error"></span></li>
                            </template>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    <?php
}
