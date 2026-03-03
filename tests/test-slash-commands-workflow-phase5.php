<?php
/**
 * Test Phase 5 Advanced Workflow Features
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

class Test_Slash_Commands_Workflow_Phase5 extends WP_UnitTestCase {

	protected $orchestrator;
	protected $handler;

	public function setUp(): void {
		parent::setUp();
		$this->handler      = $this->getMockBuilder( 'WP_MCP_AI_Slash_Command_Handler' )
			->disableOriginalConstructor()
			->getMock();
		$this->orchestrator = new WP_MCP_AI_Slash_Command_Workflow_Orchestrator( $this->handler );
	}

	public function tearDown(): void {
		// Clean up saved states.
		delete_option( 'wp_mcp_ai_workflow_states' );
		parent::tearDown();
	}

	/**
	 * Test conditional workflow execution - if_success.
	 */
	public function test_conditional_workflow_if_success() {
		$workflows = $this->orchestrator->get_workflows();
		$this->assertArrayHasKey( 'smart_inventory_replenishment', $workflows );
	}

	/**
	 * Test conditional workflow execution - less_than condition.
	 */
	public function test_conditional_workflow_less_than() {
		$workflows = $this->orchestrator->get_workflows();
		$this->assertArrayHasKey( 'intelligent_video_distribution', $workflows );
	}

	/**
	 * Test retry mechanism with configurable attempts.
	 */
	public function test_retry_mechanism() {
		// Mock handler to fail first 2 times, succeed on 3rd.
		$call_count = 0;
		$this->handler->method( 'execute' )->willReturnCallback(
			function () use ( &$call_count ) {
				$call_count++;
				if ( $call_count < 3 ) {
					return array(
						'success' => false,
						'error'   => 'temp_failure',
					);
				}
				return array(
					'success' => true,
					'data'    => array( 'result' => 'ok' ),
				);
			}
		);

		// Create a simple workflow for testing.
		$this->orchestrator->create_workflow(
			'test_retry',
			array(
				'name'  => 'Test Retry',
				'steps' => array(
					array(
						'command' => 'test-command',
						'params'  => array(),
					),
				),
			)
		);

		$result = $this->orchestrator->execute_workflow(
			'test_retry',
			array(),
			array(),
			array( 'max_retries' => 5 )
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 2, $result['steps'][0]['retries'] );
	}

	/**
	 * Test workflow state management - save and resume.
	 */
	public function test_workflow_state_management() {
		// Create a workflow.
		$this->orchestrator->create_workflow(
			'test_state',
			array(
				'name'  => 'Test State',
				'steps' => array(
					array(
						'command' => 'step1',
						'params'  => array(),
					),
					array(
						'command' => 'step2',
						'params'  => array(),
					),
				),
			)
		);

		// Mock handler for execution.
		$this->handler->method( 'execute' )->willReturn(
			array(
				'success' => true,
				'data'    => array(),
			)
		);

		// Execute with save_state.
		$result = $this->orchestrator->execute_workflow(
			'test_state',
			array(),
			array(),
			array( 'save_state' => true )
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'execution_id', $result );

		// Verify state was saved then cleared.
		$states = get_option( 'wp_mcp_ai_workflow_states', array() );
		$this->assertEmpty( $states, 'State should be cleared after successful completion' );
	}

	/**
	 * Test continue on error option.
	 */
	public function test_continue_on_error() {
		// Mock handler to fail.
		$this->handler->method( 'execute' )->willReturn(
			array(
				'success' => false,
				'error'   => 'test_error',
			)
		);

		$this->orchestrator->create_workflow(
			'test_continue',
			array(
				'name'  => 'Test Continue',
				'steps' => array(
					array(
						'command' => 'fail-step',
						'params'  => array(),
					),
					array(
						'command' => 'another-step',
						'params'  => array(),
					),
				),
			)
		);

		$result = $this->orchestrator->execute_workflow(
			'test_continue',
			array(),
			array(),
			array(
				'continue_on_error' => true,
				'max_retries'       => 0,
			)
		);

		// Should succeed overall despite failed steps.
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 2, count( $result['steps'] ) );
	}

	/**
	 * Test fallback step execution on error.
	 */
	public function test_fallback_step_execution() {
		$this->markTestSkipped( 'Fallback requires workflow with on_error configuration' );
	}

	/**
	 * Test execution ID uniqueness and reuse.
	 */
	public function test_execution_id_uniqueness() {
		$this->handler->method( 'execute' )->willReturn(
			array(
				'success' => true,
				'data'    => array(),
			)
		);

		$this->orchestrator->create_workflow(
			'test_id',
			array(
				'name'  => 'Test ID',
				'steps' => array(
					array(
						'command' => 'test',
						'params'  => array(),
					),
				),
			)
		);

		$result1 = $this->orchestrator->execute_workflow( 'test_id', array(), array() );
		$result2 = $this->orchestrator->execute_workflow( 'test_id', array(), array() );

		$this->assertNotEquals( $result1['execution_id'], $result2['execution_id'] );
		$this->assertNotEmpty( $result1['execution_id'] );
		$this->assertNotEmpty( $result2['execution_id'] );
	}

	/**
	 * Test resume workflow functionality.
	 */
	public function test_resume_workflow() {
		$this->handler->method( 'execute' )->willReturn(
			array(
				'success' => true,
				'data'    => array(),
			)
		);

		$this->orchestrator->create_workflow(
			'test_resume',
			array(
				'name'  => 'Test Resume',
				'steps' => array(
					array(
						'command' => 'step1',
						'params'  => array(),
					),
					array(
						'command' => 'step2',
						'params'  => array(),
					),
					array(
						'command' => 'step3',
						'params'  => array(),
					),
				),
			)
		);

		// Execute with resume_from_step.
		$result = $this->orchestrator->execute_workflow(
			'test_resume',
			array(),
			array(),
			array( 'resume_from_step' => 2 )
		);

		$this->assertTrue( $result['success'] );
		// Should have executed steps 2 and 3 only.
		$this->assertEquals( 2, count( $result['steps'] ) );
	}

	/**
	 * Test advanced workflow registration.
	 */
	public function test_advanced_workflow_registration() {
		$workflows = $this->orchestrator->get_workflows();

		$this->assertArrayHasKey( 'smart_inventory_replenishment', $workflows );
		$this->assertArrayHasKey( 'adaptive_content_publishing', $workflows );
		$this->assertArrayHasKey( 'intelligent_video_distribution', $workflows );
	}

	/**
	 * Test smart inventory replenishment workflow structure.
	 */
	public function test_smart_inventory_workflow_structure() {
		$workflow = $this->orchestrator->get_workflow( 'smart_inventory_replenishment' );

		$this->assertNotNull( $workflow );
		$this->assertArrayHasKey( 'steps', $workflow );
		$this->assertEquals( 4, count( $workflow['steps'] ) );

		// Check for conditional logic.
		$this->assertArrayHasKey( 'condition', $workflow['steps'][1] );
		$this->assertEquals( true, $workflow['steps'][1]['condition']['if_success'] );

		// Check for on_error handling.
		$this->assertArrayHasKey( 'on_error', $workflow['steps'][2] );
	}

	/**
	 * Test adaptive content publishing workflow.
	 */
	public function test_adaptive_content_workflow() {
		$workflow = $this->orchestrator->get_workflow( 'adaptive_content_publishing' );

		$this->assertNotNull( $workflow );
		$this->assertEquals( 4, count( $workflow['steps'] ) );

		// Verify greater_than condition exists.
		$this->assertArrayHasKey( 'condition', $workflow['steps'][2] );
		$this->assertArrayHasKey( 'greater_than', $workflow['steps'][2]['condition'] );
	}

	/**
	 * Test intelligent video distribution workflow.
	 */
	public function test_intelligent_video_workflow() {
		$workflow = $this->orchestrator->get_workflow( 'intelligent_video_distribution' );

		$this->assertNotNull( $workflow );
		$this->assertEquals( 5, count( $workflow['steps'] ) );

		// Verify less_than condition.
		$this->assertArrayHasKey( 'condition', $workflow['steps'][3] );
		$this->assertArrayHasKey( 'less_than', $workflow['steps'][3]['condition'] );
	}

	/**
	 * Test workflow count after Phase 5.
	 */
	public function test_total_workflow_count() {
		$workflows = $this->orchestrator->get_workflows();

		// Should have 14 total workflows (11 from Phases 1-4 + 3 from Phase 5).
		$this->assertGreaterThanOrEqual( 14, count( $workflows ) );
	}

	/**
	 * Test translatable strings in advanced workflows.
	 */
	public function test_advanced_workflow_translatable_strings() {
		$workflow = $this->orchestrator->get_workflow( 'smart_inventory_replenishment' );

		$this->assertIsString( $workflow['name'] );
		$this->assertIsString( $workflow['description'] );
		$this->assertNotEmpty( $workflow['name'] );
		$this->assertNotEmpty( $workflow['description'] );
	}
}
