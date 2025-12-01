<?php
/**
 * Tests for section subtab sanitization (fix for private method visibility issue).
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test that sections with subtabs can properly sanitize settings.
 *
 * This test verifies the fix for the fatal error:
 * "Call to private method get_active_subtab() from scope WP_MCP_AI_Settings_Section"
 */
class WP_MCP_AI_Section_Subtab_Sanitization_Test extends WP_UnitTestCase {

	/**
	 * Test that General section can sanitize with subtabs.
	 */
	public function test_general_section_sanitize_with_subtabs() {
		$section = new WP_MCP_AI_Section_General();

		// Simulate form submission for the 'core' subtab.
		$_POST['subtab'] = 'core';
		$submitted       = array(
			'default_provider'  => 'openai',
			'default_assistant' => '0',
		);

		// This should not throw a fatal error about private method access.
		$sanitized = $section->sanitize( $submitted );

		// Verify sanitization worked.
		$this->assertIsArray( $sanitized );
		$this->assertArrayHasKey( 'default_provider', $sanitized );

		// Clean up.
		unset( $_POST['subtab'] );
	}

	/**
	 * Test that Advanced section can sanitize with subtabs.
	 */
	public function test_advanced_section_sanitize_with_subtabs() {
		$section = new WP_MCP_AI_Section_Advanced();

		// Simulate form submission for the 'debugging' subtab.
		$_POST['subtab'] = 'debugging';
		$submitted       = array(
			'enable_logging'          => '1',
			'enable_extended_logging' => '0',
		);

		// This should not throw a fatal error about private method access.
		$sanitized = $section->sanitize( $submitted );

		// Verify sanitization worked.
		$this->assertIsArray( $sanitized );

		// Clean up.
		unset( $_POST['subtab'] );
	}

	/**
	 * Test that Authentication section can sanitize with subtabs.
	 */
	public function test_authentication_section_sanitize_with_subtabs() {
		$section = new WP_MCP_AI_Section_Authentication();

		// Simulate form submission for the 'auth0' subtab.
		$_POST['subtab'] = 'auth0';
		$submitted       = array(
			'auth0_domain'         => 'test.auth0.com',
			'auth0_audience'       => 'test-audience',
			'auth0_required_scope' => 'test:scope',
		);

		// This should not throw a fatal error about private method access.
		$sanitized = $section->sanitize( $submitted );

		// Verify sanitization worked.
		$this->assertIsArray( $sanitized );
		$this->assertArrayHasKey( 'auth0_domain', $sanitized );

		// Clean up.
		unset( $_POST['subtab'] );
	}

	/**
	 * Test that Tools section can sanitize with subtabs.
	 */
	public function test_tools_section_sanitize_with_subtabs() {
		$section = new WP_MCP_AI_Section_Tools();

		// Simulate form submission for the 'external_tools' subtab.
		$_POST['subtab'] = 'external_tools';
		$submitted       = array(
			'crawl4ai_base_url' => 'http://localhost:8000',
		);

		// This should not throw a fatal error about private method access.
		$sanitized = $section->sanitize( $submitted );

		// Verify sanitization worked.
		$this->assertIsArray( $sanitized );

		// Clean up.
		unset( $_POST['subtab'] );
	}

	/**
	 * Test that Integrations section can sanitize with subtabs.
	 */
	public function test_integrations_section_sanitize_with_subtabs() {
		$section = new WP_MCP_AI_Section_Integrations();

		// Simulate form submission for the 'gmail' subtab.
		$_POST['subtab'] = 'gmail';
		$submitted       = array(
			'gmail_client_id'     => 'test-client-id',
			'gmail_client_secret' => 'test-secret',
		);

		// This should not throw a fatal error about private method access.
		$sanitized = $section->sanitize( $submitted );

		// Verify sanitization worked.
		$this->assertIsArray( $sanitized );
		$this->assertArrayHasKey( 'gmail_client_id', $sanitized );
		$this->assertArrayHasKey( 'gmail_client_secret', $sanitized );

		// Clean up.
		unset( $_POST['subtab'] );
	}

	/**
	 * Test that Integrations section Meta subtab works.
	 */
	public function test_integrations_meta_subtab_sanitize() {
		$section = new WP_MCP_AI_Section_Integrations();

		// Simulate form submission for the 'meta' subtab.
		$_POST['subtab'] = 'meta';
		$submitted       = array(
			'meta_access_token'        => 'test-token',
			'meta_app_id'              => '123456',
			'meta_app_secret'          => 'secret',
			'meta_business_account_id' => '789',
		);

		// This should not throw a fatal error.
		$sanitized = $section->sanitize( $submitted );

		// Verify sanitization worked.
		$this->assertIsArray( $sanitized );
		$this->assertArrayHasKey( 'meta_access_token', $sanitized );
		$this->assertEquals( 'test-token', $sanitized['meta_access_token'] );

		// Clean up.
		unset( $_POST['subtab'] );
	}

	/**
	 * Test that Integrations section TikTok subtab works.
	 */
	public function test_integrations_tiktok_subtab_sanitize() {
		$section = new WP_MCP_AI_Section_Integrations();

		// Simulate form submission for the 'tiktok' subtab.
		$_POST['subtab'] = 'tiktok';
		$submitted       = array(
			'tiktok_access_token'  => 'test-token',
			'tiktok_client_key'    => 'client-key',
			'tiktok_client_secret' => 'secret',
		);

		// This should not throw a fatal error.
		$sanitized = $section->sanitize( $submitted );

		// Verify sanitization worked.
		$this->assertIsArray( $sanitized );
		$this->assertArrayHasKey( 'tiktok_access_token', $sanitized );
		$this->assertEquals( 'test-token', $sanitized['tiktok_access_token'] );

		// Clean up.
		unset( $_POST['subtab'] );
	}

	/**
	 * Test that Providers section can sanitize with subtabs (uses get_provider_groups).
	 */
	public function test_providers_section_sanitize_with_subtabs() {
		$section = new WP_MCP_AI_Section_Providers();

		// Simulate form submission for the 'openai' subtab.
		$_POST['subtab'] = 'openai';
		$submitted       = array(
			'openai_api_key' => 'sk-test-key-123',
			'default_model'  => 'gpt-4o',
		);

		// This should not throw a fatal error about private method access.
		$sanitized = $section->sanitize( $submitted );

		// Verify sanitization worked.
		$this->assertIsArray( $sanitized );

		// Clean up.
		unset( $_POST['subtab'] );
	}

	/**
	 * Test that method visibility allows parent class access.
	 */
	public function test_protected_method_visibility() {
		$section = new WP_MCP_AI_Section_General();

		// Use reflection to verify the method is protected, not private.
		$reflection = new ReflectionClass( $section );

		$get_active_subtab = $reflection->getMethod( 'get_active_subtab' );
		$this->assertTrue(
			$get_active_subtab->isProtected(),
			'get_active_subtab should be protected for parent class access'
		);

		$get_subtab_groups = $reflection->getMethod( 'get_subtab_groups' );
		$this->assertTrue(
			$get_subtab_groups->isProtected(),
			'get_subtab_groups should be protected for parent class access'
		);
	}
}
