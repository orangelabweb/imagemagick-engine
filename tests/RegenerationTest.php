<?php

/**
 * Regeneration against the real ImageMagick binary and the real Modern Image
 * Formats plugin.
 */
class RegenerationTest extends WP_UnitTestCase {

	/** @var int[] */
	private $attachments = [];

	/** @var string */
	private $fixture;

	/** @var mixed */
	private $original_options;

	public function set_up() {
		parent::set_up();

		global $ime_options;
		$this->original_options = $ime_options;

		update_option( 'perflab_modern_image_format', 'webp' );
		update_option( 'perflab_generate_webp_and_jpeg', true );
		update_option( 'thumbnail_size_w', 150 );
		update_option( 'thumbnail_size_h', 150 );
		update_option( 'thumbnail_crop', 1 );
		update_option( 'medium_size_w', 300 );
		update_option( 'medium_size_h', 300 );

		$this->fixture = $this->create_fixture();
	}

	public function tear_down() {
		global $ime_options, $ime_image_sizes, $ime_image_file, $ime_failed_sizes;

		foreach ( $this->attachments as $id ) {
			wp_delete_attachment( $id, true );
		}

		wp_delete_file( $this->fixture );

		$ime_options      = $this->original_options;
		$ime_image_sizes  = null;
		$ime_image_file   = null;
		$ime_failed_sizes = [];

		parent::tear_down();
	}

	public function test_upload_gives_handled_sizes_a_webp_variant() {
		$id = $this->upload();

		$this->assertFileExists( $this->source_path( $id, 'thumbnail', 'image/webp' ) );
	}

	public function test_regeneration_recreates_the_webp_variant() {
		$id = $this->upload();

		$this->assertTrue( ime_process_attachment( $id, [ 'thumbnail', 'medium' ], true ) );

		$this->assertFileExists( $this->source_path( $id, 'thumbnail', 'image/webp' ) );
		$this->assertFileExists( $this->source_path( $id, 'medium', 'image/webp' ) );
	}

	public function test_regenerated_webp_variant_is_made_from_the_new_dimensions() {
		$id = $this->upload();

		update_option( 'medium_size_w', 200 );
		update_option( 'medium_size_h', 200 );
		ime_process_attachment( $id, [ 'medium' ], true );

		$size = wp_getimagesize( $this->source_path( $id, 'medium', 'image/webp' ) );

		$this->assertSame( 200, $size[0] );
		$this->assertSame( 150, $size[1] );
	}

	public function test_regeneration_deletes_the_variant_of_the_old_dimensions() {
		$id       = $this->upload();
		$old_webp = $this->source_path( $id, 'medium', 'image/webp' );

		update_option( 'medium_size_w', 200 );
		update_option( 'medium_size_h', 200 );
		ime_process_attachment( $id, [ 'medium' ], true );

		$this->assertFileDoesNotExist( $old_webp );
	}

	public function test_regeneration_keeps_the_sub_size_file_itself() {
		$id = $this->upload();

		ime_process_attachment( $id, [ 'thumbnail' ], true );

		$meta = wp_get_attachment_metadata( $id );
		$this->assertFileExists( $this->upload_dir( $id ) . $meta['sizes']['thumbnail']['file'] );
	}

	public function test_size_outside_the_run_keeps_its_variant() {
		$id   = $this->upload();
		$webp = $this->source_path( $id, 'medium', 'image/webp' );

		ime_process_attachment( $id, [ 'thumbnail' ], true );

		$this->assertFileExists( $webp );
		$this->assertSame( $webp, $this->source_path( $id, 'medium', 'image/webp' ) );
	}

	public function test_failed_resize_keeps_the_existing_variant() {
		global $ime_options;

		$id   = $this->upload();
		$webp = $this->source_path( $id, 'thumbnail', 'image/webp' );

		ime_setup_options();
		$ime_options['mode'] = 'missing';

		$result = ime_process_attachment( $id, [ 'thumbnail' ], true );

		$this->assertWPError( $result );
		$this->assertFileExists( $webp );
		$this->assertSame( $webp, $this->source_path( $id, 'thumbnail', 'image/webp' ) );
	}

	public function test_regeneration_recreates_the_variant_when_only_webp_is_generated() {
		update_option( 'perflab_generate_webp_and_jpeg', false );

		$id = $this->upload();

		$this->assertTrue( ime_process_attachment( $id, [ 'thumbnail' ], true ) );

		$this->assertFileExists( $this->source_path( $id, 'thumbnail', 'image/webp' ) );
		$this->assertFileExists( get_attached_file( $id ) );
	}

	public function test_size_sharing_a_file_keeps_it_when_the_other_size_moves() {
		add_image_size( 'ime_twin', 300, 300 );
		$id = $this->upload();

		$meta                      = wp_get_attachment_metadata( $id );
		$shared                    = $meta['sizes']['medium']['file'];
		$meta['sizes']['ime_twin'] = $meta['sizes']['medium'];
		wp_update_attachment_metadata( $id, $meta );

		update_option( 'medium_size_w', 200 );
		update_option( 'medium_size_h', 200 );
		ime_process_attachment( $id, [ 'medium' ], true );
		remove_image_size( 'ime_twin' );

		$this->assertFileExists( $this->upload_dir( $id ) . $shared );
	}

	private function upload(): int {
		$id = self::factory()->attachment->create_upload_object( $this->fixture );

		$this->attachments[] = $id;

		return $id;
	}

	private function source_path( int $id, string $size, string $mime ): string {
		$meta = wp_get_attachment_metadata( $id );

		$this->assertArrayHasKey( 'sources', $meta['sizes'][ $size ], "Size {$size} has no sources." );
		$this->assertArrayHasKey( $mime, $meta['sizes'][ $size ]['sources'], "Size {$size} has no {$mime} source." );

		return $this->upload_dir( $id ) . $meta['sizes'][ $size ]['sources'][ $mime ]['file'];
	}

	private function upload_dir( int $id ): string {
		return trailingslashit( dirname( get_attached_file( $id ) ) );
	}

	/**
	 * A noisy 1200x900 JPEG, so WebP encodes smaller and is not discarded.
	 */
	private function create_fixture(): string {
		$path  = trailingslashit( get_temp_dir() ) . 'ime-fixture-' . wp_generate_password( 8, false ) . '.jpg';
		$image = imagecreatetruecolor( 1200, 900 );

		mt_srand( 42 );
		for ( $y = 0; $y < 900; $y += 3 ) {
			for ( $x = 0; $x < 1200; $x += 3 ) {
				$color = imagecolorallocate( $image, (int) ( $x / 5 ) % 256, (int) ( $y / 4 ) % 256, mt_rand( 0, 255 ) );
				imagefilledrectangle( $image, $x, $y, $x + 2, $y + 2, $color );
			}
		}

		imagejpeg( $image, $path, 95 );

		return $path;
	}
}
