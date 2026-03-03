<?php
/**
 * Tests for Phase 3 Pro Toolkit Workflows
 *
 * Tests the Phase 3 workflow definitions.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Phase 3 Pro Toolkit Workflows Test Case
 */
class Test_Slash_Commands_Pro_Workflows_Phase3 extends WP_UnitTestCase {

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
	 * Test Phase 3 workflows are registered.
	 */
	public function test_phase3_workflows_registered() {
		$workflows = $this->orchestrator->get_workflows();

		$this->assertArrayHasKey( 'product_launch_complete', $workflows );
		$this->assertArrayHasKey( 'social_campaign_automation', $workflows );
	}

	/**
	 * Test product launch workflow structure.
	 */
	public function test_product_launch_workflow_structure() {
		$workflows = $this->orchestrator->get_workflows();
		$workflow  = $workflows['product_launch_complete'];

		$this->assertNotEmpty( $workflow['name'] );
		$this->assertNotEmpty( $workflow['description'] );
		$this->assertEquals( 4, $workflow['steps'] );
	}

	/**
	 * Test social campaign automation workflow structure.
	 */
	public function test_social_campaign_automation_workflow_structure() {
		$workflows = $this->orchestrator->get_workflows();
		$workflow  = $workflows['social_campaign_automation'];

		$this->assertNotEmpty( $workflow['name'] );
		$this->assertNotEmpty( $workflow['description'] );
		$this->assertEquals( 4, $workflow['steps'] );
	}

	/**
	 * Test product launch workflow commands.
	 */
	public function test_product_launch_workflow_commands() {
		$reflection = new ReflectionClass( $this->orchestrator );
		$property   = $reflection->getProperty( 'workflows' );
		$property->setAccessible( true );
		$all_workflows = $property->getValue( $this->orchestrator );

		$workflow = $all_workflows['product_launch_complete'];
		$steps    = $workflow['steps'];

		// Verify step commands.
		$this->assertEquals( 'bundle-create', $steps[0]['command'] );
		$this->assertEquals( 'discount-optimize', $steps[1]['command'] );
		$this->assertEquals( 'campaign-create', $steps[2]['command'] );
		$this->assertEquals( 'inventory-forecast', $steps[3]['command'] );
	}

	/**
	 * Test social campaign automation workflow commands.
	 */
	public function test_social_campaign_automation_workflow_commands() {
		$reflection = new ReflectionClass( $this->orchestrator );
		$property   = $reflection->getProperty( 'workflows' );
		$property->setAccessible( true );
		$all_workflows = $property->getValue( $this->orchestrator );

		$workflow = $all_workflows['social_campaign_automation'];
		$steps    = $workflow['steps'];

		// Verify step commands.
		$this->assertEquals( 'influencer-find', $steps[0]['command'] );
		$this->assertEquals( 'post-optimize', $steps[1]['command'] );
		$this->assertEquals( 'campaign-create', $steps[2]['command'] );
		$this->assertEquals( 'social-schedule', $steps[3]['command'] );
	}

	/**
	 * Test product launch workflow parameter placeholders.
	 */
	public function test_product_launch_workflow_placeholders() {
		$reflection = new ReflectionClass( $this->orchestrator );
		$property   = $reflection->getProperty( 'workflows' );
		$property->setAccessible( true );
		$all_workflows = $property->getValue( $this->orchestrator );

		$workflow = $all_workflows['product_launch_complete'];
		$steps    = $workflow['steps'];

		// Check for {product_name} placeholder.
		$this->assertArrayHasKey( 'name', $steps[0]['params'] );
		$this->assertStringContainsString( '{product_name}', $steps[0]['params']['name'] );

		// Check for {product_ids} placeholder.
		$this->assertArrayHasKey( 'products', $steps[0]['params'] );
		$this->assertEquals( '{product_ids}', $steps[0]['params']['products'] );
	}

	/**
	 * Test social campaign workflow parameter passing.
	 */
	public function test_social_campaign_workflow_parameter_passing() {
		$reflection = new ReflectionClass( $this->orchestrator );
		$property   = $reflection->getProperty( 'workflows' );
		$property->setAccessible( true );
		$all_workflows = $property->getValue( $this->orchestrator );

		$workflow = $all_workflows['social_campaign_automation'];
		$steps    = $workflow['steps'];

		// Verify parameter passing between steps.
		$this->assertArrayHasKey( 'content', $steps[3]['params'] );
		$this->assertEquals( '{previous.optimized_content}', $steps[3]['params']['content'] );
	}

	/**
	 * Test workflow names are translatable.
	 */
	public function test_phase3_workflow_names_translatable() {
		$workflows = $this->orchestrator->get_workflows();

		$phase3_workflows = array(
			'product_launch_complete',
			'social_campaign_automation',
		);

		foreach ( $phase3_workflows as $slug ) {
			$this->assertArrayHasKey( $slug, $workflows );
			$workflow = $workflows[ $slug ];

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

		// Should have at least 9 workflows (4 Phase 1 + 3 Phase 2 + 2 Phase 3).
		$this->assertGreaterThanOrEqual( 9, count( $workflows ) );
	}

	/**
	 * Test workflow step counts.
	 */
	public function test_phase3_workflow_step_counts() {
		$workflows = $this->orchestrator->get_workflows();

		// Product launch workflow should have 4 steps.
		$this->assertEquals( 4, $workflows['product_launch_complete']['steps'] );

		// Social campaign automation workflow should have 4 steps.
		$this->assertEquals( 4, $workflows['social_campaign_automation']['steps'] );
	}

	/**
	 * Test all Phase 3 workflows have required fields.
	 */
	public function test_phase3_workflows_have_required_fields() {
		$reflection = new ReflectionClass( $this->orchestrator );
		$property   = $reflection->getProperty( 'workflows' );
		$property->setAccessible( true );
		$all_workflows = $property->getValue( $this->orchestrator );

		$phase3_workflows = array(
			'product_launch_complete',
			'social_campaign_automation',
		);

		foreach ( $phase3_workflows as $workflow_slug ) {
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
	 * Test workflow commands are registered.
	 */
	public function test_phase3_workflow_commands_registered() {
		$handler  = wp_mcp_ai_get_slash_command_handler();
		$commands = $handler->get_registered_commands();

		$reflection = new ReflectionClass( $this->orchestrator );
		$property   = $reflection->getProperty( 'workflows' );
		$property->setAccessible( true );
		$all_workflows = $property->getValue( $this->orchestrator );

		$phase3_workflows = array(
			'product_launch_complete',
			'social_campaign_automation',
		);

		foreach ( $phase3_workflows as $workflow_slug ) {
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

	/**
	 * Test product launch workflow parameter types.
	 */
	public function test_product_launch_workflow_parameter_types() {
		$reflection = new ReflectionClass( $this->orchestrator );
		$property   = $reflection->getProperty( 'workflows' );
		$property->setAccessible( true );
		$all_workflows = $property->getValue( $this->orchestrator );

		$workflow = $all_workflows['product_launch_complete'];
		$steps    = $workflow['steps'];

		// Check discount parameter is numeric.
		$this->assertArrayHasKey( 'discount', $steps[0]['params'] );
		$this->assertIsNumeric( $steps[0]['params']['discount'] );

		// Check amount parameter is numeric.
		$this->assertArrayHasKey( 'amount', $steps[1]['params'] );
		$this->assertIsNumeric( $steps[1]['params']['amount'] );
	}
}
