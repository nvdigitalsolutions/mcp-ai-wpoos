<?php
/**
 * Test CRM Toolkit — JobNavigator Adoption Features
 *
 * Covers the features adopted from vesaias/JobNavigator:
 *  - WP1: Deal stage transition history, source attribution, undo.
 *  - WP2: Lead dedup with pointer, canonical companies, email signals.
 *  - WP3: Auto-disqualification rules.
 *  - WP4: Bulk stage moves with per-row reporting.
 *  - WP5: Reply signals (record_crm_reply).
 *  - WP6: Handover bundle (get_crm_handover).
 *  - WP7: Pipeline digest (get_pipeline_digest).
 *  - WP8: Tracked proposal links + open recording.
 *  - WP9: Delete-deal lead release cascade.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.2.0
 */

/**
 * Test case for the JobNavigator-adoption CRM features.
 *
 * @since 3.2.0
 */
class Test_CRM_JobNavigator_Adoption extends WP_UnitTestCase {

	/**
	 * Lead CPT post IDs created during tests.
	 *
	 * @var int[]
	 */
	private $test_lead_ids = array();

	/**
	 * Deal CPT post IDs created during tests.
	 *
	 * @var int[]
	 */
	private $test_deal_ids = array();

	/**
	 * Company CPT post IDs created during tests.
	 *
	 * @var int[]
	 */
	private $test_company_ids = array();

	/**
	 * Admin user ID used as the current user.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * SetUp.
	 */
	public function setUp(): void {
		parent::setUp();

		// Enable CRM toolkit.
		$settings                       = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_crm_toolkit'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		// Load the shared CRM engine classes when this suite runs in isolation.
		if ( ! class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$crm_dir      = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/';
			$engine_files = array(
				'class-wp-mcp-ai-crm-engine.php',
				'class-wp-mcp-ai-crm-codes.php',
				'class-wp-mcp-ai-crm-audit.php',
				'class-wp-mcp-ai-crm-capabilities.php',
				'class-wp-mcp-ai-crm-consent.php',
				'class-wp-mcp-ai-crm-pipeline-stages.php',
				'class-wp-mcp-ai-crm-classifier.php',
				'class-wp-mcp-ai-crm-stage-history.php',
				'class-wp-mcp-ai-crm-identity.php',
				'class-wp-mcp-ai-crm-link-tracker.php',
				'inbound/class-wp-mcp-ai-crm-gmail-reply-poller.php',
				'inbound/class-wp-mcp-ai-tool-record-crm-reply.php',
			);
			foreach ( $engine_files as $file ) {
				$path = $crm_dir . $file;
				if ( file_exists( $path ) ) {
					require_once $path;
				}
			}
		}

		// Register the CRM post types directly (init has already fired).
		$cpt_map = array(
			'WP_MCP_AI_Lead_CPT'              => 'mcp_ai_lead',
			'WP_MCP_AI_Deal_CPT'              => 'mcp_ai_deal',
			'WP_MCP_AI_CRM_Activity_CPT'      => 'mcp_ai_crm_activity',
			'WP_MCP_AI_Company_CPT'           => 'mcp_ai_company',
		);
		foreach ( $cpt_map as $class_name => $post_type ) {
			if ( post_type_exists( $post_type ) ) {
				continue;
			}
			if ( ! class_exists( $class_name ) ) {
				$file = WP_MCP_AI_PRO_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
				if ( file_exists( $file ) ) {
					require_once $file;
				}
			}
			if ( class_exists( $class_name ) && method_exists( $class_name, 'register_post_type' ) ) {
				$class_name::register_post_type();
			}
		}

		$this->admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		// Reset engine settings cache so tests observe their own writes.
		if ( method_exists( 'WP_MCP_AI_CRM_Engine', 'flush_settings_cache' ) ) {
			WP_MCP_AI_CRM_Engine::flush_settings_cache();
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Link_Tracker' ) ) {
			delete_option( WP_MCP_AI_CRM_Link_Tracker::REGISTRY_OPTION );
		}
	}

