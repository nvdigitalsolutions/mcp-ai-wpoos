<?php
/**
 * Tests for Phase C — Health & Wellness breadth.
 *
 * @package WP_MCP_AI_Pro
 */

$base = dirname( __DIR__, 4 );
require_once $base . '/includes/interfaces/interface-wp-mcp-ai-tool.php';

$wellness_dir = dirname( __DIR__, 2 ) . '/includes/tools/healthcare/wellness';
foreach ( array(
	'class-wp-mcp-ai-tool-check-member-allergies.php',
	'class-wp-mcp-ai-tool-get-health-timeline.php',
	'class-wp-mcp-ai-tool-link-prescription-to-record.php',
	'class-wp-mcp-ai-tool-verify-prescription-interactions.php',
	'class-wp-mcp-ai-tool-generate-visit-summary.php',
	'class-wp-mcp-ai-tool-merge-duplicate-members.php',
) as $file ) {
	$file_path = $wellness_dir . '/' . $file;
	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
}

/**
 * Phase C wellness tools test case.
 */
class Test_Healthcare_Wellness extends WP_UnitTestCase {

	/**
	 * Ensure required CPTs exist for the tests.
	 */
	public function setUp(): void {
		parent::setUp();
		foreach ( array( 'mcp_ai_member', 'mcp_ai_allergy', 'mcp_ai_prescription', 'mcp_ai_checkup', 'mcp_ai_med_record' ) as $pt ) {
			if ( ! post_type_exists( $pt ) ) {
				register_post_type(
					$pt,
					array(
						'public' => false,
						'label'  => $pt,
					)
				);
			}
		}
		if ( ! taxonomy_exists( 'mcp_ai_allergy_severity' ) ) {
			register_taxonomy( 'mcp_ai_allergy_severity', 'mcp_ai_allergy', array( 'public' => false ) );
		}
		// Force admin user so capability checks pass.
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	/**
	 * Check_member_allergies: matches a known allergen.
	 */
	public function test_check_member_allergies_matches_known() {
		$member_id  = self::factory()->post->create(
			array(
				'post_type'  => 'mcp_ai_member',
				'post_title' => 'Jane',
			)
		);
		$allergy_id = self::factory()->post->create(
			array(
				'post_type'  => 'mcp_ai_allergy',
				'post_title' => 'Penicillin',
			)
		);
		update_post_meta( $allergy_id, '_allergy_member_id', $member_id );
		update_post_meta( $allergy_id, '_allergy_reactions', 'rash, hives' );

		$tool   = new WP_MCP_AI_Tool_Check_Member_Allergies();
		$result = $tool->execute(
			array(
				'member_id' => $member_id,
				'allergens' => array( 'penicillin', 'peanut' ),
			)
		);
		$this->assertIsArray( $result );
		$this->assertTrue( $result['has_match'] );
		$this->assertNotEmpty( $result['matches'] );
		$this->assertContains( 'peanut', $result['unmatched_queries'] );
	}

	/**
	 * Check_member_allergies: rejects missing args.
	 */
	public function test_check_member_allergies_requires_args() {
		$tool = new WP_MCP_AI_Tool_Check_Member_Allergies();
		$this->assertInstanceOf( 'WP_Error', $tool->execute( array() ) );
		$this->assertInstanceOf( 'WP_Error', $tool->execute( array( 'member_id' => 1 ) ) );
	}

	/**
	 * Get_health_timeline: returns events from at least one source.
	 */
	public function test_get_health_timeline_collects_events() {
		$member_id = self::factory()->post->create(
			array(
				'post_type'  => 'mcp_ai_member',
				'post_title' => 'Tim',
			)
		);
		$rx_id     = self::factory()->post->create(
			array(
				'post_type'  => 'mcp_ai_prescription',
				'post_title' => 'Atorvastatin Rx',
			)
		);
		update_post_meta( $rx_id, '_prescription_member_id', $member_id );
		update_post_meta( $rx_id, '_prescription_medication_name', 'Atorvastatin' );
		update_post_meta( $rx_id, '_prescription_start_date', '2025-01-15' );
		update_post_meta( $rx_id, '_prescription_status', 'active' );

		$record_id = self::factory()->post->create(
			array(
				'post_type'  => 'mcp_ai_med_record',
				'post_title' => 'Visit Note',
			)
		);
		update_post_meta( $record_id, '_medical_record_member_id', $member_id );
		update_post_meta( $record_id, '_medical_record_date', '2025-02-10' );

		$tool   = new WP_MCP_AI_Tool_Get_Health_Timeline();
		$result = $tool->execute(
			array(
				'member_id' => $member_id,
				'order'     => 'asc',
			)
		);
		$this->assertIsArray( $result );
		$this->assertGreaterThanOrEqual( 2, count( $result['events'] ) );
		// First event (asc) should be the prescription on 2025-01-15.
		$this->assertSame( 'prescription', $result['events'][0]['event_type'] );
	}

	/**
	 * Get_health_timeline: respects event_types filter.
	 */
	public function test_get_health_timeline_filters_event_types() {
		$member_id = self::factory()->post->create( array( 'post_type' => 'mcp_ai_member' ) );
		$rx_id     = self::factory()->post->create( array( 'post_type' => 'mcp_ai_prescription' ) );
		update_post_meta( $rx_id, '_prescription_member_id', $member_id );

		$tool   = new WP_MCP_AI_Tool_Get_Health_Timeline();
		$result = $tool->execute(
			array(
				'member_id'   => $member_id,
				'event_types' => array( 'allergy' ),
			)
		);
		$this->assertSame( array(), $result['events'] );
	}

	/**
	 * Link_prescription_to_record: links and lists.
	 */
	public function test_link_prescription_to_record_link_and_list() {
		$member_id = self::factory()->post->create( array( 'post_type' => 'mcp_ai_member' ) );
		$rx_id     = self::factory()->post->create( array( 'post_type' => 'mcp_ai_prescription' ) );
		$record_id = self::factory()->post->create( array( 'post_type' => 'mcp_ai_med_record' ) );
		update_post_meta( $rx_id, '_prescription_member_id', $member_id );
		update_post_meta( $record_id, '_medical_record_member_id', $member_id );

		$tool   = new WP_MCP_AI_Tool_Link_Prescription_To_Record();
		$linked = $tool->execute(
			array(
				'action'          => 'link',
				'prescription_id' => $rx_id,
				'record_id'       => $record_id,
			)
		);
		$this->assertIsArray( $linked );
		$this->assertTrue( $linked['success'] );
		$this->assertContains( $record_id, $linked['linked_records'] );

		$listed = $tool->execute(
			array(
				'action'          => 'list',
				'prescription_id' => $rx_id,
			)
		);
		$this->assertCount( 1, $listed['linked_records'] );

		$unlinked = $tool->execute(
			array(
				'action'          => 'unlink',
				'prescription_id' => $rx_id,
				'record_id'       => $record_id,
			)
		);
		$this->assertNotContains( $record_id, $unlinked['linked_records'] );
	}

	/**
	 * Link_prescription_to_record: rejects different members.
	 */
	public function test_link_prescription_to_record_member_mismatch() {
		$rx_id     = self::factory()->post->create( array( 'post_type' => 'mcp_ai_prescription' ) );
		$record_id = self::factory()->post->create( array( 'post_type' => 'mcp_ai_med_record' ) );
		update_post_meta( $rx_id, '_prescription_member_id', 1001 );
		update_post_meta( $record_id, '_medical_record_member_id', 1002 );

		$tool   = new WP_MCP_AI_Tool_Link_Prescription_To_Record();
		$result = $tool->execute(
			array(
				'action'          => 'link',
				'prescription_id' => $rx_id,
				'record_id'       => $record_id,
			)
		);
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_member_mismatch', $result->get_error_code() );
	}

	/**
	 * Verify_prescription_interactions: detects warfarin + ibuprofen.
	 */
	public function test_verify_prescription_interactions_detects_pair() {
		$tool   = new WP_MCP_AI_Tool_Verify_Prescription_Interactions();
		$result = $tool->execute(
			array(
				'medications' => array( 'Warfarin sodium 5mg', 'Ibuprofen 400mg tablets' ),
			)
		);
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['interactions'] );
		$this->assertSame( 'major', $result['interactions'][0]['severity'] );
	}

