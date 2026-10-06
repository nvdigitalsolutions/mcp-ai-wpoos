<?php
/**
 * Tests for the orchestration toolkit audit.
 *
 * The orchestration toolkit audit found one confirmed class-3 fatal plus a
 * family of argument-shape and envelope issues:
 *  - generate_research_report fed raw provider content into preg_match() /
 *    json_decode() — fatal when Gemini or an OpenAI-compatible gateway
 *    returns message.content as an array of parts (the known CRM-audit
 *    follow-up);
 *  - eight tools returned non-canonical array( 'success' => false, ... )
 *    validation envelopes instead of WP_Error;
 *  - two cross-tool consumers read those envelopes as arrays without an
 *    is_wp_error() guard (fatal on the error path);
 *  - aggregate/verify/extract/convert/instantiate fed raw argument payloads
 *    into md5()/similar_text()/stripos()/preg_*()/str_replace() without
 *    string/array guards;
 *  - analyze_data_patterns advertised frequency/correlation/outliers enums
 *    that silently did nothing.
 *
 * This suite locks the fixes: flatten shape matrix and end-to-end provider
 * mocks, canonical WP_Error validation, consumer degradation, argument-shape
 * tolerance, and the full analysis-type enum matrix.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group orchestration
 * @group pro
 */

/**
 * Orchestration toolkit hardening test case.
 */
