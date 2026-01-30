<?php
/**
 * Tests for create_agent_team delegation guidance
 *
 * Verifies that the tool provides clear guidance about using agent_id for delegation.
 *
 * @package WP_MCP_AI
 */

/**
 * Test create_agent_team delegation guidance
 */
class Test_Create_Agent_Team_Delegation_Guidance extends WP_UnitTestCase {

	/**
	 * Test that create_agent_team response includes delegation_examples
	 *
	 * Verifies that the tool response includes a delegation_examples array
	 * with the correct agent_id values.
	 */
	public function test_create_team_includes_delegation_examples() {
		// Get the tool instance.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Agent_Team' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Create_Agent_Team class not available.' );
		}

		$tool = new WP_MCP_AI_Tool_Create_Agent_Team();

		// Execute the tool.
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

		$result = $tool->execute( $arguments, $context );

		// Verify success.
		$this->assertTrue( $result['success'], 'Tool should succeed' );
		$this->assertArrayHasKey( 'delegation_examples', $result, 'Result should include delegation_examples' );
		$this->assertIsArray( $result['delegation_examples'], 'delegation_examples should be an array' );
		$this->assertNotEmpty( $result['delegation_examples'], 'delegation_examples should not be empty' );

		// Verify delegation examples contain agent_id references.
		foreach ( $result['delegation_examples'] as $example ) {
			$this->assertStringContainsString( 'agent_id', $example, 'Each example should mention agent_id' );
		}

		// Verify the delegation examples match the team members.
		$this->assertCount(
			count( $result['team']['members'] ),
			$result['delegation_examples'],
			'Should have one delegation example per team member'
		);

		// Verify each example references an actual agent_id from the team.
		foreach ( $result['team']['members'] as $member ) {
			$found = false;
			foreach ( $result['delegation_examples'] as $example ) {
				if ( strpos( $example, $member['agent_id'] ) !== false ) {
					$found = true;
					break;
				}
			}
			$this->assertTrue(
				$found,
				sprintf( 'Delegation examples should reference agent_id: %s', $member['agent_id'] )
			);
		}
	}

	/**
	 * Test that next_steps includes clear guidance about using agent_id
	 *
	 * Verifies that the next_steps explicitly warn not to use profession names.
	 */
	public function test_next_steps_warns_about_agent_id() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Agent_Team' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Create_Agent_Team class not available.' );
		}

		$tool = new WP_MCP_AI_Tool_Create_Agent_Team();

		$arguments = array(
			'task_type'    => 'content',
			'requirements' => array(
				'expertise_needed' => array( 'content writing' ),
			),
		);

		$context = array(
			'assistant_id' => 1,
			'user_id'      => 1,
		);

		$result = $tool->execute( $arguments, $context );

		$this->assertTrue( $result['success'], 'Tool should succeed' );
		$this->assertArrayHasKey( 'next_steps', $result );
		$this->assertIsArray( $result['next_steps'] );

		// Find the warning about using agent_id.
		$has_agent_id_warning = false;
		foreach ( $result['next_steps'] as $step ) {
			if ( stripos( $step, 'agent_id' ) !== false && stripos( $step, 'profession' ) !== false ) {
				$has_agent_id_warning = true;
				break;
			}
		}

		$this->assertTrue(
			$has_agent_id_warning,
			'next_steps should include a warning about using agent_id (not profession)'
		);
	}

	/**
	 * Test delegate_to_agent parameter description is clear
	 *
	 * Verifies that the agent_id parameter description warns against using profession names.
	 */
	public function test_delegate_to_agent_parameter_description() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Delegate_To_Agent' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Delegate_To_Agent class not available.' );
		}

		$tool   = new WP_MCP_AI_Tool_Delegate_To_Agent();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'agent_id', $schema['properties'] );
		$this->assertArrayHasKey( 'description', $schema['properties']['agent_id'] );

		$description = $schema['properties']['agent_id']['description'];

		// Verify it warns against using profession names.
		$this->assertStringContainsString(
			'NOT',
			$description,
			'Description should explicitly warn NOT to use profession names'
		);

		$this->assertStringContainsString(
			'profession',
			$description,
			'Description should mention profession in the warning'
		);

		// Verify it references create_agent_team response.
		$this->assertStringContainsString(
			'create_agent_team',
			$description,
			'Description should reference create_agent_team response'
		);
	}

	/**
	 * Test that team is saved as workflow for dashboard tracking
	 *
	 * Verifies that when a team is created, it's also saved as a workflow
	 * so it appears on the orchestration dashboard.
	 */
	public function test_team_saved_as_workflow_for_dashboard() {
		global $wpdb;

		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Agent_Team' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Create_Agent_Team class not available.' );
		}

		$tool = new WP_MCP_AI_Tool_Create_Agent_Team();

		$arguments = array(
			'task_type'    => 'content',
			'requirements' => array(
				'expertise_needed' => array( 'content writing' ),
			),
		);

		$context = array(
			'assistant_id' => 1,
			'user_id'      => 1,
		);

		$result = $tool->execute( $arguments, $context );

		$this->assertTrue( $result['success'], 'Tool should succeed' );
		$this->assertArrayHasKey( 'team', $result );
		$this->assertArrayHasKey( 'team_id', $result['team'] );

		$team_id = $result['team']['team_id'];

		// Check that a workflow transient was created for this team.
		$workflow_transient_name = '_transient_wp_mcp_ai_workflow_wf_' . $team_id;

		// Query the database to check if the workflow transient exists.
		$workflow_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
				$workflow_transient_name
			)
		);

		$this->assertGreaterThan(
			0,
			$workflow_exists,
			'A workflow transient should be created for the team so it appears on the orchestration dashboard'
		);

		// Verify the workflow data structure.
		$workflow_data = get_transient( 'wp_mcp_ai_workflow_wf_' . $team_id );
		$this->assertIsArray( $workflow_data, 'Workflow data should be an array' );
		$this->assertArrayHasKey( 'workflow_id', $workflow_data );
		$this->assertArrayHasKey( 'team_id', $workflow_data );
		$this->assertArrayHasKey( 'state', $workflow_data );
		$this->assertArrayHasKey( 'tasks', $workflow_data );
		$this->assertEquals( $team_id, $workflow_data['team_id'] );
		$this->assertNotEmpty( $workflow_data['tasks'], 'Workflow should have tasks' );
	}
}
