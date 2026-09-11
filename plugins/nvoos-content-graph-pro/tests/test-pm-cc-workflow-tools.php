<?php
/**
 * Characterization tests for the Wave F2 PM command-center + workflow tool
 * batch — the ported KPI/pipeline/deadlines/my-tasks command-center query
 * tools and the PM workflow-rule create/list/simulate tools.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool classes (classmap-autoloaded); the byte-identical surfaces and
 *   smoke contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/project-management/{command-center,workflow}/` are asserted
 *   in full, including the filter and ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * PM command-center + workflow tool batch tests.
 */
class Test_Pm_Cc_Workflow_Tools extends WP_UnitTestCase {

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
	 * The seven ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Get_PM_KPIs'               => 'tools/project-management/command-center/class-wp-mcp-ai-tool-get-pm-kpis.php',
			'WP_MCP_AI_Tool_Get_Project_Pipeline'      => 'tools/project-management/command-center/class-wp-mcp-ai-tool-get-project-pipeline.php',
			'WP_MCP_AI_Tool_Get_Upcoming_Deadlines'    => 'tools/project-management/command-center/class-wp-mcp-ai-tool-get-upcoming-deadlines.php',
			'WP_MCP_AI_Tool_Get_My_Tasks'              => 'tools/project-management/command-center/class-wp-mcp-ai-tool-get-my-tasks.php',
			'WP_MCP_AI_Tool_Create_PM_Workflow_Rule'   => 'tools/project-management/workflow/class-wp-mcp-ai-tool-create-pm-workflow-rule.php',
			'WP_MCP_AI_Tool_List_PM_Workflow_Rules'    => 'tools/project-management/workflow/class-wp-mcp-ai-tool-list-pm-workflow-rules.php',
			'WP_MCP_AI_Tool_Simulate_PM_Workflow_Rule' => 'tools/project-management/workflow/class-wp-mcp-ai-tool-simulate-pm-workflow-rule.php',
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
	 * The seven tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Get_PM_KPIs'               => 'get_pm_kpis',
			'WP_MCP_AI_Tool_Get_Project_Pipeline'      => 'get_project_pipeline',
			'WP_MCP_AI_Tool_Get_Upcoming_Deadlines'    => 'get_upcoming_deadlines',
			'WP_MCP_AI_Tool_Get_My_Tasks'              => 'get_my_tasks',
			'WP_MCP_AI_Tool_Create_PM_Workflow_Rule'   => 'create_pm_workflow_rule',
			'WP_MCP_AI_Tool_List_PM_Workflow_Rules'    => 'list_pm_workflow_rules',
			'WP_MCP_AI_Tool_Simulate_PM_Workflow_Rule' => 'simulate_pm_workflow_rule',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The command-center query tools must return their smoke envelopes.
	 */
	public function test_command_center_smoke(): void {
		$kpis   = new WP_MCP_AI_Tool_Get_PM_KPIs();
		$result = $kpis->execute( array(), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $result );

		$pipeline = new WP_MCP_AI_Tool_Get_Project_Pipeline();
		$result   = $pipeline->execute( array(), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $result );

		$deadlines = new WP_MCP_AI_Tool_Get_Upcoming_Deadlines();
		$result    = $deadlines->execute( array( 'days' => 7 ), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $result );

		$mine   = new WP_MCP_AI_Tool_Get_My_Tasks();
		$result = $mine->execute( array(), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $result );
	}

	/**
	 * The workflow-rule tools must create, list, and simulate rules.
	 *
	 * ⚠ UPSTREAM LATENT BUG pinned as-is (byte-identical — follow-up issue
	 * candidate): `sanitize_key()` strips the dots from `trigger_type`, so
	 * none of the five dotted `VALID_TRIGGER_TYPES` can ever pass the
	 * validity check — the create tool rejects every dotted trigger with
	 * `wp_mcp_ai_invalid_trigger`. The base addon behaves identically; the
	 * list/simulate flows are exercised against a directly-inserted rule.
	 */
	public function test_workflow_rule_flow(): void {
		// The PM init's enabled block registers the inline PM workflow-rule
		// CPT that the workflow engine queries during simulation.
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/project-management/init.php';

		$tool = new WP_MCP_AI_Tool_Create_PM_Workflow_Rule();

		$missing = $tool->execute( array(), array( 'user_id' => 1 ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_missing_title', $missing->get_error_code() );

		// Byte-identical sanitize_key() trigger rejection (upstream bug).
		$rejected = $tool->execute(
			array(
				'title'        => 'Auto-notify on block',
				'trigger_type' => 'task.status_changed',
			),
			array( 'user_id' => 1 )
		);
		$this->assertWPError( $rejected );
		$this->assertSame( 'wp_mcp_ai_invalid_trigger', $rejected->get_error_code() );

		// Insert a rule directly (bypassing the upstream trigger gate) so the
		// list/simulate flows have a real rule to exercise.
		$rule_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_pm_wf_rule',
				'post_title'  => 'Auto-notify on block',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $rule_id, '_pm_wf_trigger_type', 'task.status_changed' );
		update_post_meta( $rule_id, '_pm_wf_conditions', wp_json_encode( array() ) );
		update_post_meta( $rule_id, '_pm_wf_actions', wp_json_encode( array() ) );

		$list   = new WP_MCP_AI_Tool_List_PM_Workflow_Rules();
		$listed = $list->execute( array(), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $listed );
		$this->assertNotEmpty( $listed['rules'] ?? array() );

		$simulate = new WP_MCP_AI_Tool_Simulate_PM_Workflow_Rule();
		$sim      = $simulate->execute( array( 'rule_id' => $rule_id ), array( 'user_id' => 1 ) );
		$this->assertNotWPError( $sim );
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
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Get_PM_KPIs', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Simulate_PM_Workflow_Rule', $tools );
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
		$this->assertNotNull( $parent->all()['get_pm_kpis'] ?? null );
		$this->assertNotNull( $parent->all()['create_pm_workflow_rule'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'get_my_tasks' ) );
		$this->assertTrue( $core_tools->has( 'simulate_pm_workflow_rule' ) );
	}
}
