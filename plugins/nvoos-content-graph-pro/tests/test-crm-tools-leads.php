<?php
/**
 * Characterization tests for the Wave F2 CRM leads/customers tool batch —
 * the ported leads CRUD tools (`create/list/get/update/delete` lead,
 * `convert-lead-to-customer`) plus `WP_MCP_AI_Tool_Create_Customer`.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the tool
 *   classes (classmap-autoloaded); the byte-identical surface and the
 *   data-store-backed execute() flows are asserted against the base CPTs.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/crm/leads|customers/` are asserted in full, including the
 *   serving sources and the ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM leads/customers tool batch tests.
 */
class Test_Crm_Tools_Leads extends WP_UnitTestCase {

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
	 * @param string $class CPT class name (e.g. WP_MCP_AI_Lead_CPT).
	 * @param string $slug  CPT class file slug (e.g. lead).
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
	 * The seven tool surfaces must be byte-identical.
	 */
	public function test_leads_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Create_Lead'              => 'create_lead',
			'WP_MCP_AI_Tool_List_Leads'               => 'list_leads',
			'WP_MCP_AI_Tool_Get_Lead'                 => 'get_lead',
			'WP_MCP_AI_Tool_Update_Lead'              => 'update_lead',
			'WP_MCP_AI_Tool_Delete_Lead'              => 'delete_lead',
			'WP_MCP_AI_Tool_Convert_Lead_To_Customer' => 'convert_lead_to_customer',
			'WP_MCP_AI_Tool_Create_Customer'          => 'create_customer',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'crm', $tool->get_definition()['toolkit'], $class );
		}

		// Capability map: delete is gated harder than the edit/view set.
		$this->assertSame( 'edit_posts', ( new WP_MCP_AI_Tool_Create_Lead() )->get_required_capability() );
		$this->assertSame( 'edit_posts', ( new WP_MCP_AI_Tool_Update_Lead() )->get_required_capability() );
		$this->assertSame( 'edit_posts', ( new WP_MCP_AI_Tool_List_Leads() )->get_required_capability() );
		$this->assertSame( 'manage_options', ( new WP_MCP_AI_Tool_Delete_Lead() )->get_required_capability() );

		$this->assertSame( array( 'email' ), ( new WP_MCP_AI_Tool_Create_Lead() )->get_parameters_schema()['required'] );
	}

	/**
	 * Create Lead must persist through the tenant data store and return the
	 * canonical success envelope.
	 */
	public function test_create_lead_execute(): void {
		$this->enable_crm_toolkit();
		$this->register_cpt( 'WP_MCP_AI_Lead_CPT', 'lead' );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Tool_Create_Lead() )->execute(
			array(
				'email'        => 'jane@example.com',
				'first_name'   => 'Jane',
				'last_name'    => 'Doe',
				'company_name' => 'Acme',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertIsInt( $result['lead_id'] );
		$this->assertGreaterThan( 0, $result['lead_id'] );
		$this->assertStringContainsString( 'jane@example.com', $result['message'] );

		$lead = ( new WP_MCP_AI_Tool_Get_Lead() )->execute(
			array( 'lead_id' => $result['lead_id'] ),
			array( 'user_id' => $user_id )
		);
		$this->assertTrue( $lead['success'] );
		$this->assertSame( 'jane@example.com', $lead['lead']['email'] );
	}

	/**
	 * Create Lead must reject malformed emails. WP 6.9's sanitize_email()
	 * collapses malformed addresses to '' upstream of the validator, so the
	 * byte-identical missing-email gate fires first.
	 */
	public function test_create_lead_rejects_bad_email(): void {
		$this->enable_crm_toolkit();
		$this->register_cpt( 'WP_MCP_AI_Lead_CPT', 'lead' );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		$tool = new WP_MCP_AI_Tool_Create_Lead();

		$missing = $tool->execute( array( 'first_name' => 'X' ), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $missing );
		$this->assertSame( 'wp_mcp_ai_missing_email', $missing->get_error_code() );

		$malformed = $tool->execute( array( 'email' => 'bad@domain' ), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $malformed );
		$this->assertSame( 'wp_mcp_ai_missing_email', $malformed->get_error_code() );
	}

	/**
	 * The leads CRUD cycle (list → update → delete) must work end-to-end
	 * against the ported data store.
	 */
	public function test_leads_crud_cycle(): void {
		$this->enable_crm_toolkit();
		$this->register_cpt( 'WP_MCP_AI_Lead_CPT', 'lead' );

		// Administrator: delete-lead is gated on manage_options (byte-identical
		// capability map) while the edit/view set defaults to edit_posts.
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$context = array( 'user_id' => $user_id );

		$created = ( new WP_MCP_AI_Tool_Create_Lead() )->execute(
			array(
				'email'      => 'cycle@example.com',
				'first_name' => 'Cycle',
			),
			$context
		);
		$this->assertTrue( $created['success'] );
		$lead_id = $created['lead_id'];

		// List.
		$listed = ( new WP_MCP_AI_Tool_List_Leads() )->execute( array(), $context );
		$this->assertTrue( $listed['success'] );
		$this->assertGreaterThanOrEqual( 1, count( $listed['leads'] ) );

		// Update.
		$updated = ( new WP_MCP_AI_Tool_Update_Lead() )->execute(
			array(
				'lead_id'    => $lead_id,
				'first_name' => 'Cycled',
			),
			$context
		);
		$this->assertTrue( $updated['success'] );

		$fetched = ( new WP_MCP_AI_Tool_Get_Lead() )->execute( array( 'lead_id' => $lead_id ), $context );
		$this->assertSame( 'Cycled', $fetched['lead']['first_name'] );

		// Delete (with the confirmation gate satisfied).
		$deleted = ( new WP_MCP_AI_Tool_Delete_Lead() )->execute(
			array(
				'lead_id'               => $lead_id,
				'confirmation_required' => true,
			),
			$context
		);
		$this->assertTrue( $deleted['success'] );

		$gone = ( new WP_MCP_AI_Tool_Get_Lead() )->execute( array( 'lead_id' => $lead_id ), $context );
		$this->assertInstanceOf( 'WP_Error', $gone );
		$this->assertSame( 'wp_mcp_ai_lead_not_found', $gone->get_error_code() );
	}

	/**
	 * Delete Lead must refuse unconfirmed deletions (safety gate).
	 */
	public function test_delete_lead_confirmation_gate(): void {
		$this->enable_crm_toolkit();
		$this->register_cpt( 'WP_MCP_AI_Lead_CPT', 'lead' );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$created = ( new WP_MCP_AI_Tool_Create_Lead() )->execute(
			array(
				'email' => 'gate@example.com',
			),
			array( 'user_id' => $user_id )
		);
		$this->assertTrue( $created['success'] );

		$unconfirmed = ( new WP_MCP_AI_Tool_Delete_Lead() )->execute(
			array( 'lead_id' => $created['lead_id'] ),
			array( 'user_id' => $user_id )
		);
		$this->assertInstanceOf( 'WP_Error', $unconfirmed );
		$this->assertSame( 'wp_mcp_ai_delete_not_confirmed', $unconfirmed->get_error_code() );

		// The lead must survive the unconfirmed attempt.
		$still = ( new WP_MCP_AI_Tool_Get_Lead() )->execute( array( 'lead_id' => $created['lead_id'] ), array( 'user_id' => $user_id ) );
		$this->assertTrue( $still['success'] );
	}

	/**
	 * Create Customer must persist through the customer data store.
	 */
	public function test_create_customer_execute(): void {
		$this->enable_crm_toolkit();
		$this->register_cpt( 'WP_MCP_AI_Customer_CPT', 'customer' );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Tool_Create_Customer() )->execute(
			array(
				'email'      => 'cust@example.com',
				'first_name' => 'Cust',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertIsInt( $result['customer_id'] );
		$this->assertGreaterThan( 0, $result['customer_id'] );
	}

	/**
	 * Convert Lead To Customer must advance the lifecycle stage and create
	 * the customer (and optionally the deal) records.
	 */
	public function test_convert_lead_to_customer(): void {
		$this->enable_crm_toolkit();
		$this->register_cpt( 'WP_MCP_AI_Lead_CPT', 'lead' );
		$this->register_cpt( 'WP_MCP_AI_Customer_CPT', 'customer' );
		$this->register_cpt( 'WP_MCP_AI_Deal_CPT', 'deal' );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		$context = array( 'user_id' => $user_id );

		$created = ( new WP_MCP_AI_Tool_Create_Lead() )->execute(
			array(
				'email'      => 'convert@example.com',
				'first_name' => 'Convert',
				'last_name'  => 'Me',
			),
			$context
		);
		$this->assertTrue( $created['success'] );

		$result = ( new WP_MCP_AI_Tool_Convert_Lead_To_Customer() )->execute(
			array(
				'lead_id'     => $created['lead_id'],
				'create_deal' => true,
			),
			$context
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'customer', $result['new_stage'] );
		$this->assertGreaterThan( 0, $result['customer_id'] );
		$this->assertTrue( $result['deal_created'] );
		$this->assertGreaterThan( 0, $result['deal_id'] );
	}

	/**
	 * Standalone only: the init's tool filter must carry the leads batch.
	 */
	public function test_leads_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the CRM tool map inline.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Create_Lead', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_List_Leads', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Get_Lead', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Update_Lead', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Delete_Lead', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Convert_Lead_To_Customer', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Create_Customer', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/leads/class-wp-mcp-ai-tool-create-lead.php',
			$tools['WP_MCP_AI_Tool_Create_Lead']
		);
	}

	/**
	 * Standalone only: the ecosystem registration must register the leads
	 * batch into both registries.
	 */
	public function test_leads_ecosystem_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		wp_mcp_ai_pro_register_crm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['create_lead'] ?? null );
		$this->assertNotNull( $parent->all()['convert_lead_to_customer'] ?? null );
		$this->assertNotNull( $parent->all()['create_customer'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'create_lead' ) );
		$this->assertTrue( $core_tools->has( 'list_leads' ) );
		$this->assertTrue( $core_tools->has( 'update_lead' ) );
		$this->assertTrue( $core_tools->has( 'delete_lead' ) );
		$this->assertTrue( $core_tools->has( 'convert_lead_to_customer' ) );
		$this->assertTrue( $core_tools->has( 'create_customer' ) );
	}
}
