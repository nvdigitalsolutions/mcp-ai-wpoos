<?php
/**
 * Characterization tests for the Wave F2 CRM companies tool batch — the
 * ported `WP_MCP_AI_Tool_Get_Companies`, `WP_MCP_AI_Tool_Research_Company`,
 * and `WP_MCP_AI_Tool_Archive_Stale_Contacts` tools plus their registration
 * wiring.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the tool
 *   classes (classmap-autoloaded); the byte-identical surface and the
 *   local-only execute() flows are asserted against the base CPTs.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/crm/` are asserted in full. `WP_MCP_AI_Tool_Research_Company`
 *   degrades standalone — the base Web Search tool is a D8
 *   forward-reference, so its `is_available()`/execute-unavailable path is
 *   the asserted contract.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM companies tool batch tests.
 */
class Test_Crm_Tools_Companies extends WP_UnitTestCase {

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
	 * Register a CRM CPT for the active matrix.
	 *
	 * @param string $class  CPT class name (e.g. WP_MCP_AI_Company_CPT).
	 * @param string $slug   CPT class file slug (e.g. company).
	 * @return void
	 */
	private function register_cpt( $class, $slug ): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/class-wp-mcp-ai-' . $slug . '-cpt.php';
		} else {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-' . $slug . '-cpt.php';
		}
		$class::register_post_type();
	}

	/**
	 * The Get Companies tool surface must be byte-identical.
	 */
	public function test_get_companies_tool_surface(): void {
		$tool = new WP_MCP_AI_Tool_Get_Companies();

		$this->assertSame( 'get_companies', $tool->get_slug() );
		$this->assertSame( 'Get Companies', $tool->get_name() );
		$this->assertSame( 'edit_posts', $tool->get_required_capability() );

		$schema = $tool->get_parameters_schema();
		$this->assertArrayHasKey( 'search', $schema['properties'] );
		$this->assertArrayHasKey( 'industry', $schema['properties'] );
		$this->assertSame( 100, $schema['properties']['per_page']['maximum'] );

		$this->assertSame( 'crm', $tool->get_definition()['toolkit'] );
		$this->assertSame( 'info', $tool->get_definition()['risk_level'] );
		$this->assertContains( 'read-only', $tool->get_capability_flags() );
	}

	/**
	 * The Get Companies execute() flow lists companies and applies the
	 * industry filter in both matrices.
	 */
	public function test_get_companies_execute_filters(): void {
		$this->enable_crm_toolkit();
		$this->register_cpt( 'WP_MCP_AI_Company_CPT', 'company' );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tech = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_company',
				'post_status' => 'publish',
				'post_title'  => 'Tech Corp',
			)
		);
		update_post_meta( $tech, '_company_industry', 'Technology' );
		$maker = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_company',
				'post_status' => 'publish',
				'post_title'  => 'Maker Inc',
			)
		);
		update_post_meta( $maker, '_company_industry', 'Manufacturing' );

		$tool = new WP_MCP_AI_Tool_Get_Companies();

		$all = $tool->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertIsArray( $all );
		$this->assertTrue( $all['success'] );
		$this->assertSame( 2, $all['total_companies'] );
		$this->assertCount( 2, $all['companies'] );

		$filtered = $tool->execute( array( 'industry' => 'Manufacturing' ), array( 'user_id' => $user_id ) );
		$this->assertSame( 1, $filtered['total_companies'] );
		$this->assertSame( 'Maker Inc', $filtered['companies'][0]['company_name'] );
		$this->assertSame( 'Manufacturing', $filtered['companies'][0]['industry'] );
	}

	/**
	 * The Research Company tool surface must be byte-identical.
	 */
	public function test_research_company_tool_surface(): void {
		$tool = new WP_MCP_AI_Tool_Research_Company();

		$this->assertSame( 'research_company', $tool->get_slug() );
		$this->assertSame( 'Research Company', $tool->get_name() );
		$this->assertSame(
			array( 'general', 'target_fit', 'industry_analysis', 'competition' ),
			$tool->get_parameters_schema()['properties']['research_focus']['enum']
		);
		$this->assertContains( 'requires-capability', $tool->get_capability_flags() );
	}

	/**
	 * The Research Company availability must follow the per-mode contract:
	 * web-search-backed monolith, degraded standalone (D8 forward-reference).
	 */
	public function test_research_company_availability(): void {
		$this->register_cpt( 'WP_MCP_AI_Company_CPT', 'company' );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base Web Search tool is loaded, so the tool is
			// available once the Company CPT is registered.
			$this->assertTrue( WP_MCP_AI_Tool_Research_Company::is_available() );
		} else {
			// Standalone: the base Web Search tool is a D8 forward-reference.
			$this->assertFalse( WP_MCP_AI_Tool_Research_Company::is_available() );
			$this->assertStringContainsString(
				'Web Search',
				WP_MCP_AI_Tool_Research_Company::get_unavailable_reason()
			);

			// The execute() guard must degrade to a WP_Error without ever
			// touching the web search machinery.
			$result = ( new WP_MCP_AI_Tool_Research_Company() )->execute(
				array( 'company_name' => 'Acme' ),
				array( 'user_id' => 1 )
			);
			$this->assertInstanceOf( 'WP_Error', $result );
			$this->assertSame( 'wp_mcp_ai_tool_unavailable', $result->get_error_code() );
		}
	}

	/**
	 * The Archive Stale Contacts tool surface must be byte-identical.
	 */
	public function test_archive_stale_contacts_tool_surface(): void {
		$tool = new WP_MCP_AI_Tool_Archive_Stale_Contacts();

		$this->assertSame( 'archive_stale_contacts', $tool->get_slug() );
		$this->assertSame( 'edit_posts', $tool->get_required_capability() );
		$this->assertSame( 'caution', $tool->get_definition()['risk_level'] );
		$this->assertSame(
			array( 'lead', 'customer', 'all' ),
			$tool->get_parameters_schema()['properties']['contact_type']['enum']
		);
		$this->assertContains( 'state-changing', $tool->get_capability_flags() );
	}

	/**
	 * The Archive Stale Contacts execute() flow archives stale leads only
	 * when dry_run is false, and previews otherwise.
	 */
	public function test_archive_stale_contacts_execute(): void {
		$this->enable_crm_toolkit();
		$this->register_cpt( 'WP_MCP_AI_Lead_CPT', 'lead' );
		$this->register_cpt( 'WP_MCP_AI_CRM_Activity_CPT', 'crm-activity' );

		$stale_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_status' => 'publish',
				'post_title'  => 'Old Lead',
				'post_date'   => '2020-01-01 00:00:00',
			)
		);
		$fresh_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_status' => 'publish',
				'post_title'  => 'Fresh Lead',
			)
		);

		$tool = new WP_MCP_AI_Tool_Archive_Stale_Contacts();

		$preview = $tool->execute(
			array(
				'contact_type' => 'lead',
				'dry_run'      => true,
			),
			array( 'user_id' => 1 )
		);
		$this->assertIsArray( $preview );
		$this->assertTrue( $preview['success'] );
		$this->assertTrue( $preview['dry_run'] );
		$this->assertSame( 1, $preview['archived_count'] );
		$this->assertSame( 'publish', get_post_status( $stale_id ) );

		$live = $tool->execute(
			array(
				'contact_type' => 'lead',
				'dry_run'      => false,
			),
			array( 'user_id' => 1 )
		);
		$this->assertTrue( $live['success'] );
		$this->assertFalse( $live['dry_run'] );
		$this->assertSame( 'draft', get_post_status( $stale_id ) );
		$this->assertNotEmpty( get_post_meta( $stale_id, '_archived_date', true ) );
		$this->assertSame( 'publish', get_post_status( $fresh_id ) );
	}

	/**
	 * Standalone only: the init's tool filter must carry the whole ported
	 * companies batch.
	 */
	public function test_crm_tools_filter_carries_batch_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the CRM tool map inline.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Get_Companies', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Research_Company', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Archive_Stale_Contacts', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/class-wp-mcp-ai-tool-get-companies.php',
			$tools['WP_MCP_AI_Tool_Get_Companies']
		);
	}

	/**
	 * Standalone only: the ecosystem registration must register the whole
	 * batch into both registries.
	 */
	public function test_ecosystem_registration_batch_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		wp_mcp_ai_pro_register_crm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['get_companies'] ?? null );
		$this->assertNotNull( $parent->all()['research_company'] ?? null );
		$this->assertNotNull( $parent->all()['archive_stale_contacts'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'get_companies' ) );
		$this->assertTrue( $core_tools->has( 'research_company' ) );
		$this->assertTrue( $core_tools->has( 'archive_stale_contacts' ) );
	}
}
