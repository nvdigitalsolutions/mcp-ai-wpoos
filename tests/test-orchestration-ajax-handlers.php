<?php
/**
 * Test orchestration AJAX handlers
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for orchestration AJAX endpoints
 */
class Test_Orchestration_AJAX_Handlers extends WP_MCP_AI_Ajax_TestCase {

	/**
	 * Re-register the orchestration dashboard's wp_ajax_* actions per test.
	 *
	 * The dashboard class is only loaded under is_admin() in the production
	 * loader, which is false under CLI phpunit. wp-phpunit additionally
	 * snapshots hooks once per process and restores that snapshot after every
	 * test, wiping hooks registered by a previous test, so re-instantiate
	 * (cheap: the constructor only adds actions) whenever the handler is
	 * missing. See test-assistant-misc-ajax.php for the same pattern.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Admin_Orchestration_Dashboard' ) ) {
			$path = WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-orchestration-dashboard.php';
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		if ( class_exists( 'WP_MCP_AI_Admin_Orchestration_Dashboard' )
			&& ! has_action( 'wp_ajax_wp_mcp_ai_execute_workflow' ) ) {
			new WP_MCP_AI_Admin_Orchestration_Dashboard();
		}
	}

	/**
	 * Extract the human-readable error message from a handler response.
	 *
	 * Accepts both the array( 'message' => ... ) shape and a plain string
	 * carried as data.
	 *
	 * @param array $response Decoded response from dispatch().
	 * @return string Error message.
	 */
	protected function ajax_error_message( $response ) {
		if ( isset( $response['data'] ) && is_array( $response['data'] ) && isset( $response['data']['message'] ) ) {
			return (string) $response['data']['message'];
		}
		if ( isset( $response['data'] ) && is_string( $response['data'] ) ) {
			return $response['data'];
		}
		return '';
	}