	/**
	 * TearDown.
	 */
	public function tearDown(): void {
		foreach ( $this->test_deal_ids as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $this->test_lead_ids as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $this->test_company_ids as $id ) {
			wp_delete_post( $id, true );
		}
		if ( class_exists( 'WP_MCP_AI_CRM_Link_Tracker' ) ) {
			delete_option( WP_MCP_AI_CRM_Link_Tracker::REGISTRY_OPTION );
		}
		if ( method_exists( 'WP_MCP_AI_CRM_Engine', 'flush_settings_cache' ) ) {
			WP_MCP_AI_CRM_Engine::flush_settings_cache();
		}
		parent::tearDown();
	}

	// ────────────────────────────────────────────────────────
	// WP1 — Stage transition history + undo
	// ────────────────────────────────────────────────────────

	/**
	 * Test record/get history and time-in-stage.
	 */
	public function test_stage_history_record_and_get() {
		$deal_id = $this->create_test_deal( 'prospecting' );

		$this->assertTrue( WP_MCP_AI_CRM_Stage_History::record( $deal_id, 'prospecting', 'qualification', 'agent' ) );

		$history = WP_MCP_AI_CRM_Stage_History::get_history( $deal_id );
		$this->assertCount( 2, $history ); // Seed entry + the recorded move.
		$last = $history[ count( $history ) - 1 ];
		$this->assertSame( 'prospecting', $last['from'] );
		$this->assertSame( 'qualification', $last['to'] );
		$this->assertSame( 'agent', $last['source'] );

		$this->assertNotNull( WP_MCP_AI_CRM_Stage_History::time_in_stage( $deal_id ) );
		$this->assertNotNull( get_post_meta( $deal_id, 'stage_changed_at', true ) );
	}

	/**
	 * Test undo pops the last transition without recording a new one.
	 */
	public function test_stage_history_undo() {
		$deal_id = $this->create_test_deal( 'prospecting' );
		WP_MCP_AI_CRM_Stage_History::record( $deal_id, 'prospecting', 'qualification', 'tool' );
		WP_MCP_AI_CRM_Stage_History::record( $deal_id, 'qualification', 'proposal', 'tool' );

		$popped = WP_MCP_AI_CRM_Stage_History::undo_last( $deal_id );
		$this->assertSame( 'qualification', $popped['from'] );

		$history = WP_MCP_AI_CRM_Stage_History::get_history( $deal_id );
		$this->assertCount( 2, $history );
		$this->assertSame( 'qualification', $history[1]['to'] );

		// Second pop removes the first recorded move.
		$second = WP_MCP_AI_CRM_Stage_History::undo_last( $deal_id );
		$this->assertSame( 'prospecting', $second['from'] );

		// Third pop removes the seed entry (from is empty).
		$seed = WP_MCP_AI_CRM_Stage_History::undo_last( $deal_id );
		$this->assertNotNull( $seed );
		$this->assertSame( '', $seed['from'] );

		// Ledger is now empty.
		$this->assertNull( WP_MCP_AI_CRM_Stage_History::undo_last( $deal_id ) );
	}

	/**
	 * Test next_open_stage progression skips closed stages.
	 */
	public function test_next_open_stage() {
		$this->assertSame( 'qualification', WP_MCP_AI_CRM_Stage_History::next_open_stage( 'prospecting' ) );
		$this->assertNull( WP_MCP_AI_CRM_Stage_History::next_open_stage( 'negotiation' ) );
		$this->assertSame( 'prospecting', WP_MCP_AI_CRM_Stage_History::next_open_stage( 'not-a-stage' ) );
	}

