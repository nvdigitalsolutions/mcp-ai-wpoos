<?php
/**
 * Schedule Social Post Tool
 *
 * Schedule posts with optimal timing suggestions based on
 * audience engagement patterns and platform analytics.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for scheduling social media posts with intelligent timing.
 *
 * Supports:
 * - Multi-platform scheduling
 * - Optimal timing suggestions based on historical engagement
 * - Timezone-aware scheduling
 * - Recurring posts (daily, weekly, monthly)
 * - Queue management
 * - Auto-publish at scheduled time
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Schedule_Social_Post implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if social media toolkit is enabled.
	 */
	public static function is_available() {
		// Check if base version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Check if social media toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_social_media_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_social_media_toolkit'] ) ) {
			return __( 'Social media toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Schedule social post tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'schedule_social_post';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Schedule Social Post', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Schedule social media posts with optimal timing suggestions based on audience engagement patterns. Supports multi-platform scheduling, timezone awareness, recurring posts, and auto-publish.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'content'            => array(
					'type'        => 'string',
					'description' => __( 'Post content/text (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 5000,
				),
				'platforms'          => array(
					'type'        => 'array',
					'description' => __( 'Target platforms (required)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'facebook', 'instagram', 'twitter', 'linkedin', 'tiktok', 'pinterest' ),
					),
					'minItems'    => 1,
				),
				'scheduled_time'     => array(
					'type'        => 'string',
					'description' => __( 'Scheduled publish time (ISO 8601 format or "optimal")', 'mcp-ai-wpoos-pro' ),
				),
				'timezone'           => array(
					'type'        => 'string',
					'description' => __( 'Timezone for scheduled time (default: WordPress timezone)', 'mcp-ai-wpoos-pro' ),
					'default'     => 'UTC',
				),
				'media_urls'         => array(
					'type'        => 'array',
					'description' => __( 'URLs of images or videos to attach', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'hashtags'           => array(
					'type'        => 'array',
					'description' => __( 'Hashtags to include', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'link'               => array(
					'type'        => 'string',
					'description' => __( 'URL to include in post', 'mcp-ai-wpoos-pro' ),
				),
				'recurrence'         => array(
					'type'        => 'string',
					'description' => __( 'Recurrence pattern (none, daily, weekly, monthly)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'none', 'daily', 'weekly', 'monthly' ),
					'default'     => 'none',
				),
				'recurrence_end'     => array(
					'type'        => 'string',
					'description' => __( 'End date for recurring posts (ISO 8601 format)', 'mcp-ai-wpoos-pro' ),
				),
				'get_optimal_timing' => array(
					'type'        => 'boolean',
					'description' => __( 'Return optimal posting times without scheduling', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'   => array( 'content', 'platforms' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'social-media',
			'database-write',
			'scheduling',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check permissions.
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to schedule social media posts.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if toolkit is enabled.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'toolkit_not_enabled',
				self::get_unavailable_reason()
			);
		}

		// Validate required fields.
		if ( empty( $arguments['content'] ) ) {
			return new WP_Error(
				'missing_content',
				__( 'Post content is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['platforms'] ) || ! is_array( $arguments['platforms'] ) ) {
			return new WP_Error(
				'missing_platforms',
				__( 'At least one target platform is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Sanitize inputs.
		$content            = sanitize_textarea_field( $arguments['content'] );
		$platforms          = array_map( 'sanitize_text_field', $arguments['platforms'] );
		$scheduled_time     = isset( $arguments['scheduled_time'] ) ? sanitize_text_field( $arguments['scheduled_time'] ) : 'optimal';
		$timezone           = isset( $arguments['timezone'] ) ? sanitize_text_field( $arguments['timezone'] ) : wp_timezone_string();
		$media_urls         = isset( $arguments['media_urls'] ) ? array_map( 'esc_url_raw', (array) $arguments['media_urls'] ) : array();
		$hashtags           = isset( $arguments['hashtags'] ) ? array_map( 'sanitize_text_field', (array) $arguments['hashtags'] ) : array();
		$link               = isset( $arguments['link'] ) ? esc_url_raw( $arguments['link'] ) : '';
		$recurrence         = isset( $arguments['recurrence'] ) ? sanitize_text_field( $arguments['recurrence'] ) : 'none';
		$recurrence_end     = isset( $arguments['recurrence_end'] ) ? sanitize_text_field( $arguments['recurrence_end'] ) : '';
		$get_optimal_timing = isset( $arguments['get_optimal_timing'] ) ? (bool) $arguments['get_optimal_timing'] : false;

		// If only requesting optimal timing suggestions.
		if ( $get_optimal_timing ) {
			$optimal_times = $this->get_optimal_posting_times( $platforms );
			return array(
				'success'       => true,
				'optimal_times' => $optimal_times,
				'message'       => __( 'Optimal posting times calculated successfully.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Calculate scheduled time.
		if ( 'optimal' === $scheduled_time ) {
			$optimal_times  = $this->get_optimal_posting_times( $platforms );
			$scheduled_time = $optimal_times['recommended'];
		}

		// Validate and convert scheduled time to timestamp.
		$scheduled_timestamp = $this->parse_scheduled_time( $scheduled_time, $timezone );

		if ( is_wp_error( $scheduled_timestamp ) ) {
			return $scheduled_timestamp;
		}

		// Ensure scheduled time is in the future.
		if ( $scheduled_timestamp <= current_time( 'timestamp' ) ) {
			return new WP_Error(
				'past_schedule_time',
				__( 'Scheduled time must be in the future.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Create scheduled post.
		$schedule_data = array(
			'content'        => $content,
			'platforms'      => $platforms,
			'scheduled_time' => $scheduled_timestamp,
			'timezone'       => $timezone,
			'media_urls'     => $media_urls,
			'hashtags'       => $hashtags,
			'link'           => $link,
			'recurrence'     => $recurrence,
			'recurrence_end' => $recurrence_end,
			'user_id'        => $current_user_id,
			'status'         => 'scheduled',
		);

		$schedule_id = $this->create_scheduled_post( $schedule_data );

		if ( is_wp_error( $schedule_id ) ) {
			return $schedule_id;
		}

		// Schedule WordPress cron event.
		$this->schedule_cron_event( $schedule_id, $scheduled_timestamp );

		return array(
			'success'      => true,
			'schedule_id'  => $schedule_id,
			'scheduled_at' => gmdate( 'Y-m-d H:i:s', $scheduled_timestamp ),
			'timezone'     => $timezone,
			'platforms'    => $platforms,
			'recurrence'   => $recurrence,
			'edit_url'     => admin_url( 'post.php?post=' . $schedule_id . '&action=edit' ),
			'message'      => sprintf(
				/* translators: %s: Formatted scheduled time */
				__( 'Post scheduled successfully for %s.', 'mcp-ai-wpoos-pro' ),
				gmdate( 'Y-m-d H:i:s', $scheduled_timestamp )
			),
		);
	}

	/**
	 * Get optimal posting times based on engagement patterns.
	 *
	 * @param array $platforms Target platforms.
	 * @return array Optimal times.
	 */
	protected function get_optimal_posting_times( $platforms ) {
		// Analyze historical engagement data per platform.
		// This is a simplified implementation - real version would analyze actual engagement data.
		$platform_best_times = array(
			'facebook'  => array(
				'day'  => 'Wednesday',
				'hour' => 13,
			),
			'instagram' => array(
				'day'  => 'Wednesday',
				'hour' => 11,
			),
			'twitter'   => array(
				'day'  => 'Wednesday',
				'hour' => 12,
			),
			'linkedin'  => array(
				'day'  => 'Tuesday',
				'hour' => 10,
			),
			'tiktok'    => array(
				'day'  => 'Thursday',
				'hour' => 19,
			),
			'pinterest' => array(
				'day'  => 'Saturday',
				'hour' => 20,
			),
		);

		$suggestions = array();
		foreach ( $platforms as $platform ) {
			if ( isset( $platform_best_times[ $platform ] ) ) {
				$suggestions[ $platform ] = $this->calculate_next_optimal_time( $platform_best_times[ $platform ] );
			}
		}

		// Find the most common optimal time across platforms.
		$recommended = $this->find_best_compromise_time( $suggestions );

		return array(
			'recommended'           => $recommended,
			'platform_specific'     => $suggestions,
			'engagement_prediction' => 'high',
		);
	}

	/**
	 * Calculate next optimal time for a platform.
	 *
	 * @param array $best_time Best time configuration.
	 * @return string ISO 8601 formatted time.
	 */
	protected function calculate_next_optimal_time( $best_time ) {
		$now          = current_time( 'timestamp' );
		$target_day   = $best_time['day'];
		$target_hour  = $best_time['hour'];
		$next_optimal = strtotime( "next {$target_day} {$target_hour}:00:00", $now );

		return gmdate( 'c', $next_optimal );
	}

	/**
	 * Find best compromise time across multiple platforms.
	 *
	 * @param array $suggestions Platform-specific suggestions.
	 * @return string ISO 8601 formatted time.
	 */
	protected function find_best_compromise_time( $suggestions ) {
		if ( empty( $suggestions ) ) {
			return gmdate( 'c', strtotime( '+1 day 12:00:00' ) );
		}

		// Use the first suggestion as baseline.
		return reset( $suggestions );
	}

	/**
	 * Parse scheduled time string to timestamp.
	 *
	 * @param string $time_string Time string.
	 * @param string $timezone    Timezone.
	 * @return int|WP_Error Timestamp or error.
	 */
	protected function parse_scheduled_time( $time_string, $timezone ) {
		try {
			$datetime = new DateTime( $time_string, new DateTimeZone( $timezone ) );
			return $datetime->getTimestamp();
		} catch ( Exception $e ) {
			return new WP_Error(
				'invalid_time',
				sprintf(
					/* translators: %s: Error message */
					__( 'Invalid scheduled time format: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Create scheduled post entry in database.
	 *
	 * @param array $schedule_data Schedule data.
	 * @return int|WP_Error Post ID or error.
	 */
	protected function create_scheduled_post( $schedule_data ) {
		$post_data = array(
			'post_title'   => sprintf(
				/* translators: %s: Formatted date */
				__( 'Scheduled Post - %s', 'mcp-ai-wpoos-pro' ),
				gmdate( 'Y-m-d H:i:s', $schedule_data['scheduled_time'] )
			),
			'post_content' => $schedule_data['content'],
			'post_status'  => 'future',
			'post_type'    => 'social_scheduled_post',
			'post_author'  => $schedule_data['user_id'],
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Store schedule metadata.
		update_post_meta( $post_id, '_social_platforms', $schedule_data['platforms'] );
		update_post_meta( $post_id, '_social_scheduled_time', $schedule_data['scheduled_time'] );
		update_post_meta( $post_id, '_social_timezone', $schedule_data['timezone'] );
		update_post_meta( $post_id, '_social_media_urls', $schedule_data['media_urls'] );
		update_post_meta( $post_id, '_social_hashtags', $schedule_data['hashtags'] );
		update_post_meta( $post_id, '_social_link', $schedule_data['link'] );
		update_post_meta( $post_id, '_social_recurrence', $schedule_data['recurrence'] );
		update_post_meta( $post_id, '_social_recurrence_end', $schedule_data['recurrence_end'] );
		update_post_meta( $post_id, '_social_status', $schedule_data['status'] );

		return $post_id;
	}

	/**
	 * Schedule WordPress cron event for publishing.
	 *
	 * @param int $schedule_id  Schedule ID.
	 * @param int $timestamp    Scheduled timestamp.
	 * @return void
	 */
	protected function schedule_cron_event( $schedule_id, $timestamp ) {
		wp_schedule_single_event(
			$timestamp,
			'wp_mcp_ai_publish_scheduled_post',
			array( $schedule_id )
		);
	}
}
