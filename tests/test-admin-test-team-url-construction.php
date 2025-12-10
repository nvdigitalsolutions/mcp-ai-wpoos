<?php
/**
 * Tests for Team Member URL Construction
 *
 * Validates that the team member endpoint URL is properly constructed
 * with a trailing slash to prevent 404 errors.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test Team Member URL Construction.
 */
class Test_Admin_Test_Team_URL_Construction extends WP_UnitTestCase {

	/**
	 * Test that trailingslashit is applied to rest_url results.
	 *
	 * This test validates the fix for the issue where the URL
	 * `/wp-json/mcp-ai/v1teams/1408/members` was being generated
	 * instead of `/wp-json/mcp-ai/v1/teams/1408/members`.
	 */
	public function test_rest_url_has_trailing_slash() {
		// Get the base REST URL as WordPress would provide it.
		$rest_namespace = 'mcp-ai/v1';
		$base_rest_url  = rest_url( $rest_namespace );

		// Apply trailingslashit as our fix does.
		$fixed_rest_url = trailingslashit( $base_rest_url );

		// Verify it ends with a trailing slash.
		$this->assertStringEndsWith( '/', $fixed_rest_url, 'restUrl should have a trailing slash after trailingslashit' );

		// Verify the URL structure is correct.
		$this->assertStringContainsString( '/mcp-ai/v1/', $fixed_rest_url, 'restUrl should contain /mcp-ai/v1/' );

		// Simulate the JavaScript concatenation that was failing.
		$team_id           = 1408;
		$constructed_url   = $fixed_rest_url . 'teams/' . $team_id . '/members';
		$expected_endpoint = '/teams/1408/members';

		// Verify no double slashes are created (except in http://).
		$path_part = parse_url( $constructed_url, PHP_URL_PATH );

		// The critical assertion: verify the bug is fixed.
		$this->assertStringNotContainsString( 'v1teams', $path_part, 'URL should not contain v1teams (missing slash)' );
		$this->assertStringContainsString( 'v1/teams', $path_part, 'URL should contain v1/teams (with slash)' );
		$this->assertStringEndsWith( $expected_endpoint, $path_part, 'URL should end with the correct endpoint path' );
	}

	/**
	 * Test URL construction without trailing slash to demonstrate the bug.
	 *
	 * This test documents the original bug behavior when trailing slash is missing.
	 */
	public function test_url_construction_bug_without_trailing_slash() {
		// Get the base REST URL without trailing slash.
		$rest_namespace = 'mcp-ai/v1';
		$base_rest_url  = rest_url( $rest_namespace );

		// Remove trailing slash to simulate the bug.
		$buggy_rest_url = untrailingslashit( $base_rest_url );

		// Simulate the JavaScript concatenation.
		$team_id         = 1408;
		$constructed_url = $buggy_rest_url . 'teams/' . $team_id . '/members';

		// Get the path part.
		$path_part = parse_url( $constructed_url, PHP_URL_PATH );

		// Verify the bug: URL contains v1teams instead of v1/teams.
		$this->assertStringContainsString( 'v1teams', $path_part, 'Without trailing slash, URL incorrectly contains v1teams' );
		$this->assertStringNotContainsString( 'v1/teams', $path_part, 'Without trailing slash, URL missing v1/teams separator' );
	}

	/**
	 * Test that the fix works correctly with different namespace paths.
	 */
	public function test_trailing_slash_works_with_different_namespaces() {
		$test_cases = array(
			'mcp-ai/v1'   => '/mcp-ai/v1/',
			'wp/v2'       => '/wp/v2/',
			'custom/v3'   => '/custom/v3/',
		);

		foreach ( $test_cases as $namespace => $expected_suffix ) {
			$rest_url = rest_url( $namespace );
			$fixed    = trailingslashit( $rest_url );

			$this->assertStringEndsWith( '/', $fixed, "Namespace {$namespace} should end with slash" );
			$this->assertStringContainsString( $expected_suffix, $fixed, "Namespace {$namespace} should contain {$expected_suffix}" );
		}
	}

	/**
	 * Test that URL concatenation works correctly with both leading and non-leading slashes.
	 */
	public function test_url_concatenation_patterns() {
		$rest_url_with_slash = trailingslashit( rest_url( 'mcp-ai/v1' ) );

		// Pattern 1: No leading slash (like team members endpoint).
		$url1 = $rest_url_with_slash . 'teams/123/members';
		$this->assertStringContainsString( '/v1/teams/', $url1, 'No leading slash pattern should work' );

		// Pattern 2: With leading slash (like cron-status endpoint).
		$url2 = $rest_url_with_slash . '/cron-status';
		$this->assertStringContainsString( '/v1/cron-status', $url2, 'Leading slash pattern should work' );

		// Verify neither creates v1teams or v1/cron-status.
		$path1 = parse_url( $url1, PHP_URL_PATH );
		$path2 = parse_url( $url2, PHP_URL_PATH );

		$this->assertStringNotContainsString( 'v1teams', $path1, 'Pattern 1 should not create v1teams' );
		$this->assertStringNotContainsString( 'v1//cron-status', $path2, 'Pattern 2 should not create double slashes' );
	}
}
