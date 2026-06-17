<?php
/**
 * Tests for Listing Image Download tools (Google Maps, Facebook, Instagram).
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test Listing Image Download tools availability and basic functionality.
 */
class WP_MCP_AI_Listing_Image_Download_Tools_Test extends WP_UnitTestCase {

	/**
	 * Set up per-test state.
	 */
	public function setUp(): void {
		parent::setUp();
		// Ensure tool files are loaded.
		$base = dirname( __DIR__ ) . '/addons/pro/includes/tools/social-media/';
		require_once $base . 'class-wp-mcp-ai-pro-tool-download-google-maps-images.php';
		require_once $base . 'class-wp-mcp-ai-pro-tool-download-facebook-page-images.php';
		require_once $base . 'class-wp-mcp-ai-pro-tool-download-instagram-page-images.php';
	}

	// =========================================================================
	// Google Maps tool tests.
	// =========================================================================

	/**
	 * Test Google Maps tool is available.
	 */
	public function test_google_maps_tool_is_available() {
		$this->assertTrue( WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images::is_available() );
	}

	/**
	 * Test Google Maps tool slug.
	 */
	public function test_google_maps_tool_slug() {
		$tool = new WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images();
		$this->assertSame( 'download_google_maps_images', $tool->get_slug() );
	}

	/**
	 * Test Google Maps tool name is not empty.
	 */
	public function test_google_maps_tool_name() {
		$tool = new WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images();
		$this->assertNotEmpty( $tool->get_name() );
	}

	/**
	 * Test Google Maps tool description is not empty.
	 */
	public function test_google_maps_tool_description() {
		$tool = new WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images();
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test Google Maps tool parameters schema structure.
	 */
	public function test_google_maps_tool_parameters_schema() {
		$tool   = new WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertArrayHasKey( 'additionalProperties', $schema );
		$this->assertFalse( $schema['additionalProperties'] );

		// Check required parameters.
		$this->assertContains( 'api_key', $schema['required'] );

		// Check properties exist.
		$properties = $schema['properties'];
		$this->assertArrayHasKey( 'api_key', $properties );
		$this->assertArrayHasKey( 'place_id', $properties );
		$this->assertArrayHasKey( 'search_query', $properties );
		$this->assertArrayHasKey( 'max_images', $properties );
		$this->assertArrayHasKey( 'max_width', $properties );
		$this->assertArrayHasKey( 'max_height', $properties );
		$this->assertArrayHasKey( 'output_mode', $properties );
	}

	/**
	 * Test Google Maps tool capability flags.
	 */
	public function test_google_maps_tool_capability_flags() {
		$tool  = new WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'write', $flags );
	}

	/**
	 * Test Google Maps tool rules structure.
	 */
	public function test_google_maps_tool_rules() {
		$tool  = new WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images();
		$rules = $tool->get_tool_rules();

		$this->assertIsArray( $rules );
		$this->assertArrayHasKey( 'rate_limits', $rules );
		$this->assertArrayHasKey( 'timeout_constraints', $rules );
	}

