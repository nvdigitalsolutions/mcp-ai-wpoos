<?php
/**
 * Characterization tests for the Wave F2 CRM engagement tool batch — the
 * ported tools (get-contact-interactions, recalculate-engagement-scores,
 * scan-duplicate-contacts).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool classes (classmap-autoloaded); the byte-identical surface is
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/crm/` are asserted in full, including the ecosystem
 *   registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM engagement tool batch tests.
 */
class Test_Crm_Tools_Engagement extends WP_UnitTestCase {

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
	 * The three ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Get_Contact_Interactions'      => 'tools/crm/class-wp-mcp-ai-tool-get-contact-interactions.php',
			'WP_MCP_AI_Tool_Recalculate_Engagement_Scores' => 'tools/crm/class-wp-mcp-ai-tool-recalculate-engagement-scores.php',
			'WP_MCP_AI_Tool_Scan_Duplicate_Contacts'       => 'tools/crm/class-wp-mcp-ai-tool-scan-duplicate-contacts.php',
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
	 * The three tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Get_Contact_Interactions'      => 'get_contact_interactions',
			'WP_MCP_AI_Tool_Recalculate_Engagement_Scores' => 'recalculate_engagement_scores',
			'WP_MCP_AI_Tool_Scan_Duplicate_Contacts'       => 'scan_duplicate_contacts',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
		}

		$interactions = new WP_MCP_AI_Tool_Get_Contact_Interactions();
		$this->assertSame( 'read', $interactions->get_required_capability() );

		$recalculate = new WP_MCP_AI_Tool_Recalculate_Engagement_Scores();
		$this->assertSame( 'edit_posts', $recalculate->get_required_capability() );

		$scan = new WP_MCP_AI_Tool_Scan_Duplicate_Contacts();
		$this->assertSame( 'read', $scan->get_required_capability() );
	}

	/**
	 * Standalone only: the init's tool filter must carry the engagement batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the CRM tool map inline.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Get_Contact_Interactions', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Recalculate_Engagement_Scores', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Scan_Duplicate_Contacts', $tools );
	}

	/**
	 * Standalone only: the ecosystem registration must register the
	 * engagement batch into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		wp_mcp_ai_pro_register_crm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['get_contact_interactions'] ?? null );
		$this->assertNotNull( $parent->all()['scan_duplicate_contacts'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'get_contact_interactions' ) );
		$this->assertTrue( $core_tools->has( 'recalculate_engagement_scores' ) );
		$this->assertTrue( $core_tools->has( 'scan_duplicate_contacts' ) );
	}
}
