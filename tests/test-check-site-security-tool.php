<?php
/**
 * Tests for the Check Site Security tool.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for Check Site Security tool.
 */
class Test_Check_Site_Security_Tool extends WP_UnitTestCase {
	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Check_Site_Security
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

		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-check-site-security.php';

		$this->tool = new WP_MCP_AI_Tool_Check_Site_Security();

		// Create test users.
		$this->admin_user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		$this->subscriber_user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'check_site_security', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );

		$schema = $this->tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
	}

	/**
	 * Test security check with administrator user.
	 */
	public function test_execute_with_admin_user() {
		$context = array( 'user_id' => $this->admin_user_id );
		$result  = $this->tool->execute( array(), $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'risk_level', $result );
		$this->assertArrayHasKey( 'is_safe_to_use', $result );
		$this->assertArrayHasKey( 'recommendation', $result );
		$this->assertArrayHasKey( 'summary', $result );
		$this->assertArrayHasKey( 'checks', $result );

		// Verify summary structure.
		$this->assertArrayHasKey( 'critical', $result['summary'] );
		$this->assertArrayHasKey( 'warning', $result['summary'] );
		$this->assertArrayHasKey( 'pass', $result['summary'] );
		$this->assertArrayHasKey( 'total', $result['summary'] );

		// Verify checks structure.
		$this->assertIsArray( $result['checks'] );
		$this->assertNotEmpty( $result['checks'] );

		foreach ( $result['checks'] as $check_name => $check ) {
			$this->assertArrayHasKey( 'name', $check );
			$this->assertArrayHasKey( 'status', $check );
			$this->assertArrayHasKey( 'severity', $check );
			$this->assertArrayHasKey( 'message', $check );
		}
	}

	/**
	 * Test security check with insufficient permissions.
	 */
	public function test_execute_with_insufficient_permissions() {
		$context = array( 'user_id' => $this->subscriber_user_id );
		$result  = $this->tool->execute( array(), $context );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test security check with no user.
	 */
	public function test_execute_with_no_user() {
		$context = array( 'user_id' => 0 );
		$result  = $this->tool->execute( array(), $context );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that checks array contains expected security checks.
	 */
	public function test_checks_include_expected_security_items() {
		$context = array( 'user_id' => $this->admin_user_id );
		$result  = $this->tool->execute( array(), $context );

		$this->assertIsArray( $result );
		$checks = $result['checks'];

		// Verify expected checks are present.
		$this->assertArrayHasKey( 'https', $checks );
		$this->assertArrayHasKey( 'debug_mode', $checks );
		$this->assertArrayHasKey( 'file_edit', $checks );
		$this->assertArrayHasKey( 'default_admin', $checks );
		$this->assertArrayHasKey( 'wp_version', $checks );
		$this->assertArrayHasKey( 'ssl_verify', $checks );
		$this->assertArrayHasKey( 'force_ssl_admin', $checks );
		$this->assertArrayHasKey( 'db_prefix', $checks );
	}

	/**
	 * Test risk level calculation.
	 */
	public function test_risk_level_is_valid() {
		$context = array( 'user_id' => $this->admin_user_id );
		$result  = $this->tool->execute( array(), $context );

		$this->assertIsArray( $result );

		$valid_risk_levels = array( 'safe', 'low', 'medium', 'high', 'critical' );
		$this->assertContains( $result['risk_level'], $valid_risk_levels );
	}

	/**
	 * Test is_safe_to_use flag.
	 */
	public function test_is_safe_to_use_flag() {
		$context = array( 'user_id' => $this->admin_user_id );
		$result  = $this->tool->execute( array(), $context );

		$this->assertIsArray( $result );
		$this->assertIsBool( $result['is_safe_to_use'] );

		// If risk level is critical or high, should not be safe.
		if ( in_array( $result['risk_level'], array( 'critical', 'high' ), true ) ) {
			$this->assertFalse( $result['is_safe_to_use'] );
		}
	}

	/**
	 * Test recommendation is provided.
	 */
	public function test_recommendation_is_provided() {
		$context = array( 'user_id' => $this->admin_user_id );
		$result  = $this->tool->execute( array(), $context );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['recommendation'] );
		$this->assertIsString( $result['recommendation'] );
	}

	/**
	 * Test check severity levels are valid.
	 */
	public function test_check_severity_levels_are_valid() {
		$context = array( 'user_id' => $this->admin_user_id );
		$result  = $this->tool->execute( array(), $context );

		$this->assertIsArray( $result );

		$valid_severities = array( 'pass', 'warning', 'critical' );

		foreach ( $result['checks'] as $check ) {
			$this->assertContains( $check['severity'], $valid_severities );
		}
	}

	/**
	 * Test tool is registered in the registry.
	 */
	public function test_tool_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'check_site_security' );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Check_Site_Security', $tool );
	}

	/**
	 * Test activation security check function exists.
	 */
	public function test_activation_security_check_function_exists() {
		$this->assertTrue( function_exists( 'wp_mcp_ai_check_activation_security' ) );
	}

	/**
	 * Test activation security check can be skipped with constant.
	 */
	public function test_activation_security_check_skip_constant() {
		if ( ! defined( 'WP_MCP_AI_SKIP_SECURITY_CHECK' ) ) {
			define( 'WP_MCP_AI_SKIP_SECURITY_CHECK', true );
		}

		// Call activation security check.
		wp_mcp_ai_check_activation_security();

		// Transient should not be set when skipped.
		$result = get_transient( 'wp_mcp_ai_activation_security_check' );
		$this->assertFalse( $result );
	}
}
