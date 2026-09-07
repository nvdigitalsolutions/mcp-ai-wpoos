<?php
/**
 * Characterization tests for the Wave F2 CRM sequences tool batch — the
 * ported outreach-sequence tools (create/update/delete/list, enroll,
 * manage-state, get-performance).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool classes (classmap-autoloaded); the byte-identical surface and
 *   the deterministic execute() gates are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/crm/sequences/` are asserted in full, including the
 *   ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM sequences tool batch tests.
 */
class Test_Crm_Tools_Sequences extends WP_UnitTestCase {

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
	 * The seven ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Create_Outreach_Sequence' => 'tools/crm/sequences/class-wp-mcp-ai-tool-create-outreach-sequence.php',
			'WP_MCP_AI_Tool_Update_Outreach_Sequence' => 'tools/crm/sequences/class-wp-mcp-ai-tool-update-outreach-sequence.php',
			'WP_MCP_AI_Tool_Delete_Outreach_Sequence' => 'tools/crm/sequences/class-wp-mcp-ai-tool-delete-outreach-sequence.php',
			'WP_MCP_AI_Tool_List_Outreach_Sequences'  => 'tools/crm/sequences/class-wp-mcp-ai-tool-list-outreach-sequences.php',
			'WP_MCP_AI_Tool_Enroll_Lead_In_Sequence'  => 'tools/crm/sequences/class-wp-mcp-ai-tool-enroll-lead-in-sequence.php',
			'WP_MCP_AI_Tool_Manage_Sequence_State'    => 'tools/crm/sequences/class-wp-mcp-ai-tool-manage-sequence-state.php',
			'WP_MCP_AI_Tool_Get_Sequence_Performance' => 'tools/crm/sequences/class-wp-mcp-ai-tool-get-sequence-performance.php',
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
	 * The seven tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Create_Outreach_Sequence' => 'create_outreach_sequence',
			'WP_MCP_AI_Tool_Update_Outreach_Sequence' => 'update_outreach_sequence',
			'WP_MCP_AI_Tool_Delete_Outreach_Sequence' => 'delete_outreach_sequence',
			'WP_MCP_AI_Tool_List_Outreach_Sequences'  => 'list_outreach_sequences',
			'WP_MCP_AI_Tool_Enroll_Lead_In_Sequence'  => 'enroll_lead_in_sequence',
			'WP_MCP_AI_Tool_Manage_Sequence_State'    => 'manage_sequence_state',
			'WP_MCP_AI_Tool_Get_Sequence_Performance' => 'get_sequence_performance',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
		}

		// delete-outreach-sequence is manage_options-gated (byte-identical).
		$delete = new WP_MCP_AI_Tool_Delete_Outreach_Sequence();
		$this->assertSame( 'manage_options', $delete->get_required_capability() );

		foreach ( array_keys( $slugs ) as $class ) {
			if ( 'WP_MCP_AI_Tool_Delete_Outreach_Sequence' === $class ) {
				continue;
			}
			$tool = new $class();
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}

		$create = new WP_MCP_AI_Tool_Create_Outreach_Sequence();
		$flags  = $create->get_capability_flags();
		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'database-write', $flags );
		$this->assertContains( 'requires-capability', $flags );
	}

	/**
	 * The create-sequence permission gate must fire without a capable user.
	 */
	public function test_create_sequence_forbidden_gate(): void {
		$this->enable_crm_toolkit();
		$tool = new WP_MCP_AI_Tool_Create_Outreach_Sequence();

		$result = $tool->execute( array(), array() );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * Standalone only: the init's tool filter must carry the sequences batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the CRM tool map inline.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Create_Outreach_Sequence', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Enroll_Lead_In_Sequence', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Get_Sequence_Performance', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/sequences/class-wp-mcp-ai-tool-create-outreach-sequence.php',
			$tools['WP_MCP_AI_Tool_Create_Outreach_Sequence']
		);
	}

	/**
	 * Standalone only: the ecosystem registration must register the
	 * sequences batch into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		wp_mcp_ai_pro_register_crm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['create_outreach_sequence'] ?? null );
		$this->assertNotNull( $parent->all()['get_sequence_performance'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'create_outreach_sequence' ) );
		$this->assertTrue( $core_tools->has( 'update_outreach_sequence' ) );
		$this->assertTrue( $core_tools->has( 'delete_outreach_sequence' ) );
		$this->assertTrue( $core_tools->has( 'list_outreach_sequences' ) );
		$this->assertTrue( $core_tools->has( 'enroll_lead_in_sequence' ) );
		$this->assertTrue( $core_tools->has( 'manage_sequence_state' ) );
		$this->assertTrue( $core_tools->has( 'get_sequence_performance' ) );
	}
}
