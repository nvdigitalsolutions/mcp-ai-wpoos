<?php
/**
 * Tests for the WP_MCP_AI_Embedded_Transcribe server-side speech-to-text handler.
 *
 * Verifies audio extraction from data URIs and raw base64, size validation,
 * endpoint configuration checks, default/custom option handling, and the
 * constants defined by the class.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

/**
 * Test embedded transcribe endpoint class.
 */
class Test_Embedded_Transcribe_Endpoint extends WP_UnitTestCase {

	/**
	 * Instance of the class under test.
	 *
	 * @var WP_MCP_AI_Embedded_Transcribe
	 */
	private $transcribe_instance;

	/**
	 * Captured wp_remote_post arguments from the pre_http_request filter.
	 *
	 * @var array|null
	 */
	private $captured_http_args;

	/**
	 * Captured wp_remote_post URL from the pre_http_request filter.
	 *
	 * @var string|null
	 */
	private $captured_http_url;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Embedded_Transcribe' ) ) {
			require_once NVOOS_EMBEDDED_PATH . 'includes/embedded/class-wp-mcp-ai-embedded-transcribe.php';
		}

		$this->transcribe_instance = new WP_MCP_AI_Embedded_Transcribe();

		// Clean options.
		delete_option( 'nvoos_embedded_settings' );
	}

	/**
	 * Clean up test fixtures.
	 */
	public function tearDown(): void {
		delete_option( 'nvoos_embedded_settings' );
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Structural Tests
	// -------------------------------------------------------------------------

	/**
	 * Verify the WP_MCP_AI_Embedded_Transcribe class is loadable.
	 */
	public function test_transcribe_class_exists() {
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Embedded_Transcribe' ),
			'WP_MCP_AI_Embedded_Transcribe class should exist.'
		);
	}

	/**
	 * Verify MAX_AUDIO_SIZE constant is defined and equals 10 MB.
	 */
	public function test_max_audio_size_constant() {
		$this->assertTrue(
			defined( 'WP_MCP_AI_Embedded_Transcribe::MAX_AUDIO_SIZE' ),
			'MAX_AUDIO_SIZE constant should be defined.'
		);

		$this->assertSame(
			10 * 1024 * 1024,
			WP_MCP_AI_Embedded_Transcribe::MAX_AUDIO_SIZE,
			'MAX_AUDIO_SIZE should equal 10 MB (10485760 bytes).'
		);
	}

	// -------------------------------------------------------------------------
	// extract_audio_bytes() Tests (private method via Reflection)
	// -------------------------------------------------------------------------

	/**
	 * Test extracting bytes from a valid data URI with base64 encoding.
	 */
	public function test_extract_audio_bytes_data_uri_base64() {
		$raw_data  = 'test audio payload';
		$b64       = base64_encode( $raw_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test data encoding.
		$data_uri  = 'data:audio/wav;base64,' . $b64;
		$extracted = $this->call_extract_audio_bytes( $data_uri );

		$this->assertIsString( $extracted, 'Should return a string.' );
		$this->assertSame( $raw_data, $extracted, 'Should return the decoded bytes.' );
	}

	/**
	 * Test extracting bytes from a non-base64 data URI (raw URL-encoded payload).
	 */
	public function test_extract_audio_bytes_data_uri_plain() {
		$data_uri  = 'data:text/plain,hello%20world';
		$extracted = $this->call_extract_audio_bytes( $data_uri );

		$this->assertIsString( $extracted, 'Should return a string.' );
		$this->assertSame( 'hello world', $extracted, 'Should rawurldecode the payload.' );
	}

	/**
	 * Test extracting bytes from a raw base64 string (no data URI prefix).
	 */
	public function test_extract_audio_bytes_raw_base64() {
		$raw_data  = 'raw base64 test';
		$b64       = base64_encode( $raw_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test data encoding.
		$extracted = $this->call_extract_audio_bytes( $b64 );

		$this->assertIsString( $extracted, 'Should return a string.' );
		$this->assertSame( $raw_data, $extracted, 'Should return the decoded bytes.' );
	}

	/**
	 * Test that a malformed data URI (no comma) returns a WP_Error.
	 */
	public function test_extract_audio_bytes_invalid_data_uri() {
		$data_uri  = 'data:audio/wav;base64'; // Missing comma and payload.
		$extracted = $this->call_extract_audio_bytes( $data_uri );

		$this->assertInstanceOf(
			'WP_Error',
			$extracted,
			'Should return WP_Error for data URI without comma.'
		);
		$this->assertSame(
			'invalid_data_uri',
			$extracted->get_error_code(),
			'Error code should be invalid_data_uri.'
		);
	}

	/**
	 * Test that invalid base64 in a data URI returns a WP_Error.
	 */
	public function test_extract_audio_bytes_invalid_base64() {
		$data_uri  = 'data:audio/wav;base64,!!!not-valid-base64!!!';
		$extracted = $this->call_extract_audio_bytes( $data_uri );

		$this->assertInstanceOf(
			'WP_Error',
			$extracted,
			'Should return WP_Error for invalid base64 payload.'
		);
		$this->assertSame(
			'invalid_base64',
			$extracted->get_error_code(),
			'Error code should be invalid_base64.'
		);
	}

	// -------------------------------------------------------------------------
	// transcribe() Tests — Early Exit Guards
	// -------------------------------------------------------------------------

	/**
	 * Test transcribe returns WP_Error when no Gemma 4 endpoint is configured.
	 *
	 * The gemma4_audio_endpoint key in nvoos_embedded_settings is empty
	 * by default (setUp clears it), so the method should return early
	 * without making any HTTP request.
	 */
	public function test_transcribe_no_endpoint_configured() {
		$valid_audio = base64_encode( 'test audio data' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test data.
		$result      = $this->transcribe_instance->transcribe( $valid_audio );

		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'Should return WP_Error when no endpoint is configured.'
		);
		$this->assertSame(
			'gemma4_not_configured',
			$result->get_error_code(),
			'Error code should be gemma4_not_configured.'
		);
	}

	/**
	 * Test transcribe returns WP_Error when audio data exceeds MAX_AUDIO_SIZE.
	 *
	 * The size check occurs after extraction but before the endpoint check,
	 * so we do not need a configured endpoint to trigger this path.
	 */
	public function test_transcribe_audio_too_large() {
		// Create audio bytes that exceed MAX_AUDIO_SIZE (10 MB).
		$max         = WP_MCP_AI_Embedded_Transcribe::MAX_AUDIO_SIZE;
		$large_audio = base64_encode( str_repeat( 'A', $max + 100 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test data.
		$result      = $this->transcribe_instance->transcribe( $large_audio );

		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'Should return WP_Error when audio exceeds maximum size.'
		);
		$this->assertSame(
			'audio_too_large',
			$result->get_error_code(),
			'Error code should be audio_too_large.'
		);
	}

	// -------------------------------------------------------------------------
	// transcribe() Tests — Options Handling
	// -------------------------------------------------------------------------

	/**
	 * Test that default options are applied when none are provided.
	 *
	 * Set a valid endpoint, mock the HTTP response, and verify the request
	 * body includes the default model (gemma4:e4b) and language (en).
	 */
	public function test_transcribe_default_options() {
		$this->configure_mock_endpoint();
		$this->install_http_mock();

		$valid_audio = base64_encode( 'test audio' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test data.
		$result      = $this->transcribe_instance->transcribe( $valid_audio );

		// Verify success response shape.
		$this->assertIsArray( $result, 'Should return an array on success.' );
		$this->assertArrayHasKey( 'text', $result, 'Result should have text key.' );
		$this->assertArrayHasKey( 'language', $result, 'Result should have language key.' );
		$this->assertArrayHasKey( 'unified_response', $result, 'Result should have unified_response key.' );

		// Verify default values are used.
		$this->assertSame( 'en', $result['language'], 'Default language should be en.' );
		$this->assertNull( $result['unified_response'], 'Default unified_response should be null (unified_mode=false).' );

		// Verify the request body included the default model.
		$this->assertNotNull( $this->captured_http_args, 'HTTP request should have been captured.' );
		$body = json_decode( $this->captured_http_args['body'], true );
		$this->assertSame( 'gemma4:e4b', $body['model'], 'Request body should use default model gemma4:e4b.' );
	}

	/**
	 * Test that custom options are respected in the request and response.
	 */
	public function test_transcribe_with_options() {
		$this->configure_mock_endpoint();
		$this->install_http_mock();

		$valid_audio = base64_encode( 'custom audio' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test data.
		$options     = array(
			'model'        => 'gemma4:custom-model',
			'language'     => 'fr',
			'unified_mode' => true,
			'prompt'       => 'Please transcribe carefully.',
		);
		$result      = $this->transcribe_instance->transcribe( $valid_audio, $options );

		// Verify success response shape.
		$this->assertIsArray( $result, 'Should return an array on success.' );

		// Verify custom values propagate.
		$this->assertSame( 'fr', $result['language'], 'Language should reflect the custom option.' );

		// Verify the request body used the custom model.
		$this->assertNotNull( $this->captured_http_args, 'HTTP request should have been captured.' );
		$body = json_decode( $this->captured_http_args['body'], true );
		$this->assertSame( 'gemma4:custom-model', $body['model'], 'Request body should use the custom model.' );

		// Verify the prompt in the request reflects unified_mode=true.
		$message_text = $body['messages'][0]['content'][0]['text'];
		$this->assertStringContainsString(
			'Transcribe and respond',
			$message_text,
			'Prompt should reflect unified_mode=true.'
		);
	}

	// -------------------------------------------------------------------------
	// Additional Behavior Tests
	// -------------------------------------------------------------------------

	/**
	 * Test that the class reads from the 'nvoos_embedded_settings' option.
	 *
	 * Configure a specific endpoint, mock HTTP, call transcribe, and verify
	 * the HTTP request URL matches the configured endpoint.
	 */
	public function test_embedded_settings_option_key_used() {
		$custom_endpoint = 'https://example.com/v1/gemma4/audio';
		update_option(
			'nvoos_embedded_settings',
			array( 'gemma4_audio_endpoint' => $custom_endpoint )
		);

		$this->install_http_mock();

		$valid_audio = base64_encode( 'settings test' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test data.
		$result      = $this->transcribe_instance->transcribe( $valid_audio );

		$this->assertIsArray( $result, 'Should succeed with a configured endpoint.' );

		$this->assertNotNull(
			$this->captured_http_url,
			'HTTP request URL should have been captured.'
		);
		$this->assertSame(
			$custom_endpoint,
			$this->captured_http_url,
			'HTTP request should be sent to the configured gemma4_audio_endpoint.'
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Invoke the private extract_audio_bytes() method via reflection.
	 *
	 * @param string $audio_data Base64-encoded audio string or data URI.
	 * @return string|WP_Error Extracted bytes or error.
	 */
	private function call_extract_audio_bytes( $audio_data ) {
		$reflection = new ReflectionClass( $this->transcribe_instance );
		$method     = $reflection->getMethod( 'extract_audio_bytes' );
		$method->setAccessible( true );

		return $method->invoke( $this->transcribe_instance, $audio_data );
	}

	/**
	 * Set a Gemma 4 audio endpoint in nvoos_embedded_settings.
	 */
	private function configure_mock_endpoint() {
		update_option(
			'nvoos_embedded_settings',
			array( 'gemma4_audio_endpoint' => 'https://gemma4.local/v1/chat/completions' )
		);
	}

	/**
	 * Install a pre_http_request filter that returns a mock 200 response
	 * and captures the request URL and arguments for assertions.
	 */
	private function install_http_mock() {
		$this->captured_http_args = null;
		$this->captured_http_url  = null;

		$capture = function ( $preempt, $args, $url ) {
			$this->captured_http_args = $args;
			$this->captured_http_url  = $url;

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'choices' => array(
							array(
								'message' => array(
									'content' => 'This is a mock transcription.',
								),
							),
						),
					)
				),
			);
		};

		add_filter( 'pre_http_request', $capture, 10, 3 );
	}
}
