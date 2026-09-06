<?php
/**
 * Crawl4AI background coordinator (Wave E6, sub-cluster 5).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Crawler`
 * (`includes/crawler/class-wp-mcp-ai-crawler.php`): byte-identical
 * background coordinator for remote Crawl4AI jobs — the
 * `wp_mcp_ai_crawl4ai_job_` transient storage with the md5-hashed
 * storage keys, the `wp_mcp_ai_crawl4ai_poll_task` cron hook with the
 * single-event scheduling + `wp_mcp_ai_crawl4ai_auto_spawn_cron`
 * filter, the `FILTER_VALIDATE_URL`-gated base-URL validation, the
 * poll-interval/max-runtime defaults (30 s / 600 s) and clamps (5 s /
 * 60 s), the cooperative `wp_mcp_ai_crawl4ai_poll_lock_` tick lock
 * (30 s TTL), the inline-async-tick shutdown kick, the interim
 * progress persistence, the exponential-backoff retry path (30/60/120
 * s, 300 s cap, 3 retries, timeout-final), the dead-letter forwarding,
 * and the `wp_mcp_ai_crawl4ai_job_registered|completed|failed` +
 * `wp_mcp_ai_crawl4ai_response` surfaces.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4).
 *  - The base's `require_once` of the inline-async-tick trait
 *    disappears (PSR-4 autoloading; the trait is ported alongside as
 *    `InlineAsyncTickTrait`).
 *  - Collaborators resolve per install mode (`defined( 'WP_MCP_AI_PATH' )`
 *    discriminator — never bare class_exists):
 *    `WP_MCP_AI_Admin_Settings::get_settings()` monolith / the
 *    `wp_mcp_ai_settings` option standalone;
 *    `WP_MCP_AI_Tool_Run_Crawl4AI_Job::check_remote_task()` monolith /
 *    a documented `wp_mcp_ai_crawl4ai_check_unavailable` WP_Error
 *    standalone (new standalone-only error code — the tool remains
 *    base-owned until the Crawl4AI tool wave);
 *    `WP_MCP_AI_Crawl4AI_Local_API::cache_task_result()` monolith /
 *    dormant no-op standalone (the result-cache consumer is the base
 *    REST/tool surface);
 *    `WP_MCP_AI_Logger`, `WP_MCP_AI_Dead_Letter_Queue`,
 *    `WP_MCP_AI_Cron_Manager` monolith-only (dormant standalone —
 *    documented; the platform addon's ported DLQ/cron classes are a
 *    different distribution).
 *  - The trait filter args (`__CLASS__`, `static::class`) resolve to
 *    the ported class name (documented — class-name-dependent by
 *    design).
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\Crawler
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\Crawler;

/**
 * Coordinates background polling of remote Crawl4AI tasks using WP-Cron.
 *
 * Adopts {@see InlineAsyncTickTrait} (Slice 3 of the inline-async-tick
 * campaign) so that the first poll for a newly-queued Crawl4AI job fires
 * inline on the shutdown of the request that registered it, rather than
 * waiting up to 30 s for the next WP-Cron loopback. On hosts where
 * `DISABLE_WP_CRON` is true the cron loopback never fires at all; the
 * lock prevents the inline kick and a concurrent cron event from both
 * executing `handle_poll_event()` for the same task simultaneously.
 *
 * @since 1.1.0
 */
class Crawler {
	use InlineAsyncTickTrait;

	const JOB_STORAGE_PREFIX    = 'wp_mcp_ai_crawl4ai_job_';
	const CRON_HOOK             = 'wp_mcp_ai_crawl4ai_poll_task';
	const DEFAULT_POLL_INTERVAL = 30;
	const DEFAULT_MAX_RUNTIME   = 600;

	/**
	 * Prefix for the per-task cooperative tick lock.
	 *
	 * Combined with a hash of the task_id to form the full lock key passed
	 * to {@see inline_async_acquire_tick_lock()} /
	 * {@see inline_async_release_tick_lock()}.
	 *
	 * @var string
	 */
	const TICK_LOCK_PREFIX = 'wp_mcp_ai_crawl4ai_poll_lock_';

	/**
	 * Object-cache group used by the tick-lock entries.
	 *
	 * @var string
	 */
	const TICK_LOCK_CACHE_GROUP = 'wp_mcp_ai_crawl4ai';

