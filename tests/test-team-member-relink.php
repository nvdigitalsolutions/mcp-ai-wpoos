<?php
/**
 * Tests for WP_MCP_AI_Team_Repository::relink_members_after_profession_replace().
 *
 * Teams store resolved profession post IDs in their members meta. The
 * destructive "Replace All Professions" reseed force-deletes every
 * profession post and re-creates fresh ones with new IDs, orphaning each
 * team's member list (the members endpoint resolves zero members while the
 * teams catalogue still counts the raw meta entries). The relink seam
 * re-resolves the stored IDs through the pre-delete slug map.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for team member re-linking after a profession Replace All.
 */
class Test_Team_Member_Relink extends WP_UnitTestCase {

	/**
	 * Post IDs created by the tests (for cleanup).
	 *
	 * @var int[]
	 */
	private $created_ids = array();

	/**
	 * Clean up any fixtures from a previous test.
	 */
	public function setUp(): void {
		parent::setUp();

		foreach ( array( 'mcp_ai_profession', 'mcp_ai_team' ) as $post_type ) {
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'posts_per_page' => -1,
					'post_status'    => 'any',
					'fields'         => 'ids',
				)
			);
			foreach ( $posts as $post_id ) {
				wp_delete_post( $post_id, true );
			}
		}
	}

	/**
	 * Remove test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->created_ids as $post_id ) {
			if ( get_post( $post_id ) ) {
				wp_delete_post( $post_id, true );
			}
		}
		$this->created_ids = array();
		parent::tearDown();
	}

	/**
	 * Create a profession post.
	 *
	 * @param string $title Profession title.
	 * @param string $slug  Profession slug.
	 * @return int Post ID.
	 */
	private function create_profession( $title, $slug ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_name'   => $slug,
			)
		);
		$this->created_ids[] = $post_id;
		return $post_id;
	}

	/**
	 * Create a team post with member meta written raw (bypassing the
	 * registered sanitizer) — this simulates data written by plugin
	 * versions predating the sanitize_team_members registration, where
	 * dead profession IDs could be stored.
	 *
	 * @param int[] $member_ids Profession post IDs.
	 * @return int Post ID.
	 */
	private function create_team_with_raw_members( array $member_ids ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_team',
				'post_status' => 'publish',
				'post_title'  => 'Test Team',
			)
		);
		$this->created_ids[] = $post_id;

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bypasses registered-meta sanitization to simulate pre-sanitizer stored data.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS,
				'meta_value' => maybe_serialize( $member_ids ),
			),
			array( '%d', '%s', '%s' )
		);

		return $post_id;
	}

	/**
	 * Replaced professions are re-linked to the re-seeded posts by slug.
	 */
	public function test_relink_remaps_replaced_profession_ids() {
		$old_farmer_id     = $this->create_profession( 'Farmer', 'farmer' );
		$old_agronomist_id = $this->create_profession( 'Agronomist', 'agronomist' );
		$team_id           = $this->create_team_with_raw_members( array( $old_farmer_id, $old_agronomist_id ) );

		$old_slug_by_id = array(
			$old_farmer_id     => 'farmer',
			$old_agronomist_id => 'agronomist',
		);

		// Simulate the destructive Replace All.
		wp_delete_post( $old_farmer_id, true );
		wp_delete_post( $old_agronomist_id, true );

		$new_farmer_id     = $this->create_profession( 'Farmer', 'farmer' );
		$new_agronomist_id = $this->create_profession( 'Agronomist', 'agronomist' );

		$relinked = WP_MCP_AI_Team_Repository::relink_members_after_profession_replace( $old_slug_by_id );

		$this->assertSame( 1, $relinked, 'Exactly one team should be re-linked.' );
		$members = get_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS, true );
		$this->assertSame(
			array( $new_farmer_id, $new_agronomist_id ),
			array_map( 'absint', $members ),
			'Members should point at the re-seeded profession posts.'
		);
	}

	/**
	 * Member IDs that still resolve to live professions are kept; dangling
	 * references are dropped.
	 */
	public function test_relink_keeps_live_ids_and_drops_dangling() {
		$alive_id = $this->create_profession( 'Soil Scientist', 'soil_scientist' );
		$team_id  = $this->create_team_with_raw_members( array( $alive_id, 999999999 ) );

		// A map containing a profession that no longer exists.
		$old_slug_by_id = array( 123456789 => 'ghost_profession' );

		$relinked = WP_MCP_AI_Team_Repository::relink_members_after_profession_replace( $old_slug_by_id );

		$this->assertSame( 1, $relinked, 'The dangling member should be pruned.' );
		$members = get_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS, true );
		$this->assertSame( array( $alive_id ), array_map( 'absint', $members ) );
	}

	/**
	 * An empty map (or missing post types) is a no-op.
	 */
	public function test_relink_noop_with_empty_map() {
		$this->create_profession( 'Farmer', 'farmer' );
		$team_id = $this->create_team_with_raw_members( array( 1 ) );

		$relinked = WP_MCP_AI_Team_Repository::relink_members_after_profession_replace( array() );

		$this->assertSame( 0, $relinked );
		$members = get_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS, true );
		$this->assertNotEmpty( $members, 'Member meta should be untouched.' );
	}
}
