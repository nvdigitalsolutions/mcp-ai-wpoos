<?php
/**
 * Tests for mesh peer sites field validation and JSON decoding.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Mesh_Peer_Sites_Validation
 */
class Test_Mesh_Peer_Sites_Validation extends WP_UnitTestCase {
	/**
	 * Admin user for testing.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		// Clear any existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
	}

	/**
	 * Test that empty mesh_peer_sites textarea is converted to empty array.
	 */
	public function test_empty_mesh_peer_sites_converts_to_array() {
		// Simulate form submission with empty mesh_peer_sites textarea.
		$_POST['wp_mcp_ai_settings_nonce'] = wp_create_nonce( 'wp_mcp_ai_settings' );
		$_POST['active_tab']                = 'advanced';
		$_POST['active_subtab']             = 'federation_mesh';

		$input = array(
			'enable_mesh'          => 'true',
			'enable_federation'    => 'true',
			'mesh_peer_sites'      => '', // Empty string from empty textarea.
		);

		// Get the section instance.
		$section = new WP_MCP_AI_Section_Advanced();

		// Sanitize through the section's method.
		$sanitized = $section->sanitize( $input );

		// Verify mesh_peer_sites is converted to empty array.
		$this->assertIsArray( $sanitized['mesh_peer_sites'], 'Empty mesh_peer_sites should be converted to array' );
		$this->assertEmpty( $sanitized['mesh_peer_sites'], 'Empty mesh_peer_sites should be an empty array' );
	}

	/**
	 * Test that valid JSON mesh_peer_sites textarea is decoded to array.
	 */
	public function test_valid_json_mesh_peer_sites_decodes_to_array() {
		$_POST['wp_mcp_ai_settings_nonce'] = wp_create_nonce( 'wp_mcp_ai_settings' );
		$_POST['active_tab']                = 'advanced';
		$_POST['active_subtab']             = 'federation_mesh';

		$peer_json = '[{"url":"https://peer1.example.com","api_key":"test_key_placeholder_12345","name":"Peer 1","enabled":true}]';

		$input = array(
			'enable_mesh'          => 'true',
			'mesh_peer_sites'      => $peer_json, // JSON string from textarea.
		);

		$section   = new WP_MCP_AI_Section_Advanced();
		$sanitized = $section->sanitize( $input );

		// Verify mesh_peer_sites is decoded to array.
		$this->assertIsArray( $sanitized['mesh_peer_sites'], 'Valid JSON mesh_peer_sites should be decoded to array' );
		$this->assertCount( 1, $sanitized['mesh_peer_sites'], 'Should have one peer site' );
		$this->assertArrayHasKey( 'url', $sanitized['mesh_peer_sites'][0], 'Peer should have url field' );
		$this->assertEquals( 'https://peer1.example.com', $sanitized['mesh_peer_sites'][0]['url'] );
	}

	/**
	 * Test that invalid JSON mesh_peer_sites defaults to empty array.
	 */
	public function test_invalid_json_mesh_peer_sites_defaults_to_empty_array() {
		$_POST['wp_mcp_ai_settings_nonce'] = wp_create_nonce( 'wp_mcp_ai_settings' );
		$_POST['active_tab']                = 'advanced';
		$_POST['active_subtab']             = 'federation_mesh';

		$invalid_json = '{"url":"peer1.example.com", broken json}'; // Invalid JSON.

		$input = array(
			'enable_mesh'          => 'true',
			'mesh_peer_sites'      => $invalid_json,
		);

		$section   = new WP_MCP_AI_Section_Advanced();
		$sanitized = $section->sanitize( $input );

		// Verify invalid JSON defaults to empty array (not error).
		$this->assertIsArray( $sanitized['mesh_peer_sites'], 'Invalid JSON should default to array' );
		$this->assertEmpty( $sanitized['mesh_peer_sites'], 'Invalid JSON should default to empty array' );
	}

