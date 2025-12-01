<?php
/**
 * Background job manager for Crawl4AI tasks.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates background polling of remote Crawl4AI tasks using WP-Cron.
 */
class WP_MCP_AI_Crawler {
	const JOB_STORAGE_PREFIX    = 'wp_mcp_ai_crawl4ai_job_';
	const CRON_HOOK             = 'wp_mcp_ai_crawl4ai_poll_task';
	const DEFAULT_POLL_INTERVAL = 30;
	const DEFAULT_MAX_RUNTIME   = 600;

	/**
	 * Register action hooks.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'handle_poll_event' ), 10, 1 );
	}

	/**
	 * Persist a remote Crawl4AI job and schedule polling.
	 *
	 * @param string $task_id  Remote task identifier.
	 * @param array  $job_args Contextual arguments (base_url, arguments, context, poll_interval, wait_timeout, status, initial_result).
	 * @return bool True when the job was queued.
	 */
	public static function register_remote_job( $task_id, array $job_args ) {
		$task_id = sanitize_text_field( (string) $task_id );
		if ( '' === $task_id ) {
			return false;
		}

		$base_url = isset( $job_args['base_url'] ) ? esc_url_raw( (string) $job_args['base_url'] ) : '';
		if ( '' === $base_url ) {
			return false;
		}

		$poll_interval = isset( $job_args['poll_interval'] ) ? absint( $job_args['poll_interval'] ) : 0;
		if ( $poll_interval <= 0 ) {
			$poll_interval = self::DEFAULT_POLL_INTERVAL;
		}

		$wait_timeout = isset( $job_args['wait_timeout'] ) ? absint( $job_args['wait_timeout'] ) : 0;
		if ( $wait_timeout <= 0 ) {
			$wait_timeout = self::DEFAULT_MAX_RUNTIME;
		}

		$job = array(
			'task_id'       => $task_id,
			'base_url'      => $base_url,
			'status'        => isset( $job_args['status'] ) ? sanitize_key( $job_args['status'] ) : 'pending',
			'created_at'    => time(),
			'updated_at'    => time(),
			'poll_interval' => max( 5, $poll_interval ),
			'max_runtime'   => max( 60, $wait_timeout ),
			'arguments'     => isset( $job_args['arguments'] ) && is_array( $job_args['arguments'] ) ? $job_args['arguments'] : array(),
			'context'       => isset( $job_args['context'] ) && is_array( $job_args['context'] ) ? $job_args['context'] : array(),
		);

		if ( isset( $job_args['raw_response'] ) ) {
			$job['raw_response'] = $job_args['raw_response'];
		}

		self::save_job( $job );
		self::schedule_next_poll( $task_id, $job );

		if ( isset( $job_args['initial_result'] ) && is_array( $job_args['initial_result'] ) ) {
			$initial             = $job_args['initial_result'];
			$initial['task_id']  = $task_id;
			$initial['metadata'] = self::merge_metadata(
				isset( $initial['metadata'] ) && is_array( $initial['metadata'] ) ? $initial['metadata'] : array(),
				array(
					'poll_interval' => $job['poll_interval'],
					'wait_timeout'  => $job['max_runtime'],
					'next_poll'     => time() + $job['poll_interval'],
					'queued_at'     => current_time( 'mysql', true ),
				)
			);

			WP_MCP_AI_Crawl4AI_Local_API::cache_task_result( $task_id, $initial );
		}

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
			'status'        => isset( $job['status'] ) ? $job['status'] : 'pending',
			'created_at'    => isset( $job['created_at'] ) ? (int) $job['created_at'] : 0,
			'updated_at'    => isset( $job['updated_at'] ) ? (int) $job['updated_at'] : 0,
			'poll_interval' => isset( $job['poll_interval'] ) ? (int) $job['poll_interval'] : self::DEFAULT_POLL_INTERVAL,
			'max_runtime'   => isset( $job['max_runtime'] ) ? (int) $job['max_runtime'] : self::DEFAULT_MAX_RUNTIME,
		);

