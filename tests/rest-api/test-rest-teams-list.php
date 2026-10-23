<?php
/**
 * Tests the GET /mcp-ai/v1/teams catalogue route (added 2.4.0 for the
 * Complete Console TeamsPage — Proposal 084 Phase 1).
 *
 * Covers: manage_options gating and the team-list envelope with member
 * counts.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_REST_Teams_List_Test extends WP_UnitTestCase {

	/**
	 * Non-admins are refused.
	 */
	public function test_non_admin_is_forbidden() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$this->assertFalse( ( new WP_MCP_AI_REST_Teams_Controller() )->check_permission() );
	}

	/**
	 * Admins get the catalogue with per-team member counts.
	 */
	public function test_admin_lists_teams_with_member_counts() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Register the team + profession CPTs so the meta keys exist.
		if ( ! post_type_exists( 'mcp_ai_team' ) && class_exists( 'WP_MCP_AI_Team_CPT' ) ) {
			WP_MCP_AI_Team_CPT::register_post_type();
		}
		if ( ! post_type_exists( 'mcp_ai_profession' ) && class_exists( 'WP_MCP_AI_Profession_CPT' ) ) {
			WP_MCP_AI_Profession_CPT::register_post_type();
		}

		$profession_id = $this->factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
				'post_title'  => 'Writer',
			)
		);
		$team_id       = $this->factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_team',
				'post_status' => 'publish',
				'post_title'  => 'Ops Crew',
			)
		);
		update_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS, array( $profession_id ) );

		$response = ( new WP_MCP_AI_REST_Teams_Controller() )->get_teams();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$found = array();
		foreach ( $data['teams'] as $team ) {
			$found[ $team['id'] ] = $team;
		}
		$this->assertArrayHasKey( $team_id, $found );
		$this->assertSame( 'Ops Crew', $found[ $team_id ]['title'] );
		$this->assertSame( 1, $found[ $team_id ]['member_count'] );
	}
}
