<?php
/**
 * Tests for Create Team Modal functionality.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test Create Team Modal functionality.
 */
class Test_Create_Team_Modal extends WP_UnitTestCase {

	/**
	 * Test that the Create Team Button class exists.
	 */
	public function test_create_team_button_class_exists() {
		$this->assertTrue( class_exists( 'WP_MCP_AI_Admin_Create_Team_Button' ) );
	}

	/**
	 * Test AJAX handler creates team with valid data.
	 */
	public function test_ajax_create_team_success() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create some test professions.
		$profession1 = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Tax Advisor',
				'post_status' => 'publish',
			)
		);

		$profession2 = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Accountant',
				'post_status' => 'publish',
			)
		);

		// Set up request.
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_create_team' );
		$_POST['title']       = 'Test Team';
		$_POST['professions'] = array( $profession1, $profession2 );
		$_POST['description'] = 'A test team';
		$_POST['provider']    = 'openai';
		$_POST['model']       = 'gpt-4';
		$_POST['temperature'] = '0.7';

		// Capture the output.
		try {
			ob_start();
			WP_MCP_AI_Admin_Create_Team_Button::handle_ajax_create();
			$output = ob_get_clean();
		} catch ( Exception $e ) {
			$output = ob_get_clean();
			// wp_send_json_success exits, which throws WPAjaxDieStopException in tests.
			if ( 'WPAjaxDieStopException' === get_class( $e ) ) {
				$output = $e->getMessage();
			} else {
				throw $e;
			}
		}

		// Decode the JSON response.
		$response = json_decode( $output, true );

		// Assert success.
		$this->assertTrue( isset( $response['success'] ) );
		$this->assertTrue( $response['success'] );
		$this->assertTrue( isset( $response['data']['team_id'] ) );

		// Verify team was created.
		$team_id = $response['data']['team_id'];
		$team    = get_post( $team_id );
		$this->assertNotNull( $team );
		$this->assertEquals( 'mcp_ai_team', $team->post_type );
		$this->assertEquals( 'Test Team', $team->post_title );

		// Verify metadata.
		$members = get_post_meta( $team_id, '_wp_mcp_ai_team_members', true );
		$this->assertIsArray( $members );
		$this->assertCount( 2, $members );
		$this->assertContains( $profession1, $members );
		$this->assertContains( $profession2, $members );

		$provider = get_post_meta( $team_id, '_wp_mcp_ai_team_default_provider', true );
		$this->assertEquals( 'openai', $provider );

		$model = get_post_meta( $team_id, '_wp_mcp_ai_team_default_model', true );
		$this->assertEquals( 'gpt-4', $model );

		$temperature = get_post_meta( $team_id, '_wp_mcp_ai_team_default_temperature', true );
		$this->assertEquals( '0.7', $temperature );
	}

	/**
	 * Test AJAX handler fails with insufficient permissions.
	 */
	public function test_ajax_create_team_permission_denied() {
		// Create subscriber user.
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		// Set up request.
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_create_team' );
		$_POST['title']       = 'Test Team';
		$_POST['professions'] = array( 1, 2 );

		// Capture the output.
		try {
			ob_start();
			WP_MCP_AI_Admin_Create_Team_Button::handle_ajax_create();
			$output = ob_get_clean();
		} catch ( Exception $e ) {
			$output = ob_get_clean();
			// wp_send_json_error exits.
			if ( 'WPAjaxDieStopException' === get_class( $e ) ) {
				$output = $e->getMessage();
			} else {
				throw $e;
			}
		}

		// Decode the JSON response.
		$response = json_decode( $output, true );

		// Assert failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Insufficient permissions', $response['data']['message'] );
	}

	/**
	 * Test AJAX handler fails with too few professions.
	 */
	public function test_ajax_create_team_min_professions() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create one profession.
		$profession1 = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Tax Advisor',
				'post_status' => 'publish',
			)
		);

		// Set up request with only one profession.
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_create_team' );
		$_POST['title']       = 'Test Team';
		$_POST['professions'] = array( $profession1 );

		// Capture the output.
		try {
			ob_start();
			WP_MCP_AI_Admin_Create_Team_Button::handle_ajax_create();
			$output = ob_get_clean();
		} catch ( Exception $e ) {
			$output = ob_get_clean();
			if ( 'WPAjaxDieStopException' === get_class( $e ) ) {
				$output = $e->getMessage();
			} else {
				throw $e;
			}
		}

		// Decode the JSON response.
		$response = json_decode( $output, true );

		// Assert failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'at least 2 professions', $response['data']['message'] );
	}

	/**
	 * Test AJAX handler fails with empty title.
	 */
	public function test_ajax_create_team_empty_title() {
		// Create admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Set up request with empty title.
		$_POST['nonce']       = wp_create_nonce( 'wp_mcp_ai_create_team' );
		$_POST['title']       = '';
		$_POST['professions'] = array( 1, 2 );

		// Capture the output.
		try {
			ob_start();
			WP_MCP_AI_Admin_Create_Team_Button::handle_ajax_create();
			$output = ob_get_clean();
		} catch ( Exception $e ) {
			$output = ob_get_clean();
			if ( 'WPAjaxDieStopException' === get_class( $e ) ) {
				$output = $e->getMessage();
			} else {
				throw $e;
			}
		}

		// Decode the JSON response.
		$response = json_decode( $output, true );

		// Assert failure.
		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Team name is required', $response['data']['message'] );
	}
}
