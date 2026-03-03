<?php
/**
 * Test for Mesh Peer Sites save functionality.
 *
 * Tests that mesh peer sites configuration saves correctly when:
 * - Adding new peer sites
 * - Removing peer sites
 * - Updating existing peer sites
 *
 * @package WP_MCP_AI
 */

/**
 * Test that the Mesh Peer Sites save correctly.
 */
class WP_MCP_AI_Mesh_Peer_Sites_Save_Test extends WP_UnitTestCase {

	/**
	 * Test that adding a new peer site saves correctly.
	 */
	public function test_adding_new_peer_site_saves() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Simulate saving the federation_mesh subtab with a new peer.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_mesh'     => '1', // Mesh must be enabled.
			'mesh_peer_sites' => array(
				0 => array(
					'name'    => 'Test Peer',
					'url'     => 'https://peer.example.com',
					'api_key' => 'mesh_testkey123',
				),
			),
		);

		// Sanitize using the Advanced section.
		$section   = new WP_MCP_AI_Section_Advanced();
		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );

		// The mesh_peer_sites should be in the sanitized output.
		$this->assertArrayHasKey(
			'mesh_peer_sites',
			$sanitized,
			'mesh_peer_sites should be in sanitized settings'
		);

		// The mesh_peer_sites should be an array.
		$this->assertIsArray(
			$sanitized['mesh_peer_sites'],
			'mesh_peer_sites should be an array'
		);

		// The array should have one peer.
		$this->assertCount(
			1,
			$sanitized['mesh_peer_sites'],
			'mesh_peer_sites should have one peer'
		);

		// Verify the peer data.
		$this->assertEquals( 'Test Peer', $sanitized['mesh_peer_sites'][0]['name'] );
		$this->assertEquals( 'https://peer.example.com', $sanitized['mesh_peer_sites'][0]['url'] );
		$this->assertEquals( 'mesh_testkey123', $sanitized['mesh_peer_sites'][0]['api_key'] );

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}

	/**
	 * Test that adding multiple peer sites saves correctly.
	 */
	public function test_adding_multiple_peer_sites_saves() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Simulate saving the federation_mesh subtab with multiple peers.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_mesh'     => '1',
			'mesh_peer_sites' => array(
				0 => array(
					'name'    => 'Peer 1',
					'url'     => 'https://peer1.example.com',
					'api_key' => 'mesh_key1',
				),
				1 => array(
					'name'    => 'Peer 2',
					'url'     => 'https://peer2.example.com',
					'api_key' => 'mesh_key2',
				),
			),
		);

		// Sanitize using the Advanced section.
		$section   = new WP_MCP_AI_Section_Advanced();
		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );

		// The array should have two peers.
		$this->assertCount(
			2,
			$sanitized['mesh_peer_sites'],
			'mesh_peer_sites should have two peers'
		);

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}

	/**
	 * Test that empty peer entries are filtered out.
	 */
	public function test_empty_peer_entries_filtered_out() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Simulate saving with one valid peer and one empty entry.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_mesh'     => '1',
			'mesh_peer_sites' => array(
				0 => array(
					'name'    => 'Valid Peer',
					'url'     => 'https://valid.example.com',
					'api_key' => 'mesh_valid',
				),
				1 => array(
					'name'    => '',
					'url'     => '',
					'api_key' => '',
				),
			),
		);

		// Sanitize using the Advanced section.
		$section   = new WP_MCP_AI_Section_Advanced();
		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );

		// The array should have only one peer (empty entry filtered).
		$this->assertCount(
			1,
			$sanitized['mesh_peer_sites'],
			'Empty peer entries should be filtered out'
		);

		// Verify the remaining peer is the valid one.
		$this->assertEquals( 'Valid Peer', $sanitized['mesh_peer_sites'][0]['name'] );

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}

	/**
	 * Test that XSS attacks are sanitized.
	 */
	public function test_xss_attacks_sanitized() {
		// Clear existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Simulate saving with malicious input.
		$_POST['subtab_advanced']    = 'federation_mesh';
		$_POST['wp_mcp_ai_settings'] = array(
			'enable_mesh'     => '1',
			'mesh_peer_sites' => array(
				0 => array(
					'name'    => '<script>alert("XSS")</script>Peer',
					'url'     => 'javascript:alert("XSS")',
					'api_key' => '<script>alert("XSS")</script>',
				),
			),
		);

		// Sanitize using the Advanced section.
		$section   = new WP_MCP_AI_Section_Advanced();
		$sanitized = $section->sanitize( $_POST['wp_mcp_ai_settings'] );

		// Verify XSS was sanitized.
		$this->assertStringNotContainsString( '<script>', $sanitized['mesh_peer_sites'][0]['name'] );
		$this->assertStringNotContainsString( 'javascript:', $sanitized['mesh_peer_sites'][0]['url'] );
		$this->assertStringNotContainsString( '<script>', $sanitized['mesh_peer_sites'][0]['api_key'] );

		// Clean up.
		unset( $_POST['subtab_advanced'] );
		unset( $_POST['wp_mcp_ai_settings'] );
	}
}
