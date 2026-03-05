<?php
/**
 * Tests for Pro Toolkit Workflows
 *
 * Tests the new workflow definitions for ecommerce, social media, and video production.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Pro Toolkit Workflows Test Case
 */
class Test_Slash_Commands_Pro_Workflows extends WP_UnitTestCase {

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
	 * Test workflow orchestrator is initialized.
	 */
	public function test_workflow_orchestrator_initialized() {
		$this->assertNotNull( $this->orchestrator );
		$this->assertInstanceOf( 'WP_MCP_AI_Slash_Command_Workflow_Orchestrator', $this->orchestrator );
	}

	/**
	 * Test new workflows are registered.
	 */
	public function test_new_workflows_registered() {
		$workflows = $this->orchestrator->get_workflows();

		// Check abandoned cart workflow.
		$this->assertArrayHasKey( 'abandoned_cart_campaign', $workflows );
		$this->assertEquals( 3, $workflows['abandoned_cart_campaign']['steps'] );

		// Check social media workflow.
		$this->assertArrayHasKey( 'social_media_campaign', $workflows );
		$this->assertEquals( 3, $workflows['social_media_campaign']['steps'] );

		// Check video marketing workflow.
		$this->assertArrayHasKey( 'video_marketing_workflow', $workflows );
		$this->assertEquals( 3, $workflows['video_marketing_workflow']['steps'] );

		// Check ecommerce upsell workflow.
		$this->assertArrayHasKey( 'ecommerce_upsell_optimization', $workflows );
		$this->assertEquals( 2, $workflows['ecommerce_upsell_optimization']['steps'] );
	}

	/**
	 * Test abandoned cart workflow structure.
	 */
	public function test_abandoned_cart_workflow_structure() {
		$workflows = $this->orchestrator->get_workflows();
		$workflow  = $workflows['abandoned_cart_campaign'];

		$this->assertNotEmpty( $workflow['name'] );
		$this->assertNotEmpty( $workflow['description'] );
		$this->assertEquals( 3, $workflow['steps'] );
	}

	/**
	 * Test social media campaign workflow structure.
	 */
	public function test_social_media_campaign_workflow_structure() {
		$workflows = $this->orchestrator->get_workflows();
		$workflow  = $workflows['social_media_campaign'];

		$this->assertNotEmpty( $workflow['name'] );
		$this->assertNotEmpty( $workflow['description'] );
		$this->assertEquals( 3, $workflow['steps'] );
	}

	/**
	 * Test video marketing workflow structure.
	 */
	public function test_video_marketing_workflow_structure() {
		$workflows = $this->orchestrator->get_workflows();
		$workflow  = $workflows['video_marketing_workflow'];

		$this->assertNotEmpty( $workflow['name'] );
		$this->assertNotEmpty( $workflow['description'] );
		$this->assertEquals( 3, $workflow['steps'] );
	}

	/**
	 * Test ecommerce upsell workflow structure.
	 */
	public function test_ecommerce_upsell_workflow_structure() {
		$workflows = $this->orchestrator->get_workflows();
		$workflow  = $workflows['ecommerce_upsell_optimization'];

		$this->assertNotEmpty( $workflow['name'] );
		$this->assertNotEmpty( $workflow['description'] );
		$this->assertEquals( 2, $workflow['steps'] );
	}

	/**
	 * Test workflow names are translatable.
	 */
	public function test_workflow_names_translatable() {
		$workflows = $this->orchestrator->get_workflows();

		foreach ( $workflows as $slug => $workflow ) {
			// Check if name and description look like they've been through translation function.
			$this->assertIsString( $workflow['name'] );
			$this->assertIsString( $workflow['description'] );
			$this->assertNotEmpty( $workflow['name'] );
			$this->assertNotEmpty( $workflow['description'] );
		}
	}

	/**
	 * Test workflow step count.
	 */
	public function test_workflow_step_counts() {
		$workflows = $this->orchestrator->get_workflows();

		// Each workflow should have at least 2 steps.
		foreach ( $workflows as $slug => $workflow ) {
			$this->assertGreaterThanOrEqual( 2, $workflow['steps'], "Workflow {$slug} should have at least 2 steps" );
		}
	}

