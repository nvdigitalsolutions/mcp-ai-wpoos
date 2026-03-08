<?php
/**
 * Create Content Calendar Tool
 *
 * Plans social media content schedule with optimal posting times,
 * platform assignments, content themes, and ICS calendar export.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for creating social media content calendars.
 *
 * Supports:
 * - Intelligent scheduling with optimal posting times
 * - Multi-platform content planning
 * - Content theme organization
 * - ICS calendar export
 * - AI-powered time slot recommendations
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Create_Content_Calendar implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if toolkit is enabled.
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

		return __( 'Content calendar tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'create_content_calendar';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Create Content Calendar', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Plan social media content schedule with optimal posting times, platform assignments, and content themes. Generates ICS calendar file for easy import into calendar apps. Includes AI-powered recommendations for best posting times based on platform and audience.', 'mcp-ai-wpoos-pro' );
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
				'start_date'          => array(
					'type'        => 'string',
					'description' => __( 'Start date for calendar (YYYY-MM-DD)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'            => array(
					'type'        => 'string',
					'description' => __( 'End date for calendar (YYYY-MM-DD)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'duration_weeks'      => array(
					'type'        => 'integer',
					'description' => __( 'Duration in weeks (alternative to end_date)', 'mcp-ai-wpoos-pro' ),
					'default'     => 4,
					'minimum'     => 1,
					'maximum'     => 52,
				),
				'platforms'           => array(
					'type'        => 'array',
					'description' => __( 'Social media platforms to plan for', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'facebook', 'twitter', 'instagram', 'linkedin', 'pinterest', 'tiktok', 'youtube' ),
					),
					'default'     => array( 'facebook', 'twitter', 'instagram' ),
				),
				'posts_per_week'      => array(
					'type'        => 'integer',
					'description' => __( 'Number of posts per week per platform', 'mcp-ai-wpoos-pro' ),
					'default'     => 3,
					'minimum'     => 1,
					'maximum'     => 30,
				),
				'content_themes'      => array(
					'type'        => 'array',
					'description' => __( 'Content themes to distribute across calendar', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'timezone'            => array(
					'type'        => 'string',
					'description' => __( 'Timezone for calendar (e.g., America/New_York)', 'mcp-ai-wpoos-pro' ),
					'default'     => 'UTC',
				),
				'optimize_timing'     => array(
					'type'        => 'boolean',
					'description' => __( 'Use AI to optimize posting times based on platform best practices', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_weekends'    => array(
					'type'        => 'boolean',
					'description' => __( 'Include weekend posting in schedule', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'export_format'       => array(
					'type'        => 'string',
					'description' => __( 'Export format for calendar', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'ics', 'json', 'both' ),
					'default'     => 'both',
				),
				'business_hours_only' => array(
					'type'        => 'boolean',
					'description' => __( 'Restrict posting times to business hours (9am-5pm)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'   => array( 'start_date' ),
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
			'content-generation',
			'file-write',
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
				__( 'You do not have permission to create content calendars.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if toolkit is enabled.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'toolkit_not_enabled',
				self::get_unavailable_reason()
			);
		}

		// Validate and sanitize inputs.
		$start_date = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) ) {
			return new WP_Error(
				'invalid_start_date',
				__( 'Invalid start date format. Use YYYY-MM-DD.', 'mcp-ai-wpoos-pro' )
			);
		}

		$duration_weeks = isset( $arguments['duration_weeks'] ) ? absint( $arguments['duration_weeks'] ) : 4;
		$end_date       = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';

		if ( empty( $end_date ) ) {
			$end_date = gmdate( 'Y-m-d', strtotime( $start_date . " +{$duration_weeks} weeks" ) );
		}

		$platforms        = isset( $arguments['platforms'] ) && is_array( $arguments['platforms'] )
			? array_map( 'sanitize_text_field', $arguments['platforms'] )
			: array( 'facebook', 'twitter', 'instagram' );
		$posts_per_week   = isset( $arguments['posts_per_week'] ) ? absint( $arguments['posts_per_week'] ) : 3;
		$content_themes   = isset( $arguments['content_themes'] ) && is_array( $arguments['content_themes'] )
			? array_map( 'sanitize_text_field', $arguments['content_themes'] )
			: array();
		$timezone         = isset( $arguments['timezone'] ) ? sanitize_text_field( $arguments['timezone'] ) : 'UTC';
		$optimize_timing  = isset( $arguments['optimize_timing'] ) ? (bool) $arguments['optimize_timing'] : true;
		$include_weekends = isset( $arguments['include_weekends'] ) ? (bool) $arguments['include_weekends'] : true;
		$export_format    = isset( $arguments['export_format'] ) ? sanitize_text_field( $arguments['export_format'] ) : 'both';
		$business_hours   = isset( $arguments['business_hours_only'] ) ? (bool) $arguments['business_hours_only'] : false;

		// Generate content calendar.
		$calendar = $this->generate_calendar(
			$start_date,
			$end_date,
			$platforms,
			$posts_per_week,
			$content_themes,
			$timezone,
			$optimize_timing,
			$include_weekends,
			$business_hours
		);

		// Export calendar.
		$exports = $this->export_calendar( $calendar, $export_format, $timezone );

		return array(
			'success'        => true,
			'start_date'     => $start_date,
			'end_date'       => $end_date,
			'platforms'      => $platforms,
			'total_posts'    => count( $calendar['posts'] ),
			'posts_per_week' => $posts_per_week,
			'calendar'       => $calendar,
			'exports'        => $exports,
			'message'        => sprintf(
				/* translators: %d: Number of posts scheduled */
				__( 'Successfully created content calendar with %d posts scheduled.', 'mcp-ai-wpoos-pro' ),
				count( $calendar['posts'] )
			),
		);
	}

	/**
	 * Generate content calendar.
	 *
	 * @param string $start_date      Start date.
	 * @param string $end_date        End date.
	 * @param array  $platforms       Platforms.
	 * @param int    $posts_per_week  Posts per week.
	 * @param array  $content_themes  Content themes.
	 * @param string $timezone        Timezone.
	 * @param bool   $optimize_timing Optimize timing.
	 * @param bool   $include_weekends Include weekends.
	 * @param bool   $business_hours  Business hours only.
	 * @return array Calendar data.
	 */
	protected function generate_calendar( $start_date, $end_date, $platforms, $posts_per_week, $content_themes, $timezone, $optimize_timing, $include_weekends, $business_hours ) {
		$posts       = array();
		$start_time  = strtotime( $start_date );
		$end_time    = strtotime( $end_date );
		$theme_index = 0;

		foreach ( $platforms as $platform ) {
			$current_time = $start_time;
			$week_count   = 0;
			$post_count   = 0;

			while ( $current_time <= $end_time ) {
				// Check if weekend posting is disabled.
				$day_of_week = gmdate( 'N', $current_time );
				if ( ! $include_weekends && $day_of_week >= 6 ) {
					$current_time = strtotime( '+1 day', $current_time );
					continue;
				}

				// Check if we need to post today.
				if ( $post_count < $posts_per_week ) {
					$optimal_time = $optimize_timing
						? $this->get_optimal_posting_time( $platform, $day_of_week, $business_hours )
						: '12:00:00';

					$post_datetime = gmdate( 'Y-m-d', $current_time ) . ' ' . $optimal_time;
					$theme         = ! empty( $content_themes ) ? $content_themes[ $theme_index % count( $content_themes ) ] : '';

					$posts[] = array(
						'platform'     => $platform,
						'scheduled_at' => $post_datetime,
						'theme'        => $theme,
						'day_of_week'  => gmdate( 'l', $current_time ),
						'status'       => 'scheduled',
					);

					++$post_count;
					++$theme_index;
				}

				// Move to next day in the week.
				if ( $day_of_week === 7 || ( ! $include_weekends && $day_of_week === 5 ) ) {
					++$week_count;
					$post_count = 0;
				}

				$current_time = strtotime( '+1 day', $current_time );
			}
		}

		// Sort posts by date.
		usort(
			$posts,
			function ( $a, $b ) {
				return strcmp( $a['scheduled_at'], $b['scheduled_at'] );
			}
		);

		return array(
			'posts'    => $posts,
			'summary'  => $this->generate_calendar_summary( $posts, $platforms ),
			'timezone' => $timezone,
		);
	}

	/**
	 * Get optimal posting time for platform.
	 *
	 * @param string $platform       Platform name.
	 * @param int    $day_of_week    Day of week (1-7).
	 * @param bool   $business_hours Business hours only.
	 * @return string Time in HH:MM:SS format.
	 */
	protected function get_optimal_posting_time( $platform, $day_of_week, $business_hours ) {
		// Platform-specific optimal times based on industry research.
		$optimal_times = array(
			'facebook'  => array( '13:00:00', '15:00:00', '09:00:00' ),
			'twitter'   => array( '12:00:00', '15:00:00', '17:00:00' ),
			'instagram' => array( '11:00:00', '14:00:00', '19:00:00' ),
			'linkedin'  => array( '08:00:00', '12:00:00', '17:00:00' ),
			'pinterest' => array( '21:00:00', '14:00:00', '15:00:00' ),
			'tiktok'    => array( '19:00:00', '12:00:00', '15:00:00' ),
			'youtube'   => array( '14:00:00', '16:00:00', '18:00:00' ),
		);

		$times = isset( $optimal_times[ $platform ] ) ? $optimal_times[ $platform ] : array( '12:00:00' );

		// If business hours only, filter times.
		if ( $business_hours ) {
			$times = array_filter(
				$times,
				function ( $time ) {
					$hour = (int) substr( $time, 0, 2 );
					return $hour >= 9 && $hour <= 17;
				}
			);

			if ( empty( $times ) ) {
				$times = array( '12:00:00' );
			}
		}

		// Rotate through times based on day.
		return $times[ ( $day_of_week - 1 ) % count( $times ) ];
	}

	/**
	 * Generate calendar summary.
	 *
	 * @param array $posts     Posts data.
	 * @param array $platforms Platforms.
	 * @return array Summary data.
	 */
	protected function generate_calendar_summary( $posts, $platforms ) {
		$summary = array(
			'total_posts'    => count( $posts ),
			'by_platform'    => array(),
			'by_day_of_week' => array(),
		);

		foreach ( $platforms as $platform ) {
			$platform_posts                      = array_filter(
				$posts,
				function ( $post ) use ( $platform ) {
					return $post['platform'] === $platform;
				}
			);
			$summary['by_platform'][ $platform ] = count( $platform_posts );
		}

		$days = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
		foreach ( $days as $day ) {
			$day_posts                         = array_filter(
				$posts,
				function ( $post ) use ( $day ) {
					return $post['day_of_week'] === $day;
				}
			);
			$summary['by_day_of_week'][ $day ] = count( $day_posts );
		}

		return $summary;
	}

	/**
	 * Export calendar to various formats.
	 *
	 * @param array  $calendar      Calendar data.
	 * @param string $export_format Export format.
	 * @param string $timezone      Timezone.
	 * @return array Export data.
	 */
	protected function export_calendar( $calendar, $export_format, $timezone ) {
		$exports = array();

		if ( in_array( $export_format, array( 'json', 'both' ), true ) ) {
			$exports['json'] = $this->export_to_json( $calendar );
		}

		if ( in_array( $export_format, array( 'ics', 'both' ), true ) ) {
			$exports['ics'] = $this->export_to_ics( $calendar, $timezone );
		}

		return $exports;
	}

	/**
	 * Export calendar to JSON.
	 *
	 * @param array $calendar Calendar data.
	 * @return string JSON data.
	 */
	protected function export_to_json( $calendar ) {
		return wp_json_encode( $calendar, JSON_PRETTY_PRINT );
	}

	/**
	 * Export calendar to ICS format.
	 *
	 * @param array  $calendar Calendar data.
	 * @param string $timezone Timezone.
	 * @return string ICS data.
	 */
	protected function export_to_ics( $calendar, $timezone ) {
		$ics  = "BEGIN:VCALENDAR\r\n";
		$ics .= "VERSION:2.0\r\n";
		$ics .= "PRODID:-//NV oOS//Social Media Content Calendar//EN\r\n";
		$ics .= "CALSCALE:GREGORIAN\r\n";
		$ics .= "METHOD:PUBLISH\r\n";
		$ics .= "X-WR-CALNAME:Social Media Content Calendar\r\n";
		$ics .= 'X-WR-TIMEZONE:' . $timezone . "\r\n";

		foreach ( $calendar['posts'] as $index => $post ) {
			$start_time = strtotime( $post['scheduled_at'] );
			$end_time   = $start_time + ( 30 * 60 ); // 30 minute duration.

			$ics .= "BEGIN:VEVENT\r\n";
			$ics .= 'UID:' . md5( $post['scheduled_at'] . $post['platform'] . $index ) . "@nvoos.com\r\n";
			$ics .= 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ) . "\r\n";
			$ics .= 'DTSTART:' . gmdate( 'Ymd\THis\Z', $start_time ) . "\r\n";
			$ics .= 'DTEND:' . gmdate( 'Ymd\THis\Z', $end_time ) . "\r\n";
			$ics .= 'SUMMARY:' . $this->escape_ics_string( 'Post to ' . ucfirst( $post['platform'] ) ) . "\r\n";

			if ( ! empty( $post['theme'] ) ) {
				$ics .= 'DESCRIPTION:' . $this->escape_ics_string( 'Theme: ' . $post['theme'] ) . "\r\n";
			}

			$ics .= "STATUS:CONFIRMED\r\n";
			$ics .= "SEQUENCE:0\r\n";
			$ics .= "END:VEVENT\r\n";
		}

		$ics .= "END:VCALENDAR\r\n";

		return $ics;
	}

	/**
	 * Escape string for ICS format.
	 *
	 * @param string $string String to escape.
	 * @return string Escaped string.
	 */
	protected function escape_ics_string( $string ) {
		$string = str_replace( '\\', '\\\\', $string );
		$string = str_replace( array( "\r\n", "\n", "\r" ), '\\n', $string );
		$string = str_replace( ',', '\\,', $string );
		$string = str_replace( ';', '\\;', $string );
		return $string;
	}
}
