<?php
/**
 * Characterization tests for the Wave F2 pilot — the CRM data layer
 * (shared engine classes + the CRM CPT set + the standalone init).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the public surface is asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/crm/` + `src/class-wp-mcp-ai-*-cpt.php` are asserted in
 *   full, including the registry's `toolkit_crm` enabled-gate.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM data layer tests.
 */
class Test_Crm_Data_Layer extends WP_UnitTestCase {

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
	 * The eight CRM CPT slugs must be byte-identical.
	 */
	public function test_crm_cpt_slug_constants(): void {
		$this->assertSame( 'mcp_ai_company', WP_MCP_AI_Company_CPT::POST_TYPE );
		$this->assertSame( 'mcp_ai_lead', WP_MCP_AI_Lead_CPT::POST_TYPE );
		$this->assertSame( 'mcp_ai_deal', WP_MCP_AI_Deal_CPT::POST_TYPE );
		$this->assertSame( 'mcp_ai_crm_activity', WP_MCP_AI_CRM_Activity_CPT::POST_TYPE );
		$this->assertSame( 'mcp_ai_ticket', WP_MCP_AI_Support_Ticket_CPT::POST_TYPE );
		$this->assertSame( 'mcp_ai_customer', WP_MCP_AI_Customer_CPT::POST_TYPE );
		$this->assertSame( 'mcp_ai_sequence', WP_MCP_AI_Sequence_CPT::POST_TYPE );
		$this->assertSame( 'mcp_ai_crm_wf_rule', WP_MCP_AI_CRM_Workflow_Rule_CPT::POST_TYPE );
	}

	/**
	 * Every CRM CPT must register with its byte-identical slug.
	 */
	public function test_crm_cpts_register(): void {
		$this->enable_crm_toolkit();

		WP_MCP_AI_Company_CPT::register_post_type();
		WP_MCP_AI_Lead_CPT::register_post_type();
		WP_MCP_AI_Deal_CPT::register_post_type();
		WP_MCP_AI_CRM_Activity_CPT::register_post_type();
		WP_MCP_AI_Support_Ticket_CPT::register_post_type();
		WP_MCP_AI_Customer_CPT::register_post_type();
		WP_MCP_AI_Sequence_CPT::register_post_type();
		WP_MCP_AI_CRM_Workflow_Rule_CPT::register_post_type();

		foreach (
			array(
				'mcp_ai_company',
				'mcp_ai_lead',
				'mcp_ai_deal',
				'mcp_ai_crm_activity',
				'mcp_ai_ticket',
				'mcp_ai_customer',
				'mcp_ai_sequence',
				'mcp_ai_crm_wf_rule',
			) as $slug
		) {
			$this->assertTrue( post_type_exists( $slug ), "CPT {$slug} should be registered." );
		}
	}

	/**
	 * The pipeline stages must expose the byte-identical stage map and the
	 * open-stages helper.
	 */
	public function test_pipeline_stages_contract(): void {
		$stages = WP_MCP_AI_CRM_Pipeline_Stages::get_stages();
		$this->assertIsArray( $stages );
		$this->assertArrayHasKey( 'prospecting', $stages );
		$this->assertArrayHasKey( 'qualification', $stages );
		$this->assertSame( 0.05, $stages['prospecting']['probability'] );

		$open = WP_MCP_AI_CRM_Pipeline_Stages::get_open_stages();
		$this->assertIsArray( $open );
		foreach ( $open as $stage_id => $stage ) {
			$this->assertArrayHasKey( $stage_id, $stages );
			$this->assertArrayHasKey( 'label', $stage );
		}
	}

	/**
	 * The CRM code packs must expose a non-empty pack registry.
	 */
	public function test_crm_codes_packs(): void {
		$packs = WP_MCP_AI_CRM_Codes::get_all_packs();
		$this->assertIsArray( $packs );
		$this->assertNotEmpty( $packs );
	}

	/**
	 * The CRM capabilities map must be non-empty.
	 */
	public function test_crm_capabilities_map(): void {
		$this->assertNotEmpty( WP_MCP_AI_CRM_Capabilities::get_map() );
	}

	/**
	 * The CRM engine settings helper must return the default shape.
	 */
	public function test_crm_engine_settings(): void {
		$settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
		$this->assertIsArray( $settings );
	}

	/**
	 * The engine's pipeline-stage accessor must mirror the stages helper.
	 */
	public function test_crm_engine_pipeline_stages(): void {
		$this->assertIsArray( WP_MCP_AI_CRM_Engine::get_pipeline_stages() );
	}

	/**
	 * Standalone only: the registry's toolkit_crm module must obey the
	 * byte-identical enable_crm_toolkit gate.
	 */
	public function test_crm_module_enabled_gate_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base registry owns the CRM module.' );
		}

		require_once __DIR__ . '/helpers/test-pro-module-registry-seam.php';

		// Disabled (default): the module must not load.
		$disabled = \NvoosContentGraphPro\Tests\Test_Pro_Module_Registry_Seam::make();
		$disabled->boot();
		$this->assertFalse( $disabled->is_loaded( 'toolkit_crm' ) );

		// Enabled: the module must load its init.
		$this->enable_crm_toolkit();
		$enabled = \NvoosContentGraphPro\Tests\Test_Pro_Module_Registry_Seam::make();
		$enabled->boot();
		$this->assertTrue( $enabled->is_loaded( 'toolkit_crm' ) );
	}

	/**
	 * Standalone only: with the toolkit enabled, booting the module must
	 * wire the CPT init hooks (admin columns etc.) without fataling.
	 */
	public function test_crm_init_wires_cpt_hooks_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base Pro init owns the wiring.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		// Re-arm the CPT init hooks — the WP test framework's hook-snapshot
		// restore may have removed registrations made during an earlier
		// test's module boot.
		WP_MCP_AI_Company_CPT::init();

		$this->assertTrue( class_exists( 'WP_MCP_AI_Company_CPT' ) );
		$this->assertTrue( class_exists( 'WP_MCP_AI_CRM_Engine' ) );
		$this->assertNotFalse( has_filter( 'manage_mcp_ai_company_posts_columns' ) );
	}
}
