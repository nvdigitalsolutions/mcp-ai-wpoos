<?php
/**
 * Tests for WP_MCP_AI_Embedded_Client logging integration.
 *
 * Verifies that WP_MCP_AI_Logger::log_event() is called with the expected
 * event types at key points in the embedded client lifecycle (model download,
 * model deletion, binary download, connection test, and inference).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

/**
 * Embedded client logging integration tests.
 */
class Test_Embedded_Client_Logging extends WP_UnitTestCase {

	/**
	 * Embedded client instance under test.
	 *
	 * @var WP_MCP_AI_Embedded_Client
	 */
	private $client;

	/**
	 * Log entries captured via the wp_mcp_ai_log_entry filter.
	 *
	 * @var array[]
	 */
	private $captured_logs = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
			$pro_client_path = WP_MCP_AI_PATH . 'addons/pro/includes/class-wp-mcp-ai-embedded-client.php';
			if ( file_exists( $pro_client_path ) ) {
				require_once $pro_client_path;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Embedded_Client requires the Pro addon.' );
		}

		if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
		}

		$this->client        = new WP_MCP_AI_Embedded_Client();
		$this->captured_logs = array();

		// Enable logging so log_event() isn't gated.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_logging'        => true,
				'log_api_requests'      => true,
				'log_tool_executions'   => true,
				'log_chat_interactions' => true,
				'log_agentic_loop'      => true,
			)
		);

		// Capture all log entries via the filter instead of reading the error log.
		add_filter( 'wp_mcp_ai_log_entry', array( $this, 'capture_log_entry' ), 10, 4 );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'wp_mcp_ai_log_entry', array( $this, 'capture_log_entry' ), 10 );
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Filter callback that captures log entries for assertion.
	 *
	 * @param array  $entry   Prepared log entry.
	 * @param string $type    Event type.
	 * @param string $message Log message.
	 * @param array  $context Raw context.
	 * @return array Unmodified entry.
	 */
	public function capture_log_entry( $entry, $type, $message, $context ) {
		$this->captured_logs[] = array(
			'entry'   => $entry,
			'type'    => $type,
			'message' => $message,
			'context' => $context,
		);
		return $entry;
	}

	/**
	 * Helper: return all captured event types.
	 *
	 * @return string[]
	 */
	private function captured_event_types() {
		return array_column( $this->captured_logs, 'type' );
	}

	/**
	 * Helper: return captured logs filtered by event type.
	 *
	 * @param string $type Event type to filter by.
	 * @return array[]
	 */
	private function logs_for_type( $type ) {
		return array_filter(
			$this->captured_logs,
			function ( $log ) use ( $type ) {
				return $log['type'] === $type;
			}
		);
	}

	// =========================================================================
	// download_model() logging
	// =========================================================================

	/**
	 * Requesting download of an unknown model slug should emit
	 * embedded_model_download_error.
	 */
	public function test_download_model_unknown_slug_logs_error() {
		$result = $this->client->download_model( 'nonexistent-model-slug' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'embedded_model_download_error', $this->captured_event_types() );
	}

	/**
	 * When the model file already exists on disk no start log should be emitted
	 * (the method returns early with "already downloaded").
	 */
	public function test_download_model_already_downloaded_emits_no_start_log() {
		// Plant a dummy model file to trigger the early-return path.
		$models_dir = $this->client->get_models_directory();
		$models     = $this->client->get_available_models();
		reset( $models );
		$first_slug = key( $models );
		$filename   = $models[ $first_slug ]['filename'];
		$model_path = $models_dir . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$wrote = file_put_contents( $model_path, 'placeholder' );
		if ( false === $wrote ) {
			$this->markTestSkipped( 'Could not create placeholder model file in models directory.' );
		}

		$result = $this->client->download_model( $first_slug );

		// Clean up.
		@unlink( $model_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged

		// The early-return path does not log a start or success event.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertNotContains( 'embedded_model_download_start', $this->captured_event_types() );
		$this->assertNotContains( 'embedded_model_downloaded', $this->captured_event_types() );
	}

	// =========================================================================
	// delete_model() logging
	// =========================================================================

	/**
	 * Requesting deletion of an unknown model slug should emit
	 * embedded_model_delete_error.
	 */
	public function test_delete_model_unknown_slug_logs_error() {
		$result = $this->client->delete_model( 'nonexistent-model-slug' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'embedded_model_delete_error', $this->captured_event_types() );
	}

	/**
	 * Requesting deletion of a valid slug for a file that does not exist should
	 * emit embedded_model_delete_error.
	 */
	public function test_delete_model_file_not_found_logs_error() {
		$models = $this->client->get_available_models();
		reset( $models );
		$first_slug = key( $models );

		// Ensure the file does NOT exist.
		$models_dir = $this->client->get_models_directory();
		$model_path = $models_dir . $models[ $first_slug ]['filename'];
		if ( file_exists( $model_path ) ) {
			@unlink( $model_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$result = $this->client->delete_model( $first_slug );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'embedded_model_delete_error', $this->captured_event_types() );
	}

	/**
	 * Successfully deleting a model should emit embedded_model_deleted.
	 */
	public function test_delete_model_success_logs_event() {
		$models = $this->client->get_available_models();
		reset( $models );
		$first_slug = key( $models );
		$models_dir = $this->client->get_models_directory();
		$model_path = $models_dir . $models[ $first_slug ]['filename'];

		// Plant a dummy model file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$wrote = file_put_contents( $model_path, 'placeholder' );
		if ( false === $wrote ) {
			$this->markTestSkipped( 'Could not create placeholder model file in models directory.' );
		}

		$result = $this->client->delete_model( $first_slug );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertContains( 'embedded_model_deleted', $this->captured_event_types() );

		// Verify the log context includes the slug.
		$logs = array_values( $this->logs_for_type( 'embedded_model_deleted' ) );
		$this->assertNotEmpty( $logs );
		$this->assertSame( $first_slug, $logs[0]['context']['slug'] );
	}

	// =========================================================================
	// create_chat_completion() logging
	// =========================================================================

	/**
	 * Calling create_chat_completion() when no model is downloaded should emit
	 * embedded_inference_error.
	 */
	public function test_create_chat_completion_no_model_logs_error() {
		// Ensure all model files are absent.
		$models_dir = $this->client->get_models_directory();
		$models     = $this->client->get_available_models();
		foreach ( $models as $slug => $model ) {
			$path = $models_dir . $model['filename'];
			if ( file_exists( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		// Ensure the option does not direct to any downloaded model.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_logging'        => true,
				'embedded_server_model' => '',
			)
		);

		$result = $this->client->create_chat_completion(
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_no_embedded_model', $result->get_error_code() );
		$this->assertContains( 'embedded_inference_error', $this->captured_event_types() );
	}

	// =========================================================================
	// test_connection() logging
	// =========================================================================

	/**
	 * Calling test_connection() should log embedded_connection_test_error when the
	 * binary cannot be found.
	 */
	public function test_test_connection_no_binary_logs_error() {
		// Point to a non-existent binary so get_inference_binary() returns WP_Error.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_logging'       => true,
				'embedded_binary_path' => '/nonexistent/path/to/llama-cli',
			)
		);

		$client = new WP_MCP_AI_Embedded_Client();
		$result = $client->test_connection();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'embedded_connection_test_error', $this->captured_event_types() );
	}

	// =========================================================================
	// download_binary() logging
	// =========================================================================

	/**
	 * Calling download_binary() emits a log event appropriate to the outcome.
	 *
	 * On Linux, a download attempt begins (and will fail at the GitHub API step
	 * in a test environment), emitting either embedded_binary_download_start or
	 * embedded_binary_download_error.  On non-Linux platforms the method returns
	 * immediately with embedded_binary_download_error.
	 */
	public function test_download_binary_emits_log_event() {
		$uname = php_uname( 's' );

		if ( stripos( $uname, 'linux' ) !== false ) {
			// On Linux, the method will try to contact the GitHub API.
			// Regardless of whether the API call succeeds, at minimum the start
			// event should be captured.  If the network is unavailable an
			// error event will also be emitted.
			$result = $this->client->download_binary();

			// The result is either a WP_Error (most likely in test environments
			// without network access) or a success array.
			$logged_types = $this->captured_event_types();
			$has_start    = in_array( 'embedded_binary_download_start', $logged_types, true );
			$has_error    = in_array( 'embedded_binary_download_error', $logged_types, true );
			$has_success  = in_array( 'embedded_binary_downloaded', $logged_types, true );

			$this->assertTrue(
				$has_start || $has_error || $has_success,
				'download_binary() on Linux must emit at least one embedded_binary_download_* log event.'
			);
		} else {
			// On non-Linux platforms the method returns immediately with an error.
			$result = $this->client->download_binary();

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertContains( 'embedded_binary_download_error', $this->captured_event_types() );
		}
	}

	// =========================================================================
	// Logging disabled path
	// =========================================================================

	/**
	 * When logging is disabled no log entries should be captured even for
	 * error paths.
	 */
	public function test_no_logs_when_logging_disabled() {
		// Disable logging.
		update_option(
			'wp_mcp_ai_settings',
			array( 'enable_logging' => false )
		);

		$this->client->download_model( 'nonexistent-model-slug' );
		$this->client->delete_model( 'nonexistent-model-slug' );

		$this->assertEmpty(
			$this->captured_logs,
			'No log entries should be captured when logging is disabled.'
		);
	}
}
