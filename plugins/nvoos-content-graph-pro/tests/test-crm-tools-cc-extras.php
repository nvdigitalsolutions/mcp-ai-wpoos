<?php
/**
 * Characterization tests for the Wave F2 CRM CC-page extras batch — the
 * ported tools referenced by the command-center page (import-gmail-to-crm,
 * import/list/sync upwork, import/save/score/search linkedin) plus the
 * `WP_MCP_AI_LinkedIn_Client`.
 *
 * These files exist in the base tree but are NOT part of the monolith's
 * `$crm_tools` map — the standalone filter/ecosystem registrations carry
 * them (documented deviation 5 in the CRM init).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surface is
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies are
 *   asserted in full, including the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM CC-page extras batch tests.
 */
class Test_Crm_Tools_Cc_Extras extends WP_UnitTestCase {

	/**
	 * Snapshot of the shared settings option before mutation.
	 *
	 * @var mixed
	 */
	private $settings_snapshot = null;

	/**
	 * Snapshot the shared settings option.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->settings_snapshot = get_option( 'wp_mcp_ai_settings', null );
	}

	/**
	 * Restore the shared settings option.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		if ( null === $this->settings_snapshot ) {
			delete_option( 'wp_mcp_ai_settings' );
		} else {
			update_option( 'wp_mcp_ai_settings', $this->settings_snapshot );
		}
		parent::tearDown();
	}

	/**
	 * Enable the CRM toolkit flag in the shared settings option.
	 *
	 * @return void
	 */
	private function enable_crm_toolkit(): void {
		$settings                       = get_option( 'wp_mcp_ai_settings', array() );
		$settings                       = is_array( $settings ) ? $settings : array();
		$settings['enable_crm_toolkit'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * The nine ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Import_Gmail_To_CRM'     => 'tools/crm/inbound/class-wp-mcp-ai-tool-import-gmail-to-crm.php',
			'WP_MCP_AI_Tool_Import_Upwork_Project'   => 'tools/crm/upwork/class-wp-mcp-ai-tool-import-upwork-project.php',
			'WP_MCP_AI_Tool_List_Upwork_Contracts'   => 'tools/crm/upwork/class-wp-mcp-ai-tool-list-upwork-contracts.php',
			'WP_MCP_AI_Tool_Sync_Upwork_Tasks'       => 'tools/crm/upwork/class-wp-mcp-ai-tool-sync-upwork-tasks.php',
			'WP_MCP_AI_Tool_Import_Linkedin_Profile' => 'tools/crm/linkedin/class-wp-mcp-ai-tool-import-linkedin-profile.php',
			'WP_MCP_AI_Tool_Save_Linkedin_Job'       => 'tools/crm/linkedin/class-wp-mcp-ai-tool-save-linkedin-job.php',
			'WP_MCP_AI_Tool_Score_Linkedin_Job'      => 'tools/crm/linkedin/class-wp-mcp-ai-tool-score-linkedin-job.php',
			'WP_MCP_AI_Tool_Search_Linkedin_Jobs'    => 'tools/crm/linkedin/class-wp-mcp-ai-tool-search-linkedin-jobs.php',
			'WP_MCP_AI_LinkedIn_Client'              => 'class-wp-mcp-ai-linkedin-client.php',
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
	 * The eight tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Import_Gmail_To_CRM'     => 'import_gmail_to_crm',
			'WP_MCP_AI_Tool_Import_Upwork_Project'   => 'import_upwork_project',
			'WP_MCP_AI_Tool_List_Upwork_Contracts'   => 'list_upwork_contracts',
			'WP_MCP_AI_Tool_Sync_Upwork_Tasks'       => 'sync_upwork_tasks',
			'WP_MCP_AI_Tool_Import_Linkedin_Profile' => 'import_linkedin_profile',
			'WP_MCP_AI_Tool_Save_Linkedin_Job'       => 'save_linkedin_job',
			'WP_MCP_AI_Tool_Score_Linkedin_Job'      => 'score_linkedin_job',
			'WP_MCP_AI_Tool_Search_Linkedin_Jobs'    => 'search_linkedin_jobs',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * Standalone only: the init's tool filter must carry the CC-page extras.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the CRM tool map inline.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Import_Gmail_To_CRM', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Import_Upwork_Project', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Search_Linkedin_Jobs', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/inbound/class-wp-mcp-ai-tool-import-gmail-to-crm.php',
			$tools['WP_MCP_AI_Tool_Import_Gmail_To_CRM']
		);
	}

	/**
	 * Standalone only: the ecosystem registration must register the
	 * CC-page extras into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		wp_mcp_ai_pro_register_crm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['import_gmail_to_crm'] ?? null );
		$this->assertNotNull( $parent->all()['search_linkedin_jobs'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'import_gmail_to_crm' ) );
		$this->assertTrue( $core_tools->has( 'import_upwork_project' ) );
		$this->assertTrue( $core_tools->has( 'list_upwork_contracts' ) );
		$this->assertTrue( $core_tools->has( 'sync_upwork_tasks' ) );
		$this->assertTrue( $core_tools->has( 'import_linkedin_profile' ) );
		$this->assertTrue( $core_tools->has( 'save_linkedin_job' ) );
		$this->assertTrue( $core_tools->has( 'score_linkedin_job' ) );
		$this->assertTrue( $core_tools->has( 'search_linkedin_jobs' ) );
	}
}
