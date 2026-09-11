<?php
/**
 * Characterization tests for the Wave F4 law-firm intake-management batch —
 * the eight ported client-intake tools.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces and the graceful execute()
 *   contracts are asserted (the gated map files are required
 *   deterministically in setUp).
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/law-firm/intake-management/` are asserted in full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Law-firm intake-management tests.
 */
class Test_Law_Firm_Intake_Management extends WP_UnitTestCase {

	/**
	 * Monolith matrix: require the eight gated base files deterministically.
	 * Standalone: the entry autoloader serves them on demand.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$files = array(
				'tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-client-communication-logger.php',
				'tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-client-intake-processor.php',
				'tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-client-portal-manager.php',
				'tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-client-profile-analyzer.php',
				'tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-conflict-of-interest-checker.php',
				'tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-engagement-letter-generator.php',
				'tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-lead-scoring-calculator.php',
				'tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-referral-source-tracker.php',
			);
			foreach ( $files as $file ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/' . $file;
			}
		}
	}

	/**
	 * The eight ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_LF_Client_Communication_Logger' => 'tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-client-communication-logger.php',
			'WP_MCP_AI_Tool_LF_Client_Intake_Processor' => 'tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-client-intake-processor.php',
			'WP_MCP_AI_Tool_LF_Client_Portal_Manager'   => 'tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-client-portal-manager.php',
			'WP_MCP_AI_Tool_LF_Client_Profile_Analyzer' => 'tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-client-profile-analyzer.php',
			'WP_MCP_AI_Tool_LF_Conflict_Of_Interest_Checker' => 'tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-conflict-of-interest-checker.php',
			'WP_MCP_AI_Tool_LF_Engagement_Letter_Generator' => 'tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-engagement-letter-generator.php',
			'WP_MCP_AI_Tool_LF_Lead_Scoring_Calculator' => 'tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-lead-scoring-calculator.php',
			'WP_MCP_AI_Tool_LF_Referral_Source_Tracker' => 'tools/law-firm/intake-management/class-wp-mcp-ai-tool-lf-referral-source-tracker.php',
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
			'WP_MCP_AI_Tool_LF_Client_Communication_Logger' => 'lf_client_communication_logger',
			'WP_MCP_AI_Tool_LF_Client_Intake_Processor' => 'lf_client_intake_processor',
			'WP_MCP_AI_Tool_LF_Client_Portal_Manager'   => 'lf_client_portal_manager',
			'WP_MCP_AI_Tool_LF_Client_Profile_Analyzer' => 'lf_client_profile_analyzer',
			'WP_MCP_AI_Tool_LF_Conflict_Of_Interest_Checker' => 'lf_conflict_of_interest_checker',
			'WP_MCP_AI_Tool_LF_Engagement_Letter_Generator' => 'lf_engagement_letter_generator',
			'WP_MCP_AI_Tool_LF_Lead_Scoring_Calculator' => 'lf_lead_scoring_calculator',
			'WP_MCP_AI_Tool_LF_Referral_Source_Tracker' => 'lf_referral_source_tracker',
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
	public function test_conflict_of_interest_gates(): void {
		update_option( 'wp_mcp_ai_settings', array( 'enable_law_firm_toolkit' => true ) );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$tool   = new WP_MCP_AI_Tool_LF_Conflict_Of_Interest_Checker();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_required', $result->get_error_code() );

		delete_option( 'wp_mcp_ai_settings' );
	}
}
