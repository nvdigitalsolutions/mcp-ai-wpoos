<?php
/**
 * Test DeepSeek V4 Orchestration Implementation
 *
 * Validates that all DeepSeek V4 orchestration components are
 * properly implemented and functional.
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for DeepSeek V4 orchestration validation
 *
 * @since 1.9.0
 */
class Test_DeepSeek_V4_Orchestration_Validation extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the orchestration seeder class is loaded — it is not part of
		// the regular plugin bootstrap and must be required explicitly.
		if ( ! class_exists( 'WP_MCP_AI_Profession_Orchestration_Seeder' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-orchestration-seeder.php';
		}
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test that WP_MCP_AI_Tool_Registry uses correct method name.
	 *
	 * Verifies the fatal error fix.
	 */
	public function test_tool_registry_method_exists() {
		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Tool_Registry', 'get_instance' ),
			'WP_MCP_AI_Tool_Registry::get_instance() method should exist'
		);

		// Verify it returns an instance.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->assertInstanceOf(
			'WP_MCP_AI_Tool_Registry',
			$registry,
			'get_instance() should return WP_MCP_AI_Tool_Registry instance'
		);
	}

	/**
	 * Test that executor agent role class exists and has required methods.
	 */
	public function test_executor_agent_class_exists() {
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Agent_Role_Executor' ),
			'WP_MCP_AI_Agent_Role_Executor class should exist'
		);

		$executor = new WP_MCP_AI_Agent_Role_Executor();

		// Verify key methods exist.
		$this->assertTrue(
			method_exists( $executor, 'execute_role_task' ),
			'Executor should have execute_role_task() method'
		);
		$this->assertTrue(
			method_exists( $executor, 'execute_tool_with_context' ),
			'Executor should have execute_tool_with_context() method'
		);
	}

	/**
	 * Test that executor agent actually has tool registry instance.
	 */
	public function test_executor_has_tool_registry() {
		$executor = new WP_MCP_AI_Agent_Role_Executor();

		// Use reflection to access protected property.
		$reflection = new ReflectionClass( $executor );
		$property   = $reflection->getProperty( 'tool_registry' );
		$property->setAccessible( true );
		$tool_registry = $property->getValue( $executor );

		$this->assertInstanceOf(
			'WP_MCP_AI_Tool_Registry',
			$tool_registry,
			'Executor should have WP_MCP_AI_Tool_Registry instance'
		);
	}

	/**
	 * Test that team orchestrator class exists and has required methods.
	 */
	public function test_team_orchestrator_class_exists() {
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Agent_Team_Orchestrator' ),
			'WP_MCP_AI_Agent_Team_Orchestrator class should exist'
		);

		$orchestrator = new WP_MCP_AI_Agent_Team_Orchestrator();

		// Verify key methods exist.
		$this->assertTrue(
			method_exists( $orchestrator, 'compose_team' ),
			'Orchestrator should have compose_team() method'
		);
		$this->assertTrue(
			method_exists( $orchestrator, 'execute_team_workflow' ),
			'Orchestrator should have execute_team_workflow() method'
		);
	}

	/**
	 * Test that profession orchestration seeder exists and is functional.
	 */
	public function test_profession_orchestration_seeder_exists() {
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Profession_Orchestration_Seeder' ),
			'WP_MCP_AI_Profession_Orchestration_Seeder class should exist'
		);

		$seeder = new WP_MCP_AI_Profession_Orchestration_Seeder();

		// Verify key methods exist.
		$this->assertTrue(
			method_exists( $seeder, 'seed_all' ),
			'Seeder should have seed_all() method'
		);
		$this->assertTrue(
			method_exists( $seeder, 'seed_agent_roles' ),
			'Seeder should have seed_agent_roles() method'
		);
		$this->assertTrue(
			method_exists( $seeder, 'seed_task_patterns' ),
			'Seeder should have seed_task_patterns() method'
		);
	}

	/**
	 * Test that profession CPT has orchestration meta fields defined.
	 */
	public function test_profession_cpt_orchestration_fields() {
		$this->assertTrue(
			defined( 'WP_MCP_AI_Profession_CPT::META_AGENT_ROLE' ),
			'Profession CPT should define META_AGENT_ROLE constant'
		);
		$this->assertTrue(
			defined( 'WP_MCP_AI_Profession_CPT::META_AGENT_SECONDARY_ROLES' ),
			'Profession CPT should define META_AGENT_SECONDARY_ROLES constant'
		);
		$this->assertTrue(
			defined( 'WP_MCP_AI_Profession_CPT::META_TASK_PATTERNS' ),
			'Profession CPT should define META_TASK_PATTERNS constant'
		);
		$this->assertTrue(
			defined( 'WP_MCP_AI_Profession_CPT::META_DECISION_CRITERIA' ),
			'Profession CPT should define META_DECISION_CRITERIA constant'
		);
		$this->assertTrue(
			defined( 'WP_MCP_AI_Profession_CPT::META_ORCHESTRATION_RULES' ),
			'Profession CPT should define META_ORCHESTRATION_RULES constant'
		);
	}

	/**
	 * Test that agent coordination tools are registered.
	 */
	public function test_agent_coordination_tools_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$this->assertTrue(
			$registry->is_tool_registered( 'create_agent_team' ),
			'create_agent_team tool should be registered'
		);
		$this->assertTrue(
			$registry->is_tool_registered( 'delegate_to_agent' ),
			'delegate_to_agent tool should be registered'
		);
		$this->assertTrue(
			$registry->is_tool_registered( 'aggregate_agent_results' ),
			'aggregate_agent_results tool should be registered'
		);
	}

	/**
	 * Test that profession service has orchestration methods.
	 */
	public function test_profession_service_orchestration_methods() {
		$this->assertTrue(
			function_exists( 'wp_mcp_ai_get_profession_service' ),
			'wp_mcp_ai_get_profession_service() function should exist'
		);

		$service = wp_mcp_ai_get_profession_service();

		$this->assertTrue(
			method_exists( $service, 'get_profession_for_agent_role' ),
			'Profession service should have get_profession_for_agent_role() method'
		);
		$this->assertTrue(
			method_exists( $service, 'get_professions_by_agent_role' ),
			'Profession service should have get_professions_by_agent_role() method'
		);
		$this->assertTrue(
			method_exists( $service, 'get_orchestration_config' ),
			'Profession service should have get_orchestration_config() method'
		);
		$this->assertTrue(
			method_exists( $service, 'update_orchestration_config' ),
			'Profession service should have update_orchestration_config() method'
		);
	}

	/**
	 * Test seeder agent role determination logic.
	 */
	public function test_seeder_agent_role_determination() {
		// Create test profession.
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Test QA Engineer',
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
			)
		);

		$this->assertGreaterThan( 0, $post_id );

		// Set category and expertise.
		update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_CATEGORY, 'technical' );
		update_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_EXPERTISE, wp_json_encode( array( 'quality assurance', 'testing' ) ) );

		// Run seeder.
		$seeder = new WP_MCP_AI_Profession_Orchestration_Seeder();
		$result = $seeder->seed_agent_roles();

		// Verify role was assigned.
		$assigned_role = get_post_meta( $post_id, WP_MCP_AI_Profession_CPT::META_AGENT_ROLE, true );
		$this->assertNotEmpty( $assigned_role, 'Agent role should be assigned' );
		$this->assertEquals( 'critic', $assigned_role, 'QA Engineer should be assigned critic role' );

		// Clean up.
		wp_delete_post( $post_id, true );
	}

	/**
	 * Test that WP-CLI commands are available.
	 */
	public function test_wp_cli_commands_available() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			$this->markTestSkipped( 'WP-CLI not available in this environment' );
			return;
		}

		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Profession_Orchestration_CLI' ),
			'WP_MCP_AI_Profession_Orchestration_CLI class should exist'
		);

		$cli = new WP_MCP_AI_Profession_Orchestration_CLI();

		$this->assertTrue(
			method_exists( $cli, 'seed_orchestration' ),
			'CLI should have seed_orchestration() method'
		);
		$this->assertTrue(
			method_exists( $cli, 'orchestration_stats' ),
			'CLI should have orchestration_stats() method'
		);
	}

	/**
	 * Test executor tool execution (integration test).
	 */
	public function test_executor_tool_execution_integration() {
		$executor = new WP_MCP_AI_Agent_Role_Executor();

		// Create a simple task.
		$task = array(
			'description' => 'Test research task',
			'type'        => 'research',
			'parameters'  => array(
				'query' => 'test query',
				'limit' => 5,
			),
		);

		$context = array(
			'assistant_id' => 1,
			'user_id'      => 1,
		);

		// Execute task.
		$result = $executor->execute_role_task( $task, $context );

		// Verify result structure.
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'status', $result, 'Result should have status' );
		$this->assertArrayHasKey( 'result', $result, 'Result should have result data' );

		// Verify status is either completed or failed (not a placeholder).
		$this->assertContains(
			$result['status'],
			array( 'completed', 'failed' ),
			'Status should be completed or failed, not a placeholder'
		);
	}

	/**
	 * Test orchestrator team composition.
	 */
	public function test_orchestrator_team_composition() {
		$orchestrator = new WP_MCP_AI_Agent_Team_Orchestrator();

		$task_requirements = array(
			'task_type' => 'research',
		);

		$team = $orchestrator->compose_team( $task_requirements );

		// Verify team structure.
		$this->assertIsArray( $team, 'Team should be an array' );

		if ( ! is_wp_error( $team ) ) {
			$this->assertArrayHasKey( 'team_id', $team, 'Team should have team_id' );
			$this->assertArrayHasKey( 'members', $team, 'Team should have members' );
			$this->assertArrayHasKey( 'workflow', $team, 'Team should have workflow' );
		}
	}
}
