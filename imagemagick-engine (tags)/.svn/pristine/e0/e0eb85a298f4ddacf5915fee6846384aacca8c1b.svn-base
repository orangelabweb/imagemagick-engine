<?php
/*
  Plugin Name: ImageMagick Engine
  Plugin URI: http://wp.orangelab.se/imagemagick-engine/
  Description: Improve the quality of re-sized images by replacing standard GD library with ImageMagick
  Author: Orangelab
  Author URI: http://www.orangelab.se
  Version: 1.3.1
  Text Domain: imagemagick-engine

  Copyright 2010, 2011 Orangelab

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

/*
 * Current todo list:
 * - test command line version string
 * - test php module with required image formats
 * - handle errors in resize, fall back to GD
 *
 * Future todo list:
 * - do not iterate through all images if only resizing non-ime images
 * - edit post insert image: add custom sizes?
 * - admin: smarter find path to executable (maybe try 'which' or package handler?)
 * - allow customization of command line / class functions (safely!), check memory limit
 * - unsharp mask, sharpening level options (perhaps on a picture-by-picture basis)
 * - handle TIF and other IM formats if possible
 * - can we use IM instead of GD in more places?
 * - custom crop of images instead of blindly going for the middle
 */

if (!defined('ABSPATH'))
	die('Must not be called directly');


/*
 * Constants
 */
define('IME_OPTION_VERSION', 1);


/*
 * Global variables
 */

// Plugin options default values -- change on plugin admin page
$ime_options_default = array('enabled' => false
			     , 'mode' => null
			     , 'cli_path' => null
			     , 'handle_sizes' => array('thumbnail' => true, 'medium' => true, 'large' => true)
			     , 'quality' => ''
			     , 'version' => IME_OPTION_VERSION
			     );

// Available modes
$ime_available_modes = array('php' => "Imagick PHP module"
			     , 'cli' => "ImageMagick command-line");

// Current options
$ime_options = null;

// Keep track of attachment file & sizes between different filters
$ime_image_sizes = null;
$ime_image_file = null;


/*
 * Functions
 */
add_action('plugins_loaded', 'ime_init');

/* Plugin setup */
function ime_init() {
	load_plugin_textdomain('imagemagick-engine', false, dirname(plugin_basename(__FILE__)) . '/languages');

	if (ime_active()) {
		add_filter('intermediate_image_sizes_advanced', 'ime_filter_image_sizes', 99, 1);
		add_filter('wp_read_image_metadata', 'ime_filter_read_image_metadata', 10, 3);
		add_filter('wp_generate_attachment_metadata', 'ime_filter_attachment_metadata', 10, 2);
	}

	if (is_admin()) {
		add_action('admin_menu', 'ime_admin_menu');
		add_filter('plugin_action_links', 'ime_filter_plugin_actions', 10, 2 );
		add_filter('media_meta', 'ime_filter_media_meta', 10, 2);

		add_action('wp_ajax_ime_test_im_path', 'ime_ajax_test_im_path');
		add_action('wp_ajax_ime_process_image', 'ime_ajax_process_image');
		add_action('wp_ajax_ime_regeneration_get_images','ime_ajax_regeneration_get_images');
		
		wp_register_script('ime-admin', plugins_url('/js/ime-admin.js', __FILE__), array('jquery'));

		/*
		 * jQuery UI version 1.7 and 1.8 seems incompatible...
		 */
		if (ime_script_version_compare('jquery-ui-core', '1.8', '>=')) {
			wp_register_script('jquery-ui-progressbar', plugins_url('/js/ui.progressbar-1.8.9.js', __FILE__), array('jquery-ui-core', 'jquery-ui-widget'), '1.8.9');
		} else {
			wp_register_script('jquery-ui-progressbar', plugins_url('/js/ui.progressbar-1.7.2.js', __FILE__), array('jquery-ui-core'), '1.7.2');
		}
	}
}

/* Are we enabled with valid mode? */
function ime_active() {
	return ime_get_option("enabled") && ime_mode_valid();
}

