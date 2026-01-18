<?php
/**
 * Test Agent Communication Service
 *
 * Tests for agent-to-agent communication and coordination.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for agent communication service
 */
class Test_Agent_Communication_Service extends WP_UnitTestCase {

	/**
	 * Communication service instance
	 *
	 * @var WP_MCP_AI_Agent_Communication_Service
	 */
	protected $service;

	/**
	 * Setup test environment
	 */
	public function setUp(): void {
		parent::setUp();
		$this->service = new WP_MCP_AI_Agent_Communication_Service();
	}

	/**
	 * Test service instantiation
	 */
	public function test_service_instantiation() {
		$this->assertInstanceOf( WP_MCP_AI_Agent_Communication_Service::class, $this->service );
	}

	/**
	 * Test successful task delegation
	 */
	public function test_successful_task_delegation() {
		// Create test agents.
		$planner_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Planner Agent',
				'post_status' => 'publish',
			)
		);

		$executor_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Executor Agent',
				'post_status' => 'publish',
			)
		);

		// Set planner role.
		wp_mcp_ai_set_assistant_role( $planner_id, 'planner' );

		// Delegate task.
		$task = array(
			'description' => 'Research market trends',
			'type'        => 'research',
		);

		$context = array(
			'user_id' => 1,
		);

		$result = $this->service->delegate_task( $planner_id, $executor_id, $task, $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'delegation_id', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertEquals( 'delegated', $result['status'] );
		$this->assertArrayHasKey( 'to_agent', $result );
		$this->assertEquals( $executor_id, $result['to_agent']['id'] );
	}

	/**
	 * Test delegation with invalid agent IDs
	 */
	public function test_delegation_with_invalid_agent_ids() {
		$result = $this->service->delegate_task( 0, 0, array( 'task' => 'test' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_agent_id', $result->get_error_code() );
	}

	/**
	 * Test delegation with non-existent agents
	 */
	public function test_delegation_with_nonexistent_agents() {
		$result = $this->service->delegate_task( 99999, 99998, array( 'task' => 'test' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertTrue(
			in_array(
				$result->get_error_code(),
				array( 'wp_mcp_ai_invalid_source_agent', 'wp_mcp_ai_invalid_target_agent' ),
				true
			)
		);
	}

	/**
	 * Test delegation from non-delegating role
	 */
	public function test_delegation_from_non_delegating_role() {
		// Create executor agent (cannot delegate).
		$executor_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Executor Agent',
				'post_status' => 'publish',
			)
		);

		$target_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Target Agent',
				'post_status' => 'publish',
			)
		);

		// Set executor role (cannot delegate).
		wp_mcp_ai_set_assistant_role( $executor_id, 'executor' );

		$result = $this->service->delegate_task(
			$executor_id,
			$target_id,
			array( 'description' => 'Test task' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_cannot_delegate', $result->get_error_code() );
	}

	/**
	 * Test result aggregation with consensus strategy
	 */
	public function test_result_aggregation_consensus() {
		$results = array(
			array( 'result' => 'Result from agent 1' ),
			array( 'result' => 'Result from agent 2' ),
			array( 'result' => 'Result from agent 3' ),
		);

		$aggregated = $this->service->aggregate_results( $results, 'consensus' );

		$this->assertIsArray( $aggregated );
		$this->assertArrayHasKey( 'aggregation_id', $aggregated );
		$this->assertArrayHasKey( 'strategy', $aggregated );
		$this->assertEquals( 'consensus', $aggregated['strategy'] );
		$this->assertEquals( 3, $aggregated['agent_count'] );
		$this->assertArrayHasKey( 'result', $aggregated );
	}

	/**
	 * Test result aggregation with weighted strategy
	 */
	public function test_result_aggregation_weighted() {
		$results = array(
			array(
				'result' => 'High priority result',
				'weight' => 2.0,
			),
			array(
				'result' => 'Low priority result',
				'weight' => 0.5,
			),
		);

		$aggregated = $this->service->aggregate_results( $results, 'weighted' );

		$this->assertIsArray( $aggregated );
		$this->assertEquals( 'weighted', $aggregated['strategy'] );
		$this->assertArrayHasKey( 'result', $aggregated );
		$this->assertEquals( 'weighted', $aggregated['result']['type'] );
	}

	/**
	 * Test result aggregation with hierarchical strategy
	 */
	public function test_result_aggregation_hierarchical() {
		$results = array(
			array(
				'result'   => 'Low priority',
				'priority' => 1,
			),
			array(
				'result'   => 'High priority',
				'priority' => 10,
			),
			array(
				'result'   => 'Medium priority',
				'priority' => 5,
			),
		);

		$aggregated = $this->service->aggregate_results( $results, 'hierarchical' );

		$this->assertIsArray( $aggregated );
		$this->assertEquals( 'hierarchical', $aggregated['strategy'] );
		$this->assertArrayHasKey( 'result', $aggregated );
		$this->assertEquals( 'hierarchical', $aggregated['result']['type'] );
		// High priority should be primary.
		$this->assertEquals( 'High priority', $aggregated['result']['primary_result'] );
	}

	/**
	 * Test result aggregation with first strategy
	 */
	public function test_result_aggregation_first() {
		$results = array(
			array( 'result' => 'First result' ),
			array( 'result' => 'Second result' ),
		);

		$aggregated = $this->service->aggregate_results( $results, 'first' );

		$this->assertIsArray( $aggregated );
		$this->assertEquals( 'first', $aggregated['strategy'] );
		$this->assertEquals( 'First result', $aggregated['result']['result'] );
	}

	/**
	 * Test result aggregation with best strategy
	 */
	public function test_result_aggregation_best() {
		$results = array(
			array(
				'result' => 'Low quality',
				'score'  => 0.3,
			),
			array(
				'result' => 'High quality',
				'score'  => 0.95,
			),
			array(
				'result' => 'Medium quality',
				'score'  => 0.6,
			),
		);

		$aggregated = $this->service->aggregate_results( $results, 'best' );

		$this->assertIsArray( $aggregated );
		$this->assertEquals( 'best', $aggregated['strategy'] );
		$this->assertEquals( 'High quality', $aggregated['result']['result'] );
		$this->assertEquals( 0.95, $aggregated['result']['best_score'] );
	}

	/**
	 * Test result aggregation with invalid input
	 */
	public function test_result_aggregation_invalid_input() {
		$result = $this->service->aggregate_results( array(), 'consensus' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_results', $result->get_error_code() );
	}

	/**
	 * Test context sharing
	 */
	public function test_context_sharing() {
		// Create test agents.
		$source_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		$target_id_1 = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		$target_id_2 = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		$context_items = array(
			'project_id'   => 123,
			'requirements' => 'High priority task',
		);

		$result = $this->service->share_context(
			$source_id,
			array( $target_id_1, $target_id_2 ),
			$context_items
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'shared_count', $result );
		$this->assertArrayHasKey( 'total_count', $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 2, $result['shared_count'] );
		$this->assertEquals( 2, $result['total_count'] );
	}

	/**
	 * Test context sharing with invalid source
	 */
	public function test_context_sharing_invalid_source() {
		$result = $this->service->share_context(
			0,
			array( 1, 2 ),
			array( 'data' => 'test' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_source_agent', $result->get_error_code() );
	}

	/**
	 * Test context sharing with invalid targets
	 */
	public function test_context_sharing_invalid_targets() {
		$source_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		$result = $this->service->share_context(
			$source_id,
			array(),
			array( 'data' => 'test' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_invalid_target_agents', $result->get_error_code() );
	}

	/**
	 * Test context sharing with empty context
	 */
	public function test_context_sharing_empty_context() {
		$source_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		$target_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
			)
		);

		$result = $this->service->share_context(
			$source_id,
			array( $target_id ),
			array()
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_empty_context', $result->get_error_code() );
	}
}
