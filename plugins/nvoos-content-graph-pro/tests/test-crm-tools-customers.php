<?php
/**
 * Characterization tests for the Wave F2 CRM customers tool batch — the
 * ported customer tools (get/update/delete/list).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   tool classes (classmap-autoloaded); the byte-identical surface is
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/crm/customers/` are asserted in full, including the
 *   ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM customers tool batch tests.
 */
class Test_Crm_Tools_Customers extends WP_UnitTestCase {

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
	 * The four ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Get_Customer'    => 'tools/crm/customers/class-wp-mcp-ai-tool-get-customer.php',
			'WP_MCP_AI_Tool_Update_Customer' => 'tools/crm/customers/class-wp-mcp-ai-tool-update-customer.php',
			'WP_MCP_AI_Tool_Delete_Customer' => 'tools/crm/customers/class-wp-mcp-ai-tool-delete-customer.php',
			'WP_MCP_AI_Tool_List_Customers'  => 'tools/crm/customers/class-wp-mcp-ai-tool-list-customers.php',
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
	 * The four tool surfaces must be byte-identical.
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Get_Customer'    => 'get_customer',
			'WP_MCP_AI_Tool_Update_Customer' => 'update_customer',
			'WP_MCP_AI_Tool_Delete_Customer' => 'delete_customer',
			'WP_MCP_AI_Tool_List_Customers'  => 'list_customers',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
		}

		$get = new WP_MCP_AI_Tool_Get_Customer();
		$this->assertSame( 'edit_posts', $get->get_required_capability() );

		$update = new WP_MCP_AI_Tool_Update_Customer();
		$this->assertSame( 'edit_posts', $update->get_required_capability() );

		$delete = new WP_MCP_AI_Tool_Delete_Customer();
		$this->assertSame( 'delete_posts', $delete->get_required_capability() );

		$list = new WP_MCP_AI_Tool_List_Customers();
		$this->assertSame( 'edit_posts', $list->get_required_capability() );
	}

	/**
	 * Standalone only: the init's tool filter must carry the customers batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the CRM tool map inline.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Get_Customer', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Update_Customer', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Delete_Customer', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_List_Customers', $tools );
	}

	/**
	 * Standalone only: the ecosystem registration must register the customers
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
		$this->assertNotNull( $parent->all()['get_customer'] ?? null );
		$this->assertNotNull( $parent->all()['list_customers'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'get_customer' ) );
		$this->assertTrue( $core_tools->has( 'update_customer' ) );
		$this->assertTrue( $core_tools->has( 'delete_customer' ) );
		$this->assertTrue( $core_tools->has( 'list_customers' ) );
	}
}
