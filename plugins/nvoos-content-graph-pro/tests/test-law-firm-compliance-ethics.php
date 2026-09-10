<?php
/**
 * Characterization tests for the Wave F4 law-firm compliance-ethics batch —
 * the eight ported compliance & ethics tools.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces and the graceful execute()
 *   contracts are asserted (the gated map files are required
 *   deterministically in setUp).
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/law-firm/compliance-ethics/` are asserted in full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Law-firm compliance-ethics tests.
 */
class Test_Law_Firm_Compliance_Ethics extends WP_UnitTestCase {

	/**
	 * Monolith matrix: require the eight gated base files deterministically.
	 * Standalone: the entry autoloader serves them on demand.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$files = array(
				'tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-ethics-rule-checker.php',
				'tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-bar-deadline-monitor.php',
				'tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-cle-credit-tracker.php',
				'tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-malpractice-risk-scorer.php',
				'tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-data-privacy-compliance-checker.php',
				'tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-client-confidentiality-auditor.php',
				'tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-regulatory-change-monitor.php',
				'tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-ai-usage-disclosure-generator.php',
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
			'WP_MCP_AI_Tool_LF_Ethics_Rule_Checker'       => 'tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-ethics-rule-checker.php',
			'WP_MCP_AI_Tool_LF_Bar_Deadline_Monitor'      => 'tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-bar-deadline-monitor.php',
			'WP_MCP_AI_Tool_LF_CLE_Credit_Tracker'        => 'tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-cle-credit-tracker.php',
			'WP_MCP_AI_Tool_LF_Malpractice_Risk_Scorer'   => 'tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-malpractice-risk-scorer.php',
			'WP_MCP_AI_Tool_LF_Data_Privacy_Compliance_Checker' => 'tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-data-privacy-compliance-checker.php',
			'WP_MCP_AI_Tool_LF_Client_Confidentiality_Auditor' => 'tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-client-confidentiality-auditor.php',
			'WP_MCP_AI_Tool_LF_Regulatory_Change_Monitor' => 'tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-regulatory-change-monitor.php',
			'WP_MCP_AI_Tool_LF_AI_Usage_Disclosure_Generator' => 'tools/law-firm/compliance-ethics/class-wp-mcp-ai-tool-lf-ai-usage-disclosure-generator.php',
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
			'WP_MCP_AI_Tool_LF_Ethics_Rule_Checker'       => 'lf_ethics_rule_checker',
			'WP_MCP_AI_Tool_LF_Bar_Deadline_Monitor'      => 'lf_bar_deadline_monitor',
			'WP_MCP_AI_Tool_LF_CLE_Credit_Tracker'        => 'lf_cle_credit_tracker',
			'WP_MCP_AI_Tool_LF_Malpractice_Risk_Scorer'   => 'lf_malpractice_risk_scorer',
			'WP_MCP_AI_Tool_LF_Data_Privacy_Compliance_Checker' => 'lf_data_privacy_compliance_checker',
			'WP_MCP_AI_Tool_LF_Client_Confidentiality_Auditor' => 'lf_client_confidentiality_auditor',
			'WP_MCP_AI_Tool_LF_Regulatory_Change_Monitor' => 'lf_regulatory_change_monitor',
			'WP_MCP_AI_Tool_LF_AI_Usage_Disclosure_Generator' => 'lf_ai_usage_disclosure_generator',
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
	public function test_ethics_rule_gates(): void {
		update_option( 'wp_mcp_ai_settings', array( 'enable_law_firm_toolkit' => true ) );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$tool   = new WP_MCP_AI_Tool_LF_Ethics_Rule_Checker();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_required', $result->get_error_code() );

		delete_option( 'wp_mcp_ai_settings' );
	}
}
