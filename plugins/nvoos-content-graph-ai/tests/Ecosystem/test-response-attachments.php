<?php
/**
 * Response Attachments port tests (Wave D1e).
 *
 * Characterization suite for the ported
 * `NvoosContentGraphAi\Chat\ResponseAttachments`. Assertions pin behaviour
 * against the base plugin's `WP_MCP_AI_Response_Attachments` (ecosystem port
 * plan, principle: behaviour-preserving). Downloads run through a fake
 * client injected via the `create_file_client()` seam.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Chat\MessageAttachments;
use NvoosContentGraphAi\Chat\ResponseAttachments;

/**
 * Subclass wiring a recording download client.
 */
class Test_Response_Attachments_With_Client extends ResponseAttachments {
	public static $client;

	protected static function create_file_client() {
		if ( null === self::$client ) {
			self::$client = new Test_Attachments_File_Client_Double();
		}
		return self::$client;
	}
}

/**
 * Subclass exposing protected helpers.
 */
class Test_Response_Attachments_Exposed extends ResponseAttachments {
	public static function segments( array $response ): array {
		return self::collect_file_segments_from_response( $response );
	}

	public static function extract_id( array $segment ): string {
		return self::extract_file_id_from_segment( $segment );
	}

	public static function filename_from( array $segment ): string {
		return self::extract_filename_from_segment( $segment );
	}

	public static function with_extension( $filename, $content_type ): string {
		return self::ensure_filename_extension( $filename, $content_type );
	}

	public static function fallback_name( $content_type ): string {
		return self::generate_fallback_filename( $content_type );
	}

	public static function normalise_mime( $content_type, $file_path ): string {
		return self::normalise_mime_type( $content_type, $file_path );
	}

	public static function mime_extension( $content_type ): string {
		return self::map_mime_type_to_extension( $content_type );
	}

	public static function attachment_title( $filename ): string {
		return self::generate_attachment_title( $filename );
	}

	public static function store( array $download, array $segment ) {
		return self::store_downloaded_file( $download, $segment );
	}
}

/**
 * @group chat
 */
class Test_Response_Attachments extends \WP_UnitTestCase {

	private $created_attachments = array();

	public function setUp(): void {
		parent::setUp();
		MessageAttachments::reset_deleted_file_cache();
		Test_Attachments_File_Client_Double::reset();
		Test_Response_Attachments_With_Client::$client = null;
		\wp_set_current_user( 0 );
	}