	/**
	 * Tick-lock TTL in seconds.
	 *
	 * Should exceed the longest realistic single poll round-trip. A single
	 * Crawl4AI status check via HTTP typically completes in < 5 s; 30 s
	 * gives generous headroom while releasing the lock quickly if a request
	 * hangs.
	 *
	 * @var int
	 */
	const TICK_LOCK_TTL = 30;

	/**
	 * Minimum age (in seconds) of a newly-queued job before the REST
	 * self-heal path triggers an additional inline kick.
	 *
	 * @var int
	 */
	const STALE_QUEUED_THRESHOLD_SECONDS = 5;

	/**
	 * Register action hooks.
	 *
	 * @return void
	 */
	public static function init() {
		\add_action( self::CRON_HOOK, array( __CLASS__, 'handle_poll_event' ), 10, 1 );
	}

	/**
	 * Persist a remote Crawl4AI job and schedule polling.
	 *
	 * @param string $task_id  Remote task identifier.
	 * @param array  $job_args Contextual arguments (base_url, arguments, context, poll_interval, wait_timeout, status, initial_result).
	 * @return bool True when the job was queued.
	 */
	public static function register_remote_job( $task_id, array $job_args ) {
		$task_id = self::sanitize_task_id( $task_id );
		if ( '' === $task_id ) {
			return false;
		}

		// Require a syntactically valid absolute URL before sanitising. On
		// WordPress 6.6+ esc_url_raw() guesses an http:// scheme for bare
		// strings, which would let garbage values like "not a valid url"
		// through unchanged, so validate the raw input first.
		$base_url = isset( $job_args['base_url'] ) ? \trim( (string) $job_args['base_url'] ) : '';
		if ( '' === $base_url || false === \filter_var( $base_url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		$base_url = \esc_url_raw( $base_url );
		if ( '' === $base_url ) {
			return false;
		}

		$poll_interval = isset( $job_args['poll_interval'] ) ? \absint( $job_args['poll_interval'] ) : 0;
		if ( $poll_interval <= 0 ) {
			$poll_interval = self::DEFAULT_POLL_INTERVAL;
		}

		$wait_timeout = isset( $job_args['wait_timeout'] ) ? \absint( $job_args['wait_timeout'] ) : 0;
		if ( $wait_timeout <= 0 ) {
			$wait_timeout = self::DEFAULT_MAX_RUNTIME;
		}

		$job = array(
			'task_id'       => $task_id,
			'base_url'      => $base_url,
			'status'        => isset( $job_args['status'] ) ? \sanitize_key( $job_args['status'] ) : 'pending',
			'created_at'    => \time(),
			'updated_at'    => \time(),
			'poll_interval' => \max( 5, $poll_interval ),
			'max_runtime'   => \max( 60, $wait_timeout ),
			'arguments'     => isset( $job_args['arguments'] ) && \is_array( $job_args['arguments'] ) ? $job_args['arguments'] : array(),
			'context'       => isset( $job_args['context'] ) && \is_array( $job_args['context'] ) ? $job_args['context'] : array(),
			'retry_count'   => 0,
			'max_retries'   => 3,
			'sla_tier'      => 'batch', // Crawl4AI jobs are background batch processing.
		);

		if ( isset( $job_args['raw_response'] ) ) {
			$job['raw_response'] = $job_args['raw_response'];
		}

		self::save_job( $job );
		self::schedule_next_poll( $task_id, $job );

		// Inline-async-tick: fire the first poll on the shutdown of the current
		// request so that fast jobs (those that Crawl4AI processes in < 30 s) are
		// resolved without waiting for the next WP-Cron loopback.
		if ( self::inline_async_kick_enabled( $task_id, __CLASS__ ) ) {
			\add_action(
				'shutdown',
				function () use ( $task_id ) {
					self::inline_async_detach_worker_from_client();
					self::inline_async_run_kick(
						__CLASS__,
						$task_id,
						function () use ( $task_id ) {
							self::handle_poll_event( $task_id );
						}
					);
				},
				22
			);
		}

		if ( isset( $job_args['initial_result'] ) && \is_array( $job_args['initial_result'] ) ) {
			$initial             = $job_args['initial_result'];
			$initial['task_id']  = $task_id;
			$initial['metadata'] = self::merge_metadata(
				isset( $initial['metadata'] ) && \is_array( $initial['metadata'] ) ? $initial['metadata'] : array(),
				array(
					'poll_interval' => $job['poll_interval'],
					'wait_timeout'  => $job['max_runtime'],
					'next_poll'     => \time() + $job['poll_interval'],
					'queued_at'     => \current_time( 'mysql', true ),
				)
			);

			self::cache_task_result( $task_id, $initial );
		}

		return true;
	}

	/**
	 * Register a completed Crawl4AI job for tracking without scheduling polling.
	 *
	 * This method is used for jobs that complete synchronously or for local crawls.
	 * The job is saved to the manager for tracking purposes but no polling is scheduled.
	 *
	 * @param string $task_id Remote or local task identifier.
	 * @param array  $job_args Contextual arguments (base_url, arguments, context, status, result).
	 * @return bool True when the job was registered.
	 */
	public static function register_completed_job( $task_id, array $job_args ) {
		$task_id = self::sanitize_task_id( $task_id );
		if ( '' === $task_id ) {
			return false;
		}

		// base_url is optional for local jobs.
		$base_url = isset( $job_args['base_url'] ) ? \esc_url_raw( (string) $job_args['base_url'] ) : '';

		$job = array(
			'task_id'      => $task_id,
			'base_url'     => $base_url,
			'status'       => isset( $job_args['status'] ) ? \sanitize_key( $job_args['status'] ) : 'completed',
			'created_at'   => \time(),
			'updated_at'   => \time(),
			'arguments'    => isset( $job_args['arguments'] ) && \is_array( $job_args['arguments'] ) ? $job_args['arguments'] : array(),
			'context'      => isset( $job_args['context'] ) && \is_array( $job_args['context'] ) ? $job_args['context'] : array(),
			'skip_polling' => true, // Flag to indicate no polling needed.
		);

		if ( isset( $job_args['raw_response'] ) ) {
			$job['raw_response'] = $job_args['raw_response'];
		}

		// Save job metadata for tracking.
		self::save_job( $job );

		// Cache the result if provided.
		if ( isset( $job_args['result'] ) && \is_array( $job_args['result'] ) ) {
			$result            = $job_args['result'];
			$result['task_id'] = $task_id;

			// Store when this job was registered for tracking purposes.
			// This is kept separate from crawl metadata to avoid confusion.
			if ( ! isset( $result['metadata'] ) || ! \is_array( $result['metadata'] ) ) {
				$result['metadata'] = array();
			}

			if ( ! isset( $result['metadata']['tracking'] ) || ! \is_array( $result['metadata']['tracking'] ) ) {
				$result['metadata']['tracking'] = array();
			}

			$result['metadata']['tracking']['registered_at'] = \current_time( 'mysql', true );

			self::cache_task_result( $task_id, $result );
		}

		/**
		 * Fires when a Crawl4AI job is registered as completed.
		 *
		 * @since 1.1.0
		 *
		 * @param string $task_id Task identifier.
		 * @param array  $job     Job metadata.
		 */
		\do_action( 'wp_mcp_ai_crawl4ai_job_registered', $task_id, $job );

		return true;
	}

	/**
	 * Retrieve details for a queued job.
	 *
	 * @param string $task_id Task identifier.
	 * @return array|null
	 */
	public static function get_job_status( $task_id ) {
		$job = self::get_job( $task_id );
		if ( ! $job ) {
			return null;
		}

		$exposed = array(
			'task_id'       => $job['task_id'],
			'base_url'      => isset( $job['base_url'] ) ? (string) $job['base_url'] : '',
			'status'        => isset( $job['status'] ) ? $job['status'] : 'pending',
			'created_at'    => isset( $job['created_at'] ) ? (int) $job['created_at'] : 0,
			'updated_at'    => isset( $job['updated_at'] ) ? (int) $job['updated_at'] : 0,
			'poll_interval' => isset( $job['poll_interval'] ) ? (int) $job['poll_interval'] : self::DEFAULT_POLL_INTERVAL,
			'max_runtime'   => isset( $job['max_runtime'] ) ? (int) $job['max_runtime'] : self::DEFAULT_MAX_RUNTIME,
			'arguments'     => isset( $job['arguments'] ) && \is_array( $job['arguments'] ) ? $job['arguments'] : array(),
			'context'       => isset( $job['context'] ) && \is_array( $job['context'] ) ? $job['context'] : array(),
		);

		return $exposed;
	}

	/**
	 * Handle the WP-Cron poll event.
	 *
	 * Protected by the cooperative tick lock ({@see InlineAsyncTickTrait})
	 * so that the inline kick (fired on the shutdown of the request that
	 * registered the job) and the first scheduled WP-Cron loopback cannot
	 * both execute `check_remote_task()` concurrently for the same
	 * `$task_id`.
	 *
	 * @param string $task_id Task identifier.
	 * @return void
	 */
	public static function handle_poll_event( $task_id ) {
		$task_id = self::sanitize_task_id( $task_id );
		if ( '' === $task_id ) {
			return;
		}

		// Cooperative lock: prevents the inline shutdown kick and the WP-Cron
		// loopback from polling the same task simultaneously.
		$lock_key = self::TICK_LOCK_PREFIX . \md5( $task_id );
		if ( ! self::inline_async_acquire_tick_lock( $lock_key, self::TICK_LOCK_CACHE_GROUP, self::TICK_LOCK_TTL ) ) {
			return;
		}

		try {
			self::do_poll_event( $task_id );
		} finally {
			self::inline_async_release_tick_lock( $lock_key, self::TICK_LOCK_CACHE_GROUP );
		}
	}

	/**
	 * Inner poll logic — executes while the tick lock is held.
	 *
	 * Extracted from {@see handle_poll_event()} so unit tests can call it
	 * directly without going through the lock machinery.
	 *
	 * @param string $task_id Task identifier (already sanitized).
	 * @return void
	 */
	protected static function do_poll_event( $task_id ) {
		$job = self::get_job( $task_id );
		if ( ! $job ) {
			return;
		}

		// Skip polling for jobs marked as completed.
		if ( ! empty( $job['skip_polling'] ) ) {
			return;
		}

		if ( self::is_expired( $job ) ) {
			self::finalise_with_error(
				$job,
				new \WP_Error( 'wp_mcp_ai_crawl4ai_timeout', __( 'The Crawl4AI job timed out before completion.', 'nvoos-content-graph-ai' ) ),
				'timeout'
			);
			return;
		}

		$settings = self::settings();
		$result   = self::check_remote_task( $task_id, $job['base_url'], $settings, $job['arguments'], $job['context'] );

		if ( \is_wp_error( $result ) ) {
			self::finalise_with_error( $job, $result, 'failed' );
			return;
		}

		$formatted = isset( $result['formatted'] ) ? $result['formatted'] : array();
		$decoded   = isset( $result['decoded'] ) ? $result['decoded'] : array();

		if ( ! isset( $formatted['task_id'] ) || '' === $formatted['task_id'] ) {
			$formatted['task_id'] = $task_id;
		}

		$filtered            = \apply_filters( 'wp_mcp_ai_crawl4ai_response', $formatted, $decoded, $job['arguments'], $job['context'] );
		$filtered['task_id'] = $task_id;

		if ( empty( $filtered['results'] ) && 'completed' !== $filtered['status'] ) {
			self::persist_progress( $job, $filtered );
			return;
		}

		self::cache_task_result( $task_id, $filtered );

		/**
		 * Fires when a background Crawl4AI job has completed successfully.
		 *
		 * @since 1.1.0
		 *
		 * @param string $task_id Task identifier.
		 * @param array  $result  Filtered result payload.
		 * @param array  $job     Job metadata.
		 */
		\do_action( 'wp_mcp_ai_crawl4ai_job_completed', $task_id, $filtered, $job );

		self::delete_job( $task_id );
	}

	/**
	 * Record an interim poll result.
	 *
	 * @param array $job      Job metadata.
	 * @param array $filtered Filtered response payload.
	 * @return void
	 */
	protected static function persist_progress( array $job, array $filtered ) {
		$job['status']     = isset( $filtered['status'] ) ? \sanitize_key( $filtered['status'] ) : 'pending';
		$job['updated_at'] = \time();
		self::save_job( $job );
		self::schedule_next_poll( $job['task_id'], $job );

		$metadata = isset( $filtered['metadata'] ) && \is_array( $filtered['metadata'] ) ? $filtered['metadata'] : array();
		$metadata = self::merge_metadata(
			$metadata,
			array(
				'last_checked'  => \current_time( 'mysql', true ),
				'poll_interval' => $job['poll_interval'],
				'wait_timeout'  => $job['max_runtime'],
				'next_poll'     => \time() + $job['poll_interval'],
			)
		);

		$filtered['metadata'] = $metadata;
		self::cache_task_result( $job['task_id'], $filtered );
	}

	/**
	 * Merge new metadata into an existing record.
	 *
	 * @param array $original Original metadata.
	 * @param array $updates  Metadata updates.
	 * @return array
	 */
	protected static function merge_metadata( array $original, array $updates ) {
		foreach ( $updates as $key => $value ) {
			$original[ $key ] = $value;
		}

		return $original;
	}

	/**
	 * Persist an error result and handle retry logic or move to DLQ.
	 *
	 * Implements exponential backoff retry strategy before permanently failing.
	 *
	 * @param array     $job    Job metadata.
	 * @param \WP_Error $error  Error instance.
	 * @param string    $status Status string to expose.
	 * @return void
	 */
	protected static function finalise_with_error( array $job, \WP_Error $error, $status ) {
		$message = $error->get_error_message();
		$code    = $error->get_error_code();

		$retry_count = isset( $job['retry_count'] ) ? \absint( $job['retry_count'] ) : 0;
		$max_retries = isset( $job['max_retries'] ) ? \absint( $job['max_retries'] ) : 3;

		self::log_error(
			'Crawl4AI job failed',
			array(
				'task_id'     => $job['task_id'],
				'retry_count' => $retry_count,
				'error'       => $message,
				'code'        => $code,
			)
		);

		// Check if we should retry (not for timeouts - those are final).
		if ( 'timeout' !== $status && $retry_count < $max_retries ) {
			// Implement exponential backoff: 30s, 60s, 120s.
			$backoff_delay = $job['poll_interval'] * \pow( 2, $retry_count );
			$backoff_delay = \min( $backoff_delay, 300 ); // Cap at 5 minutes.

			$job['retry_count'] = $retry_count + 1;
			$job['status']      = 'retrying';
			$job['updated_at']  = \time();
			$job['last_error']  = $message;

			self::save_job( $job );

			// Schedule retry with backoff delay.
			$next_attempt = \time() + $backoff_delay;
			$scheduled    = \wp_schedule_single_event( $next_attempt, self::CRON_HOOK, array( $job['task_id'] ) );

			if ( $scheduled ) {
				self::log_event(
					'crawl4ai_retry_scheduled',
					'Crawl4AI job scheduled for retry with exponential backoff',
					array(
						'task_id'       => $job['task_id'],
						'retry_count'   => $job['retry_count'],
						'backoff_delay' => $backoff_delay,
						'next_attempt'  => \gmdate( 'Y-m-d H:i:s', $next_attempt ),
					)
				);

				// Cache interim retry status.
				$retry_result = array(
					'status'   => 'retrying',
					'task_id'  => $job['task_id'],
					'results'  => array(),
					'metadata' => array(
						'retry_count'   => $job['retry_count'],
						'max_retries'   => $max_retries,
						'next_attempt'  => \gmdate( 'Y-m-d H:i:s', $next_attempt ),
						'backoff_delay' => $backoff_delay,
						'last_error'    => $message,
					),
				);

				self::cache_task_result( $job['task_id'], $retry_result );
				return; // Don't finalize yet, let it retry.
			}
		}

		// Max retries exceeded or timeout - permanently fail and move to DLQ.
		$metadata = array(
			'error'       => $message,
			'code'        => $code,
			'retry_count' => $retry_count,
		);

		$result = array(
			'status'   => $status,
			'task_id'  => $job['task_id'],
			'results'  => array(),
			'metadata' => $metadata,
			'raw'      => array(
				'error' => $error->get_error_data(),
			),
		);

		self::cache_task_result( $job['task_id'], $result );

		// Move to dead letter queue if available (monolith-only — see the
		// class docblock).
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			$retry_history = array();
			for ( $i = 0; $i <= $retry_count; $i++ ) {
				$retry_history[] = array(
					'timestamp' => \time() - ( ( $retry_count - $i ) * 60 ),
					'result'    => 'failed',
					'error'     => $message,
				);
			}

			\WP_MCP_AI_Dead_Letter_Queue::add(
				\WP_MCP_AI_Dead_Letter_Queue::TYPE_CRON_JOB,
				$job['task_id'],
				array(
					'hook'      => self::CRON_HOOK,
					'args'      => array( $job['task_id'] ),
					'timestamp' => \time(),
					'job_data'  => $job,
				),
				\sprintf(
					'Crawl4AI job failed after %d retries: %s',
					$retry_count,
					$message
				),
				$retry_history
			);
		}

		/**
		 * Fires when a background Crawl4AI job fails or times out permanently.
		 *
		 * @since 1.1.0
		 *
		 * @param string    $task_id Task identifier.
		 * @param \WP_Error $error   Error instance.
		 * @param array     $job     Job metadata.
		 */
		\do_action( 'wp_mcp_ai_crawl4ai_job_failed', $job['task_id'], $error, $job );

		self::delete_job( $job['task_id'] );
	}

	/**
	 * Determine whether a job has exceeded its runtime budget.
	 *
	 * @param array $job Job metadata.
	 * @return bool
	 */
	protected static function is_expired( array $job ) {
		$created   = isset( $job['created_at'] ) ? (int) $job['created_at'] : \time();
		$max_timer = isset( $job['max_runtime'] ) ? (int) $job['max_runtime'] : self::DEFAULT_MAX_RUNTIME;

		return ( \time() - $created ) > $max_timer;
	}

	/**
	 * Retrieve a persisted job.
	 *
	 * @param string $task_id Task identifier.
	 * @return array|null
	 */
	protected static function get_job( $task_id ) {
		$task_id = self::sanitize_task_id( $task_id );
		$key     = self::get_storage_key( $task_id );

		if ( \is_multisite() ) {
			$job = \get_site_transient( $key );
		} else {
			$job = \get_transient( $key );
		}

		if ( ! \is_array( $job ) ) {
			return null;
		}

		return $job;
	}

	/**
	 * Persist job metadata.
	 *
	 * @param array $job Job metadata.
	 * @return void
	 */
	protected static function save_job( array $job ) {
		$key = self::get_storage_key( $job['task_id'] );
		$ttl = DAY_IN_SECONDS;

		if ( \is_multisite() ) {
			\set_site_transient( $key, $job, $ttl );
		} else {
			\set_transient( $key, $job, $ttl );
		}
	}

	/**
	 * Remove a job from storage and unschedule pending polls.
	 *
	 * @param string $task_id Task identifier.
	 * @return void
	 */
	protected static function delete_job( $task_id ) {
		$task_id = self::sanitize_task_id( $task_id );
		$key     = self::get_storage_key( $task_id );

		if ( \is_multisite() ) {
			\delete_site_transient( $key );
		} else {
			\delete_transient( $key );
		}

		$next = \wp_next_scheduled( self::CRON_HOOK, array( $task_id ) );
		if ( $next ) {
			\wp_unschedule_event( $next, self::CRON_HOOK, array( $task_id ) );
		}
	}

	/**
	 * Schedule the next poll for a job.
	 *
	 * @param string $task_id Task identifier.
	 * @param array  $job     Job metadata.
	 * @return void
	 */
	protected static function schedule_next_poll( $task_id, array $job ) {
		$delay = isset( $job['poll_interval'] ) ? (int) $job['poll_interval'] : self::DEFAULT_POLL_INTERVAL;
		$delay = \max( 5, $delay );

		if ( ! \wp_next_scheduled( self::CRON_HOOK, array( $task_id ) ) ) {
			$timestamp = \time() + $delay;
			\wp_schedule_single_event( $timestamp, self::CRON_HOOK, array( $task_id ) );

			// Record cron job in manager to track nested poll scheduling
			// (monolith-only — see the class docblock).
			if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
				$user_id = 0;
				if ( isset( $job['context']['user_id'] ) ) {
					$user_id = \absint( $job['context']['user_id'] );
				}
				\WP_MCP_AI_Cron_Manager::record_job(
					self::CRON_HOOK,
					array( $task_id ),
					'single',
					$timestamp,
					$user_id
				);
			}

			// Trigger WordPress cron to ensure continued polling.
			// This is necessary because WordPress cron only runs on page loads,
			// and during crawl job polling, there may be no user activity.
			/**
			 * Filters whether to kick WP-Cron immediately after scheduling.
			 *
			 * Hosts that run a real system cron — and test environments that
			 * mock HTTP — can return false to suppress the loopback request.
			 *
			 * @since 1.1.0
			 *
			 * @param bool   $spawn   Whether to spawn the cron loopback.
			 * @param string $task_id Task identifier.
			 */
			if ( \apply_filters( 'wp_mcp_ai_crawl4ai_auto_spawn_cron', true, $task_id ) ) {
				\spawn_cron();
			}
		}
	}

