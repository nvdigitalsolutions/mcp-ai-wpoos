<?php
/**
 * Message Attachments port tests (Wave D1e).
 *
 * Characterization suite for the ported
 * `NvoosContentGraphAi\Chat\MessageAttachments`. Assertions pin behaviour
 * against the base plugin's `WP_MCP_AI_Message_Attachments` (ecosystem port
 * plan, principle: behaviour-preserving). Remote file APIs are exercised
 * through fake collaborators injected via the constructor / late static
 * binding seams.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Chat\FileServiceBridge;
use NvoosContentGraphAi\Chat\MessageAttachments;

/**
 * File-service double reporting no remote File API — pins the
 * local-reference path deterministically in BOTH matrices (in monolith,
 * the real bridge delegates to the base's live upload API, which cannot
 * run in CI).
 */
class Test_File_Service_No_Remote {
	public function detect_provider_from_model( string $model ): string {
		return 'unknown';
	}

	public function provider_supports_files( string $provider ): bool {
		return false;
	}

	public function upload_file( string $file_path, string $mime_type, string $provider, array $options = array() ) {
		return new \WP_Error( 'wp_mcp_ai_file_api_unavailable', 'not called' );
	}
}

/**
 * Recording file client double (static so late-static-bound instances share it).
 */
class Test_Attachments_File_Client_Double {
	public static $deleted = array();
	public $download_result;
	public $retrieve_result;

	public function download_file( string $file_id ) {
		return $this->download_result;
	}

	public function retrieve_file( string $file_id ) {
		return $this->retrieve_result;
	}

	public function delete_file( string $file_id ) {
		self::$deleted[] = $file_id;
		return true;
	}

	public static function reset(): void {
		self::$deleted = array();
	}
}

/**
 * Subclass wiring the recording client into late-static-bound instances.
 */
class Test_Message_Attachments_With_Client extends MessageAttachments {
	public function __construct( $client = null ) {
		parent::__construct( 'openai', '', new FileServiceBridge(), null === $client ? new Test_Attachments_File_Client_Double() : $client );
	}
}

/**
 * @group chat
 */
class Test_Message_Attachments extends \WP_UnitTestCase {

	private $helper;
	private $attachment_ids = array();

	public function setUp(): void {
		parent::setUp();
		MessageAttachments::reset_deleted_file_cache();
		Test_Attachments_File_Client_Double::reset();
		\wp_set_current_user( 0 );
		$this->helper = new MessageAttachments();
	}

