<?php
/**
 * Tests for the size and content of contexts persisted to the rolling
 * recent-errors / recent-activity option buffers.
 *
 * Regression cover for the case where a tool error stored the entire assistant
 * configuration — including a multi-kilobyte resolved `system_prompt` — in
 * `wp_mcp_ai_recent_errors` and `wp_mcp_ai_recent_activity`, growing those option
 * rows into the megabytes and echoing prompt contents into the database.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Logger_Context_Storage_Test extends WP_UnitTestCase {

	/**
	 * Sentinel placed at the tail of the oversized prompt used in these tests.
	 *
	 * Positioned beyond any per-string truncation limit so that finding it in a
	 * stored entry proves the prompt body was persisted verbatim.
	 */
	const PROMPT_SENTINEL = 'ZAPIER_WEBHOOK_SENTINEL_DO_NOT_PERSIST';

	/**
	 * Original error log path, restored after each test.
	 *
	 * @var string|false|null
	 */
	protected $original_error_log;

	/**
	 * Temporary error log used to keep test output clean.
	 *
	 * @var string
	 */
	protected $temp_error_log = '';

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_error_log = ini_get( 'error_log' );
		$this->temp_error_log     = get_temp_dir() . uniqid( 'nvoos-logger-', true ) . '.log';
		ini_set( 'error_log', $this->temp_error_log );

		$this->enable_logging();

		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );
		delete_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );

		WP_MCP_AI_Logger::reset_log_file_cache();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );
		delete_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );

		// The per-test DB rollback restores the settings row without firing
		// update_option hooks, so the static settings cache would otherwise leak
		// enable_logging into later test files in the same process.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		if ( false !== $this->original_error_log && null !== $this->original_error_log ) {
			ini_set( 'error_log', (string) $this->original_error_log );
		}

		if ( '' !== $this->temp_error_log && file_exists( $this->temp_error_log ) ) {
			wp_delete_file( $this->temp_error_log );
		}

		WP_MCP_AI_Logger::reset_log_file_cache();

		parent::tearDown();
	}

	/**
	 * Configure plugin logging settings for a test.
	 *
	 * @param bool $extended Whether to enable Extended Logging.
	 */
	protected function enable_logging( $extended = false ) {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging'          => true,
				'enable_extended_logging' => (bool) $extended,
			)
		);

		WP_MCP_AI_Admin_Settings::reset_settings_cache();
	}

	/**
	 * Build an oversized system prompt ending in the sentinel.
	 *
	 * @return string
	 */
	protected function build_oversized_prompt() {
		return str_repeat( 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 1000 ) . self::PROMPT_SENTINEL;
	}

	/**
	 * Retrieve the most recently stored entry from a buffer option.
	 *
	 * @param string $option Option name.
	 * @return array
	 */
	protected function get_last_stored_entry( $option ) {
		$entries = get_option( $option, array() );

		$this->assertIsArray( $entries, "Expected {$option} to hold an array." );
		$this->assertNotEmpty( $entries, "Expected {$option} to hold at least one entry." );

		return end( $entries );
	}

	/**
	 * Log a failing tool execution carrying a full assistant configuration.
	 *
	 * Mirrors the context assembled by WP_MCP_AI_REST::execute_tool_call_internal().
	 *
	 * @param array $arguments Tool arguments.
	 */
	protected function log_failing_tool_execution( array $arguments = array() ) {
		WP_MCP_AI_Logger::log_tool_execution(
			'create_post',
			$arguments,
			new WP_Error( 'wp_mcp_ai_tool_failed', 'Tool execution blew up.' ),
			array(
				'assistant_id'     => 42,
				'user_id'          => 7,
				'iteration'        => 2,
				'assistant_config' => array(
					'provider'            => 'openai',
					'model'               => 'gpt-4o',
					'temperature'         => 0.7,
					'required_capability' => 'edit_posts',
					'tools'               => array( 'create_post', 'get_post', 'update_post' ),
					'system_prompt'       => $this->build_oversized_prompt(),
					'_last_user_message'  => 'Publish the thing.',
				),
			)
		);
	}

	/**
	 * The stored context must not contain the system prompt body.
	 */
	public function test_stored_context_omits_system_prompt_body() {
		$this->log_failing_tool_execution();

		foreach ( array( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION, WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION ) as $option ) {
			$serialized = wp_json_encode( get_option( $option, array() ) );

			$this->assertIsString( $serialized );
			$this->assertStringNotContainsString(
				self::PROMPT_SENTINEL,
				$serialized,
				"Prompt body leaked into {$option}."
			);
		}
	}

	/**
	 * The prompt must be replaced by a length + hash fingerprint.
	 */
	public function test_system_prompt_is_replaced_with_fingerprint() {
		$prompt = $this->build_oversized_prompt();

		$this->log_failing_tool_execution();

		$entry = $this->get_last_stored_entry( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );

		$this->assertArrayHasKey( 'context', $entry );
		$this->assertArrayHasKey( 'assistant_config', $entry['context'] );

		$config = $entry['context']['assistant_config'];

		$this->assertIsString( $config['system_prompt'] );
		$this->assertStringContainsString( 'prompt omitted', $config['system_prompt'] );
		$this->assertStringContainsString( (string) strlen( $prompt ), $config['system_prompt'] );
		$this->assertStringContainsString( substr( md5( $prompt ), 0, 12 ), $config['system_prompt'] );
	}

	/**
	 * Useful assistant metadata must survive the fingerprinting pass.
	 */
	public function test_assistant_config_fingerprint_retains_diagnostics() {
		$this->log_failing_tool_execution();

		$config = $this->get_last_stored_entry( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION )['context']['assistant_config'];

		$this->assertSame( 'openai', $config['provider'] );
		$this->assertSame( 'gpt-4o', $config['model'] );
		$this->assertSame( 0.7, $config['temperature'] );
		$this->assertSame( 3, $config['tool_count'] );
		$this->assertSame( 'edit_posts', $config['required_capability'] );

		// The nested private hint must not be carried through.
		$this->assertArrayNotHasKey( '_last_user_message', $config );
	}

	/**
	 * A stored entry must stay within the default context budget.
	 */
	public function test_stored_context_respects_default_budget() {
		$this->log_failing_tool_execution(
			array( 'content' => str_repeat( 'x', 40000 ) )
		);

		foreach ( array( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION, WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION ) as $option ) {
			$entry   = $this->get_last_stored_entry( $option );
			$encoded = wp_json_encode( $entry['context'] );

			$this->assertIsString( $encoded );
			$this->assertLessThanOrEqual(
				WP_MCP_AI_Logger::MAX_STORED_CONTEXT_BYTES,
				strlen( $encoded ),
				"Stored context in {$option} exceeded the default budget."
			);
		}
	}

	/**
	 * Extended Logging must actually widen the budget rather than being inert.
	 */
	public function test_extended_logging_retains_more_context() {
		// Sized to encode above the default budget but below the extended one.
		$arguments = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$arguments[ 'field_' . $i ] = str_repeat( 'y', 400 );
		}

		$this->log_failing_tool_execution( $arguments );

		$default_entry = $this->get_last_stored_entry( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );

		// Oversized arguments are replaced by a size descriptor at the default budget.
		$this->assertIsString( $default_entry['context']['arguments'] );
		$this->assertStringContainsString( 'omitted', $default_entry['context']['arguments'] );

		// Re-run with Extended Logging enabled.
		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );
		$this->enable_logging( true );

		$this->log_failing_tool_execution( $arguments );

		$extended_entry = $this->get_last_stored_entry( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );

		$this->assertIsArray( $extended_entry['context']['arguments'] );
		$this->assertCount( 20, $extended_entry['context']['arguments'] );

		$encoded = wp_json_encode( $extended_entry['context'] );
		$this->assertLessThanOrEqual(
			WP_MCP_AI_Logger::MAX_STORED_CONTEXT_BYTES_EXTENDED,
			strlen( $encoded ),
			'Extended Logging must still cap the stored context.'
		);

		// The prompt stays fingerprinted regardless of the budget.
		$this->assertStringNotContainsString( self::PROMPT_SENTINEL, (string) $encoded );
	}

	/**
	 * Diagnostic keys must never be sacrificed to the budget.
	 */
	public function test_preserved_diagnostic_keys_survive_budget_enforcement() {
		$this->log_failing_tool_execution(
			array( 'content' => str_repeat( 'x', 80000 ) )
		);

		$context = $this->get_last_stored_entry( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION )['context'];

		$this->assertSame( 'create_post', $context['tool_slug'] );
		$this->assertSame( 'wp_mcp_ai_tool_failed', $context['error_code'] );
		$this->assertSame( 'Tool execution blew up.', $context['error_message'] );
		$this->assertSame( 42, $context['assistant_id'] );
		$this->assertSame( 7, $context['user_id'] );
		$this->assertSame( 2, $context['iteration'] );
	}

	/**
	 * A system prompt merged into the chat options payload must also be
	 * fingerprinted, since WP_MCP_AI_REST_Validator::sanitize_options() copies it
	 * out of the assistant configuration.
	 */
	public function test_options_system_prompt_is_fingerprinted() {
		$prompt = $this->build_oversized_prompt();

		WP_MCP_AI_Logger::log_chat_interaction(
			42,
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello there.',
				),
			),
			array(
				'model'         => 'gpt-4o',
				'system_prompt' => $prompt,
			),
			array( 'id' => 'chatcmpl-123' ),
			7
		);

		$entry = $this->get_last_stored_entry( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );

		$this->assertSame( 'chat_interaction', $entry['type'] );
		$this->assertStringContainsString(
			'prompt omitted',
			$entry['context']['options']['system_prompt']
		);
		$this->assertStringNotContainsString(
			self::PROMPT_SENTINEL,
			(string) wp_json_encode( $entry['context'] )
		);
	}

	/**
	 * The `request` key carries no usable data and must be dropped.
	 */
	public function test_request_key_is_dropped() {
		WP_MCP_AI_Logger::log_error(
			'Something failed.',
			array(
				'request' => new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' ),
				'reason'  => 'unit-test',
			)
		);

		$context = $this->get_last_stored_entry( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION )['context'];

		$this->assertArrayNotHasKey( 'request', $context );
		$this->assertSame( 'unit-test', $context['reason'] );
	}

	/**
	 * Slimming must apply only to persistence: the `wp_mcp_ai_log_entry` filter
	 * still receives the full sanitized context.
	 */
	public function test_log_entry_filter_still_receives_full_context() {
		$captured = null;

		$filter = static function ( $entry ) use ( &$captured ) {
			$captured = $entry;
			return $entry;
		};

		add_filter( 'wp_mcp_ai_log_entry', $filter, 10, 1 );
		$this->log_failing_tool_execution();
		remove_filter( 'wp_mcp_ai_log_entry', $filter, 10 );

		$this->assertIsArray( $captured );
		$this->assertStringContainsString(
			self::PROMPT_SENTINEL,
			$captured['context']['assistant_config']['system_prompt'],
			'The filter must observe the unslimmed context.'
		);

		// ...while the persisted copy stays slim.
		$this->assertStringNotContainsString(
			self::PROMPT_SENTINEL,
			(string) wp_json_encode( get_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION, array() ) )
		);
	}

	/**
	 * A long run of tool errors must not grow the option without bound.
	 */
	public function test_buffer_stays_bounded_across_many_entries() {
		for ( $i = 0; $i < 60; $i++ ) {
			$this->log_failing_tool_execution(
				array( 'content' => str_repeat( 'x', 20000 ) )
			);
		}

		$entries = get_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION, array() );
		$encoded = wp_json_encode( $entries );

		$this->assertCount( 50, $entries );
		$this->assertIsString( $encoded );

		// 50 entries, each capped at the default context budget plus a small
		// envelope, must stay comfortably inside a few hundred kilobytes.
		$this->assertLessThan( 250000, strlen( $encoded ) );
	}

	/**
	 * The budget must be filterable for sites that need more detail.
	 */
	public function test_stored_context_budget_is_filterable() {
		$arguments = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$arguments[ 'field_' . $i ] = str_repeat( 'y', 400 );
		}

		// At the default budget this payload is too large and is replaced.
		$this->log_failing_tool_execution( $arguments );
		$this->assertIsString(
			$this->get_last_stored_entry( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION )['context']['arguments']
		);

		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );

		$filter = static function () {
			return 65536;
		};

		add_filter( 'wp_mcp_ai_stored_context_budget', $filter );
		$this->log_failing_tool_execution( $arguments );
		remove_filter( 'wp_mcp_ai_stored_context_budget', $filter );

		$this->assertIsArray(
			$this->get_last_stored_entry( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION )['context']['arguments']
		);
	}

	/**
	 * Seed both buffers with pre-fix bloated entries.
	 *
	 * Writes straight to the options so the entries bypass the write-time budget,
	 * reproducing rows created by an earlier plugin version.
	 *
	 * @param int $count Entries per buffer.
	 * @return void
	 */
	protected function seed_bloated_buffers( $count = 5 ) {
		$entries = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$entries[] = array(
				'timestamp' => '2026-01-01 00:00:0' . $i,
				'type'      => 'tool_error',
				'message'   => 'Tool execution failed.',
				'context'   => array(
					'tool_slug'        => 'create_post',
					'error_code'       => 'wp_mcp_ai_tool_failed',
					'assistant_config' => array(
						'provider'      => 'openai',
						'model'         => 'gpt-4o',
						'system_prompt' => $this->build_oversized_prompt(),
					),
					'arguments'        => array( 'content' => str_repeat( 'q', 30000 ) ),
				),
			);
		}

		update_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION, $entries, false );
		update_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION, $entries, false );
	}

	/**
	 * The stats report must describe both buffers and their combined size.
	 */
	public function test_get_recent_buffer_stats_reports_both_buffers() {
		$this->seed_bloated_buffers( 4 );

		$stats = WP_MCP_AI_Logger::get_recent_buffer_stats();

		$this->assertArrayHasKey( 'errors', $stats['buffers'] );
		$this->assertArrayHasKey( 'activity', $stats['buffers'] );

		$this->assertSame(
			WP_MCP_AI_Logger::RECENT_ERRORS_OPTION,
			$stats['buffers']['errors']['option']
		);
		$this->assertSame( WP_MCP_AI_Logger::MAX_RECENT_ERRORS, $stats['buffers']['errors']['limit'] );
		$this->assertSame( WP_MCP_AI_Logger::MAX_RECENT_ACTIVITY, $stats['buffers']['activity']['limit'] );

		$this->assertSame( 4, $stats['buffers']['errors']['entries'] );
		$this->assertSame( 8, $stats['total_entries'] );

		// Four oversized prompts plus 30 KB of arguments per buffer exceeds 100 KB.
		$this->assertGreaterThan( 100000, $stats['total_bytes'] );
		$this->assertSame(
			$stats['buffers']['errors']['bytes'] + $stats['buffers']['activity']['bytes'],
			$stats['total_bytes']
		);
	}

	/**
	 * Compacting must reclaim space while keeping every entry.
	 */
	public function test_compact_recent_buffers_reclaims_space_and_keeps_entries() {
		$this->seed_bloated_buffers( 5 );

		$before = WP_MCP_AI_Logger::get_recent_buffer_stats();
		$result = WP_MCP_AI_Logger::compact_recent_buffers();

		$this->assertGreaterThan( 0, $result['bytes_saved'] );
		$this->assertSame( $before['total_bytes'], $result['bytes_before'] );
		$this->assertLessThan( $result['bytes_before'], $result['bytes_after'] );
		$this->assertSame( 10, $result['entries_rewritten'] );

		foreach ( array( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION, WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION ) as $option ) {
			$entries = get_option( $option, array() );

			// Entries are preserved; only their contexts shrink.
			$this->assertCount( 5, $entries, 'Entries were dropped from ' . $option );
			$this->assertStringNotContainsString(
				self::PROMPT_SENTINEL,
				(string) wp_json_encode( $entries ),
				'Prompt body survived compaction in ' . $option
			);

			// Diagnostics survive.
			$this->assertSame( 'create_post', $entries[0]['context']['tool_slug'] );
			$this->assertSame( 'wp_mcp_ai_tool_failed', $entries[0]['context']['error_code'] );
		}
	}

	/**
	 * Compacting must be idempotent.
	 */
	public function test_compact_recent_buffers_is_idempotent() {
		$this->seed_bloated_buffers( 3 );

		WP_MCP_AI_Logger::compact_recent_buffers();
		$second = WP_MCP_AI_Logger::compact_recent_buffers();

		$this->assertSame( 0, $second['bytes_saved'] );
		$this->assertSame( 0, $second['entries_rewritten'] );
		$this->assertSame( $second['bytes_before'], $second['bytes_after'] );
	}

	/**
	 * Compacting empty buffers must not error.
	 */
	public function test_compact_recent_buffers_handles_empty_buffers() {
		$result = WP_MCP_AI_Logger::compact_recent_buffers();

		$this->assertSame( 0, $result['bytes_before'] );
		$this->assertSame( 0, $result['bytes_after'] );
		$this->assertSame( 0, $result['bytes_saved'] );
	}

	/**
	 * Repeated compaction must preserve the original prompt fingerprint.
	 *
	 * Re-fingerprinting an existing marker would replace the real prompt length
	 * and hash with those of the marker itself, destroying the diagnostic.
	 */
	public function test_repeated_compaction_preserves_prompt_fingerprint() {
		$prompt = $this->build_oversized_prompt();

		$this->seed_bloated_buffers( 2 );

		WP_MCP_AI_Logger::compact_recent_buffers();

		$entries     = get_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION, array() );
		$fingerprint = $entries[0]['context']['assistant_config']['system_prompt'];

		$this->assertStringContainsString( (string) strlen( $prompt ), $fingerprint );
		$this->assertStringContainsString( substr( md5( $prompt ), 0, 12 ), $fingerprint );

		// Two more passes must leave it byte-identical.
		WP_MCP_AI_Logger::compact_recent_buffers();
		WP_MCP_AI_Logger::compact_recent_buffers();

		$entries = get_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION, array() );

		$this->assertSame(
			$fingerprint,
			$entries[0]['context']['assistant_config']['system_prompt'],
			'The prompt fingerprint must survive repeated compaction unchanged.'
		);
	}

	/**
	 * Clearing must delete both option rows outright.
	 */
	public function test_clear_recent_buffers_removes_both_options() {
		$this->seed_bloated_buffers( 6 );

		$result = WP_MCP_AI_Logger::clear_recent_buffers();

		$this->assertSame( 12, $result['entries_removed'] );
		$this->assertGreaterThan( 0, $result['bytes_freed'] );

		$this->assertFalse( get_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION, false ) );
		$this->assertFalse( get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION, false ) );

		$stats = WP_MCP_AI_Logger::get_recent_buffer_stats();
		$this->assertSame( 0, $stats['total_bytes'] );
		$this->assertSame( 0, $stats['total_entries'] );
	}

	/**
	 * Compaction must re-apply the current retention limit.
	 */
	public function test_compact_recent_buffers_applies_retention_limit() {
		$this->seed_bloated_buffers( 3 );

		// Grow the errors buffer beyond its limit, as an older build could.
		$entries = get_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION, array() );
		$padded  = array();
		for ( $i = 0; $i < WP_MCP_AI_Logger::MAX_RECENT_ERRORS + 20; $i++ ) {
			$padded[] = $entries[0];
		}
		update_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION, $padded, false );

		WP_MCP_AI_Logger::compact_recent_buffers();

		$this->assertCount(
			WP_MCP_AI_Logger::MAX_RECENT_ERRORS,
			get_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION, array() )
		);
	}
}
