<?php
/**
 * Tests for Phase 2 Pro Toolkit Workflows
 *
 * Tests the new workflow definitions for Phase 2.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Phase 2 Pro Toolkit Workflows Test Case
 */
class Test_Slash_Commands_Pro_Workflows_Phase2 extends WP_UnitTestCase {

	/**
	 * Workflow orchestrator instance.
	 *
	 * @var WP_MCP_AI_Slash_Command_Workflow_Orchestrator
	 */
	protected $orchestrator;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required classes.
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/slash-commands-init.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-handler.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-workflow-orchestrator.php';

		// Initialize slash commands.
		wp_mcp_ai_init_slash_commands();

		// Get handler and create orchestrator.
		$handler            = wp_mcp_ai_get_slash_command_handler();
		$this->orchestrator = new WP_MCP_AI_Slash_Command_Workflow_Orchestrator( $handler );
	}

	/**
	 * Test Phase 2 workflows are registered.
	 */
	public function test_phase2_workflows_registered() {
		$workflows = $this->orchestrator->get_workflows();

		$this->assertArrayHasKey( 'ecommerce_inventory_management', $workflows );
		$this->assertArrayHasKey( 'social_content_planning', $workflows );
		$this->assertArrayHasKey( 'video_post_production', $workflows );
	}

	/**
	 * Test e-commerce inventory management workflow structure.
	 */
	public function test_ecommerce_inventory_workflow_structure() {
		$workflows = $this->orchestrator->get_workflows();
		$workflow  = $workflows['ecommerce_inventory_management'];

		$this->assertNotEmpty( $workflow['name'] );
		$this->assertNotEmpty( $workflow['description'] );
		$this->assertEquals( 3, $workflow['steps'] );
	}

	/**
	 * Test social content planning workflow structure.
	 */
	public function test_social_content_planning_workflow_structure() {
		$workflows = $this->orchestrator->get_workflows();
		$workflow  = $workflows['social_content_planning'];

		$this->assertNotEmpty( $workflow['name'] );
		$this->assertNotEmpty( $workflow['description'] );
		$this->assertEquals( 3, $workflow['steps'] );
	}

	/**
	 * Test video post production workflow structure.
	 */
	public function test_video_post_production_workflow_structure() {
		$workflows = $this->orchestrator->get_workflows();
		$workflow  = $workflows['video_post_production'];

		$this->assertNotEmpty( $workflow['name'] );
		$this->assertNotEmpty( $workflow['description'] );
		$this->assertEquals( 3, $workflow['steps'] );
	}

	/**
	 * Test e-commerce inventory workflow commands.
	 */
	public function test_ecommerce_inventory_workflow_commands() {
		// Get internal workflow definition.
		$reflection = new ReflectionClass( $this->orchestrator );
		$property   = $reflection->getProperty( 'workflows' );
		$property->setAccessible( true );
		$all_workflows = $property->getValue( $this->orchestrator );

		$workflow = $all_workflows['ecommerce_inventory_management'];
		$steps    = $workflow['steps'];

		// Verify step commands.
		$this->assertEquals( 'inventory-forecast', $steps[0]['command'] );
		$this->assertEquals( 'ecom-analytics', $steps[1]['command'] );
		$this->assertEquals( 'customer-segment', $steps[2]['command'] );

		// Verify first step has proper params.
		$this->assertArrayHasKey( 'period', $steps[0]['params'] );
		$this->assertEquals( 30, $steps[0]['params']['period'] );
		$this->assertTrue( $steps[0]['params']['include-seasonal'] );
	}

	/**
	 * Test social content planning workflow parameters.
	 */
	public function test_social_content_planning_workflow_parameters() {
		$reflection = new ReflectionClass( $this->orchestrator );
		$property   = $reflection->getProperty( 'workflows' );
		$property->setAccessible( true );
		$all_workflows = $property->getValue( $this->orchestrator );

		$workflow = $all_workflows['social_content_planning'];
		$steps    = $workflow['steps'];

		// Verify step commands.
		$this->assertEquals( 'competitor-track', $steps[0]['command'] );
		$this->assertEquals( 'content-calendar', $steps[1]['command'] );
		$this->assertEquals( 'social-schedule', $steps[2]['command'] );

		// Verify parameter placeholders.
		$this->assertArrayHasKey( 'competitor', $steps[0]['params'] );
		$this->assertEquals( '{competitor_handle}', $steps[0]['params']['competitor'] );

		$this->assertArrayHasKey( 'action', $steps[1]['params'] );
		$this->assertEquals( 'create', $steps[1]['params']['action'] );
	}

	/**
	 * Test video post production workflow chaining.
	 */
	public function test_video_post_production_workflow_chaining() {
		$reflection = new ReflectionClass( $this->orchestrator );
		$property   = $reflection->getProperty( 'workflows' );
		$property->setAccessible( true );
		$all_workflows = $property->getValue( $this->orchestrator );

		$workflow = $all_workflows['video_post_production'];
		$steps    = $workflow['steps'];

		// Verify step commands.
		$this->assertEquals( 'video-merge', $steps[0]['command'] );
		$this->assertEquals( 'video-thumbnail', $steps[1]['command'] );
		$this->assertEquals( 'video-compress', $steps[2]['command'] );

		// Verify second step uses previous result.
		$this->assertArrayHasKey( 'video-id', $steps[1]['params'] );
		$this->assertEquals( '{previous.video_id}', $steps[1]['params']['video-id'] );

		// Verify third step also uses previous result.
		$this->assertArrayHasKey( 'video-id', $steps[2]['params'] );
		$this->assertEquals( '{previous.video_id}', $steps[2]['params']['video-id'] );
	}

	/**
	 * Test all Phase 2 workflows have required fields.
	 */
	public function test_phase2_workflows_have_required_fields() {
		$reflection = new ReflectionClass( $this->orchestrator );
		$property   = $reflection->getProperty( 'workflows' );
		$property->setAccessible( true );
		$all_workflows = $property->getValue( $this->orchestrator );

		$phase2_workflows = array(
			'ecommerce_inventory_management',
			'social_content_planning',
			'video_post_production',
		);

		foreach ( $phase2_workflows as $workflow_slug ) {
			$this->assertArrayHasKey( $workflow_slug, $all_workflows );
			$workflow = $all_workflows[ $workflow_slug ];

			// Check required fields.
			$this->assertArrayHasKey( 'name', $workflow );
			$this->assertArrayHasKey( 'description', $workflow );
			$this->assertArrayHasKey( 'steps', $workflow );

			// Check steps are array.
			$this->assertIsArray( $workflow['steps'] );
			$this->assertNotEmpty( $workflow['steps'] );

			// Check each step has command and params.
			foreach ( $workflow['steps'] as $step ) {
				$this->assertArrayHasKey( 'command', $step );
				$this->assertArrayHasKey( 'params', $step );
			}
		}
	}

	/**
	 * Test workflow names are translatable.
	 */
	public function test_phase2_workflow_names_translatable() {
		$workflows = $this->orchestrator->get_workflows();

		$phase2_workflows = array(
			'ecommerce_inventory_management',
			'social_content_planning',
			'video_post_production',
		);

		foreach ( $phase2_workflows as $slug ) {
			$this->assertArrayHasKey( $slug, $workflows );
			$workflow = $workflows[ $slug ];

			// Check if name and description are strings.
			$this->assertIsString( $workflow['name'] );
			$this->assertIsString( $workflow['description'] );
			$this->assertNotEmpty( $workflow['name'] );
			$this->assertNotEmpty( $workflow['description'] );
		}
	}

	/**
	 * Test total workflow count.
	 */
	public function test_total_workflow_count() {
		$workflows = $this->orchestrator->get_workflows();

		// Should have at least 7 workflows (4 Phase 1 + 3 Phase 2).
		$this->assertGreaterThanOrEqual( 7, count( $workflows ) );
	}

	/**
	 * Test workflow step counts.
	 */
	public function test_phase2_workflow_step_counts() {
		$workflows = $this->orchestrator->get_workflows();

		// E-commerce inventory workflow should have 3 steps.
		$this->assertEquals( 3, $workflows['ecommerce_inventory_management']['steps'] );

		// Social content planning workflow should have 3 steps.
		$this->assertEquals( 3, $workflows['social_content_planning']['steps'] );

		// Video post production workflow should have 3 steps.
		$this->assertEquals( 3, $workflows['video_post_production']['steps'] );
	}

	/**
	 * Test workflow parameter placeholder format.
	 */
	public function test_workflow_parameter_placeholders() {
		$reflection = new ReflectionClass( $this->orchestrator );
		$property   = $reflection->getProperty( 'workflows' );
		$property->setAccessible( true );
		$all_workflows = $property->getValue( $this->orchestrator );

		$workflow = $all_workflows['video_post_production'];
		$steps    = $workflow['steps'];

		// Check for {previous.field} placeholder format.
		$video_id_param = $steps[1]['params']['video-id'];
		$this->assertStringStartsWith( '{', $video_id_param );
		$this->assertStringEndsWith( '}', $video_id_param );
		$this->assertStringContainsString( 'previous', $video_id_param );
	}

	/**
	 * Test workflow commands exist.
	 */
	public function test_workflow_commands_are_registered() {
		$handler  = wp_mcp_ai_get_slash_command_handler();
		$commands = $handler->get_registered_commands();

		$reflection = new ReflectionClass( $this->orchestrator );
		$property   = $reflection->getProperty( 'workflows' );
		$property->setAccessible( true );
		$all_workflows = $property->getValue( $this->orchestrator );

		$phase2_workflows = array(
			'ecommerce_inventory_management',
			'social_content_planning',
			'video_post_production',
		);

		foreach ( $phase2_workflows as $workflow_slug ) {
			$workflow = $all_workflows[ $workflow_slug ];

			foreach ( $workflow['steps'] as $step ) {
				$command_name = $step['command'];
				$this->assertArrayHasKey(
					$command_name,
					$commands,
					"Command '{$command_name}' used in workflow '{$workflow_slug}' should be registered"
				);
			}
		}
	}
}
