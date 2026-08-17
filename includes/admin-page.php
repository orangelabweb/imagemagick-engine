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
    $ime_page = add_options_page( 'ImageMagick Engine', 'ImageMagick Engine', 'manage_options', 'imagemagick-engine', 'ime_option_page' );

    $script_pages = [ $ime_page, 'media.php', 'media-new.php', 'media-upload.php', 'media-upload-popup', 'post.php', 'upload.php' ];
    foreach ( $script_pages as $page ) {
        add_action( 'admin_print_scripts-' . $page, 'ime_admin_print_scripts' );
        add_action( 'admin_print_styles-' . $page, 'ime_admin_print_styles' );
    }
    add_action( 'admin_print_scripts-' . $ime_page, function() {
        wp_enqueue_script( 'alpinejs' );
    } );
}

/* Enqueue admin page scripts */
function ime_admin_print_scripts() {
    wp_enqueue_script( 'ime-admin' );

    $data = [
        'noimg'              => __( 'You dont have any images to regenerate', 'imagemagick-engine' ),
        'done'               => __( 'All done!', 'imagemagick-engine' ),
        'processed_fmt'      => __( 'Processed %d images', 'imagemagick-engine' ),
        'failed'             => '<strong>' . __( 'Failed to resize image!', 'imagemagick-engine' ) . '</strong>',
        'resized'            => __( 'Resized using ImageMagick Engine', 'imagemagick-engine' ),
        'ime_nonce'          => wp_create_nonce('ime-admin-nonce'),
        'path_not_found'     => __( '%s not found at this path.', 'imagemagick-engine' ),
        'path_open_basedir'  => __( '%s not found. Your PHP open_basedir setting is restricting access to this path. Add the path to your open_basedir configuration.', 'imagemagick-engine' ),
    ];
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

    $content .= '</p><p>';
    if ( $ime ) {
        $message = ' <div class="ime-media-message" id="ime-message-' . $post->ID . '">' . __( 'Resized using ImageMagick Engine', 'imagemagick-engine' ) . '</div>';
        $resize  = __( 'Resize image', 'imagemagick-engine' );
        $force   = '1';
    } else {
        $message = '<div class="ime-media-message" id="ime-message-' . $post->ID . '" style="display: none;"></div>';
        $resize  = __( 'Resize using ImageMagick Engine', 'imagemagick-engine' );
        $force   = '0';
    }
    $handle_sizes = ime_get_option( 'handle_sizes' );
    $sizes        = [];
    foreach ( $handle_sizes as $s => $h ) {
        if ( ! $h ) {
            continue;
        }
        $sizes[] = $s;
    }
    $sizes    = implode( '|', $sizes );
    $content .= '<a href="#" id="ime-regen-link-' . absint( $post->ID ) . '" class="button ime-regen-button"'
        . ' data-post-id="' . absint( $post->ID ) . '"'
        . ' data-sizes="' . esc_attr( $sizes ) . '"'
        . ' data-force="' . esc_attr( $force ) . '">'
        . esc_html( $resize ) . '</a> '
        . $message
        . ' <div id="ime-spinner-' . absint( $post->ID ) . '" class="ime-spinner"><img src="' . esc_url( admin_url( 'images/wpspin_light.gif' ) ) . '" alt="" /></div>';

    return $content;
}

// Url to admin images
function ime_option_admin_images_url() {
    return get_bloginfo( 'wpurl' ) . '/wp-admin/images/';
}