	/**
	 * Normalise a task identifier for storage and lookup.
	 *
	 * Registering and querying a job must sanitise identically so that a
	 * caller can pass the original, unmodified task id to get_job_status()
	 * and still resolve the job stored under the sanitised value.
	 *
	 * @param string $task_id Task identifier.
	 * @return string
	 */
	protected static function sanitize_task_id( $task_id ) {
		return \sanitize_text_field( (string) $task_id );
	}

	/**
	 * Build the storage key for a job.
	 *
	 * @param string $task_id Task identifier.
	 * @return string
	 */
	protected static function get_storage_key( $task_id ) {
		$hash = \md5( $task_id );

		if ( \is_multisite() ) {
			$blog_id = \absint( \get_current_blog_id() );

			return \sprintf( '%s%s_%s', self::JOB_STORAGE_PREFIX, $blog_id, $hash );
		}

		return self::JOB_STORAGE_PREFIX . $hash;
	}

	// -------------------------------------------------------------------------
	// Per-mode collaborator seams (see the class docblock)
	// -------------------------------------------------------------------------

	/**
	 * Resolve the merged NV oOS settings per install mode.
	 *
	 * @return array
	 */
	protected static function settings() {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return \WP_MCP_AI_Admin_Settings::get_settings();
		}

		$settings = \get_option( 'wp_mcp_ai_settings', array() );

