<?php
/**
 * Characterization tests for the Wave F4 law-firm litigation-support batch —
 * the eight ported litigation-support tools.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces and the graceful execute()
 *   contracts are asserted (the gated map files are required
 *   deterministically in setUp).
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/law-firm/litigation-support/` are asserted in full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Law-firm litigation-support tests.
 */
class Test_Law_Firm_Litigation_Support extends WP_UnitTestCase {

	/**
	 * Monolith matrix: require the eight gated base files deterministically.
	 * Standalone: the entry autoloader serves them on demand.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$files = array(
				'tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-damages-calculator.php',
				'tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-deposition-summary-generator.php',
				'tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-ediscovery-document-analyzer.php',
				'tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-evidence-catalog-manager.php',
				'tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-expert-witness-tracker.php',
				'tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-jury-instruction-drafter.php',
				'tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-settlement-value-calculator.php',
				'tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-trial-preparation-checklist.php',
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
			'WP_MCP_AI_Tool_LF_Ediscovery_Document_Analyzer' => 'tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-ediscovery-document-analyzer.php',
			'WP_MCP_AI_Tool_LF_Deposition_Summary_Generator' => 'tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-deposition-summary-generator.php',
			'WP_MCP_AI_Tool_LF_Evidence_Catalog_Manager' => 'tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-evidence-catalog-manager.php',
			'WP_MCP_AI_Tool_LF_Jury_Instruction_Drafter' => 'tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-jury-instruction-drafter.php',
			'WP_MCP_AI_Tool_LF_Settlement_Value_Calculator' => 'tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-settlement-value-calculator.php',
			'WP_MCP_AI_Tool_LF_Damages_Calculator'       => 'tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-damages-calculator.php',
			'WP_MCP_AI_Tool_LF_Expert_Witness_Tracker'   => 'tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-expert-witness-tracker.php',
			'WP_MCP_AI_Tool_LF_Trial_Preparation_Checklist' => 'tools/law-firm/litigation-support/class-wp-mcp-ai-tool-lf-trial-preparation-checklist.php',
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
			'WP_MCP_AI_Tool_LF_Ediscovery_Document_Analyzer' => 'lf_ediscovery_document_analyzer',
			'WP_MCP_AI_Tool_LF_Deposition_Summary_Generator' => 'lf_deposition_summary_generator',
			'WP_MCP_AI_Tool_LF_Evidence_Catalog_Manager' => 'lf_evidence_catalog_manager',
			'WP_MCP_AI_Tool_LF_Jury_Instruction_Drafter' => 'lf_jury_instruction_drafter',
			'WP_MCP_AI_Tool_LF_Settlement_Value_Calculator' => 'lf_settlement_value_calculator',
			'WP_MCP_AI_Tool_LF_Damages_Calculator'       => 'lf_damages_calculator',
			'WP_MCP_AI_Tool_LF_Expert_Witness_Tracker'   => 'lf_expert_witness_tracker',
			'WP_MCP_AI_Tool_LF_Trial_Preparation_Checklist' => 'lf_trial_preparation_checklist',
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
	public function test_deposition_summary_gates(): void {
		update_option( 'wp_mcp_ai_settings', array( 'enable_law_firm_toolkit' => true ) );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$tool   = new WP_MCP_AI_Tool_LF_Deposition_Summary_Generator();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_required', $result->get_error_code() );

		delete_option( 'wp_mcp_ai_settings' );
	}
}
