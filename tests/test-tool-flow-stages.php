<?php
/**
 * Tests for tool flow stage eligibility.
 *
 * @package WP_MCP_AI
 */

/**
 * Mock tool with flow stage restrictions for testing.
 */
class WP_MCP_AI_Mock_Start_Stage_Tool implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Flow_Stage_Interface {
	public function get_slug() {
		return 'start_only_tool';
	}

	public function get_name() {
		return 'Start Only Tool';
	}

	public function get_description() {
		return 'A tool that can only be used in the start stage';
	}

	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		return array( 'result' => 'start stage execution' );
	}

	public function get_flow_stages() {
		return array( 'start' );
	}
}

/**
 * Mock tool with multiple stage eligibility.
 */
class WP_MCP_AI_Mock_Start_Middle_Tool implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Flow_Stage_Interface {
	public function get_slug() {
		return 'start_middle_tool';
	}

	public function get_name() {
		return 'Start and Middle Tool';
	}

	public function get_description() {
		return 'A tool that can be used in start and middle stages';
	}

	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		return array( 'result' => 'start or middle stage execution' );
	}

	public function get_flow_stages() {
		return array( 'start', 'middle' );
	}
}

/**
 * Mock tool with anytime eligibility (default behavior).
 */
class WP_MCP_AI_Mock_Anytime_Tool implements WP_MCP_AI_Tool_Interface {
	public function get_slug() {
		return 'anytime_tool';
	}

	public function get_name() {
		return 'Anytime Tool';
	}

	public function get_description() {
		return 'A tool that can be used at any stage';
	}

	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		return array( 'result' => 'anytime execution' );
	}
}

/**
 * @group tool-flow-stages
 */
class WP_MCP_AI_Tool_Flow_Stages_Tests extends WP_UnitTestCase {

	/**
	 * Original registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry|null
	 */
	protected $original_instance;

	/**
	 * Test registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	public function setUp(): void {
		parent::setUp();

		// Reset the singleton instance using reflection.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Registry' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$this->original_instance = $property->getValue();
		$property->setValue( null, null );

		// Get fresh instance.
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Register test tools.
		$this->registry->register_tool( new WP_MCP_AI_Mock_Start_Stage_Tool() );
		$this->registry->register_tool( new WP_MCP_AI_Mock_Start_Middle_Tool() );
		$this->registry->register_tool( new WP_MCP_AI_Mock_Anytime_Tool() );
	}

	public function tearDown(): void {
		// Restore original instance.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Registry' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, $this->original_instance );

		parent::tearDown();
	}

	// ===================
	// Flow Stage Tests
	// ===================

	/**
	 * Test get_tool_flow_stages for tool with stage restrictions.
	 */
	public function test_get_tool_flow_stages_for_restricted_tool() {
		$stages = $this->registry->get_tool_flow_stages( 'start_only_tool' );

		$this->assertIsArray( $stages );
		$this->assertContains( 'start', $stages );
		$this->assertNotContains( 'anytime', $stages );
	}

	/**
	 * Test get_tool_flow_stages for tool without stage restrictions.
	 */
	public function test_get_tool_flow_stages_for_unrestricted_tool() {
		$stages = $this->registry->get_tool_flow_stages( 'anytime_tool' );

		$this->assertIsArray( $stages );
		$this->assertContains( 'anytime', $stages );
	}

	/**
	 * Test get_all_tool_flow_stages returns only restricted tools.
	 */
	public function test_get_all_tool_flow_stages() {
		$all_stages = $this->registry->get_all_tool_flow_stages();

		$this->assertIsArray( $all_stages );
		$this->assertArrayHasKey( 'start_only_tool', $all_stages );
		$this->assertArrayHasKey( 'start_middle_tool', $all_stages );
		$this->assertArrayNotHasKey( 'anytime_tool', $all_stages );
	}

	/**
	 * Test determine_flow_stage with explicit flow_stage in context.
	 */
	public function test_determine_flow_stage_explicit() {
		$reflection = new ReflectionClass( $this->registry );
		$method     = $reflection->getMethod( 'determine_flow_stage' );
		$method->setAccessible( true );

		$context = array( 'flow_stage' => 'middle' );
		$stage   = $method->invoke( $this->registry, $context );

		$this->assertEquals( 'middle', $stage );
	}

	/**
	 * Test determine_flow_stage for start iteration.
	 */
	public function test_determine_flow_stage_start_iteration() {
		$reflection = new ReflectionClass( $this->registry );
		$method     = $reflection->getMethod( 'determine_flow_stage' );
		$method->setAccessible( true );

		$context = array(
			'iteration'      => 0,
			'max_iterations' => 5,
		);
		$stage   = $method->invoke( $this->registry, $context );

		$this->assertEquals( 'start', $stage );
	}

