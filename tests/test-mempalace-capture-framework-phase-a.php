<?php
/**
 * Tests for the MemPalace Capture Framework Phase A.
 *
 * Covers:
 *  - `WP_MCP_AI_Memory_Capture_Service::store()` envelope normalisation,
 *    wing/room enforcement, verbatim default, importance clamping, per-wing
 *    retention overrides (TTL ceiling, tier ceiling, sensitivity ceiling).
 *  - `WP_MCP_AI_Memory_Tier_Manager::evaluate()` promotion / demotion rules.
 *  - `WP_MCP_AI_Tool_Recall_Memory::execute()` hierarchical recall: wing/room
 *    pre-filter, always-include core tier, bi-temporal `as_of`.
 *  - CCT bridge propagation of new envelope fields (`sensitivity`,
 *    `consent_basis`, `subject_refs`, `attachments`).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Phase A test suite.
 */
class WP_MCP_AI_MemPalace_Capture_Framework_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		// The recall tool checks user_can( $user_id, 'read' ) before
		// validating arguments; provide an authenticated admin so the
		// suite exercises the wing/room logic instead of the permission gate.
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		// Skip the transient persistence leg in the headless test env so we
		// can observe the canonical event payload without depending on the
		// agent context manager singleton.
		add_filter( 'wp_mcp_ai_memory_capture_skip_transient', '__return_true' );
		delete_option( WP_MCP_AI_Memory_Capture_Service::RETENTION_OPTION );
	}

	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_memory_capture_skip_transient' );
		remove_all_filters( 'wp_mcp_ai_wing_retention_overrides' );
		remove_all_filters( 'wp_mcp_ai_recall_memory_candidates' );
		delete_option( WP_MCP_AI_Memory_Capture_Service::RETENTION_OPTION );
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// Memory Capture Service
	// -----------------------------------------------------------------

	public function test_store_requires_wing_and_room() {
		$service = WP_MCP_AI_Memory_Capture_Service::get_instance();

		$missing_wing = $service->store(
			array(
				'agent_id' => 'agent_1',
				'room'     => 'vitals',
				'content'  => 'BP 120/80',
			)
		);
		$this->assertFalse( $missing_wing['success'] );
		$this->assertSame( 'mempalace_capture_missing_wing', $missing_wing['code'] );

		$missing_room = $service->store(
			array(
				'agent_id' => 'agent_1',
				'wing'     => 'patient/jane-doe',
				'content'  => 'BP 120/80',
			)
		);
		$this->assertFalse( $missing_room['success'] );
		$this->assertSame( 'mempalace_capture_missing_room', $missing_room['code'] );
	}

	public function test_store_defaults_verbatim_true_and_recall_tier() {
		$captured = null;
		add_action(
			'wp_mcp_ai_memory_stored',
			function ( $event ) use ( &$captured ) {
				$captured = $event;
			}
		);

		$result = WP_MCP_AI_Memory_Capture_Service::get_instance()->store(
			array(
				'agent_id' => 'agent_1',
				'wing'     => 'patient/jane-doe',
				'room'     => 'vitals',
				'content'  => 'BP 120/80',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( WP_MCP_AI_Memory_Capture_Service::TIER_RECALL, $result['tier'] );
		$this->assertNotNull( $captured );
		$this->assertTrue( $captured['verbatim'] );
		$this->assertSame( 'patient/jane-doe', $captured['wing'] );
		$this->assertSame( 'vitals', $captured['room'] );
		$this->assertEqualsWithDelta( 0.5, $captured['importance'], 0.0001 );
	}

	public function test_store_clamps_importance_to_unit_interval() {
		$captured = null;
		add_action(
			'wp_mcp_ai_memory_stored',
			function ( $event ) use ( &$captured ) {
				$captured = $event;
			}
		);

		WP_MCP_AI_Memory_Capture_Service::get_instance()->store(
			array(
				'agent_id'   => 'agent_1',
				'wing'       => 'matter/123',
				'room'       => 'pleadings',
				'content'    => 'Filed motion to compel.',
				'importance' => 7.5,
			)
		);
		$this->assertEqualsWithDelta( 1.0, $captured['importance'], 0.0001 );

		WP_MCP_AI_Memory_Capture_Service::get_instance()->store(
			array(
				'agent_id'   => 'agent_1',
				'wing'       => 'matter/123',
				'room'       => 'pleadings',
				'content'    => 'Filed motion to compel.',
				'importance' => -3,
			)
		);
		$this->assertEqualsWithDelta( 0.0, $captured['importance'], 0.0001 );
	}

	public function test_per_wing_overrides_cap_ttl_tier_and_sensitivity() {
		update_option(
			WP_MCP_AI_Memory_Capture_Service::RETENTION_OPTION,
			array(
				'patient/jane-doe' => array(
					'ttl'                  => 3600,    // 1 hour ceiling
					'tier_ceiling'         => 'recall', // never promote to core
					'sensitivity_ceiling'  => 'phi',    // floor sensitivity at PHI
					'consent_basis_default'=> 'consent',
				),
			)
		);

		$captured = null;
		add_action(
			'wp_mcp_ai_memory_stored',
			function ( $event ) use ( &$captured ) {
				$captured = $event;
			}
		);

		WP_MCP_AI_Memory_Capture_Service::get_instance()->store(
			array(
				'agent_id'    => 'agent_1',
				'wing'        => 'patient/jane-doe',
				'room'        => 'vitals',
				'content'     => 'BP 120/80',
				'tier'        => 'core',          // requested but capped
				'ttl'         => DAY_IN_SECONDS,  // requested 24h, capped to 1h
				'sensitivity' => 'internal',      // raised to phi by ceiling
			)
		);

		$this->assertSame( 'recall', $captured['tier'], 'tier must be capped' );
		$this->assertLessThanOrEqual( 3600, $captured['ttl'], 'ttl must be capped' );
		$this->assertSame( 'phi', $captured['sensitivity'], 'sensitivity must be raised' );
		$this->assertSame( 'consent', $captured['consent_basis'], 'consent default applies' );
	}

	public function test_per_wing_prefix_wildcard_overrides() {
		update_option(
			WP_MCP_AI_Memory_Capture_Service::RETENTION_OPTION,
			array(
				'patient/*' => array(
					'tier_ceiling'        => 'recall',
					'sensitivity_ceiling' => 'phi',
				),
			)
		);

		$captured = null;
		add_action(
			'wp_mcp_ai_memory_stored',
			function ( $event ) use ( &$captured ) {
				$captured = $event;
			}
		);

		WP_MCP_AI_Memory_Capture_Service::get_instance()->store(
			array(
				'agent_id'    => 'agent_1',
				'wing'        => 'patient/john-smith',
				'room'        => 'vitals',
				'content'     => 'HR 72',
				'tier'        => 'core',
				'sensitivity' => 'public',
			)
		);

		$this->assertSame( 'recall', $captured['tier'] );
		$this->assertSame( 'phi', $captured['sensitivity'] );
	}

	public function test_store_emits_subject_refs_and_attachments() {
		$captured = null;
		add_action(
			'wp_mcp_ai_memory_stored',
			function ( $event ) use ( &$captured ) {
				$captured = $event;
			}
		);

		WP_MCP_AI_Memory_Capture_Service::get_instance()->store(
			array(
				'agent_id'      => 'agent_1',
				'wing'          => 'matter/123',
				'room'          => 'pleadings',
				'content'       => 'Filed amended complaint.',
				'subject_refs'  => array( 'matter:123', 'party:plaintiff:42' ),
				'attachments'   => array(
					array( 'attachment_id' => 99, 'sha256' => 'a' . str_repeat( '0', 63 ), 'mime' => 'application/pdf' ),
				),
				'consent_basis' => 'legitimate-interest',
			)
		);

		$this->assertSame( array( 'matter:123', 'party:plaintiff:42' ), $captured['subject_refs'] );
		$this->assertCount( 1, $captured['attachments'] );
		$this->assertSame( 99, $captured['attachments'][0]['attachment_id'] );
		$this->assertSame( 'legitimate-interest', $captured['consent_basis'] );
	}

	// -----------------------------------------------------------------
	// Memory Tier Manager
	// -----------------------------------------------------------------

	public function test_tier_manager_promotes_when_importance_and_access_high() {
		$manager = WP_MCP_AI_Memory_Tier_Manager::get_instance();
		$record  = array(
			'context_id'    => 'ctx_promote_001',
			'agent_id'      => 'a1',
			'wing'          => 'project/alpha',
			'room'          => 'decisions',
			'tier'          => 'recall',
			'importance'    => 0.9,
			'access_count'  => 5,
			'last_accessed' => current_time( 'mysql' ),
		);

		$transition = $manager->evaluate( $record );
		$this->assertNotNull( $transition );
		$this->assertSame( 'promote', $transition['kind'] );
		$this->assertSame( 'core', $transition['to'] );
	}

	public function test_tier_manager_does_not_promote_on_one_signal_only() {
		$manager = WP_MCP_AI_Memory_Tier_Manager::get_instance();

		// High importance, low access — should NOT promote.
		$rec1 = array(
			'tier'          => 'recall',
			'importance'    => 0.95,
			'access_count'  => 0,
			'last_accessed' => current_time( 'mysql' ),
		);
		$this->assertNull( $manager->evaluate( $rec1 ) );

		// High access, low importance — should NOT promote.
		$rec2 = array(
			'tier'          => 'recall',
			'importance'    => 0.2,
			'access_count'  => 50,
			'last_accessed' => current_time( 'mysql' ),
		);
		$this->assertNull( $manager->evaluate( $rec2 ) );
	}

	public function test_tier_manager_demotes_inactive_core_to_recall() {
		$manager = WP_MCP_AI_Memory_Tier_Manager::get_instance();
		$record  = array(
			'tier'          => 'core',
			'importance'    => 0.95,
			'access_count'  => 100,
			'last_accessed' => gmdate( 'Y-m-d H:i:s', time() - ( 365 * DAY_IN_SECONDS ) ),
		);

		$transition = $manager->evaluate( $record );
		$this->assertNotNull( $transition );
		$this->assertSame( 'demote', $transition['kind'] );
		$this->assertSame( 'recall', $transition['to'] );
	}

	public function test_tier_manager_demotes_extended_idle_recall_to_archival() {
		$manager = WP_MCP_AI_Memory_Tier_Manager::get_instance();
		$record  = array(
			'tier'          => 'recall',
			'importance'    => 0.3,
			'access_count'  => 0,
			'last_accessed' => gmdate( 'Y-m-d H:i:s', time() - ( 400 * DAY_IN_SECONDS ) ),
			'verbatim'      => true,
		);

		$transition = $manager->evaluate( $record );
		$this->assertNotNull( $transition );
		$this->assertSame( 'archival', $transition['to'] );
		// Verbatim flag must propagate so listeners can refuse summarisation.
		$this->assertTrue( $transition['verbatim'] );
	}

	public function test_tier_sweep_emits_canonical_transition_event() {
		$events = array();
		add_action(
			'wp_mcp_ai_memory_tier_transition',
			function ( $payload ) use ( &$events ) {
				$events[] = $payload;
			}
		);

		add_filter(
			'wp_mcp_ai_memory_tier_manager_candidates',
			static function () {
				return array(
					array(
						'context_id'    => 'ctx_sweep_001',
						'agent_id'      => 'a1',
						'wing'          => 'project/alpha',
						'room'          => 'decisions',
						'tier'          => 'recall',
						'importance'    => 0.9,
						'access_count'  => 7,
						'last_accessed' => current_time( 'mysql' ),
					),
				);
			}
		);

		$summary = WP_MCP_AI_Memory_Tier_Manager::get_instance()->sweep();

		$this->assertSame( 1, $summary['promoted'] );
		$this->assertCount( 1, $events );
		$this->assertSame( 'promote', $events[0]['kind'] );
		$this->assertSame( 'recall', $events[0]['from_tier'] );
		$this->assertSame( 'core', $events[0]['to_tier'] );
		$this->assertSame( 'project/alpha', $events[0]['wing'] );

		remove_all_filters( 'wp_mcp_ai_memory_tier_manager_candidates' );
	}

	// -----------------------------------------------------------------
	// Recall Memory tool — hierarchical recall + always-on core
	// -----------------------------------------------------------------

	public function test_recall_filters_by_wing_and_includes_all_core_records() {
		$tool = new WP_MCP_AI_Tool_Recall_Memory();

		add_filter(
			'wp_mcp_ai_recall_memory_candidates',
			static function () {
				$now = current_time( 'mysql' );
				return array(
					array(
						'context_id' => 'ctx_a',
						'wing'       => 'patient/jane-doe',
						'room'       => 'vitals',
						'tier'       => 'core',
						'importance' => 0.8,
						'content'    => 'Allergic to penicillin',
						'valid_from' => $now,
					),
					array(
						'context_id' => 'ctx_b',
						'wing'       => 'patient/jane-doe',
						'room'       => 'vitals',
						'tier'       => 'recall',
						'importance' => 0.4,
						'content'    => 'BP 120/80',
						'valid_from' => $now,
					),
					array(
						'context_id' => 'ctx_c',
						'wing'       => 'patient/john-smith',  // different wing
						'room'       => 'vitals',
						'tier'       => 'core',
						'importance' => 0.9,
						'content'    => 'Allergic to sulfa',
						'valid_from' => $now,
					),
				);
			}
		);

		$result = $tool->execute(
			array(
				'agent_id' => 'agent_1',
				'wing'     => 'patient/jane-doe',
				'limit'    => 10,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['core_count'] );
		// Returned set is core ∪ ranked, all from jane-doe wing only.
		$ids = array_column( $result['memories'], 'context_id' );
		$this->assertContains( 'ctx_a', $ids );
		$this->assertContains( 'ctx_b', $ids );
		$this->assertNotContains( 'ctx_c', $ids );
	}

	public function test_recall_room_filter_narrows_pool() {
		$tool = new WP_MCP_AI_Tool_Recall_Memory();
		add_filter(
			'wp_mcp_ai_recall_memory_candidates',
			static function () {
				$now = current_time( 'mysql' );
				return array(
					array( 'context_id' => 'a', 'wing' => 'matter/1', 'room' => 'pleadings', 'tier' => 'recall', 'importance' => 0.5, 'content' => 'Motion filed', 'valid_from' => $now ),
					array( 'context_id' => 'b', 'wing' => 'matter/1', 'room' => 'billable', 'tier' => 'recall', 'importance' => 0.5, 'content' => '0.5h call', 'valid_from' => $now ),
				);
			}
		);
		$result = $tool->execute(
			array(
				'agent_id' => 'agent_1',
				'wing'     => 'matter/1',
				'room'     => 'pleadings',
			)
		);
		$ids = array_column( $result['memories'], 'context_id' );
		$this->assertSame( array( 'a' ), $ids );
	}

	public function test_recall_bi_temporal_as_of() {
		$tool = new WP_MCP_AI_Tool_Recall_Memory();
		$past = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
		$now  = current_time( 'mysql' );
		add_filter(
			'wp_mcp_ai_recall_memory_candidates',
			static function () use ( $past, $now ) {
				return array(
					// Old record, no longer valid.
					array(
						'context_id'  => 'old',
						'wing'        => 'deal/1',
						'room'        => 'covenants',
						'tier'        => 'recall',
						'importance'  => 0.5,
						'content'     => 'Initial covenant',
						'valid_from'  => $past,
						'valid_until' => $now,
					),
					// New record, currently valid.
					array(
						'context_id'  => 'new',
						'wing'        => 'deal/1',
						'room'        => 'covenants',
						'tier'        => 'recall',
						'importance'  => 0.5,
						'content'     => 'Amended covenant',
						'valid_from'  => $now,
						'valid_until' => '',
					),
				);
			}
		);

		// As-of now: only the amended covenant is valid.
		$now_result = $tool->execute(
			array(
				'agent_id' => 'agent_1',
				'wing'     => 'deal/1',
			)
		);
		$ids = array_column( $now_result['memories'], 'context_id' );
		$this->assertSame( array( 'new' ), $ids );

		// As-of in the past: only the original covenant was valid.
		$past_result = $tool->execute(
			array(
				'agent_id' => 'agent_1',
				'wing'     => 'deal/1',
				'as_of'    => gmdate( 'Y-m-d H:i:s', time() - ( 15 * DAY_IN_SECONDS ) ),
			)
		);
		$ids = array_column( $past_result['memories'], 'context_id' );
		$this->assertSame( array( 'old' ), $ids );
	}

	public function test_recall_requires_wing() {
		$tool   = new WP_MCP_AI_Tool_Recall_Memory();
		$result = $tool->execute( array( 'agent_id' => 'a' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'recall_missing_wing', $result->get_error_code() );
	}

	// -----------------------------------------------------------------
	// CCT bridge propagation of new envelope fields
	// -----------------------------------------------------------------

	public function test_cct_bridge_propagates_new_envelope_fields() {
		$record = WP_MCP_AI_Agent_Memory_CCT_Bridge::build_record_from_event(
			array(
				'context_id'    => 'ctx_e2e_phaseA',
				'agent_id'      => 'agent_1',
				'context_type'  => 'capture',
				'wing'          => 'patient/jane-doe',
				'room'          => 'vitals',
				'content'       => 'BP 120/80',
				'sensitivity'   => 'phi',
				'consent_basis' => 'consent',
				'subject_refs'  => array( 'patient:42' ),
				'attachments'   => array(
					array( 'attachment_id' => 7, 'sha256' => 'abc', 'mime' => 'image/png' ),
				),
				'stored_at'     => current_time( 'mysql' ),
				'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'ttl'           => DAY_IN_SECONDS,
			)
		);

		$this->assertSame( 'phi', $record['sensitivity'] );
		$this->assertSame( 'consent', $record['consent_basis'] );
		$this->assertNotEmpty( $record['subject_refs'] );
		$decoded = json_decode( $record['subject_refs'], true );
		$this->assertSame( array( 'patient:42' ), $decoded );
		$this->assertNotEmpty( $record['attachments'] );
	}
}
