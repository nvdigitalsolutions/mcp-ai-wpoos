<?php
/**
 * Tests for Symfony Process Service integration
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Process_Service
 *
 * Tests for the Symfony Process service integration.
 */
class Test_WP_MCP_AI_Process_Service extends WP_UnitTestCase {

	/**
	 * Process service instance.
	 *
	 * @var WP_MCP_AI\Services\WP_MCP_AI_Process_Service
	 */
	private $process_service;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load process service.
		require_once dirname( __DIR__ ) . '/includes/services/class-wp-mcp-ai-process-service.php';
		$this->process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
	}

	/**
	 * Test that process service is a singleton.
	 */
	public function test_process_service_is_singleton() {
		$instance1 = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		$instance2 = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();

		$this->assertSame( $instance1, $instance2, 'Process service should be a singleton' );
	}

	/**
	 * Test basic command execution with run().
	 */
	public function test_run_basic_command() {
		$result = $this->process_service->run( array( 'echo', 'Hello World' ) );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'output', $result, 'Result should have output key' );
		$this->assertArrayHasKey( 'exit_code', $result, 'Result should have exit_code key' );
		$this->assertArrayHasKey( 'success', $result, 'Result should have success key' );
		$this->assertEquals( 0, $result['exit_code'], 'Exit code should be 0 for successful command' );
		$this->assertTrue( $result['success'], 'Command should be successful' );
		$this->assertStringContainsString( 'Hello World', $result['output'], 'Output should contain expected text' );
	}

	/**
	 * Test command execution with shell command line.
	 */
	public function test_run_shell_command() {
		$result = $this->process_service->run( 'echo "Test Output"' );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertEquals( 0, $result['exit_code'], 'Exit code should be 0' );
		$this->assertTrue( $result['success'], 'Command should be successful' );
		$this->assertStringContainsString( 'Test Output', $result['output'], 'Output should match' );
	}

	/**
	 * Test run_silent() doesn't throw exceptions on failure.
	 */
	public function test_run_silent_no_exception_on_failure() {
		// Command that will fail.
		$result = $this->process_service->run_silent( array( 'nonexistent-command-xyz' ), array( 'timeout' => 5 ) );

		$this->assertIsArray( $result, 'Result should be an array even on failure' );
		$this->assertArrayHasKey( 'exit_code', $result, 'Result should have exit_code' );
		$this->assertArrayHasKey( 'success', $result, 'Result should have success key' );
		$this->assertFalse( $result['success'], 'Command should not be successful' );
		$this->assertNotEquals( 0, $result['exit_code'], 'Exit code should be non-zero for failed command' );
	}

	/**
	 * Test timeout handling.
	 */
	public function test_timeout_handling() {
		// Command that sleeps longer than timeout.
		$result = $this->process_service->run( array( 'sleep', '5' ), array( 'timeout' => 1 ) );

		$this->assertInstanceOf( 'WP_Error', $result, 'Timeout should return WP_Error' );
		$this->assertEquals( 'process_timeout', $result->get_error_code(), 'Error code should be process_timeout' );
	}

	/**
	 * Test is_command_available() with existing command.
	 */
	public function test_is_command_available_existing() {
		// 'echo' should be available on all systems.
		$available = $this->process_service->is_command_available( 'echo' );

		$this->assertTrue( $available, 'echo command should be available' );
	}

	/**
	 * Test is_command_available() with non-existing command.
	 */
	public function test_is_command_available_nonexisting() {
		$available = $this->process_service->is_command_available( 'nonexistent-command-xyz-123' );

		$this->assertFalse( $available, 'Non-existing command should not be available' );
	}

	/**
	 * Test get_command_path() returns path for existing command.
	 */
	public function test_get_command_path_existing() {
		$path = $this->process_service->get_command_path( 'echo' );

		$this->assertIsString( $path, 'Path should be a string' );
		$this->assertNotEmpty( $path, 'Path should not be empty' );
		$this->assertStringContainsString( 'echo', $path, 'Path should contain command name' );
	}

	/**
	 * Test get_command_path() returns false for non-existing command.
	 */
	public function test_get_command_path_nonexisting() {
		$path = $this->process_service->get_command_path( 'nonexistent-command-xyz-123' );

		$this->assertFalse( $path, 'Path should be false for non-existing command' );
	}

	/**
	 * Test custom timeout setting.
	 */
	public function test_set_default_timeout() {
		$original_timeout = $this->process_service->get_default_timeout();

		$this->process_service->set_default_timeout( 120 );
		$this->assertEquals( 120, $this->process_service->get_default_timeout(), 'Timeout should be updated to 120' );

		// Restore original timeout.
		$this->process_service->set_default_timeout( $original_timeout );
	}

	/**
	 * Test error output capture.
	 */
	public function test_error_output_capture() {
		// Command that writes to stderr.
		$result = $this->process_service->run_silent( 'echo "Error message" >&2' );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'error', $result, 'Result should have error key' );
		// Note: stderr capture may vary by system, so we just check the key exists.
	}

	/**
	 * Test run_with_callback() executes callback.
	 */
	public function test_run_with_callback() {
		$output_chunks = array();
		$callback      = function ( $type, $buffer ) use ( &$output_chunks ) {
			$output_chunks[] = $buffer;
		};

		$result = $this->process_service->run_with_callback( array( 'echo', 'Test' ), $callback );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertTrue( $result['success'], 'Command should be successful' );
		$this->assertNotEmpty( $output_chunks, 'Callback should have received output chunks' );
	}

	/**
	 * Test process execution with custom working directory.
	 */
	public function test_run_with_custom_cwd() {
		$tmp_dir = sys_get_temp_dir();

		$result = $this->process_service->run( array( 'pwd' ), array( 'cwd' => $tmp_dir ) );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertTrue( $result['success'], 'Command should be successful' );
		// Output should contain the temp directory path.
		$this->assertStringContainsString( 'tmp', strtolower( $result['output'] ), 'Output should contain tmp directory' );
	}

	/**
	 * Test that failed run() returns WP_Error.
	 */
	public function test_run_failed_command_returns_wp_error() {
		// Command that will fail.
		$result = $this->process_service->run( array( 'false' ), array( 'timeout' => 5 ) );

		$this->assertInstanceOf( 'WP_Error', $result, 'Failed command should return WP_Error' );
		$this->assertEquals( 'process_failed', $result->get_error_code(), 'Error code should be process_failed' );
	}
}
