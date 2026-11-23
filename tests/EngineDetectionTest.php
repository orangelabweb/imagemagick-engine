<?php

/**
 * Command-line engine detection and the reasons reported when it fails.
 */
class EngineDetectionTest extends WP_UnitTestCase {

	/** @var string */
	private $dir;

	/** @var string|false */
	private $original_path;

	public function set_up() {
		parent::set_up();

		$this->original_path = getenv( 'PATH' );
		$this->dir           = trailingslashit( get_temp_dir() ) . 'ime-bin-' . wp_generate_password( 8, false );

		mkdir( $this->dir );
	}

	public function tear_down() {
		putenv( 'PATH=' . $this->original_path );

		foreach ( glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );

		parent::tear_down();
	}

	public function test_engine_is_valid_when_which_is_not_on_the_path() {
		putenv( 'PATH=/nonexistent' );

		$this->assertTrue( ime_im_cli_valid() );
	}

	public function test_directory_with_only_magick_is_a_working_engine() {
		symlink( '/usr/bin/magick', $this->dir . '/magick' );

		$this->assertSame( $this->dir . '/magick', ime_im_cli_check_command( $this->dir ) );
	}

	public function test_magick_binary_resizes_an_image() {
		symlink( '/usr/bin/magick', $this->dir . '/magick' );

		$source = $this->dir . '/source.jpg';
		$target = $this->dir . '/target.jpg';
		$image  = imagecreatetruecolor( 400, 300 );
		imagejpeg( $image, $source );

		$this->assertTrue( ime_im_cli_do_resize( $this->dir . '/magick', false, $source, $target, 200, 150, false, 'quality' ) );

		$size = wp_getimagesize( $target );
		$this->assertSame( 200, $size[0] );
		$this->assertSame( 150, $size[1] );
	}

	public function test_failed_resize_is_not_reported_as_success_when_the_old_file_remains() {
		$binary = $this->fake_binary( "exit 1\n" );
		$target = $this->dir . '/target.jpg';
		file_put_contents( $target, 'previous version' );

		$this->assertFalse( ime_im_cli_do_resize( $binary, false, $this->dir . '/source.jpg', $target, 200, 150, false, 'quality' ) );
		$this->assertSame( 'previous version', file_get_contents( $target ) );
	}

	public function test_warning_on_stderr_does_not_fail_a_resize() {
		// Enough output to break the write if stderr has been closed.
		$binary = $this->fake_binary( "sleep 0.2\necho 'warning: unknown profile' >&2 || exit 141\nfor last; do :; done\necho written > \"\$last\"\nexit 0\n" );
		$target = $this->dir . '/target.jpg';

		$this->assertTrue( ime_im_cli_do_resize( $binary, false, $this->dir . '/source.jpg', $target, 200, 150, false, 'quality' ) );
		$this->assertSame( "written\n", file_get_contents( $target ) );
	}

	public function test_empty_path_stays_empty_so_auto_discovery_keeps_working() {
		// realpath( '' ) is the current directory, which would be saved as the engine path.
		$this->assertSame( '', ime_try_realpath( '' ) );
	}

	public function test_working_binary_has_no_failure_reason() {
		$this->assertNull( ime_im_cli_diagnose( '/usr/bin' ) );
	}

	public function test_empty_path_is_reported() {
		$this->assertSame( 'no_path', ime_im_cli_diagnose( '' )['code'] );
	}

	public function test_missing_directory_is_reported() {
		$this->assertSame( 'path_missing', ime_im_cli_diagnose( $this->dir . '/nope' )['code'] );
	}

	public function test_directory_without_a_binary_is_reported() {
		$this->assertSame( 'binary_missing', ime_im_cli_diagnose( $this->dir )['code'] );
	}

	public function test_binary_without_execute_permission_is_reported() {
		file_put_contents( $this->dir . '/convert', "#!/bin/sh\n" );
		chmod( $this->dir . '/convert', 0644 );

		$this->assertSame( 'not_executable', ime_im_cli_diagnose( $this->dir )['code'] );
	}

	public function test_binary_that_crashes_reports_its_error_output() {
		file_put_contents( $this->dir . '/convert', "#!/bin/sh\necho 'libMagick.so: cannot open' >&2\nexit 127\n" );
		chmod( $this->dir . '/convert', 0755 );

		$reason = ime_im_cli_diagnose( $this->dir );

		$this->assertSame( 'run_failed', $reason['code'] );
		$this->assertStringContainsString( 'libMagick.so: cannot open', $reason['message'] );
	}

	public function test_program_that_is_not_imagemagick_is_reported() {
		// Windows ships an unrelated convert.exe, so this is a real case.
		file_put_contents( $this->dir . '/convert', "#!/bin/sh\necho 'Converts FAT volumes'\n" );
		chmod( $this->dir . '/convert', 0755 );

		$this->assertSame( 'wrong_program', ime_im_cli_diagnose( $this->dir )['code'] );
	}

	public function test_graphicsmagick_is_diagnosed_separately() {
		symlink( '/usr/bin/magick', $this->dir . '/magick' );

		$this->assertSame( 'binary_missing', ime_im_cli_diagnose( $this->dir, true )['code'] );
	}

	public function test_path_outside_open_basedir_is_reported() {
		$reason = ime_im_cli_diagnose( '/usr/bin', false, '/var/www:/tmp' );

		$this->assertSame( 'open_basedir', $reason['code'] );
	}

	public function test_path_inside_open_basedir_is_not_blocked() {
		$this->assertNull( ime_im_cli_diagnose( '/usr/bin', false, '/var/www:/usr' ) );
	}

	private function fake_binary( string $body ): string {
		$path = $this->dir . '/fake-convert';
		file_put_contents( $path, "#!/bin/sh\n" . $body );
		chmod( $path, 0755 );

		return $path;
	}

	public function test_sibling_directory_does_not_count_as_inside_open_basedir() {
		$this->assertTrue( ime_path_outside_open_basedir( '/usr/binary', '/usr/bin' ) );
	}
}