	public function tearDown(): void {
		foreach ( $this->attachment_ids as $attachment_id ) {
			\wp_delete_attachment( $attachment_id, true );
		}
		remove_all_filters( 'wp_mcp_ai_max_attachment_bytes' );
		remove_all_filters( 'wp_mcp_ai_allowed_image_mimes' );
		\wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Create a real attachment in the uploads dir.
	 */
	private function create_upload_attachment( $name, $contents ): int {
		$uploads = \wp_upload_dir();
		$path    = $uploads['path'] . '/' . $name;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture generation.
		file_put_contents( $path, $contents );

		$attachment_id = self::factory()->attachment->create_upload_object( $path );
		$this->attachment_ids[] = $attachment_id;
		return $attachment_id;
	}

	/**
	 * Create a real PNG attachment in the uploads dir.
	 */
	private function create_png_attachment( $name = 'test-attachment.png' ): int {
		return $this->create_upload_attachment(
			$name,
			base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' )
		);
	}

	public function test_allowed_mime_types_provider_variants(): void {
		$openai_images = MessageAttachments::get_allowed_mime_types( 'image', 'openai' );
		$this->assertContains( 'image/png', $openai_images );
		$this->assertNotContains( 'image/svg+xml', $openai_images );

		$gemini_images = MessageAttachments::get_allowed_mime_types( 'image', 'gemini' );
		$this->assertContains( 'image/svg+xml', $gemini_images );

		$files = MessageAttachments::get_allowed_mime_types( 'file' );
		$this->assertContains( 'application/pdf', $files );
		$this->assertContains( 'text/plain', $files );

		$both = MessageAttachments::get_allowed_mime_types();
		$this->assertArrayHasKey( 'image', $both );
		$this->assertArrayHasKey( 'file', $both );
	}

	public function test_allowed_mime_types_honours_filters(): void {
		add_filter( 'wp_mcp_ai_allowed_image_mimes', static function () {
			return array( 'image/tiff' );
		} );

		$this->assertSame( array( 'image/tiff' ), MessageAttachments::get_allowed_mime_types( 'image' ) );
	}

	public function test_is_image_mime_type(): void {
		$this->assertTrue( MessageAttachments::is_image_mime_type( 'image/png' ) );
		$this->assertFalse( MessageAttachments::is_image_mime_type( 'application/pdf' ) );
		$this->assertFalse( MessageAttachments::is_image_mime_type( 'image/svg+xml', 'openai' ) );
		$this->assertTrue( MessageAttachments::is_image_mime_type( 'image/svg+xml', 'gemini' ) );
	}

	public function test_prepare_text_segment_sanitises_and_trims(): void {
		$prepared = $this->helper->prepare_input_text_segment( '  <b>Hello</b> <script>bad()</script>  ' );

		$this->assertSame( 'text', $prepared['type'] );
		$this->assertSame( '<b>Hello</b> bad()', $prepared['text'] );
	}

	public function test_prepare_image_segment_from_url(): void {
		$prepared = $this->helper->prepare_input_image_segment(
			array(
				'type'    => 'image_url',
				'url'     => 'https://example.com/img.png',
				'caption' => 'A photo',
			)
		);

		$this->assertSame( 'input_image', $prepared['type'] );
		$this->assertSame( 'https://example.com/img.png', $prepared['image_url']['url'] );
		$this->assertSame( 'https://example.com/img.png', $prepared['url'] );
		$this->assertSame( 'A photo', $prepared['caption'] );
	}

	public function test_prepare_image_segment_rejects_bad_scheme_and_missing_source(): void {
		$bad = $this->helper->prepare_input_image_segment(
			array( 'type' => 'image_url', 'url' => 'ftp://example.com/img.png' )
		);
		$this->assertWPError( $bad );
		$this->assertSame( 'wp_mcp_ai_unsupported_image_url_scheme', $bad->get_error_code() );

		$missing = $this->helper->prepare_input_image_segment( array( 'type' => 'input_image' ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_missing_image_attachment', $missing->get_error_code() );
	}

	public function test_prepare_image_segment_from_attachment_uses_local_reference(): void {
		// Pin the local-reference path deterministically in both matrices
		// (see Test_File_Service_No_Remote).
		$helper        = new MessageAttachments( 'openai', '', new Test_File_Service_No_Remote() );
		$attachment_id = $this->create_png_attachment();
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$prepared = $helper->prepare_input_image_segment(
			array( 'type' => 'input_image', 'attachment_id' => $attachment_id )
		);

		$this->assertSame( 'input_image', $prepared['type'] );
		$this->assertSame( $attachment_id, $prepared['attachment_id'] );
		// Standalone mode: no remote File API → local reference id.
		$this->assertStringStartsWith( 'local-' . $attachment_id . '-', $prepared['file_id'] );
		$this->assertStringContainsString( '/test-attachment', $prepared['url'] );
		$this->assertStringEndsWith( '.png', $prepared['url'] );
		$this->assertSame( 'image/png', $prepared['mime_type'] );
	}

	public function test_prepare_image_segment_respects_size_limit(): void {
		$attachment_id = $this->create_png_attachment();
		add_filter( 'wp_mcp_ai_max_attachment_bytes', static function () {
			return 10;
		} );

		$result = $this->helper->prepare_input_image_segment(
			array( 'type' => 'input_image', 'attachment_id' => $attachment_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_attachment_too_large', $result->get_error_code() );
	}

	public function test_prepare_file_segment_requires_attachment(): void {
		$missing = $this->helper->prepare_input_file_segment( array( 'type' => 'input_file' ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_missing_file_attachment', $missing->get_error_code() );
	}

	public function test_save_and_lookup_file_metadata(): void {
		$attachment_id = $this->create_png_attachment();

		$stored = $this->helper->save_openai_file_metadata_for_attachment(
			$attachment_id,
			array(
				'file_id'  => 'file-test-123',
				'filename' => 'report.pdf',
				'bytes'    => 42,
			)
		);

		$this->assertSame( 'file-test-123', $stored['file_id'] );
		$this->assertSame( 'report.pdf', $stored['filename'] );
		$this->assertSame( 42, $stored['bytes'] );

		$this->assertSame( $attachment_id, $this->helper->get_attachment_id_for_openai_file( 'file-test-123' ) );
		$this->assertSame( 0, $this->helper->get_attachment_id_for_openai_file( 'file-unknown' ) );
		$this->assertSame( 0, $this->helper->get_attachment_id_for_openai_file( '' ) );
	}

	public function test_prepare_attachment_envelope(): void {
		// Pin the local-reference path deterministically in both matrices
		// (see Test_File_Service_No_Remote).
		$helper        = new MessageAttachments( 'openai', '', new Test_File_Service_No_Remote() );
		$attachment_id = $this->create_upload_attachment( 'envelope.txt', 'Plain text contents.' );

		$prepared = $helper->prepare_attachment( $attachment_id, 'file' );

		$this->assertSame( $attachment_id, $prepared['attachment_id'] );
		$this->assertSame( 'ready', $prepared['status'] );
		$this->assertSame( 'openai', $prepared['provider'] );
		$this->assertStringStartsWith( 'local-' . $attachment_id . '-', $prepared['file_id'] );
		$this->assertSame( 'text/plain', $prepared['mime_type'] );
		$this->assertStringStartsWith( 'envelope', $prepared['file_name'] );
		$this->assertStringEndsWith( '.txt', $prepared['file_name'] );
	}

	public function test_delete_openai_file_guards_duplicates(): void {
		$attachment_id = $this->create_png_attachment();

		Test_Message_Attachments_With_Client::delete_openai_file_for_attachment(
			$attachment_id,
			array( 'file_id' => 'file-abc' )
		);
		// Second call with the same file id is deduplicated by the static guard.
		Test_Message_Attachments_With_Client::delete_openai_file_for_attachment(
			$attachment_id,
			array( 'file_id' => 'file-abc' )
		);

		$this->assertSame( array( 'file-abc' ), Test_Attachments_File_Client_Double::$deleted );

		MessageAttachments::reset_deleted_file_cache();

		Test_Message_Attachments_With_Client::delete_openai_file_for_attachment(
			$attachment_id,
			array( 'file_id' => 'file-abc' )
		);
		$this->assertSame( array( 'file-abc', 'file-abc' ), Test_Attachments_File_Client_Double::$deleted );
	}

	public function test_init_registers_cleanup_hooks_once(): void {
		MessageAttachments::init();
		MessageAttachments::init();

		$this->assertNotFalse( has_action( 'delete_attachment', array( MessageAttachments::class, 'handle_delete_attachment' ) ) );
		$this->assertNotFalse( has_action( 'deleted_post_meta', array( MessageAttachments::class, 'handle_deleted_post_meta' ) ) );
	}
}
