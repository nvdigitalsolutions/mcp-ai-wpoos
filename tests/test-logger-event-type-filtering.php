<?php
/**
 * Tests for Logger Event Type Filtering
 *
 * Tests that the granular logging toggles properly gate API provider
 * and agentic loop events.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Logger event type filtering functionality.
 */
class Test_Logger_Event_Type_Filtering extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Clear any existing settings and reset cache.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
		WP_MCP_AI_Logger::reset_log_file_cache();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();

		// Clear settings cache.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
		WP_MCP_AI_Logger::reset_log_file_cache();

		// Clean up log options.
		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );
		delete_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );
	}

	/**
	 * Test that agentic loop events are suppressed when the toggle is disabled.
	 */
	public function test_agentic_loop_events_suppressed_when_disabled() {
		// Enable base logging but disable agentic loop logging.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging'              => true,
				'enable_agentic_loop_logging' => false,
			)
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Log an agentic event.
		WP_MCP_AI_Logger::log_event( 'agentic_tool_execution', 'Test agentic event' );

		// Should not appear in recent activity.
		$recent = get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION, array() );
		$this->assertEmpty( $recent, 'Agentic events should not be logged when toggle is disabled' );
	}

	/**
	 * Test that agentic loop events are logged when the toggle is enabled.
	 */
	public function test_agentic_loop_events_logged_when_enabled() {
		// Enable both base logging and agentic loop logging.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging'              => true,
				'enable_agentic_loop_logging' => true,
			)
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Log an agentic event.
		WP_MCP_AI_Logger::log_event( 'agentic_tool_execution', 'Test agentic event', array( 'iteration' => 1 ) );

		// Should appear in recent activity.
		$recent = get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION, array() );
		$this->assertNotEmpty( $recent, 'Agentic events should be logged when toggle is enabled' );

		$found = false;
		foreach ( $recent as $entry ) {
			if ( isset( $entry['type'] ) && $entry['type'] === 'agentic_tool_execution' ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Agentic tool execution event should be in recent activity' );
	}

	/**
	 * Test that all agentic event types are properly gated.
	 */
	public function test_all_agentic_event_types_are_gated() {
		// Disable agentic loop logging.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging'              => true,
				'enable_agentic_loop_logging' => false,
			)
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$agentic_events = array(
			'agentic_tool_execution',
			'agentic_model_switched',
			'agentic_messages_truncated',
			'agentic_loop_limit',
		);

		foreach ( $agentic_events as $event_type ) {
			WP_MCP_AI_Logger::log_event( $event_type, "Test {$event_type}" );
		}

		// None should be logged.
		$recent = get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION, array() );
		$this->assertEmpty( $recent, 'No agentic events should be logged when toggle is disabled' );
	}

	/**
	 * Test that Anthropic API events respect the API logging toggle.
	 */
	public function test_anthropic_events_respect_api_toggle() {
		// Enable base logging but disable API logging.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging'     => true,
				'enable_api_logging' => false,
			)
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Log Anthropic events.
		WP_MCP_AI_Logger::log_event( 'anthropic_request', 'Test Anthropic request' );
		WP_MCP_AI_Logger::log_event( 'anthropic_response', 'Test Anthropic response' );

		// Should not be logged.
		$recent = get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION, array() );
		$this->assertEmpty( $recent, 'Anthropic events should not be logged when API logging is disabled' );
	}

	/**
	 * Test that Anthropic API events are logged when toggle is enabled.
	 */
	public function test_anthropic_events_logged_when_enabled() {
		// Enable both base logging and API logging.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging'     => true,
				'enable_api_logging' => true,
			)
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Log Anthropic events.
		WP_MCP_AI_Logger::log_event( 'anthropic_request', 'Test Anthropic request' );
		WP_MCP_AI_Logger::log_event( 'anthropic_response', 'Test Anthropic response' );

		// Should be logged.
		$recent = get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION, array() );
		$this->assertNotEmpty( $recent, 'Anthropic events should be logged when API logging is enabled' );
	}

	/**
	 * Test that LM Studio API events respect the API logging toggle.
	 */
	public function test_lm_studio_events_respect_api_toggle() {
		// Enable base logging but disable API logging.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging'     => true,
				'enable_api_logging' => false,
			)
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Log LM Studio events.
		WP_MCP_AI_Logger::log_event( 'lm_studio_request', 'Test LM Studio request' );
		WP_MCP_AI_Logger::log_event( 'lm_studio_response', 'Test LM Studio response' );
		WP_MCP_AI_Logger::log_event( 'lm_studio_completion_request', 'Test completion request' );
		WP_MCP_AI_Logger::log_event( 'lm_studio_completion_response', 'Test completion response' );

		// Should not be logged.
		$recent = get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION, array() );
		$this->assertEmpty( $recent, 'LM Studio events should not be logged when API logging is disabled' );
	}

	/**
	 * Test that LM Studio API events are logged when toggle is enabled.
	 */
	public function test_lm_studio_events_logged_when_enabled() {
		// Enable both base logging and API logging.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging'     => true,
				'enable_api_logging' => true,
			)
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Log LM Studio events.
		WP_MCP_AI_Logger::log_event( 'lm_studio_request', 'Test LM Studio request' );

		// Should be logged.
		$recent = get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION, array() );
		$this->assertNotEmpty( $recent, 'LM Studio events should be logged when API logging is enabled' );
	}

	/**
	 * Test that all API provider event types are properly gated.
	 */
	public function test_all_api_provider_events_are_gated() {
		// Disable API logging.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging'     => true,
				'enable_api_logging' => false,
			)
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$api_events = array(
			'openai_request',
			'openai_response',
			'anthropic_request',
			'anthropic_response',
			'gemini_request',
			'gemini_response',
			'gemini_image_request',
			'gemini_image_response',
			'gemini_list_models_response',
			'gemini_count_tokens',
			'gemini_count_tokens_response',
			'gemini_create_embedding',
			'gemini_embedding_response',
			'gemini_stream_request',
			'gemini_stream_response',
			'lm_studio_request',
			'lm_studio_response',
			'lm_studio_completion_request',
			'lm_studio_completion_response',
			'openai_external_action_request',
			'openai_external_action_response',
		);

		foreach ( $api_events as $event_type ) {
			WP_MCP_AI_Logger::log_event( $event_type, "Test {$event_type}" );
		}

		// None should be logged.
		$recent = get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION, array() );
		$this->assertEmpty( $recent, 'No API events should be logged when API logging is disabled' );
	}

	/**
	 * Test that errors are always logged regardless of toggles.
	 */
	public function test_errors_always_logged() {
		// Enable base logging but disable all granular toggles.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging'                => true,
				'enable_api_logging'            => false,
				'enable_agentic_loop_logging'   => false,
				'enable_tool_execution_logging' => false,
			)
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Log an error.
		WP_MCP_AI_Logger::log_error( 'Test error message' );

		// Should still be logged.
		$recent = get_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION, array() );
		$this->assertNotEmpty( $recent, 'Errors should always be logged' );
	}

	/**
	 * Test that the toggle combinations work correctly.
	 */
	public function test_toggle_combinations() {
		// Enable base logging, agentic logging, but not API logging.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging'              => true,
				'enable_api_logging'          => false,
				'enable_agentic_loop_logging' => true,
			)
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Log both types of events.
		WP_MCP_AI_Logger::log_event( 'agentic_tool_execution', 'Test agentic' );
		WP_MCP_AI_Logger::log_event( 'anthropic_request', 'Test Anthropic' );

		$recent = get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION, array() );

		// Only agentic should be logged.
		$has_agentic   = false;
		$has_anthropic = false;

		foreach ( $recent as $entry ) {
			if ( isset( $entry['type'] ) ) {
				if ( $entry['type'] === 'agentic_tool_execution' ) {
					$has_agentic = true;
				}
				if ( $entry['type'] === 'anthropic_request' ) {
					$has_anthropic = true;
				}
			}
		}

		$this->assertTrue( $has_agentic, 'Agentic event should be logged when its toggle is enabled' );
		$this->assertFalse( $has_anthropic, 'Anthropic event should not be logged when API toggle is disabled' );
	}

	/**
	 * Test that base logging disabled suppresses all events (except via direct error log).
	 */
	public function test_base_logging_disabled_suppresses_all() {
		// Disable base logging.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging'              => false,
				'enable_api_logging'          => true,
				'enable_agentic_loop_logging' => true,
			)
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Try to log various events.
		WP_MCP_AI_Logger::log_event( 'agentic_tool_execution', 'Test agentic' );
		WP_MCP_AI_Logger::log_event( 'anthropic_request', 'Test Anthropic' );
		WP_MCP_AI_Logger::log_error( 'Test error' );

		// Nothing should be logged when base logging is disabled.
		$recent_activity = get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION, array() );
		$recent_errors   = get_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION, array() );

		$this->assertEmpty( $recent_activity, 'No activity should be logged when base logging is disabled' );
		$this->assertEmpty( $recent_errors, 'No errors should be stored when base logging is disabled' );
	}

	/**
	 * Test that Anthropic events are stored in recent activity when logged.
	 */
	public function test_anthropic_events_stored_in_recent_activity() {
		// Enable both base logging and API logging.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging'     => true,
				'enable_api_logging' => true,
			)
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Log Anthropic events.
		WP_MCP_AI_Logger::log_event( 'anthropic_request', 'Test Anthropic request', array( 'model' => 'claude-3' ) );

		// Should be stored in recent activity.
		$recent = get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION, array() );
		$this->assertNotEmpty( $recent, 'Anthropic events should be stored in recent activity' );

		// Verify the event is actually in the list.
		$found = false;
		foreach ( $recent as $entry ) {
			if ( isset( $entry['type'] ) && $entry['type'] === 'anthropic_request' ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Anthropic request should be in recent activity list' );
	}

	/**
	 * Test that LM Studio events are stored in recent activity when logged.
	 */
	public function test_lm_studio_events_stored_in_recent_activity() {
		// Enable both base logging and API logging.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging'     => true,
				'enable_api_logging' => true,
			)
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Log LM Studio events.
		WP_MCP_AI_Logger::log_event( 'lm_studio_completion_request', 'Test LM Studio completion' );

		// Should be stored in recent activity.
		$recent = get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION, array() );
		$this->assertNotEmpty( $recent, 'LM Studio events should be stored in recent activity' );

		// Verify the event is actually in the list.
		$found = false;
		foreach ( $recent as $entry ) {
			if ( isset( $entry['type'] ) && $entry['type'] === 'lm_studio_completion_request' ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'LM Studio completion request should be in recent activity list' );
	}
}
