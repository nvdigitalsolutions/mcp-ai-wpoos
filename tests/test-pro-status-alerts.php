<?php
/**
 * Tests for WP_MCP_AI_Pro_Status_Alerts (fleet events → incidents).
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the Pro fleet alert automation.
 */
class Test_Pro_Status_Alerts extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'The Pro addon is not loaded in this configuration.' );
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Status_Alerts' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-status-alerts.php';
		}
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-incident-cpt.php';

		delete_option( WP_MCP_AI_Pro_Status_Alerts::OPTION_ENABLED );
		delete_transient( 'wp_mcp_ai_incident_auto_cooldown_site:site-a' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
		delete_option( WP_MCP_AI_Pro_Status_Alerts::OPTION_ENABLED );
		delete_transient( 'wp_mcp_ai_incident_auto_cooldown_site:site-a' );
	}

	/**
	 * The module registers its event hook.
	 */
	public function test_hook_is_registered() {
		$this->assertGreaterThanOrEqual(
			10,
			has_action( 'wp_mcp_ai_site_status_event', array( 'WP_MCP_AI_Pro_Status_Alerts', 'handle_event' ) )
		);
	}

	/**
	 * Automation is opt-in: off by default, no incidents are created.
	 */
	public function test_opt_in_default_creates_nothing() {
		WP_MCP_AI_Pro_Status_Alerts::handle_event( 'site-a', 'site.down', array( 'message' => 'down' ) );

		$incidents = get_posts(
			array(
				'post_type'   => WP_MCP_AI_Incident_CPT::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( array(), $incidents );
	}

	/**
	 * Site.down auto-creates an incident scoped to the remote site.
	 */
	public function test_site_down_creates_incident() {
		update_option( WP_MCP_AI_Pro_Status_Alerts::OPTION_ENABLED, true );

		WP_MCP_AI_Pro_Status_Alerts::handle_event( 'site-a', 'site.down', array( 'message' => 'unreachable' ) );

		$incidents = get_posts(
			array(
				'post_type'   => WP_MCP_AI_Incident_CPT::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => 10,
			)
		);

		$this->assertCount( 1, $incidents );

		$services = get_post_meta( $incidents[0]->ID, '_mcp_ai_incident_services', true );
		$this->assertIsArray( $services );
		$this->assertContains( 'site:site-a', $services );

		$phase = get_post_meta( $incidents[0]->ID, '_mcp_ai_incident_phase', true );
		$this->assertSame( WP_MCP_AI_Incident_CPT::PHASE_DETECTED, $phase );
	}

	/**
	 * Site.recovered auto-resolves the matching open incident.
	 */
	public function test_site_recovered_resolves_incident() {
		update_option( WP_MCP_AI_Pro_Status_Alerts::OPTION_ENABLED, true );

		WP_MCP_AI_Pro_Status_Alerts::handle_event( 'site-a', 'site.down', array( 'message' => 'unreachable' ) );

		$incidents = get_posts(
			array(
				'post_type'   => WP_MCP_AI_Incident_CPT::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => 10,
			)
		);
		$this->assertCount( 1, $incidents );

		WP_MCP_AI_Pro_Status_Alerts::handle_event( 'site-a', 'site.recovered', array( 'message' => '' ) );

		$phase = get_post_meta( $incidents[0]->ID, '_mcp_ai_incident_phase', true );
		$this->assertSame( WP_MCP_AI_Incident_CPT::PHASE_RESOLVED, $phase );

		$resolved_at = get_post_meta( $incidents[0]->ID, '_mcp_ai_incident_resolved_at', true );
		$this->assertNotEmpty( $resolved_at );

		// The timeline carries the auto-resolution message.
		$timeline = get_post_meta( $incidents[0]->ID, '_mcp_ai_incident_timeline', true );
		$this->assertIsArray( $timeline );
		$last = end( $timeline );
		$this->assertStringContainsString( 'site-a', $last['message'] );
	}

	/**
	 * Other sites' incidents are untouched by a recovery event.
	 */
	public function test_recovery_only_resolves_matching_site() {
		update_option( WP_MCP_AI_Pro_Status_Alerts::OPTION_ENABLED, true );

		WP_MCP_AI_Pro_Status_Alerts::handle_event( 'site-a', 'site.down', array() );
		WP_MCP_AI_Pro_Status_Alerts::handle_event( 'site-b', 'site.recovered', array() );

		$incidents = get_posts(
			array(
				'post_type'   => WP_MCP_AI_Incident_CPT::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => 10,
			)
		);

		$this->assertCount( 1, $incidents );
		$phase = get_post_meta( $incidents[0]->ID, '_mcp_ai_incident_phase', true );
		$this->assertSame( WP_MCP_AI_Incident_CPT::PHASE_DETECTED, $phase );
	}
}
