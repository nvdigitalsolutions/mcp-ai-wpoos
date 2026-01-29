<?php
/**
 * General-purpose notification system for async WordPress jobs.
 *
 * Provides SSE streams and webhook dispatching for any long-running operation,
 * not limited to crawl4ai. Supports job status updates, progress tracking,
 * and completion notifications.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages real-time notifications for async jobs via SSE and webhooks.
 */
class WP_MCP_AI_Job_Notifier {
	const CACHE_PREFIX         = 'wp_mcp_ai_job_status_';
	const CACHE_DURATION       = 3600; // 1 hour.
	const WEBHOOK_OPTION_KEY   = 'wp_mcp_ai_job_webhooks';
	const MAX_WEBHOOKS_PER_JOB = 10;

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		// Hook into existing crawl4ai completion.
		add_action( 'wp_mcp_ai_crawl4ai_job_completed', array( __CLASS__, 'handle_job_completed' ), 10, 3 );

		// Hook into web_search completion.
		add_action( 'wp_mcp_ai_web_search_completed', array( __CLASS__, 'handle_web_search_completed' ), 10, 3 );

		// Hook into veo video generation completion.
		add_action( 'wp_mcp_ai_veo_video_completed', array( __CLASS__, 'handle_veo_video_completed' ), 10, 3 );

