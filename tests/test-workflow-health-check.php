<?php
/**
 * Tests for workflow health checking functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Test workflow health check functionality.
 */
class Test_Workflow_Health_Check extends WP_UnitTestCase {

	/**
	 * Test checking health of a workflow stuck in initialized state.
	 */
	public function test_check_stale_initialized_workflow() {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Team_Orchestrator' ) ) {
			$this->markTestSkipped( 'Agent Team Orchestrator not available' );
		}

		$orchestrator = new WP_MCP_AI_Agent_Team_Orchestrator();

		// Create a workflow directly in transient storage with initialized state.
		$workflow_id   = 'wf_test_' . uniqid();
		$created_time  = gmdate( 'Y-m-d H:i:s', time() - 400 ); // 6.67 minutes ago (past 5 min threshold).
		$workflow_data = array(
			'workflow_id'  => $workflow_id,
			'team_id'      => 'team_test_' . uniqid(),
			'task_type'    => 'test',
			'state'        => 'initialized',
			'tasks'        => array(),
			'members'      => array(),
			'created_at'   => $created_time,
			'updated_at'   => $created_time,
			'started_at'   => null,
			'completed_at' => null,
		);

		$transient_key = 'wp_mcp_ai_workflow_' . sanitize_key( $workflow_id );
		set_transient( $transient_key, $workflow_data, DAY_IN_SECONDS );

		// Check workflow health.
		$health = $orchestrator->check_workflow_health( $workflow_id );

		// Assert health check detects the stale workflow.
		$this->assertIsArray( $health, 'Health check should return an array' );
		$this->assertEquals( 'warning', $health['status'], 'Status should be warning for stale workflow' );
		$this->assertNotEmpty( $health['warnings'], 'Should have warnings' );
		$this->assertNotEmpty( $health['recommendations'], 'Should have recommendations' );
		$this->assertGreaterThan( 5, $health['age_minutes'], 'Age should be greater than 5 minutes' );

