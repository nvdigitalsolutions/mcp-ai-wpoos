<?php
/**
 * Async Tool Executor Service
 *
 * Responsible for executing tools asynchronously via WordPress cron.
 * Handles result storage, retrieval, and lifecycle management.
 *
 * Separation of Concerns:
 * - This class ONLY executes tools and manages results
 * - Does NOT decide sync vs async (orchestrator handles that)
 * - Does NOT format results for UI (status service handles that)
 * - Does NOT manage cron scheduling directly (cron manager handles that)
 * - Delegates logging to logger service
 *
 * PHP Optimizations:
 * - Uses transients for result caching
 * - Lazy loads dependencies
 * - Minimal memory footprint
 * - Efficient database operations
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Async Tool Executor Service class
 *
 * Executes tools asynchronously and manages results.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Async_Executor {

	/**
	 * Prefix for result storage option names
	 *
	 * @var string
	 */
	const RESULT_OPTION_PREFIX = 'wp_mcp_ai_async_result_';

	/**
	 * Prefix for job metadata transient names
	 *
	 * @var string
	 */
	const METADATA_TRANSIENT_PREFIX = 'wp_mcp_ai_async_meta_';

	/**
	 * Default result expiration time (24 hours)
	 *
	 * @var int
	 */
	const DEFAULT_RESULT_EXPIRY = 86400;

	/**
	 * Maximum result size in bytes (1MB)
	 *
	 * @var int
	 */
	const MAX_RESULT_SIZE = 1048576;

	/**
	 * Cron hook name for async tool execution
	 *
	 * @var string
	 */
	const CRON_HOOK = 'wp_mcp_ai_async_tool_execution';

	/**
	 * Default timeout for async tool execution in cron context (in seconds)
	 *
	 * Set to 180 seconds (3 minutes) to accommodate long-running tools
	 * like video generation which typically takes 60-120 seconds.
	 *
	 * @var int
	 */
	const DEFAULT_TOOL_TIMEOUT = 180;

	/**
	 * Tool registry instance (lazy loaded)
	 *
	 * @var WP_MCP_AI_Tool_Registry|null
	 */
	protected $registry = null;

	/**
	 * Logger instance (lazy loaded)
	 *
	 * @var WP_MCP_AI_Logger|null
	 */
	protected $logger = null;

	/**
	 * Initialize the executor and register hooks
	 */
	public function init() {
		add_action( self::CRON_HOOK, array( $this, 'execute_async_tool' ), 10, 1 );

		// Cleanup expired results periodically.
		add_action( 'wp_mcp_ai_cleanup_async_results', array( $this, 'cleanup_expired_results' ) );

		// Schedule cleanup if not already scheduled.
		if ( ! wp_next_scheduled( 'wp_mcp_ai_cleanup_async_results' ) ) {
			$cleanup_timestamp = time();
			wp_schedule_event( $cleanup_timestamp, 'hourly', 'wp_mcp_ai_cleanup_async_results' );

			// Record cleanup cron job in cron manager for visibility.
			if ( class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
				WP_MCP_AI_Cron_Manager::record_job(
					'wp_mcp_ai_cleanup_async_results',
					array(),
					'hourly',
					$cleanup_timestamp,
					0 // System-created job.
				);
			}
		}
	}

	/**
	 * Queue a tool for async execution
	 *
	 * @param string $tool_slug Tool slug to execute.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context Execution context.
	 * @return string|WP_Error Job ID on success, WP_Error on failure.
	 */
	public function queue_tool( $tool_slug, array $arguments = array(), array $context = array() ) {
		$tool_slug = sanitize_key( $tool_slug );

		if ( empty( $tool_slug ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_tool', __( 'Tool slug is required.', 'wp-mcp-ai' ) );
		}

		// Generate unique job ID.
		$job_id = $this->generate_job_id( $tool_slug, $arguments, $context );

		// Check if job already exists and is not expired.
		$existing_result = $this->get_result( $job_id );
		if ( ! is_wp_error( $existing_result ) && isset( $existing_result['status'] ) ) {
			if ( 'pending' === $existing_result['status'] || 'running' === $existing_result['status'] ) {
				// Job already queued or running.
				return $job_id;
			}
		}

		// Store initial job metadata.
		$metadata = array(
			'job_id'       => $job_id,
			'tool_slug'    => $tool_slug,
			'arguments'    => $arguments,
			'context'      => $this->sanitize_context( $context ),
			'status'       => 'pending',
			'queued_at'    => time(),
			'started_at'   => null,
			'completed_at' => null,
			'result'       => null,
			'error'        => null,
		);

		// Store metadata (use transient for quick access).
		$this->save_metadata( $job_id, $metadata );

		// Schedule cron job with a 20-second delay to ensure metadata and recording complete first.
		// This prevents race condition where cron executes before transient is saved or job is recorded.
		// The delay also accounts for potential delays in agentic workflows where the chat client
		// expects to receive a response after the job completes.
		$timestamp = time() + 20;
		$scheduled = wp_schedule_single_event(
			$timestamp,
			self::CRON_HOOK,
			array( $job_id )
		);

		if ( false === $scheduled ) {
			$this->delete_metadata( $job_id );
			$this->log_error(
				'Failed to schedule async tool execution via wp_schedule_single_event',
				array(
					'job_id'    => $job_id,
					'tool_slug' => $tool_slug,
					'timestamp' => $timestamp,
					'cron_hook' => self::CRON_HOOK,
				)
			);
			return new WP_Error( 'wp_mcp_ai_schedule_failed', __( 'Failed to schedule async tool execution.', 'wp-mcp-ai' ) );
		}

		// Record cron job in cron manager for visibility and management.
		$cron_recorded = false;
		if ( class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
			$user_id               = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
			$cron_recording_result = WP_MCP_AI_Cron_Manager::record_job(
				self::CRON_HOOK,
				array( $job_id ),
				'single',
				$timestamp,
				$user_id
			);
			// record_job() returns a job ID string on success.
			$cron_recorded = is_string( $cron_recording_result ) && ! empty( $cron_recording_result );
		}

		// Trigger WordPress cron immediately to ensure the async tool execution runs.
		// WordPress cron is virtual and only runs on page loads by default.
		// Calling spawn_cron() ensures the job executes even if no subsequent page loads occur.
		spawn_cron();

		// Log queuing event with detailed context for debugging.
		$this->log_event(
			'async_tool_queued',
			sprintf( 'Tool %s queued for async execution', $tool_slug ),
			array(
				'job_id'        => $job_id,
				'tool_slug'     => $tool_slug,
				'scheduled_at'  => $timestamp,
				'cron_recorded' => $cron_recorded,
				'assistant_id'  => isset( $context['assistant_id'] ) ? $context['assistant_id'] : null,
				'session_id'    => isset( $context['session_id'] ) ? $context['session_id'] : null,
			)
		);

		// Fire job started hook to notify the chat client that the async job has been created.
		// This allows the UI to display the job_id and status to the user immediately.
		do_action(
			'wp_mcp_ai_job_started',
			$job_id,
			array(
				'tool'   => $tool_slug,
				'status' => 'pending',
			)
		);

		return $job_id;
	}

	/**
	 * Execute an async tool (cron callback)
	 *
	 * @param string $job_id Job identifier.
	 */
	public function execute_async_tool( $job_id ) {
		$job_id = sanitize_key( $job_id );

		if ( empty( $job_id ) ) {
			return;
		}

		// Retrieve job metadata.
		$metadata = $this->get_metadata( $job_id );

		if ( ! $metadata || ! isset( $metadata['tool_slug'] ) ) {
			$this->log_error( 'Async tool job metadata not found', array( 'job_id' => $job_id ) );
			return;
		}

		// Update status to running.
		$metadata['status']     = 'running';
		$metadata['started_at'] = time();
		$this->save_metadata( $job_id, $metadata );

		$tool_slug = $metadata['tool_slug'];
		$arguments = isset( $metadata['arguments'] ) ? $metadata['arguments'] : array();
		$context   = isset( $metadata['context'] ) ? $metadata['context'] : array();

		// Multisite support: Switch to the correct blog context if running in multisite.
		// This ensures that file paths, attachment lookups, and other blog-specific operations
		// work correctly when the async tool runs via WP-Cron.
		$switched_blog = false;
		if ( is_multisite() && isset( $context['blog_id'] ) ) {
			$target_blog_id  = absint( $context['blog_id'] );
			$current_blog_id = get_current_blog_id();

			if ( $target_blog_id > 0 && $target_blog_id !== $current_blog_id ) {
				switch_to_blog( $target_blog_id );
				$switched_blog = true;

				$this->log_event(
					'async_tool_switched_blog',
					sprintf( 'Switched to blog %d for async tool execution', $target_blog_id ),
					array(
						'job_id'       => $job_id,
						'tool_slug'    => $tool_slug,
						'from_blog_id' => $current_blog_id,
						'to_blog_id'   => $target_blog_id,
					)
				);
			}
		}

		// Add flag to context indicating this tool is running in async executor.
		// This prevents double-async execution (e.g., video generation tool
		// queueing its own async job when already running in async context).
		$context['in_async_executor'] = true;

		// Add parent job ID to context so nested async jobs (like veo video generation)
		// can complete the parent job when they finish.
		$context['parent_job_id'] = $job_id;

		// Log execution start.
		$this->log_event(
			'async_tool_started',
			sprintf( 'Tool %s async execution started', $tool_slug ),
			array(
				'job_id'    => $job_id,
				'tool_slug' => $tool_slug,
			)
		);

		// Get tool instance.
		$registry = $this->get_registry();
		$tool     = $registry ? $registry->get_tool( $tool_slug ) : null;

		if ( ! $tool ) {
			$error_message = sprintf( 'Tool %s not found', $tool_slug );
			$this->handle_execution_error( $job_id, $metadata, $error_message );

			// Restore blog context if switched.
			if ( $switched_blog ) {
				restore_current_blog();
			}
			return;
		}

		// Execute tool.
		try {
			// Set execution time limit for async tool execution in cron context.
			// Video generation can take 60-120 seconds, so we need to allow sufficient time.
			// This is safe in cron context as it won't affect the main HTTP request.
			$tool_timeout = self::DEFAULT_TOOL_TIMEOUT;

			// Apply filter to allow customization per tool.
			$tool_timeout = apply_filters( 'wp_mcp_ai_async_tool_timeout', $tool_timeout, $tool_slug, $job_id );

			// Set timeout if function exists. Some hosting environments disable set_time_limit
			// for security reasons (safe mode, disable_functions in php.ini).
			// Silencing errors because set_time_limit may trigger:
			// - Warning when disabled in php.ini (disable_functions)
			// - Warning when safe mode is enabled
			// - Warning when running as Apache module with certain configurations
			// These warnings are expected and can be safely ignored as we're providing
			// a best-effort timeout extension for long-running tools.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( $tool_timeout ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			$start_time = microtime( true );
			$result     = $tool->execute( $arguments, $context );
			$duration   = microtime( true ) - $start_time;

			if ( is_wp_error( $result ) ) {
				$this->handle_execution_error( $job_id, $metadata, $result->get_error_message(), $result );

				// Restore blog context if switched.
				if ( $switched_blog ) {
					restore_current_blog();
				}
				return;
			}

			// Check if the tool returned a nested async response.
			// This happens when a tool (like veo video generation) falls back to its own async mode
			// due to timeout or other reasons. In this case, the parent job should be marked as
			// 'delegated' rather than 'completed', and the nested job will complete the parent later.
			//
			// EXCEPTION: If the returned job_id matches the parent job_id, it means the tool
			// (e.g., veo video generation) is reusing the parent job ID directly. In this case,
			// the veo service will manage the job polling and update this transient directly.
			// We should NOT mark it as delegated - just return and let veo handle it.
			$is_nested_async = is_array( $result )
				&& isset( $result['async'] )
				&& true === $result['async']
				&& isset( $result['job_id'] );

			if ( $is_nested_async ) {
				$nested_job_id = $result['job_id'];

				// Check if the tool is reusing the parent job ID (unified job flow).
				// When use_parent_job is true in veo service, it returns the same job_id we passed.
				if ( $nested_job_id === $job_id ) {
					// Veo is managing this job directly - don't mark as delegated.
					// The veo polling cron will update this transient when complete.
					$this->log_event(
						'async_tool_unified_job',
						sprintf( 'Tool %s is using unified job flow with same job ID', $tool_slug ),
						array(
							'job_id'    => $job_id,
							'tool_slug' => $tool_slug,
							'duration'  => $duration,
						)
					);

					// IMPORTANT: Refresh metadata from transient before updating.
					// The veo service has already merged its fields (operation_name, model, etc.)
					// into the transient. If we use our stale $metadata copy and save it,
					// we would overwrite those veo-specific fields, causing the veo polling
					// to fail with "metadata not found" error.
					$fresh_metadata = $this->get_metadata( $job_id );
					if ( $fresh_metadata && is_array( $fresh_metadata ) ) {
						// Use fresh metadata from transient (includes veo's merged fields).
						$metadata = $fresh_metadata;
					}

					// Update status to 'polling' to indicate veo is now polling for this job.
					$metadata['status']   = 'polling';
					$metadata['duration'] = $duration;
					$this->save_metadata( $job_id, $metadata );

					// Fire job started hook with polling status.
					do_action(
						'wp_mcp_ai_job_started',
						$job_id,
						array(
							'tool'    => $tool_slug,
							'status'  => 'polling',
							'message' => isset( $result['message'] ) ? $result['message'] : '',
						)
					);

					// Return without marking as delegated or completed.
					// Veo cron will update this job when video generation finishes.

					// Restore blog context if switched.
					if ( $switched_blog ) {
						restore_current_blog();
					}
					return;
				}

				// Different job ID - this is a true delegation to a nested job.
				// Tool returned a nested async response.
				// Update parent job to 'delegated' status and store the nested job info.
				$metadata['status']       = 'delegated';
				$metadata['delegated_at'] = time();
				$metadata['delegated_to'] = $result['job_id']; // The nested veo job ID.
				$metadata['result']       = $this->compress_result( $result );
				$metadata['duration']     = $duration;
				$metadata['error']        = null;

				$this->save_metadata( $job_id, $metadata );

				// Log delegation.
				$this->log_event(
					'async_tool_delegated',
					sprintf( 'Tool %s delegated to nested async job %s', $tool_slug, $result['job_id'] ),
					array(
						'parent_job_id' => $job_id,
						'nested_job_id' => $result['job_id'],
						'tool_slug'     => $tool_slug,
						'duration'      => $duration,
					)
				);

				// Fire job started hook to notify the chat client that the job is now in progress.
				// The nested job (veo_xxx) will call complete_parent_job() when it finishes,
				// which will update this parent job to 'completed' and fire wp_mcp_ai_job_completed.
				do_action(
					'wp_mcp_ai_job_started',
					$job_id,
					array(
						'tool'         => $tool_slug,
						'delegated_to' => $result['job_id'],
						'status'       => isset( $result['status'] ) ? $result['status'] : 'pending',
						'message'      => isset( $result['message'] ) ? $result['message'] : '',
					)
				);

				// Don't fire job_completed or after_tool_execution here.
				// The nested job will handle that when it completes.

				// Restore blog context if switched.
				if ( $switched_blog ) {
					restore_current_blog();
				}
				return;
			}

			// Normal completion - tool returned a final result (not a nested async response).
			$metadata['status']       = 'completed';
			$metadata['completed_at'] = time();
			$metadata['result']       = $this->compress_result( $result );
			$metadata['duration']     = $duration;
			$metadata['error']        = null;

			$this->save_metadata( $job_id, $metadata );

			// Log completion.
			$this->log_event(
				'async_tool_completed',
				sprintf( 'Tool %s async execution completed in %.2fs', $tool_slug, $duration ),
				array(
					'job_id'    => $job_id,
					'tool_slug' => $tool_slug,
					'duration'  => $duration,
				)
			);

			// Fire job completed hook for notification system.
			// This allows the chat client to receive completion notifications via SSE/polling
			// for any tool that runs through the async executor.
			do_action(
				'wp_mcp_ai_job_completed',
				$job_id,
				$result,
				array(
					'tool'     => $tool_slug,
					'duration' => $duration,
					'context'  => isset( $context['assistant_id'] ) ? array( 'assistant_id' => $context['assistant_id'] ) : array(),
				)
			);

			// Fire tool execution hook for token tracking and orchestration.
			// This ensures async tools are properly tracked by the token manager,
			// enabling proper orchestration and agentic loop completion for tools
			// with media-only responses (like veo video generation).
			// NOTE: This must fire AFTER the result is stored but BEFORE job cleanup,
			// so token usage can be recorded for the completed async job.
			do_action( 'wp_mcp_ai_after_tool_execution', $tool_slug, $arguments, $context, $result );

		} catch ( Exception $e ) {
			$this->handle_execution_error( $job_id, $metadata, $e->getMessage() );
		}

		// Restore blog context if switched.
		if ( $switched_blog ) {
			restore_current_blog();
		}
	}

	/**
	 * Handle tool execution error
	 *
	 * @param string        $job_id Job identifier.
	 * @param array         $metadata Job metadata.
	 * @param string        $error_message Error message.
	 * @param WP_Error|null $error WP_Error object if available.
	 */
	protected function handle_execution_error( $job_id, $metadata, $error_message, $error = null ) {
		$metadata['status']       = 'failed';
		$metadata['completed_at'] = time();
		$metadata['error']        = $error_message;

		if ( $error instanceof WP_Error && $error->get_error_data() ) {
			$metadata['error_data'] = $error->get_error_data();
		}

		$this->save_metadata( $job_id, $metadata );

		$this->log_error(
			sprintf( 'Tool %s async execution failed: %s', $metadata['tool_slug'], $error_message ),
			array(
				'job_id'    => $job_id,
				'tool_slug' => $metadata['tool_slug'],
				'error'     => $error_message,
			)
		);

		// Fire job failed hook for notification system.
		// This allows the chat client to receive failure notifications via SSE/polling
		// for any tool that runs through the async executor.
		$wp_error = $error instanceof WP_Error ? $error : new WP_Error( 'async_tool_failed', $error_message );
		do_action(
			'wp_mcp_ai_job_failed',
			$job_id,
			$wp_error,
			array(
				'tool'  => isset( $metadata['tool_slug'] ) ? $metadata['tool_slug'] : 'unknown',
				'error' => $error_message,
			)
		);
	}

	/**
	 * Get result for a job
	 *
	 * @param string $job_id Job identifier.
	 * @return array|WP_Error Result data or error.
	 */
	public function get_result( $job_id ) {
		$job_id = sanitize_key( $job_id );

		if ( empty( $job_id ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_job', __( 'Job ID is required.', 'wp-mcp-ai' ) );
		}

		$metadata = $this->get_metadata( $job_id );

		if ( ! $metadata ) {
			return new WP_Error( 'wp_mcp_ai_job_not_found', __( 'Job not found or expired.', 'wp-mcp-ai' ) );
		}

		// Decompress result if needed.
		if ( isset( $metadata['result'] ) && is_array( $metadata['result'] ) ) {
			$decompressed = $this->decompress_result( $metadata['result'] );

			// If decompression failed but job was completed, return error.
			if ( null === $decompressed && isset( $metadata['status'] ) && 'completed' === $metadata['status'] ) {
				$this->log_error(
					'Failed to decompress completed job result',
					array(
						'job_id'    => $job_id,
						'tool_slug' => isset( $metadata['tool_slug'] ) ? $metadata['tool_slug'] : 'unknown',
					)
				);
				return new WP_Error(
					'wp_mcp_ai_decompression_failed',
					__( 'Failed to retrieve job result. The result may be corrupted.', 'wp-mcp-ai' )
				);
			}

			$metadata['result'] = $decompressed;
		}

		return $metadata;
	}

	/**
	 * Generate unique job ID
	 *
	 * @param string $tool_slug Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context Execution context.
	 * @return string Job ID.
	 */
	protected function generate_job_id( $tool_slug, array $arguments, array $context ) {
		$data = array(
			'tool'      => $tool_slug,
			'arguments' => $arguments,
			'context'   => $this->sanitize_context( $context ),
			'time'      => microtime( true ),
			'rand'      => wp_rand( 1000, 9999 ),
		);

		return 'async_' . substr( md5( wp_json_encode( $data ) ), 0, 16 );
	}

	/**
	 * Sanitize context to remove sensitive data
	 *
	 * @param array $context Execution context.
	 * @return array Sanitized context.
	 */
	protected function sanitize_context( array $context ) {
		$safe_context = array();

		// Allow tool_call_id to preserve original LLM tool call correlation.
		// This enables proper async result correlation in the chat client.
		// Allow blog_id to ensure multisite context is preserved when executing via cron.
		$allowed_keys = array( 'user_id', 'assistant_id', 'session_id', 'tool_call_id', 'blog_id' );

		foreach ( $allowed_keys as $key ) {
			if ( isset( $context[ $key ] ) ) {
				$safe_context[ $key ] = $context[ $key ];
			}
		}

		// Ensure blog_id is always set for multisite support.
		// This allows async tool execution to switch to the correct blog context.
		if ( ! isset( $safe_context['blog_id'] ) && is_multisite() ) {
			$safe_context['blog_id'] = get_current_blog_id();
		}

		return $safe_context;
	}

	/**
	 * Save job metadata (optimized with transient)
	 *
	 * @param string $job_id Job identifier.
	 * @param array  $metadata Metadata to save.
	 * @return bool Success status.
	 */
	protected function save_metadata( $job_id, array $metadata ) {
		$transient_key = self::METADATA_TRANSIENT_PREFIX . $job_id;

		// Use transient for fast access (expires in 24 hours by default).
		$expiry = self::DEFAULT_RESULT_EXPIRY;

		/**
		 * Filter async result expiration time.
		 *
		 * @param int    $expiry  Expiration in seconds.
		 * @param string $job_id  Job identifier.
		 * @param array  $metadata Job metadata.
		 */
		$expiry = apply_filters( 'wp_mcp_ai_async_result_expiry', $expiry, $job_id, $metadata );

		return set_transient( $transient_key, $metadata, $expiry );
	}

	/**
	 * Get job metadata (from transient cache)
	 *
	 * @param string $job_id Job identifier.
	 * @return array|false Metadata or false if not found.
	 */
	protected function get_metadata( $job_id ) {
		$transient_key = self::METADATA_TRANSIENT_PREFIX . $job_id;
		return get_transient( $transient_key );
	}

	/**
	 * Delete job metadata
	 *
	 * @param string $job_id Job identifier.
	 * @return bool Success status.
	 */
	protected function delete_metadata( $job_id ) {
		$transient_key = self::METADATA_TRANSIENT_PREFIX . $job_id;
		return delete_transient( $transient_key );
	}

	/**
	 * Compress large result data to reduce memory footprint
	 *
	 * @param mixed $result Result data.
	 * @return array Compressed result metadata.
	 */
	protected function compress_result( $result ) {
		$serialized = maybe_serialize( $result );
		$size       = strlen( $serialized );

		// If result is too large, compress it.
		if ( $size > self::MAX_RESULT_SIZE / 10 ) {
			if ( function_exists( 'gzcompress' ) ) {
				$compressed = gzcompress( $serialized, 6 );

				if ( $compressed && strlen( $compressed ) < $size ) {
					return array(
						'compressed'    => true,
						'data'          => base64_encode( $compressed ),
						'original_size' => $size,
					);
				}
			}
		}

		return array(
			'compressed'    => false,
			'data'          => $result,
			'original_size' => $size,
		);
	}

	/**
	 * Decompress result data
	 *
	 * @param array $compressed_result Compressed result metadata.
	 * @return mixed Original result data.
	 */
	protected function decompress_result( $compressed_result ) {
		if ( ! isset( $compressed_result['compressed'] ) || ! $compressed_result['compressed'] ) {
			return isset( $compressed_result['data'] ) ? $compressed_result['data'] : null;
		}

		if ( ! isset( $compressed_result['data'] ) ) {
			$this->log_error( 'Compressed result missing data field', array( 'result' => $compressed_result ) );
			return null;
		}

		if ( ! function_exists( 'gzuncompress' ) ) {
			$this->log_error( 'gzuncompress function not available for decompression', array() );
			return null;
		}

		$decoded = base64_decode( $compressed_result['data'], true );
		if ( false === $decoded ) {
			$this->log_error( 'Failed to base64 decode compressed result', array() );
			return null;
		}

		$uncompressed = gzuncompress( $decoded );

		if ( false === $uncompressed ) {
			$this->log_error( 'Failed to gzuncompress result data', array( 'original_size' => $compressed_result['original_size'] ?? 'unknown' ) );
			return null;
		}

		$result = maybe_unserialize( $uncompressed );

		// Log successful decompression for debugging.
		$this->log_event(
			'async_result_decompressed',
			'Successfully decompressed async tool result',
			array(
				'original_size'     => $compressed_result['original_size'] ?? 0,
				'decompressed_size' => strlen( $uncompressed ),
			)
		);

		return $result;
	}

	/**
	 * Cleanup expired results (cron callback)
	 *
	 * Optimization: Batch delete to reduce database overhead.
	 */
	public function cleanup_expired_results() {
		global $wpdb;

		// Find expired transients.
		$prefix = self::METADATA_TRANSIENT_PREFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$expired = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} 
				WHERE option_name LIKE %s 
				AND option_value < %d 
				LIMIT 100",
				$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%',
				time()
			)
		);

		if ( empty( $expired ) ) {
			return;
		}

		$count = 0;
		foreach ( $expired as $timeout_key ) {
			$transient_key = str_replace( '_transient_timeout_', '', $timeout_key );
			delete_transient( str_replace( '_transient_', '', $transient_key ) );
			++$count;
		}

		$this->log_event(
			'async_results_cleanup',
			sprintf( 'Cleaned up %d expired async results', $count ),
			array( 'count' => $count )
		);
	}

	/**
	 * Get tool registry instance (lazy loaded)
	 *
	 * @return WP_MCP_AI_Tool_Registry|null
	 */
	protected function get_registry() {
		if ( null === $this->registry ) {
			if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
				$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
				$this->registry->init(); // Ensure registry is initialized during cron execution.
			}
		}

		return $this->registry;
	}

	/**
	 * Log event (delegates to logger)
	 *
	 * @param string $type Event type.
	 * @param string $message Event message.
	 * @param array  $context Event context.
	 */
	protected function log_event( $type, $message, $context = array() ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event( $type, $message, $context );
		}
	}

	/**
	 * Log error (delegates to logger)
	 *
	 * @param string $message Error message.
	 * @param array  $context Error context.
	 */
	protected function log_error( $message, $context = array() ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_error( $message, $context );
		}
	}
}