class Test_Orchestration_Toolkit_Hardening extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_user;

	/**
	 * Generate Research Report tool instance.
	 *
	 * @var WP_MCP_AI_Pro_Tool_Generate_Research_Report
	 */
	private $report_tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		$this->admin_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user );

		$base = WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/';

		require_once $base . 'class-wp-mcp-ai-pro-tool-generate-research-report.php';
		require_once $base . 'class-wp-mcp-ai-pro-tool-get-task-plan.php';
		require_once $base . 'class-wp-mcp-ai-pro-tool-update-task-plan.php';
		require_once $base . 'class-wp-mcp-ai-pro-tool-get-session-status.php';
		require_once $base . 'class-wp-mcp-ai-pro-tool-detect-completion-indicators.php';
		require_once $base . 'class-wp-mcp-ai-pro-tool-generate-password.php';
		require_once $base . 'class-wp-mcp-ai-pro-tool-aggregate-research-data.php';
		require_once $base . 'class-wp-mcp-ai-pro-tool-convert-html-to-markdown.php';
		require_once $base . 'class-wp-mcp-ai-pro-tool-extract-structured-data.php';
		require_once $base . 'class-wp-mcp-ai-pro-tool-verify-information.php';
		require_once $base . 'class-wp-mcp-ai-pro-tool-analyze-data-patterns.php';
		require_once $base . 'class-wp-mcp-ai-pro-tool-instantiate-template.php';

		if ( ! class_exists( 'WP_MCP_AI_Vault_Encryption_Service' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/vault/class-wp-mcp-ai-vault-encryption-service.php';
		}

		if ( ! trait_exists( 'WP_MCP_AI_Tool_Chat_Response' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
		}

		$this->report_tool = new WP_MCP_AI_Pro_Tool_Generate_Research_Report();

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		delete_option( 'wp_mcp_ai_credentials' );
		delete_option( 'wp_mcp_ai_settings' );
		WP_MCP_AI_Credential_Resolver::clear_cache();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
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
		return $this->openai_mock_with_content( '{"title":"Robotics Report","sections":[{"heading":"Overview","content":"Robotics content."}]}' );
	}

	/**
	 * Build a mocked HTTP response carrying empty OpenAI content.
	 *
	 * Public so add_filter() can register it as a callback.
	 *
	 * @return array Mock response array for pre_http_request.
	 */
	public function openai_empty_mock() {
		return $this->openai_mock_with_content( '' );
	}

	/**
	 * Build an OpenAI-shaped HTTP mock with the given content string.
	 *
	 * @param string $content Message content.
	 * @return array Mock response array for pre_http_request.
	 */
	private function openai_mock_with_content( $content ) {
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
								'content' => $content,
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
			$this->invoke_private( $this->report_tool, 'flatten_response_content', array( 'Plain text.' ) )
		);

		// Array-of-parts content (Gemini normalize_response).
		$this->assertSame(
			'Part one.Part two.',
			$this->invoke_private(
				$this->report_tool,
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
			$this->invoke_private( $this->report_tool, 'flatten_response_content', array( array( 'A', 'B' ) ) )
		);

		// Non-string / non-array content yields ''.
		$this->assertSame( '', $this->invoke_private( $this->report_tool, 'flatten_response_content', array( null ) ) );
		$this->assertSame( '', $this->invoke_private( $this->report_tool, 'flatten_response_content', array( 42 ) ) );
	}

	/**
	 * Perform_ai_research() must flatten Gemini array-of-parts content
	 * instead of passing the raw array downstream.
	 */
	public function test_perform_ai_research_flattens_gemini_array_content() {
		update_option( 'wp_mcp_ai_settings', array( 'gemini_api_key' => 'gsk-test' ) );
		WP_MCP_AI_Credential_Resolver::clear_cache();

		add_filter( 'pre_http_request', array( $this, 'gemini_parts_mock' ), 10, 3 );

		$result = $this->invoke_private( $this->report_tool, 'perform_ai_research', array( 'Research robotics clubs.', array() ) );

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

		$result = $this->invoke_private( $this->report_tool, 'perform_ai_research', array( 'Research robotics clubs.', array() ) );

		remove_filter( 'pre_http_request', array( $this, 'openai_string_mock' ), 10 );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertIsString( $result['content'] );
	}

	/**
	 * Perform_ai_research() must reject empty content with an honest error
	 * instead of handing an empty payload to the JSON parser.
	 */
	public function test_perform_ai_research_rejects_empty_content() {
		update_option( 'wp_mcp_ai_settings', array( 'openai_api_key' => 'sk-test' ) );
		WP_MCP_AI_Credential_Resolver::clear_cache();

		add_filter( 'pre_http_request', array( $this, 'openai_empty_mock' ), 10, 3 );

		$result = $this->invoke_private( $this->report_tool, 'perform_ai_research', array( 'Research robotics clubs.', array() ) );

		remove_filter( 'pre_http_request', array( $this, 'openai_empty_mock' ), 10 );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_invalid_response', $result->get_error_code() );
	}

	/**
	 * Parse_and_format_research() must accept array-of-parts content without
	 * fataling (regression: it fed the raw array into preg_match()).
	 */
	public function test_parse_and_format_research_handles_array_content() {
		$parts = array(
			array(
				'type' => 'text',
				'text' => '{"title":',
			),
			array(
				'type' => 'text',
				'text' => '"Robotics Report","sections":[{"heading":"Overview","content":"Robotics content."}]}',
			),
		);

		$result = $this->invoke_private(
			$this->report_tool,
			'parse_and_format_research',
			array(
				array( 'content' => $parts ),
				'robotics',
				'general',
				array( 'sources' => array() ),
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Robotics Report', $result['title'] );
	}

	/**
	 * Parse_and_format_research() must reject empty content with an honest
	 * error instead of a confusing JSON parse failure.
	 */
	public function test_parse_and_format_research_rejects_empty_content() {
		$result = $this->invoke_private(
			$this->report_tool,
			'parse_and_format_research',
			array(
				array( 'content' => array() ),
				'robotics',
				'general',
				array( 'sources' => array() ),
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_invalid_response', $result->get_error_code() );
	}

	/**
	 * Get_task_plan must return canonical WP_Errors, not false-success arrays.
	 */
	public function test_get_task_plan_rejects_missing_plan_id() {
		$tool   = new WP_MCP_AI_Pro_Tool_Get_Task_Plan();
		$result = $tool->execute( array(), array() );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_missing_plan_id', $result->get_error_code() );
	}

	/**
	 * Get_task_plan must return a canonical WP_Error for unknown plans.
	 */
	public function test_get_task_plan_rejects_unknown_plan() {
		$tool   = new WP_MCP_AI_Pro_Tool_Get_Task_Plan();
		$result = $tool->execute( array( 'plan_id' => 99999999 ), array() );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_task_plan_not_found', $result->get_error_code() );
	}

	/**
	 * Update_task_plan must return canonical WP_Errors for missing and
	 * unknown plans.
	 */
	public function test_update_task_plan_rejects_missing_and_unknown_plan() {
		$tool = new WP_MCP_AI_Pro_Tool_Update_Task_Plan();

		$missing = $tool->execute( array(), array() );
		$this->assertInstanceOf( 'WP_Error', $missing );
		$this->assertSame( 'wp_mcp_ai_missing_plan_id', $missing->get_error_code() );

		$unknown = $tool->execute( array( 'plan_id' => 99999999 ), array() );
		$this->assertInstanceOf( 'WP_Error', $unknown );
		$this->assertSame( 'wp_mcp_ai_task_plan_not_found', $unknown->get_error_code() );
	}

	/**
	 * Get_session_status must return canonical WP_Errors for missing and
	 * unknown sessions.
	 */
	public function test_get_session_status_rejects_missing_and_unknown_session() {
		$tool = new WP_MCP_AI_Pro_Tool_Get_Session_Status();

		$missing = $tool->execute( array(), array() );
		$this->assertInstanceOf( 'WP_Error', $missing );
		$this->assertSame( 'wp_mcp_ai_missing_session_id', $missing->get_error_code() );

		$unknown = $tool->execute( array( 'session_id' => 'ghost-session' ), array() );
		$this->assertInstanceOf( 'WP_Error', $unknown );
		$this->assertSame( 'wp_mcp_ai_session_not_found', $unknown->get_error_code() );
	}

	/**
	 * Detect_completion_indicators must degrade gracefully when the nested
	 * get_task_plan call returns a WP_Error (regression: it read
	 * $result['success'] off the WP_Error object).
	 */
	public function test_detect_completion_indicators_plan_lookup_degrades() {
		$tool   = new WP_MCP_AI_Pro_Tool_Detect_Completion_Indicators();
		$result = $this->invoke_private( $tool, 'check_plan_completion', array( 99999999 ) );

		$this->assertNull( $result );
	}

	/**
	 * Get_session_status's internal plan lookup must degrade gracefully when
	 * the nested get_task_plan call returns a WP_Error.
	 */
	public function test_get_session_status_plan_lookup_degrades() {
		$tool   = new WP_MCP_AI_Pro_Tool_Get_Session_Status();
		$result = $this->invoke_private( $tool, 'get_task_plan', array( 99999999 ) );

		$this->assertNull( $result );
	}

	/**
	 * Generate_password must reject out-of-range arguments with canonical
	 * WP_Errors.
	 */
	public function test_generate_password_rejects_out_of_range() {
		$tool = new WP_MCP_AI_Pro_Tool_Generate_Password();

		$short = $tool->execute( array( 'length' => 11 ), array() );
		$this->assertInstanceOf( 'WP_Error', $short );
		$this->assertSame( 'wp_mcp_ai_invalid_password_length', $short->get_error_code() );

		$zero_count = $tool->execute( array( 'count' => 0 ), array() );
		$this->assertInstanceOf( 'WP_Error', $zero_count );
		$this->assertSame( 'wp_mcp_ai_invalid_password_count', $zero_count->get_error_code() );

		$no_sets = $tool->execute(
			array(
				'uppercase' => false,
				'lowercase' => false,
				'numbers'   => false,
				'symbols'   => false,
			),
			array()
		);
		$this->assertInstanceOf( 'WP_Error', $no_sets );
		$this->assertSame( 'wp_mcp_ai_no_character_sets', $no_sets->get_error_code() );
	}

	/**
	 * Generate_password must return a generated password on the happy path.
	 */
	public function test_generate_password_returns_password() {
		$tool   = new WP_MCP_AI_Pro_Tool_Generate_Password();
		$result = $tool->execute( array( 'length' => 16 ), array() );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertTrue( $result['success'] );
		$this->assertIsString( $result['password'] );
		$this->assertSame( 16, strlen( $result['password'] ) );
	}

	/**
	 * Convert_html_to_markdown must reject missing and non-string HTML with
	 * canonical WP_Errors.
	 */
	public function test_convert_html_to_markdown_rejects_bad_html() {
		$tool = new WP_MCP_AI_Pro_Tool_Convert_Html_To_Markdown();

		$missing = $tool->execute( array(), array() );
		$this->assertInstanceOf( 'WP_Error', $missing );
		$this->assertSame( 'wp_mcp_ai_html_required', $missing->get_error_code() );

		$array_html = $tool->execute( array( 'html' => array( '<p>Nope</p>' ) ), array() );
		$this->assertInstanceOf( 'WP_Error', $array_html );
		$this->assertSame( 'wp_mcp_ai_invalid_html', $array_html->get_error_code() );
	}

	/**
	 * Convert_html_to_markdown must convert string HTML.
	 */
	public function test_convert_html_to_markdown_converts() {
		$tool   = new WP_MCP_AI_Pro_Tool_Convert_Html_To_Markdown();
		$result = $tool->execute( array( 'html' => '<h1>Hi</h1><p>Text</p>' ), array() );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( '# Hi', $result['markdown'] );
	}

	/**
	 * Extract_structured_data must reject missing and malformed arguments
	 * with canonical WP_Errors.
	 */
	public function test_extract_structured_data_rejects_bad_arguments() {
		$tool = new WP_MCP_AI_Pro_Tool_Extract_Structured_Data();

		$missing = $tool->execute( array(), array() );
		$this->assertInstanceOf( 'WP_Error', $missing );
		$this->assertSame( 'wp_mcp_ai_content_selectors_required', $missing->get_error_code() );

		$array_content = $tool->execute(
			array(
				'content'   => array( '<p>Nope</p>' ),
				'selectors' => array( 'field' => 'p' ),
			),
			array()
		);
		$this->assertInstanceOf( 'WP_Error', $array_content );
		$this->assertSame( 'wp_mcp_ai_invalid_content', $array_content->get_error_code() );

		$string_selectors = $tool->execute(
			array(
				'content'   => '<p>Nope</p>',
				'selectors' => 'p',
			),
			array()
		);
		$this->assertInstanceOf( 'WP_Error', $string_selectors );
		$this->assertSame( 'wp_mcp_ai_invalid_selectors', $string_selectors->get_error_code() );
	}

	/**
	 * Extract_structured_data must extract by tag and by regex without
	 * fataling on a non-string selector value.
	 */
	public function test_extract_structured_data_extracts() {
		$tool   = new WP_MCP_AI_Pro_Tool_Extract_Structured_Data();
		$result = $tool->execute(
			array(
				'content'   => '<title>Tag</title><p>Hello world</p>',
				'selectors' => array(
					'by_tag'   => 'title',
					'by_regex' => 'H[a-z]+',
					'by_array' => array( 'nested' ),
				),
			),
			array()
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Tag', $result['extracted_data']['by_tag'] );
		$this->assertSame( 'Hello', $result['extracted_data']['by_regex'] );
		$this->assertSame( '', $result['extracted_data']['by_array'] );
	}

	/**
	 * Aggregate_research_data must reject missing arguments with canonical
	 * WP_Errors.
	 */
	public function test_aggregate_research_data_rejects_bad_arguments() {
		$tool = new WP_MCP_AI_Pro_Tool_Aggregate_Research_Data();

		$missing_sources = $tool->execute( array(), array() );
		$this->assertInstanceOf( 'WP_Error', $missing_sources );
		$this->assertSame( 'wp_mcp_ai_sources_required', $missing_sources->get_error_code() );

		$missing_topic = $tool->execute(
			array(
				'sources' => array(
					array(
						'url'     => 'https://example.com',
						'content' => 'Text.',
					),
				),
			),
			array()
		);
		$this->assertInstanceOf( 'WP_Error', $missing_topic );
		$this->assertSame( 'wp_mcp_ai_topic_required', $missing_topic->get_error_code() );
	}

	/**
	 * Aggregate_research_data must tolerate array-shaped source content
	 * (regression: md5()/similar_text()/substr()/stripos() fatal on arrays).
	 */
	public function test_aggregate_research_data_handles_array_content() {
		$tool   = new WP_MCP_AI_Pro_Tool_Aggregate_Research_Data();
		$result = $tool->execute(
			array(
				'sources' => array(
					array(
						'url'     => 'https://example.com/one',
						'content' => array(
							array(
								'text' => 'Important finding one.',
							),
						),
					),
					array(
						'url'     => 'https://example.com/two',
						'content' => array(
							array(
								'text' => 'Important finding two.',
							),
						),
					),
				),
				'topic'   => 'robotics',
			),
			array()
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['unique_sources'] );
		$this->assertSame( 0, $result['duplicates_found'] );
	}

	/**
	 * Aggregate_research_data must deduplicate sources by URL.
	 */
	public function test_aggregate_research_data_deduplicates_by_url() {
		$tool   = new WP_MCP_AI_Pro_Tool_Aggregate_Research_Data();
		$result = $tool->execute(
			array(
				'sources' => array(
					array(
						'url'     => 'https://example.com/same',
						'content' => 'First copy.',
					),
					array(
						'url'     => 'https://example.com/same',
						'content' => 'Second copy.',
					),
				),
				'topic'   => 'robotics',
			),
			array()
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertSame( 1, $result['unique_sources'] );
		$this->assertSame( 1, $result['duplicates_found'] );
	}

	/**
	 * Verify_information must tolerate sources with missing or array-shaped
	 * content (regression: stripos() fatals on non-strings).
	 */
	public function test_verify_information_handles_missing_and_array_content() {
		$tool   = new WP_MCP_AI_Pro_Tool_Verify_Information();
		$result = $tool->execute(
			array(
				'claim'   => 'Robots are useful',
				'sources' => array(
					array(
						'url' => 'https://example.com/one',
					),
					array(
						'url'     => 'https://example.com/two',
						'content' => array(
							array(
								'text' => 'Robots are useful',
							),
						),
					),
				),
			),
			array()
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['sources_checked'] );
	}

	/**
	 * Analyze_data_patterns must serve every advertised analysis_type enum.
	 */
	public function test_analyze_data_patterns_enum_matrix() {
		$tool = new WP_MCP_AI_Pro_Tool_Analyze_Data_Patterns();

		$trend = $tool->execute(
			array(
				'dataset'       => array( 1, 2, 3, 4, 5, 6, 7, 8 ),
				'analysis_type' => 'trend',
			),
			array()
		);
		$this->assertNotInstanceOf( 'WP_Error', $trend );
		$this->assertSame( 'increasing', $trend['analysis']['trend'] );

		$frequency = $tool->execute(
			array(
				'dataset'       => array( 1, 1, 1, 2, 2, 3 ),
				'analysis_type' => 'frequency',
			),
			array()
		);
		$this->assertNotInstanceOf( 'WP_Error', $frequency );
		$this->assertNotEmpty( $frequency['analysis']['frequency'] );
		$this->assertSame( 1.0, $frequency['analysis']['frequency'][0]['value'] );
		$this->assertSame( 3, $frequency['analysis']['frequency'][0]['count'] );

		$correlation = $tool->execute(
			array(
				'dataset'       => array( 1, 2, 3, 4, 5 ),
				'analysis_type' => 'correlation',
			),
			array()
		);
		$this->assertNotInstanceOf( 'WP_Error', $correlation );
		$this->assertSame( 1.0, $correlation['analysis']['correlation']['coefficient'] );
		$this->assertSame( 'strong positive correlation', $correlation['analysis']['correlation']['interpretation'] );

		$outliers = $tool->execute(
			array(
				'dataset'       => array( 1, 2, 2, 2, 3, 100 ),
				'analysis_type' => 'outliers',
			),
			array()
		);
		$this->assertNotInstanceOf( 'WP_Error', $outliers );
		$this->assertSame( array( 100.0 ), $outliers['analysis']['outliers']['outliers'] );
	}

	/**
	 * Analyze_data_patterns must reject datasets without numeric values.
	 */
	public function test_analyze_data_patterns_rejects_non_numeric() {
		$tool   = new WP_MCP_AI_Pro_Tool_Analyze_Data_Patterns();
		$result = $tool->execute( array( 'dataset' => array( 'a', 'b', 'c' ) ), array() );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_analyze_data_patterns_no_numeric_data', $result->get_error_code() );
	}

	/**
	 * Instantiate_template must require a template_id.
	 */
	public function test_instantiate_template_requires_template_id() {
		$tool   = new WP_MCP_AI_Pro_Tool_Instantiate_Template();
		$result = $tool->execute( array(), array() );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'tool_error', $result->get_error_code() );
	}

	/**
	 * Instantiate_template must skip non-scalar variable values and still
	 * build the plan (regression: str_replace() fatals on array values).
	 */
	public function test_instantiate_template_skips_non_scalar_variables() {
		$template_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_task_template',
				'post_title'   => 'Robotics Template',
				'post_status'  => 'publish',
				'post_content' => "{{goal}}\n\n## Tasks\n\n- [ ] Do things",
			)
		);
		update_post_meta( $template_id, 'default_config', array( 'depth' => 'standard' ) );

		$tool   = new WP_MCP_AI_Pro_Tool_Instantiate_Template();
		$result = $tool->execute(
			array(
				'template_id'      => $template_id,
				'variables'        => array(
					'goal' => 'Build robots',
					'task' => array( 'nested', 'value' ),
				),
				'config_overrides' => array( 'depth' => 'basic' ),
			),
			array()
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Build robots', $result['goal'] );
		$this->assertSame( array( 'depth' => 'basic' ), $result['config'] );
	}
}