	/**
	 * Test abandoned cart workflow commands.
	 */
	public function test_abandoned_cart_workflow_commands() {
		$workflows = $this->orchestrator->get_workflows();

		// Get internal workflow definition (not just metadata).
		$reflection = new ReflectionClass( $this->orchestrator );
		$property   = $reflection->getProperty( 'workflows' );
		$property->setAccessible( true );
		$all_workflows = $property->getValue( $this->orchestrator );

		$workflow = $all_workflows['abandoned_cart_campaign'];

		// Check steps structure.
		$this->assertArrayHasKey( 'steps', $workflow );
		$steps = $workflow['steps'];

		// Verify first step is identify.
		$this->assertEquals( 'abandoned-recover', $steps[0]['command'] );
		$this->assertArrayHasKey( 'action', $steps[0]['params'] );
		$this->assertEquals( 'identify', $steps[0]['params']['action'] );

		// Verify second step is recover.
		$this->assertEquals( 'abandoned-recover', $steps[1]['command'] );
		$this->assertEquals( 'recover', $steps[1]['params']['action'] );
	}

	/**
	 * Test social media campaign workflow parameter placeholders.
	 */
	public function test_social_media_campaign_workflow_placeholders() {
		$reflection = new ReflectionClass( $this->orchestrator );
		$property   = $reflection->getProperty( 'workflows' );
		$property->setAccessible( true );
		$all_workflows = $property->getValue( $this->orchestrator );

		$workflow = $all_workflows['social_media_campaign'];
		$steps    = $workflow['steps'];

		// First step should use {post_content} placeholder.
		$this->assertArrayHasKey( 'content', $steps[0]['params'] );
		$this->assertEquals( '{post_content}', $steps[0]['params']['content'] );

		// Second step should also use {post_content}.
		$this->assertArrayHasKey( 'content', $steps[1]['params'] );
		$this->assertEquals( '{post_content}', $steps[1]['params']['content'] );
	}

	/**
	 * Test video marketing workflow parameter passing.
	 */
	public function test_video_marketing_workflow_parameter_passing() {
		$reflection = new ReflectionClass( $this->orchestrator );
		$property   = $reflection->getProperty( 'workflows' );
		$property->setAccessible( true );
		$all_workflows = $property->getValue( $this->orchestrator );

		$workflow = $all_workflows['video_marketing_workflow'];
		$steps    = $workflow['steps'];

		// Second step should reference previous.video_id.
		$this->assertArrayHasKey( 'video-id', $steps[1]['params'] );
		$this->assertEquals( '{previous.video_id}', $steps[1]['params']['video-id'] );

		// Third step should also reference previous.video_id.
		$this->assertArrayHasKey( 'media', $steps[2]['params'] );
		$this->assertEquals( '{previous.video_id}', $steps[2]['params']['media'] );
	}

	/**
	 * Test ecommerce upsell workflow chaining.
	 */
	public function test_ecommerce_upsell_workflow_chaining() {
		$reflection = new ReflectionClass( $this->orchestrator );
		$property   = $reflection->getProperty( 'workflows' );
		$property->setAccessible( true );
		$all_workflows = $property->getValue( $this->orchestrator );

		$workflow = $all_workflows['ecommerce_upsell_optimization'];
		$steps    = $workflow['steps'];

		// Second step should use result from first step.
		$this->assertArrayHasKey( 'product-id', $steps[1]['params'] );
		$this->assertEquals( '{previous.top_product_id}', $steps[1]['params']['product-id'] );
	}

	/**
	 * Test all new workflows have required fields.
	 */
	public function test_all_workflows_have_required_fields() {
		$reflection = new ReflectionClass( $this->orchestrator );
		$property   = $reflection->getProperty( 'workflows' );
		$property->setAccessible( true );
		$all_workflows = $property->getValue( $this->orchestrator );

		$new_workflows = array(
			'abandoned_cart_campaign',
			'social_media_campaign',
			'video_marketing_workflow',
			'ecommerce_upsell_optimization',
		);

		foreach ( $new_workflows as $workflow_slug ) {
			$this->assertArrayHasKey( $workflow_slug, $all_workflows );
			$workflow = $all_workflows[ $workflow_slug ];

			// Check required fields.
			$this->assertArrayHasKey( 'name', $workflow );
			$this->assertArrayHasKey( 'description', $workflow );
			$this->assertArrayHasKey( 'steps', $workflow );

			// Check steps are array.
			$this->assertIsArray( $workflow['steps'] );
			$this->assertNotEmpty( $workflow['steps'] );

			// Check each step has command.
			foreach ( $workflow['steps'] as $step ) {
				$this->assertArrayHasKey( 'command', $step );
				$this->assertArrayHasKey( 'params', $step );
			}
		}
	}
}