	/**
	 * Test move_deal_stage records history with source and supports undo.
	 */
	public function test_move_deal_stage_source_and_undo() {
		$deal_id = $this->create_test_deal( 'prospecting' );

		$moved = ( new WP_MCP_AI_Tool_Move_Deal_Stage() )->execute(
			array(
				'deal_id'   => $deal_id,
				'new_stage' => 'qualification',
				'source'    => 'email_reply',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertTrue( $moved['success'] );
		$this->assertSame( 'email_reply', $moved['source'] );

		// Seed entry + the recorded move.
		$history = WP_MCP_AI_CRM_Stage_History::get_history( $deal_id );
		$this->assertCount( 2, $history );
		$this->assertSame( 'email_reply', $history[1]['source'] );

		// Undo the move.
		$undone = ( new WP_MCP_AI_Tool_Move_Deal_Stage() )->execute(
			array(
				'deal_id'   => $deal_id,
				'new_stage' => 'qualification',
				'undo'      => true,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertTrue( $undone['success'] );
		$this->assertSame( 'prospecting', $undone['restored_stage'] );
		$this->assertSame( 'prospecting', get_post_meta( $deal_id, 'pipeline_stage', true ) );

		// Only the seed entry may remain — undo records no new transition.
		$history = WP_MCP_AI_CRM_Stage_History::get_history( $deal_id );
		$this->assertCount( 1, $history );
		$this->assertSame( '', $history[0]['from'] );

		// A second undo has nothing to revert.
		$again = ( new WP_MCP_AI_Tool_Move_Deal_Stage() )->execute(
			array(
				'deal_id'   => $deal_id,
				'new_stage' => 'qualification',
				'undo'      => true,
			),
			array( 'user_id' => $this->admin_user_id )
		);
		$this->assertWPError( $again );
		$this->assertSame( 'no_stage_history', $again->get_error_code() );
	}

	/**
	 * Test no-op moves leave history and updated_at untouched.
	 */
	public function test_move_deal_stage_noop_preserves_signals() {
		$deal_id = $this->create_test_deal( 'prospecting' );

		$before_modified = get_post( $deal_id )->post_modified_gmt;
		// Sleep a moment so an accidental bump would be visible.
		sleep( 1 );

		$result = ( new WP_MCP_AI_Tool_Move_Deal_Stage() )->execute(
			array(
				'deal_id'   => $deal_id,
				'new_stage' => 'prospecting',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'already_at_stage', $result->get_error_code() );
		$this->assertSame( $before_modified, get_post( $deal_id )->post_modified_gmt );

		// Only the seed entry may exist — the no-op records nothing.
		$history = WP_MCP_AI_CRM_Stage_History::get_history( $deal_id );
		$this->assertCount( 1, $history );
		$this->assertSame( '', $history[0]['from'] );
	}

	// ────────────────────────────────────────────────────────
	// WP2 — Dedup, canonical companies, email signals
	// ────────────────────────────────────────────────────────

	/**
	 * Test create_lead refuses duplicates with a pointer.
	 */
	public function test_create_lead_duplicate_refusal() {
		$tool = new WP_MCP_AI_Tool_Create_Lead();

		$first = $tool->execute(
			array( 'email' => 'dupe@example.com' ),
			array( 'user_id' => $this->admin_user_id )
		);
		$this->assertTrue( $first['success'] );
		$this->test_lead_ids[] = $first['lead_id'];

		$second = $tool->execute(
			array( 'email' => 'DUPE@example.com' ),
			array( 'user_id' => $this->admin_user_id )
		);
		$this->assertWPError( $second );
		$this->assertSame( 'wp_mcp_ai_duplicate_lead', $second->get_error_code() );
		$this->assertSame( $first['lead_id'], $second->get_error_data()['existing_lead_id'] );

		// allow_duplicate passes through.
		$third = $tool->execute(
			array(
				'email'           => 'dupe@example.com',
				'allow_duplicate' => true,
			),
			array( 'user_id' => $this->admin_user_id )
		);
		$this->assertTrue( $third['success'] );
		$this->test_lead_ids[] = $third['lead_id'];
	}

	/**
	 * Test company canonicalization, auto-create, and domain gate.
	 */
	public function test_company_link_and_canonicalization() {
		$company_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_company',
				'post_title'  => 'Acme Corporation',
				'post_status' => 'publish',
			)
		);
		$this->test_company_ids[] = $company_id;
		update_post_meta( $company_id, WP_MCP_AI_CRM_Identity::META_COMPANY_CANONICAL, 'acme corporation' );

		$lead_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_title'  => 'Canonical Lead',
				'post_status' => 'publish',
			)
		);
		$this->test_lead_ids[] = $lead_id;

		// Case/space variant must match the same company.
		$linked = WP_MCP_AI_CRM_Identity::link_or_create_company( $lead_id, '  ACME   corporation ', '' );
		$this->assertSame( $company_id, $linked );
		$this->assertSame( $company_id, absint( get_post_meta( $lead_id, 'company_id', true ) ) );
	}

	/**
	 * Test update_lead stores email reply signals.
	 */
	public function test_update_lead_email_signals() {
		$lead_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_title'  => 'Signal Lead',
				'post_status' => 'publish',
			)
		);
		$this->test_lead_ids[] = $lead_id;
		update_post_meta( $lead_id, 'email', 'signal@example.com' );