	/**
	 * Test apply orchestration preset AJAX endpoint
	 */
	public function test_apply_orchestration_preset_success() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_apply_orchestration_preset',
			array(
				'preset_id' => 'balanced',
				'nonce'     => wp_create_nonce( 'wp_mcp_ai_dashboard' ),
			)
		);

		// Verify success.
		$this->assertTrue( $response['success'], 'AJAX request should succeed' );
		$this->assertEquals( 'balanced', $response['data']['preset_id'] );

		// Verify preset was actually applied.
		$active_preset = WP_MCP_AI_Orchestration_Preset_Service::get_active_preset();
		$this->assertEquals( 'balanced', $active_preset );
	}

	/**
	 * Test apply orchestration preset without proper nonce
	 */
	public function test_apply_orchestration_preset_invalid_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_apply_orchestration_preset',
			array(
				'preset_id' => 'balanced',
				'nonce'     => 'invalid_nonce',
			)
		);

		// Nonce failures die with -1 and no JSON body.
		$this->assertAjaxForbidden( $response );
	}

	/**
	 * Test apply orchestration preset without proper permissions
	 */
	public function test_apply_orchestration_preset_insufficient_permissions() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_apply_orchestration_preset',
			array(
				'preset_id' => 'balanced',
				'nonce'     => wp_create_nonce( 'wp_mcp_ai_dashboard' ),
			)
		);

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission', $this->ajax_error_message( $response ) );
	}

	/**
	 * Test apply orchestration preset with invalid preset ID
	 */
	public function test_apply_orchestration_preset_invalid_id() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_apply_orchestration_preset',
			array(
				'preset_id' => 'nonexistent_preset',
				'nonce'     => wp_create_nonce( 'wp_mcp_ai_dashboard' ),
			)
		);

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Invalid preset', $this->ajax_error_message( $response ) );
	}

	/**
	 * Test that all presets can be applied successfully
	 */
	public function test_apply_all_presets() {
		$this->as_admin();

		$presets = WP_MCP_AI_Orchestration_Preset_Service::get_presets();

		foreach ( $presets as $preset_id => $preset_data ) {
			$response = $this->dispatch(
				'wp_mcp_ai_apply_orchestration_preset',
				array(
					'preset_id' => $preset_id,
					'nonce'     => wp_create_nonce( 'wp_mcp_ai_dashboard' ),
				)
			);

			// Verify success.
			$this->assertTrue(
				$response['success'],
				"Failed to apply preset: {$preset_id}"
			);
		}
	}

	/**
	 * Test execute workflow AJAX endpoint - success case
	 */
	public function test_execute_workflow_success() {
		$this->as_admin();

		// Create a mock workflow transient.
		$workflow_id   = 'wf_test_123';
		$workflow_data = array(
			'workflow_id'        => $workflow_id,
			'team_id'            => 'team_test_123',
			'task_type'          => 'test',
			'state'              => 'initialized',
			'tasks'              => array(
				array(
					'task_id' => 'task_1',
					'name'    => 'Test Task',
					'status'  => 'pending',
				),
			),
			'created_at'         => current_time( 'mysql' ),
			'parallel_execution' => false,
		);
		set_transient( 'wp_mcp_ai_workflow_' . $workflow_id, $workflow_data, DAY_IN_SECONDS );

		$response = $this->dispatch(
			'wp_mcp_ai_execute_workflow',
			array(
				'workflow_id' => $workflow_id,
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_orchestration' ),
			)
		);

		// Verify success or appropriate error.
		// Note: This may fail if WP_MCP_AI_Enhanced_Workflow_Coordinator is not available.
		if ( ! $response['success'] ) {
			$this->assertStringContainsString(
				'coordinator',
				strtolower( $this->ajax_error_message( $response ) ),
				'Expected coordinator availability message'
			);
		} else {
			$this->assertTrue( $response['success'], 'AJAX request should succeed' );
			$this->assertEquals( $workflow_id, $response['data']['workflow_id'] );
		}
	}

	/**
	 * Test execute workflow without proper nonce
	 */
	public function test_execute_workflow_invalid_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_execute_workflow',
			array(
				'workflow_id' => 'wf_test_123',
				'nonce'       => 'invalid_nonce',
			)
		);

		// Nonce failures die with -1 and no JSON body.
		$this->assertAjaxForbidden( $response );
	}

	/**
	 * Test execute workflow without proper permissions
	 */
	public function test_execute_workflow_insufficient_permissions() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_execute_workflow',
			array(
				'workflow_id' => 'wf_test_123',
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_orchestration' ),
			)
		);

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission', strtolower( $this->ajax_error_message( $response ) ) );
	}

	/**
	 * Test execute workflow with missing workflow ID
	 */
	public function test_execute_workflow_missing_id() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_execute_workflow',
			array(
				'nonce' => wp_create_nonce( 'wp_mcp_ai_orchestration' ),
			)
		);

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'required', strtolower( $this->ajax_error_message( $response ) ) );
	}

	/**
	 * Test restart workflow AJAX endpoint - success case
	 */
	public function test_restart_workflow_success() {
		$this->as_admin();

		// Create a mock completed workflow transient.
		$workflow_id   = 'wf_test_456';
		$workflow_data = array(
			'workflow_id'  => $workflow_id,
			'team_id'      => 'team_test_456',
			'task_type'    => 'test',
			'state'        => 'completed',
			'tasks'        => array(
				array(
					'task_id'      => 'compose_test',
					'name'         => 'Team Composition',
					'type'         => 'composition',
					'status'       => 'completed',
					'completed_at' => current_time( 'mysql' ),
				),
				array(
					'task_id'      => 'task_1',
					'name'         => 'Test Task',
					'type'         => 'execution',
					'status'       => 'completed',
					'completed_at' => current_time( 'mysql' ),
				),
			),
			'created_at'   => current_time( 'mysql' ),
			'started_at'   => current_time( 'mysql' ),
			'completed_at' => current_time( 'mysql' ),
		);
		set_transient( 'wp_mcp_ai_workflow_' . $workflow_id, $workflow_data, DAY_IN_SECONDS );

		$response = $this->dispatch(
			'wp_mcp_ai_restart_workflow',
			array(
				'workflow_id' => $workflow_id,
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_orchestration' ),
			)
		);

		// Verify success.
		$this->assertTrue( $response['success'], 'AJAX request should succeed' );
		$this->assertEquals( $workflow_id, $response['data']['workflow_id'] );

		// Verify workflow was reset.
		$reset_workflow = $response['data']['workflow'];
		$this->assertEquals( 'initialized', $reset_workflow['state'] );
		$this->assertNull( $reset_workflow['started_at'] );
		$this->assertNull( $reset_workflow['completed_at'] );

		// Verify tasks were reset (except composition).
		foreach ( $reset_workflow['tasks'] as $task ) {
			if ( 'composition' === $task['type'] ) {
				$this->assertEquals( 'completed', $task['status'] );
			} else {
				$this->assertEquals( 'pending', $task['status'] );
				$this->assertArrayNotHasKey( 'completed_at', $task );
			}
		}
	}

	/**
	 * Test restart workflow without proper nonce
	 */
	public function test_restart_workflow_invalid_nonce() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_restart_workflow',
			array(
				'workflow_id' => 'wf_test_456',
				'nonce'       => 'invalid_nonce',
			)
		);

		// Nonce failures die with -1 and no JSON body.
		$this->assertAjaxForbidden( $response );
	}

	/**
	 * Test restart workflow without proper permissions
	 */
	public function test_restart_workflow_insufficient_permissions() {
		$this->as_subscriber();

		$response = $this->dispatch(
			'wp_mcp_ai_restart_workflow',
			array(
				'workflow_id' => 'wf_test_456',
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_orchestration' ),
			)
		);

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission', strtolower( $this->ajax_error_message( $response ) ) );
	}

	/**
	 * Test restart workflow with missing workflow ID
	 */
	public function test_restart_workflow_missing_id() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_restart_workflow',
			array(
				'nonce' => wp_create_nonce( 'wp_mcp_ai_orchestration' ),
			)
		);

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'required', strtolower( $this->ajax_error_message( $response ) ) );
	}

	/**
	 * Test restart workflow with non-existent workflow
	 */
	public function test_restart_workflow_not_found() {
		$this->as_admin();

		$response = $this->dispatch(
			'wp_mcp_ai_restart_workflow',
			array(
				'workflow_id' => 'wf_nonexistent_999',
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_orchestration' ),
			)
		);

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'not found', strtolower( $this->ajax_error_message( $response ) ) );
	}
}
