<?php
/*
	Plugin Name: ImageMagick Engine
	Plugin URI: https://wordpress.org/plugins/imagemagick-engine/
	Description: Improve the quality of re-sized images by replacing standard GD library with ImageMagick
	Author: Orangelab
	Author URI: https://orangelab.com/
	Version: 1.8.0
	Text Domain: imagemagick-engine
	License: GPLv2 or later

	Copyright @ 2026 Orangelab AB

	Licenced under the GNU GPL:

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit();
}

/*
 * Constants
 */
define( 'IME_OPTION_VERSION', 1 );
define( 'IME_VERSION', '1.8.0' );

/*
 * Global variables
 */

// Plugin options default values -- change on plugin admin page
global $ime_options_default;
$ime_options_default = [
    'enabled'      => false,
    'mode'         => null,
    'cli_path'     => null,
    'gm_path'      => null,
    'handle_sizes' => [
        'thumbnail'    => 'size',
        'medium'       => 'quality',
        'medium_large' => 'quality',
        'large'        => 'quality',
    ],
    'quality'      => [
        'quality' => -1,
        'size'    => 70,
    ],
    'interlace'    => false,
    'keep_exif'    => false,
    'version'      => constant( 'IME_OPTION_VERSION' ),
];

// Available quality modes
$ime_available_quality_modes = [ 'quality', 'size', 'skip' ];

// Current options
$ime_options = null;

// Keep track of attachment file & sizes between different filters
$ime_image_sizes = null;
$ime_image_file  = null;

/*
 * Functions
 */
add_action( 'plugins_loaded', 'ime_init_early' );
add_action( 'init', 'ime_init' );
register_uninstall_hook( __FILE__, 'ime_uninstall' );

