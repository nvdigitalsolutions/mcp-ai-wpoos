<?php
/**
 * Tests for ECA management toolkit hardening (provider response flattening).
 *
 * Regression coverage for the research_eca fatal chain: Gemini (and
 * OpenAI-compatible gateways such as vLLM) return message.content as an
 * array of parts; perform_ai_research() passed that raw value through to
 * parse_research_results(), which fed it straight into preg_match() —
 * `preg_match(): Argument #2 ($subject) must be of type string, array given`.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group eca-management
 * @group pro
 */

/**
 * ECA management toolkit hardening test case.
 */
class Test_ECA_Toolkit_Hardening extends WP_UnitTestCase {

	/**
	 * Research ECA tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Research_ECA
	 */
	private $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/eca-management/class-wp-mcp-ai-tool-research-eca.php';
		$this->tool = new WP_MCP_AI_Tool_Research_ECA();

		// Neutralise environment-provided keys so provider selection is
		// driven solely by the wp_mcp_ai_settings option in these tests.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		putenv( 'OPENAI_API_KEY' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		putenv( 'GEMINI_API_KEY' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		putenv( 'ANTHROPIC_API_KEY' );

		if ( class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
			WP_MCP_AI_Credential_Resolver::clear_cache();
		}
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
		delete_option( 'wp_mcp_ai_credentials' );
		update_option( 'wp_mcp_ai_settings', array() );
	}

	/**
	 * Build a mocked HTTP response carrying Gemini array-of-parts content.
	 *
	 * Public so add_filter() can register it as a callback.
	 *
	 * @return array Mock response array for pre_http_request.
	 */
	public function gemini_parts_mock() {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'candidates'    => array(
						array(
							'content'      => array(
								'parts' => array(
									array(
										'text' => 'Part one.',
									),
									array(
										'text' => 'Part two.',
									),
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
	}

	/**
	 * Build a mocked HTTP response carrying OpenAI string content.
	 *
	 * Public so add_filter() can register it as a callback.
	 *
	 * @return array Mock response array for pre_http_request.
	 */
	public function openai_string_mock() {
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
								'content' => '{"title":"Robotics Club"}',
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
	}

	/**
	 * Invoke a private/protected method on an object.
	 *
	 * @param object $target    Target object.
	 * @param string $method    Method name.
	 * @param array  $arguments Method arguments.
	 * @return mixed Method return value.
	 */
	private function invoke_private( $target, $method, array $arguments ) {
		$reflection = new ReflectionClass( $target );
		$method_ref = $reflection->getMethod( $method );
		$method_ref->setAccessible( true );
		return $method_ref->invokeArgs( $target, $arguments );
	}

	/**
	 * The flattening helper must handle every provider shape.
	 */
	public function test_flatten_response_content_handles_shapes() {
		// String content (OpenAI-style).
		$this->assertSame(
			'Plain text.',
			$this->invoke_private( $this->tool, 'flatten_response_content', array( 'Plain text.' ) )
		);

		// Array-of-parts content (Gemini normalize_response).
		$this->assertSame(
			'Part one.Part two.',
			$this->invoke_private(
				$this->tool,
				'flatten_response_content',
				array(
					array(
						array(
							'type' => 'text',
							'text' => 'Part one.',
						),
						array(
							'type' => 'text',
							'text' => 'Part two.',
						),
					),
				)
			)
		);

		// Array-of-strings content.
		$this->assertSame(
			'AB',
			$this->invoke_private( $this->tool, 'flatten_response_content', array( array( 'A', 'B' ) ) )
		);

		// Non-string / non-array content yields ''.
		$this->assertSame( '', $this->invoke_private( $this->tool, 'flatten_response_content', array( null ) ) );
		$this->assertSame( '', $this->invoke_private( $this->tool, 'flatten_response_content', array( 42 ) ) );
	}

	/**
	 * Perform_ai_research() must flatten Gemini array-of-parts content
	 * instead of passing the raw array downstream.
	 */
	public function test_perform_ai_research_flattens_gemini_array_content() {
		update_option( 'wp_mcp_ai_settings', array( 'gemini_api_key' => 'gsk-test' ) );
		WP_MCP_AI_Credential_Resolver::clear_cache();

		add_filter( 'pre_http_request', array( $this, 'gemini_parts_mock' ), 10, 3 );

		$result = $this->invoke_private( $this->tool, 'perform_ai_research', array( 'Research robotics clubs.', array() ) );

		remove_filter( 'pre_http_request', array( $this, 'gemini_parts_mock' ), 10 );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertIsString( $result['content'] );
		$this->assertSame( 'Part one.Part two.', $result['content'] );
	}

	/**
	 * Perform_ai_research() must pass OpenAI string content through.
	 */
	public function test_perform_ai_research_passes_string_content() {
		update_option( 'wp_mcp_ai_settings', array( 'openai_api_key' => 'sk-test' ) );
		WP_MCP_AI_Credential_Resolver::clear_cache();

		add_filter( 'pre_http_request', array( $this, 'openai_string_mock' ), 10, 3 );

		$result = $this->invoke_private( $this->tool, 'perform_ai_research', array( 'Research robotics clubs.', array() ) );

		remove_filter( 'pre_http_request', array( $this, 'openai_string_mock' ), 10 );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertIsString( $result['content'] );
		$this->assertSame( '{"title":"Robotics Club"}', $result['content'] );
	}

	/**
	 * Parse_research_results() must accept array-of-parts content without
	 * fataling (regression: it fed the raw array into preg_match()).
	 */
	public function test_parse_research_results_handles_array_content() {
		$parts = array(
			array(
				'type' => 'text',
				'text' => '{"title":',
			),
			array(
				'type' => 'text',
				'text' => '"Robotics Club","category":"STEM"}',
			),
		);

		$result = $this->invoke_private(
			$this->tool,
			'parse_research_results',
			array(
				array(
					'content'  => $parts,
					'provider' => 'gemini',
					'model'    => 'gemini-test',
				),
				'Robotics Club',
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'Robotics Club', $result['title'] );
		$this->assertSame( 'STEM', $result['category'] );
	}

	/**
	 * Fenced JSON content must still parse (string path unchanged).
	 */
	public function test_parse_research_results_parses_fenced_json() {
		$result = $this->invoke_private(
			$this->tool,
			'parse_research_results',
			array(
				array(
					'content'  => "```json\n{\"title\":\"Debate Team\",\"category\":\"Humanities\"}\n```",
					'provider' => 'openai',
					'model'    => 'gpt-4.1',
				),
				'Debate Team',
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'Debate Team', $result['title'] );
		$this->assertSame( 'Humanities', $result['category'] );
	}

	/**
	 * Empty content must yield an honest parse error instead of a fatal.
	 */
	public function test_parse_research_results_errors_on_empty_content() {
		$result = $this->invoke_private(
			$this->tool,
			'parse_research_results',
			array(
				array(
					'content'  => array(),
					'provider' => 'gemini',
					'model'    => 'gemini-test',
				),
				'Robotics Club',
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_parse_error', $result->get_error_code() );
	}
}
