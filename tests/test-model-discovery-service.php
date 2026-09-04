<?php
/**
 * Tests for the model catalog discovery service and its WP-Cron event.
 *
 * @package WP_MCP_AI
 */

class Test_Model_Discovery_Service extends WP_UnitTestCase {

	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_model_discovery_enabled' );
		remove_all_filters( 'wp_mcp_ai_model_discovery_interval' );
		remove_all_actions( 'wp_mcp_ai_model_catalog_suggestions_updated' );
		delete_option( WP_MCP_AI_Model_Discovery_Service::SUGGESTIONS_OPTION );
		delete_option( WP_MCP_AI_Model_Discovery_Service::LAST_RUN_OPTION );
		parent::tearDown();
	}

	/**
	 * The cron event is registered when the bootstrap runs.
	 */
	public function test_discovery_cron_event_registered() {
		// The bootstrap registers an action; reuse the helper that schedules it.
		if ( function_exists( 'wp_mcp_ai_ensure_cleanup_cron_scheduled' ) ) {
			wp_mcp_ai_ensure_cleanup_cron_scheduled();
		}
		$this->assertNotFalse(
			wp_next_scheduled( 'wp_mcp_ai_model_catalog_discovery' ),
			'Daily model catalog discovery cron should be scheduled.'
		);
	}

	/**
	 * The discovery service is suppressed when the enabled filter returns false.
	 */
	public function test_discovery_respects_enabled_filter() {
		// Seed a known timestamp so we can assert it is unchanged after a disabled run.
		update_option( WP_MCP_AI_Model_Discovery_Service::LAST_RUN_OPTION, 1234567890, false );

		add_filter( 'wp_mcp_ai_model_discovery_enabled', '__return_false' );

		$fired = false;
		add_action(
			'wp_mcp_ai_model_catalog_suggestions_updated',
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		WP_MCP_AI_Model_Discovery_Service::cron_handler();

		$this->assertFalse( $fired, 'Disabled discovery must not fire the suggestions_updated action.' );
		$this->assertSame(
			1234567890,
			(int) get_option( WP_MCP_AI_Model_Discovery_Service::LAST_RUN_OPTION ),
			'Disabled discovery must not overwrite the last_run timestamp.'
		);
	}

	/**
	 * Running discovery with no providers persists an empty diff and fires the action.
	 */
	public function test_discovery_persists_diff_and_fires_action() {
		$payload = null;
		add_action(
			'wp_mcp_ai_model_catalog_suggestions_updated',
			static function ( $diff ) use ( &$payload ) {
				$payload = $diff;
			}
		);

		$service = new WP_MCP_AI_Model_Discovery_Service();

		// Ensure no provider is considered enabled, regardless of what earlier
		// suites left in the settings option.
		delete_option( 'wp_mcp_ai_settings' );

		$diff = $service->run( array() ); // No enabled providers.

		$this->assertIsArray( $diff );
		$this->assertArrayHasKey( 'additions', $diff );
		$this->assertArrayHasKey( 'sunsets', $diff );
		$this->assertArrayHasKey( 'price_changes', $diff );
		$this->assertSame( 'ok', $diff['status'] );
		$this->assertNotNull( $payload, 'Action should fire with the diff payload.' );

		$stored = get_option( WP_MCP_AI_Model_Discovery_Service::SUGGESTIONS_OPTION );
		$this->assertIsArray( $stored );
	}
}
