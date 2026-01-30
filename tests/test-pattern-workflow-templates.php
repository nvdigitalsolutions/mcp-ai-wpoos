<?php
/**
 * Pattern Workflow Templates Tests
 *
 * @package WP_MCP_AI
 */

/**
 * Test pattern workflow templates
 */
class Test_Pattern_Workflow_Templates extends WP_UnitTestCase {

	/**
	 * Workflow templates instance
	 *
	 * @var WP_MCP_AI_Pattern_Workflow_Templates
	 */
	protected $templates;

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required classes.
		require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-pattern-constants.php';
		require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-pattern-registry.php';
		require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-pattern-workflow-templates.php';

		$this->templates = new WP_MCP_AI_Pattern_Workflow_Templates();
	}

	/**
	 * Test getting all templates
	 */
	public function test_get_all_templates() {
		$templates = $this->templates->get_all_templates();

		$this->assertIsArray( $templates );
		$this->assertCount( 8, $templates, 'Should have 8 pattern templates' );

		// Check all expected patterns have templates.
		$expected_patterns = array(
			WP_MCP_AI_Pattern_Constants::PATTERN_ORCHESTRATOR,
			WP_MCP_AI_Pattern_Constants::PATTERN_SEQUENTIAL,
			WP_MCP_AI_Pattern_Constants::PATTERN_PEER_TO_PEER,
			WP_MCP_AI_Pattern_Constants::PATTERN_SKILL_ROUTER,
			WP_MCP_AI_Pattern_Constants::PATTERN_LAYERED_DEFENSE,
			WP_MCP_AI_Pattern_Constants::PATTERN_EVENT_DRIVEN,
			WP_MCP_AI_Pattern_Constants::PATTERN_HIERARCHICAL,
			WP_MCP_AI_Pattern_Constants::PATTERN_EXPERIMENTATION,
		);

		foreach ( $expected_patterns as $pattern_slug ) {
			$this->assertArrayHasKey( $pattern_slug, $templates );
		}
	}

	/**
	 * Test getting specific template
	 */
	public function test_get_workflow_template() {
		$template = $this->templates->get_workflow_template( WP_MCP_AI_Pattern_Constants::PATTERN_ORCHESTRATOR );

		$this->assertIsArray( $template );
		$this->assertArrayHasKey( 'name', $template );
		$this->assertArrayHasKey( 'pattern', $template );
		$this->assertArrayHasKey( 'description', $template );
		$this->assertArrayHasKey( 'roles', $template );
		$this->assertArrayHasKey( 'workflow', $template );
	}

	/**
	 * Test template structure validation
	 */
	public function test_template_structure() {
		$templates = $this->templates->get_all_templates();

		foreach ( $templates as $pattern_slug => $template ) {
			// Required fields.
			$this->assertArrayHasKey( 'name', $template, "{$pattern_slug} missing name" );
			$this->assertArrayHasKey( 'pattern', $template, "{$pattern_slug} missing pattern" );
			$this->assertArrayHasKey( 'description', $template, "{$pattern_slug} missing description" );
			$this->assertArrayHasKey( 'roles', $template, "{$pattern_slug} missing roles" );
			$this->assertArrayHasKey( 'workflow', $template, "{$pattern_slug} missing workflow" );

			// Validate roles is array.
			$this->assertIsArray( $template['roles'], "{$pattern_slug} roles should be array" );
			$this->assertNotEmpty( $template['roles'], "{$pattern_slug} should have at least one role" );

			// Validate workflow is array.
			$this->assertIsArray( $template['workflow'], "{$pattern_slug} workflow should be array" );
			$this->assertNotEmpty( $template['workflow'], "{$pattern_slug} should have at least one workflow step" );

			// Validate workflow steps.
			foreach ( $template['workflow'] as $step ) {
				$this->assertArrayHasKey( 'name', $step, "{$pattern_slug} workflow step missing name" );
				$this->assertArrayHasKey( 'type', $step, "{$pattern_slug} workflow step missing type" );
			}
		}
	}

	/**
	 * Test orchestrator template
	 */
	public function test_orchestrator_template() {
		$template = $this->templates->get_workflow_template( WP_MCP_AI_Pattern_Constants::PATTERN_ORCHESTRATOR );

		$this->assertEquals( WP_MCP_AI_Pattern_Constants::PATTERN_ORCHESTRATOR, $template['pattern'] );
		$this->assertContains( 'coordinator', $template['roles'] );
		$this->assertGreaterThanOrEqual( 2, count( $template['roles'] ), 'Orchestrator needs coordinator + workers' );
		$this->assertGreaterThanOrEqual( 2, count( $template['workflow'] ), 'Orchestrator needs multiple steps' );
	}

	/**
	 * Test sequential template
	 */
	public function test_sequential_template() {
		$template = $this->templates->get_workflow_template( WP_MCP_AI_Pattern_Constants::PATTERN_SEQUENTIAL );

		$this->assertEquals( WP_MCP_AI_Pattern_Constants::PATTERN_SEQUENTIAL, $template['pattern'] );
		$this->assertGreaterThanOrEqual( 2, count( $template['roles'] ), 'Sequential needs multiple stages' );
		$this->assertGreaterThanOrEqual( 2, count( $template['workflow'] ), 'Sequential needs multiple steps' );
	}

	/**
	 * Test peer-to-peer template
	 */
	public function test_peer_to_peer_template() {
		$template = $this->templates->get_workflow_template( WP_MCP_AI_Pattern_Constants::PATTERN_PEER_TO_PEER );

		$this->assertEquals( WP_MCP_AI_Pattern_Constants::PATTERN_PEER_TO_PEER, $template['pattern'] );
		$this->assertGreaterThanOrEqual( 3, count( $template['roles'] ), 'Peer-to-peer needs multiple peers' );
	}

	/**
	 * Test skill router template
	 */
	public function test_skill_router_template() {
		$template = $this->templates->get_workflow_template( WP_MCP_AI_Pattern_Constants::PATTERN_SKILL_ROUTER );

		$this->assertEquals( WP_MCP_AI_Pattern_Constants::PATTERN_SKILL_ROUTER, $template['pattern'] );
		$this->assertContains( 'router', $template['roles'] );
	}

	/**
	 * Test layered defense template
	 */
	public function test_layered_defense_template() {
		$template = $this->templates->get_workflow_template( WP_MCP_AI_Pattern_Constants::PATTERN_LAYERED_DEFENSE );

		$this->assertEquals( WP_MCP_AI_Pattern_Constants::PATTERN_LAYERED_DEFENSE, $template['pattern'] );
		$this->assertGreaterThanOrEqual( 2, count( $template['roles'] ), 'Layered defense needs multiple layers' );
		$this->assertGreaterThanOrEqual( 2, count( $template['workflow'] ), 'Layered defense needs multiple validation steps' );
	}

	/**
	 * Test event-driven template
	 */
	public function test_event_driven_template() {
		$template = $this->templates->get_workflow_template( WP_MCP_AI_Pattern_Constants::PATTERN_EVENT_DRIVEN );

		$this->assertEquals( WP_MCP_AI_Pattern_Constants::PATTERN_EVENT_DRIVEN, $template['pattern'] );
		$this->assertContains( 'monitor', $template['roles'] );
	}

	/**
	 * Test hierarchical template
	 */
	public function test_hierarchical_template() {
		$template = $this->templates->get_workflow_template( WP_MCP_AI_Pattern_Constants::PATTERN_HIERARCHICAL );

		$this->assertEquals( WP_MCP_AI_Pattern_Constants::PATTERN_HIERARCHICAL, $template['pattern'] );
		$this->assertGreaterThanOrEqual( 5, count( $template['roles'] ), 'Hierarchical needs director + managers + workers' );
	}

	/**
	 * Test experimentation template
	 */
	public function test_experimentation_template() {
		$template = $this->templates->get_workflow_template( WP_MCP_AI_Pattern_Constants::PATTERN_EXPERIMENTATION );

		$this->assertEquals( WP_MCP_AI_Pattern_Constants::PATTERN_EXPERIMENTATION, $template['pattern'] );
		$this->assertContains( 'evaluator', $template['roles'] );
		$this->assertGreaterThanOrEqual( 2, count( $template['roles'] ), 'Experimentation needs experimenters + evaluator' );
	}

	/**
	 * Test getting invalid template
	 */
	public function test_get_invalid_template() {
		$template = $this->templates->get_workflow_template( 'invalid_pattern' );

		$this->assertNull( $template );
	}

	/**
	 * Test template customization
	 */
	public function test_customize_template() {
		$template = $this->templates->get_workflow_template( WP_MCP_AI_Pattern_Constants::PATTERN_ORCHESTRATOR );

		$context = array(
			'team_size'    => 5,
			'custom_roles' => array( 'custom_role_1', 'custom_role_2' ),
		);

		$customized = $this->templates->customize_template( $template, $context );

		$this->assertIsArray( $customized );
		$this->assertArrayHasKey( 'roles', $customized );

		// Check custom roles were added.
		$this->assertContains( 'custom_role_1', $customized['roles'] );
		$this->assertContains( 'custom_role_2', $customized['roles'] );
	}

	/**
	 * Test workflow step types
	 */
	public function test_workflow_step_types() {
		$templates = $this->templates->get_all_templates();

		$valid_step_types = array(
			'coordinate',
			'delegate',
			'parallel',
			'validate',
			'route',
			'delegate_dynamic',
			'collaborate',
			'vote',
			'monitor',
			'respond',
			'evaluate',
		);

		foreach ( $templates as $pattern_slug => $template ) {
			foreach ( $template['workflow'] as $step ) {
				$this->assertContains(
					$step['type'],
					$valid_step_types,
					"{$pattern_slug} has invalid step type: {$step['type']}"
				);
			}
		}
	}
}