	/**
	 * Test Google Maps tool execute requires authentication.
	 */
	public function test_google_maps_tool_execute_requires_authentication() {
		wp_set_current_user( 0 );

		$tool   = new WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images();
		$result = $tool->execute( array(), array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test Google Maps tool execute requires capability.
	 */
	public function test_google_maps_tool_execute_requires_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool    = new WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images();
		$context = array( 'user_id' => $user_id );
		$result  = $tool->execute( array(), $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test Google Maps tool execute requires api_key.
	 */
	public function test_google_maps_tool_execute_requires_api_key() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool    = new WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images();
		$context = array( 'user_id' => $user_id );
		$result  = $tool->execute( array(), $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_params', $result->get_error_code() );
	}

	/**
	 * Test Google Maps tool execute requires place_id or search_query.
	 */
	public function test_google_maps_tool_execute_requires_place_or_query() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool    = new WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images();
		$context = array( 'user_id' => $user_id );
		$args    = array( 'api_key' => 'test-api-key-12345' );
		$result  = $tool->execute( $args, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_params', $result->get_error_code() );
	}

	/**
	 * Test Google Maps tool execute handles API failure.
	 */
	public function test_google_maps_tool_execute_api_failure() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$pre_http_request = function ( $preempt, $args, $url ) {
			return new WP_Error( 'http_request_failed', 'Connection refused' );
		};
		add_filter( 'pre_http_request', $pre_http_request, 10, 3 );

		$tool    = new WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images();
		$context = array( 'user_id' => $user_id );
		$args    = array(
			'api_key'  => 'test-api-key-12345',
			'place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4',
		);
		$result  = $tool->execute( $args, $context );

		remove_filter( 'pre_http_request', $pre_http_request, 10 );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// =========================================================================
	// Facebook tool tests.
	// =========================================================================

	/**
	 * Test Facebook tool is available.
	 */
	public function test_facebook_tool_is_available() {
		$this->assertTrue( WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images::is_available() );
	}

	/**
	 * Test Facebook tool slug.
	 */
	public function test_facebook_tool_slug() {
		$tool = new WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images();
		$this->assertSame( 'download_facebook_page_images', $tool->get_slug() );
	}

	/**
	 * Test Facebook tool name is not empty.
	 */
	public function test_facebook_tool_name() {
		$tool = new WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images();
		$this->assertNotEmpty( $tool->get_name() );
	}

	/**
	 * Test Facebook tool description is not empty.
	 */
	public function test_facebook_tool_description() {
		$tool = new WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images();
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test Facebook tool parameters schema structure.
	 */
	public function test_facebook_tool_parameters_schema() {
		$tool   = new WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertArrayHasKey( 'additionalProperties', $schema );
		$this->assertFalse( $schema['additionalProperties'] );

		// Check required parameters.
		$this->assertContains( 'access_token', $schema['required'] );
		$this->assertContains( 'page_id', $schema['required'] );

		// Check properties exist.
		$properties = $schema['properties'];
		$this->assertArrayHasKey( 'access_token', $properties );
		$this->assertArrayHasKey( 'page_id', $properties );
		$this->assertArrayHasKey( 'album', $properties );
		$this->assertArrayHasKey( 'max_images', $properties );
		$this->assertArrayHasKey( 'output_mode', $properties );
	}

	/**
	 * Test Facebook tool capability flags.
	 */
	public function test_facebook_tool_capability_flags() {
		$tool  = new WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'write', $flags );
	}

	/**
	 * Test Facebook tool rules structure.
	 */
	public function test_facebook_tool_rules() {
		$tool  = new WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images();
		$rules = $tool->get_tool_rules();

		$this->assertIsArray( $rules );
		$this->assertArrayHasKey( 'rate_limits', $rules );
		$this->assertArrayHasKey( 'timeout_constraints', $rules );
	}

	/**
	 * Test Facebook tool execute requires authentication.
	 */
	public function test_facebook_tool_execute_requires_authentication() {
		wp_set_current_user( 0 );

		$tool   = new WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images();
		$result = $tool->execute( array(), array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test Facebook tool execute requires capability.
	 */
	public function test_facebook_tool_execute_requires_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool    = new WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images();
		$context = array( 'user_id' => $user_id );
		$result  = $tool->execute( array(), $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test Facebook tool execute requires access_token.
	 */
	public function test_facebook_tool_execute_requires_access_token() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool    = new WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images();
		$context = array( 'user_id' => $user_id );
		$result  = $tool->execute( array(), $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_params', $result->get_error_code() );
	}

	/**
	 * Test Facebook tool execute requires page_id.
	 */
	public function test_facebook_tool_execute_requires_page_id() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool    = new WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images();
		$context = array( 'user_id' => $user_id );
		$args    = array( 'access_token' => 'test-token-12345' );
		$result  = $tool->execute( $args, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_params', $result->get_error_code() );
	}

	/**
	 * Test Facebook tool execute handles API failure.
	 */
	public function test_facebook_tool_execute_api_failure() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$pre_http_request = function ( $preempt, $args, $url ) {
			return new WP_Error( 'http_request_failed', 'Connection refused' );
		};
		add_filter( 'pre_http_request', $pre_http_request, 10, 3 );

		$tool    = new WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images();
		$context = array( 'user_id' => $user_id );
		$args    = array(
			'access_token' => 'test-token-12345',
			'page_id'      => '123456789',
		);
		$result  = $tool->execute( $args, $context );

		remove_filter( 'pre_http_request', $pre_http_request, 10 );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// =========================================================================
	// Instagram tool tests.
	// =========================================================================

	/**
	 * Test Instagram tool is available.
	 */
	public function test_instagram_tool_is_available() {
		$this->assertTrue( WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images::is_available() );
	}

	/**
	 * Test Instagram tool slug.
	 */
	public function test_instagram_tool_slug() {
		$tool = new WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images();
		$this->assertSame( 'download_instagram_page_images', $tool->get_slug() );
	}

	/**
	 * Test Instagram tool name is not empty.
	 */
	public function test_instagram_tool_name() {
		$tool = new WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images();
		$this->assertNotEmpty( $tool->get_name() );
	}

	/**
	 * Test Instagram tool description is not empty.
	 */
	public function test_instagram_tool_description() {
		$tool = new WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images();
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test Instagram tool parameters schema structure.
	 */
	public function test_instagram_tool_parameters_schema() {
		$tool   = new WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertArrayHasKey( 'additionalProperties', $schema );
		$this->assertFalse( $schema['additionalProperties'] );

		// Check required parameters.
		$this->assertContains( 'access_token', $schema['required'] );
		$this->assertContains( 'ig_user_id', $schema['required'] );

		// Check properties exist.
		$properties = $schema['properties'];
		$this->assertArrayHasKey( 'access_token', $properties );
		$this->assertArrayHasKey( 'ig_user_id', $properties );
		$this->assertArrayHasKey( 'media_type_filter', $properties );
		$this->assertArrayHasKey( 'max_images', $properties );
		$this->assertArrayHasKey( 'output_mode', $properties );
	}

	/**
	 * Test Instagram tool capability flags.
	 */
	public function test_instagram_tool_capability_flags() {
		$tool  = new WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'write', $flags );
	}

	/**
	 * Test Instagram tool rules structure.
	 */
	public function test_instagram_tool_rules() {
		$tool  = new WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images();
		$rules = $tool->get_tool_rules();

		$this->assertIsArray( $rules );
		$this->assertArrayHasKey( 'rate_limits', $rules );
		$this->assertArrayHasKey( 'timeout_constraints', $rules );
	}

	/**
	 * Test Instagram tool execute requires authentication.
	 */
	public function test_instagram_tool_execute_requires_authentication() {
		wp_set_current_user( 0 );

		$tool   = new WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images();
		$result = $tool->execute( array(), array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test Instagram tool execute requires capability.
	 */
	public function test_instagram_tool_execute_requires_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool    = new WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images();
		$context = array( 'user_id' => $user_id );
		$result  = $tool->execute( array(), $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test Instagram tool execute requires access_token.
	 */
	public function test_instagram_tool_execute_requires_access_token() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool    = new WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images();
		$context = array( 'user_id' => $user_id );
		$result  = $tool->execute( array(), $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_params', $result->get_error_code() );
	}

	/**
	 * Test Instagram tool execute requires ig_user_id.
	 */
	public function test_instagram_tool_execute_requires_ig_user_id() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool    = new WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images();
		$context = array( 'user_id' => $user_id );
		$args    = array( 'access_token' => 'test-token-12345' );
		$result  = $tool->execute( $args, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_params', $result->get_error_code() );
	}

	/**
	 * Test Instagram tool execute handles API failure.
	 */
	public function test_instagram_tool_execute_api_failure() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$pre_http_request = function ( $preempt, $args, $url ) {
			return new WP_Error( 'http_request_failed', 'Connection refused' );
		};
		add_filter( 'pre_http_request', $pre_http_request, 10, 3 );

		$tool    = new WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images();
		$context = array( 'user_id' => $user_id );
		$args    = array(
			'access_token' => 'test-token-12345',
			'ig_user_id'   => '17841400000000000',
		);
		$result  = $tool->execute( $args, $context );

		remove_filter( 'pre_http_request', $pre_http_request, 10 );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// =========================================================================
	// Cross-tool interface tests.
	// =========================================================================

	/**
	 * Test all tools implement the required tool interface.
	 */
	public function test_all_tools_implement_required_interfaces() {
		$google    = new WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images();
		$facebook  = new WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images();
		$instagram = new WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images();

		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $google );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $facebook );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $instagram );
	}

	/**
	 * Test all tools implement the capability flags interface.
	 */
	public function test_all_tools_implement_capability_flags_interface() {
		$google    = new WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images();
		$facebook  = new WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images();
		$instagram = new WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images();

		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $google );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $facebook );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $instagram );
	}

	/**
	 * Test all tools implement the rules interface.
	 */
	public function test_all_tools_implement_rules_interface() {
		$google    = new WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images();
		$facebook  = new WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images();
		$instagram = new WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images();

		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Rules_Interface', $google );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Rules_Interface', $facebook );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Rules_Interface', $instagram );
	}
}
