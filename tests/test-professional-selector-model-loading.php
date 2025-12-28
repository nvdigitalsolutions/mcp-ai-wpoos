<?php
/**
 * Tests for Professional Selector Model Loading AJAX Handler
 *
 * Tests that the professional selector widget can load models for both
 * logged-in and logged-out users (guests).
 *
 * @package WP_MCP_AI
 */

/**
 * Test Professional Selector Model Loading functionality.
 */
class Test_Professional_Selector_Model_Loading extends WP_Ajax_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * Regular user ID.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Professional selector shortcode instance.
	 *
	 * @var WP_MCP_AI_Professional_Selector_Shortcode
	 */
	protected $shortcode;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create admin user.
		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Create regular user (editor role to have edit_posts capability).
		$this->user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		// Ensure the professional selector shortcode class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Professional_Selector_Shortcode' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-professional-selector-shortcode.php';
		}

		// Initialize the shortcode instance.
		$this->shortcode = new WP_MCP_AI_Professional_Selector_Shortcode();
	}

	/**
	 * Test that the wp_ajax hook is registered for logged-in users.
	 */
	public function test_ajax_hook_registered_for_logged_in_users() {
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_get_models_for_provider' ) !== false,
			'wp_ajax_wp_mcp_ai_get_models_for_provider should be registered for logged-in users'
		);
	}

	/**
	 * Test that the wp_ajax_nopriv hook is registered for guests.
	 */
	public function test_ajax_hook_registered_for_guests() {
		$this->assertTrue(
			has_action( 'wp_ajax_nopriv_wp_mcp_ai_get_models_for_provider' ) !== false,
			'wp_ajax_nopriv_wp_mcp_ai_get_models_for_provider should be registered for guests'
		);
	}

	/**
	 * Test that both hooks point to the same handler in the shortcode class.
	 */
	public function test_both_hooks_use_shortcode_handler() {
		global $wp_filter;

		$ajax_hook        = 'wp_ajax_wp_mcp_ai_get_models_for_provider';
		$ajax_nopriv_hook = 'wp_ajax_nopriv_wp_mcp_ai_get_models_for_provider';

		// Check that both hooks are registered.
		$this->assertArrayHasKey( $ajax_hook, $wp_filter );
		$this->assertArrayHasKey( $ajax_nopriv_hook, $wp_filter );

		// Get the callbacks for both hooks.
		$ajax_callbacks        = $wp_filter[ $ajax_hook ]->callbacks;
		$ajax_nopriv_callbacks = $wp_filter[ $ajax_nopriv_hook ]->callbacks;

		// Both should have callbacks registered.
		$this->assertNotEmpty( $ajax_callbacks );
		$this->assertNotEmpty( $ajax_nopriv_callbacks );

		// Find the professional selector shortcode handler in each.
		$found_ajax        = false;
		$found_ajax_nopriv = false;

		foreach ( $ajax_callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) &&
					$callback['function'][0] instanceof WP_MCP_AI_Professional_Selector_Shortcode &&
					'handle_get_models_for_provider' === $callback['function'][1] ) {
					$found_ajax = true;
					break 2;
				}
			}
		}

		foreach ( $ajax_nopriv_callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) &&
					$callback['function'][0] instanceof WP_MCP_AI_Professional_Selector_Shortcode &&
					'handle_get_models_for_provider' === $callback['function'][1] ) {
					$found_ajax_nopriv = true;
					break 2;
				}
			}
		}

		$this->assertTrue(
			$found_ajax,
			'Professional selector shortcode handler should be registered for logged-in users'
		);
		$this->assertTrue(
			$found_ajax_nopriv,
			'Professional selector shortcode handler should be registered for guests'
		);
	}

	/**
	 * Test that logged-in users can access the model loading endpoint with proper nonce.
	 */
	public function test_logged_in_user_can_load_models_with_valid_nonce() {
		wp_set_current_user( $this->user_id );

		$_POST['nonce']    = wp_create_nonce( 'wp-mcp-ai-professional-selector' );
		$_POST['provider'] = 'openai';

		try {
			$this->_handleAjax( 'wp_mcp_ai_get_models_for_provider' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected - AJAX will call wp_die().
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );

		// The response should be a success or error with models data.
		// It won't have models if OpenAI isn't configured, but it should not fail with 403.
		$this->assertIsArray( $response, 'Response should be a valid JSON array' );
		$this->assertArrayHasKey( 'success', $response, 'Response should have success key' );

		// If it failed, it should NOT be due to nonce or permission issues.
		if ( ! $response['success'] ) {
			$this->assertArrayHasKey( 'data', $response );
			$this->assertArrayHasKey( 'message', $response['data'] );
			// Ensure error is NOT related to authentication or permission.
			$this->assertStringNotContainsStringIgnoringCase(
				'nonce',
				$response['data']['message'],
				'Error should not be related to nonce verification'
			);
			$this->assertStringNotContainsStringIgnoringCase(
				'permission',
				$response['data']['message'],
				'Error should not be related to permissions'
			);
		}
	}

	/**
	 * Test that logged-in users cannot access with invalid nonce.
	 */
	public function test_logged_in_user_rejected_with_invalid_nonce() {
		wp_set_current_user( $this->user_id );

		$_POST['nonce']    = 'invalid_nonce';
		$_POST['provider'] = 'openai';

		try {
			$this->_handleAjax( 'wp_mcp_ai_get_models_for_provider' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
			unset( $e );
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );

		$this->assertFalse( $response['success'], 'Request with invalid nonce should fail' );
	}

	/**
	 * Test that guests can access the model loading endpoint with proper nonce.
	 */
	public function test_guest_can_load_models_with_valid_nonce() {
		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		$_POST['nonce']    = wp_create_nonce( 'wp-mcp-ai-professional-selector' );
		$_POST['provider'] = 'openai';

		try {
			$this->_handleAjax( 'wp_mcp_ai_get_models_for_provider' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );

		// The response should be a success or error with models data.
		$this->assertIsArray( $response, 'Response should be a valid JSON array' );
		$this->assertArrayHasKey( 'success', $response, 'Response should have success key' );

		// If it failed, it should NOT be due to nonce or permission issues.
		if ( ! $response['success'] ) {
			$this->assertArrayHasKey( 'data', $response );
			$this->assertArrayHasKey( 'message', $response['data'] );
			// Ensure error is NOT related to authentication or permission.
			$this->assertStringNotContainsStringIgnoringCase(
				'nonce',
				$response['data']['message'],
				'Error should not be related to nonce verification'
			);
			$this->assertStringNotContainsStringIgnoringCase(
				'permission',
				$response['data']['message'],
				'Error should not be related to permissions'
			);
		}
	}

	/**
	 * Test that the handler rejects requests without a provider parameter.
	 */
	public function test_handler_rejects_missing_provider() {
		wp_set_current_user( $this->user_id );

		$_POST['nonce'] = wp_create_nonce( 'wp-mcp-ai-professional-selector' );
		// Intentionally omit provider parameter.

		try {
			$this->_handleAjax( 'wp_mcp_ai_get_models_for_provider' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );

		$this->assertFalse( $response['success'], 'Request without provider should fail' );
		$this->assertArrayHasKey( 'data', $response );
		$this->assertArrayHasKey( 'message', $response['data'] );
		$this->assertStringContainsStringIgnoringCase(
			'provider',
			$response['data']['message'],
			'Error message should mention missing provider'
		);
	}

	/**
	 * Test that the handler uses the correct nonce action.
	 */
	public function test_handler_uses_professional_selector_nonce() {
		wp_set_current_user( $this->user_id );

		// Use the correct nonce for professional selector.
		$_POST['nonce']    = wp_create_nonce( 'wp-mcp-ai-professional-selector' );
		$_POST['provider'] = 'openai';

		try {
			$this->_handleAjax( 'wp_mcp_ai_get_models_for_provider' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );

		// Should not fail due to nonce issue.
		$this->assertIsArray( $response );
		if ( ! $response['success'] ) {
			$this->assertStringNotContainsStringIgnoringCase(
				'nonce',
				$response['data']['message'] ?? '',
				'Should not fail due to nonce when using correct nonce action'
			);
		}
	}

	/**
	 * Test that admin AJAX handler doesn't interfere with professional selector.
	 *
	 * This ensures the admin handler (which uses different nonce) doesn't
	 * override the professional selector handler for logged-in users.
	 */
	public function test_admin_handler_does_not_interfere() {
		wp_set_current_user( $this->admin_user_id );

		// Use professional selector nonce (not admin nonce).
		$_POST['nonce']    = wp_create_nonce( 'wp-mcp-ai-professional-selector' );
		$_POST['provider'] = 'openai';

		try {
			$this->_handleAjax( 'wp_mcp_ai_get_models_for_provider' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );

		// Even for admin users, professional selector nonce should work.
		$this->assertIsArray( $response );
		if ( ! $response['success'] ) {
			// Should not fail due to nonce or permission issues.
			$this->assertStringNotContainsStringIgnoringCase(
				'nonce',
				$response['data']['message'] ?? '',
				'Admin should be able to use professional selector with its nonce'
			);
			$this->assertStringNotContainsStringIgnoringCase(
				'permission',
				$response['data']['message'] ?? '',
				'Admin should have sufficient permissions'
			);
		}
	}

	/**
	 * Test that the render professional chat AJAX hook is registered for logged-in users.
	 */
	public function test_render_chat_ajax_hook_registered_for_logged_in_users() {
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_render_professional_chat' ) !== false,
			'wp_ajax_wp_mcp_ai_render_professional_chat should be registered for logged-in users'
		);
	}

	/**
	 * Test that the render professional chat AJAX hook is registered for guests.
	 */
	public function test_render_chat_ajax_hook_registered_for_guests() {
		$this->assertTrue(
			has_action( 'wp_ajax_nopriv_wp_mcp_ai_render_professional_chat' ) !== false,
			'wp_ajax_nopriv_wp_mcp_ai_render_professional_chat should be registered for guests'
		);
	}

	/**
	 * Test that the render chat handler rejects requests with invalid nonce.
	 */
	public function test_render_chat_rejects_invalid_nonce() {
		wp_set_current_user( $this->user_id );

		$_POST['nonce']          = 'invalid_nonce';
		$_POST['shortcode_atts'] = 'assistant="profession_123"';

		try {
			$this->_handleAjax( 'wp_mcp_ai_render_professional_chat' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );

		$this->assertFalse( $response['success'], 'Request with invalid nonce should fail' );
	}

	/**
	 * Test that the render chat handler rejects requests without shortcode attributes.
	 */
	public function test_render_chat_rejects_missing_shortcode_atts() {
		wp_set_current_user( $this->user_id );

		$_POST['nonce'] = wp_create_nonce( 'wp-mcp-ai-professional-selector' );
		// Intentionally omit shortcode_atts parameter.

		try {
			$this->_handleAjax( 'wp_mcp_ai_render_professional_chat' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );

		$this->assertFalse( $response['success'], 'Request without shortcode_atts should fail' );
		$this->assertArrayHasKey( 'data', $response );
		$this->assertArrayHasKey( 'message', $response['data'] );
		$this->assertStringContainsStringIgnoringCase(
			'shortcode',
			$response['data']['message'],
			'Error message should mention invalid/missing shortcode attributes'
		);
	}

	/**
	 * Test that guests can access the render chat endpoint with valid nonce.
	 */
	public function test_guest_can_render_chat_with_valid_nonce() {
		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		$_POST['nonce']          = wp_create_nonce( 'wp-mcp-ai-professional-selector' );
		$_POST['shortcode_atts'] = 'assistant="profession_123" allow_guests="true"';

		try {
			$this->_handleAjax( 'wp_mcp_ai_render_professional_chat' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );

		// The response should be an array with success key.
		$this->assertIsArray( $response, 'Response should be a valid JSON array' );
		$this->assertArrayHasKey( 'success', $response, 'Response should have success key' );

		// If it failed, it should NOT be due to nonce or permission issues.
		if ( ! $response['success'] ) {
			$this->assertArrayHasKey( 'data', $response );
			$this->assertArrayHasKey( 'message', $response['data'] );
			// Ensure error is NOT related to authentication or permission.
			$this->assertStringNotContainsStringIgnoringCase(
				'nonce',
				$response['data']['message'],
				'Error should not be related to nonce verification'
			);
		}
	}

	/**
	 * Test that render chat handler returns both HTML and config on success.
	 *
	 * This test verifies the fix for the "Assistant configuration was not found" error
	 * when loading chat in the professional selector modal.
	 */
	public function test_render_chat_returns_config_with_html() {
		wp_set_current_user( $this->user_id );

		// Create a test assistant for the shortcode.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		$_POST['nonce']          = wp_create_nonce( 'wp-mcp-ai-professional-selector' );
		$_POST['shortcode_atts'] = 'assistant="' . $assistant_id . '"';

		try {
			$this->_handleAjax( 'wp_mcp_ai_render_professional_chat' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected.
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );

		// The response should be successful.
		$this->assertTrue( $response['success'], 'Render chat should succeed with valid assistant' );

		// Verify response contains both html and config.
		$this->assertArrayHasKey( 'data', $response, 'Response should have data key' );
		$this->assertArrayHasKey( 'html', $response['data'], 'Response data should contain html' );
		$this->assertArrayHasKey( 'config', $response['data'], 'Response data should contain config for modal initialization' );

		// Verify the config is a valid array with required keys.
		$config = $response['data']['config'];
		$this->assertIsArray( $config, 'Config should be an array' );
		$this->assertArrayHasKey( 'id', $config, 'Config should have instance id' );
		$this->assertArrayHasKey( 'assistantId', $config, 'Config should have assistantId' );
		$this->assertArrayHasKey( 'restUrl', $config, 'Config should have restUrl' );
		$this->assertArrayHasKey( 'messagesEndpoint', $config, 'Config should have messagesEndpoint' );

		// Verify the HTML contains the chat interface.
		$html = $response['data']['html'];
		$this->assertStringContainsString( 'wp-mcp-ai-chat', $html, 'HTML should contain chat container' );
		$this->assertStringContainsString( 'data-wp-mcp-ai-chat', $html, 'HTML should contain chat data attribute' );
	}
}