	/**
	 * Verify_prescription_interactions: returns empty when fewer than 2 meds.
	 */
	public function test_verify_prescription_interactions_handles_single_med() {
		$tool   = new WP_MCP_AI_Tool_Verify_Prescription_Interactions();
		$result = $tool->execute( array( 'medications' => array( 'Aspirin' ) ) );
		$this->assertSame( array(), $result['interactions'] );
	}

	/**
	 * Verify_prescription_interactions: filterable registry.
	 */
	public function test_verify_prescription_interactions_filterable_pairs() {
		add_filter(
			'wp_mcp_ai_healthcare_interaction_pairs',
			static function ( $pairs ) {
				$pairs[] = array(
					'a'        => 'foodrug',
					'b'        => 'bardrug',
					'severity' => 'minor',
					'note'     => 'test',
				);
				return $pairs;
			}
		);
		$tool   = new WP_MCP_AI_Tool_Verify_Prescription_Interactions();
		$result = $tool->execute( array( 'medications' => array( 'Foodrug XR', 'Bardrug ER' ) ) );
		$this->assertNotEmpty( $result['interactions'] );
		$this->assertSame( 'minor', $result['interactions'][0]['severity'] );
		remove_all_filters( 'wp_mcp_ai_healthcare_interaction_pairs' );
	}

