<?php
/**
 * Get Available Slots Tool - Phase 2.6
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_MCP_AI_Tool_Get_Available_Slots implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false; }
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_calendar_booking_toolkit'] );
	}
	public static function get_unavailable_reason() {
		return __( 'Calendar Booking toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}
	public function get_slug() {
		return 'get_available_slots'; }
	public function get_name() {
		return __( 'Get Available Slots', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Get list of available time slots for booking appointments.', 'mcp-ai-wpoos-pro' );
	}
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'date'             => array(
					'type'        => 'string',
					'description' => __( 'Date to check (Y-m-d format)', 'mcp-ai-wpoos-pro' ),
				),
				'duration_minutes' => array(
					'type'        => 'integer',
					'description' => __( 'Required duration', 'mcp-ai-wpoos-pro' ),
					'default'     => 60,
				),
			),
			'required'   => array( 'date' ),
		);
	}
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'phase-2.6' ); }
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'toolkit_not_available', self::get_unavailable_reason() );
		}
		$date = ! empty( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : '';
		if ( empty( $date ) || ! strtotime( $date ) ) {
			return new WP_Error( 'invalid_date', __( 'Valid date is required.', 'mcp-ai-wpoos-pro' ) );
		}
		$duration = ! empty( $arguments['duration_minutes'] ) ? absint( $arguments['duration_minutes'] ) : 60;
		$slots    = $this->calculate_available_slots( $date, $duration );
		return array(
			'success'          => true,
			'date'             => $date,
			'duration_minutes' => $duration,
			'available_slots'  => $slots,
			'total_slots'      => count( $slots ),
		);
	}
	private function calculate_available_slots( $date, $duration ) {
		$day_of_week    = strtolower( gmdate( 'l', strtotime( $date ) ) );
		$business_hours = get_option( 'wp_mcp_ai_business_hours', array() );
		if ( empty( $business_hours[ $day_of_week ] ) || empty( $business_hours[ $day_of_week ]['enabled'] ) ) {
			return array();
		}
		$slots         = array();
		$start         = $business_hours[ $day_of_week ]['start_time'];
		$end           = $business_hours[ $day_of_week ]['end_time'];
		$slot_duration = ! empty( $business_hours[ $day_of_week ]['slot_duration'] ) ? $business_hours[ $day_of_week ]['slot_duration'] : 60;
		$current_time  = strtotime( $date . ' ' . $start );
		$end_time      = strtotime( $date . ' ' . $end );
		while ( $current_time + ( $duration * 60 ) <= $end_time ) {
			$slot_start = gmdate( 'Y-m-d H:i:s', $current_time );
			$slot_end   = gmdate( 'Y-m-d H:i:s', $current_time + ( $duration * 60 ) );
			$args       = array(
				'post_type'   => 'mcp_appointment',
				'post_status' => 'publish',
				'meta_query'  => array(
					'relation' => 'AND',
					array(
						'key'     => '_status',
						'value'   => array( 'confirmed', 'pending' ),
						'compare' => 'IN',
					),
					array(
						'key'     => '_start_time',
						'value'   => $slot_end,
						'compare' => '<',
						'type'    => 'DATETIME',
					),
					array(
						'key'     => '_end_time',
						'value'   => $slot_start,
						'compare' => '>',
						'type'    => 'DATETIME',
					),
				),
			);
			$query      = new WP_Query( $args );
			if ( ! $query->have_posts() ) {
				$slots[] = array(
					'start_time' => $slot_start,
					'end_time'   => $slot_end,
					'available'  => true,
				);
			}
			$current_time += ( $slot_duration * 60 );
		}
		return $slots;
	}
}
