<?php
/**
 * Tests for the Login Security Monitor tool.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for Login Security Monitor tool.
 */
class Test_Login_Security_Monitor extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Login_Security_Monitor
	 */
	private $tool;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private $subscriber_user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-login-security-monitor.php';

		$this->tool = new WP_MCP_AI_Tool_Login_Security_Monitor();

		$this->admin_user_id = $this->factory->user->create(
			array( 'role' => 'administrator' )
		);

		$this->subscriber_user_id = $this->factory->user->create(
			array( 'role' => 'subscriber' )
		);
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test tool slug.
	 */
	public function test_get_slug() {
		$this->assertSame( 'login_security_monitor', $this->tool->get_slug() );
	}

	/**
	 * Test tool definition structure.
	 */
	public function test_get_definition() {
		$definition = $this->tool->get_definition();

		$this->assertIsArray( $definition );
		$this->assertArrayHasKey( 'name', $definition );
		$this->assertNotEmpty( $definition['name'] );
		$this->assertArrayHasKey( 'description', $definition );
		$this->assertNotEmpty( $definition['description'] );
		$this->assertArrayHasKey( 'category', $definition );
		$this->assertSame( 'security', $definition['category'] );
		$this->assertArrayHasKey( 'required_capability', $definition );
		$this->assertSame( 'manage_options', $definition['required_capability'] );
		$this->assertArrayHasKey( 'parameters', $definition );
		$this->assertIsArray( $definition['parameters'] );
	}

	/**
	 * Test tool parameters include expected fields.
	 */
	public function test_definition_parameters() {
		$definition = $this->tool->get_definition();
		$params     = $definition['parameters'];

		$this->assertArrayHasKey( 'time_period', $params );
		$this->assertArrayHasKey( 'start_date', $params );
		$this->assertArrayHasKey( 'end_date', $params );
		$this->assertArrayHasKey( 'username', $params );
		$this->assertArrayHasKey( 'ip_address', $params );
		$this->assertArrayHasKey( 'threats_only', $params );
		$this->assertArrayHasKey( 'include_analysis', $params );
	}

	/**
	 * Test that non-admin users cannot execute the tool.
	 */
	public function test_execute_requires_manage_options_capability() {
		wp_set_current_user( $this->subscriber_user_id );

		$result = $this->tool->execute(
			array( 'time_period' => '24hours' ),
			array( 'user_id' => $this->subscriber_user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that unauthenticated requests are rejected.
	 */
	public function test_execute_unauthenticated() {
		wp_set_current_user( 0 );

		$result = $this->tool->execute(
			array( 'time_period' => '24hours' ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that administrator can execute the tool.
	 */
	public function test_execute_with_admin_user() {
		wp_set_current_user( $this->admin_user_id );

		$result = $this->tool->execute(
			array(
				'time_period'      => '24hours',
				'include_analysis' => false,
				'threats_only'     => false,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'time_range', $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertArrayHasKey( 'threats', $result );
		$this->assertArrayHasKey( 'recommendations', $result );
	}

	/**
	 * Test summary structure.
	 */
	public function test_summary_structure() {
		wp_set_current_user( $this->admin_user_id );

		$result = $this->tool->execute(
			array(
				'time_period'      => '24hours',
				'include_analysis' => false,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'summary', $result );

		$summary = $result['summary'];
		$this->assertIsArray( $summary );
		$this->assertArrayHasKey( 'total_attempts', $summary );
		$this->assertArrayHasKey( 'successful_logins', $summary );
		$this->assertArrayHasKey( 'failed_attempts', $summary );
		$this->assertArrayHasKey( 'blocked_attempts', $summary );
		$this->assertArrayHasKey( 'unique_users', $summary );
		$this->assertArrayHasKey( 'unique_ips', $summary );
		$this->assertArrayHasKey( 'threat_level', $summary );
		$this->assertArrayHasKey( 'risk_score', $summary );

		// Counts should be non-negative integers.
		$this->assertGreaterThanOrEqual( 0, $summary['total_attempts'] );
		$this->assertGreaterThanOrEqual( 0, $summary['failed_attempts'] );
		$this->assertGreaterThanOrEqual( 0, $summary['blocked_attempts'] );
	}

	/**
	 * Test threats structure.
	 */
	public function test_threats_structure() {
		wp_set_current_user( $this->admin_user_id );

		$result = $this->tool->execute(
			array(
				'time_period'      => '24hours',
				'include_analysis' => false,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'threats', $result );
		$this->assertIsArray( $result['threats'] );
	}

	/**
	 * Test recommendations structure.
	 */
	public function test_recommendations_structure() {
		wp_set_current_user( $this->admin_user_id );

		$result = $this->tool->execute(
			array(
				'time_period'      => '24hours',
				'include_analysis' => false,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'recommendations', $result );
		$this->assertIsArray( $result['recommendations'] );
	}

	/**
	 * Test different time periods.
	 */
	public function test_various_time_periods() {
		wp_set_current_user( $this->admin_user_id );

		$periods = array( '1hour', '24hours', '7days', '30days' );
		foreach ( $periods as $period ) {
			$result = $this->tool->execute(
				array(
					'time_period'      => $period,
					'include_analysis' => false,
				),
				array( 'user_id' => $this->admin_user_id )
			);

			$this->assertIsArray( $result, "Failed for period: {$period}" );
			$this->assertTrue( $result['success'], "Should succeed for period: {$period}" );
			$this->assertArrayHasKey( 'summary', $result, "Missing summary for: {$period}" );
		}
	}

	/**
	 * Test custom time period with start/end dates.
	 */
	public function test_custom_time_period() {
		wp_set_current_user( $this->admin_user_id );

		$result = $this->tool->execute(
			array(
				'time_period'      => 'custom',
				'start_date'       => gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
				'end_date'         => gmdate( 'Y-m-d' ),
				'include_analysis' => false,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'summary', $result );
	}

	/**
	 * Test username filter.
	 */
	public function test_username_filter() {
		wp_set_current_user( $this->admin_user_id );

		$admin_user = get_userdata( $this->admin_user_id );
		$result     = $this->tool->execute(
			array(
				'time_period'      => '24hours',
				'username'         => $admin_user->user_login,
				'include_analysis' => false,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	/**
	 * Test include_analysis flag does not trigger when no threats exist.
	 */
	public function test_include_analysis_no_threats() {
		wp_set_current_user( $this->admin_user_id );

		$result = $this->tool->execute(
			array(
				'time_period'      => '24hours',
				'include_analysis' => true,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		// With no login activity, ai_insights will not be set (requires non-empty threats).
		// Verify the rest of the structure is present regardless.
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertArrayHasKey( 'threats', $result );
	}

	/**
	 * Test that the result has correct time_range structure.
	 */
	public function test_time_range_structure() {
		wp_set_current_user( $this->admin_user_id );

		$result = $this->tool->execute(
			array(
				'time_period'      => '24hours',
				'include_analysis' => false,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'time_range', $result );

		$time_range = $result['time_range'];
		$this->assertIsArray( $time_range );
		$this->assertArrayHasKey( 'start', $time_range );
		$this->assertArrayHasKey( 'end', $time_range );
		$this->assertGreaterThan( 0, $time_range['start'] );
		$this->assertGreaterThan( $time_range['start'], $time_range['end'] );
	}

	/**
	 * Test that Wordfence integration falls back gracefully when table is absent.
	 */
	public function test_wordfence_fallback_when_no_table() {
		wp_set_current_user( $this->admin_user_id );

		// Without a Wordfence table (not installed), we should still get valid data
		// structure via the WordPress native fallback.
		$result = $this->tool->execute(
			array(
				'time_period'      => '24hours',
				'include_analysis' => false,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertGreaterThanOrEqual( 0, $result['summary']['total_attempts'] );
	}
}
