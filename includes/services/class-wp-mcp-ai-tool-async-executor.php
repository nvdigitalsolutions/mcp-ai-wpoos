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
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-inline-async-tick.php';

/**
 * Async Tool Executor Service class
 *
 * Executes tools asynchronously and manages results.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Async_Executor {

	use WP_MCP_AI_Inline_Async_Tick_Trait;

	/**
	 * Cooperative tick-lock prefix for the per-job lock.
	 *
	 * Prevents an inline shutdown worker and a (possibly delayed)
	 * WP-Cron loopback from invoking `execute_async_tool()` for the
	 * same job concurrently.
	 *
	 * @var string
	 */
	const TICK_LOCK_PREFIX = 'wp_mcp_ai_async_exec_lock_';

	/**
	 * Object-cache group used by the cooperative tick lock.
	 *
	 * @var string
	 */
	const TICK_LOCK_CACHE_GROUP = 'wp_mcp_ai_async_executor';

	/**
	 * Tick-lock TTL in seconds. Must comfortably exceed
	 * {@see DEFAULT_TOOL_TIMEOUT} so a healthy long-running tool does
	 * not hit lock expiry mid-execution.
	 *
	 * @var int
	 */
	const TICK_LOCK_TTL = 300;

	/**
	 * Minimum age (in seconds) that a `pending` async job must reach
	 * before the REST poll endpoint is allowed to dispatch a
	 * self-healing inline kick. Avoids racing the initial enqueue
	 * request and its own shutdown handler.
	 *
	 * @var int
	 */
	const STALE_PENDING_THRESHOLD_SECONDS = 5;

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
			return new WP_Error( 'wp_mcp_ai_invalid_tool', __( 'Tool slug is required.', 'mcp-ai-wpoos' ) );
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

		// Store metadata (use transient for quick access). This must happen before
		// scheduling the cron event so the handler can read it as soon as it fires.
		$this->save_metadata( $job_id, $metadata );

		// Record cron job in cron manager for visibility and management BEFORE scheduling
		// the event. Recording first eliminates the race where the cron handler could fire
		// before the cron-manager record exists (which previously motivated a 20s schedule
		// delay). The cron handler itself reads metadata from the transient above and does
		// not depend on the cron-manager record, but downstream visibility consumers
		// (e.g. the /cron-status endpoint) do.
		$timestamp     = time() - 1;
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

		// Schedule the cron event in the immediate past so wp_get_ready_cron_jobs()
		// returns it and spawn_cron() can actually trigger execution. Previously this
		// was scheduled 20 seconds in the future, which guaranteed spawn_cron() failed
		// (it only spawns events whose timestamp is already <= time()) and left the
		// chat client waiting on a job that would only run on the next page load or
		// SSE heartbeat.
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
			return new WP_Error( 'wp_mcp_ai_schedule_failed', __( 'Failed to schedule async tool execution.', 'mcp-ai-wpoos' ) );
		}

		// Trigger WordPress cron immediately to ensure the async tool execution runs.
		// WordPress cron is virtual and only runs on page loads by default. Because the
		// event timestamp above is already due, spawn_cron() can now successfully fire
		// the wp-cron.php request that runs the handler. A false return here is not an
		// error condition: it usually just means another cron spawn is already in
		// progress (DOING_CRON lock) and our event will be picked up by that run or by
		// the next request, so we record it as a normal info-level event.
		$spawn_result = function_exists( 'spawn_cron' ) ? spawn_cron() : false;
		if ( false === $spawn_result ) {
			$this->log_event(
				'async_tool_spawn_cron_skipped',
				'spawn_cron() returned false; async job will run on the next cron tick or page load',
				array(
					'job_id'    => $job_id,
					'tool_slug' => $tool_slug,
				)
			);
		}

		// Inline-async fallback: register a `shutdown` action that re-checks the
		// job state once the current HTTP response is flushed and runs the tick
		// in this same PHP process if the cron loopback hasn't already drained
		// it. Without this, async tools sit at `status: pending` indefinitely on
		// hosts where `DISABLE_WP_CRON = true` or where `wp-cron.php` cannot be
		// reached via loopback HTTP. The cooperative tick lock inside
		// `execute_async_tool()` prevents double-execution if cron does fire
		// later. Mirrors the pattern from PR #4916 for the Mine Memories job.
		if ( self::inline_async_kick_enabled( $job_id, __CLASS__ ) ) {
			$executor = $this;
			add_action(
				'shutdown',
				static function () use ( $executor, $job_id ) {
					$executor->kick_inline( $job_id );
				},
				20
			);
		}

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
	 * Wraps {@see execute_async_tool_locked()} with a cooperative
	 * tick lock so an inline shutdown worker and a delayed cron
	 * loopback cannot double-process the same job.
	 *
	 * @param string $job_id Job identifier.
	 */
	public function execute_async_tool( $job_id ) {
		$job_id = sanitize_key( $job_id );

		if ( empty( $job_id ) ) {
			return;
		}

		$lock_key = self::TICK_LOCK_PREFIX . $job_id;
		if ( ! self::inline_async_acquire_tick_lock( $lock_key, self::TICK_LOCK_CACHE_GROUP, self::TICK_LOCK_TTL ) ) {
			// Another worker (a delayed cron loopback or a parallel
			// shutdown handler) is already inside execute_async_tool()
			// for this job. Bail — that worker will save fresh metadata
			// when it exits.
			return;
		}

		try {
			$this->execute_async_tool_locked( $job_id );
		} finally {
			self::inline_async_release_tick_lock( $lock_key, self::TICK_LOCK_CACHE_GROUP );
		}
	}

	/**
	 * Run the inline-async fallback for a queued job.
	 *
	 * Used by the `shutdown` action registered in {@see queue_tool()}
	 * and by the self-healing branch of the REST cron-status endpoint
	 * via {@see kick_inline_if_stale()}. Flushes the active HTTP
	 * response (FastCGI), detaches the worker from the client, and
	 * delegates to {@see execute_async_tool()} which owns the
	 * cooperative lock.
	 *
	 * Safe to call from any request context; the cooperative lock
	 * prevents duplicate execution when WP-Cron eventually fires its
	 * own tick.
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	public function kick_inline( $job_id ) {
		$job_id = sanitize_key( $job_id );
		if ( empty( $job_id ) ) {
			return;
		}

		// Honour the global escape hatch.
		if ( ! self::inline_async_kick_enabled( $job_id, __CLASS__ ) ) {
			return;
		}

		// Detach from the HTTP client so the kick can survive client
		// disconnects and the response can flush early.
		self::inline_async_detach_worker_from_client();

		$metadata = $this->get_metadata( $job_id );
		if ( ! is_array( $metadata ) ) {
			return;
		}
		// Only kick when the cron tick has not already advanced the job.
		// Once status is anything other than `pending`, the cooperative
		// lock in execute_async_tool() would block us anyway —
		// short-circuit to avoid lock churn.
		if ( ! isset( $metadata['status'] ) || 'pending' !== $metadata['status'] ) {
			return;
		}

		$this->log_event(
			'async_tool_inline_kick',
			'Async tool job kicked inline (cron loopback fallback)',
			array(
				'job_id'    => $job_id,
				'tool_slug' => isset( $metadata['tool_slug'] ) ? $metadata['tool_slug'] : 'unknown',
				'source'    => 'inline_shutdown',
			)
		);

		$executor = $this;
		self::inline_async_run_kick(
			__CLASS__,
			$job_id,
			static function () use ( $executor, $job_id ) {
				$executor->execute_async_tool( $job_id );
			}
		);
	}

	/**
	 * Self-healing inline kick used by the REST cron-status endpoint.
	 *
	 * If the job has been stuck in `pending` past
	 * {@see STALE_PENDING_THRESHOLD_SECONDS}, schedules a `shutdown`
	 * action that runs the tick after the response is flushed. The
	 * REST response payload itself is unchanged — callers see the
	 * same job details they would have without this kick. Returns
	 * true when a kick was scheduled, false otherwise (so callers can
	 * log/metric the self-healing decision if desired).
	 *
	 * @param string $job_id Job identifier (already sanitised).
	 * @return bool
	 */
	public function kick_inline_if_stale( $job_id ) {
		$job_id = sanitize_key( $job_id );
		if ( empty( $job_id ) ) {
			return false;
		}

		if ( ! self::inline_async_kick_enabled( $job_id, __CLASS__ ) ) {
			return false;
		}

		$metadata = $this->get_metadata( $job_id );
		if ( ! is_array( $metadata ) || ! isset( $metadata['status'] ) || 'pending' !== $metadata['status'] ) {
			return false;
		}

		$queued_at = isset( $metadata['queued_at'] ) ? (int) $metadata['queued_at'] : 0;
		if ( $queued_at <= 0 || ( time() - $queued_at ) <= self::STALE_PENDING_THRESHOLD_SECONDS ) {
			return false;
		}

		$executor = $this;
		add_action(
			'shutdown',
			static function () use ( $executor, $job_id ) {
				$executor->kick_inline( $job_id );
			},
			20
		);

		return true;
	}

	/**
	 * Cancel a pending or running async job.
	 *
	 * Sets the job status to 'cancelled' and fires the wp_mcp_ai_job_cancelled
	 * action. The cooperative tick lock ensures any in-flight
	 * execute_async_tool_locked() call completes cleanly; subsequent ticks see
	 * 'cancelled' in the status gate and bail early.
	 *
	 * @param string $job_id  Job identifier.
	 * @param int    $user_id ID of the user requesting the cancellation (0 = skip ownership check).
	 * @return bool|WP_Error True on success, WP_Error if the job cannot be cancelled.
	 */
	public function cancel_job( $job_id, $user_id = 0 ) {
		$metadata = $this->get_metadata( $job_id );
		if ( ! is_array( $metadata ) ) {
			return new WP_Error( 'wp_mcp_ai_job_not_found', __( 'Job not found.', 'mcp-ai-wpoos' ) );
		}

		$terminal = array( 'completed', 'failed', 'cancelled' );
		if ( in_array( $metadata['status'], $terminal, true ) ) {
			return new WP_Error( 'wp_mcp_ai_job_terminal', __( 'Job is already in a terminal state.', 'mcp-ai-wpoos' ) );
		}

		// Ownership check — admins bypass per-user restriction.
		if ( $user_id > 0 && isset( $metadata['context']['user_id'] ) && (int) $metadata['context']['user_id'] !== $user_id ) {
			if ( ! user_can( $user_id, 'manage_options' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to cancel this job.', 'mcp-ai-wpoos' ) );
			}
		}

		$metadata['status']       = 'cancelled';
		$metadata['completed_at'] = time();
		$this->save_metadata( $job_id, $metadata );

		if ( class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
			WP_MCP_AI_Job_Notifier::update_status( $job_id, 'cancelled' );
		}

		/**
		 * Fires after an async job is cancelled.
		 *
		 * @param string $job_id  Job identifier.
		 * @param int    $user_id User who initiated the cancellation.
		 */
		do_action( 'wp_mcp_ai_job_cancelled', $job_id, $user_id );

		return true;
	}

	/**
	 * Retry a failed or cancelled async job by re-queuing it.
	 *
	 * Clears the existing result/error fields, resets status to 'pending',
	 * and schedules a fresh cron event. Fires the wp_mcp_ai_job_retried action.
	 *
	 * @param string $job_id  Job identifier.
	 * @param int    $user_id ID of the user requesting the retry (0 = skip ownership check).
	 * @return string|WP_Error The job ID string on success, WP_Error on failure.
	 */
	public function retry_job( $job_id, $user_id = 0 ) {
		$metadata = $this->get_metadata( $job_id );
		if ( ! is_array( $metadata ) ) {
			return new WP_Error( 'wp_mcp_ai_job_not_found', __( 'Job not found.', 'mcp-ai-wpoos' ) );
		}

		$retryable = array( 'failed', 'cancelled' );
		if ( ! in_array( $metadata['status'], $retryable, true ) ) {
			return new WP_Error( 'wp_mcp_ai_job_not_retryable', __( 'Only failed or cancelled jobs can be retried.', 'mcp-ai-wpoos' ) );
		}

		// Ownership check — admins bypass per-user restriction.
		if ( $user_id > 0 && isset( $metadata['context']['user_id'] ) && (int) $metadata['context']['user_id'] !== $user_id ) {
			if ( ! user_can( $user_id, 'manage_options' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to retry this job.', 'mcp-ai-wpoos' ) );
			}
		}

		// Reset state for a fresh execution run.
		$metadata['status']       = 'pending';
		$metadata['queued_at']    = time();
		$metadata['started_at']   = null;
		$metadata['completed_at'] = null;
		$metadata['result']       = null;
		$metadata['error']        = null;
		$this->save_metadata( $job_id, $metadata );

		// Schedule cron event in the immediate past so spawn_cron() picks it up.
		$timestamp = time() - 1;
		wp_schedule_single_event( $timestamp, self::CRON_HOOK, array( $job_id ) );

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		if ( class_exists( 'WP_MCP_AI_Job_Notifier' ) ) {
			WP_MCP_AI_Job_Notifier::update_status( $job_id, 'pending' );
		}

		/**
		 * Fires after an async job is retried.
		 *
		 * @param string $job_id  Job identifier.
		 * @param int    $user_id User who initiated the retry.
		 */
		do_action( 'wp_mcp_ai_job_retried', $job_id, $user_id );

		// Inline-kick fallback for environments where WP-Cron loopback is disabled.
		if ( self::inline_async_kick_enabled( $job_id, __CLASS__ ) ) {
			$executor = $this;
			add_action(
				'shutdown',
				static function () use ( $executor, $job_id ) {
					$executor->kick_inline( $job_id );
				},
				20
			);
		}

		return $job_id;
	}

	/**
	 * Check whether a job is owned by the given user.
	 *
	 * Returns true if the job exists and was created by $user_id,
	 * or if $user_id holds the manage_options capability.
	 *
	 * @param string $job_id  Job identifier.
	 * @param int    $user_id WordPress user ID.
	 * @return bool
	 */
	public function is_owned_by( $job_id, $user_id ) {
		$metadata = $this->get_metadata( $job_id );
		if ( ! is_array( $metadata ) ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$owner = isset( $metadata['context']['user_id'] ) ? (int) $metadata['context']['user_id'] : 0;
		return $owner === $user_id;
	}

	/**
	 * Internal body of the cron callback. Runs inside the cooperative
	 * tick lock acquired by {@see execute_async_tool()}.
	 *
	 * @param string $job_id Sanitised job identifier.
	 * @return void
	 */
	protected function execute_async_tool_locked( $job_id ) {
		// Retrieve job metadata.
		$metadata = $this->get_metadata( $job_id );

		if ( ! $metadata || ! isset( $metadata['tool_slug'] ) ) {
			$this->log_error( 'Async tool job metadata not found', array( 'job_id' => $job_id ) );
			return;
		}

		// Re-check the status gate after taking the cooperative lock: a
		// parallel inline-shutdown worker may have already advanced this
		// job past `pending` in the brief window between the lock release
		// and our acquisition. Re-running a completed/failed/delegated
		// job would corrupt its state and double-fire completion hooks.
		// Note: 'cancelled' is also a terminal state and is caught here.
		if ( isset( $metadata['status'] ) && 'pending' !== $metadata['status'] ) {
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
		// This ensures that file paths, attachment lookups, and other blog-specific operations.
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

		// Add parent job ID to context so nested async jobs (like veo video generation).
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
			// - Warning when safe mode is enabled.
			// - Warning when running as Apache module with certain configurations.
			// These warnings are expected and can be safely ignored as we're providing.
			// a best-effort timeout extension for long-running tools.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( $tool_timeout ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Silenced intentionally: set_time_limit() may emit warnings when safe_mode is on or the function is disabled; failure is non-critical as this is a best-effort timeout extension.
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
			// This happens when a tool (like veo video generation) falls back to its own async mode.
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
					// we would overwrite those veo-specific fields, causing the veo polling.
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
				// The nested job (veo_xxx) will call complete_parent_job() when it finishes,.
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
			// This allows the chat client to receive completion notifications via SSE/polling.
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
			// This ensures async tools are properly tracked by the token manager,.
			// enabling proper orchestration and agentic loop completion for tools.
			// with media-only responses (like veo video generation).
			// NOTE: This must fire AFTER the result is stored but BEFORE job cleanup,
			// so token usage can be recorded for the completed async job.
			$wp_mcp_ai_descriptor                = WP_MCP_AI_Tool_Lifecycle_Descriptor::build( $result, null, $tool_slug, $context );
			$wp_mcp_ai_descriptor['duration_ms'] = round( (float) $duration * 1000.0, 3 );
			do_action(
				'wp_mcp_ai_after_tool_execution',
				$tool_slug,
				$arguments,
				$context,
				$result,
				$wp_mcp_ai_descriptor
			);

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
		// This allows the chat client to receive failure notifications via SSE/polling.
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
			return new WP_Error( 'wp_mcp_ai_invalid_job', __( 'Job ID is required.', 'mcp-ai-wpoos' ) );
		}

		$metadata = $this->get_metadata( $job_id );

		if ( ! $metadata ) {
			return new WP_Error( 'wp_mcp_ai_job_not_found', __( 'Job not found or expired.', 'mcp-ai-wpoos' ) );
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
					__( 'Failed to retrieve job result. The result may be corrupted.', 'mcp-ai-wpoos' )
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

		// Validate result size before storage.
		if ( $size > self::MAX_RESULT_SIZE ) {
			$this->log_error(
				'Async tool result exceeds maximum size limit',
				array(
					'size'     => $size,
					'max_size' => self::MAX_RESULT_SIZE,
				)
			);

			// Truncate by storing a summary instead of the full result.
			$truncated_result = array(
				'truncated' => true,
				'message'   => sprintf(
					/* translators: %s: size description */
					__( 'Result truncated: original size %s exceeded the maximum allowed size.', 'mcp-ai-wpoos' ),
					size_format( $size )
				),
			);

			// Preserve key metadata if result is an array.
			if ( is_array( $result ) ) {
				if ( isset( $result['status'] ) ) {
					$truncated_result['status'] = $result['status'];
				}
				if ( isset( $result['message'] ) ) {
					$truncated_result['original_message'] = substr( $result['message'], 0, 500 );
				}
				if ( isset( $result['url'] ) ) {
					$truncated_result['url'] = $result['url'];
				}
			}

			$serialized = maybe_serialize( $truncated_result );
			$size       = strlen( $serialized );
		}

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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table; WP_Query does not support custom table queries of this type.
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
