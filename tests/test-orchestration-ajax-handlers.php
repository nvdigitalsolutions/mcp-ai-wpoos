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
}
