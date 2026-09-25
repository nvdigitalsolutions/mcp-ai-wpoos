<?php
/**
 * Tests for get_system_logs tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test get_system_logs tool functionality.
 */
class Test_Tool_Get_System_Logs extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Get_System_Logs
	 */
	private $tool;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tool      = new WP_MCP_AI_Tool_Get_System_Logs();
		$this->admin_id  = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );
		delete_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		ini_restore( 'error_log' );
		delete_option( WP_MCP_AI_Logger::RECENT_ERRORS_OPTION );
		delete_option( WP_MCP_AI_Logger::RECENT_ACTIVITY_OPTION );
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Enable NV oOS logging and clear the settings cache.
	 */
	private function enable_logging() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'enable_logging' => true )
		);
		WP_MCP_AI_Admin_Settings_Base::reset_settings_cache();
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'get_system_logs', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
	}

	/**
	 * Unauthenticated call returns forbidden.
	 */
	public function test_unauthenticated_returns_forbidden() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Admin gets a response with expected top-level keys.
	 */
	public function test_admin_gets_log_response_shape() {
		$result = $this->tool->execute(
			array( 'include_plugin_logs' => false ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'wp_mcp_ai', $result );
		$this->assertArrayHasKey( 'wordpress', $result );
	}

	/**
	 * Plugin logs are omitted when include_plugin_logs is false.
	 */
	public function test_plugin_logs_omitted_when_disabled() {
		$result = $this->tool->execute(
			array( 'include_plugin_logs' => false ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'plugin_logs', $result );
		// When disabled, plugin_logs should contain a 'message' key (not an array of files).
		$this->assertArrayHasKey( 'message', $result['plugin_logs'] );
	}

	/**
	 * Capability flags include 'requires-capability'.
	 */
	public function test_capability_flags_require_capability() {
		$flags = $this->tool->get_capability_flags();
		$this->assertContains( 'requires-capability', $flags );
	}

	/**
	 * The parameters schema exposes the searchable filters.
	 */
	public function test_schema_exposes_search_filters() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'since', $schema['properties'] );
		$this->assertArrayHasKey( 'levels', $schema['properties'] );
		$this->assertArrayHasKey( 'search', $schema['properties'] );
		$this->assertSame( 'string', $schema['properties']['since']['type'] );
		$this->assertSame( 'array', $schema['properties']['levels']['type'] );
	}

	/**
	 * Relative and absolute since values parse into UTC cutoff timestamps.
	 */
	public function test_parse_since_variants() {
		$now = time();

		$two_hours = WP_MCP_AI_Tool_Get_System_Logs::parse_since( '2h' );
		$this->assertNotFalse( $two_hours );
		$this->assertGreaterThanOrEqual( $now - 7200 - 5, $two_hours );
		$this->assertLessThanOrEqual( $now - 7200 + 5, $two_hours );

		$this->assertNotFalse( WP_MCP_AI_Tool_Get_System_Logs::parse_since( '30 minutes' ) );
		$this->assertNotFalse( WP_MCP_AI_Tool_Get_System_Logs::parse_since( '1 day ago' ) );

		// A bare number is treated as minutes.
		$bare = WP_MCP_AI_Tool_Get_System_Logs::parse_since( '45' );
		$this->assertGreaterThanOrEqual( $now - 2700 - 5, $bare );
		$this->assertLessThanOrEqual( $now - 2700 + 5, $bare );

		// Absolute ISO 8601 and MySQL-style dates.
		$iso = WP_MCP_AI_Tool_Get_System_Logs::parse_since( gmdate( 'c', $now - 3600 ) );
		$this->assertNotFalse( $iso );
		$this->assertGreaterThanOrEqual( $now - 3600 - 5, $iso );
		$this->assertLessThanOrEqual( $now - 3600 + 5, $iso );

		$this->assertSame( 0, WP_MCP_AI_Tool_Get_System_Logs::parse_since( '' ) );
		$this->assertFalse( WP_MCP_AI_Tool_Get_System_Logs::parse_since( 'banana' ) );
		$this->assertFalse( WP_MCP_AI_Tool_Get_System_Logs::parse_since( '2x' ) );
	}

	/**
	 * A malformed since value produces an invalid_since error.
	 */
	public function test_execute_rejects_invalid_since() {
		$result = $this->tool->execute(
			array( 'since' => 'banana' ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_since', $result->get_error_code() );
	}

	/**
	 * Structured error entries are filtered by since, levels, and search.
	 */
	public function test_filters_recent_error_entries() {
		$this->enable_logging();

		update_option(
			WP_MCP_AI_Logger::RECENT_ERRORS_OPTION,
			array(
				array(
					'timestamp' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
					'type'      => 'error',
					'message'   => 'Old cache miss on products',
				),
				array(
					'timestamp' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
					'type'      => 'warning',
					'message'   => 'Quota nearing limit',
				),
				array(
					'timestamp' => gmdate( 'Y-m-d H:i:s', time() - 600 ),
					'type'      => 'error',
					'message'   => 'Timeout talking to stripe',
				),
			)
		);

		// Time window: only the 10-minute-old entry survives.
		$result = $this->tool->execute(
			array(
				'since'                => '30m',
				'include_plugin_logs'  => false,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['wp_mcp_ai']['recent_errors'] );
		$this->assertStringContainsString( 'stripe', $result['wp_mcp_ai']['recent_errors'][0]['message'] );
		$this->assertSame( '30m', $result['filters']['since'] );
		$this->assertArrayHasKey( 'since_timestamp', $result['filters'] );

		// Level filter: only the warning entry survives.
		$result = $this->tool->execute(
			array(
				'levels'               => array( 'warning' ),
				'include_plugin_logs'  => false,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertCount( 1, $result['wp_mcp_ai']['recent_errors'] );
		$this->assertSame( 'warning', $result['wp_mcp_ai']['recent_errors'][0]['type'] );

		// Combined window + level + search.
		$result = $this->tool->execute(
			array(
				'since'                => '2h',
				'levels'               => array( 'error' ),
				'search'               => 'stripe',
				'include_plugin_logs'  => false,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertCount( 1, $result['wp_mcp_ai']['recent_errors'] );
		$this->assertStringContainsString( 'stripe', $result['wp_mcp_ai']['recent_errors'][0]['message'] );
	}

	/**
	 * Entries without a timestamp are excluded when a time filter is active.
	 */
	public function test_since_excludes_entries_without_timestamp() {
		$this->enable_logging();

		update_option(
			WP_MCP_AI_Logger::RECENT_ERRORS_OPTION,
			array(
				array(
					'timestamp' => '',
					'type'      => 'error',
					'message'   => 'Legacy entry without timestamp',
				),
			)
		);

		$result = $this->tool->execute(
			array(
				'since'               => '2h',
				'include_plugin_logs' => false,
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertCount( 0, $result['wp_mcp_ai']['recent_errors'] );
	}

	/**
	 * PHP error log lines are filtered by time, level, and search.
	 */
	public function test_filters_php_error_log_lines() {
		$this->enable_logging();

		$path = tempnam( sys_get_temp_dir(), 'wpmcpai-log-' );
		$this->assertNotFalse( $path );

		file_put_contents(
			$path,
			implode(
				"\n",
				array(
					'[' . gmdate( 'd-M-Y H:i:s', time() - 7200 ) . ' UTC] PHP Warning: old warning alpha',
					'[' . gmdate( 'd-M-Y H:i:s', time() - 1800 ) . ' UTC] PHP Fatal error: recent fatal beta',
					'[' . gmdate( 'd-M-Y H:i:s', time() - 300 ) . ' UTC] PHP Notice: fresh notice gamma',
					'#0 /var/www/wp-includes/plugin.php(123): apply_filters()',
				)
			)
		);

		ini_set( 'error_log', $path );

		$context = array( 'user_id' => $this->admin_id );

		// No filters: all four lines come back.
		$result = $this->tool->execute(
			array(
				'include_debug_log'   => false,
				'include_plugin_logs' => false,
			),
			$context
		);

		$entries = $result['wordpress']['php_error_log']['entries'];
		$this->assertCount( 4, $entries );

		// Time window: the 2-hour-old warning drops; the untimestamped
		// continuation line is kept conservatively.
		$result = $this->tool->execute(
			array(
				'since'               => '1h',
				'include_debug_log'   => false,
				'include_plugin_logs' => false,
			),
			$context
		);

		$entries = $result['wordpress']['php_error_log']['entries'];
		$this->assertCount( 3, $entries );
		$this->assertSame( 1, $result['wordpress']['php_error_log']['filtered_out'] );

		$joined = implode( "\n", $entries );
		$this->assertStringNotContainsString( 'alpha', $joined );
		$this->assertStringContainsString( 'beta', $joined );
		$this->assertStringContainsString( 'gamma', $joined );

		// Level filter: only the fatal error line matches.
		$result = $this->tool->execute(
			array(
				'levels'              => array( 'error' ),
				'include_debug_log'   => false,
				'include_plugin_logs' => false,
			),
			$context
		);

		$entries = $result['wordpress']['php_error_log']['entries'];
		$this->assertCount( 1, $entries );
		$this->assertStringContainsString( 'beta', $entries[0] );

		// Search filter is case-insensitive.
		$result = $this->tool->execute(
			array(
				'search'              => 'GAMMA',
				'include_debug_log'   => false,
				'include_plugin_logs' => false,
			),
			$context
		);

		$entries = $result['wordpress']['php_error_log']['entries'];
		$this->assertCount( 1, $entries );
		$this->assertStringContainsString( 'gamma', $entries[0] );

		unlink( $path );
	}

	/**
	 * The filters summary describes inactive filters too.
	 */
	public function test_filters_summary_reflects_inactive_filters() {
		$result = $this->tool->execute(
			array( 'include_plugin_logs' => false ),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'filters', $result );
		$this->assertSame( '', $result['filters']['since'] );
		$this->assertSame( array(), $result['filters']['levels'] );
		$this->assertSame( '', $result['filters']['search'] );
		$this->assertArrayNotHasKey( 'since_timestamp', $result['filters'] );
	}
}
