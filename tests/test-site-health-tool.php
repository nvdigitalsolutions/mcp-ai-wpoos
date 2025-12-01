<?php
/**
 * tests/test-site-health-tool.php
 *
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-site-health.php';

/**
 * Tests for the Site Health tool.
 */
class WP_MCP_AI_Site_Health_Tool_Test extends WP_UnitTestCase {
	/**
	 * Ensure the tool enforces the Site Health capability requirement.
	 */
	public function test_execute_requires_site_health_capability() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$logged_errors = array();

		// Capture error log events.
		add_filter(
			'wp_mcp_ai_log_entry',
			function ( $entry ) use ( &$logged_errors ) {
				if ( isset( $entry['type'] ) && 'error' === $entry['type'] ) {
					$logged_errors[] = $entry;
				}
				return $entry;
			}
		);

		$tool   = new WP_MCP_AI_Tool_Get_Site_Health();
		$result = $tool->execute( array(), array( 'user_id' => $subscriber_id ) );

		remove_all_filters( 'wp_mcp_ai_log_entry' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );

		// Verify that an error was logged.
		$this->assertNotEmpty( $logged_errors, 'Should log an error when access is denied' );
		$this->assertStringContainsString( 'insufficient permissions', $logged_errors[0]['message'], 'Error message should mention permissions' );
		$this->assertArrayHasKey( 'context', $logged_errors[0], 'Error should have context' );
		$this->assertArrayHasKey( 'user_id', $logged_errors[0]['context'], 'Error context should include user_id' );
		$this->assertSame( $subscriber_id, $logged_errors[0]['context']['user_id'], 'Logged user_id should match' );
	}

	/**
	 * Ensure that logging events are triggered during tool execution.
	 */
	public function test_execute_logs_diagnostic_information() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$logged_events = array();

		// Capture log events.
		add_filter(
			'wp_mcp_ai_log_entry',
			function ( $entry ) use ( &$logged_events ) {
				$logged_events[] = $entry;
				return $entry;
			}
		);

		$tool = new WP_MCP_AI_Tool_Get_Site_Health();

		add_filter( 'site_status_tests', array( $this, 'filter_site_status_tests' ) );

		try {
			$tool->execute( array(), array( 'user_id' => $admin_id ) );
		} finally {
			remove_filter( 'site_status_tests', array( $this, 'filter_site_status_tests' ) );
			remove_all_filters( 'wp_mcp_ai_log_entry' );
		}

		// Verify that expected log events were triggered.
		$event_types = array_column( $logged_events, 'type' );

		$this->assertContains( 'site_health_check', $event_types, 'Should log initial access check' );
		$this->assertContains( 'site_health_capability_check', $event_types, 'Should log capability check result' );
		$this->assertContains( 'site_health_multisite_check', $event_types, 'Should log multisite check' );
		$this->assertContains( 'site_health_dependency_check', $event_types, 'Should log dependency check' );

		// Verify user_id is logged in the initial check.
		$initial_check = array_values(
			array_filter(
				$logged_events,
				function ( $event ) {
					return isset( $event['type'] ) && 'site_health_check' === $event['type'];
				}
			)
		);

		$this->assertNotEmpty( $initial_check, 'Initial check event should be logged' );
		$this->assertArrayHasKey( 'context', $initial_check[0], 'Event should have context' );
		$this->assertArrayHasKey( 'user_id', $initial_check[0]['context'], 'Context should include user_id' );
		$this->assertSame( $admin_id, $initial_check[0]['context']['user_id'], 'Logged user_id should match' );
	}

	/**
	 * The tool should return structured results grouped by severity.
	 */
	public function test_execute_returns_structured_site_health_data() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$tool = new WP_MCP_AI_Tool_Get_Site_Health();

		add_filter( 'site_status_tests', array( $this, 'filter_site_status_tests' ) );

		try {
			$result = $tool->execute( array(), array( 'user_id' => $admin_id ) );
		} finally {
			remove_filter( 'site_status_tests', array( $this, 'filter_site_status_tests' ) );
		}

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertArrayHasKey( 'tests', $result );

		$this->assertSame( 1, $result['summary']['critical'] );
		$this->assertSame( 1, $result['summary']['warning'] );
		$this->assertSame( 1, $result['summary']['pass'] );

		$critical = $result['tests']['critical'][0];
		$this->assertSame( 'custom_critical', $critical['test'] );
		$this->assertSame( 'critical', $critical['status'] );
		$this->assertSame( 'Automatic updates disabled', $critical['label'] );
		$this->assertSame( 'Security', $critical['badge']['label'] );
		$this->assertSame( 'red', $critical['badge']['color'] );
		$this->assertSame( 'The site cannot apply security updates automatically.', $critical['description'] );
		$this->assertSame( 'Enable automatic updates as soon as possible. Security Guide', $critical['recommendation']['summary'] );
		$this->assertSame(
			array(
				array(
					'url'   => 'https://example.com/security',
					'label' => 'Security Guide',
				),
			),
			$critical['recommendation']['links']
		);
		$this->assertCount( 1, $critical['fields'] );
		$this->assertSame( 'Last check', $critical['fields'][0]['label'] );
		$this->assertSame( 'Never', $critical['fields'][0]['value'] );

		$warning = $result['tests']['warning'][0];
		$this->assertSame( 'custom_warning', $warning['test'] );
		$this->assertSame( 'recommended', $warning['status'] );
		$this->assertSame( 'Persistent object cache', $warning['label'] );
		$this->assertSame( 'Performance', $warning['badge']['label'] );
		$this->assertSame(
			array(
				array(
					'url'   => 'https://example.com/cache-guide',
					'label' => 'Object Cache Guide',
				),
			),
			$warning['recommendation']['links']
		);

		$pass = $result['tests']['pass'][0];
		$this->assertSame( 'custom_good', $pass['test'] );
		$this->assertSame( 'good', $pass['status'] );
		$this->assertSame( 'HTTPS Status', $pass['label'] );
		$this->assertSame( '', $pass['recommendation']['summary'] );
		$this->assertSame( array(), $pass['recommendation']['links'] );
	}

	/**
	 * Provide predictable Site Health tests for the tool to execute.
	 *
	 * @return array
	 */
	public function filter_site_status_tests() {
		return array(
			'direct' => array(
				'custom_critical' => array(
					'label' => 'Critical security test',
					'test'  => array( $this, 'run_custom_critical_test' ),
				),
				'custom_warning'  => array(
					'label' => 'Caching test',
					'test'  => array( $this, 'run_custom_warning_test' ),
				),
				'custom_good'     => array(
					'label' => 'HTTPS test',
					'test'  => array( $this, 'run_custom_good_test' ),
				),
			),
			'async'  => array(),
		);
	}

	/**
	 * Simulate a critical Site Health result.
	 *
	 * @return array
	 */
	public function run_custom_critical_test() {
		return array(
			'label'       => 'Automatic updates disabled',
			'status'      => 'critical',
			'description' => '<p>The site cannot apply security updates automatically.</p>',
			'actions'     => '<p>Enable automatic updates as soon as possible. <a href="https://example.com/security">Security Guide</a></p>',
			'badge'       => array(
				'label' => 'Security',
				'color' => 'red',
			),
			'fields'      => array(
				array(
					'label' => 'Last check',
					'value' => '<em>Never</em>',
				),
				array(
					'label' => '',
					'value' => 'Ignored',
				),
			),
		);
	}

	/**
	 * Simulate a recommended Site Health result.
	 *
	 * @return array
	 */
	public function run_custom_warning_test() {
		return array(
			'label'       => 'Persistent object cache',
			'status'      => 'recommended',
			'description' => '<p>Consider enabling a persistent object cache.</p>',
			'actions'     => '<p>Follow the <a href="https://example.com/cache-guide">Object Cache Guide</a> to configure caching.</p>',
			'badge'       => array(
				'label' => 'Performance',
				'color' => 'blue',
			),
		);
	}

	/**
	 * Simulate a passing Site Health result.
	 *
	 * @return array
	 */
	public function run_custom_good_test() {
		return array(
			'label'  => 'HTTPS Status',
			'status' => 'good',
		);
	}

	/**
	 * Test that exceptions thrown by Site Health tests are handled gracefully.
	 */
	public function test_execute_handles_test_exceptions_gracefully() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$tool = new WP_MCP_AI_Tool_Get_Site_Health();

		$logged_errors = array();

		// Capture error log events.
		add_filter(
			'wp_mcp_ai_log_entry',
			function ( $entry ) use ( &$logged_errors ) {
				if ( isset( $entry['type'] ) && 'error' === $entry['type'] ) {
					$logged_errors[] = $entry;
				}
				return $entry;
			}
		);

		add_filter( 'site_status_tests', array( $this, 'filter_site_status_tests_with_exception' ) );

		try {
			$result = $tool->execute( array(), array( 'user_id' => $admin_id ) );
		} finally {
			remove_filter( 'site_status_tests', array( $this, 'filter_site_status_tests_with_exception' ) );
			remove_all_filters( 'wp_mcp_ai_log_entry' );
		}

		// Tool should still return a result, not throw a 500 error.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertArrayHasKey( 'tests', $result );

		// The good test should still pass.
		$this->assertSame( 1, $result['summary']['pass'] );

		// The critical and warning tests should not appear since they threw exceptions.
		$this->assertSame( 0, $result['summary']['critical'] );
		$this->assertSame( 0, $result['summary']['warning'] );

		// Verify errors were logged.
		$this->assertNotEmpty( $logged_errors, 'Exceptions should be logged as errors' );

		// Verify that errors were logged with proper structure.
		$error_count = 0;
		foreach ( $logged_errors as $error ) {
			if ( isset( $error['message'] ) && false !== strpos( $error['message'], 'Site Health test callback threw error' ) ) {
				++$error_count;
				$this->assertArrayHasKey( 'context', $error, 'Error should have context array' );
				$this->assertArrayHasKey( 'error_message', $error['context'], 'Context should include error_message' );
				$this->assertArrayHasKey( 'error_file', $error['context'], 'Context should include error_file' );
				$this->assertArrayHasKey( 'error_line', $error['context'], 'Context should include error_line' );
				$this->assertArrayHasKey( 'callback', $error['context'], 'Context should include callback name' );
				$this->assertNotEmpty( $error['context']['error_message'], 'Error message should not be empty' );
			}
		}

		$this->assertGreaterThan( 0, $error_count, 'At least one error should be logged' );
	}

	/**
	 * Provide Site Health tests where some throw exceptions.
	 *
	 * @return array
	 */
	public function filter_site_status_tests_with_exception() {
		return array(
			'direct' => array(
				'throws_exception' => array(
					'label' => 'Test that throws exception',
					'test'  => array( $this, 'run_test_that_throws_exception' ),
				),
				'throws_error'     => array(
					'label' => 'Test that throws error',
					'test'  => array( $this, 'run_test_that_throws_error' ),
				),
				'custom_good'      => array(
					'label' => 'HTTPS test',
					'test'  => array( $this, 'run_custom_good_test' ),
				),
			),
			'async'  => array(),
		);
	}

	/**
	 * Simulate a test that throws an exception.
	 *
	 * @throws Exception Always throws.
	 */
	public function run_test_that_throws_exception() {
		throw new Exception( 'Test exception from Site Health' );
	}

	/**
	 * Simulate a test that throws an error (PHP 7+).
	 *
	 * @throws Error Always throws.
	 */
	public function run_test_that_throws_error() {
		throw new Error( 'Test error from Site Health' );
	}

	/**
	 * Test that the tool gracefully handles undefined function errors that may occur
	 * when WordPress admin dependencies are not fully loaded.
	 */
	public function test_execute_handles_undefined_function_errors() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$tool = new WP_MCP_AI_Tool_Get_Site_Health();

		$logged_errors = array();

		// Capture error log events.
		add_filter(
			'wp_mcp_ai_log_entry',
			function ( $entry ) use ( &$logged_errors ) {
				if ( isset( $entry['type'] ) && 'error' === $entry['type'] ) {
					$logged_errors[] = $entry;
				}
				return $entry;
			}
		);

		add_filter( 'site_status_tests', array( $this, 'filter_site_status_tests_with_undefined_function' ) );

		try {
			$result = $tool->execute( array(), array( 'user_id' => $admin_id ) );
		} finally {
			remove_filter( 'site_status_tests', array( $this, 'filter_site_status_tests_with_undefined_function' ) );
			remove_all_filters( 'wp_mcp_ai_log_entry' );
		}

		// Tool should still return a result, not throw a fatal error.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertArrayHasKey( 'tests', $result );

		// The good test should still pass.
		$this->assertSame( 1, $result['summary']['pass'] );

		// The test that calls undefined function should not appear since it threw an error.
		$this->assertSame( 0, $result['summary']['critical'] );

		// Verify error was logged.
		$this->assertNotEmpty( $logged_errors, 'Undefined function errors should be logged' );

		// Verify that the error was logged with proper structure.
		$found_undefined_function_error = false;
		foreach ( $logged_errors as $error ) {
			if ( isset( $error['message'] ) && false !== strpos( $error['message'], 'Site Health test callback threw error' ) ) {
				$this->assertArrayHasKey( 'context', $error, 'Error should have context array' );
				$this->assertArrayHasKey( 'error_message', $error['context'], 'Context should include error_message' );
				$this->assertArrayHasKey( 'callback', $error['context'], 'Context should include callback name' );
				// Check case-insensitively for "undefined function" in the error message.
				if ( false !== stripos( $error['context']['error_message'], 'undefined function' ) ) {
					$found_undefined_function_error = true;
				}
			}
		}

		$this->assertTrue( $found_undefined_function_error, 'Should log undefined function error specifically' );
	}

	/**
	 * Provide Site Health tests where one simulates calling an undefined function.
	 *
	 * @return array
	 */
	public function filter_site_status_tests_with_undefined_function() {
		return array(
			'direct' => array(
				'undefined_function' => array(
					'label' => 'Test that calls undefined function',
					'test'  => array( $this, 'run_test_with_undefined_function' ),
				),
				'custom_good'        => array(
					'label' => 'HTTPS test',
					'test'  => array( $this, 'run_custom_good_test' ),
				),
			),
			'async'  => array(),
		);
	}

	/**
	 * Simulate a test that calls an undefined function.
	 *
	 * This simulates the scenario where WordPress admin includes are not loaded
	 * and functions like wp_check_php_version() are unavailable.
	 *
	 * @throws Error Throws Error when an undefined function is called.
	 */
	public function run_test_with_undefined_function() {
		// Simulate calling a function that doesn't exist.
		// This will throw an Error in PHP 7+ with message "Call to undefined function...".
		// We use eval to dynamically call a non-existent function name.
		$undefined_function_name = 'wp_mcp_ai_test_undefined_function_' . uniqid();
		eval( $undefined_function_name . '();' );
	}

	/**
	 * Test that the polyfill for wp_check_php_version() is available and works.
	 */
	public function test_wp_check_php_version_polyfill_is_available() {
		// Create a new instance to trigger ensure_site_health_dependencies().
		$tool = new WP_MCP_AI_Tool_Get_Site_Health();

		// Use reflection to call the protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'ensure_site_health_dependencies' );
		$method->setAccessible( true );

		// Call the method to ensure dependencies are loaded.
		$dependencies_loaded = $method->invoke( $tool );

		// Verify dependencies were loaded successfully.
		$this->assertTrue( $dependencies_loaded, 'Dependencies should be loaded successfully' );

		// Verify that wp_check_php_version function exists after loading dependencies.
		$this->assertTrue(
			function_exists( 'wp_check_php_version' ),
			'wp_check_php_version function should exist after loading dependencies'
		);

		// Call the function and verify it returns the expected structure.
		$result = wp_check_php_version();

		// The function should return either an array or false.
		$this->assertTrue(
			is_array( $result ) || false === $result,
			'wp_check_php_version should return an array or false'
		);

		// If it returns an array, verify it has the expected keys.
		if ( is_array( $result ) ) {
			$this->assertArrayHasKey( 'recommended_version', $result, 'Result should have recommended_version' );
			$this->assertArrayHasKey( 'minimum_version', $result, 'Result should have minimum_version' );
			$this->assertArrayHasKey( 'is_supported', $result, 'Result should have is_supported' );
			$this->assertArrayHasKey( 'is_secure', $result, 'Result should have is_secure' );
			$this->assertArrayHasKey( 'is_acceptable', $result, 'Result should have is_acceptable' );
		}
	}
}
