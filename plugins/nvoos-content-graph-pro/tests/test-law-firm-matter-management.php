<?php
/**
 * Characterization tests for the Wave F4 law-firm matter-management batch —
 * the ten ported matter-management tools.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces and the graceful execute()
 *   contracts are asserted (the gated map files are required
 *   deterministically in setUp).
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/law-firm/matter-management/` are asserted in full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Law-firm matter-management tests.
 */
class Test_Law_Firm_Matter_Management extends WP_UnitTestCase {

	/**
	 * Monolith matrix: require the ten gated base files deterministically.
	 * Standalone: the entry autoloader serves them on demand.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$files = array(
				'tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-calendar-rule-calculator.php',
				'tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-case-outcome-predictor.php',
				'tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-case-status-dashboard.php',
				'tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-case-timeline-generator.php',
				'tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-court-deadline-tracker.php',
				'tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-matter-budget-manager.php',
				'tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-matter-pipeline-manager.php',
				'tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-opposing-counsel-tracker.php',
				'tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-statute-of-limitations-calculator.php',
				'tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-task-assignment-manager.php',
			);
			foreach ( $files as $file ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/' . $file;
			}
		}
	}

	/**
	 * The ten ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_LF_Calendar_Rule_Calculator' => 'tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-calendar-rule-calculator.php',
			'WP_MCP_AI_Tool_LF_Case_Outcome_Predictor'   => 'tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-case-outcome-predictor.php',
			'WP_MCP_AI_Tool_LF_Case_Status_Dashboard'    => 'tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-case-status-dashboard.php',
			'WP_MCP_AI_Tool_LF_Case_Timeline_Generator'  => 'tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-case-timeline-generator.php',
			'WP_MCP_AI_Tool_LF_Court_Deadline_Tracker'   => 'tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-court-deadline-tracker.php',
			'WP_MCP_AI_Tool_LF_Matter_Budget_Manager'    => 'tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-matter-budget-manager.php',
			'WP_MCP_AI_Tool_LF_Matter_Pipeline_Manager'  => 'tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-matter-pipeline-manager.php',
			'WP_MCP_AI_Tool_LF_Opposing_Counsel_Tracker' => 'tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-opposing-counsel-tracker.php',
			'WP_MCP_AI_Tool_LF_Statute_Of_Limitations_Calculator' => 'tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-statute-of-limitations-calculator.php',
			'WP_MCP_AI_Tool_LF_Task_Assignment_Manager'  => 'tools/law-firm/matter-management/class-wp-mcp-ai-tool-lf-task-assignment-manager.php',
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
	 * The tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_LF_Calendar_Rule_Calculator' => 'lf_calendar_rule_calculator',
			'WP_MCP_AI_Tool_LF_Case_Outcome_Predictor'   => 'lf_case_outcome_predictor',
			'WP_MCP_AI_Tool_LF_Case_Status_Dashboard'    => 'lf_case_status_dashboard',
			'WP_MCP_AI_Tool_LF_Case_Timeline_Generator'  => 'lf_case_timeline_generator',
			'WP_MCP_AI_Tool_LF_Court_Deadline_Tracker'   => 'lf_court_deadline_tracker',
			'WP_MCP_AI_Tool_LF_Matter_Budget_Manager'    => 'lf_matter_budget_manager',
			'WP_MCP_AI_Tool_LF_Matter_Pipeline_Manager'  => 'lf_matter_pipeline_manager',
			'WP_MCP_AI_Tool_LF_Opposing_Counsel_Tracker' => 'lf_opposing_counsel_tracker',
			'WP_MCP_AI_Tool_LF_Statute_Of_Limitations_Calculator' => 'lf_statute_of_limitations_calculator',
			'WP_MCP_AI_Tool_LF_Task_Assignment_Manager'  => 'lf_task_assignment_manager',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The settings-gated availability + missing-required first gate must be
	 * byte-identical.
	 */
	public function test_calendar_rule_gates(): void {
		update_option( 'wp_mcp_ai_settings', array( 'enable_law_firm_toolkit' => true ) );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$tool   = new WP_MCP_AI_Tool_LF_Calendar_Rule_Calculator();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_required', $result->get_error_code() );

		delete_option( 'wp_mcp_ai_settings' );
	}
}