/* Plugin setup (early) */
function ime_init_early() {
    load_plugin_textdomain( 'imagemagick-engine', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    if ( ime_active() ) {
        add_filter( 'intermediate_image_sizes_advanced', 'ime_filter_image_sizes', 99, 1 );
        add_filter( 'wp_read_image_metadata', 'ime_filter_read_image_metadata', 10, 3 );
        add_filter( 'wp_generate_attachment_metadata', 'ime_filter_attachment_metadata', 10, 2 );
    }
}

/* Plugin setup */
function ime_init() {
    if ( is_admin() ) {
        add_action( 'admin_menu', 'ime_admin_menu' );
        add_filter( 'plugin_action_links', 'ime_filter_plugin_actions', 10, 2 );
        add_filter( 'media_meta', 'ime_filter_media_meta', 10, 2 );

        add_action( 'wp_ajax_ime_test_im_path', 'ime_ajax_test_im_path' );
        add_action( 'wp_ajax_ime_process_image', 'ime_ajax_process_image' );
        add_action( 'wp_ajax_ime_regeneration_get_images', 'ime_ajax_regeneration_get_images' );

        wp_register_script( 'alpinejs', 'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js', [], '3.15.9', true );
        wp_register_script( 'ime-admin', plugins_url( '/js/ime-admin.js', __FILE__ ), [ 'jquery', 'jquery-ui-progressbar' ], constant('IME_VERSION'), true );
    }
}

/* Remove all plugin data on uninstall */
function ime_uninstall() {
    delete_option( 'ime_options' );
    delete_transient( 'ime_cli_valid' );
    delete_transient( 'ime_gm_valid' );
}

/* Are we enabled with valid mode? */
function ime_active() {
    return ime_get_option( 'enabled' ) && ime_mode_valid();
}

/* Check if mode is valid */
function ime_mode_valid( $mode = null ) {
    if ( empty( $mode ) ) {
        $mode = ime_get_option( 'mode' );
    }
    $fn = 'ime_im_' . $mode . '_valid';
    return ( ! empty( $mode ) && function_exists( $fn ) && call_user_func( $fn ) );
}

// Check version of a registered WordPress script
function ime_script_version_compare( $handle, $version, $compare = '>=' ) {
    global $wp_scripts;
    if ( ! is_a( $wp_scripts, 'WP_Scripts' ) ) {
        $wp_scripts = new WP_Scripts();
    }

    $query = $wp_scripts->query( $handle, 'registered' );
    if ( ! $query ) {
        return false;
    }

    return version_compare( $query->ver, $version, $compare );
}

// Get array of available image sizes
function ime_available_image_sizes() {
    global $_wp_additional_image_sizes;
    $sizes = [
        'thumbnail'    => __( 'Thumbnail' ),
        'medium'       => __( 'Medium' ),
        'medium_large' => __( 'Medium Large' ),
        'large'        => __( 'Large' ),
    ]; // Standard sizes
    if ( isset( $_wp_additional_image_sizes ) && count( $_wp_additional_image_sizes ) ) {
        foreach ( $_wp_additional_image_sizes as $name => $spec ) {
            $sizes[ $name ] = $name;
        }
    }

    return $sizes;
}



/*
 * Plugin option handling
 */

// Setup plugin options
function ime_setup_options() {
    global $ime_options;

    // Already setup?
    if ( is_array( $ime_options ) ) {
        return;
    }

    $ime_options = get_option( 'ime_options' );

    // No stored options yet?
    if ( ! is_array( $ime_options ) ) {
        global $ime_options_default;
        $ime_options = $ime_options_default ?? array();
    }

    // Do we need to upgrade options?
    if ( ! array_key_exists( 'version', $ime_options )
        || $ime_options['version'] < constant( 'IME_OPTION_VERSION' ) ) {

        /*
         * Future compatability code goes here!
         */

        $ime_options['version'] = constant( 'IME_OPTION_VERSION' );
        ime_store_options();
    }
}

// Store plugin options
function ime_store_options() {
    global $ime_options;

    ime_setup_options();

    $stored_options = get_option( 'ime_options' );

    if ( $stored_options === false ) {
        add_option( 'ime_options', $ime_options, null, false );
    } else {
        update_option( 'ime_options', $ime_options );
    }
}

// Get plugin option
function ime_get_option( $option_name, $default = null ) {
    ime_setup_options();

    global $ime_options, $ime_options_default;

    if ( is_array( $ime_options ) && array_key_exists( $option_name, $ime_options ) ) {
        return $ime_options[ $option_name ];
    }

    if ( ! is_null( $default ) ) {
        return $default;
    }

    if ( is_array( $ime_options_default ) && array_key_exists( $option_name, $ime_options_default ) ) {
        return $ime_options_default[ $option_name ];
    }

    return null;
}

// Set plugin option
function ime_set_option( $option_name, $option_value, $store = false ) {
    ime_setup_options();

    global $ime_options;

    $ime_options[ $option_name ] = $option_value;

    if ( $store ) {
        ime_store_options();
    }
}

// Should images be converted with interlace or not
function ime_interlace() {
    return ime_get_option( 'interlace' );
}

// Should Exif data (including GPS) be preserved when stripping metadata
function ime_keep_exif() {
    return ime_get_option( 'keep_exif' );
}

// Get image quality setting for type
function ime_get_quality( $resize_mode = 'quality' ) {
    $quality = ime_get_option( 'quality', '-1' );
    if ( ! $quality ) {
        return -1;
    }
    if ( ! is_array( $quality ) ) {
        return $quality;
    }
    if ( isset( $quality[ $resize_mode ] ) ) {
        return $quality[ $resize_mode ];
    }

    return -1;
}

// Get resize mode for size
function ime_get_resize_mode( $size ) {
    $handle_sizes = ime_get_option( 'handle_sizes' );
    if ( isset( $handle_sizes[ $size ] ) && is_string( $handle_sizes[ $size ] ) ) {
        return $handle_sizes[ $size ];
    } else {
        return 'quality'; // default to quality
    }
}


/*
 * WP integration & image handling functions
 */

/*
 * Filter image sizes (in wp_generate_attachment_metadata()).
 *
 * We store the sizes we are interested in, and remove those sizes from the
 * list so that WP doesn't handle them -- we will take care of them later.
 *
 * The reason we do things this way is so we do not resize image twize (once
 * by WordPress using GD, and then again by us).
 */
function ime_filter_image_sizes( $sizes ) {
    global $ime_image_sizes;

    $handle_sizes = ime_get_option( 'handle_sizes' );
    foreach ( $handle_sizes as $s => $handle ) {
        if ( ! $handle || $handle == 'skip' || ! array_key_exists( $s, $sizes ) ) {
            continue;
        }
        $ime_image_sizes[ $s ] = $sizes[ $s ];
        unset( $sizes[ $s ] );
    }
    return $sizes;
}

/*
 * Filter to get target file name.
 *
 * Function wp_generate_attachment_metadata calls wp_read_image_metadata which
 * gives us a hook to get the target filename.
 */
function ime_filter_read_image_metadata( $metadata, $file, $ignore ) {
    global $ime_image_file;

    $ime_image_file = $file;

    return $metadata;
}

/*
 * Filter new attachment metadata
 *
 * Resize image for the sizes we are interested in.
 *
 * Parts of function copied from wp-includes/media.php:image_resize()
 */
function ime_filter_attachment_metadata( $metadata, $attachment_id ) {
    global $ime_image_sizes, $ime_image_file;

    // Any sizes we are interested in?
    if ( empty( $ime_image_sizes ) ) {
        return $metadata;
    }

    $attachment = get_post( $attachment_id );

    // We can only process attachments.
    if ( 'attachment' !== get_post_type( $attachment ) ) {
        return $metadata;
    }

    // Make sure file exists on server
    if ( ! $ime_image_file || ! file_exists( $ime_image_file ) ) {
        return $metadata;
    }

    $editor = wp_get_image_editor( $ime_image_file );
    if ( is_wp_error( $editor ) ) {
        // Display a more helpful error message.
        if ( 'image_no_editor' === $editor->get_error_code() ) {
            $editor = new WP_Error( 'image_no_editor', __( 'The current image editor cannot process this file type.', 'regenerate-thumbnails' ) );
        }

        $editor->add_data( array(
            'attachment' => $attachment,
            'status'     => 415,
        ) );

        return $editor;
    }

    // Get size & image type of original image
    $old_stats = wp_getimagesize( $ime_image_file );
    if ( ! $old_stats || is_wp_error( $old_stats ) ) {
        return $metadata;
    }

    list($orig_w, $orig_h, $orig_type) = $old_stats;

    /*
     * Sort out the filename, extension (and image type) of resized images
     */
    $info     = pathinfo( $ime_image_file );
    $dir      = $info['dirname'];
    $ext      = $info['extension'];

    /*
     * Do the actual resize
     */
    foreach ( $ime_image_sizes as $size => $size_data ) {
        $width  = $size_data['width'];
        $height = $size_data['height'];

        // ignore sizes equal to or larger than original size
        if ( $orig_w <= $width && $orig_h <= $height ) {
            continue;
        }

        $crop = $size_data['crop'];

        $dims = image_resize_dimensions( $orig_w, $orig_h, $width, $height, $crop );
        if ( ! $dims ) {
            continue;
        }
        list($dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h) = $dims;

        $suffix       = "{$dst_w}x{$dst_h}";
        $new_filename = $editor->generate_filename( $suffix, null, $ext );

        $resized = ime_im_resize( $ime_image_file, $new_filename, $dst_w, $dst_h, $crop, ime_get_resize_mode( $size ) );
        if ( ! $resized ) {
            continue;
        }

        $metadata['sizes'][ $size ] = [
            'file'   => wp_basename( $new_filename ),
            'width'  => $dst_w,
            'height' => $dst_h,
        ];

        if ( ! isset( $metadata['image-converter'] ) || ! is_array( $metadata['image-converter'] ) ) {
            $metadata['image-converter'] = [];
        }

        $metadata['image-converter'][ $size ] = 'IME';

        // Set correct file permissions
        $stat  = stat( dirname( $new_filename ) );
        $perms = $stat['mode'] & 0000666; //same permissions as parent folder, strip off the executable bits
        @ chmod( $new_filename, $perms );
    }

    $ime_image_sizes = null;
    return $metadata;
}

// Resize file by calling mode specific resize function
function ime_im_resize( $old_file, $new_file, $width, $height, $crop, $resize_mode = 'quality' ) {
    $mode = ime_get_option( 'mode' );
    $fn   = 'ime_im_' . $mode . '_valid';
    if ( empty( $mode ) || ! function_exists( $fn ) || ! call_user_func( $fn ) ) {
        return false;
    }

    $fn      = 'ime_im_' . $mode . '_resize';
    $success = ( function_exists( $fn ) && call_user_func( $fn, $old_file, $new_file, $width, $height, $crop, $resize_mode ) );
    do_action( 'ime_after_resize', $success, $old_file, $new_file, $width, $height, $crop, $resize_mode );
    return $success;
}

// Is this the filename of a jpeg?
function ime_im_filename_is_jpg( $filename ) {
    $info = pathinfo( $filename );
    $ext  = $info['extension'];
    return ( strcasecmp( $ext, 'jpg' ) == 0 ) || ( strcasecmp( $ext, 'jpeg' ) == 0 );
}

// Get file extenstion
function ime_im_get_filetype( $filename ) {
    $info = pathinfo( $filename );
    return strtolower( $info['extension'] );
}

/*
 * PHP ImageMagick ("Imagick") class handling
 */

// Does class exist?
function ime_im_php_valid() {
    return class_exists( 'Imagick' );
}

// Resize file using PHP Imagick class
function ime_im_php_resize( $old_file, $new_file, $width, $height, $crop, $resize_mode = 'quality' ) {
    $im = new Imagick( $old_file );
    if ( ! $im->valid() ) {
        return false;
    }

    try {
        $im->setImageFormat( ime_im_get_filetype( $old_file ) );

        // Apply Exif orientation to actual pixels before any dimension calculations.
        // Without this, getImageGeometry() returns pre-rotation dimensions and
        // resized images end up with the wrong orientation.
        $im->autoOrient();

        $quality = ime_get_quality( $resize_mode );
        if ( is_numeric( $quality ) && $quality >= 0 && $quality <= 100 && ime_im_filename_is_jpg( $new_file ) ) {
            $im->setImageCompression( Imagick::COMPRESSION_JPEG );
            $im->setImageCompressionQuality( $quality );
        }

        if ( ime_interlace() ) {
            $im->setInterlaceScheme( Imagick::INTERLACE_PLANE );
        }

        if ( $resize_mode == 'size' ) {
            if ( ime_keep_exif() ) {
                // Strip everything except Exif (preserves GPS and other Exif data)
                foreach ( [ 'iptc', '8bim', 'xmp', 'APP13' ] as $profile ) {
                    @$im->removeImageProfile( $profile );
                }
            } else {
                $im->stripImage();
            }
        }

        if ( $crop ) {
            /*
             * Unfortunately we cannot use the PHP module
             * cropThumbnailImage() function as it strips profile data.
             *
             * Crop an area proportional to target $width and $height and
             * fall through to scaleImage() below.
             */

            $geo         = $im->getImageGeometry();
            $orig_width  = $geo['width'];
            $orig_height = $geo['height'];

            if ( ( $orig_width / $width ) < ( $orig_height / $height ) ) {
                $crop_width  = $orig_width;
                $crop_height = ceil( ( $height * $orig_width ) / $width );
                $off_x       = 0;
                $off_y       = ceil( ( $orig_height - $crop_height ) / 2 );
            } else {
                $crop_width  = ceil( ( $width * $orig_height ) / $height );
                $crop_height = $orig_height;
                $off_x       = ceil( ( $orig_width - $crop_width ) / 2 );
                $off_y       = 0;
            }
            $im->cropImage( $crop_width, $crop_height, $off_x, $off_y );
        }

        $im->scaleImage( $width, $height, true );

        $im->setImagePage( $width, $height, 0, 0 ); // to make sure canvas is correct
        $im->writeImage( $new_file );

        return file_exists( $new_file );
    } catch ( ImagickException $ie ) {
        return false;
    }
}

// Does Gmagick class exist?
function ime_im_gmagick_valid() {
    return class_exists( 'Gmagick' );
}

// Resize file using PHP Gmagick class
function ime_im_gmagick_resize( $old_file, $new_file, $width, $height, $crop, $resize_mode = 'quality' ) {
    try {
        $im = new Gmagick( $old_file );

        $im->setimageformat( ime_im_get_filetype( $old_file ) );

        // Apply Exif orientation correction manually (Gmagick has no autoOrient())
        $orientation = $im->getimageorientation();
        switch ( $orientation ) {
            case Gmagick::ORIENTATION_BOTTOMRIGHT: // 3 — rotated 180
                $im->rotateimage( '#000000', 180 );
                break;
            case Gmagick::ORIENTATION_RIGHTTOP: // 6 — rotated 90 CW
                $im->rotateimage( '#000000', 90 );
                break;
            case Gmagick::ORIENTATION_LEFTBOTTOM: // 8 — rotated 270 CW
                $im->rotateimage( '#000000', 270 );
                break;
            case Gmagick::ORIENTATION_TOPRIGHT: // 2 — flipped horizontal
                $im->flopimage();
                break;
            case Gmagick::ORIENTATION_BOTTOMLEFT: // 4 — flipped vertical
                $im->flipimage();
                break;
            case Gmagick::ORIENTATION_LEFTTOP: // 5 — transpose
                $im->flopimage();
                $im->rotateimage( '#000000', 90 );
                break;
            case Gmagick::ORIENTATION_RIGHTBOTTOM: // 7 — transverse
                $im->flopimage();
                $im->rotateimage( '#000000', 270 );
                break;
        }
        if ( $orientation > 1 ) {
            $im->setimageorientation( Gmagick::ORIENTATION_TOPLEFT );
        }

        $quality = ime_get_quality( $resize_mode );
        if ( is_numeric( $quality ) && $quality >= 0 && $quality <= 100 && ime_im_filename_is_jpg( $new_file ) ) {
            $im->setimagecompression( Gmagick::COMPRESSION_JPEG );
            $im->setimagecompressionquality( intval( $quality ) );
        }

        if ( ime_interlace() && defined( 'Gmagick::INTERLACE_PLANE' ) ) {
            $im->setinterlacescheme( Gmagick::INTERLACE_PLANE );
        }

        if ( $resize_mode == 'size' ) {
            if ( ime_keep_exif() ) {
                foreach ( [ 'iptc', '8bim', 'xmp', 'APP13' ] as $profile ) {
                    try {
                        $im->removeimageprofile( $profile );
                    } catch ( GmagickException $e ) {
                        // Profile may not exist — not an error
                    }
                }
            } else {
                $im->stripimage();
            }
        }

        $orig_width  = $im->getimagewidth();
        $orig_height = $im->getimageheight();

        if ( $crop ) {
            if ( ( $orig_width / $width ) < ( $orig_height / $height ) ) {
                $crop_width  = $orig_width;
                $crop_height = ceil( ( $height * $orig_width ) / $width );
                $off_x       = 0;
                $off_y       = ceil( ( $orig_height - $crop_height ) / 2 );
            } else {
                $crop_width  = ceil( ( $width * $orig_height ) / $height );
                $crop_height = $orig_height;
                $off_x       = ceil( ( $orig_width - $crop_width ) / 2 );
                $off_y       = 0;
            }
            $im->cropimage( intval( $crop_width ), intval( $crop_height ), intval( $off_x ), intval( $off_y ) );
        }

        $im->scaleimage( $width, $height, true );
        $im->writeimage( $new_file );

        return file_exists( $new_file );
    } catch ( GmagickException $ge ) {
        return false;
    }
}

/*
 * ImageMagick executable handling
 */

// Check if path is executable depending on OS
function ime_is_executable($fullpath) {
    if ( ! function_exists('proc_open') ) {
        return @is_executable($fullpath);
    }
    $whereIsCommand = (PHP_OS == 'WINNT') ? 'where' : 'which';
    $process = proc_open(
        [ $whereIsCommand, $fullpath ],
        [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
        $pipes
    );
    if ( ! is_resource($process) ) {
        return false;
    }
    $output = trim( stream_get_contents( $pipes[1] ) );
    fclose( $pipes[1] );
    fclose( $pipes[2] );
    proc_close( $process );
    return ! empty( $output );
}

// Do we have a valid CLI executable set? Pass $is_gm = true for GraphicsMagick.
function ime_im_cli_valid( $is_gm = false ) {
    $transient = $is_gm ? 'ime_gm_valid' : 'ime_cli_valid';
    if ( WP_DEBUG || false === ( $valid = get_transient( $transient ) ) ) {
        $cmd   = ime_im_cli_command( $is_gm );
        $valid = ( ! empty( $cmd ) && ime_is_executable( $cmd ) ) ? 'yes' : 'no';
        set_transient( $transient, $valid, DAY_IN_SECONDS );
    }
    return $valid === 'yes';
}

// Test if executable is a working IM or GM binary.
function ime_im_cli_check_executable( $fullpath, $is_gm = false ) {
    if ( ! @is_executable( $fullpath ) || ! function_exists( 'proc_open' ) ) {
        return false;
    }

    $args    = $is_gm ? [ $fullpath, 'version' ] : [ $fullpath, '--version' ];
    $process = proc_open(
        $args,
        [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
        $pipes
    );
    if ( ! is_resource( $process ) ) {
        return false;
    }
    $output = stream_get_contents( $pipes[1] );
    fclose( $pipes[1] );
    fclose( $pipes[2] );
    proc_close( $process );

    if ( $is_gm ) {
        preg_match( '/GraphicsMagick ([0-9]+\.[0-9]+(?:\.[0-9]+)?)/', $output, $version );
        if ( isset( $version[1] ) ) {
            ime_set_option( 'graphicsmagick_version', $version[1], true );
            return true;
        }
    } else {
        preg_match( '/ImageMagick ([0-9]+\.[0-9]+\.[0-9]+)/', $output, $version );
        if ( isset( $version[1] ) ) {
            ime_set_option( 'imagemagick_version', $version[1], true );
            return true;
        }
    }

    return false;
}

/*
 * Try to get realpath of path
 *
 * This won't work if there is open_basename restrictions.
 */
function ime_try_realpath( $path ) {
    $realpath = @realpath( $path );
    if ( $realpath ) {
        return $realpath;
    } else {
        return $path;
    }
}

// Check if a directory contains a working IM or GM executable.
function ime_im_cli_check_command( $path, $is_gm = false ) {
    $path        = ime_try_realpath( $path );
    $executables = $is_gm ? [ 'gm' ] : [ 'magick', 'convert' ];

    foreach ( $executables as $executable ) {
        $full_path = $path . DIRECTORY_SEPARATOR . $executable;
        if ( ime_im_cli_check_executable( $full_path, $is_gm ) ) {
            return $full_path;
        }
        $full_path_exe = $full_path . '.exe';
        if ( ime_im_cli_check_executable( $full_path_exe, $is_gm ) ) {
            return $full_path_exe;
        }
    }

    return null;
}

// Try to auto-discover an IM or GM executable in common paths.
function ime_im_cli_find_command( $is_gm = false ) {
    $possible_paths = [ '/usr/bin', '/usr/local/bin', '/opt/homebrew/bin' ];

    foreach ( $possible_paths as $path ) {
        if ( ime_im_cli_check_command( $path, $is_gm ) ) {
            return $path;
        }
    }

    return null;
}

// Get the full path to the IM or GM executable.
function ime_im_cli_command( $is_gm = false ) {
    $path_option = $is_gm ? 'gm_path' : 'cli_path';
    $path        = ime_get_option( $path_option );

    if ( ! empty( $path ) ) {
        return ime_im_cli_check_command( $path, $is_gm );
    }

    $path = ime_im_cli_find_command( $is_gm );
    if ( empty( $path ) ) {
        return null;
    }
    ime_set_option( $path_option, $path, true );
    return ime_im_cli_check_command( $path, $is_gm );
}

// Thin wrappers so the mode dispatch system finds ime_im_graphicsmagick_valid().
function ime_im_graphicsmagick_valid() {
    return ime_im_cli_valid( true );
}

// Check if we are running under Windows (which differs for character escape)
function ime_is_windows() {
    return ( constant( 'PHP_SHLIB_SUFFIX' ) == 'dll' );
}

// Shared resize implementation for both ImageMagick and GraphicsMagick CLI.
// GraphicsMagick requires 'convert' as a subcommand; ImageMagick does not.
function ime_im_cli_do_resize( $cmd_path, $is_gm, $old_file, $new_file, $width, $height, $crop, $resize_mode ) {
    $geometry = intval( $width ) . 'x' . intval( $height );
    $prefix   = $is_gm ? [ $cmd_path, 'convert' ] : [ $cmd_path ];

    // Build command args array — passed directly to proc_open, no shell interpretation
    $cmd_args = array_merge( $prefix, [
        $old_file,
        '-auto-orient',        // apply Exif rotation to pixels before resizing
        '-limit', 'memory', '157286400',
        '-limit', 'map', '134217728',
        '-resize', $geometry . ( $crop ? '^' : '!' ),
    ] );

    if ( $crop ) {
        $cmd_args = array_merge( $cmd_args, [ '-gravity', 'center', '-extent', $geometry ] );
    }

    $quality = ime_get_quality( $resize_mode );
    if ( is_numeric( $quality ) && $quality >= 0 && $quality <= 100 && ime_im_filename_is_jpg( $new_file ) ) {
        $cmd_args = array_merge( $cmd_args, [ '-quality', (string) intval( $quality ) ] );
    }

    if ( ime_interlace() ) {
        $cmd_args = array_merge( $cmd_args, [ '-interlace', 'Plane' ] );
    }

    if ( $resize_mode == 'size' ) {
        if ( ime_keep_exif() ) {
            // Remove bulky non-Exif profiles; preserve Exif (contains GPS)
            $cmd_args = array_merge( $cmd_args, [ '+profile', '8bim', '+profile', 'iptc', '+profile', 'xmp' ] );
        } else {
            $cmd_args[] = '-strip';
        }
    }

    $cmd_args[] = $new_file;

    $process = proc_open(
        $cmd_args,
        [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
        $pipes
    );
    if ( is_resource( $process ) ) {
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        proc_close( $process );
    }

    return file_exists( $new_file );
}

function ime_im_cli_resize( $old_file, $new_file, $width, $height, $crop, $resize_mode = 'quality' ) {
    $cmd_path = ime_im_cli_command();
    if ( empty( $cmd_path ) ) {
        return false;
    }
    return ime_im_cli_do_resize( $cmd_path, false, $old_file, $new_file, $width, $height, $crop, $resize_mode );
}

function ime_im_graphicsmagick_resize( $old_file, $new_file, $width, $height, $crop, $resize_mode = 'quality' ) {
    $cmd_path = ime_im_cli_command( true );
    if ( empty( $cmd_path ) ) {
        return false;
    }
    return ime_im_cli_do_resize( $cmd_path, true, $old_file, $new_file, $width, $height, $crop, $resize_mode );
}

/*
 * AJAX functions
 */

// Test if a path is correct for IM binary
function ime_ajax_test_im_path() {
    if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_REQUEST['ime_nonce'], 'ime-admin-nonce') ) {
        wp_die( 'Sorry, but you do not have permissions to perform this action.' );
    }

    $mode = sanitize_text_field( wp_unslash( $_REQUEST['mode'] ?? 'cli' ) );

    $is_gm      = ( $mode === 'graphicsmagick' );
    $path_key   = $is_gm ? 'gm_path' : 'cli_path';
    $input_path = sanitize_text_field( wp_unslash( $_REQUEST[ $path_key ] ?? '' ) );
    $check_path = @realpath( $input_path ) ?: $input_path;
    $r          = ime_im_cli_check_command( $check_path, $is_gm );
    $found = ! empty( $r );

    $open_basedir_issue = false;
    if ( ! $found ) {
        $open_basedir = ini_get( 'open_basedir' );
        if ( $open_basedir ) {
            $covered = false;
            foreach ( explode( PATH_SEPARATOR, $open_basedir ) as $dir ) {
                if ( $dir !== '' && strpos( $check_path, rtrim( $dir, '/\\' ) ) === 0 ) {
                    $covered = true;
                    break;
                }
            }
            $open_basedir_issue = ! $covered;
        }
    }

    wp_send_json( [
        'found'        => $found,
        'open_basedir' => $open_basedir_issue,
        'engine'       => $is_gm ? 'GraphicsMagick' : 'ImageMagick',
    ] );
}

// Get list of attachments to regenerate
function ime_ajax_regeneration_get_images() {
    global $wpdb;

    if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_REQUEST['ime_nonce'], 'ime-admin-nonce') ) {
        wp_die( 'Sorry, but you do not have permissions to perform this action.' );
    }

    // Query for the IDs only to reduce memory usage
    $images = $wpdb->get_results( "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' AND post_mime_type != 'image/svg+xml'" );

    // Generate the list of IDs
    $ids = [];
    foreach ( $images as $image ) {
        $ids[] = $image->ID;
    }
    $ids = implode( ',', $ids );

    wp_die( $ids );
}

