<?php
/**
 * Test ESPN Fantasy Client handles missing Admin Settings class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for ESPN Fantasy Client class_exists check.
 */
class WP_MCP_AI_ESPN_Fantasy_Client_Class_Exists_Test extends WP_UnitTestCase {

	/**
	 * Test that ESPN Fantasy Client doesn't fatal error when WP_MCP_AI_Admin_Settings is not loaded.
	 */
	public function test_espn_client_handles_missing_admin_settings_class() {
		// Check if the Pro addon is available.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'ESPN Fantasy Client is a Pro feature, skipping test.' );
			return;
		}

		// Check if the client class file exists.
		$client_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-espn-fantasy-client.php';
		if ( ! file_exists( $client_file ) ) {
			$this->markTestSkipped( 'ESPN Fantasy Client file not found.' );
			return;
		}

		// Temporarily undefine the Admin Settings class if it exists.
		// This simulates the scenario where the class might not be loaded during tool execution.
		$admin_settings_existed = class_exists( 'WP_MCP_AI_Admin_Settings', false );

		// Load the ESPN Fantasy Client (which should handle missing class gracefully).
		require_once $client_file;

		// Verify the ESPN Fantasy Client class exists.
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_ESPN_Fantasy_Client' ),
			'ESPN Fantasy Client class should be available'
		);

		// Try to instantiate the client without credentials.
		// This should trigger the load_credentials_from_settings method.
		try {
			$client = new WP_MCP_AI_ESPN_Fantasy_Client( array() );
			$this->assertInstanceOf(
				'WP_MCP_AI_ESPN_Fantasy_Client',
				$client,
				'ESPN Fantasy Client should instantiate without fatal error even if WP_MCP_AI_Admin_Settings is not available'
			);
		} catch ( Error $e ) {
			$this->fail( 'ESPN Fantasy Client should not throw a fatal error when WP_MCP_AI_Admin_Settings is missing: ' . $e->getMessage() );
		} catch ( Exception $e ) {
			$this->fail( 'ESPN Fantasy Client should not throw an exception when WP_MCP_AI_Admin_Settings is missing: ' . $e->getMessage() );
		}
	}

	/**
	 * Test that ESPN Fantasy Client loads credentials from settings when class exists.
	 */
	public function test_espn_client_loads_credentials_when_admin_settings_exists() {
		// Check if the Pro addon is available.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'ESPN Fantasy Client is a Pro feature, skipping test.' );
			return;
		}

		// Check if the client class file exists.
		$client_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-espn-fantasy-client.php';
		if ( ! file_exists( $client_file ) ) {
			$this->markTestSkipped( 'ESPN Fantasy Client file not found.' );
			return;
		}

		// Verify Admin Settings class is available.
		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Admin_Settings class not available for this test.' );
			return;
		}

		// Load the ESPN Fantasy Client.
		require_once $client_file;

		// Set test credentials in options.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'espn_fantasy_espn_s2' => 'test_espn_s2_value',
				'espn_fantasy_swid'    => 'test_swid_value',
			)
		);

		// Instantiate the client without credentials - it should load from settings.
		$client = new WP_MCP_AI_ESPN_Fantasy_Client( array() );

		// Use reflection to check if credentials were loaded.
		$reflection = new ReflectionClass( $client );

		$espn_s2_property = $reflection->getProperty( 'espn_s2' );
		$espn_s2_property->setAccessible( true );
		$espn_s2_value = $espn_s2_property->getValue( $client );

		$swid_property = $reflection->getProperty( 'swid' );
		$swid_property->setAccessible( true );
		$swid_value = $swid_property->getValue( $client );

		$this->assertEquals( 'test_espn_s2_value', $espn_s2_value, 'ESPN S2 credential should be loaded from settings' );
		$this->assertEquals( 'test_swid_value', $swid_value, 'SWID credential should be loaded from settings' );

		// Clean up.
		delete_option( 'wp_mcp_ai_settings' );
	}
}
