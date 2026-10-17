<?php
/**
 * Tests for the self-hosted OCR client (Unlimited-OCR / DeepSeek-OCR).
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for WP_MCP_AI_Self_Hosted_OCR_Client.
 */
class WP_MCP_AI_Self_Hosted_OCR_Client_Test extends WP_UnitTestCase {

	/**
	 * Client instance.
	 *
	 * @var WP_MCP_AI_Self_Hosted_OCR_Client
	 */
	private $client;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-self-hosted-ocr-client.php';
		$this->client = new WP_MCP_AI_Self_Hosted_OCR_Client();

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		parent::tearDown();
	}

	/**
	 * Content flattening must handle every shape.
	 */
	public function test_flatten_content_shapes() {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'flatten_content' );
		$method->setAccessible( true );

		// String passthrough.
		$this->assertSame( 'Plain text.', $method->invoke( $this->client, 'Plain text.' ) );

		// Array of parts.
		$this->assertSame(
			'Part one.Part two.',
			$method->invoke(
				$this->client,
				array(
					array(
						'type' => 'text',
						'text' => 'Part one.',
					),
					array(
						'type' => 'text',
						'text' => 'Part two.',
					),
				)
			)
		);

		// Array of plain strings.
		$this->assertSame(
			'A B',
			$method->invoke( $this->client, array( 'A ', 'B' ) )
		);

		// Garbage input yields ''.
		$this->assertSame( '', $method->invoke( $this->client, 42 ) );
	}

	/**
	 * OCR requests must flatten array content returned by vLLM-style servers
	 * instead of casting it to the literal string "Array".
	 */
	public function test_ocr_image_flattens_array_content() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'unlimited_ocr_endpoint_url' => 'http://ocr.test:8000',
			)
		);
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		$filter_callback = function ( $preempt, $args, $url ) {
			// Connection probe.
			if ( false !== strpos( $url, '/v1/models' ) ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'data' => array(
								array( 'id' => 'unlimited-ocr' ),
							),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			}

			// OCR completion — content is an array of parts.
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'choices' => array(
							array(
								'message' => array(
									'role'    => 'assistant',
									'content' => array(
										array(
											'type' => 'text',
											'text' => 'Extracted 1234',
										),
									),
								),
							),
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

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding a fixture image for the OCR API payload.
		$result = $this->client->ocr_image( base64_encode( 'fake-image-bytes' ), '', 'unlimited_ocr' );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'text', $result );
		$this->assertSame( 'Extracted 1234', $result['text'] );
		$this->assertStringNotContainsString( 'Array', $result['text'] );
	}

	/**
	 * The OCR payload must target the vLLM OpenAI-compatible contract, not the
	 * SGLang request dialect: `model` is the served HF id, ngram args travel in
	 * `extra_body.vllm_xargs`, and no `custom_logit_processor` / `custom_params` /
	 * `images_config` fields are sent.
	 */
	public function test_ocr_payload_is_vllm_shaped() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'unlimited_ocr_endpoint_url' => 'http://ocr.test:8000',
			)
		);
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		$captured_body = null;
		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_body ) {
			if ( false !== strpos( $url, '/v1/models' ) ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'data' => array() ) ),
					'response' => array( 'code' => 200, 'message' => 'OK' ),
				);
			}

			$captured_body = isset( $args['body'] ) ? $args['body'] : null;

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'choices' => array(
							array(
								'message' => array(
									'role'    => 'assistant',
									'content' => 'Extracted text',
								),
							),
						),
					),
				),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding a fixture image for the OCR API payload.
		$this->client->ocr_image( base64_encode( 'fake-image-bytes' ), '', 'unlimited_ocr' );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotNull( $captured_body, 'OCR request payload was not captured.' );
		$payload = json_decode( $captured_body, true );

		$this->assertSame( 'baidu/Unlimited-OCR', $payload['model'] );
		$this->assertArrayHasKey( 'extra_body', $payload );
		$this->assertFalse( $payload['extra_body']['skip_special_tokens'] );
		$this->assertArrayHasKey( 'vllm_xargs', $payload['extra_body'] );
		$this->assertSame( 35, $payload['extra_body']['vllm_xargs']['ngram_size'] );
		$this->assertSame( 128, $payload['extra_body']['vllm_xargs']['window_size'] );

		// No SGLang-specific fields may leak through.
		$this->assertArrayNotHasKey( 'custom_logit_processor', $payload );
		$this->assertArrayNotHasKey( 'custom_params', $payload );
		$this->assertArrayNotHasKey( 'images_config', $payload );
		$this->assertArrayNotHasKey( 'skip_special_tokens', $payload );
	}

	/**
	 * DeepSeek-OCR must send its <td>/</td> whitelist inside vllm_xargs and
	 * target the deepseek served id.
	 */
	public function test_ocr_payload_deepseek_whitelist() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'deepseek_ocr_endpoint_url' => 'http://ocr.test:8000',
			)
		);
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		$captured_body = null;
		$filter_callback = function ( $preempt, $args, $url ) use ( &$captured_body ) {
			if ( false !== strpos( $url, '/v1/models' ) ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'data' => array() ) ),
					'response' => array( 'code' => 200, 'message' => 'OK' ),
				);
			}

			$captured_body = isset( $args['body'] ) ? $args['body'] : null;

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'choices' => array(
							array(
								'message' => array(
									'role'    => 'assistant',
									'content' => 'Extracted text',
								),
							),
						),
					),
				),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding a fixture image for the OCR API payload.
		$this->client->ocr_image( base64_encode( 'fake-image-bytes' ), '', 'deepseek_ocr' );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertNotNull( $captured_body, 'OCR request payload was not captured.' );
		$payload = json_decode( $captured_body, true );

		$this->assertSame( 'deepseek-ai/DeepSeek-OCR', $payload['model'] );
		$this->assertSame( 30, $payload['extra_body']['vllm_xargs']['ngram_size'] );
		$this->assertSame( 90, $payload['extra_body']['vllm_xargs']['window_size'] );
		$this->assertSame( array( 128821, 128822 ), $payload['extra_body']['vllm_xargs']['whitelist_token_ids'] );
	}

	/**
	 * The result metadata must keep reporting the human-readable model label
	 * (not the vLLM served id) so transcripts and diagnostics stay readable.
	 */
	public function test_result_metadata_reports_model_label() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'unlimited_ocr_endpoint_url' => 'http://ocr.test:8000',
			)
		);
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		$filter_callback = function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, '/v1/models' ) ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'data' => array() ) ),
					'response' => array( 'code' => 200, 'message' => 'OK' ),
				);
			}

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'choices' => array(
							array(
								'message' => array(
									'role'    => 'assistant',
									'content' => 'Extracted text',
								),
							),
						),
					),
				),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};

		add_filter( 'pre_http_request', $filter_callback, 10, 3 );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding a fixture image for the OCR API payload.
		$result = $this->client->ocr_image( base64_encode( 'fake-image-bytes' ), '', 'unlimited_ocr' );

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'Unlimited-OCR', $result['metadata']['model'] );
	}
}
