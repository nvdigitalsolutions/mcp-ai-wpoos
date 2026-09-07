<?php
/**
 * Characterization tests for the Wave F2 CRM deals tool batch — the ported
 * deals CRUD tools (`create/list/get/update/delete` deal +
 * `move-deal-stage`).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the tool
 *   classes (classmap-autoloaded); the byte-identical surface and the
 *   data-store-backed execute() flows are asserted against the base CPTs.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/crm/deals/` are asserted in full, including the ecosystem
 *   registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM deals tool batch tests.
 */
class Test_Crm_Tools_Deals extends WP_UnitTestCase {

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
	 * @param string $class CPT class name (e.g. WP_MCP_AI_Deal_CPT).
	 * @param string $slug  CPT class file slug (e.g. deal).
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
	 * Create a lead through the ported create-lead tool (prerequisite for
	 * create-deal, which requires a lead_id).
	 *
	 * @param int    $user_id Acting user ID.
	 * @param string $email   Lead email.
	 * @return int Lead ID.
	 */
	private function create_lead( $user_id, $email ): int {
		$created = ( new WP_MCP_AI_Tool_Create_Lead() )->execute(
			array(
				'email'      => $email,
				'first_name' => 'Deal',
				'last_name'  => 'Driver',
			),
			array( 'user_id' => $user_id )
		);
		$this->assertTrue( $created['success'] );
		return $created['lead_id'];
	}

