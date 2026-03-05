<?php
/**
 * Test Office 365 field persistence in Remote Site Manager.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for Remote Site Manager Office 365 field persistence.
 */
class Test_Remote_Connection_Office365_Fields extends WP_UnitTestCase {

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
	 * Test that office365 connection type can be saved.
	 */
	public function test_office365_connection_type_saves() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test Office 365 Connection',
			'url'             => 'https://graph.microsoft.com/v1.0',
			'connection_type' => 'office365',
			'auth_type'       => 'none',
			'enabled'         => true,
			'client_id'       => 'test-azure-client-id',
			'tenant_id'       => 'test-azure-tenant-id',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return WP_Error' );
		$this->assertNotEmpty( $result, 'Connection save should return a connection ID' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertSame( 'office365', $saved['connection_type'], 'Connection type should be office365' );
	}

	/**
	 * Test that office365 client_id field persists.
	 */
	public function test_office365_client_id_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test Office 365 Client ID',
			'url'             => 'https://graph.microsoft.com/v1.0',
			'connection_type' => 'office365',
			'auth_type'       => 'none',
			'enabled'         => true,
			'client_id'       => 'test-azure-client-id',
			'tenant_id'       => 'test-azure-tenant-id',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return WP_Error' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertSame( 'test-azure-client-id', $saved['client_id'], 'Client ID should persist' );
	}

	/**
	 * Test that office365 tenant_id field persists.
	 */
	public function test_office365_tenant_id_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test Office 365 Tenant ID',
			'url'             => 'https://graph.microsoft.com/v1.0',
			'connection_type' => 'office365',
			'auth_type'       => 'none',
			'enabled'         => true,
			'client_id'       => 'test-azure-client-id',
			'tenant_id'       => 'test-azure-tenant-id',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return WP_Error' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertSame( 'test-azure-tenant-id', $saved['tenant_id'], 'Tenant ID should persist' );
	}

	/**
	 * Test that the office365 connection type is included in the list of all connections.
	 */
	public function test_office365_connection_appears_in_all_connections() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Office 365 Test Connection',
			'url'             => 'https://graph.microsoft.com/v1.0',
			'connection_type' => 'office365',
			'auth_type'       => 'none',
			'enabled'         => true,
			'client_id'       => 'test-azure-client-id',
			'tenant_id'       => 'test-azure-tenant-id',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return WP_Error' );

		$all_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		$found = false;
		foreach ( $all_connections as $conn ) {
			if ( isset( $conn['connection_type'] ) && 'office365' === $conn['connection_type'] ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Office 365 connection should appear in all connections list' );
	}
}