/* Check if mode is valid */
function ime_mode_valid($mode = null) {
	if (empty($mode))
		$mode = ime_get_option("mode");
	$fn = 'ime_im_' . $mode . '_valid';
	return (!empty($mode) && function_exists($fn) && call_user_func($fn));
}

// Check version of a registered WordPress script
function ime_script_version_compare($handle, $version, $compare = '>=') {
	global $wp_scripts;
	if ( !is_a($wp_scripts, 'WP_Scripts') )
		$wp_scripts = new WP_Scripts();

	$query = $wp_scripts->query($handle, 'registered');
	if (!$query)
		return false;

	return version_compare($query->ver, $version, $compare);
}


/*
 * Plugin option handling
 */

// Setup plugin options
function ime_setup_options() {
	global $ime_options;

	// Already setup?
	if (is_array($ime_options))
		return;

	$ime_options = get_option("ime_options");

	// No stored options yet?
	if (!is_array($ime_options)) {
		global $ime_options_default;
		$ime_options = $ime_options_default;
	}

	// Do we need to upgrade options?
	if (!array_key_exists('version', $ime_options)
	    || $ime_options['version'] < IME_OPTION_VERSION) {
		
		/*
		 * Future compatability code goes here!
		 */
		
		$ime_options['version'] = IME_OPTION_VERSION;
		ime_store_options();
	}
}

// Store plugin options
function ime_store_options() {
	global $ime_options;

	ime_setup_options();

	$stored_options = get_option("ime_options");
	
	if ($stored_options === false)
		add_option("ime_options", $ime_options, null, false);
	else
		update_option("ime_options", $ime_options);
}

// Get plugin option
function ime_get_option($option_name, $default = null) {
	ime_setup_options();
	
	global $ime_options, $ime_options_default;

	if (array_key_exists($option_name, $ime_options))
		return $ime_options[$option_name];

	if (!is_null($default))
		return $default;

	if (array_key_exists($option_name, $ime_options_default))
		return $ime_options_default[$option_name];

	return null;
}