	/**
	 * The six tool surfaces must be byte-identical.
	 */
	public function test_deals_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Create_Deal'     => 'create_deal',
			'WP_MCP_AI_Tool_List_Deals'      => 'list_deals',
			'WP_MCP_AI_Tool_Get_Deal'        => 'get_deal',
			'WP_MCP_AI_Tool_Update_Deal'     => 'update_deal',
			'WP_MCP_AI_Tool_Delete_Deal'     => 'delete_deal',
			'WP_MCP_AI_Tool_Move_Deal_Stage' => 'move_deal_stage',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
		}

		$this->assertSame( 'edit_posts', ( new WP_MCP_AI_Tool_Create_Deal() )->get_required_capability() );
		$this->assertSame( 'edit_posts', ( new WP_MCP_AI_Tool_Move_Deal_Stage() )->get_required_capability() );
		$this->assertSame( 'manage_options', ( new WP_MCP_AI_Tool_Delete_Deal() )->get_required_capability() );
		$this->assertSame( array( 'lead_id' ), ( new WP_MCP_AI_Tool_Create_Deal() )->get_parameters_schema()['required'] );
	}

	/**
	 * Create Deal must build a deal from a lead and return the canonical
	 * success envelope.
	 */
	public function test_create_deal_execute(): void {
		$this->enable_crm_toolkit();
		$this->register_cpt( 'WP_MCP_AI_Lead_CPT', 'lead' );
		$this->register_cpt( 'WP_MCP_AI_Deal_CPT', 'deal' );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		$lead_id = $this->create_lead( $user_id, 'dealmaker@example.com' );

		$result = ( new WP_MCP_AI_Tool_Create_Deal() )->execute(
			array( 'lead_id' => $lead_id ),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertIsInt( $result['deal_id'] );
		$this->assertGreaterThan( 0, $result['deal_id'] );

		$fetched = ( new WP_MCP_AI_Tool_Get_Deal() )->execute(
			array( 'deal_id' => $result['deal_id'] ),
			array( 'user_id' => $user_id )
		);
		$this->assertTrue( $fetched['success'] );
		$this->assertEquals( $lead_id, $fetched['deal']['lead_id'] );
	}

	/**
	 * Create Deal must reject a missing lead_id.
	 */
	public function test_create_deal_rejects_missing_lead(): void {
		$this->enable_crm_toolkit();
		$this->register_cpt( 'WP_MCP_AI_Lead_CPT', 'lead' );
		$this->register_cpt( 'WP_MCP_AI_Deal_CPT', 'deal' );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Tool_Create_Deal() )->execute( array(), array( 'user_id' => $user_id ) );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * The deals update/list/delete cycle must work end-to-end against the
	 * ported data store.
	 */
	public function test_deals_crud_cycle(): void {
		$this->enable_crm_toolkit();
		$this->register_cpt( 'WP_MCP_AI_Lead_CPT', 'lead' );
		$this->register_cpt( 'WP_MCP_AI_Deal_CPT', 'deal' );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$context = array( 'user_id' => $user_id );

		$lead_id = $this->create_lead( $user_id, 'cycle-deal@example.com' );
		$created = ( new WP_MCP_AI_Tool_Create_Deal() )->execute( array( 'lead_id' => $lead_id ), $context );
		$this->assertTrue( $created['success'] );
		$deal_id = $created['deal_id'];

		// List.
		$listed = ( new WP_MCP_AI_Tool_List_Deals() )->execute( array(), $context );
		$this->assertTrue( $listed['success'] );
		$this->assertGreaterThanOrEqual( 1, count( $listed['deals'] ) );

		// Update amount.
		$updated = ( new WP_MCP_AI_Tool_Update_Deal() )->execute(
			array(
				'deal_id' => $deal_id,
				'amount'  => 5000,
			),
			$context
		);
		$this->assertTrue( $updated['success'] );

		$fetched = ( new WP_MCP_AI_Tool_Get_Deal() )->execute( array( 'deal_id' => $deal_id ), $context );
		$this->assertEquals( 5000.0, $fetched['deal']['amount'] );

		// Delete without confirmation must be refused.
		$unconfirmed = ( new WP_MCP_AI_Tool_Delete_Deal() )->execute( array( 'deal_id' => $deal_id ), $context );
		$this->assertInstanceOf( 'WP_Error', $unconfirmed );

		// Delete with confirmation.
		$deleted = ( new WP_MCP_AI_Tool_Delete_Deal() )->execute(
			array(
				'deal_id' => $deal_id,
				'confirm' => true,
			),
			$context
		);
		$this->assertTrue( $deleted['success'] );

		$gone = ( new WP_MCP_AI_Tool_Get_Deal() )->execute( array( 'deal_id' => $deal_id ), $context );
		$this->assertInstanceOf( 'WP_Error', $gone );
	}

	/**
	 * Move Deal Stage must advance the pipeline stage with the byte-identical
	 * envelope (previous/new stage, win probability, is_won flag).
	 */
	public function test_move_deal_stage_execute(): void {
		$this->enable_crm_toolkit();
		$this->register_cpt( 'WP_MCP_AI_Lead_CPT', 'lead' );
		$this->register_cpt( 'WP_MCP_AI_Deal_CPT', 'deal' );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		$context = array( 'user_id' => $user_id );

		$lead_id = $this->create_lead( $user_id, 'stage@example.com' );
		$created = ( new WP_MCP_AI_Tool_Create_Deal() )->execute( array( 'lead_id' => $lead_id ), $context );
		$this->assertTrue( $created['success'] );

		$result = ( new WP_MCP_AI_Tool_Move_Deal_Stage() )->execute(
			array(
				'deal_id'   => $created['deal_id'],
				'new_stage' => 'proposal',
			),
			$context
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'proposal', $result['new_stage'] );
		$this->assertSame( 'prospecting', $result['previous_stage'] );
		$this->assertFalse( $result['is_won'] );

		// Invalid stage must be rejected.
		$invalid = ( new WP_MCP_AI_Tool_Move_Deal_Stage() )->execute(
			array(
				'deal_id'   => $created['deal_id'],
				'new_stage' => 'not-a-stage',
			),
			$context
		);
		$this->assertInstanceOf( 'WP_Error', $invalid );
	}

	/**
	 * Standalone only: the init's tool filter must carry the deals batch.
	 */
	public function test_deals_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the CRM tool map inline.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Create_Deal', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_List_Deals', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Get_Deal', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Update_Deal', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Delete_Deal', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Move_Deal_Stage', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/deals/class-wp-mcp-ai-tool-create-deal.php',
			$tools['WP_MCP_AI_Tool_Create_Deal']
		);
	}

	/**
	 * Standalone only: the ecosystem registration must register the deals
	 * batch into both registries.
	 */
	public function test_deals_ecosystem_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		wp_mcp_ai_pro_register_crm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['create_deal'] ?? null );
		$this->assertNotNull( $parent->all()['move_deal_stage'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'create_deal' ) );
		$this->assertTrue( $core_tools->has( 'list_deals' ) );
		$this->assertTrue( $core_tools->has( 'update_deal' ) );
		$this->assertTrue( $core_tools->has( 'delete_deal' ) );
		$this->assertTrue( $core_tools->has( 'move_deal_stage' ) );
	}
}
