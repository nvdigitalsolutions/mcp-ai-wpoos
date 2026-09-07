<?php
/**
 * Characterization tests for the Wave F2 D8-compat proof pair — the first
 * ported CRM tool (`WP_MCP_AI_Tool_Create_Company`) plus its standalone
 * ecosystem registration wiring.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the tool
 *   class (classmap-autoloaded); the byte-identical surface and the
 *   execute() flow are asserted against the base Company CPT.
 * - Standalone matrix (base plugin absent): the ported copy in
 *   `src/tools/crm/` is asserted in full — serving source, the
 *   `wp_mcp_ai_pro_tools` filter subset, and the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM tool proof tests.
 */
class Test_Crm_Tool_Proof extends WP_UnitTestCase {

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
	 * Register the Company CPT for the active matrix.
	 *
	 * @return void
	 */
	private function register_company_cpt(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/class-wp-mcp-ai-company-cpt.php';
		} else {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-company-cpt.php';
		}
		WP_MCP_AI_Company_CPT::register_post_type();
	}

	/**
	 * The tool surface must be byte-identical: slug, capability, parameter
	 * schema, definition metadata, and capability flags.
	 */
	public function test_create_company_tool_surface(): void {
		$tool = new WP_MCP_AI_Tool_Create_Company();

		$this->assertSame( 'create_company', $tool->get_slug() );
		$this->assertSame( 'Create Company', $tool->get_name() );
		$this->assertSame( 'edit_posts', $tool->get_required_capability() );

		$schema = $tool->get_parameters_schema();
		$this->assertSame( array( 'company_name', 'industry' ), $schema['required'] );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'company_name', $schema['properties'] );
		$this->assertSame(
			array( 'prospect', 'target', 'in_discussion', 'client', 'not_interested' ),
			$schema['properties']['target_status']['enum']
		);

		$definition = $tool->get_definition();
		$this->assertSame( 'crm', $definition['toolkit'] );
		$this->assertSame( 'standard', $definition['risk_level'] );
		$this->assertSame( 'Create Company', $definition['name'] );

		$this->assertContains( 'modifies-data', $tool->get_capability_flags() );
		$this->assertContains( 'requires-capability', $tool->get_capability_flags() );
	}

	/**
	 * The serving source must follow the ownership boundary.
	 */
	public function test_tool_serving_source(): void {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Create_Company' );
		$file       = str_replace( '\\', '/', (string) $reflection->getFileName() );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertStringContainsString( 'addons/pro/includes/tools/crm/class-wp-mcp-ai-tool-create-company.php', $file );
		} else {
			$this->assertStringContainsString( 'nvoos-content-graph-pro/src/tools/crm/class-wp-mcp-ai-tool-create-company.php', $file );
		}
	}

	/**
	 * Availability tracks the Company CPT registration in both matrices.
	 * (A preceding suite test may already have registered the CPT, so the
	 * pre-registration state is probed after an explicit unregister.)
	 */
	public function test_availability_tracks_company_cpt(): void {
		unregister_post_type( 'mcp_ai_company' );
		$this->assertFalse( WP_MCP_AI_Tool_Create_Company::is_available() );
		$this->assertStringContainsString( 'CRM Toolkit', WP_MCP_AI_Tool_Create_Company::get_unavailable_reason() );

		$this->register_company_cpt();
		$this->assertTrue( WP_MCP_AI_Tool_Create_Company::is_available() );
	}

	/**
	 * The execute() flow creates a company post with metadata and returns
	 * the canonical success envelope.
	 */
	public function test_execute_creates_company(): void {
		$this->enable_crm_toolkit();
		$this->register_company_cpt();

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Company();
		$result = $tool->execute(
			array(
				'company_name' => 'Acme Widgets',
				'industry'     => 'Manufacturing',
				'company_size' => '51-200',
				'website'      => 'https://acme.example.com',
				'notes'        => '<p>Key supplier.</p>',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertIsInt( $result['company_id'] );
		$this->assertSame( 'Acme Widgets', $result['company_name'] );
		$this->assertSame( 'Manufacturing', $result['industry'] );
		$this->assertStringContainsString( 'Acme Widgets', $result['message'] );

		$this->assertSame( 'Acme Widgets', get_the_title( $result['company_id'] ) );
		$this->assertSame( 'Manufacturing', get_post_meta( $result['company_id'], '_company_industry', true ) );
		$this->assertSame( '51-200', get_post_meta( $result['company_id'], '_company_size', true ) );
		$this->assertSame( 'https://acme.example.com', get_post_meta( $result['company_id'], '_company_website', true ) );
	}

	/**
	 * The execute() flow rejects missing required fields with WP_Error
	 * envelopes (the canonical failure shape — never success => false).
	 */
	public function test_execute_rejects_missing_required_fields(): void {
		$this->enable_crm_toolkit();
		$this->register_company_cpt();

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Create_Company();

		$no_name = $tool->execute( array( 'industry' => 'Tech' ), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $no_name );
		$this->assertSame( 'wp_mcp_ai_missing_company_name', $no_name->get_error_code() );

		$no_industry = $tool->execute( array( 'company_name' => 'Acme' ), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $no_industry );
		$this->assertSame( 'wp_mcp_ai_missing_industry', $no_industry->get_error_code() );
	}

	/**
	 * The execute() flow enforces the edit_posts capability gate.
	 */
	public function test_execute_enforces_capability_gate(): void {
		$this->enable_crm_toolkit();
		$this->register_company_cpt();

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Company();
		$result = $tool->execute(
			array(
				'company_name' => 'Nope',
				'industry'     => 'Tech',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Standalone only: the init's tool filter must carry the ported tool
	 * subset with the addon's file path.
	 */
	public function test_crm_tools_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the CRM tool map inline.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		// Re-arm the filter added inside the init's enabled block — the WP
		// test framework may have restored an earlier hook snapshot.
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Create_Company', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/class-wp-mcp-ai-tool-create-company.php',
			$tools['WP_MCP_AI_Tool_Create_Company']
		);
	}

	/**
	 * Standalone only: the ecosystem registration must register the tool
	 * into both the graph ToolRegistry and the nvoos/core registry.
	 */
	public function test_ecosystem_tool_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		// The registration helper lives in the CRM init (file scope) — load
		// it with the toolkit enabled so the full standalone wiring runs,
		// then call the helper explicitly (idempotent — duplicate slugs are
		// caught and ignored).
		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		wp_mcp_ai_pro_register_crm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertInstanceOf( 'NvoosContentGraph\\ToolRegistry', $parent );
		$this->assertNotNull( $parent->all()['create_company'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'create_company' ) );
	}
}