	/**
	 * Test determine_flow_stage for middle iteration.
	 */
	public function test_determine_flow_stage_middle_iteration() {
		$reflection = new ReflectionClass( $this->registry );
		$method     = $reflection->getMethod( 'determine_flow_stage' );
		$method->setAccessible( true );

		$context = array(
			'iteration'      => 2,
			'max_iterations' => 5,
		);
		$stage   = $method->invoke( $this->registry, $context );

		$this->assertEquals( 'middle', $stage );
	}

	/**
	 * Test determine_flow_stage for end iteration.
	 */
	public function test_determine_flow_stage_end_iteration() {
		$reflection = new ReflectionClass( $this->registry );
		$method     = $reflection->getMethod( 'determine_flow_stage' );
		$method->setAccessible( true );

		$context = array(
			'iteration'      => 4,
			'max_iterations' => 5,
		);
		$stage   = $method->invoke( $this->registry, $context );

		$this->assertEquals( 'end', $stage );
	}

	/**
	 * Test determine_flow_stage for single iteration.
	 */
	public function test_determine_flow_stage_single_iteration() {
		$reflection = new ReflectionClass( $this->registry );
		$method     = $reflection->getMethod( 'determine_flow_stage' );
		$method->setAccessible( true );

		$context = array(
			'iteration'      => 0,
			'max_iterations' => 1,
		);
		$stage   = $method->invoke( $this->registry, $context );

		$this->assertEquals( 'start', $stage );
	}

	/**
	 * Test validate_tool_flow_stage allows execution in correct stage.
	 */
	public function test_validate_tool_flow_stage_allows_correct_stage() {
		$context = array(
			'iteration'      => 0,
			'max_iterations' => 5,
		);

		$result = $this->registry->validate_tool_flow_stage( 'start_only_tool', $context );

		$this->assertTrue( $result );
	}

	/**
	 * Test validate_tool_flow_stage blocks execution in incorrect stage.
	 */
	public function test_validate_tool_flow_stage_blocks_incorrect_stage() {
		$context = array(
			'iteration'      => 2,
			'max_iterations' => 5,
		);

		$result = $this->registry->validate_tool_flow_stage( 'start_only_tool', $context );

		$this->assertWPError( $result );
		$this->assertEquals( 'tool_flow_stage_not_eligible', $result->get_error_code() );
	}

	/**
	 * Test validate_tool_flow_stage allows anytime tools in any stage.
	 */
	public function test_validate_tool_flow_stage_allows_anytime_tools() {
		$contexts = array(
			array( 'iteration' => 0, 'max_iterations' => 5 ),
			array( 'iteration' => 2, 'max_iterations' => 5 ),
			array( 'iteration' => 4, 'max_iterations' => 5 ),
		);

		foreach ( $contexts as $context ) {
			$result = $this->registry->validate_tool_flow_stage( 'anytime_tool', $context );
			$this->assertTrue( $result, 'Anytime tool should be allowed in all stages' );
		}
	}

	/**
	 * Test validate_tool_flow_stage with multiple eligible stages.
	 */
	public function test_validate_tool_flow_stage_multiple_eligible_stages() {
		// Start stage - should pass.
		$context = array(
			'iteration'      => 0,
			'max_iterations' => 5,
		);
		$result  = $this->registry->validate_tool_flow_stage( 'start_middle_tool', $context );
		$this->assertTrue( $result );

		// Middle stage - should pass.
		$context = array(
			'iteration'      => 2,
			'max_iterations' => 5,
		);
		$result  = $this->registry->validate_tool_flow_stage( 'start_middle_tool', $context );
		$this->assertTrue( $result );

		// End stage - should fail.
		$context = array(
			'iteration'      => 4,
			'max_iterations' => 5,
		);
		$result  = $this->registry->validate_tool_flow_stage( 'start_middle_tool', $context );
		$this->assertWPError( $result );
	}

	/**
	 * Test execute_tool with flow stage validation.
	 */
	public function test_execute_tool_validates_flow_stage() {
		$context = array(
			'iteration'      => 2,
			'max_iterations' => 5,
		);

		$result = $this->registry->execute_tool( 'start_only_tool', array(), $context );

		$this->assertWPError( $result );
		$this->assertEquals( 'tool_flow_stage_not_eligible', $result->get_error_code() );
	}

	/**
	 * Test execute_tool allows correct stage execution.
	 */
	public function test_execute_tool_allows_correct_stage() {
		$context = array(
			'iteration'      => 0,
			'max_iterations' => 5,
		);

		$result = $this->registry->execute_tool( 'start_only_tool', array(), $context );

		$this->assertIsArray( $result );
		$this->assertEquals( 'start stage execution', $result['result'] );
	}

	// ===================
	// Context Restriction Tests
	// ===================

	/**
	 * Test validate_tool_context returns true for tools without restrictions.
	 */
	public function test_validate_tool_context_unrestricted_tool() {
		$context = array( 'endpoint' => '/chat-client' );

		$result = $this->registry->validate_tool_context( 'anytime_tool', $context );

		$this->assertTrue( $result );
	}
}