	/**
	 * Test that mesh_peer_sites passes validation after JSON decoding.
	 */
	public function test_mesh_peer_sites_passes_validation_after_decoding() {
		// Simulate enabling federation mesh features with empty peer sites.
		$input = array(
			'enable_mesh'               => true,
			'enable_federation'         => true,
			'enable_federation_directory' => true,
			'mesh_peer_sites'           => array(), // Already decoded to array.
		);

		// Save settings through the dashboard.
		$dashboard = new WP_MCP_AI_Settings_Dashboard();
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() ); // Clear settings.

		// Directly call validation method.
		$reflection = new ReflectionClass( $dashboard );
		$method     = $reflection->getMethod( 'validate_merged_settings' );
		$method->setAccessible( true );

		$errors = $method->invoke( $dashboard, $input, array() );

		// Verify no validation errors for mesh_peer_sites being an array.
		$this->assertIsArray( $errors, 'Validation should return an array' );
		$this->assertEmpty( $errors, 'Should have no validation errors when mesh_peer_sites is an array' );
	}

	/**
	 * Test that validation catches non-array mesh_peer_sites.
	 */
	public function test_validation_catches_non_array_mesh_peer_sites() {
		$dashboard = new WP_MCP_AI_Settings_Dashboard();

		// Try to save with mesh_peer_sites as a string (should fail validation).
		$input = array(
			'enable_mesh'     => true,
			'mesh_peer_sites' => 'not an array', // This should trigger validation error.
		);

		$reflection = new ReflectionClass( $dashboard );
		$method     = $reflection->getMethod( 'validate_merged_settings' );
		$method->setAccessible( true );

		$errors = $method->invoke( $dashboard, $input, array() );

		// Verify validation error exists.
		$this->assertNotEmpty( $errors, 'Should have validation errors for non-array mesh_peer_sites' );
		$this->assertStringContainsString( 'Mesh peer sites must be an array', implode( '; ', $errors ) );
	}

	/**
	 * Test end-to-end: form submission with empty JSON doesn't cause validation error.
	 */
	public function test_end_to_end_empty_mesh_peer_sites_no_validation_error() {
		$_POST['wp_mcp_ai_settings_nonce'] = wp_create_nonce( 'wp_mcp_ai_settings' );
		$_POST['active_tab']                = 'advanced';
		$_POST['active_subtab']             = 'federation_mesh';
		$_POST['save_all_tabs']             = '0';

		// Simulate form data: enable federation features with empty peer sites.
		$form_data = array(
			'enable_mesh'               => 'true',
			'enable_federation'         => 'true',
			'enable_federation_directory' => 'true',
			'mesh_peer_sites'           => '', // Empty textarea.
			'federation_regions'        => '',
			'federation_data_tags'      => '',
			'federation_qps'            => '100',
			'federation_burst'          => '200',
			'federation_jwks_keys'      => '',
			'federation_price_hints'    => '',
			'mesh_inbound_api_key'      => '',
		);

		$dashboard = new WP_MCP_AI_Settings_Dashboard();
		$sanitized = $dashboard->sanitize_settings( $form_data, 'advanced' );

		// Get settings errors.
		$errors = get_settings_errors( 'wp_mcp_ai_settings' );

		// Check for the specific validation error.
		$has_mesh_error = false;
		foreach ( $errors as $error ) {
			if ( strpos( $error['message'], 'Mesh peer sites must be an array' ) !== false ) {
				$has_mesh_error = true;
				break;
			}
		}

		$this->assertFalse( $has_mesh_error, 'Should not have "Mesh peer sites must be an array" validation error' );

		// Verify the saved settings have mesh_peer_sites as an array.
		$saved_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		if ( isset( $saved_settings['mesh_peer_sites'] ) ) {
			$this->assertIsArray( $saved_settings['mesh_peer_sites'], 'Saved mesh_peer_sites should be an array' );
		}
	}
}
