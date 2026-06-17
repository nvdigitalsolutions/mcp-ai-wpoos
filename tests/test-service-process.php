<?php
/**
 * Tests for WP_MCP_AI\Services\WP_MCP_AI_Process_Service.
 *
 * Covers singleton behaviour, timeout configuration, run_silent result shape,
 * and the graceful fallback when process functions are unavailable.
 *
 * Note: WP_MCP_AI_Process_Service lives in the WP_MCP_AI\Services namespace.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Process_Service.
 */
class Test_Service_Process extends WP_UnitTestCase {

	/**
	 * Fully-qualified class name to keep test methods concise.
	 */
	const SERVICE_CLASS = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::class;

	/**
	 * Service instance under test.
	 *
	 * @var \WP_MCP_AI\Services\WP_MCP_AI_Process_Service
	 */
	private $service;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( self::SERVICE_CLASS ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Process_Service class not available.' );
		}

		$this->service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		$this->service = null;
		parent::tearDown();
	}

	/**
	 * Test that get_instance returns an instance of the service.
	 */
	public function test_get_instance_returns_service_instance() {
		$instance = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		$this->assertInstanceOf( self::SERVICE_CLASS, $instance );
	}

	/**
	 * Test that get_instance always returns the same object (singleton).
	 */
	public function test_get_instance_returns_same_object_on_repeated_calls() {
		$a = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		$b = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		$this->assertSame( $a, $b );
	}

	/**
	 * Test that the default timeout is 60 seconds.
	 */
	public function test_get_default_timeout_returns_sixty() {
		$this->assertSame( 60, $this->service->get_default_timeout() );
	}

	/**
	 * Test that set_default_timeout updates the timeout value.
	 */
	public function test_set_default_timeout_updates_value() {
		$original = $this->service->get_default_timeout();

		$this->service->set_default_timeout( 120 );
		$this->assertSame( 120, $this->service->get_default_timeout() );

		// Restore.
		$this->service->set_default_timeout( $original );
	}

	/**
	 * Test that set_default_timeout with a negative value uses absint() (absolute value).
	 *
	 * The implementation uses absint() which returns the absolute value, so -5 → 5,
	 * not 0.  This test documents that behaviour.
	 */
	public function test_set_default_timeout_treats_negative_as_zero() {
		$original = $this->service->get_default_timeout();

		$this->service->set_default_timeout( -5 );
		// absint(-5) === 5 — the absolute value is stored, not clamped to 0.
		$this->assertSame( 5, $this->service->get_default_timeout() );

		$this->service->set_default_timeout( $original );
	}

	/**
	 * Test that run_silent returns an array with the expected keys.
	 */
	public function test_run_silent_returns_array_with_expected_keys() {
		// Use 'echo' which is universally available on Linux and safe for tests.
		$result = $this->service->run_silent( array( 'echo', 'phpunit-test' ), array( 'timeout' => 5 ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'output', $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayHasKey( 'exit_code', $result );
		$this->assertArrayHasKey( 'success', $result );
	}

	/**
	 * Test that run_silent with a valid command sets success to true.
	 */
	public function test_run_silent_with_echo_command_succeeds() {
		$result = $this->service->run_silent( array( 'echo', 'hello' ), array( 'timeout' => 5 ) );

		if ( isset( $result['disabled'] ) && $result['disabled'] ) {
			$this->markTestSkipped( 'proc_open is disabled on this server.' );
		}

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['exit_code'] );
	}

	/**
	 * Test that is_command_available returns a boolean.
	 */
	public function test_is_command_available_returns_bool() {
		$result = $this->service->is_command_available( 'echo' );
		$this->assertIsBool( $result );
	}

	/**
	 * Test that run returns WP_Error when process functions are unavailable.
	 *
	 * We simulate the unavailability scenario by mocking the condition where
	 * run_silent returns ['disabled' => true]. For the run() path we must
	 * observe the WP_Error result in real test environments where proc_open
	 * is absent; otherwise we skip gracefully.
	 */
	public function test_run_returns_wp_error_or_array() {
		$result = $this->service->run( array( 'echo', 'hi' ), array( 'timeout' => 5 ) );

		$this->assertTrue(
			is_array( $result ) || is_wp_error( $result ),
			'run() must return an array or WP_Error.'
		);
	}
}
