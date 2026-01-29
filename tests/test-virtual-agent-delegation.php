<?php
/**
 * Tests for virtual agent delegation functionality
 *
 * @package WP_MCP_AI
 */

/**
 * Test virtual agent delegation
 */
class Test_Virtual_Agent_Delegation extends WP_UnitTestCase {

	/**
	 * Test delegating to a virtual agent
	 *
	 * Verifies that delegate_to_agent accepts virtual agent IDs and can retrieve
	 * virtual agent data from team transients.
	 */
	public function test_delegate_to_virtual_agent() {
		// Create a team with virtual agents.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Agent_Team' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Create_Agent_Team class not available.' );
		}

		$team_tool = new WP_MCP_AI_Tool_Create_Agent_Team();

		$arguments = array(
			'task_type'    => 'content',
			'requirements' => array(
				'expertise_needed' => array( 'content writing' ),
				'quality_level'    => 'standard',
			),
		);

		$context = array(
			'assistant_id' => 1,
			'user_id'      => 1,
		);

		$team_result = $team_tool->execute( $arguments, $context );

		$this->assertTrue( $team_result['success'], 'Team creation should succeed' );
		$this->assertArrayHasKey( 'team', $team_result );
		$this->assertArrayHasKey( 'team_id', $team_result['team'] );
		$this->assertArrayHasKey( 'members', $team_result['team'] );
		$this->assertNotEmpty( $team_result['team']['members'], 'Team should have members' );

		// Get the first virtual agent from the team.
		$virtual_agent    = $team_result['team']['members'][0];
		$virtual_agent_id = $virtual_agent['agent_id'];
		$team_id          = $team_result['team']['team_id'];

		$this->assertStringStartsWith( 'virtual_', $virtual_agent_id, 'Agent ID should be a virtual agent ID' );

		// Now test delegation to this virtual agent.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Delegate_To_Agent' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Delegate_To_Agent class not available.' );
		}

		$delegate_tool = new WP_MCP_AI_Tool_Delegate_To_Agent();

		$delegate_args = array(
			'agent_id' => $virtual_agent_id,
			'task'     => 'Write a short blog post about AI',
			'context'  => array(
				'team_id' => $team_id,
			),
		);

		$delegate_result = $delegate_tool->execute( $delegate_args, $context );

		// The delegation should succeed now.
		$this->assertTrue( $delegate_result['success'], 'Delegation to virtual agent should succeed' );
		$this->assertArrayHasKey( 'delegation', $delegate_result );
		$this->assertArrayHasKey( 'delegation_id', $delegate_result['delegation'] );
		$this->assertEquals( $virtual_agent_id, $delegate_result['delegation']['agent_id'] );
	}

	/**
	 * Test delegating to virtual agent without team context
	 *
	 * Verifies that the system can find virtual agents even without explicit team_id.
	 */
	public function test_delegate_to_virtual_agent_without_team_context() {
		// Create a team with virtual agents.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Agent_Team' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Create_Agent_Team class not available.' );
		}

		$team_tool = new WP_MCP_AI_Tool_Create_Agent_Team();

		$arguments = array(
			'task_type'    => 'content',
			'requirements' => array(
				'expertise_needed' => array( 'content writing' ),
				'quality_level'    => 'standard',
			),
		);

		$context = array(
			'assistant_id' => 1,
			'user_id'      => 1,
		);

		$team_result = $team_tool->execute( $arguments, $context );
		$this->assertTrue( $team_result['success'] );

		// Get the first virtual agent.
		$virtual_agent    = $team_result['team']['members'][0];
		$virtual_agent_id = $virtual_agent['agent_id'];

		// Test delegation without team_id in context (system should find it).
		$delegate_tool = new WP_MCP_AI_Tool_Delegate_To_Agent();

		$delegate_args = array(
			'agent_id' => $virtual_agent_id,
			'task'     => 'Write a short blog post',
			'context'  => array(), // No team_id provided.
		);

		$delegate_result = $delegate_tool->execute( $delegate_args, $context );

		// Should succeed by searching transients.
		$this->assertTrue( $delegate_result['success'], 'Delegation should succeed by finding team in transients' );
		$this->assertEquals( $virtual_agent_id, $delegate_result['delegation']['agent_id'] );
	}

	/**
	 * Test delegating to non-existent virtual agent
	 *
	 * Verifies that delegation fails with appropriate error for invalid virtual agents.
	 */
	public function test_delegate_to_invalid_virtual_agent() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Delegate_To_Agent' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Delegate_To_Agent class not available.' );
		}

		$delegate_tool = new WP_MCP_AI_Tool_Delegate_To_Agent();

		$delegate_args = array(
			'agent_id' => 'virtual_nonexistent_12345',
			'task'     => 'Test task',
			'context'  => array(
				'team_id' => 'team_nonexistent',
			),
		);

		$context = array(
			'assistant_id' => 1,
			'user_id'      => 1,
		);

		$delegate_result = $delegate_tool->execute( $delegate_args, $context );

		// Should fail with appropriate error.
		$this->assertFalse( $delegate_result['success'], 'Delegation to invalid virtual agent should fail' );
		$this->assertArrayHasKey( 'message', $delegate_result );
		$this->assertStringContainsString( 'virtual agent', strtolower( $delegate_result['message'] ) );
	}

	/**
	 * Test parameter schema accepts both integer and string agent_id
	 *
	 * Verifies that the tool schema allows both types.
	 */
	public function test_delegate_tool_schema_accepts_string_agent_id() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Delegate_To_Agent' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Delegate_To_Agent class not available.' );
		}

		$delegate_tool = new WP_MCP_AI_Tool_Delegate_To_Agent();
		$schema        = $delegate_tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'agent_id', $schema['properties'] );

		// Should accept both integer and string.
		$agent_id_type = $schema['properties']['agent_id']['type'];
		$this->assertTrue(
			is_array( $agent_id_type ) && in_array( 'string', $agent_id_type, true ) && in_array( 'integer', $agent_id_type, true ),
			'agent_id should accept both integer and string types'
		);
	}
}
