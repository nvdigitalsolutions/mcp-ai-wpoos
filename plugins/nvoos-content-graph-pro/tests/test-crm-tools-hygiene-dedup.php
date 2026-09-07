<?php
/**
 * Characterization tests for the Wave F2 CRM hygiene + dedup tool batch —
 * the ported compliance tools (classify/manage email hygiene,
 * prune-crm-messages, repair-crm-data, detect-duplicates,
 * merge-duplicates).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool classes (classmap-autoloaded); the byte-identical surface is
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/crm/compliance/` are asserted in full, including the
 *   ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM hygiene + dedup tool batch tests.
 */
class Test_Crm_Tools_Hygiene_Dedup extends WP_UnitTestCase {

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
	 * The six ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Classify_Email_Hygiene' => 'tools/crm/compliance/class-wp-mcp-ai-tool-classify-email-hygiene.php',
			'WP_MCP_AI_Tool_Manage_Email_Hygiene'   => 'tools/crm/compliance/class-wp-mcp-ai-tool-manage-email-hygiene.php',
			'WP_MCP_AI_Tool_Prune_CRM_Messages'     => 'tools/crm/compliance/class-wp-mcp-ai-tool-prune-crm-messages.php',
			'WP_MCP_AI_Tool_Repair_CRM_Data'        => 'tools/crm/compliance/class-wp-mcp-ai-tool-repair-crm-data.php',
			'WP_MCP_AI_Tool_Detect_Duplicates'      => 'tools/crm/compliance/class-wp-mcp-ai-tool-detect-duplicates.php',
			'WP_MCP_AI_Tool_Merge_Duplicates'       => 'tools/crm/compliance/class-wp-mcp-ai-tool-merge-duplicates.php',
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
	 * The six tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Classify_Email_Hygiene' => 'classify_email_hygiene',
			'WP_MCP_AI_Tool_Manage_Email_Hygiene'   => 'manage_email_hygiene',
			'WP_MCP_AI_Tool_Prune_CRM_Messages'     => 'prune_crm_messages',
			'WP_MCP_AI_Tool_Repair_CRM_Data'        => 'repair_crm_data',
			'WP_MCP_AI_Tool_Detect_Duplicates'      => 'detect_duplicates',
			'WP_MCP_AI_Tool_Merge_Duplicates'       => 'merge_duplicates',
		);

		// Capability-map resolutions (byte-identical map keys with the
		// documented fallbacks): manage settings/delete lead →
		// manage_options, view lead → edit_posts.
		$manage_options = array(
			'WP_MCP_AI_Tool_Manage_Email_Hygiene',
			'WP_MCP_AI_Tool_Prune_CRM_Messages',
			'WP_MCP_AI_Tool_Repair_CRM_Data',
			'WP_MCP_AI_Tool_Merge_Duplicates',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$expected_cap = in_array( $class, $manage_options, true ) ? 'manage_options' : 'edit_posts';
			$this->assertSame( $expected_cap, $tool->get_required_capability(), $class );
		}
	}

	/**
	 * Standalone only: the init's tool filter must carry the hygiene batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the CRM tool map inline.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Classify_Email_Hygiene', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Detect_Duplicates', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Merge_Duplicates', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/compliance/class-wp-mcp-ai-tool-repair-crm-data.php',
			$tools['WP_MCP_AI_Tool_Repair_CRM_Data']
		);
	}

	/**
	 * Standalone only: the ecosystem registration must register the hygiene
	 * batch into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		wp_mcp_ai_pro_register_crm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['classify_email_hygiene'] ?? null );
		$this->assertNotNull( $parent->all()['merge_duplicates'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'classify_email_hygiene' ) );
		$this->assertTrue( $core_tools->has( 'manage_email_hygiene' ) );
		$this->assertTrue( $core_tools->has( 'prune_crm_messages' ) );
		$this->assertTrue( $core_tools->has( 'repair_crm_data' ) );
		$this->assertTrue( $core_tools->has( 'detect_duplicates' ) );
		$this->assertTrue( $core_tools->has( 'merge_duplicates' ) );
	}
}
