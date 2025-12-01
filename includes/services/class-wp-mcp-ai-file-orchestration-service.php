<?php
/**
 * Generic File Orchestration Service (Abstract Base Class)
 *
 * Provides a provider-agnostic abstraction layer for file upload, status polling,
 * and cleanup operations across different AI providers (Gemini, OpenAI, etc.).
 *
 * This class follows the Template Method pattern to ensure consistent file handling
 * while allowing provider-specific implementations.
 *
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract File Orchestration Service
 *
 * Responsibilities:
 * - Abstract file upload workflow
 * - Abstract status polling workflow
 * - Abstract file cleanup workflow
 * - Common error handling
 * - Common logging patterns
 * - Provider-agnostic interface
 *
 * @since 1.0.0
 */
abstract class WP_MCP_AI_File_Orchestration_Service {

	/**
	 * Maximum polling attempts for file processing
	 *
	 * @var int
	 */
	protected $max_polling_attempts = 60;

	/**
	 * Delay between polling attempts in seconds
	 *
	 * @var int
	 */
	protected $polling_delay = 5;

	/**
	 * Provider name for logging
	 *
	 * @var string
	 */
	protected $provider_name = 'Generic';

	/**
	 * Constructor
	 *
	 * @param int $max_polling_attempts Maximum number of polling attempts.
	 * @param int $polling_delay        Delay between polls in seconds.
	 */
	public function __construct( $max_polling_attempts = 60, $polling_delay = 5 ) {
		$this->max_polling_attempts = absint( $max_polling_attempts );
		$this->polling_delay        = absint( $polling_delay );
	}

	/**
	 * Upload a file to the provider's API
	 *
	 * Template method that orchestrates the upload process.
	 *
	 * @param string $file_path    Local file path to upload.
	 * @param string $mime_type    MIME type of the file.
	 * @param array  $options      Additional options (display_name, purpose, etc.).
	 * @return array|WP_Error Upload result with file identifier and metadata, or error.
	 */
	public function upload_file( $file_path, $mime_type, array $options = array() ) {
		// Validate inputs.
		$validation = $this->validate_upload_inputs( $file_path, $mime_type, $options );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Get API key/credentials.
		$credentials = $this->get_api_credentials();
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		// Read file content.
		$file_content = $this->read_file_content( $file_path );
		if ( is_wp_error( $file_content ) ) {
			return $file_content;
		}

		// Prepare upload request.
		$request_data = $this->prepare_upload_request( $file_path, $file_content, $mime_type, $options, $credentials );
		if ( is_wp_error( $request_data ) ) {
			return $request_data;
		}

		// Log upload start.
		$this->log_upload_start( $file_path, $mime_type, $options );

		// Execute upload.
		$response = $this->execute_upload_request( $request_data );
		if ( is_wp_error( $response ) ) {
			$this->log_upload_error( $response );
			return $response;
		}

		// Parse upload response.
		$upload_result = $this->parse_upload_response( $response );
		if ( is_wp_error( $upload_result ) ) {
			$this->log_upload_error( $upload_result );
			return $upload_result;
		}

		// Log upload success.
		$this->log_upload_success( $upload_result );

		return $upload_result;
	}

