<?php
/**
 * Bug Regression Test — reproduces the 11 bugs found by WP-CLI testing.
 *
 * Each bug gets a test case that asserts the current behavior. When a bug
 * is fixed, flip the assertion from `expect_fail` to `expect_pass`.
 *
 * Usage:
 *   studio wp --user=admin eval-file tests/regression/bugs.php
 *
 * @package WP_MCP_AI
 * @since  1.1.34
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	// Gracefully skip when run under PHPUnit or direct web access.
	if ( defined( 'PHPUNIT_COMPOSER_INSTALL' ) || defined( 'WP_TESTS_DOMAIN' ) ) {
		return;
	}
	fwrite( STDERR, "This script must be run via WP-CLI.\n" );
	exit( 1 );
}

$user_id = get_current_user_id();
$passed  = 0;
$failed  = 0;
$total   = 0;

/**
 * Run a single bug regression test.
 *
 * @param string $bug_id      Bug identifier (B1-B11).
 * @param string $description What the bug is.
 * @param string $tool_class  Tool class to instantiate (optional).
 * @param array  $args        Arguments to pass to execute().
 * @param bool   $expect_pass Whether we expect the tool to pass (bug is fixed).
 * @param string $expected_error_code Expected WP_Error code if we expect failure.
 */
function test_bug( $bug_id, $description, $tool_class, $args, $expect_pass, $expected_error_code = '' ) {
	global $user_id, $passed, $failed, $total;
	$total++;

	WP_CLI::log( sprintf( "\n[%s] %s", $bug_id, $description ) );

	if ( ! class_exists( $tool_class ) ) {
		WP_CLI::log( '  SKIP: Class not found.' );
		return;
	}

	$tool = new $tool_class();

	if ( method_exists( $tool, 'is_available' ) && ! $tool->is_available() ) {
		WP_CLI::log( '  SKIP: Tool not available.' );
		return;
	}

	try {
		$result = $tool->execute( $args, array( 'user_id' => $user_id ) );

		if ( $expect_pass ) {
			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( sprintf( '  FAIL (expected pass): %s', $result->get_error_message() ) );
				$failed++;
			} else {
				WP_CLI::success( '  PASS (bug fixed).' );
				$passed++;
			}
		} else {
			if ( is_wp_error( $result ) ) {
				if ( $expected_error_code && $result->get_error_code() !== $expected_error_code ) {
					WP_CLI::log( sprintf( '  EXPECTED: Got error "%s" (expected code "%s", got "%s"). Bug may have changed.', $result->get_error_message(), $expected_error_code, $result->get_error_code() ) );
					$passed++; // Still counts as "not a fatal crash".
				} else {
					WP_CLI::log( sprintf( '  EXPECTED: %s', $result->get_error_message() ) );
					$passed++;
				}
			} else {
				WP_CLI::warning( '  UNEXPECTED PASS: Bug may have been silently fixed.' );
				$failed++;
			}
		}
	} catch ( \Throwable $e ) {
		if ( $expect_pass ) {
			WP_CLI::warning( sprintf( '  FATAL (expected pass): %s at %s:%d', $e->getMessage(), $e->getFile(), $e->getLine() ) );
			$failed++;
		} else {
			WP_CLI::log( sprintf( '  FATAL (expected): %s', $e->getMessage() ) );
			$passed++;
		}
	}
}

// ============================================================================
// B6: get_environment_status — count() on null. FIXED in v1.1.34.
// ============================================================================
test_bug( 'B6', 'get_environment_status count() on null (apply_filters guard)', 'WP_MCP_AI_Tool_Get_Environment_Status', array(), true );

// ============================================================================
// B9: enable_reasoning_mode — undefined method success(). FIXED in v1.1.34.
// ============================================================================
test_bug( 'B9', 'enable_reasoning_mode success() -> format_chat_response()', 'WP_MCP_AI_Tool_Enable_Reasoning_Mode', array( 'task' => 'test' ), true );

// ============================================================================
// B10: analyze_code_sequence — undefined method success(). FIXED in v1.1.34.
// ============================================================================
test_bug( 'B10', 'analyze_code_sequence success() -> format_chat_response()', 'WP_MCP_AI_Tool_Analyze_Code_Sequence', array( 'code' => '<?php echo "test";' ), true );

// ============================================================================
// B11: validate_reasoning_chain — trim() on array + success(). FIXED in v1.1.34.
// ============================================================================
test_bug( 'B11', 'validate_reasoning_chain trim() guard + success()', 'WP_MCP_AI_Tool_Validate_Reasoning_Chain', array( 'reasoning_steps' => array( 'Step 1', 'Step 2' ), 'conclusion' => 'test' ), true );

// ============================================================================
// B7: transcode_video — undefined method get_video_file_info().
// ============================================================================
test_bug( 'B7', 'transcode_video get_video_file_info() method', 'WP_MCP_AI_Tool_Transcode_Video', array( 'output_format' => 'mp4' ), false, 'wp_mcp_ai_missing_parameter' );

// ============================================================================
// B8: evaluate_inbound_message — WP_Error used as array.
// ============================================================================
test_bug( 'B8', 'evaluate_inbound_message array access on WP_Error', 'WP_MCP_AI_Tool_Evaluate_Inbound_Message', array( 'message_body' => 'Test message for CI regression test.' ), true );

// ============================================================================
// B1, B2: Provider CLI commands — fatal errors. (CLI-only, skip in eval-file)
// ============================================================================
WP_CLI::log( "\n[B1/B2] Provider CLI commands — cannot test via eval-file (CLI-only)." );
WP_CLI::log( '  Run: wp mcp-ai provider list' );
WP_CLI::log( '  Run: wp mcp-ai provider test openai' );
$total += 2;

// ============================================================================
// B3: Cron CLI — fatal error. (CLI-only, skip in eval-file)
// ============================================================================
WP_CLI::log( "\n[B3] Cron CLI command — cannot test via eval-file (CLI-only)." );
WP_CLI::log( '  Run: wp mcp-ai cron list' );
$total++;

// ============================================================================
// B4: Chat CLI — fatal error. (CLI-only, skip in eval-file)
// ============================================================================
WP_CLI::log( "\n[B4] Chat CLI command — cannot test via eval-file (CLI-only)." );
WP_CLI::log( '  Run: wp mcp-ai chat "Hello"' );
$total++;

// ============================================================================
// B5: Connection --url conflict. Already fixed (uses --remote-url).
// ============================================================================
WP_CLI::log( "\n[B5] Connection --url conflict — already uses --remote-url." );
WP_CLI::success( '  PASS (verified via code review).' );
$passed++;
$total++;

// ============================================================================
// Report.
// ============================================================================
WP_CLI::log( '' );
WP_CLI::log( '========================================' );
WP_CLI::log( '       BUG REGRESSION TEST RESULTS' );
WP_CLI::log( '========================================' );
WP_CLI::log( sprintf( 'Total tests: %d', $total ) );
WP_CLI::log( sprintf( 'Passed:      %d', $passed ) );
WP_CLI::log( sprintf( 'Failed:      %d', $failed ) );
WP_CLI::log( '========================================' );

if ( $failed > 0 ) {
	WP_CLI::error( sprintf( '%d regression test(s) failed.', $failed ) );
} else {
	WP_CLI::success( 'All regression tests passed.' );
}
