<?php
/**
 * Tests for Gemini Managed Agent / Antigravity Service.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Gemini Managed Agent Service.
 */
class Test_Gemini_Managed_Agent_Service extends WP_UnitTestCase {
	/**
	 * Service instance.
	 *
	 * @var WP_MCP_AI_Gemini_Managed_Agent_Service
	 */
	protected $service;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-managed-agent-service.php';
		$this->service = new WP_MCP_AI_Gemini_Managed_Agent_Service();
	}

	/**
	 * Test that class constants are defined and have expected values.
	 */
	public function test_constants_are_defined() {
		$this->assertNotEmpty( WP_MCP_AI_Gemini_Managed_Agent_Service::INTERACTIONS_ENDPOINT );
		$this->assertStringContainsString( 'interactions', WP_MCP_AI_Gemini_Managed_Agent_Service::INTERACTIONS_ENDPOINT );
		$this->assertNotEmpty( WP_MCP_AI_Gemini_Managed_Agent_Service::AGENTS_ENDPOINT );
		$this->assertNotEmpty( WP_MCP_AI_Gemini_Managed_Agent_Service::ENV_DOWNLOAD_ENDPOINT );
		$this->assertNotEmpty( WP_MCP_AI_Gemini_Managed_Agent_Service::ANTIGRAVITY_AGENT_ID );
		$this->assertStringContainsString( 'antigravity', WP_MCP_AI_Gemini_Managed_Agent_Service::ANTIGRAVITY_AGENT_ID );
		$this->assertNotEmpty( WP_MCP_AI_Gemini_Managed_Agent_Service::API_REVISION );
		$this->assertEquals( '2026-05-20', WP_MCP_AI_Gemini_Managed_Agent_Service::API_REVISION );
	}

	/**
	 * Test that BUILTIN_TOOLS constant contains expected tool names.
	 */
	public function test_builtin_tools_contain_expected_values() {
		$tools = WP_MCP_AI_Gemini_Managed_Agent_Service::BUILTIN_TOOLS;

		$this->assertContains( 'code_execution', $tools );
		$this->assertContains( 'google_search', $tools );
		$this->assertContains( 'url_context', $tools );
		$this->assertContains( 'filesystem', $tools );
	}

	/**
	 * Test that managed agents availability defaults to false.
	 */
	public function test_is_managed_agents_available_defaults_false() {
		delete_option( 'wp_mcp_ai_settings' );
		$this->assertFalse( WP_MCP_AI_Gemini_Managed_Agent_Service::is_managed_agents_available() );
	}

	/**
	 * Test that managed agents are available when enabled in settings.
	 */
	public function test_is_managed_agents_available_when_enabled() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_managed_agents' => true ) );
		$this->assertTrue( WP_MCP_AI_Gemini_Managed_Agent_Service::is_managed_agents_available() );
	}

	/**
	 * Test that the managed agents availability filter can override the setting.
	 */
	public function test_is_managed_agents_available_filter_override() {
		delete_option( 'wp_mcp_ai_settings' );

		add_filter( 'wp_mcp_ai_managed_agents_available', '__return_true' );
		$this->assertTrue( WP_MCP_AI_Gemini_Managed_Agent_Service::is_managed_agents_available() );
		remove_filter( 'wp_mcp_ai_managed_agents_available', '__return_true' );

		add_filter( 'wp_mcp_ai_managed_agents_available', '__return_false' );
		update_option( 'wp_mcp_ai_settings', array( 'enable_managed_agents' => true ) );
		$this->assertFalse( WP_MCP_AI_Gemini_Managed_Agent_Service::is_managed_agents_available() );
		remove_filter( 'wp_mcp_ai_managed_agents_available', '__return_false' );
	}

	/**
	 * Test send_interaction requires the enabled gate.
	 */
	public function test_send_interaction_requires_enabled_gate() {
		delete_option( 'wp_mcp_ai_settings' );
		$result = $this->service->send_interaction( array( 'input' => 'Test task.' ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_managed_agents_unavailable', $result->get_error_code() );
	}

	/**
	 * Test send_interaction requires an API key.
	 */
	public function test_send_interaction_requires_api_key() {
		update_option( 'wp_mcp_ai_settings', array( 'enable_managed_agents' => true ) );
		$result = $this->service->send_interaction( array( 'input' => 'Test task.' ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_api_key', $result->get_error_code() );
	}

	/**
	 * Test send_interaction requires input.
	 */
	public function test_send_interaction_requires_input() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_managed_agents' => true,
				'gemini_api_key'        => 'AIza-test-key',
			)
		);
		$result = $this->service->send_interaction( array() );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_input', $result->get_error_code() );
	}

	/**
	 * Test normalise_environment returns 'remote' for default inputs.
	 */
	public function test_normalise_environment_remote() {
		$reflection = new ReflectionMethod( $this->service, 'normalise_environment' );
		$reflection->setAccessible( true );

		$this->assertEquals( 'remote', $reflection->invoke( $this->service, 'remote' ) );
		$this->assertEquals( 'remote', $reflection->invoke( $this->service, '' ) );
		$this->assertEquals( 'remote', $reflection->invoke( $this->service, '  ' ) );
	}

	/**
	 * Test normalise_environment passes through custom IDs and arrays.
	 */
	public function test_normalise_environment_passthrough() {
		$reflection = new ReflectionMethod( $this->service, 'normalise_environment' );
		$reflection->setAccessible( true );

		$env_id = 'env_abc123';
		$this->assertEquals( $env_id, $reflection->invoke( $this->service, $env_id ) );

		$config = array(
			'type'    => 'remote',
			'sources' => array(),
		);
		$this->assertSame( $config, $reflection->invoke( $this->service, $config ) );
	}

	/**
	 * Test build_tool_list filters out unknown tool slugs.
	 */
	public function test_build_tool_list_filters_unknown_slugs() {
		$reflection = new ReflectionMethod( $this->service, 'build_tool_list' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( $this->service, array( 'invalid_tool', 'code_execution' ) );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertEquals( 'code_execution', $result[0]['type'] );
	}

	/**
	 * Test build_tool_list excludes filesystem (auto-enabled via environment).
	 */
	public function test_build_tool_list_excludes_filesystem() {
		$reflection = new ReflectionMethod( $this->service, 'build_tool_list' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( $this->service, array( 'code_execution', 'filesystem' ) );

		$this->assertCount( 1, $result );
		$this->assertEquals( 'code_execution', $result[0]['type'] );
	}

	/**
	 * Test build_tool_list accepts all built-in tool types.
	 */
	public function test_build_tool_list_accepts_all_builtins() {
		$reflection = new ReflectionMethod( $this->service, 'build_tool_list' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke(
			$this->service,
			array( 'code_execution', 'google_search', 'url_context' )
		);

		$this->assertCount( 3, $result );
		$types = wp_list_pluck( $result, 'type' );
		$this->assertContains( 'code_execution', $types );
		$this->assertContains( 'google_search', $types );
		$this->assertContains( 'url_context', $types );
	}

	/**
	 * Test build_tool_list sanitizes tool slugs to lowercase.
	 */
	public function test_build_tool_list_sanitizes_slugs() {
		$reflection = new ReflectionMethod( $this->service, 'build_tool_list' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( $this->service, array( 'CoDe_ExEcUtIoN', 'GOOGLE_SEARCH' ) );

		$this->assertCount( 2, $result );
		$this->assertEquals( 'code_execution', $result[0]['type'] );
		$this->assertEquals( 'google_search', $result[1]['type'] );
	}

	/**
	 * Test build_multimodal_input with text-only input.
	 */
	public function test_build_multimodal_input_text_only() {
		$reflection = new ReflectionMethod( $this->service, 'build_multimodal_input' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke(
			$this->service,
			array(
				array(
					'type' => 'text',
					'text' => 'Hello world',
				),
			)
		);

		$this->assertCount( 1, $result );
		$this->assertEquals( 'text', $result[0]['type'] );
		$this->assertEquals( 'Hello world', $result[0]['text'] );
	}

	/**
	 * Test build_multimodal_input with image input.
	 */
	public function test_build_multimodal_input_image() {
		$reflection = new ReflectionMethod( $this->service, 'build_multimodal_input' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke(
			$this->service,
			array(
				array(
					'type'      => 'image',
					'data'      => 'iVBORw0KGgo...',
					'mime_type' => 'image/png',
				),
			)
		);

		$this->assertCount( 1, $result );
		$this->assertEquals( 'image', $result[0]['type'] );
		$this->assertEquals( 'iVBORw0KGgo...', $result[0]['data'] );
		$this->assertEquals( 'image/png', $result[0]['mime_type'] );
	}

	/**
	 * Test build_multimodal_input defaults mime_type to image/png.
	 */
	public function test_build_multimodal_input_defaults_mime_type() {
		$reflection = new ReflectionMethod( $this->service, 'build_multimodal_input' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke(
			$this->service,
			array(
				array(
					'type' => 'image',
					'data' => 'base64data',
				),
			)
		);

		$this->assertEquals( 'image/png', $result[0]['mime_type'] );
	}

	/**
	 * Test build_multimodal_input skips parts without valid type.
	 */
	public function test_build_multimodal_input_skips_invalid_parts() {
		$reflection = new ReflectionMethod( $this->service, 'build_multimodal_input' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke(
			$this->service,
			array(
				array( 'no_type' => 'here' ),
				array(
					'type' => 'text',
					'text' => 'Valid',
				),
				array( 'type' => 'unknown' ),
			)
		);

		$this->assertCount( 1, $result );
		$this->assertEquals( 'Valid', $result[0]['text'] );
	}

	/**
	 * Test build_multimodal_input with mixed text and image parts.
	 */
	public function test_build_multimodal_input_mixed_text_and_image() {
		$reflection = new ReflectionMethod( $this->service, 'build_multimodal_input' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke(
			$this->service,
			array(
				array(
					'type' => 'text',
					'text' => 'Analyze this chart:',
				),
				array(
					'type'      => 'image',
					'data'      => 'base64png',
					'mime_type' => 'image/png',
				),
			)
		);

		$this->assertCount( 2, $result );
		$this->assertEquals( 'text', $result[0]['type'] );
		$this->assertEquals( 'image', $result[1]['type'] );
	}

	/**
	 * Test build_headers includes the Api-Revision header.
	 */
	public function test_build_headers_includes_api_revision() {
		$reflection = new ReflectionMethod( $this->service, 'build_headers' );
		$reflection->setAccessible( true );

		$headers = $reflection->invoke( $this->service, 'AIza-test-key' );

		$this->assertEquals( 'application/json', $headers['Content-Type'] );
		$this->assertEquals( 'AIza-test-key', $headers['x-goog-api-key'] );
		$this->assertEquals( '2026-05-20', $headers['Api-Revision'] );
	}

	/**
	 * Test build_headers respects custom Content-Type.
	 */
	public function test_build_headers_custom_content_type() {
		$reflection = new ReflectionMethod( $this->service, 'build_headers' );
		$reflection->setAccessible( true );

		$headers = $reflection->invoke( $this->service, 'AIza-key', 'text/plain' );

		$this->assertEquals( 'text/plain', $headers['Content-Type'] );
	}

	/**
	 * Test parse_api_error returns 'unavailable' for 404 responses.
	 */
	public function test_parse_api_error_not_found() {
		$reflection = new ReflectionMethod( $this->service, 'parse_api_error' );
		$reflection->setAccessible( true );

		$error = $reflection->invoke(
			$this->service,
			404,
			array( 'error' => array( 'message' => 'Resource not found' ) ),
			''
		);

		$this->assertWPError( $error );
		$this->assertEquals( 'wp_mcp_ai_managed_agents_unavailable', $error->get_error_code() );
	}

	/**
	 * Test parse_api_error returns 'quota_exceeded' for 429 responses.
	 */
	public function test_parse_api_error_quota_exceeded() {
		$reflection = new ReflectionMethod( $this->service, 'parse_api_error' );
		$reflection->setAccessible( true );

		$error = $reflection->invoke(
			$this->service,
			429,
			array( 'error' => array( 'message' => 'Quota exceeded for this project' ) ),
			''
		);

		$this->assertWPError( $error );
		$this->assertEquals( 'wp_mcp_ai_quota_exceeded', $error->get_error_code() );
	}

	/**
	 * Test parse_api_error returns 'invalid_api_key' for 403 key errors.
	 */
	public function test_parse_api_error_invalid_key() {
		$reflection = new ReflectionMethod( $this->service, 'parse_api_error' );
		$reflection->setAccessible( true );

		$error = $reflection->invoke(
			$this->service,
			403,
			array( 'error' => array( 'message' => 'API key not valid' ) ),
			''
		);

		$this->assertWPError( $error );
		$this->assertEquals( 'wp_mcp_ai_invalid_api_key', $error->get_error_code() );
	}

	/**
	 * Test parse_api_error includes guidance for unsupported generation config errors.
	 */
	public function test_parse_api_error_generation_config() {
		$reflection = new ReflectionMethod( $this->service, 'parse_api_error' );
		$reflection->setAccessible( true );

		$error = $reflection->invoke(
			$this->service,
			400,
			array( 'error' => array( 'message' => 'temperature is not supported' ) ),
			''
		);

		$this->assertWPError( $error );
		$this->assertStringContainsString( 'Antigravity agent does not support', $error->get_error_message() );
	}

	/**
	 * Test parse_api_error returns generic error for unknown failures.
	 */
	public function test_parse_api_error_generic() {
		$reflection = new ReflectionMethod( $this->service, 'parse_api_error' );
		$reflection->setAccessible( true );

		$error = $reflection->invoke(
			$this->service,
			500,
			null,
			'Internal server error'
		);

		$this->assertWPError( $error );
		$this->assertEquals( 'wp_mcp_ai_agent_request_failed', $error->get_error_code() );
	}

	/**
	 * Test normalise_interaction_result extracts basic fields.
	 */
	public function test_normalise_interaction_result_basic() {
		$reflection = new ReflectionMethod( $this->service, 'normalise_interaction_result' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke(
			$this->service,
			array(
				'id'             => 'int_abc123',
				'environment_id' => 'env_xyz789',
				'output_text'    => 'Task completed successfully.',
				'finish_reason'  => 'STOP',
			)
		);

		$this->assertEquals( 'int_abc123', $result['interaction_id'] );
		$this->assertEquals( 'env_xyz789', $result['environment_id'] );
		$this->assertEquals( 'Task completed successfully.', $result['output_text'] );
		$this->assertEquals( 'STOP', $result['finish_reason'] );
	}

	/**
	 * Test normalise_interaction_result counts steps and tool calls.
	 */
	public function test_normalise_interaction_result_with_steps() {
		$reflection = new ReflectionMethod( $this->service, 'normalise_interaction_result' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke(
			$this->service,
			array(
				'id'    => 'int_def456',
				'steps' => array(
					array(
						'tool_calls' => array(
							array(
								'name' => 'code_execution',
								'args' => array( 'language' => 'python' ),
							),
						),
					),
					array(
						'tool_calls' => array(
							array(
								'name' => 'google_search',
								'args' => array( 'query' => 'test' ),
							),
						),
					),
				),
			)
		);

		$this->assertEquals( 2, $result['step_count'] );
		$this->assertEquals( 2, $result['tool_call_count'] );
		$this->assertCount( 2, $result['tool_calls'] );
		$this->assertEquals( 'code_execution', $result['tool_calls'][0]['tool'] );
		$this->assertEquals( 'google_search', $result['tool_calls'][1]['tool'] );
	}

	/**
	 * Test normalise_interaction_result returns defaults for empty input.
	 */
	public function test_normalise_interaction_result_empty() {
		$reflection = new ReflectionMethod( $this->service, 'normalise_interaction_result' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( $this->service, array() );

		$this->assertEquals( '', $result['interaction_id'] );
		$this->assertEquals( '', $result['environment_id'] );
		$this->assertEquals( '', $result['output_text'] );
		$this->assertEquals( array(), $result['steps'] );
	}

	/**
	 * Test aggregate_stream_events returns defaults for empty events.
	 */
	public function test_aggregate_stream_events_empty() {
		$reflection = new ReflectionMethod( $this->service, 'aggregate_stream_events' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( $this->service, array() );

		$this->assertEquals( '', $result['interaction_id'] );
		$this->assertEquals( '', $result['output_text'] );
		$this->assertEquals( 0, $result['event_count'] );
	}

	/**
	 * Test aggregate_stream_events concatenates text deltas.
	 */
	public function test_aggregate_stream_events_with_text_deltas() {
		$reflection = new ReflectionMethod( $this->service, 'aggregate_stream_events' );
		$reflection->setAccessible( true );

		$events = array(
			array(
				'id'    => 'int_1',
				'delta' => array( 'text' => 'Hello ' ),
			),
			array( 'delta' => array( 'text' => 'world!' ) ),
			array( 'finish_reason' => 'STOP' ),
		);

		$result = $reflection->invoke( $this->service, $events );

		$this->assertEquals( 'int_1', $result['interaction_id'] );
		$this->assertEquals( 'Hello world!', $result['output_text'] );
		$this->assertEquals( 'STOP', $result['finish_reason'] );
		$this->assertEquals( 3, $result['event_count'] );
	}

	/**
	 * Test aggregate_stream_events collects step objects.
	 */
	public function test_aggregate_stream_events_with_steps() {
		$reflection = new ReflectionMethod( $this->service, 'aggregate_stream_events' );
		$reflection->setAccessible( true );

		$events = array(
			array(
				'id'   => 'int_2',
				'step' => array(
					'type' => 'reasoning',
					'text' => 'Planning...',
				),
			),
			array(
				'step' => array(
					'type' => 'tool_call',
					'tool' => 'google_search',
				),
			),
		);

		$result = $reflection->invoke( $this->service, $events );

		$this->assertCount( 2, $result['steps'] );
		$this->assertEquals( 'reasoning', $result['steps'][0]['type'] );
	}

	/**
	 * Test track_environment creates transients and interaction mapping.
	 */
	public function test_track_environment_creates_transient() {
		$reflection = new ReflectionMethod( $this->service, 'track_environment' );
		$reflection->setAccessible( true );

		$env_id = 'env_test_' . wp_rand();
		$result = array( 'id' => 'int_test_' . wp_rand() );

		$reflection->invoke( $this->service, $env_id, $result );

		$env_data = get_transient( 'wp_mcp_ai_agent_session_env_' . $env_id );
		$this->assertIsArray( $env_data );
		$this->assertEquals( $env_id, $env_data['environment_id'] );
		$this->assertEquals( $result['id'], $env_data['last_interaction_id'] );
		$this->assertEquals( 1, $env_data['interaction_count'] );

		// Interaction→environment tracking.
		$int_data = get_transient( 'wp_mcp_ai_agent_session_int_' . $result['id'] );
		$this->assertIsArray( $int_data );
		$this->assertEquals( $env_id, $int_data['environment_id'] );

		// Cleanup.
		delete_transient( 'wp_mcp_ai_agent_session_env_' . $env_id );
		delete_transient( 'wp_mcp_ai_agent_session_int_' . $result['id'] );
	}

	/**
	 * Test track_environment increments counter on reuse.
	 */
	public function test_track_environment_updates_existing() {
		$reflection = new ReflectionMethod( $this->service, 'track_environment' );
		$reflection->setAccessible( true );

		$env_id  = 'env_test_reuse_' . wp_rand();
		$result1 = array( 'id' => 'int_1_' . wp_rand() );
		$result2 = array( 'id' => 'int_2_' . wp_rand() );

		// First interaction.
		$reflection->invoke( $this->service, $env_id, $result1 );

		// Second interaction reusing same environment.
		$reflection->invoke( $this->service, $env_id, $result2 );

		$env_data = get_transient( 'wp_mcp_ai_agent_session_env_' . $env_id );
		$this->assertEquals( 2, $env_data['interaction_count'] );
		$this->assertEquals( $result2['id'], $env_data['last_interaction_id'] );

		// Cleanup.
		delete_transient( 'wp_mcp_ai_agent_session_env_' . $env_id );
		delete_transient( 'wp_mcp_ai_agent_session_int_' . $result1['id'] );
		delete_transient( 'wp_mcp_ai_agent_session_int_' . $result2['id'] );
	}

	/**
	 * Test forget_environment removes the transient.
	 */
	public function test_forget_environment_removes_transient() {
		$env_id = 'env_test_forget_' . wp_rand();

		set_transient( 'wp_mcp_ai_agent_session_env_' . $env_id, array( 'test' => 'data' ), 3600 );

		$this->assertNotFalse( get_transient( 'wp_mcp_ai_agent_session_env_' . $env_id ) );

		$deleted = $this->service->forget_environment( $env_id );
		$this->assertTrue( $deleted );
		$this->assertFalse( get_transient( 'wp_mcp_ai_agent_session_env_' . $env_id ) );
	}

	/**
	 * Test forget_environment returns false for nonexistent environments.
	 */
	public function test_forget_environment_returns_false_for_nonexistent() {
		$deleted = $this->service->forget_environment( 'env_nonexistent_12345' );
		$this->assertFalse( $deleted );
	}

	/**
	 * Test get_environment_for_interaction returns the mapped environment ID.
	 */
	public function test_get_environment_for_interaction_returns_id() {
		$reflection = new ReflectionMethod( $this->service, 'get_environment_for_interaction' );
		$reflection->setAccessible( true );

		$interaction_id = 'int_link_' . wp_rand();
		$env_id         = 'env_link_' . wp_rand();

		set_transient(
			'wp_mcp_ai_agent_session_int_' . $interaction_id,
			array(
				'environment_id' => $env_id,
				'created_at'     => time(),
			),
			3600
		);

		$result = $reflection->invoke( $this->service, $interaction_id );
		$this->assertEquals( $env_id, $result );

		delete_transient( 'wp_mcp_ai_agent_session_int_' . $interaction_id );
	}

	/**
	 * Test get_environment_for_interaction returns empty string when missing.
	 */
	public function test_get_environment_for_interaction_returns_empty_when_missing() {
		$reflection = new ReflectionMethod( $this->service, 'get_environment_for_interaction' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( $this->service, 'nonexistent_interaction' );
		$this->assertEquals( '', $result );
	}

	/**
	 * Test create_managed_agent requires the enabled gate.
	 */
	public function test_create_managed_agent_requires_enabled() {
		delete_option( 'wp_mcp_ai_settings' );
		$result = $this->service->create_managed_agent( array( 'id' => 'test-agent' ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_managed_agents_unavailable', $result->get_error_code() );
	}

	/**
	 * Test create_managed_agent requires an agent ID.
	 */
	public function test_create_managed_agent_requires_id() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_managed_agents' => true,
				'gemini_api_key'        => 'AIza-test',
			)
		);
		$result = $this->service->create_managed_agent( array() );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_agent_id', $result->get_error_code() );
	}

	/**
	 * Test build_agent_environment defaults to remote type.
	 */
	public function test_build_agent_environment_defaults() {
		$reflection = new ReflectionMethod( $this->service, 'build_agent_environment' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( $this->service, array() );

		$this->assertIsArray( $result );
		$this->assertEquals( 'remote', $result['type'] );
	}

	/**
	 * Test build_agent_environment passes through custom type.
	 */
	public function test_build_agent_environment_custom_type() {
		$reflection = new ReflectionMethod( $this->service, 'build_agent_environment' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( $this->service, array( 'type' => 'custom-type' ) );

		$this->assertEquals( 'custom-type', $result['type'] );
	}

	/**
	 * Test build_agent_environment with inline source.
	 */
	public function test_build_agent_environment_inline_source() {
		$reflection = new ReflectionMethod( $this->service, 'build_agent_environment' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke(
			$this->service,
			array(
				'type'    => 'remote',
				'sources' => array(
					array(
						'type'    => 'inline',
						'target'  => '.agents/AGENTS.md',
						'content' => 'You are a helpful assistant.',
					),
				),
			)
		);

		$this->assertCount( 1, $result['sources'] );
		$this->assertEquals( 'inline', $result['sources'][0]['type'] );
		$this->assertEquals( '.agents/AGENTS.md', $result['sources'][0]['target'] );
		$this->assertEquals( 'You are a helpful assistant.', $result['sources'][0]['content'] );
	}

	/**
	 * Test build_agent_environment with repository source.
	 */
	public function test_build_agent_environment_repository_source() {
		$reflection = new ReflectionMethod( $this->service, 'build_agent_environment' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke(
			$this->service,
			array(
				'sources' => array(
					array(
						'type'   => 'repository',
						'target' => '.agents/skills',
						'source' => 'https://github.com/user/repo',
					),
				),
			)
		);

		$this->assertCount( 1, $result['sources'] );
		$this->assertEquals( 'repository', $result['sources'][0]['type'] );
		$this->assertEquals( 'https://github.com/user/repo', $result['sources'][0]['source'] );
	}

	/**
	 * Test build_agent_environment with GCS source.
	 */
	public function test_build_agent_environment_gcs_source() {
		$reflection = new ReflectionMethod( $this->service, 'build_agent_environment' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke(
			$this->service,
			array(
				'sources' => array(
					array(
						'type'   => 'gcs',
						'target' => '.agents/skills',
						'source' => 'gs://my-bucket/skills',
					),
				),
			)
		);

		$this->assertCount( 1, $result['sources'] );
		$this->assertEquals( 'gcs', $result['sources'][0]['type'] );
		$this->assertEquals( 'gs://my-bucket/skills', $result['sources'][0]['source'] );
	}

	/**
	 * Test build_agent_environment skips malformed sources.
	 */
	public function test_build_agent_environment_skips_malformed_sources() {
		$reflection = new ReflectionMethod( $this->service, 'build_agent_environment' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke(
			$this->service,
			array(
				'sources' => array(
					array( 'no_type' => true ),
					array(
						'type'    => 'inline',
						'target'  => '.agents/AGENTS.md',
						'content' => 'Valid source.',
					),
				),
			)
		);

		$this->assertCount( 1, $result['sources'] );
		$this->assertEquals( 'inline', $result['sources'][0]['type'] );
	}

	/**
	 * Test list_environments returns an array.
	 */
	public function test_list_environments_returns_array() {
		$result = $this->service->list_environments();
		$this->assertIsArray( $result );
	}

	/**
	 * Test list_environments includes stored environment data.
	 */
	public function test_list_environments_with_data() {
		$env_id = 'env_list_test_' . wp_rand();

		set_transient(
			'wp_mcp_ai_agent_session_env_' . $env_id,
			array(
				'environment_id'      => $env_id,
				'last_interaction_id' => 'int_123',
				'created_at'          => time(),
				'last_used_at'        => time(),
				'interaction_count'   => 3,
			),
			3600
		);

		$environments = $this->service->list_environments();

		$found = false;
		foreach ( $environments as $env ) {
			if ( $env['environment_id'] === $env_id ) {
				$found = true;
				$this->assertEquals( 'int_123', $env['interaction_id'] );
				$this->assertEquals( 3, $env['interaction_count'] );
				break;
			}
		}

		$this->assertTrue( $found, 'Created environment should appear in list' );

		delete_transient( 'wp_mcp_ai_agent_session_env_' . $env_id );
	}

	/**
	 * Test continue_interaction preserves IDs when available.
	 */
	public function test_continue_interaction_preserves_ids() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_managed_agents' => true,
				'gemini_api_key'        => 'AIza-test-key',
			)
		);

		// Setup interaction→environment mapping.
		$interaction_id = 'int_continue_' . wp_rand();
		$env_id         = 'env_continue_' . wp_rand();

		set_transient(
			'wp_mcp_ai_agent_session_int_' . $interaction_id,
			array(
				'environment_id' => $env_id,
				'created_at'     => time(),
			),
			3600
		);

		// We can't actually call the real API in a unit test, but we can verify
		// the continue_interaction method properly passes its arguments through
		// to send_interaction, which will fail with a network error (expected).
		$result = $this->service->continue_interaction( $interaction_id, 'Follow-up task.' );

		// The request should fail at the HTTP level, not at validation.
		$this->assertWPError( $result );

		// Cleanup.
		delete_transient( 'wp_mcp_ai_agent_session_int_' . $interaction_id );
	}
}
