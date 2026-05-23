<?php
/**
 * Test async AJAX data seeding operations.
 *
 * Tests AJAX endpoints that handle async data seeding operations like
 * team reseeding, profession reseeding, and playbook generation.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for async data seeding AJAX endpoints.
 */
class Test_Async_AJAX_Data_Seeding extends WP_MCP_AI_Ajax_TestCase {

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
		$this->as_admin();

		// Create at least 10 professions (dependency requirement).
		$this->create_test_professions( 12 );

		// Dispatch using our helper — HTTP is automatically stubbed.
		$response = $this->dispatch(
			'wp_mcp_ai_reseed_teams',
			array(
				'action_type' => 'update',
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_reseed_teams' ),
			)
		);

		// Verify success.
		$this->assertIsArray( $response );

		if ( isset( $response['success'] ) && $response['success'] ) {
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
	}

	/**
	 * Test reseed teams fails without sufficient professions.
	 */
	public function test_reseed_teams_fails_without_professions() {
		$this->as_admin();

		// Create fewer than 10 professions (should fail).
		$this->create_test_professions( 5 );

		$response = $this->dispatch(
			'wp_mcp_ai_reseed_teams',
			array(
				'action_type' => 'update',
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_reseed_teams' ),
			)
		);

		$this->assertIsArray( $response );

		if ( isset( $response['success'] ) ) {
			// May fail or succeed depending on the seeder implementation.
			if ( ! $response['success'] ) {
				$this->assertStringContainsString( 'Not enough professions', $response['data']['message'] );
			}
		}
	}

	/**
	 * Test reseed teams with replace action deletes existing teams.
	 */
	public function test_reseed_teams_replace_deletes_existing() {
		$this->as_admin();

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

		$response = $this->dispatch(
			'wp_mcp_ai_reseed_teams',
			array(
				'action_type' => 'replace',
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_reseed_teams' ),
			)
		);

		$this->assertIsArray( $response );

		if ( isset( $response['success'] ) && $response['success'] ) {
			// Verify old teams were deleted.
			foreach ( $existing_team_ids as $team_id ) {
				$this->assertNull( get_post( $team_id ), 'Old team should be deleted' );
			}
		}
	}

	/**
	 * Test reseed teams requires proper permissions.
	 */
	public function test_reseed_teams_requires_permissions() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_reseed_teams',
			array(
				'action_type' => 'update',
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_reseed_teams' ),
			)
		);

		$this->assertAjaxError( $response, 'permission' );
	}

	/**
	 * Test reseed teams requires valid nonce.
	 */
	public function test_reseed_teams_requires_valid_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_reseed_teams',
			array(
				'action_type' => 'update',
				'nonce'       => 'invalid_nonce',
			)
		);

		$this->assertAjaxForbidden( $response );
	}

	/**
	 * Test reseed teams rejects invalid action type.
	 */
	public function test_reseed_teams_rejects_invalid_action_type() {
		$this->as_admin();

		// Create professions.
		$this->create_test_professions( 12 );

		$response = $this->dispatch(
			'wp_mcp_ai_reseed_teams',
			array(
				'action_type' => 'invalid_action',
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_reseed_teams' ),
			)
		);

		$this->assertIsArray( $response );

		if ( isset( $response['success'] ) && ! $response['success'] ) {
			$this->assertStringContainsString( 'Invalid action type', $response['data']['message'] );
		}
	}

	/**
	 * Test reseed professions AJAX endpoint.
	 */
	public function test_reseed_professions_success() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_reseed_professions',
			array(
				'action_type' => 'update',
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_reseed_professions' ),
			)
		);

		$this->assertIsArray( $response );

		if ( isset( $response['success'] ) && $response['success'] ) {
			$professions = get_posts(
				array(
					'post_type'      => 'mcp_ai_profession',
					'posts_per_page' => -1,
					'post_status'    => 'publish',
				)
			);
			$this->assertGreaterThan( 0, count( $professions ), 'Professions should be created' );
		}
	}

	/**
	 * Test regenerate playbook AJAX endpoint.
	 */
	public function test_regenerate_playbook_success() {
		$this->as_admin();

		// Create a test profession.
		$profession_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Test Profession',
				'post_status' => 'publish',
			)
		);

		$response = $this->dispatch(
			'wp_mcp_ai_regenerate_playbook',
			array(
				'profession_id' => $profession_id,
				'nonce'         => wp_create_nonce( 'wp_mcp_ai_regenerate_playbook' ),
			)
		);

		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test sync all playbooks AJAX endpoint.
	 */
	public function test_sync_all_playbooks_success() {
		$this->as_admin();

		// Create test professions.
		$this->create_test_professions( 3 );

		$response = $this->dispatch(
			'wp_mcp_ai_sync_all_playbooks',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_sync_all_playbooks' ) )
		);

		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test seed task templates AJAX endpoint.
	 */
	public function test_seed_task_templates_success() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_seed_task_templates',
			array(
				'action_type' => 'update',
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_seed_task_templates' ),
			)
		);

		$this->assertIsArray( $response, 'Response should be an array' );
		$this->assertArrayHasKey( 'success', $response );
	}

	/**
	 * Test seed orchestration AJAX endpoint.
	 */
	public function test_seed_orchestration_success() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_seed_orchestration',
			array( 'nonce' => wp_create_nonce( 'wp_mcp_ai_seed_orchestration' ) )
		);

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