		return \is_array( $settings ) ? $settings : array();
	}

	/**
	 * Check a remote Crawl4AI task per install mode.
	 *
	 * @param string $task_id   Task identifier.
	 * @param string $base_url  Base URL.
	 * @param array  $settings  Settings array.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Tool context.
	 * @return array|\WP_Error
	 */
	protected static function check_remote_task( $task_id, $base_url, $settings, $arguments, $context ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Tool_Run_Crawl4AI_Job' ) ) {
			$tool = new \WP_MCP_AI_Tool_Run_Crawl4AI_Job();
			return $tool->check_remote_task( $task_id, $base_url, $settings, $arguments, $context );
		}

		// Standalone: the validated tool remains base-owned until the
		// Crawl4AI tool wave (documented degradation — the poll loop fails
		// the job through the normal retry path).
		return new \WP_Error(
			'wp_mcp_ai_crawl4ai_check_unavailable',
			__( 'The Crawl4AI check tool is unavailable in this install mode.', 'nvoos-content-graph-ai' )
		);
	}

	/**
	 * Cache a task result per install mode.
	 *
	 * @param string $task_id Task identifier.
	 * @param array  $result  Result payload.
	 * @return void
	 */
	protected static function cache_task_result( $task_id, array $result ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Crawl4AI_Local_API' ) ) {
			\WP_MCP_AI_Crawl4AI_Local_API::cache_task_result( $task_id, $result );
		}

		// Standalone: dormant — the result-cache consumer is the base
		// REST/tool surface (documented).
	}

	/**
	 * Log an error per install mode (monolith-only).
	 *
	 * @param string $message Error message.
	 * @param array  $context Context.
	 * @return void
	 */
	protected static function log_error( $message, array $context ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_error( $message, $context );
		}
	}

	/**
	 * Log an activity event per install mode (monolith-only).
	 *
	 * @param string $code    Event code.
	 * @param string $message Event message.
	 * @param array  $context Context.
	 * @return void
	 */
	protected static function log_event( $code, $message, array $context ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $code, $message, $context );
		}
	}
}
