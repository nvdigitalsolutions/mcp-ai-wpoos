<?php
/**
 * Tool for generating event timelines.
 *
 * Allows AI assistants to create detailed event timelines for DJ performances.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates event timelines for DJ performances.
 */
class WP_MCP_AI_Tool_Generate_Event_Timeline implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_event_timeline';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Event Timeline', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates a detailed event timeline for DJ performances. Includes setup, performance segments, and breakdown schedules.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Booking ID to generate timeline for (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'event_date'       => array(
					'type'        => 'string',
					'description' => __( 'Event date in ISO 8601 format (YYYY-MM-DD) (required if no booking_id)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'start_time'       => array(
					'type'        => 'string',
					'description' => __( 'Event start time in 24-hour format (HH:MM) (required if no booking_id)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^([01]\d|2[0-3]):([0-5]\d)$',
				),
				'end_time'         => array(
					'type'        => 'string',
					'description' => __( 'Event end time in 24-hour format (HH:MM) (required if no booking_id)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^([01]\d|2[0-3]):([0-5]\d)$',
				),
				'setup_duration'   => array(
					'type'        => 'integer',
					'description' => __( 'Setup duration in minutes (optional, defaults to 60)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 15,
					'maximum'     => 180,
					'default'     => 60,
				),
				'breakdown_duration' => array(
					'type'        => 'integer',
					'description' => __( 'Breakdown duration in minutes (optional, defaults to 30)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 15,
					'maximum'     => 120,
					'default'     => 30,
				),
				'event_type'       => array(
					'type'        => 'string',
					'description' => __( 'Event type for timeline customization (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'wedding', 'corporate', 'birthday', 'club', 'private_party', 'festival', 'other' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments, array $context = array() ) {
		$event_date    = '';
		$start_time    = '';
		$end_time      = '';
		$event_type    = ! empty( $arguments['event_type'] ) ? sanitize_text_field( $arguments['event_type'] ) : 'other';
		$booking_id    = ! empty( $arguments['booking_id'] ) ? absint( $arguments['booking_id'] ) : 0;

		// Get details from booking if provided.
		if ( $booking_id ) {
			if ( ! get_post( $booking_id ) || get_post_type( $booking_id ) !== 'dj_booking' ) {
				return array(
					'success' => false,
					'error'   => __( 'Invalid booking ID.', 'mcp-ai-wpoos-pro' ),
				);
			}

			$event_date = get_post_meta( $booking_id, '_event_date', true );
			$start_time = get_post_meta( $booking_id, '_start_time', true );
			$end_time   = get_post_meta( $booking_id, '_end_time', true );
			if ( ! $event_type || $event_type === 'other' ) {
				$event_type = get_post_meta( $booking_id, '_event_type', true );
			}
		} else {
			// Validate required parameters if no booking ID.
			if ( empty( $arguments['event_date'] ) || empty( $arguments['start_time'] ) || empty( $arguments['end_time'] ) ) {
				return array(
					'success' => false,
					'error'   => __( 'Event date, start time, and end time are required when booking_id is not provided.', 'mcp-ai-wpoos-pro' ),
				);
			}

			$event_date = sanitize_text_field( $arguments['event_date'] );
			$start_time = sanitize_text_field( $arguments['start_time'] );
			$end_time   = sanitize_text_field( $arguments['end_time'] );
		}

		$setup_duration     = ! empty( $arguments['setup_duration'] ) ? absint( $arguments['setup_duration'] ) : 60;
		$breakdown_duration = ! empty( $arguments['breakdown_duration'] ) ? absint( $arguments['breakdown_duration'] ) : 30;

		// Build timeline.
		$timeline = array();

		// Calculate setup time.
		$setup_time = date( 'H:i', strtotime( $start_time ) - ( $setup_duration * 60 ) );
		$timeline[] = array(
			'time'        => $setup_time,
			'activity'    => __( 'Arrive at venue', 'mcp-ai-wpoos-pro' ),
			'duration'    => 0,
			'type'        => 'arrival',
		);

		$timeline[] = array(
			'time'        => $setup_time,
			'activity'    => __( 'Equipment setup and sound check', 'mcp-ai-wpoos-pro' ),
			'duration'    => $setup_duration,
			'type'        => 'setup',
		);

		// Add event-specific timeline items.
		$timeline = array_merge( $timeline, $this->get_event_specific_timeline( $event_type, $start_time, $end_time ) );

		// Calculate breakdown time.
		$breakdown_time = $end_time;
		$timeline[] = array(
			'time'        => $breakdown_time,
			'activity'    => __( 'Equipment breakdown and packing', 'mcp-ai-wpoos-pro' ),
			'duration'    => $breakdown_duration,
			'type'        => 'breakdown',
		);

		$departure_time = date( 'H:i', strtotime( $end_time ) + ( $breakdown_duration * 60 ) );
		$timeline[] = array(
			'time'        => $departure_time,
			'activity'    => __( 'Departure', 'mcp-ai-wpoos-pro' ),
			'duration'    => 0,
			'type'        => 'departure',
		);

		// Store timeline if booking ID provided.
		if ( $booking_id ) {
			update_post_meta( $booking_id, '_event_timeline', $timeline );
		}

		return array(
			'success'    => true,
			'message'    => __( 'Event timeline generated successfully.', 'mcp-ai-wpoos-pro' ),
			'event_date' => $event_date,
			'timeline'   => $timeline,
			'summary'    => array(
				'arrival_time'   => $setup_time,
				'event_start'    => $start_time,
				'event_end'      => $end_time,
				'departure_time' => $departure_time,
				'total_duration' => $this->calculate_total_duration( $setup_time, $departure_time ),
			),
		);
	}

	/**
	 * Get event-specific timeline items.
	 *
	 * @param string $event_type Event type.
	 * @param string $start_time Start time.
	 * @param string $end_time End time.
	 * @return array Timeline items.
	 */
	private function get_event_specific_timeline( $event_type, $start_time, $end_time ) {
		$timeline = array();

		switch ( $event_type ) {
			case 'wedding':
				$timeline[] = array(
					'time'     => $start_time,
					'activity' => __( 'Cocktail hour - Background music', 'mcp-ai-wpoos-pro' ),
					'duration' => 60,
					'type'     => 'performance',
				);
				$timeline[] = array(
					'time'     => date( 'H:i', strtotime( $start_time ) + 3600 ),
					'activity' => __( 'Grand entrance and first dance', 'mcp-ai-wpoos-pro' ),
					'duration' => 30,
					'type'     => 'performance',
				);
				$timeline[] = array(
					'time'     => date( 'H:i', strtotime( $start_time ) + 5400 ),
					'activity' => __( 'Open dancing', 'mcp-ai-wpoos-pro' ),
					'duration' => $this->calculate_duration( date( 'H:i', strtotime( $start_time ) + 5400 ), $end_time ),
					'type'     => 'performance',
				);
				break;

			case 'corporate':
				$timeline[] = array(
					'time'     => $start_time,
					'activity' => __( 'Background music during networking', 'mcp-ai-wpoos-pro' ),
					'duration' => 90,
					'type'     => 'performance',
				);
				$timeline[] = array(
					'time'     => date( 'H:i', strtotime( $start_time ) + 5400 ),
					'activity' => __( 'Upbeat music and dancing', 'mcp-ai-wpoos-pro' ),
					'duration' => $this->calculate_duration( date( 'H:i', strtotime( $start_time ) + 5400 ), $end_time ),
					'type'     => 'performance',
				);
				break;

			default:
				$timeline[] = array(
					'time'     => $start_time,
					'activity' => __( 'DJ performance', 'mcp-ai-wpoos-pro' ),
					'duration' => $this->calculate_duration( $start_time, $end_time ),
					'type'     => 'performance',
				);
				break;
		}

		return $timeline;
	}

	/**
	 * Calculate duration between two times in minutes.
	 *
	 * @param string $start_time Start time.
	 * @param string $end_time End time.
	 * @return int Duration in minutes.
	 */
	private function calculate_duration( $start_time, $end_time ) {
		$start = strtotime( $start_time );
		$end   = strtotime( $end_time );
		return ( $end - $start ) / 60;
	}

	/**
	 * Calculate total duration in hours and minutes.
	 *
	 * @param string $start_time Start time.
	 * @param string $end_time End time.
	 * @return string Formatted duration.
	 */
	private function calculate_total_duration( $start_time, $end_time ) {
		$minutes = $this->calculate_duration( $start_time, $end_time );
		$hours   = floor( $minutes / 60 );
		$mins    = $minutes % 60;
		return sprintf( '%d hours %d minutes', $hours, $mins );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_flag_capabilities() {
		return array( 'read' );
	}
}
