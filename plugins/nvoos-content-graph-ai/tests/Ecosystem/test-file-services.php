<?php
/**
 * File service factory + OpenAI/Gemini file service port tests (Wave D2f).
 *
 * Characterization suite for `FileServiceFactory`, `OpenAiFileService`,
 * and `GeminiFileService`. Assertions mirror the base plugin's file
 * service tests: provider detection, support probing, upload flows
 * (missing key/file, multipart payload, HTTP errors), cache
 * tracking/roundtrip, cleanup, delete/retrieve/download, and Gemini
 * polling. Requests are intercepted via `pre_http_request`.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Adapter\CredentialResolver;
use NvoosContentGraphAi\Chat\FileServiceFactory;
use NvoosContentGraphAi\Chat\GeminiFileService;
use NvoosContentGraphAi\Chat\OpenAiFileService;

/**
 * @group chat
 */
class Test_File_Services extends \WP_UnitTestCase {

	/** @var array<int, string> */
	private $temp_files = array();

	public function setUp(): void {
		parent::setUp();

		\delete_option( 'nvoos_content_graph_settings' );
		\delete_option( 'wp_mcp_ai_openai_tracked_files' );
		\delete_option( 'wp_mcp_ai_gemini_tracked_files' );
		\NvoosContentGraphAi\Security\CredentialStore::delete( 'openai' );
		\NvoosContentGraphAi\Security\CredentialStore::delete( 'gemini' );
		CredentialResolver::clearCache();
	}

	public function tearDown(): void {
		\remove_all_filters( 'pre_http_request' );

		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		$this->temp_files = array();

		\delete_option( 'nvoos_content_graph_settings' );
		\delete_option( 'wp_mcp_ai_openai_tracked_files' );
		\delete_option( 'wp_mcp_ai_gemini_tracked_files' );
		\NvoosContentGraphAi\Security\CredentialStore::delete( 'openai' );
		\NvoosContentGraphAi\Security\CredentialStore::delete( 'gemini' );
		CredentialResolver::clearCache();

		parent::tearDown();
	}

	private function seed_key( string $provider, string $key ): void {
		\NvoosContentGraphAi\CoreBridge::instance()->settings->set( 'ai_api_key_' . $provider, $key );
		CredentialResolver::clearCache();
	}