// Set plugin option
function ime_set_option($option_name, $option_value, $store = false) {
	ime_setup_options();
	
	global $ime_options;

	$ime_options[$option_name] = $option_value;

	if ($store)
		ime_store_options();
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
function ime_filter_image_sizes($sizes) {
	global $ime_image_sizes;

	$handle_sizes = ime_get_option('handle_sizes');
	foreach ($handle_sizes AS $s => $handle) {
		if (!$handle || !array_key_exists($s, $sizes))
			continue;
		$ime_image_sizes[$s] = $sizes[$s];
		unset($sizes[$s]);
	}
	return $sizes;
}

/*
 * Filter to get target file name.
 *
 * Function wp_generate_attachment_metadata calls wp_read_image_metadata which
 * gives us a hook to get the target filename.
 */
function ime_filter_read_image_metadata($metadata, $file, $ignore) {
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
function ime_filter_attachment_metadata($metadata, $attachment_id) {
	global $ime_image_sizes, $ime_image_file;

	// Any sizes we are interested in?
	if (empty($ime_image_sizes))
		return $metadata;

	// Get size & image type of original image
	$old_stats = @getimagesize($ime_image_file);
	if (!$old_stats)
		return $metadata;

	list($orig_w, $orig_h, $orig_type) = $old_stats;

	/*
	 * Sort out the filename, extension (and image type) of resized images
	 *
	 * If image type is PNG or GIF keep it, otherwise make sure it is JPG
	 */
	$info = pathinfo($ime_image_file);
	$dir = $info['dirname'];
	$ext = $info['extension'];
	$namebase = basename($ime_image_file, ".{$ext}");
	if ($orig_type == IMAGETYPE_PNG || $orig_type == IMAGETYPE_GIF)
		$new_ext = $ext;
	else
		$new_ext = "jpg";
	
	/*
	 * Do the actual resize
	 */
	foreach ($ime_image_sizes as $size => $size_data) {
		$width = $size_data['width'];
		$height = $size_data['height'];

		// ignore sizes equal to or larger than original size
		if ($orig_w <= $width && $orig_h <= $height)
			continue;

		$crop = $size_data['crop'];
		
		$dims = image_resize_dimensions($orig_w, $orig_h, $width, $height, $crop);
		if ( !$dims )
			continue;
		list($dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h) = $dims;

		$suffix = "{$dst_w}x{$dst_h}";
		$new_filename = "{$dir}/{$namebase}-{$suffix}.{$new_ext}";

		$resized = ime_im_resize($ime_image_file, $new_filename, $dst_w, $dst_h, $crop);
		if (!$resized)
			continue;

		$metadata['sizes'][$size] = array('file' => basename($new_filename)
						  , 'width' => $dst_w
						  , 'height' => $dst_h
						  );

		if (!isset($metadata['image-converter']) || !is_array($metadata['image-converter']))
			$metadata['image-converter'] = array();
		
		$metadata['image-converter'][$size] = 'IME';

		// Set correct file permissions
		$stat = stat( dirname( $new_filename ));
		$perms = $stat['mode'] & 0000666; //same permissions as parent folder, strip off the executable bits
		@ chmod( $new_filename, $perms );
	}

	$ime_image_sizes = null;
	return $metadata;
}

// Resize file by calling mode specific resize function
function ime_im_resize($old_file, $new_file, $width, $height, $crop) {
	$mode = ime_get_option("mode");
	$fn = 'ime_im_' . $mode . '_valid';
	if (empty($mode) || !function_exists($fn) || !call_user_func($fn))
		return false;

	$fn = 'ime_im_' . $mode . '_resize';
	return (function_exists($fn) && call_user_func($fn, $old_file, $new_file, $width, $height, $crop));
}

// Is this the filename of a jpeg?
function ime_im_filename_is_jpg($filename) {
	$info = pathinfo($filename);
	$ext = $info['extension'];
	return (strcasecmp($ext, 'jpg') == 0) || (strcasecmp($ext, 'jpeg') == 0);
}

/*
 * PHP ImageMagick ("Imagick") class handling
 */

// Does class exist?
function ime_im_php_valid() {
	return class_exists('Imagick');
}

// Resize file using PHP Imagick class
function ime_im_php_resize($old_file, $new_file, $width, $height, $crop) {
	$im = new Imagick($old_file);
	if (!$im->valid())
		return false;

	$quality = ime_get_option('quality', '-1');
	if (is_numeric($quality) && $quality >= 0 && $quality <= 100 && ime_im_filename_is_jpg($new_file)) {
		$im->setImageCompression(Imagick::COMPRESSION_JPEG);
		$im->setImageCompressionQuality($quality);
	}
	if (method_exists($im, 'setImageOpacity'))
		$im->setImageOpacity(1.0);

	if ($crop) {
		/*
		 * Unfortunately we cannot use the PHP module
		 * cropThumbnailImage() function as it strips profile data.
		 *
		 * Crop an area proportional to target $width and $height and
		 * fall through to scaleImage() below.
		 */
		
		$geo = $im->getImageGeometry();
		$orig_width = $geo['width'];
		$orig_height = $geo['height'];

		if(($orig_width / $width) < ($orig_height / $height)) {
			$crop_width = $orig_width;
			$crop_height = ceil(($height * $orig_width) / $width);
			$off_x = 0;
			$off_y = ceil(($orig_height - $crop_height) / 2);
		} else {
			$crop_width = ceil(($width * $orig_height) / $height);
			$crop_height = $orig_height;
			$off_x = ceil(($orig_width - $crop_width) / 2);
			$off_y = 0;
		}
		$im->cropImage($crop_width, $crop_height, $off_x, $off_y);
	}
	
	$im->scaleImage($width, $height, true);

	$im->setImagePage($width, $height, 0, 0); // to make sure canvas is correct
	$im->writeImage($new_file);

	return file_exists($new_file);
}

/*
 * ImageMagick executable handling
 */

// Do we have a valid ImageMagick executable set?
function ime_im_cli_valid() {
	$cmd = ime_im_cli_command();
	return !empty($cmd) && is_executable($cmd);
}

// Test if we are allowed to exec executable!
function ime_im_cli_check_executable($fullpath) {
	if (!is_executable($fullpath))
		return false;

	@exec('"' . $fullpath . '" --version', $output);

	return count($output) > 0;
}

/*
 * Try to get realpath of path
 *
 * This won't work if there is open_basename restrictions.
 */
function ime_try_realpath($path) {
	$realpath = @realpath($path);
	if ($realpath)
		return $realpath;
	else
		return $path;
}

// Check if path leads to ImageMagick executable
function ime_im_cli_check_command($path, $executable='convert') {
	$path = ime_try_realpath($path);

	$cmd = $path . '/' . $executable;
	if (ime_im_cli_check_executable($cmd))
		return $cmd;

	$cmd = $cmd . '.exe';
	if (ime_im_cli_check_executable($cmd))
		return $cmd;

	return null;
}

// Try to find a valid ImageMagick executable
function ime_im_cli_find_command($executable='convert') {
	$possible_paths = array("/usr/bin", "/usr/local/bin");

	foreach ($possible_paths AS $path) {
		if (ime_im_cli_check_command($path, $executable))
			return $path;
	}

	return null;
}

// Get ImageMagick executable
function ime_im_cli_command($executable='convert') {
	$path = ime_get_option("cli_path");
	if (!empty($path))
		return ime_im_cli_check_command($path, $executable);

	$path = ime_im_cli_find_command($executable);
	if (empty($path))
		return null;
	ime_set_option("cli_path", $path, true);
	return ime_im_cli_check_command($path, $executable);
}

// Check if we are running under Windows (which differs for character escape)
function ime_is_windows() {
	return (constant('PHP_SHLIB_SUFFIX') == 'dll');
}

// Resize using ImageMagick executable
function ime_im_cli_resize($old_file, $new_file, $width, $height, $crop) {
	$cmd = ime_im_cli_command();
	if (empty($cmd))
		return false;

	$old_file = addslashes($old_file);
	$new_file = addslashes($new_file);

	$geometry = $width . 'x' . $height;

	// limits are 150mb and 128mb
	$cmd = "\"$cmd\" \"$old_file\" -limit memory 157286400 -limit map 134217728 -resize $geometry";
	if ($crop) {
		// '^' is an escape character on Windows
		$cmd .= (ime_is_windows() ? '^^' : '^') . " -gravity center -extent $geometry";
	} else
		$cmd .= "!"; // force these dimensions

	$quality = ime_get_option('quality', '-1');
	if (is_numeric($quality) && $quality >= 0 && $quality <= 100 && ime_im_filename_is_jpg($new_file))
		$cmd .= " -quality " . intval($quality);

	$cmd .= ' "' .  $new_file . '"';
	exec($cmd);

	return file_exists($new_file);
}


/*
 * AJAX functions
 */

// Test if a path is correct for IM binary
function ime_ajax_test_im_path() {
	if (!current_user_can('manage_options'))
		die();
	$r = ime_im_cli_check_command($_REQUEST['cli_path']);
	echo empty($r) ? "0" : "1";
	die();
}

// Get list of attachments to regenerate
function ime_ajax_regeneration_get_images() {
	global $wpdb;
	
	if (!current_user_can('manage_options'))
		wp_die('Sorry, but you do not have permissions to perform this action.');
	
	// Query for the IDs only to reduce memory usage
	$images = $wpdb->get_results( "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" );
	
	// Generate the list of IDs
	$ids = array();
	foreach ( $images as $image )
		$ids[] = $image->ID;
	$ids = implode( ',', $ids );
	
	die($ids);
}

// Process single attachment ID
function ime_ajax_process_image() {
	global $ime_image_sizes, $ime_image_file, $_wp_additional_image_sizes;

	if (!current_user_can('manage_options') || !ime_mode_valid())
		die('-1');

	$id = intval($_REQUEST['id']);
	if ($id <= 0)
		die('-1');

	$temp_sizes = $_REQUEST['sizes'];
	if (empty($temp_sizes))
		die('-1');
	$temp_sizes = explode('|', $temp_sizes);
	if (count($temp_sizes) < 1)
		die('-1');

	$temp_sizes = apply_filters( 'intermediate_image_sizes', $temp_sizes );

	foreach ( $temp_sizes as $s ) {
		$sizes[$s] = array( 'width' => '', 'height' => '', 'crop' => FALSE );
		if ( isset( $_wp_additional_image_sizes[$s]['width'] ) )
			$sizes[$s]['width'] = intval( $_wp_additional_image_sizes[$s]['width'] ); // For theme-added sizes
		else
			$sizes[$s]['width'] = get_option( "{$s}_size_w" ); // For default sizes set in options
		if ( isset( $_wp_additional_image_sizes[$s]['height'] ) )
			$sizes[$s]['height'] = intval( $_wp_additional_image_sizes[$s]['height'] ); // For theme-added sizes
		else
			$sizes[$s]['height'] = get_option( "{$s}_size_h" ); // For default sizes set in options
		if ( isset( $_wp_additional_image_sizes[$s]['crop'] ) )
			$sizes[$s]['crop'] = intval( $_wp_additional_image_sizes[$s]['crop'] ); // For theme-added sizes
		else
			$sizes[$s]['crop'] = get_option( "{$s}_crop" ); // For default sizes set in options
	}

	remove_filter('intermediate_image_sizes_advanced', 'ime_filter_image_sizes', 99, 1);
	$sizes = apply_filters( 'intermediate_image_sizes_advanced', $sizes );

	$force = isset($_REQUEST['force']) && !! $_REQUEST['force'];

	$ime_image_file = get_attached_file($id);

	if (false === $ime_image_file || !file_exists($ime_image_file))
		die('-1');

	$metadata = wp_get_attachment_metadata($id);

	// Do not re-encode IME images unless forced
	if (!$force && isset($metadata['image-converter']) && is_array($metadata['image-converter'])) {
		$converter = $metadata['image-converter'];
		
		foreach ($sizes AS $s => $ignore) {
			if (isset($converter[$s]) && $converter[$s] == 'IME')
				unset($sizes[$s]);
		}
		if (count($sizes) < 1)
			die(1);
	}

	$ime_image_sizes = $sizes;

	set_time_limit(60);

	$new_meta = ime_filter_attachment_metadata($metadata, $id);
	wp_update_attachment_metadata($id, $new_meta);

	/*
	 * Normally the old file gets overwritten by the new one when
	 * regenerating resized images.
	 *
	 * However, if the specifications of image sizes were changed this
	 * will result in different resized file names.
	 *
	 * Make sure they get deleted.
	 */

	$dir = trailingslashit(dirname($ime_image_file));

	foreach ($metadata['sizes'] as $size => $sizeinfo) {
		$old_file = $sizeinfo['file'];

		// Does file exist in new meta?
		$exists = false;
		foreach ($new_meta['sizes'] as $ignore => $new_sizeinfo) {
			if ($old_file != $new_sizeinfo['file'])
				continue;
			$exists = true;
			break;
		}
		if ($exists)
			continue;

		// Old file did not exist in new meta. Delete it!
		@ unlink($dir . $old_file);
	}
	
	die('1');
}


/*
 * Admin page functions
 */

/* Add admin page */
function ime_admin_menu() {
	$page = add_options_page('ImageMagick Engine', 'ImageMagick Engine', 'manage_options', 'imagemagick-engine', 'ime_option_page');
	
	add_action('admin_print_scripts-' . $page, 'ime_admin_scripts');
	add_action('admin_print_styles-' . $page, 'ime_admin_styles');
}

/* Enqueue admin page scripts */
function ime_admin_scripts() {	
	wp_enqueue_script('ime-admin');
	wp_enqueue_script('jquery-ui-dialog');
	wp_enqueue_script('jquery-ui-progressbar');
}

/* Enqueue admin page style */
function ime_admin_styles() {
	wp_enqueue_style( 'ime-admin-style', plugins_url('/css/ime-admin.css', __FILE__), array());
}

/* Add settings to plugin action links */
function ime_filter_plugin_actions($links, $file) {
	if($file == plugin_basename(__FILE__)) {
		$settings_link = "<a href=\"options-general.php?page=imagemagick-engine\">"
			. __('Settings', 'imagemagick-engine') . '</a>';
		array_unshift( $links, $settings_link ); // before other links
	}

	return $links;
}

/*
 * Add admin information if attachment is converted using plugin
 */
function ime_filter_media_meta($content, $post) {
	$metadata = wp_get_attachment_metadata($post->ID);

	if (!is_array($metadata) || !array_key_exists('image-converter', $metadata))
		return $content;

	foreach ($metadata['image-converter'] as $size => $converter) {
		if ($converter != 'IME')
			continue;

		return $content . '</p><p><i>' . __('Resized using ImageMagick Engine', 'imagemagick-engine') . '</i>';
	}

	return $content;
}

// Url to admin images
function ime_option_admin_images_url() {
	return get_bloginfo('wpurl') . '/wp-admin/images/';
}

// Url to status icon
function ime_option_status_icon($yes = true) {
	return ime_option_admin_images_url() . ($yes ? 'yes' : 'no') . '.png';
}

// Display, or not
function ime_option_display($display = true, $echo = true) {
	if ($display)
		return '';
	$s = ' style="display: none" ';
	if ($echo)
		echo $s;
	return $s;
}

/* Plugin admin / status page */
function ime_option_page() {
	global $ime_available_modes;

	if (!current_user_can('manage_options'))
		wp_die('Sorry, but you do not have permissions to change settings.');

	/* Make sure post was from this page */
	if (count($_POST) > 0)
		check_admin_referer('ime-options');

	global $_wp_additional_image_sizes;
	$sizes = array('thumbnail' => __('Thumbnail', 'imagemagick-engine')
		       , 'medium' => __('Medium', 'imagemagick-engine')
		       , 'large' => __('Large', 'imagemagick-engine')); // Standard sizes
	if ( isset( $_wp_additional_image_sizes ) && count( $_wp_additional_image_sizes ) ) {
		foreach ($_wp_additional_image_sizes as $name => $spec)
			$sizes[$name] = $name;
	}

	if (isset($_POST['regenerate-images'])) {
		ime_show_regenerate_images(array_keys($sizes));
		return;
	}

	/* Should we update settings? */
	if (isset($_POST['update_settings'])) {
		$new_enabled = isset($_POST['enabled']) && !! $_POST['enabled'];
		ime_set_option('enabled', $new_enabled);
		if (isset($_POST['mode']) && array_key_exists($_POST['mode'], $ime_available_modes))
			ime_set_option('mode', $_POST['mode']);
		if (isset($_POST['cli_path']))
			ime_set_option('cli_path', ime_try_realpath(trim($_POST['cli_path'])));
		if (isset($_POST['quality'])) {
			if (is_numeric($_POST['quality']))
				ime_set_option('quality', min(100, max(0, intval($_POST['quality']))));
			else if (empty($_POST['quality']))
				ime_set_option('quality', '');
		}
		$new_handle_sizes = array();
		foreach ($sizes AS $s => $name) {
			$f = 'handle-' . $s;
			$new_handle_sizes[$s] = isset($_POST[$f]) && !! $_POST[$f];
		}
		ime_set_option('handle_sizes', $new_handle_sizes);

		ime_store_options();
		
		echo '<div id="message" class="updated fade"><p>'
			. __('Settings updated', 'imagemagick-engine')
			. '</p></div>';
	}

	$modes_valid = $ime_available_modes;
	$any_valid = false;
	foreach($modes_valid AS $m => $ignore) {
		$modes_valid[$m] = ime_mode_valid($m);
		if ($modes_valid[$m])
			$any_valid = true;
	}

	$current_mode = ime_get_option('mode');
	if (!$modes_valid[$current_mode])
		$current_mode = null;
	if (is_null($current_mode) && $any_valid) {
		foreach ($modes_valid AS $m => $valid) {
			if ($valid) {
				$current_mode = $m;
				break;
			}
		}
	}

	$enabled = ime_get_option('enabled') && $current_mode;
	
	$cli_path = ime_get_option('cli_path');
	if (is_null($cli_path))
		$cli_path = ime_im_cli_command();
	$cli_path_ok = ime_im_cli_check_command($cli_path);

	$quality = ime_get_option('quality');
	$handle_sizes = ime_get_option('handle_sizes');

	if (!$any_valid)
		echo '<div id="warning" class="error"><p>'
			. sprintf(__('No valid ImageMagick mode found! Please check %sFAQ%s for installation instructions.', 'imagemagick-engine'), '<a href="http://wp.orangelab.se/imagemagick-engine/documentation#installation">', '</a>')
			. '</p></div>';
	elseif (!$enabled)
		echo '<div id="warning" class="error"><p>'
			. __('ImageMagick Engine is not enabled.', 'imagemagick-engine')
			. '</p></div>';
?>
<div class="wrap">
  <div id="regen-message" class="hidden updated fade"></div>
  <h2><?php _e('ImageMagick Engine Settings','imagemagick-engine'); ?></h2>
  <form action="options-general.php?page=imagemagick-engine" method="post" name="update_options">
    <?php wp_nonce_field('ime-options'); ?>
    <input type="hidden" name="rt_message_noimg" id="rt_message_noimg" value="<?php _e('You dont have any images to regenerate', 'imagemagick-engine'); ?>" />
    <input type="hidden" name="rt_message_done" id="rt_message_done" value="<?php _e('All done!', 'imagemagick-engine'); ?>" />
    <input type="hidden" name="rt_message_processed" id="rt_message_processed" value="<?php _e('Processed', 'imagemagick-engine'); ?>" />
    <input type="hidden" name="rt_message_images" id="rt_message_images" value="<?php _e('images', 'imagemagick-engine'); ?>" />
  <div id="poststuff" class="metabox-holder has-right-sidebar">
    <div class="inner-sidebar">
      <div class="meta-box-sortables ui-sortable">
	<div id="regenerate-images-metabox" class="postbox">
	  <h3 class="hndle"><span><?php _e('Regenerate Images','imagemagick-engine'); ?></span></h3>
	  <div class="inside">
	    <div class="submitbox">
	      <table border=0>
		<tr>
		  <td scope="row" valign="top" style="padding-right: 20px;"><?php _e('Sizes','imagemagick-engine'); ?>:</td>
		  <td>
		    <?php
		      foreach($sizes AS $s => $name) {
			      echo '<input type="checkbox" name="regen-size-' . $s . '" value="1" ' . (isset($handle_sizes[$s]) && $handle_sizes[$s] ? ' CHECKED ' : '') . ' /> ' . $name . '<br />';
		      }
		      ?>
		      </td>
		    </tr>
	      </table>
	      <p><?php _e('ImageMagick images too','imagemagick-engine'); ?>: 
	      <input type="checkbox" name="force" id="force" value="1" /></p>
	      <?php
		      if (!ime_active())
			      echo '<p class="howto">' . __('Resize will use standard WordPress functions.', 'imagemagick-engine') . '</p>';
	      ?>
	      <p><input class="button-primary" type="button" id="regenerate-images" value="<?php _e('Regenerate', 'imagemagick-engine'); ?>" /> <img alt="" title="" class="ajax-feedback" src="<?php echo ime_option_admin_images_url(); ?>wpspin_light.gif" style="visibility: hidden;"> <?php _e('(this can take a long time)', 'imagemagick-engine'); ?></p>
	    </div>
	    <div class="hidden">
	      <div id="regeneration" title="<?php _e('Regenerating images', 'imagemagick-engine'); ?>...">
	      <noscript><p><em><?php _e( 'You must enable Javascript in order to proceed!', 'imagemagick-engine' ) ?></em></p></noscript>
	      <div id="regenbar">
		<div id="regenbar-percent"></div>
	      </div>
	    </div>
	  </div>
	</div>
      </div>
    </div>
  </div>
  <div id="post-body">
    <div id="post-body-content">
      <div id="ime-settings" class="postbox">
	<h3 class="hndle"><span><?php _e('Settings', 'imagemagick-engine'); ?></span></h3>
	<div class="inside">
	  <table class="form-table">
	    <tr>
	      <th scope="row" valign="top"><?php _e('Enable enhanced image engine','imagemagick-engine'); ?>:</th>
	      <td>
		<input type="checkbox" id="enabled" name="enabled" value="1" <?php echo $enabled ? " CHECKED " : ""; echo $any_valid ? '' : ' disabled=disabled '; ?> />
	      </td>
	    </tr>
	    <tr>
	      <th scope="row" valign="top"><?php _e('Image engine','imagemagick-engine'); ?>:</th>
	      <td>
		<select id="ime-select-mode" name="mode">
		  <?php
		      foreach($modes_valid AS $m => $valid) {
			      echo '<option value="' . $m . '"';
			      if ($m == $current_mode)
				      echo ' selected=selected ';
			      echo '>' . $ime_available_modes[$m] . '</option>';
		      }
		  ?>
		</select>
	      </td>
	    </tr>
	    <tr id="ime-row-php">
	      <th scope="row" valign="top"><?php _e('Imagick PHP module','imagemagick-engine'); ?>:</th>
	      <td>
		<img src="<?php echo ime_option_status_icon($modes_valid['php']); ?>" />
		<?php echo $modes_valid['php'] ? __('Imagick PHP module found', 'imagemagick-engine') : __('Imagick PHP module not found', 'imagemagick-engine'); ?>
	      </td>
	    </tr>
	    <tr id="ime-row-cli">
	      <th scope="row" valign="top"><?php _e('ImageMagick path','imagemagick-engine'); ?>:</th>
	      <td>
		<img id="cli_path_yes" class="cli_path_icon" src="<?php echo ime_option_status_icon(true); ?>" alt="" <?php ime_option_display($cli_path_ok); ?> />
		<img id="cli_path_no" class="cli_path_icon" src="<?php echo ime_option_status_icon(false); ?>" alt="<?php _e('Command not found', 'qp-qie'); ?>"  <?php ime_option_display(!$cli_path_ok); ?> />
		<img id="cli_path_progress" src="<?php echo ime_option_admin_images_url(); ?>wpspin_light.gif" alt="<?php _e('Testing command...', 'qp-qie'); ?>"  <?php ime_option_display(false); ?> />
		<input id="cli_path" type="text" name="cli_path" size="<?php echo max(30, strlen($cli_path) + 5); ?>" value="<?php echo $cli_path; ?>" />
		<input type="button" name="ime_cli_path_test" id="ime_cli_path_test" value="<?php _e('Test path', 'imagemagick-engine'); ?>" class="button-secondary" />
	      </td>
	    </tr>
	    <tr>
	      <th scope="row" valign="top"><?php _e('ImageMagick quality','imagemagick-engine'); ?>:</th>
	      <td>
		<input id="quality" type="text" name="quality" size="3" value="<?php echo $quality; ?>" /> <?php _e('(0-100, leave empty for default value, computed dynamically)', 'imagemagick-engine'); ?>
	      </td>
	    </tr>
	    <tr>
	      <th scope="row" valign="top"><?php _e('Handle sizes','imagemagick-engine'); ?>:</th>
	      <td>
		<?php
		      foreach($sizes AS $s => $name) {
			      echo '<input type="checkbox" name="handle-' . $s . '" value="1" ' . (isset($handle_sizes[$s]) && $handle_sizes[$s] ? ' CHECKED ' : '') . ' /> ' . $name . '<br />';
		      }
		?>
	      </td>
	    </tr>
	    <tr>
	      <td>
		<input class="button-primary" type="submit" name="update_settings" value="<?php _e('Save Changes', 'imagemagick-engine'); ?>" />
	      </td>
	    </tr>
	  </table>
	</div>
      </div>
    </div>
  </div>
  </form>
</div>
<?php
}
?>