// Process single attachment ID
function ime_ajax_process_image() {
    global $ime_image_sizes, $ime_image_file, $_wp_additional_image_sizes;

    if ( ! current_user_can( 'manage_options' ) || ! ime_mode_valid() || ! wp_verify_nonce( $_REQUEST['ime_nonce'], 'ime-admin-nonce') ) {
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

    foreach ( $temp_sizes as $s ) {
        $sizes[ $s ] = [
            'width'  => '',
            'height' => '',
            'crop'   => false,
        ];
        if ( isset( $_wp_additional_image_sizes[ $s ]['width'] ) ) {
            $sizes[ $s ]['width'] = intval( $_wp_additional_image_sizes[ $s ]['width'] ); // For theme-added sizes
        } else {
            $sizes[ $s ]['width'] = get_option( "{$s}_size_w" ); // For default sizes set in options
        }
        if ( isset( $_wp_additional_image_sizes[ $s ]['height'] ) ) {
            $sizes[ $s ]['height'] = intval( $_wp_additional_image_sizes[ $s ]['height'] ); // For theme-added sizes
        } else {
            $sizes[ $s ]['height'] = get_option( "{$s}_size_h" ); // For default sizes set in options
        }
        if ( isset( $_wp_additional_image_sizes[ $s ]['crop'] ) ) {
            $sizes[ $s ]['crop'] = intval( $_wp_additional_image_sizes[ $s ]['crop'] ); // For theme-added sizes
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


/*
 * Admin page functions
 */

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
    wp_enqueue_style( 'ime-admin-style', plugins_url( '/css/ime-admin.css', __FILE__ ), [] );
}

/* Add settings to plugin action links */
function ime_filter_plugin_actions( $links, $file ) {
    if ( $file == plugin_basename( __FILE__ ) ) {
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
        if ( isset( $_POST['mode'] ) && array_key_exists( $_POST['mode'], ime_get_available_modes() ) ) {
            ime_set_option( 'mode', $_POST['mode'] );
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
                                                    echo '<input type="checkbox" name="regen-size-' . $s . '" value="1" ' . ( ( isset( $handle_sizes[ $s ] ) && $handle_sizes[ $s ] != 'skip' && $handle_sizes[ $s ] != false ) ? ' checked="checked" ' : '' ) . ' /> ' . $name . '<br />';
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
?>
