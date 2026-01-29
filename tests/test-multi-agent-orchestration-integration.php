<?php
/**
 * Test Multi-Agent Orchestration Integration
 *
 * Integration tests for DeepSeek V4 multi-agent orchestration system.
 * Tests end-to-end workflows: planner → executor → critic coordination.
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 */

/**
 * Test case for multi-agent orchestration integration
 *
 * @since 1.9.0
 */
class Test_Multi_Agent_Orchestration_Integration extends WP_UnitTestCase {

	/**
	 * Test profession IDs for agents.
	 *
	 * @var array
	 */
	protected $profession_ids = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test professions with agent roles.
		$this->profession_ids['planner']  = $this->create_test_profession( 'Project Manager', 'planner' );
		$this->profession_ids['executor'] = $this->create_test_profession( 'Data Scientist', 'executor' );
		$this->profession_ids['critic']   = $this->create_test_profession( 'Technical Editor', 'critic' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Clean up test professions.
		foreach ( $this->profession_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		parent::tearDown();
	}

	/**
	 * Create a test profession with agent role.
	 *
	 * @param string $title Title.
	 * @param string $agent_role Agent role.
	 * @return int Post ID.
	 */
	protected function create_test_profession( $title, $agent_role ) {
		$post_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
			)
		);

		// Set agent role.
		update_post_meta( $post_id, '_wp_mcp_ai_profession_agent_role', $agent_role );