	public function tearDown(): void {
		foreach ( $this->created_attachments as $attachment_id ) {
			\wp_delete_attachment( $attachment_id, true );
		}
		\wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function file_response( $content ): array {
		return array(
			'choices' => array(
				array( 'message' => array( 'content' => $content ) ),
			),
		);
	}

	public function test_collect_segments_from_nested_response_shapes(): void {
		$response = array(
			'response' => $this->file_response(
				array(
					array(
						'type'    => 'file',
						'file_id' => 'file-inner-1',
					),
				)
			),
			'output'   => array(
				array(
					'type'    => 'file',
					'file_id' => 'file-output-1',
				),
			),
		);

		$segments = Test_Response_Attachments_Exposed::segments( $response );

		$this->assertCount( 2, $segments );
		$this->assertSame( 'file-inner-1', $segments[0]['file_id'] );
		$this->assertSame( 'file-output-1', $segments[1]['file_id'] );
	}

	public function test_collect_segments_ignores_plain_string_content(): void {
		$segments = Test_Response_Attachments_Exposed::segments(
			$this->file_response( 'Just a plain text answer.' )
		);

		$this->assertSame( array(), $segments );
	}

	public function test_extract_file_id_from_various_shapes(): void {
		$this->assertSame( 'file-top-1', Test_Response_Attachments_Exposed::extract_id( array( 'file_id' => 'file-top-1' ) ) );
		$this->assertSame( 'file-nested-1', Test_Response_Attachments_Exposed::extract_id( array( 'file' => array( 'file_id' => 'file-nested-1' ) ) ) );
		$this->assertSame( 'file-ann-1', Test_Response_Attachments_Exposed::extract_id( array( 'annotations' => array( array( 'file_id' => 'file-ann-1' ) ) ) ) );
		// IDs that don't look like provider files are ignored.
		$this->assertSame( '', Test_Response_Attachments_Exposed::extract_id( array( 'id' => 'local-42-abc' ) ) );
	}

	public function test_extract_filename_from_segment(): void {
		$this->assertSame( 'report.pdf', Test_Response_Attachments_Exposed::filename_from( array( 'filename' => 'report.pdf' ) ) );
		$this->assertSame( 'nested.txt', Test_Response_Attachments_Exposed::filename_from( array( 'file' => array( 'filename' => 'nested.txt' ) ) ) );
		$this->assertSame( '', Test_Response_Attachments_Exposed::filename_from( array( 'type' => 'file' ) ) );
	}

	public function test_ensure_filename_extension(): void {
		$this->assertSame( 'report.pdf', Test_Response_Attachments_Exposed::with_extension( 'report.pdf', 'application/pdf' ) );
		$this->assertSame( 'report.pdf', Test_Response_Attachments_Exposed::with_extension( 'report', 'application/pdf' ) );
		$this->assertSame( 'openai-file.pdf', Test_Response_Attachments_Exposed::with_extension( '', 'application/pdf' ) );
		$this->assertSame( 'report', Test_Response_Attachments_Exposed::with_extension( 'report', '' ) );
	}

	public function test_fallback_filename_pattern(): void {
		$this->assertSame( 1, preg_match( '/^openai-file-\d{8}-\d{6}\.pdf$/', Test_Response_Attachments_Exposed::fallback_name( 'application/pdf' ) ) );
		$this->assertSame( 1, preg_match( '/^openai-file-\d{8}-\d{6}\.bin$/', Test_Response_Attachments_Exposed::fallback_name( 'application/x-unknown' ) ) );
	}

	public function test_normalise_mime_type(): void {
		$this->assertSame( 'text/csv', Test_Response_Attachments_Exposed::normalise_mime( 'text/csv', '/tmp/x.csv' ) );
		$this->assertSame( 'application/octet-stream', Test_Response_Attachments_Exposed::normalise_mime( '', '/tmp/x.unknown' ) );
		$this->assertSame( 'text/plain', Test_Response_Attachments_Exposed::normalise_mime( 'application/octet-stream', '/tmp/x.txt' ) );
	}

	public function test_mime_type_to_extension(): void {
		$this->assertSame( 'pdf', Test_Response_Attachments_Exposed::mime_extension( 'application/pdf' ) );
		// wp_get_mime_types() maps text/plain to txt|asc|c|cc|h|srt — the
		// last variant wins in both implementations (behaviour-preserving).
		$this->assertSame( 'srt', Test_Response_Attachments_Exposed::mime_extension( 'text/plain' ) );
		$this->assertSame( '', Test_Response_Attachments_Exposed::mime_extension( '' ) );
		$this->assertSame( '', Test_Response_Attachments_Exposed::mime_extension( 'application/x-never-heard-of' ) );
	}

	public function test_attachment_title_generation(): void {
		$this->assertSame( 'Quarterly Report', Test_Response_Attachments_Exposed::attachment_title( 'quarterly_report.pdf' ) );
		$this->assertSame( 'My File', Test_Response_Attachments_Exposed::attachment_title( 'my-file.txt' ) );
		$this->assertSame( 'Assistant file', Test_Response_Attachments_Exposed::attachment_title( '' ) );
	}

	public function test_store_downloaded_file_rejects_empty_body(): void {
		$result = Test_Response_Attachments_Exposed::store( array( 'body' => '' ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_response_file_empty', $result->get_error_code() );
	}

	public function test_store_downloaded_file_persists_attachment(): void {
		$unique = 'summary-' . \wp_generate_password( 6, false );
		$result = Test_Response_Attachments_Exposed::store(
			array(
				'body'         => 'Hello response file.',
				'filename'     => $unique,
				'content_type' => 'text/plain',
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertGreaterThan( 0, $result['attachment_id'] );
		$this->assertSame( $unique . '.srt', $result['file_name'] );
		$this->assertSame( 'text/plain', $result['mime_type'] );
		$this->assertGreaterThan( 0, $result['bytes'] );
		$this->assertNotSame( '', $result['hash'] );
		$this->created_attachments[] = $result['attachment_id'];
	}

	public function test_handle_chat_response_noops_without_file_segments(): void {
		$client = new Test_Attachments_File_Client_Double();
		$client->download_result = new \WP_Error( 'x', 'should not be called' );
		Test_Response_Attachments_With_Client::$client = $client;

		Test_Response_Attachments_With_Client::handle_chat_response(
			0,
			$this->file_response( 'No files here.' ),
			null
		);

		$this->assertSame( array(), Test_Attachments_File_Client_Double::$deleted );
	}

	public function test_handle_chat_response_skips_download_for_known_file(): void {
		// Register a fake mapping via post meta on a real attachment.
		$uploads       = \wp_upload_dir();
		$path          = $uploads['path'] . '/known.txt';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture generation.
		file_put_contents( $path, 'x' );
		$attachment_id = self::factory()->attachment->create_upload_object( $path );
		$this->created_attachments[] = $attachment_id;

		$helper = new MessageAttachments();
		$helper->save_openai_file_metadata_for_attachment(
			$attachment_id,
			array( 'file_id' => 'file-known-1', 'filename' => 'known.txt' )
		);

		$client = new Test_Attachments_File_Client_Double();
		$client->download_result = new \WP_Error( 'x', 'should not be called' );
		Test_Response_Attachments_With_Client::$client = $client;

		Test_Response_Attachments_With_Client::handle_chat_response(
			0,
			$this->file_response(
				array(
					array( 'type' => 'file', 'file_id' => 'file-known-1' ),
				)
			),
			null
		);

		// No crash, no additional attachment created.
		$this->assertSame( $attachment_id, $helper->get_attachment_id_for_openai_file( 'file-known-1' ) );
	}

	public function test_handle_chat_response_degrades_when_download_fails(): void {
		$client = new Test_Attachments_File_Client_Double();
		$client->download_result = new \WP_Error( 'wp_mcp_ai_file_api_unavailable', 'nope' );
		Test_Response_Attachments_With_Client::$client = $client;

		Test_Response_Attachments_With_Client::handle_chat_response(
			0,
			$this->file_response(
				array(
					array( 'type' => 'file', 'file_id' => 'file-new-9' ),
				)
			),
			null
		);

		// Standalone degradation: no crash, nothing persisted.
		$this->assertSame( 0, ( new MessageAttachments() )->get_attachment_id_for_openai_file( 'file-new-9' ) );
	}

	public function test_handle_chat_response_stores_downloaded_file(): void {
		$client = new Test_Attachments_File_Client_Double();
		$client->download_result = array(
			'body'         => 'The generated report.',
			'filename'     => 'generated-report',
			'content_type' => 'text/plain',
		);
		Test_Response_Attachments_With_Client::$client = $client;

		Test_Response_Attachments_With_Client::handle_chat_response(
			0,
			$this->file_response(
				array(
					array( 'type' => 'file', 'file_id' => 'file-new-10' ),
				)
			),
			null
		);

		$helper = new MessageAttachments();
		$attachment_id = $helper->get_attachment_id_for_openai_file( 'file-new-10' );
		$this->assertGreaterThan( 0, $attachment_id );
		$this->created_attachments[] = $attachment_id;

		$meta = \get_post_meta( $attachment_id, MessageAttachments::OPENAI_FILE_META_KEY, true );
		$this->assertSame( 'file-new-10', $meta['file_id'] );
		$this->assertSame( 'assistants', $meta['purpose'] );
	}
}
