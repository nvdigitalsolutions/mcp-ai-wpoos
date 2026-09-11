<?php
/**
 * Characterization tests for the Wave F2 CRM workflow-rules + routing tool
 * batch — the ported command-center tools (create/manage/simulate workflow
 * rule, get-workflow-inbox, auto-route-inbound-message, get-owner-workload).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool classes (classmap-autoloaded); the byte-identical surface is
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/crm/command-center/` are asserted in full, including the
 *   ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM workflow-rules + routing tool batch tests.
 */
class Test_Crm_Tools_Workflow extends WP_UnitTestCase {

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
			'WP_MCP_AI_Tool_Create_Crm_Workflow_Rule'   => 'tools/crm/command-center/class-wp-mcp-ai-tool-create-crm-workflow-rule.php',
			'WP_MCP_AI_Tool_Manage_Workflow_Rules'      => 'tools/crm/command-center/class-wp-mcp-ai-tool-manage-workflow-rules.php',
			'WP_MCP_AI_Tool_Simulate_Workflow_Rule'     => 'tools/crm/command-center/class-wp-mcp-ai-tool-simulate-workflow-rule.php',
			'WP_MCP_AI_Tool_Get_Workflow_Inbox'         => 'tools/crm/command-center/class-wp-mcp-ai-tool-get-workflow-inbox.php',
			'WP_MCP_AI_Tool_Auto_Route_Inbound_Message' => 'tools/crm/command-center/class-wp-mcp-ai-tool-auto-route-inbound-message.php',
			'WP_MCP_AI_Tool_Get_Owner_Workload'         => 'tools/crm/command-center/class-wp-mcp-ai-tool-get-owner-workload.php',
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
			'WP_MCP_AI_Tool_Create_Crm_Workflow_Rule'   => 'create_crm_workflow_rule',
			'WP_MCP_AI_Tool_Manage_Workflow_Rules'      => 'manage_workflow_rules',
			'WP_MCP_AI_Tool_Simulate_Workflow_Rule'     => 'simulate_workflow_rule',
			'WP_MCP_AI_Tool_Get_Workflow_Inbox'         => 'get_workflow_inbox',
			'WP_MCP_AI_Tool_Auto_Route_Inbound_Message' => 'auto_route_inbound_message',
			'WP_MCP_AI_Tool_Get_Owner_Workload'         => 'get_owner_workload',
		);

		$manage_options = array(
			'WP_MCP_AI_Tool_Create_Crm_Workflow_Rule',
			'WP_MCP_AI_Tool_Manage_Workflow_Rules',
			'WP_MCP_AI_Tool_Simulate_Workflow_Rule',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$expected_cap = in_array( $class, $manage_options, true ) ? 'manage_options' : 'edit_posts';
			$this->assertSame( $expected_cap, $tool->get_required_capability(), $class );
		}
	}

	/**
	 * Standalone only: the init's tool filter must carry the workflow batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the CRM tool map inline.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Create_Crm_Workflow_Rule', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Auto_Route_Inbound_Message', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Get_Owner_Workload', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/command-center/class-wp-mcp-ai-tool-get-workflow-inbox.php',
			$tools['WP_MCP_AI_Tool_Get_Workflow_Inbox']
		);
	}

	/**
	 * Standalone only: the ecosystem registration must register the workflow
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
		$this->assertNotNull( $parent->all()['create_crm_workflow_rule'] ?? null );
		$this->assertNotNull( $parent->all()['get_workflow_inbox'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'create_crm_workflow_rule' ) );
		$this->assertTrue( $core_tools->has( 'manage_workflow_rules' ) );
		$this->assertTrue( $core_tools->has( 'simulate_workflow_rule' ) );
		$this->assertTrue( $core_tools->has( 'get_workflow_inbox' ) );
		$this->assertTrue( $core_tools->has( 'auto_route_inbound_message' ) );
		$this->assertTrue( $core_tools->has( 'get_owner_workload' ) );
	}
}
