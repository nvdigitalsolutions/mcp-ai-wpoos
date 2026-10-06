<?php
/**
 * Regression tests for the image-production toolkit hardening pass.
 *
 * Covers the four recurring production failure classes inside the
 * image-production toolkit:
 * - string-assuming parsing of provider responses (prompt optimizer trim()
 *   fatal on array-of-parts content),
 * - fenced-JSON payloads that never parsed,
 * - provider error responses surfaced as generic failures (Stability path),
 * - unguarded sidecar/envelope fields (optimized_size undefined index),
 * - unchecked HTTP downloads of provider-returned image URLs (harmonization
 *   ai_edit_image status gate), plus the Gemini/OpenAI image-edit round-trips.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 */

/**
 * Image-production hardening test case.
 */
class Test_Image_Production_Hardening extends WP_UnitTestCase {

	/**
	 * 1x1 transparent PNG for image round-trip fixtures.
	 */
	const TINY_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

	/**
	 * Number of HTTP requests issued during the current test.
	 *
	 * @var int
	 */
	private $request_count = 0;

	/**
	 * Captured arguments of the last HTTP request.
	 *
	 * @var array|null
	 */
	private $last_request_args = null;

	/**
	 * Set up the test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->request_count     = 0;
		$this->last_request_args = null;

		if ( class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
			WP_MCP_AI_Credential_Resolver::clear_cache();
		}
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
		delete_option( 'wp_mcp_ai_credentials' );
		delete_option( 'wp_mcp_ai_settings' );
		delete_option( 'wp_mcp_ai_stability_api_key' );
	}

	/**
	 * Reset circuit-breaker static state between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/**
	 * Mock the HTTP layer to return a given JSON body with a given status.
	 *
	 * @param array|string $body   Body payload.
	 * @param int          $status HTTP status code.
	 * @return void
	 */
	private function mock_http( $body, $status = 200 ) {
		$test = $this;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $test, $body, $status ) {
				$test->request_count++;
				$test->last_request_args = array(
					'args' => $args,
					'url'  => $url,
				);

				return array(
					'headers'  => array(),
					'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
					'response' => array(
						'code'    => $status,
						'message' => 200 === $status ? 'OK' : 'Error',
					),
				);
			},
			10,
			3
		);
	}

	/**
	 * Create a temp file containing the tiny PNG fixture.
	 *
	 * @return string Temp file path.
	 */
	private function create_fixture_image() {
		$path = wp_tempnam( 'ip-hardening-fixture-' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Decoding and writing a test fixture image.
		file_put_contents( $path, base64_decode( self::TINY_PNG_B64, true ) );
		return $path;
	}

	/**
	 * Invoke a protected/private method on an object.
	 *
	 * @param object $instance   Target object.
	 * @param string $class_name Class declaring the method.
	 * @param string $method     Method name.
	 * @param array  $args       Arguments.
	 * @return mixed Method result.
	 */
	private function invoke_method( $instance, $class_name, $method, array $args = array() ) {
		$reflection = new ReflectionMethod( $class_name, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $instance, $args );
	}

	/**
	 * String message content must flow through to the parsed result.
	 */
	public function test_prompt_optimizer_string_content() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'openai_api_key' => 'sk-test' )
		);

		$this->mock_http(
			array(
				'choices' => array(
					array(
						'message' => array(
							'content' => '{"prompt":"A lone wolf under an aurora","suggestions":["Add lens"],"keywords":["aurora"],"improvements":["specified subject"]}',
						),
					),
				),
			)
		);

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-text-to-image-prompt-optimizer.php';
		$tool   = new WP_MCP_AI_Tool_Text_To_Image_Prompt_Optimizer();
		$result = $tool->execute( array( 'prompt' => 'a wolf at night' ), array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'A lone wolf under an aurora', $result['optimized_prompt'] );
		$this->assertContains( 'Add lens', $result['suggestions'] );
	}

	/**
	 * Array-of-parts content must be flattened, never fed into trim().
	 */
	public function test_prompt_optimizer_flattens_array_content() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'openai_api_key' => 'sk-test' )
		);

		$this->mock_http(
			array(
				'choices' => array(
					array(
						'message' => array(
							'content' => array(
								array(
									'type' => 'text',
									'text' => '{"prompt":"Part one."}',
								),
								array(
									'type' => 'text',
									'text' => '{"prompt":"Part two."}',
								),
							),
						),
					),
				),
			)
		);

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-text-to-image-prompt-optimizer.php';
		$tool   = new WP_MCP_AI_Tool_Text_To_Image_Prompt_Optimizer();
		$result = $tool->execute( array( 'prompt' => 'x' ), array() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertStringNotContainsString( 'Array', (string) $result['optimized_prompt'] );
		$this->assertNotEmpty( $result['optimized_prompt'] );
	}

	/**
	 * Fenced JSON payloads must actually parse into structured fields.
	 */
	public function test_prompt_optimizer_strips_fenced_json() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'openai_api_key' => 'sk-test' )
		);

		$this->mock_http(
			array(
				'choices' => array(
					array(
						'message' => array(
							'content' => "```json\n{\"prompt\":\"Fenced prompt\",\"suggestions\":[\"Use bokeh\"],\"keywords\":[\"bokeh\"],\"improvements\":[\"detail\"]}\n```",
						),
					),
				),
			)
		);

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-text-to-image-prompt-optimizer.php';
		$tool   = new WP_MCP_AI_Tool_Text_To_Image_Prompt_Optimizer();
		$result = $tool->execute( array( 'prompt' => 'portrait' ), array() );

		$this->assertIsArray( $result );
		$this->assertSame( 'Fenced prompt', $result['optimized_prompt'] );
		$this->assertContains( 'Use bokeh', $result['suggestions'] );
	}

	/**
	 * Non-200 provider responses must surface the API's own message.
	 */
	public function test_prompt_optimizer_surfaces_api_error() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'openai_api_key' => 'sk-test' )
		);

		$this->mock_http(
			array(
				'error' => array(
					'message' => 'You exceeded your current quota.',
				),
			),
			401
		);

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-text-to-image-prompt-optimizer.php';
		$tool   = new WP_MCP_AI_Tool_Text_To_Image_Prompt_Optimizer();
		$result = $tool->execute( array( 'prompt' => 'x' ), array() );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'quota', $result->get_error_message() );
	}

	/**
	 * A missing API key must fail fast without any HTTP traffic.
	 */
	public function test_prompt_optimizer_missing_key_no_request() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-text-to-image-prompt-optimizer.php';
		$tool   = new WP_MCP_AI_Tool_Text_To_Image_Prompt_Optimizer();
		$result = $tool->execute( array( 'prompt' => 'x' ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_credentials', $result->get_error_code() );
		$this->assertSame( 0, $this->request_count );
	}

	/**
	 * The flatten helper must handle all three shapes deterministically.
	 */
	public function test_flatten_response_content_shapes() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-text-to-image-prompt-optimizer.php';
		$tool = new WP_MCP_AI_Tool_Text_To_Image_Prompt_Optimizer();

		$this->assertSame( 'plain', $this->invoke_method( $tool, 'WP_MCP_AI_Tool_Text_To_Image_Prompt_Optimizer', 'flatten_response_content', array( 'plain' ) ) );
		$this->assertSame(
			'ab',
			$this->invoke_method(
				$tool,
				'WP_MCP_AI_Tool_Text_To_Image_Prompt_Optimizer',
				'flatten_response_content',
				array(
					array(
						array(
							'type' => 'text',
							'text' => 'a',
						),
						'b',
					),
				)
			)
		);
		$this->assertSame( '', $this->invoke_method( $tool, 'WP_MCP_AI_Tool_Text_To_Image_Prompt_Optimizer', 'flatten_response_content', array( null ) ) );
	}

	/**
	 * The Stability path must post sane dimensions even for malformed sizes.
	 */
	public function test_stability_malformed_size_defaults_dimensions() {
		update_option( 'wp_mcp_ai_stability_api_key', 'sk-stability' );

		$this->mock_http( array( 'artifacts' => array() ) );

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-generate-image-ai.php';
		$tool = new WP_MCP_AI_Tool_Generate_Image_AI();

		$result = $this->invoke_method(
			$tool,
			'WP_MCP_AI_Tool_Generate_Image_AI',
			'generate_with_stability',
			array(
				'a test scene',
				array( 'size' => '1024' ),
				array(),
			)
		);

		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
		$this->assertNotNull( $this->last_request_args );
		$posted = json_decode( $this->last_request_args['args']['body'], true );
		$this->assertSame( 1024, $posted['width'] );
		$this->assertSame( 1024, $posted['height'] );
	}

	/**
	 * Stability error responses must surface the provider message.
	 */
	public function test_stability_surfaces_api_error() {
		update_option( 'wp_mcp_ai_stability_api_key', 'sk-stability' );

		$this->mock_http( array( 'message' => 'Invalid API key provided.' ), 401 );

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-generate-image-ai.php';
		$tool = new WP_MCP_AI_Tool_Generate_Image_AI();

		$result = $this->invoke_method(
			$tool,
			'WP_MCP_AI_Tool_Generate_Image_AI',
			'generate_with_stability',
			array(
				'a test scene',
				array(),
				array(),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_api_error', $result->get_error_code() );
		$this->assertStringContainsString( 'Invalid API key', $result->get_error_message() );
	}

	/**
	 * A successful Stability generation must decode artifacts into media.
	 */
	public function test_stability_success_decodes_artifacts() {
		update_option( 'wp_mcp_ai_stability_api_key', 'sk-stability' );

		$this->mock_http(
			array(
				'artifacts' => array(
					array( 'base64' => self::TINY_PNG_B64 ),
				),
			)
		);

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-generate-image-ai.php';
		$tool = new WP_MCP_AI_Tool_Generate_Image_AI();

		$result = $this->invoke_method(
			$tool,
			'WP_MCP_AI_Tool_Generate_Image_AI',
			'generate_with_stability',
			array(
				'a test scene',
				array( 'save_prompt' => false ),
				array(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertArrayHasKey( 'attachment_id', $result['images'][0] );

		// Clean up the generated attachment.
		wp_delete_attachment( (int) $result['images'][0]['attachment_id'], true );
	}

	/**
	 * The OpenAI edit round-trip must return decoded raw image bytes.
	 */
	public function test_harmonization_ai_edit_openai_round_trip() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'openai_api_key' => 'sk-test' )
		);

		$path = $this->create_fixture_image();

		$this->mock_http(
			array(
				'output' => array(
					array(
						'type'   => 'image_generation_call',
						'result' => self::TINY_PNG_B64,
					),
				),
			)
		);

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/harmonization/class-wp-mcp-ai-tool-harmonization-base.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/harmonization/class-wp-mcp-ai-tool-generate-scene-background.php';
		$tool = new WP_MCP_AI_Tool_Generate_Scene_Background();

		$bytes = $this->invoke_method(
			$tool,
			'WP_MCP_AI_Tool_Harmonization_Base',
			'ai_edit_image',
			array( $path, 'Soften the lighting.', 'openai' )
		);

		wp_delete_file( $path );

		$this->assertIsString( $bytes );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the fixture for a byte-level assertion.
		$this->assertSame( base64_decode( self::TINY_PNG_B64, true ), $bytes );
	}

	/**
	 * The Gemini edit round-trip must return decoded raw image bytes.
	 */
	public function test_harmonization_ai_edit_gemini_round_trip() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'gemini_api_key' => 'gsk-test' )
		);

		$path = $this->create_fixture_image();

		$this->mock_http(
			array(
				'candidates' => array(
					array(
						'content' => array(
							'parts' => array(
								array(
									'inlineData' => array(
										'data'     => self::TINY_PNG_B64,
										'mimeType' => 'image/png',
									),
								),
							),
						),
					),
				),
			)
		);

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/harmonization/class-wp-mcp-ai-tool-harmonization-base.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/harmonization/class-wp-mcp-ai-tool-generate-scene-background.php';
		$tool = new WP_MCP_AI_Tool_Generate_Scene_Background();

		$bytes = $this->invoke_method(
			$tool,
			'WP_MCP_AI_Tool_Harmonization_Base',
			'ai_edit_image',
			array( $path, 'Soften the lighting.', 'gemini' )
		);

		wp_delete_file( $path );

		$this->assertIsString( $bytes );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the fixture for a byte-level assertion.
		$this->assertSame( base64_decode( self::TINY_PNG_B64, true ), $bytes );
	}

	/**
	 * A sidecar payload without optimized_size must not emit an undefined
	 * index notice; the written file size is used instead.
	 */
	public function test_sharp_sidecar_missing_optimized_size() {
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-optimize-image-sharp.php';

		$source = $this->create_fixture_image();

		$tool = new class() extends WP_MCP_AI_Tool_Optimize_Image_Sharp {
			/**
			 * Stub the multipart upload; return a payload without optimized_size.
			 *
			 * @param string $endpoint  API path.
			 * @param string $file_path Local file path.
			 * @param array  $fields    Extra form fields.
			 * @param int    $timeout   Request timeout.
			 * @return array Stubbed sidecar response.
			 */
			protected function sidecar_upload( $endpoint, $file_path, $fields = array(), $timeout = 330 ) {
				unset( $endpoint, $file_path, $fields, $timeout );
				return array(
					'b64'             => Test_Image_Production_Hardening::TINY_PNG_B64,
					'width'           => 1,
					'savings_percent' => '50%',
				);
			}
		};

		$result = $this->invoke_method(
			$tool,
			'WP_MCP_AI_Tool_Optimize_Image_Sharp',
			'optimize_via_sidecar',
			array(
				array(
					'source'    => $source,
					'operation' => 'optimize',
					'quality'   => 80,
				),
			)
		);

		wp_delete_file( $source );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( 50.0, $result['reduction_percent'] );
		$this->assertGreaterThan( 0, $result['optimized_size'] );
		$this->assertFileExists( $result['output_path'] );

		if ( isset( $result['output_path'] ) && file_exists( $result['output_path'] ) ) {
			wp_delete_file( $result['output_path'] );
		}
	}
}
