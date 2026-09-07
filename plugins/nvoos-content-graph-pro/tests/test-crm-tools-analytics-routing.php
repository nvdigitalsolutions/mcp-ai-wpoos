<?php
/**
 * Characterization tests for the Wave F2 CRM analytics/routing tool batch —
 * the ported pipeline analytics tools (`get-pipeline-view`,
 * `get-conversion-funnel`, `forecast-pipeline-revenue`,
 * `identify-top-customers`, `identify-top-clients`) and the routing tools
 * (`assign-lead-to-owner`, `rotate-leads`).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the tool
 *   classes (classmap-autoloaded); the byte-identical surface and the
 *   read-only/assignment execute() flows are asserted against the base
 *   CPTs.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/crm/analytics|routing/` are asserted in full, including the
 *   ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM analytics/routing tool batch tests.
 */
class Test_Crm_Tools_Analytics_Routing extends WP_UnitTestCase {

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
	 * Register the Lead CPT for the active matrix.
	 *
	 * @return void
	 */
	private function register_lead_cpt(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/class-wp-mcp-ai-lead-cpt.php';
		} else {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-lead-cpt.php';
		}
		WP_MCP_AI_Lead_CPT::register_post_type();
	}

	/**
	 * The seven tool surfaces must be byte-identical.
	 */
	public function test_analytics_routing_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Get_Pipeline_View'         => 'get_pipeline_view',
			'WP_MCP_AI_Tool_Get_Conversion_Funnel'     => 'get_conversion_funnel',
			'WP_MCP_AI_Tool_Forecast_Pipeline_Revenue' => 'forecast_pipeline_revenue',
			'WP_MCP_AI_Tool_Identify_Top_Customers'    => 'identify_top_customers',
			'WP_MCP_AI_Tool_Identify_Top_Clients'      => 'identify_top_clients',
			'WP_MCP_AI_Tool_Assign_Lead_To_Owner'      => 'assign_lead_to_owner',
			'WP_MCP_AI_Tool_Rotate_Leads'              => 'rotate_leads',
		);

		$edit_posts_tools = array(
			'WP_MCP_AI_Tool_Get_Pipeline_View',
			'WP_MCP_AI_Tool_Get_Conversion_Funnel',
			'WP_MCP_AI_Tool_Forecast_Pipeline_Revenue',
			'WP_MCP_AI_Tool_Identify_Top_Customers',
			'WP_MCP_AI_Tool_Identify_Top_Clients',
			'WP_MCP_AI_Tool_Assign_Lead_To_Owner',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			if ( in_array( $class, $edit_posts_tools, true ) ) {
				$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
			}
		}

		// rotate-leads is gated harder than the rest (byte-identical map).
		$this->assertSame( 'manage_options', ( new WP_MCP_AI_Tool_Rotate_Leads() )->get_required_capability() );
	}

	/**
	 * The pipeline analytics tools must return success envelopes on an empty
	 * CRM (read-only, no required arguments).
	 */
	public function test_analytics_smoke(): void {
		$this->enable_crm_toolkit();

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		$context = array( 'user_id' => $user_id );

		foreach (
			array(
				'WP_MCP_AI_Tool_Get_Pipeline_View',
				'WP_MCP_AI_Tool_Get_Conversion_Funnel',
				'WP_MCP_AI_Tool_Forecast_Pipeline_Revenue',
			) as $class
		) {
			$result = ( new $class() )->execute( array(), $context );
			$this->assertIsArray( $result, $class );
			$this->assertTrue( $result['success'], $class );
		}

		// The top-customer/client tools read through the data store and the
		// audit trail; empty CRM still yields a success envelope.
		$top_customers = ( new WP_MCP_AI_Tool_Identify_Top_Customers() )->execute( array(), $context );
		$this->assertTrue( $top_customers['success'] );

		$top_clients = ( new WP_MCP_AI_Tool_Identify_Top_Clients() )->execute( array(), $context );
		$this->assertTrue( $top_clients['success'] );
	}

	/**
	 * Assign Lead To Owner must stamp the contact_owner meta on a seeded
	 * lead.
	 */
	public function test_assign_lead_to_owner(): void {
		$this->enable_crm_toolkit();
		$this->register_lead_cpt();

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$owner   = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$created = ( new WP_MCP_AI_Tool_Create_Lead() )->execute(
			array(
				'email' => 'assign@example.com',
			),
			array( 'user_id' => $user_id )
		);
		$this->assertTrue( $created['success'] );

		$result = ( new WP_MCP_AI_Tool_Assign_Lead_To_Owner() )->execute(
			array(
				'lead_id'  => $created['lead_id'],
				'owner_id' => $owner,
			),
			array( 'user_id' => $user_id )
		);
		$this->assertTrue( $result['success'] );
		$this->assertEquals( $owner, get_post_meta( $created['lead_id'], 'contact_owner', true ) );

		// Unknown lead IDs must be rejected.
		$ghost = ( new WP_MCP_AI_Tool_Assign_Lead_To_Owner() )->execute(
			array(
				'lead_id'  => 999999,
				'owner_id' => $owner,
			),
			array( 'user_id' => $user_id )
		);
		$this->assertInstanceOf( 'WP_Error', $ghost );
	}

	/**
	 * Rotate Leads must run against the routing engine (administrator gate).
	 */
	public function test_rotate_leads_smoke(): void {
		$this->enable_crm_toolkit();
		$this->register_lead_cpt();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Tool_Rotate_Leads() )->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	/**
	 * Standalone only: the init's tool filter must carry the batch.
	 */
	public function test_analytics_routing_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the CRM tool map inline.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Get_Pipeline_View', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Identify_Top_Customers', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Assign_Lead_To_Owner', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Rotate_Leads', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/analytics/class-wp-mcp-ai-tool-get-pipeline-view.php',
			$tools['WP_MCP_AI_Tool_Get_Pipeline_View']
		);
	}

	/**
	 * Standalone only: the ecosystem registration must register the batch
	 * into both registries.
	 */
	public function test_analytics_routing_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		wp_mcp_ai_pro_register_crm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['get_pipeline_view'] ?? null );
		$this->assertNotNull( $parent->all()['assign_lead_to_owner'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'get_pipeline_view' ) );
		$this->assertTrue( $core_tools->has( 'get_conversion_funnel' ) );
		$this->assertTrue( $core_tools->has( 'forecast_pipeline_revenue' ) );
		$this->assertTrue( $core_tools->has( 'identify_top_customers' ) );
		$this->assertTrue( $core_tools->has( 'identify_top_clients' ) );
		$this->assertTrue( $core_tools->has( 'assign_lead_to_owner' ) );
		$this->assertTrue( $core_tools->has( 'rotate_leads' ) );
	}
}
