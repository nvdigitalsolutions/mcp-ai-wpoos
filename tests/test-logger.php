<?php
/**
 * Tests for the logging helper.
 */
class WP_MCP_AI_Logger_Test extends WP_UnitTestCase {

	/**
	 * Original error log path to restore after tests run.
	 *
	 * @var string|false
	 */
	protected $original_error_log;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_error_log = ini_get( 'error_log' );
		WP_MCP_AI_Logger::reset_log_file_cache();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		if ( false !== $this->original_error_log && null !== $this->original_error_log ) {
			ini_set( 'error_log', (string) $this->original_error_log );
		}

		WP_MCP_AI_Logger::reset_log_file_cache();

		parent::tearDown();
	}

	/**
	 * Ensure chat interaction logging removes oversized payloads from the context.
	 */
	public function test_log_chat_interaction_redacts_large_payloads() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging' => true,
			)
		);

		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );

		$attachments = array(
			array(
				'id'       => 123,
				'filename' => 'large-file.txt',
				'data'     => str_repeat( 'A', 2048 ),
			),
		);

		$memory_documents = array(
			array(
				'id'      => 'doc-1',
				'content' => str_repeat( 'B', 500 ),
			),
			array(
				'id'      => 'doc-2',
				'content' => 'short',
			),
		);

		$options  = array(
			'attachments'      => $attachments,
			'memory_documents' => $memory_documents,
			'temperature'      => 0.5,
		);
		$response = array(
			'choices' => array(
				array(
					'message' => array(
						'content' => str_repeat( 'C', 800 ),
					),
				),
			),
			'usage'   => array(
				'prompt_tokens' => 42,
			),
		);

		$captured_entry = null;
		$filter         = function ( $entry, $type, $message, $raw_context ) use ( &$captured_entry ) {
			$captured_entry = $entry;
			return false;
		};

		add_filter( 'wp_mcp_ai_log_entry', $filter, 10, 4 );

		try {
			WP_MCP_AI_Logger::log_chat_interaction(
				456,
				array(
					array(
						'role'    => 'user',
						'content' => str_repeat( 'D', 240 ),
					),
				),
				$options,
				$response,
				789
			);
		} finally {
			remove_filter( 'wp_mcp_ai_log_entry', $filter, 10 );
		}

		$this->assertNotNull( $captured_entry, 'Expected the log entry to be captured.' );
		$this->assertSame( 'chat_interaction', $captured_entry['type'] );
		$this->assertSame( 'Chat request executed.', $captured_entry['message'] );

		$context = $captured_entry['context'];

		$this->assertArrayHasKey( 'options', $context );
		$this->assertArrayHasKey( 'attachments', $context['options'] );
		$this->assertSame( '[redacted]', $context['options']['attachments'][0]['data'] );
		$this->assertSame( str_repeat( 'A', 2048 ), $options['attachments'][0]['data'], 'Original attachments should remain untouched.' );

		$this->assertArrayHasKey( 'memory_documents', $context['options'] );
		$this->assertSame( 2, $context['options']['memory_documents']['count'] );
		$this->assertCount( 2, $context['options']['memory_documents']['preview'] );
		$this->assertStringEndsWith( '…', $context['options']['memory_documents']['preview'][0]['content'] );
		$this->assertSame( str_repeat( 'B', 500 ), $options['memory_documents'][0]['content'], 'Memory documents should remain unchanged in the original payload.' );

		$this->assertArrayHasKey( 'response', $context );
		$this->assertIsArray( $context['response'] );
		$this->assertArrayHasKey( 'preview', $context['response'] );
		$preview_length = function_exists( 'mb_strlen' )
			? mb_strlen( $context['response']['preview'], 'UTF-8' )
			: strlen( $context['response']['preview'] );

		$this->assertLessThanOrEqual( 401, $preview_length );
		$this->assertTrue( $context['response']['truncated'] );

		$this->assertIsArray( $response['choices'] );
		$this->assertArrayHasKey( 'message', $response['choices'][0] );
		$this->assertSame( str_repeat( 'C', 800 ), $response['choices'][0]['message']['content'], 'Original response data should not be mutated.' );
	}

	/**
	 * Ensure sensitive values are redacted from log context payloads.
	 */
	public function test_log_event_redacts_sensitive_values_in_context() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging' => true,
			)
		);

		$context = array(
			'api_key' => 'sk-secret',
			'nested'  => array(
				'access_token' => 'token-123',
				'headers'      => array(
					'Authorization' => 'Bearer sensitive',
					'X-Test'        => 'keep-me',
				),
				'data'         => array(
					'client_secret' => 'client-secret',
					'inner'         => (object) array(
						'refresh_token' => 'refresh-me',
						'allowed'       => 'visible',
					),
				),
			),
			'regular' => 'value',
		);

		$captured_entry = null;
		$filter         = function ( $entry ) use ( &$captured_entry ) {
			$captured_entry = $entry;
			return false;
		};

		add_filter( 'wp_mcp_ai_log_entry', $filter );

		try {
			WP_MCP_AI_Logger::log_event( 'tool_error', 'Context redaction.', $context );
		} finally {
			remove_filter( 'wp_mcp_ai_log_entry', $filter );
		}

		$this->assertNotNull( $captured_entry );
		$this->assertArrayHasKey( 'context', $captured_entry );

		$sanitized = $captured_entry['context'];

		$this->assertSame( '[redacted]', $sanitized['api_key'] );
		$this->assertSame( '[redacted]', $sanitized['nested']['access_token'] );
		$this->assertSame( '[redacted]', $sanitized['nested']['headers']['Authorization'] );
		$this->assertSame( 'keep-me', $sanitized['nested']['headers']['X-Test'] );
		$this->assertSame( '[redacted]', $sanitized['nested']['data']['client_secret'] );
		$this->assertSame( '[redacted]', $sanitized['nested']['data']['inner']['refresh_token'] );
		$this->assertSame( 'visible', $sanitized['nested']['data']['inner']['allowed'] );
		$this->assertSame( 'value', $sanitized['regular'] );

		$this->assertSame( 'sk-secret', $context['api_key'], 'Original context should remain untouched.' );
		$this->assertSame( 'token-123', $context['nested']['access_token'], 'Original nested value should not be mutated.' );
		$this->assertSame( 'Bearer sensitive', $context['nested']['headers']['Authorization'] );
		$this->assertSame( 'client-secret', $context['nested']['data']['client_secret'] );
		$this->assertSame( 'refresh-me', $context['nested']['data']['inner']->refresh_token );
	}

	/**
	 * Ensure recent error logging tracks only errors and warnings and limits the history.
	 */
	public function test_recent_error_messages_track_latest_entries() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging' => true,
			)
		);

		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );

		WP_MCP_AI_Logger::log_event( 'chat_interaction', 'Should be ignored.', array() );
		WP_MCP_AI_Logger::log_event( 'error', 'First error.' );
		WP_MCP_AI_Logger::log_event( 'warning', 'First warning.' );
		WP_MCP_AI_Logger::log_event( 'tool_error', 'Tool failed.' );

		$recent = WP_MCP_AI_Logger::get_recent_error_messages();

		$this->assertCount( 3, $recent );
		$this->assertSame( 'tool_error', $recent[0]['type'] );
		$this->assertSame( 'Tool failed.', $recent[0]['message'] );
		$this->assertSame( 'warning', $recent[1]['type'] );
		$this->assertSame( 'First warning.', $recent[1]['message'] );
		$this->assertSame( 'error', $recent[2]['type'] );
		$this->assertSame( 'First error.', $recent[2]['message'] );

		foreach ( $recent as $entry ) {
			$this->assertArrayHasKey( 'timestamp', $entry );
		}
	}

	/**
	 * Ensure the recent error history never exceeds the requested limit.
	 */
	public function test_recent_error_messages_limit_size() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging' => true,
			)
		);

		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );

		for ( $i = 1; $i <= 25; $i++ ) {
			WP_MCP_AI_Logger::log_event( 'error', 'Error ' . $i );
		}

		$recent = WP_MCP_AI_Logger::get_recent_error_messages();

		$this->assertCount( 20, $recent );
		$this->assertSame( 'Error 25', $recent[0]['message'] );
		$this->assertSame( 'Error 6', $recent[19]['message'] );
	}

	/**
	 * Ensure Gemini image request/response events are tracked as recent activity entries.
	 */
	public function test_gemini_image_events_tracked_in_recent_activity() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_logging' => true,
			)
		);

		delete_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );

		WP_MCP_AI_Logger::log_event( 'gemini_image_request', 'Gemini image request dispatched.' );
		WP_MCP_AI_Logger::log_event( 'gemini_image_response', 'Gemini image response received.' );

		$entries = WP_MCP_AI_Logger::get_recent_activity_entries( 5 );

		$this->assertCount( 2, $entries );
		$this->assertSame( 'gemini_image_response', $entries[0]['type'] );
		$this->assertSame( 'Gemini image response received.', $entries[0]['message'] );
		$this->assertSame( 'gemini_image_request', $entries[1]['type'] );
		$this->assertSame( 'Gemini image request dispatched.', $entries[1]['message'] );
	}

	/**
	 * Ensure the logger can determine the PHP error log size when a file exists.
	 */
	public function test_get_log_file_size_reports_bytes() {
		$temp_file = wp_tempnam( 'wp-mcp-ai-log-' );

		try {
			file_put_contents( $temp_file, str_repeat( 'A', 2048 ) );

			ini_set( 'error_log', $temp_file );
			WP_MCP_AI_Logger::reset_log_file_cache();

			$this->assertSame( 2048, WP_MCP_AI_Logger::get_log_file_size() );
		} finally {
			if ( file_exists( $temp_file ) ) {
				unlink( $temp_file );
			}
		}
	}

	/**
	 * Ensure pruning the error log truncates the file and clears cached entries.
	 */
	public function test_prune_error_log_truncates_file() {
		$temp_file = wp_tempnam( 'wp-mcp-ai-log-' );

		try {
			file_put_contents( $temp_file, str_repeat( 'B', 512 ) );

			ini_set( 'error_log', $temp_file );
			WP_MCP_AI_Logger::reset_log_file_cache();

			update_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION, array( array( 'type' => 'error' ) ) );
			update_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION, array( array( 'type' => 'tool_execution' ) ) );

			$result = WP_MCP_AI_Logger::prune_error_log();

			$this->assertTrue( $result );
			$this->assertSame( 0, filesize( $temp_file ) );
			$this->assertSame( array(), WP_MCP_AI_Logger::get_recent_error_messages() );
			$this->assertSame( array(), WP_MCP_AI_Logger::get_recent_activity_entries( 5 ) );
		} finally {
			if ( file_exists( $temp_file ) ) {
				unlink( $temp_file );
			}
		}
	}

	/**
	 * Ensure pruning fails gracefully when the error log path is unavailable.
	 */
	public function test_prune_error_log_returns_error_when_path_missing() {
		ini_set( 'error_log', 'syslog' );
		WP_MCP_AI_Logger::reset_log_file_cache();

		$result = WP_MCP_AI_Logger::prune_error_log();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_log_missing', $result->get_error_code() );
	}
}