	/**
	 * Generate_visit_summary: produces structured + markdown output.
	 */
	public function test_generate_visit_summary_structured_and_markdown() {
		$member_id = self::factory()->post->create(
			array(
				'post_type'  => 'mcp_ai_member',
				'post_title' => 'Alex',
			)
		);
		$checkup   = self::factory()->post->create(
			array(
				'post_type'  => 'mcp_ai_checkup',
				'post_title' => 'Annual physical',
			)
		);
		update_post_meta( $checkup, '_checkup_member_id', $member_id );
		update_post_meta( $checkup, '_checkup_datetime', '2025-03-04 10:00:00' );
		update_post_meta( $checkup, '_checkup_provider', 'Dr Smith' );
		update_post_meta( $checkup, '_checkup_status', 'completed' );

		$tool       = new WP_MCP_AI_Tool_Generate_Visit_Summary();
		$structured = $tool->execute(
			array(
				'member_id' => $member_id,
				'date_from' => '2025-01-01',
				'date_to'   => '2025-12-31',
			)
		);
		$this->assertIsArray( $structured );
		$this->assertSame( 1, $structured['totals']['visits'] );
		$this->assertSame( 'Alex', $structured['member']['name'] );

		$md = $tool->execute(
			array(
				'member_id' => $member_id,
				'format'    => 'markdown',
			)
		);
		$this->assertArrayHasKey( 'markdown', $md );
		$this->assertStringContainsString( 'Annual physical', $md['markdown'] );
	}

	/**
	 * Merge_duplicate_members: dry run reports children without changes.
	 */
	public function test_merge_duplicate_members_dry_run() {
		$source      = self::factory()->post->create(
			array(
				'post_type'  => 'mcp_ai_member',
				'post_title' => 'Dupe',
			)
		);
		$destination = self::factory()->post->create(
			array(
				'post_type'  => 'mcp_ai_member',
				'post_title' => 'Real',
			)
		);
		$rx_id       = self::factory()->post->create( array( 'post_type' => 'mcp_ai_prescription' ) );
		update_post_meta( $rx_id, '_prescription_member_id', $source );

		$tool   = new WP_MCP_AI_Tool_Merge_Duplicate_Members();
		$result = $tool->execute(
			array(
				'source_member_id'      => $source,
				'destination_member_id' => $destination,
				'dry_run'               => true,
			)
		);
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 1, $result['planned_moves']['mcp_ai_prescription']['count'] );
		// Source should still exist (not trashed).
		$this->assertSame( 'publish', get_post_status( $source ) );
		// Meta should still point to source.
		$this->assertSame( (string) $source, (string) get_post_meta( $rx_id, '_prescription_member_id', true ) );
	}

	/**
	 * Merge_duplicate_members: actually re-parents and trashes source.
	 */
	public function test_merge_duplicate_members_applies() {
		$source      = self::factory()->post->create(
			array(
				'post_type'  => 'mcp_ai_member',
				'post_title' => 'Dupe',
			)
		);
		$destination = self::factory()->post->create(
			array(
				'post_type'  => 'mcp_ai_member',
				'post_title' => 'Real',
			)
		);
		$rx_id       = self::factory()->post->create( array( 'post_type' => 'mcp_ai_prescription' ) );
		$allergy_id  = self::factory()->post->create( array( 'post_type' => 'mcp_ai_allergy' ) );
		update_post_meta( $rx_id, '_prescription_member_id', $source );
		update_post_meta( $allergy_id, '_allergy_member_id', $source );

		$tool   = new WP_MCP_AI_Tool_Merge_Duplicate_Members();
		$result = $tool->execute(
			array(
				'source_member_id'      => $source,
				'destination_member_id' => $destination,
			)
		);
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'trashed', $result['source']['state'] );
		$this->assertSame( (string) $destination, (string) get_post_meta( $rx_id, '_prescription_member_id', true ) );
		$this->assertSame( (string) $destination, (string) get_post_meta( $allergy_id, '_allergy_member_id', true ) );
		$this->assertSame( 'trash', get_post_status( $source ) );
	}

	/**
	 * Merge_duplicate_members: rejects same-member merges.
	 */
	public function test_merge_duplicate_members_rejects_same() {
		$id     = self::factory()->post->create( array( 'post_type' => 'mcp_ai_member' ) );
		$tool   = new WP_MCP_AI_Tool_Merge_Duplicate_Members();
		$result = $tool->execute(
			array(
				'source_member_id'      => $id,
				'destination_member_id' => $id,
			)
		);
		$this->assertInstanceOf( 'WP_Error', $result );
	}
}
