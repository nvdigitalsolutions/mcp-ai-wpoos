<?php
/**
 * Test Apple Messages for Business (iMessage) field persistence in Remote Site Manager.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test class for Remote Site Manager Apple Messages for Business field persistence.
 */
class Test_Remote_Connection_Apple_Messages_Fields extends WP_UnitTestCase {

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
	 * Test that apple_messages connection type can be saved.
	 */
	public function test_apple_messages_connection_type_saves() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test Apple Messages Connection',
			'url'             => 'https://api.example-msp.com/v1/apple/messages',
			'connection_type' => 'apple_messages',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_msp_api_key',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return WP_Error' );
		$this->assertNotEmpty( $result, 'Connection save should return a connection ID' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertSame( 'apple_messages', $saved['connection_type'], 'Connection type should be apple_messages' );
	}

	/**
	 * Test that apple_messages MSP API URL persists.
	 */
	public function test_apple_messages_url_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$msp_url = 'https://api.example-msp.com/v1/apple/messages';

		$connection_data = array(
			'name'            => 'Test Apple Messages URL',
			'url'             => $msp_url,
			'connection_type' => 'apple_messages',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_msp_api_key_url',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return WP_Error' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertSame( $msp_url, $saved['url'], 'MSP API URL should persist' );
	}

	/**
	 * Test that the Apple Business ID persists as business_id field.
	 */
	public function test_apple_messages_business_id_persists() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Test Apple Messages Business ID',
			'url'             => 'https://api.example-msp.com/v1/apple/messages',
			'connection_type' => 'apple_messages',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_api_key',
			'business_id'     => 'test-apple-business-id-12345',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return WP_Error' );

		$saved = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $result );

		$this->assertNotNull( $saved, 'Saved connection should be retrievable' );
		$this->assertSame( 'test-apple-business-id-12345', $saved['business_id'], 'Apple Business ID should persist' );
	}

	/**
	 * Test that the apple_messages connection type is included in the list of all connections.
	 */
	public function test_apple_messages_connection_appears_in_all_connections() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
			return;
		}

		$connection_data = array(
			'name'            => 'Apple Messages Test Connection',
			'url'             => 'https://api.example-msp.com/v1/apple/messages',
			'connection_type' => 'apple_messages',
			'auth_type'       => 'none',
			'enabled'         => true,
			'api_key'         => 'test_api_key_list',
		);

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection_data );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Connection save should not return WP_Error' );

		$all_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		$found = false;
		foreach ( $all_connections as $conn ) {
			if ( isset( $conn['connection_type'] ) && 'apple_messages' === $conn['connection_type'] ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Apple Messages connection should appear in all connections list' );
	}
}
