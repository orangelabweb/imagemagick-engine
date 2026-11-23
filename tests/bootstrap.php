<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite with this plugin and
 * Modern Image Formats (webp-uploads) active.
 */

$ime_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/wordpress-phpunit';

if ( ! file_exists( $ime_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found in {$ime_tests_dir}. Run the tests inside the wp-env cli container.\n" );
	exit( 1 );
}

define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

// Without the binary the engine is silently inactive and core does the resizing,
// so the tests would be exercising the wrong code path.
if ( ! is_executable( '/usr/bin/convert' ) ) {
	fwrite( STDERR, "ImageMagick not found in this container. Run: apk add imagemagick imagemagick-jpeg imagemagick-webp\n" );
	exit( 1 );
}

require_once $ime_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		$webp_uploads = WP_PLUGIN_DIR . '/webp-uploads/load.php';

		if ( ! file_exists( $webp_uploads ) ) {
			fwrite( STDERR, "webp-uploads not found in the plugins directory. It is mapped in .wp-env.json.\n" );
			exit( 1 );
		}

		require_once $webp_uploads;
		require_once dirname( __DIR__ ) . '/imagemagick-engine.php';
	}
);

// The engine hooks are attached at plugins_loaded, so the options must be in
// place before then. Uses the real ImageMagick binary from the container.
tests_add_filter(
	'pre_option_ime_options',
	static function () {
		return [
			'enabled'                        => true,
			'mode'                           => 'cli',
			'cli_path'                       => '/usr/bin',
			'gm_path'                        => '/usr/bin',
			'handle_sizes'                   => [
				'thumbnail'    => 'quality',
				'medium'       => 'quality',
				'medium_large' => 'quality',
				'large'        => 'quality',
			],
			'quality'                        => [
				'quality' => -1,
				'size'    => 70,
			],
			'interlace'                      => false,
			'keep_exif'                      => false,
			'disable_client_side_processing' => true,
			'version'                        => IME_OPTION_VERSION,
		];
	}
);

require $ime_tests_dir . '/includes/bootstrap.php';
