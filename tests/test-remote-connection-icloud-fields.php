<?php
/**
 * Test iCloud field persistence in Remote Site Manager.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for Remote Site Manager iCloud field persistence.
 */
class Test_Remote_Connection_iCloud_Fields extends WP_UnitTestCase {

	/**
	 * Clean up connections before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
	}

	/**
	 * Clean up connections after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
		parent::tearDown();
	}

	/**
	 * Test that icloud connection type can be saved.
	 */
	public function test_icloud_connection_type_saves() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test iCloud Connection',
			'url'             => 'https://gateway.example.com/api/icloud',
			'connection_type' => 'icloud',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test-icloud-api-key',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return WP_Error' );
		$this->assertNotEmpty( $result, 'Connection save should return a connection ID' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertSame( 'icloud', $saved['connection_type'], 'Connection type should be icloud' );
	}

	/**
	 * Test that icloud gateway URL persists.
	 */
	public function test_icloud_gateway_url_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$gateway_url = 'https://gateway.example.com/api/icloud';

		$connection_data = array(
			'name'            => 'Test iCloud Gateway URL',
			'url'             => $gateway_url,
			'connection_type' => 'icloud',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test-icloud-api-key-url',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return WP_Error' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertSame( $gateway_url, $saved['url'], 'Gateway URL should persist' );
	}

	/**
	 * Test that icloud api_key field persists.
	 */
	public function test_icloud_api_key_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test iCloud API Key',
			'url'             => 'https://gateway.example.com/api/icloud',
			'connection_type' => 'icloud',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test-icloud-api-key',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return WP_Error' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertSame( 'test-icloud-api-key', $saved['api_key'], 'API key should persist' );
	}

	/**
	 * Test that the icloud connection type is included in the list of all connections.
	 */
	public function test_icloud_connection_appears_in_all_connections() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'iCloud Test Connection',
			'url'             => 'https://gateway.example.com/api/icloud',
			'connection_type' => 'icloud',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test-icloud-api-key-list',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return WP_Error' );

		$all_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		$found = false;
		foreach ( $all_connections as $conn ) {
			if ( isset( $conn['connection_type'] ) && 'icloud' === $conn['connection_type'] ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'iCloud connection should appear in all connections list' );
	}
}
