<?php
/**
 * Tests the GET/POST /mcp-ai/v1/settings REST surface for the Pro SPA
 * Settings page.
 *
 * Covers: administrator-only access, masked secrets on read, sanitized
 * persists on write (including the masked-placeholder keep-existing
 * contract), and the SPA field-name -> stored-key mapping.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_REST_Settings_Controller_Test extends WP_UnitTestCase {

	/**
	 * Set up a clean settings option per test.
	 */
	public function set_up() {
		parent::set_up();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
	}

	/**
	 * Remove the test option between tests.
	 */
	public function tear_down() {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
		parent::tear_down();
	}

	/**
	 * Build a JSON-bodied POST request.
	 *
	 * @param array $payload Payload fields.
	 * @return WP_REST_Request
	 */
	private function build_post_request( array $payload ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );
		return $request;
	}

	/**
	 * Non-administrators cannot read or write settings.
	 */
	public function test_non_admin_is_forbidden() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_REST_Settings_Controller() )->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The read payload masks stored secrets with the admin placeholder.
	 */
	public function test_admin_read_masks_secrets() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'openai_api_key'   => 'sk-secret-123',
				'gemini_api_key'   => 'gemini-secret',
				'enable_logging'   => true,
				'default_provider' => 'openai',
			)
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$response = ( new WP_MCP_AI_REST_Settings_Controller() )->get_settings_item();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertNotSame( 'sk-secret-123', $data['data']['openai_api_key'] );
		$this->assertSame( '**************', $data['data']['openai_api_key'] );
		$this->assertSame( '**************', $data['data']['google_api_key'] );
		$this->assertTrue( $data['data']['enable_logging'] );
		$this->assertSame( 'openai', $data['data']['default_provider'] );
	}

	/**
	 * A POST persists sanitized fields and maps SPA names onto stored keys.
	 */
	public function test_update_persists_sanitized_fields() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request  = $this->build_post_request(
			array(
				'openai_api_key'  => 'sk-new-key',
				'google_api_key'  => 'gemini-new-key',
				'ollama_endpoint' => 'http://localhost:11434',
				'enable_logging'  => true,
				'default_model'   => 'gpt-4.1-mini',
				'max_tokens'      => '90000',
				'temperature'     => '5',
			)
		);
		$response = ( new WP_MCP_AI_REST_Settings_Controller() )->update_settings_item( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		// Secrets come back masked, never raw.
		$this->assertSame( '**************', $data['data']['openai_api_key'] );
		$this->assertSame( '**************', $data['data']['google_api_key'] );
		$this->assertSame( 'http://localhost:11434', $data['data']['ollama_endpoint'] );
		$this->assertTrue( $data['data']['enable_logging'] );
		$this->assertSame( 'gpt-4.1-mini', $data['data']['default_model'] );
		// Out-of-range numerics are clamped.
		$this->assertSame( 32000, $data['data']['max_tokens'] );
		$this->assertSame( 2.0, $data['data']['temperature'] );

		$stored = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$this->assertNotEmpty( $stored['openai_api_key'] );
		$this->assertSame( 'http://localhost:11434', $stored['ollama_endpoint_url'] );
	}

	/**
	 * Submitting the masked placeholder preserves the existing secret.
	 */
	public function test_masked_placeholder_keeps_existing_secret() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'openai_api_key' => 'sk-existing' )
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$request = $this->build_post_request( array( 'openai_api_key' => '**************' ) );
		( new WP_MCP_AI_REST_Settings_Controller() )->update_settings_item( $request );

		$stored = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$this->assertSame( 'sk-existing', $stored['openai_api_key'] );
	}

	/**
	 * An empty sensitive field clears the stored secret.
	 */
	public function test_empty_sensitive_field_clears_secret() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'openai_api_key' => 'sk-existing' )
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$request = $this->build_post_request( array( 'openai_api_key' => '' ) );
		( new WP_MCP_AI_REST_Settings_Controller() )->update_settings_item( $request );

		$stored = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$this->assertSame( '', $stored['openai_api_key'] );
	}
}