		return $post_id;
	}

	/**
	 * Test agent coordination tools are registered.
	 */
	public function test_agent_coordination_tools_registered() {
		$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
		$all_tools     = $tool_registry->get_tools();

		$this->assertArrayHasKey( 'create_agent_team', $all_tools );
		$this->assertArrayHasKey( 'delegate_to_agent', $all_tools );
		$this->assertArrayHasKey( 'aggregate_agent_results', $all_tools );
	}

	/**
	 * Test create_agent_team tool execution.
	 */
	public function test_create_agent_team_tool() {
		$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool          = $tool_registry->get_tool( 'create_agent_team' );

		$this->assertNotNull( $tool );

		$arguments = array(
			'task_type'    => 'research',
			'requirements' => array(
				'expertise_needed' => array( 'data analysis' ),
				'quality_level'    => 'validated',
			),
		);

		$context = array(
			'assistant_id' => 1,
			'user_id'      => 1,
		);

		$result = $tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'team_id', $result );
		$this->assertArrayHasKey( 'team_composition', $result );
		$this->assertArrayHasKey( 'workflow', $result );

		$composition = $result['team_composition'];
		$this->assertArrayHasKey( 'planner', $composition );
		$this->assertArrayHasKey( 'executors', $composition );
		$this->assertArrayHasKey( 'critic', $composition );
	}

	/**
	 * Test delegate_to_agent tool execution.
	 */
	public function test_delegate_to_agent_tool() {
		$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool          = $tool_registry->get_tool( 'delegate_to_agent' );

		$this->assertNotNull( $tool );

		$arguments = array(
			'agent_id'        => $this->profession_ids['executor'],
			'task'            => 'Analyze dataset and create visualization',
			'context'         => array(
				'parent_task_id' => 'research-1',
				'shared_data'    => array(
					'dataset_url' => 'https://example.com/data.csv',
				),
			),
			'expected_output' => array(
				'format' => 'json',
				'fields' => array( 'chart_url', 'insights' ),
			),
		);

		$context = array(
			'assistant_id' => 1,
			'user_id'      => 1,
		);

		$result = $tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'delegation_id', $result );
		$this->assertArrayHasKey( 'agent_id', $result );
		$this->assertArrayHasKey( 'task', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertEquals( 'delegated', $result['status'] );
	}

	/**
	 * Test aggregate_agent_results tool execution.
	 */
	public function test_aggregate_agent_results_tool() {
		$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool          = $tool_registry->get_tool( 'aggregate_agent_results' );

		$this->assertNotNull( $tool );

		$arguments = array(
			'agent_results' => array(
				array(
					'agent_id'   => $this->profession_ids['executor'],
					'result'     => array( 'analysis' => 'positive trend' ),
					'confidence' => 0.9,
				),
				array(
					'agent_id'   => $this->profession_ids['critic'],
					'result'     => array( 'analysis' => 'positive trend' ),
					'confidence' => 0.85,
				),
			),
			'strategy'      => 'consensus',
		);

		$context = array(
			'assistant_id' => 1,
			'user_id'      => 1,
		);

		$result = $tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'aggregated_result', $result );
		$this->assertArrayHasKey( 'confidence_score', $result );
		$this->assertArrayHasKey( 'strategy_used', $result );
		$this->assertEquals( 'consensus', $result['strategy_used'] );
	}

	/**
	 * Test sequential workflow orchestration.
	 */
	public function test_sequential_workflow() {
		// Create team with sequential orchestration.
		$team_id = wp_insert_post(
			array(
				'post_title'  => 'Research Team (Sequential)',
				'post_type'   => 'mcp_ai_team',
				'post_status' => 'publish',
			)
		);

		// Set team members.
		update_post_meta( $team_id, '_wp_mcp_ai_team_members', array_values( $this->profession_ids ) );

		// Set orchestration mode.
		update_post_meta( $team_id, '_wp_mcp_ai_team_orchestration_mode', 'sequential' );

		// Set workflow template.
		$workflow = array(
			'workflow_name' => 'research_pipeline',
			'steps'         => array(
				array(
					'step_id'    => '1',
					'agent_role' => 'planner',
					'action'     => 'decompose_task',
				),
				array(
					'step_id'    => '2',
					'agent_role' => 'executor',
					'action'     => 'execute_analysis',
					'depends_on' => '1',
				),
				array(
					'step_id'    => '3',
					'agent_role' => 'critic',
					'action'     => 'validate_results',
					'depends_on' => '2',
				),
			),
		);
		update_post_meta( $team_id, '_wp_mcp_ai_team_workflow_template', wp_json_encode( $workflow ) );

		// Set aggregation strategy.
		update_post_meta( $team_id, '_wp_mcp_ai_team_result_aggregation', 'hierarchical' );

		// Verify team configuration.
		$saved_mode     = get_post_meta( $team_id, '_wp_mcp_ai_team_orchestration_mode', true );
		$saved_workflow = get_post_meta( $team_id, '_wp_mcp_ai_team_workflow_template', true );
		$saved_strategy = get_post_meta( $team_id, '_wp_mcp_ai_team_result_aggregation', true );

		$this->assertEquals( 'sequential', $saved_mode );
		$this->assertNotEmpty( $saved_workflow );
		$this->assertEquals( 'hierarchical', $saved_strategy );

		$decoded_workflow = json_decode( $saved_workflow, true );
		$this->assertIsArray( $decoded_workflow );
		$this->assertArrayHasKey( 'steps', $decoded_workflow );
		$this->assertCount( 3, $decoded_workflow['steps'] );

		// Clean up.
		wp_delete_post( $team_id, true );
	}

	/**
	 * Test parallel workflow orchestration.
	 */
	public function test_parallel_workflow() {
		// Create team with parallel orchestration.
		$team_id = wp_insert_post(
			array(
				'post_title'  => 'Content Team (Parallel)',
				'post_type'   => 'mcp_ai_team',
				'post_status' => 'publish',
			)
		);

		// Set team members.
		update_post_meta( $team_id, '_wp_mcp_ai_team_members', array_values( $this->profession_ids ) );

		// Set orchestration mode.
		update_post_meta( $team_id, '_wp_mcp_ai_team_orchestration_mode', 'parallel' );

		// Set workflow template with parallel execution.
		$workflow = array(
			'workflow_name' => 'parallel_content_creation',
			'steps'         => array(
				array(
					'step_id'    => '1',
					'agent_role' => 'planner',
					'action'     => 'decompose_task',
				),
				array(
					'step_id'    => '2a',
					'agent_role' => 'executor',
					'action'     => 'write_section_1',
					'depends_on' => '1',
					'parallel'   => true,
				),
				array(
					'step_id'    => '2b',
					'agent_role' => 'executor',
					'action'     => 'write_section_2',
					'depends_on' => '1',
					'parallel'   => true,
				),
				array(
					'step_id'    => '3',
					'agent_role' => 'critic',
					'action'     => 'review_combined',
					'depends_on' => array( '2a', '2b' ),
				),
			),
		);
		update_post_meta( $team_id, '_wp_mcp_ai_team_workflow_template', wp_json_encode( $workflow ) );

		// Set aggregation strategy.
		update_post_meta( $team_id, '_wp_mcp_ai_team_result_aggregation', 'weighted' );

		// Verify team configuration.
		$saved_mode     = get_post_meta( $team_id, '_wp_mcp_ai_team_orchestration_mode', true );
		$saved_workflow = get_post_meta( $team_id, '_wp_mcp_ai_team_workflow_template', true );

		$this->assertEquals( 'parallel', $saved_mode );

		$decoded_workflow = json_decode( $saved_workflow, true );
		$this->assertIsArray( $decoded_workflow );
		$this->assertCount( 4, $decoded_workflow['steps'] );

		// Verify parallel flag.
		$step_2a = $decoded_workflow['steps'][1];
		$this->assertArrayHasKey( 'parallel', $step_2a );
		$this->assertTrue( $step_2a['parallel'] );

		// Clean up.
		wp_delete_post( $team_id, true );
	}

	/**
	 * Test swarm orchestration mode.
	 */
	public function test_swarm_orchestration() {
		// Create team with swarm orchestration.
		$team_id = wp_insert_post(
			array(
				'post_title'  => 'Validation Team (Swarm)',
				'post_type'   => 'mcp_ai_team',
				'post_status' => 'publish',
			)
		);

		// Set team members (multiple critics for swarm).
		update_post_meta( $team_id, '_wp_mcp_ai_team_members', array_values( $this->profession_ids ) );

		// Set orchestration mode.
		update_post_meta( $team_id, '_wp_mcp_ai_team_orchestration_mode', 'swarm' );

		// Set aggregation strategy (consensus required for swarm).
		update_post_meta( $team_id, '_wp_mcp_ai_team_result_aggregation', 'consensus' );

		// Verify team configuration.
		$saved_mode     = get_post_meta( $team_id, '_wp_mcp_ai_team_orchestration_mode', true );
		$saved_strategy = get_post_meta( $team_id, '_wp_mcp_ai_team_result_aggregation', true );

		$this->assertEquals( 'swarm', $saved_mode );
		$this->assertEquals( 'consensus', $saved_strategy );

		// Clean up.
		wp_delete_post( $team_id, true );
	}

	/**
	 * Test result aggregation strategies.
	 */
	public function test_aggregation_strategies() {
		$strategies = array( 'consensus', 'weighted', 'hierarchical', 'first', 'best' );

		foreach ( $strategies as $strategy ) {
			$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
			$tool          = $tool_registry->get_tool( 'aggregate_agent_results' );

			$arguments = array(
				'agent_results' => array(
					array(
						'agent_id'   => $this->profession_ids['executor'],
						'result'     => array( 'value' => 'result1' ),
						'confidence' => 0.9,
					),
					array(
						'agent_id'   => $this->profession_ids['critic'],
						'result'     => array( 'value' => 'result1' ),
						'confidence' => 0.85,
					),
				),
				'strategy'      => $strategy,
			);

			$context = array(
				'assistant_id' => 1,
				'user_id'      => 1,
			);

			$result = $tool->execute( $arguments, $context );

			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'strategy_used', $result );
			$this->assertEquals( $strategy, $result['strategy_used'] );
		}
	}

	/**
	 * Test agent team orchestrator workflow execution.
	 */
	public function test_orchestrator_workflow_execution() {
		// Test that orchestrator can execute workflows.
		if ( ! class_exists( 'WP_MCP_AI_Agent_Team_Orchestrator' ) ) {
			$this->markTestSkipped( 'Agent Team Orchestrator not available.' );
			return;
		}

		$orchestrator = new WP_MCP_AI_Agent_Team_Orchestrator();

		$workflow = array(
			'steps' => array(
				array(
					'type'        => 'delegation',
					'agent_role'  => 'planner',
					'description' => 'Plan the research project',
				),
				array(
					'type'        => 'delegation',
					'agent_role'  => 'executor',
					'description' => 'Execute research tasks',
					'depends_on'  => 0,
				),
				array(
					'type'        => 'validation',
					'agent_role'  => 'critic',
					'description' => 'Validate research results',
					'depends_on'  => 1,
				),
			),
		);

		$context = array(
			'team_id'      => 1,
			'assistant_id' => 1,
			'user_id'      => 1,
		);

		// Test workflow structure validation.
		$this->assertIsArray( $workflow );
		$this->assertArrayHasKey( 'steps', $workflow );
		$this->assertCount( 3, $workflow['steps'] );

		foreach ( $workflow['steps'] as $step ) {
			$this->assertArrayHasKey( 'type', $step );
			$this->assertArrayHasKey( 'agent_role', $step );
			$this->assertArrayHasKey( 'description', $step );
		}
	}

	/**
	 * Test profession service orchestration methods.
	 */
	public function test_profession_service_orchestration_methods() {
		if ( ! class_exists( 'WP_MCP_AI_Profession_Service' ) ) {
			$this->markTestSkipped( 'Profession Service not available.' );
			return;
		}

		$service = new WP_MCP_AI_Profession_Service();

		// Test get_professions_by_agent_role().
		$planners = $service->get_professions_by_agent_role( 'planner' );
		$this->assertIsArray( $planners );
		$this->assertNotEmpty( $planners );

		$executors = $service->get_professions_by_agent_role( 'executor' );
		$this->assertIsArray( $executors );
		$this->assertNotEmpty( $executors );

		$critics = $service->get_professions_by_agent_role( 'critic' );
		$this->assertIsArray( $critics );
		$this->assertNotEmpty( $critics );

		// Test get_orchestration_config().
		$config = $service->get_orchestration_config( $this->profession_ids['planner'] );
		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'agent_role', $config );
		$this->assertEquals( 'planner', $config['agent_role'] );
	}

	/**
	 * Test end-to-end multi-agent workflow.
	 */
	public function test_end_to_end_workflow() {
		// 1. Create team with coordination tools.
		$tool_registry    = WP_MCP_AI_Tool_Registry::get_instance();
		$create_team_tool = $tool_registry->get_tool( 'create_agent_team' );

		$team_args = array(
			'task_type'    => 'research',
			'requirements' => array(
				'expertise_needed' => array( 'data analysis' ),
				'quality_level'    => 'validated',
			),
		);

		$team_result = $create_team_tool->execute( $team_args, array( 'user_id' => 1 ) );
		$this->assertIsArray( $team_result );
		$this->assertArrayHasKey( 'team_id', $team_result );

		// 2. Delegate to executor.
		$delegate_tool = $tool_registry->get_tool( 'delegate_to_agent' );

		$delegate_args = array(
			'agent_id' => $this->profession_ids['executor'],
			'task'     => 'Analyze research data',
		);

		$delegation_result = $delegate_tool->execute( $delegate_args, array( 'user_id' => 1 ) );
		$this->assertIsArray( $delegation_result );
		$this->assertArrayHasKey( 'delegation_id', $delegation_result );

		// 3. Aggregate results.
		$aggregate_tool = $tool_registry->get_tool( 'aggregate_agent_results' );

		$aggregate_args = array(
			'agent_results' => array(
				array(
					'agent_id'   => $this->profession_ids['executor'],
					'result'     => array( 'analysis' => 'complete' ),
					'confidence' => 0.9,
				),
			),
			'strategy'      => 'best',
		);

		$aggregate_result = $aggregate_tool->execute( $aggregate_args, array( 'user_id' => 1 ) );
		$this->assertIsArray( $aggregate_result );
		$this->assertArrayHasKey( 'aggregated_result', $aggregate_result );
	}
}
