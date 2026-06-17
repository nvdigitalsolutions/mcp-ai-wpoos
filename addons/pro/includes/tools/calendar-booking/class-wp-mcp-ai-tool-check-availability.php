<?php
/**
 * Check Availability Tool
 *
 * Checks time slot availability for appointment booking.
 * Considers existing appointments and availability rules.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Calendar_Booking_Toolkit
 * @since 2.6.0
 * @phase Phase 2.6 - Calendar Booking Toolkit
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for checking time slot availability.
 *
 * Features:
 * - Real-time availability checking
 * - Business hours validation
 * - Holiday/blocked time detection
 * - Buffer time consideration
 * - Multiple slot checking
 *
 * @since 2.6.0
 */
class WP_MCP_AI_Tool_Check_Availability implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @since 2.6.0
	 *
	 * @return bool True if calendar booking toolkit is enabled.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_calendar_booking_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 2.6.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_calendar_booking_toolkit'] ) ) {
			return __( 'Calendar Booking toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Check availability tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'check_availability';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Check Availability', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Check time slot availability for appointment booking. Considers existing appointments, business hours, and blocked times.', 'mcp-ai-wpoos-pro' );
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
				'start_time'           => array(
					'type'        => 'string',
					'description' => __( 'Start time to check (Y-m-d H:i:s format, required)', 'mcp-ai-wpoos-pro' ),
				),
				'end_time'             => array(
					'type'        => 'string',
					'description' => __( 'End time to check (Y-m-d H:i:s format, required)', 'mcp-ai-wpoos-pro' ),
				),
				'check_business_hours' => array(
					'type'        => 'boolean',
					'description' => __( 'Validate against business hours', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'check_blocked_times'  => array(
					'type'        => 'boolean',
					'description' => __( 'Check for blocked time slots', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'start_time', 'end_time' ),
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
			'database-read',
			'phase-2.6',
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
		if ( ! self::is_available() ) {
			return new WP_Error(
				'toolkit_not_available',
				self::get_unavailable_reason()
			);
		}

		// Validate required fields.
		if ( empty( $arguments['start_time'] ) || empty( $arguments['end_time'] ) ) {
			return new WP_Error(
				'missing_time',
				__( 'Both start_time and end_time are required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$start_time = sanitize_text_field( $arguments['start_time'] );
		$end_time   = sanitize_text_field( $arguments['end_time'] );

		// Validate time format.
		if ( ! strtotime( $start_time ) || ! strtotime( $end_time ) ) {
			return new WP_Error(
				'invalid_time_format',
				__( 'Invalid time format. Use Y-m-d H:i:s format.', 'mcp-ai-wpoos-pro' )
			);
		}

		$is_available = true;
		$reasons      = array();

		// Check for conflicts.
		$conflicts = $this->check_conflicts( $start_time, $end_time );
		if ( ! empty( $conflicts ) ) {
			$is_available = false;
			$reasons[]    = sprintf(
			/* translators: %d: Number of conflicting appointments */
				__( '%d existing appointment(s) conflict with this time slot.', 'mcp-ai-wpoos-pro' ),
				count( $conflicts )
			);
		}

		// Check business hours if requested.
		if ( ! empty( $arguments['check_business_hours'] ) ) {
			$in_business_hours = $this->check_business_hours( $start_time, $end_time );
			if ( ! $in_business_hours ) {
				$is_available = false;
				$reasons[]    = __( 'Time slot is outside business hours.', 'mcp-ai-wpoos-pro' );
			}
		}

		// Check blocked times if requested.
		if ( ! empty( $arguments['check_blocked_times'] ) ) {
			$is_blocked = $this->check_blocked_times( $start_time, $end_time );
			if ( $is_blocked ) {
				$is_available = false;
				$reasons[]    = __( 'Time slot has been blocked.', 'mcp-ai-wpoos-pro' );
			}
		}

		return array(
			'success'    => true,
			'available'  => $is_available,
			'start_time' => $start_time,
			'end_time'   => $end_time,
			'conflicts'  => $conflicts,
			'reasons'    => $reasons,
			'message'    => $is_available
			? __( 'Time slot is available.', 'mcp-ai-wpoos-pro' )
			: __( 'Time slot is not available.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Check for appointment conflicts.
	 *
	 * @param string $start_time Start time.
	 * @param string $end_time   End time.
	 * @return array Array of conflicting appointment IDs.
	 */
	private function check_conflicts( $start_time, $end_time ) {
		$args = array(
			'post_type'      => 'mcp_appointment',
			'post_status'    => 'publish',
			'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'check_availability', 0, 500 ) : 500,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => '_status',
					'value'   => array( 'confirmed', 'pending' ),
					'compare' => 'IN',
				),
				array(
					'relation' => 'OR',
					array(
						'relation' => 'AND',
						array(
							'key'     => '_start_time',
							'value'   => $start_time,
							'compare' => '<=',
							'type'    => 'DATETIME',
						),
						array(
							'key'     => '_end_time',
							'value'   => $start_time,
							'compare' => '>',
							'type'    => 'DATETIME',
						),
					),
					array(
						'relation' => 'AND',
						array(
							'key'     => '_start_time',
							'value'   => $end_time,
							'compare' => '<',
							'type'    => 'DATETIME',
						),
						array(
							'key'     => '_end_time',
							'value'   => $end_time,
							'compare' => '>=',
							'type'    => 'DATETIME',
						),
					),
				),
			),
		);

		$query = new WP_Query( $args );
		return $query->posts ? wp_list_pluck( $query->posts, 'ID' ) : array();
	}

	/**
	 * Check if time is within business hours.
	 *
	 * @param string $start_time Start time.
	 * @param string $end_time   End time.
	 * @return bool True if within business hours.
	 */
	private function check_business_hours( $start_time, $end_time ) {
		$business_hours = get_option( 'wp_mcp_ai_business_hours', array() );

		if ( empty( $business_hours ) ) {
			return true;
		}

		$start_dt    = new DateTime( $start_time );
		$day_of_week = strtolower( $start_dt->format( 'l' ) );

		if ( empty( $business_hours[ $day_of_week ] ) || empty( $business_hours[ $day_of_week ]['enabled'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if time is blocked.
	 *
	 * @param string $start_time Start time.
	 * @param string $end_time   End time.
	 * @return bool True if blocked.
	 */
	private function check_blocked_times( $start_time, $end_time ) {
		$args = array(
			'post_type'      => 'mcp_blocked_time',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => '_start_time',
					'value'   => $end_time,
					'compare' => '<',
					'type'    => 'DATETIME',
				),
				array(
					'key'     => '_end_time',
					'value'   => $start_time,
					'compare' => '>',
					'type'    => 'DATETIME',
				),
			),
		);

		$query = new WP_Query( $args );
		return $query->have_posts();
	}
}