		return $exposed;
	}

	/**
	 * Handle the WP-Cron poll event.
	 *
	 * @param string $task_id Task identifier.
	 */
	public static function handle_poll_event( $task_id ) {
		$task_id = sanitize_text_field( (string) $task_id );
		if ( '' === $task_id ) {
			return;
		}

		$job = self::get_job( $task_id );
		if ( ! $job ) {
			return;
		}

		if ( self::is_expired( $job ) ) {
			self::finalise_with_error(
				$job,
				new WP_Error( 'wp_mcp_ai_crawl4ai_timeout', __( 'The Crawl4AI job timed out before completion.', 'wp-mcp-ai' ) ),
				'timeout'
			);
			return;
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$tool     = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
		$result   = $tool->check_remote_task( $task_id, $job['base_url'], $settings, $job['arguments'], $job['context'] );

		if ( is_wp_error( $result ) ) {
			self::finalise_with_error( $job, $result, 'failed' );
			return;
		}

		$formatted = isset( $result['formatted'] ) ? $result['formatted'] : array();
		$decoded   = isset( $result['decoded'] ) ? $result['decoded'] : array();

		if ( ! isset( $formatted['task_id'] ) || '' === $formatted['task_id'] ) {
			$formatted['task_id'] = $task_id;
		}

		$filtered            = apply_filters( 'wp_mcp_ai_crawl4ai_response', $formatted, $decoded, $job['arguments'], $job['context'] );
		$filtered['task_id'] = $task_id;

		if ( empty( $filtered['results'] ) && 'completed' !== $filtered['status'] ) {
			self::persist_progress( $job, $filtered );
			return;
		}

		WP_MCP_AI_Crawl4AI_Local_API::cache_task_result( $task_id, $filtered );

		/**
		 * Fires when a background Crawl4AI job has completed successfully.
		 *
		 * @param string $task_id Task identifier.
		 * @param array  $result  Filtered result payload.
		 * @param array  $job     Job metadata.
		 */
		do_action( 'wp_mcp_ai_crawl4ai_job_completed', $task_id, $filtered, $job );

		self::delete_job( $task_id );
	}

	/**
	 * Record an interim poll result.
	 *
	 * @param array $job      Job metadata.
	 * @param array $filtered Filtered response payload.
	 */
	protected static function persist_progress( array $job, array $filtered ) {
		$job['status']     = isset( $filtered['status'] ) ? sanitize_key( $filtered['status'] ) : 'pending';
		$job['updated_at'] = time();
		self::save_job( $job );
		self::schedule_next_poll( $job['task_id'], $job );

		$metadata = isset( $filtered['metadata'] ) && is_array( $filtered['metadata'] ) ? $filtered['metadata'] : array();
		$metadata = self::merge_metadata(
			$metadata,
			array(
				'last_checked'  => current_time( 'mysql', true ),
				'poll_interval' => $job['poll_interval'],
				'wait_timeout'  => $job['max_runtime'],
				'next_poll'     => time() + $job['poll_interval'],
			)
		);

		$filtered['metadata'] = $metadata;
		WP_MCP_AI_Crawl4AI_Local_API::cache_task_result( $job['task_id'], $filtered );
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
	 * Persist an error result and remove the job.
	 *
	 * @param array    $job    Job metadata.
	 * @param WP_Error $error  Error instance.
	 * @param string   $status Status string to expose.
	 */
	protected static function finalise_with_error( array $job, WP_Error $error, $status ) {
		$message = $error->get_error_message();
		$code    = $error->get_error_code();

		$metadata = array(
			'error' => $message,
			'code'  => $code,
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

		WP_MCP_AI_Crawl4AI_Local_API::cache_task_result( $job['task_id'], $result );

		/**
		 * Fires when a background Crawl4AI job fails or times out.
		 *
		 * @param string   $task_id Task identifier.
		 * @param WP_Error $error   Error instance.
		 * @param array    $job     Job metadata.
		 */
		do_action( 'wp_mcp_ai_crawl4ai_job_failed', $job['task_id'], $error, $job );

		self::delete_job( $job['task_id'] );
	}

	/**
	 * Determine whether a job has exceeded its runtime budget.
	 *
	 * @param array $job Job metadata.
	 * @return bool
	 */
	protected static function is_expired( array $job ) {
		$created   = isset( $job['created_at'] ) ? (int) $job['created_at'] : time();
		$max_timer = isset( $job['max_runtime'] ) ? (int) $job['max_runtime'] : self::DEFAULT_MAX_RUNTIME;

		return ( time() - $created ) > $max_timer;
	}

	/**
	 * Retrieve a persisted job.
	 *
	 * @param string $task_id Task identifier.
	 * @return array|null
	 */
	protected static function get_job( $task_id ) {
		$key = self::get_storage_key( $task_id );

		if ( is_multisite() ) {
			$job = get_site_transient( $key );
		} else {
			$job = get_transient( $key );
		}

		if ( ! is_array( $job ) ) {
			return null;
		}

		return $job;
	}

	/**
	 * Persist job metadata.
	 *
	 * @param array $job Job metadata.
	 */
	protected static function save_job( array $job ) {
		$key = self::get_storage_key( $job['task_id'] );
		$ttl = DAY_IN_SECONDS;

		if ( is_multisite() ) {
			set_site_transient( $key, $job, $ttl );
		} else {
			set_transient( $key, $job, $ttl );
		}
	}

	/**
	 * Remove a job from storage and unschedule pending polls.
	 *
	 * @param string $task_id Task identifier.
	 */
	protected static function delete_job( $task_id ) {
		$key = self::get_storage_key( $task_id );

		if ( is_multisite() ) {
			delete_site_transient( $key );
		} else {
			delete_transient( $key );
		}

		$next = wp_next_scheduled( self::CRON_HOOK, array( $task_id ) );
		if ( $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK, array( $task_id ) );
		}
	}

	/**
	 * Schedule the next poll for a job.
	 *
	 * @param string $task_id Task identifier.
	 * @param array  $job     Job metadata.
	 */
	protected static function schedule_next_poll( $task_id, array $job ) {
		$delay = isset( $job['poll_interval'] ) ? (int) $job['poll_interval'] : self::DEFAULT_POLL_INTERVAL;
		$delay = max( 5, $delay );

		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $task_id ) ) ) {
			$timestamp = time() + $delay;
			wp_schedule_single_event( $timestamp, self::CRON_HOOK, array( $task_id ) );

			// Record cron job in manager to track nested poll scheduling.
			if ( class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
				$user_id = 0;
				if ( isset( $job['context']['user_id'] ) ) {
					$user_id = absint( $job['context']['user_id'] );
				}
				WP_MCP_AI_Cron_Manager::record_job(
					self::CRON_HOOK,
					array( $task_id ),
					'single',
					$timestamp,
					$user_id
				);
			}

			// Trigger WordPress cron to ensure continued polling.
			// This is necessary because WordPress cron only runs on page loads,.
			// and during crawl job polling, there may be no user activity.
			spawn_cron();
		}
	}

	/**
	 * Build the storage key for a job.
	 *
	 * @param string $task_id Task identifier.
	 * @return string
	 */
	protected static function get_storage_key( $task_id ) {
		$hash = md5( $task_id );

		if ( is_multisite() ) {
			$blog_id = absint( get_current_blog_id() );

			return sprintf( '%s%s_%s', self::JOB_STORAGE_PREFIX, $blog_id, $hash );
		}

		return self::JOB_STORAGE_PREFIX . $hash;
	}
}
