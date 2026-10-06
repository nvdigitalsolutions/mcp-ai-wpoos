<?php
/**
 * Tests for provider response content flattening in document generation tools.
 *
 * Regression: Gemini (and some OpenAI-compatible gateways) return
 * message.content as an array of parts; the pro_pdf/pro_word/pro_excel tools
 * used to feed that array straight into preg_match() — a fatal TypeError.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 */

/**
 * Provider content flattening test case.
 */
class Test_DocGen_Provider_Content_Flattening extends WP_UnitTestCase {

	/**
	 * Tool instance (pro_pdf is representative — pro_word and
	 * pro_excel_document carry byte-identical call_provider logic).
	 *
	 * @var WP_MCP_AI_Tool_Pro_PDF
	 */
	private $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-pdf.php';
		$this->tool = new WP_MCP_AI_Tool_Pro_PDF();

		if ( class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
			WP_MCP_AI_Credential_Resolver::clear_cache();
		}
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
		delete_option( 'wp_mcp_ai_credentials' );
	}

	/**
	 * The Gemini path must flatten array-of-parts content into a string
	 * (regression: it previously returned the raw array, which fataled in
	 * preg_match()).
	 */
	public function test_call_provider_flattens_gemini_array_content() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'gemini_api_key'       => 'gsk-test',
				'default_gemini_model' => 'gemini-test-model',
			)
		);

		$filter_callback = function () {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'candidates'    => array(
							array(
								'content'      => array(
									'parts' => array(
										array( 'text' => 'Part one.' ),
										array( 'text' => 'Part two.' ),
									),
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

		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'call_provider' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$this->tool,
			'gemini',
			'gemini-test-model',
			array(
				array(
					'role'    => 'user',
					'content' => 'Generate a PDF.',
				),
			),
			array()
		);

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'content', $result );
		$this->assertSame( 'Part one.Part two.', $result['content'] );
	}

	/**
	 * String content must pass through unchanged.
	 */
	public function test_call_provider_passes_through_string_content() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'openai_api_key' => 'sk-test',
			)
		);

		$filter_callback = function () {
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
									'content' => 'Plain text content.',
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

		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'call_provider' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$this->tool,
			'openai',
			'gpt-4.1',
			array(
				array(
					'role'    => 'user',
					'content' => 'Generate a PDF.',
				),
			),
			array()
		);

		remove_filter( 'pre_http_request', $filter_callback, 10 );

		$this->assertIsArray( $result );
		$this->assertSame( 'Plain text content.', $result['content'] );
	}

	/**
	 * JSON response parsing must flatten array-of-parts input instead of
	 * fataling, and still extract the JSON payload.
	 */
	public function test_try_parse_json_response_flattens_array_content() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'try_parse_json_response' );
		$method->setAccessible( true );

		$parts = array(
			array(
				'type' => 'text',
				'text' => '{"title":',
			),
			array(
				'type' => 'text',
				'text' => '"Quarterly Report"}',
			),
		);

		$parsed = $method->invoke( $this->tool, $parts );

		$this->assertIsArray( $parsed );
		$this->assertSame( 'Quarterly Report', $parsed['title'] );
	}

	/**
	 * JSON wrapped in a markdown fence must parse (regression: the fence
	 * pattern had an unbalanced parenthesis and never compiled).
	 */
	public function test_try_parse_json_response_parses_fenced_json() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'try_parse_json_response' );
		$method->setAccessible( true );

		$parsed = $method->invoke( $this->tool, "```json\n{\"title\":\"Fenced Doc\"}\n```" );

		$this->assertIsArray( $parsed );
		$this->assertSame( 'Fenced Doc', $parsed['title'] );
	}

	/**
	 * Response content flattening must handle every provider shape.
	 */
	public function test_flatten_response_content_handles_shapes() {
		$reflection = new ReflectionClass( $this->tool );
		$method     = $reflection->getMethod( 'flatten_response_content' );
		$method->setAccessible( true );

		// String content (OpenAI-style).
		$this->assertSame(
			'Plain text.',
			$method->invoke(
				$this->tool,
				array(
					'choices' => array(
						array(
							'message' => array( 'content' => 'Plain text.' ),
						),
					),
				)
			)
		);

		// Array-of-parts content (Gemini normalize_response).
		$this->assertSame(
			'Part one.Part two.',
			$method->invoke(
				$this->tool,
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

		// Top-level content fallback shape.
		$this->assertSame(
			'Fallback.',
			$method->invoke( $this->tool, array( 'content' => 'Fallback.' ) )
		);

		// Empty payloads must yield ''.
		$this->assertSame( '', $method->invoke( $this->tool, array() ) );
	}

	/**
	 * All three provider-based Pro tools must carry the flattening helper.
	 */
	public function test_all_pro_tools_expose_flatten_helper() {
		$tools = array(
			WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-pdf.php'          => 'WP_MCP_AI_Tool_Pro_PDF',
			WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-word.php'         => 'WP_MCP_AI_Tool_Pro_Word',
			WP_MCP_AI_PRO_PATH . 'includes/tools/document-generation/class-wp-mcp-ai-tool-pro-excel-document.php' => 'WP_MCP_AI_Tool_Pro_Excel_Document',
		);

		foreach ( $tools as $file => $class ) {
			require_once $file;
			$this->assertTrue( class_exists( $class ), $class . ' should exist' );
			$this->assertTrue( method_exists( $class, 'flatten_response_content' ), $class . '::flatten_response_content() should exist' );
			$this->assertTrue( method_exists( $class, 'try_parse_json_response' ), $class . '::try_parse_json_response() should exist' );
		}
	}
}