	private function temp_file( string $contents = 'hello file contents' ): string {
		$file = \wp_tempnam( 'd2f-test' );
		\file_put_contents( $file, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->temp_files[] = $file;
		return $file;
	}

	/**
	 * Intercept HTTP with a URL-switching responder (replaces any previous intercept).
	 *
	 * @param callable $responder fn( $method, $url, $args ): response.
	 */
	private function intercept( callable $responder ): void {
		\remove_all_filters( 'pre_http_request' );
		\add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $responder ) {
				return $responder( $args['method'] ?? 'GET', $url, $args );
			},
			10,
			3
		);
	}

	// ─── Factory ────────────────────────────────────────────────────

	public function test_factory_provider_mapping(): void {
		$this->assertInstanceOf( OpenAiFileService::class, FileServiceFactory::get_file_service( 'openai' ) );
		$this->assertInstanceOf( GeminiFileService::class, FileServiceFactory::get_file_service( 'gemini' ) );
		$this->assertInstanceOf( GeminiFileService::class, FileServiceFactory::get_file_service( 'google' ) );
		$this->assertNull( FileServiceFactory::get_file_service( 'anthropic' ) );

		$this->assertSame( 'openai', FileServiceFactory::detect_provider_from_model( 'gpt-4o' ) );
		$this->assertSame( 'openai', FileServiceFactory::detect_provider_from_model( 'o1-mini' ) );
		$this->assertSame( 'gemini', FileServiceFactory::detect_provider_from_model( 'gemini-2.0-flash' ) );
		$this->assertSame( 'anthropic', FileServiceFactory::detect_provider_from_model( 'claude-sonnet' ) );
		$this->assertSame( 'local', FileServiceFactory::detect_provider_from_model( 'ollama/llama3' ) );
		$this->assertSame( 'unknown', FileServiceFactory::detect_provider_from_model( 'mystery-model' ) );

		$this->assertTrue( FileServiceFactory::provider_supports_files( 'openai' ) );
		$this->assertTrue( FileServiceFactory::provider_supports_files( 'gemini' ) );
		$this->assertFalse( FileServiceFactory::provider_supports_files( 'anthropic' ) );
		$this->assertTrue( FileServiceFactory::model_supports_files( 'gpt-4o' ) );
		$this->assertFalse( FileServiceFactory::model_supports_files( 'claude-3' ) );
	}

	public function test_factory_upload_unsupported_provider(): void {
		$result = FileServiceFactory::upload_file( '/tmp/x.png', 'image/png', 'anthropic' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_unsupported_provider', $result->get_error_code() );
	}

	public function test_factory_upload_gemini_path(): void {
		$this->seed_key( 'gemini', 'gkey-1' );

		$file = $this->temp_file( 'GEMINI-PAYLOAD' );

		$captured_url = '';
		$this->intercept(
			static function ( $method, $url ) use ( &$captured_url ) {
				$captured_url = $url;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => \wp_json_encode(
						array(
							'file' => array(
								'name'  => 'files/abc',
								'uri'   => 'https://generativelanguage.googleapis.com/v1beta/files/abc',
								'state' => 'PROCESSING',
							),
						)
					),
				);
			}
		);

		$result = FileServiceFactory::upload_file( $file, 'text/plain', 'gemini' );

		$this->assertIsArray( $result );
		$this->assertSame( 'files/abc', $result['file_name'] );
		$this->assertTrue( $result['uploaded'] );
		$this->assertStringContainsString( 'generativelanguage.googleapis.com/upload/v1beta/files', $captured_url );
	}

	// ─── OpenAI file service ────────────────────────────────────────

	public function test_openai_upload_missing_key(): void {
		$service = new OpenAiFileService();

		$result = $service->upload_file( $this->temp_file() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_api_key', $result->get_error_code() );
	}

	public function test_openai_upload_missing_file(): void {
		$this->seed_key( 'openai', 'sk-1' );
		$service = new OpenAiFileService();

		$result = $service->upload_file( '/nonexistent/path/file.png' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_file_upload_missing_file', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_openai_upload_success_multipart(): void {
		$this->seed_key( 'openai', 'sk-1' );

		$file   = $this->temp_file( 'OPENAI-FILE-BYTES' );
		$calls  = array();

		$this->intercept(
			static function ( $method, $url, $args ) use ( &$calls ) {
				$calls[] = array( $method, $url, $args );

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"id":"file-42","object":"file","purpose":"assistants","filename":"test.txt"}',
				);
			}
		);

		$service = new OpenAiFileService();
		$result  = $service->upload_file(
			$file,
			array(
				'purpose'   => 'assistants',
				'filename'  => 'test.txt',
				'mime_type' => 'text/plain',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'file-42', $result['id'] );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'POST', $calls[0][0] );
		$this->assertSame( 'https://api.openai.com/v1/files', $calls[0][1] );
		$this->assertSame( 'Bearer sk-1', $calls[0][2]['headers']['Authorization'] );

		$body = $calls[0][2]['body'];
		$this->assertStringContainsString( 'name="purpose"', $body );
		$this->assertStringContainsString( 'assistants', $body );
		$this->assertStringContainsString( 'name="file"; filename="test.txt"', $body );
		$this->assertStringContainsString( 'Content-Type: text/plain', $body );
		$this->assertStringContainsString( 'OPENAI-FILE-BYTES', $body );
	}

	public function test_openai_upload_http_error(): void {
		$this->seed_key( 'openai', 'sk-1' );

		$this->intercept(
			static function () {
				return array(
					'response' => array( 'code' => 401 ),
					'body'     => '{"error":{"message":"Incorrect API key provided"}}',
				);
			}
		);

		$service = new OpenAiFileService();
		$result  = $service->upload_file( $this->temp_file(), array( 'purpose' => 'assistants' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_file_upload_failed', $result->get_error_code() );
		$this->assertSame( 'Incorrect API key provided', $result->get_error_message() );
	}

	public function test_openai_cache_tracking_roundtrip(): void {
		$service = new OpenAiFileService();

		$this->assertTrue( $service->track_uploaded_file( 'file-9', 'assistants', 'a.txt', 'https://example.com/a.txt' ) );

		$cached = $service->get_cached_file( 'https://example.com/a.txt', null, 'assistants' );
		$this->assertIsArray( $cached );
		$this->assertSame( 'file-9', $cached['file_id'] );

		// Different purpose → different cache slot.
		$this->assertNull( $service->get_cached_file( 'https://example.com/a.txt', null, 'vision' ) );

		$tracked = $service->list_tracked_files();
		$this->assertNotEmpty( $tracked );
		$this->assertSame( 'file-9', $tracked[0]['file_id'] );
	}

	public function test_openai_delete_retrieve_download(): void {
		$this->seed_key( 'openai', 'sk-1' );

		$this->intercept(
			static function ( $method, $url ) {
				if ( 'DELETE' === $method ) {
					return array( 'response' => array( 'code' => 204 ), 'body' => '' );
				}

				if ( false !== strpos( $url, '/content' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => 'file-content-bytes',
						'headers'  => array( 'content-type' => 'text/plain', 'content-disposition' => 'attachment; filename="report.txt"' ),
					);
				}

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"id":"file-5","object":"file","purpose":"assistants"}',
				);
			}
		);

		$service = new OpenAiFileService();

		$this->assertTrue( $service->delete_file( 'file-5' ) );

		$meta = $service->retrieve_file( 'file-5' );
		$this->assertIsArray( $meta );
		$this->assertSame( 'file-5', $meta['id'] );

		$download = $service->download_file( 'file-5' );
		$this->assertSame( 'file-content-bytes', $download['body'] );
		$this->assertSame( 'report.txt', $download['filename'] );
		$this->assertSame( 'text/plain', $download['content_type'] );
	}

	public function test_openai_cleanup_old_files(): void {
		$this->seed_key( 'openai', 'sk-1' );

		// Track a file as old (uploaded 2 days ago).
		$tracked = array(
			'wp_mcp_ai_openai_file_https://example.com/old.txt_assistants' => array(
				'file_id'     => 'file-old',
				'uploaded_at' => time() - 172800,
			),
		);
		\update_option( 'wp_mcp_ai_openai_tracked_files', $tracked, false );

		$this->intercept(
			static function () {
				return array( 'response' => array( 'code' => 204 ), 'body' => '' );
			}
		);

		$service = new OpenAiFileService();
		$result  = $service->cleanup_old_files();

		$this->assertSame( 1, $result['deleted_count'] );
		$this->assertSame( 0, $result['failed_count'] );
		$this->assertSame( 1, $result['total_checked'] );
		$this->assertSame( array(), \get_option( 'wp_mcp_ai_openai_tracked_files', array() ) );
	}

	// ─── Gemini file service ────────────────────────────────────────

	public function test_gemini_upload_validation_and_success(): void {
		$service = new GeminiFileService();

		// Missing file.
		$result = $service->upload_file( '/nonexistent/x.mp4', 'video/mp4' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_file_not_found', $result->get_error_code() );

		// Missing MIME type.
		$result = $service->upload_file( $this->temp_file(), '' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_mime_type', $result->get_error_code() );

		// Missing key.
		$result = $service->upload_file( $this->temp_file(), 'text/plain' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_gemini_api_key', $result->get_error_code() );

		// Success path.
		$this->seed_key( 'gemini', 'gkey-2' );
		$calls = array();

		$this->intercept(
			static function ( $method, $url, $args ) use ( &$calls ) {
				$calls[] = array( $method, $url, $args );

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"file":{"name":"files/xyz","uri":"https://example.com/f","state":"PROCESSING"}}',
				);
			}
		);

		$result = $service->upload_file( $this->temp_file( 'GEMINI-BYTES' ), 'video/mp4', 'My Video' );

		$this->assertIsArray( $result );
		$this->assertSame( 'files/xyz', $result['file_name'] );
		$this->assertSame( 'PROCESSING', $result['state'] );
		$this->assertSame( 'video/mp4', $result['mime_type'] );

		$this->assertSame( 'gkey-2', $calls[0][2]['headers']['x-goog-api-key'] );
		$this->assertStringContainsString( 'GEMINI-BYTES', $calls[0][2]['body'] );
		$this->assertStringContainsString( '"displayName":"My Video"', $calls[0][2]['body'] );
	}

	public function test_gemini_wait_for_processing_states(): void {
		$this->seed_key( 'gemini', 'gkey-3' );

		$service = new GeminiFileService( 5, 1 );

		// ACTIVE on first poll.
		$this->intercept(
			static function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"name":"files/xyz","state":"ACTIVE","uri":"https://example.com/f"}',
				);
			}
		);

		$status = $service->wait_for_processing( 'files/xyz', 60 );
		$this->assertIsArray( $status );
		$this->assertSame( 'ACTIVE', $status['state'] );

		// FAILED on first poll.
		$this->intercept(
			static function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"name":"files/xyz","state":"FAILED"}',
				);
			}
		);

		$status = $service->wait_for_processing( 'files/xyz', 60 );
		$this->assertInstanceOf( \WP_Error::class, $status );
		$this->assertSame( 'wp_mcp_ai_processing_failed', $status->get_error_code() );

		// Timeout (empty timeout = no attempts allowed).
		$status = $service->wait_for_processing( 'files/xyz', 0 );
		$this->assertInstanceOf( \WP_Error::class, $status );
	}

	public function test_gemini_delete_and_status_errors(): void {
		$this->seed_key( 'gemini', 'gkey-4' );
		$service = new GeminiFileService();

		// Missing file name.
		$result = $service->delete_file( '' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_file_name', $result->get_error_code() );

		// Successful delete.
		$this->intercept(
			static function () {
				return array( 'response' => array( 'code' => 204 ), 'body' => '' );
			}
		);
		$this->assertTrue( $service->delete_file( 'files/xyz' ) );

		// Status error.
		$this->intercept(
			static function () {
				return array(
					'response' => array( 'code' => 404 ),
					'body'     => '{"error":{"message":"File not found"}}',
				);
			}
		);
		$result = $service->get_file_status( 'files/xyz' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_api_error', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}
}
