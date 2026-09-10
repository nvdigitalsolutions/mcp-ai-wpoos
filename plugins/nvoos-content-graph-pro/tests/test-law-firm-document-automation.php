<?php
/**
 * Characterization tests for the Wave F4 law-firm document-automation batch —
 * the ten ported document-automation tools.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes; the byte-identical surfaces and the graceful execute()
 *   contracts are asserted (the gated map files are required
 *   deterministically in setUp).
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/law-firm/document-automation/` are asserted in full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Law-firm document-automation tests.
 */
class Test_Law_Firm_Document_Automation extends WP_UnitTestCase {

	/**
	 * Monolith matrix: require the ten gated base files deterministically.
	 * Standalone: the entry autoloader serves them on demand.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$files = array(
				'tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-document-drafter.php',
				'tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-contract-reviewer.php',
				'tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-clause-library-manager.php',
				'tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-redline-comparator.php',
				'tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-pleading-generator.php',
				'tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-discovery-request-builder.php',
				'tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-document-version-tracker.php',
				'tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-legal-citation-checker.php',
				'tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-brief-outline-generator.php',
				'tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-document-template-manager.php',
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
			'WP_MCP_AI_Tool_LF_Document_Drafter'          => 'tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-document-drafter.php',
			'WP_MCP_AI_Tool_LF_Contract_Reviewer'         => 'tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-contract-reviewer.php',
			'WP_MCP_AI_Tool_LF_Clause_Library_Manager'    => 'tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-clause-library-manager.php',
			'WP_MCP_AI_Tool_LF_Redline_Comparator'        => 'tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-redline-comparator.php',
			'WP_MCP_AI_Tool_LF_Pleading_Generator'        => 'tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-pleading-generator.php',
			'WP_MCP_AI_Tool_LF_Discovery_Request_Builder' => 'tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-discovery-request-builder.php',
			'WP_MCP_AI_Tool_LF_Document_Version_Tracker'  => 'tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-document-version-tracker.php',
			'WP_MCP_AI_Tool_LF_Legal_Citation_Checker'    => 'tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-legal-citation-checker.php',
			'WP_MCP_AI_Tool_LF_Brief_Outline_Generator'   => 'tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-brief-outline-generator.php',
			'WP_MCP_AI_Tool_LF_Document_Template_Manager' => 'tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-document-template-manager.php',
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
			'WP_MCP_AI_Tool_LF_Document_Drafter'          => 'lf_document_drafter',
			'WP_MCP_AI_Tool_LF_Contract_Reviewer'         => 'lf_contract_reviewer',
			'WP_MCP_AI_Tool_LF_Clause_Library_Manager'    => 'lf_clause_library_manager',
			'WP_MCP_AI_Tool_LF_Redline_Comparator'        => 'lf_redline_comparator',
			'WP_MCP_AI_Tool_LF_Pleading_Generator'        => 'lf_pleading_generator',
			'WP_MCP_AI_Tool_LF_Discovery_Request_Builder' => 'lf_discovery_request_builder',
			'WP_MCP_AI_Tool_LF_Document_Version_Tracker'  => 'lf_document_version_tracker',
			'WP_MCP_AI_Tool_LF_Legal_Citation_Checker'    => 'lf_legal_citation_checker',
			'WP_MCP_AI_Tool_LF_Brief_Outline_Generator'   => 'lf_brief_outline_generator',
			'WP_MCP_AI_Tool_LF_Document_Template_Manager' => 'lf_document_template_manager',
		);

		$caps = array(
			'WP_MCP_AI_Tool_LF_Document_Drafter'          => 'manage_options',
			'WP_MCP_AI_Tool_LF_Contract_Reviewer'         => 'edit_posts',
			'WP_MCP_AI_Tool_LF_Clause_Library_Manager'    => 'edit_posts',
			'WP_MCP_AI_Tool_LF_Redline_Comparator'        => 'edit_posts',
			'WP_MCP_AI_Tool_LF_Pleading_Generator'        => 'edit_posts',
			'WP_MCP_AI_Tool_LF_Discovery_Request_Builder' => 'edit_posts',
			'WP_MCP_AI_Tool_LF_Document_Version_Tracker'  => 'edit_posts',
			'WP_MCP_AI_Tool_LF_Legal_Citation_Checker'    => 'edit_posts',
			'WP_MCP_AI_Tool_LF_Brief_Outline_Generator'   => 'edit_posts',
			'WP_MCP_AI_Tool_LF_Document_Template_Manager' => 'edit_posts',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( $caps[ $class ], $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The settings-gated availability + missing-required first gate must be
	 * byte-identical.
	 */
	public function test_contract_reviewer_gates(): void {
		update_option( 'wp_mcp_ai_settings', array( 'enable_law_firm_toolkit' => true ) );

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$tool   = new WP_MCP_AI_Tool_LF_Contract_Reviewer();
		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'missing_required', $result->get_error_code() );

		delete_option( 'wp_mcp_ai_settings' );
	}
}
