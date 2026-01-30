<?php
/**
 * Test async AJAX data seeding operations.
 *
 * Tests AJAX endpoints that handle async data seeding operations like
 * team reseeding, profession reseeding, and playbook generation.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for async data seeding AJAX endpoints.
 */
class Test_Async_AJAX_Data_Seeding extends WP_Ajax_UnitTestCase {

	/**
	 * Setup test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure admin context is initialized.
		if ( ! did_action( 'admin_init' ) ) {
			do_action( 'admin_init' );
		}
	}

	/**
	 * Test reseed teams AJAX endpoint with update action.
	 */
	public function test_reseed_teams_update_success() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create at least 10 professions (dependency requirement).
		$this->create_test_professions( 12 );

		// Set up AJAX request.
		$_POST['action']      = 'wp_mcp_ai_reseed_teams';
		$_POST['action_type'] = 'update';
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_reseed_teams' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_reseed_teams' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - AJAX handlers call wp_die().
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify success.
		$this->assertTrue( $response['success'], 'Reseed teams should succeed with sufficient professions' );
		$this->assertArrayHasKey( 'data', $response );

		// Verify teams were created/updated.
		$teams = get_posts(
			array(
				'post_type'      => 'mcp_ai_team',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			)
		);
		$this->assertGreaterThan( 0, count( $teams ), 'Teams should be created' );
	}

	/**
	 * Test reseed teams fails without sufficient professions.
	 */
	public function test_reseed_teams_fails_without_professions() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create fewer than 10 professions (should fail).
		$this->create_test_professions( 5 );

		// Set up AJAX request.
		$_POST['action']      = 'wp_mcp_ai_reseed_teams';
		$_POST['action_type'] = 'update';
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_reseed_teams' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_reseed_teams' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure.
		$this->assertFalse( $response['success'], 'Should fail with insufficient professions' );
		$this->assertStringContainsString( 'Not enough professions', $response['data']['message'] );
	}

	/**
	 * Test reseed teams with replace action deletes existing teams.
	 */
	public function test_reseed_teams_replace_deletes_existing() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create professions.
		$this->create_test_professions( 12 );

		// Create existing teams.
		$existing_team_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$existing_team_ids[] = wp_insert_post(
				array(
					'post_type'   => 'mcp_ai_team',
					'post_title'  => 'Test Team ' . $i,
					'post_status' => 'publish',
				)
			);
		}

		// Verify teams exist.
		$this->assertEquals( 3, wp_count_posts( 'mcp_ai_team' )->publish );

		// Set up AJAX request with replace action.
		$_POST['action']      = 'wp_mcp_ai_reseed_teams';
		$_POST['action_type'] = 'replace';
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_reseed_teams' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_reseed_teams' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify success.
		$this->assertTrue( $response['success'], 'Replace action should succeed' );

		// Verify old teams were deleted.
		foreach ( $existing_team_ids as $team_id ) {
			$this->assertNull( get_post( $team_id ), 'Old team should be deleted' );
		}
	}

	/**
	 * Test reseed teams requires proper permissions.
	 */
	public function test_reseed_teams_requires_permissions() {
		// Create subscriber user (no manage_options).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Set up AJAX request.
		$_POST['action']      = 'wp_mcp_ai_reseed_teams';
		$_POST['action_type'] = 'update';
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_reseed_teams' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_reseed_teams' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission', $response['data']['message'] );
	}

	/**
	 * Test reseed teams requires valid nonce.
	 */
	public function test_reseed_teams_requires_valid_nonce() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request with invalid nonce.
		$_POST['action']      = 'wp_mcp_ai_reseed_teams';
		$_POST['action_type'] = 'update';
		$_POST['nonce']       = 'invalid_nonce';

		// Expect failure due to nonce check.
		$this->expectException( 'WPAjaxDieStopException' );

		$this->_handleAjax( 'wp_mcp_ai_reseed_teams' );
	}

	/**
	 * Test reseed teams rejects invalid action type.
	 */
	public function test_reseed_teams_rejects_invalid_action_type() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create professions.
		$this->create_test_professions( 12 );

		// Set up AJAX request with invalid action type.
		$_POST['action']      = 'wp_mcp_ai_reseed_teams';
		$_POST['action_type'] = 'invalid_action';
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_reseed_teams' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_reseed_teams' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Invalid action type', $response['data']['message'] );
	}

	/**
	 * Test reseed professions AJAX endpoint.
	 */
	public function test_reseed_professions_success() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']      = 'wp_mcp_ai_reseed_professions';
		$_POST['action_type'] = 'update';
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_reseed_professions' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_reseed_professions' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify success.
		$this->assertTrue( $response['success'], 'Reseed professions should succeed' );

		// Verify professions were created.
		$professions = get_posts(
			array(
				'post_type'      => 'mcp_ai_profession',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			)
		);
		$this->assertGreaterThan( 0, count( $professions ), 'Professions should be created' );
	}

	/**
	 * Test regenerate playbook AJAX endpoint.
	 */
	public function test_regenerate_playbook_success() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create a test profession.
		$profession_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Test Profession',
				'post_status' => 'publish',
			)
		);

		// Set up AJAX request.
		$_POST['action']        = 'wp_mcp_ai_regenerate_playbook';
		$_POST['profession_id'] = $profession_id;
		$_POST['nonce']         = wp_create_nonce( 'wp_mcp_ai_regenerate_playbook' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_regenerate_playbook' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify response structure (success or async job queued).
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test sync all playbooks AJAX endpoint.
	 */
	public function test_sync_all_playbooks_success() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create test professions.
		$this->create_test_professions( 3 );

		// Set up AJAX request.
		$_POST['action'] = 'wp_mcp_ai_sync_all_playbooks';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_sync_all_playbooks' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_sync_all_playbooks' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify response structure.
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test seed task templates AJAX endpoint.
	 */
	public function test_seed_task_templates_success() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']      = 'wp_mcp_ai_seed_task_templates';
		$_POST['action_type'] = 'update';
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_seed_task_templates' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_seed_task_templates' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify response structure.
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test seed orchestration AJAX endpoint.
	 */
	public function test_seed_orchestration_success() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action'] = 'wp_mcp_ai_seed_orchestration';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_seed_orchestration' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_seed_orchestration' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify response structure.
		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Helper: Create test professions.
	 *
	 * @param int $count Number of professions to create.
	 * @return array Array of profession post IDs.
	 */
	private function create_test_professions( $count ) {
		$profession_ids = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$profession_ids[] = wp_insert_post(
				array(
					'post_type'   => 'mcp_ai_profession',
					'post_title'  => 'Test Profession ' . $i,
					'post_status' => 'publish',
				)
			);
		}

		return $profession_ids;
	}
}