		// Clean up.
		delete_transient( $transient_key );
	}

	/**
	 * Test checking health of a recent initialized workflow.
	 */
	public function test_check_recent_initialized_workflow() {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Team_Orchestrator' ) ) {
			$this->markTestSkipped( 'Agent Team Orchestrator not available' );
		}

		$orchestrator = new WP_MCP_AI_Agent_Team_Orchestrator();

		// Create a recent workflow (2 minutes ago - within threshold).
		$workflow_id   = 'wf_test_' . uniqid();
		$created_time  = gmdate( 'Y-m-d H:i:s', time() - 120 ); // 2 minutes ago.
		$workflow_data = array(
			'workflow_id'  => $workflow_id,
			'team_id'      => 'team_test_' . uniqid(),
			'task_type'    => 'test',
			'state'        => 'initialized',
			'tasks'        => array(),
			'members'      => array(),
			'created_at'   => $created_time,
			'updated_at'   => $created_time,
			'started_at'   => null,
			'completed_at' => null,
		);

		$transient_key = 'wp_mcp_ai_workflow_' . sanitize_key( $workflow_id );
		set_transient( $transient_key, $workflow_data, DAY_IN_SECONDS );

		// Check workflow health.
		$health = $orchestrator->check_workflow_health( $workflow_id );

		// Assert health check shows pending but not warning.
		$this->assertIsArray( $health, 'Health check should return an array' );
		$this->assertEquals( 'pending', $health['status'], 'Status should be pending for recent workflow' );
		$this->assertLessThan( 5, $health['age_minutes'], 'Age should be less than 5 minutes' );

		// Clean up.
		delete_transient( $transient_key );
	}

	/**
	 * Test checking health of all workflows.
	 */
	public function test_check_all_workflows_health() {
		if ( ! class_exists( 'WP_MCP_AI_Enhanced_Workflow_Coordinator' ) ) {
			$this->markTestSkipped( 'Enhanced Workflow Coordinator not available' );
		}

		$coordinator = new WP_MCP_AI_Enhanced_Workflow_Coordinator();

		// Create multiple workflows with different states.
		$workflows = array();

		// Stale workflow.
		$stale_id = 'wf_stale_' . uniqid();
		$workflows[] = array(
			'workflow_id'  => $stale_id,
			'state'        => 'initialized',
			'created_at'   => gmdate( 'Y-m-d H:i:s', time() - 400 ),
		);

		// Recent workflow.
		$recent_id = 'wf_recent_' . uniqid();
		$workflows[] = array(
			'workflow_id'  => $recent_id,
			'state'        => 'initialized',
			'created_at'   => gmdate( 'Y-m-d H:i:s', time() - 60 ),
		);

		// Running workflow.
		$running_id = 'wf_running_' . uniqid();
		$workflows[] = array(
			'workflow_id'  => $running_id,
			'state'        => 'running',
			'created_at'   => gmdate( 'Y-m-d H:i:s', time() - 30 ),
		);

		// Store workflows as transients.
		foreach ( $workflows as $workflow ) {
			$transient_key = 'wp_mcp_ai_workflow_' . sanitize_key( $workflow['workflow_id'] );
			set_transient( $transient_key, $workflow, DAY_IN_SECONDS );
		}

		// Check overall health.
		$health = $coordinator->get_workflows_health();

		// Assert health check detects issues.
		$this->assertIsArray( $health, 'Health check should return an array' );
		$this->assertArrayHasKey( 'total', $health, 'Should have total count' );
		$this->assertArrayHasKey( 'by_state', $health, 'Should have state breakdown' );
		$this->assertArrayHasKey( 'stale_initialized', $health, 'Should have stale workflows list' );
		$this->assertNotEmpty( $health['stale_initialized'], 'Should detect stale workflows' );
		$this->assertEquals( 'warning', $health['status'], 'Overall status should be warning' );

		// Clean up.
		foreach ( $workflows as $workflow ) {
			$transient_key = 'wp_mcp_ai_workflow_' . sanitize_key( $workflow['workflow_id'] );
			delete_transient( $transient_key );
		}
	}

	/**
	 * Test the check_workflow_health tool.
	 */
	public function test_check_workflow_health_tool() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Check_Workflow_Health' ) ) {
			$this->markTestSkipped( 'Check Workflow Health tool not available' );
		}

		$tool = new WP_MCP_AI_Tool_Check_Workflow_Health();

		// Test tool metadata.
		$this->assertEquals( 'check_workflow_health', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );

		// Test tool parameters schema.
		$schema = $tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );

		// Test capability flags.
		$flags = $tool->get_capability_flags();
		$this->assertIsArray( $flags );
		$this->assertTrue( $flags['safe'], 'Tool should be safe (read-only)' );
		$this->assertTrue( $flags['read-only'], 'Tool should be read-only' );
		$this->assertFalse( $flags['modifies-wp'], 'Tool should not modify WordPress' );
	}

	/**
	 * Test executing the check_workflow_health tool.
	 */
	public function test_execute_check_workflow_health_tool() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Check_Workflow_Health' ) ) {
			$this->markTestSkipped( 'Check Workflow Health tool not available' );
		}

		if ( ! class_exists( 'WP_MCP_AI_Enhanced_Workflow_Coordinator' ) ) {
			$this->markTestSkipped( 'Enhanced Workflow Coordinator not available' );
		}

		$tool = new WP_MCP_AI_Tool_Check_Workflow_Health();

		// Create a stale workflow.
		$workflow_id   = 'wf_test_' . uniqid();
		$workflow_data = array(
			'workflow_id'  => $workflow_id,
			'team_id'      => 'team_test_' . uniqid(),
			'state'        => 'initialized',
			'created_at'   => gmdate( 'Y-m-d H:i:s', time() - 400 ),
			'tasks'        => array(),
		);

		$transient_key = 'wp_mcp_ai_workflow_' . sanitize_key( $workflow_id );
		set_transient( $transient_key, $workflow_data, DAY_IN_SECONDS );

		// Execute tool to check all workflows.
		$result = $tool->execute( array(), array() );

		// Assert tool execution returns expected data.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'], 'Tool execution should succeed' );
		$this->assertArrayHasKey( 'health', $result );

		// Clean up.
		delete_transient( $transient_key );
	}
}
