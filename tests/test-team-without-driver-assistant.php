<?php
/**
 * Test that teams work without a driver assistant.
 *
 * Verifies that the driver assistant field is optional and teams can
 * function without it, since team orchestration uses profession configs
 * directly rather than requiring pre-existing assistant posts.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

class Test_Team_Without_Driver_Assistant extends WP_UnitTestCase {

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

		// Ensure Team CPT class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Team_CPT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/teams/class-wp-mcp-ai-team-cpt.php';
		}

		// Ensure Profession CPT class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Profession_CPT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-cpt.php';
		}
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
	 * Test that teams REST endpoint works without driver assistant.
	 */
	public function test_teams_rest_endpoint_works_without_driver_assistant() {
		// Create a profession.
		$profession_id = $this->create_profession_post( 'Software Engineer', 'technical' );

		// Create a team with NO driver assistant.
		$team_id = $this->create_team_post(
			'Dev Team',
			array(
				WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS => array( $profession_id ),
			)
		);

		// Verify no driver assistant is set.
		$driver_id = get_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_DRIVER_ASSISTANT, true );
		$this->assertEmpty( $driver_id, 'Driver assistant should not be set' );

		// Make authenticated request to get team members.
		$request  = $this->create_authenticated_request(
			'GET',
			'/mcp-ai/v1/teams/' . $team_id . '/members'
		);
		$response = rest_get_server()->dispatch( $request );

		// Should succeed without driver assistant.
		$this->assertEquals( 200, $response->get_status(), 'Request should succeed without driver assistant' );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'team_id', $data );
		$this->assertArrayHasKey( 'members', $data );
		$this->assertArrayHasKey( 'has_driver_assistant', $data );
		$this->assertArrayHasKey( 'supports_unified_mode', $data );

		$this->assertEquals( $team_id, $data['team_id'] );
		$this->assertEquals( 1, $data['count'] );
		$this->assertFalse( $data['has_driver_assistant'], 'has_driver_assistant should be false' );
	}

	/**
	 * Test that supports_unified_mode works without driver assistant.
	 */
	public function test_supports_unified_mode_without_driver_assistant() {
		// Create multiple professions.
		$profession1_id = $this->create_profession_post( 'Developer', 'technical' );
		$profession2_id = $this->create_profession_post( 'Designer', 'creative' );

		// Create a team with NO driver assistant but multiple members.
		$team_id = $this->create_team_post(
			'Multi-Member Team',
			array(
				WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS => array( $profession1_id, $profession2_id ),
			)
		);

		// Make request.
		$request  = $this->create_authenticated_request(
			'GET',
			'/mcp-ai/v1/teams/' . $team_id . '/members'
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();

		// Should support unified mode based on member count, not driver assistant.
		$this->assertTrue(
			$data['supports_unified_mode'],
			'Should support unified mode with multiple members regardless of driver assistant'
		);
		$this->assertFalse( $data['has_driver_assistant'], 'Should not have driver assistant' );
		$this->assertEquals( 2, $data['count'], 'Should have 2 members' );
	}

	/**
	 * Test that driver assistant is optional in REST response.
	 */
	public function test_driver_assistant_optional_in_response() {
		// Create profession and team.
		$profession_id = $this->create_profession_post( 'Consultant' );
		$team_id       = $this->create_team_post(
			'Consulting Team',
			array(
				WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS => array( $profession_id ),
			)
		);

		// Make request.
		$request  = $this->create_authenticated_request(
			'GET',
			'/mcp-ai/v1/teams/' . $team_id . '/members'
		);
		$response = rest_get_server()->dispatch( $request );

		$data = $response->get_data();

		// Response should include driver_assistant_id field (for compatibility).
		$this->assertArrayHasKey( 'driver_assistant_id', $data );
		$this->assertEquals( 0, $data['driver_assistant_id'], 'Driver assistant ID should be 0 when not set' );
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
