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
}
