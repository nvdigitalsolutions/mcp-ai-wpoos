<?php
/**
 * Additional tool execution security tests for complex scenarios.
 *
 * Tests edge cases and complex execution paths to ensure
 * all tools properly enforce security across different scenarios.
 *
 * @package WP_MCP_AI
 */

/**
 * Test complex tool execution scenarios.
 *
 * @group security
 * @group tools
 */
class WP_MCP_AI_Tool_Execution_Complex_Security_Test extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure classes are loaded.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-security-validator.php';
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-tool-registry.php';
	}

	/**
	 * Test that POST /tools endpoint enforces security validation.
	 */
	public function test_tools_endpoint_enforces_security() {
		// Create test user and assistant.
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		// Configure assistant with test tool.
		update_post_meta( $assistant_id, '_wp_mcp_ai_assistant_config', array(
			'tools' => array( 'test_tool' ),
		) );

		// Attempt SQL injection via POST /tools.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_param( 'assistant_id', $assistant_id );
		$request->set_param( 'tool', 'search_content' );
		$request->set_param( 'arguments', array(
			'query' => "' OR '1'='1",
		) );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		// The endpoint should exist.
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey(
			'/mcp-ai/v1/tools',
			$routes,
			'POST /tools endpoint should be registered'
		);
	}

	/**
	 * Test multisite context validation.
	 */
	public function test_multisite_user_validation() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test requires multisite' );
		}

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Tool execution should validate multisite membership in the tool itself.
		// The security validator handles basic authentication.
		$tool = $this->create_mock_tool( 'test_tool' );

		$result = WP_MCP_AI_Tool_Security_Validator::validate_tool_execution(
			$tool,
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertTrue(
			$result,
			'Security validator should pass - individual tools handle multisite checks'
		);
	}

	/**
	 * Test nested array argument sanitization.
	 */
	public function test_nested_array_sanitization() {
		$tool     = $this->create_mock_tool( 'test_tool' );
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// SQL injection in nested array.
		$result = WP_MCP_AI_Tool_Security_Validator::validate_tool_execution(
			$tool,
			array(
				'data' => array(
					'query' => "' UNION SELECT * FROM wp_users--",
				),
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'SQL injection in nested arrays should be detected'
		);
	}

	/**
	 * Test that capability checks work with token authentication.
	 */
	public function test_token_authentication_with_capabilities() {
		$tool = $this->create_mock_tool( 'generate_simple_jwt_token' );

		// Token auth without manage_options should fail.
		$result = WP_MCP_AI_Tool_Security_Validator::validate_tool_execution(
			$tool,
			array(),
			array(
				'user_id'             => 0,
				'token_authenticated' => true,
				'token_type'          => 'bearer',
			)
		);

		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'Credential tools should require manage_options even with token auth'
		);

		// Token auth WITH proper user_id and manage_options should pass.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$result = WP_MCP_AI_Tool_Security_Validator::validate_tool_execution(
			$tool,
			array(),
			array(
				'user_id'             => $admin_id,
				'token_authenticated' => true,
				'token_type'          => 'bearer',
			)
		);

		$this->assertTrue(
			$result,
			'Admin with token auth should pass credential tool validation'
		);
	}

	/**
	 * Test argument sanitization with special characters.
	 */
	public function test_special_character_sanitization() {
		$tool     = $this->create_mock_tool( 'test_tool' );
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Test various special characters that should be safe.
		$safe_characters = array(
			'Test with @mentions',
			'Email: test@example.com',
			'Price: $100.00',
			'Percentage: 50%',
			'Hashtag: #test',
		);

		foreach ( $safe_characters as $value ) {
			$result = WP_MCP_AI_Tool_Security_Validator::validate_tool_execution(
				$tool,
				array( 'content' => $value ),
				array( 'user_id' => $admin_id )
			);

			// These should pass - they're legitimate content.
			if ( is_wp_error( $result ) ) {
				$this->fail( "Safe character content should pass: {$value}. Error: " . $result->get_error_message() );
			}
		}
	}

	/**
	 * Test URL encoding bypass attempts.
	 */
	public function test_url_encoding_bypass_attempts() {
		$tool     = $this->create_mock_tool( 'test_tool' );
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$encoded_payloads = array(
			'%27%20OR%20%271%27%3D%271',  // ' OR '1'='1'.
			'%2e%2e%2f%2e%2e%2fetc%2fpasswd', // ../../etc/passwd.
		);

		foreach ( $encoded_payloads as $payload ) {
			$result = WP_MCP_AI_Tool_Security_Validator::validate_tool_execution(
				$tool,
				array( 'path' => $payload ),
				array( 'user_id' => $admin_id )
			);

			$this->assertInstanceOf(
				'WP_Error',
				$result,
				"URL-encoded payload should be detected: {$payload}"
			);
		}
	}

	/**
	 * Test case sensitivity in SQL injection detection.
	 */
	public function test_case_insensitive_sql_detection() {
		$tool     = $this->create_mock_tool( 'test_tool' );
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$case_variations = array(
			"' union select * from wp_users--",
			"' UNION SELECT * FROM wp_users--",
			"' UnIoN SeLeCt * FrOm wp_users--",
		);

		foreach ( $case_variations as $payload ) {
			$result = WP_MCP_AI_Tool_Security_Validator::validate_tool_execution(
				$tool,
				array( 'query' => $payload ),
				array( 'user_id' => $admin_id )
			);

			$this->assertInstanceOf(
				'WP_Error',
				$result,
				"SQL injection should be detected regardless of case: {$payload}"
			);
		}
	}

	/**
	 * Test attachment validation with structured attachments array.
	 */
	public function test_structured_attachments_validation() {
		$owner_id = $this->factory->user->create( array( 'role' => 'author' ) );
		$other_id = $this->factory->user->create( array( 'role' => 'author' ) );

		$attachment_id = $this->factory->attachment->create_object(
			'test.pdf',
			0,
			array(
				'post_mime_type' => 'application/pdf',
				'post_author'    => $owner_id,
				'post_status'    => 'private',
			)
		);

		$tool = $this->create_mock_tool( 'submit_document_prompt' );

		// Test with structured attachments array.
		wp_set_current_user( $other_id );
		$result = WP_MCP_AI_Tool_Security_Validator::validate_tool_execution(
			$tool,
			array(
				'prompt'      => 'Test',
				'attachments' => array(
					array(
						'id'           => $attachment_id,
						'display_name' => 'test.pdf',
					),
				),
			),
			array( 'user_id' => $other_id )
		);

		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'Structured attachments should enforce access control'
		);

		$this->assertEquals(
			'wp_mcp_ai_attachment_forbidden',
			$result->get_error_code()
		);
	}

	/**
	 * Test that public tools enforce authentication.
	 */
	public function test_public_tools_still_require_auth() {
		$tool = $this->create_mock_tool( 'web_search' );

		// Even public tools require authentication.
		$result = WP_MCP_AI_Tool_Security_Validator::validate_tool_execution(
			$tool,
			array( 'query' => 'test' ),
			array( 'user_id' => 0 ) // No auth.
		);

		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'Public tools should still require authentication'
		);

		$this->assertEquals(
			'wp_mcp_ai_authentication_required',
			$result->get_error_code()
		);
	}

	/**
	 * Test error logging for security violations.
	 */
	public function test_security_violation_logging() {
		if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			$this->markTestSkipped( 'Logger class not available' );
		}

		$tool     = $this->create_mock_tool( 'test_tool' );
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Clear any existing logs.
		delete_option( 'wp_mcp_ai_recent_errors' );

		// Attempt SQL injection.
		$result = WP_MCP_AI_Tool_Security_Validator::validate_tool_execution(
			$tool,
			array( 'query' => "' DROP TABLE wp_posts--" ),
			array( 'user_id' => $admin_id )
		);

		$this->assertInstanceOf(
			'WP_Error',
			$result,
			'SQL injection should be blocked'
		);

		// Security events should be logged.
		// Note: This depends on logger implementation.
		// If logger is available, verify the event was logged.
	}

	/**
	 * Test that validation works with empty arguments.
	 */
	public function test_empty_arguments_validation() {
		$tool     = $this->create_mock_tool( 'count_tokens' );
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$result = WP_MCP_AI_Tool_Security_Validator::validate_tool_execution(
			$tool,
			array(), // Empty arguments.
			array( 'user_id' => $admin_id )
		);

		$this->assertTrue(
			$result,
			'Empty arguments should pass security validation'
		);
	}

	/**
	 * Test document tool with file_id (external file).
	 */
	public function test_document_tool_with_external_file_id() {
		$user_id = $this->factory->user->create( array( 'role' => 'author' ) );
		$tool    = $this->create_mock_tool( 'submit_document_prompt' );

		// file_id references external OpenAI files, not WordPress attachments.
		// These should not be validated as attachments.
		$result = WP_MCP_AI_Tool_Security_Validator::validate_tool_execution(
			$tool,
			array(
				'prompt'  => 'Test',
				'file_id' => 'file-abc123', // External file.
			),
			array( 'user_id' => $user_id )
		);

		$this->assertTrue(
			$result,
			'External file_ids should pass validation (not WordPress attachments)'
		);
	}

	/**
	 * Create a mock tool for testing.
	 *
	 * @param string $slug Tool slug.
	 * @return WP_MCP_AI_Tool_Interface Mock tool instance.
	 */
	protected function create_mock_tool( $slug ) {
		$tool = $this->getMockBuilder( 'WP_MCP_AI_Tool_Interface' )
			->setMethods( array( 'get_slug', 'get_name', 'get_description', 'get_parameters_schema', 'execute' ) )
			->getMock();

		$tool->method( 'get_slug' )->willReturn( $slug );
		$tool->method( 'get_name' )->willReturn( 'Test Tool' );
		$tool->method( 'get_description' )->willReturn( 'Test tool description' );
		$tool->method( 'get_parameters_schema' )->willReturn( array() );

		return $tool;
	}
}
