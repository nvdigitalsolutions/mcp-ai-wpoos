<?php
/**
 * Characterization tests for the Wave F2 CRM JobNavigator-adoption batch —
 * stage history, dedup/identity, reply signals, handover, digest, bulk
 * stage moves, and tracked links.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surface and the
 *   execute() flows are asserted against the base CPTs.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/crm/` are asserted in full, including the ecosystem
 *   registrations and the tracked-link resolver wiring.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * CRM JobNavigator-adoption batch tests.
 */
class Test_Crm_JobNavigator_Adoption extends WP_UnitTestCase {

	/**
	 * Snapshot of the shared settings option before mutation.
	 *
	 * @var mixed
	 */
	private $settings_snapshot = null;

	/**
	 * Created lead IDs for cleanup.
	 *
	 * @var int[]
	 */
	private $lead_ids = array();

	/**
	 * Created deal IDs for cleanup.
	 *
	 * @var int[]
	 */
	private $deal_ids = array();

	/**
	 * Snapshot the shared settings option.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->settings_snapshot = get_option( 'wp_mcp_ai_settings', null );
		delete_option( 'wp_mcp_ai_crm_link_registry' );
	}

	/**
	 * Restore the shared settings option and remove fixtures.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		foreach ( $this->deal_ids as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $this->lead_ids as $id ) {
			wp_delete_post( $id, true );
		}
		delete_option( 'wp_mcp_ai_crm_link_registry' );
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
	 * Create a raw lead fixture for the active matrix.
	 *
	 * @param string $email Lead email.
	 * @return int Lead ID.
	 */
	private function create_lead_fixture( $email ): int {
		$this->register_cpt( 'WP_MCP_AI_Lead_CPT', 'lead' );
		$lead_id          = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_title'  => 'Fixture Lead',
				'post_status' => 'publish',
			)
		);
		$this->lead_ids[] = $lead_id;
		update_post_meta( $lead_id, 'email', $email );
		return $lead_id;
	}

	/**
	 * Create a raw deal fixture with a seeded stage history.
	 *
	 * @param string $stage Initial pipeline stage.
	 * @return int Deal ID.
	 */
	private function create_deal_fixture( string $stage ): int {
		$this->register_cpt( 'WP_MCP_AI_Lead_CPT', 'lead' );
		$this->register_cpt( 'WP_MCP_AI_Deal_CPT', 'deal' );
		$lead_id          = $this->create_lead_fixture( 'fixture' . wp_rand() . '@example.com' );
		$deal_id          = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_deal',
				'post_title'  => 'Fixture Deal',
				'post_status' => 'publish',
			)
		);
		$this->deal_ids[] = $deal_id;
		update_post_meta( $deal_id, 'lead_id', $lead_id );
		update_post_meta( $deal_id, 'pipeline_stage', $stage );
		WP_MCP_AI_CRM_Stage_History::record( $deal_id, null, $stage, 'tool' );
		return $deal_id;
	}

	/**
	 * The five new tool surfaces must be byte-identical.
	 */
	public function test_adoption_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Bulk_Move_Deal_Stages' => 'bulk_move_deal_stages',
			'WP_MCP_AI_Tool_Create_Tracked_Link'   => 'create_tracked_link',
			'WP_MCP_AI_Tool_Record_CRM_Reply'      => 'record_crm_reply',
			'WP_MCP_AI_Tool_Get_CRM_Handover'      => 'get_crm_handover',
			'WP_MCP_AI_Tool_Get_Pipeline_Digest'   => 'get_pipeline_digest',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * The stage-history ledger contract must be byte-identical: record,
	 * get, undo, and next-open-stage.
	 */
	public function test_stage_history_contract(): void {
		$deal_id = $this->create_deal_fixture( 'prospecting' );

		$this->assertTrue( WP_MCP_AI_CRM_Stage_History::record( $deal_id, 'prospecting', 'qualification', 'agent' ) );

		$history = WP_MCP_AI_CRM_Stage_History::get_history( $deal_id );
		$this->assertCount( 2, $history ); // Seed entry + the recorded move.
		$last = $history[ count( $history ) - 1 ];
		$this->assertSame( 'qualification', $last['to'] );
		$this->assertSame( 'agent', $last['source'] );

		$popped = WP_MCP_AI_CRM_Stage_History::undo_last( $deal_id );
		$this->assertSame( 'prospecting', $popped['from'] );

		$this->assertSame( 'qualification', WP_MCP_AI_CRM_Stage_History::next_open_stage( 'prospecting' ) );
	}

	/**
	 * Move Deal Stage must record source-attributed transitions and undo the
	 * last move without recording a new one.
	 */
	public function test_move_deal_stage_source_and_undo(): void {
		$this->enable_crm_toolkit();
		$deal_id = $this->create_deal_fixture( 'prospecting' );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		$context = array( 'user_id' => $user_id );

		$moved = ( new WP_MCP_AI_Tool_Move_Deal_Stage() )->execute(
			array(
				'deal_id'   => $deal_id,
				'new_stage' => 'qualification',
				'source'    => 'email_reply',
			),
			$context
		);
		$this->assertTrue( $moved['success'] );
		$this->assertSame( 'email_reply', $moved['source'] );

		$undone = ( new WP_MCP_AI_Tool_Move_Deal_Stage() )->execute(
			array(
				'deal_id'   => $deal_id,
				'new_stage' => 'qualification',
				'undo'      => true,
			),
			$context
		);
		$this->assertTrue( $undone['success'] );
		$this->assertSame( 'prospecting', $undone['restored_stage'] );
		$this->assertCount( 1, WP_MCP_AI_CRM_Stage_History::get_history( $deal_id ) );
	}

	/**
	 * Create Lead must refuse duplicates with a pointer to the existing lead.
	 */
	public function test_create_lead_duplicate_refusal(): void {
		$this->enable_crm_toolkit();
		$this->register_cpt( 'WP_MCP_AI_Lead_CPT', 'lead' );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		$context = array( 'user_id' => $user_id );

		$first = ( new WP_MCP_AI_Tool_Create_Lead() )->execute(
			array( 'email' => 'dupe@example.com' ),
			$context
		);
		$this->assertTrue( $first['success'] );
		$this->lead_ids[] = $first['lead_id'];

		$second = ( new WP_MCP_AI_Tool_Create_Lead() )->execute(
			array( 'email' => 'DUPE@example.com' ),
			$context
		);
		$this->assertInstanceOf( 'WP_Error', $second );
		$this->assertSame( 'wp_mcp_ai_duplicate_lead', $second->get_error_code() );
		$this->assertSame( $first['lead_id'], $second->get_error_data()['existing_lead_id'] );
	}

	/**
	 * Bulk Move Deal Stages must report per-row outcomes without aborting on
	 * malformed IDs.
	 */
	public function test_bulk_move_deal_stages_reporting(): void {
		$this->enable_crm_toolkit();
		$deal_a = $this->create_deal_fixture( 'prospecting' );
		$deal_b = $this->create_deal_fixture( 'qualification' );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Tool_Bulk_Move_Deal_Stages() )->execute(
			array(
				'deal_ids'       => array( $deal_a, $deal_b, 999999, 'bogus' ),
				'pipeline_stage' => 'qualification',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['updated'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( array( '999999', 'bogus' ), $result['not_found'] );
	}

	/**
	 * Tracked links must be created, resolved with open recording, and refuse
	 * non-http(s) destinations.
	 */
	public function test_tracked_link_lifecycle(): void {
		$this->enable_crm_toolkit();
		$deal_id = $this->create_deal_fixture( 'prospecting' );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$created = ( new WP_MCP_AI_Tool_Create_Tracked_Link() )->execute(
			array(
				'deal_id' => $deal_id,
				'url'     => 'https://example.com/proposal.pdf',
				'label'   => 'Proposal PDF',
			),
			array( 'user_id' => $user_id )
		);
		$this->assertTrue( $created['success'] );
		$this->assertStringContainsString( 'nvoos_track=', $created['tracking_url'] );

		$this->assertSame( 'https://example.com/proposal.pdf', WP_MCP_AI_CRM_Link_Tracker::resolve( $created['token'] ) );

		$registry = get_option( 'wp_mcp_ai_crm_link_registry', array() );
		$this->assertSame( 1, $registry[ $created['token'] ]['opens'] );
		$this->assertSame( '', WP_MCP_AI_CRM_Link_Tracker::resolve( 'nope' ) );

		$bad = ( new WP_MCP_AI_Tool_Create_Tracked_Link() )->execute(
			array(
				'deal_id' => $deal_id,
				'url'     => 'javascript:alert(1)',
			),
			array( 'user_id' => $user_id )
		);
		$this->assertInstanceOf( 'WP_Error', $bad );
	}

	/**
	 * The handover bundle must assemble lead facts and the closing ask
	 * without any LLM call.
	 */
	public function test_handover_bundle(): void {
		$this->enable_crm_toolkit();
		$lead_id = $this->create_lead_fixture( 'handover@example.com' );
		update_post_meta( $lead_id, 'first_name', 'Hand' );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Tool_Get_CRM_Handover() )->execute(
			array(
				'entity'    => 'lead',
				'entity_id' => $lead_id,
			),
			array( 'user_id' => $user_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'handover@example.com', $result['text'] );
		$this->assertStringContainsString( 'What I need from you', $result['text'] );
	}

	/**
	 * The pipeline digest must detect stalled deals from the stage anchor.
	 */
	public function test_pipeline_digest(): void {
		$this->enable_crm_toolkit();
		$deal_id = $this->create_deal_fixture( 'prospecting' );
		update_post_meta( $deal_id, 'amount', 5000 );
		update_post_meta( $deal_id, 'stage_changed_at', gmdate( 'c', time() - ( 20 * DAY_IN_SECONDS ) ) );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Tool_Get_Pipeline_Digest() )->execute(
			array( 'stale_days' => 14 ),
			array( 'user_id' => $user_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( $deal_id, $result['data']['stalled_deals'][0]['deal_id'] );
		$this->assertEqualsWithDelta( 5000.0, $result['data']['pipeline_value'], 0.01 );
	}

	/**
	 * Standalone only: the init's tool filter must carry the adoption batch.
	 */
	public function test_adoption_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the CRM tool map inline.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_crm_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Bulk_Move_Deal_Stages', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Create_Tracked_Link', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Record_CRM_Reply', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Get_CRM_Handover', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Get_Pipeline_Digest', $tools );
		$this->assertSame(
			NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/deals/class-wp-mcp-ai-tool-bulk-move-deal-stages.php',
			$tools['WP_MCP_AI_Tool_Bulk_Move_Deal_Stages']
		);
	}

	/**
	 * Standalone only: the ecosystem registration must register the adoption
	 * batch into both registries.
	 */
	public function test_adoption_ecosystem_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		wp_mcp_ai_pro_register_crm_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['bulk_move_deal_stages'] ?? null );
		$this->assertNotNull( $parent->all()['get_crm_handover'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'create_tracked_link' ) );
		$this->assertTrue( $core_tools->has( 'record_crm_reply' ) );
		$this->assertTrue( $core_tools->has( 'get_pipeline_digest' ) );
	}

	/**
	 * Standalone only: the init must wire the tracked-link resolver
	 * (query var + front-end redirect handler).
	 */
	public function test_link_tracker_init_wiring_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base Pro init owns the wiring.' );
		}

		$this->enable_crm_toolkit();
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/crm/init.php';
		// Re-arm the tracker hooks — the WP test framework's hook-snapshot
		// restore may have removed registrations made during an earlier
		// test's module boot.
		WP_MCP_AI_CRM_Link_Tracker::init();

		$this->assertContains( 'nvoos_track', apply_filters( 'query_vars', array() ) );
		$this->assertNotFalse( has_action( 'template_redirect' ) );
	}
}
