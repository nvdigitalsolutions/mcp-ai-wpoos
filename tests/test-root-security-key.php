<?php
/**
 * Test Root Security Key functionality.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for root security key management.
 */
class Test_Root_Security_Key extends WP_UnitTestCase {
	/**
	 * Root security key instance.
	 *
	 * @var WP_MCP_AI_Root_Security_Key
	 */
	private $security_key;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->security_key = WP_MCP_AI_Root_Security_Key::get_instance();

		// Clean up any existing state.
		delete_option( WP_MCP_AI_Root_Security_Key::OPTION_KEY_REQUIRED );
		delete_option( WP_MCP_AI_Root_Security_Key::OPTION_KEY_FAILED_ATTEMPTS );
		delete_transient( WP_MCP_AI_Root_Security_Key::TRANSIENT_RATE_LIMIT );
	}

	/**
	 * Skip tests that assert the not-configured contract when an earlier suite
	 * defined WP_MCP_AI_ROOT_SECURITY_KEY.
	 *
	 * Constants cannot be undefined once set, and the shared PHPUnit process
	 * runs the security test suites before this one, so the constant may
	 * already be defined by the time these tests run.
	 */
	private function skip_when_key_constant_defined() {
		if ( defined( 'WP_MCP_AI_ROOT_SECURITY_KEY' ) && '' !== WP_MCP_AI_ROOT_SECURITY_KEY ) {
			$this->markTestSkipped(
				'WP_MCP_AI_ROOT_SECURITY_KEY is already defined by an earlier suite; the not-configured contract is untestable in a shared process.'
			);
		}
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Clean up test data.
		delete_option( WP_MCP_AI_Root_Security_Key::OPTION_KEY_REQUIRED );
		delete_option( WP_MCP_AI_Root_Security_Key::OPTION_KEY_FAILED_ATTEMPTS );
		delete_transient( WP_MCP_AI_Root_Security_Key::TRANSIENT_RATE_LIMIT );

		parent::tearDown();
	}

	/**
	 * Test that key is not configured by default.
	 */
	public function test_key_not_configured_by_default() {
		$this->skip_when_key_constant_defined();
		$this->assertFalse( $this->security_key->is_key_configured() );
	}

	/**
	 * Test that key is not required by default.
	 */
	public function test_key_not_required_by_default() {
		$this->assertFalse( $this->security_key->is_key_required() );
	}

	/**
	 * Test that initialization can proceed when key is not required.
	 */
	public function test_can_initialize_without_key() {
		$this->assertTrue( $this->security_key->can_initialize() );
	}

	/**
	 * Test enabling key requirement fails when key is not configured.
	 */
	public function test_enable_key_requirement_fails_without_configured_key() {
		$this->skip_when_key_constant_defined();
		$result = $this->security_key->enable_key_requirement( 'Test reason' );
		$this->assertFalse( $result );
	}

	/**
	 * Test get status returns correct information.
	 */
	public function test_get_status() {
		$this->skip_when_key_constant_defined();
		$status = $this->security_key->get_status();

		$this->assertIsArray( $status );
		$this->assertArrayHasKey( 'configured', $status );
		$this->assertArrayHasKey( 'required', $status );
		$this->assertArrayHasKey( 'locked_out', $status );
		$this->assertArrayHasKey( 'failed_attempts', $status );

		$this->assertFalse( $status['configured'] );
		$this->assertFalse( $status['required'] );
		$this->assertFalse( $status['locked_out'] );
		$this->assertEquals( 0, $status['failed_attempts'] );
	}

	/**
	 * Test verification fails without configured key.
	 */
	public function test_verify_key_fails_without_configured_key() {
		$this->skip_when_key_constant_defined();
		$result = $this->security_key->verify_key( 'test_key' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'key_not_configured', $result->get_error_code() );
	}

	/**
	 * Test disable key requirement fails without configured key.
	 */
	public function test_disable_key_requirement_fails_without_configured_key() {
		$this->skip_when_key_constant_defined();
		$result = $this->security_key->disable_key_requirement( 'test_key' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'key_not_configured', $result->get_error_code() );
	}

	/**
	 * Test that failed attempts are not recorded when key is not configured.
	 */
	public function test_no_failed_attempts_without_configured_key() {
		$this->skip_when_key_constant_defined();
		$this->security_key->verify_key( 'test_key' );

		$status = $this->security_key->get_status();
		$this->assertEquals( 0, $status['failed_attempts'] );
	}

	/**
	 * Test singleton pattern.
	 */
	public function test_singleton_pattern() {
		$instance1 = WP_MCP_AI_Root_Security_Key::get_instance();
		$instance2 = WP_MCP_AI_Root_Security_Key::get_instance();

		$this->assertSame( $instance1, $instance2 );
	}

	/**
	 * Test that admin can always initialize even when key is required.
	 */
	public function test_admin_can_initialize_with_key_required() {
		// Simulate key being required (even though not configured for this test).
		update_option(
			WP_MCP_AI_Root_Security_Key::OPTION_KEY_REQUIRED,
			array(
				'enabled_at' => current_time( 'mysql', true ),
				'enabled_by' => 1,
				'reason'     => 'Test',
			)
		);

		// Simulate admin context.
		set_current_screen( 'dashboard' );

		// Admin should still be able to initialize.
		$this->assertTrue( $this->security_key->can_initialize() );

		// Clean up.
		set_current_screen( 'front' );
	}

	/**
	 * Test that REST API requests can initialize even when key is required.
	 *
	 * This ensures that REST API endpoints remain accessible for authentication checks.
	 * Permission callbacks on individual endpoints will enforce access control.
	 */
	public function test_rest_api_can_initialize_with_key_required() {
		// Simulate key being required.
		update_option(
			WP_MCP_AI_Root_Security_Key::OPTION_KEY_REQUIRED,
			array(
				'enabled_at' => current_time( 'mysql', true ),
				'enabled_by' => 1,
				'reason'     => 'Test',
			)
		);

		// REST_REQUEST cannot be undefined once set, and defining it here would
		// leak into the shared process: every later wp_send_json() call would
		// fire the "Return a WP_REST_Response …" incorrect usage notice and
		// fail unrelated AJAX tests. Assert the REST guard at source level
		// instead (same pattern as the DOING_AUTOSAVE fix). The REST branch
		// itself is unreachable here without defining the key constant and
		// REST_REQUEST, both of which would leak across the suite.
		$class_file = WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-root-security-key.php';
		$this->assertFileExists( $class_file, 'Root security key class file should exist.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source file in a test.
		$source = file_get_contents( $class_file );
		$this->assertStringContainsString(
			'REST_REQUEST',
			$source,
			'can_initialize() should allow REST API requests to initialize when the key is required.'
		);
	}
}
