<?php
/**
 * Tests for credential-bearing URL query-parameter redaction in log context.
 *
 * Regression cover for the Composio Connect Link leak: a one-time OAuth
 * `state` grant was persisted verbatim into `wp_mcp_ai_recent_activity`
 * because it lived inside the query string of a `url` value, which neither
 * the key deny-list nor the Bearer/sk-/AIza value patterns matched.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Logger_Url_Redaction_Test extends WP_UnitTestCase {

	/**
	 * Sentinel secret asserted absent from every persisted surface.
	 */
	const SECRET = '3bxMbOoYrcrw0jzwreCSVWjyxAxpcjcO';

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		WP_MCP_AI_Logger::reset_log_file_cache();
		WP_MCP_AI_Logger::reset_sensitive_query_pattern_cache();

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'enable_logging' => true )
		);

		delete_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );
		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Logger::reset_log_file_cache();
		WP_MCP_AI_Logger::reset_sensitive_query_pattern_cache();

		parent::tearDown();
	}

	/**
	 * Capture the sanitized context for a tool execution without persisting it.
	 *
	 * @param string $tool_slug Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @param mixed  $result    Tool result.
	 * @return array Sanitized log context.
	 */
	protected function capture_tool_context( $tool_slug, $arguments, $result ) {
		$captured = null;
		$filter   = function ( $entry ) use ( &$captured ) {
			$captured = $entry;
			return false;
		};

		add_filter( 'wp_mcp_ai_log_entry', $filter );

		try {
			WP_MCP_AI_Logger::log_tool_execution( $tool_slug, $arguments, $result, array() );
		} finally {
			remove_filter( 'wp_mcp_ai_log_entry', $filter );
		}

		$this->assertNotNull( $captured, 'Expected the log entry to be captured.' );

		return $captured['context'];
	}

	/**
	 * The reported leak: the OAuth state grant must not reach the option row.
	 *
	 * @group security
	 */
	public function test_oauth_state_is_not_persisted_to_activity_option() {
		WP_MCP_AI_Logger::log_tool_execution(
			'composio_create_connect_link',
			array( 'toolkit' => 'gmail' ),
			array(
				'success' => true,
				'message' => 'Connect Link for gmail created. Share the URL with the user.',
				'toolkit' => 'gmail',
				'url'     => 'https://connect.composio.dev/link/lk_9XgCEUuh9JIN?state=' . self::SECRET,
			),
			array( 'assistant_id' => 7 )
		);

		$stored = wp_json_encode( get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION ) );

		$this->assertIsString( $stored );
		$this->assertStringContainsString(
			'composio_create_connect_link',
			$stored,
			'Guard: the activity entry should have been stored at all.'
		);
		$this->assertStringNotContainsString(
			self::SECRET,
			$stored,
			'The one-time OAuth state grant must never be persisted.'
		);
		// `composio_create_connect_link` declares `url` as a sensitive result
		// field, so the whole value is masked rather than just its query string.
		$this->assertStringContainsString( 'url', $stored );
		$this->assertStringContainsString( '[redacted]', $stored );
	}

	/**
	 * Redaction must preserve the URL shape so the log stays diagnostic.
	 *
	 * @group security
	 */
	public function test_redaction_preserves_url_and_non_secret_parameters() {
		$context = $this->capture_tool_context(
			'run_crawl4ai_job',
			array( 'toolkit' => 'gmail' ),
			array(
				'url' => 'https://connect.composio.dev/cb?connection_id=abc123&state=' . self::SECRET . '&toolkit=gmail',
			)
		);

		$url = $context['result_preview']['url'];

		$this->assertStringNotContainsString( self::SECRET, $url );
		$this->assertStringContainsString( 'https://connect.composio.dev/cb', $url, 'Scheme, host and path must survive.' );
		$this->assertStringContainsString( 'connection_id=abc123', $url, 'Non-secret parameters must survive.' );
		$this->assertStringContainsString( 'toolkit=gmail', $url, 'Parameters after the secret must survive.' );
		$this->assertStringContainsString( 'state=[redacted]', $url );
	}

	/**
	 * A plain diagnostic URL with no credentials must pass through untouched.
	 *
	 * @group security
	 */
	public function test_plain_urls_are_not_altered() {
		$url = 'https://example.test/wp-content/uploads/2026/08/image.png';

		$context = $this->capture_tool_context(
			'get_media',
			array( 'per_page' => 1 ),
			array( 'url' => $url )
		);

		$this->assertSame(
			$url,
			$context['result_preview']['url'],
			'A URL carrying no credential parameters must not be rewritten.'
		);
	}

	/**
	 * Parameter names must anchor on both sides to avoid false positives.
	 *
	 * `redirect_state` is not `state`; `error_code` is not `code`; and
	 * `token_secret_note` is not `token` because `=` does not follow.
	 *
	 * @group security
	 */
	public function test_parameter_name_suffixes_are_not_redacted() {
		$url = 'https://example.test/?redirect_state=keepme&error_code=keepme2&token_secret_note=keepme3';

		$context = $this->capture_tool_context(
			'run_crawl4ai_job',
			array(),
			array( 'url' => $url )
		);

		$this->assertSame(
			$url,
			$context['result_preview']['url'],
			'Only whole parameter names delimited by ?, & or # may be redacted.'
		);
	}

	/**
	 * An empty parameter value has nothing to hide and must stay empty.
	 *
	 * @group security
	 */
	public function test_empty_parameter_values_are_left_alone() {
		$url = 'https://example.test/?state=&foo=1';

		$context = $this->capture_tool_context( 'run_crawl4ai_job', array(), array( 'url' => $url ) );

		$this->assertSame( $url, $context['result_preview']['url'] );
	}

	/**
	 * Implicit-flow tokens arrive in the fragment, not the query string.
	 *
	 * @group security
	 */
	public function test_fragment_carried_tokens_are_redacted() {
		$context = $this->capture_tool_context(
			'run_crawl4ai_job',
			array(),
			array( 'url' => 'https://example.test/#access_token=' . self::SECRET . '&expires_in=3600' )
		);

		$url = $context['result_preview']['url'];

		$this->assertStringNotContainsString( self::SECRET, $url );
		$this->assertStringContainsString( 'access_token=[redacted]', $url );
		$this->assertStringContainsString( 'expires_in=3600', $url );
	}

	/**
	 * Redaction must also cover the truncated-JSON path.
	 *
	 * Results over 400 bytes are JSON-encoded and truncated by
	 * `limit_result_payload()` before redaction runs, so the secret arrives as
	 * a substring of a JSON blob rather than as an isolated array leaf.
	 *
	 * @group security
	 */
	public function test_secrets_are_redacted_inside_truncated_json_previews() {
		$context = $this->capture_tool_context(
			'composio_list_connected_accounts',
			array(),
			array(
				'url'     => 'https://example.test/cb?state=' . self::SECRET,
				'padding' => str_repeat( 'x', 600 ),
			)
		);

		$preview = $context['result_preview'];

		$this->assertIsString( $preview, 'Oversized results should collapse to a truncated JSON string.' );
		$this->assertStringNotContainsString( self::SECRET, $preview );
		$this->assertStringContainsString( 'state=[redacted]', $preview );
	}

	/**
	 * Redaction lives in the shared redactor, so tool arguments are covered too.
	 *
	 * @group security
	 */
	public function test_secrets_in_tool_arguments_are_redacted() {
		WP_MCP_AI_Logger::log_tool_execution(
			'run_crawl4ai_job',
			array(
				'url' => 'https://example.test/cb?session_uri=https%3A%2F%2Fx.test%2FABC&code=' . self::SECRET,
			),
			array( 'ok' => true ),
			array()
		);

		$stored = wp_json_encode( get_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION ) );

		$this->assertIsString( $stored );
		$this->assertStringContainsString( 'run_crawl4ai_job', $stored, 'Guard: entry should have been stored.' );
		$this->assertStringNotContainsString( self::SECRET, $stored );
		$this->assertStringNotContainsString( 'x.test', $stored, 'The single-use session_uri must be masked too.' );
		$this->assertStringContainsString( 'code=[redacted]', $stored );
		$this->assertStringContainsString( 'session_uri=[redacted]', $stored );
	}

	/**
	 * Failed tool executions route to the error option and must redact too.
	 *
	 * @group security
	 */
	public function test_secrets_are_redacted_on_the_tool_error_path() {
		WP_MCP_AI_Logger::log_tool_execution(
			'composio_create_connect_link',
			array( 'toolkit' => 'gmail' ),
			new WP_Error(
				'wp_mcp_ai_composio_link_failed',
				'Callback rejected: https://example.test/cb?state=' . self::SECRET
			),
			array()
		);

		$stored = wp_json_encode( get_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION ) );

		$this->assertIsString( $stored );
		$this->assertStringNotContainsString( self::SECRET, $stored );
	}

	/**
	 * The filter may widen the parameter list.
	 *
	 * @group security
	 */
	public function test_filter_can_add_parameters() {
		$filter = function ( $parameters ) {
			$parameters[] = 'custom_grant';
			return $parameters;
		};

		add_filter( 'wp_mcp_ai_sensitive_query_parameters', $filter );
		WP_MCP_AI_Logger::reset_sensitive_query_pattern_cache();

		try {
			$context = $this->capture_tool_context(
				'run_crawl4ai_job',
				array(),
				array( 'url' => 'https://example.test/?custom_grant=' . self::SECRET )
			);
		} finally {
			remove_filter( 'wp_mcp_ai_sensitive_query_parameters', $filter );
			WP_MCP_AI_Logger::reset_sensitive_query_pattern_cache();
		}

		$this->assertStringNotContainsString( self::SECRET, $context['result_preview']['url'] );
		$this->assertStringContainsString( 'custom_grant=[redacted]', $context['result_preview']['url'] );
	}

	/**
	 * The filter must not be able to weaken the built-in list.
	 *
	 * @group security
	 */
	public function test_filter_cannot_remove_built_in_parameters() {
		add_filter( 'wp_mcp_ai_sensitive_query_parameters', '__return_empty_array' );
		WP_MCP_AI_Logger::reset_sensitive_query_pattern_cache();

		try {
			$context = $this->capture_tool_context(
				'run_crawl4ai_job',
				array(),
				array( 'url' => 'https://example.test/?state=' . self::SECRET )
			);
		} finally {
			remove_filter( 'wp_mcp_ai_sensitive_query_parameters', '__return_empty_array' );
			WP_MCP_AI_Logger::reset_sensitive_query_pattern_cache();
		}

		$this->assertStringNotContainsString(
			self::SECRET,
			$context['result_preview']['url'],
			'Redaction is additive-only; a filter must never disable the defaults.'
		);
		$this->assertStringContainsString( 'state=[redacted]', $context['result_preview']['url'] );
	}

	/**
	 * A malformed filter return value must not break the pattern.
	 *
	 * @group security
	 */
	public function test_malformed_filter_values_are_discarded() {
		$filter = function () {
			// Regex metacharacters and non-strings must be rejected outright.
			return array( 'bad)(param', '.*', 42, '', 'ok_param' );
		};

		add_filter( 'wp_mcp_ai_sensitive_query_parameters', $filter );
		WP_MCP_AI_Logger::reset_sensitive_query_pattern_cache();

		try {
			$context = $this->capture_tool_context(
				'run_crawl4ai_job',
				array(),
				array( 'url' => 'https://example.test/?ok_param=' . self::SECRET . '&state=' . self::SECRET )
			);
		} finally {
			remove_filter( 'wp_mcp_ai_sensitive_query_parameters', $filter );
			WP_MCP_AI_Logger::reset_sensitive_query_pattern_cache();
		}

		$url = $context['result_preview']['url'];

		$this->assertStringNotContainsString( self::SECRET, $url );
		$this->assertStringContainsString( 'ok_param=[redacted]', $url, 'Valid additions must still apply.' );
		$this->assertStringContainsString( 'state=[redacted]', $url, 'Defaults must survive a malformed filter.' );
	}
}
