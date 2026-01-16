<?php
/**
 * Test Agent Roles
 *
 * Tests for the multi-agent coordination framework and agent roles.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for agent roles
 */
class Test_Agent_Roles extends WP_UnitTestCase {

	/**
	 * Test agent roles are registered
	 */
	public function test_agent_roles_are_registered() {
		$roles = wp_mcp_ai_get_agent_roles();

		$this->assertIsArray( $roles );
		$this->assertNotEmpty( $roles );
		$this->assertArrayHasKey( 'planner', $roles );
		$this->assertArrayHasKey( 'executor', $roles );
		$this->assertArrayHasKey( 'critic', $roles );
	}

	/**
	 * Test planner role
	 */
	public function test_planner_role() {
		$planner = wp_mcp_ai_get_agent_role( 'planner' );

		$this->assertInstanceOf( WP_MCP_AI_Agent_Role_Interface::class, $planner );
		$this->assertEquals( 'planner', $planner->get_role_type() );
		$this->assertNotEmpty( $planner->get_role_name() );
		$this->assertNotEmpty( $planner->get_role_description() );
		$this->assertTrue( $planner->can_delegate() );
		
		$capabilities = $planner->get_capabilities();
		$this->assertIsArray( $capabilities );
		$this->assertContains( 'can-delegate', $capabilities );
		$this->assertContains( 'can-coordinate', $capabilities );
	}

	/**
	 * Test executor role
	 */
	public function test_executor_role() {
		$executor = wp_mcp_ai_get_agent_role( 'executor' );

		$this->assertInstanceOf( WP_MCP_AI_Agent_Role_Interface::class, $executor );
		$this->assertEquals( 'executor', $executor->get_role_type() );
		$this->assertNotEmpty( $executor->get_role_name() );
		$this->assertNotEmpty( $executor->get_role_description() );
		$this->assertFalse( $executor->can_delegate() );
		
		$capabilities = $executor->get_capabilities();
		$this->assertIsArray( $capabilities );
		$this->assertContains( 'requires-tools', $capabilities );
	}

	/**
	 * Test critic role
	 */
	public function test_critic_role() {
		$critic = wp_mcp_ai_get_agent_role( 'critic' );

		$this->assertInstanceOf( WP_MCP_AI_Agent_Role_Interface::class, $critic );
		$this->assertEquals( 'critic', $critic->get_role_type() );
		$this->assertNotEmpty( $critic->get_role_name() );
		$this->assertNotEmpty( $critic->get_role_description() );
		$this->assertFalse( $critic->can_delegate() );
		
		$capabilities = $critic->get_capabilities();
		$this->assertIsArray( $capabilities );
		$this->assertContains( 'can-validate', $capabilities );
	}

	/**
	 * Test planner task execution
	 */
	public function test_planner_task_execution() {
		$planner = wp_mcp_ai_get_agent_role( 'planner' );

		$task = array(
			'description' => 'Create a comprehensive market research report for a new product',
		);

		$context = array(
			'assistant_id' => 1,
			'user_id'      => 1,
		);

		$result = $planner->execute_role_task( $task, $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'task_id', $result );
		$this->assertArrayHasKey( 'subtasks', $result );
		$this->assertArrayHasKey( 'complexity', $result );
		$this->assertArrayHasKey( 'success_criteria', $result );
		$this->assertNotEmpty( $result['subtasks'] );
	}

	/**
	 * Test executor task execution
	 */
	public function test_executor_task_execution() {
		$executor = wp_mcp_ai_get_agent_role( 'executor' );

		$task = array(
			'id'          => 'task_123',
			'description' => 'Research market trends',
			'type'        => 'research',
		);

		$context = array(
			'assistant_id' => 1,
			'user_id'      => 1,
		);

		$result = $executor->execute_role_task( $task, $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'task_id', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'result', $result );
		$this->assertArrayHasKey( 'execution_time', $result );
		$this->assertEquals( 'completed', $result['status'] );
	}

	/**
	 * Test critic validation
	 */
	public function test_critic_validation() {
		$critic = wp_mcp_ai_get_agent_role( 'critic' );

		$task = array(
			'description'        => 'Validate research results',
			'result_to_validate' => array(
				'title'   => 'Market Research Report',
				'content' => 'Detailed market analysis goes here...',
			),
			'criteria'           => array(
				'required_fields' => array( 'title', 'content' ),
			),
		);

		$context = array(
			'assistant_id' => 1,
			'user_id'      => 1,
		);

		$result = $critic->execute_role_task( $task, $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'validation_id', $result );
		$this->assertArrayHasKey( 'passes', $result );
		$this->assertArrayHasKey( 'overall_score', $result );
		$this->assertArrayHasKey( 'checks', $result );
		$this->assertArrayHasKey( 'feedback', $result );
		$this->assertIsBool( $result['passes'] );
		$this->assertIsFloat( $result['overall_score'] );
	}

	/**
	 * Test setting assistant role
	 */
	public function test_set_assistant_role() {
		// Create a test assistant.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Planner Assistant',
				'post_status' => 'publish',
			)
		);

		// Set planner role.
		$result = wp_mcp_ai_set_assistant_role( $assistant_id, 'planner' );
		$this->assertTrue( $result );

		// Verify role is set.
		$role_type = get_post_meta( $assistant_id, '_wp_mcp_ai_agent_role', true );
		$this->assertEquals( 'planner', $role_type );

		// Get role for assistant.
		$role = wp_mcp_ai_get_assistant_role( $assistant_id );
		$this->assertInstanceOf( WP_MCP_AI_Agent_Role_Interface::class, $role );
		$this->assertEquals( 'planner', $role->get_role_type() );
	}

	/**
	 * Test invalid role
	 */
	public function test_invalid_role() {
		$role = wp_mcp_ai_get_agent_role( 'nonexistent' );
		$this->assertNull( $role );

		// Try to set invalid role.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		$result = wp_mcp_ai_set_assistant_role( $assistant_id, 'nonexistent' );
		$this->assertFalse( $result );
	}

	/**
	 * Test recommended tools
	 */
	public function test_recommended_tools() {
		$planner = wp_mcp_ai_get_agent_role( 'planner' );
		$tools   = $planner->get_recommended_tools();

		$this->assertIsArray( $tools );
		$this->assertNotEmpty( $tools );
		$this->assertContains( 'create_agent_team', $tools );
		$this->assertContains( 'delegate_to_agent', $tools );
	}

	/**
	 * Test system prompt additions
	 */
	public function test_system_prompt_additions() {
		$planner = wp_mcp_ai_get_agent_role( 'planner' );
		$prompt  = $planner->get_system_prompt_additions();

		$this->assertIsString( $prompt );
		$this->assertNotEmpty( $prompt );
		$this->assertStringContainsString( 'Planner agent', $prompt );
	}

	/**
	 * Test task validation
	 */
	public function test_task_validation_error() {
		$planner = wp_mcp_ai_get_agent_role( 'planner' );

		// Missing description.
		$task = array();

		$context = array(
			'assistant_id' => 1,
		);

		$result = $planner->execute_role_task( $task, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_task', $result->get_error_code() );
	}

	/**
	 * Test context validation error
	 */
	public function test_context_validation_error() {
		$planner = wp_mcp_ai_get_agent_role( 'planner' );

		$task = array(
			'description' => 'Test task',
		);

		// Missing assistant_id.
		$context = array();

		$result = $planner->execute_role_task( $task, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_context', $result->get_error_code() );
	}
}
