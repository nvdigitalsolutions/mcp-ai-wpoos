<?php
/**
 * Tests for run_gemini_managed_agent tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test run_gemini_managed_agent tool functionality.
 *
 * @group external-http
 */
class Test_Tool_Run_Gemini_Managed_Agent extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Run_Gemini_Managed_Agent
	 */
	private $tool;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		if ( ! class_exists( 'WP_MCP_AI_Tool_Run_Gemini_Managed_Agent' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Run_Gemini_Managed_Agent class not available.' );
		}

		$this->tool = new WP_MCP_AI_Tool_Run_Gemini_Managed_Agent();
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'run_gemini_managed_agent', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
	}

	/**
	 * Required capability is edit_posts.
	 */
	public function test_required_capability() {
		$this->assertSame( 'edit_posts', $this->tool->get_required_capability() );
	}

	/**
	 * Parameter schema is valid and requires operation.
	 */
	public function test_parameter_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'operation', $schema['required'] );

		// Verify key properties exist.
		$this->assertArrayHasKey( 'session_id', $schema['properties'] );
		$this->assertArrayHasKey( 'task', $schema['properties'] );
		$this->assertArrayHasKey( 'system_prompt', $schema['properties'] );
		$this->assertArrayHasKey( 'tool_slugs', $schema['properties'] );
		$this->assertArrayHasKey( 'max_iterations', $schema['properties'] );
		$this->assertArrayHasKey( 'timeout', $schema['properties'] );
		$this->assertArrayHasKey( 'model', $schema['properties'] );
	}

	/**
	 * Invalid operation returns WP_Error.
	 */
	public function test_invalid_operation_returns_error() {
		$result = $this->tool->execute(
			array( 'operation' => 'invalid_op' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_operation', $result->get_error_code() );
	}

	/**
	 * Run operation without session_id returns error.
	 */
	public function test_run_without_session_id_returns_error() {
		$result = $this->tool->execute(
			array(
				'operation' => 'run',
				'task'      => 'Test task.',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_session', $result->get_error_code() );
	}

	/**
	 * Run operation without task returns error.
	 */
	public function test_run_without_task_returns_error() {
		$result = $this->tool->execute(
			array(
				'operation'  => 'run',
				'session_id' => 'test-session-123',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_task', $result->get_error_code() );
	}

	/**
	 * Status operation without session_id returns error.
	 */
	public function test_status_without_session_id_returns_error() {
		$result = $this->tool->execute(
			array( 'operation' => 'status' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_session', $result->get_error_code() );
	}

	/**
	 * Terminate operation without session_id returns error.
	 */
	public function test_terminate_without_session_id_returns_error() {
		$result = $this->tool->execute(
			array( 'operation' => 'terminate' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_session', $result->get_error_code() );
	}

	/**
	 * Create operation returns results (may error if service unavailable).
	 */
	public function test_create_operation_handles_service_unavailability() {
		$result = $this->tool->execute(
			array(
				'operation' => 'create',
				'task'      => 'Test task for managed agent.',
			),
			array( 'user_id' => $this->admin_id )
		);

		// Should either return a chat response or a WP_Error, never crash.
		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
	}

	/**
	 * List operation returns results without errors.
	 */
	public function test_list_operation_returns_results() {
		$result = $this->tool->execute(
			array( 'operation' => 'list' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
	}

	/**
	 * Capability flags include background-only.
	 */
	public function test_capability_flags() {
		if ( ! method_exists( $this->tool, 'get_capability_flags' ) ) {
			$this->markTestSkipped( 'get_capability_flags method not available.' );
		}

		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertArrayHasKey( 'background-only', $flags );
		$this->assertTrue( $flags['background-only'] );
	}

	/**
	 * Model requirements specify gemini provider.
	 */
	public function test_model_requirements() {
		if ( ! method_exists( $this->tool, 'get_model_requirements' ) ) {
			$this->markTestSkipped( 'get_model_requirements method not available.' );
		}

		$requirements = $this->tool->get_model_requirements();

		$this->assertIsArray( $requirements );
		$this->assertArrayHasKey( 'providers', $requirements );
		$this->assertContains( 'gemini', $requirements['providers'] );
	}
}
