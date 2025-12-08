<?php
/**
 * Tests for WP_MCP_AI_REST_Teams_Controller.
 *
 * Validates the /teams/{id}/members REST endpoint that powers
 * the team member selection UI in the test team page.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

class Test_REST_Teams_Controller extends WP_UnitTestCase {

	use WP_MCP_AI_REST_Test_Helper;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create admin user.
		$this->admin_user_id = $this->create_test_user( 'administrator' );
		wp_set_current_user( $this->admin_user_id );

		// Set up REST server.
		$this->setup_rest_server();

		// Ensure REST routes are registered.
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		// Clean up REST server.
		$this->teardown_rest_server();

		parent::tearDown();
	}

	/**
	 * Test that the teams endpoint is registered.
	 */
	public function test_teams_endpoint_is_registered() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey(
			'/mcp-ai/v1/teams/(?P<id>\d+)/members',
			$routes,
			'Teams members endpoint should be registered'
		);
	}

	/**
	 * Test that the endpoint requires authentication.
	 */
	public function test_endpoint_requires_authentication() {
		// Log out.
		wp_set_current_user( 0 );

		// Create a team.
		$team_id = $this->create_team_post( 'Test Team' );

		// Make request without authentication.
		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/teams/' . $team_id . '/members' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals(
			403,
			$response->get_status(),
			'Unauthenticated request should be forbidden'
		);
	}

	/**
	 * Test that the endpoint requires manage_options capability.
	 */
	public function test_endpoint_requires_manage_options() {
		// Create a subscriber user (no manage_options).
		$subscriber_id = $this->create_test_user( 'subscriber' );
		wp_set_current_user( $subscriber_id );

		// Create a team.
		$team_id = $this->create_team_post( 'Test Team' );

		// Make request as subscriber.
		$request = $this->create_authenticated_request(
			'GET',
			'/mcp-ai/v1/teams/' . $team_id . '/members'
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals(
			403,
			$response->get_status(),
			'Request without manage_options should be forbidden'
		);
	}

	/**
	 * Test getting team members for a valid team.
	 */
	public function test_get_team_members_success() {
		// Create professions.
		$profession1_id = $this->create_profession_post( 'Software Engineer', 'technical' );
		$profession2_id = $this->create_profession_post( 'Marketing Specialist', 'creative' );

		// Create a team with these members.
		$team_id = $this->create_team_post(
			'Dev Team',
			array(
				WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS => array( $profession1_id, $profession2_id ),
			)
		);

		// Make authenticated request.
		$request = $this->create_authenticated_request(
			'GET',
			'/mcp-ai/v1/teams/' . $team_id . '/members'
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status(), 'Request should succeed' );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'team_id', $data, 'Response should include team_id' );
		$this->assertArrayHasKey( 'members', $data, 'Response should include members array' );
		$this->assertArrayHasKey( 'count', $data, 'Response should include count' );

		$this->assertEquals( $team_id, $data['team_id'], 'Team ID should match' );
		$this->assertEquals( 2, $data['count'], 'Count should be 2' );
		$this->assertCount( 2, $data['members'], 'Should have 2 members' );

		// Validate member structure.
		$member1 = $data['members'][0];
		$this->assertArrayHasKey( 'id', $member1, 'Member should have id' );
		$this->assertArrayHasKey( 'title', $member1, 'Member should have title' );
		$this->assertArrayHasKey( 'category', $member1, 'Member should have category' );
		$this->assertArrayHasKey( 'category_slug', $member1, 'Member should have category_slug' );
		$this->assertArrayHasKey( 'excerpt', $member1, 'Member should have excerpt' );
		$this->assertArrayHasKey( 'expertise', $member1, 'Member should have expertise' );
		$this->assertArrayHasKey( 'tools_count', $member1, 'Member should have tools_count' );

		// Verify member data.
		$this->assertEquals( $profession1_id, $member1['id'], 'Member ID should match' );
		$this->assertEquals( 'Software Engineer', $member1['title'], 'Member title should match' );
		$this->assertEquals( 'Technical', $member1['category'], 'Member category should be formatted' );
		$this->assertEquals( 'technical', $member1['category_slug'], 'Member category slug should match' );
	}

	/**
	 * Test getting team members for a team with no members.
	 */
	public function test_get_team_members_empty_team() {
		// Create a team with no members.
		$team_id = $this->create_team_post( 'Empty Team' );

		// Make authenticated request.
		$request = $this->create_authenticated_request(
			'GET',
			'/mcp-ai/v1/teams/' . $team_id . '/members'
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status(), 'Request should succeed' );

		$data = $response->get_data();

		$this->assertEquals( 0, $data['count'], 'Count should be 0' );
		$this->assertEmpty( $data['members'], 'Members array should be empty' );
	}

	/**
	 * Test getting team members with invalid team ID.
	 */
	public function test_get_team_members_invalid_id() {
		// Make request with non-existent team ID.
		$request = $this->create_authenticated_request(
			'GET',
			'/mcp-ai/v1/teams/999999/members'
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals(
			400,
			$response->get_status(),
			'Request with invalid team ID should return 400'
		);
	}

	/**
	 * Test that deleted profession members are filtered out.
	 */
	public function test_deleted_members_are_filtered() {
		// Create professions.
		$profession1_id = $this->create_profession_post( 'Developer' );
		$profession2_id = $this->create_profession_post( 'Designer' );

		// Create team.
		$team_id = $this->create_team_post(
			'Test Team',
			array(
				WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS => array( $profession1_id, $profession2_id ),
			)
		);

		// Delete one profession.
		wp_delete_post( $profession2_id, true );

		// Make request.
		$request = $this->create_authenticated_request(
			'GET',
			'/mcp-ai/v1/teams/' . $team_id . '/members'
		);
		$response = rest_get_server()->dispatch( $request );

		$data = $response->get_data();

		$this->assertEquals( 1, $data['count'], 'Only active members should be returned' );
		$this->assertEquals( $profession1_id, $data['members'][0]['id'], 'Only the first member should remain' );
	}

	/**
	 * Helper: Create a team post.
	 *
	 * @param string $title Team title.
	 * @param array  $meta  Optional meta data.
	 * @return int Team post ID.
	 */
	protected function create_team_post( $title, $meta = array() ) {
		$team_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_team',
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $team_id, 'Team post creation should not return WP_Error' );
		$this->assertNotEmpty( $team_id, 'Team post ID should not be empty' );

		// Set meta.
		foreach ( $meta as $key => $value ) {
			update_post_meta( $team_id, $key, $value );
		}

		return $team_id;
	}

	/**
	 * Helper: Create a profession post.
	 *
	 * @param string $title    Profession title.
	 * @param string $category Optional category.
	 * @return int Profession post ID.
	 */
	protected function create_profession_post( $title, $category = 'other' ) {
		$profession_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => $title,
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $profession_id, 'Profession post creation should not return WP_Error' );
		$this->assertNotEmpty( $profession_id, 'Profession post ID should not be empty' );

		// Set category meta.
		update_post_meta( $profession_id, WP_MCP_AI_Profession_CPT::META_CATEGORY, $category );

		return $profession_id;
	}
}
