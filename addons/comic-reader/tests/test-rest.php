<?php
/**
 * NV oOS Comic Reader — REST API Tests
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

/**
 * Test REST API endpoints.
 */
class Test_Comic_Reader_REST extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * First author user ID (owns fixtures).
	 *
	 * @var int
	 */
	private $author_id;

	/**
	 * Second author user ID (must not touch the first author's comics).
	 *
	 * @var int
	 */
	private $other_author_id;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id        = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->author_id       = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->other_author_id = self::factory()->user->create( array( 'role' => 'author' ) );

		// Register the comic MIME types so upload paths accept .cbz files.
		if ( class_exists( 'NV_oOS_Comic_Reader_Mime' ) ) {
			NV_oOS_Comic_Reader_Mime::init();
		}

		// Register the series/collection taxonomies.
		if ( class_exists( 'NV_oOS_Comic_Reader_Taxonomy' ) ) {
			NV_oOS_Comic_Reader_Taxonomy::register_taxonomies();
		}

		// Ensure REST routes are registered. The addon's bootstrap hooks
		// register_routes onto rest_api_init; the global REST server fires
		// the action once when first created.
		do_action( 'rest_api_init' );
	}

	/**
	 * Create a comic attachment fixture backed by a real file on disk.
	 *
	 * @param string $name     Filename.
	 * @param string $contents Raw file bytes.
	 * @param int    $author   Attachment author user ID.
	 * @return int Attachment ID.
	 */
	private function create_comic_attachment( $name, $contents, $author = 0 ) {
		$uploads = wp_upload_dir();
		if ( ! file_exists( $uploads['path'] ) ) {
			wp_mkdir_p( $uploads['path'] );
		}

		$file = wp_unique_filename( $uploads['path'], $name );
		file_put_contents( $uploads['path'] . '/' . $file, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$attachment_id = self::factory()->attachment->create_object(
			$uploads['path'] . '/' . $file,
			0,
			array(
				'post_mime_type' => 'application/zip',
				'post_title'     => pathinfo( $name, PATHINFO_FILENAME ),
				'post_author'    => $author,
			)
		);

		return $attachment_id;
	}

	/**
	 * Build a minimal CBZ (zip) archive containing a PNG page and ComicInfo.xml.
	 *
	 * @return string CBZ file contents.
	 */
	private function build_cbz_contents() {
		// 1x1 transparent PNG.
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );

		$tmp = tempnam( sys_get_temp_dir(), 'nvoos-cbz-' );
		$zip = new ZipArchive();
		// OVERWRITE avoids the PHP 8.3+ deprecation for opening empty files.
		$zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'page_001.png', $png );
		$zip->addFromString( 'ComicInfo.xml', '<ComicInfo><Title>Test Comic</Title></ComicInfo>' );
		$zip->close();

		$contents = file_get_contents( $tmp );
		unlink( $tmp );

		return $contents;
	}

	/**
	 * Test health endpoint returns ok.
	 *
	 * @return void
	 */
	public function test_health_endpoint() {
		$request  = new WP_REST_Request( 'GET', '/nvoos-comic-reader/v1/health' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'ok', $data['status'] );
	}

	/**
	 * Test manifest endpoint.
	 *
	 * @return void
	 */
	public function test_manifest_endpoint() {
		$request  = new WP_REST_Request( 'GET', '/nvoos-comic-reader/v1/manifest' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'comic-reader', $data['slug'] );
		$this->assertContains( 'cbr', $data['supported_formats'] );
		$this->assertContains( 'cbz', $data['supported_formats'] );
	}

	/**
	 * Test upload requires permission.
	 *
	 * @return void
	 */
	public function test_upload_requires_permission() {
		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'POST', '/nvoos-comic-reader/v1/upload' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test delete requires permission.
	 *
	 * @return void
	 */
	public function test_delete_requires_permission() {
		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'DELETE', '/nvoos-comic-reader/v1/comics/1/delete' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test listing comics returns a paginated response for logged-in users.
	 *
	 * @return void
	 */
	public function test_list_comics_returns_paginated() {
		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/nvoos-comic-reader/v1/comics' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'comics', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'page', $data );
		$this->assertArrayHasKey( 'total_pages', $data );
	}

	/**
	 * Test listing comics requires login.
	 *
	 * @return void
	 */
	public function test_list_comics_requires_login() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/nvoos-comic-reader/v1/comics' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test upload rejects a renamed text file (magic-byte validation).
	 *
	 * @return void
	 */
	public function test_upload_rejects_non_archive_bytes() {
		wp_set_current_user( $this->admin_id );

		$tmp = tempnam( sys_get_temp_dir(), 'nvoos-fake-' );
		file_put_contents( $tmp, 'this is not a comic archive' );

		$request = new WP_REST_Request( 'POST', '/nvoos-comic-reader/v1/upload' );
		$request->set_file_params(
			array(
				'file' => array(
					'name'     => 'fake.cbz',
					'tmp_name' => $tmp,
					'size'     => 26,
					'error'    => 0,
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		unlink( $tmp );

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'invalid_archive', $response->get_data()['code'] );
	}

	/**
	 * Test upload rejects files above the filterable size cap.
	 *
	 * @return void
	 */
	public function test_upload_rejects_oversized_files() {
		wp_set_current_user( $this->admin_id );

		add_filter(
			'nvoos_comic_reader_max_upload_bytes',
			function () {
				return 10;
			}
		);

		$tmp = tempnam( sys_get_temp_dir(), 'nvoos-big-' );
		file_put_contents( $tmp, str_repeat( 'a', 1024 ) );

		$request = new WP_REST_Request( 'POST', '/nvoos-comic-reader/v1/upload' );
		$request->set_file_params(
			array(
				'file' => array(
					'name'     => 'big.cbz',
					'tmp_name' => $tmp,
					'size'     => 1024,
					'error'    => 0,
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		unlink( $tmp );

		$this->assertEquals( 413, $response->get_status() );
	}

	/**
	 * Test the upload capability can be overridden via filter.
	 *
	 * @return void
	 */
	public function test_upload_capability_filter() {
		wp_set_current_user( $this->author_id ); // Author has upload_files.

		add_filter(
			'nvoos_comic_reader_upload_capability',
			function () {
				return 'manage_options';
			}
		);

		$request  = new WP_REST_Request( 'POST', '/nvoos-comic-reader/v1/upload' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test successful upload of a valid CBZ via the REST endpoint.
	 *
	 * Uses the nvoos_comic_reader_upload_handler seam because WP core's
	 * media_handle_upload requires a genuinely HTTP-uploaded temp file
	 * (is_uploaded_file) which PHPUnit cannot produce.
	 *
	 * @return void
	 */
	public function test_upload_valid_cbz() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available.' );
		}

		wp_set_current_user( $this->admin_id );

		add_filter(
			'nvoos_comic_reader_upload_handler',
			function ( $handler ) {
				return function () {
					$uploads = wp_upload_dir();
					if ( ! file_exists( $uploads['path'] ) ) {
						wp_mkdir_p( $uploads['path'] );
					}
					$file = wp_unique_filename( $uploads['path'], 'handled.cbz' );
					file_put_contents( $uploads['path'] . '/' . $file, $this->build_cbz_contents() ); // phpcs:ignore WordPress.WP.AlternativeFunctions

					return self::factory()->attachment->create_object(
						$uploads['path'] . '/' . $file,
						0,
						array(
							'post_mime_type' => 'application/zip',
							'post_title'     => 'real',
							'post_author'    => get_current_user_id(),
						)
					);
				};
			}
		);

		$tmp = tempnam( sys_get_temp_dir(), 'nvoos-real-' );
		file_put_contents( $tmp, $this->build_cbz_contents() );

		// media_handle_upload reads $_FILES directly; mirror into the request too.
		$_FILES['file'] = array(
			'name'     => 'real.cbz',
			'tmp_name' => $tmp,
			'size'     => (int) filesize( $tmp ),
			'error'    => 0,
		);

		$request = new WP_REST_Request( 'POST', '/nvoos-comic-reader/v1/upload' );
		$request->set_file_params(
			array(
				'file' => array(
					'name'     => 'real.cbz',
					'tmp_name' => $tmp,
					'size'     => (int) filesize( $tmp ),
					'error'    => 0,
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		unset( $_FILES['file'] );
		unlink( $tmp );

		$this->assertEquals( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertEquals( 'CBZ', $data['format'] );
	}

	/**
	 * Test delete is scoped to the comic owner (non-admin authors cannot
	 * delete another author's comics).
	 *
	 * @return void
	 */
	public function test_delete_scoped_to_owner() {
		$attachment_id = $this->create_comic_attachment(
			'owned.cbz',
			"PK\x03\x04" . str_repeat( 'x', 64 ),
			$this->author_id
		);

		// Another author holds delete_posts but not delete_others_posts.
		wp_set_current_user( $this->other_author_id );
		$request  = new WP_REST_Request( 'DELETE', '/nvoos-comic-reader/v1/comics/' . $attachment_id . '/delete' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 403, $response->get_status() );

		// The owner can delete their own comic.
		wp_set_current_user( $this->author_id );
		$request  = new WP_REST_Request( 'DELETE', '/nvoos-comic-reader/v1/comics/' . $attachment_id . '/delete' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test the file endpoint serves the full archive for logged-in users.
	 *
	 * @return void
	 */
	public function test_file_endpoint_serves_archive() {
		$contents      = "PK\x03\x04" . str_repeat( 'y', 256 );
		$attachment_id = $this->create_comic_attachment( 'plain.cbz', $contents, $this->admin_id );

		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/nvoos-comic-reader/v1/comics/' . $attachment_id . '/file' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( $contents, $response->get_data() );
		$this->assertEquals( (string) strlen( $contents ), $response->get_headers()['Content-Length'] );
	}

	/**
	 * Test the file endpoint honours HTTP Range requests.
	 *
	 * @return void
	 */
	public function test_file_endpoint_supports_range_requests() {
		$contents      = "PK\x03\x04" . str_repeat( 'z', 512 );
		$attachment_id = $this->create_comic_attachment( 'range.cbz', $contents, $this->admin_id );

		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'GET', '/nvoos-comic-reader/v1/comics/' . $attachment_id . '/file' );
		$request->add_header( 'Range', 'bytes=0-3' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 206, $response->get_status() );
		$this->assertEquals( 'PK' . "\x03\x04", $response->get_data() );
		$headers = $response->get_headers();
		$this->assertEquals( '4', $headers['Content-Length'] );
		$this->assertEquals( 'bytes 0-3/' . strlen( $contents ), $headers['Content-Range'] );
	}

	/**
	 * Test unsatisfiable ranges return 416.
	 *
	 * @return void
	 */
	public function test_file_endpoint_rejects_invalid_range() {
		$contents      = "PK\x03\x04" . str_repeat( 'w', 128 );
		$attachment_id = $this->create_comic_attachment( 'invalid-range.cbz', $contents, $this->admin_id );

		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'GET', '/nvoos-comic-reader/v1/comics/' . $attachment_id . '/file' );
		$request->add_header( 'Range', 'bytes=99999-100000' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 416, $response->get_status() );
	}

	/**
	 * Test server-side cover generation for CBZ archives.
	 *
	 * @return void
	 */
	public function test_cover_generation_for_cbz() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available.' );
		}

		$attachment_id = $this->create_comic_attachment( 'cover.cbz', $this->build_cbz_contents(), $this->admin_id );

		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'POST', '/nvoos-comic-reader/v1/comics/' . $attachment_id . '/cover/generate' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertGreaterThan( 0, $data['cover_id'] );
		$this->assertNotEmpty( $data['url'] );

		// The comic payload now exposes the cached cover.
		wp_set_current_user( $this->subscriber_id );
		$request  = new WP_REST_Request( 'GET', '/nvoos-comic-reader/v1/comics/' . $attachment_id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertNotEmpty( $response->get_data()['cover_url'] );
	}

	/**
	 * Test cover generation is idempotent (second call reuses the cache).
	 *
	 * @return void
	 */
	public function test_cover_generation_is_idempotent() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available.' );
		}

		$attachment_id = $this->create_comic_attachment( 'cover2.cbz', $this->build_cbz_contents(), $this->admin_id );

		wp_set_current_user( $this->admin_id );

		$request   = new WP_REST_Request( 'POST', '/nvoos-comic-reader/v1/comics/' . $attachment_id . '/cover/generate' );
		$response1 = rest_get_server()->dispatch( $request );
		$response2 = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response1->get_status() );
		$this->assertEquals( 200, $response2->get_status() );
		$this->assertEquals( $response1->get_data()['cover_id'], $response2->get_data()['cover_id'] );
	}

	/**
	 * Test deleting a comic also removes its generated cover.
	 *
	 * @return void
	 */
	public function test_delete_removes_generated_cover() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension not available.' );
		}

		$attachment_id = $this->create_comic_attachment( 'cover3.cbz', $this->build_cbz_contents(), $this->admin_id );

		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'POST', '/nvoos-comic-reader/v1/comics/' . $attachment_id . '/cover/generate' );
		$response = rest_get_server()->dispatch( $request );
		$cover_id = $response->get_data()['cover_id'];

		$request  = new WP_REST_Request( 'DELETE', '/nvoos-comic-reader/v1/comics/' . $attachment_id . '/delete' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertNull( get_post( $cover_id ), 'Generated cover attachment should be deleted with the comic.' );
	}

	/**
	 * Test per-user reading progress save and read.
	 *
	 * @return void
	 */
	public function test_progress_save_and_read() {
		$attachment_id = $this->create_comic_attachment( 'progress.cbz', "PK\x03\x04" . str_repeat( 'p', 64 ), $this->admin_id );

		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'POST', '/nvoos-comic-reader/v1/comics/' . $attachment_id . '/progress' );
		$request->set_body(
			wp_json_encode(
				array(
					'page'      => 12,
					'total'     => 30,
					'completed' => false,
				)
			)
		);
		$request->set_header( 'Content-Type', 'application/json' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['saved'] );
		$this->assertEquals( 12, $data['progress']['page'] );

		// Read it back.
		$request  = new WP_REST_Request( 'GET', '/nvoos-comic-reader/v1/comics/' . $attachment_id . '/progress' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 12, $response->get_data()['progress']['page'] );

		// Progress is per-user: a different user sees nothing.
		wp_set_current_user( $this->author_id );
		$request  = new WP_REST_Request( 'GET', '/nvoos-comic-reader/v1/comics/' . $attachment_id . '/progress' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertNull( $response->get_data()['progress'] );
	}

	/**
	 * Test the progress map endpoint and that the comic payload embeds it.
	 *
	 * @return void
	 */
	public function test_progress_map_endpoint() {
		$attachment_id = $this->create_comic_attachment( 'progmap.cbz', "PK\x03\x04" . str_repeat( 'm', 64 ), $this->admin_id );

		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'POST', '/nvoos-comic-reader/v1/comics/' . $attachment_id . '/progress' );
		$request->set_body(
			wp_json_encode(
				array(
					'page'      => 3,
					'total'     => 10,
					'completed' => false,
				)
			)
		);
		$request->set_header( 'Content-Type', 'application/json' );
		rest_get_server()->dispatch( $request );

		$request  = new WP_REST_Request( 'GET', '/nvoos-comic-reader/v1/comics/progress' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayHasKey( $attachment_id, $response->get_data()['progress'] );
	}

	/**
	 * Test ComicInfo metadata import sets the series term and direction meta.
	 *
	 * @return void
	 */
	public function test_save_metadata_sets_series_and_direction() {
		$attachment_id = $this->create_comic_attachment( 'meta.cbz', "PK\x03\x04" . str_repeat( 't', 64 ), $this->author_id );

		wp_set_current_user( $this->author_id );

		$payload = array(
			'title'      => 'One Piece Vol 1',
			'series'     => 'One Piece',
			'number'     => '1',
			'writer'     => 'Eiichiro Oda',
			'publisher'  => 'Shueisha',
			'rtl'        => 'Yes',
			'manga'      => 'Yes',
			'page_count' => '200',
		);

		$request = new WP_REST_Request( 'POST', '/nvoos-comic-reader/v1/comics/' . $attachment_id . '/metadata' );
		$request->set_body( wp_json_encode( $payload ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['saved'] );

		// Series term was applied.
		$series = wp_get_object_terms(
			$attachment_id,
			NV_oOS_Comic_Reader_Taxonomy::SERIES_TAXONOMY,
			array( 'fields' => 'names' )
		);
		$this->assertContains( 'One Piece', $series );

		// RTL flag persisted as reading direction.
		$this->assertEquals( 'rtl', get_post_meta( $attachment_id, '_nvoos_comic_reading_direction', true ) );
	}

	/**
	 * Test metadata import requires edit capability.
	 *
	 * @return void
	 */
	public function test_save_metadata_requires_edit_permission() {
		$attachment_id = $this->create_comic_attachment( 'meta2.cbz', "PK\x03\x04" . str_repeat( 'u', 64 ), $this->admin_id );

		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'POST', '/nvoos-comic-reader/v1/comics/' . $attachment_id . '/metadata' );
		$request->set_body( wp_json_encode( array( 'title' => 'Nope' ) ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test editing comic details via PUT.
	 *
	 * @return void
	 */
	public function test_update_comic_edits_details() {
		$attachment_id = $this->create_comic_attachment( 'editme.cbz', "PK\x03\x04" . str_repeat( 'e', 64 ), $this->author_id );

		wp_set_current_user( $this->author_id );

		$request = new WP_REST_Request( 'PUT', '/nvoos-comic-reader/v1/comics/' . $attachment_id );
		$request->set_body(
			wp_json_encode(
				array(
					'title'             => 'Edited Title',
					'series'            => 'My Series',
					'writer'            => 'A. Writer',
					'reading_direction' => 'rtl',
				)
			)
		);
		$request->set_header( 'Content-Type', 'application/json' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'Edited Title', $data['title'] );
		$this->assertContains( 'My Series', $data['series'] );
		$this->assertEquals( 'rtl', $data['reading_direction'] );
		$this->assertEquals( 'A. Writer', $data['metadata']['writer'] );
	}

	/**
	 * Test collections CRUD flow.
	 *
	 * @return void
	 */
	public function test_collections_crud() {
		$attachment_id = $this->create_comic_attachment( 'collect.cbz', "PK\x03\x04" . str_repeat( 'c', 64 ), $this->admin_id );

		wp_set_current_user( $this->author_id );

		// Create.
		$request = new WP_REST_Request( 'POST', '/nvoos-comic-reader/v1/collections' );
		$request->set_body( wp_json_encode( array( 'name' => 'Favorites' ) ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 201, $response->get_status() );
		$collection_id = $response->get_data()['id'];

		// Add an item.
		$request = new WP_REST_Request( 'POST', '/nvoos-comic-reader/v1/collections/' . $collection_id . '/items' );
		$request->set_body( wp_json_encode( array( 'comic_id' => $attachment_id ) ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertContains( $attachment_id, $response->get_data()['items'] );

		// List.
		$request  = new WP_REST_Request( 'GET', '/nvoos-comic-reader/v1/collections' );
		$response = rest_get_server()->dispatch( $request );
		$names    = wp_list_pluck( $response->get_data()['collections'], 'name' );
		$this->assertContains( 'Favorites', $names );

		// Remove the item.
		$request  = new WP_REST_Request( 'DELETE', '/nvoos-comic-reader/v1/collections/' . $collection_id . '/items/' . $attachment_id );
		$response = rest_get_server()->dispatch( $request );
		$this->assertNotContains( $attachment_id, $response->get_data()['items'] );
	}

	/**
	 * Test list comics supports series filtering.
	 *
	 * @return void
	 */
	public function test_list_comics_filters_by_series() {
		$in_series  = $this->create_comic_attachment( 's1.cbz', "PK\x03\x04" . str_repeat( 's', 64 ), $this->admin_id );
		$out_series = $this->create_comic_attachment( 's2.cbz', "PK\x03\x04" . str_repeat( 's', 64 ), $this->admin_id );

		wp_set_object_terms( $in_series, 'Batman', NV_oOS_Comic_Reader_Taxonomy::SERIES_TAXONOMY );
		wp_set_object_terms( $out_series, 'Superman', NV_oOS_Comic_Reader_Taxonomy::SERIES_TAXONOMY );

		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'GET', '/nvoos-comic-reader/v1/comics' );
		$request->set_param( 'series', 'batman' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$ids = wp_list_pluck( $response->get_data()['comics'], 'id' );
		$this->assertContains( $in_series, $ids );
		$this->assertNotContains( $out_series, $ids );
	}

	/**
	 * Test the series facets endpoint.
	 *
	 * @return void
	 */
	public function test_list_series_facets() {
		$attachment_id = $this->create_comic_attachment( 'facet.cbz', "PK\x03\x04" . str_repeat( 'f', 64 ), $this->admin_id );
		wp_set_object_terms( $attachment_id, 'Saga', NV_oOS_Comic_Reader_Taxonomy::SERIES_TAXONOMY );

		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/nvoos-comic-reader/v1/series' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$names = wp_list_pluck( $response->get_data()['series'], 'name' );
		$this->assertContains( 'Saga', $names );
	}
}
