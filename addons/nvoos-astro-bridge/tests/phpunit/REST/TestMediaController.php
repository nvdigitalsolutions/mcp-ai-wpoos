<?php
/**
 * PHPUnit tests for MediaController — image uploads + previews powering the
 * Astro CMS standalone surface (no media-modal dependency).
 *
 * @package NvoosAstroBridge\Tests
 */

/**
 * Media endpoint tests (upload + preview record).
 *
 * @covers \NvoosAstroBridge\REST\MediaController
 * @group nvoos-astro-bridge
 */
class TestMediaController extends WP_Test_REST_TestCase {

	/**
	 * Admin user ID used by authenticated-path assertions.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Temp files created by this test — removed in tearDown.
	 *
	 * @var string[]
	 */
	private $tmp_files = array();

	/**
	 * Set up test state: register REST routes, create an admin.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( 0 );

		// CLI test environments cannot produce real PHP uploads
		// (is_uploaded_file() is always false) — switch the handler to the
		// sideload action (is_readable gate) for the suite. The production
		// default is pinned by test_upload_action_defaults_to_upload.
		add_filter( 'funiq_bridge_media_upload_action', static fn() => 'wp_handle_sideload' );
	}

	/**
	 * Remove temp files.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		foreach ( $this->tmp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->tmp_files = array();
		parent::tearDown();
	}

	/**
	 * Write a real 1x1 PNG to a temp file and track it for cleanup.
	 *
	 * @return string Temp file path.
	 */
	private function write_png_file(): string {
		$tmp = tempnam( sys_get_temp_dir(), 'nvoos-media-' );
		// 1x1 transparent PNG — valid bytes so MIME detection passes.
		file_put_contents(
			$tmp,
			base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' )
		);
		$this->tmp_files[] = $tmp;
		return $tmp;
	}

	/**
	 * Build the multipart-style file array wp_handle_upload expects.
	 *
	 * @param string $path Temp file path.
	 * @param string $name Original filename.
	 * @return array<string, mixed>
	 */
	private function file_array( string $path, string $name ): array {
		return array(
			'name'     => $name,
			'type'     => 'image/png',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $path ),
		);
	}

	/**
	 * The media routes exist on both namespaces.
	 */
	public function test_media_routes_are_registered_on_both_namespaces(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/funiq/v1/media', $routes );
		$this->assertArrayHasKey( '/nvoos-commerce/v1/media', $routes );
		$this->assertArrayHasKey( '/nvoos-commerce/v1/media/(?P<id>\d+)', $routes );
	}

	/**
	 * Production default: the handler verifies the file came through PHP's
	 * upload mechanism (is_uploaded_file) — the suite filter must not leak
	 * into the shipped behaviour.
	 */
	public function test_upload_action_defaults_to_upload(): void {
		remove_all_filters( 'funiq_bridge_media_upload_action' );
		$this->assertSame( 'wp_handle_upload', apply_filters( 'funiq_bridge_media_upload_action', 'wp_handle_upload' ) );
	}

	/**
	 * Anonymous uploads are rejected.
	 */
	public function test_upload_requires_authentication(): void {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/nvoos-commerce/v1/media' );
		$request->set_file_params( array( 'file' => $this->file_array( $this->write_png_file(), 'anon.png' ) ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertTrue(
			401 === $response->get_status() || 403 === $response->get_status(),
			'Anonymous media upload must be denied (got ' . $response->get_status() . ').'
		);
	}

	/**
	 * A manager upload creates an attachment and returns the preview record.
	 */
	public function test_upload_creates_attachment(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/nvoos-commerce/v1/media' );
		$request->set_file_params( array( 'file' => $this->file_array( $this->write_png_file(), 'test-image.png' ) ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status(), 'Upload failed: ' . wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();

		$this->assertArrayHasKey( 'id', $data );
		$this->assertArrayHasKey( 'url', $data );
		$this->assertArrayHasKey( 'path', $data );
		$this->assertGreaterThan( 0, (int) $data['id'] );
		$this->assertStringContainsString( 'test-image', $data['url'] );
		$this->assertStringContainsString( 'test-image', $data['path'] );

		$attachment = get_post( (int) $data['id'] );
		$this->assertNotNull( $attachment );
		$this->assertSame( 'image/png', $attachment->post_mime_type );
	}

	/**
	 * Non-image uploads are rejected by the MIME gate.
	 */
	public function test_upload_rejects_non_image(): void {
		wp_set_current_user( $this->admin_id );

		$tmp = tempnam( sys_get_temp_dir(), 'nvoos-media-' );
		file_put_contents( $tmp, '<?php echo "x";' );
		$this->tmp_files[] = $tmp;

		$request = new WP_REST_Request( 'POST', '/nvoos-commerce/v1/media' );
		$request->set_file_params(
			array(
				'file' => array(
					'name'     => 'evil.php',
					'type'     => 'application/octet-stream',
					'tmp_name' => $tmp,
					'error'    => UPLOAD_ERR_OK,
					'size'     => filesize( $tmp ),
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * GET /media/{id} returns the preview record for an uploaded file.
	 */
	public function test_get_media_returns_record(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/nvoos-commerce/v1/media' );
		$request->set_file_params( array( 'file' => $this->file_array( $this->write_png_file(), 'preview.png' ) ) );
		$upload   = rest_get_server()->dispatch( $request );
		$uploaded = $upload->get_data();

		$request = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/media/' . $uploaded['id'] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status(), 'Preview failed: ' . wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertEquals( $uploaded['id'], $data['id'] );
		$this->assertEquals( $uploaded['path'], $data['path'] );
		$this->assertStringContainsString( 'preview', $data['url'] );
	}

	/**
	 * GET /media/{id} 404s for unknown attachments.
	 */
	public function test_get_media_404s_for_missing_attachment(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/media/999999' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}
}
