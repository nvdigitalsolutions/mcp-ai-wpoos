<?php
/**
 * Characterization tests for the Wave F2 financial-planning data layer —
 * the financial-account CPT and the slim standalone init (which the base
 * tree ships but nothing loads — byte-identical dormancy).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the CPT
 *   class (classmap-autoloaded); the byte-identical contracts are asserted.
 * - Standalone matrix (base plugin absent): the ported copy in `src/` is
 *   asserted in full, including the init's enabled gate and the
 *   standalone-only registry module.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Financial data-layer tests.
 */
class Test_Financial_Data_Layer extends WP_UnitTestCase {

	/**
	 * Enable the financial planner toolkit for the init gate.
	 */
	public function setUp(): void {
		parent::setUp();
		$settings                                     = get_option( 'wp_mcp_ai_settings', array() );
		$settings                                     = is_array( $settings ) ? $settings : array();
		$settings['enable_financial_planner_toolkit'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * The ported symbol must follow the ownership boundary.
	 */
	public function test_serving_source(): void {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Financial_Account_CPT' );
		$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'addons/pro/includes/class-wp-mcp-ai-financial-account-cpt.php', $path );
		} else {
			$this->assertStringContainsString( 'nvoos-content-graph-pro/src/class-wp-mcp-ai-financial-account-cpt.php', $path );
		}
	}

	/**
	 * The CPT contracts must be byte-identical.
	 */
	public function test_cpt_contracts(): void {
		$this->assertSame( 'mcp_ai_fin_account', WP_MCP_AI_Financial_Account_CPT::POST_TYPE );

		WP_MCP_AI_Financial_Account_CPT::init();
		$this->assertNotFalse( has_action( 'init', array( 'WP_MCP_AI_Financial_Account_CPT', 'register_post_type' ) ) );
		$this->assertNotFalse( has_action( 'init', array( 'WP_MCP_AI_Financial_Account_CPT', 'register_taxonomies' ) ) );
		$this->assertNotFalse( has_action( 'add_meta_boxes', array( 'WP_MCP_AI_Financial_Account_CPT', 'add_meta_boxes' ) ) );

		WP_MCP_AI_Financial_Account_CPT::register_post_type();
		WP_MCP_AI_Financial_Account_CPT::register_taxonomies();
		$this->assertTrue( post_type_exists( 'mcp_ai_fin_account' ) );
		$this->assertTrue( taxonomy_exists( 'mcp_ai_account_type' ) );
	}

	/**
	 * Standalone only: the slim init's enabled gate must load the CPT and
	 * expose the standalone-only tool wiring.
	 */
	public function test_init_gate_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base financial init is tree-dormant.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/init.php';

		// The CPT require ran inside the enabled gate (setting enabled in
		// setUp), so the class resolves from the addon's src tree.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Financial_Account_CPT' );
		$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
		$this->assertStringContainsString( 'nvoos-content-graph-pro/src/class-wp-mcp-ai-financial-account-cpt.php', $path );

		// The standalone-only tool wiring functions must exist.
		$this->assertTrue( function_exists( 'wp_mcp_ai_pro_register_financial_tools' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_pro_register_financial_ecosystem_tools' ) );

		// The filter must be inert-but-present.
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_financial_tools', 10 );
		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertIsArray( $tools );
	}

	/**
	 * Standalone only: the registry must define the standalone-only
	 * `toolkit_financial_planning` module.
	 */
	public function test_registry_module_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base registry has no financial module.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-module-registry.php';

		$registry = WP_MCP_AI_Pro_Module_Registry::get_instance();
		$registry->boot();
		$this->assertArrayHasKey( 'toolkit_financial_planning', $registry->get_modules() );

		// The module's files guard must be satisfied by the ported init, so
		// boot() loads it standalone.
		$this->assertTrue( $registry->is_loaded( 'toolkit_financial_planning' ) );
	}
}
