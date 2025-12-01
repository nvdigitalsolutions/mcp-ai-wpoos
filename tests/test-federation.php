<?php
/**
 * Tests for the federation system.
 *
 * @package WP_MCP_AI
 */

/**
 * Test federation settings and configuration.
 */
class WP_MCP_AI_Federation_Test extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clean up any existing settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Clean up any existing peers.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'ai_peer'" );
	}

	/**
	 * Test that federation settings exist in defaults.
	 */
	public function test_default_settings_include_federation() {
		$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();

		$this->assertArrayHasKey( 'enable_federation', $defaults );
		$this->assertArrayHasKey( 'enable_federation_directory', $defaults );
		$this->assertArrayHasKey( 'federation_regions', $defaults );
		$this->assertArrayHasKey( 'federation_data_tags', $defaults );
		$this->assertArrayHasKey( 'federation_qps', $defaults );
		$this->assertArrayHasKey( 'federation_burst', $defaults );

		// Check defaults.
		$this->assertFalse( $defaults['enable_federation'] );
		$this->assertFalse( $defaults['enable_federation_directory'] );
		$this->assertSame( 'global', $defaults['federation_regions'] );
		$this->assertSame( '', $defaults['federation_data_tags'] );
		$this->assertSame( 5, $defaults['federation_qps'] );
		$this->assertSame( 10, $defaults['federation_burst'] );
	}

	/**
	 * Test federation settings helper returns correct values.
	 */
	public function test_federation_settings_get_settings() {
		// Test with default values.
		$settings = WP_MCP_AI_Federation_Settings::get_settings();

		$this->assertIsArray( $settings );
		$this->assertFalse( $settings['enable_federation'] );
		$this->assertFalse( $settings['enable_federation_directory'] );
		$this->assertIsArray( $settings['federation_regions'] );
		$this->assertContains( 'global', $settings['federation_regions'] );

		// Test with custom values.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_federation'           => true,
				'enable_federation_directory' => true,
				'federation_regions'          => 'us, eu, ap',
				'federation_data_tags'        => 'no_pii, gdpr_ok',
				'federation_qps'              => 10,
				'federation_burst'            => 20,
			)
		);

		$settings = WP_MCP_AI_Federation_Settings::get_settings();

		$this->assertTrue( $settings['enable_federation'] );
		$this->assertTrue( $settings['enable_federation_directory'] );
		$this->assertIsArray( $settings['federation_regions'] );
		$this->assertContains( 'us', $settings['federation_regions'] );
		$this->assertContains( 'eu', $settings['federation_regions'] );
		$this->assertContains( 'ap', $settings['federation_regions'] );
		$this->assertIsArray( $settings['federation_data_tags'] );
		$this->assertContains( 'no_pii', $settings['federation_data_tags'] );
		$this->assertContains( 'gdpr_ok', $settings['federation_data_tags'] );
		$this->assertSame( 10, $settings['federation_qps'] );
		$this->assertSame( 20, $settings['federation_burst'] );
	}

	/**
	 * Test is_federation_enabled helper.
	 */
	public function test_is_federation_enabled() {
		// Initially disabled.
		$this->assertFalse( WP_MCP_AI_Federation_Settings::is_federation_enabled() );

		// Enable federation.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_federation' => true,
			)
		);

		$this->assertTrue( WP_MCP_AI_Federation_Settings::is_federation_enabled() );
	}

	/**
	 * Test is_directory_enabled helper.
	 */
	public function test_is_directory_enabled() {
		// Initially disabled.
		$this->assertFalse( WP_MCP_AI_Federation_Settings::is_directory_enabled() );

		// Enable directory.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_federation_directory' => true,
			)
		);

		$this->assertTrue( WP_MCP_AI_Federation_Settings::is_directory_enabled() );
	}

	/**
	 * Test AI Peer CPT registration.
	 */
	public function test_ai_peer_cpt_is_registered() {
		// Trigger init to register the CPT.
		do_action( 'init' );

		$post_type = get_post_type_object( WP_MCP_AI_AI_Peer_CPT::POST_TYPE );

		$this->assertNotNull( $post_type );
		$this->assertSame( 'ai_peer', $post_type->name );
		$this->assertFalse( $post_type->public );
		$this->assertTrue( $post_type->show_ui );
	}

	/**
	 * Test creating an AI Peer post.
	 */
	public function test_create_ai_peer() {
		$peer_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_AI_Peer_CPT::POST_TYPE,
				'post_title'  => 'Test Peer',
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $peer_id );
		$this->assertGreaterThan( 0, $peer_id );

		// Add meta data.
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_SITE_URL, 'https://example.com' );
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_MCP_URL, 'https://example.com/wp-json/mcp-ai/v1' );
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_JWKS_URI, 'https://example.com/.well-known/jwks.json' );
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_CAPABILITIES, wp_json_encode( array( 'test_tool', 'another_tool' ) ) );
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_REGIONS, wp_json_encode( array( 'us', 'eu' ) ) );
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_HEALTH_STATUS, 'healthy' );
		update_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_LATENCY_P50, 250 );

		// Verify meta was saved.
		$this->assertSame( 'https://example.com', get_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_SITE_URL, true ) );
		$this->assertSame( 'healthy', get_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_HEALTH_STATUS, true ) );
		$this->assertSame( 250, get_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_LATENCY_P50, true ) );

		$capabilities = json_decode( get_post_meta( $peer_id, WP_MCP_AI_AI_Peer_CPT::META_CAPABILITIES, true ), true );
		$this->assertIsArray( $capabilities );
		$this->assertContains( 'test_tool', $capabilities );
	}

	/**
	 * Test well-known endpoint data structure.
	 */
	public function test_wellknown_ai_peer_document_structure() {
		// Enable federation.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_federation'    => true,
				'federation_regions'   => 'us, eu',
				'federation_data_tags' => 'no_pii, gdpr_ok',
				'federation_qps'       => 10,
				'federation_burst'     => 20,
			)
		);

		$registry  = WP_MCP_AI_Tool_Registry::get_instance();
		$wellknown = new WP_MCP_AI_Federation_WellKnown( $registry );

		// Use reflection to test the protected method.
		$reflection = new ReflectionClass( $wellknown );
		$method     = $reflection->getMethod( 'get_ai_peer_document' );
		$method->setAccessible( true );

		$document = $method->invoke( $wellknown );

		// Verify structure.
		$this->assertIsArray( $document );
		$this->assertArrayHasKey( 'version', $document );
		$this->assertArrayHasKey( 'site_name', $document );
		$this->assertArrayHasKey( 'site_url', $document );
		$this->assertArrayHasKey( 'mcp', $document );
		$this->assertArrayHasKey( 'openapi', $document );
		$this->assertArrayHasKey( 'jwks_uri', $document );
		$this->assertArrayHasKey( 'capabilities', $document );
		$this->assertArrayHasKey( 'regions', $document );
		$this->assertArrayHasKey( 'data_tags', $document );
		$this->assertArrayHasKey( 'quotas', $document );

		// Verify content.
		$this->assertSame( '1.0', $document['version'] );
		$this->assertIsArray( $document['capabilities'] );
		$this->assertIsArray( $document['regions'] );
		$this->assertIsArray( $document['data_tags'] );
		$this->assertIsArray( $document['quotas'] );
		$this->assertArrayHasKey( 'qps', $document['quotas'] );
		$this->assertArrayHasKey( 'burst', $document['quotas'] );
		$this->assertSame( 10, $document['quotas']['qps'] );
		$this->assertSame( 20, $document['quotas']['burst'] );
	}

	/**
	 * Test peer search and ranking.
	 */
	public function test_peer_search_and_ranking() {
		// Create test peers.
		$peer1_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_AI_Peer_CPT::POST_TYPE,
				'post_title'  => 'US Peer',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $peer1_id, WP_MCP_AI_AI_Peer_CPT::META_CAPABILITIES, wp_json_encode( array( 'transcribe_audio', 'ocr_image' ) ) );
		update_post_meta( $peer1_id, WP_MCP_AI_AI_Peer_CPT::META_REGIONS, wp_json_encode( array( 'us' ) ) );
		update_post_meta( $peer1_id, WP_MCP_AI_AI_Peer_CPT::META_DATA_TAGS, wp_json_encode( array( 'no_pii' ) ) );
		update_post_meta( $peer1_id, WP_MCP_AI_AI_Peer_CPT::META_HEALTH_STATUS, 'healthy' );
		update_post_meta( $peer1_id, WP_MCP_AI_AI_Peer_CPT::META_LATENCY_P50, 200 );
		update_post_meta( $peer1_id, WP_MCP_AI_AI_Peer_CPT::META_MCP_URL, 'https://peer1.example.com/mcp' );
		update_post_meta( $peer1_id, WP_MCP_AI_AI_Peer_CPT::META_JWKS_URI, 'https://peer1.example.com/jwks' );

		$peer2_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_AI_Peer_CPT::POST_TYPE,
				'post_title'  => 'EU Peer',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $peer2_id, WP_MCP_AI_AI_Peer_CPT::META_CAPABILITIES, wp_json_encode( array( 'transcribe_audio', 'translate' ) ) );
		update_post_meta( $peer2_id, WP_MCP_AI_AI_Peer_CPT::META_REGIONS, wp_json_encode( array( 'eu' ) ) );
		update_post_meta( $peer2_id, WP_MCP_AI_AI_Peer_CPT::META_DATA_TAGS, wp_json_encode( array( 'no_pii', 'gdpr_ok' ) ) );
		update_post_meta( $peer2_id, WP_MCP_AI_AI_Peer_CPT::META_HEALTH_STATUS, 'healthy' );
		update_post_meta( $peer2_id, WP_MCP_AI_AI_Peer_CPT::META_LATENCY_P50, 150 );
		update_post_meta( $peer2_id, WP_MCP_AI_AI_Peer_CPT::META_MCP_URL, 'https://peer2.example.com/mcp' );
		update_post_meta( $peer2_id, WP_MCP_AI_AI_Peer_CPT::META_JWKS_URI, 'https://peer2.example.com/jwks' );

		// Search for transcribe_audio in EU with no_pii.
		$request = new WP_REST_Request( 'GET', '/ai-dir/v1/search' );
		$request->set_param( 'capability', 'transcribe_audio' );
		$request->set_param( 'region', 'eu' );
		$request->set_param( 'data_tag', 'no_pii' );

		$rest     = new WP_MCP_AI_Federation_Directory_REST();
		$response = $rest->search_peers( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertArrayHasKey( 'results', $data );
		$this->assertGreaterThan( 0, count( $data['results'] ) );

		// EU peer should be first (matches region and data_tag, lower latency).
		$first_result = $data['results'][0];
		$this->assertSame( $peer2_id, $first_result['peer_id'] );
		$this->assertArrayHasKey( 'score', $first_result );
	}

	/**
	 * Test that federation is conditionally loaded.
	 */
	public function test_federation_conditional_loading() {
		// Initially disabled - components should not be loaded.
		$this->assertFalse( WP_MCP_AI_Federation::is_enabled() );
		$this->assertFalse( WP_MCP_AI_Federation::is_directory_enabled() );

		// Enable federation.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_federation' => true,
			)
		);

		$this->assertTrue( WP_MCP_AI_Federation_Settings::is_federation_enabled() );
		$this->assertTrue( WP_MCP_AI_Federation::is_enabled() );
	}

	/**
	 * Test cron scheduling.
	 */
	public function test_cron_scheduling() {
		// Initially no cron scheduled.
		$timestamp = wp_next_scheduled( 'wp_mcp_ai_verify_peers' );
		$this->assertFalse( $timestamp );

		// Enable directory service and trigger init.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_federation_directory' => true,
			)
		);

		$registry   = WP_MCP_AI_Tool_Registry::get_instance();
		$federation = new WP_MCP_AI_Federation( $registry );
		do_action( 'init' );

		// Cron should now be scheduled.
		$timestamp = wp_next_scheduled( 'wp_mcp_ai_verify_peers' );
		$this->assertNotFalse( $timestamp );
		$this->assertGreaterThan( 0, $timestamp );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Clear cron.
		$timestamp = wp_next_scheduled( 'wp_mcp_ai_verify_peers' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_mcp_ai_verify_peers' );
		}

		parent::tearDown();
	}
}
