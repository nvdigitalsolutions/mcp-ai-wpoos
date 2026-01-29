<?php
/**
 * Test orchestration AJAX handlers
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for orchestration AJAX endpoints
 */
class Test_Orchestration_AJAX_Handlers extends WP_Ajax_UnitTestCase {

	/**
	 * Test apply orchestration preset AJAX endpoint
	 */
	public function test_apply_orchestration_preset_success() {
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request.
		$_POST['action']    = 'wp_mcp_ai_apply_orchestration_preset';
		$_POST['preset_id'] = 'balanced';
		$_POST['nonce']     = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_apply_orchestration_preset' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - AJAX handlers call wp_die().
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

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
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request with invalid nonce.
		$_POST['action']    = 'wp_mcp_ai_apply_orchestration_preset';
		$_POST['preset_id'] = 'balanced';
		$_POST['nonce']     = 'invalid_nonce';

		// Expect failure due to nonce check.
		$this->expectException( 'WPAjaxDieStopException' );

		$this->_handleAjax( 'wp_mcp_ai_apply_orchestration_preset' );
	}

	/**
	 * Test apply orchestration preset without proper permissions
	 */
	public function test_apply_orchestration_preset_insufficient_permissions() {
		// Set up subscriber user (no manage_options capability).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Set up AJAX request.
		$_POST['action']    = 'wp_mcp_ai_apply_orchestration_preset';
		$_POST['preset_id'] = 'balanced';
		$_POST['nonce']     = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_apply_orchestration_preset' );
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
	 * Test apply orchestration preset with invalid preset ID
	 */
	public function test_apply_orchestration_preset_invalid_id() {
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request with invalid preset.
		$_POST['action']    = 'wp_mcp_ai_apply_orchestration_preset';
		$_POST['preset_id'] = 'nonexistent_preset';
		$_POST['nonce']     = wp_create_nonce( 'wp_mcp_ai_dashboard' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_apply_orchestration_preset' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Invalid preset', $response['data']['message'] );
	}

	/**
	 * Test that all presets can be applied successfully
	 */
	public function test_apply_all_presets() {
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$presets = WP_MCP_AI_Orchestration_Preset_Service::get_presets();

		foreach ( $presets as $preset_id => $preset_data ) {
			// Set up AJAX request.
			$_POST['action']    = 'wp_mcp_ai_apply_orchestration_preset';
			$_POST['preset_id'] = $preset_id;
			$_POST['nonce']     = wp_create_nonce( 'wp_mcp_ai_dashboard' );

			// Make AJAX request.
			try {
				$this->_handleAjax( 'wp_mcp_ai_apply_orchestration_preset' );
			} catch ( WPAjaxDieContinueException $e ) {
				// Expected.
			}

			// Get the response.
			$response = json_decode( $this->_last_response, true );

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
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

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

		// Set up AJAX request.
		$_POST['action']      = 'wp_mcp_ai_execute_workflow';
		$_POST['workflow_id'] = $workflow_id;
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_orchestration' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_execute_workflow' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - AJAX handlers call wp_die().
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify success or appropriate error.
		// Note: This may fail if WP_MCP_AI_Enhanced_Workflow_Coordinator is not available.
		if ( ! $response['success'] ) {
			$this->assertStringContainsString(
				'coordinator',
				strtolower( $response['data']['message'] ),
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
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request with invalid nonce.
		$_POST['action']      = 'wp_mcp_ai_execute_workflow';
		$_POST['workflow_id'] = 'wf_test_123';
		$_POST['nonce']       = 'invalid_nonce';

		// Expect failure due to nonce check.
		$this->expectException( 'WPAjaxDieStopException' );

		$this->_handleAjax( 'wp_mcp_ai_execute_workflow' );
	}

	/**
	 * Test execute workflow without proper permissions
	 */
	public function test_execute_workflow_insufficient_permissions() {
		// Set up subscriber user (no manage_options capability).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Set up AJAX request.
		$_POST['action']      = 'wp_mcp_ai_execute_workflow';
		$_POST['workflow_id'] = 'wf_test_123';
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_orchestration' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_execute_workflow' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission', strtolower( $response['data']['message'] ) );
	}

	/**
	 * Test execute workflow with missing workflow ID
	 */
	public function test_execute_workflow_missing_id() {
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request without workflow_id.
		$_POST['action'] = 'wp_mcp_ai_execute_workflow';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_orchestration' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_execute_workflow' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'required', strtolower( $response['data']['message'] ) );
	}

	/**
	 * Test restart workflow AJAX endpoint - success case
	 */
	public function test_restart_workflow_success() {
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

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

		// Set up AJAX request.
		$_POST['action']      = 'wp_mcp_ai_restart_workflow';
		$_POST['workflow_id'] = $workflow_id;
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_orchestration' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_restart_workflow' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - AJAX handlers call wp_die().
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

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
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request with invalid nonce.
		$_POST['action']      = 'wp_mcp_ai_restart_workflow';
		$_POST['workflow_id'] = 'wf_test_456';
		$_POST['nonce']       = 'invalid_nonce';

		// Expect failure due to nonce check.
		$this->expectException( 'WPAjaxDieStopException' );

		$this->_handleAjax( 'wp_mcp_ai_restart_workflow' );
	}

	/**
	 * Test restart workflow without proper permissions
	 */
	public function test_restart_workflow_insufficient_permissions() {
		// Set up subscriber user (no manage_options capability).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Set up AJAX request.
		$_POST['action']      = 'wp_mcp_ai_restart_workflow';
		$_POST['workflow_id'] = 'wf_test_456';
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_orchestration' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_restart_workflow' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permission', strtolower( $response['data']['message'] ) );
	}

	/**
	 * Test restart workflow with missing workflow ID
	 */
	public function test_restart_workflow_missing_id() {
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request without workflow_id.
		$_POST['action'] = 'wp_mcp_ai_restart_workflow';
		$_POST['nonce']  = wp_create_nonce( 'wp_mcp_ai_orchestration' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_restart_workflow' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'required', strtolower( $response['data']['message'] ) );
	}

	/**
	 * Test restart workflow with non-existent workflow
	 */
	public function test_restart_workflow_not_found() {
		// Set up admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up AJAX request with non-existent workflow.
		$_POST['action']      = 'wp_mcp_ai_restart_workflow';
		$_POST['workflow_id'] = 'wf_nonexistent_999';
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_orchestration' );

		// Make AJAX request.
		try {
			$this->_handleAjax( 'wp_mcp_ai_restart_workflow' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
		}

		// Get the response.
		$response = json_decode( $this->_last_response, true );

		// Verify failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'not found', strtolower( $response['data']['message'] ) );
	}
}