		// Generic hooks for any async operation.
		add_action( 'wp_mcp_ai_job_started', array( __CLASS__, 'handle_job_started' ), 10, 2 );
		add_action( 'wp_mcp_ai_job_progress', array( __CLASS__, 'handle_job_progress' ), 10, 3 );
		add_action( 'wp_mcp_ai_job_completed', array( __CLASS__, 'handle_job_completed' ), 10, 3 );
		add_action( 'wp_mcp_ai_job_failed', array( __CLASS__, 'handle_job_failed' ), 10, 3 );
	}

	/**
	 * Ensure all relevant context IDs are captured in metadata for authorization and audit trails.
	 *
	 * This ensures we track:
	 * - user_id: WordPress user who initiated the job
	 * - assistant_id: Assistant that triggered the job
	 * - team_id: Team workflow that triggered the job
	 * - professional_id/profession_id: Professional that triggered the job
	 * - agent_id: Specific agent that executed the job (in multi-agent workflows)
	 * - virtual_id: Virtual assistant that triggered the job
	 *
	 * @param array $metadata Job metadata.
	 * @param array $context Optional execution context to extract IDs from.
	 * @return array Enhanced metadata with all available IDs.
	 */
	private static function ensure_tracking_ids( $metadata, $context = array() ) {
		// Ensure user_id is always stored.
		if ( ! isset( $metadata['user_id'] ) ) {
			$metadata['user_id'] = get_current_user_id();
		}

		// Extract assistant_id from context if available.
		if ( ! isset( $metadata['assistant_id'] ) && isset( $context['assistant_id'] ) ) {
			$metadata['assistant_id'] = absint( $context['assistant_id'] );
		}

		// Extract team_id from context if available.
		if ( ! isset( $metadata['team_id'] ) && isset( $context['team_id'] ) ) {
			$metadata['team_id'] = absint( $context['team_id'] );
		}

		// Extract professional_id or profession_id from context if available.
		if ( ! isset( $metadata['professional_id'] ) && ! isset( $metadata['profession_id'] ) ) {
			if ( isset( $context['professional_id'] ) ) {
				$metadata['professional_id'] = absint( $context['professional_id'] );
			} elseif ( isset( $context['profession_id'] ) ) {
				$metadata['profession_id'] = absint( $context['profession_id'] );
			} elseif ( isset( $context['profession_slug'] ) ) {
				$metadata['profession_slug'] = sanitize_key( $context['profession_slug'] );
			}
		}

		// Extract agent_id from context if available (multi-agent workflows).
		if ( ! isset( $metadata['agent_id'] ) && isset( $context['agent_id'] ) ) {
			$metadata['agent_id'] = sanitize_text_field( $context['agent_id'] );
		}

		// Extract agent_role from context if available (for agent role tracking).
		if ( ! isset( $metadata['agent_role'] ) && isset( $context['agent_role'] ) ) {
			$metadata['agent_role'] = sanitize_key( $context['agent_role'] );
		}

		// Extract virtual_id from context if available.
		if ( ! isset( $metadata['virtual_id'] ) && isset( $context['virtual_id'] ) ) {
			$metadata['virtual_id'] = absint( $context['virtual_id'] );
		}

		return $metadata;
	}

	/**
	 * Handle job started event.
	 *
	 * @param string $job_id   Job identifier.
	 * @param array  $metadata Job metadata (may contain context).
	 */
	public static function handle_job_started( $job_id, $metadata = array() ) {
		// Extract context if embedded in metadata for ID tracking.
		$context = isset( $metadata['context'] ) ? $metadata['context'] : array();

		// Ensure all relevant IDs are captured for authorization and audit.
		$metadata = self::ensure_tracking_ids( $metadata, $context );

		$status = array(
			'job_id'     => $job_id,
			'status'     => 'started',
			'started_at' => current_time( 'mysql', true ),
			'metadata'   => $metadata,
		);

		self::cache_job_status( $job_id, $status );
		self::dispatch_webhooks( $job_id, 'started', $status );
		self::emit_sse_event( $job_id, 'started', $status );
	}

	/**
	 * Handle job progress update.
	 *
	 * @param string $job_id   Job identifier.
	 * @param float  $progress Progress percentage (0-100).
	 * @param array  $metadata Additional metadata (may contain context).
	 */
	public static function handle_job_progress( $job_id, $progress, $metadata = array() ) {
		$status = self::get_job_status( $job_id );

		if ( ! $status ) {
			$status = array(
				'job_id' => $job_id,
				'status' => 'running',
			);
		}

		// Extract context if embedded in metadata for ID tracking.
		$context = isset( $metadata['context'] ) ? $metadata['context'] : array();

		// Ensure all relevant IDs are captured for authorization and audit.
		$metadata = self::ensure_tracking_ids( $metadata, $context );

		$status['progress']   = max( 0, min( 100, floatval( $progress ) ) );
		$status['updated_at'] = current_time( 'mysql', true );
		$status['metadata']   = $metadata;

		self::cache_job_status( $job_id, $status );
		self::dispatch_webhooks( $job_id, 'progress', $status );
		self::emit_sse_event( $job_id, 'progress', $status );
	}

	/**
	 * Handle web search completion.
	 *
	 * Adapter method to convert web_search_completed action parameters
	 * to the format expected by handle_job_completed.
	 *
	 * @param array $result    Search results array.
	 * @param array $arguments Original search arguments.
	 * @param array $context   Execution context.
	 */
	public static function handle_web_search_completed( $result, $arguments = array(), $context = array() ) {
		// Extract or generate a job ID for tracking.
		// Web search results include a task_id field for this purpose.
		$job_id = isset( $result['task_id'] ) ? $result['task_id'] : '';

		if ( '' === $job_id ) {
			// If no task_id, generate one based on query and timestamp.
			// This ensures we can track and cache the result.
			$query  = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';
			$job_id = 'search-' . md5( $query . microtime( true ) );
		}

		// Build metadata from context.
		$metadata = array(
			'tool'     => 'web_search',
			'query'    => isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '',
			'provider' => isset( $result['provider'] ) ? sanitize_key( $result['provider'] ) : '',
			'cached'   => isset( $result['cached'] ) ? (bool) $result['cached'] : false,
			'context'  => $context, // Embed context for ID extraction.
		);

		// Delegate to the standard job completion handler.
		self::handle_job_completed( $job_id, $result, $metadata );
	}

	/**
	 * Handle Veo video generation completion.
	 *
	 * Adapter method to convert veo_video_completed action parameters
	 * to the format expected by handle_job_completed.
	 *
	 * @param array $result    Video generation result.
	 * @param array $arguments Original generation arguments.
	 * @param array $context   Execution context.
	 */
	public static function handle_veo_video_completed( $result, $arguments = array(), $context = array() ) {
		// Generate a job ID for tracking video generation.
		// Use attachment_id if available, otherwise generate based on prompt and timestamp.
		if ( isset( $result['attachment_id'] ) && $result['attachment_id'] > 0 ) {
			$job_id = 'veo-video-' . absint( $result['attachment_id'] );
		} else {
			$prompt = isset( $arguments['prompt'] ) ? sanitize_text_field( $arguments['prompt'] ) : '';
			$job_id = 'veo-video-' . md5( $prompt . microtime( true ) );
		}

		// Build metadata from context and result.
		$metadata = array(
			'tool'     => 'generate_veo_video',
			'prompt'   => isset( $arguments['prompt'] ) ? sanitize_text_field( $arguments['prompt'] ) : '',
			'model'    => isset( $result['model'] ) ? sanitize_key( $result['model'] ) : '',
			'duration' => isset( $result['duration'] ) ? absint( $result['duration'] ) : 0,
			'user_id'  => isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0,
		);

		// Add attachment_id to metadata if available.
		if ( isset( $result['attachment_id'] ) ) {
			$metadata['attachment_id'] = absint( $result['attachment_id'] );
		}

		// Delegate to the standard job completion handler.
		self::handle_job_completed( $job_id, $result, $metadata );
	}

	/**
	 * Handle job completion.
	 *
	 * @param string $job_id Job identifier.
	 * @param array  $result Job result data.
	 * @param array  $metadata Job metadata.
	 */
	public static function handle_job_completed( $job_id, $result = array(), $metadata = array() ) {
		// Normalize result to ensure JSON serializability.
		// This recursively converts any WP_Error objects to serializable arrays,.
		// preventing JSON encoding failures when the status is retrieved.
		$result = self::normalize_data_recursive( $result );

		// Extract context if embedded in metadata for ID tracking.
		$context = isset( $metadata['context'] ) ? $metadata['context'] : array();

		// Ensure all relevant IDs are captured for authorization and audit.
		$metadata = self::ensure_tracking_ids( $metadata, $context );

		$status = array(
			'job_id'       => $job_id,
			'status'       => 'completed',
			'completed_at' => current_time( 'mysql', true ),
			'result'       => $result,
			'metadata'     => $metadata,
		);

		self::cache_job_status( $job_id, $status );
		self::dispatch_webhooks( $job_id, 'completed', $status );
		self::emit_sse_event( $job_id, 'completed', $status );
	}

	/**
	 * Handle job failure.
	 *
	 * @param string         $job_id Job identifier.
	 * @param WP_Error|array $error  Error information.
	 * @param array          $metadata Job metadata (may contain context).
	 */
	public static function handle_job_failed( $job_id, $error, $metadata = array() ) {
		$error_data = array(
			'message' => is_wp_error( $error ) ? $error->get_error_message() : 'Unknown error',
			'code'    => is_wp_error( $error ) ? $error->get_error_code() : 'unknown_error',
		);

		// Extract context if embedded in metadata for ID tracking.
		$context = isset( $metadata['context'] ) ? $metadata['context'] : array();

		// Ensure all relevant IDs are captured for authorization and audit.
		$metadata = self::ensure_tracking_ids( $metadata, $context );

		$status = array(
			'job_id'    => $job_id,
			'status'    => 'failed',
			'failed_at' => current_time( 'mysql', true ),
			'error'     => $error_data,
			'metadata'  => $metadata,
		);

		self::cache_job_status( $job_id, $status );
		self::dispatch_webhooks( $job_id, 'failed', $status );
		self::emit_sse_event( $job_id, 'failed', $status );
	}

	/**
	 * Emit SSE event for job status update.
	 *
	 * Emits a Server-Sent Event that can be consumed by chat clients
	 * to display real-time job status updates in conversations.
	 *
	 * @param string $job_id     Job identifier.
	 * @param string $event_type Event type (started, progress, completed, failed).
	 * @param array  $status     Job status data.
	 */
	protected static function emit_sse_event( $job_id, $event_type, $status ) {
		// Don't emit SSE events if we're not in an SSE context.
		// This prevents errors when job status changes outside of an active SSE stream.
		if ( ! defined( 'WP_MCP_AI_SSE_ACTIVE' ) || ! WP_MCP_AI_SSE_ACTIVE ) {
			return;
		}

		// Determine the SSE event name based on job type.
		$sse_event_name = self::get_sse_event_name_for_job( $job_id, $event_type );

		// Build SSE event data.
		$event_data = array(
			'job_id'   => $job_id,
			'status'   => isset( $status['status'] ) ? $status['status'] : $event_type,
			'progress' => isset( $status['progress'] ) ? $status['progress'] : null,
			'message'  => self::get_status_message( $status, $event_type ),
			'metadata' => isset( $status['metadata'] ) ? $status['metadata'] : array(),
		);

		// Add result data for completed jobs.
		if ( 'completed' === $event_type && isset( $status['result'] ) ) {
			$event_data['result'] = $status['result'];
		}

		// Add error data for failed jobs.
		if ( 'failed' === $event_type && isset( $status['error'] ) ) {
			$event_data['error'] = $status['error'];
		}

		// Emit the SSE event using WordPress action.
		// This allows the Chat Controller or other SSE handlers to catch and stream it.
		do_action( 'wp_mcp_ai_emit_sse_event', $sse_event_name, $event_data );
	}

	/**
	 * Get SSE event name for a job.
	 *
	 * Determines whether this is a cron job or crawl4ai job
	 * and returns the appropriate SSE event name.
	 *
	 * @param string $job_id     Job identifier.
	 * @param string $event_type Event type.
	 * @return string SSE event name.
	 */
	protected static function get_sse_event_name_for_job( $job_id, $event_type ) {
		// Check if this is a crawl4ai job.
		if ( strpos( $job_id, 'crawl' ) === 0 || strpos( $job_id, 'crawl4ai' ) === 0 ) {
			return 'crawl4ai_job_status_update';
		}

		// Check if this is a cron job.
		if ( strpos( $job_id, 'cron' ) === 0 ) {
			return 'cron_job_status_update';
		}

		// Check metadata for tool type.
		$cached_status = self::get_job_status( $job_id );
		if ( $cached_status && isset( $cached_status['metadata']['tool'] ) ) {
			$tool = $cached_status['metadata']['tool'];
			
			if ( 'run_crawl4ai_job' === $tool || 'crawl4ai' === $tool ) {
				return 'crawl4ai_job_status_update';
			}
			
			if ( 'create_cron_job' === $tool || strpos( $tool, 'cron' ) !== false ) {
				return 'cron_job_status_update';
			}
		}

		// Default to generic job status update.
		return 'job_status_update';
	}

	/**
	 * Get human-readable status message.
	 *
	 * @param array  $status     Status data.
	 * @param string $event_type Event type.
	 * @return string Status message.
	 */
	protected static function get_status_message( $status, $event_type ) {
		// Check for custom message in metadata.
		if ( isset( $status['metadata']['message'] ) ) {
			return sanitize_text_field( $status['metadata']['message'] );
		}

		// Generate default message based on event type.
		switch ( $event_type ) {
			case 'started':
				return __( 'Job started', 'mcp-ai-wpoos' );
			case 'progress':
				$progress = isset( $status['progress'] ) ? absint( $status['progress'] ) : 0;
				/* translators: %d: progress percentage */
				return sprintf( __( 'Processing... %d%%', 'mcp-ai-wpoos' ), $progress );
			case 'completed':
				return __( 'Job completed successfully', 'mcp-ai-wpoos' );
			case 'failed':
				if ( isset( $status['error']['message'] ) ) {
					return sanitize_text_field( $status['error']['message'] );
				}
				return __( 'Job failed', 'mcp-ai-wpoos' );
			default:
				return __( 'Job status update', 'mcp-ai-wpoos' );
		}
	}

	/**
	 * Cache job status for SSE retrieval.
	 *
	 * @param string $job_id Job identifier.
	 * @param array  $status Status data.
	 * @return bool True on success.
	 */
	protected static function cache_job_status( $job_id, array $status ) {
		$cache_key = self::CACHE_PREFIX . sanitize_key( $job_id );
		return set_transient( $cache_key, $status, self::CACHE_DURATION );
	}

	/**
	 * Retrieve cached job status.
	 *
	 * @param string $job_id Job identifier.
	 * @return array|null Status data or null if not found.
	 */
	public static function get_job_status( $job_id ) {
		$cache_key = self::CACHE_PREFIX . sanitize_key( $job_id );
		$status    = get_transient( $cache_key );

		// Enhance status with Little's Law metrics if job is running.
		if ( is_array( $status ) && isset( $status['status'] ) && 'running' === $status['status'] ) {
			$status = self::add_littles_law_metrics( $status );
		}

		return is_array( $status ) ? $status : null;
	}

	/**
	 * Add Little's Law metrics to job status.
	 *
	 * Calculates predicted completion time and queue position using Little's Law:
	 * L = λ × W
	 *
	 * @param array $status Job status data.
	 * @return array Enhanced status with Little's Law metrics.
	 */
	protected static function add_littles_law_metrics( $status ) {
		$job_id   = isset( $status['job_id'] ) ? $status['job_id'] : '';
		$metadata = isset( $status['metadata'] ) ? $status['metadata'] : array();

		// Get job type/tool for SLA tier determination.
		$tool_name = isset( $metadata['tool'] ) ? sanitize_key( $metadata['tool'] ) : '';
		$sla_tier  = self::infer_sla_tier_from_tool( $tool_name );

		// Get current time and start time.
		$current_time = time();
		$started_at   = isset( $status['started_at'] ) ? strtotime( $status['started_at'] ) : $current_time;
		$elapsed_time = max( 0, $current_time - $started_at );

		// Get SLA target for this tier.
		$sla_target = self::get_sla_target_for_tier( $sla_tier );

		// Estimate completion based on progress.
		$progress = isset( $status['progress'] ) ? floatval( $status['progress'] ) : 0;
		if ( $progress > 0 && $progress < 100 ) {
			// Estimate total time = elapsed / (progress / 100).
			$estimated_total     = $elapsed_time / ( $progress / 100 );
			$estimated_remaining = max( 0, $estimated_total - $elapsed_time );
		} else {
			// No progress info, use SLA target as estimate.
			$estimated_remaining = $sla_target;
		}

		// Add Little's Law metrics to status.
		$status['littles_law'] = array(
			'sla_tier'             => $sla_tier,
			'sla_target'           => $sla_target,
			'elapsed_time'         => $elapsed_time,
			'estimated_remaining'  => $estimated_remaining,
			'estimated_total'      => isset( $estimated_total ) ? $estimated_total : null,
			'sla_compliance'       => self::calculate_sla_compliance( $elapsed_time, $estimated_remaining, $sla_target ),
			'predicted_completion' => date( 'c', $current_time + $estimated_remaining ),
		);

		return $status;
	}

	/**
	 * Infer SLA tier from tool name.
	 *
	 * @param string $tool_name Tool name.
	 * @return string SLA tier (realtime, near_realtime, batch).
	 */
	protected static function infer_sla_tier_from_tool( $tool_name ) {
		// Default tool-to-tier mapping.
		$tier_map = array(
			'web_search'         => 'near_realtime',
			'generate_veo_video' => 'batch',
			'crawl4ai'           => 'batch',
			'generate_image'     => 'near_realtime',
			'transcribe_audio'   => 'near_realtime',
			'analyze_video'      => 'batch',
			'save_post'          => 'realtime',
			'get_user_info'      => 'realtime',
		);

		/**
		 * Filter the tool-to-tier mapping for SLA classification.
		 *
		 * Allows customization of which SLA tier is assigned to each tool
		 * without modifying code.
		 *
		 * @since 1.2.0
		 *
		 * @param array $tier_map Associative array of tool_name => tier.
		 */
		$tier_map = apply_filters( 'wp_mcp_ai_tool_sla_tier_map', $tier_map );

		// Check if tool is in our map.
		if ( isset( $tier_map[ $tool_name ] ) ) {
			return sanitize_key( $tier_map[ $tool_name ] );
		}

		// Default to batch for unknown tools.
		return 'batch';
	}

	/**
	 * Get SLA target in seconds for a tier.
	 *
	 * @param string $tier SLA tier.
	 * @return float SLA target in seconds.
	 */
	protected static function get_sla_target_for_tier( $tier ) {
		$targets = array(
			'realtime'      => 1.0,
			'near_realtime' => 30.0,
			'batch'         => 300.0,
		);

		return isset( $targets[ $tier ] ) ? $targets[ $tier ] : 300.0;
	}

	/**
	 * Calculate SLA compliance status.
	 *
	 * @param float $elapsed_time        Time elapsed so far (seconds).
	 * @param float $estimated_remaining Estimated remaining time (seconds).
	 * @param float $sla_target          SLA target (seconds).
	 * @return string Compliance status (on_track, at_risk, violated).
	 */
	protected static function calculate_sla_compliance( $elapsed_time, $estimated_remaining, $sla_target ) {
		$estimated_total = $elapsed_time + $estimated_remaining;

		// Already exceeded SLA.
		if ( $elapsed_time > $sla_target ) {
			return 'violated';
		}

		// Projected to exceed SLA.
		if ( $estimated_total > $sla_target ) {
			return 'at_risk';
		}

		// Within SLA bounds.
		return 'on_track';
	}

	/**
	 * Register a webhook for job notifications.
	 *
	 * @param string $job_id      Job identifier (or '*' for all jobs).
	 * @param string $webhook_url URL to POST notifications to.
	 * @param array  $events      Events to trigger on (started, progress, completed, failed).
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function register_webhook( $job_id, $webhook_url, $events = array() ) {
		// Basic URL format validation.
		if ( ! is_string( $webhook_url ) || ! filter_var( $webhook_url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error( 'invalid_webhook_url', __( 'Invalid webhook URL provided.', 'mcp-ai-wpoos' ) );
		}

		// SSRF Protection: Parse and validate URL components.
		$parsed_url = wp_parse_url( $webhook_url );

		// Only allow http/https protocols.
		if ( ! isset( $parsed_url['scheme'] ) || ! in_array( $parsed_url['scheme'], array( 'http', 'https' ), true ) ) {
			return new WP_Error(
				'invalid_webhook_scheme',
				__( 'Only http and https protocols are allowed for webhooks.', 'mcp-ai-wpoos' )
			);
		}

		// Block private/internal IP ranges to prevent SSRF attacks.
		if ( isset( $parsed_url['host'] ) ) {
			$host = $parsed_url['host'];
			$ip   = gethostbyname( $host );

			// Check for private IP ranges (RFC 1918, loopback, link-local).
			if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
				return new WP_Error(
					'private_ip_blocked',
					__( 'Webhooks to private IP addresses, localhost, or internal networks are not allowed for security reasons.', 'mcp-ai-wpoos' )
				);
			}
		}

		// Use WordPress built-in URL validation for additional security.
		if ( ! wp_http_validate_url( $webhook_url ) ) {
			return new WP_Error(
				'webhook_validation_failed',
				__( 'Webhook URL failed security validation.', 'mcp-ai-wpoos' )
			);
		}

		$job_id = sanitize_text_field( $job_id );
		if ( '' === $job_id ) {
			return new WP_Error( 'invalid_job_id', __( 'Invalid job ID provided.', 'mcp-ai-wpoos' ) );
		}

		if ( empty( $events ) ) {
			$events = array( 'completed', 'failed' );
		}

		$webhooks = get_option( self::WEBHOOK_OPTION_KEY, array() );

		if ( ! isset( $webhooks[ $job_id ] ) ) {
			$webhooks[ $job_id ] = array();
		}

		if ( count( $webhooks[ $job_id ] ) >= self::MAX_WEBHOOKS_PER_JOB ) {
			return new WP_Error( 'too_many_webhooks', __( 'Maximum webhooks per job exceeded.', 'mcp-ai-wpoos' ) );
		}

		$webhooks[ $job_id ][] = array(
			'url'        => esc_url_raw( $webhook_url ),
			'events'     => array_map( 'sanitize_key', (array) $events ),
			'created_at' => current_time( 'mysql', true ),
		);

		return update_option( self::WEBHOOK_OPTION_KEY, $webhooks );
	}

	/**
	 * Dispatch webhooks for a job event.
	 *
	 * @param string $job_id Job identifier.
	 * @param string $event  Event name (started, progress, completed, failed).
	 * @param array  $data   Event data to send.
	 */
	protected static function dispatch_webhooks( $job_id, $event, $data ) {
		$webhooks = get_option( self::WEBHOOK_OPTION_KEY, array() );

		if ( empty( $webhooks ) ) {
			return;
		}

		// Get webhooks for this specific job and wildcard webhooks.
		$job_webhooks = isset( $webhooks[ $job_id ] ) ? $webhooks[ $job_id ] : array();
		$all_webhooks = isset( $webhooks['*'] ) ? $webhooks['*'] : array();
		$targets      = array_merge( $job_webhooks, $all_webhooks );

		foreach ( $targets as $webhook ) {
			if ( ! isset( $webhook['events'] ) || ! in_array( $event, $webhook['events'], true ) ) {
				continue;
			}

			if ( empty( $webhook['url'] ) ) {
				continue;
			}

			// Send webhook asynchronously to avoid blocking.
			$timestamp = time();
			$payload   = array(
				'event'   => $event,
				'job_id'  => $job_id,
				'data'    => $data,
				'sent_at' => current_time( 'c', true ),
			);

			// Use numerically-indexed array for wp_schedule_single_event.
			// The action expects 2 arguments: $url and $payload.
			$webhook_args = array(
				$webhook['url'],
				$payload,
			);

			wp_schedule_single_event(
				$timestamp,
				'wp_mcp_ai_send_webhook',
				$webhook_args
			);

			// Record webhook dispatch job in manager for admin visibility.
			if ( class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
				// Try to get user_id from metadata or data.
				$user_id = 0;
				if ( isset( $data['metadata']['user_id'] ) ) {
					$user_id = absint( $data['metadata']['user_id'] );
				} elseif ( isset( $data['user_id'] ) ) {
					$user_id = absint( $data['user_id'] );
				} else {
					$user_id = get_current_user_id();
				}

				WP_MCP_AI_Cron_Manager::record_job(
					'wp_mcp_ai_send_webhook',
					$webhook_args,
					'single',
					$timestamp,
					$user_id
				);
			}

			// Trigger WordPress cron immediately to ensure the webhook job runs.
			// WordPress cron is virtual and only runs on page loads by default.
			spawn_cron();
		}
	}

	/**
	 * Send a webhook notification.
	 *
	 * Hooked to 'wp_mcp_ai_send_webhook' action for async delivery.
	 * Tracks delivery attempts and moves to dead letter queue after max retries.
	 *
	 * @param string $url     Webhook URL.
	 * @param array  $payload Payload to send.
	 */
	public static function send_webhook( $url, $payload ) {
		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'User-Agent'   => 'WP-MCP-AI-Webhook/1.0',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error(
				'Webhook delivery failed',
				array(
					'url'   => $url,
					'error' => $response->get_error_message(),
				)
			);

			// Track webhook delivery failures and add to DLQ after max retries.
			self::handle_webhook_failure( $url, $payload, $response );
		}
	}

	/**
	 * Handle webhook delivery failure.
	 *
	 * Tracks retry attempts and moves to dead letter queue after max failures.
	 *
	 * @param string   $url      Webhook URL.
	 * @param array    $payload  Webhook payload.
	 * @param WP_Error $error    Error object.
	 */
	protected static function handle_webhook_failure( $url, $payload, $error ) {
		// Generate identifier for this webhook + payload combination.
		$identifier = md5( $url . wp_json_encode( $payload ) );

		// Track retry count in transients (expires after 1 hour).
		$retry_key   = 'wp_mcp_ai_webhook_retry_' . $identifier;
		$retry_count = get_transient( $retry_key );

		if ( false === $retry_count ) {
			$retry_count = 0;
		}

		$retry_count = absint( $retry_count ) + 1;
		$max_retries = 3;

		// Store updated retry count.
		set_transient( $retry_key, $retry_count, HOUR_IN_SECONDS );

		// If max retries exceeded, move to dead letter queue.
		if ( $retry_count >= $max_retries && class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			$retry_history = array();
			for ( $i = 0; $i < $retry_count; $i++ ) {
				$retry_history[] = array(
					'timestamp' => time() - ( ( $retry_count - $i - 1 ) * 300 ),
					'result'    => 'failed',
					'error'     => $error->get_error_message(),
				);
			}

			WP_MCP_AI_Dead_Letter_Queue::add(
				WP_MCP_AI_Dead_Letter_Queue::TYPE_WEBHOOK,
				$identifier,
				array(
					'url'     => $url,
					'payload' => $payload,
				),
				sprintf(
					'Webhook delivery failed after %d attempts: %s',
					$retry_count,
					$error->get_error_message()
				),
				$retry_history
			);

			// Clear retry transient since it's now in DLQ.
			delete_transient( $retry_key );
		}
	}

	/**
	 * Clean up expired job statuses.
	 *
	 * Should be called periodically via cron.
	 */
	public static function cleanup_expired_jobs() {
		global $wpdb;

		// Clean up old transients.
		$pattern = $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$pattern,
				time() - self::CACHE_DURATION
			)
		);
	}

	/**
	 * Recursively normalize data structures to ensure JSON serializability.
	 *
	 * Walks through arrays and objects to convert any WP_Error instances
	 * to serializable array format. This prevents JSON encoding failures
	 * when sending data through SSE streams or REST API responses.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $data Data to normalize, can be any type.
	 * @return mixed Normalized data with all WP_Error objects converted to arrays.
	 */
	protected static function normalize_data_recursive( $data ) {
		// Handle WP_Error directly.
		if ( is_wp_error( $data ) ) {
			$error_data  = $data->get_error_data();
			$error_array = array(
				'error'   => true,
				'code'    => $data->get_error_code(),
				'message' => $data->get_error_message(),
			);

			if ( ! empty( $error_data ) ) {
				$error_array['data'] = $error_data;
			}

			return $error_array;
		}

		// Handle arrays - recursively process each element.
		if ( is_array( $data ) ) {
			$normalized = array();
			foreach ( $data as $key => $value ) {
				$normalized[ $key ] = self::normalize_data_recursive( $value );
			}
			return $normalized;
		}

		// Handle objects - convert to array and recurse.
		// Note: WP_Error is already handled above via is_wp_error() check.
		if ( is_object( $data ) ) {
			return self::normalize_data_recursive( (array) $data );
		}

		// Scalars pass through unchanged.
		return $data;
	}
}

// Register webhook sender.
add_action( 'wp_mcp_ai_send_webhook', array( 'WP_MCP_AI_Job_Notifier', 'send_webhook' ), 10, 2 );
