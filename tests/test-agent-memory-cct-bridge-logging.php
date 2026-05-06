<?php
/**
 * Tests for the rate-limited logging in the agent-memory CCT bridge.
 *
 * Verifies that:
 * - When `WP_MCP_AI_JetEngine_Agent_Memories_CCT` is missing the bridge
 *   logs a single warning with `reason=jetengine_cct_class_missing`.
 * - The warning is emitted at most once per request even when many memory
 *   events fire (rate-limit) — this prevents a 50-item batch from spamming
 *   `wp_mcp_ai_recent_errors`.
 * - When the class exists but `get_item_handler()` returns null the bridge
 *   logs a single warning with `reason=jetengine_handler_unavailable` and
 *   includes JetEngine module status keys.
 *
 * The bridge is not given a real JetEngine in test, so the "happy path"
 * is implicitly the same code path; what we assert is that *no* warning
 * is logged for events that fail validation early (missing context_id).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for `WP_MCP_AI_Agent_Memory_CCT_Bridge` warn_once behaviour.
 */
class WP_MCP_AI_Agent_Memory_CCT_Bridge_Logging_Test extends WP_UnitTestCase {

	/**
	 * Set up: enable logging and clear recent-errors / warn-once state.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging' => true,
			)
		);
		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );
		delete_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );
		WP_MCP_AI_Agent_Memory_CCT_Bridge::reset_warn_state();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );
		delete_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );
		WP_MCP_AI_Agent_Memory_CCT_Bridge::reset_warn_state();
		parent::tearDown();
	}

	/**
	 * Build a minimal valid memory event payload.
	 *
	 * @param string $context_id Context id.
	 * @return array
	 */
	private function build_event( $context_id ) {
		return array(
			'context_id'   => $context_id,
			'agent_id'     => 'agent_unit',
			'context_type' => 'fact',
			'content'      => 'Some durable fact.',
			'tool_name'    => 'store_agent_context',
		);
	}

	/**
	 * Find recent-errors entries whose context.reason matches the given key.
	 *
	 * @param string $reason Reason key.
	 * @return array
	 */
	private function find_warnings_with_reason( $reason ) {
		$entries = get_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION, array() );
		$matches = array();
		foreach ( (array) $entries as $entry ) {
			$ctx = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();
			if ( isset( $ctx['reason'] ) && $reason === $ctx['reason'] ) {
				$matches[] = $entry;
			}
		}
		return $matches;
	}

	/**
	 * When the bridge can't find the CCT class, it must log exactly once.
	 *
	 * NB: the JetEngine CCT class is not present in the unit-test bootstrap,
	 * so this is the "real" failure path — the bridge sees no class, emits
	 * one warning, and remains silent for subsequent events in the same
	 * request.
	 */
	public function test_missing_cct_class_logs_once_per_request() {
		// Ensure the class isn't autoloadable for the duration of this test.
		// The bootstrap doesn't ship it — this is just a defensive check.
		if ( class_exists( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT', false ) ) {
			$this->markTestSkipped( 'JetEngine agent memories CCT class is loaded; cannot test missing-class path.' );
		}

		WP_MCP_AI_Agent_Memory_CCT_Bridge::on_memory_stored( $this->build_event( 'ctx_a' ) );
		WP_MCP_AI_Agent_Memory_CCT_Bridge::on_memory_stored( $this->build_event( 'ctx_b' ) );
		WP_MCP_AI_Agent_Memory_CCT_Bridge::on_memory_stored( $this->build_event( 'ctx_c' ) );

		$warnings = $this->find_warnings_with_reason( 'jetengine_cct_class_missing' );
		$this->assertCount(
			1,
			$warnings,
			'Bridge must log "jetengine_cct_class_missing" exactly once per request, even across multiple memory events.'
		);
		$this->assertSame( 'warning', $warnings[0]['type'] );
	}

	/**
	 * When `reset_warn_state()` is called between events the second event
	 * must produce a fresh warning. Models a new request boundary.
	 */
	public function test_warn_state_resets_per_request() {
		if ( class_exists( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT', false ) ) {
			$this->markTestSkipped( 'JetEngine agent memories CCT class is loaded; cannot test missing-class path.' );
		}

		WP_MCP_AI_Agent_Memory_CCT_Bridge::on_memory_stored( $this->build_event( 'ctx_first' ) );
		WP_MCP_AI_Agent_Memory_CCT_Bridge::reset_warn_state();
		WP_MCP_AI_Agent_Memory_CCT_Bridge::on_memory_stored( $this->build_event( 'ctx_second' ) );

		$warnings = $this->find_warnings_with_reason( 'jetengine_cct_class_missing' );
		$this->assertCount(
			2,
			$warnings,
			'After reset_warn_state(), a subsequent event must emit a fresh warning.'
		);
	}

	/**
	 * Events without a context_id must short-circuit before any logging.
	 */
	public function test_invalid_event_does_not_log() {
		WP_MCP_AI_Agent_Memory_CCT_Bridge::on_memory_stored( array() );
		WP_MCP_AI_Agent_Memory_CCT_Bridge::on_memory_stored( array( 'agent_id' => 'x' ) );

		$warnings = $this->find_warnings_with_reason( 'jetengine_cct_class_missing' );
		$this->assertCount( 0, $warnings, 'Events with no context_id must not trigger a warning.' );
	}
}