		$result = ( new WP_MCP_AI_Tool_Update_Lead() )->execute(
			array(
				'lead_id'              => $lead_id,
				'last_email_received'  => '2026-09-16T10:00:00+00:00',
				'last_email_snippet'   => 'Looking forward to the proposal.',
				'last_email_sentiment' => 'positive',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'positive', get_post_meta( $lead_id, 'last_email_sentiment', true ) );
		$this->assertSame( 'Looking forward to the proposal.', get_post_meta( $lead_id, 'last_email_snippet', true ) );

		// Invalid sentiment rejected.
		$bad = ( new WP_MCP_AI_Tool_Update_Lead() )->execute(
			array(
				'lead_id'              => $lead_id,
				'last_email_sentiment' => 'ecstatic',
			),
			array( 'user_id' => $this->admin_user_id )
		);
		$this->assertWPError( $bad );
	}

	// ────────────────────────────────────────────────────────
	// WP3 — Auto-disqualification
	// ────────────────────────────────────────────────────────

	/**
	 * Test auto-disqualification rules.
	 */
	public function test_auto_disqualify() {
		$lead_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_title'  => 'Cold Lead',
				'post_status' => 'publish',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) ),
			)
		);
		$this->test_lead_ids[] = $lead_id;
		update_post_meta( $lead_id, 'lead_score', 10 );
		update_post_meta( $lead_id, 'lead_status', 'new' );

		$crm_settings                          = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
		$crm_settings['auto_disqualify']       = array(
			'enabled'       => true,
			'max_score'     => 20,
			'min_age_days'  => 30,
			'only_statuses' => array( 'new', 'contacted' ),
		);
		update_option( WP_MCP_AI_CRM_Engine::SETTINGS_OPTION, $crm_settings );
		WP_MCP_AI_CRM_Engine::flush_settings_cache();

		$fired = 0;
		add_action(
			'wp_mcp_ai_crm_lead_auto_disqualified',
			function () use ( &$fired ) {
				$fired++;
			}
		);

		$this->assertTrue( WP_MCP_AI_CRM_Engine::maybe_auto_disqualify( $lead_id ) );
		$this->assertSame( 'disqualified', get_post_meta( $lead_id, 'lead_status', true ) );
		$this->assertSame( 1, $fired );

		// High score is never disqualified.
		$hot_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_title'  => 'Hot Lead',
				'post_status' => 'publish',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) ),
			)
		);
		$this->test_lead_ids[] = $hot_id;
		update_post_meta( $hot_id, 'lead_score', 80 );
		update_post_meta( $hot_id, 'lead_status', 'new' );
		$this->assertFalse( WP_MCP_AI_CRM_Engine::maybe_auto_disqualify( $hot_id ) );
	}

	// ────────────────────────────────────────────────────────
	// WP4 — Bulk stage moves
	// ────────────────────────────────────────────────────────

	/**
	 * Test bulk move with per-row reporting.
	 */
	public function test_bulk_move_deal_stages() {
		$deal_a = $this->create_test_deal( 'prospecting' );
		$deal_b = $this->create_test_deal( 'qualification' );
		$deal_c = $this->create_test_deal( 'prospecting' );

		$result = ( new WP_MCP_AI_Tool_Bulk_Move_Deal_Stages() )->execute(
			array(
				'deal_ids'       => array( $deal_a, $deal_b, 999999, 'bogus' ),
				'pipeline_stage' => 'qualification',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['updated'] );    // deal_a moved.
		$this->assertSame( 1, $result['skipped'] );    // deal_b already there.
		$this->assertSame( array( '999999', 'bogus' ), $result['not_found'] );
		$this->assertSame( 'qualification', get_post_meta( $deal_a, 'pipeline_stage', true ) );
		$this->assertSame( 'qualification', get_post_meta( $deal_b, 'pipeline_stage', true ) );

		// deal_c untouched.
		$this->assertSame( 'prospecting', get_post_meta( $deal_c, 'pipeline_stage', true ) );

		// History recorded with bulk source.
		$history = WP_MCP_AI_CRM_Stage_History::get_history( $deal_a );
		$this->assertSame( 'bulk', $history[ count( $history ) - 1 ]['source'] );
	}

	// ────────────────────────────────────────────────────────
	// WP5 — Reply signals
	// ────────────────────────────────────────────────────────

	/**
	 * Test record_crm_reply spreads signals and advances a deal.
	 */
	public function test_record_crm_reply() {
		$lead_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_title'  => 'Reply Lead',
				'post_status' => 'publish',
			)
		);
		$this->test_lead_ids[] = $lead_id;
		update_post_meta( $lead_id, 'email', 'reply@example.com' );

		$deal_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_deal',
				'post_title'  => 'Reply Deal',
				'post_status' => 'publish',
			)
		);
		$this->test_deal_ids[] = $deal_id;
		update_post_meta( $deal_id, 'lead_id', $lead_id );
		update_post_meta( $deal_id, 'pipeline_stage', 'prospecting' );
		WP_MCP_AI_CRM_Stage_History::record( $deal_id, null, 'prospecting', 'tool' );

		$result = ( new WP_MCP_AI_Tool_Record_CRM_Reply() )->execute(
			array(
				'lead_id'      => $lead_id,
				'deal_id'      => $deal_id,
				'snippet'      => 'Send the proposal today.',
				'sentiment'    => 'positive',
				'advance_deal' => true,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'positive', get_post_meta( $lead_id, 'last_email_sentiment', true ) );
		$this->assertSame( 'positive', get_post_meta( $deal_id, 'last_email_sentiment', true ) );

		// Advanced one open stage with email_reply source.
		$this->assertSame( 'qualification', get_post_meta( $deal_id, 'pipeline_stage', true ) );
		$history = WP_MCP_AI_CRM_Stage_History::get_history( $deal_id );
		$this->assertSame( 'email_reply', $history[ count( $history ) - 1 ]['source'] );
	}

	// ────────────────────────────────────────────────────────
	// WP6 — Handover bundle
	// ────────────────────────────────────────────────────────

	/**
	 * Test handover bundle for a lead.
	 */
	public function test_handover_lead() {
		$lead_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_title'  => 'Handover Lead',
				'post_status' => 'publish',
			)
		);
		$this->test_lead_ids[] = $lead_id;
		update_post_meta( $lead_id, 'email', 'handover@example.com' );
		update_post_meta( $lead_id, 'first_name', 'Hand' );
		update_post_meta( $lead_id, 'last_name', 'Over' );
		update_post_meta( $lead_id, 'lead_score', 60 );

		$result = ( new WP_MCP_AI_Tool_Get_CRM_Handover() )->execute(
			array(
				'entity'    => 'lead',
				'entity_id' => $lead_id,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'handover@example.com', $result['text'] );
		$this->assertStringContainsString( 'What I need from you', $result['text'] );

		$missing = ( new WP_MCP_AI_Tool_Get_CRM_Handover() )->execute(
			array(
				'entity'    => 'lead',
				'entity_id' => 999999,
			),
			array( 'user_id' => $this->admin_user_id )
		);
		$this->assertWPError( $missing );
	}

	/**
	 * Test handover bundle for a deal includes stage history.
	 */
	public function test_handover_deal() {
		$deal_id = $this->create_test_deal( 'prospecting' );
		WP_MCP_AI_CRM_Stage_History::record( $deal_id, 'prospecting', 'qualification', 'tool' );

		$result = ( new WP_MCP_AI_Tool_Get_CRM_Handover() )->execute(
			array(
				'entity'    => 'deal',
				'entity_id' => $deal_id,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( '## Stage history', $result['text'] );
		$this->assertStringContainsString( 'qualification', $result['text'] );
	}

	// ────────────────────────────────────────────────────────
	// WP7 — Pipeline digest
	// ────────────────────────────────────────────────────────

	/**
	 * Test pipeline digest totals and stalled detection.
	 */
	public function test_pipeline_digest() {
		$deal_id = $this->create_test_deal( 'prospecting' );
		update_post_meta( $deal_id, 'amount', 5000 );
		// Make the deal appear stalled: backdate the stage anchor.
		update_post_meta( $deal_id, 'stage_changed_at', gmdate( 'c', time() - ( 20 * DAY_IN_SECONDS ) ) );

		$result = ( new WP_MCP_AI_Tool_Get_Pipeline_Digest() )->execute(
			array( 'stale_days' => 14 ),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'Pipeline Digest', $result['text'] );
		$this->assertStringContainsString( 'Stalled deals', $result['text'] );
		$this->assertSame( 1, $result['data']['stage_totals']['prospecting']['count'] );
		$this->assertEqualsWithDelta( 5000.0, $result['data']['pipeline_value'], 0.01 );
		$this->assertSame( $deal_id, $result['data']['stalled_deals'][0]['deal_id'] );
	}

	// ────────────────────────────────────────────────────────
	// WP8 — Tracked links
	// ────────────────────────────────────────────────────────

	/**
	 * Test tracked link creation and open recording.
	 */
	public function test_tracked_link_lifecycle() {
		$deal_id = $this->create_test_deal( 'prospecting' );

		$created = ( new WP_MCP_AI_Tool_Create_Tracked_Link() )->execute(
			array(
				'deal_id' => $deal_id,
				'url'     => 'https://example.com/proposal.pdf',
				'label'   => 'Proposal PDF',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertTrue( $created['success'] );
		$this->assertStringContainsString( 'nvoos_track=', $created['tracking_url'] );
		$token = $created['token'];

		// Resolve twice: opens must accumulate.
		$this->assertSame( 'https://example.com/proposal.pdf', WP_MCP_AI_CRM_Link_Tracker::resolve( $token ) );
		$this->assertSame( 'https://example.com/proposal.pdf', WP_MCP_AI_CRM_Link_Tracker::resolve( $token ) );

		$registry = get_option( WP_MCP_AI_CRM_Link_Tracker::REGISTRY_OPTION, array() );
		$this->assertSame( 2, $registry[ $token ]['opens'] );
		$this->assertNotEmpty( $registry[ $token ]['last_opened_at'] );

		$links = get_post_meta( $deal_id, 'tracked_links', true );
		$this->assertSame( 2, $links[0]['opens'] );

		// Unknown tokens resolve to nothing.
		$this->assertSame( '', WP_MCP_AI_CRM_Link_Tracker::resolve( 'nope' ) );

		// Invalid URL refused.
		$bad = ( new WP_MCP_AI_Tool_Create_Tracked_Link() )->execute(
			array(
				'deal_id' => $deal_id,
				'url'     => 'javascript:alert(1)',
			),
			array( 'user_id' => $this->admin_user_id )
		);
		$this->assertWPError( $bad );
	}

	// ────────────────────────────────────────────────────────
	// WP9 — Delete-deal cascade
	// ────────────────────────────────────────────────────────

	/**
	 * Test deleting the only won deal releases the lead.
	 */
	public function test_delete_won_deal_releases_lead() {
		$lead_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_title'  => 'Won Lead',
				'post_status' => 'publish',
			)
		);
		$this->test_lead_ids[] = $lead_id;
		update_post_meta( $lead_id, 'lifecycle_stage', 'customer' );

		$deal_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_deal',
				'post_title'  => 'Won Deal',
				'post_status' => 'publish',
			)
		);
		$this->test_deal_ids[] = $deal_id;
		update_post_meta( $deal_id, 'lead_id', $lead_id );
		update_post_meta( $deal_id, 'pipeline_stage', 'closed_won' );

		$fired = 0;
		add_action(
			'wp_mcp_ai_crm_deal_deleted',
			function () use ( &$fired ) {
				$fired++;
			}
		);

		$result = ( new WP_MCP_AI_Tool_Delete_Deal() )->execute(
			array(
				'deal_id' => $deal_id,
				'confirm' => true,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['lead_released'] );
		$this->assertSame( 'opportunity', get_post_meta( $lead_id, 'lifecycle_stage', true ) );
		$this->assertSame( 1, $fired );
	}

	// ────────────────────────────────────────────────────────
	// WP5b — Gmail reply poller
	// ────────────────────────────────────────────────────────

	/**
	 * Test record_crm_reply's static apply path (cron-safe core).
	 */
	public function test_record_crm_reply_static_apply() {
		$lead_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_title'  => 'Apply Lead',
				'post_status' => 'publish',
			)
		);
		$this->test_lead_ids[] = $lead_id;
		update_post_meta( $lead_id, 'email', 'apply@example.com' );

		$deal_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_deal',
				'post_title'  => 'Apply Deal',
				'post_status' => 'publish',
			)
		);
		$this->test_deal_ids[] = $deal_id;
		update_post_meta( $deal_id, 'lead_id', $lead_id );
		update_post_meta( $deal_id, 'pipeline_stage', 'prospecting' );
		WP_MCP_AI_CRM_Stage_History::record( $deal_id, null, 'prospecting', 'tool' );

		// Static apply runs without a capability check (cron context).
		$applied = WP_MCP_AI_Tool_Record_CRM_Reply::apply(
			$lead_id,
			$deal_id,
			'Send the proposal today.',
			'positive',
			gmdate( 'c' ),
			true
		);

		$this->assertIsArray( $applied );
		$this->assertSame( 'positive', get_post_meta( $lead_id, 'last_email_sentiment', true ) );
		$this->assertSame( 'qualification', get_post_meta( $deal_id, 'pipeline_stage', true ) );

		// Unknown lead refused.
		$bad = WP_MCP_AI_Tool_Record_CRM_Reply::apply( 999999, 0, 'x', 'neutral', gmdate( 'c' ), false );
		$this->assertWPError( $bad );
	}

	/**
	 * Test the poller is disabled by default and reports the disabled status.
	 */
	public function test_gmail_reply_poller_disabled_by_default() {
		$summary = WP_MCP_AI_CRM_Gmail_Reply_Poller::run();
		$this->assertSame( 'disabled', $summary['status'] );
		$this->assertSame( 0, $summary['replies'] );
	}

	/**
	 * Test an enabled poll degrades gracefully with no Gmail connections.
	 */
	public function test_gmail_reply_poller_no_connections() {
		$crm_settings                      = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
		$crm_settings['gmail_reply_poll']  = array(
			'enabled'              => true,
			'advance_on_positive'  => false,
			'max_per_poll'         => 10,
			'min_interval_minutes' => 15,
		);
		update_option( WP_MCP_AI_CRM_Engine::SETTINGS_OPTION, $crm_settings );
		WP_MCP_AI_CRM_Engine::flush_settings_cache();

		$summary = WP_MCP_AI_CRM_Gmail_Reply_Poller::run();
		$this->assertSame( 'complete', $summary['status'] );
		$this->assertSame( 0, $summary['replies'] );
		$this->assertNotEmpty( get_option( WP_MCP_AI_CRM_Gmail_Reply_Poller::OPTION_LAST_POLL, '' ) );
	}

	/**
	 * Test scheduling follows the settings gate and unschedules when off.
	 */
	public function test_gmail_reply_poller_scheduling() {
		// Hook wiring.
		WP_MCP_AI_CRM_Gmail_Reply_Poller::init();
		$this->assertNotFalse( has_action( WP_MCP_AI_CRM_Gmail_Reply_Poller::CRON_HOOK ) );

		// Disabled: nothing scheduled.
		WP_MCP_AI_CRM_Gmail_Reply_Poller::maybe_schedule();
		$this->assertFalse( wp_next_scheduled( WP_MCP_AI_CRM_Gmail_Reply_Poller::CRON_HOOK ) );

		// Enabled: the event is scheduled.
		$crm_settings                      = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
		$crm_settings['gmail_reply_poll']  = array(
			'enabled'              => true,
			'advance_on_positive'  => false,
			'max_per_poll'         => 10,
			'min_interval_minutes' => 15,
		);
		update_option( WP_MCP_AI_CRM_Engine::SETTINGS_OPTION, $crm_settings );
		WP_MCP_AI_CRM_Engine::flush_settings_cache();

		WP_MCP_AI_CRM_Gmail_Reply_Poller::maybe_schedule();
		$this->assertNotFalse( wp_next_scheduled( WP_MCP_AI_CRM_Gmail_Reply_Poller::CRON_HOOK ) );

		// Unschedule removes the event.
		WP_MCP_AI_CRM_Gmail_Reply_Poller::unschedule();
		$this->assertFalse( wp_next_scheduled( WP_MCP_AI_CRM_Gmail_Reply_Poller::CRON_HOOK ) );

		delete_option( WP_MCP_AI_CRM_Gmail_Reply_Poller::OPTION_LAST_POLL );
	}

	// ────────────────────────────────────────────────────────
	// Helpers
	// ────────────────────────────────────────────────────────

	/**
	 * Create a test deal with a seeded stage history.
	 *
	 * @param string $stage Initial pipeline stage.
	 * @return int
	 */
	private function create_test_deal( string $stage ): int {
		$lead_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_lead',
				'post_title'  => 'Test Lead ' . wp_rand(),
				'post_status' => 'publish',
			)
		);
		$this->test_lead_ids[] = $lead_id;
		update_post_meta( $lead_id, 'email', 'lead' . wp_rand() . '@example.com' );

		$deal_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_deal',
				'post_title'  => 'Test Deal ' . wp_rand(),
				'post_status' => 'publish',
			)
		);
		$this->test_deal_ids[] = $deal_id;
		update_post_meta( $deal_id, 'lead_id', $lead_id );
		update_post_meta( $deal_id, 'pipeline_stage', $stage );
		WP_MCP_AI_CRM_Stage_History::record( $deal_id, null, $stage, 'tool' );

		return $deal_id;
	}
}