	/**
	 * Get file status from the provider's API
	 *
	 * @param string $file_identifier Provider-specific file identifier.
	 * @return array|WP_Error File status information or error.
	 */
	public function get_file_status( $file_identifier ) {
		// Validate input.
		if ( empty( $file_identifier ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_file_identifier',
				__( 'File identifier is required.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Get API credentials.
		$credentials = $this->get_api_credentials();
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		// Prepare status request.
		$request_data = $this->prepare_status_request( $file_identifier, $credentials );
		if ( is_wp_error( $request_data ) ) {
			return $request_data;
		}

		// Execute status request.
		$response = $this->execute_status_request( $request_data );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Parse status response.
		return $this->parse_status_response( $response );
	}

	/**
	 * Wait for file processing to complete
	 *
	 * Template method for polling file status until completion.
	 *
	 * @param string $file_identifier Provider-specific file identifier.
	 * @param int    $timeout         Maximum time to wait in seconds.
	 * @return array|WP_Error File status when active, or error.
	 */
	public function wait_for_processing( $file_identifier, $timeout = 300 ) {
		$timeout      = absint( $timeout );
		$max_attempts = min( $this->max_polling_attempts, ceil( $timeout / $this->polling_delay ) );
		$attempt      = 0;
		$start_time   = time();

		$this->log_polling_start( $file_identifier, $timeout, $max_attempts );

		while ( $attempt < $max_attempts ) {
			++$attempt;

			// Check timeout.
			if ( ( time() - $start_time ) > $timeout ) {
				return $this->handle_polling_timeout( $file_identifier, $attempt, time() - $start_time );
			}

			// Get file status.
			$status = $this->get_file_status( $file_identifier );
			if ( is_wp_error( $status ) ) {
				return $status;
			}

			// Check if processing is complete.
			$state = $this->extract_processing_state( $status );
			if ( $this->is_processing_complete( $state ) ) {
				$this->log_polling_success( $file_identifier, $attempt, time() - $start_time );
				return $status;
			}

			// Check for failed state.
			if ( $this->is_processing_failed( $state ) ) {
				return $this->handle_processing_failure( $file_identifier, $state );
			}

			// Still processing, wait before next attempt.
			if ( $attempt < $max_attempts ) {
				sleep( $this->polling_delay );
			}
		}

		// Max attempts reached.
		return $this->handle_max_attempts_reached( $file_identifier, $attempt );
	}

	/**
	 * Delete a file from the provider's API
	 *
	 * @param string $file_identifier Provider-specific file identifier.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function delete_file( $file_identifier ) {
		// Validate input.
		if ( empty( $file_identifier ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_file_identifier',
				__( 'File identifier is required.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Get API credentials.
		$credentials = $this->get_api_credentials();
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		// Prepare delete request.
		$request_data = $this->prepare_delete_request( $file_identifier, $credentials );
		if ( is_wp_error( $request_data ) ) {
			return $request_data;
		}

		// Log deletion start.
		$this->log_delete_start( $file_identifier );

		// Execute delete request.
		$response = $this->execute_delete_request( $request_data );
		if ( is_wp_error( $response ) ) {
			$this->log_delete_error( $response );
			return $response;
		}

		// Verify deletion.
		$verified = $this->verify_deletion( $response );
		if ( is_wp_error( $verified ) ) {
			$this->log_delete_error( $verified );
			return $verified;
		}

		// Log deletion success.
		$this->log_delete_success( $file_identifier );

		return true;
	}

	// ========================================================================.
	// Abstract methods to be implemented by provider-specific subclasses.
	// ========================================================================.

	/**
	 * Get API credentials for the provider
	 *
	 * @return array|WP_Error Array with credentials or WP_Error.
	 */
	abstract protected function get_api_credentials();

	/**
	 * Prepare upload request data
	 *
	 * @param string $file_path    File path.
	 * @param string $file_content File content.
	 * @param string $mime_type    MIME type.
	 * @param array  $options      Upload options.
	 * @param array  $credentials  API credentials.
	 * @return array|WP_Error Request data or error.
	 */
	abstract protected function prepare_upload_request( $file_path, $file_content, $mime_type, array $options, array $credentials );

	/**
	 * Execute upload request
	 *
	 * @param array $request_data Request data.
	 * @return array|WP_Error HTTP response or error.
	 */
	abstract protected function execute_upload_request( array $request_data );

	/**
	 * Parse upload response
	 *
	 * @param array $response HTTP response.
	 * @return array|WP_Error Parsed upload result or error.
	 */
	abstract protected function parse_upload_response( array $response );

	/**
	 * Prepare status request data
	 *
	 * @param string $file_identifier File identifier.
	 * @param array  $credentials     API credentials.
	 * @return array|WP_Error Request data or error.
	 */
	abstract protected function prepare_status_request( $file_identifier, array $credentials );

	/**
	 * Execute status request
	 *
	 * @param array $request_data Request data.
	 * @return array|WP_Error HTTP response or error.
	 */
	abstract protected function execute_status_request( array $request_data );

	/**
	 * Parse status response
	 *
	 * @param array $response HTTP response.
	 * @return array|WP_Error Parsed status or error.
	 */
	abstract protected function parse_status_response( array $response );

	/**
	 * Prepare delete request data
	 *
	 * @param string $file_identifier File identifier.
	 * @param array  $credentials     API credentials.
	 * @return array|WP_Error Request data or error.
	 */
	abstract protected function prepare_delete_request( $file_identifier, array $credentials );

	/**
	 * Execute delete request
	 *
	 * @param array $request_data Request data.
	 * @return array|WP_Error HTTP response or error.
	 */
	abstract protected function execute_delete_request( array $request_data );

	/**
	 * Verify deletion was successful
	 *
	 * @param array $response HTTP response.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	abstract protected function verify_deletion( array $response );

	/**
	 * Extract processing state from status response
	 *
	 * @param array $status Status response.
	 * @return string Processing state.
	 */
	abstract protected function extract_processing_state( array $status );

	/**
	 * Check if processing is complete
	 *
	 * @param string $state Processing state.
	 * @return bool True if complete.
	 */
	abstract protected function is_processing_complete( $state );

	/**
	 * Check if processing failed
	 *
	 * @param string $state Processing state.
	 * @return bool True if failed.
	 */
	abstract protected function is_processing_failed( $state );

	// ========================================================================.
	// Common helper methods (with default implementations)
	// ========================================================================.

	/**
	 * Validate upload inputs
	 *
	 * @param string $file_path File path.
	 * @param string $mime_type MIME type.
	 * @param array  $options   Options.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	protected function validate_upload_inputs( $file_path, $mime_type, array $options ) {
		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'File not found on server.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		if ( empty( $mime_type ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_mime_type',
				__( 'MIME type is required for file upload.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Read file content from disk
	 *
	 * @param string $file_path File path.
	 * @return string|WP_Error File content or error.
	 */
	protected function read_file_content( $file_path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents.
		$file_content = file_get_contents( $file_path );

		if ( false === $file_content ) {
			return new WP_Error(
				'wp_mcp_ai_file_read_error',
				__( 'Failed to read file content.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		return $file_content;
	}

	/**
	 * Handle polling timeout
	 *
	 * @param string $file_identifier File identifier.
	 * @param int    $attempts        Number of attempts made.
	 * @param int    $elapsed         Elapsed time.
	 * @return WP_Error Timeout error.
	 */
	protected function handle_polling_timeout( $file_identifier, $attempts, $elapsed ) {
		WP_MCP_AI_Logger::log_error(
			sprintf( '%s file processing timeout.', $this->provider_name ),
			array(
				'file_identifier' => $file_identifier,
				'attempts'        => $attempts,
				'elapsed'         => $elapsed,
			)
		);

		return new WP_Error(
			'wp_mcp_ai_processing_timeout',
			__( 'File processing timed out. The file may still be processing.', 'wp-mcp-ai' ),
			array( 'status' => 408 )
		);
	}

	/**
	 * Handle processing failure
	 *
	 * @param string $file_identifier File identifier.
	 * @param string $state           Processing state.
	 * @return WP_Error Processing failure error.
	 */
	protected function handle_processing_failure( $file_identifier, $state ) {
		WP_MCP_AI_Logger::log_error(
			sprintf( '%s file processing failed.', $this->provider_name ),
			array(
				'file_identifier' => $file_identifier,
				'state'           => $state,
			)
		);

		return new WP_Error(
			'wp_mcp_ai_processing_failed',
			sprintf(
				/* translators: %s: provider name */
				__( 'File processing failed on %s servers.', 'wp-mcp-ai' ),
				$this->provider_name
			),
			array( 'status' => 500 )
		);
	}

	/**
	 * Handle max polling attempts reached
	 *
	 * @param string $file_identifier File identifier.
	 * @param int    $attempts        Number of attempts.
	 * @return WP_Error Max attempts error.
	 */
	protected function handle_max_attempts_reached( $file_identifier, $attempts ) {
		WP_MCP_AI_Logger::log_error(
			sprintf( '%s file processing max attempts reached.', $this->provider_name ),
			array(
				'file_identifier' => $file_identifier,
				'attempts'        => $attempts,
			)
		);

		return new WP_Error(
			'wp_mcp_ai_max_attempts_reached',
			__( 'Maximum polling attempts reached while waiting for file processing.', 'wp-mcp-ai' ),
			array( 'status' => 408 )
		);
	}

	// ========================================================================.
	// Logging methods.
	// ========================================================================.

	/**
	 * Log upload start
	 *
	 * @param string $file_path File path.
	 * @param string $mime_type MIME type.
	 * @param array  $options   Options.
	 */
	protected function log_upload_start( $file_path, $mime_type, array $options ) {
		WP_MCP_AI_Logger::log_event(
			strtolower( $this->provider_name ) . '_file_upload',
			sprintf( 'Uploading file to %s File API.', $this->provider_name ),
			array(
				'file_name' => basename( $file_path ),
				'mime_type' => $mime_type,
				'file_size' => file_exists( $file_path ) ? filesize( $file_path ) : 0,
			)
		);
	}

	/**
	 * Log upload success
	 *
	 * @param array $upload_result Upload result.
	 */
	protected function log_upload_success( array $upload_result ) {
		WP_MCP_AI_Logger::log_event(
			strtolower( $this->provider_name ) . '_file_uploaded',
			sprintf( 'File uploaded to %s File API successfully.', $this->provider_name ),
			$upload_result
		);
	}

	/**
	 * Log upload error
	 *
	 * @param WP_Error $error Error object.
	 */
	protected function log_upload_error( WP_Error $error ) {
		WP_MCP_AI_Logger::log_error(
			sprintf( '%s file upload failed.', $this->provider_name ),
			array( 'error' => $error->get_error_message() )
		);
	}

	/**
	 * Log polling start
	 *
	 * @param string $file_identifier File identifier.
	 * @param int    $timeout         Timeout.
	 * @param int    $max_attempts    Max attempts.
	 */
	protected function log_polling_start( $file_identifier, $timeout, $max_attempts ) {
		WP_MCP_AI_Logger::log_event(
			strtolower( $this->provider_name ) . '_file_polling_start',
			sprintf( 'Starting to poll for file processing completion on %s.', $this->provider_name ),
			array(
				'file_identifier' => $file_identifier,
				'timeout'         => $timeout,
				'max_attempts'    => $max_attempts,
			)
		);
	}

	/**
	 * Log polling success
	 *
	 * @param string $file_identifier File identifier.
	 * @param int    $attempts        Attempts.
	 * @param int    $elapsed         Elapsed time.
	 */
	protected function log_polling_success( $file_identifier, $attempts, $elapsed ) {
		WP_MCP_AI_Logger::log_event(
			strtolower( $this->provider_name ) . '_file_processing_complete',
			sprintf( 'File processing completed successfully on %s.', $this->provider_name ),
			array(
				'file_identifier' => $file_identifier,
				'attempts'        => $attempts,
				'elapsed'         => $elapsed,
			)
		);
	}

	/**
	 * Log delete start
	 *
	 * @param string $file_identifier File identifier.
	 */
	protected function log_delete_start( $file_identifier ) {
		WP_MCP_AI_Logger::log_event(
			strtolower( $this->provider_name ) . '_file_delete',
			sprintf( 'Deleting file from %s File API.', $this->provider_name ),
			array( 'file_identifier' => $file_identifier )
		);
	}

	/**
	 * Log delete success
	 *
	 * @param string $file_identifier File identifier.
	 */
	protected function log_delete_success( $file_identifier ) {
		WP_MCP_AI_Logger::log_event(
			strtolower( $this->provider_name ) . '_file_deleted',
			sprintf( 'File deleted from %s File API successfully.', $this->provider_name ),
			array( 'file_identifier' => $file_identifier )
		);
	}

	/**
	 * Log delete error
	 *
	 * @param WP_Error $error Error object.
	 */
	protected function log_delete_error( WP_Error $error ) {
		WP_MCP_AI_Logger::log_error(
			sprintf( '%s file deletion failed.', $this->provider_name ),
			array( 'error' => $error->get_error_message() )
		);
	}
}
