<?php
/**
 * Tests for OCR Service
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 */

/**
 * Test OCR Service class
 */
class Test_WP_MCP_AI_OCR_Service extends WP_UnitTestCase {

	/**
	 * OCR Service instance.
	 *
	 * @var WP_MCP_AI_OCR_Service
	 */
	private $ocr_service;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load OCR service.
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-ocr-service.php';
		$this->ocr_service = new WP_MCP_AI_OCR_Service();

		// Reset the static circuit-breaker state so tests are order-independent.
		$circuit = new ReflectionProperty( 'WP_MCP_AI_OCR_Service', 'circuit_breaker' );
		$circuit->setAccessible( true );
		$circuit->setValue( null, array() );
	}

	/**
	 * Test that OCR service class exists.
	 */
	public function test_ocr_service_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_OCR_Service' ) );
	}

	/**
	 * Test OCR service instantiation.
	 */
	public function test_ocr_service_instantiation() {
		$this->assertInstanceOf( 'WP_MCP_AI_OCR_Service', $this->ocr_service );
	}

	/**
	 * Test is_scanned_pdf method with non-existent file.
	 */
	public function test_is_scanned_pdf_with_nonexistent_file() {
		$result = $this->ocr_service->is_scanned_pdf( '/path/to/nonexistent.pdf' );

		// Should return true (assume scanned if can't read).
		$this->assertTrue( $result );
	}

	/**
	 * Test extract_text_from_image with non-existent file.
	 */
	public function test_extract_text_from_image_with_nonexistent_file() {
		$result = $this->ocr_service->extract_text_from_image( '/path/to/nonexistent.jpg' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'file_not_found', $result->get_error_code() );
	}

	/**
	 * Test extract_text_from_pdf with non-existent file.
	 */
	public function test_extract_text_from_pdf_with_nonexistent_file() {
		$result = $this->ocr_service->extract_text_from_pdf( '/path/to/nonexistent.pdf' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'file_not_found', $result->get_error_code() );
	}

	/**
	 * Test OCR provider determination.
	 */
	public function test_determine_best_provider() {
		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'determine_best_provider' );
		$method->setAccessible( true );

		$provider = $method->invoke( $this->ocr_service );

		// Should return a valid provider name.
		$this->assertContains(
			$provider,
			array( 'openai', 'gemini', 'ollama', 'tesseract' )
		);
	}

	/**
	 * Test fallback providers list.
	 */
	public function test_get_fallback_providers() {
		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'get_fallback_providers' );
		$method->setAccessible( true );

		$fallbacks = $method->invoke( $this->ocr_service, 'openai' );

		// Should return array without primary provider.
		$this->assertIsArray( $fallbacks );
		$this->assertNotContains( 'openai', $fallbacks );
		$this->assertContains( 'gemini', $fallbacks );
	}

	/**
	 * Test Sharp availability check.
	 */
	public function test_is_sharp_available() {
		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'is_sharp_available' );
		$method->setAccessible( true );

		$available = $method->invoke( $this->ocr_service );

		// Should return boolean.
		$this->assertIsBool( $available );
	}

	/**
	 * Test PDF to images conversion with invalid file.
	 */
	public function test_convert_pdf_to_images_with_invalid_file() {
		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'convert_pdf_to_images' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ocr_service, '/path/to/invalid.pdf', array() );

		// Should return WP_Error for invalid file.
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Create a minimal PNG file for vision-provider tests.
	 *
	 * @return string Path to the temp PNG.
	 */
	private function create_test_png() {
		// 1x1 transparent PNG.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a fixture PNG for tests.
		$png  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
		$path = sys_get_temp_dir() . '/ocr_test_' . uniqid() . '.png';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a fixture image for tests.
		file_put_contents( $path, $png );

		return $path;
	}

	/**
	 * Reset provider-key resolution state so each test sees its own settings.
	 */
	private function reset_key_state() {
		if ( class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
			WP_MCP_AI_Credential_Resolver::clear_cache();
		}
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
		update_option( 'wp_mcp_ai_settings', array() );
		delete_option( 'wp_mcp_ai_credentials' );
	}

	/**
	 * The Gemini path must return a WP_Error without credentials — never a
	 * fatal (regression: it used to call a nonexistent generate_content()).
	 */
	public function test_extract_with_gemini_missing_credentials_returns_error() {
		$this->reset_key_state();
		$image = $this->create_test_png();

		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'extract_with_gemini' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ocr_service, $image, array() );

		wp_delete_file( $image );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'no_api_key', $result->get_error_code() );
	}

	/**
	 * The Gemini path must go through create_chat_completion() and send the
	 * image as a Gemini inlineData part (regression: the old code built a raw
	 * payload and called a nonexistent method).
	 */
	public function test_extract_with_gemini_sends_inline_image() {
		$this->reset_key_state();
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key'       => 'gsk-test',
				'default_gemini_model' => 'gemini-test-model',
			)
		);

		$image    = $this->create_test_png();
		$captured = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured ) {
			$captured = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'candidates'    => array(
							array(
								'content'      => array(
									'parts' => array( array( 'text' => 'Extracted page text.' ) ),
								),
								'finishReason' => 'STOP',
							),
						),
						'usageMetadata' => array(),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'extract_with_gemini' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ocr_service, $image, array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );
		wp_delete_file( $image );

		$this->assertSame( 'Extracted page text.', $result );

		$this->assertNotNull( $captured );
		$payload = json_decode( $captured['args']['body'], true );
		$this->assertIsArray( $payload );

		$parts  = isset( $payload['contents'][0]['parts'] ) ? $payload['contents'][0]['parts'] : array();
		$inline = null;
		foreach ( $parts as $part ) {
			if ( isset( $part['inlineData'] ) ) {
				$inline = $part['inlineData'];
			}
		}

		$this->assertNotNull( $inline, 'Expected an inlineData part in the Gemini payload.' );
		$this->assertNotEmpty( $inline['data'] );
		$this->assertSame( 'image/png', $inline['mimeType'] );
	}

	/**
	 * 'anthropic' must route to the Anthropic provider — not 'unknown_provider'.
	 */
	public function test_extract_with_provider_routes_anthropic() {
		$this->reset_key_state();
		$image = $this->create_test_png();

		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'extract_with_provider' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ocr_service, $image, 'anthropic', array() );

		wp_delete_file( $image );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'no_api_key', $result->get_error_code() );
	}

	/**
	 * The Anthropic path must send a base64 image block and return extracted text.
	 */
	public function test_extract_with_anthropic_returns_text() {
		$this->reset_key_state();
		update_option(
			'wp_mcp_ai_settings',
			array(
				'anthropic_api_key'      => 'sk-ant-test',
				'anthropic_vision_model' => 'claude-sonnet-4-6',
			)
		);

		$image    = $this->create_test_png();
		$captured = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured ) {
			$captured = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'id'      => 'msg_test',
						'type'    => 'message',
						'role'    => 'assistant',
						'content' => array(
							array(
								'type' => 'text',
								'text' => 'Extracted by Claude.',
							),
						),
						'model'   => 'claude-sonnet-4-6',
						'usage'   => array(
							'input_tokens'  => 10,
							'output_tokens' => 5,
						),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'extract_with_anthropic' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ocr_service, $image, array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );
		wp_delete_file( $image );

		$this->assertSame( 'Extracted by Claude.', $result );

		$this->assertNotNull( $captured );
		$payload = json_decode( $captured['args']['body'], true );
		$this->assertIsArray( $payload );

		$first_block = isset( $payload['messages'][0]['content'][0] ) ? $payload['messages'][0]['content'][0] : array();
		$this->assertSame( 'base64', isset( $first_block['source']['type'] ) ? $first_block['source']['type'] : '' );
	}

	/**
	 * The OpenAI path must return a WP_Error without credentials — never a fatal.
	 */
	public function test_extract_with_openai_missing_credentials_returns_error() {
		$this->reset_key_state();
		$image = $this->create_test_png();

		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'extract_with_openai' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ocr_service, $image, array() );

		wp_delete_file( $image );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'no_api_key', $result->get_error_code() );
	}

	/**
	 * The OpenAI path must send a data-URL image block and return extracted text.
	 */
	public function test_extract_with_openai_returns_text() {
		$this->reset_key_state();
		update_option(
			'wp_mcp_ai_settings',
			array(
				'openai_api_key' => 'sk-test',
			)
		);

		$image    = $this->create_test_png();
		$captured = null;

		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured ) {
			$captured = array(
				'args' => $args,
				'url'  => $url,
			);

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'id'      => 'chatcmpl-test',
						'object'  => 'chat.completion',
						'created' => time(),
						'model'   => 'gpt-4.1',
						'choices' => array(
							array(
								'index'   => 0,
								'message' => array(
									'role'    => 'assistant',
									'content' => 'OpenAI extracted text.',
								),
							),
						),
						'usage'   => array(
							'prompt_tokens'     => 10,
							'completion_tokens' => 5,
							'total_tokens'      => 15,
						),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'extract_with_openai' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ocr_service, $image, array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );
		wp_delete_file( $image );

		$this->assertSame( 'OpenAI extracted text.', $result );

		$this->assertNotNull( $captured );
		$payload = json_decode( $captured['args']['body'], true );
		$this->assertIsArray( $payload );
		$this->assertStringContainsString( 'data:image/png;base64,', $payload['messages'][0]['content'][1]['image_url']['url'] );
	}

	/**
	 * A validation failure (missing key) must NOT open the circuit breaker.
	 */
	public function test_no_api_key_does_not_open_circuit() {
		$this->reset_key_state();
		$image = $this->create_test_png();

		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'extract_with_provider' );
		$method->setAccessible( true );

		$first  = $method->invoke( $this->ocr_service, $image, 'openai', array() );
		$second = $method->invoke( $this->ocr_service, $image, 'openai', array() );

		wp_delete_file( $image );

		$this->assertInstanceOf( 'WP_Error', $first );
		$this->assertSame( 'no_api_key', $first->get_error_code() );
		$this->assertInstanceOf( 'WP_Error', $second );
		$this->assertSame( 'no_api_key', $second->get_error_code() );
	}

	/**
	 * A non-validation failure must open the circuit breaker, so subsequent
	 * attempts report the provider as temporarily unavailable (regression:
	 * the production cascade seen on nirmanawellness).
	 */
	public function test_circuit_breaker_opens_on_non_validation_failure() {
		$this->reset_key_state();
		update_option(
			'wp_mcp_ai_settings',
			array(
				'openai_api_key' => 'sk-test',
			)
		);

		$image = $this->create_test_png();

		// Mock a malformed success (no choices) — not a validation error.
		$filter_callback = function () {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'id'      => 'chatcmpl-test',
						'choices' => array(),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'extract_with_provider' );
		$method->setAccessible( true );

		$first = $method->invoke( $this->ocr_service, $image, 'openai', array() );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertInstanceOf( 'WP_Error', $first );
		$this->assertSame( 'invalid_response', $first->get_error_code() );

		// The circuit is now open — the next attempt short-circuits.
		$second = $method->invoke( $this->ocr_service, $image, 'openai', array() );

		wp_delete_file( $image );

		$this->assertInstanceOf( 'WP_Error', $second );
		$this->assertSame( 'provider_unavailable', $second->get_error_code() );
	}

	/**
	 * The shared response extractor must handle every provider response shape.
	 */
	public function test_extract_text_from_response_handles_shapes() {
		$reflection = new ReflectionClass( $this->ocr_service );
		$method     = $reflection->getMethod( 'extract_text_from_response' );
		$method->setAccessible( true );

		// String content (OpenAI-style).
		$this->assertSame(
			'Plain text.',
			$method->invoke(
				$this->ocr_service,
				array(
					'choices' => array(
						array(
							'message' => array( 'content' => '  Plain text.  ' ),
						),
					),
				)
			)
		);

		// Array-of-parts content (Gemini normalize_response).
		$this->assertSame(
			'Part one.Part two.',
			$method->invoke(
				$this->ocr_service,
				array(
					'choices' => array(
						array(
							'message' => array(
								'content' => array(
									array(
										'type' => 'text',
										'text' => 'Part one.',
									),
									array(
										'type' => 'text',
										'text' => 'Part two.',
									),
								),
							),
						),
					),
				)
			)
		);

		// Raw Gemini candidates envelope (custom base URLs).
		$this->assertSame(
			'Native envelope.',
			$method->invoke(
				$this->ocr_service,
				array(
					'candidates' => array(
						array(
							'content' => array(
								'parts' => array( array( 'text' => 'Native envelope.' ) ),
							),
						),
					),
				)
			)
		);

		// Empty / malformed payloads must yield '' (caller converts to an error).
		$this->assertSame( '', $method->invoke( $this->ocr_service, array() ) );
		$this->assertSame( '', $method->invoke( $this->ocr_service, array( 'choices' => array() ) ) );
	}

	/**
	 * End-to-end PDF OCR: convert pages via Imagick, OCR each page through the
	 * mocked Gemini provider, and assert the per-page concatenated output.
	 */
	public function test_extract_text_from_pdf_processes_pages_with_imagick() {
		if ( ! extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'Imagick extension is not available.' );
		}

		$this->reset_key_state();
		update_option(
			'wp_mcp_ai_settings',
			array(
				'gemini_api_key'       => 'gsk-test',
				'default_gemini_model' => 'gemini-test-model',
			)
		);

		$pdf_path = sys_get_temp_dir() . '/ocr_test_' . uniqid() . '.pdf';

		$page_one = new Imagick();
		$page_one->newImage( 200, 200, new ImagickPixel( 'white' ) );
		$page_one->setImageFormat( 'pdf' );

		$page_two = new Imagick();
		$page_two->newImage( 200, 200, new ImagickPixel( 'white' ) );
		$page_two->setImageFormat( 'pdf' );

		$pdf = new Imagick();
		$pdf->addImage( $page_one );
		$pdf->addImage( $page_two );
		$pdf->setImageFormat( 'pdf' );
		$pdf->writeImages( $pdf_path, true );

		$pdf->clear();
		$pdf->destroy();
		$page_one->clear();
		$page_one->destroy();
		$page_two->clear();
		$page_two->destroy();

		$call_count = 0;

		$filter_callback = function () use ( &$call_count ) {
			++$call_count;

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'candidates'    => array(
							array(
								'content'      => array(
									'parts' => array( array( 'text' => 'Page text ' . $call_count ) ),
								),
								'finishReason' => 'STOP',
							),
						),
						'usageMetadata' => array(),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		$result = $this->ocr_service->extract_text_from_pdf(
			$pdf_path,
			array(
				'provider'   => 'gemini',
				'preprocess' => false,
				'dpi'        => 72,
			)
		);

		remove_filter( 'pre_http_request', $filter_callback, 10 );
		wp_delete_file( $pdf_path );

		$this->assertIsString( $result );
		$this->assertSame( 2, $call_count );
		$this->assertStringContainsString( '--- Page 1 ---', $result );
		$this->assertStringContainsString( 'Page text 1', $result );
		$this->assertStringContainsString( '--- Page 2 ---', $result );
		$this->assertStringContainsString( 'Page text 2', $result );
	}
}
