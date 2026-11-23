<?php

/**
 * The logic behind `wp ime regenerate`.
 */
class CliRegenerateTest extends WP_UnitTestCase {

	/** @var int[] */
	private $attachments = [];

	/** @var mixed */
	private $original_options;

	public function set_up() {
		parent::set_up();

		global $ime_options;
		ime_setup_options();
		$this->original_options = $ime_options;
	}

	public function tear_down() {
		global $ime_options, $ime_image_sizes, $ime_image_file, $ime_failed_sizes;

		foreach ( $this->attachments as $id ) {
			wp_delete_attachment( $id, true );
		}

		$ime_options      = $this->original_options;
		$ime_image_sizes  = null;
		$ime_image_file   = null;
		$ime_failed_sizes = [];

		parent::tear_down();
	}

	public function test_default_sizes_leave_out_skipped_sizes() {
		global $ime_options;
		$ime_options['handle_sizes'] = [
			'thumbnail' => 'skip',
			'medium'    => 'quality',
			'large'     => 'size',
		];

		$this->assertSame( [ 'medium', 'large' ], ime_cli_resolve_sizes( null ) );
	}

	public function test_requested_sizes_are_used() {
		$this->assertSame( [ 'thumbnail', 'large' ], ime_cli_resolve_sizes( 'thumbnail, large' ) );
	}

	public function test_unknown_size_is_an_error_naming_it() {
		$result = ime_cli_resolve_sizes( 'thumbnail,nonsense' );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'nonsense', $result->get_error_message() );
	}

	public function test_explicit_id_that_is_not_an_image_is_an_error() {
		$post = self::factory()->post->create();

		$result = ime_cli_validate_ids( [ (string) $post ] );

		$this->assertWPError( $result );
		$this->assertStringContainsString( (string) $post, $result->get_error_message() );
	}

	public function test_explicit_image_ids_are_returned_as_integers() {
		$id = $this->upload();

		$this->assertSame( [ $id ], ime_cli_validate_ids( [ (string) $id ] ) );
	}

	public function test_all_image_ids_are_walked_across_page_boundaries() {
		$ids = [ $this->upload(), $this->upload(), $this->upload() ];

		$this->assertSame( $ids, iterator_to_array( ime_cli_all_image_ids( 2 ), false ) );
	}

	public function test_failed_image_does_not_stop_the_run() {
		$broken = $this->upload();
		$good   = $this->upload();

		// The original, not the attached file: Modern Image Formats may have
		// replaced the attached file with a WebP copy.
		unlink( wp_get_original_image_path( $broken ) );

		$result = ime_cli_regenerate( [ $broken, $good ], [ 'thumbnail' ], true );

		$this->assertSame( 1, $result['done'] );
		$this->assertSame( [ $broken ], array_keys( $result['failed'] ) );
	}

	public function test_progress_is_reported_once_per_image() {
		$ids   = [ $this->upload(), $this->upload() ];
		$calls = 0;

		ime_cli_regenerate( $ids, [ 'thumbnail' ], true, static function () use ( &$calls ) {
			$calls++;
		} );

		$this->assertSame( 2, $calls );
	}

	private function upload(): int {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		$this->attachments[] = $id;

		return $id;
	}
}
