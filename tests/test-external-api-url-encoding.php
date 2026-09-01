<?php
/**
 * External API URL Encoding
 *
 * Tests that external API URL builders RFC 1738-encode query values with
 * spaces. WordPress' build_query() passes $urlencode=false, so add_query_arg()
 * leaves raw spaces in URLs — these tests lock in the explicit encoding.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-google-maps-client.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-web-search.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-reliefweb-reports.php';

/**
 * Tests for external API URL encoding.
 */
class WP_MCP_AI_External_API_URL_Encoding_Test extends WP_UnitTestCase {

	/**
	 * Prepare default settings for each test.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );
	}

	/**
	 * Clean up between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_mcp_ai_reliefweb_appname' );

		parent::tearDown();
	}

	/**
	 * Install an HTTP stub that captures the request URL and returns a JSON body.
	 *
	 * @param array  $captured  Accumulator for captured URLs.
	 * @param string $json_body JSON body to return.
	 */
	private function stub_capture_url( &$captured, $json_body ) {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$captured, $json_body ) {
				$captured[] = $url;

				return array(
					'headers'  => array(),
					'body'     => $json_body,
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			3
		);
	}

	/**
	 * Geocoding addresses must be URL-encoded.
	 */
	public function test_google_maps_geocode_encodes_address() {
		$settings                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['google_maps_api_key'] = 'test-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$captured = array();
		$this->stub_capture_url(
			$captured,
			wp_json_encode(
				array(
					'status'  => 'OK',
					'results' => array(),
				)
			)
		);

		$client = new WP_MCP_AI_Google_Maps_Client();
		$client->geocode( '1600 Amphitheatre Parkway, Mountain View, CA' );

		$this->assertNotEmpty( $captured );
		$url = $captured[0];
		$this->assertStringContainsString( 'address=1600+Amphitheatre+Parkway%2C+Mountain+View%2C+CA', $url );
		$this->assertStringNotContainsString( '1600 Amphitheatre', $url );
	}

	/**
	 * Places text search queries must be URL-encoded.
	 */
	public function test_google_maps_text_search_encodes_query() {
		$settings                        = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['google_maps_api_key'] = 'test-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$captured = array();
		$this->stub_capture_url(
			$captured,
			wp_json_encode(
				array(
					'status'  => 'OK',
					'results' => array(),
				)
			)
		);

		$client = new WP_MCP_AI_Google_Maps_Client();
		$client->text_search( 'best coffee shops in boulder' );

		$this->assertNotEmpty( $captured );
		$url = $captured[0];
		$this->assertStringContainsString( 'query=best+coffee+shops+in+boulder', $url );
		$this->assertStringNotContainsString( 'query=best coffee', $url );
	}

	/**
	 * DuckDuckGo search queries must be URL-encoded.
	 */
	public function test_duckduckgo_search_encodes_query() {
		$tool = new WP_MCP_AI_Tool_Web_Search();

		$captured = array();
		$this->stub_capture_url(
			$captured,
			wp_json_encode(
				array(
					'AbstractText' => '',
					'Results'      => array(),
				)
			)
		);

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'perform_duckduckgo_search' );
		$method->setAccessible( true );
		$method->invoke( $tool, 'hello world search', 5, array() );

		$this->assertNotEmpty( $captured );
		$this->assertStringContainsString( 'q=hello+world+search', $captured[0] );
		$this->assertStringNotContainsString( 'q=hello world', $captured[0] );
	}

	/**
	 * Brave search queries must be URL-encoded.
	 */
	public function test_brave_search_encodes_query() {
		$settings                         = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['brave_search_api_key'] = 'test-brave-key';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$tool = new WP_MCP_AI_Tool_Web_Search();

		$captured = array();
		$this->stub_capture_url(
			$captured,
			wp_json_encode( array( 'web' => array( 'results' => array() ) ) )
		);

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'perform_brave_search' );
		$method->setAccessible( true );
		$method->invoke( $tool, 'latest ai news today', 3, array() );

		$this->assertNotEmpty( $captured );
		$this->assertStringContainsString( 'q=latest+ai+news+today', $captured[0] );
		$this->assertStringNotContainsString( 'q=latest ai', $captured[0] );
	}

	/**
	 * ReliefWeb appname values must be URL-encoded.
	 */
	public function test_reliefweb_encodes_appname() {
		add_filter( 'wp_mcp_ai_reliefweb_appname', 'wp_mcp_ai_return_my_spaced_app' );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool = new WP_MCP_AI_Tool_ReliefWeb_Reports();

		$captured = array();
		$this->stub_capture_url( $captured, wp_json_encode( array( 'data' => array() ) ) );

		$tool->execute(
			array(
				'country' => 'Nepal',
				'search'  => 'earthquake',
			),
			array( 'user_id' => $admin_id )
		);

		$this->assertNotEmpty( $captured );
		$this->assertStringContainsString( 'appname=my+spaced+app', $captured[0] );
		$this->assertStringNotContainsString( 'appname=my spaced', $captured[0] );
	}
}

/**
 * Filter helper returning an appname with spaces.
 *
 * @return string Appname with spaces.
 */
function wp_mcp_ai_return_my_spaced_app() {
	return 'my spaced app';
}
