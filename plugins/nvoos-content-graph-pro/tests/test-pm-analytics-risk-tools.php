<?php
/**
 * Characterization tests for the Wave F2 PM analytics + risk tool batch —
 * the ported burndown/velocity/portfolio/utilization/timeline/forecast
 * analytics tools and the risk assessment/stale-detection/blocker
 * identification tools.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool classes (classmap-autoloaded); the byte-identical surfaces and
 *   smoke contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/project-management/{analytics,risk}/` are asserted in full,
 *   including the filter and ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * PM analytics + risk tool batch tests.
 */
class Test_Pm_Analytics_Risk_Tools extends WP_UnitTestCase {

	/**
	 * Enable the PM toolkit for availability gates.
	 */
	public function setUp(): void {
		parent::setUp();
		$settings                              = get_option( 'wp_mcp_ai_settings', array() );
		$settings                              = is_array( $settings ) ? $settings : array();
		$settings['enable_project_management'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * The nine ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Get_Burndown_Chart'       => 'tools/project-management/analytics/class-wp-mcp-ai-tool-get-burndown-chart.php',
			'WP_MCP_AI_Tool_Get_Team_Velocity'        => 'tools/project-management/analytics/class-wp-mcp-ai-tool-get-team-velocity.php',
			'WP_MCP_AI_Tool_Get_Portfolio_Health'     => 'tools/project-management/analytics/class-wp-mcp-ai-tool-get-portfolio-health.php',
			'WP_MCP_AI_Tool_Get_Resource_Utilization' => 'tools/project-management/analytics/class-wp-mcp-ai-tool-get-resource-utilization.php',
			'WP_MCP_AI_Tool_Get_Project_Timeline'     => 'tools/project-management/analytics/class-wp-mcp-ai-tool-get-project-timeline.php',
			'WP_MCP_AI_Tool_Forecast_Completion'      => 'tools/project-management/analytics/class-wp-mcp-ai-tool-forecast-completion.php',
			'WP_MCP_AI_Tool_Assess_Project_Risk'      => 'tools/project-management/risk/class-wp-mcp-ai-tool-assess-project-risk.php',
			'WP_MCP_AI_Tool_Detect_Stale_Tasks'       => 'tools/project-management/risk/class-wp-mcp-ai-tool-detect-stale-tasks.php',
			'WP_MCP_AI_Tool_Identify_Blockers'        => 'tools/project-management/risk/class-wp-mcp-ai-tool-identify-blockers.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}
	}

	/**
	 * The nine tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Get_Burndown_Chart'       => 'get_burndown_chart',
			'WP_MCP_AI_Tool_Get_Team_Velocity'        => 'get_team_velocity',
			'WP_MCP_AI_Tool_Get_Portfolio_Health'     => 'get_portfolio_health',
			'WP_MCP_AI_Tool_Get_Resource_Utilization' => 'get_resource_utilization',
			'WP_MCP_AI_Tool_Get_Project_Timeline'     => 'get_project_timeline',
			'WP_MCP_AI_Tool_Forecast_Completion'      => 'forecast_completion',
			'WP_MCP_AI_Tool_Assess_Project_Risk'      => 'assess_project_risk',
			'WP_MCP_AI_Tool_Detect_Stale_Tasks'       => 'detect_stale_tasks',
			'WP_MCP_AI_Tool_Identify_Blockers'        => 'identify_blockers',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The analytics tools must return their smoke envelopes on empty data
	 * (a real project feeds the project-scoped forecast).
	 */
	public function test_analytics_smoke(): void {
		WP_MCP_AI_Project_CPT::register_post_type();
		$create  = new WP_MCP_AI_Tool_Create_Project();
		$project = $create->execute( array( 'name' => 'Analytics Home' ), array( 'user_id' => 1 ) );

		$portfolio = new WP_MCP_AI_Tool_Get_Portfolio_Health();
		$result    = $portfolio->execute( array(), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );

		$velocity = new WP_MCP_AI_Tool_Get_Team_Velocity();
		$result   = $velocity->execute( array(), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $result );

		$forecast = new WP_MCP_AI_Tool_Forecast_Completion();
		$result   = $forecast->execute( array( 'project_id' => $project['project_id'] ), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $result );
	}

	/**
	 * The risk tools must run their smoke paths and keep the stale-task
	 * envelope shape.
	 */
	public function test_risk_smoke(): void {
		WP_MCP_AI_Project_CPT::register_post_type();
		$create  = new WP_MCP_AI_Tool_Create_Project();
		$project = $create->execute( array( 'name' => 'Risk Home' ), array( 'user_id' => 1 ) );

		$stale  = new WP_MCP_AI_Tool_Detect_Stale_Tasks();
		$result = $stale->execute( array( 'days' => 14 ), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'stale_tasks', $result );

		$blockers = new WP_MCP_AI_Tool_Identify_Blockers();
		$result   = $blockers->execute( array( 'project_id' => $project['project_id'] ), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $result );

		$risk   = new WP_MCP_AI_Tool_Assess_Project_Risk();
		$result = $risk->execute( array( 'project_id' => $project['project_id'] ), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $result );
	}

	/**
	 * Standalone only: the init's tool filter must carry the batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the PM tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_pm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Get_Burndown_Chart', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Identify_Blockers', $tools );
	}

	/**
	 * Standalone only: the ecosystem registration must register the batch
	 * into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/init.php';
		wp_mcp_ai_pro_register_pm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['get_burndown_chart'] ?? null );
		$this->assertNotNull( $parent->all()['assess_project_risk'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'get_portfolio_health' ) );
		$this->assertTrue( $core_tools->has( 'identify_blockers' ) );
	}
}
