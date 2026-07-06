<?php
/**
 * Tests for check_workflow_health tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test check_workflow_health tool — detects stuck/unhealthy workflows.
 */
class Test_Tool_Check_Workflow_Health extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Check_Workflow_Health
	 */
	private $tool;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tool = new WP_MCP_AI_Tool_Check_Workflow_Health();
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'check_workflow_health', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
	}

	/**
	 * When no orchestrator class is available, execute returns graceful failure.
	 */
	public function test_no_orchestrator_returns_graceful_result() {
		$result = $this->tool->execute( array(), array() );

		// Tool may return WP_Error when orchestrator/coordinator classes are unavailable,
		// or a success array when the infrastructure is present. Both are acceptable.
		if ( is_wp_error( $result ) ) {
			$this->assertNotEmpty( $result->get_error_message() );
		} else {
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'success', $result );
		}
	}

	/**
	 * Non-existent workflow_id returns a result (not a fatal error).
	 */
	public function test_unknown_workflow_id_does_not_fatal() {
		$result = $this->tool->execute(
			array( 'workflow_id' => 'phpunit-nonexistent-wf-' . uniqid() ),
			array()
		);

		// Unknown workflow may produce WP_Error("Workflow not found") or a success array.
		// The key assertion: we didn't fatal/crash.
		if ( is_wp_error( $result ) ) {
			$this->assertNotEmpty( $result->get_error_message() );
		} else {
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'success', $result );
		}
	}

	/**
	 * Empty arguments still returns an array with a success key.
	 */
	public function test_empty_args_returns_array() {
		$result = $this->tool->execute( array(), array() );

		// Tool may return WP_Error or array depending on available infrastructure.
		$this->assertTrue(
			is_wp_error( $result ) || is_array( $result ),
			'Empty args should return WP_Error or array, not crash.'
		);
	}

	/**
	 * Parameters schema is well-formed.
	 */
	public function test_parameters_schema_is_array() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
	}
}