// Url to status icon
function ime_option_status_icon( $yes = true ) {

    return ime_option_admin_images_url() . ( $yes ? 'yes' : 'no' ) . '.png';
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

// Display, or not
function ime_option_display( $display = true, $echo = true ) {
    if ( $display ) {
        return '';
    }
    $s = ' style="display: none" ';
    if ( $echo ) {
        echo $s;
    }
    return $s;
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

    if ( isset( $_POST['regenerate-images'] ) ) {
        ime_show_regenerate_images( array_keys( $sizes ) );
        return;
    }

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

        echo '<div id="message" class="updated fade"><p>'
            . __( 'Settings updated', 'imagemagick-engine' )
            . '</p></div>';
    }

    $modes_valid = ime_get_available_modes();
    $any_valid   = false;
    foreach ( $modes_valid as $m => $ignore ) {
        $modes_valid[ $m ] = ime_mode_valid( $m );
        if ( $modes_valid[ $m ] ) {
            $any_valid = true;
        }
    }

    $current_mode = ime_get_option( 'mode' );
    if ( ! isset( $modes_valid[ $current_mode ] ) || ! $modes_valid[ $current_mode ] ) {
        $current_mode = null;
    }
    if ( is_null( $current_mode ) && $any_valid ) {
        foreach ( $modes_valid as $m => $valid ) {
            if ( $valid ) {
                $current_mode = $m;
                break;
            }
        }
    }

    $enabled = ime_get_option( 'enabled' ) && $current_mode;

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
        echo '<div id="warning" class="error"><p>'
            . __( 'No valid ImageMagick mode found!', 'imagemagick-engine' )
            . '</p></div>';
    } elseif ( ! $enabled ) {
        echo '<div id="warning" class="error"><p>'
            . __( 'ImageMagick Engine is not enabled.', 'imagemagick-engine' )
            . '</p></div>';
    }
    ?>
    <div class="wrap">
        <div id="regen-message" class="hidden updated fade"></div>
        <h2><?php _e( 'ImageMagick Engine Settings', 'imagemagick-engine' ); ?></h2>
        <div id="ime-regeneration" title="<?php _e( 'Regenerating images', 'imagemagick-engine' ); ?>...">
            <noscript><p><em><?php _e( 'You must enable Javascript in order to proceed!', 'imagemagick-engine' ); ?></em></p></noscript>
            <p><strong><?php _e( 'Regenerating images', 'imagemagick-engine' ); ?>...</strong></p>
            <div id="ime-regenbar">
                <div id="ime-regenbar-percent"></div>
            </div>
        </div>

        <form action="options-general.php?page=imagemagick-engine" method="post" name="update_options" x-data="ime">
            <?php wp_nonce_field( 'ime-options' ); ?>
            <div id="poststuff" class="metabox-holder has-right-sidebar">
                <div class="inner-sidebar">
                    <div class="meta-box-sortables ui-sortable">
                        <div id="regenerate-images-metabox" class="postbox">
                            <h3 class="hndle"><span><?php _e( 'Regenerate Images', 'imagemagick-engine' ); ?></span></h3>
                            <div class="inside">
                                <div class="submitbox">
                                    <table border=0>
                                        <tr>
                                            <td scope="row" valign="top" style="padding-right: 20px;"><?php _e( 'Sizes', 'imagemagick-engine' ); ?>:</td>
                                            <td>
                                                <?php
                                                foreach ( $sizes as $s => $name ) {
                                                    $checked = ( isset( $handle_sizes[ $s ] ) && $handle_sizes[ $s ] != 'skip' && $handle_sizes[ $s ] != false ) ? ' checked="checked" ' : '';
                                                    echo '<input type="checkbox" name="regen-size-' . esc_attr( $s ) . '" value="1" ' . $checked . ' /> ' . esc_html( $name ) . '<br />';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    </table>
                                    <p><?php _e( 'ImageMagick images too', 'imagemagick-engine' ); ?>:
                                        <input type="checkbox" name="force" id="force" value="1" /></p>
                                    <?php
                                    if ( ! ime_active() ) {
                                        echo '<p class="howto">' . __( 'Resize will use standard WordPress functions.', 'imagemagick-engine' ) . '</p>';
                                    }
                                    ?>
                                    <p><input class="button-primary" type="button" id="regenerate-images" value="<?php _e( 'Regenerate', 'imagemagick-engine' ); ?>" /></p>
                                    <p class="description"><?php _e( '(this can take a long time)', 'imagemagick-engine' ); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="post-body">
                    <div id="post-body-content">
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
                                                <select id="ime-select-mode" name="mode" x-model="mode">
                                                    <?php
                                                    foreach ( $modes_valid as $m => $valid ) {
                                                        echo '<option value="' . esc_attr( $m ) . '"';
                                                        if ( $m === $current_mode ) {
                                                            echo ' selected=selected ';
                                                        }
                                                        echo '>' . esc_html( ime_get_available_modes()[ $m ] ) . '</option>';
                                                    }
                                                    ?>
                                                </select>
                                            </td>
                                        </tr>
                                        <tr id="ime-row-php" x-show="mode === 'php'">
                                            <th scope="row" valign="top"><?php _e( 'Imagick PHP module', 'imagemagick-engine' ); ?>:</th>
                                            <td>
                                                <img src="<?php echo esc_url( ime_option_status_icon( $modes_valid['php'] ) ); ?>" alt="" />
                                                <?php echo $modes_valid['php'] ? esc_html__( 'Imagick PHP module found', 'imagemagick-engine' ) : esc_html__( 'Imagick PHP module not found', 'imagemagick-engine' ); ?>
                                            </td>
                                        </tr>
                                        <tr id="ime-row-gmagick" x-show="mode === 'gmagick'">
                                            <th scope="row" valign="top"><?php _e( 'Gmagick PHP module', 'imagemagick-engine' ); ?>:</th>
                                            <td>
                                                <img src="<?php echo esc_url( ime_option_status_icon( $modes_valid['gmagick'] ) ); ?>" alt="" />
                                                <?php echo $modes_valid['gmagick'] ? esc_html__( 'Gmagick PHP module found', 'imagemagick-engine' ) : esc_html__( 'Gmagick PHP module not found', 'imagemagick-engine' ); ?>
                                            </td>
                                        </tr>
                                        <tr id="ime-row-cli" x-show="mode === 'cli'">
                                            <th scope="row" valign="top"><?php _e( 'ImageMagick path', 'imagemagick-engine' ); ?>:</th>
                                            <td>
                                                <img id="cli_path_yes" class="cli_path_icon" src="<?php echo esc_url( ime_option_status_icon( true ) ); ?>" alt="" <?php ime_option_display( $cli_path_ok ); ?> />
                                                <img id="cli_path_no" class="cli_path_icon" src="<?php echo esc_url( ime_option_status_icon( false ) ); ?>" alt="<?php esc_attr_e( 'Command not found', 'imagemagick-engine' ); ?>"  <?php ime_option_display( ! $cli_path_ok ); ?> />
                                                <img id="cli_path_progress" src="<?php echo esc_url( ime_option_admin_images_url() . 'wpspin_light.gif' ); ?>" alt="<?php esc_attr_e( 'Testing command...', 'imagemagick-engine' ); ?>"  <?php ime_option_display( false ); ?> />
                                                <input id="cli_path" type="text" name="cli_path" size="<?php echo absint( max( 30, strlen( $cli_path ) + 5 ) ); ?>" value="<?php echo esc_attr( $cli_path ); ?>" />
                                                <input type="button" name="ime_cli_path_test" id="ime_cli_path_test" value="<?php esc_attr_e( 'Test path', 'imagemagick-engine' ); ?>" class="button-secondary" />
                                                <span <?php ime_option_display( $cli_path_ok ); ?>><br><br><?php if ( ime_get_option( 'imagemagick_version' ) ) { echo 'ImageMagick version ' . esc_html( ime_get_option( 'imagemagick_version' ) ); } ?></span>
                                                <p id="cli_path_error" class="ime-path-error" style="display:none;"></p>
                                                <?php if ($current_mode !== 'cli') { ?><p class="ime-description"><?php _e( 'Enter the path where ImageMagick is installed on your server. This is usually /usr/bin or /usr/local/bin.', 'imagemagick-engine' ); ?></p><?php } ?>
                                            </td>
                                        </tr>
                                        <tr id="ime-row-graphicsmagick" x-show="mode === 'graphicsmagick'">
                                            <th scope="row" valign="top"><?php _e( 'GraphicsMagick path', 'imagemagick-engine' ); ?>:</th>
                                            <td>
                                                <img id="gm_path_yes" class="gm_path_icon" src="<?php echo esc_url( ime_option_status_icon( true ) ); ?>" alt="" <?php ime_option_display( $gm_path_ok ); ?> />
                                                <img id="gm_path_no" class="gm_path_icon" src="<?php echo esc_url( ime_option_status_icon( false ) ); ?>" alt="<?php esc_attr_e( 'Command not found', 'imagemagick-engine' ); ?>"  <?php ime_option_display( ! $gm_path_ok ); ?> />
                                                <img id="gm_path_progress" src="<?php echo esc_url( ime_option_admin_images_url() . 'wpspin_light.gif' ); ?>" alt="<?php esc_attr_e( 'Testing command...', 'imagemagick-engine' ); ?>"  <?php ime_option_display( false ); ?> />
                                                <input id="gm_path" type="text" name="gm_path" size="<?php echo absint( max( 30, strlen( $gm_path ) + 5 ) ); ?>" value="<?php echo esc_attr( $gm_path ); ?>" />
                                                <input type="button" name="ime_gm_path_test" id="ime_gm_path_test" value="<?php esc_attr_e( 'Test path', 'imagemagick-engine' ); ?>" class="button-secondary" />
                                                <span <?php ime_option_display( $gm_path_ok ); ?>><br><br><?php if ( ime_get_option( 'graphicsmagick_version' ) ) { echo 'GraphicsMagick version ' . esc_html( ime_get_option( 'graphicsmagick_version' ) ); } ?></span>
                                                <p id="gm_path_error" class="ime-path-error" style="display:none;"></p>
                                                <?php if ($current_mode !== 'graphicsmagick') { ?><p class="ime-description"><?php _e( 'Enter the path where GraphicsMagick is installed on your server. This is usually /usr/bin or /usr/local/bin.', 'imagemagick-engine' ); ?></p><?php } ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row" valign="top"><?php _e( 'ImageMagick quality', 'imagemagick-engine' ); ?>:</th>
                                            <td>
                                                <p><input id="quality-quality" type="text" name="quality-quality" size="3" value="<?php echo esc_attr( ( isset( $quality['quality'] ) && $quality['quality'] > 0 ) ? $quality['quality'] : '' ); ?>" /> <?php _e( 'Optimize for quality', 'imagemagick-engine' ); ?></p>
                                                <p><input id="quality-size" type="text" name="quality-size" size="3" value="<?php echo esc_attr( ( isset( $quality['size'] ) && $quality['size'] > 0 ) ? $quality['size'] : '' ); ?>" /> <?php _e( 'Optimize for size', 'imagemagick-engine' ); ?></p>
                                                <p class="ime-description"><?php _e( 'Set to 0-100. Higher value gives better image quality but larger file size. Leave empty for default value, computed dynamically.', 'imagemagick-engine' ); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row" valign="top"><?php _e( 'Image interlace?', 'imagemagick-engine' ); ?>:</th>
                                            <td>
                                                <input type="checkbox" id="interlace" name="interlace" value="1"
                                                    <?php checked( $interlace, true ); ?>
                                                />
                                                <p class="ime-description"><?php _e( 'Adds interlace option to ImageMagick when images are processed.', 'imagemagick-engine' ); ?></p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row" valign="top"><?php _e( 'Preserve Exif data?', 'imagemagick-engine' ); ?>:</th>
                                            <td>
                                                <input type="checkbox" id="keep_exif" name="keep_exif" value="1"
                                                    <?php checked( $keep_exif, true ); ?>
                                                />
                                                <p class="ime-description"><?php _e( 'When optimizing for size, preserve Exif metadata (including GPS location) instead of stripping it. Other non-essential metadata (IPTC, XMP) is still removed.', 'imagemagick-engine' ); ?></p>
                                            </td>
                                        </tr>
                                        <?php if ( ime_client_side_processing_available() ) { ?>
                                        <tr>
                                            <th scope="row" valign="top"><?php _e( 'Disable client-side media processing?', 'imagemagick-engine' ); ?>:</th>
                                            <td>
                                                <input type="checkbox" id="disable_client_side_processing" name="disable_client_side_processing" value="1"
                                                    <?php checked( $disable_client_side_processing, true ); ?>
                                                />
                                                <p class="ime-description"><?php _e( 'WordPress 7.1 and later lets the browser generate image sizes during upload, bypassing ImageMagick for those sizes. Keep this checked so every size is generated on the server with the settings above.', 'imagemagick-engine' ); ?></p>
                                            </td>
                                        </tr>
                                        <?php } ?>
                                        <tr>
                                            <td colspan="2" class="ime-handle-table-wrapper">
                                                <table border='0' class="ime-handle-table" id="ime-handle-table">
                                                    <tr>
                                                        <th scope="row" class="ime-headline" valign="top"><strong><?php _e( 'Image size', 'imagemagick-engine' ); ?></strong></th>
                                                        <td class="ime-headline ime-fixed-width"><?php _e( 'Quality', 'imagemagick-engine' ); ?></td>
                                                        <td class="ime-headline ime-fixed-width"><?php _e( 'Size', 'imagemagick-engine' ); ?></td>
                                                        <td class="ime-headline"><?php _e( 'None (use WP instead)', 'imagemagick-engine' ); ?></td>
                                                    </tr>
                                                    <?php
                                                    foreach ( $sizes as $s => $name ) {
                                                        // fixup for old (pre 1.5.0) options
                                                        if ( ! isset( $handle_sizes[ $s ] ) || ! $handle_sizes[ $s ] ) {
                                                            $handle_sizes[ $s ] = 'skip';
                                                        } elseif ( $handle_sizes[ $s ] === true ) {
                                                            $handle_sizes[ $s ] = 'quality';
                                                        }
                                                        ?>
                                                        <tr>
                                                            <th scope="row" valign="top"><?php echo esc_html( $name ); ?></th>
                                                            <td class="ime-fixed-width">
                                                                <input type="radio" name="handle-mode-<?php echo esc_attr( $s ); ?>" value="quality" <?php checked( 'quality', $handle_sizes[ $s ] ); ?> />
                                                            </td>
                                                            <td class="ime-fixed-width">
                                                                <input type="radio" name="handle-mode-<?php echo esc_attr( $s ); ?>" value="size" <?php checked( 'size', $handle_sizes[ $s ] ); ?> />
                                                            </td>
                                                            <td>
                                                                <input type="radio" name="handle-mode-<?php echo esc_attr( $s ); ?>" value="skip" <?php checked( 'skip', $handle_sizes[ $s ] ); ?> />
                                                            </td>
                                                        </tr>
                                                        <?php
                                                    }
                                                    ?>
                                                </table>
                                            </td>
                                        </tr>
                                    </tbody>
                                    <tr>
                                        <th colspan="2">
                                            <input class="button-primary" type="submit" name="update_settings" value="<?php _e( 'Save Changes', 'imagemagick-engine' ); ?>" />
                                        </th>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
        </form>
    </div>
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('ime', () => ({
                enabled: <?php echo $enabled ? 'true' : 'false'; ?>,
                mode: '<?php echo esc_js( $current_mode ); ?>'
            }))
        })
    </script>
    <?php
}
